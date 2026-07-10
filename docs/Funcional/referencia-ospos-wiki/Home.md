# Open Source Point of Sale

[Demo](https://demo.opensourcepos.org/) | [Requisitos](Minimum-Server-Requirements) | [Instalación](Getting-Started-installations) | [Guía de Uso](Getting-Started-usage)

---

Una **aplicación de Punto de Venta (POS) basada en web** para gestionar inventario y llevar el control de ventas. Incluye impresión de recibos, cotizaciones y facturación.

## Características Principales

| Categoría | Características |
|----------|----------|
| **Ventas** | Caja registradora de Punto de Venta, Ventas/Devoluciones con registro de transacciones, Impresión de recibos |
| **Inventario** | Gestión de stock, Artículos y Kits, Generación de códigos de barras, Toma de inventario |
| **Clientes** | CRM, Base de datos de clientes/proveedores, Tarjetas de regalo, Recompensas |
| **Restaurante** | Gestión de mesas, Impresión en cocina |
| **Finanzas** | Registro de gastos, Función de cierre de caja, Soporte multi-moneda |
| **Reportes** | Reportes de ventas, Estado del inventario, Reportes personalizados |

Consulta la [Ficha Técnica Completa de Características](Complete-feature-datasheet) para más información.

## Resumen de la Documentación

### Documentación de Usuario

| Tema | Descripción |
|-------|-------------|
| [Primeros Pasos - Instalación](Getting-Started-installations) | Instalar en tu servidor |
| [Primeros Pasos - Uso](Getting-Started-usage) | Aprende a usar la aplicación |
| [Requisitos Mínimos del Servidor](Minimum-Server-Requirements) | Requisitos del sistema |
| [Soporte de Hardware](Supported-hardware-datasheet) | Impresoras, escáneres, etc. soportados |
| [Configuración de Impresión](Printing) | Configurar impresoras de recibos |
| [Códigos de Barras con Peso](Weighted-Barcodes) | Configurar códigos de barras de báscula |

### Documentación para Desarrolladores

| Tema | Descripción |
|-------|-------------|
| [Índice de Desarrollo](Development-Index) | Índice de documentación técnica |
| [Entorno de Desarrollo](Development-Environment) | Configurar el entorno de desarrollo |
| [Registro de Errores](Error-Logging) | Depuración y registro |
| [Agregar Traducciones](Adding-translations) | Ayuda a traducir |
| [Soporte de Localización](Localisation-support) | Configuración de idioma |

### Guías de Instalación

| Guía | Plataforma |
|-------|----------|
| [Instalación con Docker](Getting-Started-installations#local-docker-install) | Docker (recomendado) |
| [Instalación LAMP Local](Getting-Started-installations#local-deploy-install-for-unixlinux-environments) | Linux/Mac |
| [LEMP (Nginx)](Local-Deployment-using-LEMP) | Linux/Nginx |
| [XAMPP](XAMPP-Installation) | Windows/Mac (solo pruebas) |
| [Raspberry Pi](Raspberry-Pi-Installation) | Raspberry Pi |
| [Ubuntu 24.04/22.04](Ubuntu-24.04-22.04-Installation) | Ubuntu/Mint |
| [Windows Local](Windows-Local-Installation) | Localhost en Windows |

## Soporte

- **Crear un ticket:** [GitHub Issues](https://github.com/opensourcepos/opensourcepos/issues/new)
- **Chat:** [Gitter](https://app.gitter.im/#/room/#opensourcepos_Lobby:gitter.im)
- **Wiki:** ¡La estás leyendo!

## Requisitos del Sistema

| Componente | Mínimo | Recomendado |
|-----------|---------|-------------|
| **PHP** | 8.2 | 8.3 - 8.4 |
| **MySQL/MariaDB** | 5.7 / 10.x | 8.0+ / 10.6+ |
| **Apache** | 2.4 | 2.4.64+ |
| **Navegador** | Firefox 34+, Chrome 40+ | Navegador moderno |

⚠️ **PHP ≤ 8.1 NO está soportado**

Consulta [Requisitos Mínimos del Servidor](Minimum-Server-Requirements) para más detalles.

## Licencia

Open Source Point of Sale está licenciado bajo los términos MIT. La firma del pie de página debe mantenerse en cada página.

Consulta el archivo [LICENSE](https://github.com/opensourcepos/opensourcepos/blob/master/LICENSE) para más detalles.

---

## Donaciones

Si te gusta este proyecto y estás generando dinero con él, considera [invitarnos un café](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=MUN6AEG7NY6H8) para ayudar a mantener el desarrollo en marcha.

[![Donate](https://www.paypalobjects.com/en_US/i/btn/btn_donate_LG.gif)](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=MUN6AEG7NY6H8)