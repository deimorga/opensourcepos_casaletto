# Preventas: cartera y avisos por correo — diseño técnico

> **Estado (2026-10-28):** **en producción.** Cartera (§3.4), avisos y recordatorios (§4.9) desde el
> 2026-10-27 22:37 (`aca401538`), certificados antes en staging (funcional §9). El envío lo hace el
> worker `presales-notifier` desde el 2026-10-27 23:25 (`e83d35c8c`), con latido, healthcheck y techo de
> recursos desde el 2026-10-28 09:45 (`1ccbd6bbf`). Resend comprobado en producción con
> `platform:mail-test` (entregado en la bandeja de entrada). Casaletto tiene los avisos encendidos.
> Segunda entrega del módulo Preventas, que está en
> producción desde el 2026-10-14 (`e0554cfab`). Decisiones de negocio en
> `docs/Funcional/preventas-cartera-y-avisos.md` §6 (A1-A5) y supuestos en su §7 (S1-S6). Las
> decisiones técnicas están en §2. Envío por Resend (A6) desde el 2026-10-26. El diseño de la primera entrega está en `venta-anticipada.md`.

---

## 1. Lo que ya existe y se reutiliza

| Pieza | Dónde | Uso aquí |
|---|---|---|
| Estado derivado de una preventa | `Presale::derive()` (pura) | Única definición de pagado, saldo, vencido a la fecha, días de atraso y próxima cuota. La cartera **no** reescribe esas reglas |
| Lista con filtro por estado | `Presales::search_sql()` + `installments_for()` | Mismo patrón para la cartera: lo pagado en SQL, las cuotas en una consulta y `derive()` en PHP |
| Reporte con CSV | `PresaleReports::getCommitted*` + `safe_cell()` | Plantilla de la pantalla y del CSV, incluida la protección contra inyección de fórmulas |
| Comprobantes | `presales/receipt_presale`, `receipt_payment`, `receipt_cancel` | Se dibujan a HTML y pasan a PDF con `create_pdf()` (dompdf), como `Sales::getSendPdf()` |
| Envío | `Email_lib` (CI4 `Email`) | Ya lee la configuración del negocio desde `app_config`. Se le agrega el modo plataforma (§4.1) |
| Historial | `presale_events` | Los envíos **no** se guardan aquí: tienen tabla propia (§4.3), porque cambian de estado |
| Recorrer los negocios desde la CLI | `PlatformSupportEmployee::negocios()` + `TenantProvisioner` | Plantilla del comando de envío (§4.5) |

**Estado verificado de producción** (consulta de DNS y `docs/Tecnico/cotizaciones.md` §107):

- `app_config.protocol = mail` sin `smtp_host`, y el contenedor no trae `sendmail`: hoy **ningún** correo sale.
- `micronuba.net` tiene MX propio, `mail.micronuba.net` (94.72.167.146, Hivelocity), SPF raíz
  `v=spf1 +mx include:relay.mailbaby.net include:spf.ipzmarketing.com ~all`, DKIM `default` y DMARC
  `p=none`. **No tiene los registros de Resend** (`resend._domainkey`, `send`). Hoy solo
  `plagie.cloud` está verificado en Resend: PLAGIE envía como `no-reply@plagie.cloud` por
  `smtp.resend.com:587`, desde este mismo VPS, así que la salida al 587 ya está comprobada.
- Agregar `micronuba.net` a Resend no toca el MX ni el SPF raíz. Resend usa `send.micronuba.net` para
  el remitente de sobre (MAIL FROM, rebotes) y `resend._domainkey.micronuba.net` para DKIM. La
  alineación DMARC pasa por DKIM (`d=micronuba.net`).

---

## 2. Decisiones técnicas

| # | Decisión | Por qué | Descartado |
|---|---|---|---|
| **K1** | El SMTP de la plataforma (**Resend**, `smtp.resend.com`, usuario `resend`, clave de API de solo envío) va en **variables de entorno** (`PLATFORM_SMTP_*`), no en `app_config` | Es una sola cuenta para todos los esquemas; el repositorio es público (AGENTS.md: `${VAR}` y `.env` sin versionar) | Copiarla en el `app_config` de cada negocio: N copias de una contraseña, y un negocio nuevo nacería sin ella |
| **K2** | `From: "<company>" <ospos@micronuba.net>` y `Reply-To: <app_config.email>` del negocio | Alineación SPF/DKIM con el dominio que firma; las respuestas van al negocio (A2) | `From` con el correo del negocio: falla DMARC del dominio ajeno y el correo va a spam |
| **K3** | **Bandeja de salida** (`presale_notifications`) y envío **fuera de la petición**, con un comando programado cada minuto | Apache con mod_php no puede responder y seguir trabajando: un SMTP lento (timeout de 10 s) congelaría la caja. Con la bandeja, la caja nunca espera al correo | Enviar en línea después del `commit`: el cajero espera al SMTP; `register_shutdown_function`: con mod_php sigue reteniendo la respuesta |
| **K4** | El PDF se genera **al enviar**, desde los mismos comprobantes de la pantalla | Una sola fuente de verdad para lo impreso y lo enviado; no se guardan archivos | Guardar el PDF al registrar: archivos que limpiar y datos que podrían desactualizarse |
| **K5** | El logo va como **imagen embebida por CID**, no como `data:` en base64 | Gmail no muestra imágenes `data:`. `Email_lib::buildLogoImgTag()` usa base64 y sirve para el PDF, no para el cuerpo del correo | — |
| **K6** | **Idempotencia** por `UNIQUE (presale_id, kind, ref_id)` | Un recordatorio no puede salir dos veces aunque el comando corra dos veces o se solape | Controlarlo con la hora de la última corrida: falla si una corrida se cae a la mitad |
| **K7** | Encolar en la **misma transacción** que el hecho (registro, abono, cancelación, entrega) | Si el hecho se revierte, el aviso desaparece con él; si se confirma, el aviso existe sí o sí | Encolar después del `commit`: una caída entre ambos pasos pierde el aviso sin dejar rastro |
| **K8** | Cartera calculada **en PHP con `derive()`** sobre las preventas vigentes | Son cientos de filas por negocio (D9). Reutilizar `derive()` evita una segunda definición del vencido | Todo en SQL: duplicaría la regla «la cuota de hoy no está vencida» y el cálculo de antigüedad |

---

## 3. Cartera (entrega 2a)

### 3.1 Cálculo

`Presale_report::portfolio(string $today, ?int $campaign_id, ?string $delivery_date): array`

1. **Una consulta:** las preventas `status = open` del filtro, con `paid` agregado de
   `presale_payments` usando `Presale_payment::net_sql()`, más los datos del cliente (nombre,
   teléfono, correo) y de la campaña.
2. **Una consulta:** las cuotas de esas preventas (`installments_for()`, que se extrae del controlador
   a un sitio compartido).
3. Por cada preventa, `Presale::derive()`. Con eso:
   - **vencido** = `max(0, due_to_date − paid)`;
   - **tramo de antigüedad** según `days_late`: 1-7, 8-30 o 31 y más;
   - **próximos 7 días**: las cuotas con `today ≤ due_date < today + 7`, menos lo que ya cubren los
     pagos (la misma acumulación que `derive()` usa para `next_due`). Se agrega a `derive()` una
     variante que devuelve la cobertura cuota por cuota, para no duplicar el algoritmo.
4. **Totales:** vendido (Σ total), recaudado (Σ paid), por cobrar y vencido, todos con `bcadd` y
   redondeados con `totals_decimals()`.

Las preventas **pagadas pero sin entregar** cuentan en vendido y en recaudado, con 0 por cobrar. Las
canceladas y las entregadas no entran, porque su dinero ya está en otro sitio: la devolución o la venta.

### 3.2 Pantalla y permisos

- `PresaleReports::getPortfolio()` y `getPortfolioCsv()`, vista `presales/portfolio.php` con el
  patrón de `committed.php` (cabecera compartida y tema; feedback de homogeneidad).
- Se exige `presales_manage` (S4) con la misma guarda que Campañas. El botón en `presales/manage`
  aparece solo para quien lo tiene.
- El CSV pasa cada celda por `safe_cell()`. El teléfono y el correo son texto que escribió un usuario.

### 3.3 Pruebas

- `derive()` con cobertura por cuota: la cuota de hoy, la de ayer, un pago exacto y un pago parcial
  que reparte entre dos cuotas.
- La cartera cuadra con una suma hecha a mano sobre un conjunto fijo: al día, atrasada en cada tramo,
  pagada sin entregar, cancelada con devolución y entregada.
- Sin `presales_manage` responde 403 o redirige; con el módulo apagado muestra la vista `disabled`.

### 3.4 Cómo quedó construida (2026-10-26)

| Pieza | Dónde |
|---|---|
| Cobertura cuota por cuota | `Presale::coverage($paid, $installments)`, pura. Devuelve cada cuota con `amount`, `cumulative`, `covered` y `missing`, en orden de fecha (empates por `installment_id`). **`derive()` ahora recorre `coverage()`**: la acumulación está escrita una sola vez, y las pruebas de `derive()` de siempre (`PresaleRulesTest`) siguen pasando sin cambios |
| Cuotas de varias preventas | `Presale::installments_for($ids)`, en el modelo. `Presales::installments_for()` quedó como una línea que delega en él |
| Cálculo | `Presale_report::portfolio($today, ?$campaign_id, ?$delivery_date)` y `open_delivery_dates()`. Dos consultas, sea cual sea el número de preventas |
| Pantalla y CSV | `PresaleReports::getPortfolio()` y `getPortfolioCsv()`, vista `presales/portfolio.php`, rutas `presales/portfolio` y `presales/portfolio/csv` |
| Botón | «Cartera» en `presales/manage`, junto a «Campañas», solo con `presales_manage` |
| Textos | `app/Language/{es-MX,es-ES,en}/Presale_reports.php`, el archivo de «Lo comprometido», no `Presales.php` |
| Pruebas | `PresaleCoverageTest` (pura), `PresalePortfolioTest` (el conjunto fijo sumado a mano, en el encabezado del archivo) y `PresalePortfolioControllerTest` |

**Decisiones tomadas al construir, que el diseño no fijaba:**

- **Tramo de antigüedad:** todo el vencido de una preventa va al tramo de sus `days_late`, que se cuentan
  desde la cuota más vieja sin cubrir. Una preventa con dos cuotas vencidas, de 40 y de 10 días, suma su
  vencido entero en «más de 30». Así cada preventa cuenta una sola vez en el número del tramo.
- **Ventana de los próximos 7 días:** hoy y los seis días siguientes (`today ≤ due_date < today + 7`). Una
  cuota que vence hoy está en la ventana y no en el vencido. Cuenta solo lo que falta de cada cuota según
  `coverage()`: un abono parcial se aplica primero a la cuota vencida, como en `derive()`.
- **«Todas las campañas»** es toda preventa `open`, sin mirar la campaña: una campaña con preventas no se
  puede borrar (`Presale_campaign::delete_campaign()`), así que no hay preventas vigentes huérfanas. Una
  campaña o una fecha que no está en las listas del filtro se lee como «todas», nunca como un valor
  libre: las listas son la lista blanca, como en «Lo comprometido».
- **El CSV trae una fila por preventa vigente del filtro**, no solo los atrasados: número, cliente,
  teléfono, correo, campaña, fecha de entrega, total, pagado, saldo, vencido, días de atraso y lo que
  vence en 7 días, del más atrasado al menos. Con eso, las cuatro cifras y los tramos salen sumando
  columnas en la hoja de cálculo. Los montos van con los decimales y el separador decimal del negocio,
  sin separador de miles ni símbolo, por la misma razón que las cantidades de «Lo comprometido». Cada
  celda pasa por `safe_cell()`: un teléfono `+57 …` sale como `'+57 …`.
- **Sin `presales_manage`, la pantalla y el CSV redirigen** a `no_access/presales/presales_manage`, como
  la página de Campañas. No hay 403 porque ninguno de los dos se llama por script.
- **Por cobrar** es vendido menos recaudado, como dice el funcional. Los montos se suman a escala 2 con
  `bcadd` y se redondean con `Presale_campaign::round_money()`, que usa `totals_decimals()`.
- Cada número de preventa abre el detalle en el mismo diálogo de la lista (`modal-dlg`), que ya trae el
  formulario para abonar.

---

## 4. Avisos por correo (entregas 2b y 2c)

### 4.1 Correo de la plataforma

**Construido el 2026-10-26** (`Email_lib::platform()`, `php spark platform:mail-test`), antes que el
resto de la 2b, para dejar la configuración de los dos ambientes probada de punta a punta.

| Variable | Producción (Resend) | Staging (Mailpit) |
|---|---|---|
| `PLATFORM_SMTP_HOST` | `smtp.resend.com` | `mailpit-casaletto-staging` |
| `PLATFORM_SMTP_PORT` | `587` | `1025` |
| `PLATFORM_SMTP_CRYPTO` | `tls` (STARTTLS) | vacío |
| `PLATFORM_SMTP_USER` | `resend` | vacío: CodeIgniter no hace AUTH |
| `PLATFORM_SMTP_PASS` | `${PLATFORM_SMTP_PASS}` del `.env`: clave de API `ospos-produccion`, «Sending access», limitada a `micronuba.net` | vacío |
| `PLATFORM_MAIL_FROM` | `ospos@micronuba.net` | `ospos@micronuba.net` |

Todo menos la clave está escrito en el compose de cada ambiente: no es secreto y así se ve qué usa
cada uno. La clave se carga con `scripts/set-env-secret.sh` (despliegue.md §6). En producción, si la
clave falta, se toma vacía: el contenedor arranca igual y el correo falla con un rechazo de acceso.
La caja no depende del correo.

- **Staging nunca escribe a clientes reales**, y no por una regla del código. Su servidor de correo es
  **Mailpit** (`axllent/mailpit`, servicio del compose de staging): recibe por SMTP en el puerto 1025 de
  `app_net`, guarda cada mensaje y no entrega ninguno, porque no tiene relevo configurado
  (`MP_SMTP_RELAY_*`). Así se descartó la redirección por variable que proponía la primera versión de
  este documento. Los correos se leen en `https://mailpit.staging.ospos-saas.micronuba.net`, con
  usuario y contraseña (`MAILPIT_UI_AUTH` en el `.env` de staging), porque llevan datos de clientes
  reales. Sin esa variable, `docker compose` falla al interpolar y el despliegue se detiene antes de
  tocar nada, en vez de publicar la bandeja abierta. El router y el servicio de Traefik se llaman
  `pos-casaletto-mailpit-staging`: la regla del gateway del VPS exige el prefijo del proyecto (desde el
  2026-10-28; antes `mailpit-staging`). El router tiene prioridad 30 sobre el comodín de
  los negocios de staging (10), y `mailpit` es un slug reservado.
- **El nombre de red de Mailpit es `mailpit-casaletto-staging`, y no `mailpit`.** El contenedor
  también está en `micronuba_public`, la red que comparten todos los proyectos del VPS, porque
  Traefik lo publica desde ahí, y Docker anuncia el nombre del servicio en cada red. El 2026-10-26,
  con el servicio llamado `mailpit`, ese nombre resolvía también al Mailpit de inventory-saas, y el
  primer correo de prueba terminó en esa bandeja: `platform:mail-test` dijo «Enviado» y la nuestra
  estaba vacía. Por eso, en esa prueba hay que mirar la bandeja, no solo el «Enviado».
- `Email_lib::platform(): ?Email_lib`, un constructor alterno que lee esas variables. Sin host o sin un
  remitente válido devuelve `null`: los avisos quedarán en `skipped_no_transport`, sin error. El
  constructor de siempre, el del negocio, **no cambia** (S6: las cotizaciones siguen como están).
- `send_as($nombre_del_negocio, $para, $asunto, $html, $responder_a, $adjuntos)`. `From` es
  `PLATFORM_MAIL_FROM` con el nombre del negocio, y `Reply-To` es el correo del negocio cuando es
  válido; si no lo es, se omite. El nombre y el asunto pasan por `header_text()` (una línea, sin
  caracteres de control).
- **Un servidor de correo caído devuelve `false`, nunca una excepción.** CodeIgniter abre el socket
  con `fsockopen()` sin `@`. En producción, la advertencia de una conexión rechazada se vuelve
  `ErrorException`, y en las pruebas haría fallar la corrida (`failOnWarning`). Mientras envía,
  `send_as` instala un manejador que anota la advertencia y la absorbe. `last_error()` devuelve
  ese motivo más las respuestas del servidor, que nunca incluyen la clave.
- Una clave de API por ambiente que la necesite. Hoy es solo `ospos-produccion`, porque staging no usa
  Resend. Si se filtra, se revoca sin tocar a PLAGIE.
- Seguimiento de clics y aperturas de Resend **apagado** para `micronuba.net`. Reescribir enlaces y
  agregar un píxel perjudica la entrega de un correo transaccional y no aporta nada.

### 4.2 Configuración por negocio

Migración `…_AddPresalesNotifyConfig` (semilla en todos los esquemas, idempotente):

| Clave | Por defecto | Uso |
|---|---|---|
| `presales_notify_email` | `0` | Interruptor de §4.5 del funcional |
| `presales_reminder_days_before` | `2` | S1. `0` = sin recordatorio previo |
| `presales_reminder_late` | `1` | S1. `0` = sin recordatorio de vencida |

Van en la pestaña Preventas de Configuración (`Config::postSavePresales`), con textos en es-MX, es-ES
y en.

### 4.3 Bandeja de salida

Tabla `presale_notifications`, en el esquema de cada negocio, misma migración:

| Columna | Tipo | Nota |
|---|---|---|
| `notification_id` | INT PK | |
| `presale_id` | INT | |
| `kind` | VARCHAR(24) | `created`, `payment`, `canceled`, `delivered`, `reminder_before`, `reminder_late` |
| `ref_id` | INT | `payment_id`, `sale_id` o `installment_id`; `0` en `created` y `canceled` |
| `to_email` | VARCHAR(255) NULL | Se copia al encolar; al reenviar se vuelve a leer de la ficha del cliente |
| `status` | VARCHAR(24) | `pending`, `sending`, `sent`, `failed`, `skipped_no_email`, `skipped_no_transport` |
| `attempts` | TINYINT | |
| `last_error` | VARCHAR(500) NULL | Sin credenciales: se recorta el `printDebugger()` |
| `next_attempt_at` | DATETIME | Espera creciente: 1, 5, 15 y 60 minutos; tras 5 intentos queda `failed` |
| `created_at`, `sent_at` | DATETIME | |
| `employee_id` | INT NULL | Quien causó el aviso o pidió el reenvío; NULL en los recordatorios |

`UNIQUE (presale_id, kind, ref_id)` (K6). «Reenviar» no crea una fila: vuelve a poner la misma en
`pending`, con `attempts = 0` y el correo actual del cliente.

### 4.4 Dónde se encola (K7)

| Hecho | Lugar | `ref_id` |
|---|---|---|
| Registro | `Presale::create()` | 0 |
| Abono | `Presale::add_payment()` | `payment_id` |
| Cancelación | `Presale::cancel()` | 0 |
| Entrega | `Presale::mark_delivered()`, dentro de la transacción envolvente de `postComplete` (técnico de venta-anticipada §7.4) | `sale_id` |

Con `presales_notify_email = 0` no se encola nada. Si el cliente no tiene un correo válido
(`filter_var(..., FILTER_VALIDATE_EMAIL)`; **nunca** `FILTER_SANITIZE_*`, ver memoria de tildes), se
encola con `skipped_no_email` y la respuesta JSON del controlador lleva el aviso para la pantalla (A4).

### 4.5 Envío: comando programado

`php spark presales:notify [--tenant=<slug>]`

1. Recorre los negocios activos, igual que `platform:support-employee`, y carga la configuración de
   cada uno (`update_settings()`), porque el nombre, el logo y el `Reply-To` son del negocio.
2. **Recordatorios (2c):** una vez por día y negocio, desde las 9:00 hora del negocio
   (`app_config.timezone`, no la del servidor). Inserta con `INSERT IGNORE` los `reminder_before` de
   las cuotas que vencen en N días y los `reminder_late` de las vencidas ayer sin cubrir. Solo para
   preventas `open` con saldo, según `derive()`.
3. **Envío:** toma hasta 50 filas `pending` con `next_attempt_at ≤ now`, las marca `sending` con un
   `UPDATE … WHERE status = 'pending'` (así dos corridas solapadas no envían dos veces), arma el
   correo y el PDF y envía. El resultado queda en `sent` o en `pending` con espera, o `failed` tras el
   último intento.
4. Una fila en `sending` con más de 10 minutos se considera caída y vuelve a `pending`.

**Programación:** el servicio **`presales-notifier`** de cada docker-compose lo corre cada minuto
(`docker/presales-notifier.sh`), con la misma imagen y configuración que la aplicación, sin Apache ni
migraciones. **No es una línea de crontab del servidor** (decisión del dueño, 2026-10-27): todo lo que
corre vive en los contenedores, para poder migrar de nube con el repositorio y el `.env`. `deploy.sh`
lo recrea junto con la aplicación. Detalles de operación en `despliegue.md` §7.

### 4.6 Contenido

- Vistas `presales/email_{created,payment,canceled,delivered,reminder}.php`, en HTML con estilos en
  línea y una versión de texto plano. Todo pasa por `esc()`: el nombre del cliente, la descripción de
  la línea, el motivo de cancelación.
- **Asunto con el nombre del negocio:** «Casaletto — Preventa PV-000123 registrada»,
  «… abono recibido», «… cancelada», «… entregada», «… cuota por vencer el 15/11/2026».
- **PDF:** los comprobantes `receipt_*` dibujados a HTML y pasados por `create_pdf()`. Para la
  entrega se usa el recibo de la venta (`sales/receipt`). Los recordatorios van sin adjunto (S2).
- **Montos y fechas** con los ajustes del negocio (`to_currency`, `dateformat` d/m/Y), no del servidor.
- **Textos** en archivos de idioma es-MX, es-ES y en (la app corre en es-MX).
- **Asunto:** se quitan los saltos de línea antes de `setSubject` (inyección de cabeceras), aunque CI4
  ya lo hace.

### 4.7 Pantalla de la preventa

`presales/detail` agrega la pestaña **«Correos»**, con tipo, dirección, estado, fecha y error si lo
hubo, y el botón **«Reenviar»** (`Presales::postResendNotification($id)`, POST con CSRF y permiso
`presales`). El aviso de «cliente sin correo» aparece al registrar, abonar y entregar.

### 4.8 Pruebas

- Encolar: cada hecho crea su fila en la misma transacción; un hecho revertido no deja fila; con el
  interruptor apagado no hay fila; un cliente sin correo queda en `skipped_no_email`.
- Comando: con un transporte falso (subclase de `Email` que registra en memoria), los estados
  `pending → sending → sent`; un fallo reintenta con espera; dos corridas solapadas envían una sola vez.
- Recordatorios: la cuota a N días produce uno, la de ayer sin cubrir produce uno, la de ayer cubierta
  ninguno, y correr dos veces el mismo día no duplica (K6). La zona horaria usada es la del negocio.
- Contenido: `From`, `Reply-To`, el asunto con el negocio, `esc()` sobre un nombre con `<script>` y
  tildes intactas («José Muñoz»), y el PDF adjunto no vacío.
- Seguridad: «Reenviar» sin CSRF o sin permiso se rechaza, y no se puede reenviar la preventa de otro
  negocio (el esquema es por negocio, pero se prueba).
- Base de pruebas compartida: cada prueba restaura las claves que toca (`TOUCHED_KEYS`).

### 4.9 Lo construido (2026-10-26) y en qué cambió respecto al diseño

**Avisos (2b) y recordatorios (2c) construidos el 2026-10-26** en la rama `feat/presales_avisos_correo`:
migración `20261026000000_AddPresaleNotifications`, `Presale_notification` (bandeja),
`Presale_notifier` (contenido y envío), `Presale_documents` (datos de los comprobantes, compartidos
con la pantalla), `php spark presales:notify`, vistas `presales/email_*.php` y
`presales/receipt_delivered.php`, worker `presales-notifier` (`docker/presales-notifier.sh`). Pruebas en
`tests/Controllers/PresaleNotificationsTest.php`.

Diferencias con el diseño de arriba, y por qué:

- **El PDF de la entrega es un comprobante de entrega propio** (`receipt_delivered`), no el recibo de
  la venta (`sales/receipt`). Ese recibo se arma desde el carrito en la sesión de la caja, que el
  comando no tiene. El comprobante propio lista lo entregado y la diferencia por peso, cobrada o
  devuelta. La diferencia es el total de la venta (lo que guardaron sus pagos, `payment_amount −
  cash_refund`) menos lo pagado en la preventa. No se recalculan impuestos ni redondeos.
- **Un proceso por negocio.** Sin argumento, `presales:notify` lee el registro y se lanza a sí mismo
  por cada negocio con `MYSQL_DB_NAME`, como `migrate-tenants.sh`. Cambiar la conexión dentro de un
  comando ya arrancado no es fiable (`TenantMigrateOne.php`). Cada negocio tiene además su
  configuración en caché, su zona horaria y su idioma. El hijo fija `TenantContext` antes de leer la
  configuración: sin eso leería la clave de caché `settings`, que es la de Casaletto.
- **Un estado más, `skipped_paid`:** un recordatorio cuya cuota se pagó, o cuya preventa dejó de estar
  abierta, entre que se encoló y que salió. No se envía.
- **Con la casilla apagada, el comando no envía nada**, ni siquiera lo que ya estaba en cola, y
  «Reenviar» se rechaza. Lo encolado espera hasta que se vuelva a encender.
- **El aviso de «cliente sin correo»** aparece en la página del comprobante que sigue al registro, al
  abono o a la cancelación (`?sin_correo=1`, solo en pantalla, `print_hide`). Las tres acciones navegan
  a esa página, así que un `$.notify()` se perdía. **En la entrega no hay aviso en la caja:** la caja
  imprime su propio recibo y no se tocó para esto. La pestaña «Correos» lo muestra igual.
- **Los recordatorios se encolan en cada corrida desde las 9:00**, no una vez al día: la unicidad (K6)
  vuelve inofensivo repetirlo. Si el worker estuvo caído todo el día, el recordatorio de «vencida ayer»
  de ese día no sale: la cuota sigue en la cartera y en la lista de atrasados.
- **Logo por CID:** `send_as()` recibe la ruta del logo, lo adjunta `inline` y cambia `cid:logo` en el
  cuerpo por el CID real.

---

## 5. Despliegue

1. **2a Cartera:** código solo; migración ninguna, permiso nuevo ninguno (usa `presales_manage`, que ya
   existe, así que no hace falta `platform:support-employee`). Construida el 2026-10-26; sale cuando
   esté certificada en staging (funcional §9.1).
2. **2b Avisos:**
   - `micronuba.net` verificado en Resend (registros `send` MX/TXT y `resend._domainkey` en Cloudflare);
   - ✅ 2026-10-26: correo de la plataforma construido; Mailpit en staging; Resend en el compose de
     producción;
   - la clave `PLATFORM_SMTP_PASS` en `/root/POS_Casaletto/.env` y `MAILPIT_UI_AUTH` en
     `/root/POS_Casaletto_staging/.env`, con `scripts/set-env-secret.sh`;
   - `php spark platform:mail-test` en cada ambiente: en staging el correo aparece en Mailpit; en
     producción llega a la bandeja y aparece en Resend → Emails;
   - se despliega con `deploy.sh`: la migración crea la tabla y las claves en todos los esquemas;
   - el worker `presales-notifier` sale con el despliegue (antes era una línea de cron, retirada el 2026-10-27);
   - se enciende `presales_notify_email` solo en Casaletto, cuando el dueño lo pida.
3. **2c Recordatorios:** el mismo comando y el mismo worker, con la parte de recordatorios.

**Vuelta atrás:** apagar `presales_notify_email` detiene los avisos de ese negocio sin desplegar nada.
Detener el worker (`docker compose stop presales-notifier`) detiene todos los envíos. La regla de AGENTS.md sigue valiendo: una imagen
anterior a la migración solo junto con su respaldo.

---

## 6. Riesgos

| Riesgo | Mitigación |
|---|---|
| Resend se cae o suspende la cuenta | Los avisos esperan en la bandeja y se reintentan; la caja no se entera. El transporte es SMTP estándar: cambiar de proveedor es cambiar las variables |
| Los correos llegan a spam | `From` alineado con el dominio que firma (K2), DKIM `default` presente; prueba en Gmail y Outlook (funcional §9.8). Más adelante, subir DMARC de `p=none` a `quarantine` |
| Límite de envíos | El plan Pro de Resend (50.000 al mes) se comparte con PLAGIE. Preventas suma cientos al mes. Lote de 50 por minuto, por debajo del límite de peticiones por segundo |
| El worker se detiene | `restart: always` lo levanta, `deploy.sh` lo recrea en cada despliegue y la pestaña «Correos» muestra los `pending` viejos. Se puede agregar una alerta si hay `pending` con más de 30 minutos |
| Un error del comando no se ve | Log propio rotado. El comando sale con código distinto de 0 si un negocio falla, sin detener a los demás |
