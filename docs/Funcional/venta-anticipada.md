# Alcance funcional — Venta anticipada (Preventas): se paga por cuotas, se entrega al final

> **Estado (2026-10-07):** **alcance cerrado, en construcción.** Todas las preguntas al dueño están
> respondidas en tres rondas (§6) y no quedan supuestos abiertos. Lo que hay hoy en el sistema está
> verificado contra el código (§2). La salida a producción está planeada para **finales de octubre de
> 2026** (§7); las fechas se pueden ajustar. Nada de esto existe todavía en ningún negocio.
>
> Documento hermano: `docs/Tecnico/venta-anticipada.md`.

---

## 1. El problema, en una frase

Un negocio quiere **vender hoy y entregar después**. El cliente separa uno o varios productos de
una **campaña**, deja una cuota inicial y se compromete a pagar el resto en fechas acordadas. **Recibe
el producto solo cuando terminó de pagar**, en una de las fechas de entrega de la campaña. Hoy el
sistema no tiene cómo registrarlo.

**Es un módulo de la plataforma, no una función de un negocio.** Cualquier negocio lo enciende cuando
lo necesite. Uno que no lo encienda no ve ningún cambio.

**El primer negocio que lo usa es Casaletto**, para su temporada navideña: vende desde noviembre los
productos de Navidad y los entrega en diciembre. Ese caso fija la fecha de salida, no el diseño. Le
sirve para dos cosas, y le servirían igual a cualquier otro negocio:

1. **Tener flujo de caja antes de la temporada.**
2. **Saber con tiempo qué comprar.** Las preventas dicen cuántas unidades de cada producto ya están
   comprometidas y para qué fecha.

---

## 2. Qué hay hoy, verificado

### 2.1 No había nada documentado sobre esto

Revisé toda la documentación propia (`docs/Funcional`, `docs/Tecnico`) y la copia de la wiki de
OSPOS. Busqué preventa, anticipo, separado, plan de pagos, cuota, abono, encargo, fecha de entrega,
cuentas por cobrar y fiado. **Ningún documento propio trata el tema** y **ningún negocio lo había
pedido antes.**

La wiki de OSPOS dice expresamente que el sistema **no** hace apartados ni tiene cuentas por cobrar
(`referencia-ospos-wiki/Complete-feature-datasheet.md`). Lo más parecido que describe es la
**Orden de Trabajo** (`referencia-ospos-wiki/Work-Orders.md`), pensada para talleres de reparación.

### 2.2 Lo que trae el sistema, y por qué no sirve tal cual

| Pieza que existe | Qué hace | Por qué no resuelve la preventa |
|---|---|---|
| Medio de pago **«Adeudo»** | Cierra una venta sin cobrarla, siempre que tenga cliente | Es **fiado**, lo contrario de lo que queremos: el producto sale el primer día, el inventario baja ese día y la venta cuenta como ingreso ese día. No hay forma de abonar por partes |
| **Venta suspendida** | Guarda los productos de una venta sin terminar, y puede llevar pagos | Al terminarla, el sistema **borra y vuelve a escribir los pagos con la fecha y el turno del día en que se termina**. Una cuota cobrada en octubre aparecería cobrada en diciembre, y el cuadre de caja de octubre quedaría descuadrado |
| **Orden de trabajo** (apagada en todos los negocios) | Venta suspendida con pagos tipo «Depósito» | Mismo problema de los pagos. Además, el «Depósito en efectivo» **no cuenta como efectivo del cajón** al cerrar el turno |
| **Cotización** | Precio propuesto a un cliente | No admite pagos, a propósito |
| **Tarjeta de regalo** | Saldo prepagado | Cargarle plata no es una venta y nunca pasa por el turno. No está ligada a productos ni a una fecha de entrega |

### 2.3 Lo que falta por completo

- **Campañas**: qué productos se venden en preventa, a qué precio, con qué fechas de entrega y hasta
  cuándo.
- Un **plan de cuotas**: qué día y cuánto se compromete a pagar el cliente.
- **Abonos** que queden registrados el día y en el turno en que entró la plata.
- Saber **cuánto ha pagado y cuánto debe** cada cliente, y **quién va atrasado**.
- **Impedir la entrega** mientras haya saldo.
- Una **lista de lo comprometido por producto y fecha de entrega**, para planear las compras.
- Registrar una **cancelación** con lo que se haya acordado con el cliente.

**Conclusión:** del sistema actual se aprovechan la ficha del cliente, el catálogo de productos, la
pantalla de caja (con la que se hace la entrega) y el cuadre de caja. **La preventa en sí hay que
construirla.**

### 2.4 Lo que hay que revisar antes de abrir una campaña

- **Que los productos de la campaña existan en el catálogo.** En Casaletto, el Pavo navideño
  (código C10138) está borrado y sigue dentro de 4 recetas (`articulos-e-ingredientes.md`). Si se
  va a vender, hay que recuperarlo o crearlo bien antes de armar la campaña.
- **Las tildes en los nombres de clientes.** El arreglo de tildes de 2026-08-22 dejó pendientes
  Clientes y Tarjetas de regalo. Antes de registrar cientos de clientes nuevos hay que comprobar
  que «José» se guarda como «José» y se encuentra al buscarlo (`docs/Tecnico/venta-anticipada.md`
  §8.7).

---

## 3. La solución: un módulo nuevo, «Preventas», organizado por campañas

Una **campaña** es la temporada de preventa que arma el negocio, por ejemplo «Navidad 2026». Define:

- **qué productos** se pueden vender en preventa, y **a qué precio**;
- **en qué fechas** se entrega;
- **hasta cuándo** se aceptan preventas nuevas.

Una **preventa** es el acuerdo con un cliente registrado dentro de una campaña. Tiene seis partes:

- **los productos** que se lleva y su precio, que queda pactado;
- **la fecha de entrega**, elegida entre las de la campaña;
- **el plan de cuotas**, con fechas y montos acordados con el cliente;
- **los abonos** que va haciendo;
- **el saldo**, que es lo que falta por pagar;
- **un estado**: al día, atrasada, pagada, entregada o cancelada.

**El producto no sale del inventario hasta la entrega**, y **la entrega solo se puede hacer con el
saldo en cero.** La entrega se hace en la pantalla de caja, y ahí la preventa se vuelve una venta
normal.

---

## 4. Cómo funciona

### 4.1 Nada de esto es obligatorio

El módulo viene **apagado** en todos los negocios. Uno que no lo enciende no ve ningún cambio: ni
menú, ni pantallas, ni nada distinto en la caja, en el cuadre o en los reportes. Lo enciende el
administrador de cada negocio cuando lo necesite (§4.12).

### 4.2 Configurar una campaña

La arma quien tenga el permiso «Gestionar preventas» (§4.13), antes de empezar a vender:

1. **Nombre**, por ejemplo «Navidad 2026».
2. **Periodo de venta**: desde qué día y **hasta qué día** se pueden registrar preventas nuevas.
   Pasada la fecha de cierre, la campaña ya no acepta preventas nuevas. **Las que ya existen siguen
   recibiendo abonos y se siguen entregando** normalmente.
3. **Fechas de entrega**: la lista de días en que se va a entregar, por ejemplo 23 y 24 de
   diciembre. Así las entregas quedan unificadas por logística.
4. **Descuento de la campaña** (opcional): un porcentaje que aplica a **todos** sus productos.
5. **Cuota inicial mínima** (opcional): el porcentaje del total que el cliente tiene que pagar como
   mínimo al registrar la preventa, por ejemplo 30%. En cero, no hay mínimo.
6. **Productos**: se buscan **en el catálogo del negocio** y se agregan. Si hace falta un producto
   que no existe, primero se crea en Artículos. Para cada uno:
   - el precio de preventa **arranca en el precio de catálogo** de ese momento;
   - se le puede poner un **descuento en porcentaje propio**, que reemplaza al de la campaña para ese
     producto;
   - o se le puede poner **un precio propio de la campaña**, distinto al de la caja.

   El orden es: precio propio, si lo tiene; si no, el precio de catálogo con el descuento del
   producto; y si el producto no tiene descuento propio, con el de la campaña.
7. **Activar** la campaña.

Detalles:

- **Si el precio del catálogo cambia** después de agregar el producto, el precio de la campaña
  **no cambia solo**. Si se quiere, se actualiza a mano en la campaña.
- **Un negocio puede tener varias campañas a la vez**, por ejemplo Navidad y Año Nuevo. Cada
  preventa pertenece a una sola.
- Los productos pueden ser de cualquier tipo: normales, recetas armadas (kits) o **por peso**. Uno
  por peso se pacta con un peso inicial, por ejemplo 2,5 kg, que puede cambiar en la entrega (§4.8).
- **No hay cupo**: la campaña no limita cuántas unidades de un producto se venden en preventa.

### 4.3 Registrar la preventa (el «contrato»)

Se hace una a una, con el cliente al frente o al teléfono:

1. **Campaña.** Se elige entre las que están dentro de su periodo de venta.
2. **Cliente.** Se busca entre los registrados. Si no existe, se crea en el momento con la misma
   ficha de Clientes. **No hay preventas sin cliente**: es a quien se le cobra y a quien se le
   entrega.
3. **Productos.** Solo los de la campaña, con cantidad. Cada uno trae el precio de la campaña, y
   **ese queda pactado** en esta preventa (§4.7).
4. **Fecha de entrega.** Se elige de la lista de la campaña.
5. **Plan de cuotas.** Quien registra escribe las cuotas acordadas con el cliente: fecha y monto de
   cada una. La primera es la **cuota inicial**. Hay tres reglas:
   - **la suma de las cuotas tiene que dar el total**; si no da, el sistema no deja guardar y dice
     cuánto falta o sobra;
   - ninguna cuota puede quedar después de la fecha de entrega;
   - la cuota inicial no puede ser menor que el mínimo de la campaña, si la campaña tiene uno.
6. **Cuota inicial.** Se cobra en ese mismo momento, como cualquier pago de la caja (efectivo,
   datáfono o transferencia).
7. **Comprobante.** Se imprime un comprobante de preventa para el cliente. Lleva la campaña, los
   productos, el precio pactado, la fecha de entrega, el plan de cuotas, lo abonado, el saldo y las
   **condiciones de la preventa** (§4.14). Es el «contrato» en papel.

Cada preventa recibe un **número propio** (por ejemplo `PV-000123`), que es el que se le da al
cliente y el que se usa para buscarla.

### 4.4 Abonar

El cliente vuelve y paga una cuota, o lo que pueda:

1. Se busca la preventa por número, por nombre del cliente o por teléfono.
2. Se ve el resumen: total, abonado, saldo, próxima cuota y si va atrasada.
3. Se registra el abono: monto y medio de pago.
4. Se imprime el **comprobante de abono** con lo pagado hoy, el acumulado y el saldo.

Reglas:

- **El abono no tiene que coincidir con la cuota.** El cliente puede pagar menos, más o adelantar
  varias. Lo que importa es el acumulado frente al plan (§4.6).
- **No se puede abonar más del saldo.**
- **Medios de pago admitidos: efectivo, datáfono (débito o crédito) y transferencia.** No se admite
  «Adeudo», que contradice la idea de pagar antes, ni tarjeta de regalo ni puntos.
- **Se necesita un turno de caja abierto**, igual que para vender.
- Se puede abonar aunque la campaña ya haya cerrado su periodo de venta.

### 4.5 La plata entra al turno que la recibió

Esto es lo más importante para la operación. **Un abono cuenta en el cuadre del turno en que se
recibió**, como cualquier pago de la caja:

- Un abono en efectivo **suma a lo que debe haber en el cajón** de ese turno.
- Un abono por datáfono o transferencia suma a los ingresos de ese turno, en su medio de pago.
- En la pantalla de cierre del turno, los abonos aparecen **en un renglón propio, «Abonos de
  preventa»**, para que el cajero sepa de dónde salió esa plata.

**El día de la entrega esa plata no se vuelve a contar**, porque ya entró en su momento (§4.8). En el
cierre de ese turno la entrega aparece aparte, como «Entregas de preventa (cobradas antes)».

### 4.6 Al día, atrasada, pagada

El sistema compara **lo abonado** con **lo que el plan decía que ya debía estar pagado a la fecha**:

| Estado | Cuándo |
|---|---|
| **Al día** | Lo abonado cubre todas las cuotas vencidas |
| **Atrasada** | Hay al menos una cuota vencida que lo abonado no alcanza a cubrir. **Solo se marca**: no hay recargo, ni intereses, ni pérdida de la reserva |
| **Pagada** | El saldo es cero. Lista para entregar |
| **Entregada** | Ya se entregó; es una venta |
| **Cancelada** | Se canceló (§4.10) |

Se muestra cuántos días lleva atrasada y cuánto falta para ponerse al día.

### 4.7 El precio queda pactado

- El precio de cada producto **queda congelado el día de la preventa**, tomado de la campaña. Si
  después cambia el precio de la campaña o del catálogo, esa preventa no cambia.
- **Ajustar el precio o las cantidades de una preventa ya registrada** queda para después de la
  salida (§7). Mientras tanto, si hay que cambiar algo se cancela y se registra de nuevo.

### 4.8 Entregar, desde la pantalla de caja

1. En la lista de preventas, o buscándola, se pulsa **Entregar**.
2. **Si tiene saldo, Entregar no está habilitado** y el sistema dice cuánto falta. No hay excepción:
   es la regla del negocio.
3. Con saldo en cero, la preventa **se abre en la pantalla de caja**, en una pestaña propia, con el
   cliente, los productos a precio pactado y el pago «Preventa» por lo abonado. **Nada de eso se
   puede modificar ahí**: ni productos, ni precios, ni cliente, ni ese pago. La única excepción es
   el peso real de los productos por peso (ver abajo).
4. El cajero pulsa **Completar**, como en cualquier venta. En ese momento:
   - la preventa se convierte en una **venta normal**, con su número de venta;
   - **el inventario baja ese día**, como en cualquier venta;
   - la venta queda pagada con lo abonado, y esa plata **no se suma otra vez** a la caja del turno
     de la entrega;
   - se imprime el recibo normal de venta y la preventa queda **Entregada**.

Funciona igual en un negocio que usa mesas y en uno que no, con una diferencia de forma
(construido el 2026-10-07, pendiente de certificar en staging):

- **Con mesas**, la entrega aparece como una pestaña más de la barra, con el número de la preventa
  (por ejemplo `PV-000123`). El cajero puede atender otra mesa y volver a ella, recargar la página o
  abrirla desde otra caja: sigue siendo la entrega, con su pago «Preventa».
- **Sin mesas**, la entrega se carga directamente en la venta que está en pantalla, como cualquier
  venta de ese negocio.
- En los dos casos, **si la caja tiene una venta a medias que no está guardada**, el sistema no la
  borra: pide completarla o suspenderla antes de entregar.
- En pantalla se ve una franja azul: «Entrega de preventa PV-000123 — solo se puede ajustar el peso
  de los productos por peso». Los botones que no aplican (buscar productos, borrar líneas, quitar el
  cliente, Suspender, Cancelar) no aparecen.
- Pulsar «Entregar» dos veces no abre dos entregas, y si dos cajas completan la misma entrega a la
  vez, solo una venta queda registrada.
- Si la preventa se cancela mientras su entrega está abierta en la caja, la entrega desaparece de la
  caja con un aviso.
- Si se mandó la preventa equivocada o el cliente no llegó, el botón **«Devolver a preventas»** (en
  lugar de Cancelar) saca la entrega de la caja sin cobrar nada; la preventa sigue abierta y pagada y
  se puede volver a entregar. Cancelar y Suspender no existen en una entrega.
- Si el peso real es distinto del pactado, queda anotado en la historia de la preventa.
- **Pendiente de decidir (dueño):** hoy no hay un límite a cuánto puede bajar el peso, y lo que baja
  se devuelve en efectivo. ¿Se pone una tolerancia, o se pide autorización por encima de cierto
  monto?

**Productos por peso.** El peso pactado es inicial. En la entrega, **lo único que el cajero puede
cambiar en esa pestaña es el peso real** de esas líneas, al precio por kilo pactado. Si el total
cambia:

- **si pesa más**, la diferencia se cobra en ese momento con cualquier medio de pago de la caja, y
  cuenta en el turno de la entrega;
- **si pesa menos**, la diferencia se le devuelve al cliente como vuelto, en efectivo, desde el cajón
  de ese turno.

El saldo de la preventa tiene que estar en cero **antes** de entregar; la diferencia por peso se
arregla en la entrega misma.

**Fecha de entrega vencida:** si llega la fecha y el cliente no ha terminado de pagar, la preventa
sigue abierta y aparece señalada en la lista. El negocio decide con el cliente qué hacer: esperar o
cancelar.

### 4.9 Ventas ya entregadas

La venta que nace de una entrega **no se puede anular** como una venta común, porque dejaría la
preventa entregada con la plata cobrada y el inventario devuelto. Si el cliente devuelve producto ya
entregado, se usa el modo **Devolución** normal de la caja.

### 4.10 Cancelar: lo que se acuerde con el cliente

La devolución del dinero **no tiene una regla fija**: se negocia con cada cliente. El sistema no la
decide; **registra lo que se acordó**:

1. Una persona con permiso «Gestionar preventas» (§4.13) cancela la preventa y escribe el motivo.
2. Indica **cuánto se le devuelve al cliente**: cualquier valor entre cero y lo abonado. Si es todo,
   la devolución es total; si es una parte o nada, el resto lo retiene el negocio.
3. Si hay devolución, se indica el medio. **Una devolución en efectivo sale del cajón del turno
   abierto** y aparece en su cierre, igual que un abono pero restando.
4. Se imprime un **comprobante de cancelación** con lo abonado, lo devuelto y lo retenido.

El producto nunca salió del inventario, así que no hay nada que devolver a bodega.

### 4.11 Listas y reportes

- **Lista de preventas**, con filtros por campaña, estado, fecha de entrega y cliente. Muestra total,
  abonado, saldo, próxima cuota y días de atraso. Sirve para llamar a quien va atrasado y para
  preparar las entregas de cada fecha.
- **Lo comprometido, por campaña**: por producto, cuántas unidades están comprometidas en preventas
  abiertas, separadas por fecha de entrega, y cuántas hay hoy en inventario. **Es la lista de
  compras de la temporada.**
- **Cuadre de caja**: los renglones «Abonos de preventa» y «Entregas de preventa» en cada turno
  (§4.5).

### 4.12 Cómo se enciende para un negocio

Lo hace el **administrador de cada negocio**, cuando lo necesite. No lo hace la plataforma:

1. En **Configuración**, pestaña **Preventas**, encender «Usar preventas». Ahí mismo se escribe el
   prefijo del número (por defecto `PV-`) y las condiciones que llevan los comprobantes (§4.14).
2. En **Empleados**, dar el permiso **Preventas** a quien vaya a registrar, abonar y entregar.
3. Dar el permiso **«Gestionar preventas»** solo a quien vaya a armar campañas y cancelar.
4. Crear la primera campaña (§4.2).

Apagarlo **no borra nada**: las campañas y las preventas quedan guardadas y reaparecen al encenderlo
otra vez. **Mientras haya preventas abiertas no se debe apagar**, porque nadie podría abonar ni
entregar. La pantalla avisa cuántas hay abiertas antes de dejar apagarlo.

### 4.13 Permisos

| Permiso | Para quién | Qué permite |
|---|---|---|
| **Preventas** | El cajero | Ver, registrar preventas, abonar, entregar e imprimir comprobantes |
| **Gestionar preventas** | El encargado o el dueño | Además: crear y editar campañas, y cancelar con o sin devolución |

### 4.14 Las condiciones de la preventa

Todos los comprobantes (preventa, abono y cancelación) llevan al pie las **condiciones** del negocio.
Cada negocio escribe las suyas en Configuración, pestaña Preventas. Para no arrancar en blanco, la
pantalla tiene el botón **«Usar texto sugerido»**, que llena el campo con este texto. El negocio lo
corrige a su gusto antes de guardar:

> **CONDICIONES DE LA PREVENTA**
>
> 1. Este comprobante reserva los productos y los precios aquí indicados para la fecha de entrega
>    señalada.
> 2. Los productos se entregan **únicamente cuando el valor total esté pagado**.
> 3. El cliente se compromete a pagar las cuotas en las fechas acordadas. Puede abonar antes de cada
>    fecha y por cualquier valor, hasta completar el saldo.
> 4. Los atrasos en las cuotas no generan intereses ni recargos.
> 5. Los productos que se venden por peso se pactan con un peso aproximado. El valor final se ajusta
>    al peso real el día de la entrega, y la diferencia se paga o se devuelve ese mismo día.
> 6. Si el cliente cancela la preventa, la devolución de lo abonado se acordará entre el cliente y el
>    negocio, y quedará registrada en el comprobante de cancelación.
> 7. Para abonar y para reclamar los productos, presente este comprobante o su número de preventa.

El texto sugerido no menciona ningún negocio. Lo que se imprime es lo que cada negocio guarde.

---

## 5. Lo que este requerimiento NO hace

- **No es fiado.** El producto no sale antes de estar pagado. El crédito con entrega inmediata
  («Adeudo») sigue como está, sin cambios.
- **No cobra recargos ni intereses por atraso.** Solo se marca (D4).
- **No limita cuántas unidades se venden en preventa** («solo 40 pavos»), por ahora. Lo
  comprometido se informa para planear las compras, pero no hay cupo.
- **No aparta inventario.** Mientras llega la entrega, el sistema no impide vender esas unidades en
  la caja.
- **No vende productos que no estén en el catálogo.** Un producto nuevo se crea primero en Artículos.
- **No envía recordatorios** por SMS, WhatsApp ni correo.
- **No cambia el tratamiento contable ni de impuestos.** La venta se registra como cualquier venta
  del POS (D7). La facturación electrónica sigue por fuera, como hoy.
- **No hay preventas por lotes ni importación masiva.** Una a una (D9).
- **No admite entregas parciales.** Una preventa se entrega completa (D6).
- **No toca las órdenes de trabajo, las cotizaciones ni las ventas suspendidas.** Siguen igual.

---

## 6. Decisiones tomadas

| # | Decisión | Fecha |
|---|---|---|
| **D1** | Es un **módulo nuevo**, «Preventas», con su propio menú y permisos. No reutiliza ventas suspendidas ni órdenes de trabajo, porque reescriben los pagos con la fecha de la entrega (§2.2) | 2026-10-07 |
| **D2** | **Para todos los negocios, apagado por defecto**; lo enciende el administrador de cada negocio cuando lo necesite. Casaletto es el primero en usarlo, no el destinatario | 2026-10-07 |
| **D3** | **Solo clientes registrados.** La preventa exige cliente | 2026-10-07 |
| **D4** | **Atraso: solo se marca**, sin penalización, porque el producto no se ha entregado | 2026-10-07 |
| **D5** | **Fechas de cuotas libres**: las acuerda el negocio con el cliente y se escriben al registrar la preventa | 2026-10-07 |
| **D6** | **Una sola entrega por preventa**, en una de las fechas de la campaña (D15) | 2026-10-07 |
| **D7** | **Se trata como una venta más del POS**, sin contabilidad ni impuestos aparte. **Confirmado:** cada abono cuenta el día y en el turno en que entra; la venta, con sus productos y su inventario, cuenta el día de la entrega | 2026-10-07 |
| **D8** | **Precio congelado** al registrar. El ajuste de una preventa ya registrada queda para después de la salida | 2026-10-07 |
| **D9** | Pueden ser **cientos de preventas**, pero se registran **una a una** | 2026-10-07 |
| **D10** | **La cancelación queda abierta**: se registra lo que se negocie con el cliente, devolución de 0 al 100% de lo abonado | 2026-10-07 |
| **D11** | **No se entrega con saldo pendiente**, sin excepción | 2026-10-07 |
| **D12** | **Cada abono cuenta en el turno que lo recibió**, y no se vuelve a contar al entregar | 2026-10-07 |
| **D13** | **Campañas configurables con nombre.** Toda preventa pertenece a una campaña; puede haber varias a la vez | 2026-10-07 |
| **D14** | **Precio de campaña = precio de catálogo por defecto**, con descuento % o precio propio por producto. No sigue los cambios posteriores del catálogo | 2026-10-07 |
| **D15** | **La campaña define la lista de fechas de entrega**; la preventa elige una | 2026-10-07 |
| **D16** | **La campaña tiene periodo de venta con fecha de cierre.** Cerrada, no hay preventas nuevas, pero sí abonos y entregas | 2026-10-07 |
| **D17** | **La entrega se hace en la pantalla de caja**, en una pestaña bloqueada que el cajero completa | 2026-10-07 |
| **D18** | **La salida incluye la cancelación con devolución.** El ajuste de precio, el cambio de fecha y la cartera llegan después | 2026-10-07 |
| **D19** | **Descuento configurable por campaña y por producto.** El del producto reemplaza al de la campaña; el precio propio reemplaza a ambos | 2026-10-07 |
| **D20** | **Sin cupo de unidades**, por ahora | 2026-10-07 |
| **D21** | **Cuota inicial mínima configurable en la campaña**, en porcentaje del total; cero = sin mínimo | 2026-10-07 |
| **D22** | **Productos por peso: peso inicial que puede cambiar en la entrega.** La diferencia se paga o se devuelve ese día | 2026-10-07 |
| **D23** | **Condiciones de la preventa: texto por negocio**, con un texto sugerido de partida (§4.14) | 2026-10-07 |
| **D24** | **Solo productos del catálogo del negocio.** Si falta uno, se crea en Artículos | 2026-10-07 |
| **D25** | **Nosotros certificamos en staging antes de entregar** a la certificación del equipo del negocio | 2026-10-07 |

### 6.1 Primera ronda de preguntas, resuelta el 2026-10-07

| # | Pregunta | Respuesta del dueño |
|---|---|---|
| 1 | Si el cliente cancela, ¿se devuelve el dinero? | Queda abierto. Se negocia con el cliente y el sistema debe permitir registrar lo que se defina |
| 2 | ¿El precio queda congelado? | Sí, con posibilidad de ajuste según las necesidades del negocio |
| 3 | ¿Qué pasa con las cuotas atrasadas? | Solo se marcan; sin penalización, porque el producto no se ha entregado |
| 4 | ¿Calendario libre o con plantillas? | Las fechas las define el negocio con el cliente al registrar la preventa |
| 5 | ¿Una o varias fechas de entrega? | Se buscan fechas unificadas por logística, pero la fecha la pone el negocio |
| 6 | ¿Cómo se trata contablemente? | Como todo lo que se vende en el POS; no debe cambiar. Es una venta, solo que anticipada |
| 7 | ¿Para qué negocios? | Para todos, configurable; el administrador indica si lo usa. Módulo nuevo |
| 8 | ¿Cuántas preventas? | Pueden ser cientos, pero una a una, no por lotes |

### 6.2 Segunda ronda, resuelta el 2026-10-07

| # | Pregunta | Respuesta del dueño |
|---|---|---|
| 9 | ¿La plata cuenta el día que entra y la venta el día de la entrega? | Sí, así |
| 10 | ¿La entrega se hace por la pantalla de caja o dentro del módulo? | Por la pantalla de caja |
| 11 | ¿Con qué se sale a vender la temporada? | Entrega 1 más la cancelación con devolución |
| 12 | ¿Cómo se sabe qué productos van en preventa? | Deben ser configurables: el negocio define los productos de cada campaña |
| 13 | ¿La campaña es una entidad propia con nombre? | Sí, campañas con nombre |
| 14 | ¿Qué precio toma un producto? | El de catálogo al inicio, con porcentajes de descuento definibles o valores propios de la campaña |
| 15 | ¿La campaña define las fechas de entrega? | Sí, una lista de fechas |
| 16 | ¿La campaña tiene periodo de venta? | Sí, con fecha de cierre |

### 6.3 Tercera ronda, resuelta el 2026-10-07

| # | Pregunta | Respuesta del dueño |
|---|---|---|
| 17 | ¿Descuento solo por producto o también por campaña? | Configurable por producto o por campaña |
| 18 | ¿Cupo de unidades por producto? | Por ahora, sin límite |
| 19 | ¿Cuota inicial mínima? | La define el negocio en la configuración de la campaña |
| 20 | ¿Productos por peso? | Tienen un valor inicial, pero puede cambiar |
| 21 | ¿Texto de condiciones del comprobante? | Hay que definirlo: queda el texto sugerido de §4.14 |
| 22 | ¿Qué productos se pueden vender? | Los que el negocio tenga en su catálogo; si necesita otros, los ingresa al catálogo |
| 23 | ¿Quién certifica? | El equipo del negocio, pero antes lo certificamos nosotros en staging |
| 24 | ¿Si la fecha aprieta? | Se pueden ajustar las fechas y el plan de despliegue |

No quedan supuestos abiertos.

---

## 7. Alcance, en entregas

La temporada del primer negocio manda: **para vender preventas navideñas desde noviembre, la salida
tiene que estar en producción a finales de octubre de 2026.**

### Salida — Vender, abonar, entregar y cancelar — **por construir, objetivo finales de octubre**

- Interruptor en Configuración y los dos permisos (§4.12, §4.13).
- Campañas: productos con precio de catálogo, descuento o precio propio; fechas de entrega; periodo
  de venta (§4.2).
- Registrar la preventa con plan de cuotas y cobrar la cuota inicial, con su comprobante (§4.3).
- Abonar, con comprobante (§4.4).
- Los abonos entran al cuadre del turno que los recibió, en su renglón propio (§4.5).
- Estados al día, atrasada y pagada (§4.6).
- Entregar desde la pantalla de caja, solo con saldo cero, sin contar dos veces la plata (§4.8).
- Cancelar con devolución de 0 a 100% y su comprobante (§4.10).
- Lista de preventas y lo comprometido por campaña (§4.11).

### Después — Gestionar — **por construir**

- Ajustar precio o cantidades de una preventa registrada, con motivo y registro de quién lo hizo.
- Mover la fecha de entrega y rehacer el plan de cuotas, con motivo.
- Cartera de preventas: cuánto falta por cobrar en total y cuánto está vencido.
- Cupo de unidades por producto, si se pide.

### Más adelante, si se pide

- Recordatorios de cuota al cliente.
- Estado de cuenta del cliente con todas sus preventas.

---

## 8. Cómo se va a comprobar que funciona

**Dos certificaciones, en este orden (D25):**

1. **La nuestra, primero.** Recorremos en staging todo lo que sigue y dejamos un informe con lo
   probado, lo que salió y las evidencias. Solo con todo en verde se entrega.
2. **La del equipo del negocio**, sobre el mismo staging, con ese informe en la mano.

El recorrido, con el módulo encendido en un negocio de prueba:

1. Crear una campaña con descuento general del 10%, cuota inicial mínima del 30%, dos fechas de
   entrega, un periodo de venta y 4 productos: uno con el descuento de la campaña, uno con descuento
   propio, uno con precio propio y uno por peso. Los precios de preventa salen según el orden de
   §4.2.
2. Intentar registrar una preventa con cuota inicial menor al 30%: no deja.
3. Registrar una preventa con cuota inicial en efectivo y 3 cuotas más. El turno abierto muestra el
   abono en «Abonos de preventa» y el efectivo esperado en el cajón sube en ese monto.
4. Cerrar ese turno, abrir otro y abonar por transferencia. El abono aparece **en el segundo turno**,
   no en el primero.
5. Dejar vencer una cuota (fecha en el pasado): la preventa sale **Atrasada**.
6. Intentar entregar con saldo: no deja.
7. Pagar el saldo y entregar desde la caja. Sale una venta con los productos al precio pactado y el
   inventario baja ese día. **El cuadre del turno de la entrega no suma otra vez** la plata de los
   abonos. En la pestaña de entrega no se puede tocar nada salvo el peso real del producto por peso.
8. Entregar otra preventa cambiando el peso real: si pesa más, se cobra la diferencia; si pesa menos,
   se devuelve como vuelto. Las dos cosas cuentan en el turno de la entrega.
9. Cerrar el periodo de venta de la campaña: no deja registrar preventas nuevas, pero sí abonar a las
   existentes.
10. Cancelar otra preventa con devolución parcial en efectivo: el esperado del cajón baja en lo
    devuelto y el comprobante muestra lo abonado, lo devuelto y lo retenido.
11. Lo comprometido muestra los productos de las preventas abiertas por fecha de entrega, y deja de
    mostrarlos al entregar o cancelar.
12. Los comprobantes salen con las condiciones del negocio, en el papel configurado.
13. Repetir lo esencial en **un segundo negocio con otra configuración**: otro formato de números,
    sin mesas, con un producto por peso y papel de 58 mm.
14. Con el módulo apagado en otro negocio, la caja, el cuadre y los reportes se ven exactamente igual
    que hoy.
