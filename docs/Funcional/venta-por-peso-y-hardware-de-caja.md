# Alcance funcional — Venta por peso, hardware de caja e inventario para supermercado

> **Estado a 2026-08-28: en construcción.** Ya está hecho el trabajo de fondo que no se ve:
> los artículos ya pueden decir si se venden por unidad o por kilo, se corrigieron cuatro errores
> que hacían perder decimales de peso en silencio, y el despliegue ya no puede dejar a un negocio
> sin poder entrar al sistema. En curso: la pantalla de configuración de la báscula y el campo de
> peso en la caja.
>
> **Todavía no está verificado contra una base de datos real.** Las pruebas están escritas, pero el
> entorno de pruebas local no está disponible, así que 138 de ellas —incluidas las que comprueban
> estos arreglos— nunca se han ejecutado. **Nada de esto debe llegar a producción antes de correrlas.**
>
> **Actualización del 2026-08-28:** báscula identificada y documentada (ROCHI RC-A01E, y es
> **multiprotocolo**) — §4.3. Periféricos confirmados en §3.4; el terminal será **Windows**, así que
> el diseño se mantiene íntegro. **Falta confirmar si habrá pistola lectora.**
> **Atención al §4.4: el fabricante advierte que esta báscula no es apta para actividades
> mercantiles.** Es un riesgo del negocio del cliente, no del desarrollo, pero hay que informarlo.
>
> **Plan de trabajo aprobado el 2026-08-28.** Dos decisiones de ese día: el paso a producción será
> un **corte en seco** —se apaga el POS anterior, sin plan de retorno— y el formato de la báscula se
> averigua por cuenta propia, porque **el proveedor del firmware cerró el soporte**.
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
| Paso a producción | **Corte en seco.** El día de salida se apaga el POS anterior. | 2026-08-28 |
| Formato de la báscula | Se averigua **revisando el instalador del POS actual**, y si no, en el montaje. | 2026-08-28 |
| Unidad de peso | **Solo kilogramos.** La libra se probó y se quitó: media libra se registra como 0,227 kg. Ver 3.1b. | 2026-08-30 |

Las dos últimas nacieron corrigiendo un planteamiento inicial más pesado, y conviene dejar por
escrito **por qué**, porque las razones van a seguir siendo válidas con los próximos clientes.

**Los lotes son opcionales porque en el mercado colombiano de hortalizas ese dato no existe.** Un
bulto de cebolla no trae lote impreso ni fecha de vencimiento, y la mercancía rota en días. Un
módulo que exija ese dato no se usa mal: **se abandona**, y arrastra consigo al inventario entero.

**El programa local lo construimos nosotros porque de todas formas vamos al local a montar la
caja.** Instalar un ejecutable más no cuesta una visita adicional, y a cambio no dependemos de la
licencia de nadie ni de que un proveedor externo siga existiendo. El costo se vuelve nuestro tiempo
una sola vez, en vez de un pago por cada PC de cada cliente.

**El corte en seco tiene una consecuencia que hay que decir en voz alta.** Apagar el POS anterior el
día de la salida nos da libertad para dejar la báscula en el formato que más nos conviene, y evita
el enredo de mantener dos sistemas al tiempo. Pero **elimina el plan de retorno**: si ese día la
báscula no responde, el cliente no tiene a qué volver.

Por eso el peso digitado a mano deja de ser una comodidad y pasa a ser **la contingencia que
mantiene la tienda abierta**. Antes del corte tiene que estar probado y **el personal entrenado en
él**, no solo disponible. Y el montaje se ensaya completo antes, no se improvisa ese día.

**Y un cierre que no salió como esperábamos:** el proveedor que le puso a la báscula el firmware
multiformato — Mavin — **ya no da soporte**, así que no hay a quién pedirle la tabla de formatos.
Se resuelve por dos vías propias: revisando el instalador del POS actual del cliente, y con una
herramienta de diagnóstico dentro de nuestro programa que muestra qué está enviando la báscula. No
cambia el alcance; sí explica por qué esa herramienta pasó a ser obligatoria.

## 3. Cómo va a funcionar la venta por peso

### 3.1 Lo que ve el cajero

1. Pasa el producto por la pistola, o teclea su código corto.
2. Si el producto **se vende por peso**, el sistema lleva el cursor al campo de peso y espera.
3. El operario pone la bolsa en la báscula y presiona transmitir. El peso entra solo.
4. La línea queda con la cantidad en kilos y el total calculado contra el precio por kilo.

Para un producto que se vende por unidad no cambia nada: se escanea y entra con cantidad 1, igual
que hoy.

**Probado en el ambiente de pruebas el 2026-08-30, con datos reales de Casaletto.** Se cargó el
QUESO DE CABEZA: la caja no lo metió al carrito, pidió el peso con el precio por kilo a la vista y
un teclado numérico en pantalla. Digitado 0,735, la línea quedó en $19.110 exactos. Se volvió a
entrar a la línea para editarla y el peso siguió siendo 0,735: antes de este trabajo se habría
convertido en 1 kilo y en $26.000. Pesada una segunda bolsa de 0,740, la cantidad quedó en **1,475**
y el total en $38.350; con el error anterior habrían sido 1,47 y $38.220, es decir **$130 perdidos
en cada venta de dos bolsas**.

En esa misma prueba se encontró y corrigió que **el aviso de peso salía en inglés**. La traducción
estaba escrita, pero en la variante de español equivocada. Ya quedó en español.

### 3.1b Todo se pesa en kilogramos, y la libra no existe en el sistema

Durante un día el sistema aceptó **libra** como unidad, porque así se había entendido la venta del
queso de cabeza. **Se quitó el 2026-08-30**, y conviene dejar escrito por qué, para que no vuelva a
proponerse:

- El precio del queso de cabeza **ya era por kilo**. Sus dos meses de ventas lo dicen solos: un
  cuarto se vendió a $6.500, o sea $26.000 el kilo. Por libra ese mismo cuarto habría costado
  $14.330. Las cajeras venían digitando kilos desde el primer día, y Siigo lo tiene como kilogramo.
- El otro artículo, **CAFÉ MAKOR LIBRA**, no se pesa: "libra" ahí es el tamaño del empaque. Es una
  unidad.
- **El sistema no convierte entre unidades de peso, a propósito.** Por eso tener dos era peligroso:
  poner un artículo en la equivocada lo cobra a 2,2 veces el precio equivocado **sin mostrar ningún
  error**. Justo lo que este trabajo existe para evitar.

**Qué significa en el día a día:** un negocio que hable de libras sigue hablando de libras — pero en
el sistema media libra se registra como **0,227 kg**, y el precio se guarda por kilo. El menú de la
ficha del artículo ofrece solo **Unidad** y **Kilogramo**.

Producción nunca alcanzó a recibir la libra; el cambio solo había llegado al ambiente de pruebas.

### 3.2 Lo que cambia en la ficha del artículo

Aparece un campo nuevo: **unidad de medida**. Dice si el artículo se vende por unidad o por peso.
**Ya está construido** (2026-08-28), y es opcional: un artículo que no lo llene se comporta como
hasta hoy.

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

### 3.5 Un error que encontramos revisando, y que el negocio habría pagado a diario

Auditando el código apareció un defecto que no estaba en la lista original y que es el más frecuente
de todos: **al pesar dos bolsas del mismo producto en la misma venta, el sistema guardaba 1,47 kg en
vez de 1,475**. Cinco gramos por repetición, sin ningún aviso.

En un supermercado de hortalizas eso pasa decenas de veces al día. No es plata perdida en la venta
—el cliente paga lo que marca la pantalla— pero el **inventario en kilos se va desviando** de la
realidad sin que nadie sepa por qué. Ya está identificado y entra en la primera fase de trabajo.

### 3.6 Una precaución que el negocio va a notar

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

### 4.3b Los cinco minutos con la báscula: qué se logra y qué no (2026-08-30)

La báscula se presta **una vez y por cinco minutos**. Conviene que el negocio sepa qué esperar de
esa sesión, porque de ahí sale una expectativa que hay que ajustar.

**Lo que se logra:** conectarla, grabar todo lo que emite y salir con un archivo. Con ese archivo se
termina la integración **sin volver a necesitar la báscula**.

**Lo que NO se logra:** probar todos los modos en que la báscula puede hablar. Esa báscula puede
emitir en unos diez modos distintos, pero **solo habla en el que está configurada**, y cambiar de
modo se hace en su teclado, no desde el computador. Así que de la sesión sale **uno**: el que el
cliente usa hoy.

**Y con ese uno basta.** Nuestro programa aprende a leer ese modo. No hay ninguna ventaja en
conocer los otros nueve.

**Hay una segunda vuelta opcional.** Existe un modo mejor —la báscula contesta solo cuando se le
pide, en vez de mandar peso todo el tiempo— y si sobra tiempo se cambia y se captura otra vez.
**Va segundo a propósito**: si se cambiara primero y algo saliera mal, la báscula quedaría en un
modo que nadie lee y sin captura, y no hay segunda visita. El corte en seco nos da permiso para
reprogramarla, porque el POS anterior se apaga ese mismo día.

**Lo que le pedimos al cliente:** que nos diga **en qué número está configurada** hoy. Es un dato de
diez segundos, y sin él no se puede devolver a como estaba.

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

1. ~~¿Qué báscula tiene hoy instalada?~~ **Resuelta: ROCHI RC-A01E, y sirve.**
   ~~¿Y en qué formato transmite?~~ **Sin respuesta posible por fuera: Mavin cerró el soporte
   (2026-08-28).** Se resuelve por cuenta propia — revisando el instalador del POS actual, y con la
   herramienta de diagnóstico del programa local. Ya no es una pregunta abierta, es trabajo del
   plan.
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
