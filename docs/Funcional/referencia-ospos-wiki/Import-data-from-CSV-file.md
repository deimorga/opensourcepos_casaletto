
[← Back to Usage Guide](Getting-Started-usage) | [Home](Home)

---

## Supported Import Features

* [Importing Items](#importing-items)
* [Importing Customers](#importing-customers)

## Importing Items

You can manually insert each product one by one, but this becomes cumbersome with large numbers of products.  For this reason, a Comma-Separated Values (CSV) import function has been introduced.

## Prerequisites and file format

CSV file to RFC-4180 specification with commas as the delimeter.  See https://en.wikipedia.org/wiki/Comma-separated_values for more details.

OSPOS will generate your CSV template based on your current data structure.  You can generate this file by going to Items->CSV Import and clicking the link (Download Import CSV Template (CSV))

Empty rows will be ignored.

CSV files with the Byte-Order Mark (BOM) or without it will be processed by the software.

## Item Columns and field mapping

```
0 - barcode
1 - item name
2 - category
3 - supplier id
4 - cost
5 - price
6 - tax 1 name
7 - tax 1 rate
8 - tax 2 name
9 - tax 2 rate
10 - reorder level
11 - item description
12 - allow alt description flag (1 to allow, otherwise keep this blank/empty)
13 - is serialized flag (1 is serialized, otherwise keep this blank/empty)
14 - custom 1
15 - custom 2
16 - custom 3
17 - custom 4
18 - custom 5
19 - custom 6
20 - custom 7
21 - custom 8
22 - custom 9
23 - custom 10
24 - item image
25 - location 1
26 - quantity 1
```

# Importing Customers

You can manually insert each Client one by one at Sale module when register a sale, but when you have a lot of customers it's not fun.

## File format and prerequisites

By using plain CSV file.

# See Also

* [Getting Started Usage](Getting-Started-usage)
* [Complete Feature Datasheet](Complete-feature-datasheet#complete-list-of-features)