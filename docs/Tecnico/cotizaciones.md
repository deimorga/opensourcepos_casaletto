# Cotizaciones — cómo funcionan y qué se corrigió

> **Estado (2026-09-24):** revisión completa del flujo y correcciones en `3e4d3700a`, `bb76587fe` y
> `5458fcf93`. **En producción desde las 09:00 del 2026-09-24** (`926e64501`, junto con CodeIgniter
> 4.7.4): imagen de retorno `casaletto-ospos:rollback-20260924` (= `bcfac895f`). **Es anterior a la
> migración `20260924000000`: volver a ella con las bases ya migradas deja a todos sin poder entrar**
> (`MY_Migration::is_latest()` da falso; ver `AGENTS.md`). Volver atrás exige imagen **y** respaldo de
> base juntos; respaldo en `/root/backups/prod-20260924-pre-cotizaciones/`. Autorizado por el
> dueño en horario de apertura: cero sesiones con usuario (solo el monitor de disponibilidad). Pruebas: `SalesQuoteTest`, `QuoteDefaultsMigrationTest`, `Token_libTest`,
> `SalesKitControllerTest::testTypingAKitsCodeAddsTheKitLikeTheLiveSearch`.
>
> Documento hermano: `docs/Funcional/cotizaciones.md`.

## 1. Cómo se verificó

- **Producción, solo lectura:** cero ventas con `sale_type = 3` en los tres negocios;
  `last_used_quote_number = 0`. Nadie había cotizado nunca.
- **Staging, cotizaciones reales** con `curl` como `cert_cajero`, más un cliente de prueba (id 7).
  Dos lecciones sobre la propia prueba:
  - Con Mesas encendido, `sales/changeMode` **sin `dinner_table`** limpia el carrito y la sesión
    vuelve al modo venta (`Sales::postChangeMode()`, rama de cambio de mesa). El formulario real
    siempre manda la mesa elegida. Los dos primeros intentos quedaron como **ventas completadas**
    990009 y 990010 en staging por eso, no por un defecto del flujo.
  - Sin `Accept-Language`, el número salió `Q٢٦000001` (§3.2).

## 2. El flujo

| Paso | Código |
|---|---|
| El modo «Cotizar» existe mientras `invoice_enable` | `Sale_lib::get_register_mode_options()` |
| Cambiar de modo | `Sales::postChangeMode()` → `set_sale_type(SALE_TYPE_QUOTE)` |
| Botón «Cotizar» (solo con cliente) | `sales/register.php`, `#finish_invoice_quote_button` |
| Guardar | `Sales::postComplete()`, rama `is_quote_mode()`: número con `Token_lib::render(sales_quote_format)`, `sale_status = SUSPENDED`, `sale_type = SALE_TYPE_QUOTE (3)`, vista `sales/quote` |
| Inventario | No se toca: `Sale::save_value()` solo mueve existencias con `COMPLETED` |
| Número | `{QSEQ}` → `Token_quote_sequence` → `Appconfig::acquire_next_quote_sequence()` |
| Correo | `Sales::getSendPdf($id, 'quote')` → `sales/quote_email` → dompdf → `Email_lib` |
| Convertir | Suspendidas → `postUnsuspend()` → `copy_entire_sale()`; se cambia el modo y se completa sobre la misma fila |

## 3. Qué se corrigió y por qué

### 3.1 El documento

- Título `Sales.quote_document` («Cotización»). `Sales.quote` («Cotizar») queda para el botón y el
  modo, que sí son un verbo. El asunto y el nombre del PDF del correo usan también el sustantivo.
- Total `Sales.quote_total` en lugar de `Sales.invoice_total` («Total Facturado»); en el PDF, en lugar
  de `Sales.amount_due`.
- `Sales.quote_number` en es-MX: «Número de cotización» (decía «presupuesto»). es-ES se deja: allá
  «presupuesto» es el término.
- «Comentarios:» ya no se imprime solo cuando no hay comentario (PDF).
- `quote_email.php` escapaba el número en la tabla pero no al pie; ahora en los dos.

### 3.2 Dígitos árabes en el número

`Token_lib::applyDateFormats()` creaba `IntlDateFormatter(null, …)`: el locale del **proceso**, que
CodeIgniter negocia con el `Accept-Language` del navegador (`App::$negotiateLocale = true`) y cuya
lista `App::$supportedLocales` **empieza por `ar-EG`**. Un navegador sin idioma, o con uno que no está
en la lista, generaba `%y` = `٢٦`, y el número se guarda así en `sales.quote_number`. Ahora usa
`Services::language()->getLocale()`, que `Load_config` fija desde `app_config.language_code`.

Afecta a todo formato con `%` — cotizaciones (`Q%y…`) y órdenes de trabajo (`W%y…`). Las facturas de
estos negocios usan `{CO}`, sin fecha.

Queda en staging la cotización 990011 con `Q٢٦000001`, como evidencia.

### 3.3 Pagos en una cotización

`Sale::save_value()` escribe `sales_payments`, descuenta tarjetas de regalo y puntos para cualquier
estado. La rama de cotización ahora devuelve la caja con `Sales.quote_no_payments` si
`sale_lib->get_payments()` no está vacío — **antes** de generar el número, así no se gasta uno.

### 3.4 Volver a ver e imprimir; PDF

- `Sales::getQuote($id)`: la misma vista `sales/quote`, cargada con `_load_sale_data()` (lo mismo que
  usan `getInvoice()`/`getReceipt()`). Con `reprint = true` no ofrece «Descartar»: ese botón actúa
  sobre el id suspendido de la sesión, no sobre la cotización abierta.
- `Sales::getQuotePdf($id)`: `sales/quote_email` → `create_pdf()` → descarga
  `Cotización-<número>.pdf`.
- Los dos rechazan un id que no sea cotización (`sales/quote_not_found`), para no dibujar el recibo de
  otra venta como cotización. `Sale::get_info()` devuelve ahora `sale_type`.
- Suspendidas (`sales/suspended.php`) muestra «Ver cotización» cuando `sale_type = 3`;
  `Sale::get_all_suspended()` devuelve ahora `sale_type`.

### 3.5 Vigencia

Clave nueva `quote_validity_days` (15 por defecto, 0 = no se imprime, 0–365), en Configuración →
Facturación. «Válida hasta» = fecha de la cotización + días: al crearla, `time()`; al reabrirla,
`sales.sale_time`. Se lee siempre con `?? 15`: una caché de configuración anterior a la migración no
tiene la clave.

### 3.6 Textos de ejemplo en inglés

Migración `20260924000000_QuoteValidityAndSpanishDefaults`. Reemplaza `quote_default_comments`,
`invoice_default_comments` (→ vacío) e `invoice_email_message` (→ «Estimado(a) {CU}: adjuntamos el
documento {ISEQ}.») **solo si conservan exactamente el texto de upstream y `language_code` empieza por
`es`**. Lo escrito por un negocio no se toca. `down()` revierte solo lo que sigue intacto.
`invoice_default_comments` importa: Casaletto emitió 5 facturas con «This is a default comment».

### 3.7 El kit tecleado (apareció probando cotizaciones; afecta a toda la caja)

`Item_kit::is_valid_item_kit()` acepta «KIT <id>» **o** el código del kit. Con el código, `postAdd()`
pasaba `'C20013'` a `Item_kit::get_info()` (busca por id) y a `Sale_lib::out_of_stock(int)` →
`TypeError` → 500. Ahora `postAdd()` lo convierte en `KIT <id>` con
`Item_kit::get_item_kit_id_by_number()` antes de seguir. La búsqueda en vivo ya mandaba `KIT <id>`.

## 4. Lo que no se hizo

- **Correo.** Producción tiene `protocol = mail`, sin `smtp_host`, y el contenedor no trae `sendmail`:
  `getSendPdf()` falla. Se necesita una cuenta SMTP; es configuración, no código. El PDF descargable
  cubre la necesidad mientras tanto.
- **Formato de fecha.** Resuelto aparte el mismo día: `d/m/Y` (D27, `docs/Tecnico/formato-de-fecha.md`).
- **Cliente obligatorio en el servidor.** Solo la pantalla lo exige (oculta el botón). Se deja así: la
  única forma de cotizar sin cliente es fabricar la petición.
- Defectos menores vistos y no tocados: `Token_quote_sequence` listado dos veces en
  `Token::get_tokens()`; el número se consume antes del chequeo de duplicado; el filtro `quotes` de
  `Sale::search` no es alcanzable desde Ventas; en `work_order.php` el enlace de descartar usa
  `discard_suspended_sale`, que probablemente no llega a `getDiscardSuspendedSale` con el
  auto-routing mejorado (sin verificar; órdenes de trabajo están apagadas).
