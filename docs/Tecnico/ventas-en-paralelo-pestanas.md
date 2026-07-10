# Diseño técnico — Ventas en paralelo por mesa/cliente (pestañas en Register)

- **Alcance funcional:** [`docs/Funcional/ventas-en-paralelo-pestanas.md`](../Funcional/ventas-en-paralelo-pestanas.md) (leer primero)
- **Estado:** Diseño aprobado, pendiente de implementación
- **Fecha:** 2026-07-09

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
| `app/Models/Sale.php` | `get_all_suspended()` (~L1179-1184) | Query de la pantalla "Suspendidas" (ALT+4) → incluir también `OPENED` para que las mesas sigan apareciendo ahí, o crear un método separado `get_all_opened()` dedicado a pestañas (recomendado, ver 4.1) |
| `app/Models/Sale.php` | `get_suspended_sale_info()` (~L1364-1368) | Lookup por `sale_id` al reanudar/cargar una pestaña → aceptar `OPENED` |
| `app/Controllers/Sales.php` | botón "Suspender" genérico (~L1606) | `$sale_status = ($dinner_table_id !== null) ? OPENED : SUSPENDED;` |

Las vistas (`register.php`, `suspended.php`) no tienen comparaciones de estado hardcodeadas — no requieren cambio directo por este punto (sí por la barra de pestañas nueva, ver sección 5).

## 3. Autoguardado (persistencia inmediata)

**Requisito funcional:** una pestaña abierta debe sobrevivir a una desconexión o reinicio del sistema sin perder más que la última acción en curso.

**Estado actual:** el carrito vive solo en sesión (`Sale_lib::get_cart()/set_cart()`, session-scoped) hasta que se llama a `Sale::save_value()` — que hoy solo ocurre en Suspender/Cancelar/Completar. Si el sistema se cae mientras se están agregando items a una pestaña recién abierta, se pierde todo lo no guardado.

**Cambio necesario:**

1. **Al abrir una mesa** (primer item agregado, o al seleccionar la mesa): crear inmediatamente la fila `Sale` con `sale_status = OPENED`, `dinner_table_id` asignado, carrito vacío o con el primer item.
2. **En cada mutación del carrito de una pestaña `OPENED`** (agregar item, editar cantidad/precio, quitar item, cambiar cliente, agregar comentario): llamar a `Sale::save_value()` de inmediato contra esa fila (`UPDATE`, no `INSERT` nuevo), en el mismo request que hoy solo actualiza la sesión.
   - Los endpoints existentes (`Sales::postAdd`, `postEditItem`, `getDeleteItem`, `postSelectCustomer`, `postSetComment`) ya son llamadas AJAX individuales por acción — el cambio es que, cuando el contexto activo es una pestaña `OPENED`, además de tocar la sesión, cada uno persiste. No hace falta un mecanismo de autoguardado por timer/polling aparte.
3. Esto **no aplica** al modo de venta normal sin mesa (ticket simple de mostrador) — ese sigue funcionando como hoy (todo en sesión hasta Completar/Suspender), porque no tiene el requisito de "sobrevivir mientras se atienden otras cuentas en paralelo".

## 4. Backend — cambio de forma de `Sale_lib`

**Decisión:** la base de datos es la fuente de verdad; la sesión solo refleja **cuál pestaña está actualmente visible** para ese cajero, no el contenido de todas.

- `Sale_lib` mantiene un solo "contexto activo" en sesión (como hoy), pero ese contexto ahora es liviano: básicamente `active_sale_id` (o `active_dinner_table_id`).
- Cambiar de pestaña = guardar cualquier cambio pendiente de la pestaña actual (ya persistido en cada mutación, ver sección 3, así que normalmente no hay nada pendiente) → cargar desde la base el carrito/cliente/comentario de la pestaña destino → actualizar `active_sale_id` en sesión → re-renderizar solo el área del carrito.
- **No se necesita** sostener N carritos completos en memoria de sesión simultáneamente — se lee de la base bajo demanda al cambiar de pestaña. Esto es más simple y más robusto ante desconexiones que una alternativa de "multi-cart en sesión".

### 4.1 Endpoints nuevos (AJAX, JSON)

| Endpoint | Función |
|---|---|
| `GET sales/getOpenTabs` | Lista las pestañas actualmente `OPENED` (id, mesa, cliente, cantidad de items, total) para pintar la barra de pestañas. Usa el `get_all_opened()` nuevo en `Sale.php`. |
| `POST sales/openTable/{dinner_table_id}` | Si la mesa no tiene venta `OPENED`, la crea; si ya la tiene, la reutiliza. Devuelve `sale_id` y marca la mesa como ocupada (`Dinner_table::occupy()`, ya existe). |
| `POST sales/switchTab/{sale_id}` | Cambia el contexto activo de la sesión al `sale_id` indicado, devuelve el carrito/cliente/comentario para re-pintar. Reemplaza el ciclo completo de suspender+recargar que hace hoy `postChangeMode` para este caso. |

Los endpoints existentes de mutación de carrito (`postAdd`, `postEditItem`, `getDeleteItem`, etc.) se ajustan para persistir cuando el contexto activo sea `OPENED` (sección 3), no se reemplazan.

## 5. Frontend — `app/Views/sales/register.php`

- Nueva barra de pestañas arriba del área de carrito, junto a "Register Mode": una pestaña por cada mesa con venta `OPENED` (nombre de mesa + indicador de total/cantidad de items), más un control para abrir una mesa libre nueva.
- Click en una pestaña → llama a `switchTab`, reemplaza el área de carrito/cliente/totales sin recargar la página completa.
- Pestaña activa resaltada visualmente.
- Al completar el pago de una pestaña, esta desaparece de la barra (la mesa se libera vía `Dinner_table::release()`, ya existe).

## 6. Validar issue #1933 ("solo funciona con 2 mesas")

Antes de dar por terminada la implementación: dar de alta 3+ mesas reales en staging, abrir pestañas en las 3 en paralelo, agregar items distintos en cada una, alternar entre ellas, y confirmar que ninguna se pierde ni se mezcla con otra. Si el bug persiste en 3.4.2, se resuelve como parte de este mismo desarrollo (probablemente en `Dinner_table::get_empty_tables()` o en la lógica de `postChangeMode`), no como algo aparte.

## 7. Fuera de alcance de este diseño

- Cambios al flujo de pago/cobro en sí (se mantiene igual).
- Multi-usuario simultáneo sobre la misma mesa (ver `docs/Funcional/`, sección 4).
- Reportes: las pestañas (`OPENED`) no se agregan a los reportes existentes salvo pedido explícito posterior.

## 8. Archivos a tocar (resumen)

- `app/Config/Constants.php` — nueva constante.
- `app/Models/Sale.php` — `get_all_opened()` nuevo (o extender `get_all_suspended`), `get_suspended_sale_info()` extendido, `save_value()` sin cambios de firma (ya acepta `sale_status` como parámetro).
- `app/Models/Dinner_table.php` — reutilizar `occupy()`/`release()`/`is_occupied()`/`get_empty_tables()` existentes; revisar si el límite del issue #1933 vive acá.
- `app/Controllers/Sales.php` — lógica de decidir `OPENED` vs `SUSPENDED`, endpoints nuevos (`getOpenTabs`, `openTable`, `switchTab`), ajuste de endpoints de mutación existentes para persistir en caliente.
- `app/Libraries/Sale_lib.php` — simplificar a "contexto activo" liviano en sesión en vez de dueño del estado completo del carrito.
- `app/Views/sales/register.php` — barra de pestañas.
- JS del Register (`public/js/` o el bundle correspondiente) — llamadas AJAX a los endpoints nuevos, sin recarga de página.
