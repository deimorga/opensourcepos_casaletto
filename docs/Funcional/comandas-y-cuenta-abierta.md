# Alcance funcional — Comandas: el pedido que se toma en la mesa

> **Estado:** requerimiento **cerrado** el 2026-09-22 con el dueño. **Nada construido todavía.**
> Decisiones en §6. No quedan preguntas abiertas: §6.1 recoge las cuatro que había y su respuesta.
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

Antes hay que limpiar el `Unidad: kilogramo` que hoy ensucia el 90 % de las líneas, o la comanda
saldrá ilegible.

### 4.5 Se agregan platos después, y solo sale lo nuevo

Un pedido crece: el cliente pide dos cosas más a los veinte minutos. **La segunda comanda imprime
únicamente lo que se agregó**, marcado como añadido, para que la cocina no vuelva a preparar lo que
ya despachó.

Eso exige que el sistema recuerde qué líneas ya se mandaron. Es la parte más delicada del trabajo
—el documento técnico explica por qué— y es la que no se puede improvisar.

### 4.6 Si se toca algo ya enviado, se avisa y se deja

Quitar o cambiar un plato que ya está en cocina **no se bloquea**. Se avisa:

- **Al cajero**, con una advertencia en su pantalla: *«esto ya está en cocina»*.
- **En la cocina**, en un monitor que muestra los pedidos y se actualiza solo.

Y el pedido se actualiza para que **la facturación final cobre lo que de verdad se sirvió**.

### 4.7b La comanda tiene estados, y se gestiona

Una comanda no solo se abre y se cobra. Hay que poder decir qué pasó con ella:

- **Abierta** — el pedido está tomado y puede seguir creciendo.
- **Entregada** — se confirma que el pedido llegó a la mesa o salió a domicilio. Es la gestión de
  orden que permite saber qué está pendiente de servir.
- **Cancelada** — se cae antes de pedir el pago. Ocurre, y hoy no hay forma de registrarlo.
- **Cobrada** — se facturó en la caja y la cuenta se cierra.

Una comanda que se finalizó **debería haberse pagado en la caja**. Si al cierre del día queda una
cuenta sin cobrar, eso no es un caso que el sistema deba resolver solo: es algo que la operación
tiene que mirar, y para eso hace falta que se vea.

### 4.7 La cuenta se ve y se actualiza en la pantalla de venta

La comanda no es un papel que se va y se olvida: **es una cuenta viva** en el módulo de venta, que
refleja en todo momento lo que se ha pedido y lo que se mandó a cocina.

---

### 4.8 El mesero toma el pedido desde su celular, por el navegador

No va a haber aplicación móvil. **La pantalla de comanda se construye responsive**, y con eso el
mesero entra desde el navegador de su teléfono, se autentica con su propio usuario y captura el
pedido de pie junto a la mesa. Eso es exactamente lo que el requerimiento venía a resolver: que el
pedido no dependa de la memoria de alguien caminando hacia la caja.

Cada mesero entra con **su** usuario, así que cada comanda queda con un nombre detrás.

Que el teléfono sirva no significa que el resto del sistema sirva en el teléfono: **hoy solo la
pantalla de ingreso está preparada para un celular.** Cualquier otra pantalla a la que el mesero
llegue va a salir en ancho de escritorio. Es una limitación conocida y aceptada, y el mesero no
necesita ninguna otra pantalla.

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
| **D10** | **Primero la caja.** El celular del mesero es requerimiento aparte | 2026-09-22 |
| **D11** | **El objetivo es el mesero, no la cocina.** Capturar el pedido en la mesa para que no se pierda camino a la caja | 2026-09-22 |
| **D12** | **La comanda lleva precios** | 2026-09-22 |
| **D13** | **El kit sale como plato, jamás desglosado** | 2026-09-22 |
| **D14** | **La comanda tiene estados** y se puede cancelar antes de pedir el pago | 2026-09-22 |
| **D15** | **La cocina es habilitable aparte de la comanda.** Un comercio puede usar comandas sin nada en cocina | 2026-09-22 |
| **D16** | **Sin aplicación móvil.** La pantalla de comanda se hace responsive y se usa desde el navegador del celular | 2026-09-22 |
| **D17** | **El mesero entra con su propio usuario.** Cada comanda queda con un responsable | 2026-09-22 |
| **D18** | **El refactor responsive del resto del sistema es otro proyecto.** Aquí se hace una sola pantalla | 2026-09-22 |

### 6.1 Resueltas el 2026-09-22

| # | Pregunta | Respuesta del dueño |
|---|---|---|
| **P1** | ¿La comanda lleva precios? | **Sí.** No importa que salgan |
| **P2** | ¿Un kit sale desglosado? | **No, definitivamente.** Sale como la unidad, como el plato |
| **P3** | ¿Y una cuenta sin cobrar al cierre? | Lo mira la operación. Una comanda finalizada debió pagarse en caja; para eso la comanda necesita estados y gestión de orden (§4.7b) |
| **P4** | ¿La pantalla de cocina? | **Habilitable, y no la tenemos todavía.** Hay comercios que la van a requerir y comercios que no |

---

## 7. Alcance, en entregas

### Entrega 1 — La comanda desde la caja
Cuenta abierta con nombre libre, instrucciones por plato, estados de la comanda (§4.7b), comanda
impresa con precios, rondas con solo lo agregado, y el interruptor por comercio. Es el cimiento:
define el dato, y cualquier pantalla posterior lo lee.

### Entrega 2 — La misma pantalla, desde el celular del mesero
La pantalla de comanda hecha responsive, con el mesero autenticándose desde el navegador de su
teléfono. **Es aquí donde el requerimiento entrega lo que vino a entregar.**

Lo que hay que resolver en esta entrega y no antes: dos meseros sobre la misma comanda, y qué pasa
cuando el teléfono pierde señal a mitad de un pedido.

### Entrega 3 — La pantalla de cocina
El monitor que muestra los pedidos y se actualiza solo. Va de última **porque es la parte opcional**:
hay comercios que la van a pedir y comercios que no, y se enciende aparte de las comandas (D15).
