# Referencia — Wiki oficial de OpenSourcePOS (importada)

Copia local completa del wiki oficial de [opensourcepos/opensourcepos](https://github.com/opensourcepos/opensourcepos/wiki), importada el **2026-07-09** (clonado directo de `opensourcepos.wiki.git`, 49 páginas).

## Por qué está acá

Dejamos de depender de la wiki externa para diseñar y evolucionar el producto. Esta carpeta es el **punto de partida congelado** de la documentación funcional del proyecto original — de acá en adelante, cualquier cambio de comportamiento que hagamos para Casaletto se documenta en `docs/Funcional/` (fuera de esta subcarpeta), no acá. Estos archivos **no se actualizan automáticamente** con la wiki de upstream; son una foto del momento en que forkeamos el proyecto funcionalmente.

## Cómo usar esto

- Antes de tocar un módulo, revisar acá cómo lo documentó el proyecto original (si existe la página).
- Si el comportamiento cambia en nuestro fork, **no editar estos archivos** — crear/actualizar el documento correspondiente en `docs/Funcional/` (raíz) describiendo el comportamiento real de Casaletto, y referenciar la página original acá como contexto histórico si hace falta.
- Contenido licenciado bajo los mismos términos que el proyecto OpenSourcePOS (MIT) — ver `LICENSE` en la raíz del repo.

## Índice por tema

**Ventas / Register**
- [Sales.md](Sales.md) — flujo general de ventas/register
- [Sales-Modes.md](Sales-Modes.md) — modos de venta (recibo, factura, cotización, devolución)
- [Sales-Restaurant.md](Sales-Restaurant.md) — modo mesas (relevante para el requerimiento de pestañas en paralelo, ver `../ventas-en-paralelo-pestanas.md`)
- [Work-Orders.md](Work-Orders.md) — órdenes de trabajo
- [Pos.md](Pos.md)
- [Payment-Processing.md](Payment-Processing.md)
- [Alternate-Currency-Receipts-and-Invoices.md](Alternate-Currency-Receipts-and-Invoices.md)

**Inventario / Items**
- [Items.md](Items.md), [Item-Kits.md](Item-Kits.md), [Item-Attributes.md](Item-Attributes.md)
- [Inventory-Items.md](Inventory-Items.md), [Inventory-Kits.md](Inventory-Kits.md)
- [Stocktake.md](Stocktake.md)
- [Weighted-Barcodes.md](Weighted-Barcodes.md)
- [Import-data-from-CSV-file.md](Import-data-from-CSV-file.md)

**Personas y permisos**
- [Employees.md](Employees.md)
- [Menu-and-Permissions.md](Menu-and-Permissions.md)

**Finanzas**
- [Expenses.md](Expenses.md)
- [Purchasing.md](Purchasing.md)
- [Taxes.md](Taxes.md)
- [India-GST.md](India-GST.md)

**Configuración general**
- [Configuration.md](Configuration.md)
- [Localisation-support.md](Localisation-support.md)
- [Printing.md](Printing.md), [Printing-support.md](Printing-support.md)
- [Email-Configuration.md](Email-Configuration.md)
- [Supported-hardware-datasheet.md](Supported-hardware-datasheet.md)

**Referencia general del producto**
- [Home.md](Home.md) — portada original de la wiki
- [Complete-feature-datasheet.md](Complete-feature-datasheet.md) — listado completo de features
- [Getting-Started-usage.md](Getting-Started-usage.md)
- [Database-Layout.md](Database-Layout.md)

**Instalación / despliegue (no funcional, contexto técnico upstream)**
- [Getting-Started-installations.md](Getting-Started-installations.md)
- [Minimum-Server-Requirements.md](Minimum-Server-Requirements.md)
- [Webserver-Overview.md](Webserver-Overview.md)
- [Ubuntu-24.04-22.04-Installation.md](Ubuntu-24.04-22.04-Installation.md)
- [XAMPP-Installation.md](XAMPP-Installation.md)
- [IIS-Deployment.md](IIS-Deployment.md)
- [Local-Deployment-using-LEMP.md](Local-Deployment-using-LEMP.md)
- [Local-Deployment-using-MAMP-for-Windows.md](Local-Deployment-using-MAMP-for-Windows.md)
- [Windows-Local-Installation.md](Windows-Local-Installation.md)
- [Raspberry-Pi-Installation.md](Raspberry-Pi-Installation.md)
- [Upgrading-to-MySQL-8.x.md](Upgrading-to-MySQL-8.x.md)

**Desarrollo / contribución upstream (no aplica a nuestro flujo, ver `docs/branching-deploy-policy` en su lugar)**
- [Development-Environment.md](Development-Environment.md)
- [Development-Index.md](Development-Index.md)
- [Error-Logging.md](Error-Logging.md)
- [How-to-add-a-new-report.md](How-to-add-a-new-report.md)
- [Adding-translations.md](Adding-translations.md)
- [Developer-Translations.md](Developer-Translations.md)
- [Milestones-and-Labels.md](Milestones-and-Labels.md)
- [Why-my-issue-was-closed?.md](Why-my-issue-was-closed%3F.md)

**Navegación original de la wiki**
- [_Sidebar.md](_Sidebar.md), [_Footer.md](_Footer.md)
