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

**Hueco conocido:** `TenantProvisioner` no fija el idioma. Un negocio nuevo nace `en` + `m/d/Y`, y
esta migración (igual que `20260924000000`) ya corrió cuando alguien le pone español a mano. El alta
de un negocio tiene que incluir poner idioma y formato de fecha en Configuración → Local.

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

## 4. Riesgo que queda: fechas imposibles

`date_create_from_format('d/m/Y …', '09/30/2026 …')` **no devuelve false**: desborda el mes 30 a
**2028-06-09** y solo deja una advertencia en `date_get_last_errors()`, que ningún controlador
revisa. Pasaba igual antes con `30/09/2026` en `m/d/Y`. Propuesto: un helper que rechace el valor si
hay advertencias, usado por los siete puntos de lectura del §1. No se hizo en este cambio.

## 5. Visto y no tocado

- Con el tema oscuro, el calendario del daterangepicker pinta varios días blanco sobre blanco. Es del
  tema, no del formato; ya pasaba.
- `partial/daterangepicker.php`, rama `date_or_time_format` (no la usa ningún negocio: el valor es
  `0`): `date($config['dateformat'] . ' ' . $config['dateformat'], …)` repite la fecha donde iba la
  hora. Defecto de upstream.
