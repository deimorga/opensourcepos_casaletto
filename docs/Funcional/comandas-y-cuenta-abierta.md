# Alcance funcional — Comandas: el pedido que llega a la cocina

> **Estado:** requerimiento definido el 2026-09-22 con el dueño. **Nada construido todavía.**
> Decisiones en §6. Lo que queda por confirmar, en §6.1.
>
> Documento hermano: `docs/Tecnico/comandas-y-cuenta-abierta.md`.

---

## 1. El problema, en una frase

**La cocina no recibe nada del sistema.** El pedido se arma en la caja, se cobra, y lo que se
prepara viaja de la pantalla a los fogones por voz o por un papel escrito a mano.

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

### 4.7 La cuenta se ve y se actualiza en la pantalla de venta

La comanda no es un papel que se va y se olvida: **es una cuenta viva** en el módulo de venta, que
refleja en todo momento lo que se ha pedido y lo que se mandó a cocina.

---

## 5. Lo que este requerimiento NO hace

- **No reemplaza la forma actual de vender.** Es un camino paralelo, opcional y apagado por defecto.
- **No enruta a estaciones.** Una sola cocina, un solo destino. No hay barra que prepare aparte.
- **No incluye la toma desde el celular del mesero** en la primera entrega. Ver §7.

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

### 6.1 Pendientes de confirmar

| # | Pregunta | Recomendación |
|---|---|---|
| **P1** | ¿La pantalla de cocina es un monitor fijo en la cocina, o basta con que alguien abra una página? | Un monitor fijo con la página abierta. Es lo más barato y no exige aplicación nueva |
| **P2** | ¿La comanda impresa lleva precios? | **No.** El cocinero no necesita precios y el papel se lee mejor sin ellos |
| **P3** | ¿Un kit sale a cocina como el plato o desglosado en sus ingredientes? | Como el plato. El 69 % de los pedidos lleva kit, y desglosar «SANDWICH 4 CARNES» en 12 ingredientes haría la comanda ilegible |
| **P4** | ¿Qué pasa con una cuenta abierta que nadie cobra al cierre del día? | Que el cuadre la muestre. Hoy una cuenta abierta no entra en reportes pero sí existe en la base |

---

## 7. Alcance, en entregas

### Entrega 1 — La comanda desde la caja
Cuenta abierta con nombre libre, instrucciones por plato, comanda impresa, rondas con solo lo
agregado, y el ajuste por comercio. **Con esto la cocina ya recibe el pedido del sistema.**

### Entrega 2 — La pantalla de cocina
El monitor que muestra los pedidos y se actualiza solo, incluida la notificación de lo que cambió.
Es lo que hoy la aplicación no puede hacer sin trabajo de fondo: la pantalla de venta no sabe
empujar cambios al navegador.

### Entrega 3 — El celular del mesero
Requerimiento aparte, con su propio análisis. Tomar el pedido en la mesa desde un teléfono obliga a
resolver que varias personas editen el mismo pedido a la vez, la autenticación de meseros y qué
pasa cuando no hay señal.
