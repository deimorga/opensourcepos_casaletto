
[← Volver a la Guía de Uso](Getting-Started-usage) | [Inicio](Home)

---

**Este es un trabajo en progreso. Se está agregando soporte para artículos temporales al sistema.**

## 1. Funcionalidades de Artículos


## 2. Definiciones/Estructura

*Artículo* representa algo que se puede vender. Puede usarse para gestionar la cantidad del artículo en existencia o puede ser un servicio prestado.

* *UPC/EAN/ISBN*

* *Nombre del artículo*

* *Categoría*

* *Proveedor*

* *Precio de costo*

* *Precio de venta*

* *Impuesto 1*

* *Impuesto 2*

* *Cantidad en existencia*

* *Cantidad a recibir "no se puede establecer en 0"*

* *Nivel de reorden*

* *Descripción del artículo*

* *Avatar*

* *Permitir descripción alternativa*

* *El artículo tiene número de serie*

* *Tipo de existencia* define si el artículo es un artículo físico que se rastrea en el inventario o si es un artículo sin existencia (por ejemplo, un servicio de mano de obra). Los valores válidos son `0 - Con existencia`, `1 - Sin existencia`. El tipo de existencia predeterminado es `Con existencia`.

* *Tipo de artículo* define si el artículo es un artículo estándar que puede ser un artículo que se mantiene en inventario o un artículo que representa un único servicio que se presta. Un segundo tipo es un Kit, que es un Artículo que representa una colección de otros artículos que no son kits. Otro tipo de artículo es un artículo estándar, pero en lugar de pedirse por cantidad, el artículo puede pedirse por monto en dólares. Un artículo temporal es un tipo de artículo sin existencia que puede usarse para definir rápidamente un artículo sobre la marcha para gestionar una venta. Esto puede ser útil en situaciones donde los artículos se fabrican por encargo. Cuando se agrega un artículo temporal a un pedido, se le pedirá al usuario que sobrescriba el nombre temporal, el código de barras y la descripción. Un artículo temporal debe ser un artículo sin existencia.
** Los valores válidos son `0 - Estándar`, `1 - Kit`, `2 - Ingreso de monto de artículo`, `3 - Temporal`. El tipo de artículo predeterminado es `Estándar`.

* *Eliminado*

Se propone agregar los siguientes elementos a la tabla `items` en apoyo a

* *Cantidad por paquete* es el número de unidades de venta mínima por paquete.

* *Nombre del paquete* es el nombre del tipo de paquete. Si no se especifica un nombre de paquete pero se utiliza el nombre del paquete, entonces se usará por defecto "EACH" (CADA UNO). Los nombres típicos podrían ser "CASE" (CAJA), "CARTON" (CARTÓN), "BOTTLE" (BOTELLA) o "GALLONS" (GALONES).

* *Id de artículo de venta mínima* es el id del artículo que representa el paquete más pequeño de un producto dado. Por ejemplo, si 281 es el id del artículo para una barra de dulce individual, entonces el Id de artículo de venta mínima para ese artículo también sería 281, la cantidad por paquete sería 1 y el nombre del paquete sería "EACH". Si hay un cartón pequeño que contiene 6 de esas barras de dulce, entonces ese sería un id de artículo diferente (digamos 817) con una cantidad por paquete de 6, un nombre de paquete de CARTON, y el Id de artículo de venta mínima sería 281. Un escenario similar existiría para un artículo diferente si también se vendiera por caja, donde podría haber 10 cartones por caja. En ese escenario, la cantidad por paquete sería 60 (10 cartones multiplicados por 6 unidades por cartón).



## 3. Reglas de operación

### 3.1 Ventas

- Actualmente, en el punto de venta todos los artículos se verifican para comprobar si hay suficiente cantidad disponible para satisfacer la venta. Si el *Tipo de existencia* está establecido en `Sin existencia`, entonces esta validación no se realizará.

### 3.2 Mantenimiento de artículos

- Cuando se agrega un nuevo Artículo de tipo Kit de artículos, se verificará la tabla de Kits de artículos para ver si existe una entrada en la tabla con el mismo nombre que el Artículo recién agregado. Si no es así, se agregará un nuevo Kit de artículos para este artículo. Si se encuentra uno, el campo `item_id` del Kit de artículos se actualizará para apuntar a este artículo.

- Si se agrega un Artículo de tipo Kit de artículos, el sistema verificará si ya existe un Kit de artículos con la misma descripción. Si el Kit de artículos existente hace referencia a un Artículo diferente, entonces el nuevo Artículo no podrá agregarse. Es necesario cambiar la descripción del artículo o borrar el artículo referenciado por el Kit de artículos. Esto es para asegurar que no terminemos accidentalmente con dos kits de artículos para el mismo artículo. Sin embargo, si esto es algo requerido, es posible lograrlo cambiando la descripción después de agregar el artículo.

- {EN DESARROLLO} Un artículo de tipo Artículo Temporal solo puede ser sin existencia. Se recomienda una descripción genérica que identifique al artículo como un artículo temporal. Dado que el sistema de ventas debe tener un número de artículo interno único, debe configurar tantos artículos temporales como espere necesitar en un solo pedido. Puede sobrescribir el código UPC, el nombre del artículo, la descripción, el costo, el precio y el descuento en el punto de venta.

- {EN DESARROLLO} Normalmente, un artículo se inventaría, se vende y se costea en el paquete de venta al detalle (el número más bajo de unidades por paquete). La propuesta que se está planteando es permitir que se definan dos o más artículos para el mismo producto, cada uno representando una cantidad diferente por paquete. Esta capacidad estará controlada por una configuración del sistema y estará deshabilitada por defecto.

### 3.3 Artículos temporales

Los Artículos Temporales se crean con el propósito de vender un artículo que no queremos gestionar en nuestra colección "estándar" de artículos con y sin existencia. Se crean para registrar la venta de algo que no se mantiene en existencia y que no se espera volver a vender. Podría ser que se espere volver a vender el artículo, pero estamos posponiendo su configuración adecuada para más adelante.

Internamente, un artículo temporal se mantiene en el archivo de artículos igual que cualquier otro artículo. Sin embargo, tiene propiedades únicas (además de un tipo de artículo "Temporal").

Primero, está asociado a un único pedido y se adjunta al pedido solo al hacer clic en el botón Nuevo desde la caja registradora de ventas. Los artículos que se agregan a una venta a través de la caja registradora pueden eliminarse. Si se elimina un artículo temporal de la caja registradora, también se elimina de la tabla de artículos.

El enfoque adoptado para soportar artículos temporales busca asegurar la integridad del sistema de reportes, el cual depende de uniones (joins) desde la tabla sales_items hacia la tabla items para recuperar la información del artículo. Se puede pensar en los artículos temporales más como una extensión de la tabla sales_items, ya que ese artículo solo existe con el propósito de contener información sobre el artículo temporal para ese sales_item.

Aunque los artículos temporales no se incluyen en la lista "normal" de artículos, existe una opción de filtro que permite listar únicamente los artículos temporales. Desde la lista de artículos temporales ahora es posible cambiar el tipo de artículo a un artículo estándar o a un artículo de kit. Este es un cambio de una sola vía. Una vez que se convierte un artículo temporal en un artículo estándar o de kit, no se puede volver a convertir en un artículo temporal.
