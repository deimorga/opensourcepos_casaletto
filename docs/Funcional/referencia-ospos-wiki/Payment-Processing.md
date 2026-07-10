[← Volver a la Guía de Uso](Getting-Started-usage) | [Inicio](Home)

---

## Resumen

En una operación normal de POS se realiza una venta y se ingresa el monto pagado por la venta. La venta no se puede completar hasta que al menos se ingrese como pago el monto adeudado. Si se recibe más del monto adeudado, se reporta el Cambio a Entregar (Change Due) y se asume que el comprador recibió dicho cambio.

Se registra el monto del pago y el monto reembolsado (junto con cuándo y quién lo realizó).

Existe soporte para una transacción de pago manual (usada para ventas POS) que permite al vendedor cerrar la venta aunque quede un saldo pendiente. Esta transacción de pago se usa cuando el administrador del sitio quiere que el cajero tome una decisión deliberada de cerrar la venta con un saldo pendiente. Solo las ventas con un cliente asignado se pueden cerrar con saldo pendiente.

En una venta por factura es normal que quede un saldo pendiente. Todas las facturas requieren que se asocie un cliente a la venta. Aunque es posible registrar una transacción de pago "pendiente" (due) contra una factura, no es necesario.

Actualmente se está desarrollando una mejora para llevar un mejor control de los montos de saldo pendiente (incluyendo las transacciones "pendientes") y para mejorar ligeramente el soporte de pagos posteriores a la venta. Este trabajo busca resolver el issue #3072