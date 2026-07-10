As every year a stocktake is needed to check if the amounts in the point of sale correspond with reality, I decided to do a short writeup on an approach for doing a stocktake using OSPOS.

## Create a new employee
This will help us track the corrections on our inventory after the stocktake has been done. Therefore, create a new employee that you use to do the stocktake. This means that it should be logged out once you stop correcting inventory levels. We suggest to give it an easy recognizable and unique name.

## Correct inventory levels
The next step is the correction of the inventory levels. Currently we'll need to rely on the inventory form that exists in the items module. The idea is as follows

* Every time we want to count an item, we scan it in the items module search field
* Next we check the quantity of the item still in stock in the items overview
* Then the middle icon on the right is clicked on the retrieved item row
* A form shows up that allows you to correct the inventory level for this item
* The form will show a list with all the transactions registered for this item
* An inventory correction can be entered in this form

## How to keep track of item counts
As you logged in with the newly created employee, the inventory dialog will show you if you have already encountered the item or not. This will be visible as a transaction made by this specific user.

Basically you correct with the current quantity in the system based on what you have counted so far.

### Inventory correction examples
* If you have an item with current quantity of 2, and it is the first time you encounter the item, you add a correction of *-1* and submit the form, so totals 1
* If you have an item with current quantity of 1, and it is the second time you encounter the item, you add a correction of *+1* and submit the form, so totals 2
* If you have an item with current quantity of 1, and it is the first time you encounter the item, you add a correction of *0* and submit the form, so totals 1

It's important to also add a *0* in case the quantity is correct, so next time you see the item you know it was counted before.

## Inventory reporting
After you are done with the stocktake, *you need to logout* the newly created 'stocktake' employee. After this we can run some reporting SQL to retrieve the corrected items and their inventory levels.

First you need to get a hold of the 'stocktake' employee id and the date you started this stocktake. Then you can run following queries for reporting. In this case change the following parameters in the queries below

* `trans_employee = 11209` with `trans_employee = <youremployeeid>
* `trans_date > date('2019-12-01')` with `trans_date > date('<dateyoustartedthestocktake'>)`.

### List of items in the stocktake
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

### List of items not in the stocktake
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

## Inventory correction
After the reporting is done, you need to reset the inventory levels for the items that you did not encounter during the stocktake. To accomplish this task, you need to set the quantities in the `ospos_item_quantities` table to **0** for the items you did not see. Next up you need to correct the inventory table and synchronize it with this change

### Reset quantities for items not in stocktake
~~~~sql
update ospos_item_quantities set quantity = 0
where quantity <> 0 and item_number is not null and ospos_items.item_id not in 
(select ospos_items.item_id from ospos_items join ospos_item_quantities on ospos_item_quantities.item_id = ospos_items.item_id 
join ospos_inventory on ospos_inventory.trans_items = ospos_items.item_id 
where stock_type = 0 and quantity <> 0 and item_number is not null and deleted = 0 and ospos_items.item_id and trans_user = 11209 and trans_date > date('2019-12-01') order by item_number) 
group by item_id 
order by item_number;
~~~~
### Inventory level correction after reset
~~~~sql
insert into ospos_inventory (trans_user, trans_comment, trans_location, trans_inventory, trans_items) select 11209, 'Inventory autocorrection', 1, (quantity - sum(trans_inventory)) as trans_inventory, item_id from ospos_item_quantities join ospos_inventory on item_id = trans_items
group by trans_items having trans_inventory <> 0
~~~~


