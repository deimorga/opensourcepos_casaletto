# Alcance funcional — Carga masiva de artículos: crear y actualizar

> **Estado a 2026-08-31: requerimiento planteado, sin construir nada.**
>
> Nace de un problema inmediato y concreto: **Paraíso de la Canasta tiene 1.184 artículos cargados
> sin precio**, porque el cliente decidió ponerlos él mismo. Hoy **no hay ninguna forma razonable de
> que lo haga**: la importación por archivo sabe crear pero no actualizar, y la edición masiva pone
> el mismo valor a todos.
>
> **Se trabaja en paralelo, en otra conversación**, junto con
> `docs/Funcional/gestion-de-plataforma-y-negocios.md`. La línea del cliente supermercado —venta por
> peso y hardware— sigue por su cuenta.
>
> Diseño técnico en `docs/Tecnico/carga-masiva-de-articulos.md`.

---

## 1. El problema, en una frase

**Se puede cargar un catálogo. No se puede volver a bajarlo, corregirlo y subirlo.**

Y ese ida y vuelta es exactamente lo que un negocio necesita para mantener sus precios.

---

## 2. Qué pasa hoy, verificado

Revisado en el código, no supuesto.

### 2.1 La plantilla que se descarga viene vacía

El botón *"Descargar Plantilla para Importar desde CSV"* entrega **solo la fila de encabezados**.
Ni un artículo. Sirve para empezar de cero, no para corregir lo que ya existe.

### 2.2 Para actualizar hay que saber un número que nadie tiene

El archivo trae una primera columna, `Id`, y **es la que decide todo**:

- `Id` vacío → **crea** un artículo nuevo
- `Id` con número → **actualiza** ese artículo

Ese número es el identificador interno de la base de datos. **El cliente no lo conoce, no lo ve en
ninguna pantalla, y no hay forma de exportarlo.** Sin él, cada reimportación **duplica** el catálogo
en vez de corregirlo.

### 2.3 El botón de exportar de la grilla no resuelve esto

Existe un botón *"Export data"* en el listado de artículos, pero exporta **solo lo que está pintado
en la pantalla en ese momento** — la página actual, no las 1.184 filas. Y **no incluye la unidad de
medida**, porque esa columna ni siquiera aparece en el listado.

### 2.4 La edición masiva pone el MISMO valor a todos

Sirve para "poner a estos 40 artículos la categoría X". **No sirve para precios**, que es justo lo
que hace falta: cada artículo tiene el suyo.

Y le faltan campos. La edición masiva ofrece nombre, categoría, proveedor, costo, precio, punto de
reorden, descripción y dos opciones más — pero **no la unidad de medida**, aunque el sistema por
dentro sí la acepta. Quedó a medio cablear.

---

## 3. Lo que hace falta

### 3.1 Poder bajar el catálogo completo, tal como se puede volver a subir

Una exportación que traiga **todos** los artículos, con **las mismas columnas** que espera la
importación, incluido el `Id`. Ese archivo se abre en Excel, se llenan los precios, se sube, y el
sistema actualiza en vez de duplicar.

Es la pieza que falta. Con ella el problema de Paraíso se resuelve solo.

### 3.2 Poder actualizar sin depender de un número interno

Aun con la exportación, depender del `Id` es frágil: basta que alguien ordene mal el archivo o borre
esa columna para crear 1.184 duplicados.

**Debería poder emparejarse por el código del artículo** —el que el negocio imprime y teclea— que sí
conocen y sí ven. Con una regla clara de qué pasa cuando el código no existe: ¿se crea, o se reporta
como error?

### 3.3 Que la importación diga qué hizo

Hoy termina y devuelve al listado. Debería decir **cuántos creó, cuántos actualizó y cuántos falló, y
por qué** — con el número de fila. Subir un archivo de mil filas a ciegas y no saber qué pasó no es
aceptable para el cliente ni para nosotros dando soporte.

### 3.4 Completar la edición masiva

Agregarle la unidad de medida, que ya está soportada por dentro. Y decidir si vale la pena un
"aumentar todos los precios un X%", que es la operación que un negocio pide de verdad.

---

## 4. Decisiones que hay que tomar antes de construir

**4.1 ¿Por qué campo se emparejan las filas al actualizar?**
Opciones: solo `Id`; solo el código del artículo; o `Id` si viene y el código si no.
**Recomendación: la tercera.** Conserva lo que ya funciona y agrega lo que la gente puede usar.

**4.2 Si el código no existe en el sistema, ¿qué se hace?**
¿Crear el artículo, o rechazar la fila?
**Recomendación: que lo elija quien sube el archivo**, con una casilla. Los dos casos son legítimos:
"actualizar precios" quiere rechazar; "cargar catálogo nuevo" quiere crear.

**4.3 ¿Una fila mala tumba el archivo entero, o se salta?**
Hoy todo va dentro de una transacción.
**Recomendación: informar todo lo que está mal ANTES de aplicar nada**, y que el usuario decida.
Nada peor que aplicar 900 filas y fallar en la 901.

**4.4 ¿Una celda vacía significa "no cambiar" o "poner en blanco"?**
Es la decisión más peligrosa del lote. Si "vacío" significa "poner en blanco", un archivo con solo
la columna de precios llena **borraría los nombres**.
**Recomendación: vacío = no cambiar, siempre.** Y decirlo en la pantalla.

**4.5 ¿La exportación completa se limita por permisos?**
Baja el catálogo entero con costos. **Recomendación: sí, que exija el mismo permiso que la
importación.**

---

## 5. Alcance propuesto, en dos entregas

### Entrega 1 — Resolver lo de Paraíso
- **Exportar todos los artículos** en el formato exacto de la importación, con `Id`.
- **Informe de resultado**: creados, actualizados, fallidos y por qué.
- Confirmar la regla de 4.4 y dejarla escrita en la pantalla.

*Con esto el cliente ya puede cargar sus precios.*

### Entrega 2 — Que no dependa de un número interno
- **Emparejar por código de artículo** cuando no viene `Id`.
- **Validar antes de aplicar**, con la lista de problemas por fila.
- Completar la **edición masiva** con la unidad de medida.

---

## 6. Lo que NO incluye

- **Importar clientes, proveedores o ventas.** Solo artículos.
- **Sincronización automática** con otro sistema. Es un archivo que alguien sube.
- **Cambiar cómo se calculan precios o impuestos.** Se cargan valores, no reglas.
- **Cargar existencias.** Eso es toma de inventario y va aparte.

---

## 7. Cómo sabremos que quedó bien

- Un cliente con 1.184 artículos **baja, llena precios en Excel, sube, y quedan aplicados**.
- Subir dos veces el mismo archivo **no duplica nada**.
- Un archivo con un error dice **en qué fila** y no aplica nada a medias.
- Un archivo con solo la columna de precios **no borra los nombres**.
- La unidad de medida sobrevive al ida y vuelta: un artículo en kilogramo **sigue en kilogramo**.
