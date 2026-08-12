# Errores de producción heredados de OSPOS upstream

Bitácora de errores detectados revisando `writable/logs/` en producción que **no vienen de nuestro trabajo** sino del código base de Open Source POS. Se documentan aquí para no re-diagnosticarlos y para dejar constancia de cómo se corrigieron en este fork.

Cómo revisar los logs de producción (solo lectura):

```bash
ssh -i ~/.ssh/ospos_deploy root@148.230.82.172
cd /root/POS_Casaletto
docker compose -f docker-compose.prod.yml exec -T ospos sh -c 'grep -E "^(CRITICAL|ERROR)" writable/logs/log-$(date +%F).log'
```

---

## 1. `log_message('error', ...)` de depuración inundando el canal de errores (corregido 2026-08-11)

**Síntoma:** entre 26 y 74 líneas de nivel `ERROR` por día en producción, todas con la misma consulta SQL y **sin ningún mensaje de error del motor**. La consulta no fallaba: se estaba registrando a propósito.

**Causa:** `Sale::create_temp_table_sales_payments_data()` tenía un `log_message('error', $sub_query);` justo después de compilar la subconsulta. Se ejecuta cada vez que se arma la tabla temporal de pagos — o sea, en cada carga de la grilla de Ventas (`Sales::getSearch()` → `Sale::search()`) y de los reportes de ventas.

**Origen:** upstream, commit `061ed57bf` (2024-05-14).

**Gravedad real:** ninguna funcionalmente, pero **las 1294 líneas `ERROR` acumuladas en 30 archivos de log eran todas esta sentencia**. El canal de errores estaba 100% tapado, así que un error de verdad no se habría notado nunca.

**Corrección:** eliminar la línea. `$sub_query` se sigue usando en el `CREATE TEMPORARY TABLE` inmediatamente debajo.

**Cómo verificarlo:** contar `grep -c "^ERROR"` sobre los logs, cargar `sales/search`, volver a contar. El contador no debe subir. Que la respuesta traiga el campo `payment_type` (ej. `"Efectivo 18000.00"`) confirma que la función sí corrió, porque ese valor sale precisamente del `GROUP_CONCAT` de esa tabla temporal.

---

## 2. `TypeError` al cerrar caja con un campo de importe vacío (corregido 2026-08-11)

**Síntoma:** 1 a 3 `CRITICAL` por noche, siempre durante el cierre de turno:

```
TypeError: App\Controllers\Cashups::_calculate_total(): Argument #5 ($closed_amount_card) must be of type float, string given
[Method: POST, Route: cashups/ajax_cashup_total]
_calculate_total(3511000.0, 0.0, 0.0, 3866450.0, '', 30750.0)
```

**Causa, en cadena:**
1. `app/Views/cashups/form.php` postea los seis importes a `cashups/ajax_cashup_total` con cada tecleo (debounce de 300 ms) para refrescar el Total.
2. `parse_decimals()` (`app/Helpers/locale_helper.php`) hace `if (empty($number)) return $number;` — devuelve **la cadena vacía tal cual** cuando el campo aún no se ha llenado.
3. `_calculate_total()` declara esos parámetros como `float`. PHP convierte `'0'` o `'1500'` sin problema, pero `''` no es numérico → `TypeError` → HTTP 500.

**Por qué "cheque" nunca rompió y "tarjeta" sí:** `$closed_amount_check` era el único parámetro **sin tipo declarado**. No era una decisión, era una inconsistencia en la firma.

**Qué veía la cajera:** el recuadro Total (que es `readonly`) dejaba de actualizarse en silencio, sin ningún mensaje, hasta que todos los campos tuvieran valor. La respuesta 500 simplemente no ejecuta el callback que escribe el resultado.

**Impacto en datos: ninguno, verificado.** El total cerrado se guarda tal como viene del formulario (`Cashups::postSave()`), sin recalcularse en el servidor, así que un total desactualizado *podría* haberse guardado. Se compararon los 12 turnos más recientes contra la fórmula (`closed_amount_cash - open_amount_cash - transfer_amount_cash + closed_amount_due + closed_amount_card + closed_amount_check`): **diferencia 0.00 en todos los cerrados**. Los únicos con diferencia son los turnos abiertos, donde los campos de cierre están en cero — eso es normal.

**Corrección:** `Cashups::_posted_amount()` normaliza los tres retornos no-float que `parse_decimals()` puede dar (`''`, el literal `'0'` porque `empty('0')` es `true` en PHP, y `false` para números inválidos o fuera de rango), y `$closed_amount_check` recibe su tipo `float`.

**Deliberadamente NO se tocó `parse_decimals()`**: tiene 43 usos en 12 archivos y cambiarle el contrato de "vacío" es una superficie de riesgo desproporcionada. La normalización vive en el punto de entrada del controlador.

**Pendiente, decidido explícitamente por el usuario:** no se recalcula `closed_amount_total` en el servidor al guardar. Sigue siendo el valor que manda el formulario.

---

## 3. `date_create_from_format()` sin verificar en `Cashups::postSave()` (corregido 2026-08-11)

**Síntoma:** `Error: Call to a member function format() on bool` en `cashups/save/-1`. Una sola vez, el 2026-07-15, sin repetirse.

**Causa:** `date_create_from_format()` devuelve `false` si la fecha posteada no calza con el `dateformat`/`timeformat` configurados, y la línea siguiente le llamaba `->format()` encima. Un error de validación terminaba en pantallazo fatal.

**Corrección:** verificar el retorno en las dos ramas (apertura y cierre) y devolver el JSON de error que el propio controlador ya usa en su rama de fallo, reusando la clave `Cashups.error_adding_updating` que ya existe en todos los idiomas.

---

## Otro hallazgo, sin corregir

`ErrorException: Undefined array key "cash_adjustment"` en `Sale.php` durante `sales/complete`. Tres veces, todas el 2026-07-15, sin repetirse en el mes siguiente. Un pago llegó al bucle de guardado sin esa clave. No se investigó a fondo por no ser reproducible; queda anotado por si reaparece.

---

## 4. El efectivo de apertura se guardaba como cero sin avisar (corregido 2026-08-11)

**Incidente:** el turno 29 (2026-08-11) se abrió a las 12:01:43 con `open_amount_cash = 0.00` cuando debía ser 217.000. Nadie lo notó hasta después de cerrar el turno esa noche.

**No hubo ningún error registrado.** El log no tiene una sola línea a esa hora: la aplicación aceptó el cero en silencio.

**Causa:** `Cashups::postSave()` **no tenía validación de ninguna clase** — ni reglas registradas en `app/Config/Validation*`, ni una llamada a `validate()`. Los importes iban directo de `parse_decimals()` a columnas `DECIMAL`, y ese helper devuelve `''` para un campo vacío y `false` para lo que no logra parsear. MySQL guarda ambos como `0.00` sin chistar.

**Por qué duele:** `Cashups::getView()` siembra el efectivo de cierre con `open_amount_cash + transfer_amount_cash`. Una apertura en cero arrastra el faltante hasta el cierre, así que el turno cerró corto por exactamente esos 217.000 y el descuadre solo se vio al revisar a mano.

**Primera vez en 29 turnos.** Los otros 28 abrieron con el efectivo de cierre del turno anterior.

**Corrección de datos** (autorizada explícitamente por el usuario, con respaldo previo de la tabla):

```sql
UPDATE ospos_cash_up SET open_amount_cash = 217000.00, closed_amount_cash = 375500.00
 WHERE cashup_id = 29 AND open_amount_cash = 0.00 AND closed_amount_cash = 158500.00;
```

**`closed_amount_total` NO se tocó, a propósito.** La fórmula del app resta la apertura (`closed_amount_cash - open_amount_cash - transfer + due + card + check`), así que subir 217.000 tanto la apertura como el cierre se cancela: el total sigue siendo 1.082.775, que es justo lo que la fórmula produce. El neto del día siempre estuvo bien; lo que estaba mal era la composición del cajón. Verificado después: los 29 turnos cuadran, cero descuadrados.

**Corrección de código:** `Cashups::_amount_is_valid()` rechaza el guardado en vez de dejar pasar un cero silencioso. Un campo vacío solo se tolera donde el formulario de verdad significa "ninguno" (`transfer_amount_cash` al abrir; adeudo/datafono/banco al cerrar); un valor que `parse_decimals()` rechaza no se tolera nunca. El mensaje nombra el campo culpable, con la clave `Cashups.invalid_amount` agregada a `en` (el fallback), `es-ES` y `es-MX` — que es el idioma que corre esta instalación.

**Lección:** el punto ciego no era la falta de un arreglo puntual sino que **un controlador que escribe plata no tenía ninguna validación**. Vale la pena revisar con la misma lupa los otros formularios que guardan importes (`Expenses`, `Receivings`, `Giftcards`), donde `parse_decimals()` se usa con el mismo patrón de guardar el retorno tal cual.
