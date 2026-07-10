_Lo siguiente es una mejora propuesta para OSPOS. Próximamente en un pull request cerca de ti._

En la industria de servicios y reparaciones existe el concepto de Orden de Trabajo (Work Order). Una orden de trabajo es una fase de la vida de una venta. Es una forma de comunicar a quienes realizan el trabajo de servicio y reparación lo que el cliente desea, y también es una forma de comunicar de vuelta al representante de ventas y al cliente lo que realmente sucedió. La orden de trabajo normalmente se encuentra dentro de una funda protectora de plástico.

Este cambio permitirá que el vendedor prepare una orden de trabajo y, cuando el trabajo esté completo, la orden de trabajo se actualiza para reflejar lo que realmente ocurrió y luego la venta se factura.

## Requisitos/Funcionalidades

* Introducir un nuevo tipo de documento de orden de trabajo que se pueda usar para llevar el control de materiales, piezas y servicios realizados en trabajos de reparación o servicio.
* Poder agregar un pago de Depósito en Efectivo (Cash Deposit) o un pago de Depósito con Crédito (Credit Deposit) contra una orden de trabajo.
* Conservar la orden de trabajo de modo que, si el cliente cancela la orden de trabajo, se pueda registrar el motivo en el comentario de la venta.

## Definiciones/Estructuras

La funcionalidad de Orden de Trabajo no introduce ninguna tabla nueva. Sin embargo, sí hace lo siguiente:

* Agrega un nuevo campo `sales_type` a la tabla `sales`. Los valores del tipo de venta son (0=Venta POS, 1=Factura, 2=Orden de Trabajo, 3=Cotización, 4=Devolución)
* Agrega un nuevo valor de `sale_status` llamado CANCELADO (CANCELED). Ahora los valores son (0=Completo, 1=Suspendida, 2=Cancelada)
* Agrega 3 nuevos valores de configuración
  * work_order_enabled
  * work_order_format
  * last_used_work_order_number
* Agrega 2 nuevos tipos de pago
  * Depósito en Efectivo
  * Depósito con Crédito

## Reglas/Restricciones

* Una nueva venta se puede crear como una orden de trabajo.
* Cualquier venta suspendida se puede convertir en una orden de trabajo.
* Por defecto los precios no se incluirán en la orden de trabajo impresa, sin embargo el usuario tiene la opción de incluir los precios en la orden de trabajo impresa.
* Una orden de trabajo es esencialmente una venta suspendida que se puede recuperar en cualquier momento para actualizarla con más información.
* Las órdenes de trabajo no deben eliminarse físicamente porque queremos poder reportar por qué se canceló la orden de trabajo. El motivo de la cancelación debe anotarse en el campo de comentario de la venta.
* Una vez que el trabajo se completa en una orden de trabajo, esta se puede recuperar desde su estado "suspendida", actualizarse y luego facturarse.

## Operaciones


## Configuración

* Para habilitar las órdenes de trabajo, ve a Config --> tabla de Factura, y desplázate hacia abajo hasta donde se encuentra la casilla etiquetada Soporte de Orden de Trabajo (Work Order Support) y márcala.
* Este cambio introduce un nuevo token llamado {WSEQ:9} que sigue la misma convención que los tokens ISEQ y QSEQ. El valor predeterminado para el número de orden de trabajo aprovecha ese token, y el número inicial de orden de trabajo se puede establecer en el campo Último Número de Orden de Trabajo Usado.
