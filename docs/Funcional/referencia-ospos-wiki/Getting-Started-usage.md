
[← Back to Home](Home) | [Installation](Getting-Started-installations)

---

After having successfully [installed](Getting-Started-installations) and logged in, you will see a main menu screen.

All those icons are **Modules** that can be enabled or disabled for each **Employee**. The complete list of features per module is described in the [Complete Feature Datasheet](Complete-feature-datasheet#complete-list-of-features).

## Table of Contents

1. [Configuration](#1-configuration)
2. [Employees](#2-employees)
   - [Permissions](#21-permissions)
3. [Inventory](#3-inventory)
   - [Items](#31-items)
   - [Kits](#32-kits)
4. [Sales](#4-sales)
   1. [Register](#41-sales-normal)
   2. [Restaurant](#42-sales-restaurant)
5. [Expenses](#5-expenses)


# 1. Configuration

The **first step is to configure the store** at the **Office module** as the screen capture shows:
Please refers to the [Configuration](Configuration) wiki page to learn how to do that.

![MainScreen->Office->click](https://gitlab.com/webvnz/osposos/raw/master/debianOspos/screenshot-ospos-docs-1-startingmenu.png)

Older versions of the OSPOS don't have the Office module icon so will be as the following screen:

![MainScreen->StoreConfig->click](http://www.opensourceposguide.com/sites/default/files/configuration-new/welcome.jpg)

# 2. Employees

The second is **minimal employee users** that can login in the store. Since OSPOS 3.2.0 the **Employee** module are in the **back Office** store configuration interface. Go to the **Office** module and click on the **Employee** icon module as show here:

![MainScreen->Office->(click)->Employee->(click)](http://www.opensourceposguide.com/sites/default/files/employees-new/employees-tab.jpg)

Please refers to the [Employees](Employees) wiki page to learn how to do that.

# 2.1. Permissions

There are several tabs related to inventory control.  Before you can add inventory items into the system, though, you have to do some prep work.

# 3. Inventory

This module lets you load in the **Items** and **Kits**. This its a very complex module, Open Source Point of Sale are well integrated, from the **Sales** module, for instance, you can create new Inventory Item on the fly. Also a special **Sale** module its the **Return** sale mode that can manage also the Inventory Stock that can be covert in the next module documentation.
![items with kits in sale](https://user-images.githubusercontent.com/23066623/40659853-6ca19664-631d-11e8-8a64-172e7fbaad4d.png)
## 3.1. Items

Allow to employee load a product to the inventory so then have some stock. Start by clicking the Items button. This will load up your list of items if there any loaded. Please refers to the [Inventory Items](Inventory-Items) wiki page to learn how to do that.

## 3.2. Kits

Allow to employee group products from inventory so then can sell as combo product by example. Start by clicking the **Items Kits** module button. This will load up your list of item kits if there any configured. Please refers to the [Inventory Kits](Inventory-Kits) wiki page to learn how to do that.

# 4. Sales

This module lets you ring up Sales and Returns. This its a very complex module in the Open Source POS, due implicates many artifacts and modes, methods of payments and expend receipts. Also of course can print those.
There's also a temporary item feature for one-time sales that don't need to be tracked in inventory.

## 4.1. Sales Modes

Open Source Point of Sale includes a Cash Register / Point of Sale module. Please refers to the [Sales Modes](Sales-Modes) wiki page to learn how to do that.

## 4.2. Sales Restaurant

The OSPOS can be enabled to with a little change work as a POS system for Restaurant sale, by the Table feature. Please refers to the [Sales Restaurant](Sales-Restaurant) wiki page to learn how to do that.

![type:sale->then->tablechoose](https://user-images.githubusercontent.com/38166071/38460567-fa9a8bfa-3a92-11e8-968f-b08ce70851e6.gif)

# 5. Expenses

Since 3.2.0 there's a minimal but functional **Expenses** module, const of simple registration of the expenses, no accounting are linked on or any accounting operations. Please refers to the [Expenses](Expenses) wiki page to learn how to use it.

![](https://user-images.githubusercontent.com/38244786/39165614-f3a5c586-47a2-11e8-8775-89dd952bd678.png)