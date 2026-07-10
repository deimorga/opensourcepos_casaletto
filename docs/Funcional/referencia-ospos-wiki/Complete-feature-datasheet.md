
[← Back to Home](Home)

---

* **Feature index table**:
  * [Quick Overview of Features](#quick-overview-of-features)
  * [Complete List of Features](#complete-list-of-features)
* **See also**:
  * [Getting Started Installations](Getting-Started-installations)
  * [Getting Started Usage](Getting-Started-usage)
  * [Printing general info](Printing)
  * [Wiki Home](Home)

## Quick Overview Of Features
----------------------------

Please for complete feature see the [Complete List of Features](#complete-list-of-features) here below, this its a quick reference

| ❔ Wanted feature... | 🚀 What can do... | ℹ️ What cannot do... |
| --- | --- | --- |
| Point of Sale (POS) | **Sell** products or/and services  and apply **multiple payments** (inclusivelly different types) to the sale, include a featured **Restaurant Tables** module, Purchase Orders | Advanced Transaction Types such as Special Orders, Back Orders and **Layaways**, Credit **accounting** sales |
| Customer Relationship Management (CRM) | Add customers, import/export from CSV, **maintain customer** profiles and view full **sales history**, and market to them via **email** marketing and **SMS marketing** | Accounts Receivable / House Accounts, OSPOS has CRM features but **no A.R**. features, No **multi tenancy** with one deployment. |
| Inventory Management | Create stock (any product) and non-stock items (articles), ...with custom fields. Import/export from CSV. Generate amd read barcodes | Inventory Matrix, Track items features, colors, materials. Support QR codes (see #1935) |
| Multilingual Web Interface (i18n GUI) | Multilingual support with regionalisation, Selectable Boostrap (Bootswatch) based UI theme | End-user customize interface like Wordpress but with little coding can be done. No regionalisation per items attributes |
| Reporting | Customers, Inventory and Transactions (sales or returns) |  There are reports, but no dashboard that shares Top Sales, Top Suppliers, Best Selling Items, Top Customers, etc. See #1433 |
| Expense Modules | Added and summarize basic expenses, no accounting needs | Accounting with expenses over the sales are out of scope |
| Gift Card and Rewards | Issue gift cards from as a payment method, Customers rewards | Management of accounting gift cards, expired dates on rewards |

* **See also**:
  * [Getting Started Installations](Getting-Started-installations)
  * [Getting Started Usage](Getting-Started-usage)
  * [Printing general info](Printing)
  * [Wiki Home](Home)

The following section has the complete list of each feature and compressive guides to use them:

# Complete List of Features
---------------------------

A quick overview of those modules can be found at the [Getting Started Usage](Getting-Started-usage) wiki page, here were detailed all the features of the Open Source Point Of Sale software:

* Interface
   * Web interface responsive, mobile and desktop view capable
   * Employee access Login 
   * Multiuser module permission control
   * reCAPTCHA option to protect login page from brute force attacks
   * [Multi-language](Developer-Translations#translation-status)
   * [User interface Internalization](Developer-Translations#translation-status)
   * Selectable Boostrap (Bootswatch) based UI theme
* Employees
   * Employees Manage
   * Importing from CSV file
   * Exporting to Spreadsheets
* Sales
   * Sales Management: Receipt
   * Sales Management: Return
   * Sales Management: Quote
   * Sales Management: Suspend
   * Sales Transactions logging
   * Sales Receipt and invoice printing and/or emailing
   * Receiving
   * [Temporary Items](Items#temporary-items)
   * Gift card Support
   * Rewards on customers
   * [Restaurant Tables](Sales-Restaurant)
   * Purchase Orders
* Inventory
   * Suppliers Management
   * Items Stock Management
   * Items Kits for Items groups
   * Barcode support, Barcode generation
   * Inventory counting
   * Items Management: Category Naming and Image
   * Items Management Taxation
   * Items Price Management
   * Managing Stock for multi location
   * [Items Importing from CSV](Import-data-from-CSV-file#importing-items)
   * Exporting to Spreadsheets
* Customers
   * Customer Manage, including registration consent repect GPDR regulations #2003
   * [Customer Import from CSV](Import-data-from-CSV-file#importing-customers)
   * Exporting to Spreadsheets
   * VAT and multi tiers taxation
* [Expenses](Expenses)
   * [Expenses logging by Simple and faster UI](Expenses)
* Reporting
   * Reporting on sales, orders, expenses, inventory status
   * Exporting to Spreadsheets
* Communications
   * Messaging (SMS)
   * Mailchimp integration
* [Printing](Printing)
   * [Device printing](Printing#device-printing-support)
   * [Barcode Printing](Printing#barcode-printing)
   * PDF Printing export
   * [Printing Item Labels](Printing#device-printing-support)
   * [Fiscal Printing](Printing#fiscal-printing)
* Store Office
   * Store Configuration
   * Regionalisation

## See also

  * [Getting Started Installations](Getting-Started-installations)
  * [Getting Started Usage](Getting-Started-usage)
  * [Printing general info](Printing)
  * [Wiki Home](Home)