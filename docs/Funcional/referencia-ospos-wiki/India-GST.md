[← Back to Configuration](Configuration) | [Home](Home)

---

This guide explains how to configure India Goods and Services Tax (GST) in the application.

## Overview

India GST (introduced in 2017) uses 3 main tax jurisdictions:

| Jurisdiction | Description |
|--------------|-------------|
| **CGST** | Central Goods and Services Tax |
| **SGST** | State Goods and Services Tax |
| **IGST** | Integrated Goods and Services Tax |

Unlike the US with numerous tax jurisdictions, India has only these 3, making it simpler to configure with the Destination Based Tax feature.

## Tax Code Assignment

Tax codes are assigned to customers to identify which tax jurisdictions apply:

- **Customer has tax code** - Uses that tax code
- **No customer tax code** - Uses customer's state to determine tax jurisdiction
- **Default** - Uses company's default tax code

> **Important:** To print a tax invoice for a customer, you must enter their GSTIN (GST Identification Number).

## Prerequisites

Before configuring India GST:

1. Enable **Destination Based Tax** (see [Taxes](Taxes))
2. Understand tax categories and jurisdictions
3. Have your company's GSTIN ready
4. Know which tax slab rates apply to your products

## Step 1: Enable HSN Codes

Go to **Office → Configuration → General**:

| Setting | Value |
|---------|-------|
| Include support for HSN Codes | ☑ Checked |

HSN (Harmonized System Nomenclature) codes classify products for tax purposes.

## Step 2: Configure Tax Settings

Go to **Office → Configuration → Tax**:

| Setting | Value | Notes |
|---------|-------|-------|
| Tax Id | Your GSTIN | Enter your company's GST Identification Number |
| Tax Included | ☑ or ☐ | Check if prices include tax (VAT-style) |
| Use Destination Based Tax | ☑ Checked | Required for GST |

## Step 3: Create Tax Codes

Go to **Office → Taxes**:

### Create Tax Jurisdictions

Create tax codes for each jurisdiction your business requires:

1. **Your company's tax code** (required) - Represents your location
2. **CGST** - Central GST jurisdiction
3. **SGST** - State GST jurisdiction
4. **IGST** - Integrated GST jurisdiction
5. **Others** - Any additional state-specific jurisdictions

### Create Tax Categories

Create one tax category for each tax slab:

| Slab | Category Name |
|------|---------------|
| 5% | Tax Category 5% |
| 12% | Tax Category 12% |
| 18% | Tax Category 18% |
| 28% | Tax Category 28% |

### Create Tax Rates

Define tax rates for each combination:
- Tax Code (jurisdiction)
- Tax Category (slab)
- Tax Rate (percentage)

## Step 4: Set Defaults

Go to **Office → Configuration → Tax**:

| Setting | Value |
|---------|-------|
| Default Tax Category | Your most common tax slab |
| Default Tax Jurisdiction | Your company's state |
| Default Tax Code | Your company's tax code |

## Step 5: Configure Items

For each item:

1. Go to **Items → Manage Items**
2. Edit the item
3. Enter the **HSN Code** (product classification)
4. Select the **Tax Category** (tax slab)
5. Click **Submit**

> **Note:** If an item has no tax category, the Default Tax Category will be used.

## Step 6: Configure Customers

For each customer:

1. Go to **Customers → Manage Customers**
2. Edit the customer
3. Assign **Tax Code** (if different from default)
4. Enter **GSTIN** (if they need tax invoices)
5. Click **Submit**

> **Tax Invoice:** If GSTIN is provided, a tax invoice is generated. Otherwise, a standard invoice is generated.

## Example Configuration

### Example: Company in Maharashtra

**Tax Codes:**
- `MH-CGST` - Maharashtra CGST (9%)
- `MH-SGST` - Maharashtra SGST (9%)
- `IGST` - Interstate GST (18%)

**Tax Categories:**
- `GST-5` - 5% slab
- `GST-12` - 12% slab
- `GST-18` - 18% slab
- `GST-28` - 28% slab

**Tax Rates:**
| Tax Code | Category | Rate |
|----------|----------|------|
| MH-CGST | GST-18 | 9% |
| MH-SGST | GST-18 | 9% |
| IGST | GST-18 | 18% |

### For Sale Within Maharashtra:
- CGST: 9%
- SGST: 9%
- Total: 18%

### For Sale Outside Maharashtra (Interstate):
- IGST: 18%

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Tax invoice not printing | Verify customer has GSTIN entered |
| Wrong tax rate | Check item's tax category and customer's tax code |
| HSN code not showing | Enable "Include support for HSN Codes" in General config |
| Multiple taxes showing | Verify tax rates are correctly assigned to jurisdictions |

## See Also

- [Taxes](Taxes) - Base tax system and Destination Based Tax
- [Configuration](Configuration) - Store configuration
- [Items](Items) - Managing inventory items