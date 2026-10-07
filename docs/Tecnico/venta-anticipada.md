# Venta anticipada (Preventas) — diseño técnico

> **Estado (2026-10-07):** **diseño, sin código.** El mapa de lo que existe (§2) está verificado contra
> `develop` en `94631087b`. Las decisiones de negocio están en el documento hermano
> `docs/Funcional/venta-anticipada.md` §6. Este documento propone el diseño y las decisiones técnicas
> (T1…T12). Queda una sola cosa por resolver antes de escribir código, la §11.

---

## 1. Qué se pide, en términos de datos

Un acuerdo con un cliente registrado. Lleva líneas de producto con precio congelado, una fecha de
entrega, un calendario de cuotas y una serie de abonos. El acuerdo **no toca el inventario ni la
tabla `sales` hasta la entrega**, y la entrega exige saldo cero.

Hay dos cosas que tienen que salir bien y que la mecánica actual de OSPOS hace mal:

1. **Cada abono se atribuye al turno de caja que lo recibió.**
2. **La plata no se cuenta dos veces** cuando el acuerdo se convierte en venta.

---

## 2. Mapa de lo que existe

Todas las rutas son relativas a la raíz del repositorio.

| Pieza | Dónde | Estado para este caso |
|---|---|---|
| Medio de pago «Adeudo» (`due`) | `app/Helpers/payment_type_helper.php:33`; se ofrece en `app/Helpers/locale_helper.php:267` | Completa la venta (COMPLETED), descuenta inventario (`Sale.php:768`) y cuenta como pago. Exigir cliente se controla **solo en la vista** (`app/Views/sales/register.php:724-744`); `Sales::postComplete()` no lo valida. **No sirve: es fiado.** |
| Venta suspendida con pagos | `Sales::postSuspend` (`Sales.php:2487-2518`); se retoma con `Sale_lib::copy_entire_sale` (`Sale_lib.php:1871-1913`) | Guarda pagos con `SUSPENDED`. Al completar, `Sale::clear_suspended_sale_detail()` (`Sale.php:1550-1576`) **borra y reinserta** los pagos con `payment_time` = ahora, y `save_value()` sella la venta con el turno abierto en ese momento (`Sale.php:678-682`). **Una cuota de octubre queda en el turno de diciembre.** |
| Orden de trabajo y depósitos | `cash_deposit` / `credit_deposit` solo en modo `sale_work_order` (`Sale.php:1075-1078`); requiere `work_order_enable` (por defecto 0) | Mismo problema de reescritura. El autollenado del cierre ignora los depósitos (`Cashups.php:153-169`) y la conciliación solo cuenta `payment_type_code = 'cash'` (`Cashups.php:246`). Al anular conserva los pagos y no hay devolución. |
| Tarjeta de regalo y puntos | `Sale::save_value` `:713-722` | **Probable error heredado (por lectura, sin reproducir):** descuentan en cada guardado sin mirar el estado, así que suspender y completar descontaría dos veces. Otra razón para no montar la preventa sobre suspendidas. |
| Cotización | `Sales.php:1414-1419` | Rechaza pagos a propósito (`tests/Controllers/SalesQuoteTest.php:99`). |
| Editar pagos de una venta | `Sales::getEdit` / `postSave` (`Sales.php:2209-2421`), `app/Views/sales/form.php` | El monto es de solo lectura (`form.php:92`), pero **el tipo se puede cambiar** (`form.php:84`). Las tarjetas de regalo se bloquean (`form.php:79-82`). Importa para la trampa §8.3. |
| Sello del turno | `sales.cashup_id`, escrito solo al completar (`Sale.php:678-682`); `Sale::update` lo preserva (`Sale.php:463-467`) | El turno se guarda **por venta, no por pago**. Ningún código lee `sales_payments.payment_time`. |
| Conciliación del cierre | `Cashups::_build_reconciliation` (`Cashups.php:237-297`), fuente `Sale::get_payments_by_cashup` (`Sale.php:548-603`) | Esperado = apertura + efectivo − gastos de caja − recogidas. **Es el punto donde se enchufan los abonos** (§6). |
| Autollenado del cierre | `Cashups.php:150-169`, por rango de fechas vía `Summary_payments` | Ignora cualquier código distinto de efectivo, débito, crédito y transferencia. |
| Recogidas de efectivo | `app/Models/Cash_collection.php`, migración `20260823060000_AddCashCollections.php` | **Precedente directo**: un movimiento de caja que no es una venta, con tabla propia, y que la conciliación suma por ventana de fechas. |
| Ingresos vs Gastos, modo caja | `app/Models/Reports/Income_expenses.php:176-183` | Lee `sales_payments` agrupado por `sales.sale_time`. Un pago de preventa en `sales_payments` caería en la fecha de la entrega (§7.2). |
| Estadísticas del cliente | `Customer::get_stats` (`Customer.php:132-175`) | Suma `payment_amount − cash_refund` de ventas COMPLETED. |
| Cliente | `app/Models/Customer.php:18-32` | No tiene campos de crédito ni saldo, y no hace falta agregarlos (T2). |
| Módulo e interruptor | Plantilla `20260923020000_AddOrderTicketsModule.php` y `20260923010000_AddOrderTicketsConfigKeys.php`; pestaña de configuración como `ConfigOrderTicketsTest` | Patrón a copiar literal (§5). |
| Borrado de artículos protegido | `tests/Controllers/ItemsDeleteGuardTest.php` | Hay que extenderlo (§8.5). |

Constantes (`app/Config/Constants.php:136-145`): `COMPLETED=0`, `SUSPENDED=1`, `CANCELED=2`,
`OPENED=3`; `SALE_TYPE_POS=0`, `INVOICE=1`, `WORK_ORDER=2`, `QUOTE=3`, `RETURN=4`.

**No hay pruebas** de Adeudo, depósitos, tarjetas de regalo ni puntos.

---

## 3. Decisiones técnicas

| # | Decisión | Por qué | Lo que se descartó |
|---|---|---|---|
| **T1** | **Tablas propias** (`presales`, `presale_items`, `presale_installments`, `presale_payments`, `presale_events`). La preventa no vive en `sales` hasta la entrega | `sales` alimenta todos los reportes, el inventario y el cuadre. Una fila ahí con un estado nuevo obliga a revisar cada consulta que filtra por `sale_status`; ya son 9 archivos solo con `SUSPENDED` (`docs/Tecnico/ventas-en-paralelo-pestanas.md`) | Un estado `PRESALE=4` en `sales`; venta suspendida u orden de trabajo (§2) |
| **T2** | **El abono es un movimiento propio** en `presale_payments`, con su `payment_time` y su `cashup_id` | Es lo único que atribuye cada peso a su turno sin tocar la mecánica de `sales_payments`. Sigue el precedente de `cash_collections` | Agregar `cashup_id` a `sales_payments` para toda la aplicación: arreglo correcto en abstracto, pero cambia la consulta del cuadre de todos los negocios en plena temporada |
| **T3** | **El abono exige un turno abierto** (`Cashup::get_open_cashup_id() !== null`); sin turno se rechaza | Una venta sin turno se tolera (`cashup_id` nulo es una respuesta válida). Un abono sin turno es plata que nadie cuadra | Tolerar nulo como en ventas |
| **T4** | **La entrega crea una venta COMPLETED normal** (`SALE_TYPE_POS`) con un único pago de un código nuevo, **`presale`** («Preventa»), por el total. Se enlaza con `presales.sale_id` | Reportes por artículo, categoría, empleado e impuestos, el inventario, el recibo y la estadística del cliente funcionan sin cambios (D7) | Un `sale_type` nuevo: cada `switch` sobre `sale_type` de vistas y recibos tendría que aprenderlo |
| **T5** | **El código `presale` nunca es efectivo** y **se excluye de los ingresos del turno**; se muestra aparte como «Entregas de preventa (cobradas antes)» | La plata ya entró por `presale_payments` en su turno (D12). Sumarla otra vez en el turno de la entrega es contarla dos veces | — |
| **T6** | **El estado guardado es solo `open` / `delivered` / `canceled`.** «Al día», «Atrasada» y «Pagada» **se calculan al leer** | Un estado derivado y guardado se desincroniza en cuanto alguien abona o mueve una fecha. Con cientos de filas, calcularlo cuesta nada | Un trabajo nocturno que marque atrasos |
| **T7** | **El saldo se valida en el servidor, dentro de una transacción con bloqueo de la fila** (`SELECT … FOR UPDATE` sobre `presales`) | Dos clics, o dos cajas abonando a la vez, no pueden dejar el saldo negativo | Validarlo solo en la vista, el error de origen de «Adeudo» (§2) |
| **T8** | **Precio congelado por línea** (`unit_price`, `discount`, `discount_type`) copiado al registrar. **Toda modificación pasa por `presale_events`** con valor anterior, valor nuevo, empleado y motivo | D8: ajustable, pero auditable | Leer el precio del catálogo al entregar |
| **T9** | **Número de preventa = prefijo + id con ceros** (`PV-000123`). El prefijo sale de configuración | Sin contador aparte que pueda desincronizarse | Un token de secuencia como `{WSEQ}` |
| **T10** | **Medios de pago del abono: `cash`, `debit`, `credit`, `bank_transfer`.** Lista cerrada en el servidor | D3 / §4.3 funcional. Adeudo, tarjeta de regalo, puntos y depósitos no tienen sentido aquí | — |
| **T11** | **Módulo `presales` con UNA sola subpermisión, `presales_manage`** | La regla de prefijo de `Employee::has_module_grant()` (`Employee.php:483-502`): con dos o más subpermisiones, un empleado sin el permiso base pasa el chequeo. Explicado en `AddOrderTicketsModule` | Separar ajustar, cancelar y devolver en tres permisos |
| **T12** | **Interruptor por negocio `presales_enable`**, en la Configuración del propio negocio, sembrado en `'0'` y leído siempre con `?? '0'` | Igual que comandas (`docs/Tecnico/comandas-y-cuenta-abierta.md` §5.1): la consola de plataforma no escribe en el `app_config` de ningún negocio | Encenderlo desde la plataforma |

---

## 4. Modelo de datos

Todas las tablas con el prefijo `ospos_`, InnoDB, utf8mb4. Montos en `decimal(15,2)` y cantidades en
`decimal(15,3)`, igual que `sales_items` y `sales_payments`.

### 4.1 `presales`

| columna | tipo | nota |
|---|---|---|
| `presale_id` | int PK AI | |
| `customer_id` | int NOT NULL, FK `customers.person_id` | D3: siempre hay cliente |
| `employee_id` | int NOT NULL, FK `employees.person_id` | Quien la registró |
| `location_id` | int NOT NULL, FK `stock_locations` | De dónde saldrá el inventario al entregar |
| `created_at` | datetime NOT NULL | |
| `delivery_date` | date NOT NULL | D6 |
| `status` | tinyint NOT NULL default 0 | 0 open, 1 delivered, 2 canceled (T6) |
| `total` | decimal(15,2) NOT NULL | Se recalcula y se guarda en cada ajuste (T8) |
| `comment` | text NULL | |
| `sale_id` | int NULL UNIQUE, FK `sales.sale_id` | La venta de la entrega (T4) |
| `delivered_at`, `delivered_by` | datetime / int NULL | |
| `canceled_at`, `canceled_by` | datetime / int NULL | |
| `cancel_reason` | text NULL | D10 |

Índices: `(status, delivery_date)` para la lista y para lo comprometido; `(customer_id)`.

### 4.2 `presale_items`

`presale_id` FK, `line` smallint, `item_id` FK `items`, `description` varchar NULL, `quantity`,
`unit_price`, `discount`, `discount_type`. La PK es `(presale_id, line)`, igual que `sales_items`.

### 4.3 `presale_installments`

`installment_id` PK, `presale_id` FK, `due_date` date, `amount` decimal(15,2). Es el plan
**comprometido**, no lo pagado: los abonos no se asignan a una cuota concreta. Se comparan los
acumulados (§4.6).

### 4.4 `presale_payments`

| columna | tipo | nota |
|---|---|---|
| `payment_id` | int PK AI | |
| `presale_id` | int FK | |
| `kind` | enum('payment','refund') | Una devolución (D10) es una fila `refund` con monto positivo |
| `payment_type_code` | varchar(40) | Solo los de T10 |
| `amount` | decimal(15,2) > 0 | |
| `payment_time` | datetime NOT NULL | |
| `employee_id` | int FK | |
| `cashup_id` | int NOT NULL, FK `cash_up` | T3 |
| `reference_code` | varchar NULL | Referencia de la transferencia o del datáfono |

Índices: `(cashup_id)` para el cuadre, `(presale_id)` y `(payment_time)` para los reportes.

### 4.5 `presale_events`

`event_id`, `presale_id`, `event_time`, `employee_id`, `event_type` (`created`, `payment`, `refund`,
`price_adjusted`, `quantity_adjusted`, `delivery_date_changed`, `plan_changed`, `delivered`,
`canceled`), `detail` json (antes / después) y `reason` text. **Es solo de inserción**: nada lo
actualiza ni lo borra.

### 4.6 Derivados (T6)

- `paid` = Σ `payment` − Σ `refund`
- `balance` = `total` − `paid`
- `due_to_date` = Σ `installments.amount` con `due_date < hoy`
- **Atrasada** ⇔ `status = open` y `paid < due_to_date`. **Días de atraso** = hoy menos la fecha de
  la cuota vencida más antigua que el acumulado pagado no cubre.
- **Pagada** ⇔ `status = open` y `balance = 0`

### 4.7 Invariantes, en el servidor

1. Σ `installments.amount` = `total` al crear y en cada cambio del plan o del total.
2. `max(due_date)` ≤ `delivery_date`.
3. Un abono no puede pasar de `balance`. Una devolución no puede pasar de `paid`.
4. Solo se entrega con `balance = 0` (D11).
5. Una preventa `delivered` o `canceled` es de solo lectura.

---

## 5. Interruptor, módulo y permisos

**Migraciones**, una por tema y con plantilla literal:

- `AddPresales`: las 5 tablas.
- `AddPresalesConfigKeys`: `presales_enable = '0'`, `presales_prefix = 'PV-'` y `presales_terms = ''`
  (texto libre que va en los comprobantes).
- `AddPresalesModule`: módulo `presales`, sort 72 (después de Ventas, que es 70, y antes de Comandas,
  que es 75), permisos `presales` y `presales_manage`. **Sin conceder ningún permiso**, por la misma
  razón escrita en `AddWriteoffsModule`.

| permiso | abre |
|---|---|
| `presales` | Lista, ver, crear, abonar, entregar, imprimir |
| `presales_manage` | Ajustar precio o cantidad, mover fecha, rehacer plan, cancelar o devolver |

**Con el interruptor apagado:**
- el controlador responde `no_access`;
- el menú no se muestra aunque haya permisos;
- el bloque de preventas no aparece en el cuadre;
- el reporte de comprometidos no se ofrece.

**Apagarlo con preventas abiertas:** la pantalla de Configuración muestra cuántas hay y pide
confirmar. No se prohíbe, porque el administrador manda, pero se avisa.

Tras el despliegue hay que correr `php spark platform:support-employee` (AGENTS.md); si no, la sesión
de soporte no ve el módulo.

---

## 6. El cuadre de caja

Es la parte delicada. La conciliación actual está en producción desde 2026-09-20 y costó llegar ahí
(`docs/Tecnico/cuadre-de-caja-y-origen-del-efectivo.md`).

### 6.1 Conciliación (`Cashups::_build_reconciliation`)

1. Un método nuevo, `Presale_payment::get_by_cashup(int $cashup_id)`, agrupa por `payment_type_code`
   y por `kind`, con la devolución en negativo. Se lee por `cashup_id`, no por rango de fechas, por
   la misma razón que `get_payments_by_cashup` (`Sale.php:540-548`).
2. `income_cash` suma el neto de `cash`. `income_total` suma el neto de todos los códigos.
3. **El código `presale` se saca de `income_total`** y va a un renglón informativo, «Entregas de
   preventa (cobradas antes)» (T5). No afecta el esperado, porque el esperado solo mira `cash`; pero
   sin este cambio el total de ingresos del turno de la entrega quedaría inflado.
4. En la vista se agrega el bloque «Abonos de preventa» por medio de pago, al lado de «Ventas
   anuladas».

### 6.2 Autollenado del cierre (`Cashups.php:150-169`)

Hoy suma por rango de fechas desde `Summary_payments`. Se le agregan los abonos netos del mismo rango
leídos de `presale_payments`, para que «Efectivo», «Datáfono» y «Banco» se llenen con lo que de verdad
entró. El código `presale` ya cae fuera de los `if` existentes, así que no hay que excluirlo.

### 6.3 Con el interruptor apagado

**El cuadre es byte a byte el de hoy.** Si el interruptor está apagado, ni siquiera se consulta
`presale_payments`, de modo que un negocio sin preventas no paga ninguna consulta extra. Lo fija una
prueba (§10).

---

## 7. La entrega

### 7.1 Cómo se construye la venta — decisión pendiente (§11)

Hay dos caminos y los dos se pueden hacer:

**A. Por la caja.** «Entregar» abre la preventa en una pestaña nueva de la pantalla de venta, con el
mismo mecanismo de cuentas paralelas (`docs/Tecnico/ventas-en-paralelo-pestanas.md`). Llega con:
- cliente, líneas y precios congelados, y las líneas bloqueadas;
- un pago `presale` por el total, bloqueado.

El cajero pulsa Completar y `postComplete()` hace lo de siempre: impuestos, inventario, sello, recibo
e impresora. Hay que añadir guardas: no editar líneas ni precio, que no haga falta el permiso
`sales_change_price` para el precio congelado, no agregar otros pagos ni quitar el `presale`, y que la
pestaña no se pueda suspender.

**B. En el servidor.** Un servicio `Presale_lib::deliver()` arma `$items`, calcula impuestos con
`Tax_lib` y llama a `Sale::save_value()`. Así no toca la pantalla de venta, pero **duplica la lógica
de impuestos y precios de `postComplete()`**, y esa lógica tiene casos (impuesto incluido, kits
anidados, redondeo de efectivo) que ya han fallado antes.

**Recomendación: A.** Reutiliza el único camino de cierre de venta probado en producción. El riesgo
de A son las guardas; el de B, una segunda implementación de impuestos que nadie vigila.

### 7.2 Lo que la entrega no debe romper

- **Sello del turno:** la venta queda sellada con el turno de la entrega, como siempre. Su único
  pago es `presale`, que no es efectivo y está fuera de `income_total` (§6.1).
- **Ingresos vs Gastos, modo devengo:** la venta aparece en la fecha de entrega, sin cambios
  (Funcional §6.2).
- **Ingresos vs Gastos, modo caja** (`Income_expenses::paymentsByPeriod`): hay que **excluir
  `presale`** y **sumar los abonos netos de `presale_payments` por `payment_time`**. Si no, la plata
  aparece en diciembre en vez de en octubre.
- **`Summary_payments`:** mostrará una línea «Preventa» en la fecha de entrega. Se deja así, con la
  etiqueta clara, para que el reporte siga cuadrando con las ventas. Los abonos se ven en el reporte
  del módulo.
- **`Customer::get_stats`:** cuenta el total el día de la entrega. Es lo correcto.
- **Cajón:** solo se abre con efectivo (`Sale_lib.php:304-342`), así que en la entrega no se abre.
  En el abono en efectivo sí se abre, reusando la misma función.

---

## 8. Trampas conocidas

1. **`clear_suspended_sale_detail` reescribe pagos.** Ninguna parte de la preventa puede pasar por una
   venta suspendida. Si se elige el camino A (§7.1), la pestaña de entrega **no se puede suspender**.
2. **El chequeo de cliente de Adeudo vive solo en la vista.** No repetir ese error: todas las
   invariantes de §4.7 van en el servidor y tienen prueba.
3. **Editar una venta deja cambiar el tipo de pago.** Un `presale` cambiado a «Efectivo» desde
   `Sales::getEdit` contaría esa plata dos veces. Hay que bloquearlo igual que la tarjeta de regalo
   (`form.php:79-82`, `Sales.php:2240`), en la vista y en `postSave`.
4. **Anular la venta de una entrega** (`Sales::postDelete`) devolvería el inventario dejando la
   preventa como entregada y la plata cobrada. En la Entrega 1 se bloquea la anulación de una venta
   con preventa enlazada. Una devolución de mercancía ya entregada se hace con el modo Devolución
   normal.
5. **Borrar un artículo** que está en una preventa abierta rompe la entrega. Hay que extender la
   guarda de `ItemsDeleteGuardTest`. Lo mismo para **borrar un cliente** con preventas abiertas.
6. **Kits.** En Casaletto hay 40 kits «Receta Armada» y `add_item_kit()` los expande de forma
   recursiva. **Antes de construir hay que verificar si los productos navideños son artículos o kits**
   (§11). Un kit en preventa se guarda como sus componentes al precio que la caja calcula al
   agregarlo, y así queda congelado.
7. **Tildes.** `Customers::postSave` lee `first_name` y `last_name` sin filtro
   (`Customers.php:242-243`), pero la memoria del arreglo de tildes lista Clientes como pendiente.
   **Hay que verificarlo en staging** guardando y buscando «José Muñoz» antes de abrir la temporada.
   La regla de la memoria aplica: ejecutar antes que razonar.
8. **Traducciones.** La aplicación corre en `es-MX`; una cadena escrita solo en `es-ES` no se ve.
   Escribir en `es-MX`, `es-ES` y `en`.
9. **ICU.** En los textos de idioma, `'{0}'` entre comillas simples se imprime tal cual. Nada de
   comillas simples alrededor de sustituciones.
10. **Fechas.** Entrada y salida en d/m/Y con lectura estricta (`docs/Tecnico/formato-de-fecha.md`).
    Usar `parse_typed_datetime()` y `typed_date_error()`, como `Customers::postSave`.
11. **Base de pruebas compartida.** Una prueba que escriba `presales_enable` en `app_config` tiene que
    restaurarlo; si no, rompe pruebas de otros archivos.

---

## 9. Reporte de lo comprometido

```sql
SELECT pi.item_id, i.name, p.delivery_date, SUM(pi.quantity) AS committed
FROM ospos_presale_items pi
JOIN ospos_presales p ON p.presale_id = pi.presale_id AND p.status = 0
JOIN ospos_items i ON i.item_id = pi.item_id
GROUP BY pi.item_id, i.name, p.delivery_date
```

Se cruza con `item_quantities` de la ubicación para mostrar lo que hay hoy y lo que falta. Con cientos
de preventas y decenas de productos, la consulta es trivial (índice `(status, delivery_date)`).

---

## 10. Pruebas

Ubicación: `tests/Models/Presale*Test.php` y `tests/Controllers/Presales*Test.php`. Se corren en CI
(«PHPUnit Tests»); no hace falta Docker local.

| Prueba | Fija |
|---|---|
| Crear rechaza: sin cliente, plan que no suma el total, cuota después de la entrega, medio de pago fuera de T10 | §4.7, T10 |
| Abonar sin turno abierto es rechazado | T3 |
| Abonar más del saldo es rechazado, también con dos abonos concurrentes | T7 |
| Un abono en efectivo sube el esperado del turno que lo recibió, y **no** el del siguiente | D12 |
| Una devolución en efectivo baja el esperado de su turno | D10 |
| Entregar con saldo es rechazado en el servidor | D11 |
| La venta de la entrega: COMPLETED, precios congelados, inventario descontado, un pago `presale`, sellada con el turno de la entrega, y `income_total` de ese turno sin la plata ya abonada | T4, T5 |
| Ingresos vs Gastos, modo caja: los abonos en sus fechas; el pago `presale` no aparece | §7.2 |
| `presale` no se puede cambiar de tipo en la edición de la venta, y la venta enlazada no se anula | §8.3, §8.4 |
| Atrasada, al día y pagada calculadas en fechas límite (vence hoy, ayer, pago exacto) | T6 |
| Con el interruptor apagado: el controlador da `no_access` y la conciliación es idéntica a la de hoy | §6.3 |
| Permisos: sin `presales_manage` no se ajusta ni se cancela | T11 |
| Borrar un artículo o un cliente con preventa abierta es rechazado | §8.5 |

---

## 11. Antes de escribir código

1. **Elegir A o B para la entrega** (§7.1). Recomendación: A.
2. **Mirar el catálogo de Casaletto, solo lectura:** cuáles son los productos navideños, si son
   artículos o kits, y el estado del Pavo navideño (código 157, borrado). Define la trampa §8.6.
3. **Verificar las tildes de Clientes en staging** (§8.7).
4. **Confirmar con el dueño la interpretación de D7** (Funcional §6.2).

---

## 12. Orden de construcción

| Entrega | Contenido | Efecto en quien no lo encienda |
|---|---|---|
| **1** | Migraciones; modelos; `Presales` controlador y vistas (lista, crear, ver, abonar); comprobantes; integración con el cuadre (§6); entrega (§7); reporte de comprometidos (§9); exclusión de `presale` en Ingresos vs Gastos y en la edición de ventas | Ninguno: interruptor apagado, módulo sin permisos |
| **2** | Ajustes con auditoría; cambio de fecha y de plan; cancelación con devolución; cartera | Ninguno |

**Despliegue:** por GitHub Actions (`scripts/deploy.sh`), primero staging y después producción
**después de las 22:00**. Las migraciones las corre el entrypoint en cada esquema. **Volver atrás:**
imagen y respaldo juntos, nunca una imagen con menos migraciones que la base (AGENTS.md). Para un
negocio concreto, el interruptor apagado es la vuelta atrás sin despliegue.
