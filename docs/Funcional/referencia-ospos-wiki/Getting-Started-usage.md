
[← Volver a Inicio](Home) | [Instalación](Getting-Started-installations)

---

Después de haber [instalado](Getting-Started-installations) e iniciado sesión correctamente, verás una pantalla de menú principal.

Todos esos íconos son **Módulos** que se pueden habilitar o deshabilitar para cada **Empleado**. La lista completa de características por módulo se describe en la [Ficha Técnica Completa de Características](Complete-feature-datasheet#complete-list-of-features).

## Tabla de Contenidos

1. [Configuración](#1-configuration)
2. [Empleados](#2-employees)
   - [Permisos](#21-permissions)
3. [Inventario](#3-inventory)
   - [Artículos](#31-items)
   - [Kits](#32-kits)
4. [Ventas](#4-sales)
   1. [Caja Registradora](#41-sales-normal)
   2. [Restaurante](#42-sales-restaurant)
5. [Gastos](#5-expenses)


# 1. Configuración

El **primer paso es configurar la tienda** en el **módulo Oficina**, como muestra la captura de pantalla:
Consulta la página wiki de [Configuración](Configuration) para aprender a hacerlo.

![MainScreen->Office->click](https://gitlab.com/webvnz/osposos/raw/master/debianOspos/screenshot-ospos-docs-1-startingmenu.png)

Las versiones antiguas de OSPOS no tienen el ícono del módulo Oficina, por lo que la pantalla será como la siguiente:

![MainScreen->StoreConfig->click](http://www.opensourceposguide.com/sites/default/files/configuration-new/welcome.jpg)

# 2. Empleados

El segundo paso son los **usuarios empleados mínimos** que pueden iniciar sesión en la tienda. Desde OSPOS 3.2.0 el módulo de **Empleados** está en la interfaz de configuración de la **back Office** de la tienda. Ve al módulo **Oficina** y haz clic en el ícono del módulo **Empleados** como se muestra aquí:

![MainScreen->Office->(click)->Employee->(click)](http://www.opensourceposguide.com/sites/default/files/employees-new/employees-tab.jpg)

Consulta la página wiki de [Empleados](Employees) para aprender a hacerlo.

# 2.1. Permisos

Hay varias pestañas relacionadas con el control de inventario. Antes de poder agregar artículos de inventario al sistema, sin embargo, hay que hacer algo de trabajo previo.

# 3. Inventario

Este módulo te permite cargar los **Artículos** y **Kits**. Este es un módulo muy complejo; Open Source Point of Sale está bien integrado, desde el módulo de **Ventas**, por ejemplo, puedes crear un nuevo Artículo de Inventario sobre la marcha. Además, un módulo especial de **Venta** es el modo de venta de **Devolución**, que también puede gestionar el Stock de Inventario, lo cual se cubre en la siguiente documentación del módulo.
![items with kits in sale](https://user-images.githubusercontent.com/23066623/40659853-6ca19664-631d-11e8-8a64-172e7fbaad4d.png)
## 3.1. Artículos

Permite al empleado cargar un producto al inventario para luego tener stock. Comienza haciendo clic en el botón Artículos. Esto cargará tu lista de artículos si hay alguno cargado. Consulta la página wiki de [Artículos de Inventario](Inventory-Items) para aprender a hacerlo.

## 3.2. Kits

Permite al empleado agrupar productos del inventario para venderlos como producto combo, por ejemplo. Comienza haciendo clic en el botón del módulo **Kits de Artículos**. Esto cargará tu lista de kits de artículos si hay alguno configurado. Consulta la página wiki de [Kits de Inventario](Inventory-Kits) para aprender a hacerlo.

# 4. Ventas

Este módulo te permite registrar Ventas y Devoluciones. Este es un módulo muy complejo dentro del OSPOS, ya que involucra muchos elementos y modos, métodos de pago y emisión de recibos. También, por supuesto, se pueden imprimir.
También existe una función de artículo temporal para ventas puntuales que no necesitan registrarse en el inventario.

## 4.1. Modos de Venta

Open Source Point of Sale incluye un módulo de Caja Registradora / Punto de Venta. Consulta la página wiki de [Modos de Venta](Sales-Modes) para aprender a hacerlo.

## 4.2. Ventas en Restaurante

El OSPOS se puede habilitar, con un pequeño cambio, para funcionar como un sistema POS para ventas de Restaurante, mediante la función de Mesas. Consulta la página wiki de [Ventas en Restaurante](Sales-Restaurant) para aprender a hacerlo.

![type:sale->then->tablechoose](https://user-images.githubusercontent.com/38166071/38460567-fa9a8bfa-3a92-11e8-968f-b08ce70851e6.gif)

# 5. Gastos

Desde la versión 3.2.0 hay un módulo de **Gastos** mínimo pero funcional, que consiste en un simple registro de los gastos, sin vincularse a ninguna contabilidad ni operaciones contables. Consulta la página wiki de [Gastos](Expenses) para aprender a usarlo.

![](https://user-images.githubusercontent.com/38244786/39165614-f3a5c586-47a2-11e8-8775-89dd952bd678.png)