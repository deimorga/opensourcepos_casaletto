[← Volver a Configuración](Configuration) | [Inicio](Home)

---

Esta guía explica cómo configurar el Impuesto sobre Bienes y Servicios de India (GST) en la aplicación.

## Resumen

El GST de India (introducido en 2017) usa 3 jurisdicciones fiscales principales:

| Jurisdicción | Descripción |
|--------------|-------------|
| **CGST** | Impuesto Central sobre Bienes y Servicios |
| **SGST** | Impuesto Estatal sobre Bienes y Servicios |
| **IGST** | Impuesto Integrado sobre Bienes y Servicios |

A diferencia de EE. UU., que tiene numerosas jurisdicciones fiscales, India tiene solo estas 3, lo que hace más simple su configuración con la funcionalidad de Impuesto Basado en Destino.

## Asignación de Código de Impuesto

Los códigos de impuesto se asignan a los clientes para identificar qué jurisdicciones fiscales aplican:

- **El cliente tiene código de impuesto** - Se usa ese código de impuesto
- **El cliente no tiene código de impuesto** - Se usa el estado del cliente para determinar la jurisdicción fiscal
- **Predeterminado** - Se usa el código de impuesto predeterminado de la empresa

> **Importante:** Para imprimir una factura fiscal (tax invoice) para un cliente, debes ingresar su GSTIN (Número de Identificación GST).

## Requisitos Previos

Antes de configurar el GST de India:

1. Habilita **Impuesto Basado en Destino** (consulta [Impuestos](Taxes))
2. Comprende las categorías y jurisdicciones fiscales
3. Ten a mano el GSTIN de tu empresa
4. Conoce qué tasas de tramo (slab) de impuesto aplican a tus productos

## Paso 1: Habilitar Códigos HSN

Ve a **Oficina → Configuración → General**:

| Configuración | Valor |
|---------|-------|
| Incluir soporte para Códigos HSN | ☑ Marcado |

Los códigos HSN (Nomenclatura del Sistema Armonizado) clasifican los productos para fines fiscales.

## Paso 2: Configurar los Ajustes de Impuestos

Ve a **Oficina → Configuración → Impuestos**:

| Configuración | Valor | Notas |
|---------|-------|-------|
| Tax Id | Tu GSTIN | Ingresa el Número de Identificación GST de tu empresa |
| Impuesto Incluido | ☑ o ☐ | Marca si los precios incluyen impuesto (estilo IVA) |
| Usar Impuesto Basado en Destino | ☑ Marcado | Requerido para GST |

## Paso 3: Crear Códigos de Impuesto

Ve a **Oficina → Impuestos**:

### Crear Jurisdicciones Fiscales

Crea códigos de impuesto para cada jurisdicción que requiera tu negocio:

1. **El código de impuesto de tu empresa** (requerido) - Representa tu ubicación
2. **CGST** - Jurisdicción de GST Central
3. **SGST** - Jurisdicción de GST Estatal
4. **IGST** - Jurisdicción de GST Integrado
5. **Otros** - Cualquier jurisdicción adicional específica de un estado

### Crear Categorías de Impuesto

Crea una categoría de impuesto para cada tramo (slab) de impuesto:

| Tramo | Nombre de Categoría |
|------|---------------|
| 5% | Tax Category 5% |
| 12% | Tax Category 12% |
| 18% | Tax Category 18% |
| 28% | Tax Category 28% |

### Crear Tasas de Impuesto

Define las tasas de impuesto para cada combinación:
- Código de Impuesto (jurisdicción)
- Categoría de Impuesto (tramo)
- Tasa de Impuesto (porcentaje)

## Paso 4: Establecer Valores Predeterminados

Ve a **Oficina → Configuración → Impuestos**:

| Configuración | Valor |
|---------|-------|
| Categoría de Impuesto Predeterminada | Tu tramo de impuesto más común |
| Jurisdicción Fiscal Predeterminada | El estado de tu empresa |
| Código de Impuesto Predeterminado | El código de impuesto de tu empresa |

## Paso 5: Configurar los Artículos

Para cada artículo:

1. Ve a **Artículos → Administrar Artículos**
2. Edita el artículo
3. Ingresa el **Código HSN** (clasificación del producto)
4. Selecciona la **Categoría de Impuesto** (tramo de impuesto)
5. Haz clic en **Enviar**

> **Nota:** Si un artículo no tiene categoría de impuesto, se usará la Categoría de Impuesto Predeterminada.

## Paso 6: Configurar los Clientes

Para cada cliente:

1. Ve a **Clientes → Administrar Clientes**
2. Edita el cliente
3. Asigna el **Código de Impuesto** (si es diferente al predeterminado)
4. Ingresa el **GSTIN** (si necesitan facturas fiscales)
5. Haz clic en **Enviar**

> **Factura Fiscal:** Si se proporciona el GSTIN, se genera una factura fiscal (tax invoice). De lo contrario, se genera una factura estándar.

## Ejemplo de Configuración

### Ejemplo: Empresa en Maharashtra

**Códigos de Impuesto:**
- `MH-CGST` - CGST de Maharashtra (9%)
- `MH-SGST` - SGST de Maharashtra (9%)
- `IGST` - GST Interestatal (18%)

**Categorías de Impuesto:**
- `GST-5` - tramo 5%
- `GST-12` - tramo 12%
- `GST-18` - tramo 18%
- `GST-28` - tramo 28%

**Tasas de Impuesto:**
| Código de Impuesto | Categoría | Tasa |
|----------|----------|------|
| MH-CGST | GST-18 | 9% |
| MH-SGST | GST-18 | 9% |
| IGST | GST-18 | 18% |

### Para Venta Dentro de Maharashtra:
- CGST: 9%
- SGST: 9%
- Total: 18%

### Para Venta Fuera de Maharashtra (Interestatal):
- IGST: 18%

## Solución de Problemas

| Problema | Solución |
|-------|----------|
| La factura fiscal no se imprime | Verifica que el cliente tenga ingresado el GSTIN |
| Tasa de impuesto incorrecta | Verifica la categoría de impuesto del artículo y el código de impuesto del cliente |
| El código HSN no aparece | Habilita "Incluir soporte para Códigos HSN" en la configuración General |
| Aparecen múltiples impuestos | Verifica que las tasas de impuesto estén correctamente asignadas a las jurisdicciones |

## Ver También

- [Impuestos](Taxes) - Sistema de impuestos base e Impuesto Basado en Destino
- [Configuración](Configuration) - Configuración de la tienda
- [Artículos](Items) - Gestión de artículos de inventario
