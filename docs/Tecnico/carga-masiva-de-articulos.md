# Diseño técnico — Carga masiva de artículos: crear y actualizar

> **Estado a 2026-09-01: alcance CERRADO, sin escribir una línea de código.**
>
> Las nueve decisiones de diseño (D1-D9) están en `docs/Funcional/carga-masiva-de-articulos.md` §6.
> **Leerlo primero.** Dos quedan por confirmar con el dueño y están marcadas allí.
>
> El módulo de plataforma se cerró el 2026-09-01 y ya no compite por este árbol. La línea del cliente
> supermercado vive en `docs/*/venta-por-peso-y-hardware-de-caja.md` y **no se toca desde aquí**.

---

## 1. Mapa de lo que existe

Verificado leyendo el código.

| Archivo | Qué hace |
|---|---|
| `app/Helpers/importfile_helper.php` | `generate_import_items_csv()` — devuelve **solo encabezados**, sin datos |
| `app/Controllers/Items.php:992` | `getGenerateCsvFile()` — entrega esa plantilla vacía |
| `app/Controllers/Items.php:1007` | `getCsvImport()` — la pantalla de subida |
| `app/Controllers/Items.php:~1017-1215` | `postImportCsv()` — el procesamiento real |
| `app/Models/Item.php:70` | `ALLOWED_BULK_EDIT_FIELDS` |
| `app/Views/items/form_bulk.php` | El formulario de edición masiva |
| `public/js/manage_tables.js:225-230` | La exportación de la grilla |
| `app/Helpers/tabular_helper.php:398, 490` | Las columnas del listado, enumeradas a mano |

**Las columnas del archivo**, en este orden exacto:

```
Id, Barcode, "Item Name", Category, "Supplier ID", "Cost Price", "Unit Price",
"Tax 1 Name", "Tax 1 Percent", "Tax 2 Name", "Tax 2 Percent", "Reorder Level",
Description, "Allow Alt Description", "Item has Serial Number", Image, HSN,
"Unit of Measure"
```
más una columna `location_<nombre>` por bodega y una por atributo.

---

## 2. Los hechos que definen el problema

### 2.1 La plantilla no trae datos

`generate_import_items_csv()` concatena encabezados y retorna. No consulta la tabla de artículos.

### 2.2 Crear vs actualizar depende del `Id`, y sólo de él

```php
$itemId   = (int) $row['Id'];
$isUpdate = ($itemId > 0);
```

Un archivo sin `Id` **intenta crear siempre**.

**Y aquí el documento del 2026-08-31 se equivocaba.** No duplica: con `allow_duplicate_barcodes = 0`
--como están los dos negocios de producción-- `item_number_exists()` marca la fila como fallida, y
como todo corre en una transacción, **una fila fallida revierte el archivo completo**.

O sea que hoy reimportar el propio catálogo no ensucia nada: sencillamente no hace nada, y contesta
«fallaron las filas 2, 3, 4… 1185». Menos peligroso de lo que creíamos, igual de inservible.

Con `allow_duplicate_barcodes = 1` sí duplicaría. Ninguno de los dos negocios lo tiene así, pero el
diseño no puede depender de esa configuración: **el emparejamiento por código (D3) tiene que ser
explícito, no un efecto secundario de la validación de unicidad.**

### 2.3 La exportación de la grilla no sirve como origen

```js
exportDataType: 'basic',
```

`basic` en bootstrap-table exporta **las filas renderizadas**, es decir la página actual. Con 1.184
artículos y páginas de 25, saca 25.

Y **`unit_of_measure` no está en la grilla**: cero apariciones en `tabular_helper.php`. `item_headers()`
(:398) y `get_item_data_row()` (:490) enumeran los campos a mano, y `sanitizeSortColumn` rechaza
ordenar por una columna que no está en su lista. Aunque la exportación trajera todas las filas, la
unidad se perdería en el ida y vuelta.

### 2.4 La edición masiva está a medio cablear

`ALLOWED_BULK_EDIT_FIELDS` incluye **`unit_of_measure`**, y el backend lo normaliza. Pero
`form_bulk.php` **no tiene control para él**: sus campos son `name, category, supplier_id,
cost_price, unit_price, reorder_level, description, allow_alt_description, is_serialized`.

El backend acepta algo que la pantalla no ofrece. Cerrarlo es agregar el control y pasarle las
opciones desde `Item::units_of_measure_options()`.

---

## 3. Trampas que este trabajo se va a encontrar

Escritas porque ya costaron tiempo en este repositorio.

### 3.1 `Item::save_value()` escribe con el query builder crudo
**No consulta `$allowedFields`.** El normalizador de cada campo es lo único que separa un POST
arbitrario de la columna. Cualquier campo nuevo que se acepte en la importación tiene que normalizarse
explícitamente; confiar en `$allowedFields` es confiar en algo que ese camino no usa.

### 3.2 Celda vacía ≠ "poner en blanco"
Ya hay precedente y hay que respetarlo. La importación **omite la clave** de `unit_of_measure` cuando
la celda viene vacía, precisamente para que una reimportación no degrade a `unit` todos los artículos
pesados. El comentario en `importfile_helper.php` lo explica. **La decisión 4.4 del funcional tiene
que extender esa regla a los demás campos, no contradecirla.**

### 3.3 La cabecera nueva va al final, nunca intercalada
Los clientes conservan copias llenas de la plantilla. Reordenar columnas **corre en silencio todos
los valores que ya escribieron**. Está documentado en el propio helper y es innegociable.

### 3.4 `items.item_number` NO es único
No hay restricción de unicidad. Emparejar por código (decisión 4.1) tiene que decidir qué hacer si
dos artículos comparten el código. Y ojo con el orden: hasta el 2026-08-31 una búsqueda por código
podía devolver otro artículo — ver *"Un código tecleado podía vender otro producto"* en
`docs/Tecnico/errores-produccion-upstream.md`. **`item_number` gana sobre `item_id`**; cualquier
emparejamiento nuevo tiene que seguir esa misma regla o habrá dos verdades.

### 3.5 La importación corre dentro de una transacción
`$db->transBegin()` antes del bucle. Cambiar el manejo de errores (decisión 4.3) implica decidir
explícitamente qué se confirma y qué se revierte. Validar **antes** de abrir la transacción evita el
problema entero.

### 3.6 El precio y la cantidad se leen con configuración de idioma
Este tenant corre con `number_locale = es_CO`, donde el **punto es separador de miles**. Un archivo
escrito en Excel en español y otro en inglés no significan lo mismo. Antes de aceptar decimales por
archivo, mirar cómo lo resolvió la venta por peso: `Sale_lib::normalize_weight_input()` existe
justamente porque `parse_decimals()` leía `0.735` como 735.

### 3.7 Las pruebas de artículos son frágiles por dos motivos concretos
- **`$refresh = true` NO trunca entre métodos** en las clases de esta suite; los fixtures se acumulan.
- **`items.item_number` no es único**, así que con copias acumuladas una búsqueda resuelve siempre a
  la más vieja. **Los fixtures de artículos deben borrar antes de insertar.** Síntoma: cada prueba
  pasa aislada y fallan en conjunto.

---

### 3.8 Excel destruye un EAN de 13 dígitos al abrir el archivo

Encontrado el 2026-09-01 mirando los datos de Paraíso, y es el que decide si esto sirve o no.

Los 1.184 códigos de ese negocio son **EAN de 13 dígitos**. Excel convierte a notación científica
cualquier número de más de 11 dígitos al abrir un CSV: `7702028000316` se vuelve `7,70203E+12`, y al
guardar **el código queda destruido en las 1.184 filas, sin un solo aviso**.

Entrecomillar el valor NO basta: Excel ignora las comillas de un CSV para decidir el tipo. Las salidas
reales son escribir la celda de forma que Excel la trate como texto, o entregar un formato donde el
tipo se pueda declarar. **Sea cual sea la elegida, la importación tiene que aceptar las dos formas**
--el código limpio y el envuelto-- porque el cliente puede subir un archivo que no salió de nosotros.

Criterio de aceptación del funcional §9, punto 2. No es un detalle de implementación.

### 3.9 No hay unicidad, pero hoy tampoco hay duplicados

Medido en producción el 2026-09-01: **cero códigos repetidos** en Casaletto y en Paraíso, incluso
contando los borrados. Así que D3 --emparejar por código-- es inequívoco hoy.

Pero la columna sigue sin restricción (§3.4), así que el emparejamiento **tiene que decidir el caso de
todas formas**: D6 dice que un código que aparezca en más de un artículo vivo es un error de esa fila.
Nunca se adivina cuál. Escrito porque la tentación de «tomar el primero» es fuerte y el síntoma
--actualizar el artículo equivocado-- tarda meses en verse.

### 3.10 18 artículos de Casaletto no tienen código

Medido el 2026-09-01. Para ellos el emparejamiento por código no puede funcionar, así que:

- En la **descarga** su columna de código va vacía, pero **su `Id` va lleno** — que es justo para lo
  que el `Id` se sigue exportando.
- Al **subir**, esas filas se emparejan por `Id`. Si alguien borra esa columna, esas 18 filas se
  leerían como artículos nuevos, y la vista previa lo dirá antes de escribir nada.

---

## 4. El diseño, ya cerrado

### 4.1 Las dos descargas (D1)

Dos rutas distintas, no un parámetro de la misma: quien llega a esta pantalla ya sabe a cuál de las
dos cosas viene, y un desplegable para elegir sería una decisión que no hace falta pedirle.

| | Origen | Contenido |
|---|---|---|
| Plantilla vacía | `getGenerateCsvFile()`, **ya existe** | Solo encabezados |
| Mi catálogo | **nuevo** | Los mismos encabezados + una fila por artículo vivo |

La generación de encabezados se comparte: **una sola función arma la cabecera** y la nueva le añade
las filas. Duplicarla es garantizar que un día la exportación y la importación dejen de encajar --que
es exactamente el fallo que este trabajo viene a arreglar.

Va por `Item::get_all()` con paginación por lotes, no cargando 1.184 objetos de golpe en memoria; y el
`Id` **se exporta siempre**, porque es lo único que empareja a los 18 artículos de Casaletto que no
tienen código (§3.10).

### 4.2 El emparejamiento (D3, D6)

Por fila, en este orden:

1. **¿Viene `Id`?** Se empareja por `Id`. Si ese `Id` no existe → error de fila. *(Es lo de hoy.)*
2. **¿No viene `Id` pero sí código?** Se busca por `item_number` entre los artículos vivos.
   - Un solo resultado → **actualizar** ese.
   - Ninguno → **crear**.
   - **Más de uno → error de fila** (D6). No se adivina.
3. **¿Ni `Id` ni código?** → **crear**, si trae los tres campos obligatorios.

El `Id` manda sobre el código, lo cual es la regla opuesta a la de la búsqueda del punto de venta
(§3.4, donde `item_number` gana). **No es una inconsistencia y conviene entender por qué**: allí se
resuelve lo que un cajero teclea, y lo que teclea es el código impreso. Aquí se resuelve un archivo
que salió de nosotros, donde el `Id` es la identificación exacta y el código es la humana.

### 4.3 Celda vacía = no cambiar (D4)

Se generaliza lo que hoy solo hace `unit_of_measure` (§3.2): **la clave se omite del arreglo** cuando
la celda viene vacía, en vez de escribir `''`. En un `update` la columna se queda como estaba; en un
`insert` toma el `DEFAULT` de la columna.

Ojo con el filtro que ya existe justo antes de guardar:

```php
$itemData = array_filter($itemData, fn($v) => $v !== null && strlen($v));
```

Descarta los vacíos **pero también descartaría un `0` si dejara de compararse con `strlen`**. Un
precio de 0 y un `reorder_level` de 0 son valores legítimos. Cualquier reescritura de ese filtro tiene
que seguir dejando pasar el cero.

### 4.4 La vista previa (D5)

**Es lo que más cambia el diseño de esta pantalla**, y es lo que sustituye a la casilla de «permitir
crear»: el usuario no decide a ciegas antes de ver el archivo, sino con el recuento delante.

Dos pasos:

1. **POST del archivo** → se valida entero, **sin abrir transacción y sin escribir nada**, y se
   devuelve el plan: a crear, a actualizar, con error (número de fila y motivo).
2. **POST de confirmación** → se aplica.

La decisión técnica que hay que tomar es **dónde vive el archivo entre los dos pasos**. Las opciones y
lo que cuesta cada una:

| Opción | A favor | En contra |
|---|---|---|
| Volver a subirlo al confirmar | Sin estado en el servidor | El usuario sube 1.184 filas dos veces |
| Guardarlo en `writable/uploads` con nombre por sesión | Un solo envío | Hay que limpiarlo, y es un archivo del cliente en disco |
| Guardar el **plan ya calculado** en sesión | No se guarda el archivo | Un plan de 1.184 filas es grande para una sesión |

**Recomendación: la segunda**, con borrado al aplicar o al caducar. Es la única que no le pide al
cliente subir dos veces un archivo grande, y el archivo ya estuvo en el servidor de todas formas.

Validar antes de la transacción resuelve además §3.5 sin discutirlo: para cuando se abre la
transacción, ya se sabe que todo va a entrar.

### 4.5 «Cómo estaba antes» (D7)

Se genera **la misma exportación de §4.1** justo antes de aplicar, y se ofrece en la pantalla de
resultado. No hace falta nada más: es el mismo código, y el archivo sirve para volver atrás subiéndolo
de nuevo.

### 4.6 Los códigos y Excel (§3.8)

Criterio de aceptación, no detalle. Elegir la técnica, aplicarla en la exportación, y hacer que la
importación acepte **el código limpio y el envuelto** --porque el archivo puede no haber salido de
nosotros.

---

## 5. Cobertura de pruebas

Hoy existen pruebas de la unidad de medida en la importación. **No hay pruebas de la exportación**
(no existe) ni del emparejamiento.

Cuidado con §3.7 al escribirlas: los fixtures de artículos **deben borrar antes de insertar**, o cada
prueba pasa aislada y fallan en conjunto.

Lo mínimo que este trabajo tiene que traer:

**El viaje de ida y vuelta**
- Exportar e importar sin tocar nada **no cambia ni una fila**. Es la prueba que define el trabajo.
- Un artículo en kilogramo **sigue en kilogramo** tras el viaje.
- Un artículo **sin código** (los 18 de Casaletto) sobrevive al viaje por su `Id`.
- Un código EAN de 13 dígitos **vuelve idéntico** (§3.8).

**Que no destruya**
- Un archivo con **solo la columna de precios** llena no borra los nombres.
- Un precio de **0** y un `reorder_level` de **0** se guardan como 0, no se descartan (§4.3).
- Subir el mismo archivo dos veces **no duplica**.

**El emparejamiento**
- Código que existe → actualiza, y **no** crea uno nuevo.
- Código que no existe → crea.
- Código repetido en dos artículos vivos → **error de fila**, y no se toca ninguno (D6).
- `Id` y código que apuntan a artículos distintos → **manda el `Id`** (§4.2).

**La vista previa**
- Un archivo con una fila mala **no escribe absolutamente nada** hasta confirmar.
- Los conteos del plan coinciden con lo que de verdad ocurre al aplicar.

---

## 6. Compuerta antes de producción

La de siempre en este repositorio:

- Suite completa verde contra MariaDB real.
- Probado en staging con los dos negocios montados.
- Verificación en producción **en solo lectura**.
- Despliegue **después de las 22:00 hora Colombia**, salvo autorización explícita en el momento.
- **Casaletto se comporta idénticamente.** Es el negocio que está vendiendo, y tiene 284 artículos
  con precios reales que este trabajo podría tocar.
