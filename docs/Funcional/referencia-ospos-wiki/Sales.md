
[← Volver a la Guía de Uso](Getting-Started-usage) | [Inicio](Home)

---

## Funcionalidades de Ventas

## Definiciones/Estructura
![sales](https://github.com/jekkos/opensourcepos/blob/master/design/sales.png)

Aún no está incluido en el diagrama de entidades el siguiente campo, que fue agregado tanto a la tabla `sales_items` como a la tabla `sales_suspended~items`. El campo es `print_option`. Los valores son...
- `0` - Incluir el artículo de venta en la lista de artículos al imprimir la factura y el recibo.
- `1` - Si el precio es cero, entonces excluir el artículo de venta de ser incluido en el detalle del recibo y la factura.
- `2` - Excluir siempre el artículo de venta de ser incluido en el detalle del recibo y la factura.


## 3. Reglas de Operación

### Ventas

- En el punto de venta, todos los *artículos de inventario* (stock items) se verifican para comprobar si hay cantidad suficiente disponible para satisfacer la venta. Si el *Tipo de Artículo* está configurado como *sin inventario* (non-stock), entonces la validación no se realizará.

### Impresión de Factura

### Cotizaciones

Los documentos de cotización todavía no son compatibles. Se documentan aquí para solicitar retroalimentación. El Issue #352 es la base de la mayoría de las especificaciones de diseño documentadas aquí.

Actualmente las facturas se pueden imprimir marcando la casilla "Imprimir Factura" durante el registro de la venta. Sin embargo, esta opción solo está disponible cuando la venta está completa y el pago se ha realizado en su totalidad.


Con *Factura* seleccionada, la casilla etiquetada "Crear Factura" quedará marcada y estará disponible un botón etiquetado "Factura" (entre "Suspender" y "Cancelar"). En cualquier momento el usuario puede hacer clic en el botón Factura, lo cual completará la venta e imprimirá la factura. La información adicional que normalmente solo está disponible cuando el pago está completo estará disponible para su uso. Cuando se presiona el botón *Factura*, la venta se completará y se imprimirá el documento de factura. Esto "Completará" la venta como una venta de tipo Factura en lugar de una venta de tipo Recibo.

Con *Cotización* seleccionada, la casilla etiquetada "Crear Factura" no estará marcada y el botón central se etiquetará como "Cotización" en lugar de "Factura". (Si el usuario desea omitir la creación de una Cotización e ir directamente a la creación de una Factura, solo necesita seleccionar el botón "Crear Factura"). Después de registrar la venta, cuando el usuario presione el botón "Cotización", la venta quedará suspendida y se imprimirá un documento de cotización.

Incluso con "Factura" o "Cotización" seleccionada, el comportamiento estándar de "Recibo" se logra presionando el botón "Completar", que sigue estando disponible.
 
## 4. Configuración

### Facturación

**Habilitar Facturación** - Si está marcada, estará disponible la opción de imprimir facturas en lugar de un recibo al completar una venta.

**Modo de Registro Predeterminado** - [PROPUESTO] Cuando el usuario vuelve al Registro (Register), este tomará por defecto uno de los siguientes modos.
- *Venta* - Cuando la venta está completa y el pago se recibió en su totalidad, se imprimirá un recibo de venta.
- *Venta por Factura* - Cuando la venta está completa, sin importar el monto pagado, se imprimirá una factura.
- *Cotización* - Se utiliza para preparar un documento de cotización. Al completarse, la transacción de venta derivada de la cotización queda suspendida y puede reanudarse (unsuspend) y procesarse ya sea como "Venta" o como "Venta por Factura".

**Formato de Factura de Venta** - Así es como se formateará el número de factura cuando se le asigne uno. Existen tres códigos que se pueden proporcionar y que se traducirán a un valor en particular. Normalmente solo se usa uno.
- *$CO o {CO:9}* - Se reemplazará con el número de registros de venta que tienen un número de factura.
- *$YCO o {YCO:9}* - Se reemplazará con el número de registros de venta reiniciando el conteo cada año.
- *$SCO o {SCO:9}* - Es un conteo del número de registros de venta suspendidos que tienen un número de factura.
- *{ISEQ:9}* - Usará el valor de configuración "Último número de factura usado" para construir la factura cuando la venta se complete. Al completarse la venta, el valor se incrementa y reemplaza a {ISEQ}. Si el número de factura debe tener una cantidad fija de dígitos, la cantidad de dígitos a usar se puede indicar con el formato {ISEQ:9}, donde el dígito numérico después de los dos puntos representa la cantidad de dígitos que se deben devolver. Puede tener hasta 9 dígitos de largo, donde 0 indica que el número debe tener la longitud necesaria para contener el valor completo, y {xxx:0} y {xxx} se tratarán de la misma manera.
- *%y* - El año de dos dígitos de la fecha de venta reemplaza el valor %y. De hecho, existen numerosos tokens "%" basados en la fecha y hora actual del sistema. Están listados en http://php.net/manual/en/function.strftime.php

Por ejemplo, si el valor es "INV-%y{ISEQ:6}", se generará el número de factura INV-17000032 si, antes de la generación, el valor del último número de factura usado es 31 y el año de la venta es 2017.

**Plantilla de Correo de Factura**

**Comentario de Factura** - Este es el texto de un comentario que se agregará automáticamente a cada factura que se genere.

**Secuencia de Líneas de Factura** - Las líneas de artículos en la factura se pueden imprimir en cuatro secuencias. La capacidad de controlar esto se configura a nivel global. Las opciones son:

- *Entrada (Entry)* - Listará los artículos en la secuencia en la que fueron ingresados. El primer artículo ingresado se listará primero. Este es el valor predeterminado.
- *Agrupar por Tipo* - Agrupará los artículos por tipo de inventario. Los artículos sin inventario (non-stock) se listarán primero, seguidos por los artículos con inventario (stock). Dentro de cada grupo, los artículos se ordenarán por nombre o descripción alternativa.
- *Agrupar por Categoría* - Agrupará los artículos por categoría. Las categorías se listarán por nombre de categoría. Dentro de una categoría, los artículos se ordenarán por nombre o descripción alternativa.

**Último número de factura usado** - [Propuesto] Contendrá el último número usado para construir un número de factura donde {ISEQ:9} es parte del formato.

**Formato de Cotización de Venta** - Así es como se formateará el número de cotización cuando se le asigne uno. Se pueden usar los siguientes códigos para asignar un valor en particular. Normalmente solo se usa uno, pero se pueden combinar.
- *$CO o {CO:9}* - Se reemplazará con el número de registros de venta que tienen un número de factura.
- *$YCO o {YCO:9}* - Se reemplazará con el número de registros de venta.
- *$SCO o {SCO:9}* - Es un conteo del número de registros de venta suspendidos que tienen un número de factura.
- *{QSEQ:9}* - [Propuesto] Usará el valor de configuración "Último número de cotización usado" para construir la factura cuando la venta se complete. Al completarse la venta, el valor se incrementa y reemplaza a {QSEQ:9}. Si el número de cotización debe tener una cantidad fija de dígitos, la cantidad de dígitos a usar se puede indicar con el formato {QSEQ:0}, donde la cantidad de ceros entre corchetes indica cuán largo debe ser el número. Puede tener hasta 9 dígitos de largo, donde 0 indica que el número debe tener la longitud necesaria para contener el valor completo, y {xxx:0} y {xxx} se tratarán de la misma manera.
- *%y* - El año de dos dígitos de la fecha de venta reemplaza el valor %y. De hecho, existen numerosos tokens "%" basados en la fecha y hora actual del sistema. Están listados en http://php.net/manual/en/function.strftime.php

Por ejemplo, si el valor es "Q%y{QSEQ:6}", se generará el número de cotización Q17000032 si, antes de la generación, el valor del último número de factura usado es 31 y el año de la venta es 2017.

**Último número de cotización usado** - [Propuesto] Contendrá el último número usado para construir un número de cotización donde {QSEQ:9} es parte del formato.

---

**Este es un trabajo en progreso. Siempre se agradece la retroalimentación.**