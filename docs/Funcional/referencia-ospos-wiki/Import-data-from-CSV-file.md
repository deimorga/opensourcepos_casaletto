
[← Volver a la Guía de Uso](Getting-Started-usage) | [Inicio](Home)

---

## Funcionalidades de importación soportadas

* [Importación de artículos](#importing-items)
* [Importación de clientes](#importing-customers)

## Importación de artículos

Puede insertar manualmente cada producto uno por uno, pero esto se vuelve engorroso con grandes cantidades de productos. Por esta razón, se introdujo una función de importación de Valores Separados por Comas (CSV).

## Requisitos previos y formato de archivo

Archivo CSV conforme a la especificación RFC-4180, con comas como delimitador. Consulte https://en.wikipedia.org/wiki/Comma-separated_values para más detalles.

OSPOS generará su plantilla CSV según la estructura de datos actual. Puede generar este archivo yendo a Artículos->Importar CSV y haciendo clic en el enlace (Descargar plantilla de importación CSV (CSV)).

Las filas vacías serán ignoradas.

El software procesará los archivos CSV con o sin la Marca de Orden de Bytes (BOM).

## Columnas de artículos y mapeo de campos

```
0 - barcode
1 - item name
2 - category
3 - supplier id
4 - cost
5 - price
6 - tax 1 name
7 - tax 1 rate
8 - tax 2 name
9 - tax 2 rate
10 - reorder level
11 - item description
12 - allow alt description flag (1 to allow, otherwise keep this blank/empty)
13 - is serialized flag (1 is serialized, otherwise keep this blank/empty)
14 - custom 1
15 - custom 2
16 - custom 3
17 - custom 4
18 - custom 5
19 - custom 6
20 - custom 7
21 - custom 8
22 - custom 9
23 - custom 10
24 - item image
25 - location 1
26 - quantity 1
```

# Importación de clientes

Puede insertar manualmente cada cliente uno por uno en el módulo de Ventas al registrar una venta, pero cuando tiene muchos clientes esto deja de ser práctico.

## Formato de archivo y requisitos previos

Usando un archivo CSV plano.

# Ver también

* [Guía de uso inicial](Getting-Started-usage)
* [Hoja de datos completa de funcionalidades](Complete-feature-datasheet#complete-list-of-features)
