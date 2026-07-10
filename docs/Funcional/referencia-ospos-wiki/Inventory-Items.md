[← Back to Usage Guide](Getting-Started-usage) | [Home](Home)

---

Allows employees to load products into inventory. Start by clicking the Items button. This will load your list of items.

## Access

You can **add, remove, modify and manage** items using the [Inventory module](Getting-Started-usage#3-inventory) and use them for sales or returns in the [Sale Module](Getting-Started-usage#4-sales).

The [Inventory module](Getting-Started-usage#3-inventory) displays a list of recent items and entries for item kits.

The [Sale Module](Getting-Started-usage#4-sales) has an input field to scan barcodes or search items/kits by name or barcode number. The list is presented and you can pick items for the current sale.

## Importing Items

CSV imports are possible for items to both **add new items** and **update existing items**. The file must follow standard CSV format including a header row. It is recommended that you download the CSV Import Template each time (Items > CSV Import > "Download Import CSV Template (CSV)").

If the **Id** column is populated with the `item_id`, the importer will **update** the item, but it only replaces attributes and fields where there is a value. Empty fields are ignored rather than removing existing data. For update operations, there are no required columns like in imports.

If the **Id** column is left blank, the importer treats the item as new. It checks the barcode against existing barcodes and if **no duplicate barcodes** is enabled, it will create an error if a duplicate is found.

Errors are logged in the error log. **If an error occurs, none of the rows will be imported.**