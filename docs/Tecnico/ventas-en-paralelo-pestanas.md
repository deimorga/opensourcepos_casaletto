# Diseño técnico — Ventas en paralelo por mesa/cliente (pestañas en Register)

- **Alcance funcional:** [`docs/Funcional/ventas-en-paralelo-pestanas.md`](../Funcional/ventas-en-paralelo-pestanas.md) (leer primero)
- **Estado:** Diseño aprobado, pendiente de implementación
- **Fecha:** 2026-07-09 (revisado 2026-07-10 tras verificar el código exacto con agentes de exploración — ver sección 4)

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

Antes de dar por terminada la implementación: dar de alta 3+ mesas reales en staging, abrir pestañas en las 3 en paralelo, agregar items distintos en cada una, alternar entre ellas, y confirmar que ninguna se pierde ni se mezcla con otra. Si el bug persiste en 3.4.2, se resuelve como parte de este mismo desarrollo (probablemente en `Dinner_table::get_empty_tables()` o en la lógica de `postChangeMode`), no como algo aparte.

## 7. Fuera de alcance de este diseño

- Cambios al flujo de pago/cobro en sí (se mantiene igual).
- Multi-usuario simultáneo sobre la misma mesa (ver `docs/Funcional/`, sección 4).
- Reportes: las pestañas (`OPENED`) no se agregan a los reportes existentes salvo pedido explícito posterior.

## 8. Archivos a tocar (resumen final)

- `app/Config/Constants.php` — nueva constante `OPENED = 3`.
- `app/Models/Sale.php` — `get_all_opened()` nuevo, `get_open_sale_by_table()` nuevo, `get_suspended_sale_info()` extendido a `IN (SUSPENDED, OPENED)`. `save_value()` sin cambios de firma (ya acepta `sale_status` como parámetro).
- `app/Models/Dinner_table.php` — reutiliza `occupy()`/`release()`/`is_occupied()`/`get_empty_tables()` existentes sin cambios de firma; revisar si el bug #1933 vive acá al validar en la sección 6 (no se toca a priori).
- `app/Controllers/Sales.php` — `postChangeMode()` reescrito (sección 4.4), `_autosave_open_tab()` nuevo + 3 llamadas (`postAdd`, `postEditItem`, `getDeleteItem`), `_reload()` con `open_tabs` agregado. **Sin endpoints nuevos.**
- `app/Libraries/Sale_lib.php` — **sin cambios de forma.** Se sigue usando tal cual (`clear_all()`, `copy_entire_sale()`, getters/setters existentes) — la sección 4 anterior que proponía "simplificar a contexto activo liviano" quedó descartada: no hace falta tocar este archivo.
- `app/Views/sales/register.php` — barra de pestañas + botones que reutilizan el submit existente del selector de mesa. **Sin JS/AJAX nuevo.**
