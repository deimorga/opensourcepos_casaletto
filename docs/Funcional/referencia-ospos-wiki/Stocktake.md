Como cada año se necesita hacer una toma de inventario para verificar si las cantidades en el punto de venta corresponden con la realidad, decidí escribir una breve guía sobre un enfoque para realizar una toma de inventario usando OSPOS.

## Crear un nuevo empleado
Esto nos ayudará a rastrear las correcciones en nuestro inventario después de haber realizado la toma de inventario. Por lo tanto, cree un nuevo empleado que use para hacer la toma de inventario. Esto significa que dicho empleado debe cerrar sesión una vez que termine de corregir los niveles de inventario. Sugerimos darle un nombre fácil de reconocer y único.

## Corregir los niveles de inventario
El siguiente paso es la corrección de los niveles de inventario. Actualmente necesitaremos depender del formulario de inventario que existe en el módulo de artículos. La idea es la siguiente:

* Cada vez que queremos contar un artículo, lo escaneamos en el campo de búsqueda del módulo de artículos
* Luego verificamos la cantidad del artículo que aún queda en existencia en el resumen de artículos
* Después se hace clic en el ícono del medio a la derecha de la fila del artículo recuperado
* Aparece un formulario que permite corregir el nivel de inventario de este artículo
* El formulario mostrará una lista con todas las transacciones registradas para este artículo
* En este formulario se puede ingresar una corrección de inventario

## Cómo llevar el control de los conteos de artículos
Como inició sesión con el empleado recién creado, el diálogo de inventario le mostrará si ya se ha encontrado el artículo o no. Esto será visible como una transacción realizada por este usuario específico.

Básicamente, se corrige con la cantidad actual en el sistema en función de lo que se ha contado hasta el momento.

### Ejemplos de corrección de inventario
* Si tiene un artículo con cantidad actual de 2, y es la primera vez que encuentra el artículo, agrega una corrección de *-1* y envía el formulario, por lo que el total queda en 1
* Si tiene un artículo con cantidad actual de 1, y es la segunda vez que encuentra el artículo, agrega una corrección de *+1* y envía el formulario, por lo que el total queda en 2
* Si tiene un artículo con cantidad actual de 1, y es la primera vez que encuentra el artículo, agrega una corrección de *0* y envía el formulario, por lo que el total queda en 1

Es importante también agregar un *0* en caso de que la cantidad sea correcta, para que la próxima vez que vea el artículo sepa que ya fue contado antes.

## Reporte de inventario
Después de terminar la toma de inventario, *debe cerrar sesión* del empleado 'stocktake' recién creado. Después de esto podemos ejecutar algunas consultas SQL de reporte para recuperar los artículos corregidos y sus niveles de inventario.

Primero necesita obtener el id del empleado 'stocktake' y la fecha en que inició esta toma de inventario. Luego puede ejecutar las siguientes consultas para el reporte. En este caso, cambie los siguientes parámetros en las consultas de abajo:

* `trans_employee = 11209` por `trans_employee = <su id de empleado>`
* `trans_date > date('2019-12-01')` por `trans_date > date('<fecha en que inició la toma de inventario>')`.

### Lista de artículos incluidos en la toma de inventario
~~~~sql
select distinct ospos_items.item_id, item_number, name, quantity, cost_price, unit_price, min(trans_date) as first_buy from ospos_items 
join ospos_inventory on ospos_inventory.trans_items = ospos_items.item_id 
join ospos_item_quantities on ospos_item_quantities.item_id = ospos_items.item_id 
where ospos_items.item_id in 
(select ospos_items.item_id from ospos_items join ospos_item_quantities on ospos_item_quantities.item_id = ospos_items.item_id 
join ospos_inventory on ospos_inventory.trans_items = ospos_items.item_id 
where stock_type = 0 and quantity <> 0 and item_number is not null and deleted = 0 and ospos_items.item_id and trans_user = 11209 and trans_date > date('2019-12-01') order by item_number) 
group by item_id 
order by item_number;
~~~~

### Lista de artículos no incluidos en la toma de inventario
~~~~sql
select distinct ospos_items.item_id, item_number, name, quantity, cost_price, unit_price, min(trans_date) as first_buy from ospos_items 
join ospos_inventory on ospos_inventory.trans_items = ospos_items.item_id 
join ospos_item_quantities on ospos_item_quantities.item_id = ospos_items.item_id 
where quantity <> 0 and item_number is not null and ospos_items.item_id not in 
(select ospos_items.item_id from ospos_items join ospos_item_quantities on ospos_item_quantities.item_id = ospos_items.item_id 
join ospos_inventory on ospos_inventory.trans_items = ospos_items.item_id 
where stock_type = 0 and quantity <> 0 and item_number is not null and deleted = 0 and ospos_items.item_id and trans_user = 11209 and trans_date > date('2019-12-01') order by item_number) 
group by item_id 
order by item_number;
~~~~

## Corrección de inventario
Después de terminar el reporte, necesita restablecer los niveles de inventario para los artículos que no encontró durante la toma de inventario. Para lograr esta tarea, necesita establecer las cantidades en la tabla `ospos_item_quantities` en **0** para los artículos que no vio. A continuación, necesita corregir la tabla de inventario y sincronizarla con este cambio.

### Restablecer cantidades para artículos no incluidos en la toma de inventario
~~~~sql
update ospos_item_quantities set quantity = 0
where quantity <> 0 and item_number is not null and ospos_items.item_id not in 
(select ospos_items.item_id from ospos_items join ospos_item_quantities on ospos_item_quantities.item_id = ospos_items.item_id 
join ospos_inventory on ospos_inventory.trans_items = ospos_items.item_id 
where stock_type = 0 and quantity <> 0 and item_number is not null and deleted = 0 and ospos_items.item_id and trans_user = 11209 and trans_date > date('2019-12-01') order by item_number) 
group by item_id 
order by item_number;
~~~~
### Corrección del nivel de inventario después del restablecimiento
~~~~sql
insert into ospos_inventory (trans_user, trans_comment, trans_location, trans_inventory, trans_items) select 11209, 'Inventory autocorrection', 1, (quantity - sum(trans_inventory)) as trans_inventory, item_id from ospos_item_quantities join ospos_inventory on item_id = trans_items
group by trans_items having trans_inventory <> 0
~~~~

