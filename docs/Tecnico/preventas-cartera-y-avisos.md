# Preventas: cartera y avisos por correo — diseño técnico

> **Estado (2026-10-26):** **diseño, sin construir.** Segunda entrega del módulo Preventas, que está en
> producción desde el 2026-10-14 (`e0554cfab`). Decisiones de negocio en
> `docs/Funcional/preventas-cartera-y-avisos.md` §6 (A1-A5) y supuestos en su §7 (S1-S6). Las
> decisiones técnicas están en §2. El diseño de la primera entrega está en `venta-anticipada.md`.

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
- `micronuba.net` tiene MX `mail.micronuba.net` (94.72.167.146, Hivelocity), SPF
  `v=spf1 +mx include:relay.mailbaby.net include:spf.ipzmarketing.com ~all`, DKIM con selector
  `default` y DMARC `p=none`. Si se envía por SMTP autenticado contra `mail.micronuba.net`, SPF y
  DKIM quedan alineados con el `From`.

---

## 2. Decisiones técnicas

| # | Decisión | Por qué | Descartado |
|---|---|---|---|
| **K1** | El SMTP de la plataforma va en **variables de entorno** (`PLATFORM_SMTP_*`), no en `app_config` | Es una sola cuenta para todos los esquemas; el repositorio es público (AGENTS.md: `${VAR}` y `.env` sin versionar) | Copiarla en el `app_config` de cada negocio: N copias de una contraseña, y un negocio nuevo nacería sin ella |
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

---

## 4. Avisos por correo (entregas 2b y 2c)

### 4.1 Correo de la plataforma

Variables nuevas en cada `.env` del VPS (sin versionar), pasadas por `docker-compose.prod.yml` como
`${VAR}`:

```
PLATFORM_SMTP_HOST=mail.micronuba.net
PLATFORM_SMTP_PORT=465
PLATFORM_SMTP_CRYPTO=ssl
PLATFORM_SMTP_USER=ospos@micronuba.net
PLATFORM_SMTP_PASS=…
PLATFORM_MAIL_FROM=ospos@micronuba.net
```

- `Email_lib::platform(): ?Email_lib`, un constructor alterno. Con `PLATFORM_SMTP_HOST` vacío devuelve
  `null` y los avisos quedan en `skipped_no_transport`, sin error. El constructor actual, el que usa
  el negocio, **no cambia** (S6: las cotizaciones siguen como están).
- `sendEmail()` recibe `Reply-To` y adjuntos con CID. `setFrom(PLATFORM_MAIL_FROM, company)`.
- Staging usa **su propia** cuenta o un buzón de pruebas, nunca la de producción: los correos de
  prueba no deben salir con el nombre de un negocio real hacia clientes reales.
- **Antes de la 2b, comprobar desde el contenedor** que el VPS alcanza `mail.micronuba.net:465` y
  `:587`. Hostinger bloquea el 25; los otros dos no deberían estar bloqueados.

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

**Programación:** una línea en el crontab del **host**, porque el contenedor solo corre Apache:

```
* * * * * cd /root/POS_Casaletto && docker compose exec -T ospos php spark presales:notify >> /root/deploy-logs/presales-notify.log 2>&1
```

Igual que `platform:support-employee`, queda **fuera del entrypoint**: si el comando falla, no puede
tumbar la caja. Se versiona en `docker/cron/` y `despliegue.md` documenta cómo instalarlo en cada VPS.
Como el log crece, se rota con `logrotate`.

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

---

## 5. Despliegue

1. **2a Cartera:** código solo; migración ninguna. Sale cuando esté certificada.
2. **2b Avisos:**
   - el dueño crea `ospos@micronuba.net`;
   - se cargan las `PLATFORM_SMTP_*` en el `.env` de producción y de staging, cada una con su cuenta;
   - se comprueba la salida a los puertos 465 y 587 desde el contenedor;
   - se despliega con `deploy.sh`: la migración crea la tabla y las claves en todos los esquemas;
   - se instala la línea de cron;
   - se enciende `presales_notify_email` solo en Casaletto, cuando el dueño lo pida.
3. **2c Recordatorios:** el mismo comando y la misma línea de cron, con la parte de recordatorios.

**Vuelta atrás:** apagar `presales_notify_email` detiene los avisos de ese negocio sin desplegar nada.
Quitar la línea de cron detiene todos los envíos. La regla de AGENTS.md sigue valiendo: una imagen
anterior a la migración solo junto con su respaldo.

---

## 6. Riesgos

| Riesgo | Mitigación |
|---|---|
| `mail.micronuba.net` se apaga con la salida de Neolo (S5) | Confirmar con el dueño antes de la 2b. Alternativa: Resend con dominio verificado, sin cambiar el código (solo las variables SMTP) |
| Los correos llegan a spam | `From` alineado con el dominio que firma (K2), DKIM `default` presente; prueba en Gmail y Outlook (funcional §9.8). Más adelante, subir DMARC de `p=none` a `quarantine` |
| Límite de envíos del servidor de correo | Volumen esperado bajo: cientos de preventas × pocos correos cada una. Lote de 50 por minuto; preguntar el límite por hora de la cuenta |
| El cron se queda sin instalar en un VPS | `despliegue.md` lo trae como paso y la pestaña «Correos» muestra los `pending` viejos. Se puede agregar una alerta si hay `pending` con más de 30 minutos |
| Un error del comando no se ve | Log propio rotado. El comando sale con código distinto de 0 si un negocio falla, sin detener a los demás |
