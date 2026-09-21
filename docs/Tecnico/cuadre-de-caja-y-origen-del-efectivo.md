# Diseño técnico — Cuadre de caja: origen del efectivo, recogidas y conciliación del turno

> **Estado a 2026-08-23: construido en `develop`, con la suite completa en verde en CI.**
> 247 pruebas contra una base MariaDB real, en PHP 8.2, 8.3 y 8.4 — incluidas las 29 nuevas de este
> trabajo. **Todavía sin desplegar**: las migraciones no se han ejecutado en ningún ambiente.

Alcance funcional en `docs/Funcional/cuadre-de-caja-y-origen-del-efectivo.md`.

---

## 1. Principio rector

**El sistema tiene que poder decir cuánto efectivo debería haber en el cajón.** Hoy no puede, y por
eso el cierre acepta cualquier número sin chistar. Todo lo demás de este diseño existe para llegar a
esa cifra: de dónde salió cada gasto, qué se recogió, y a qué turno pertenece cada venta.

Un corolario que ordena las decisiones: **si un dato no se puede calcular, se muestra como
descuadre — nunca se asume.** Es la misma regla que se aplicó al backfill de medios de pago y al
turno 29.

## 2. Piezas y orden de implementación

Las cuatro se pueden construir por separado, pero **la conciliación depende de las tres primeras**.

| # | Pieza | Depende de |
|---|---|---|
| 1 | Origen del efectivo en gastos | — |
| 2 | Registro de recogidas de caja | — |
| 3 | Un solo turno abierto + venta sellada con el turno | — |
| 4 | Conciliación en el cierre y reporte de turnos | 1, 2 y 3 |

## 3. Origen del efectivo (gastos)

### 3.1 Modelo

Columna nueva en `ospos_expenses`:

```
cash_source  VARCHAR(20)  NULL   -- 'register' | 'collected' | NULL
```

**`NULL` cuando el medio de pago no es efectivo.** No es un valor faltante: es que la pregunta no
aplica. Una transferencia bancaria no sale de ningún bolsillo de efectivo.

Los códigos son estables e independientes del idioma, igual que `payment_type_code`. Las etiquetas se
resuelven al mostrar.

### 3.2 Por qué una columna y no dos tipos de pago

Decidido con el usuario. El medio de pago responde *cómo se pagó*; el origen, *de qué bolsillo salió*.
Mezclarlos en un solo campo rompería además la simetría del reporte Ingresos vs Gastos que ya está en
producción, cuyo modo caja compara ingresos y gastos **por el mismo medio de pago**: si los gastos
tuvieran dos tipos de efectivo y las ventas uno, ese filtro dejaría de tener sentido.

### 3.3 Sesgo por rol

**Administrador** = tiene el permiso `config`. Hoy separa limpio a 4 administradores de 2 cajeros.

- **Cajero**: el campo se muestra **deshabilitado**, fijo en `register`. Ve que la distinción existe y
  por qué su gasto siempre sale del cajón.
- **Administrador**: **sin valor por defecto**. Tiene que elegir. Un valor por defecto invita a
  dejarlo mal, y en su caso la deducción es imposible porque sí tiene acceso a los dos bolsillos.

**La regla se aplica en el servidor, no solo en la vista.** Un campo deshabilitado no se envía en el
formulario, y aunque se enviara, un POST fabricado puede traer cualquier cosa. `Expenses::postSave()`
decide el valor así:

```
si el medio de pago no es efectivo        -> null
si quien registra NO tiene 'config'
      y el gasto ya tenía un origen        -> se conserva el que ya tenía
      y no tenía ninguno                   -> 'register'   (ignora lo que venga en el POST)
si tiene 'config'                          -> lo que eligió, y si no eligió, se rechaza el guardado
```

Rechazar es deliberado: es la lección del turno 29 — **un dato que no se pudo determinar tiene que
hacer ruido, no convertirse en el valor más inocente disponible.**

**Editar no es decidir** *(corregido el 2026-08-23, ver 3.4)*. La primera versión de esta regla decía
que quien no tiene `config` siempre escribe `'register'`. Eso es correcto al **crear** un gasto y
equivocado al **editarlo**: un cajero que abre un gasto que un administrador marcó como `collected` y
le corrige la descripción se lo voltearía a `register`, y el cajón de ese día aparecería corto sin
nada en el registro que lo explique.

`'register'` queda como respaldo solo cuando **no hay ninguna decisión previa que conservar** — un
gasto nuevo, o uno que era transferencia y pasa a efectivo — que es además el único bolsillo que un
cajero alcanza. Un valor guardado nunca contesta por el administrador: si tiene `config` y no eligió,
se rechaza igual, haya lo que haya en la columna.

### 3.4 Por qué esta regla espeja a la de `employee_id`

`Expenses::postSave()` ya resolvía exactamente el mismo problema tres líneas más arriba, para el
empleado al que se le imputa el gasto: lee el POST, y si quien guarda no tiene el permiso `employees`,
usa su propio id **al crear** y **conserva el almacenado al editar**. La regla del origen del efectivo
sigue esa misma forma a propósito. Cualquier campo con sesgo por rol en este formulario debería
seguirla: es la que distingue *no puedes decidir esto* de *no puedes conservar lo que otro decidió*.

## 4. Registro de recogidas de caja

### 4.1 Modelo

Tabla nueva `ospos_cash_collections`:

```
collection_id   INT           PK, auto
amount          DECIMAL(15,2) NOT NULL
collected_at    TIMESTAMP     NOT NULL   -- cuándo salió el dinero, no cuándo se registró
collected_by    INT           NOT NULL   -- quién se llevó el dinero (administrador)
registered_by   INT           NOT NULL   -- quién anotó el movimiento
note            VARCHAR(255)  NOT NULL DEFAULT ''
deleted         TINYINT(1)    NOT NULL DEFAULT 0
```

**`collected_at` es la hora real del movimiento**, no la del turno ni la del registro. Es lo que
permite atribuir la recogida al turno correcto aunque se anote horas después.

**No lleva `cashup_id`.** La recogida pertenece al turno que estaba abierto en `collected_at`, y ese
turno se resuelve igual que para las ventas (sección 5). Guardar el turno además de la hora sería
guardar dos veces el mismo hecho, con el riesgo de que discrepen.

### 4.2 Reglas

- **`collected_by` tiene que ser un administrador.** Validado en el servidor contra el permiso
  `config`, no solo filtrando el desplegable.
- **Cualquiera con acceso a gastos puede registrar** una recogida — el cajero anota que el
  administrador se llevó el dinero. Por eso `registered_by` es distinto de `collected_by`.
- **Editable en cualquier momento**, no atada a la apertura ni al cierre.
- **Se muestra y se puede editar desde el cierre del turno**, para anotar lo que se olvidó durante la
  jornada.

### 4.3 Efecto contable

Una recogida **no es un gasto**: es un traslado. Nunca entra en el reporte de Ingresos vs Gastos ni en
ningún total de gastos. Su único efecto es sobre el efectivo esperado en el cajón:

```
efectivo esperado = apertura + ventas en efectivo − gastos con origen 'register' − recogidas
```

Y le da procedencia trazable al `collected` de la sección 3: cuando un administrador pague con
efectivo recolectado, esa plata salió del cajón tal día y la recogió tal persona.

## 5. Un solo turno abierto, y la venta sellada

### 5.1 La restricción

`Cashups::postSave()`, al crear un turno, **rechaza si ya existe otro con `status = 'open'`**.

Hoy los 40 turnos están cerrados, así que la restricción no bloquea nada al activarse.

### 5.2 La venta se sella al completarse

Columna nueva en `ospos_sales`:

```
cashup_id  INT  NULL
```

Se escribe en `Sale::save_value()` con el turno abierto en ese momento. **Queda fijo para siempre.**

**Por qué sellar en vez de cruzar por fecha.** Los turnos hoy se solapan — el 31 cierra el 14/08 a las
16:21 y el 32 abrió ese mismo día a las 13:08 — así que ningún cruce por ventana horaria es limpio
sobre el histórico. Y aunque la restricción de 5.1 lo evite hacia adelante, un cruce calculado
depende de fechas que alguien puede editar después; un sello no.

Coincide además con la regla del usuario: *"si una venta no se cerró en el turno que era, se aplica
para el turno que esté vivo cuando se cierre."*

**Si no hay ningún turno abierto**, la venta se guarda con `cashup_id = NULL` y aparece en el reporte
como venta sin turno. No se inventa una atribución.

## 6. Conciliación en el cierre

### 6.1 Lo que se agrega

```
Ingresos del turno    $932.915   ← bruto, por medio de pago
  Efectivo              603.115
  Datáfono              218.500
  Transferencia         111.300

Gastos de caja       −$344.800   ← solo cash_source = 'register'
Recogidas                  0     ← nuevo
Esperado en cajón     $258.315
Contado (declarado)   $257.850
Descuadre                −$465

Total (como hoy)      $587.650   ← sin cambios
```

**El Total no se toca.** Lleva 40 turnos calculándose así; cambiar la fórmula rompería la comparación
con el histórico. Se agregan las piezas que faltan, no se altera lo que ya existe.

**Todo lo nuevo es calculado y de solo lectura.** Nadie lo escribe.

### 6.2 De dónde sale cada cifra

| Cifra | Fuente |
|---|---|
| Ingresos por medio de pago | `sales_payments` de las ventas **completadas** con ese `cashup_id`, neto de vueltas (`payment_amount − cash_refund`) |
| Cobrado en anuladas | lo mismo, pero de las ventas con `sale_status = CANCELED`. Se muestra, **no** se suma al esperado (§11) |
| Gastos de caja | `expenses` con `cash_source = 'register'` cuya fecha cae en el turno |
| Recogidas | `cash_collections` cuyo `collected_at` cae en el turno |
| Esperado | apertura + efectivo − gastos de caja − recogidas |
| Contado | `closed_amount_cash`, lo que el cajero escribe |
| Descuadre | contado − esperado |

**Las vueltas ya están contempladas**: `cash_refund` es el cambio que se le devuelve al cliente
(`Sales.php:855`, `'cash_refund' => $data['amount_change']`), no una devolución de venta.

### 6.3 El autocompletado del cierre sigue usando el rango de fechas *(decidido el 2026-08-23)*

La conciliación lee los ingresos **del sello** (`cashup_id`), como dice 6.2. El autocompletado que
prellena `closed_amount_cash` **no se cambió** y sigue leyendo un rango de fechas.

**Por qué no se unificó.** Ese prellenado alimenta `closed_amount_total`, y el Total es justo lo que
se decidió no tocar (6.1). Cambiarle la fuente movería el Total guardado de cualquier turno que se
reabra.

**La consecuencia, dicha en voz alta:** en la misma pantalla conviven dos cifras de origen distinto.
Con la configuración de solo-fecha, `Cashups::getView()` trunca la ventana a días completos con
`substr($open_date, 0, 10)`, así que un turno de 14:26 a 20:54 se autocompleta con **todas** las
ventas del día; y el 31/07, el único día con dos turnos, cada uno tomaría las ventas del otro. **La
conciliación no tiene ese defecto**, y la diferencia entre ambas cifras es precisamente lo que
delata el problema.

Unificarlos es un trabajo aparte, porque exige decidir qué pasa con el Total histórico.

### 6.4 El bloque se refresca solo, sin recargar el formulario

Agregar o borrar una recogida repinta **únicamente** la tabla de recogidas y las filas de la
conciliación, vía un endpoint que devuelve JSON ya formateado.

No se recarga el formulario a propósito: el cajero puede estar a mitad de escribir los importes de
cierre cuando anota una recogida, y recargar se los llevaría. Es el mismo problema que hace
obligatoria la validación de cliente en 3.3.

El descuadre se calcula **en el servidor**, no en el navegador. Los importes llegan formateados según
el idioma del operador y `parse_decimals()` es lo único que los lee bien; hacer esa aritmética en
JavaScript sería reintroducir el bug de comparar contra representaciones traducidas.

### 6.5 El campo "Entrada/Salida de Efectivo" se retira

`transfer_amount_cash` sale del formulario. Su reemplazo es el registro de recogidas.

**La columna no se elimina de la base**: los 40 turnos la tienen en cero y la fórmula del Total la
usa. Quitarla cambiaría el Total histórico, que es justo lo que se decidió no tocar. Se deja de
escribir y se deja de mostrar.

## 7. Regularización del histórico

**Migración, con reporte de lo que toca y con `down()`.** Mismo criterio que la reparación de
entidades: respaldo antes, conteos después, y nada se asume en silencio.

**a) 55 gastos en efectivo → `cash_source = 'register'`.** Los registró el cajero, que solo alcanza el
cajón, y la aritmética lo respalda: el cuadre por día funciona precisamente porque salieron de ahí.
Las 2 transferencias quedan en `NULL`.

**b) Las 2 recogidas confirmadas se cargan como los primeros registros**: $1.000.000 el 17/08 y
$800.000 el 21/08, ambas con Rodrigo Tovar como `collected_by`. Con eso los turnos 35 y 39 dejan de
aparecer como anomalías y quedan con residuos de $2.900 y $370 — dentro del ruido normal.

**c) Las 792 ventas existentes reciben `cashup_id` por ventana horaria donde sea inequívoco.** Donde
los turnos se solapan (13 al 15 de agosto) **se dejan en `NULL` y se reportan**. La migración imprime
cuántas quedaron sin turno.

**d) No se toca ningún importe.** La regularización clasifica y ata; no corrige plata.

**Después de desplegar**, revisar contra el reporte qué quedó sin explicar y cargar las recogidas que
falten. El turno 18 del 31/07 (−$708.775) es el primer candidato, **pero su cifra no es confiable**
hasta que las ventas de ese día —el único con dos turnos— queden separadas por el sello de 5.2.

## 8. Archivos a tocar

**Origen del efectivo**
1. Migración: columna `cash_source` + backfill de los 55.
2. `app/Models/Expense.php` — `$allowedFields` (ojo: CI4 descarta en silencio lo que no esté ahí).
3. `app/Controllers/Expenses.php` — decisión por rol en el servidor, y filtro nuevo en la grilla.
4. `app/Views/expenses/form.php` — campo condicionado al medio de pago y al rol.
5. `app/Helpers/tabular_helper.php` — columna en la grilla de Gastos.

**Recogidas**
6. Migración: tabla `cash_collections` + carga de las 2 confirmadas.
7. `app/Models/Cash_collection.php` — nuevo.
8. `app/Controllers/Cash_collections.php` — nuevo, con su permiso y su entrada de menú.
9. `app/Views/cash_collections/` — grilla y formulario.

**Turno y venta**
10. Migración: columna `cashup_id` en `sales` + backfill por ventana.
11. `app/Controllers/Cashups.php` — rechazo de segundo turno abierto.
12. `app/Models/Sale.php` — sellar `cashup_id` al guardar.
13. `app/Views/cashups/form.php` — retirar `transfer_amount_cash`, agregar el bloque de conciliación.
14. `app/Controllers/Cashups.php` — cálculo de la conciliación.

**Idiomas:** `en`, `es-ES` y **`es-MX`**, que es el que corre esta instalación.

**Commits separados por pieza.** Son cuatro temas distintos aunque se trabajen seguidos.

## 9. Pruebas

- Un cajero guarda un gasto en efectivo → queda `register` aunque el POST diga otra cosa.
- Un administrador guarda sin elegir origen → **se rechaza**.
- Un gasto por transferencia → `cash_source` queda `NULL`, no `register`.
- Abrir un turno con otro abierto → se rechaza.
- Una venta completada se sella con el turno abierto; sin turno abierto queda en `NULL`.
- Una recogida con `collected_by` no administrador → se rechaza.
- El esperado en cajón cambia al agregar una recogida, y el descuadre se mueve en consecuencia.
- Una recogida registrada con hora de ayer cae en el turno de ayer, no en el de hoy.
- El Total del turno **no cambia** con ninguna de las piezas nuevas.
- Backfill: los 55 quedan `register`, las 2 transferencias `NULL`, y el conteo se reporta.
- Los turnos 35 y 39 pasan de −$1.002.900 y −$800.370 a −$2.900 y −$370.

## 9b. Trampas operativas confirmadas al desplegar en staging (2026-08-23)

**El `php spark migrate` como root deja la aplicación ciega el resto del día.** El log del día queda
con dueño `root` y permisos `-rw-rw----`; Apache corre como `www-data` y **no puede escribir en él**,
así que a partir de ese momento ninguna excepción web deja rastro. Es la explicación del 500 de
`item_kits/save` que quedó sin diagnosticar, y de dos 500 de este trabajo.

Después de migrar, devolver el dueño:

```
docker compose exec ospos chown www-data:www-data writable/logs/*.log
```

**La compilación de assets no es opcional en ningún despliegue**, ni siquiera cuando "solo cambió
PHP". `git reset --hard` restaura `app/Views/partial/header.php` a la versión del repositorio y borra
los tags que inyecta gulp-inject: jQuery deja de cargar y la aplicación queda inservible. Confirmado
en staging.

**Guardar un gasto exige el permiso `items_stock`.** `Expense::save_value()` pasa por
`Item_lib::get_item_location()`, que resuelve la ubicación desde los permisos del usuario y
desreferencia el resultado sin comprobarlo (hay un TODO en `Stock_location.php` admitiéndolo). Un
empleado con `expenses` pero sin `items_stock` produce un 500 al guardar. **Los seis empleados de
producción lo tienen**, así que hoy nadie está expuesto; queda anotado como fragilidad latente.

## 9c. Despliegue a producción (2026-08-24)

**Los contenedores corren las migraciones al arrancar.** `AGENTS.md` decía que hay que ejecutarlas a
mano por SSH; en la práctica el `docker compose up --build` ya las dispara. El `php spark migrate`
posterior no encuentra nada nuevo y devuelve "Migrations complete" **sin una sola línea de reporte**,
que es la única señal de que ya habían corrido.

Consecuencia real: el backfill de ventas corrió **antes** de que se pudiera limpiar el turno 18, con
él todavía abierto, y le atribuyó 247 ventas. Se reparó vaciando `sales.cashup_id` y borrando la
fila de esa migración en `ospos_migrations` para que volviera a ejecutarse sola. **Cualquier arreglo
de datos que deba preceder a una migración hay que hacerlo antes de levantar los contenedores.**

**El turno 18 era un fantasma.** Abierto el 31/07 a las 21:09, seis minutos antes de cerrar el 17,
con su mismo importe de apertura y todos los cierres en cero. La continuidad de caja va del 17
(cierre 710.300) al 19 (apertura 710.300): el 18 nunca estuvo en la cadena, y las 8 ventas de ese día
ocurrieron dentro del 17. Se cerró en ceros con fecha de cierre igual a la de apertura — ventana de
duración cero, para que no absorba ventas. **No se eliminó**: sigue visible con `deleted = 0`.

Cuidado al cerrarlo: el formulario venía autocompletado con **13.200.467** (todas las ventas desde el
31/07) y fecha de cierre de hoy. Enviarlo tal cual habría inventado ese cierre.

### Resultado del backfill

| Cifra | Valor |
|---|---|
| Ventas con turno asignado | 530, repartidas en 30 turnos |
| Ventas sin turno | 278 (4 ambiguas, 274 sin ventana que las cubra) |
| Gastos con `cash_source = 'register'` | 58 |
| Recogidas cargadas | 2, por $1.800.000 |

Las 274 sin turno tienen una causa operativa, no técnica: **los turnos se abren al final de la
jornada**, así que las ventas del día ocurren antes de que exista la ventana. El turno 39 (21/08)
duró 44 segundos y por eso su cuadre no puede mostrar ingresos.

### Lo que el cuadre destapó de inmediato

El turno 35 (17/08) pasó de un Total de −$951.800 sin explicación a: recogido −$1.000.000, esperado
$212.500, contado $226.500, **descuadre $14.000**.

## 10. Despliegue

Varias migraciones, así que **no termina con el workflow**: hay que lanzar `php spark migrate` por
SSH. Build de assets antes de cualquier `up --build` manual. **Producción solo fuera del horario de
operación**, con respaldo previo de `ospos_expenses`, `ospos_sales` y `ospos_cash_up`.

**Verificación con datos reales:** el reporte de turnos no puede contradecir al de Transacciones para
el mismo rango, y los turnos que hoy cuadran no pueden dejar de cuadrar.

---

## 11. El cuadre contaba los pagos de las ventas anuladas *(2026-09-20)*

### 11.1 El síntoma

El turno 70 cerró el 2026-09-20 mostrando un faltante de **$75.200**. El cajón estaba
**exacto**: apertura 1.887.750 + efectivo real 299.386 − gastos 84.500 = 2.102.636, que es
justo lo que se contó. Los $75.200 eran una venta cobrada y anulada a las 17:16.

### 11.2 Dos rutas, una sola filtra

En la misma pantalla conviven dos cálculos con fuentes distintas:

| | Ruta | Filtra por estado | Acota por |
|---|---|---|---|
| Lo que se **guarda** al cerrar | `Cashups::getView()` → `Summary_payments::getData()` | Sí, `COMPLETED` | rango de fechas |
| Lo que se **muestra** en el panel | `Cashups::_build_reconciliation()` → `Sale::get_payments_by_cashup()` | **No** | `cashup_id` |

Por eso `closed_amount_check` quedó en $317.600 (correcto) mientras el panel mostraba $417.700
en la misma pantalla: la prueba visual de que eran dos consultas distintas.

Nada de la ruta B se persiste — el panel es de solo lectura. **No hubo datos corrompidos y no
hubo filas que reparar.**

### 11.3 Por qué el pago sobrevive a la anulación

`Sale::delete()` devuelve el inventario si la venta estaba `COMPLETED`, marca `CANCELED` y
termina: **no toca `sales_payments` ni `cashup_id`**, que se asignó al completarse (§5.2). Es
deliberado — una caja necesita el registro de que ese dinero se movió.

Existe otra vía, `Sale::clear_suspended_sale_detail()`, que **sí** borra los pagos. De ahí que no
toda anulación haga daño: de 53 anuladas con `cashup_id` en producción, 42 no tenían pagos
(inofensivas) y 11 sí (las que distorsionaban).

### 11.4 El arreglo, y por qué no se borra nada

`get_payments_by_cashup()` pasa a filtrar `sale_status = COMPLETED`, la misma regla que ya
aplican `Summary_payments`, `Detailed_sales` e `Income_expenses`.

**Lo anulado no se descarta en silencio**: `Sale::get_voided_payments_by_cashup()` lo devuelve
aparte y el panel lo nombra en su propia línea, con la advertencia de que no cuenta como ingreso.
Si ese efectivo sigue físicamente en el cajón, ahora aparece como **sobrante** — una pregunta que
alguien puede responder — en vez de quedar absorbido en el esperado.

Ambos métodos comparten `payments_by_cashup(int $cashup_id, int $sale_status)`, privado.

`sealed_sales` pasa a ser `$income !== [] || $voided !== []`: un turno cuyas únicas ventas selladas
se anularon sí tiene ventas asociadas, y no debe caer en el aviso de "turno sin ventas".

### 11.5 Radio de impacto verificado

- **Nada guardado cambia.** El panel no escribe.
- **Los informes no se tocan.** Ya filtraban por `COMPLETED`.
- **La lista de Turnos no se toca.** Usa `closed_amount_total`.
- **Las devoluciones siguen contando.** Son `COMPLETED` con pago negativo; ese efectivo sí sale
  del cajón. Cubierto por prueba.
- **Las vueltas siguen netas.** `payment_amount − cash_refund` no cambió. Cubierto por prueba.
- **Ningún estado distinto de `COMPLETED`/`CANCELED` tiene `cashup_id`** — verificado en los dos
  esquemas de producción (`ospos` y `tenant_paraisodelacanasta`) antes de escribir el filtro.
- **Paraíso no tiene ni una anulada con turno**: para ese negocio el cambio es un no-op.

### 11.6 Alcance histórico

11 ventas en 8 turnos desde el 2026-07-16, $598.500 en total, de los cuales **$352.300 en
efectivo** (lo único que mueve el esperado del cajón; datáfono y transferencia solo inflaban el
"Ingresos del turno").

De los 7 turnos cerrados afectados, **5 cambian de signo** al corregirse:

| Turno | Día | Mostraba | Era en realidad |
|---|---|---|---|
| 2 | 16 jul | Faltante $51.060 | Sobrante $23.740 |
| 29 | 11 ago | Faltante $28.900 | Cuadre exacto, $0 |
| 56 | 6 sep | Faltante $1.755 | Sobrante $11.245 |
| 61 | 11 sep | Faltante $151.850 | Sobrante $3.850 |
| 63 | 13 sep | Sobrante $56.250 | Sobrante $69.250 |
| 65 | 15 sep | Faltante $34.530 | Faltante $30 |
| 69 | 19 sep | Faltante $22.493 | Sobrante $9.907 |
| 70 | 20 sep | Faltante $75.200 | Cuadre exacto, $0 |

### 11.7 Pruebas

`tests/Models/SalePaymentsByCashupTest.php`. La prueba que importa no es que deje de sumar
anuladas, sino que **un turno sin anuladas no se mueva ni un peso** y que devoluciones y vueltas
sigan restando. Limpia solo sus propias filas (`cashup_id = 910001`), nunca con `truncate`: las
tablas de ventas se comparten con el resto de la suite.

`cashup_id` no tiene clave foránea, así que la prueba puede sellar contra un turno ficticio sin
crear la fila en `cash_up`.

### 11.8 Deuda que este arreglo NO toca

La ruta A acota por rango de fechas y la B por `cashup_id`. Con un turno por día coinciden, pero el
2026-07-31 hubo dos turnos y cada uno contaría las ventas del otro (§6.3 ya lo anticipaba).
`cashup_id` es la fuente más correcta de las dos.

La venta 48 (16 jul) pagó $74.800 contra un bruto de $46.900 y sin movimientos de devolución de
inventario. Caso suelto de la primera semana, sin explicar, sin efecto sobre este arreglo.

### 11.9 Certificación en staging por navegador *(2026-09-20, 22:30-22:40)*

Las pruebas unitarias cubren el modelo, no la pantalla. Para cerrar esa brecha se certificó sobre
staging desplegado, con Chrome, reproduciendo la condición **por la vía real** en vez de sembrarla
con SQL:

1. Turno 5 abierto desde la interfaz con apertura de $100.000.
2. Venta de $34.000 en efectivo, cobrada y completada en la caja.
3. Segunda venta de $18.000 en efectivo, cobrada y completada.
4. Cuadre leído **antes** de anular: ingresos $52.000, esperado $152.000, descuadre $0. **Sin línea
   de anuladas** — el bloque no se dibuja cuando no hay ninguna.
5. La venta de $18.000 anulada desde su pantalla de edición (`/sales/edit/<id>`, botón «Eliminar»),
   que es el camino que usa el negocio.
6. Verificado en la base que quedó exactamente la condición de producción: `sale_status = 2`, con
   su pago de $18.000 y su `cashup_id` intactos.

Resultado del panel:

```
Ingresos del turno                        $34.000
  Efectivo                                $34.000
Cobrado en ventas anuladas (no cuenta)    $18.000
  Efectivo                                $18.000
  «Este dinero se cobró y después se anuló la venta...»
Apertura                                 $100.000
Esperado en el cajón                     $134.000
Contado (declarado)                      $134.000
Descuadre                                      $0
```

Antes del arreglo ese mismo turno habría dicho esperado $152.000 y, con $134.000 en el cajón, un
faltante fantasma de $18.000. La pestaña «Cuadre del cajón» se abrió con un clic real, no forzada
por script, y la consola no reportó ni un error (solo un aviso de accesibilidad de Bootstrap que ya
existía).

**Acceso usado**: la cuenta `soporte_micronuba`, cuya contraseña almacenada es el texto fijo
`*sin-contrasena-usa-la-plataforma*` y por tanto no puede iniciar sesión. Se le puso una contraseña
temporal solo para esto y se restauró el texto original al terminar, verificando después que el
login vuelve a ser rechazado. **No se tocó la credencial de ninguna persona.**

**Lo que quedó en staging**: el turno 5 borrado (`deleted = 1`, cero turnos abiertos) y dos ventas
de prueba, la 990002 completada de $34.000 y la 990003 anulada de $18.000. Se dejan a propósito:
son el escenario de regresión para la próxima vez que haya que mirar esta pantalla.
