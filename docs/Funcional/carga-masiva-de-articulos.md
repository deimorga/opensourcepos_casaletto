# Alcance funcional — Carga masiva de artículos: crear y actualizar

> **Estado a 2026-09-01: alcance CERRADO, sin construir nada.** Las decisiones de diseño se tomaron
> con el dueño el 2026-09-01 y están en §6. Quedan dos por confirmar, marcadas como tales.
>
> Nace de un problema inmediato y medido: **Paraíso de la Canasta tiene 1.184 artículos, los 1.184
> sin precio y sin costo, y cero ventas.** Ese negocio no puede vender, y no por falta de punto de
> venta: es que no hay forma razonable de que suba sus precios.
>
> Diseño técnico en `docs/Tecnico/carga-masiva-de-articulos.md`.

---

## 1. El problema, en una frase

**Se puede cargar un catálogo. No se puede volver a bajarlo, corregirlo y subirlo.**

Y ese ida y vuelta es exactamente lo que un negocio necesita para mantener sus precios.

---

## 2. Qué pasa hoy, verificado

Revisado en el código y contrastado contra producción el 2026-09-01, no supuesto.

### 2.1 La plantilla que se descarga viene vacía

El botón *«Descargar Plantilla para Importar desde CSV»* entrega **solo la fila de encabezados**. Ni
un artículo. Sirve para empezar de cero, no para corregir lo que ya existe.

### 2.2 Para actualizar hay que saber un número que nadie tiene

El archivo trae una primera columna, `Id`, y **es la que decide todo**: vacía crea, con número
actualiza. Ese número es el identificador interno de la base. **El cliente no lo conoce, no lo ve en
ninguna pantalla, y no hay forma de exportarlo.**

### 2.3 Reimportar el propio catálogo NO duplica: lo rechaza entero

> **Corrección de lo escrito el 2026-08-31.** Aquel documento decía que una reimportación duplicaría
> el catálogo. **No es cierto**, y conviene saberlo porque cambia el riesgo.

Con `allow_duplicate_barcodes = 0` --como están los dos negocios de producción-- una fila cuyo código
ya existe **falla la validación**. Y como toda la importación corre dentro de una transacción, una
sola fila fallida **revierte el archivo completo**.

Resultado real para el cliente: sube sus 1.184 artículos y recibe *«fallaron las filas 2, 3, 4…
1185»*. No se duplica nada --es menos peligroso de lo que creíamos-- pero queda igual de bloqueado.

### 2.4 El botón de exportar de la grilla no resuelve esto

El *«Export data»* del listado es del navegador (`bootstrap-table`, `exportDataType: 'basic'`):
exporta **lo que está pintado en la pantalla**, la página actual, no las 1.184 filas. Y no incluye la
unidad de medida, porque esa columna ni siquiera aparece en el listado.

### 2.5 La edición masiva pone el MISMO valor a todos

Sirve para «poner a estos 40 artículos la categoría X». **No sirve para precios**, que es justo lo que
hace falta: cada artículo tiene el suyo. Y le falta la unidad de medida, aunque el sistema por dentro
sí la acepta.

### 2.6 Los datos que deciden el diseño

Medidos en producción el 2026-09-01:

| | Casaletto | Paraíso |
|---|---|---|
| Artículos vivos | 284 | 1.184 |
| Sin precio | — | **1.184** |
| **Con código propio** | 266 (18 sin código) | **1.184 (todos)** |
| Códigos repetidos | 0 | 0 |
| Ventas | 903 | **0** |

Los códigos de Paraíso son **códigos de barras EAN de 13 dígitos**, reales. Eso hace que emparejar por
código cubra el 100% de su catálogo — y trae una trampa práctica, §5.

---

## 3. La solución: el viaje de ida y vuelta

**Bajo lo que tengo, lo corrijo en Excel, lo vuelvo a subir.** Es lo único que un tendero ya sabe
hacer, y de ahí sale todo lo demás.

---

## 4. Cómo funciona

### 4.1 Dos descargas, no una

Son dos necesidades distintas y tienen que ser dos botones distintos, porque quien llega a esta
pantalla ya sabe a cuál de las dos cosas viene:

| Botón | Qué entrega | Para qué |
|---|---|---|
| **Descargar mis artículos** | Todo el catálogo, con todos sus datos, en las mismas columnas que la importación lee | **Actualizar** lo que ya existe |
| **Descargar plantilla vacía** | Solo los encabezados | **Crear** desde cero |

La segunda ya existe hoy. La primera es la que falta, y es la que desbloquea a Paraíso.

### 4.2 Crear y actualizar son el mismo archivo y la misma subida

**No hay dos modos ni dos pantallas.** Lo que decide qué pasa con cada fila es si su código ya existe:

| La fila trae… | El sistema… |
|---|---|
| Un código que ya existe | **Actualiza** ese artículo |
| Un código que no existe | **Crea** un artículo nuevo |

Así, para cargar 200 artículos nuevos se descarga el catálogo, se **agregan 200 filas al final** y se
sube: en la misma pasada se actualizan los viejos y nacen los nuevos.

### 4.3 Qué necesita una fila para crear un artículo

Verificado en `Items::validateCSVData()`. Solo tres datos son obligatorios:

- **Nombre**
- **Categoría**
- **Precio de venta**

El costo, si va vacío, entra en cero. Todo lo demás es opcional. El **código** conviene ponerlo
siempre: es lo que permite volver a actualizar ese artículo mañana.

### 4.4 Celda vacía significa «no cambiar». Siempre

Es la regla más peligrosa del lote y por eso es una sola, sin excepciones. Si «vacío» significara
«poner en blanco», un archivo con solo la columna de precios llena **borraría todos los nombres**.

Ya hay precedente en el sistema —la unidad de medida se comporta así desde el 2026-08— y esta regla lo
extiende al resto. **Y se dice en la pantalla**, no solo aquí.

### 4.5 La vista previa: nada se escribe hasta que lo apruebas

Al subir el archivo el sistema **no toca nada todavía**. Muestra qué haría:

> *«1.172 se van a actualizar. 12 se van a crear. 2 filas tienen error: la 340 no tiene categoría, la
> 890 tiene el precio en letras.»*

Y ahí se decide: aplicar, o cancelar y corregir.

Esto es lo que más cambia la experiencia, y resuelve tres problemas de un golpe:

1. **Sustituye a la casilla de «permitir crear».** Si alguien creía estar solo actualizando precios y
   lee «12 se van a crear», sabe al instante que hay doce códigos mal escritos. Una casilla obliga a
   decidir a ciegas, antes de ver el archivo; la vista previa lo enseña.
2. **Desactiva el dilema de «¿una fila mala tumba el archivo?»**: los errores se ven antes de aplicar.
3. **Hace visible lo invisible**, que es de lo que se queja hoy el cliente: subir mil filas a ciegas.

### 4.6 Al terminar, decir qué pasó y dejar volver atrás

- **Cuántos** se crearon, cuántos se actualizaron, cuántos quedaron sin cambios, cuántos fallaron.
- **«Descargar cómo estaba antes»**: el archivo previo a la importación.

No es un deshacer de verdad, pero cuesta muy poco y es la red que hoy no existe cuando alguien cambia
1.184 precios de un golpe.

---

## 5. La trampa que rompería justo al cliente que lo pidió

Los códigos de Paraíso son EAN de 13 dígitos. **Excel los convierte a notación científica al abrir el
archivo** —`7702028000316` se vuelve `7,70203E+12`— y al guardar, el código queda destruido. En
silencio, en las 1.184 filas.

Cualquier diseño que no resuelva esto fracasa el primer día, y fracasa de la peor forma: sin dar
error. La exportación tiene que escribir los códigos en un formato que Excel respete como texto, y la
importación tiene que aceptar las dos formas.

**Es criterio de aceptación, no un detalle de implementación.**

---

## 6. Decisiones tomadas

| # | Decisión | Cuándo |
|---|---|---|
| **D1** | **Dos descargas**: una con todo el catálogo, otra vacía. Botones separados | 2026-09-01 |
| **D2** | **Crear y actualizar son la misma subida.** Lo decide si el código existe | 2026-09-01 |
| **D3** | **Se empareja por el código del artículo.** El `Id` se sigue aceptando si viene, y manda sobre el código | 2026-09-01 |
| **D4** | **Celda vacía = no cambiar.** Sin excepciones, y dicho en pantalla | 2026-09-01 |
| **D5** | **Vista previa obligatoria.** Nada se escribe sin aprobar. Sustituye a la casilla de «permitir crear» | 2026-09-01 |
| **D6** | **Un código que aparece en más de un artículo vivo es un error de esa fila.** Nunca se adivina cuál | 2026-09-01 |
| **D7** | **Se ofrece «cómo estaba antes»** al terminar | 2026-09-01 |
| **D8** | **Nada de «subir todos los precios un X%»** por ahora. Excel lo hace mejor, y tocar 1.184 precios de un clic necesita un deshacer que no existe | 2026-09-01 |
| **D9** | **Candado opcional «este archivo solo actualiza»**, para listas de proveedor donde algo nuevo es un error. No es el camino normal | 2026-09-01 |

### Pendientes de confirmar

| # | Pregunta | Recomendación |
|---|---|---|
| **P1** | ¿La descarga del catálogo incluye los **costos**? | **Sí**, exigiendo el mismo permiso que la importación. Baja el catálogo con márgenes, así que no puede quedar al alcance de cualquiera |
| **P2** | ¿Se construye solo la Entrega 1 primero? | **Sí.** Con ella Paraíso ya puede vender; la 2 es comodidad |

---

## 7. Alcance, en dos entregas

### Entrega 1 — Desbloquear a Paraíso

- **Descargar mis artículos**: el catálogo completo, en las columnas que la importación lee.
- **Emparejar por código** cuando no viene `Id`.
- **Celda vacía = no cambiar.**
- **Vista previa** con conteos y errores por número de fila.
- **Resultado** con conteos y el archivo «cómo estaba antes».
- **Los códigos sobreviven a Excel** (§5).

Con esto, el problema de Paraíso se resuelve.

### Entrega 2 — Que sea fácil de verdad

- **Aceptar solo las columnas que traiga el archivo.** Una columna ausente = ese campo no se toca para
  nadie. Así un archivo válido puede ser literalmente **dos columnas: código y precio**, que es como
  llega la lista de un proveedor.
- **Errores descargables** como archivo, para corregir y reintentar sin transcribir.
- **Unidad de medida en la edición masiva**, que ya está soportada por dentro y quedó a medio cablear.

---

## 8. Lo que NO incluye

- **Subir precios por porcentaje** (D8).
- **Editar precios en la grilla**: para 1.184 artículos es peor que Excel.
- **Importar clientes o proveedores**: es otro requerimiento.
- **Programar cargas automáticas** desde un proveedor.

---

## 9. Cómo sabremos que quedó bien

La prueba es sobre el caso real, no sobre un archivo de juguete:

1. Se descarga el catálogo de Paraíso: **1.184 filas**, todas con su código intacto.
2. Se abre en Excel, se guardan los cambios, y **los códigos EAN siguen siendo EAN**.
3. Se llenan los precios y se agregan **tres artículos nuevos** al final.
4. Al subir, la vista previa dice **«1.184 actualizados, 3 nuevos, 0 errores»** antes de escribir nada.
5. Al aplicar, **Paraíso puede vender**: sus artículos tienen precio.
6. Se descarga «cómo estaba antes» y el archivo trae los precios en cero.
7. Un archivo con una fila sin categoría **no aplica nada** hasta que se corrige o se acepta.
