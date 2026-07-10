[← Volver a Configuración](Configuration) | [Inicio](Home)

---

# Atributos de artículos

Esta página explica cómo usar atributos personalizados para artículos, ventas y recepciones.

> **Nota:** Los campos antiguos `custom1` a `custom10` están en desuso. Use el sistema de Atributos en su lugar.

## Descripción general

Los atributos le permiten agregar campos personalizados a artículos, ventas y recepciones. Cada atributo puede ser:

- **Texto** - Valores de texto libre
- **Lista desplegable** - Lista predefinida de valores
- **Decimal** - Valores numéricos
- **Fecha** - Valores de fecha

Puede definir en qué secciones aparecen los atributos:
- **Artículos** - Ver y editar en los detalles del artículo
- **Ventas** - Ver y editar durante las transacciones de venta
- **Recepciones** - Ver y editar durante la recepción

## Acceder al módulo de Atributos

1. Vaya a **Oficina → Empleados**
2. Edite los permisos del empleado
3. Marque el permiso del módulo **Atributos**

## Crear definiciones de atributos

Vaya a **Oficina → Atributos**:

### Campos de definición

| Campo | Descripción |
|-------|-------------|
| **Nombre de la definición** | Nombre visible del atributo (por ejemplo, "Marca", "Color", "Talla") |
| **Tipo de definición** | `TEXT`, `DROPDOWN`, `DECIMAL` o `DATE` |
| **Unidad de la definición** | Unidad de medida (por ejemplo, "kg", "cm", "%" para atributos numéricos) |
| **Mostrar en Artículos** | ☑ Mostrar y editar en los detalles del artículo |
| **Mostrar en Ventas** | ☑ Mostrar y editar en las transacciones de venta |
| **Mostrar en Recepciones** | ☑ Mostrar y editar en los formularios de recepción |

### Valores de lista desplegable

Para atributos de tipo `DROPDOWN`:

1. Cree la definición del atributo
2. Haga clic en el atributo para editarlo
3. Agregue los valores de la lista desplegable en la sección de valores
4. Cada valor puede usarse múltiples veces en distintos artículos

## Usar atributos en artículos

Una vez que los atributos están definidos con "Mostrar en Artículos" marcado:

1. Vaya a **Artículos → Administrar artículos**
2. Edite un artículo
3. Desplácese hasta la sección **Atributos**
4. Ingrese los valores para cada atributo
5. Haga clic en **Enviar**

### Importar vía CSV

Al importar artículos vía CSV, incluya columnas de atributos:

```
attribute_1_name,attribute_1_value
attribute_2_name,attribute_2_value
```

**Ejemplo:**

```
item_name,category,Brand,Color,Size
Widget A,Electronics,Samsung,Black,Large
Widget B,Electronics,LG,White,Small
```

## Usar atributos en ventas

Para mostrar atributos en ventas:

1. Cree la definición del atributo
2. Marque **Mostrar en Ventas**
3. Durante una venta, el atributo aparecerá en los detalles del artículo

Los atributos se pueden editar durante la venta y se guardarán junto con la transacción.

## Usar atributos en recepciones

Para mostrar atributos en recepciones:

1. Cree la definición del atributo
2. Marque **Mostrar en Recepciones**
3. Durante la recepción, el atributo aparecerá en los detalles del artículo

Esto le permite actualizar los valores de los atributos al recibir nueva existencia.

## Estructura de la base de datos

Los atributos utilizan tres tablas de base de datos:

| Tabla | Propósito |
|-------|---------|
| `attribute_definitions` | Almacena las definiciones de atributos (nombre, tipo, indicadores) |
| `attribute_values` | Almacena los valores de la lista desplegable para el tipo DROPDOWN |
| `attribute_links` | Vincula los atributos con artículos/ventas/recepciones |

### Indicadores de atributos

Los atributos admiten los siguientes indicadores de visibilidad:

| Indicador | Valor | Se muestra en |
|------|-------|----------|
| SHOW_IN_ITEMS | 1 | Lista y edición de artículos |
| SHOW_IN_SALES | 2 | Transacciones de venta |
| SHOW_IN_RECEIVINGS | 4 | Formularios de recepción |

Estos se pueden combinar (por ejemplo, `5` = Artículos + Recepciones).

## Ejemplos

### Ejemplo 1: Atributo Marca

```
Definition Name: Brand
Type: TEXT
Show in Items: ☑
Show in Sales: ☑
Show in Receivings: ☐
```

Los artículos tendrán un campo Marca, visible durante las ventas.

### Ejemplo 2: Lista desplegable de Talla

```
Definition Name: Size
Type: DROPDOWN
Values: Small, Medium, Large, XL, XXL
Show in Items: ☑
Show in Sales: ☑
Show in Receivings: ☑
```

Seleccione entre las tallas predefinidas en todo el sistema.

### Ejemplo 3: Peso (Decimal)

```
Definition Name: Weight
Type: DECIMAL
Unit: kg
Show in Items: ☑
Show in Sales: ☐
Show in Receivings: ☑
```

Rastree el peso en kilogramos, editable en recepciones.

## Endpoints de la API

Para desarrolladores, se puede acceder a los atributos mediante:

| Endpoint | Descripción |
|----------|-------------|
| `GET /attributes` | Lista todas las definiciones de atributos |
| `POST /attributes/save_value` | Guarda el valor de un atributo para un artículo |
| `POST /attributes/save_definition` | Guarda la definición de un atributo |
| `DELETE /attributes/delete_value` | Elimina un valor de la lista desplegable |

## Solución de problemas

| Problema | Solución |
|-------|----------|
| Los atributos no se muestran | Verifique los indicadores de visibilidad en la definición |
| Faltan valores de la lista desplegable | Agregue valores después de crear el tipo DROPDOWN |
| No se pueden editar los atributos | Verifique que el empleado tenga el permiso de Atributos |
| Falla la importación | Verifique que los nombres de las columnas del CSV coincidan con los nombres de las definiciones de atributos |

## Ver también

- [Artículos](Items)
- [Artículos de inventario](Inventory-Items)
- [Importar desde CSV](Import-data-from-CSV-file)
- [Código fuente del modelo de Atributos](https://github.com/opensourcepos/opensourcepos/blob/master/app/Models/Attribute.php)
