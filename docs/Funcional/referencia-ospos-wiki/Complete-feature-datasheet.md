
[← Volver a Inicio](Home)

---

* **Tabla índice de características**:
  * [Resumen Rápido de Características](#quick-overview-of-features)
  * [Lista Completa de Características](#complete-list-of-features)
* **Ver también**:
  * [Primeros Pasos - Instalaciones](Getting-Started-installations)
  * [Primeros Pasos - Uso](Getting-Started-usage)
  * [Información general de Impresión](Printing)
  * [Inicio de la Wiki](Home)

## Resumen Rápido De Características
----------------------------

Para ver la lista completa de características consulta la [Lista Completa de Características](#complete-list-of-features) más abajo, esto es una referencia rápida

| ❔ Característica deseada... | 🚀 Qué puede hacer... | ℹ️ Qué no puede hacer... |
| --- | --- | --- |
| Punto de Venta (POS) | **Vender** productos y/o servicios y aplicar **múltiples pagos** (incluyendo diferentes tipos) a la venta, incluye un módulo destacado de **Mesas de Restaurante**, Órdenes de Compra | Tipos de Transacción avanzados como Órdenes Especiales, Pedidos Pendientes (Back Orders) y **Apartados (Layaways)**, ventas de crédito con **contabilidad** |
| Gestión de Relación con Clientes (CRM) | Agregar clientes, importar/exportar desde CSV, **mantener perfiles de clientes** y ver el **historial de ventas** completo, y hacerles marketing vía **email marketing** y **marketing por SMS** | Cuentas por Cobrar / Cuentas de Casa, OSPOS tiene funciones de CRM pero **no funciones de Cuentas por Cobrar**, No hay **multi-tenencia** con un solo despliegue |
| Gestión de Inventario | Crear artículos de stock (cualquier producto) y artículos sin stock (artículos temporales), ...con campos personalizados. Importar/exportar desde CSV. Generar y leer códigos de barras | Matriz de Inventario, seguimiento de características de artículos, colores, materiales. Soporte de códigos QR (ver #1935) |
| Interfaz Web Multilingüe (i18n GUI) | Soporte multilingüe con regionalización, Tema de interfaz seleccionable basado en Bootstrap (Bootswatch) | El usuario final personaliza la interfaz como en Wordpress pero se puede hacer con poco código. No hay regionalización por atributos de artículos |
| Reportes | Clientes, Inventario y Transacciones (ventas o devoluciones) | Hay reportes, pero no un panel (dashboard) que muestre las Mejores Ventas, Mejores Proveedores, Artículos Más Vendidos, Mejores Clientes, etc. Ver #1433 |
| Módulo de Gastos | Agregar y resumir gastos básicos, sin necesidades contables | La contabilidad de gastos sobre las ventas está fuera del alcance |
| Tarjetas de Regalo y Recompensas | Emitir tarjetas de regalo como método de pago, Recompensas de clientes | Gestión contable de tarjetas de regalo, fechas de vencimiento en recompensas |

* **Ver también**:
  * [Primeros Pasos - Instalaciones](Getting-Started-installations)
  * [Primeros Pasos - Uso](Getting-Started-usage)
  * [Información general de Impresión](Printing)
  * [Inicio de la Wiki](Home)

La siguiente sección contiene la lista completa de cada característica y guías detalladas para usarlas:

# Lista Completa de Características
---------------------------

Un resumen rápido de esos módulos se puede encontrar en la página wiki de [Primeros Pasos - Uso](Getting-Started-usage); aquí se detallan todas las características del software Open Source Point Of Sale:

* Interfaz
   * Interfaz web responsiva, capaz de verse en móvil y escritorio
   * Inicio de sesión de acceso de Empleado
   * Control de permisos por módulo multiusuario
   * Opción de reCAPTCHA para proteger la página de inicio de sesión de ataques de fuerza bruta
   * [Multi-idioma](Developer-Translations#translation-status)
   * [Internacionalización de la interfaz de usuario](Developer-Translations#translation-status)
   * Tema de interfaz seleccionable basado en Bootstrap (Bootswatch)
* Empleados
   * Gestión de Empleados
   * Importación desde archivo CSV
   * Exportación a Hojas de Cálculo
* Ventas
   * Gestión de Ventas: Recibo
   * Gestión de Ventas: Devolución
   * Gestión de Ventas: Cotización
   * Gestión de Ventas: Suspender
   * Registro de Transacciones de Ventas
   * Impresión y/o envío por correo de Recibos y facturas de Venta
   * Recepción
   * [Artículos Temporales](Items#temporary-items)
   * Soporte de Tarjetas de Regalo
   * Recompensas de clientes
   * [Mesas de Restaurante](Sales-Restaurant)
   * Órdenes de Compra
* Inventario
   * Gestión de Proveedores
   * Gestión de Stock de Artículos
   * Kits de Artículos para grupos de Artículos
   * Soporte de códigos de barras, generación de códigos de barras
   * Conteo de inventario
   * Gestión de Artículos: Nombrado de Categorías e Imagen
   * Gestión de Impuestos de Artículos
   * Gestión de Precios de Artículos
   * Gestión de Stock para múltiples ubicaciones
   * [Importación de Artículos desde CSV](Import-data-from-CSV-file#importing-items)
   * Exportación a Hojas de Cálculo
* Clientes
   * Gestión de Clientes, incluyendo consentimiento de registro respetando la normativa GPDR #2003
   * [Importación de Clientes desde CSV](Import-data-from-CSV-file#importing-customers)
   * Exportación a Hojas de Cálculo
   * IVA e impuestos multi-nivel
* [Gastos](Expenses)
   * [Registro de Gastos con una interfaz simple y rápida](Expenses)
* Reportes
   * Reportes de ventas, órdenes, gastos, estado del inventario
   * Exportación a Hojas de Cálculo
* Comunicaciones
   * Mensajería (SMS)
   * Integración con Mailchimp
* [Impresión](Printing)
   * [Impresión en dispositivo](Printing#device-printing-support)
   * [Impresión de códigos de barras](Printing#barcode-printing)
   * Exportación de impresión en PDF
   * [Impresión de Etiquetas de Artículos](Printing#device-printing-support)
   * [Impresión Fiscal](Printing#fiscal-printing)
* Oficina de Tienda
   * Configuración de Tienda
   * Regionalización

## Ver también

  * [Primeros Pasos - Instalaciones](Getting-Started-installations)
  * [Primeros Pasos - Uso](Getting-Started-usage)
  * [Información general de Impresión](Printing)
  * [Inicio de la Wiki](Home)