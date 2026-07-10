# Diseño técnico — Ventas en paralelo por mesa/cliente (pestañas en Register)

- **Alcance funcional:** [`docs/Funcional/ventas-en-paralelo-pestanas.md`](../Funcional/ventas-en-paralelo-pestanas.md) (leer primero)
- **Estado:** Implementado y verificado funcionalmente en navegador (3+ mesas reales, alternancia repetida, resiliencia ante reinicio, cobro) — ver sección 9
- **Fecha:** 2026-07-09 (revisado 2026-07-10 tras verificar el código exacto con agentes de exploración — ver sección 4; verificación funcional y corrección de 2 bugs reales — ver sección 9)

## 1. Principio rector

**Cada mesa dada de alta es, uno a uno, una pestaña posible en el Register.** No se introduce un concepto de "pestaña" nuevo en la base de datos — la pestaña es una proyección visual de una venta con `dinner_table_id` asignado y estado `OPENED`. El identificador de la pestaña en el frontend es directamente `dinner_table_id` (o `sale_id` una vez creada la venta).

## 2. Modelo de datos

### 2.1 Nuevo estado `OPENED`

```php
// app/Config/Constants.php
const COMPLETED = 0;
const SUSPENDED = 1;
const CANCELED = 2;
const OPENED = 3;   // NUEVO — cuenta de mesa abierta (pestaña en Register)
```

- `sale_status` es `tinyint(1)` sin `ENUM`/`CHECK` (confirmado en `app/Database/Migrations/sqlscripts/3.4.0_database_optimizations.sql:76`) → **no requiere migración de esquema**, solo el nuevo valor de constante.
- `SUSPENDED` sigue significando exactamente lo mismo que hoy (Cotización, Orden de Trabajo, "Suspender" sin mesa) — no se toca su significado ni sus usos existentes.
- `OPENED` se usa **únicamente** cuando `sale_type = SALE_TYPE_POS` (o el tipo que corresponda) **y** `dinner_table_id IS NOT NULL`.

### 2.2 Por qué el costo es bajo (verificado archivo por archivo)

De los 9 archivos que usan `SUSPENDED` hoy:

**No requieren cambio (6 archivos — reportes):** `Specific_supplier.php`, `Specific_discount.php`, `Specific_customer.php`, `Specific_employee.php`, `Detailed_sales.php`, `Summary_report.php`. Todos filtran `sale_status = SUSPENDED` siempre junto con `sale_type = SALE_TYPE_QUOTE` o `SALE_TYPE_WORK_ORDER` explícito — nunca consultan el caso genérico, así que las cuentas de mesa ya son invisibles ahí hoy y seguirán siéndolo. **No entran en el alcance de este cambio** (si en el futuro se pide que las mesas SÍ aparezcan en estos reportes, es un requerimiento aparte).

**Sí requieren cambio (3 archivos, puntual):**

| Archivo | Función/línea | Cambio |
|---|---|---|
| `app/Config/Constants.php` | — | Agregar `const OPENED = 3;` |
| `app/Models/Sale.php` | nuevo `get_all_opened()` | Método dedicado (no se mezcla con `get_all_suspended()`), filtrado `sale_status = OPENED`, con JOIN a `dinner_tables` — alimenta la barra de pestañas vía `_reload()` (ver sección 4). |
| `app/Models/Sale.php` | nuevo `get_open_sale_by_table(int $dinner_table_id): ?int` | Lookup "¿esta mesa ya tiene una venta `OPENED`?" — no existe hoy, es la pieza que falta para que cambiar de mesa cargue el carrito correcto (ver 4.2, hipótesis del issue #1933). |
| `app/Models/Sale.php` | `get_suspended_sale_info()` (~L1364-1368) | Lookup por `sale_id` al reanudar/cargar una pestaña → aceptar `sale_status IN (SUSPENDED, OPENED)`, ya que `copy_entire_sale()` la usa. |
| `app/Controllers/Sales.php` | botón "Suspender" (`postSuspend`, ~L1606) | **No se modifica.** Sigue creando siempre `SUSPENDED`, igual que hoy (cotizaciones, órdenes de trabajo, o "guardar para después" sin mesa). Para mesas, el autoguardado (sección 3) ya cubre la persistencia sin depender de este botón — no se le agrega ni se le quita comportamiento. |

Las vistas (`register.php`, `suspended.php`) no tienen comparaciones de estado hardcodeadas — no requieren cambio directo por este punto (sí por la barra de pestañas nueva, ver sección 5).

## 3. Autoguardado (persistencia inmediata)

**Requisito funcional:** una pestaña abierta debe sobrevivir a una desconexión o reinicio del sistema sin perder más que la última acción en curso.

**Estado actual:** el carrito vive solo en sesión (`Sale_lib::get_cart()/set_cart()`, session-scoped) hasta que se llama a `Sale::save_value()` — que hoy solo ocurre en Suspender/Cancelar/Completar. Si el sistema se cae mientras se están agregando items a una pestaña recién abierta, se pierde todo lo no guardado.

**Cambio necesario:**

1. **Al abrir una mesa** (primer item agregado, o al seleccionar la mesa): crear inmediatamente la fila `Sale` con `sale_status = OPENED`, `dinner_table_id` asignado, carrito vacío o con el primer item.
2. **En cada mutación del carrito de una pestaña `OPENED`** (agregar item, editar cantidad/precio, quitar item, cambiar cliente, agregar comentario): llamar a `Sale::save_value()` de inmediato contra esa fila (`UPDATE`, no `INSERT` nuevo), en el mismo request que hoy solo actualiza la sesión.
   - Los endpoints existentes (`Sales::postAdd`, `postEditItem`, `getDeleteItem`, `postSelectCustomer`, `postSetComment`) ya son llamadas AJAX individuales por acción — el cambio es que, cuando el contexto activo es una pestaña `OPENED`, además de tocar la sesión, cada uno persiste. No hace falta un mecanismo de autoguardado por timer/polling aparte.
3. Esto **no aplica** al modo de venta normal sin mesa (ticket simple de mostrador) — ese sigue funcionando como hoy (todo en sesión hasta Completar/Suspender), porque no tiene el requisito de "sobrevivir mientras se atienden otras cuentas en paralelo".

## 4. Corrección tras verificar el código exacto (2026-07-10)

Se lanzaron 3 agentes de exploración para confirmar contra el código real (no memoria de sesiones previas) cómo funciona hoy `Sale_lib`, `Sales.php` y `register.php`. Esto **reemplaza** el diseño original de esta sección (endpoints AJAX/JSON nuevos) por uno más simple, apoyado en mecanismos que ya existen.

### 4.1 Cómo funciona el Register hoy, en realidad

**Todo el módulo usa el patrón "POST de formulario completo → el servidor re-renderiza `sales/register.php` entero"**, vía el método privado `Sales::_reload()` (`app/Controllers/Sales.php:1188-1296`). No hay una API JSON parcial para el carrito: agregar item, editar, borrar, cambiar cliente, cambiar modo/mesa — todos hacen un `$('#algun_form').submit()` real y el servidor devuelve la vista `sales/register` completa. Confirmado con grep: no hay ningún `isAJAX()` en `Sales.php`; la decisión JSON-vs-vista-completa es fija por endpoint (los ~14 que mutan el carrito siempre devuelven la vista completa vía `_reload()`).

**Ya existe código que hace casi exactamente "cambiar de pestaña sin perder nada":** `Sales::postUnsuspend()` (`app/Controllers/Sales.php:1641-1654`) hace:
```php
$this->sale_lib->clear_all();
$this->sale_lib->copy_entire_sale($sale_id);
// ... y termina en _reload()
```
`Sale_lib::copy_entire_sale(int $sale_id)` (`app/Libraries/Sale_lib.php:1357-1400`) ya reconstruye cart/payments/customer/comment/dinner_table/sale_type completos desde una venta persistida y los deja en sesión.

### 4.2 Por qué NO hace falta indexar múltiples carritos en sesión

`Sale_lib` guarda todo el estado de la venta activa en ~30 claves de sesión planas (`sales_cart`, `dinner_table`, `sales_customer`, `sales_comment`, `sales_payments`, `sale_id`, etc.) — un único store por sesión de cajero, sin namespacing por mesa. Con autoguardado (sección 3) ya garantizado, esto deja de ser un problema: cuando se cambia de pestaña, lo que se está por descartar de la sesión **ya está guardado en la base** (se guardó en la última mutación). `clear_all()` + `copy_entire_sale()` deja de ser "perder la pestaña anterior" y pasa a ser "descartar de la sesión algo que ya está seguro, y cargar la otra pestaña" — sin tocar la forma de `Sale_lib`.

### 4.3 Hipótesis fundamentada del bug #1933 ("solo funciona con 2 mesas")

`Sales::postChangeMode()` (`app/Controllers/Sales.php:254-296`), al cambiar de mesa, usa `Dinner_table::swap_tables()` para intercambiar **flags de ocupación** entre la mesa liberada y la ocupada — pero **nunca verifica si la mesa destino ya tiene una venta guardada, ni carga su contenido**. Hoy, cambiar el selector de mesa simplemente reetiqueta el carrito activo con el nuevo número de mesa, en vez de cargar el carrito real de esa mesa. Con 2 mesas esto puede pasar desapercibido; con 3+ es plausible que se mezclen/pisen datos. **Se corrige como parte de este desarrollo** (sección 4.4), no aparte.

### 4.4 Diseño final — sin endpoints nuevos

**`postChangeMode()`**, al cambiar `dinner_table`, reemplaza la lógica de `swap_tables()` por:
1. `$existing_sale_id = $this->sale->get_open_sale_by_table($occupied_dinner_table);`
2. Si existe y es distinto de la venta activa actual → mismo patrón que `postUnsuspend()`: `$this->sale_lib->clear_all(); $this->sale_lib->copy_entire_sale($existing_sale_id);`
3. Si no existe (mesa vacía) → comportamiento actual: `clear_all()` + `set_dinner_table($occupied_dinner_table)`, carrito nuevo vacío (la fila `Sale` se crea recién en la primera mutación, vía autoguardado).

**Autoguardado**: nuevo método privado `Sales::_autosave_open_tab(): void`, llamado al final de `postAdd`, `postEditItem`, `getDeleteItem` (antes del `_reload()` final) — solo si hay mesa activa, hace el mismo `save_value(...)` que ya hace `postSuspend()` pero con `$sale_status = OPENED`.

**Barra de pestañas**: `_reload()` agrega `$data['open_tabs'] = $this->sale->get_all_opened();` al array que ya arma — la vista la pinta en el mismo render, sin request adicional.

## 5. Frontend — `app/Views/sales/register.php`

- Nueva barra de pestañas usando `$open_tabs`, ubicada junto al bloque `#mode_form`/selector de mesa (~L76-83).
- **No hace falta JS/AJAX nuevo.** Cada pestaña, al hacer click, fija el valor del `<select name="dinner_table">` a la mesa correspondiente y dispara el mismo `$('#mode_form').submit()` que ya usa el `onchange` del selector (L81) — reutiliza el mecanismo existente tal cual.
- Pestaña activa resaltada visualmente (comparar contra `$selected_table`, ya disponible en `_reload()`).
- Al completar el pago de una pestaña, esta desaparece de la barra en el siguiente render (la mesa se libera vía `Dinner_table::release()`, ya existe).
- CSRF: resuelto automáticamente por el override global en `app/Views/partial/header_js.php:51-64,83-88` para cualquier `.submit()` — no requiere manejo adicional.

## 6. Validar issue #1933 ("solo funciona con 2 mesas")

**Validado el 2026-07-10 contra el contenedor local (`casaletto.local:8090`) con 3 mesas reales — el bug NO se reproduce** con el diseño final de esta sección (ver 9.2 para el detalle de la corrección que lo garantiza). Se dieron de alta Mesa 1/2/3, se abrió una pestaña en cada una con un item distinto, se alternó repetidamente entre las tres (incluida la secuencia A→C→B→A→C) y ninguna perdió ni mezcló sus items.

## 7. Fuera de alcance de este diseño

- Cambios al flujo de pago/cobro en sí (se mantiene igual).
- Multi-usuario simultáneo sobre la misma mesa (ver `docs/Funcional/`, sección 4).
- Reportes: las pestañas (`OPENED`) no se agregan a los reportes existentes salvo pedido explícito posterior.

## 8. Archivos a tocar (resumen final)

- `app/Config/Constants.php` — nueva constante `OPENED = 3`.
- `app/Models/Sale.php` — `get_all_opened()` nuevo, `get_open_sale_by_table()` nuevo, `get_suspended_sale_info()` extendido a `IN (SUSPENDED, OPENED)`. `save_value()` sin cambios de firma (ya acepta `sale_status` como parámetro).
- `app/Models/Dinner_table.php` — reutiliza `occupy()`/`release()`/`is_occupied()`/`get_empty_tables()` existentes sin cambios de firma.
- `app/Controllers/Sales.php` — `postChangeMode()` reescrito (secciones 4.4 y 9.1), `_autosave_open_tab()` nuevo + 3 llamadas (`postAdd`, `postEditItem`, `getDeleteItem`), `_reload()` con `open_tabs` agregado. **Sin endpoints nuevos.**
- `app/Libraries/Sale_lib.php` — sin cambios de forma; solo se hizo `get_sale_id()` nula-segura (sección 9.3). Se sigue usando tal cual (`clear_all()`, `copy_entire_sale()`, getters/setters existentes).
- `app/Views/sales/register.php` — barra de pestañas + botón que reutiliza el submit existente del selector de mesa (con el fix de la sección 9.2 para mesas ocupadas). **Sin AJAX nuevo.**

## 9. Verificación funcional y bugs reales encontrados (2026-07-10)

Tras la implementación (3 agentes en paralelo + integración), se corrió el ciclo completo en el contenedor local (`docker-compose.local.yml`, `casaletto.local:8090`, DB fresca migrada desde cero): login real, alta de 3 mesas reales en Config, alta de 3 items reales, apertura de pestaña en cada mesa, alternancia repetida, `docker restart` con una mesa sin cobrar, y cobro final. Este ciclo expuso **dos bugs reales** en la primera implementación que un repaso de código no había detectado — ambos corregidos y re-verificados en el mismo ciclo.

### 9.1 Bug 1 — `postChangeMode()` no vaciaba el carrito al ir a una mesa vacía

La primera versión de `postChangeMode()` (sección 4.4) sólo llamaba `clear_all()` en la rama "la mesa destino ya tiene una venta" (para cargar `copy_entire_sale()`). En la rama "mesa vacía" solo hacía `set_dinner_table($occupied_dinner_table)` — **sin** `clear_all()` antes. Resultado: cambiar de Mesa 1 (con items) a Mesa 2 (vacía) dejaba el carrito de Mesa 1 visualmente "pegado" a Mesa 2 hasta el primer `postAdd()`, que entonces autoguardaba los items de Mesa 1 bajo el `dinner_table_id` de Mesa 2. Es exactamente el patrón del issue #1933 reproducido en código propio, no heredado.

**Corrección:** `clear_all()` se llama siempre que la mesa seleccionada cambia respecto a la mesa activa en sesión (se compara `$occupied_dinner_table` contra `Sale_lib::get_dinner_table()`, no contra `sale_id`, para no vaciar el carrito cuando el cajero simplemente reselecciona la mesa ya activa). Luego, según corresponda, `copy_entire_sale()` o `set_dinner_table()`. Código final en `Sales::postChangeMode()`.

### 9.2 Bug 2 — el botón de pestaña no podía cambiar a una mesa ya ocupada

El `<select name="dinner_table">` del Register solo lista mesas **libres** (`$empty_tables`, filtrado en el controlador — comportamiento preexistente, no tocado por este desarrollo). El JS original de la barra de pestañas (sección 5) hacía `$("select[name='dinner_table']").val(tableId)` para fijar la mesa antes de enviar el formulario — pero si `tableId` corresponde a una mesa **ocupada** (el caso normal: cambiar a una pestaña que ya tiene una cuenta abierta), no existe ese `<option>` en el DOM y `.val()` falla en silencio, dejando el `<select>` en su primera opción real (p. ej. "Delivery"). Esto rompía el caso de uso central de la feature: literalmente no se podía volver a una pestaña ya abierta haciendo click en ella.

**Corrección:** el handler de click ahora agrega una `<option>` descartable al `<select>` si el valor no existe todavía, antes de fijar `.val()` y enviar el formulario (el `<option>` no necesita persistir ni sincronizarse visualmente con el widget `selectpicker`, porque la página se recarga completa inmediatamente después). Código final en `app/Views/sales/register.php`, handler `.open_tab_button`.

### 9.3 Fix adicional — `Sale_lib::get_sale_id(): int`

`postChangeMode()` ahora llama a `get_sale_id()` en un punto del ciclo de vida donde, para una sesión recién autenticada que nunca pasó por `clear_all()`, la sesión no tiene `sale_id` — el método (preexistente, tipado `int` estricto) devolvía `null` de la sesión y disparaba un `TypeError`. Se corrigió para devolver `NEW_ENTRY` (mismo valor por defecto que usa `clear_all()`) cuando la sesión no tiene el valor todavía, sin cambiar el contrato para el resto de los llamadores (que ya siempre corren después de `clear_all()`).

### 9.4 Resultado de la verificación funcional completa

- ✅ Apertura de pestaña por mesa, autoguardado inmediato (`sale_status = OPENED`, fila creada al primer item).
- ✅ Cambiar de mesa sin cobrar preserva el carrito de la mesa anterior intacto (tras 9.1).
- ✅ 3 mesas alternadas repetidamente sin pérdida ni mezcla de items (issue #1933 no se reproduce — sección 6).
- ✅ Prueba de resiliencia: con una mesa con items sin cobrar, `docker restart` del contenedor `ospos` → al volver a entrar, la pestaña sigue en la barra con sus items intactos (confirma que el autoguardado no depende de la sesión del navegador, solo de la fila persistida).
- ✅ Cobrar una mesa la hace desaparecer de la barra de pestañas y la libera como mesa disponible en el selector; las demás pestañas abiertas no se ven afectadas.

## 10. Crear mesas nuevas directamente desde Register (2026-07-10)

**Pedido del usuario:** poder agregar mesas nuevas, con nombre, sin salir de la pantalla de Sales (antes solo era posible desde Config > Table). Investigación previa confirmó que **no existía ningún límite real de cantidad de mesas** en ninguna capa (JS/controlador/modelo/BD) — el único límite es el tamaño de columna `name varchar(30)`. Lo que faltaba era la conveniencia de crearlas sin ir a Config.

### 10.1 Diseño

- Botón "+ New table" (`Sales.new_table`) al final de la barra de pestañas (`open_tabs_bar`), visible siempre que `dinner_table_enable` esté activo — no depende de que ya existan pestañas abiertas.
- Al hacer click, un `prompt()` del navegador pide el nombre (`Sales.new_table_prompt`); si el usuario cancela o deja vacío, no pasa nada.
- El nombre se envía por POST a un formulario dedicado (`#new_table_form`, action `sales/createTable`) — **separado** de `#mode_form` a propósito, porque `#mode_form` ya tiene una acción fija (`sales/changeMode`) usada por mode/table/stock_location; mezclar una tercera acción ahí hubiera sido más confuso que un form chico aparte.
- `Sales::postCreateTable()` (nuevo): si `dinner_table_enable` está activo y el nombre no es vacío, crea la mesa vía `Dinner_table::create()` (nuevo método, hace `insert()` + devuelve `insertID()` — distinto de `save_value()`, que está pensado para el guardado masivo de Config y no expone el id nuevo), y la deja seleccionada como pestaña activa (`clear_all()` + `set_dinner_table()`, mismo patrón que `postChangeMode()`).
- El nombre se trunca a 30 caracteres en el controlador (`mb_substr($table_name, 0, 30)`) para respetar la columna `varchar(30)` sin que el insert falle — la validación de duplicados/caracteres prohibidos sigue siendo solo client-side en Config (deuda preexistente, no introducida ni resuelta por este cambio).

### 10.2 Bug real encontrado durante esta verificación: JS roto en el contenedor local por revertir artefactos de build

Al cerrar el ciclo anterior (Fase 4), se revirtieron `app/Views/login.php` y `app/Views/partial/header.php` con `git checkout --` para no commitear los `<script>`/`<link>` que `gulp-inject` escribe ahí en cada build (nombres de archivo con hash, específicos de cada corrida local — correctamente excluidos del commit). Pero esos tags **no son solo un artefacto cosmético**: son la única forma en que jQuery, moment y el resto del bundle se cargan en producción (`CI_ENVIRONMENT=production` usa la rama `inject:prod:js`, vacía tras el revert). El contenedor se reconstruyó con esos archivos revertidos y quedó sirviendo páginas **sin ningún JS** (confirmado con `Uncaught ReferenceError: $ is not defined` / `jQuery is not defined` en consola) — rompiendo silenciosamente todo el register, no solo la feature nueva.

**Corrección/aprendizaje:** `git checkout --` esos dos archivos es seguro para el propósito de "no ensuciar el commit", pero **debe ir seguido de un rebuild de assets (`npx gulp ...`) antes de reconstruir la imagen de nuevo**, para que el contenedor local siga sirviendo JS/CSS real. No alcanza con revertir y listo — el working tree necesita quedar con los inject reales para cualquier build posterior, aunque esos dos archivos nunca se commiteen.

### 10.3 Verificación

Se creó una mesa "Terraza" desde el botón nuevo, quedó seleccionada automáticamente, se le agregó un item, y se confirmó en base que autoguardó correctamente (`sale_status = OPENED`, `dinner_table_id` apuntando a la mesa recién creada) y que apareció en la barra de pestañas junto a las demás mesas ya abiertas.

## 11. Bug real: "Suspend" dejaba mesas ocupadas pero invisibles (2026-07-10)

**Reporte del usuario:** tras varias horas de pruebas manuales, algunas mesas quedaban "grabadas" — ocupadas, pero sin aparecer en la barra de pestañas ni liberarse. Pidió que la barra se limpie correctamente al cancelar o pagar, y que arranque vacía si no hay pedidos.

### 11.1 Diagnóstico

Se reprodujo el ciclo completo (abrir Mesa 1 → agregar item → **Cancel**) y funcionó correctamente: la mesa desapareció de la barra y volvió a estar libre en el dropdown. Se revisó también la base de datos completa de mesas/ventas y el estado era consistente — nada roto en Cancel ni en Complete (ambos ya verificados en la sección 9.4).

El bug real está en la interacción entre el botón **"Suspend"** (preexistente, no tocado por este desarrollo) y el nuevo estado `OPENED`:

- `Sale::save_value()` (preexistente, `app/Models/Sale.php:676-683`) tiene esta lógica genérica, que corre en **cualquier** guardado de venta con mesa asociada:
  ```php
  if ($sale_status == COMPLETED) {
      $dinner_table->release($dinner_table_id);
  } else {
      $dinner_table->occupy($dinner_table_id);
  }
  ```
  Es decir: la mesa se libera **solo** cuando el estado guardado es `COMPLETED`. Para cualquier otro estado (`OPENED`, `SUSPENDED`, etc.) la mesa se marca/mantiene **ocupada** (`dinner_tables.status = 1`).
- `Sales::postSuspend()` (preexistente, sin modificar) llama a `save_value()` con `$sale_status = SUSPENDED` y **nunca** llama a `$dinner_table->release()` — a diferencia de `postCancel()`, que sí lo hace explícitamente (`app/Controllers/Sales.php:1652`).
- Resultado: si el cajero presiona "Suspend" mientras una mesa está activa, la venta pasa a `SUSPENDED` → desaparece de `get_all_opened()` (que solo filtra `OPENED`, sección 4.4) → **pero la mesa sigue ocupada**, porque `postSuspend()` nunca la libera. La mesa queda "fantasma": ocupada (no aparece como libre en el selector) pero sin pestaña visible para volver a ella. Solo era recuperable por el mecanismo viejo de "Suspended" (botón/lista aparte), no por la barra de pestañas nueva.
- No se encontraron mesas en este estado en la base al momento de investigar (probablemente porque el usuario las liberó por otra vía durante sus propias pruebas), pero el bug es real y reproducible: cualquier "Suspend" sobre una mesa real (`dinner_table_id > 2`) lo dispara.

### 11.2 Corrección

En vez de intentar reconciliar dos mecanismos de "cuenta pendiente" (autoguardado `OPENED` + `Suspend` clásico), se **oculta el botón "Suspend"** cuando hay una mesa real activa (`dinner_table_enable` y `dinner_table_id > 2`, dejando afuera "Delivery"/"Take Away" que no participan del tracking de ocupación — ver `Dinner_table::occupy()`/`release()`). Esto es consistente con la decisión de diseño original (sección 3 / doc funcional 5.2): el autoguardado ya cubre por completo la necesidad que "Suspend" resolvía antes para mesas, así que dejarlo visible solo agregaba una vía para llegar a un estado inconsistente. Sigue disponible normalmente para Cotización/Orden de Trabajo/"suspender" sin mesa, que es su uso original.

Código: `app/Views/sales/register.php`, botón `#suspend_sale_button` envuelto en `<?php if (!($config['dinner_table_enable'] && (int) $selected_table > 2)) { ?>`.

No se requirió limpieza de datos porque no había mesas fantasma activas al momento de la corrección — si se detectan en producción, se resuelven manualmente liberando la mesa (`UPDATE ospos_dinner_tables SET status = 0 WHERE dinner_table_id = ...`) y, si corresponde, marcando la venta huérfana como `CANCELED`.

## 12. Decisión de diseño: la mesa es una pestaña desechable, no un mueble fijo (2026-07-10)

**Reporte del usuario:** tras la corrección de la sección 11, seguía viendo el dropdown "Table" con mesas ya pagadas/canceladas (Mesa 1/2/3, Terraza, Carlos, Prueba) en vez de arrancar vacío. Aclarado vía pregunta directa: el reporte era sobre el **dropdown de mesas libres** (`<select name="dinner_table">`), no la barra de pestañas de arriba (que ya funcionaba bien desde la sección 11). Y, más importante, aclaró el modelo de negocio real: **una "mesa" en Casaletto no es un mueble físico permanente — es una cuenta desechable por visita/cliente**, que incluso puede llevar el nombre del cliente (p. ej. "Carlos") en vez de un número. Esto aplica **también** a las mesas numeradas (Mesa 1, Mesa 2, etc.), no solo a las creadas ad-hoc: confirmado explícitamente que **todas** las mesas deben borrarse al liberarse, y volver a crearse (vía "+ New table", sección 10) la próxima vez que se use ese lugar físico.

### 12.1 Cambio de comportamiento

- `Sales::postComplete()` (rama de venta normal, `sale_type = SALE_TYPE_POS`): tras confirmar que la venta se guardó (`sale_id_num != NEW_ENTRY`), si hay mesa activa y no es una pseudo-mesa (`dinner_table_id > 2`, dejando afuera Delivery/Take Away — mismo criterio que en toda la feature), se llama a `Dinner_table::delete($dinner_table_id)` (método preexistente, hace soft-delete `deleted = 1`) en vez de dejarla simplemente liberada (`status = 0`) para su reuso.
- `Sales::postCancel()`: mismo criterio — en vez de solo `release()`, se llama `delete()` cuando la mesa es real.
- En ambos casos se reutiliza `Dinner_table::delete()` tal cual existe (ya usado desde Config para borrar mesas manualmente) — no se agregó ningún método nuevo al modelo para esto.

### 12.2 Bug preexistente expuesto: `get_empty_tables()` con precedencia de operadores incorrecta

Al verificar el cambio (soft-deleting una mesa vía SQL directo para simular el nuevo flujo), el dropdown seguía mostrando mesas marcadas `deleted = 1`. Causa: `Dinner_table::get_empty_tables()` armaba la condición, en su versión original, como

```php
$builder->where('status', 0);
$builder->orWhere('dinner_table_id', $current_dinner_table_id);
$builder->where('deleted', 0);
```

que CI4 QueryBuilder traduce literalmente a `WHERE status = 0 OR dinner_table_id = ? AND deleted = 0` — y en SQL, `AND` liga más fuerte que `OR`, así que esto se evalúa como `WHERE status = 0 OR (dinner_table_id = ? AND deleted = 0)`. El filtro `deleted = 0` solo aplicaba dentro de la segunda rama del OR: **cualquier fila con `status = 0`, sin importar su `dinner_table_id` ni su `deleted`, pasaba igual**. Y `status = 0` es justamente el estado normal de una mesa recién borrada (borrar solo toca `deleted`, no `status`) — por eso toda mesa pagada/cancelada seguía "colándose" en el dropdown de mesas libres.

Este bug es **preexistente** (la función nunca tuvo `groupStart()`/`groupEnd()`) y no se había notado antes porque el borrado de mesas hasta ahora solo pasaba manualmente desde Config, de forma esporádica — el nuevo flujo automático (borrar en cada `postComplete()`/`postCancel()`) lo ejercita constantemente y lo hizo visible de inmediato.

**Corrección:** agrupar explícitamente la condición OR para que quede `WHERE deleted = 0 AND (status = 0 OR dinner_table_id = ?)`:

```php
$builder->where('deleted', 0);
$builder->groupStart();
$builder->where('status', 0);
$builder->orWhere('dinner_table_id', $current_dinner_table_id);
$builder->groupEnd();
```

Código final en `app/Models/Dinner_table.php::get_empty_tables()`.

### 12.3 Verificación

Ciclo completo repetido en el contenedor local tras ambos cambios (delete-on-close + fix de `get_empty_tables()`):
- Limpieza de datos de prueba acumulados de sesiones anteriores (soft-delete manual vía SQL de las mesas de prueba ya pagadas/canceladas).
- Dropdown "Table" confirmado mostrando **solo** Delivery/Take Away tras la limpieza.
- Creada "Mesa 5" vía "+ New table", agregado un item (Empanadas, $6.00), agregado pago en efectivo por el monto exacto, **Complete**.
- Tras completar y volver a `/sales`: la barra de pestañas no muestra "Mesa 5" (ya no hay pestañas abiertas) y el dropdown "Table" vuelve a mostrar solo Delivery/Take Away — confirma que la mesa fue borrada (no solo liberada) y que `get_empty_tables()` ya no filtra mal.
- ✅ Comportamiento esperado por el usuario: la lista de mesas arranca vacía sin pedidos, y cada mesa (incluidas las numeradas) desaparece por completo al pagarse o cancelarse, quedando disponible para recrearse con "+ New table" la próxima vez.
