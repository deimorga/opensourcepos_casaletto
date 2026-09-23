# Alcance funcional — Comandas: el pedido que se toma en la mesa

> **Estado (2026-09-23):** la **Entrega 1 está construida y probada**, pero **todavía no la usa
> ningún comercio**: falta que alguien distinto de quien la programó la pruebe en staging, y después
> subirla a producción. Mientras tanto, para todos los negocios la aplicación sigue exactamente igual.
>
> Decisiones en §6. Al construirla aparecieron tres cosas que el negocio tiene que saber, y están en
> §4.1, §4.6 y §4.11: comandas necesita Mesas encendido, la caja avisa cuando el mesero cambia algo, y
> cómo se enciende para un comercio.
>
> Documento hermano: `docs/Tecnico/comandas-y-cuenta-abierta.md`.

---

## 1. El problema, en una frase

**El pedido se pierde entre la mesa y la caja.** Quien atiende toma lo que el cliente pide, camina
hasta la caja y lo registra de memoria o de un papel. Lo que se pierde en ese trayecto se pierde.

Que la cocina reciba el pedido impreso es **un añadido valioso, no el motivo**. Hay comercios que lo
van a querer y comercios que no lo necesitan, y depende del tamaño de cada uno.

## 2. Qué pasa hoy, verificado

### 2.1 Ya están improvisando comandas, y se nota en los datos

Las «mesas» que el negocio crea no son mesas. En los últimos siete días se crearon estas:

| «mesa» | ventas |
|---|---|
| ANDREA | 1 |
| LOBO GORDITO | 1 |
| nicolas uribe | 1 |
| MESA SILLA DENTRO | 1 |
| DENTRO | 1 |

**Son nombres de pedido.** Están usando la función de mesas como identificador de una cuenta
abierta, que es exactamente lo que una comanda necesita. Esa es la señal más fuerte de que el
requerimiento encaja con cómo ya trabajan.

### 2.2 «Delivery» no significa domicilio

En el mismo periodo, 105 ventas figuran contra la pseudo-mesa «Delivery». **Eso no dice que fueran
domicilios**: `Delivery` es el valor que el sistema asigna solo cuando nadie toca el selector de
mesa. Lo que el dato dice es que **casi nunca se escoge mesa**, no cómo se entregó el pedido.

Queda escrito porque este documento afirmó lo contrario en una primera versión y era falso.

### 2.3 El pedido se cobra en el mismo acto en que se toma

De las ventas de los últimos 60 días, 962 se completaron de una vez y **solo 1 quedó suspendida**.
No existe hoy un periodo en que el pedido esté tomado y todavía no cobrado.

**Ese es el hueco de fondo: no hay un momento «mandar a cocina» distinto de «cobrar».**

### 2.4 No hay dónde escribir una instrucción de cocina

Ninguna línea de venta lleva hoy una nota para el cocinero. El campo que serviría —la descripción
de la línea— **ya está ocupado**: el 90 % de las líneas dice `Unidad: kilogramo`, `Unidad: unidad`
o similar, metadatos que arrastró la importación de Siigo y que hoy se imprimen bajo cada línea del
recibo del cliente.

### 2.5 Se anula, y no poco

42 ventas anuladas en 60 días, un 4,2 %. Si una comanda ya salió a cocina, alguien tiene que
enterarse de que se cayó.

---

## 3. La solución: la comanda ES la cuenta abierta

No se inventa una entidad nueva. **Tomar un pedido abre una cuenta**, la cuenta se queda abierta y
visible en la pantalla de venta, y **cada vez que se manda a cocina sale una comanda impresa**. Al
final se cobra esa misma cuenta.

Eso reutiliza el mecanismo de cuentas abiertas que el sistema ya tiene y que hoy solo se usa para
mesas.

## 4. Cómo funciona

### 4.1 Nada de esto es obligatorio

**La venta rápida de hoy sigue funcionando exactamente igual.** Quien arma un pedido y cobra de una
vez no ve ningún cambio, no abre ninguna cuenta y no imprime ninguna comanda.

Y el negocio decide: **es un ajuste que cada comercio enciende o apaga**. Apagado, la aplicación se
comporta como hoy.

**Comandas necesita Mesas encendido.** La comanda llega a la caja como una pestaña más de la barra de
cuentas abiertas, y esa barra solo existe cuando Mesas está encendido. Por eso el sistema **no deja**
encender Comandas con Mesas apagado, **ni apagar** Mesas mientras Comandas esté encendido: las comandas
abiertas quedarían sin forma de llegar a la caja, con los pedidos ya en la cocina. En los dos casos la
pantalla dice qué hacer primero. Esto corrige lo que prometía D4 —un interruptor independiente— y está
anotado como D21.

### 4.2 La cuenta se identifica con un nombre libre

El cajero escribe **«ANDREA»**, **«mesa 4»** o **«domicilio Juan»**. Es literalmente lo que ya hacen,
y sirve igual para salón que para domicilio. No se les impone una numeración que les quite la
referencia de quién es quién.

### 4.3 La comanda sale por la impresora de la caja

**No hay segunda impresora.** La comanda es un documento aparte que sale por la misma impresora que
ya imprime el recibo, y alguien lo lleva a la cocina. Si más adelante se quiere una impresora en la
cocina, se decide entonces: es un cambio en el programa que corre en la máquina de la caja y hay que
reinstalarlo en cada punto.

### 4.4 Instrucciones por plato

Cada línea puede llevar su nota para el cocinero: *sin cebolla*, *término medio*, *para llevar*.
**Es indispensable**, y sale impresa en la comanda.

Es un campo **nuevo**. El que parecía servir —la descripción de la línea— no sirve por dos razones,
no una: ya está ocupado por el `Unidad: kilogramo` de §2.4, y **solo admite 30 caracteres**, que no
alcanzan para una instrucción de cocina.

Aparte de eso hay que dejar de imprimir el `Unidad: kilogramo` en la comanda, o saldrá ilegible.

### 4.5 Se agregan platos después, y solo sale lo nuevo

Un pedido crece: el cliente pide dos cosas más a los veinte minutos. **La segunda comanda imprime
únicamente lo que se agregó**, marcado como añadido, para que la cocina no vuelva a preparar lo que
ya despachó.

Eso exige que el sistema recuerde qué líneas ya se mandaron. Es la parte más delicada del trabajo
—el documento técnico explica por qué— y es la que no se puede improvisar.

### 4.6 Si se toca algo ya enviado, se avisa y se deja

Quitar o cambiar un plato que ya está en cocina **no se bloquea**. Se avisa:

- **Al mesero**, en su pantalla: *«ya estaba en cocina: el cambio queda marcado»*, y el plato queda
  señalado como «cambió después de enviarse».
- **Al cajero**, si ese plato ya había pasado a la caja: arriba de la venta aparece la lista de lo que
  cambió —*«Empanada: ahora 3»*, *«Jugo: anulado»*— con un botón **Entendido**. La caja **no cambia el
  cobro por su cuenta**, porque el cajero puede haberlo ajustado ya; él decide y ajusta.
- **En la cocina**, en un monitor que se actualiza solo. *Esto llega con la Entrega 3.*

Así la facturación final cobra lo que de verdad se sirvió, con una persona decidiendo.

### 4.7 La comanda tiene estados, y se gestiona

Una comanda no solo se abre y se cobra. Hay que poder decir qué pasó con ella:

- **Abierta** — el pedido está tomado y puede seguir creciendo.
- **Entregada** — se confirma que el pedido llegó a la mesa o salió a domicilio. Es la gestión de
  orden que permite saber qué está pendiente de servir.
- **Cancelada** — se cae antes de pedir el pago. Ocurre, y hoy no hay forma de registrarlo.
- **Cobrada** — se facturó en la caja y la cuenta se cierra.

Una comanda que se finalizó **debería haberse pagado en la caja**. Si al cierre del día queda una
cuenta sin cobrar, eso no es un caso que el sistema deba resolver solo: es algo que la operación
tiene que mirar, y para eso hace falta que se vea.

### 4.8 La cuenta se ve y se actualiza en la pantalla de venta

La comanda no es un papel que se va y se olvida: **es una cuenta viva** en el módulo de venta. Aparece
como una pestaña con el nombre completo de la comanda, y **cada vez que el cajero hace algo en esa
pestaña, los platos nuevos que tomó el mesero entran solos a la venta**, con su precio, como si el
cajero los hubiera tecleado. Un kit entra como entra siempre en la caja, con sus ingredientes para el
inventario.

Dos garantías que el negocio puede dar por hechas:

- **Ningún plato del mesero se pierde** porque el cajero esté trabajando en la misma cuenta al mismo
  tiempo.
- **La caja nunca cobra un total distinto del que el cajero tiene en pantalla.** Si llegó un plato
  justo antes de pulsar «Completar», la caja no cobra: muestra el plato nuevo, lo avisa, y el cajero
  vuelve a cobrar con el total correcto.

Cobrar la cuenta cierra la comanda. Cancelar la cuenta en la caja cancela la comanda. Y cancelar la
comanda desde el celular quita la cuenta de la caja, aunque el cajero la tuviera abierta en ese
momento.

### 4.9 El mesero toma el pedido desde su celular, por el navegador

No va a haber aplicación móvil. **La pantalla de comanda se construye responsive**, y con eso el
mesero entra desde el navegador de su teléfono, se autentica con su propio usuario y captura el
pedido de pie junto a la mesa. Eso es exactamente lo que el requerimiento venía a resolver: que el
pedido no dependa de la memoria de alguien caminando hacia la caja.

Cada mesero entra con **su** usuario, así que cada comanda queda con un nombre detrás. Al ingresar,
**el mesero cae directo en su pantalla de comandas** y tiene su propio botón **Salir**: no pasa por la
pantalla de inicio de la caja, que no le corresponde.

**El mesero no puede llegar a la caja.** Con el permiso de Comandas y ningún otro, el sistema no le
abre ventas, ni configuración, ni ninguna otra pantalla, ni siquiera tecleando la dirección.

Que el teléfono sirva no significa que el resto del sistema sirva en el teléfono: **hoy solo la
pantalla de ingreso está preparada para un celular.** Cualquier otra pantalla a la que el mesero
llegue va a salir en ancho de escritorio. Es una limitación conocida y aceptada, y el mesero no
necesita ninguna otra pantalla.

**Del teléfono no sale ningún papel.** La impresora es la de la caja (D6) y el celular no la
alcanza. Lo que el mesero hace desde la mesa es *tomar* el pedido; la comanda se imprime en la caja,
que es donde está el papel y donde alguien la recoge para llevarla a la cocina.

### 4.10 Lo que el comercio tiene que poner

La aplicación vive en internet, no en una máquina del local, así que el teléfono del mesero entra
igual que entraría a cualquier página: **por los datos móviles de su plan o por el WiFi del local.**

Eso convierte la conectividad en un requisito que el comercio garantiza, al mismo nivel que tener
luz o tener impresora. Va en la ficha que se le entrega al cliente **antes** de encenderle las
comandas:

- Cobertura de datos móviles aceptable dentro del local, o WiFi que llegue a las mesas.
- Un teléfono por mesero, con navegador actualizado.

Y la contraparte honesta: **si la señal se cae a mitad de un pedido, lo ya guardado está a salvo y lo
que se estaba escribiendo se pierde.** No hay modo sin conexión. El mesero que se queda sin red
vuelve al papel, que es lo que hace hoy.

### 4.12 Varios teléfonos sobre la misma comanda, y la señal que va y viene

*Construido en la Entrega 2 (2026-09-23), pendiente de probar en staging.*

Lo que el mesero ve en cada caso:

| Qué pasa | Qué hace el sistema | Qué ve el mesero |
|---|---|---|
| Dos meseros agregan platos **distintos** a la misma comanda | Guarda los dos. No se pisan | Cada uno, su plato guardado |
| Dos meseros cambian **el mismo plato** a la vez | Guarda el primero. Al segundo **no le guarda nada** | «Otra persona cambió este plato mientras usted lo editaba. No se guardó nada: revíselo y vuelva a intentar.» La pantalla ya muestra el cambio del otro |
| Dos personas pulsan «Enviar a cocina» a la vez | La segunda espera a la primera. Sale **una** hoja | La primera ve la ronda enviada; la segunda, «No hay platos nuevos para enviar» |
| El envío falla (la base de datos no respondió a tiempo) | No envía nada | «Los platos NO llegaron a la cocina… vuelva a pulsar». **Nunca** «no hay platos nuevos», que diría lo contrario |
| El mesero pulsa sin señal | No manda nada | «Sin señal: no se envió nada. Cuando vuelva la señal, vuelva a pulsar.» En lugar de la página de error del navegador |
| Recarga la página, o el celular reenvía el formulario al recuperar la señal | No repite nada | «Eso ya se había guardado; no se repitió.» |
| Pulsa en una pantalla que quedó vieja (abierta desde antes de una actualización) | No guarda nada y recarga | «Esta página estaba desactualizada y no se guardó nada…» |

**Por qué no se bloquea la comanda mientras alguien la edita.** Un candado sostenido por un celular
con mala señal, en un restaurante lleno, deja a todos los demás esperando. Es peor que pedirle a uno
de los dos que revise y vuelva a pulsar.

**Lo que sigue sin existir:** un modo sin conexión. Lo que ya estaba guardado está a salvo y el
sistema dice con claridad cuándo algo **no** se guardó, pero lo que el mesero estaba escribiendo
cuando se cayó la señal hay que volver a escribirlo.

---

### 4.11 Cómo se enciende para un comercio

Se hace **con** el comercio, no por defecto:

1. En **Configuración**, pestaña **Mesas**: encender.
2. En **Configuración**, pestaña **Comandas**: encender. (La casilla de la pantalla de cocina queda
   apagada: esa pantalla todavía no existe.)
3. En **Empleados**, crear a cada mesero con **un solo permiso: Comandas**. El permiso **«Permitir
   cancelar una comanda»** se le da solo a quien supervisa: cancelar una comanda que la cocina ya
   preparó es la acción que alguien va a querer revisar.
4. Entregarle al comercio la ficha de §4.10, y decirle en voz alta que **enviar a cocina e imprimir
   son dos actos**: el mesero envía desde el celular, y la hoja sale cuando alguien la imprime en la
   caja. En la comanda, cada ronda dice «Sin imprimir» hasta que se imprime.

---

## 5. Lo que este requerimiento NO hace

- **No reemplaza la forma actual de vender.** Es un camino paralelo, opcional y apagado por defecto.
- **No enruta a estaciones.** Una sola cocina, un solo destino. No hay barra que prepare aparte.
- **No hay aplicación móvil.** Se cubre con una pantalla responsive en el navegador (§4.8).
- **No vuelve responsive el resto del sistema.** Ese refactor completo es un proyecto aparte, que el
  dueño quiere hacer más adelante. Aquí se hace **una** pantalla, no la aplicación.

---

## 6. Decisiones tomadas

| # | Decisión | Fecha |
|---|---|---|
| **D1** | **La comanda ES la cuenta abierta** que después se cobra, no un documento aparte | 2026-09-22 |
| **D2** | **Sirve para salón y para domicilio**, sin distinguirlos: el nombre libre cubre los dos | 2026-09-22 |
| **D3** | **Nunca obligatoria.** La venta rápida de hoy sigue igual | 2026-09-22 |
| **D4** | **Cada comercio la enciende o la apaga.** Apagada, no existe | 2026-09-22 |
| **D5** | **Identidad por nombre libre**, no por número correlativo | 2026-09-22 |
| **D6** | **Una sola impresora**, la de la caja. Sin estaciones | 2026-09-22 |
| **D7** | **Instrucciones por plato, indispensables** | 2026-09-22 |
| **D8** | **Rondas: la segunda comanda imprime solo lo agregado** | 2026-09-22 |
| **D9** | **Modificar lo ya enviado se permite**, avisando al cajero Y en la pantalla de cocina | 2026-09-22 |
| **D10** | **Primero la caja, después el celular.** Es un orden de construcción, no un recorte de alcance: los dos están dentro de este requerimiento. *Reemplaza la versión de esta decisión que dejaba el celular fuera* | 2026-09-22 |
| **D11** | **El objetivo es el mesero, no la cocina.** Capturar el pedido en la mesa para que no se pierda camino a la caja | 2026-09-22 |
| **D12** | **La comanda lleva precios** | 2026-09-22 |
| **D13** | **El kit sale como plato, jamás desglosado** | 2026-09-22 |
| **D14** | **La comanda tiene estados** y se puede cancelar antes de pedir el pago | 2026-09-22 |
| **D15** | **La cocina es habilitable aparte de la comanda.** Un comercio puede usar comandas sin nada en cocina | 2026-09-22 |
| **D16** | **Sin aplicación móvil.** La pantalla de comanda se hace responsive y se usa desde el navegador del celular | 2026-09-22 |
| **D17** | **El mesero entra con su propio usuario.** Cada comanda queda con un responsable | 2026-09-22 |
| **D18** | **El refactor responsive del resto del sistema es otro proyecto.** Aquí se hace una sola pantalla | 2026-09-22 |
| **D19** | **Los dos interruptores se ponen en la Configuración del comercio**, junto a la pestaña «Mesas». No en la consola de plataforma | 2026-09-22 |
| **D20** | **La conectividad es un requisito del comercio**, no un riesgo del proyecto. El teléfono entra por datos móviles o WiFi | 2026-09-22 |
| **D21** | **Comandas necesita Mesas encendido**, y Mesas no se puede apagar con Comandas encendido. La comanda llega a la caja como una pestaña de Mesas (se eligió no abrirle un camino nuevo en la pantalla del dinero). *Corrige D4* | 2026-09-23 |
| **D22** | **La caja trae sola los platos del mesero** y **nunca cobra un total que el cajero no vio**. Lo que el mesero cambia después de pasar a la caja se avisa al cajero, no se aplica solo | 2026-09-23 |
| **D23** | **El mesero cae directo en su pantalla y tiene su propia salida.** Con solo el permiso de Comandas no alcanza ninguna otra pantalla | 2026-09-23 |

### 6.1 Resueltas el 2026-09-22

| # | Pregunta | Respuesta del dueño |
|---|---|---|
| **P1** | ¿La comanda lleva precios? | **Sí.** No importa que salgan |
| **P2** | ¿Un kit sale desglosado? | **No, definitivamente.** Sale como la unidad, como el plato |
| **P3** | ¿Y una cuenta sin cobrar al cierre? | Lo mira la operación. Una comanda finalizada debió pagarse en caja; para eso la comanda necesita estados y gestión de orden (§4.7) |
| **P4** | ¿La pantalla de cocina? | **Habilitable, y no la tenemos todavía.** Hay comercios que la van a requerir y comercios que no |

---

## 7. Alcance, en entregas

### Entrega 1 — La comanda desde la caja — **construida, pendiente de probar en staging**
Cuenta abierta con nombre libre, instrucciones por plato, estados de la comanda (§4.7), comanda
impresa con precios, rondas con solo lo agregado, y el interruptor por comercio. Es el cimiento:
define el dato, y cualquier pantalla posterior lo lee.

Se construyó ya **responsive**, así que un mesero puede usarla desde el celular desde el primer día;
lo que la Entrega 2 agrega es que varios teléfonos trabajen a la vez sobre la misma comanda sin
pisarse.

### Entrega 2 — La misma pantalla, desde el celular del mesero — **construida el 2026-09-23, pendiente de certificar en staging**
La pantalla de comanda hecha responsive, con el mesero autenticándose desde el navegador de su
teléfono. **Es aquí donde el requerimiento entrega lo que vino a entregar.**

Resuelve dos meseros sobre la misma comanda y lo que pasa cuando el teléfono pierde la señal a mitad
de un pedido (§4.12). También la ficha que se le entrega al comercio
(`comandas-ficha-para-el-comercio.md`).

Falta la prueba que no se puede automatizar: **un turno en staging con dos meseros, dos teléfonos
reales y la caja cobrando**, hecha por alguien que no escribió el código.

### Entrega 3 — La pantalla de cocina
El monitor que muestra los pedidos y se actualiza solo. Va de última **porque es la parte opcional**:
hay comercios que la van a pedir y comercios que no, y se enciende aparte de las comandas (D15).
