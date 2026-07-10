
[← Volver a la Guía de Uso](Getting-Started-usage) | [Inicio](Home)

---

## Ingreso rápido de pedidos

Ofrece una forma de ingresar rápidamente un grupo de artículos relacionados en un pedido. El usuario selecciona el kit y todos los artículos asociados al kit se agregan al pedido.

No es necesario crear ningún artículo para representar el kit. Solo será necesario agregar a la tabla de artículos los artículos que componen el kit.

## Artículos empaquetados

Esto permite que un cliente compre un paquete especial de artículos (como una promoción de paquete de fiesta de cumpleaños) que se agrupan juntos. El usuario puede ingresar el identificador del paquete y el paquete se agrega al pedido de venta junto con todos los artículos que lo componen.

Se crea un artículo en la tabla de artículos para representar el kit.

Los artículos empaquetados pueden estar preempacados y mantenerse en existencia como cualquier otro artículo. La cantidad de cada artículo dentro del paquete se puede seguir rastreando como si no estuviera dentro del paquete.

Cuando se ingresa el paquete, se muestra el número de paquetes que están *prearmados* y, si no hay disponibles (pero los artículos individuales muestran existencia suficiente), entonces el usuario sabe que se puede armar un paquete "justo a tiempo".

### 1.3 **Ventas de servicios agrupados**

Esta funcionalidad permite que un usuario venda un servicio, el cual, por supuesto, es un artículo sin existencia (a menos que se quiera limitar la cantidad de estos servicios vendidos porque es un producto de pérdida para atraer clientes).

El servicio puede incluir una lista de tareas que se incluirán con el servicio. También puede incluir una lista de materiales con existencia que se consumen como parte del servicio. Al usuario se le puede cobrar por cada tarea y material individual, o el precio del servicio puede establecerse una sola vez para cubrir todas las tareas y materiales, o el precio del servicio puede establecerse una sola vez para cubrir todas las tareas del servicio pero los materiales se pueden cobrar por separado.

### 1.4 **Descuentos**

Los descuentos ahora pueden ser por porcentaje o por monto fijo, y el descuento se aplica a cada artículo individual agregado al pedido. Las fuentes de descuentos provienen de la configuración del sistema, lo que significa que se aplica a cada artículo agregado a un pedido, o de la configuración del cliente, o de la configuración del kit.

Cuando el descuento es por porcentaje, ese porcentaje de descuento se agrega a cada artículo agregado al pedido al que se le asigna un precio. Cuando el descuento es por monto, el monto del descuento se vuelve un poco más complicado. El supuesto es que si el descuento es por monto, entonces NO debería aplicarse a cada artículo. En cambio, el monto del descuento se aplica a cada artículo hasta que el monto se agota. Así que, en efecto, se distribuye entre los artículos. Esto realmente solo parece lógico si se está fijando el precio a nivel de kit, porque entonces el monto del descuento se aplicaría al kit. Si el precio del kit es esencialmente un cargo por el empaquetado, el monto del descuento podría ser mayor que el precio del artículo de kit. Esto hará que el monto del descuento para el artículo de kit no pueda ser mayor que el precio, por lo que será igual al precio (dejando esencialmente el artículo con precio cero). El resto del monto del descuento se aplicará al siguiente artículo. Esto se repite luego para el siguiente artículo hasta que el monto del descuento se agote por completo.


## 2. Definiciones/Estructura

*Los Kits de Artículos* representan una colección de artículos con existencia o artículos de mano de obra.

* *Nombre del kit de artículos* es el nombre interno usado para el kit. No se usará en la factura ni en el recibo. Cuando se crea el kit, el *nombre del kit de artículos* se cargará desde el *artículo del kit de artículos* seleccionado.

* *Descripción del kit de artículos* es la descripción del kit para uso interno únicamente. No aparecerá en las facturas ni en los recibos.

* *Artículo del kit* es el número de artículo del artículo en la tabla `item` que representa el kit. Este es un campo opcional y solo es necesario si se requiere que el *artículo del kit* aparezca listado en la factura o el recibo, o si se necesita rastrear la cantidad de kits que se tienen disponibles para la venta.

* *Descuento del kit de artículos* es el monto de descuento que se aplicará a todos los artículos del kit. Este descuento solo se aplica si el *descuento del kit de artículos* es mayor que el descuento del cliente.

* *Método de fijación de precios del kit* es un código que identifica cómo se deben aplicar los precios a los artículos del kit de artículos cuando se agregan a la tabla `sales_items`. Los valores son (0=todos los artículos del kit de artículos tienen precio basado en el precio encontrado en la tabla `items`, lo cual incluye al propio artículo del kit (si existe y si tiene un precio asignado), 1=El kit tiene precio basado en el precio del *artículo del kit*, 2=El kit tiene precio basado en el precio del *artículo del kit* más el precio de cualquier artículo con existencia que esté incluido en el kit)

* *Imprimir artículos del kit* es un código que identifica si los artículos con precio cero deben incluirse o no en el recibo o factura impresos. Los valores son (0=Incluir todos los artículos del kit de artículos en el recibo y la factura, 1=Incluir solo los artículos con precio en el recibo o la factura, 2=Incluir solo el *artículo del kit* en el recibo o la factura.)

*Los Artículos del Kit de Artículos* representan un artículo individual que forma parte de un kit. El mismo artículo puede ser componente de múltiples kits.

*Un Kit de Artículos* también puede combinar artículos tanto de *existencia* como de *mano de obra*.

Si *un Kit de Artículos* puede inventariarse, entonces debe hacer referencia a una entrada en la tabla *Artículo* que tenga existencia.

Si *un Kit de Artículos* debe listarse como un artículo en una venta, entonces debe hacer referencia a una entrada en la tabla *Artículo*.

Si *un Kit de Artículos* no representa un artículo que esté en la tabla *Artículo*, entonces solo sirve para facilitar el pedido y la recepción de una colección de artículos a la vez. El kit en sí no aparecerá en el reporte de pedidos ni de recepciones.

*Un Kit de Artículos* puede representar un grupo de artículos inventariados que se mantienen en inventario por separado pero que pueden venderse como un conjunto, posiblemente con un descuento (como un paquete de fiesta).

*Un Kit de Artículos* también puede representar una colección de servicios y/o materiales inventariados usados como parte de los servicios incluidos en el kit.

