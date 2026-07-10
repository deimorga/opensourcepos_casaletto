[← Back to Configuration](Configuration) | [Home](Home)

---

# Item Attributes

This page explains how to use custom attributes for items, sales, and receivings.

> **Note:** The old `custom1` through `custom10` fields are deprecated. Use the Attributes system instead.

## Overview

Attributes allow you to add custom fields to items, sales, and receivings. Each attribute can be:

- **Text** - Free-form text values
- **Dropdown** - Pre-defined list of values
- **Decimal** - Numeric values
- **Date** - Date values

You can define which attributes appear in:
- **Items** - View and edit in item details
- **Sales** - View and edit during sale transactions
- **Receivings** - View and edit during receiving

## Access Attributes Module

1. Go to **Office → Employees**
2. Edit employee permissions
3. Check the **Attributes** module permission

## Create Attribute Definitions

Go to **Office → Attributes**:

### Definition Fields

| Field | Description |
|-------|-------------|
| **Definition Name** | Display name for the attribute (e.g., "Brand", "Color", "Size") |
| **Definition Type** | `TEXT`, `DROPDOWN`, `DECIMAL`, or `DATE` |
| **Definition Unit** | Unit of measure (e.g., "kg", "cm", "%" for numeric attributes) |
| **Show in Items** | ☑ Display and edit in item details |
| **Show in Sales** | ☑ Display and edit in sales transactions |
| **Show in Receivings** | ☑ Display and edit in receiving forms |

### Dropdown Values

For `DROPDOWN` type attributes:

1. Create the attribute definition
2. Click the attribute to edit
3. Add dropdown values in the values section
4. Each value can be used multiple times across items

## Use Attributes in Items

Once attributes are defined with "Show in Items" checked:

1. Go to **Items → Manage Items**
2. Edit an item
3. Scroll to **Attributes** section
4. Enter values for each attribute
5. Click **Submit**

### Import via CSV

When importing items via CSV, include attribute columns:

```
attribute_1_name,attribute_1_value
attribute_2_name,attribute_2_value
```

**Example:**

```
item_name,category,Brand,Color,Size
Widget A,Electronics,Samsung,Black,Large
Widget B,Electronics,LG,White,Small
```

## Use Attributes in Sales

To show attributes in sales:

1. Create attribute definition
2. Check **Show in Sales**
3. During a sale, the attribute will appear in the item details

Attributes can be edited during the sale and will be saved with the transaction.

## Use Attributes in Receivings

To show attributes in receivings:

1. Create attribute definition
2. Check **Show in Receivings**
3. During receiving, the attribute will appear in the item details

This allows you to update attribute values when receiving new stock.

## Database Structure

Attributes use three database tables:

| Table | Purpose |
|-------|---------|
| `attribute_definitions` | Stores attribute definitions (name, type, flags) |
| `attribute_values` | Stores dropdown values for DROPDOWN type |
| `attribute_links` | Links attributes to items/sales/receivings |

### Attribute Flags

Attributes support these visibility flags:

| Flag | Value | Shows In |
|------|-------|----------|
| SHOW_IN_ITEMS | 1 | Items list and edit |
| SHOW_IN_SALES | 2 | Sales transactions |
| SHOW_IN_RECEIVINGS | 4 | Receiving forms |

These can be combined (e.g., `5` = Items + Receivings).

## Examples

### Example 1: Brand Attribute

```
Definition Name: Brand
Type: TEXT
Show in Items: ☑
Show in Sales: ☑
Show in Receivings: ☐
```

Items will have a Brand field, visible during sales.

### Example 2: Size Dropdown

```
Definition Name: Size
Type: DROPDOWN
Values: Small, Medium, Large, XL, XXL
Show in Items: ☑
Show in Sales: ☑
Show in Receivings: ☑
```

Select from predefined sizes throughout the system.

### Example 3: Weight (Decimal)

```
Definition Name: Weight
Type: DECIMAL
Unit: kg
Show in Items: ☑
Show in Sales: ☐
Show in Receivings: ☑
```

Track weight in kilograms, editable in receivings.

## API Endpoints

For developers, attributes can be accessed via:

| Endpoint | Description |
|----------|-------------|
| `GET /attributes` | List all attribute definitions |
| `POST /attributes/save_value` | Save attribute value for an item |
| `POST /attributes/save_definition` | Save attribute definition |
| `DELETE /attributes/delete_value` | Delete dropdown value |

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Attributes not showing | Check visibility flags in definition |
| Dropdown values missing | Add values after creating DROPDOWN type |
| Can't edit attributes | Verify employee has Attributes permission |
| Import fails | Check CSV column names match attribute definition names |

## See Also

- [Items](Items)
- [Inventory Items](Inventory-Items)
- [Import from CSV](Import-data-from-CSV-file)
- [Attribute Model Source](https://github.com/opensourcepos/opensourcepos/blob/master/app/Models/Attribute.php)