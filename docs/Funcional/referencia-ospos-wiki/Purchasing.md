
[← Volver a la Guía de Uso](Getting-Started-usage) | [Inicio](Home)

---

Esta página organiza y reúne las especificaciones de diseño para la funcionalidad de compras (purchasing).

## Requisitos/Funcionalidades

_Por favor agrega cualquier funcionalidad que necesites que sea compatible con el módulo de compras, con toda la explicación que consideres necesaria. Recuerda que los términos usados en un país o específicos de una industria podrían necesitar más detalle para que el desarrollador los entienda mejor._

* Debe poder registrar compras realizadas por teléfono directamente al proveedor. En otras palabras, el estado ya es "pedido realizado con el proveedor".
* Cuando se recibe el inventario, la orden de compra se puede ubicar por id de proveedor, nombre de proveedor, fecha de vencimiento, o nombre de artículo.
* Se puede crear una orden de compra manualmente.
* Se puede generar automáticamente una orden de compra para un proveedor seleccionado basada en bajo inventario.
* Se puede imprimir una orden de compra.
* Se puede enviar por correo electrónico una orden de compra a la dirección de correo del proveedor.
* Se puede ajustar una orden de compra que ya fue enviada al proveedor, basándose en la recepción anticipada real.
* El usuario debe poder subir un documento PDF que pertenece al proveedor pero que es referenciado por la orden de compra, ya sea como confirmación de pedido o como factura.
* Se puede generar un reporte de las diferencias entre lo pedido y lo real.
* Se puede generar e imprimir un documento de recepción (también conocido como nota de recepción de mercancía) a partir de la orden de compra.
* El registro de una recepción asociada a una orden de compra debe actualizar la orden de compra con la cantidad real recibida.
* Cuando se recibe una factura por el envío, debemos poder conciliar la factura con la orden de compra, y el costo del artículo tomado de la factura se almacena en el artículo de la orden de compra como costo real, y el costo real se actualizará (por defecto) al costo del artículo.
* La aplicación de una factura cambiará el estado de una orden de compra a completado.
* La orden de compra debe tener funcionalidad de solicitante-aprobador (control de acceso de usuario), antes de que el sistema marque el estado como solicitado. Debe requerir que un administrador (otro usuario que tenga el rol de aprobador) la apruebe. Y este esquema de solicitante-aprobador debe ser configurable, es decir, si está habilitado requerirá iniciador y aprobador. Y si está deshabilitado, no se necesitará solicitar aprobación.
* En la página de reportes, deben estar disponibles múltiples filtros, es decir, basados en proveedor y/o fecha y/o artículos, etc.
* En el reporte debe mostrarse quién inició/aprobó la orden si la funcionalidad de solicitante-aprobador está habilitada.
* La orden de compra debe tener una página de reportes diferente a la de Recepción.
* La orden de compra debe tener control de acceso de usuario
* [ai] Se desearía una funcionalidad para cargos adicionales como flete, etiquetado previo a precios, regalías, etc., que se distribuyan entre el costo de los artículos según el volumen/peso del artículo, el monto (costo), o la cantidad.

**[daN4cat]** Ten en cuenta que, al igual que las compras, existen devoluciones que resultan en una Nota de Crédito. Esto es bastante común. Los productos defectuosos tienen una solicitud de devolución, un retiro y luego quedan pendientes de la emisión de la nota de crédito por parte del proveedor.

**[daN4cat]** Ten en cuenta que la compra podría realizarse en diferentes monedas; no es inusual aquí en el Reino Unido comprar mercancía de la zona de la UE y recibir facturas en euros. Luego, al momento del pago, tienes la conversión y sabes cuánto pagaste en libras esterlinas (GBP). Eso es para efectos de rastrear el gasto por proveedor.

### Reglas de Recepción

* Al momento de recibir, el usuario debe poder proporcionar cualquier información faltante del artículo, como código UPC, id de proveedor, o número o id de vendedor (aquí "Vendedor" representa al fabricante del producto, que se usa en mi entorno para identificar de forma única el producto, ya que no todos los artículos tienen códigos UPC y el id de artículo del proveedor no es confiablemente único.)

### Reglas de Cuentas por Pagar


## Preguntas

* Hubo un requisito solicitado para almacenar una factura en formato pdf con términos de pago y fecha límite. No tengo claro si esto es un requisito para OSPOS o para un sistema de almacenamiento de documentos. _Pregunta respondida e incorporada en el documento_

* [daN4cat] Hablamos de "vendor" pero OSPOS tiene el concepto de Proveedor (Supplier), con Agencia como un campo. ¿Es lo mismo? _Respuesta: Sí. Se han hecho las correcciones. Sin embargo, no tengo claro qué es una Agencia en este contexto. Supuse que era algo europeo. ¿Alguien puede explicarme el rol entre un proveedor y una Agencia?_

* [daN4cat] Existe un concepto de confirmación de pedido que podría no coincidir con tu pedido original debido a que descontinuaron artículos, o cambiaron artículos por uno más nuevo, etc. Esta información entrante necesita almacenarse, ya que podría llegar como pdf por correo electrónico del proveedor. La factura del proveedor necesita almacenarse, ya que puede llegar como pdf o puede ser escaneada a pdf. _Respuesta: Esto se ha agregado a los requisitos._

* [daN4cat] Un punto a considerar aquí es que los códigos de barras de artículos nuevos no se conocen hasta que se recibe la mercancía. _Respuesta: esto se ha incorporado en los requisitos bajo un nuevo tema de Reglas de Recepción, que representará los cambios al proceso de recepción que se necesitarán hacer._

* [daN4cat] Recibimos las confirmaciones de pedido y facturas de los proveedores por correo electrónico en formato pdf; esos archivos deberían adjuntarse a la orden de compra (PO). Además, el monto de pago, moneda, forma de pago y fecha límite deberían agregarse en el momento en que se agrega la factura a la PO, una vez que se recibe la mercancía. Así, la notificación de la fecha límite de pago queda marcada o visible desde una vista de oficina. Como se dijo, deberíamos gestionar el ciclo de vida de la factura del proveedor con fechas de vencimiento de pago hasta que se pague. También deberíamos considerar casos de "Venta o Devolución" (Sale or Return), por lo que la factura podría emitirse con el propósito de formalizar el movimiento de mercancía (por ejemplo, por razones fiscales y de seguros) pero podría pagarse después. _Respuesta: Se comenzará a incorporar un componente simple de Gestión de Cuentas por Pagar en el sistema de compras. Se está acabando el tiempo por el momento, así que se retomará esto muy pronto._

## Definiciones/Estructura

_Por favor lista los términos específicos de la industria con su explicación. Esta sección también será la base embrionaria de la base de datos._

### Definiciones

* **Distribuidor** - OSPOS está centrado en el distribuidor, por lo que los negocios que proveen inventario a una tienda OSPOS siempre se clasificarán como distribuidor (aunque técnicamente podrían ser un fabricante con el que estamos tratando directamente). Un distribuidor en OSPOS es típicamente un distribuidor mayorista de artículos que son fabricados y vendidos por otros proveedores (vendors).
* **Estado de la Orden de Compra** - Este es el estado de la orden de compra
  * **Abierta (Open)** - El iniciador de la orden de compra está preparando la orden de compra.
  * **Preparada (Prepared)** - El iniciador de la orden de compra ha completado su trabajo.
  * **Aprobada (Approved)** - El aprobador de la orden de compra ha dado su visto bueno a la orden de compra y ya puede enviarse.
  * **Enviada (Submitted)** - El emisor de la orden de compra ha impreso y/o enviado por correo electrónico la orden de compra al proveedor.
  * **Parcial (Partial)** - Uno o más de los artículos de la orden de compra han sido recibidos, pero otros artículos pedidos siguen en pedido pendiente o su estado de entrega aún no se ha determinado.
  * **Cumplida (Fulfilled)** - Todos los artículos de la orden de compra han sido recibidos, o se ha determinado que el artículo no será entregado.
  * **Facturada (Invoiced)** - Se ha recibido una factura por uno o más de los artículos y la orden de compra ha sido parcialmente conciliada.
  * **Completa (Complete)** - Todos los artículos de la orden de compra han sido conciliados con una factura recibida del proveedor (vendor).
  * **Cancelada (Canceled)** - No se espera que ningún artículo de la orden de compra sea cumplido.

### Estructura

* **Requisición (Requisition)** - Una requisición es un artículo para el cual se necesita generar una orden de compra. Establece el vínculo entre la demanda y el pedido real, convirtiéndose eventualmente en el detalle de soporte de la orden de compra.
  * **Id de Requisición** - El identificador único de esta requisición.
  * **Origen de la Requisición**, int(2) - Un código que designa qué está generando la demanda de este artículo. (0-Reposición de Inventario, 1=Orden de Trabajo, 2=Pedido Justo a Tiempo, 3=Compra General)
  * **Id de Venta**, int(10) - Si la requisición se genera a partir de una venta (es decir, una orden de trabajo), este es el identificador único de la venta.
  * **Número de Línea** - Si la requisición se genera a partir de una venta (es decir, una orden de trabajo), este es el identificador único de la venta.
  * **Id de Orden de Compra** - El identificador único de la orden de compra. Se agrega como clave foránea cuando la requisición se asigna a una orden de compra.
  * **Solicitado Por** - El id de empleado de la persona que generó la requisición.
  * **Id de Artículo**
  * **Código de Proveedor** - El número de artículo que se usará para hacer pedidos al vendedor (vendor). Puede ser el Id de Artículo, UPC, ID de Proveedor, o Id de Vendedor.
  * **Descripción**, varchar(30), permite nulo
  * **Número de Serie**, varchar(30), permite nulo
  * **Línea**, int(3)
  * **Cantidad de Pedido Original**, decimal(15,3)
  * **Cantidad de Pedido Actual**, decimal(15,3)
  * **Cantidad Recibida**, decimal(15,3)
  * **Cantidad Cancelada**, decimal(15,3)
  * **Precio de Costo del Artículo**, decimal(15,2)
  * **Precio Unitario del Artículo**, decimal(15,2)
  * **Ubicación del Artículo**, int(11)
  * **Cantidad de Pedido**, decimal(15,3), por defecto 1

* **Orden de Compra**
  * **Id de Orden de Compra**, int(10) - El identificador único se asigna de forma incremental en el momento en que se guarda la orden de compra. Cuando la orden de compra se genera automáticamente para un proveedor, el id de orden de compra se genera cuando se guarda el documento de recepción.
  * **Número de Orden de Compra** - Este es el número de documento que asigna el sistema cuando se guarda o se crea automáticamente la orden de compra. Usará el mismo generador de números automáticos basado en tokens que usa el sistema de ventas para facturas y cotizaciones.
  * **Estado de la Orden de Compra**, tinyint(2) - Este es el estado de la orden de compra. (0-Nueva, 1-Abierta, 2-Parcial, 3-Cumplida, 4-Facturada, 5-Completa, 6-Cancelada)
  * **Tipo de Orden de Compra**, tinyint(2) - Este es el tipo de la orden de compra. (0-Estándar, 1-Reposición).
  * **Id de Proveedor**, int(10) - Este es el identificador único de la empresa (proveedor) que cumple el pedido.
  * **Vendedor del Proveedor**, varchar(32) - Este es el nombre del vendedor del proveedor, para que su back office sepa a quién consultar internamente sobre la orden de compra (P.O.).
  * **Empleado**, int(10) - Este es el id del empleado que guardó la orden de compra.
  * **Comentario**, text - Este es un comentario que se puede ingresar en el momento en que se genera la orden de compra o se guarda la recepción.
  * **Referencia**, varchar(32) - Este es un campo para ingresar un número de documento proporcionado por el proveedor que podría usarse para discutir qué fue enviado por el vendedor.
  * **Cuándo se Compró**, timestamp - Esta es la fecha y hora en que se generó la orden de compra
  * **Preparado Por** - El id de empleado de la persona que consolidó las requisiciones y preparó la orden de compra.
  * **Aprobado Por** - El id de empleado de la persona que aprobó la orden de compra para su envío al proveedor.
  * **Enviado Por** - El id de empleado de la persona que imprimió y/o envió por correo electrónico la orden de compra al proveedor.


### Tablas Existentes que Podrían Estar Involucradas

* **Recepciones (Receivings)**
  * **Id de Recepción**, int(10) - El identificador único de recepción se asigna de forma incremental en el momento en que se guarda la recepción. Cuando el documento de recepción se genera a partir de la(s) orden(es) de compra, el id de recepción se genera cuando se guarda el documento de recepción.
  * **Id de Proveedor**, int(10) - Este es el identificador único de la empresa (proveedor) que cumple el pedido.
  * **Empleado**, int(10) - Este es el id del empleado que guardó la recepción o generó el documento de recepción.
  * **Comentario**, text - Este es un comentario que se puede ingresar en el momento en que se genera el documento de recepción o se guarda la recepción.
  * **Tipo de Pago**, varchar(20) - Creo que esto probablemente quedará obsoleto con la introducción de la funcionalidad de compras
  * **Referencia**, varchar(32) - Este es un campo para ingresar un número de documento proporcionado por el proveedor que podría usarse para discutir qué fue enviado por el vendedor.
  * **Hora de Recepción**, timestamp - Esta es la fecha y hora en que se
  * **Id de Orden de Compra**, int(10), NUEVO - Esta es la orden de compra de la que se genera el documento de recepción. Si el documento no se genera a partir de un documento de recepción, el valor será nulo.


* **Artículos de Recepciones (Receivings Items)**

  * **Id de Recepción**, int(10) - El identificador único de recepción se asigna de forma incremental en el momento en que se guarda la recepción. Cuando el documento de recepción se genera a partir de la(s) orden(es) de compra, el id de recepción se genera cuando se guarda el documento de recepción.
  * **Id de Artículo**
  * **Descripción**, varchar(30), permite nulo
  * **Número de Serie**, varchar(30), permite nulo
  * **Línea**, int(3)
  * **Cantidad Comprada**, decimal(15,3)
  * **Precio de Costo del Artículo**, decimal(15,2)
  * **Precio Unitario del Artículo**, decimal(15,2)
  * **Ubicación del Artículo**, int(11)
  * **Cantidad de Recepción**, decimal(15,3), por defecto 1

* **Artículos (Items)** _Solo se muestra un subconjunto de las propiedades relevantes del artículo._
  * **Id de Artículo**, int(10)
  * **Nombre del Artículo**, varchar(255)
  * **Número de Artículo**, varchar(255), UPC
  * **¿Personalizado?**, varchar(25), el identificador del artículo para una orden de compra puede ser el número de artículo (que es el identificador UPC), o uno de los campos personalizados que representan el id de artículo del proveedor.
  * **Cantidad de Recepción**, decimal(15,3) - Cuando se genera una orden de compra, la cantidad pedida tomará por defecto el valor de este campo. Por defecto se actualizará con la última cantidad recibida
  * **Nivel de Reorden**, decimal(15,3) - Cuando se genera un documento de recepción, se agregarán artículos al documento de recepción cuando la cantidad total en todas las ubicaciones sea igual o menor al nivel de reorden.
  * **Tipo de Stock**, tinyint(2) - Solo los artículos de stock serán repuestos.
  * **Id de Proveedor**, int(11) - Cuando se selecciona un proveedor para que se le genere una orden de compra
  * **Precio de Costo**, decimal(15,2)
  * **Precio Unitario**, decimal(15,2)

## Reglas y Restricciones

## Operaciones

## Configuración
