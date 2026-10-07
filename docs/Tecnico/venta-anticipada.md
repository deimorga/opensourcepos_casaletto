# Venta anticipada (Preventas) — diseño técnico

> **Estado (2026-10-07, 18:30):** construido, en `develop` y **certificado por nosotros en staging**
> (`4e17cb62d`, §7.10). Pendiente: certificación del equipo del negocio y producción. Decisiones de
> negocio en `docs/Funcional/venta-anticipada.md` §6 (D1-D29); técnicas en §3.
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
| **T20** | **El total de la preventa lo calcula el código de la caja** (`Presale_register::charge_for()`): `Tax_lib::get_taxes()` y las primitivas de `Sale_lib::get_totals()` sobre un carrito con las claves del de la entrega, para el cliente de la preventa y en modo venta; se guarda redondeado a medias hacia arriba a los decimales de la moneda, que es el mínimo que la caja acepta como pagado (§7.8) | D26: «los impuestos dependen de la configuración de cada negocio y la caja ya los maneja» | Sumar líneas redondeadas (lo que había: no cuadraba con pesos ni con impuesto aparte); reimplementar las reglas de impuestos en el modelo |
| **T21** | **Kit: precio de campaña = kit completo; componentes en 0** sea cual sea `price_option`; `print_option` el que la caja da a una línea en 0 | D27 | Componentes al precio que les da la caja (cobraba el kit dos veces con ALL o KIT_STOCK) |
| **T22** | **Tope de devolución por peso** `presales_weight_refund_limit` (porcentaje del total, `'15'`, `'0'` = sin tope); por encima, completar exige `presales_manage`; quién autorizó va en el evento `quantity_adjusted` | D28 | Una tolerancia que impida pesar menos; pedir `sales_change_price` |
| **T23** | **Entregar exige turno abierto**, en `postDeliverPresale` y al completar (`completion_refusal`) | D29; T3 ya lo exigía para abonar y devolver | Tolerar `cashup_id` nulo en la venta de la entrega |
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
| `status` | varchar(16) NOT NULL | `open`, `delivered`, `canceled` (T6). Código de texto y no número, el mismo criterio que `order_tickets.status` y `payment_type_code`: un número cuyo significado vive en otro archivo es lo que enseñó `sale_status` |
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
3. **Fecha de entrega:** pertenece a la campaña (T15) y es ≥ hoy (`Presales.delivery_date_past`,
   2026-10-07; hoy se admite).
4. **Plan:** Σ `installments.amount` = `total`, `min(due_date)` ≥ hoy (`Presales.installment_in_past`,
   2026-10-07) y `max(due_date)` ≤ `delivery_date`. La primera cuota y el abono inicial ≥
   `ceil(total × min_initial_percent / 100)` (T17). «Hoy» es el `$today` que recibe
   `Presale::create()`; el controlador pasa `date('Y-m-d')`.
5. **Abono:** no puede pasar de `balance`. **Devolución:** no puede pasar de `paid`.
6. **Entrega:** solo con `balance = 0` (D11), y solo una vez (`sale_id` UNIQUE + `UPDATE … WHERE
   status = 'open'`, con las filas afectadas comprobadas).
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
- el cuadre sigue leyendo `presale_payments` (vacío en un negocio que nunca lo usó; ver §6.3);
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

Se le suman los abonos netos **del turno** (`Presale_payment::get_by_cashup()`, por el `cashup_id` que
trae cada abono), para que «Efectivo», «Datáfono» y «Banco» se llenen con lo que de verdad entró. El
código `presale` ya cae fuera de los `if` existentes. *Corregido el 2026-10-07 (§7.10): la primera
versión los leía por la misma ventana de fechas que las ventas y, con dos turnos el mismo día, le daba a
uno los abonos del otro.*

### 6.3 Con el interruptor apagado *(corregido el 2026-10-07, carril C)*

**Un negocio que nunca usó preventas ve el cuadre idéntico al de hoy**, con el interruptor apagado o
encendido. Lo fija `tests/Models/PresaleCashupReconciliationTest.php`.

Lo que cambió respecto de la primera versión de este apartado: **`presale_payments` se consulta
siempre que la tabla exista, no solo con el interruptor encendido.** El interruptor dice si el
negocio puede usar preventas, no si la plata ya cobrada existe. Si un negocio toma un abono en
efectivo y apaga el módulo antes de cerrar el turno, ese efectivo está en el cajón: ocultarlo haría
que el turno diera sobrante, y reabrir un turno viejo daría cifras distintas según cómo esté el
interruptor ese día. Sin abonos, la consulta (indexada por `cashup_id`) devuelve vacío y el cuadre
sale igual que antes. Lo mismo vale para el pago `presale` de la venta de entrega (§6.1, paso 3) y
para el autollenado (§6.2).

En el autollenado con la configuración de solo-fecha, la ventana de los abonos se ensancha a días
completos igual que la de las ventas (`docs/Tecnico/cuadre-de-caja-y-origen-del-efectivo.md` §6.3).

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
  hace falta, dar a la entrega su propio camino de pestaña. **Resuelto en §7.6.**

### 7.6 Lo construido (carril D, 2026-10-07, rama `feat/presales-delivery`)

**Piezas.** `Sales::postDeliverPresale($presale_id)` (ruta automática `sales/deliverPresale/{id}`,
POST con CSRF; es el contrato con el carril B), la librería `app/Libraries/Presale_register.php`, la
marca de sesión `Sale_lib::get/set/clear_presale_id()` (clave `sales_presale_id`, que `clear_all()`
borra), los textos `Presale_register.*` en es-MX, es-ES y en, y cambios en `views/sales/register.php`.
Pruebas: `tests/Controllers/PresaleDeliveryRegisterTest.php` (18, contra la caja real) y
`tests/Libraries/PresaleRegisterTest.php` (6).

**El endpoint** exige, en este orden: `presales_enable = '1'`, el permiso `presales` (además del
acceso a Ventas que ya pide el controlador), la preventa abierta y en estado `paid` según
`get_summary()` (D11 en el servidor), y que el carrito actual se pueda dejar sin perder nada: vacío, o
una pestaña de mesa ya autoguardada. **Una venta en curso que no está guardada en ninguna parte (sin
Mesas, o en las seudomesas Domicilio/Para llevar) nunca se bota por una entrega**: se rechaza con
«La caja tiene una venta en curso». Si la preventa ya tiene su pestaña abierta, el endpoint lleva a
ella en vez de abrir otra.

**Mesas apagadas — comprobado en el código, no supuesto.** Con `dinner_table_enable = 0` la mecánica
de pestañas no existe: la barra solo se dibuja con Mesas (`register.php`, `#open_tabs_bar`),
`postChangeMode()` solo cambia de carrito con Mesas, `Sale_lib::get_dinner_table()` devuelve null y
`_autosave_open_tab()` no guarda nada. Una venta OPENED creada igual sería una pestaña a la que nadie
puede llegar. **Decisión:** con Mesas apagadas la entrega **es el carrito de la caja**, como cualquier
venta de ese negocio: vive en la sesión hasta completar y no se escribe nada antes. Perder la sesión
cuesta el carrito y nada más; la preventa sigue abierta y pagada y «Entregar» la vuelve a cargar. Lo
fija `testWithTablesOffTheDeliveryIsTheCartAndCompletes`, `testWithTablesOffCompletingTwiceProducesOneSale`
y `testWithTablesOffTheGuardsHold`.

**Con Mesas: la marca vive en `presales.sale_id`.** Al abrir la pestaña, en una transacción (como
`OrderTickets::postCreate()`): mesa desechable con el número de la preventa (`create_at`, ocupada),
venta OPENED (`create_open_sale`) y `UPDATE presales SET sale_id = <venta abierta> WHERE presale_id = ?
AND status = 'open' AND sale_id IS NULL` (o `= <enlace viejo>`), comprobando una fila afectada.
Por qué esa columna y no otra:

- `save_value()` completa la pestaña **en el mismo `sale_id`**, así que la venta abierta y la venta de
  la entrega son la misma fila: `mark_delivered()` vuelve a escribir el mismo valor.
- Es `UNIQUE`: dos preventas no pueden apuntar a la misma pestaña, y dos cajas que pulsan «Entregar»
  a la vez producen una pestaña y un rechazo.
- Sobrevive a todo lo que la sesión no: cambiar de pestaña (`clear_all()` + `copy_entire_sale()`),
  recargar, cerrar sesión y volver, otra caja. Lo fija
  `testTheDeliveryTabSurvivesSwitchingTabsAndANewSession`.
- Descartado: una columna nueva en `sales` (migración en la tabla más caliente para un caso raro), el
  comentario de la venta (lo ve y lo edita el cajero) y solo la sesión (una pestaña autoguardada con su
  pago `presale` y sin marca sería editable y cobrable: plata contada dos veces).

**Cómo sabe la caja que el carrito es una entrega** (`Presale_register::presale_for()`): primero la
marca de sesión; si no hay, `Presale::get_by_sale(sale_id actual)` cuando hay una venta guardada.
Sigue los **datos, no el interruptor**, como `Order_ticket_register`: una pestaña abierta con el módulo
encendido conserva sus guardas si después se apaga. Un negocio que nunca usó preventas paga un
`tableExists()` en caché y, solo con una venta guardada en pantalla, una consulta por índice único.
**Nunca lanza**: si la consulta falla devuelve null, y el respaldo es `postComplete()`, que rechaza
cualquier carrito con un pago `presale` que no sea una entrega reconocida.

**El carrito** (`Presale_register::load()`): cada línea se arma con `add_item()` sobre un carrito
vacío (así no hay fusión) y luego se juntan con `set_cart()`; `print_option` se copia de
`presale_items`. Cliente con `set_customer()`, sin `apply_customer_discount()`. Modo venta y
`SALE_TYPE_POS`. Un único pago con la etiqueta `lang('Sales.presale')` por `Presale_payment::get_paid()`.
`ensure_delivery_state()` lo repone en cada `_reload()` (después de `_sync_order_ticket()`), siempre
desde la base. Si el carrito ya no coincide con la preventa (`cart_matches()`: mismas líneas, artículo,
precio y descuento; cantidad igual salvo las líneas por peso, que solo tienen que ser > 0) se
reconstruye con `restore()`, conservando los pesos ya tecleados. Eso cubre la trampa de
`copy_entire_sale()`, que al volver a la pestaña fusionaría dos líneas del mismo artículo.

**Guardas** (todas con prueba en `testEveryGuardRefusesOnADeliveryTab`, y `testTheGuardsLeaveEveryOtherCartAlone`
fija que un carrito normal no se entera): rechazan en una entrega `postAdd`, `postAddWeight`,
`getDeleteItem`, `postChangeItemNumber/Name/Description` (JSON `success: false`), `postSelectCustomer`,
`getRemoveCustomer`, `postSuspend`, `postCancel` y `getDeletePayment` del pago `presale`.
`postAddPayment` rechaza la etiqueta `presale` **en cualquier carrito** (ningún desplegable la ofrece;
esto cierra el post forjado). `postChangeMode` deja el modo en venta (salir de la pestaña sí se
permite) y la vista solo ofrece «Venta». `postEditItem` en una entrega solo cambia la cantidad de las
líneas por peso, leída con `_parse_weight_quantity()` y > 0, al precio y descuento pactados; cualquier
otra línea se rechaza. Los pagos normales se admiten.

**Completar** (`postComplete`): antes de guardar, `completion_refusal()` relee la preventa (abierta y
`paid`), exige modo venta, que el carrito coincida, **un** pago `presale` igual a lo abonado y que los
pagos cubran el total. Luego `_save_presale_delivery()`: `transBegin()` → `save_value()` →
`Presale::mark_delivered()` → `transCommit()`, o `transRollback()` si `save_value` devolvió ≤ 0,
`mark_delivered` devolvió false o algo lanzó. El borrado de la mesa desechable, `_apply_pending_reprices`
y `mark_charged` van después, como siempre. Probado: venta COMPLETED con precios pactados, inventario
descontado de la ubicación de la preventa, un pago `presale` = 41,00, `cashup_id` del turno abierto y
la preventa `delivered` con ese `sale_id`; completar dos veces deja una venta y el inventario bajado una
vez (con y sin Mesas). Peso mayor: se rechaza hasta cobrar la diferencia; peso menor: la vía de
siempre agrega la línea de efectivo con `cash_refund` en el turno de la entrega.

**«Devolver a preventas»** (`Sales::postReleasePresale`, botón en lugar de Cancelar en una entrega):
saca la entrega de la caja sin cobrar nada. Con Mesas anula la venta OPENED, borra la mesa desechable
y desenlaza `presales.sale_id`; sin Mesas solo vacía el carrito. La preventa no se toca: sigue abierta
y pagada y se puede volver a entregar. Es la salida de la entrega equivocada o del cliente que no
llegó; sin ella, con Mesas apagadas, la caja quedaba bloqueada para cualquier otra venta (hallazgo de
la revisión adversarial). `postCancel` sigue rechazado en una entrega porque borra la venta que esté
en pantalla, y `postSuspend` porque una suspendida reescribe sus pagos (§8.1).

**Peso real, registrado:** al completar, si alguna cantidad difiere de la pactada se escribe en la
misma transacción un `presale_events` de tipo `quantity_adjusted` con `{sale_id, lines: [{line,
item_id, agreed, delivered}]}`. Un peso menor devuelve efectivo por el mostrador, y eso tiene que
poder leerse después.

**Preventa cancelada con la pestaña abierta:** desde el carril E (2026-10-07) `Presale::cancel()`
**rechaza** una preventa con su pestaña abierta (§7.7), así que con Mesas esto ya no ocurre por la
vía normal; queda como respaldo, y sin Mesas (la entrega vive en la sesión) sigue siendo el camino.
En el siguiente `_reload()` la venta OPENED se anula
(`Sale::delete`), la mesa desechable se borra, `presales.sale_id` se desenlaza (solo si no está
entregada) y el carrito se vacía con un aviso. Si la entregó otra caja, esta solo olvida la pestaña.

**Vista:** franja «Entrega de preventa PV-xxxxxx — solo se puede ajustar el peso de los productos por
peso» (`#presale_delivery_banner`, alerta informativa de Bootstrap como los demás avisos de la caja);
se ocultan la búsqueda de artículos, los iconos de borrar línea, precio y descuento editables, el botón
de quitar cliente, la papelera del pago `presale`, Suspender y Cancelar. La cantidad solo es editable
en líneas por peso.

**Revisión adversarial (2026-10-07), lo que se corrigió y lo que no.** Se corrigieron: la salida de
una entrega («Devolver a preventas»), el registro del peso real, la comprobación del cliente al
completar, la segunda caja que pierde la carrera al abrir la pestaña (ahora va a la pestaña de la
otra en vez de decir «intente de nuevo») y el retorno de `transBegin()`. Lo que no es de este carril
va en la lista siguiente.

**Quedan abiertos** (para la integración):

1. ~~La escritura de `presales.sale_id` al abrir y al desenlazar está en `Presale_register::attach()` y
   `detach()`.~~ **Cerrado el 2026-10-07 (carril E):** vive en `Presale::attach_delivery_sale()` /
   `detach_delivery_sale()`, con la misma semántica; `Presale_register` solo los llama (§7.7).
2. ~~**Peso menor sin tope (decisión del dueño).**~~ **Cerrado el 2026-10-07 (carril G, T22, §7.8):**
   tope configurable; por encima, solo con `presales_manage`. Sigue abierto lo de la unidad: «se vende
   por peso» se lee de la unidad **actual** del artículo; guardarla en `presale_items` al registrar lo
   fijaría.
3. ~~**Impuestos y redondeo (carril B).**~~ **Cerrado el 2026-10-07 (carril G, T20, §7.8):** el total se
   calcula con el código de la caja, impuestos y redondeo incluidos.
5. **Cuadre (carril C).** Esta es la primera vez que se escriben filas `sales_payments` con código
   `presale`. Hasta que el carril C las saque de `income_total` (§6.1), el turno de la entrega las
   muestra como ingreso (el efectivo esperado no cambia: no es `cash`). ~~Si no hay turno abierto, la
   venta queda con `cashup_id` nulo; falta decidir si una entrega exige turno.~~ **Decidido el
   2026-10-07 (D29, T23): la exige**, al mandarla a la caja y al completarla (§7.8).
6. **Editar o anular la venta entregada (carril C, §8.3-8.4)**: hasta que se bloquee en
   `postSave`/`postDelete`, se puede.
7. ~~**Cancelar una preventa con su pestaña abierta.**~~ **Cerrado el 2026-10-07 (carril E)**, de otra
   forma que la propuesta: `Presale::cancel()` **rechaza** la cancelación mientras la pestaña está
   abierta, en vez de anular la venta OPENED desde el modelo (§7.7).
8. ~~**El pago se reconoce por su etiqueta traducida.**~~ **Cerrado el 2026-10-07 (revisión, §7.9):**
   no era riesgo bajo. Los idiomas son por empleado, no por aplicación: una pestaña guardada por un
   cajero en `es-MX` («Preventa») y reabierta por uno en `en` dejaba la fila guardada como pago común
   y agregaba otro `presale`, y al completar la caja devolvía la preventa entera como vuelto. El pago
   lleva ahora su código en el carrito.
9. **Sin Mesas, `sales.location_id`** sale de la ubicación de la sesión (`save_value`), no de la
   preventa; el inventario sí sale de la ubicación de la preventa (por línea). Solo importa con varias
   sedes.
4. Con Mesas, un pago normal tecleado en la pestaña de entrega (por un peso mayor) vive en la sesión
   como en cualquier pestaña: si se cambia de pestaña antes de completar, se pierde y hay que volver a
   cobrarlo. Es el comportamiento de siempre de las pestañas.

### 7.7 Cancelar con devolución (carril E, 2026-10-07, rama `feat/presales-cancel`)

**Piezas.** `Presales::postCancel($id)` (`POST presales/cancel/{id}`), `postCancelPreview($id)`
(`POST presales/cancelPreview/{id}`), `getCancelReceipt($id)` (`GET presales/cancelReceipt/{id}`), la
vista `presales/receipt_cancel.php`, el formulario en `presales/detail.php`, y en el modelo
`Presale::cancel()` (ya existía) más `attach_delivery_sale()` / `detach_delivery_sale()`. Pruebas:
`tests/Controllers/PresalesCancelTest.php` y `tests/Models/PresaleDeliveryLinkTest.php`.

**El endpoint** exige `presales_enable = '1'` y `presales_manage` **en el servidor** (403 con mensaje
si falta cualquiera: lo llama un script, una redirección se leería como datos), además del permiso
`presales` de `Secure_Controller` y el CSRF global. Lee el monto con `parse_decimals()` (vacío = 0),
toma el medio de pago **solo** si el monto es mayor que cero y le pasa todo a `Presale::cancel()`, que
es quien decide: motivo obligatorio, devolución entre 0 y lo abonado, medio de T10, turno abierto
cuando hay devolución, bloqueo de la fila. Los mensajes van con `esc()` porque `$.notify` escribe HTML.

**La vista previa** (`cancelPreview`) calcula abonado / devuelto / retenido en el servidor, por la
misma razón que `postPreview()`: el monto se teclea en el formato del negocio y solo
`parse_decimals()` lo lee bien. La pantalla no hace aritmética.

**El formulario** se abre en el mismo detalle (un panel, como el de abonar), no en un modal encima del
diálogo: el detalle ya es un diálogo de Bootstrap y un segundo modal encima pelea por el foco y el
`z-index`. Medio y referencia solo se muestran con devolución mayor que cero.

**La pestaña de entrega abierta bloquea la cancelación.** Dentro de la transacción de `cancel()`, tras
el `SELECT … FOR UPDATE`, si `presales.sale_id` apunta a una venta con `sale_status = OPENED` se
devuelve `Presales.cancel_open_in_register` sin escribir nada. Va en el modelo y después del bloqueo
para que ningún camino se lo salte, y porque `attach_delivery_sale()` escribe la misma fila: si la caja
está abriendo la pestaña, una de las dos espera a la otra. Si `sale_id` apunta a algo que ya no es una
pestaña abierta (la venta se borró), el enlace es viejo: no bloquea y la cancelación lo pone en nulo.
**Descartado:** que `cancel()` anule la venta OPENED él mismo (lo que proponía §7.6). El modelo de
preventas no escribe en `sales` (su contrato, ver el encabezado de `Presale.php`), la mesa desechable y
el carrito de la sesión de otra caja quedarían a medias, y el cajero que tiene la pestaña abierta no se
enteraría. «Devolver a preventas» ya hace esa limpieza bien desde la caja.

**El enlace, en el modelo.** `attach_delivery_sale($presale_id, $sale_id, ?$stale_sale_id)`:
`UPDATE presales SET sale_id = ? WHERE presale_id = ? AND status = 'open' AND sale_id IS NULL` (o `=
<enlace viejo>`), con una fila afectada comprobada; sin transacción propia (la caja envuelve crear la
pestaña y enlazarla en una). `detach_delivery_sale($presale_id, $sale_id)`: pone `sale_id` en nulo solo
si sigue apuntando a esa venta y la preventa no está entregada. `Presale_register::attach()`/`detach()`
quedan como envoltorios (el segundo sigue sin lanzar hacia la caja).

**Comprobante de cancelación.** Se arma con los movimientos guardados (suma de `payment` = abonado,
suma de `refund` = devuelto, retenido = la resta), así una reimpresión dice lo mismo que el día. Lleva
quién canceló, el motivo y las condiciones con `nl2br(esc())`. Solo existe para una preventa
`canceled` (404 si no). Se reimprime con el permiso `presales`.

**El cajón** se abre después de una devolución **en efectivo**, con la misma marca de un solo uso de
los abonos (`DRAWER_FLASH`, valor `<id>:cancel`): la pone `postCancel()` y la consume el comprobante
que sigue, que además comprueba que la última devolución sea `cash` y pregunta a
`Sale_lib::should_open_cash_drawer()`. Reimprimir no lo abre.

**En el cuadre** no hubo que tocar nada: la fila `refund` lleva el `cashup_id` del turno abierto y el
carril C ya la resta (§6.1). La prueba lo comprueba con `_build_reconciliation()`: el esperado del
turno baja exactamente en lo devuelto.

**Pendiente:** certificar en staging (cancelar con devolución parcial en efectivo y ver el cierre del
turno, §8 del funcional, punto 10).

### 7.8 Decisiones del dueño (carril G, 2026-10-07, rama `feat/presales-owner-decisions`)

**Paridad del total con la caja (T20).** Lo que cobra la caja al completar
(`Sales::postComplete()`), leído en el código y no supuesto:

1. `Tax_lib::get_taxes($cart)` con el cliente y el modo **de la sesión** (`Sale_lib::get_customer()`,
   `get_mode()`): sin impuestos si el cliente no es `taxable`; impuesto por línea con
   `get_tax_for_amount()` (HALF_UP a `tax_decimals`) o `get_included_tax()`; con impuesto por
   destino, `apply_destination_tax()` con `default_tax_code` en modo venta; al final `round_taxes()` a
   `totals_decimals` (impuesto aparte) o `tax_decimals` (incluido).
2. `Sale_lib::get_totals($taxes)`: `Σ get_extended_amount(cantidad, precio, get_item_discount(...))`
   **sin redondear por línea**, más el `sale_tax_amount` de cada impuesto `TAX_TYPE_EXCLUDED`. Todo
   con `bcmath` a la escala por defecto, que `Load_config` fija en cada petición en
   `max(2, totals_decimals + tax_decimals)` (más allá de esa escala trunca, no redondea).
3. El total **no se redondea**. Se da por pagado cuando lo que falta es menor que media unidad de la
   moneda (`payments_cover_total`, umbral `10^-totals_decimals / 2`). El mínimo que cubre es el total
   redondeado a medias hacia arriba a `totals_decimals`; si se paga más, la diferencia sale como vuelto
   (`amount_change`).
4. **Redondeo de efectivo:** no aplica a la entrega. El pago `presale` no es efectivo, así que la caja
   no entra en modo efectivo (`Sale_lib::get_payments_total()`; `_reload()` lo reinicia con
   `reset_cash_rounding()`).

`Presale_register::charge_for($lines, $customer_id)` hace eso **llamando al mismo código**: arma con
`cart_for()` un carrito con las claves que leen `Tax_lib` y los totales (línea, artículo, cantidad,
precio y descuento pactados, `tax_category_id` y `stock_type` del artículo: los mismos valores que
`load()` obtiene de `add_item()` para esa línea), y en `register_charge()` llama a
`Tax_lib::get_taxes()` y a `Sale_lib::get_item_discount()` / `get_extended_amount()`. Fija la escala
de `bcmath` con la fórmula de `Load_config` y la deja como estaba. Devuelve el total sin redondear, los
impuestos agregados y el **cargo** = `Presale_campaign::round_money(total)` (medio hacia arriba a
`totals_decimals`), que es lo que guarda `Presale::create()` como `total` y lo que muestra
`presales/preview` (que ahora también devuelve `taxes`; el formulario lo vuelve a pedir al elegir o
quitar el cliente, y sin cliente lo calcula como para un cliente de mostrador, como la caja).

**El cliente, sin tocar la sesión.** El cajero puede tener una venta a medias en la caja, así que el
cálculo no puede cambiar el cliente de la sesión. `Tax_lib` recibe en su constructor el `Sale_lib` del
que lee el cliente y el modo (parámetro opcional agregado el 2026-10-07; sin él usa el de la sesión, como
siempre), y la preventa le pasa uno anónimo que responde el cliente de la preventa y el modo venta.
Todo lo demás es el código de la caja. Si el cálculo falla, `Presale::register_charge()` lo deja en el
log como crítico y la preventa se rechaza con `Presales.total_unavailable` (nunca un total equivocado).
**Descartado:** cambiar el cliente de la sesión y restaurarlo (un error a mitad dejaría la venta en curso
con otro cliente); inyectarlo por reflexión sobre la propiedad privada, que fue la primera versión del
carril G y se rompería en silencio con un renombre.

`Presale::price_lines()` sigue devolviendo `amount` por línea, **solo para mostrar**; el total no es su
suma.

**Límites conocidos.** El impuesto queda congelado en el total al registrar, pero la caja lo recalcula
al entregar con la configuración de ese día: si el negocio cambia impuestos o la categoría de un
artículo entre una cosa y la otra, la caja pedirá la diferencia (o dará vuelto). Con
`currency_decimals` mayor que 2, `round_money()` se queda en 2 (las columnas son `decimal(15,2)`).

**El residuo del redondeo hacia arriba.** Si el total de la caja tiene una fracción de media unidad o
más (25.074,90 en pesos), el cargo es 25.075 y la caja, por su regla de vueltas (`amount_change =
−amount_due`, cualquier valor positivo), anotaría 0,10 de vuelto en efectivo y abriría el cajón por
él. Hallazgo de la revisión adversarial. En una entrega, `postComplete` descarta un vuelto menor que
media unidad de la moneda (`Presale_register::is_rounding_remainder()`): la venta queda con el pago
`presale` solo. Es el simétrico de lo que la caja ya hace hacia abajo (25.145 pagados por 25.145,12
cuentan como pagado). Solo en entregas; la caja normal no cambia.

**Otras suposiciones, escritas.** El impuesto de un kit sale solo de las filas de impuesto del
artículo kit (los componentes van en 0): un kit sin impuestos propios se vende sin impuesto, en la
preventa y en su entrega por igual. Con impuesto por destino, un cliente sin `sales_tax_code_id` hace
fallar `Tax_lib::apply_destination_tax()` (tipo `int` con un `NULL`); la caja tiene el mismo fallo, y
la preventa lo muestra como `Presales.total_unavailable`. Las preventas registradas antes de este
cambio (solo en staging; el módulo no está en producción) guardaron la suma redondeada por línea sin
impuestos: si tienen impuestos o pesos con decimales, la caja pedirá la diferencia; se cancelan y se
registran de nuevo.

**Kits (T21).** `kit_components()` ya no recibe `price_option`: todo componente va en `0.00`, con el
`print_option` que la caja da a una línea en cero (`kit_print_option()`: PRICED no la imprime) y su
`item_type`. La línea del kit lleva el precio de campaña. Ver §8.6.

**Tope de devolución por peso (T22).** `presales_weight_refund_limit` (migración
`20261008030000_AddPresalesWeightRefundLimit`, sembrada en `'15'`, nunca sobrescribe; se lee con
`?? '15'`). Campo en Configuración > Preventas; `Config::postSavePresales` lo lee con
`parse_decimals(..., tax_decimals())`, exige 0-100 y, si el campo no viene en el POST, lo deja como
está. Al completar (`postComplete`, después de `completion_refusal()`),
`Presale_register::weight_refund_refusal($presale, $totals['total'], has_grant('presales_manage'))`
calcula lo devuelto = abonado − total de la caja con los pesos en pantalla, **sin redondear** (una
diferencia por encima del tope no puede redondearse hasta quedar en él), y rechaza con
`Presale_register.weight_refund_needs_manager` si pasa de `total × tope / 100` (estrictamente mayor)
y el cajero no tiene el permiso. **Solo cuando cambió un peso**: un vuelto con los pesos pactados
(impuestos que cambiaron después de registrar) no es una devolución por peso y no lo frena el tope.
En el evento, `refund` va a 2 decimales, como la columna `cash_refund` de la venta. El evento
`quantity_adjusted` lleva ahora `{sale_id, lines, refund, authorized_by}`: `authorized_by` es el
empleado que completó cuando pasó del tope (tiene el permiso, porque si no, no habría completado) y
`null` si no pasó.

**Turno abierto (T23).** `postDeliverPresale` rechaza con `Presale_register.no_open_cashup` antes de
tocar nada, y `completion_refusal()` lo vuelve a mirar al completar (el turno pudo cerrarse con la
entrega en pantalla).

**Pruebas.** `tests/Controllers/PresaleOwnerDecisionsTest.php` (contra la caja real: impuesto aparte
e incluido, cliente exento, líneas por peso en moneda sin decimales redondeando hacia abajo y hacia
arriba, vuelto que no viene del peso, vista previa, tope con y sin
permiso y en 0, turno al mandar y al completar, tildes en pantalla y comprobante);
`tests/Models/PresaleKitTest.php` (componentes en 0 con los tres `price_option`);
`tests/Controllers/ConfigPresalesTest.php` (el tope); `tests/Database/PresalesWeightRefundLimitMigrationTest.php`;
`tests/Views/PresalesConfigViewTest.php`. `PresaleDeliveryRegisterTest::testALighterWeightGivesCashChangeInTheDeliveryShift`
fija el tope en 0 (devuelve el 22 %) y lee el detalle nuevo del evento.

**Tildes (lo que reportó el carril E).** Revisado: ninguna vista ni controlador de preventas usa
`htmlentities()` ni lee con `FILTER_SANITIZE_*`; todo sale con `esc()` (`htmlspecialchars`, que deja
las tildes). Las entidades que vio el carril E salen del marco de pruebas, no de la aplicación:
`TestResponse::getBody()` no existe como método propio y `__call()` lo manda al analizador DOM de
`assertSee()`, que convierte todo lo no ASCII en entidades (`José` → `Jos&eacute;`). Comprobado en CI
el 2026-10-07: con `getBody()` la prueba nueva fallaba con `Mu&ntilde;oz`; con
`response()->getBody()`, el cuerpo real, sale «Muñoz». Una prueba que mire tildes en una página tiene
que leer `response()->getBody()`.

**Pendiente.** Con impuesto aparte, el comprobante y el detalle muestran las líneas sin impuesto y el
total con impuesto, sin un renglón de impuestos (el evento `created` ya guarda `taxes`).

### 7.9 Revisión de código (2026-10-07, rama `fix/presales-review`)

**El pago `presale` se reconoce por su código (hallazgo 1).** `Sale_lib::add_payment()` acepta un
cuarto parámetro opcional, `payment_type_code`, que guarda en la entrada del pago; `copy_entire_sale()`
le pasa el código de cada fila de `sales_payments`, así una pestaña recargada trae el código guardado
y no solo la etiqueta en el idioma de quien la guardó. `Presale_register::ensure_payment()` marca su
pago con `'payment_type_code' => 'presale'`. `Presale_register::is_presale_entry($key, $payment)`
decide por el código y, solo para una entrada sin código (tecleada en esta sesión), por la etiqueta;
`presale_entries()` y `has_presale_payment()` lo usan, `ensure_payment()` quita toda entrada
`presale` sea cual sea su etiqueta, y `completion_refusal()` cuenta por código y rechaza más de una.
`is_presale_payment($label)` queda solo para lo que se teclea o se postea (`postAddPayment`).
Prueba: `PresaleDeliveryRegisterTest::testATabSavedInSpanishAndReopenedInEnglishKeepsOnePresalePayment`
(cambia `employees.language_code` de la persona 1 y lo restaura). **No cubierto:** `Sale::save_value()`
y `Sale::update()` siguen calculando `payment_type_code` desde la etiqueta en el idioma de quien
guarda; para `presale` no importa (la entrada se reetiqueta en el idioma actual antes de guardar),
pero una fila «Efectivo» recargada por un empleado en `en` se reescribe con código nulo. Es de
`Sale.php`, fuera de este carril.

**Cuándo un artículo está en uso (hallazgo 2).** `Presale::item_in_use($item_id, ?$today)`: en una
preventa `open`, o en una campaña con `deleted = 0` y `sale_ends >= $today`. Antes bastaba cualquier
fila de `presale_campaign_items` y el artículo quedaba sin poderse borrar para siempre.
`Presale_campaign::delete_campaign()` (solo sin preventas) borra en la misma transacción las filas de
`presale_campaign_items` y `presale_campaign_dates`; el filtro `deleted = 0` cubre las campañas borradas
antes. Pruebas: `PresaleTest` (campaña terminada, borrada, fila vieja de campaña borrada, preventa
abierta tras terminar la campaña) y `DeletePresaleGuardTest` (por Artículos).

**La fila de solo vueltas al editar una venta (hallazgo 3, todos los negocios).** Comportamiento
anterior al carril C, leído en `git show 5503710be^:app/Controllers/Sales.php` y en `Sale::update()`:
el formulario postea la fila (monto 0, `cash_refund` X) con el medio de devolución elegido (por defecto
«Efectivo»). Con devolución en efectivo, la entrada llegaba a `Sale::update()` con monto 0 y **se
borraba** (`payment_amount == 0` → `DELETE`): el esperado del turno subía en X. Con otro medio, la
conversión de siempre («Non-cash positive refund amounts») la volvía `payment_type = <medio>`, monto
`−X`, `cash_refund = 0`, y como el monto no es cero `Sale::update()` la **reescribía**: el cambio se
aplicaba bien. El carril C dejó toda fila de solo vueltas tal cual para evitar el borrado, pero con eso
ignoraba también el medio elegido y respondía éxito. Ahora (`Sales::postSave`), con los montos de la
fila guardada y no los del formulario: si el medio de devolución no es efectivo, se aplica la
conversión de antes (se reescribe, no se borra; el efectivo esperado sube en X porque esas vueltas no
salieron del cajón); si es efectivo y el medio de pago no cambió, queda intacta; si es efectivo y
cambió el medio de pago, se rechaza con `Sales.change_only_payment_type_locked` (`Sale::update()` la
borraría, y retipar una fila sin monto no significa nada). Las guardas de `presale` van antes: una
devolución como `presale` se rechaza también en esta fila. Pruebas en `SalesPresaleGuardTest` contra
`Sale::get_payments_by_cashup()`.

**Fechas pasadas (hallazgo 4).** §4.10, puntos 3 y 4. El formulario de registro ya no ofrece fechas de
entrega anteriores a hoy (`Presales::selling_campaigns()`).

**Rendimiento (hallazgos 5, 6 y 8).** `Presale_campaign::get_items_by_id()` lee los productos de la
campaña una vez; `Presale::price_lines($campaign_id, $requested, ?$campaign_items)` los recibe, y
`postPreview` y `selling_campaigns()` los pasan (antes, N×M consultas). `Presale_register::delivery_facts()`
lee resumen y líneas una vez en `postComplete`, y se pasan a `completion_refusal()`,
`weight_refund_refusal()` y `_save_presale_delivery()`; dentro de la transacción solo
`mark_delivered()` vuelve a leer, con la fila bloqueada. `Presale_campaign::get_with_counts()` trae la
lista de campañas con sus conteos en una consulta (subconsultas con `COUNT`/`GROUP BY`, SQL crudo
como `search_sql()` para que el constructor de consultas no prefije los alias).

**Guardas (hallazgo 7).** Agregadas: `postChangeMode` no cambia la ubicación de inventario en una
entrega (se pregunta después del cambio de mesa, así salir de la pestaña sigue permitido);
`postUnsuspend` y `postCreateTable` se rechazan con `Presale_register.delivery_in_progress`
(`_refuse_while_presale_delivery()`). `presale_for()` se memoriza por carrito (marca de sesión +
`sale_id`) dentro de la petición; `attach()`, `detach()` y `forget()` lo olvidan; una lectura que
falla no se memoriza.

**Regla:** las guardas son una lista de negación repartida en los endpoints de `Sales`. **Todo
endpoint nuevo de la caja** que cambie el carrito, el cliente, los pagos, el modo o la ubicación, o
que reemplace el carrito por otra venta, **tiene que llamar a la guarda**:
`_refuse_on_presale_delivery()` (cambios), `_refuse_while_presale_delivery()` (reemplazos) o
`presale_register->presale_for()`. Lo dice también el comentario al inicio de la sección de preventas
de `Sales.php`. `completion_refusal()` sigue siendo la última línea detrás de todas.

**Reutilización y nombres (hallazgos 9 y 10).** `Presale_payment::net_sql()` es la única escritura del
neto (abonos − devoluciones); `Presales::can_manage(Employee)` y `Presales::read_decimal()` sirven a
los dos controladores. Los métodos y variables de preventas quedaron en `snake_case` (AGENTS.md); las
pruebas conservan sus ayudantes en camelCase.

---

### 7.10 Certificación en staging (2026-10-07, ramas `fix/presales-certificacion` y `fix/presales-kit-weight`)

Informe para el negocio: `docs/Funcional/venta-anticipada-certificacion-staging.md` (22 pruebas, staging en `4e17cb62d`).

Primera vuelta de nuestra certificación en staging, en Panadería La Espiga (`tenant_panaderia`: sin
Mesas, `es_CO`, 0 decimales, 58 mm, impuesto aparte) con el usuario `cert_preventas`. Recorrido: encender
el módulo, abrir turno, crear campaña, registrar con cuota mínima rechazada y aceptada, abono por
transferencia en otro turno, atraso simulado, entrega por la caja con peso mayor, cancelación con
devolución parcial. El dinero, el cuadre, el inventario y el historial cuadraron en todos los pasos.
Encontró cuatro cosas, corregidas aquí con su prueba (`tests/Controllers/PresaleCertificationFixesTest.php`
y un caso nuevo en `tests/Models/PresaleCashupReconciliationTest.php`):

1. **Una campaña nueva nacía inactiva:** el formulario traía la casilla «Activa» desmarcada
   (`campaign_form.php`), y la campaña no aparecía al registrar. Ahora una campaña nueva viene activa.
2. **El buscador de productos de la campaña no ofrecía recetas armadas:** `Item::get_search_suggestions()`
   las excluye a propósito (la caja las busca aparte). `PresaleCampaigns::getSuggest()` suma ahora
   `Item::get_kit_search_suggestions()`. Sin esto no se podía vender una canasta en preventa.
3. **El autollenado del cierre tomaba los abonos por ventana de fechas** y, con dos turnos el mismo día
   (o con el ajuste de solo fecha), le daba a un turno los abonos del otro: el turno 2 proponía $21.107 con
   $11.107 en el cajón. Cada abono trae su turno, así que el autollenado los lee con
   `Presale_payment::get_by_cashup()` (§6.2 queda así). Las ventas siguen como estaban.
4. **No había cómo llegar a Campañas ni a Lo comprometido** desde la lista: se agregaron los dos botones
   (Campañas solo con `presales_manage`).

5. **(segunda vuelta, Casaletto staging, con Mesas)** En la entrega de una receta armada quedaban
   editables también los **componentes por peso** de la receta (8 campos con una tabla de quesos). No
   movía dinero —los componentes van en $0— pero sí lo que sale del inventario. La regla es ahora
   `Presale_register::is_adjustable_weight_line()`: se vende por peso **y tiene precio**; la usan la
   vista de la caja, `Sales::_edit_presale_delivery_line()`, la restauración de pesos y
   `cart_matches()`. Prueba en `tests/Libraries/PresaleRegisterTest.php`.

De paso: `presale_quantity()` (`app/Helpers/presales_helper.php`) muestra un producto por peso con hasta
tres decimales y su unidad en el detalle y el comprobante, sin depender de `quantity_decimals` (con 0, 2,5
kg salía «3»); y `Config.saved_successfully` estaba en inglés en es-MX.

## 8. Trampas conocidas

1. **`clear_suspended_sale_detail` reescribe pagos.** Ninguna parte de la preventa puede pasar por una
   venta suspendida.
2. **El chequeo de cliente de Adeudo vive solo en la vista.** No repetir ese error: todas las
   invariantes de §4.10 y las guardas de §7.3 van en el servidor y tienen prueba.
3. **Editar una venta deja cambiar el tipo de pago.** Un `presale` cambiado a «Efectivo» desde
   `Sales::getEdit` contaría esa plata dos veces. Hay que bloquearlo como la tarjeta de regalo
   (`form.php:79-82`, `Sales.php:2240`), en la vista y en `postSave`.
   **Hecho el 2026-10-07 (carril C)**, con dos hallazgos de `Sale::update()` que obligaron a ir más
   allá de forzar los valores: recalcula `payment_type_code` desde la etiqueta en el idioma de quien
   edita (un empleado en `en` dejaba el pago «Preventa» con código nulo) y **borra toda fila con
   monto cero**, que es justo la fila de vueltas (efectivo 0, `cash_refund` > 0) de una venta pagada
   con tarjeta o con preventa: una edición cualquiera la borraba y el esperado del turno subía en el
   valor de las vueltas. `postSave` ahora **no reenvía** la fila `presale`, ni la de solo vueltas
   mientras no se cambie su medio de devolución (si se cambia, se aplica como antes del carril C:
   §7.9, hallazgo 3). Además rechaza un `payment_id` que no sea de esa venta
   (`Sale::update()` escribe por id sin mirar la venta), y ninguna otra fila ni pago nuevo puede
   volverse `presale`. Pruebas: `tests/Controllers/SalesPresaleGuardTest.php`.
4. **Anular la venta de una entrega** (`Sales::postDelete`) devolvería el inventario dejando la
   preventa entregada. Se bloquea; la devolución de mercancía va por el modo Devolución.
5. **Borrar** un artículo que está en una preventa abierta o en una campaña vigente (no borrada,
   `sale_ends >= hoy`), o un cliente con preventas abiertas: se rechaza. Una campaña terminada o
   borrada ya no retiene sus artículos (2026-10-07, §7.9).
6. **Kits.** Implementado el 2026-10-07 (carril B, `Presale::price_lines()` con `kit_lines()` y
   `kit_components()`). Al registrar, un kit de la campaña se expande como lo hace la caja
   (`Sale_lib::add_item_kit()`, `Sale_lib.php:1829-1865`): primero la línea del kit (`item_type =
   ITEM_KIT`) con el **precio de campaña**, después cada componente con la cantidad multiplicada, en
   **cero**, y el `print_option` que la caja da a una línea en cero. Un kit anidado se expande en su
   lugar; una referencia circular, un kit sin componentes o un componente borrado se rechazan. Un
   kit se vende por unidades enteras. Diferencias deliberadas con la caja: no se aplica
   `kit_discount` (los descuentos de una preventa son los de la campaña), la línea del kit lleva
   siempre el precio de campaña y **los componentes van en 0 sea cual sea `price_option`**. Pruebas:
   `tests/Models/PresaleKitTest.php`.
   **Decidido el 2026-10-07 (dueño, D27/T21):** el precio de campaña de un kit es el del **kit
   completo**. La pregunta que había aquí (con ALL o KIT_STOCK el total era el kit **más** los
   componentes a precio de catálogo) quedó cerrada así: componentes siempre en 0.
   **Para la entrega:** los componentes repiten `item_id` y `add_item()` los fusionaría; la caja se
   arma con `set_cart()` (§7.2).
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
  se calcula en la entrega con el mismo `Tax_lib` de siempre. El total del módulo **es** el de la
  caja porque sale del mismo código (T20, §7.8); probado con impuesto aparte, incluido y cliente
  exento (2026-10-07).
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
JOIN ospos_presales p ON p.presale_id = pi.presale_id AND p.status = 'open'
JOIN ospos_items i ON i.item_id = pi.item_id
WHERE p.campaign_id = ?
GROUP BY p.campaign_id, pi.item_id, i.name, p.delivery_date
```

Se cruza con `item_quantities` de la ubicación para mostrar lo que hay hoy y lo que falta. Las líneas
de kit cuentan por sus componentes, que es lo que hay que comprar: la línea propia del kit
(`item_type = ITEM_KIT`) se excluye (`Presale_report::committed()`, 2026-10-07). Con cientos de preventas la
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
| Total = lo que cobra la caja: impuesto aparte, incluido, cliente exento, líneas por peso en moneda sin decimales; la entrega se completa solo con el pago `presale` | T20 |
| Kit: componentes en 0 con ALL, KIT y KIT_STOCK | T21 |
| Peso menor: bajo el tope cualquiera completa; sobre el tope sin `presales_manage` se rechaza; con el permiso completa y queda quién autorizó; tope 0 = sin tope | T22 |
| Sin turno abierto no se manda a la caja ni se completa | T23 |

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
