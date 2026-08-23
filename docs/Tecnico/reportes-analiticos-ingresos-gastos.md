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
`Y-m-d H:i:s` según `date_or_time_format`, y trae **14 presets**. La granularidad viaja como un
parámetro más en `queryParams`, junto con las fechas y los filtros, igual que en `sales/manage.php`.

**La granularidad se deriva del tamaño del rango**, no de la etiqueta del preset (razón en el
documento funcional, 5.5): `≤ 14 días → día`, `15–92 → semana`, `> 92 → mes`. El cálculo vive en el
callback del daterangepicker, que ya recibe `(start, end, label)`; se usan `start` y `end`, nunca
`label`. Una vez que el usuario toca el selector a mano, se marca como manual y deja de
recalcularse.

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

**La granularidad recibida en el servidor se valida contra una lista blanca** (`day`/`week`/`month`)
antes de tocar el SQL. Es un parámetro que entra en una cláusula `GROUP BY`, así que no puede
interpolarse tal como llega.

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

## 7. Corrección de los medios de pago (Ventas y Gastos)

Las dos fallas están descritas en la sección 7 del documento funcional. Resumen técnico y plan.

### 7.1 Falla A — entidades HTML en `sales_payments.payment_type`

Verificado contra producción el 2026-08-22, a nivel de bytes:

```
HEX('Tarjeta de d&eacute;bito') = 5461726A6574612064652064 26 6561637574653B 6269746F
                                                              ^^ &          ^^ ;
```

El `&` (0x26) y el `;` (0x3B) están literalmente almacenados. `Sale::search()` y
`Sale::get_payments_summary()` filtran con `like('payment_type', lang('Sales.debit'))`, donde la
etiqueta lleva la `é` real. **No coincide, y no es un problema de colación**: `utf8_general_ci`
equipara `é` con `e`, pero no expande una entidad de ocho caracteres a uno.

Medido sobre los datos reales: `only_debit` → **0** coincidencias (194 pagos, 12.715.730);
`only_creditcard` → **0** (6 pagos, 362.120). `only_cash` (444) y `only_bank_transfer` (91)
funcionan porque sus etiquetas no llevan tilde.

**Causa raíz encontrada (2026-08-22).** `app/Controllers/Sales.php:443`:

```php
$payment_type = $this->request->getPost('payment_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
```

La documentación de PHP describe ese filtro como *"equivalente a `htmlspecialchars()` con
`ENT_QUOTES`"*. **Es falso**: internamente usa `php_escape_html_entities_ex()` con `all = 1`, o sea
el comportamiento de `htmlentities()`, que codifica las vocales acentuadas. Comprobado ejecutando
PHP dentro del contenedor de producción:

```
htmlspecialchars("Tarjeta de débito", ENT_QUOTES)                    → ...64 c3a9 62...  (é intacta)
filter_var("Tarjeta de débito", FILTER_SANITIZE_FULL_SPECIAL_CHARS)  → ...64 266561637574653b 62...
```

**No hay ningún servicio externo ni integración involucrada**: está enteramente en el código, así que
es controlable de cara al modelo SaaS. El diagnóstico completo, con todo lo que se descartó en el
camino y el alcance real (147 usos en 19 controladores), está en
`docs/Tecnico/errores-produccion-upstream.md` sección 5.

### 7.2 Falla B — dos diccionarios distintos en Gastos

- Se **escribe** `lang('Sales.*')` — `get_payment_options()` (`app/Helpers/locale_helper.php:239`)
  arma el dropdown con siete opciones desde las claves `Sales.*`.
- Se **filtra** contra `lang('Expenses.*')` — diez comparaciones en `app/Models/Expense.php`
  (cinco en `search()`, cinco en `get_payments_summary()`), todas con `LIKE`.
- En es-MX, `Sales.due` = "Adeudo" y `Expenses.due` = "A Crédito": nunca coincide. Hoy no oculta
  nada porque no hay gastos con ese medio de pago.
- Dos de los siete medios que el formulario permite guardar — **Transferencia Bancaria** y
  **Monedero** — no tienen filtro en la grilla. Los 2 gastos por 1.650.000 pagados por
  transferencia son inalcanzables.

### 7.3 Plan de corrección, en tres pasos

**Paso 1 — reparar los datos.** Migración que decodifica las entidades en
`sales_payments.payment_type`. Devuelve los filtros de Ventas de inmediato, sin depender de los
otros dos pasos.

Se decodifica con una lista explícita de las entidades presentes, **no con un `REPLACE` genérico de
`&` ni con `html_entity_decode()` sobre todo el campo**: el campo también guarda tipos compuestos
(tarjetas de regalo con número, ajustes de caja), y una decodificación amplia podría alterar valores
que no están rotos. La migración **imprime cuántas filas tocó y cuántas quedaron con `&` residual**.

**La misma migración normaliza `items.description`** (decidido con el usuario, 2026-08-22): 50 filas
con `Unidad: n&uacute;mero de unidades internacionales`. **Su origen es distinto** — vienen del
archivo de Siigo, no de este filtro; en esas mismas filas el nombre del artículo conserva su tilde
real. Se incluyen aquí porque el síntoma y la reparación son idénticos, son datos de solo lectura y
hoy se ven literalmente como `n&uacute;mero` en pantalla dondequiera que la salida esté escapada.
Van como un paso separado dentro de la misma migración, con su propio conteo, para poder revertirlas
por separado.

**Paso 2 — tapar la causa.** Sin esto, el paso 1 se deshace con la primera venta con tarjeta esa
misma noche. **No se despliega el paso 1 a producción sin el 2.**

La causa ya está identificada (7.1): `FILTER_SANITIZE_FULL_SPECIAL_CHARS` al leer el POST. El
criterio de corrección es que **el saneamiento de entrada no debe cambiar el dato** — escapar es
responsabilidad de la salida, y las vistas ya usan `esc()`.

**Aquí no se corrigen los 147 usos.** Este trabajo toca solo las cuatro lecturas de medio de pago
(`Sales.php:443`, `Sales.php:1594`, `Sales.php:1625` y `Expenses.php:183`). Y una vez hecho el paso
3 esas lecturas dejan de necesitar saneamiento de ningún tipo: el valor posteado pasa a ser un
**código de una lista blanca conocida**, así que se valida contra la lista y se rechaza lo que no
esté. Es más seguro que sanear, y no deforma el dato.

El resto de los 147 usos queda documentado en `errores-produccion-upstream.md` sección 5 como deuda
conocida, para abordarse por módulo. **Hoy el daño está contenido** porque los usuarios de Casaletto
escriben sin tildes: en producción no hay una sola fila con tilde en `people`,
`expenses.description`, `expense_categories` ni `sales.comment`. Deja de estar contenido en cuanto un
tenant nuevo tenga usuarios que sí las escriban.

**Paso 3 — códigos estables.** Nueva columna `payment_type_code VARCHAR(20) NULL` con índice, en
`expenses` y en `sales_payments`. Valores independientes del idioma: `cash`, `debit`, `credit`,
`due`, `check`, `bank_transfer`, `wallet`, `upi`.

- **Escritura**: se guarda el código. El `form_dropdown` pasa a tener el código como clave y la
  etiqueta traducida como valor — que es como ya funciona `form_dropdown`.
- **Lectura**: la etiqueta se resuelve con `lang()` al mostrar. Un cambio de idioma deja de romper
  el histórico.
- **Filtros**: igualdad contra el código, no `LIKE`. Desaparece la dependencia de la colación.
- **Búsqueda de texto libre** (`orLike('expenses.payment_type', $search)`): pasa a resolver primero
  qué códigos tienen una etiqueta que coincide con el término, y filtra por esos códigos. Sin esto,
  buscar "efectivo" deja de encontrar nada.
- **Filtros de la grilla de Gastos**: de cinco a siete, para que coincidan con lo que el formulario
  permite guardar.

### 7.4 Backfill: trivial en Gastos, acotado en Ventas

Los datos reales de producción (2026-08-22) hacen esto mucho más simple de lo previsto:

| Tabla | Valores distintos | Mapeo |
|---|---|---|
| `expenses` | 2 — "Efectivo" (54), "Transferencia Bancaria" (2) | sin ambigüedad |
| `sales_payments` | 4 — Efectivo, Tarjeta de d&eacute;bito, Transferencia Bancaria, Tarjeta de Cr&eacute;dito | sin ambigüedad **después del paso 1** |

El mapeo se hace contra la unión de las etiquetas `Sales.*` y `Expenses.*` de **todos los idiomas
instalados** (47 directorios en `app/Language/`) — no porque haga falta hoy, sino porque el mismo
código correrá en los tenants nuevos de la plataforma, que pueden estar en otro idioma.

**Los valores que no mapeen quedan en `NULL` y se registran en el log de la migración, con su
conteo.** No se les asigna un código por defecto. Es directamente la lección del turno 29: un dato
que no se pudo interpretar tiene que hacer ruido, no convertirse en silencio en el valor más
inocente disponible.

`payment_type` se conserva intacta como columna heredada. La lectura usa `payment_type_code` y cae
al texto original cuando es `NULL`, así que ningún registro histórico desaparece de la grilla aunque
su medio de pago no se haya podido clasificar.

### 7.5 Alcance del cambio

**Gastos** (verificado): 10 comparaciones en `app/Models/Expense.php`, 1 escritura en
`app/Controllers/Expenses.php`, 2 presentaciones en `app/Helpers/tabular_helper.php`, 1 dropdown en
`app/Views/expenses/form.php`.

**Ventas**: 7 comparaciones en `Sale::get_payments_summary()` más las de `Sale::search()`, la
escritura en `Sales::postAdd_payment()` y en la edición de venta, y la presentación en
`tabular_helper.php` y `register.php`.

**`get_payment_options()` no se toca.** La usan el registro de ventas, gastos y recepciones;
cambiarle el contrato es una superficie de riesgo desproporcionada — mismo criterio que se aplicó
con `parse_decimals()` al corregir el cierre de caja. La conversión código↔etiqueta vive en un
helper nuevo.

**Cuidado con las comparaciones sueltas**: `Sales.php` y `register.php` comparan `$payment_type`
contra `lang('Sales.giftcard')`, `lang('Sales.cash')` y otras, tanto en PHP como en JavaScript. Hoy
funcionan porque esas etiquetas no llevan tilde. Todas tienen que migrar al código en el mismo
cambio, o quedarán como la próxima falla silenciosa de esta misma familia.

### 7.6 Fase 1b — erradicar el filtro en el resto de la aplicación

**Decidido con el usuario el 2026-08-22.** La fase 1 arregla los 4 usos de medio de pago; la 1b se
lleva por delante los 143 restantes.

**Es solo código: no hace falta ninguna migración de datos.** Barrido completo de la base de
producción (2026-08-22, 15 campos revisados): los únicos con entidades son
`sales_payments.payment_type` (201, este filtro) e `items.description` (50, origen Siigo). Están en
**cero** los nombres de proveedor, de agencia, valores y definiciones de atributo, descripciones de
kit, tarjetas de regalo, nombres de empleado, comentarios de cliente, categorías de artículo,
comentarios de recepción, nombres de ubicación, nombres de mesa y nombres de artículo. **La fase 1b
previene, no repara.**

**El obstáculo real son las 255 salidas sin escapar.** Hoy el filtro hace doble oficio: además de
deformar el dato, es lo único entre lo que se postea y esas salidas. Ejemplo confirmado, en la
pantalla de venta:

```php
<td><?= $payment['payment_type'] ?></td>     // app/Views/sales/register.php:552 — crudo
```

Quitar el filtro sin auditar la salida cambiaría un problema de datos por uno de seguridad. **El
orden dentro de cada módulo no es negociable: primero se escapa la salida, después se quita el
filtro.** Nunca al revés, ni siquiera "por un rato".

**Procedimiento por módulo:**

1. Auditar las salidas del módulo —sus vistas y sus funciones en `tabular_helper.php`— y escapar con
   `esc()` donde falte.
2. Quitar el filtro de las lecturas de POST/GET de ese módulo.
3. Probar en staging con texto acentuado de verdad: guardar un "José Muñoz", buscarlo por nombre,
   verlo en la grilla, editarlo, exportarlo a Excel y verlo en un recibo impreso.

**Orden, por riesgo y beneficio:**

| # | Módulo | Usos | Por qué ahí |
|---|---|---|---|
| 1 | `Customers` | 2 | Nombres propios: el primer sitio donde un negocio nuevo escribirá tildes. Alimenta además el autocompletado del registro de venta. |
| 2 | `Employees` | 19 | Nombres propios, y el más grande de los de arriba. |
| 3 | `Suppliers` | 16 | Ya carga 2 parches de `html_entity_decode` que hay que **retirar** al corregir el origen. |
| 4 | `Items` + `Item_kits` | 10 | Ya hay 26 nombres con tilde real desde Siigo. Hoy, editar uno por la web lo corrompe. |
| 5 | `Expenses` + `Expenses_categories` | 15 | Descripciones de texto libre. |
| 6 | `Sales` + `Receivings` + `Cashups` | 44 | Comentarios y campos libres. `Sales` queda parcialmente hecho desde la fase 1. |
| 7 | `Attributes` | 4 | Retirar sus 4 `html_entity_decode` al corregir el origen. |
| 8 | Resto: `Taxes`, `Tax_codes`, `Tax_jurisdictions`, `Tax_categories`, `Messages`, `Giftcards`, `Home`, `tabular_helper` | 37 | Campos mayormente numéricos o de catálogo. |

**Los 6 `html_entity_decode()` se retiran junto con el filtro que los hizo necesarios**, módulo por
módulo. Dejarlos sería peor que quitarlos: con el dato entrando limpio, esa llamada pasa a decodificar
texto legítimo — quien escriba "Ron &amp; Cola" en una descripción vería su texto transformado.

**Regla para no reintroducirlo.** En lecturas de POST/GET:

- **Valor de lista conocida** (medios de pago, tipos, estados): validar contra lista blanca. Es más
  seguro que sanear y no deforma el dato.
- **Texto libre**: leer crudo y escapar en la salida.
- **Números**: los filtros numéricos (`FILTER_SANITIZE_NUMBER_INT`) no deforman y se quedan.

Conviene una prueba que recorra los controladores ya migrados y **falle si el filtro reaparece**.
Sin eso, la próxima actualización desde upstream lo reintroduce y nadie se entera hasta que un
cliente se llame "José".

## 7bis. Fase 0 — poner la documentación al día

**Pedido por el usuario el 2026-08-22, antes de arrancar la fase 1.** La auditoría se hizo ese día;
las correcciones van en el mismo commit que este plan.

### Deriva encontrada, verificada una por una

**1. `docs/Funcional/multi-tenant-multi-negocio.md` describía un proyecto sin desplegar.** Su sección
"Estado de avance" terminaba en la Fase 9 y afirmaba que las fases 3, 4, 5, 7 y 8 estaban "solo en la
rama de desarrollo", que "todavía no hay nada visible para ningún usuario" y que el dominio nuevo en
producción "queda pendiente". La realidad: las once fases cerraron el 2026-08-03 y Casaletto opera
como tenant real desde entonces. Comprobado el 2026-08-22 con peticiones de solo lectura —
`casaletto.ospos-saas.micronuba.net` responde 200 con TLS válido y la URL legacy responde 200 en
paralelo. **Corregido**: bloque de estado actual, correcciones puntuales a las frases que ya eran
falsas, y la Fase 10 documentada.

**Causa de la deriva, que es lo que importa:** los dos commits que registraron la Fase 10
(`a9e14b369`, `50db218f9`) tocaron **únicamente** `docs/Tecnico/`. El documento técnico quedó al día
y el funcional se quedó tres semanas atrás. El funcional es justamente el que leería un socio, un
cliente o alguien que entra al proyecto.

**2. `AGENTS.md` contradecía la política de ramas.** Decía *"Create a new git worktree for each
issue, based on the latest state of `origin/master`"* — de upstream, donde `master` es la rama de
trabajo. Acá el trabajo nuevo va a `develop`. Un agente que siguiera ese archivo al pie de la letra
ramificaría desde producción. **Corregido**, y de paso se le agregaron las reglas operativas del fork
que hasta hoy no estaban escritas en ningún archivo del repo: los workflows no corren migraciones, el
build de assets antes de cualquier `up --build` manual, producción no se toca en horario operativo, y
nada de secretos en los compose.

Lo verificado que **sí** estaba correcto y no se tocó: `.php-cs-fixer.no-header.php` existe,
`composer test` ejecuta phpunit, `npm run build` ejecuta gulp, y las rutas de controladores/modelos/
vistas/migraciones son las reales.

**3. La wiki vendorizada tiene rutas de CodeIgniter 3.**
`docs/Funcional/referencia-ospos-wiki/How-to-add-a-new-report.md` manda editar
`application\models\reports\`, `application\controllers\reports.php` y
`application\helpers\table_helper.php`. En CI4 eso es `app/Models/Reports/`,
`app/Controllers/Reports.php` y `app/Helpers/tabular_helper.php`.

**No se corrige ahí**: esa carpeta tiene una regla explícita en su README — es una foto congelada de
upstream al momento del fork y no se edita. La versión válida para este fork son las secciones 2 a 4
de **este** documento, que además cubren lo que esa página no menciona: que el reporte no aparece sin
su fila en `ospos_permissions`, y que un permiso `reports_*` nuevo se cuela solo en los paneles
Gráficos y Resumidos si no se lo excluye en `can_show_report()`.

**4. Sin deriva:** ningún documento propio referencia archivos que no existan. Las cuatro rutas que
aparecen sin correspondencia (`Income_expenses.php`, `payment_type_helper.php`,
`analytical_income_expenses.php`, `multiline.php`) son las que este plan propone crear.

### Regla permanente, para que no se repita

**Un cambio no está terminado hasta que la documentación coincide con el código.** En concreto:

- Un cambio de comportamiento se documenta en `docs/Funcional/` **y** en `docs/Tecnico/`, o en
  ninguno de los dos. Actualizar solo el técnico es exactamente la falla que produjo la deriva de
  arriba.
- Un despliegue que cambia lo que el negocio ve —una fase que sale a producción, una URL nueva— se
  refleja en el documento funcional en el mismo commit.
- Las frases de estado ("pendiente", "sin desplegar", "solo en desarrollo") llevan **fecha**. Sin
  fecha no se puede distinguir un pendiente real de un párrafo que envejeció.
- La wiki vendorizada no se edita nunca. Las correcciones van en `docs/Funcional/` propio.

Esto quedó también escrito en `AGENTS.md`, que es el archivo que lee un agente al entrar al repo —
la memoria de una sesión de chat no le sirve a nadie más.

### Aplica a las fases de este plan

Cada fase cierra con su documentación al día:

- **Fase 1**: la corrección de medios de pago se registra en
  `docs/Tecnico/errores-produccion-upstream.md` (ya tiene su entrada 5, hay que marcarla como
  corregida al terminar) y el cambio visible —que los filtros de tarjeta por fin devuelvan
  resultados— en `docs/Funcional/`.
- **Fase 1b**: cada módulo migrado actualiza la tabla de avance de la sección 7.6, con fecha.
- **Fase 2**: el reporte nuevo necesita su propia página funcional describiendo los dos modos, porque
  es lo que un usuario del negocio tiene que entender antes de leer una cifra.

## 8. Orden de implementación y archivos a tocar

**La fase 1 bloquea la fase 2.** El modo caja del reporte cruza ingresos y gastos por medio de pago;
sobre los datos actuales daría cero para débito y crédito.

### Fase 0 — documentación (hecha)

Ver 7bis. Corregidos `docs/Funcional/multi-tenant-multi-negocio.md` y `AGENTS.md`; regla permanente
escrita en `AGENTS.md`.

### Fase 1 — medios de pago

1. Migración: **reparar** las entidades HTML en `sales_payments.payment_type`, informando filas
   tocadas y residuales.
2. **Diagnóstico y corrección de la causa** (paso 2 de 7.3). Trabajo de investigación, sin alcance
   cerrado todavía. **No se despliega el punto 1 sin este.**
3. Migración: columna `payment_type_code` en `expenses` y `sales_payments` + backfill con reporte de
   no mapeados.
4. `app/Helpers/payment_type_helper.php` — nuevo, conversión código↔etiqueta.
5. `app/Models/Expense.php` — 10 comparaciones + búsqueda libre.
6. `app/Controllers/Expenses.php` — escritura y lista de filtros (5 → 7).
7. `app/Models/Sale.php` — comparaciones de `search()` y `get_payments_summary()`.
8. `app/Controllers/Sales.php` — escritura y comparaciones sueltas contra `lang('Sales.*')`.
9. `app/Helpers/tabular_helper.php` — resolución de etiqueta en ambas grillas.
10. `app/Views/expenses/form.php` y `app/Views/sales/register.php` — dropdowns por código, incluidas
    las comparaciones en JavaScript.

### Fase 1b — erradicación del filtro (ver 7.6)

Módulo por módulo, en el orden de la tabla de 7.6. Cada módulo es un commit propio: auditoría de
salida + retiro del filtro + prueba en staging. No hay migraciones de datos.

### Fase 2 — reporte

11. `app/Models/Reports/Income_expenses.php` — nuevo.
12. `app/Controllers/Reports.php` — vista + endpoint `search`.
13. `app/Config/Routes.php` — dos rutas.
14. `app/Views/reports/analytical_income_expenses.php` — nueva.
15. `app/Views/reports/graphs/multiline.php` — nueva.
16. `app/Views/reports/listing.php` — cuarto panel.
17. `app/Helpers/report_helper.php` — excluir `analytics` en `can_show_report()`.
18. `app/Helpers/tabular_helper.php` — cabeceras y filas del reporte.
19. Migración del permiso `reports_analytics`.

**Idiomas:** `en` (fallback), `es-ES` y **`es-MX`** (el que corre esta instalación).

**Commits separados por concern**: la corrección de medios de pago y el reporte son dos temas
distintos y van en commits distintos, aunque se trabajen en la misma sesión.

## 9. Pruebas

Siguiendo el patrón de `tests/Models/Reports/Summary_taxes_test.php`.

**Medios de pago (fase 1)**

- **Regresión de la falla A**: un pago guardado como "Tarjeta de d&eacute;bito" es encontrado por el
  filtro de débito. Hoy esa prueba falla — 0 coincidencias sobre 194 pagos reales.
- **Regresión de la falla B**: un gasto guardado como "Adeudo" es encontrado por el filtro de adeudo.
  Hoy esa prueba falla.
- Un gasto pagado por Transferencia Bancaria es alcanzable por un filtro de la grilla. Hoy no existe
  ese filtro.
- **Mapeo del backfill**: cada etiqueta de `Sales.*` y `Expenses.*` de en/es-ES/es-MX cae en su
  código; un valor desconocido queda en `NULL` y se reporta.
- La reparación de entidades **no altera** los tipos compuestos (tarjeta de regalo con número,
  ajuste de caja).
- La búsqueda de texto libre por "efectivo" sigue encontrando los gastos en efectivo después de
  migrar a códigos.

**Reporte (fase 2)**

- El total de ingresos **coincide con `Summary_sales::getSummaryData()`** para el mismo rango. Es la
  prueba que protege el principio rector.
- Agrupación correcta en las tres granularidades, incluyendo un rango que cruza fin de año (donde
  `YEARWEEK` es más fácil de equivocar).
- La granularidad derivada: 7 días → día, 30 días → semana, 365 días → mes; y la elección manual del
  usuario no se sobrescribe al mover el rango.
- Una granularidad inválida recibida en el servidor se rechaza contra la lista blanca.
- Un período con ventas y sin gastos, y uno con gastos y sin ventas, aparecen ambos.
- `margen %` es `null`, no `0`, cuando no hay ingresos.
- Los gastos eliminados quedan fuera por defecto y dentro con el filtro activo.

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

Varias migraciones, así que el despliegue **no termina con el workflow** — los pipelines solo
sincronizan código y construyen assets. Después de desplegar:

```bash
ssh -i ~/.ssh/ospos_deploy root@148.230.82.172
cd /root/POS_Casaletto_staging   # /root/POS_Casaletto en producción
docker compose -f docker-compose.staging.yml exec ospos php spark migrate
```

Y antes de cualquier `docker compose up --build` manual, correr el build de assets — las vistas
nuevas entran por los bloques de gulp-inject de `header.php`, y saltarse ese paso deja la página sin
JS con un HTTP 200 que ningún smoke test detecta.

**Producción solo después de las 10pm hora Colombia**, salvo autorización puntual.

### Lineamientos del usuario para esta fase (2026-08-22)

**No se puede perder información de producción.** De ahí lo que sigue.

**Staging hoy NO ejercita el bug.** Su tabla `sales_payments` tiene 2 pagos, ambos "Efectivo", sin
una sola tilde. Una migración de reparación pasaría en staging sin tocar nada, y eso no probaría
absolutamente nada. **Antes de probar hay que sembrar staging con datos representativos**: los cuatro
valores reales de producción (`Efectivo`, `Transferencia Bancaria`, `Tarjeta de d&eacute;bito`,
`Tarjeta de Cr&eacute;dito`) más los compuestos (tarjeta de regalo con número, ajuste de caja), en
volumen suficiente para que los conteos sean verificables.

**Criterio de aceptación en staging, antes de tocar producción:**

- Conteos por medio de pago idénticos antes y después. La reparación cambia la *forma* del valor,
  nunca el número de filas ni los importes.
- La suma de `payment_amount` y de `cash_refund` no cambia en un solo peso.
- Los tipos compuestos quedan intactos.
- Los filtros de la grilla pasan de 0 a la cifra esperada.
- La migración es **idempotente**: correrla dos veces no vuelve a transformar nada.
- Existe y se prueba el camino de vuelta (`down()`), no solo el de ida.

### Respaldo antes de la reparación de datos

La migración del punto 1 **escribe sobre datos de dinero ya existentes**. Antes de correrla en
producción: respaldo de `ospos_sales_payments`, igual que se hizo antes de corregir el turno 29.

### Verificación después de migrar

- La migración de reparación imprime filas tocadas y residuales con `&`. Si quedan residuales, hay
  que mirarlas una por una antes de seguir.
- El backfill imprime cuántas filas quedaron sin mapear. Sobre los datos actuales **debe ser cero**
  (2 valores distintos en gastos, 4 en pagos de venta); cualquier otra cosa es una señal de que
  apareció un valor no previsto.
- Contraste de solo lectura contra la cifra conocida: los filtros de débito y crédito de la grilla
  de Ventas deben pasar de 0 a 194 y 6 coincidencias respectivamente.
