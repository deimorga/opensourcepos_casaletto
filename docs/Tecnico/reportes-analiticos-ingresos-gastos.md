# Diseño técnico — Reportes Analíticos: Ingresos vs Gastos

Alcance funcional en `docs/Funcional/reportes-analiticos-ingresos-gastos.md`.

---

## 1. Principio rector

**El reporte no puede producir una cifra distinta a la que ya muestran los reportes existentes.**
Si el Reporte Resumido de Transacciones dice que agosto vendió 24.800.000, este reporte tiene que
decir exactamente eso. Cualquier reimplementación de la fórmula de venta es una oportunidad de
divergir; por eso el diseño **reutiliza el modelo existente** en vez de reescribir su SQL (ver 4.1).

## 2. Dónde vive

### 2.1 Categoría nueva en la pantalla de Reportes

`app/Views/reports/listing.php` arma sus tres paneles **iterando los permisos del empleado**, no una
lista fija. El cuarto panel, "Reportes Analíticos", sigue el patrón del panel de Inventario, que ya
es un caso especial con enlaces explícitos:

```php
<?php if (in_array('reports_analytics', $permission_ids, true)) { ?>
    <!-- panel "Reportes Analíticos" con sus enlaces -->
<?php } ?>
```

Ojo: los paneles Gráficos y Resumidos iteran **todos** los permisos que empiezan por `reports_`
(filtrando `inventory` y `receiving` vía `can_show_report()`). Un permiso nuevo llamado
`reports_analytics` **aparecería automáticamente y por error** en esos dos paneles, apuntando a
`reports/graphical_analytics` y `reports/summary_analytics`, que no existen. Hay que añadirlo a la
lista de exclusión de `can_show_report()` — igual que ya se hace con `inventory` y `receiving`.

### 2.2 Permiso

Migración nueva que inserta en `ospos_permissions` (`permission_id = 'reports_analytics'`,
`module_id = 'reports'`) y otorga el grant al empleado 1, siguiendo el patrón de
`3.1.1_to_3.2.0.sql` para `reports_expenses_categories`.

**Recordatorio operativo:** los workflows de despliegue **no corren migraciones**. Después de
desplegar hay que lanzar a mano `php spark migrate` por SSH (ver la política de ramas y despliegue).

### 2.3 Rutas

```php
$routes->add('reports/analytical_income_expenses',        'Reports::analytical_income_expenses');
$routes->add('reports/analytical_income_expenses/search', 'Reports::getIncome_expenses_search');
```

Se declaran **antes** de los patrones genéricos. No colisionan con `summary_(:any)`,
`graphical_(:any)`, `detailed_(:any)`, `specific_(:any)` ni `inventory_(:any)`, pero el orden
explícito evita sorpresas si mañana se agrega otro comodín.

## 3. Por qué el reporte necesita un endpoint JSON

Los 20 reportes actuales renderizan los datos **dentro del HTML** (`reports/tabular.php` recibe
`$data` ya formateado y lo inyecta con `json_encode` en la inicialización de bootstrap-table). Eso
funciona porque los filtros se eligen *antes*, en un formulario que recarga la página.

Con el control de la grilla los filtros cambian *después* de cargada la página, así que la tabla
tiene que poder re-consultar. `table_support` construye la URL como `options.resource + '/search'`
(`public/js/manage_tables.js:217`), de modo que con `resource: 'reports/analytical_income_expenses'`
pega contra `reports/analytical_income_expenses/search` y espera `{total, rows}`.

**Consecuencia:** este reporte no reutiliza `reports/tabular.php`; necesita una vista propia. A
cambio hereda de las grillas el refresco sin recarga, la persistencia de filtros en la URL y la
exportación, que bootstrap-table ya trae.

## 4. Modelo de datos

Modelo nuevo: `app/Models/Reports/Income_expenses.php`.

**Extiende `Report` directamente, no `Summary_report`.** `Summary_report` está cableado a
`sales_items` en `getData()`/`getSummaryData()`; `Summary_expenses_categories` lo extiende y luego
**sobreescribe ambos métodos completos** para no usar nada de la clase padre — un patrón que no vale
la pena copiar. `Report` es exactamente la clase abstracta pensada para esto: declara los tres
métodos y nada más.

### 4.1 Lado ingresos — reutilizar, no reescribir

El total de una venta no es `SUM(precio)`: es la expresión de
`Summary_report::__common_select()`, que contempla descuentos por porcentaje o por valor, dos
tablas temporales (impuestos por línea y pagos por venta), el `cash_adjustment`, y la variante según
`tax_included`. Reimplementarla es garantizar que algún día las dos pantallas no cuadren.

**El modelo llama a `Summary_sales::getData()`** con el rango de fechas, `sale_type = 'complete'` y
`location_id = 'all'`, y **agrupa en PHP** las filas diarias que devuelve en el período pedido
(día / semana / mes). El volumen está acotado: una fila por día operativo — el preset más amplio
("Todo el tiempo", desde 2010) da unos pocos miles de filas.

Beneficio directo: el reporte **no puede** divergir del Reporte Resumido de Transacciones, porque
lee de la misma fuente.

### 4.2 Lado gastos

Consulta propia sobre `expenses`, en la misma línea que `Summary_expenses_categories::getData()`:

- Agrupada por la misma expresión de período que el lado de ingresos.
- `WHERE expenses.deleted = 0` salvo que el filtro "incluir eliminados" esté activo.
- Respeta la bifurcación por `date_or_time_format` que usan todos los modelos de reporte
  (`DATE(expenses.date) BETWEEN ...` contra `expenses.date BETWEEN ...`).

### 4.3 Unión y cálculo

Se unen ambos lados por clave de período. **Un período con ventas y sin gastos, o al revés, tiene
que aparecer igual** con cero del lado que falte — un `INNER JOIN` escondería justo los meses que
más interesan.

- `resultado = ingresos − gastos`
- `margen % = resultado / ingresos × 100`, y **`null` cuando `ingresos = 0`** (no `0`, no división
  por cero): un período sin ventas debe mostrar "—", no "0%", que se leería como "no ganamos ni
  perdimos".

## 5. Filtros

### 5.1 Fecha y granularidad

El partial `partial/daterangepicker` ya entrega `start_date`/`end_date` en `Y-m-d` o
`Y-m-d H:i:s` según `date_or_time_format`. La granularidad viaja como un parámetro más en
`queryParams`, junto con las fechas y los filtros, igual que en `sales/manage.php`.

Expresiones de agrupación (aplicadas a `sale_date` del lado ingresos y a `expenses.date` del lado
gastos):

| Granularidad | Agrupación | Etiqueta |
|---|---|---|
| Día | `DATE(x)` | fecha formateada con `to_date()` |
| Semana | `YEARWEEK(x, 3)` (ISO-8601, semana empieza lunes) | rango "dd/mm – dd/mm" |
| Mes | `DATE_FORMAT(x, '%Y-%m')` | mes y año en el idioma activo |

`YEARWEEK` con modo 3 es el que corresponde a ISO-8601. El modo por defecto (0) empieza la semana en
domingo y numera distinto — usarlo produciría semanas que no coinciden con las del calendario que
usa el negocio.

### 5.2 Persistencia en URL

Se reutiliza `partial/table_filter_persistence` pasándole
`['additional_params' => ['granularity']]`, que es justo para lo que existe ese parámetro. Ese
partial ya trae el `table_support.refresh()` que se había perdido en la regresión de PR upstream
#4400 y se restauró en el commit `2c61066ca`; **no hay que volver a declarar los manejadores en la
vista** (`sales/manage.php` los tiene duplicados por herencia histórica — se disparan dos veces, es
inofensivo pero no es el patrón a copiar).

### 5.3 Gráfico en la misma página

En vez de una segunda ruta gráfica (el patrón `graphical_*` de los otros reportes), el gráfico va
**arriba de la tabla, en la misma vista**, y se redibuja con el mismo JSON que alimenta la tabla.
Evita duplicar controlador, ruta y filtros, y es lo que hace coherente el control de grilla.

`reports/graphs/line.php` está cableado a **una sola serie** (`series: [{name, data}]`), así que hace
falta una vista nueva `reports/graphs/multiline.php` con las dos series. Chartist las soporta sin
librerías adicionales.

## 6. El filtro de medio de pago: cambio de modo declarado

**Decidido el 2026-08-22.** Esta sección estuvo abierta porque el filtro choca con la definición de
ingreso, y la resolución cambia la cifra principal según el filtro esté activo o no.

### 6.1 El choque

El alcance funcional fijó que **ingreso = total facturado de ventas completadas**. Un filtro por
medio de pago sobre ese número es inconsistente por dos razones:

1. **Una venta a crédito todavía no cobrada no tiene ningún pago asociado.** No pertenece a ninguna
   categoría de medio de pago, así que desaparecería del reporte en cuanto se active cualquier
   filtro — sin avisar, y justo las ventas que más importa no perder de vista.
2. **Una venta puede tener pagos partidos** (parte efectivo, parte tarjeta). La grilla de Ventas
   resuelve esto a nivel de venta — "muéstrame las ventas que tuvieron algún pago en efectivo" — y
   sigue mostrando el **total completo** de esas ventas. Sumar esos totales bajo la etiqueta
   "ingresos en efectivo" daría una cifra inflada.

### 6.2 La solución adoptada

**El reporte tiene dos modos, y dice en pantalla en cuál está.**

| | Filtro de medio de pago inactivo | Filtro activo |
|---|---|---|
| **Pregunta que responde** | ¿Cuánto facturamos y cuánto gastamos? | ¿Cuánto entró y cuánto salió por este canal? |
| **Ingresos** | Total facturado (`Summary_sales`, ver 4.1) | `sales_payments.payment_amount` de los medios seleccionados |
| **Gastos** | Todos los del período | Solo los de los medios seleccionados |
| **Subtítulo** | Rango de fechas | Rango + *"Ingresos = pagos recibidos, no facturación"* |

Cada modo es **internamente consistente**: en el primero se comparan dos magnitudes de devengo, en
el segundo dos magnitudes de caja. Lo que nunca ocurre es mezclarlas.

El modo de caja además responde algo que hoy ningún reporte responde: *"¿cuánto entró y cuánto salió
en efectivo este mes?"* — que para un negocio que maneja caja física es la pregunta operativa real.

### 6.3 La regla que hace esto aceptable

**El cambio de modo se declara, no se deduce.** El subtítulo del reporte y el encabezado de la
columna de ingresos cambian visiblemente al activar el filtro. Un reporte que cambia lo que mide sin
decirlo es exactamente el patrón que produjo el descuadre del turno 29: un número que parecía un
dato y era una falla.

Consecuencia para las pruebas: hay que cubrir **los dos modos por separado**, y verificar que el
subtítulo cambia. Ver sección 9.

### 6.4 Implementación

- El lado de ingresos en modo caja consulta `sales_payments` unida a `sales`, restringida a
  `sale_status = COMPLETED` y al mismo rango de fechas, agrupada por la misma expresión de período.
- **Se descuenta `cash_refund`** (`payment_amount - cash_refund`), igual que hace
  `Summary_payments`: una devolución en efectivo es plata que salió del cajón y no puede contar como
  ingreso.
- El mapeo entre los medios de pago de ventas y los de gastos se hace **por código**, no por
  etiqueta — lo que solo es posible después de la corrección de la sección 7. Ese es el orden de
  implementación obligatorio: primero la corrección, después el filtro.

## 7. Corrección del medio de pago en Gastos

Defecto descrito en la sección 7 del documento funcional. Resumen técnico:

- Se **escribe** `lang('Sales.*')` — `get_payment_options()` en `app/Helpers/locale_helper.php:239`
  arma el dropdown con siete opciones desde las claves `Sales.*`.
- Se **filtra** contra `lang('Expenses.*')` — diez comparaciones en `app/Models/Expense.php`
  (cinco en `search()`, cinco en `get_payments_summary()`), todas con `LIKE`.
- En es-MX, `Sales.due` = "Adeudo" y `Expenses.due` = "A Crédito": **el filtro nunca coincide**.
- Los otros cuatro coinciden **solo** porque `ospos_expenses` usa `utf8_general_ci`, que ignora
  mayúsculas y tildes ("Tarjeta de débito" ≈ "Tarjeta de Débito").
- Dos de los siete medios que el formulario permite guardar — **Transferencia Bancaria** y
  **Monedero** — no tienen filtro en la grilla.

### 7.1 Corrección: guardar un código, mostrar una etiqueta

Nueva columna `payment_type_code VARCHAR(20) NULL` con índice. Valores estables e independientes del
idioma: `cash`, `debit`, `credit`, `due`, `check`, `bank_transfer`, `wallet`, `upi`.

- **Escritura** (`Expenses::postSave()`): guarda el código. El `form_dropdown` pasa a tener el código
  como clave y la etiqueta traducida como valor — que es como ya funciona `form_dropdown`, sin
  cambios en la vista más allá del arreglo que recibe.
- **Lectura** (columna de la grilla y resumen de pagos, en `tabular_helper.php`): resuelve la
  etiqueta con `lang()` en el momento de mostrar. Un cambio de idioma deja de romper el histórico.
- **Filtros**: comparación por igualdad contra el código, no `LIKE`. Desaparece la dependencia de la
  colación.
- **Búsqueda de texto libre** (`orLike('expenses.payment_type', $search)`): pasa a resolver primero
  qué códigos tienen una etiqueta que coincide con el término buscado, y filtra por esos códigos.
  Si no se hace, buscar "efectivo" deja de encontrar nada.
- **Filtros de la grilla**: de cinco a siete, para que coincidan con lo que el formulario permite
  guardar.

### 7.2 Backfill del histórico

La migración mapea cada valor distinto de `payment_type` a su código, comparando contra la unión de
las etiquetas `Sales.*` y `Expenses.*` **de todos los idiomas instalados** (47 directorios en
`app/Language/`) — el histórico pudo escribirse bajo cualquier idioma activo en su momento.

**Los valores que no mapeen quedan en `NULL` y se registran en el log de la migración, con su
conteo.** No se les asigna un código por defecto. Esta regla es directamente la lección del turno 29:
un dato que no se pudo interpretar tiene que hacer ruido, no convertirse en silencio en el valor más
inocente disponible.

`payment_type` se conserva intacta como columna heredada. La lectura usa `payment_type_code` y cae
al texto original cuando es `NULL`, así que ningún gasto histórico desaparece de la grilla aunque su
medio de pago no se haya podido clasificar.

### 7.3 Alcance del cambio

Contenido y verificado: 10 sitios de comparación en `app/Models/Expense.php`, 1 de escritura en
`app/Controllers/Expenses.php`, 2 de presentación en `app/Helpers/tabular_helper.php`, 1 dropdown en
`app/Views/expenses/form.php`.

**`get_payment_options()` no se toca.** Tiene usos fuera de Gastos (el registro de ventas, entre
otros) y cambiarle el contrato es una superficie de riesgo desproporcionada — mismo criterio que se
aplicó con `parse_decimals()` al corregir el cierre de caja. La conversión código↔etiqueta vive en
un helper propio del módulo de Gastos.

## 8. Archivos a tocar

**Reporte**
1. `app/Models/Reports/Income_expenses.php` — nuevo.
2. `app/Controllers/Reports.php` — vista + endpoint `search`.
3. `app/Config/Routes.php` — dos rutas.
4. `app/Views/reports/analytical_income_expenses.php` — nueva.
5. `app/Views/reports/graphs/multiline.php` — nueva.
6. `app/Views/reports/listing.php` — cuarto panel.
7. `app/Helpers/report_helper.php` — excluir `analytics` en `can_show_report()`.
8. `app/Helpers/tabular_helper.php` — cabeceras y filas del reporte.
9. Migración del permiso `reports_analytics`.

**Corrección del medio de pago**
10. Migración: columna `payment_type_code` + backfill con reporte de no mapeados.
11. `app/Models/Expense.php` — 10 comparaciones + búsqueda libre.
12. `app/Controllers/Expenses.php` — escritura y lista de filtros (5 → 7).
13. `app/Helpers/tabular_helper.php` — resolución de etiqueta.
14. `app/Views/expenses/form.php` — dropdown por código.

**Idiomas:** `en` (fallback), `es-ES` y **`es-MX`** (el que corre esta instalación).

## 9. Pruebas

Siguiendo el patrón de `tests/Models/Reports/Summary_taxes_test.php`:

- El total de ingresos del reporte **coincide con `Summary_sales::getSummaryData()`** para el mismo
  rango. Es la prueba que protege el principio rector.
- Agrupación correcta en las tres granularidades, incluyendo un rango que cruza fin de año (donde
  `YEARWEEK` es más fácil de equivocar).
- Un período con ventas y sin gastos, y uno con gastos y sin ventas, aparecen ambos.
- `margen %` es `null`, no `0`, cuando no hay ingresos.
- Los gastos eliminados quedan fuera por defecto y dentro con el filtro activo.
- **Mapeo del backfill**: cada etiqueta de `Sales.*` y `Expenses.*` de en/es-ES/es-MX cae en su
  código; un valor desconocido queda en `NULL` y se reporta.
- **Regresión del bug**: un gasto guardado como "Adeudo" es encontrado por el filtro de adeudo.
  Hoy esa prueba falla.

**Los dos modos del reporte** (sección 6) se prueban por separado:

- Modo devengo (sin filtro de medio de pago): los ingresos coinciden con `Summary_sales`.
- Modo caja (con filtro): los ingresos coinciden con la suma de `payment_amount - cash_refund` de
  los medios seleccionados, y **no** con el total facturado.
- Una venta a crédito sin cobrar **aparece** en modo devengo y **no aparece** en modo caja. Es la
  prueba que documenta por qué existen los dos modos.
- Una venta con pago partido (efectivo + tarjeta) aporta a cada modo solo su porción
  correspondiente, no su total, cuando hay filtro activo.
- El subtítulo cambia al activar el filtro. Que el cambio de modo sea visible es parte del
  contrato, no un detalle de presentación.

## 10. Despliegue

Dos migraciones, así que el despliegue **no termina con el workflow**. Después de desplegar:

```bash
ssh -i ~/.ssh/ospos_deploy root@148.230.82.172
cd /root/POS_Casaletto_staging   # /root/POS_Casaletto en producción
docker compose -f docker-compose.staging.yml exec ospos php spark migrate
```

Y antes de cualquier `docker compose up --build` manual, correr el build de assets — la vista nueva
entra por los bloques de gulp-inject de `header.php`, y saltarse ese paso deja la página sin JS con
un HTTP 200 que ningún smoke test detecta.

**Producción solo después de las 10pm hora Colombia**, salvo autorización puntual.

**Verificación con datos reales:** la migración de backfill imprime cuántos gastos quedaron sin
mapear. Si ese número no es cero hay que revisar esos valores antes de dar el reporte por bueno.
