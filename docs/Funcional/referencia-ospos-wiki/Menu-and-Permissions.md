
[← Back to Usage Guide](Getting-Started-usage) | [Home](Home)

---

This document describes the menu and permissions infrastructure in order to support a primary/home/storefront menu and a "back office"/manager menu.

## Requirements/Features

* Provide a separation between the "normal" home page menu (the "home" menu group) and a "back office" home page menu (the "office" menu group..
* The "office" menu will be secured so that only those who have been granted permission to access it can do so.
* A configuration option will allow for an office menu navigation icon to be included in the home menu group.  By default it will be included, but it can be excluded.  In that case the only access to the office page will be through a direct link or via a manual URL change.
* A logged in employee will always have access to the home page.
* Employees will need to have explicit permissions granted to the office menu.
* The menu options available on either menu group are unique to each employee and set via the permissions table in Employee maintenance.
* A menu option can appear in both menu groups for a given employee.
* Only module level permissions can be used to determine which menu the module navigation icon appears.

## Operations

By default, the home menu will contain the following menu options.

* Office
* Customers
* Items
* Item Kits
* Suppliers
* Reports
* Receivings
* Sales
* Messages
* Gift Cars

By default, the office menu will contain the following menu options

* Home
* Employees
* Taxes
* Store Configuration
* Migration

The Office and Home options toggle back and forth between the menu groups.

The Office menu option which appears on the Home menu group can be removed from the Home menu group via a configuration option on the General configuration tab.  The result of this option is that the user must click on a link or type in the URL in order to access the office menu.  This isolates it a bit from the home page so that no one will try to go into the office menu unless they are authorized and know what the URL is for it.

