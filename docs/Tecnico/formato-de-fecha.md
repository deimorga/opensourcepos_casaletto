# Formato de fecha d/m/Y — por qué es seguro cambiarlo y qué se verificó

> **Estado (2026-09-24):** migración `20260924010000_DayMonthYearDateFormat` (`41fd37b70`, corregida
> en `d9d1322ef`). Pruebas: `DayMonthYearMigrationTest`, `DateFormatDayFirstTest`. Verificado en
> staging (§3). **En producción** con `1c2d346c9` (desplegado por otra sesión junto con dompdf 3.1.6;
> imagen de retorno `casaletto-ospos:rollback-20260924-predompdf`). Revisión de solo lectura a las
> 12:53: `d/m/Y` en los tres negocios; cero ventas, gastos o turnos con fecha futura; un turno y un
> gasto de Casaletto registrados ese día con la fecha correcta; sin errores en el registro.
>
> Documento hermano: `docs/Funcional/formato-de-fecha.md`.

## 1. Un solo ajuste, en los dos sentidos

`app_config.dateformat` (+ `timeformat`) se usa para **mostrar** y para **leer**:

- Mostrar: `to_date()`/`to_datetime()` (`locale_helper.php`), tablas (`tabular_helper.php`),
  recibos, facturas, cotizaciones, reloj (`header_js.php`).
- Leer lo que llega de un formulario: `date_create_from_format($config['dateformat'] . ' ' .
  $config['timeformat'], …)` en `Cashups` (apertura, cierre, `collected_at`), `Expenses`, `Sales`
  (editar fecha), `Receivings`, `Customers`, `Writeoffs`; `Attribute` para atributos de tipo fecha.
- Calendarios: `dateformat_momentjs()` (daterangepicker, reloj) y `dateformat_bootstrap()`
  (bootstrap-datetimepicker del formulario de turnos y recepciones).

Como el formulario se llena con el mismo formato con el que después se lee, cambiarlo es coherente.
Lo que no depende del ajuste:

- **Datos:** `DATETIME` en la base. Nada se reescribe.
- **Orden de las tablas:** `sidePagination: 'server'` (`manage_tables.js`): ordena el SQL sobre la
  columna real, no el texto formateado.
- **Rangos de fechas:** el daterangepicker manda `start_date`/`end_date` como `YYYY-MM-DD`
  (`partial/daterangepicker.php`); la persistencia de filtros (`table_filter_persistence.php`) guarda
  eso mismo en la URL.
- No hay formatos escritos a mano (`m/d/Y`, `MM/DD`) en controladores, vistas, librerías ni JS propio.

## 2. La migración

`m/d/Y → d/m/Y` y `m/d/y → d/m/y`, **solo** si el valor sigue siendo el de upstream y
`language_code` empieza por `es`. Otro formato se respeta; un negocio en inglés conserva el orden de
EE. UU. `down()` revierte solo lo que esta migración pudo escribir.

**Negocios nuevos:** en un alta las migraciones corren antes que `TenantConfigProfile`, con el esquema
todavía en `en`, así que esta migración no los toca. Por eso el perfil fija `dateformat = d/m/Y`
(`2d71adff3`) y una prueba exige que perfil y migración usen el mismo valor. (Una versión anterior
de este párrafo decía que el alta no fijaba el idioma: sí lo fija, en el perfil.)

## 3. Qué se verificó en staging (2026-09-24, Casaletto, `cert_cajero`, `Accept-Language: es-CO`)

La migración cambió `ospos` y `tenant_panaderia` (es-MX) y dejó `tenant_pruebas` y
`tenant_restauranteprueba` (en) en `m/d/Y`.

| Qué | Resultado |
|---|---|
| Formularios de gasto, turno y edición de venta | Precargan `24/09/2026 09:06:32` |
| Guardar gasto con `30/09/2026 10:15:00` | `ospos_expenses.date = 2026-09-30 10:15:00` |
| Abrir turno con `30/09/2026 08:00:00` | `ospos_cash_up.open_date = 2026-09-30 08:00:00` |
| Cambiar fecha de venta a `30/09/2026 12:00:00` | `sale_time = 2026-09-30 12:00:00` (restaurada después) |
| Listas de ventas, gastos, turnos | `23/09/2026 22:28:27`, `30/09/2026 10:15:00` |
| Calendarios (ventas, gastos, turnos, artículos, reportes) | `DD/MM/YYYY`; turnos `dd/mm/yyyy hh:ii:ss` |
| Reloj | `DD/MM/YYYY HH:mm:ss` |
| Rango «Mes actual» con clics reales (Chrome sin ventana) | pide `2026-09-01 … 2026-09-24`, 12 ventas |
| Rango tecleado `05/09/2026 - 20/09/2026` | pide `2026-09-05 … 2026-09-20` |
| Vigencia de cotización | `09/10/2026` (9 de octubre) |

Se usó el 30/09 a propósito: no existe en orden mes/día, así que un cambio de orden no podía pasar
inadvertido. El gasto 14 y el turno 6 de prueba se borraron de staging.

## 4. Fechas escritas a mano: `parse_typed_datetime()` (`a5e4607cb`)

`date_create_from_format()` **no falla** con una fecha imposible: la desborda (`09/30/2026` en
`d/m/Y` → 2028-06-09; `30/02/2026` → 2026-03-02) y solo deja una advertencia en
`date_get_last_errors()`. Ningún controlador la revisaba. Además Recepciones, editar venta y Clientes
llamaban `->format()` sobre el resultado sin comprobarlo: una fecha ilegible era un 500.

`parse_typed_datetime(?string $value, bool $with_time = true): DateTime|false` (`locale_helper.php`):

1. formato del negocio, aceptado solo sin advertencias ni errores;
2. si no, el mismo formato con día y mes invertidos (`swap_day_and_month()`): una fecha que solo es
   real al revés solo puede significar eso;
3. si no, `false`, y el formulario responde `typed_date_error()` → `Common.date_invalid` con lo
   escrito y un ejemplo de hoy.

Lo usan `Cashups` (apertura, cierre, `collected_at`), `Expenses`, `Receivings`, `Sales::postSave`,
`Customers` y `Attribute` (solo fecha). Una fecha válida en los dos órdenes se lee en el del negocio.

**En pantalla** (`partial/datepicker_locale.php`, que cargan todos esos formularios): bajo cada campo
`.datetime, #datetime, #open_date, #close_date, #collection_collected_at`, la fecha en palabras con
`Intl.DateTimeFormat` del navegador («= miércoles, 30 de septiembre de 2026»); misma regla de
inversión con moment.js estricto, reescribiendo el campo y avisándolo; `has-error` si no existe.
Delegado con espacio de nombres `.datereadback`: los formularios en modal cargan el parcial cada vez.

Verificado en staging (Chrome sin ventana, formulario de gasto real, y POST directo sin la pantalla):
`09/30/2026` → campo `30/09/2026` + aviso, guardado `2026-09-30`; `05/09/2026` → «sábado, 5 de
septiembre»; `31/31/2026` y `30/02/2026` → rechazados, nada guardado; turno y edición de venta con
fecha imposible → rechazados con mensaje (la venta antes daba 500). Pruebas: `DateFormatDayFirstTest`
(9) y `ExpensesCashSourceTest` (fecha invertida e imposible por el formulario real).

## 5. Visto y no tocado

- Con el tema oscuro, el calendario del daterangepicker pinta varios días blanco sobre blanco. Es del
  tema, no del formato; ya pasaba.
- `partial/daterangepicker.php`, rama `date_or_time_format` (no la usa ningún negocio: el valor es
  `0`): `date($config['dateformat'] . ' ' . $config['dateformat'], …)` repite la fecha donde iba la
  hora. Defecto de upstream.
