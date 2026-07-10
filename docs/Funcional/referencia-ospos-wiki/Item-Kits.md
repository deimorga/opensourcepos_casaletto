
[← Back to Usage Guide](Getting-Started-usage) | [Home](Home)

---

## Quick Order Entry

Provides a way to quickly enter a group of related items into an order. The user selects the kit and all of the items associated with the kit are added to the order.

No item needs to be created to represent the kit. Only the items in the kit will need to be added to the item table.

## Packaged Items

This allows a customer to purchase a special package of items (such as a birthday party package promotion) that are bundled together.  The user can enter the package identifier and the package is added to the sales order along with all of the items in the package.

An item is created in the items table to represent the kit.

Packaged items can be prepackaged and maintained in stock just like any other item.  The quantity of each item in the package can continue to be tracked as if it were not in the package.

When the package is entered then the number of packages that are *prebuilt* is shown and if unavalable (but the individual items show adequate stock) then the user knows that a package can be built "just in time".

### 1.3 **Bundled Service Sales**

This feature allows a user to sell a service which is, of course, a non-stocked item (unless you wanted to limit the number of these services sold because it is a loss-leader).

The service can include a list of tasks that will be included with the service.  It can also include a list of stocked materials that are consumed as part of the service. The user can be charged for each individual task and material, or the price for the service can be set once to cover all of the tasks and materials, or the price for the service can be set once to cover all of the service tasks but the materials can be charged separately.

### 1.4 **Discounts**

Discounts can now be by percent or fixed amount and the discount is applied to each individual item added to the order.  Sources for discounts from system configuration which means it is applied to every item added to an order, or from the customer configuration, or from the kit configuration.

When the discount is by percent then that discount percent is added to each item added to the order that is assigned a price.  When the discount is by amount then the discount amount gets a bit squirrely.  The assumption is that if the discount is by amount then it should NOT be applied to each item.  Instead, the discount amount is applied to each item until the amount is depleted.  So in effect it is spread across the items.  This really only seems logical if you are pricing at the kit level because then the discount amount would be applied to the kit. If the kit price is essentially a charge for packaging then the discount amount might be greater than the price from the kit item.  This will result in the discount amount for the kit item cannot be greater than the price so it will be the same as the price (essentially giving the item a zero price).  The remainder of the discount amount will be applied to the next item.  This is then repeated for the next item until the discount amount is fully depleted. 


## 2. Definitions/Structure

*Item Kits* represent a collection of stocked items or labor items. 

* *Item Kit Name* is the internal name used for the kit.  It will not be used for the invoice or receipt.  When the kit is created the *item kit name* will be loaded from the selected *item kit item*.

* *Item Kit Description* is description of the kit for internal use only.  It will not appear on invoices or receipts.

* *Kit Item* is the item number of the item on the `item` table that represents the kit.  This is an optional field and is needed only if you need the *kit item* listed on the invoice or receipt, or you need to track the the number of kits that you have available to sale.

* *Item Kit Discount* is the discount amount to be applied to all items in the kit.  This discount is only applied if the *item kit discount* is greater than the customer discount.

* *Kit Pricing Method* is a code that identifies how the prices are to be applied to item kit items when they are added to the `sales_items` table.  The values are(0=all item kit items are priced based on the price found in the `items` table which includes the kit item itself (if it exists and if a price is assigned to it), 1=The kit is priced based on the price of the *kit item*, 2=The kit is priced based on the price of the kit item* plus the price of any stocked items that are included in the kit)

* *Print Kit Items* is a code that identifies whether or not items with a zero price should be included in the printed receipt or invoice.  The values are (0=Include all item kit items in the receipt and invoice, 1=Include only priced items in the receipt or invoice, 2=Include only the *kit item* in the receipt or invoice.)  

*Item Kit Items* represent an individual item that is part of a kit.  The same item can be a component of multiple kits. 

An *Item Kit* can also combine both *stock* and *labor* items.

If an *Item Kit* can be inventoried then it must reference an entry in the *Item* table that is stocked.

If an *Item Kit* is to be listed as an item in a sale then it must reference an entry in the *Item* Table.

If an *Item Kit* does not represent an item that is in the *Item* table then it serves only to facilitate the ordering and receiving of a collection of items at one time.  The kit itself will not be listed on the order or receivings report
 
An *Item Kit* can represent a group of inventoried items that are kept in inventory separately but that can be sold as a set sold as a set at a possible discount (such as a party pack).

An *Item Kit* can also represent a collection of services and/or inventoried material used as part of the services included in the kit.

