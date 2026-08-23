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

---

## 5. `FILTER_SANITIZE_FULL_SPECIAL_CHARS` convierte las tildes en entidades HTML (diagnosticado 2026-08-22, sin corregir)

**Síntoma:** en la grilla de Ventas, los filtros "Tarjeta de Débito" y "Tarjeta de Crédito" no
devuelven nada. Nunca. Sin mensaje de error: una lista vacía que se lee como un dato.

**Qué hay en la base**, verificado con `HEX()` sobre producción:

```
Tarjeta de d&eacute;bito     195 pagos    12.715.730
Tarjeta de Cr&eacute;dito      6 pagos       362.120
Efectivo                     444 pagos    (correcto)
Transferencia Bancaria        91 pagos    (correcto)
```

El `&` (0x26) y el `;` (0x3B) están literalmente almacenados. Los filtros comparan con
`like('payment_type', lang('Sales.debit'))`, donde la etiqueta lleva la `é` real, así que no
coinciden. **No es un problema de colación**: `utf8_general_ci` equipara `é` con `e`, pero no expande
una entidad de ocho caracteres a uno. Los dos medios que sí funcionan son exactamente los dos cuyas
etiquetas no llevan tilde.

**Causa raíz.** `app/Controllers/Sales.php:443` lee el medio de pago así:

```php
$payment_type = $this->request->getPost('payment_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
```

La documentación de PHP describe ese filtro como *"equivalente a llamar `htmlspecialchars()` con
`ENT_QUOTES`"*. **Eso es falso.** Internamente invoca `php_escape_html_entities_ex()` con el flag
`all = 1`, que es el comportamiento de `htmlentities()`: codifica **todos** los caracteres con
entidad conocida, incluidas las vocales acentuadas.

Comprobado ejecutando PHP dentro del contenedor de producción:

```
$s = "Tarjeta de débito";
htmlspecialchars($s, ENT_QUOTES)                       → 5461726a...64 c3a9 6269746f   (é intacta)
filter_var($s, FILTER_SANITIZE_FULL_SPECIAL_CHARS)     → 5461726a...64 266561637574653b 6269746f
                                                                        ^^^^^^^^^^^^^^^^  &eacute;
```

Descartados en el camino, para que nadie los vuelva a revisar: el archivo de idioma tiene la `é`
real (`c3 a9`) tanto en el repo como en el contenedor desplegado; `form_dropdown()` de CI4 usa
`htmlspecialchars()`, que no la toca; el `esc()` de CI4 (Laminas) produce entidades **numéricas**
(`&#xE9;`), no con nombre; `bootstrap-select` no reescribe el `value` de los `<option>` y su
`htmlEscape` solo cubre `&<>"'`; la página declara `<meta charset="utf-8">` y el formulario se envía
con un POST nativo del navegador; y la configuración de PHP del contenedor es limpia
(`default_charset=UTF-8`, sin `mbstring.http_output`, sin `output_handler`).

**No hay ningún servicio externo ni integración involucrada.** El problema está enteramente en el
código de la aplicación, así que es controlable — lo cual importa de cara al modelo SaaS.

**Alcance real: mucho más que los medios de pago.** El filtro se usa **147 veces en 19
controladores**: `Sales` (25), `Employees` (19), `Suppliers` (16), `Receivings` (11), `Taxes` (10),
`Expenses` (10), `Cashups` (8), `Items` (6), y así. **Cualquier texto acentuado que un usuario
escriba en la aplicación se guarda codificado.** Un cliente llamado "José" queda como
`Jos&eacute;`, y no volverá a aparecer en una búsqueda por "José".

**Por qué casi no se nota hoy:** los usuarios de Casaletto escriben sin tildes. En producción,
`ospos_people`, `ospos_expenses.description`, `ospos_expense_categories` y `ospos_sales.comment`
tienen **cero** filas con tilde — reales o codificadas. Los 26 artículos con tilde real
("Jamón Serrano", "Pavo relleno navideño") entraron por los scripts de importación de Siigo, que no
pasan por este filtro. Las únicas 50 descripciones de artículo con entidad
("Unidad: n&uacute;mero de unidades internacionales") vienen del propio archivo de Siigo, no de
aquí: en esas mismas filas el nombre conserva su tilde real.

O sea: **hoy el daño está contenido en los medios de pago porque son el único texto acentuado que
la aplicación genera por sí misma.** En cuanto un tenant nuevo tenga usuarios que escriban con
tildes, el problema se vuelve general.

**Upstream conoce el síntoma y lo parchó por partes.** Hay seis `html_entity_decode()` repartidos
justo donde les dolió: cuatro en `Attributes.php`, uno en `suppliers/form.php` y uno en
`tabular_helper.php` para `company_name`. Ninguno toca la causa.

**Corrección: planificada, no ejecutada.** El criterio es que **el saneamiento de entrada no debe
cambiar el dato** — escapar es responsabilidad de la **salida** (`esc()` en las vistas, que ya se
usa). El usuario decidió el 2026-08-22 erradicarlo por completo, en dos tramos: primero los medios
de pago (fase 1) y después los 143 usos restantes módulo por módulo (fase 1b), empezando por
`Customers` y `Employees`. Plan completo en
`docs/Tecnico/reportes-analiticos-ingresos-gastos.md` secciones 7.3 y 7.6.

**Obstáculo a tener presente**: el filtro hoy hace doble oficio — es lo único entre lo que se postea
y las **255 salidas sin escapar** que hay en las vistas (por ejemplo
`app/Views/sales/register.php:552`, que imprime el medio de pago en crudo). Dentro de cada módulo el
orden es primero escapar la salida y después quitar el filtro, nunca al revés.

**Alcance de la reparación de datos, medido.** Barrido completo de producción el 2026-08-22 sobre 15
campos: los únicos con entidades son `sales_payments.payment_type` (201 filas, este filtro) e
`items.description` (50 filas, que vienen del archivo de Siigo y no de aquí). Están en cero los
nombres de proveedor y agencia, valores y definiciones de atributo, descripciones de kit, tarjetas de
regalo, nombres de empleado, comentarios de cliente, categorías, comentarios de recepción, nombres de
ubicación, nombres de mesa y nombres de artículo. **Reparar esos dos campos regulariza el 100% del
daño existente**; la fase 1b es prevención.
