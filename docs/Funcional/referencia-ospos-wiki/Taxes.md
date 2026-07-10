[← Volver a Configuración](Configuration) | [Inicio](Home)

---

Esta página explica cómo configurar los impuestos en la aplicación. Para GST de India específicamente, consulta [Configuración de GST India](India-GST).

## Tabla de Contenidos

- [Resumen del Sistema de Impuestos](#tax-system-overview)
- [Impuestos del Sistema Base](#base-system-taxes)
- [Impuesto Basado en Destino](#destination-based-tax)
- [Definiciones de Impuestos](#tax-definitions)
- [Configurar Impuestos](#configure-taxes)
- [Solución de Problemas](#troubleshooting)

## Resumen del Sistema de Impuestos

La aplicación admite múltiples configuraciones de impuestos:

| Tipo de Impuesto | Caso de Uso | Funcionalidades |
|----------|----------|----------|
| **Impuesto del Sistema Base** | Requisitos de impuestos simples | Hasta 2 tasas de impuesto por artículo, impuesto sobre ventas o IVA |
| **Impuesto Basado en Destino** | Múltiples jurisdicciones fiscales | Impuesto según la ubicación del cliente, impuestos estatales/de condado/de ciudad en EE. UU. |
| **GST India** | Impuesto sobre Bienes y Servicios de India | Soporte para CGST, SGST, IGST |

## Impuestos del Sistema Base

Si tus requisitos de impuestos son simples, usa el enfoque de impuesto del sistema base.

**Funcionalidades:**
- Máximo de 2 tasas de impuesto por artículo
- Admite impuesto sobre ventas (se agrega al precio) o IVA (incluido en el precio)
- **No se pueden mezclar impuesto sobre ventas e IVA** - elige un solo enfoque

**Importante:** Una vez que comienzas a realizar ventas, no puedes cambiar entre impuesto sobre ventas e IVA sin generar reportes incorrectos.

## Impuesto Basado en Destino

En Estados Unidos, el impuesto sobre ventas puede basarse en:
- **Dirección de origen** - Ubicación de la tienda
- **Dirección de envío** - A dónde se envían los productos
- **Dirección de facturación** - Dirección de facturación del cliente

Usa esta funcionalidad si necesitas recaudar y reportar impuestos en múltiples jurisdicciones fiscales.

## Definiciones de Impuestos

| Término | Definición |
|------|------------|
| **Código de Impuesto Predeterminado** | Código de impuesto para la ubicación de la tienda (también llamado Código de Impuesto de Origen). Solo uno por empresa. |
| **Código de Impuesto** | Código asignado a un cliente que identifica qué jurisdicciones fiscales aplican a su venta. |
| **Tasa de Impuesto** | Valor porcentual (hasta 4 decimales) para una jurisdicción fiscal. |
| **Código HSN** | Nomenclatura del Sistema Armonizado - código de categoría de producto adoptado internacionalmente. |
| **Categoría de Impuesto de Artículo** | Categoría para artículos que requieren tasas de impuesto diferentes (por ejemplo, alcohol con una tasa más alta). |
| **Impuesto IVA** | Impuesto rastreado tanto para ventas como para recepciones (Impuesto al Valor Agregado). |
| **Impuesto Incluido** | El impuesto está incluido en el precio de venta. |
| **Impuesto Excluido** | El impuesto se agrega al precio de venta. |
| **Decimales de Impuesto** | Número de decimales para el almacenamiento del monto de impuesto. |
| **Impuesto en Cascada** | Segundo impuesto calculado sobre el total de la factura más el primer impuesto (IVA). No es compatible actualmente. |
| **Grupo de Impuestos** | Resumen de los impuestos recaudados para una o más autoridades fiscales en una venta. |

## Configurar Impuestos

### Paso 1: Habilitar el Módulo de Impuestos

1. Ve a **Oficina → Empleados**
2. Edita al empleado que administrará los impuestos
3. Marca el permiso del módulo **Impuestos** (no el de Reportes de impuestos)

### Paso 2: Configurar el Tipo de Impuesto

Ve a **Oficina → Configuración de Tienda → General**:

| Configuración | Valor | Notas |
|---------|-------|-------|
| **Impuesto Incluido** | ☑ Marcado | Úsalo para IVA (impuesto incluido en el precio) |
| | ☐ Desmarcado | Úsalo para impuesto sobre ventas (impuesto agregado al precio) |
| **Impuesto Basado en Destino** | ☑ Marcado | Habilita el cálculo de impuestos basado en destino |
| | ☐ Desmarcado | Usa tasas de impuesto simples a nivel de artículo |

### Paso 3: Establecer el Código de Impuesto de Origen Predeterminado

1. Crea un código de impuesto para la ubicación de tu tienda
2. Ve a **Oficina → Configuración de Tienda → General**
3. Ingresa el código de impuesto en **Código de Impuesto de Origen Predeterminado**

### Paso 4: Agregar Tasas de Impuesto

Ve a **Oficina → Impuestos** y agrega cada jurisdicción fiscal:

1. **Código de Impuesto** - Identificador único (por ejemplo, `CA` para California)
2. **Nombre del Código de Impuesto** - Descripción (por ejemplo, `California State Tax`)
3. **Tipo de Código de Impuesto** - Elige:
   - `Sales Tax` - El impuesto se calcula por artículo y luego se totaliza
   - `Sales Tax by Invoice` - El impuesto se calcula sobre el total por categoría
4. **Ciudad** y **Estado** - Ubicación para la búsqueda de impuestos según el cliente
5. **Tasa de Impuesto** - Porcentaje (por ejemplo, `7.25` para 7.25%)
6. **Código de Redondeo** - Cómo redondear los montos fraccionarios
7. **Excepciones de Categoría** - Sobrescribe la tasa estándar para categorías específicas

### Paso 5: Asignar Categorías de Impuesto a los Artículos

Para artículos con tasas de impuesto diferentes (por ejemplo, alcohol, servicios):

1. Ve a **Oficina → Artículos**
2. Edita el artículo
3. Asigna la **Categoría de Impuesto** correspondiente

## Reglas y Restricciones

> **Importante:** Una vez habilitado el Impuesto de Destino, los campos de tasa de impuesto predeterminada en los artículos ya no se usan para el cálculo de impuestos.

| Regla | Comportamiento |
|------|------------|
| Sin código de impuesto de cliente | Se usa el Código de Impuesto de Origen (predeterminado de la tienda) |
| El cliente tiene código de impuesto | Se usa el código de impuesto asignado al cliente |
| Ventas por Recibo | El impuesto se basa en el código de impuesto predeterminado |
| Ventas por Factura | El impuesto se basa en la ciudad/estado del cliente |
| Tasas de impuesto bloqueadas | Las tasas quedan congeladas cuando se completa la venta |

## Asignación de Código de Impuesto de Cliente

Los códigos de impuesto de los clientes se asignan según esta prioridad:

1. **Código de impuesto asignado al cliente** - Si el cliente tiene un código de impuesto específico
2. **Ciudad + estado del cliente** - Si el código de impuesto coincide con la ubicación del cliente
3. **Estado del cliente** - Si el código de impuesto coincide con el estado del cliente
4. **Código de impuesto de origen predeterminado** - Alternativa por defecto a la ubicación de la tienda

## GST India

El Impuesto sobre Bienes y Servicios de India (introducido en 2017) tiene requisitos similares al Impuesto Basado en Destino de EE. UU.

Para la configuración de GST de India, consulta [Configuración de GST India](India-GST).

## Solución de Problemas

| Problema | Solución |
|-------|----------|
| El impuesto no se calcula | Verifica que "Impuesto Basado en Destino" esté habilitado en Configuración de Tienda |
| Tasa de impuesto incorrecta | Verifica que el cliente tenga asignado el código de impuesto correcto |
| Las tasas de impuesto cambiaron después de la venta | Las tasas de impuesto se bloquean cuando se completa la venta (por diseño) |
| Reportes mezclan IVA e impuesto sobre ventas | No se puede cambiar entre tipos de impuesto después de haber realizado ventas (por diseño) |
| No se aplica el impuesto de categoría | Verifica que el artículo tenga asignada la categoría de impuesto correcta |

## Ver También

- [Configuración de GST India](India-GST)
- [Configuración](Configuration)
- [Primeros Pasos - Uso](Getting-Started-usage)
