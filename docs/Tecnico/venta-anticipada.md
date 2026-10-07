# Venta anticipada (Preventas) — diseño técnico

> **Estado (2026-10-07):** **diseño cerrado, en construcción (Fase 0).** El mapa de lo que existe (§2) está
> verificado contra `develop` en `f2f489c19`. Las decisiones de negocio están en el documento hermano
> `docs/Funcional/venta-anticipada.md` §6 (D1-D25). Las técnicas, en §3 (T1-T19). Las dos que este
> documento dejaba abiertas quedaron resueltas el 2026-10-07: la entrega va por la pantalla de caja y
> D7 está confirmada. Antes de codificar solo quedan dos verificaciones (§12). Plan de construcción
> en §13.

---

## 1. Qué se pide, en términos de datos

El negocio arma **campañas**: un conjunto de productos con precio de preventa, una lista de fechas de
entrega y un periodo de venta. Dentro de una campaña registra **preventas**: un acuerdo con un cliente
registrado, con líneas a precio congelado, una de las fechas de entrega, un calendario de cuotas y
una serie de abonos. La preventa **no toca el inventario ni la tabla `sales` hasta la entrega**, y la
entrega exige saldo cero.

Hay dos cosas que tienen que salir bien y que la mecánica actual de OSPOS hace mal:

1. **Cada abono se atribuye al turno de caja que lo recibió.**
2. **La plata no se cuenta dos veces** cuando la preventa se convierte en venta.

---

## 2. Mapa de lo que existe

Todas las rutas son relativas a la raíz del repositorio.

| Pieza | Dónde | Estado para este caso |
|---|---|---|
| Medio de pago «Adeudo» (`due`) | `app/Helpers/payment_type_helper.php:33`; se ofrece en `app/Helpers/locale_helper.php:267` | Completa la venta (COMPLETED), descuenta inventario (`Sale.php:768`) y cuenta como pago. Exigir cliente se controla **solo en la vista** (`app/Views/sales/register.php:724-744`); `Sales::postComplete()` no lo valida. **No sirve: es fiado.** |
| Venta suspendida con pagos | `Sales::postSuspend` (`Sales.php:2487-2518`); se retoma con `Sale_lib::copy_entire_sale` (`Sale_lib.php:1871-1913`) | Guarda pagos con `SUSPENDED`. Al completar, `Sale::clear_suspended_sale_detail()` (`Sale.php:1550-1576`) **borra y reinserta** los pagos con `payment_time` = ahora, y `save_value()` sella la venta con el turno abierto en ese momento (`Sale.php:678-692`). **Una cuota de octubre queda en el turno de diciembre.** |
| Orden de trabajo y depósitos | `cash_deposit` / `credit_deposit` solo en modo `sale_work_order` (`Sale.php:1075-1078`); requiere `work_order_enable` (por defecto 0) | Mismo problema de reescritura. El autollenado del cierre ignora los depósitos (`Cashups.php:153-169`) y la conciliación solo cuenta `payment_type_code = 'cash'` (`Cashups.php:246`). Al anular conserva los pagos y no hay devolución. |
| Tarjeta de regalo y puntos | `Sale::save_value` `:713-722` | **Probable error heredado (por lectura, sin reproducir):** descuentan en cada guardado sin mirar el estado. Otra razón para no montar la preventa sobre suspendidas. |
| Cotización | `Sales.php:1414-1419` | Rechaza pagos a propósito (`tests/Controllers/SalesQuoteTest.php:99`). |
| Editar pagos de una venta | `Sales::getEdit` / `postSave` (`Sales.php:2209-2421`), `app/Views/sales/form.php` | El monto es de solo lectura (`form.php:92`), pero **el tipo se puede cambiar** (`form.php:84`). Las tarjetas de regalo se bloquean (`form.php:79-82`). Importa para §8.3. |
| Sello del turno | `sales.cashup_id`, escrito solo al completar (`Sale.php:688-692`); `Sale::update` lo preserva (`Sale.php:463-467`) | El turno se guarda **por venta, no por pago**. Ningún código lee `sales_payments.payment_time`. |
| Conciliación del cierre | `Cashups::_build_reconciliation` (`Cashups.php:237-298`), fuente `Sale::get_payments_by_cashup` (`Sale.php:548-603`). Vista: `app/Views/cashups/form.php:393-460`, bloque de anuladas `:414-428` | Esperado = apertura + efectivo − gastos de caja − recogidas. **Es el punto donde se enchufan los abonos** (§6). |
| Autollenado del cierre | `Cashups.php:150-169`, por rango de fechas vía `Summary_payments` | Ignora cualquier código distinto de efectivo, débito, crédito y transferencia. |
| Recogidas de efectivo | `app/Models/Cash_collection.php`, migración `20260823060000_AddCashCollections.php` | **Precedente**: un movimiento de caja que no es una venta, con tabla propia. Aquí se va un paso más allá: el abono guarda `cashup_id`, mientras que la recogida se asigna por ventana de fechas. |
| Ingresos vs Gastos, modo caja | `app/Models/Reports/Income_expenses.php:176-183` | Lee `sales_payments` agrupado por `sales.sale_time`. Un pago de preventa en `sales_payments` caería en la fecha de la entrega (§7.3). |
| Estadísticas del cliente | `Customer::get_stats` (`Customer.php:132-175`) | Suma `payment_amount − cash_refund` de ventas COMPLETED. Contar la preventa el día de la entrega es correcto. |
| Pestañas de la caja | `docs/Tecnico/ventas-en-paralelo-pestanas.md`; `Sale::create_open_sale` (`Sale.php:1349`), `Dinner_table::create_at` (`Dinner_table.php:62`), `postChangeMode` (`Sales.php:290-320`) | Comandas crea una pestaña desde un módulo externo (`OrderTickets.php:131-148`). Es el precedente de la entrega (§7). |
| Jalar líneas externas a la caja | `Sales::_sync_order_ticket()` (`Sales.php:1878-1920`), llamado desde `_reload()` (`:1951-1962`); `Order_ticket_register::add_unbilled_to_cart()` | Precedente de cómo un módulo alimenta el carrito y lo autoguarda. |
| Módulo, interruptor y pestaña de Configuración | `20260923020000_AddOrderTicketsModule.php`, `20260923010000_AddOrderTicketsConfigKeys.php`; `Config::postSaveOrderTickets` (`Config.php:915-934`); `views/configs/order_tickets_config.php`; `OrderTickets::is_enabled` (`OrderTickets.php:633-636`) | Patrón a copiar literal (§5). |
| Lista con tabla y modal | `Cashups::getIndex/getSearch/getView` (`Cashups.php:38-87`), `views/cashups/manage.php`, `tabular_helper.php:869-913`, `public/js/manage_tables.js` | Pantalla más parecida para la lista de preventas (regla de homogeneidad del diseño). |
| Comprobante fuera de la venta | `views/order_tickets/round_print.php`, `views/partial/receipt_paper.php`, `Sale_lib::receipt_printable_width_mm` (`Sale_lib.php:220`) | Modelo de los comprobantes de preventa, abono y cancelación. |
| Borrado de artículos protegido | `tests/Controllers/ItemsDeleteGuardTest.php` | Hay que extenderlo (§8.5). |
| Soporte de plataforma | `app/Commands/PlatformSupportEmployee.php` → `TenantProvisioner::grantEveryPermission()` (`TenantProvisioner.php:544-585`) | Recoge los permisos nuevos al volver a correrlo a mano después del despliegue. |

Constantes (`app/Config/Constants.php:136-145`): `COMPLETED=0`, `SUSPENDED=1`, `CANCELED=2`,
`OPENED=3`; `SALE_TYPE_POS=0`, `INVOICE=1`, `WORK_ORDER=2`, `QUOTE=3`, `RETURN=4`.

**No hay pruebas** de Adeudo, depósitos, tarjetas de regalo ni puntos.

---

## 3. Decisiones técnicas

| # | Decisión | Por qué | Lo que se descartó |
|---|---|---|---|
| **T1** | **Tablas propias** (§4). La preventa no vive en `sales` hasta la entrega | `sales` alimenta todos los reportes, el inventario y el cuadre. Una fila ahí con un estado nuevo obliga a revisar cada consulta que filtra por `sale_status`; ya son 9 archivos solo con `SUSPENDED` | Un estado `PRESALE=4` en `sales`; venta suspendida u orden de trabajo (§2) |
| **T2** | **El abono es un movimiento propio** en `presale_payments`, con su `payment_time` y su `cashup_id` | Es lo único que atribuye cada peso a su turno sin tocar la mecánica de `sales_payments` | Agregar `cashup_id` a `sales_payments` para toda la aplicación: correcto en abstracto, pero cambia la consulta del cuadre de todos los negocios en plena temporada |
| **T3** | **El abono y la devolución exigen un turno abierto** (`Cashup::get_open_cashup_id() !== null`); sin turno se rechazan | Una venta sin turno se tolera (`cashup_id` nulo es una respuesta válida). Un abono sin turno es plata que nadie cuadra | Tolerar nulo como en ventas |
| **T4** | **La entrega crea una venta COMPLETED normal** (`SALE_TYPE_POS`) con un pago de un código nuevo, **`presale`** («Preventa»), por lo abonado (T18). Se enlaza con `presales.sale_id` | Reportes por artículo, categoría, empleado e impuestos, el inventario, el recibo y la estadística del cliente funcionan sin cambios (D7) | Un `sale_type` nuevo: cada `switch` sobre `sale_type` de vistas y recibos tendría que aprenderlo |
| **T5** | **El código `presale` nunca es efectivo** y **se excluye de `income_total`** del turno; se muestra aparte como «Entregas de preventa (cobradas antes)» | La plata ya entró por `presale_payments` en su turno (D12) | — |
| **T6** | **El estado guardado es solo `open` / `delivered` / `canceled`.** «Al día», «Atrasada» y «Pagada» **se calculan al leer** | Un estado derivado y guardado se desincroniza en cuanto alguien abona. Con cientos de filas, calcularlo cuesta nada | Un trabajo nocturno que marque atrasos |
| **T7** | **El saldo se valida en el servidor, dentro de una transacción con bloqueo de la fila** (`SELECT … FOR UPDATE` sobre `presales`) | Dos clics, o dos cajas abonando a la vez, no pueden dejar el saldo negativo | Validarlo solo en la vista, el error de origen de «Adeudo» |
| **T8** | **Precio congelado por línea** (`unit_price`, `discount`, `discount_type`) copiado de la campaña al registrar. Todo cambio pasa por `presale_events` | D8 | Leer el precio del catálogo o de la campaña al entregar |
| **T9** | **Número de preventa = prefijo + id con ceros** (`PV-000123`). El prefijo sale de configuración | Sin contador aparte que pueda desincronizarse | Un token de secuencia como `{WSEQ}` |
| **T10** | **Medios de pago del abono y de la devolución: `cash`, `debit`, `credit`, `bank_transfer`.** Lista cerrada en el servidor | Adeudo, tarjeta de regalo, puntos y depósitos no tienen sentido aquí | — |
| **T11** | **Módulo `presales` con UNA sola subpermisión, `presales_manage`**, que cubre campañas y cancelación | La regla de prefijo de `Employee::has_module_grant()` (`Employee.php:483-502`): con dos o más subpermisiones, un empleado sin el permiso base pasa el chequeo. Explicado en `AddOrderTicketsModule` | Un permiso por acción |
| **T12** | **Interruptor por negocio `presales_enable`**, en la Configuración del propio negocio, sembrado en `'0'` y leído siempre con `?? '0'` | Igual que comandas: la consola de plataforma no escribe en el `app_config` de ningún negocio | Encenderlo desde la plataforma |
| **T13** | **Campañas en tablas propias** (`presale_campaigns`, `presale_campaign_items`, `presale_campaign_dates`). `presales.campaign_id` es obligatorio | D13: productos, precios, fechas y periodo son configuración del negocio, no código | Una casilla «vendible en preventa» en `items` |
| **T14** | **El precio de campaña se materializa al agregar el producto**: `base_price` = `unit_price` del catálogo en ese momento. `campaign_price` y `discount_percent` del producto son opcionales (NULL = heredar); la campaña tiene su propio `discount_percent`. Precio efectivo = `campaign_price ?? base_price × (1 − (item.discount_percent ?? campaign.discount_percent)/100)`, redondeado a `totals_decimals()`. Se calcula en un solo método del modelo | D14, D19: «inicialmente serán los valores del catálogo», y no sigue sus cambios. Guardar `base_price` deja ver de dónde salió el precio | Leer el catálogo en vivo cada vez |
| **T15** | **La fecha de entrega es una FK a `presale_campaign_dates`**, no una fecha libre. Se guarda también `delivery_date` desnormalizada para filtrar y ordenar | D15. Una fecha de la campaña con preventas no se puede borrar | Fecha libre con sugerencias |
| **T16** | **El periodo de venta se valida en el servidor** al registrar: `sale_starts ≤ hoy ≤ sale_ends` y campaña activa. Abonar, entregar y cancelar **no** dependen del periodo | D16 | — |
| **T17** | **Cuota inicial mínima** = `presale_campaigns.min_initial_percent` (0-100, 0 = sin mínimo). Se valida en el servidor contra la primera cuota **y** contra el abono que se cobra al registrar, redondeando hacia arriba a `totals_decimals()` | D21 | Un monto fijo |
| **T18** | **Peso variable en la entrega**: en la pestaña de entrega solo se puede editar la cantidad de las líneas de artículos por peso, al precio congelado. El pago `presale` = **lo abonado** (no el total de la venta); la diferencia se cobra con pagos normales de la caja o sale como vuelto en efectivo, y cuenta en el turno de la entrega | D22 | Bloquear el peso y ajustar después con una devolución |
| **T19** | **Condiciones: `presales_terms` por negocio**, sembrada vacía. El texto sugerido vive en los archivos de idioma (`Presales.terms_template`), y el botón «Usar texto sugerido» lo copia al campo | D23. Un texto sembrado en `app_config` quedaría en un solo idioma y en todos los negocios | Sembrarlo en la migración |

---

## 4. Modelo de datos

Todas las tablas con el prefijo `ospos_`, InnoDB, utf8mb4. Montos en `decimal(15,2)` y cantidades en
`decimal(15,3)` (admite peso), igual que `sales_items` y `sales_payments`. Sin FKs declaradas ni
TIMESTAMP, como `AddOrderTickets` (DATETIME, guardas `tableExists`).

### 4.1 `presale_campaigns`

| columna | tipo | nota |
|---|---|---|
| `campaign_id` | int PK AI | |
| `name` | varchar(100) NOT NULL | |
| `sale_starts`, `sale_ends` | date NOT NULL | Periodo de venta, inclusivo (T16) |
| `active` | tinyint NOT NULL default 1 | Desactivar oculta la campaña para registrar, nada más |
| `discount_percent` | decimal(5,2) NOT NULL default 0 | Descuento general (D19) |
| `min_initial_percent` | decimal(5,2) NOT NULL default 0 | Cuota inicial mínima (T17) |
| `created_at`, `created_by` | datetime / int | |
| `deleted` | tinyint default 0 | Una campaña con preventas nunca se borra físicamente |

### 4.2 `presale_campaign_items`

`campaign_id`, `item_id` (PK compuesta), `base_price` decimal(15,2) NOT NULL, `campaign_price`
decimal(15,2) NULL y `discount_percent` decimal(5,2) NULL, donde NULL hereda el de la campaña (T14). Un artículo kit se
admite: al registrar la preventa se expande como lo hace la caja (§8.6).

### 4.3 `presale_campaign_dates`

`date_id` PK, `campaign_id`, `delivery_date` date. Clave única en `(campaign_id, delivery_date)`.

### 4.4 `presales`

| columna | tipo | nota |
|---|---|---|
| `presale_id` | int PK AI | |
| `campaign_id` | int NOT NULL | T13 |
| `customer_id` | int NOT NULL | D3 |
| `employee_id` | int NOT NULL | Quien la registró |
| `location_id` | int NOT NULL | De dónde saldrá el inventario al entregar |
| `created_at` | datetime NOT NULL | |
| `delivery_date_id` | int NOT NULL | T15 |
| `delivery_date` | date NOT NULL | Copia desnormalizada |
| `status` | tinyint NOT NULL default 0 | 0 open, 1 delivered, 2 canceled (T6) |
| `total` | decimal(15,2) NOT NULL | |
| `comment` | text NULL | |
| `sale_id` | int NULL UNIQUE | La venta de la entrega (T4) |
| `delivered_at`, `delivered_by` | datetime / int NULL | |
| `canceled_at`, `canceled_by` | datetime / int NULL | |
| `cancel_reason` | text NULL | D10 |

Índices: `(status, delivery_date)`, `(campaign_id, status)`, `(customer_id)`.

### 4.5 `presale_items`

`presale_id`, `line` (PK compuesta, como `sales_items`), `item_id`, `description` varchar NULL,
`quantity`, `unit_price`, `discount`, `discount_type`, `print_option`, `item_type`. Las columnas
`print_option` e `item_type` permiten reconstruir en la caja las líneas de kit exactamente como
quedaron (§7.2).

### 4.6 `presale_installments`

`installment_id` PK, `presale_id`, `due_date` date, `amount` decimal(15,2). Es el plan
**comprometido**, no lo pagado: los abonos no se asignan a una cuota concreta. Se comparan los
acumulados (§4.9).

### 4.7 `presale_payments`

| columna | tipo | nota |
|---|---|---|
| `payment_id` | int PK AI | |
| `presale_id` | int | |
| `kind` | varchar(10) `payment` / `refund` | Una devolución (D10) es una fila `refund` con monto positivo |
| `payment_type_code` | varchar(40) | Solo los de T10 |
| `amount` | decimal(15,2) > 0 | |
| `payment_time` | datetime NOT NULL | |
| `employee_id` | int | |
| `cashup_id` | int NOT NULL | T3 |
| `reference_code` | varchar NULL | Referencia de la transferencia o del datáfono |

Índices: `(cashup_id)`, `(presale_id)`, `(payment_time)`.

### 4.8 `presale_events`

`event_id`, `presale_id`, `event_time`, `employee_id`, `event_type` (`created`, `payment`, `refund`,
`delivered`, `canceled`; después de la salida también `price_adjusted`, `quantity_adjusted`,
`delivery_date_changed` y `plan_changed`), `detail` json (antes / después) y `reason` text. **Es solo
de inserción**: nada lo actualiza ni lo borra.

### 4.9 Derivados (T6)

- `paid` = Σ `payment` − Σ `refund`
- `balance` = `total` − `paid` (solo para `open`)
- `due_to_date` = Σ `installments.amount` con `due_date < hoy`
- **Atrasada** ⇔ `status = open` y `paid < due_to_date`. **Días de atraso** = hoy menos la fecha de
  la cuota vencida más antigua que el acumulado pagado no cubre.
- **Pagada** ⇔ `status = open` y `balance = 0`

«Hoy» es la fecha en la zona horaria de `app_config.timezone` del negocio, no la del servidor (ver la
memoria «Timezone real de OSPOS»).

### 4.10 Invariantes, en el servidor

1. **Campaña:** activa, no borrada y dentro de su periodo de venta al registrar (T16).
2. **Productos:** cada línea pertenece a la campaña, con el precio efectivo de la campaña (T14).
3. **Fecha de entrega:** pertenece a la campaña (T15).
4. **Plan:** Σ `installments.amount` = `total`, y `max(due_date)` ≤ `delivery_date`. La primera
   cuota y el abono inicial ≥ `ceil(total × min_initial_percent / 100)` (T17).
5. **Abono:** no puede pasar de `balance`. **Devolución:** no puede pasar de `paid`.
6. **Entrega:** solo con `balance = 0` (D11), y solo una vez (`sale_id` UNIQUE + `UPDATE … WHERE
   status = 0`, con las filas afectadas comprobadas).
7. **Cierre:** una preventa `delivered` o `canceled` es de solo lectura.

---

## 5. Interruptor, módulo y permisos

**Migraciones**, una por tema y con plantilla literal:

- `AddPresales`: las 8 tablas.
- `AddPresalesConfigKeys`: `presales_enable = '0'`, `presales_prefix = 'PV-'` y `presales_terms = ''`
  (texto de condiciones de los comprobantes). Nunca sobrescribe un valor existente.
- `AddPresalesModule`: módulo `presales`, sort 72 (después de Ventas, que es 70, y antes de Comandas,
  que es 75), permisos `presales` y `presales_manage`. **Sin conceder ningún permiso.**
- Icono: una línea `copy-menubar` en `gulpfile.js` para `presales.svg`; si falta, falla
  `tests/Views/MenubarIconsTest.php`.

| permiso | abre |
|---|---|
| `presales` | Lista, ver, registrar, abonar, entregar, imprimir |
| `presales_manage` | Además: campañas (crear, editar, activar, cerrar) y cancelar con devolución |

**Endpoints propios de búsqueda** (`presales/suggestCustomer`, `presales/suggestItem`):
`customers/suggest` exige el permiso `customers` y la búsqueda de la caja exige `sales`. Un cajero de
preventas no necesariamente los tiene. `Writeoffs::getSuggest` (`Writeoffs.php:140`) es la plantilla.
Dar de alta un cliente en línea sigue exigiendo el permiso `customers`, como en la caja.

**Con el interruptor apagado:**
- el controlador muestra la vista `disabled`;
- el menú no aparece aunque haya permisos;
- el cuadre no consulta `presale_payments`;
- «Entregar» no existe en la caja.

**Apagarlo con preventas abiertas:** la pantalla de Configuración muestra cuántas hay y pide
confirmar.

Tras el despliegue hay que correr `php spark platform:support-employee` (AGENTS.md).

---

## 6. El cuadre de caja

Es la parte delicada. La conciliación actual está en producción desde 2026-09-20 y costó llegar ahí
(`docs/Tecnico/cuadre-de-caja-y-origen-del-efectivo.md`).

### 6.1 Conciliación (`Cashups::_build_reconciliation`)

1. Un método nuevo, `Presale_payment::get_by_cashup(int $cashup_id)`, agrupa por `payment_type_code`
   y por `kind`, con la devolución en negativo. Se lee por `cashup_id`, no por rango de fechas, por
   la misma razón que `get_payments_by_cashup`.
2. `income_cash` suma el neto de `cash`. `income_total` suma el neto de todos los códigos.
3. **El código `presale` se saca de `income_total`** y va a un renglón informativo, «Entregas de
   preventa (cobradas antes)» (T5). No afecta el esperado, porque el esperado solo mira `cash`.
4. En la vista (`cashups/form.php`, junto a «Ventas anuladas», `:414-428`) se agregan los bloques
   «Abonos de preventa», por medio de pago, y «Entregas de preventa».

### 6.2 Autollenado del cierre (`Cashups.php:150-169`)

Se le suman los abonos netos del mismo rango leídos de `presale_payments`, para que «Efectivo»,
«Datáfono» y «Banco» se llenen con lo que de verdad entró. El código `presale` ya cae fuera de los
`if` existentes.

### 6.3 Con el interruptor apagado

**El cuadre es idéntico al de hoy**, y ni siquiera se consulta `presale_payments`. Lo fija una
prueba (§11).

---

## 7. La entrega, por la pantalla de caja (resuelto: D17)

### 7.1 El flujo

1. «Entregar», en el módulo, solo existe con `balance = 0` y `status = open`.
2. En una transacción, como en `OrderTickets.php:131-148`:
   - se crea una pestaña: `Dinner_table::create_at` + `Sale::create_open_sale` con estado OPENED;
   - se guarda la marca de que esa venta abierta es la entrega de la preventa N, en la sesión de la
     caja y en la base (`presales` o una columna en la venta abierta; se decide en el carril D).
3. La caja cambia a esa pestaña. `Presale_register` (librería nueva, a la par de
   `Order_ticket_register`) carga el carrito y el pago.
4. El cajero pulsa Completar. `postComplete()` hace lo de siempre: impuestos, inventario, sello del
   turno, recibo e impresora.

### 7.2 Cargar el carrito

- Las líneas se reconstruyen tal como quedaron en `presale_items`, con
  `add_item(..., PRICE_MODE_STANDARD, null, null, $frozen_price, $desc, null, null, true,
  $print_option)`, igual que `copy_entire_sale` (`Sale_lib.php:1877`).
- **`add_item` fusiona líneas del mismo artículo** (`Sale_lib.php:1514-1528`), y la línea fusionada
  queda inconsistente: precio viejo, total nuevo (`:1601-1604`). Por eso se arma el carrito completo
  y se escribe con `set_cart()` (`:534`), o se agrupa antes por (artículo, precio).
- El cliente se asigna **sin** pasar por `apply_customer_discount()` (`Sale_lib.php:2007`); si no, el
  descuento del cliente alteraría las líneas congeladas.
- El precio congelado no necesita el permiso `sales_change_price`: ese permiso solo se mira en
  `postEditItem`. Las líneas no llevan la clave `reprice`, así que no se cuelan al catálogo.
- Un único pago `presale` por **lo abonado** (= total pactado, porque se entrega con saldo cero).
  Sin cambios de peso, la venta queda pagada exacta. Hay que cuidar el redondeo de efectivo
  (`Sale_lib.php:1154-1166`).
- **Peso real (T18):** las líneas de artículos por peso admiten editar la cantidad, y solo la
  cantidad. Si el total sube, el cajero agrega un pago normal por la diferencia. Si baja, el
  excedente del pago `presale` sale como vuelto en efectivo por la vía de siempre
  (`Sales.php:1317-1338`), que abre el cajón y queda en el turno de la entrega.

### 7.3 Guardas en el servidor, todas con prueba

| Riesgo | Dónde | Guarda |
|---|---|---|
| `empty_payments()` borra el pago `presale` al cambiar de pestaña o editar | `Sales.php:332, 1162, 1187` | Reinyectarlo en `_reload()`, junto a `_sync_order_ticket()` (`Sales.php:1962`), desde la base y no desde la sesión |
| Agregar, editar o borrar líneas | `postAdd` `:584`, `postAddWeight` `:692`, `postEditItem` `:1035`, `getDeleteItem` `:1183`, cambios de número, nombre y descripción `:2609-2653` | Rechazar si la pestaña es una entrega de preventa. **Excepción (T18):** `postEditItem` acepta solo la cantidad en líneas de artículos por peso; precio, descuento y todo lo demás se ignoran |
| Cambiar o quitar el cliente | `postSelectCustomer` `:250`, `getRemoveCustomer` `:1200` | Rechazar |
| Quitar el pago `presale` o agregar otro `presale` | `getDeletePayment` `:569`, `postAddPayment` `:469` | Rechazar. Los pagos normales sí se admiten, porque cubren la diferencia por peso (T18). **`postAddPayment` hoy acepta cualquier etiqueta** (`:553-556`): rechazar `presale` siempre que no lo inyecte `Presale_register` |
| Suspender o cancelar la pestaña | `postSuspend` `:2487`, `postCancel` `:2431` (borra la venta) | Rechazar, y ocultar los botones |
| Completar sin cubrir el total | `postComplete` solo verifica total negativo (`:1304`) | Para la entrega: verificar que la preventa sigue `open` y con saldo 0, que el pago `presale` = lo abonado, y que los pagos cubren el total de la venta |
| Entregar dos veces | Dos cajas a la vez | `UPDATE presales SET status=1, sale_id=?, delivered_at=?, delivered_by=? WHERE presale_id=? AND status=0`, comprobando las filas afectadas |

### 7.4 Atomicidad

`save_value()` no tiene un punto de enganche. Las transacciones de CodeIgniter 4 se anidan (las
internas solo cambian el contador de profundidad), así que el controlador envuelve todo:

1. `transBegin()` antes de `save_value` (`Sales.php:1473`).
2. `save_value`.
3. El `UPDATE` de §7.3.
4. `commit`, o `rollback` si `save_value` devolvió −1 o el `UPDATE` no afectó ninguna fila.

Así entra también la transacción separada de `clear_suspended_sale_detail()` (`Sale.php:1550-1575`).
Va antes del borrado de la mesa desechable y de `_apply_pending_reprices`. El recibo sale igual que
hoy.

### 7.5 Lo que la entrega no debe romper

- **Sello del turno:** la venta queda sellada con el turno de la entrega. Su único pago es `presale`,
  que no es efectivo y está fuera de `income_total` (§6.1).
- **Ingresos vs Gastos, modo devengo:** la venta aparece en la fecha de entrega, sin cambios (D7).
- **Ingresos vs Gastos, modo caja** (`Income_expenses::paymentsByPeriod`, `:176`): **excluir
  `presale`** y **sumar los abonos netos de `presale_payments` por `payment_time`**.
- **`Summary_payments`:** muestra una línea «Preventa» en la fecha de entrega, con la etiqueta
  clara.
- **Lista blanca de filtros de reportes:** sale de las claves del mapa de códigos (`Reports.php:203`),
  así que `presale` queda filtrable. Es inofensivo.
- **Cajón:** solo se abre con efectivo (`Sale_lib.php:304-342`). En la entrega no se abre; en un
  abono en efectivo sí.
- **Mesas apagadas:** la pestaña de entrega tiene que funcionar con `dinner_table_enable = 0`. La
  mecánica de pestañas depende hoy de las mesas, así que el carril D debe comprobarlo primero y, si
  hace falta, dar a la entrega su propio camino de pestaña.

---

## 8. Trampas conocidas

1. **`clear_suspended_sale_detail` reescribe pagos.** Ninguna parte de la preventa puede pasar por una
   venta suspendida.
2. **El chequeo de cliente de Adeudo vive solo en la vista.** No repetir ese error: todas las
   invariantes de §4.10 y las guardas de §7.3 van en el servidor y tienen prueba.
3. **Editar una venta deja cambiar el tipo de pago.** Un `presale` cambiado a «Efectivo» desde
   `Sales::getEdit` contaría esa plata dos veces. Hay que bloquearlo como la tarjeta de regalo
   (`form.php:79-82`, `Sales.php:2240`), en la vista y en `postSave`.
4. **Anular la venta de una entrega** (`Sales::postDelete`) devolvería el inventario dejando la
   preventa entregada. Se bloquea; la devolución de mercancía va por el modo Devolución.
5. **Borrar** un artículo que está en una campaña o en una preventa abierta, o un cliente con
   preventas abiertas: se rechaza. Hay que extender la guarda de `ItemsDeleteGuardTest`.
6. **Kits.** `add_item_kit()` (`Sale_lib.php:1829-1865`) expande kits anidados de forma recursiva y
   reparte el precio según `price_option` (`:1466-1478`). Al registrar la preventa, un kit de la
   campaña se expande igual que en la caja y se guarda línea por línea con su `print_option`. El
   precio de campaña se asigna a la línea representativa del kit, y los componentes quedan como los
   dejaría la caja. Hay que probarlo con los tres `price_option`.
7. **Tildes.** `Customers::postSave` lee `first_name` y `last_name` sin filtro
   (`Customers.php:242-243`), pero la memoria del arreglo de tildes lista Clientes como pendiente.
   **Hay que verificarlo en staging** guardando y buscando «José Muñoz». La regla de la memoria
   aplica: ejecutar antes que razonar.
8. **Traducciones.** La aplicación corre en `es-MX`; una cadena escrita solo en `es-ES` no se ve.
   Escribir en `es-MX`, `es-ES` y `en`.
9. **ICU.** En los textos de idioma, `'{0}'` entre comillas simples se imprime tal cual.
10. **Fechas.** Entrada y salida en d/m/Y con lectura estricta (`docs/Tecnico/formato-de-fecha.md`).
    Usar `parse_typed_datetime()` y `typed_date_error()`.
11. **Base de pruebas compartida.** Una prueba que escriba `presales_enable` en `app_config` tiene que
    restaurarlo, con el patrón `TOUCHED_KEYS` de `ConfigOrderTicketsTest.php:34-71`.
12. **Sesión en las pruebas de controlador.** Hay que rearmar `$_SESSION` antes de cada petición
    (`OrderTicketsControllerTest.php:831-850`); si no, `Secure_Controller` hace `exit()` en silencio.

---

## 9. Multi-negocio

El módulo es de la plataforma (D2). Reglas que aplican a todo el código:

- **Nada propio de un negocio en el código**: ni nombres de campaña, ni productos, ni fechas, ni
  textos. Todo sale de la configuración y de los datos de cada negocio.
- **Instalado y apagado en todos los esquemas.** El entrypoint corre las migraciones en cada negocio
  al arrancar: tablas vacías, `presales_enable = '0'` y cero permisos concedidos.
- **Formato de números del negocio.** Todo monto o cantidad que llega de un formulario se lee con
  `parse_decimals()`: con `number_locale = es_CO` el punto separa miles
  (`docs/Tecnico/carga-masiva-de-articulos.md`). Las cantidades por peso usan la misma normalización
  que la caja (`Sale_lib::normalize_weight_input()`).
- **Impuesto incluido o no:** la preventa guarda precios como la caja (`unit_price`), y el impuesto
  se calcula en la entrega con el mismo `Tax_lib` de siempre. Los totales que muestra el módulo
  tienen que coincidir con los de la caja; hay que probarlo en las dos configuraciones.
- **Ubicación de inventario:** `presales.location_id` sale de la ubicación activa al registrar, y la
  entrega descuenta de esa ubicación.
- **Papel del recibo:** los comprobantes usan `partial/receipt_paper` (58 u 80 mm).
- **Funciones apagadas:** nada depende de Mesas, Comandas ni facturas (§7.5).
- **Artículos por peso:** cantidad decimal pactada como inicial; puede cambiar en la entrega (T18).
- **Solo artículos del catálogo del negocio** (D24): el buscador de la campaña solo ofrece artículos
  existentes y no borrados.
- **Verificación obligatoria:** con el módulo apagado, la caja, el cuadre y los reportes son
  idénticos a hoy, en un negocio que no lo usa.

---

## 10. Lo comprometido

```sql
SELECT p.campaign_id, pi.item_id, i.name, p.delivery_date, SUM(pi.quantity) AS committed
FROM ospos_presale_items pi
JOIN ospos_presales p ON p.presale_id = pi.presale_id AND p.status = 0
JOIN ospos_items i ON i.item_id = pi.item_id
WHERE p.campaign_id = ?
GROUP BY p.campaign_id, pi.item_id, i.name, p.delivery_date
```

Se cruza con `item_quantities` de la ubicación para mostrar lo que hay hoy y lo que falta. Las líneas
de kit cuentan por sus componentes, que es lo que hay que comprar. Con cientos de preventas la
consulta es trivial.

---

## 11. Pruebas

Ubicación: `tests/Database/PresalesMigrationTest.php`, `tests/Models/Presale*Test.php`,
`tests/Controllers/Presales*Test.php` y `tests/Controllers/ConfigPresalesTest.php`. Se corren en CI
(«PHPUnit Tests»); no hace falta Docker local.

| Prueba | Fija |
|---|---|
| Registrar rechaza: sin cliente, campaña inactiva o fuera de periodo, producto fuera de la campaña, fecha fuera de la lista, plan que no suma el total, cuota después de la entrega | §4.10, T13-T16 |
| Precio efectivo = `campaign_price` o `base_price × (1 − %)`, congelado aunque luego cambien la campaña o el catálogo | T8, T14 |
| Campaña cerrada: rechaza preventas nuevas, acepta abonos, entregas y cancelaciones | T16 |
| Abonar sin turno abierto es rechazado; medio de pago fuera de T10 rechazado | T3, T10 |
| Abonar más del saldo es rechazado, también con dos abonos concurrentes | T7 |
| Un abono en efectivo sube el esperado del turno que lo recibió, y **no** el del siguiente | D12 |
| Una devolución en efectivo baja el esperado de su turno; no puede pasar de lo abonado | D10 |
| Entregar con saldo es rechazado en el servidor | D11 |
| Venta de la entrega: COMPLETED, precios congelados, inventario descontado de la ubicación, un pago `presale` = lo abonado, sellada con el turno de la entrega, `income_total` de ese turno sin la plata abonada | T4, T5 |
| Entregar dos veces (dos peticiones) deja una sola venta | §7.3 |
| Cada guarda de §7.3 rechaza su acción en la pestaña de entrega | §7.3 |
| Entrega con Mesas apagado | §7.5 |
| Ingresos vs Gastos, modo caja: los abonos en sus fechas; el pago `presale` no aparece | §7.5 |
| `presale` no se puede cambiar de tipo en la edición de ventas, y la venta enlazada no se anula | §8.3, §8.4 |
| Atrasada, al día y pagada en fechas límite (vence hoy, ayer, pago exacto), con la zona horaria del negocio | T6 |
| Kits con los tres `price_option`, y un artículo por peso | §8.6, §9 |
| Precio efectivo con descuento de campaña, descuento propio del producto y precio propio (T14) | D19 |
| Cuota inicial menor al mínimo de la campaña es rechazada; mínimo 0 = sin mínimo | T17 |
| Entrega con peso real mayor (cobra la diferencia) y menor (vuelto en efectivo), ambas en el turno de la entrega; solo la cantidad de líneas por peso es editable | T18 |
| `presales_terms` vacía en la migración; el texto sugerido existe en es-MX, es-ES y en | T19 |
| Montos con `number_locale = es_CO` | §9 |
| Con el interruptor apagado: vista `disabled`, sin menú, y una conciliación idéntica a la de hoy | §6.3, §9 |
| Permisos: sin `presales_manage` no se crean campañas ni se cancela; ningún permiso concedido por la migración | T11 |
| Borrar un artículo de campaña o un cliente con preventa abierta es rechazado | §8.5 |

---

## 12. Antes de escribir código (actualizado 2026-10-07)

Resuelto:
- **La entrega va por la caja** (D17).
- **D7 confirmada.**
- **Los productos son configurables por campaña** (D13), así que ya no hace falta mirar el catálogo
  de un negocio para diseñar.

Quedan dos verificaciones que no frenan el diseño:

1. **Tildes de Clientes en staging** (§8.7), antes de que el primer negocio registre clientes en
   masa.
2. **Que los productos de la primera campaña existan en el catálogo** del negocio que la abre. En
   Casaletto, el Pavo navideño está borrado.

---

## 13. Plan de construcción

Fecha objetivo: **producción a finales de octubre de 2026**, después de las 22:00. Detalle completo
en el plan de la sesión del 2026-10-07.

| Fase | Contenido | Forma |
|---|---|---|
| **0 · Cimientos** | Estos documentos; migraciones; modelos con invariantes y estado derivado; código `presale` y textos; pestaña de Configuración; controlador vacío con `is_enabled()`; pruebas de migración, modelo y config; tildes en staging | Secuencial, una sola mano. Punto de control: CI verde en `develop` |
| **1 · Carriles en paralelo** | **A** campañas · **B** registrar, lista, detalle, abonar y comprobantes · **C** cuadre, Ingresos vs Gastos, bloqueo en la edición de ventas y guardas de borrado · **D** entrega por la caja con sus guardas y su transacción | Un agente por carril, cada uno en su worktree y su rama; PR a `develop` |
| **2 · Lo que depende de B y C** | **E** cancelación con devolución · **F** lo comprometido por campaña | Dos carriles en paralelo |
| **3 · Integración y certificación** | Merge; suite completa en CI; revisión de código y de seguridad; staging en dos negocios con configuración distinta; **nuestra certificación con informe y evidencias (D25)**; después, la del equipo del negocio | Secuencial |
| **4 · Producción** | Despliegue por workflow; `platform:support-employee`; verificar «instalado y apagado» en todos los negocios; encender solo en el que lo pide | Después de las 22:00 |

**Efecto en un negocio que no lo encienda: ninguno, en ninguna fase.**

**Volver atrás:** imagen y respaldo juntos, nunca una imagen con menos migraciones que la base
(AGENTS.md). Para un negocio concreto, apagar el interruptor es la vuelta atrás sin despliegue.
