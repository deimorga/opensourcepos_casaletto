# Alcance funcional — Venta por peso, hardware de caja e inventario para supermercado

> **Estado a 2026-08-27: alcance cerrado y documentado. Nada implementado todavía.**
> Este documento y su par técnico son el punto de partida del desarrollo. Ninguna línea de código,
> ninguna migración y ninguna configuración de este requerimiento existe aún.
>
> **Actualización del 2026-08-28:** báscula identificada y documentada (ROCHI RC-A01E, y es
> **multiprotocolo**) — §4.3. Periféricos confirmados en §3.4; el terminal será **Windows**, así que
> el diseño se mantiene íntegro. **Falta confirmar si habrá pistola lectora.**
> **Atención al §4.4: el fabricante advierte que esta báscula no es apta para actividades
> mercantiles.** Es un riesgo del negocio del cliente, no del desarrollo, pero hay que informarlo.
>
> Diseño técnico en `docs/Tecnico/venta-por-peso-y-hardware-de-caja.md`.

---

## 1. De dónde sale esto

Llega un cliente nuevo: un **supermercado de hortalizas**. Es el segundo negocio de la plataforma
después de Casaletto, y es distinto a todo lo que el sistema ha atendido hasta hoy por una razón
central: **casi todo lo que vende se vende por peso**. Cebolla, papa, ajo, verduras varias.

El cliente pidió cuatro cosas concretas para la caja que va a montar:

- Una **báscula USB** conectada directamente al sistema.
- Una **impresora de recibos**.
- Un **cajón monedero** (registradora).
- Una **pistola lectora láser**.

Y además quiere **empezar a usar el módulo de inventario**, que hasta hoy ningún cliente de la
plataforma ha usado.

Hoy el cliente trabaja con otro POS (POS Online Colombia) y allí la báscula le funciona bien. Ese
sistema es una aplicación de escritorio instalada en Windows; el nuestro es una aplicación web. La
diferencia importa y se explica en el punto 4.

## 2. Las decisiones de alcance ya tomadas

| Tema | Decisión | Fecha |
|---|---|---|
| Dónde se pesa | **Báscula USB en la caja**, leída en vivo. No etiquetadora de mostrador. | 2026-08-26 |
| Facturación electrónica DIAN | **El cliente no está obligado.** Fuera del alcance. | 2026-08-26 |
| Caída de internet | **Riesgo aceptado.** Sin canal de respaldo ni instalación local de contingencia. | 2026-08-26 |
| Lotes y vencimientos | **Se construyen, pero opcionales** y apagados por defecto. | 2026-08-26 |
| Cómo se conecta la báscula | **Un programa propio instalado en el PC de la caja.** No licencia de terceros. | 2026-08-26 |

Las dos últimas nacieron corrigiendo un planteamiento inicial más pesado, y conviene dejar por
escrito **por qué**, porque las razones van a seguir siendo válidas con los próximos clientes.

**Los lotes son opcionales porque en el mercado colombiano de hortalizas ese dato no existe.** Un
bulto de cebolla no trae lote impreso ni fecha de vencimiento, y la mercancía rota en días. Un
módulo que exija ese dato no se usa mal: **se abandona**, y arrastra consigo al inventario entero.

**El programa local lo construimos nosotros porque de todas formas vamos al local a montar la
caja.** Instalar un ejecutable más no cuesta una visita adicional, y a cambio no dependemos de la
licencia de nadie ni de que un proveedor externo siga existiendo. El costo se vuelve nuestro tiempo
una sola vez, en vez de un pago por cada PC de cada cliente.

## 3. Cómo va a funcionar la venta por peso

### 3.1 Lo que ve el cajero

1. Pasa el producto por la pistola, o teclea su código corto.
2. Si el producto **se vende por peso**, el sistema lleva el cursor al campo de peso y espera.
3. El operario pone la bolsa en la báscula y presiona transmitir. El peso entra solo.
4. La línea queda con la cantidad en kilos y el total calculado contra el precio por kilo.

Para un producto que se vende por unidad no cambia nada: se escanea y entra con cantidad 1, igual
que hoy.

### 3.2 Lo que cambia en la ficha del artículo

Aparece un campo nuevo: **unidad de medida**. Dice si el artículo se vende por unidad o por peso.

No es un adorno: es lo que le permite al sistema saber a cuáles productos pedirle el peso a la
báscula y a cuáles no. Sin ese campo la báscula no puede funcionar, y por eso está en la primera
fase de trabajo aunque suene menor.

Como efecto secundario, el recibo va a poder decir **"0,735 kg de Tomate"** en vez de
"0,735 Tomate", que es lo que diría hoy.

### 3.3 Cuando la báscula falla

**El peso siempre se puede digitar a mano.** El campo es un campo normal: si la báscula está
desconectada, si el programa local no arrancó, o si simplemente el operario prefiere teclearlo,
la venta sigue. Esto es deliberado y no es un parche: es lo que impide que un problema de hardware
detenga la caja.

**Habrá teclado físico** conectado al terminal táctil (confirmado 2026-08-28), así que digitar el
peso a mano funciona sin más. Aun así vamos a poner un **teclado numérico en pantalla** en el campo
de peso: los teclados de mostrador se desconectan, se mojan o se guardan, y el respaldo tiene que
funcionar el día que eso pase. Deja de ser imprescindible y pasa a ser barato y sensato.

### 3.4 Los periféricos confirmados

Esto es lo que se va a conectar al terminal (confirmado con el cliente el 2026-08-28):

| Periférico | Estado |
|---|---|
| **Pantalla táctil todo-en-uno, Windows** | Reemplaza el PC. Todo lo diseñado funciona igual |
| **Teclado físico** | Conectado a la pantalla |
| **Impresora de recibos** | Ya contemplada |
| **Cajón monedero** | Ya contemplado, colgado de la impresora |
| **Báscula ROCHI RC-A01E** | Ya identificada y documentada |

**Falta confirmar la pistola lectora.** En la petición original estaba, pero en esta lista no
aparece. Para un supermercado que además vende productos empacados con código de barras — arroz,
aceite, panela — la pistola no es opcional: sin ella toca teclear el código de cada producto.
Conviene aclararlo antes de comprar el terminal, porque cambia cuántos puertos USB hacen falta.

Dos cosas más que revisar al comprar el terminal:

- **Puertos USB suficientes.** Báscula, impresora, teclado y —si la hay— pistola. Son cuatro, y
  muchos terminales táctiles vienen con tres. Si no alcanzan, un hub **con alimentación propia**:
  la impresora y la báscula no deben colgar de un hub sin corriente.
- **Objetivos táctiles.** La pantalla de venta actual está hecha para mouse: botones y campos
  pequeños. Hay que agrandar lo que el cajero toca todo el día. No es rehacerla, pero tampoco es
  gratis, y se dimensiona mejor con el terminal ya elegido.

### 3.5 Una precaución que el negocio va a notar

El sistema **no va a aceptar un peso tomado mientras la bolsa se está acomodando**. Las básculas
avisan cuándo el peso ya se asentó, y vamos a esperar ese aviso. En la práctica significa que el
operario presiona transmitir cuando la balanza deja de moverse, que es lo que ya hace hoy.

## 4. El hardware de la caja

| Equipo | Qué hay que hacer | Quién |
|---|---|---|
| **Pistola lectora láser** | Programarla con sufijo Enter. Nada más. | Nosotros, en el montaje |
| **Impresora de recibos** | Instalar su driver y configurar el navegador para que no salga el diálogo de impresión. | Nosotros, en el montaje |
| **Cajón monedero** | Colgarlo del puerto de la impresora y marcar la casilla del driver. | Nosotros, en el montaje |
| **Báscula USB** | Instalar nuestro programa local y configurar el formato que emite la báscula. | Nosotros, en el montaje |

### 4.1 Por qué la báscula necesita un programa instalado

Vale la pena explicarlo porque va a salir en la conversación con el cliente, que ya vio funcionar
una báscula en otro sistema.

Una **aplicación de escritorio** puede hablarle al puerto de la báscula directamente. Una **página
web no puede**: el navegador lo prohíbe por seguridad, y no hay forma de convencerlo desde la
página. No es una limitación de nuestro sistema ni un descuido: es cómo están construidos los
navegadores, y le pasa igual a cualquier POS web del mundo.

La solución estándar de la industria es exactamente la que vamos a usar: **un programa pequeño
instalado en el PC de la caja que abre el puerto y le entrega el peso a la página**.

### 4.2 Qué es ese programa, en términos del negocio

Es un ejecutable nuestro, sin ventanas, que arranca con el computador y se queda esperando. No pide
nada al usuario y no hay que abrirlo. Se instala una vez por caja durante el montaje.

Va a hacer tres cosas, no una:

- Leer la báscula.
- Imprimir el recibo sin que salga el diálogo de impresión.
- Abrir el cajón monedero, **también cuando no hay venta** — para dar un cambio o cuadrar el turno.

Esa tercera es la única función de esta lista que el sistema no puede hacer hoy de ninguna manera.

### 4.3 La báscula ya está identificada (2026-08-27)

Es una **ROCHI RC-A01E**, la que el cliente ya tiene funcionando con su POS actual. Buenas noticias:
**sirve, y no hay que comprar otra.** Se conecta al computador por cable USB y trae driver, que es
justo el tipo de conexión para la que diseñamos el programa local.

Dos límites del equipo que el negocio tiene que conocer antes de abrir, porque no son corregibles
por software:

- **No pesa nada por debajo de 200 gramos.** Para papa o cebolla da igual. Para unos ajos sueltos o
  un puñado de hierbas, no: por debajo de ese peso la báscula no da una lectura válida.
  **El negocio tiene que decidir qué hace con esos productos** — venderlos por unidad, en bolsa
  preempacada con peso fijo, o con un precio mínimo. Es una decisión de negocio, no técnica.
- **Mide de 5 en 5 gramos.** Nunca va a mostrar 737 gramos: va a mostrar 735 o 740. No es un error
  ni hay nada que arreglar, pero conviene saberlo para que nadie lo reporte como falla.

Técnicamente ya está todo resuelto: el manual del fabricante da la configuración exacta del puerto
y el driver es gratuito. **No hay ninguna incógnita técnica bloqueante con esta báscula.**

Y una buena noticia adicional del 2026-08-28: la etiqueta de la base dice **"MultiProtocolo"**, o
sea que **la báscula se adapta al sistema, no al revés**. Puede emitir el peso en varios formatos y
se elige cuál desde su propio teclado. Uno de ellos funciona **por petición**: el sistema le pide el
peso y la báscula responde con una sola lectura. Es el que vamos a usar, y es más confiable que
escuchar un flujo continuo, porque no hay riesgo de tomar el peso a mitad del bamboleo.

### 4.4 Una advertencia legal que encontramos en el manual, y que no es nuestra decisión

Al leer el manual del fabricante apareció algo que el negocio tiene que saber. En la contraportada,
**ROCHI advierte textualmente**:

> *"Esta báscula no puede ser utilizada en actividades mercantiles ni sanitarias. Hacerlo podría
> acarrear la imposición de multas hasta por dos mil (2.000) salarios mínimos legales vigentes por
> parte de la Superintendencia de Industria y Comercio. Artículo 2.2.1.7.14.3 Decreto 1074 de 2015."*

Lo verificamos: en Colombia, **los instrumentos que se usan para pesar con el fin de hacer
transacciones comerciales o determinar un precio están sujetos a control metrológico** de la SIC
(Decreto 1074 de 2015 y Resolución 77506 de 2016). Las básculas aptas para eso llevan **aprobación
de modelo** y quedan registradas en el sistema SIMEL, verificadas por un organismo autorizado. Por
eso otros vendedores anuncian sus equipos como *"con aprobación de modelo OIML"*: es un
diferenciador, no algo que traigan todas.

**Un supermercado que le vende verduras al público pesándolas es exactamente esa actividad.**

Qué significa esto en la práctica:

- **No cambia nada de nuestro desarrollo.** El trabajo técnico es idéntico con esta báscula o con
  otra, porque casi todas se conectan igual y nuestro sistema lee el formato por configuración.
- **Sí es un riesgo del negocio del cliente**, y es de él, no nuestro. Pero callarlo sabiéndolo
  sería peor: **hay que decírselo por escrito** y que él lo verifique con su proveedor y con la SIC.
- Si le toca reemplazarla por una con aprobación de modelo, para nosotros es **cambiar un parámetro
  de configuración**, no rehacer nada. Conviene que lo sepa antes de comprar la pantalla táctil, no
  después.

Vale aclarar que el cliente **ya viene operando con esta báscula** en su POS actual. Nosotros no
creamos la situación; solo la encontramos al leer el manual y la reportamos.

### 4.5 El cajón, en dos etapas

Desde el primer día el cajón **abre solo en cada recibo**, con la casilla del driver de la
impresora. Eso cubre la operación normal.

Lo que queda para cuando esté el programa local es **abrirlo sin vender**. Hasta entonces, para dar
un cambio hay que abrirlo con la llave. Es una molestia conocida y aceptada, no un olvido.

## 5. El módulo de inventario

### 5.1 Lo que ya funciona y solo hay que configurar

- Existencias por sede, con decimales hasta el gramo.
- Historial de todos los movimientos, con usuario, fecha, sede y comentario.
- Compras a proveedor, con devoluciones.
- Costo promedio ponderado, que se recalcula solo al recibir mercancía a otro precio.
- Punto de reorden por artículo, con su reporte de inventario bajo.
- Valorización del inventario (cantidad × costo).
- Carga masiva de artículos por archivo, con existencias iniciales.

### 5.2 Registro de merma — la pieza más valiosa para este cliente

Hoy la única forma de sacar del inventario algo que se dañó es un ajuste negativo con un comentario
escrito a mano. No se puede sumar, ni comparar entre semanas, ni atribuir a un producto.

**Para un negocio de hortalizas eso es el costo que más duele.** La cebolla que se pudre, la papa
que se magulla y el ajo que se seca son plata que se pierde todos los días.

Se construye un registro de merma con **causa clasificada** — dañado, mermado, robo, error de
digitación — y su reporte contra las compras del mes. Va **antes** que los lotes en el orden de
trabajo, precisamente porque es lo que este cliente va a usar a diario.

### 5.3 Toma de inventario

Hoy no existe un módulo de conteo: hay que ajustar artículo por artículo. Para un supermercado eso
no es viable.

Se construye una toma de inventario de verdad: se carga el conteo, el sistema muestra las
diferencias contra lo que tenía registrado, y se aplican todas de una sola vez dejando constancia.

### 5.4 Lotes y vencimientos — opcionales de verdad

Se construyen con cuatro reglas que **no son negociables**, porque son lo que hace que el módulo
sobreviva en un negocio que no maneja ese dato:

1. **Apagados por defecto, con interruptor por artículo.** No se activan para el negocio, se activan
   para el producto que los necesite. La cebolla nunca los ve. Si mañana entra un producto empacado
   que sí trae vencimiento impreso, se le prende a ese artículo y solo a ese.
2. **El total de existencias manda; el lote es un detalle encima.** La existencia sigue viviendo
   donde vive hoy. Los lotes la explican cuando existen, pero nunca son una fuente paralela de
   verdad que se pueda desincronizar.
3. **Ningún lote puede detener una venta.** Si no hay lotes registrados, la venta descuenta del
   total y sigue. Sin advertencias y sin ventanas emergentes.
4. **La recepción no pide lote.** Los campos existen, están al final del formulario, y quien no los
   llena ni se entera de que estaban ahí.

Construido así, el módulo queda listo para el día que llegue un cliente de lácteos o de carnes —
que sí los necesita — sin estorbarle un solo día a este.

### 5.5 Traslados entre sedes

Solo si el cliente tiene más de un punto. Hoy hay que restar de un lado y sumar del otro a mano, sin
que quede constancia de que fue un mismo traslado.

## 6. Lo que este trabajo NO incluye, y por qué

**Facturación electrónica DIAN.** El cliente no está obligado hoy. Conviene anotar que la obligación
en Colombia depende de umbrales que cambian: **si el supermercado crece o cambia de régimen, esto
vuelve.** No es trabajo para ahora; es un punto para revisar con el cliente dentro de un año.

**Contingencia de internet.** Se decidió aceptar el riesgo. Es una decisión defendible si el
internet del local es confiable, pero **tiene que quedar firmada por el cliente con esa frase y no
con un eufemismo**: sin internet la caja no vende despacio, no vende. El día que pase habrá una fila
en la puerta. Un canal 4G de respaldo cuesta poco y resuelve casi todos los casos: vale la pena
ofrecerlo una vez más antes de cerrar.

**Abrir el cajón sin venta.** Queda para la entrega del programa local, no para el arranque.

**Categorías con lista y jerarquía.** Las categorías siguen siendo texto libre. Con un catálogo de
hortalizas — decenas de productos, no miles — el daño es manejable. Volvería a ser prioridad si el
cliente amplía a abarrotes.

## 6b. Que esto no le toque un pelo a Casaletto

Es el primer desarrollo que hacemos **para un cliente distinto al que ya está vendiendo todos los
días**. Casaletto usa el mismo sistema, así que la pregunta no es si el supermercado va a funcionar:
es si el día que despleguemos, Casaletto va a seguir vendiendo como si nada.

### Por qué el riesgo es real y no teórico

Cada negocio tiene su propia base de datos y su propia configuración, así que **los datos están
aislados**. Pero el programa es uno solo: si tocamos una parte compartida, Casaletto la recibe en el
mismo momento en que subimos la versión.

### Cómo lo evitamos

**Lo nuevo aparece solo donde hay razón para que aparezca.** El campo de peso se muestra únicamente
en artículos que se venden por kilo. Casaletto no tiene ninguno, así que sus cajeros no van a ver un
campo nuevo ni un botón distinto. No es una configuración que se pueda equivocar: es que sin
artículos por peso, simplemente no hay nada que mostrar.

**Los módulos nuevos van detrás de permisos.** Merma, conteo de inventario y lotes solo aparecen
para quien los tenga otorgados. Casaletto no los pide y no los ve.

**Lo único que cambiaría su pantalla sin motivo lleva interruptor.** Es el modo táctil: agrandar
botones tiene sentido en el terminal del supermercado y ninguno en el computador de Casaletto. Ese
se activa negocio por negocio.

**Los arreglos de defectos sí los recibe, y es a propósito.** Dos de los tres problemas que
encontramos son errores que también le aplican a Casaletto — hoy no los sufre porque no vende por
peso, pero están ahí. Esconderlos detrás de un interruptor sería dejarle el error puesto a
sabiendas. Se arreglan para todos, y se prueban con sus casos de uso, no solo con los del
supermercado.

### La trampa operativa que encontramos revisando esto

Nuestros despliegues **no actualizan la base de datos automáticamente** — eso siempre se ha hecho a
mano. Con un solo cliente era una molestia. **Con dos es un riesgo:** si se actualiza el programa y
se olvida actualizar la base de Casaletto, Casaletto empieza a fallar en plena venta.

Ya está resuelto en el plan: se actualizan **todas** las bases primero, con una verificación de que
cada una terminó bien, y solo después se sube el programa. Además queda una lista de chequeo escrita
para el día del despliegue, y se hace **después de las 22:00**, con el negocio cerrado.

### Cómo lo comprobamos antes

Las pruebas automáticas van a correr **dos escenarios**: uno que representa a Casaletto (venta por
unidad) y otro que representa al supermercado (venta por peso). La pregunta que tienen que responder
no es "¿sirve el peso?" sino **"¿sigue funcionando todo lo que ya funcionaba?"**.

Y antes de producción, todo pasa por el ambiente de pruebas con los dos negocios montados.

## 7. Lo que el negocio tiene que aportar

- **El mapa de IVA.** Qué categorías van excluidas, cuáles exentas y cuáles al 19%. Las verduras
  frescas generalmente van excluidas. **Esto lo confirma el contador del cliente, no nosotros.**
- **El catálogo de productos** con sus precios por kilo, para la carga inicial.
- ~~La báscula~~ — **resuelto: ya la tiene y sirve** (ROCHI RC-A01E). Falta prestárnosla unos días,
  o darnos acceso al equipo, para desarrollar y probar contra hardware real.
- **La decisión sobre los productos de menos de 200 g**, que la báscula no puede pesar.
- **La aceptación firmada** del riesgo de quedarse sin internet.

## 8. Orden de entrega

| Fase | Qué se entrega | Depende de |
|---|---|---|
| 0 | Blindaje del despliegue multi-tenant, para no tocar a Casaletto (§6b) | — |
| 1 | Arreglo de los defectos que rompen el peso, y la unidad de medida en los artículos | Fase 0 |
| 2 | El negocio provisionado y configurado | Fase 1 |
| 3 | Merma, toma de inventario y lotes opcionales | Fase 1 |
| 4 | El campo de peso en la caja, con digitación manual | Fase 1 |
| 5 | Catálogo cargado y hardware montado en el local | Fases 2 y 4 |
| 6 | El programa local: báscula, impresión directa y apertura de cajón | Fase 5 |
| 7 | Acompañamiento de la primera semana | Fase 5 |

**El programa local está deliberadamente después de la salida a producción.** El cliente arranca
vendiendo con el peso digitado o con la báscula en modo teclado, y el programa llega sin presión de
cronograma. Así, un problema de instalación no retrasa la apertura.

## 9. Preguntas abiertas

1. ~~¿Qué báscula tiene hoy instalada?~~ **Resuelta: ROCHI RC-A01E, y sirve.** Queda una gestión
   fácil y gratis: **llamar a Mavin Colombia y pedir la tabla de formatos** del puerto USB
   multiformato de esa báscula — el firmware multiprotocolo es de ellos, así que son la fuente
   autorizada. Ver §5.10b-bis del documento técnico. Alternativa igual de útil: una foto de la
   pantalla de configuración de báscula del POS actual del cliente.
2. **¿Va a haber pistola lectora de código de barras?** Estaba en la petición original pero no en
   la lista de periféricos confirmada. Sin ella hay que teclear el código de cada producto
   empacado. Afecta cuántos puertos USB necesita el terminal.
3. **¿La báscula tiene aprobación de modelo ante la SIC?** Ver §4.4. Es la pregunta más importante
   de esta lista y no la podemos responder nosotros: la verifica el cliente con su proveedor.
4. **¿Qué se hace con los productos que pesan menos de 200 gramos?** La báscula no los lee. Decisión
   de negocio: por unidad, preempacados o con precio mínimo.
5. **¿Cuántas cajas va a tener?** Define cuántas básculas montar y cómo se hace el cuadre si hay más
   de un cajero por turno.
6. **¿Cuántos productos tiene el catálogo?** Con decenas de productos la velocidad de la caja
   alcanza sin tocar nada; con miles hay que revisarla antes de salir.

## 10. Referencia técnica

`docs/Tecnico/venta-por-peso-y-hardware-de-caja.md` — los defectos concretos con archivo y línea,
el modelo de datos, el diseño del programa local y lo que se descartó con su razón.
