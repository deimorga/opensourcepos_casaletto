# Diseño técnico — Carga masiva de artículos: crear y actualizar

> **Estado a 2026-08-31: requerimiento planteado, sin escribir una línea de código.**
>
> Alcance de negocio en `docs/Funcional/carga-masiva-de-articulos.md`. **Leerlo primero**: trae cinco
> decisiones abiertas (§4), y la 4.4 puede destruir datos si se elige mal.
>
> **Se trabaja en paralelo, en otra conversación**, junto con
> `docs/Tecnico/gestion-de-plataforma-y-negocios.md`. La línea del cliente supermercado vive en
> `docs/*/venta-por-peso-y-hardware-de-caja.md` y **no se toca desde aquí**.

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

## 2. Los cuatro hechos que definen el problema

### 2.1 La plantilla no trae datos

`generate_import_items_csv()` concatena encabezados y retorna. No consulta la tabla de artículos.

### 2.2 Crear vs actualizar depende del `Id`, y sólo de él

```php
$itemId   = (int) $row['Id'];
$isUpdate = ($itemId > 0);
```

Un archivo sin `Id` **crea siempre**. Reimportar el mismo archivo duplica el catálogo entero.

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

## 4. Orden sugerido

1. **Exportación completa** en el formato de importación, con `Id` y con `unit_of_measure`. No toca
   el camino de escritura, así que es el de menor riesgo — y resuelve solo el caso de Paraíso.
2. **Informe de resultado** de la importación: creados, actualizados, fallidos con número de fila.
3. **Validar antes de aplicar** y emparejar por código de artículo. Aquí viven las decisiones 4.1 a
   4.4; no empezar sin ellas resueltas.
4. **Completar la edición masiva** con la unidad de medida.

---

## 5. Cobertura de pruebas

Hoy existen pruebas de la unidad de medida en la importación. **No hay pruebas de la exportación**
(no existe) ni del emparejamiento. Lo mínimo que debería traer este trabajo:

- Subir el mismo archivo dos veces **no duplica**.
- Un archivo con solo la columna de precios **no borra los nombres**.
- Un artículo en kilogramo **sigue en kilogramo** tras exportar y reimportar.
- Una fila mala se reporta **con su número** y no deja el catálogo a medias.

---

## 6. Compuerta antes de producción

La de siempre en este repositorio:

- Suite completa verde contra MariaDB real.
- Probado en staging con los dos negocios montados.
- Verificación en producción **en solo lectura**.
- Despliegue **después de las 22:00 hora Colombia**, salvo autorización explícita en el momento.
- **Casaletto se comporta idénticamente.** Es el negocio que está vendiendo, y tiene 284 artículos
  con precios reales que este trabajo podría tocar.
