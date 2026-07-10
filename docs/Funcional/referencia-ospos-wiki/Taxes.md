[← Back to Configuration](Configuration) | [Home](Home)

---

This page explains how to configure taxes in the application. For India GST specifically, see [India GST Configuration](India-GST).

## Table of Contents

- [Tax System Overview](#tax-system-overview)
- [Base System Taxes](#base-system-taxes)
- [Destination Based Tax](#destination-based-tax)
- [Tax Definitions](#tax-definitions)
- [Configure Taxes](#configure-taxes)
- [Troubleshooting](#troubleshooting)

## Tax System Overview

The application supports multiple tax configurations:

| Tax Type | Use Case | Features |
|----------|----------|----------|
| **Base System Tax** | Simple tax requirements | Up to 2 tax rates per item, sales tax or VAT |
| **Destination Based Tax** | Multiple tax jurisdictions | Tax by customer location, US state/county/city taxes |
| **India GST** | India Goods and Services Tax | CGST, SGST, IGST support |

## Base System Taxes

If your tax requirements are simple, use the base system tax approach.

**Features:**
- Maximum of 2 tax rates per item
- Supports sales tax (added to price) or VAT (included in price)
- **Cannot mix sales tax and VAT** - choose one approach

**Important:** Once you start making sales, you cannot switch between sales tax and VAT without incorrect reports.

## Destination Based Tax

In the United States, sales tax can be based on:
- **Origin address** - Store location
- **Ship-to address** - Where products are shipped
- **Bill-to address** - Customer billing address

Use this feature if you need to collect and report taxes by multiple tax jurisdictions.

## Tax Definitions

| Term | Definition |
|------|------------|
| **Default Tax Code** | Tax code for the store location (also called Origin Tax Code). Only one per company. |
| **Tax Code** | Code assigned to a customer identifying which tax jurisdictions apply to their sale. |
| **Tax Rate** | Percent value (up to 4 decimal places) for a tax jurisdiction. |
| **HSN Code** | Harmonized System Nomenclature - internationally adopted product category code. |
| **Item Tax Category** | Category for items requiring different tax rates (e.g., alcohol at higher rate). |
| **VAT Tax** | Tax tracked for both sales and receiving (Value Added Tax). |
| **Tax Included** | Tax is included in the sales price. |
| **Tax Excluded** | Tax is added to the sales price. |
| **Taxing Decimals** | Number of decimals for tax amount storage. |
| **Cascaded Tax** | Second tax computed on invoice total plus first tax (VAT). Not currently supported. |
| **Tax Group** | Summary of taxes collected for one or more tax authorities in a sale. |

## Configure Taxes

### Step 1: Enable Tax Module

1. Go to **Office → Employees**
2. Edit the employee who will manage taxes
3. Check the **Taxes** module permission (not Tax reporting)

### Step 2: Configure Tax Type

Go to **Office → Store Config → General**:

| Setting | Value | Notes |
|---------|-------|-------|
| **Tax Included** | ☑ Checked | Use for VAT (tax included in price) |
| | ☐ Unchecked | Use for sales tax (tax added to price) |
| **Destination Based Tax** | ☑ Checked | Enable destination-based tax calculation |
| | ☐ Unchecked | Use simple item-level tax rates |

### Step 3: Set Default Origin Tax Code

1. Create a tax code for your store location
2. Go to **Office → Store Config → General**
3. Enter the tax code in **Default Origin Tax Code**

### Step 4: Add Tax Rates

Go to **Office → Taxes** and add each tax jurisdiction:

1. **Tax Code** - Unique identifier (e.g., `CA` for California)
2. **Tax Code Name** - Description (e.g., `California State Tax`)
3. **Tax Code Type** - Choose:
   - `Sales Tax` - Tax calculated per item, then totaled
   - `Sales Tax by Invoice` - Tax calculated on total per category
4. **City** and **State** - Location for customer-based tax lookup
5. **Tax Rate** - Percentage (e.g., `7.25` for 7.25%)
6. **Rounding Code** - How to round fractional amounts
7. **Category Exceptions** - Override standard rate for specific categories

### Step 5: Assign Tax Categories to Items

For items with different tax rates (e.g., alcohol, services):

1. Go to **Office → Items**
2. Edit the item
3. Assign the appropriate **Tax Category**

## Rules and Constraints

> **Important:** Once Destination Tax is enabled, the default tax rate fields on items are no longer used for tax calculation.

| Rule | Behavior |
|------|----------|
| No customer tax code | Use Origin Tax Code (store default) |
| Customer has tax code | Use customer's assigned tax code |
| Sales by Receipt | Tax based on default tax code |
| Sales by Invoice | Tax based on customer city/state |
| Tax rates locked | Rates are frozen when sale completes |

## Customer Tax Code Assignment

Customer tax codes are assigned based on this priority:

1. **Customer's assigned tax code** - If customer has a specific tax code
2. **Customer's city + state** - If tax code matches customer location
3. **Customer's state** - If tax code matches customer state
4. **Default origin tax code** - Fallback to store location

## India GST

India's Goods and Services Tax (introduced 2017) has similar requirements to US Destination Based Tax.

For India GST configuration, see [India GST Configuration](India-GST).

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Tax not calculating | Check "Destination Based Tax" is enabled in Store Config |
| Wrong tax rate | Verify customer has correct tax code assigned |
| Tax rates changed after sale | Tax rates are locked when sale completes (by design) |
| VAT and sales tax mixed reports | Cannot switch between tax types after sales (by design) |
| Category tax not applying | Verify item has correct tax category assigned |

## See Also

- [India GST Configuration](India-GST)
- [Configuration](Configuration)
- [Getting Started Usage](Getting-Started-usage)