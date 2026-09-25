# Artículos que son ingredientes de recetas

> **Estado (2026-09-24):** corrección de datos aplicada en Casaletto; protección contra el borrado y
> registro de quién borra, construidos (ver §4 para su estado de despliegue).
>
> Documento hermano: `docs/Tecnico/articulos-e-ingredientes.md`.

---

## 1. Qué pasó

Al vender o cotizar el **SANDWICH 4 CARNES** salía «Falló al agregar artículos para venta». La receta
tenía 14 ingredientes y 3 estaban **borrados** en Artículos. La caja no puede agregar un artículo
borrado: cobraba bien el sándwich, pero **no descontaba del inventario** esos ingredientes.

No era un caso aislado: **13 recetas activas usaban 9 ingredientes borrados**, algunos desde julio.

## 2. Por qué pasó

Para cambiar el nombre o el precio de un producto, el equipo **borraba el artículo viejo** (que tenía
código, por ejemplo C10143) **y creaba uno nuevo sin código**. El sistema no avisaba que el viejo era
ingrediente de recetas, así que las recetas quedaron apuntando a artículos borrados.

El historial del sistema muestra que los borrados del 30 de agosto y del 9 de septiembre los hizo el
usuario **juan.nieto**. Los demás no dejaron registro: el sistema solo anotaba el borrado cuando el
artículo tenía existencias positivas.

## 3. Lo que se corrigió el 2026-09-24

| Código | Ahora es |
|---|---|
| **C10143** | PECHUGA DE POLLO, la que recibe las compras. Se le sumaron las existencias del duplicado, que se retiró |
| **C10148** | PERNIL DOLCETTO |

En las recetas, cada ingrediente borrado se cambió por el que se usa hoy:

| Borrado | Lo reemplaza |
|---|---|
| Pechuga de pollo (C10143) | PECHUGA DE POLLO (C10143) |
| Pernil de dolcetto «sabor a cordero» (C10148) | PERNIL DOLCETTO (C10148) |
| CAFE MAKOR CATURRA MOLIDO 500G | CAFE MAKOR MOLIDO GRANO 500GR (C10192) |
| QUESO MOZZARELLA, QUESO MOZARELLA BLOQUE | QUESO MOZARELLA BLOQUE X 2500 (C10076) |
| Jamón de pollo | Jamón de pollo Premium (C10182) |
| Jamón de cerdo finas hierbas | Jamón finas hierbas (C10183) |
| JAMON SERRANO FACTORIA | Jamón Serrano 100 gr (C10207) |

**Pendiente:** el **Pavo navideño** sigue en 4 recetas (SANDWICH 4 CARNES, SANDWICH DOLCETTO, SANDWICH
ITALIANO, COMBO LA SELECCIÓN DE TODOS) porque ninguno de los pavos activos es su reemplazo claro. Y el
PERNIL DOLCETTO nuevo cuesta la mitad que el viejo: si es otra presentación, la cantidad de las recetas
puede necesitar ajuste.

## 4. Lo que el sistema hace desde ahora

**No deja borrar un ingrediente de una receta activa.** Dice cuál y dónde:

> No se puede borrar «PECHUGA DE POLLO»: es ingrediente de SANDWICH 4 CARNES, SANDWICH TOSCANA,
> COMBO LA SELECCION DE TODOS. Reemplácelo o quítelo primero de esas recetas.

Si se seleccionan varios artículos y uno está bloqueado, no se borra ninguno. Si la receta está
borrada, el ingrediente sí se puede borrar.

**Queda registrado quién borra cada artículo y cuándo**, aunque no tenga existencias. Se ve en
Artículos → el artículo → detalle de inventario, como una fila «Artículo borrado» con el empleado.

## 5. Recomendación para el equipo

Para cambiar el nombre, el precio o la presentación de un producto, **editar el artículo**, no borrarlo
y crear otro. Así conserva su código, su historial y las recetas que lo usan.
