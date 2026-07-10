[← Volver a la Guía de Uso](Getting-Started-usage) | [Inicio](Home)

---

Permite a los empleados cargar productos en el inventario. Comience haciendo clic en el botón Artículos. Esto cargará su lista de artículos.

## Acceso

Puede **agregar, eliminar, modificar y administrar** artículos usando el [módulo de Inventario](Getting-Started-usage#3-inventory) y usarlos para ventas o devoluciones en el [módulo de Ventas](Getting-Started-usage#4-sales).

El [módulo de Inventario](Getting-Started-usage#3-inventory) muestra una lista de artículos recientes y entradas de kits de artículos.

El [módulo de Ventas](Getting-Started-usage#4-sales) cuenta con un campo de entrada para escanear códigos de barras o buscar artículos/kits por nombre o número de código de barras. Se presenta la lista y usted puede elegir los artículos para la venta actual.

## Importación de artículos

Es posible realizar importaciones CSV de artículos, tanto para **agregar artículos nuevos** como para **actualizar artículos existentes**. El archivo debe seguir el formato CSV estándar, incluyendo una fila de encabezado. Se recomienda descargar la Plantilla de Importación CSV cada vez (Artículos > Importar CSV > "Descargar plantilla de importación CSV (CSV)").

Si la columna **Id** está completada con el `item_id`, el importador **actualizará** el artículo, pero solo reemplazará los atributos y campos donde haya un valor. Los campos vacíos se ignoran en lugar de eliminar los datos existentes. Para las operaciones de actualización, no hay columnas obligatorias como sí las hay en las importaciones.

Si la columna **Id** se deja en blanco, el importador trata al artículo como nuevo. Verifica el código de barras contra los códigos de barras existentes y, si la opción de **sin códigos de barras duplicados** está habilitada, generará un error si se encuentra un duplicado.

Los errores se registran en el registro de errores. **Si ocurre un error, no se importará ninguna de las filas.**
