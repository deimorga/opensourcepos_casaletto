# Alcance funcional — Venta anticipada: se paga por cuotas, se entrega al final

> **Estado (2026-10-07):** **definición de alcance, sin construir.** Lo que hay hoy en el sistema
> está verificado contra el código (§2). Las decisiones del dueño del 2026-10-07 están en §6. Queda una
> sola interpretación por confirmar (§6.2). Nada de esto existe todavía en ningún negocio.
>
> Documento hermano: `docs/Tecnico/venta-anticipada.md`.

---

## 1. El problema, en una frase

Queremos **vender hoy y entregar después**: el cliente separa un producto, deja una cuota inicial,
se compromete a pagar el resto en fechas acordadas y **recibe el producto solo cuando terminó de
pagar**. Hoy el sistema no tiene cómo registrarlo.

**El caso que lo origina:** la temporada navideña de Casaletto. Se venden desde octubre los
productos de Navidad y se entregan en diciembre. Esto sirve para dos cosas:

1. **Tener flujo de caja antes de la temporada.**
2. **Saber con tiempo qué hay que comprar**, porque las preventas dicen cuántas unidades de cada
   producto ya están comprometidas y para qué fecha.

El módulo no es solo para Casaletto. Debe poder usarlo cualquier negocio de la plataforma que lo
encienda (§4.11).

---

## 2. Qué hay hoy, verificado

### 2.1 No hay nada documentado sobre esto

Se revisó toda la documentación propia (`docs/Funcional`, `docs/Tecnico`) y la copia de la wiki de
OSPOS. Se buscó preventa, anticipo, separado, plan de pagos, cuota, abono, encargo, fecha de
entrega, cuentas por cobrar y fiado. **Ningún documento propio trata el tema** y **ningún negocio
lo había pedido antes.**

La wiki de OSPOS dice expresamente que el sistema **no** hace apartados ni tiene cuentas por cobrar
(`referencia-ospos-wiki/Complete-feature-datasheet.md`). Lo más parecido que describe es la
**Orden de Trabajo** (`referencia-ospos-wiki/Work-Orders.md`), pensada para talleres de reparación.

### 2.2 Lo que trae el sistema, y por qué no sirve tal cual

| Pieza que existe | Qué hace | Por qué no resuelve la preventa |
|---|---|---|
| Medio de pago **«Adeudo»** | Cierra una venta sin cobrarla, siempre que tenga cliente | Es **fiado**, lo contrario de lo que queremos: el producto sale el primer día, el inventario baja ese día y la venta cuenta como ingreso ese día. No hay forma de abonar por partes. |
| **Venta suspendida** | Guarda los productos de una venta sin terminar, y puede llevar pagos | Al terminarla, el sistema **borra y vuelve a escribir los pagos con la fecha y el turno del día en que se termina**. Una cuota cobrada en octubre aparecería cobrada en diciembre, y el cuadre de caja de octubre quedaría descuadrado. |
| **Orden de trabajo** (apagada en todos los negocios) | Venta suspendida con pagos tipo «Depósito» | Mismo problema de los pagos. Además, el «Depósito en efectivo» **no cuenta como efectivo del cajón** al cerrar el turno. |
| **Cotización** | Precio propuesto a un cliente | No admite pagos, a propósito. |
| **Tarjeta de regalo** | Saldo prepagado | Cargarle plata no es una venta y nunca pasa por el turno. No está ligada a productos ni a una fecha de entrega. |

### 2.3 Lo que falta por completo

- Una **fecha de entrega** pactada con el cliente.
- Un **plan de cuotas**: qué día y cuánto se compromete a pagar.
- **Abonos** que queden registrados el día y en el turno en que entró la plata.
- Saber **cuánto ha pagado y cuánto debe** cada cliente, y **quién va atrasado**.
- **Impedir la entrega** mientras haya saldo.
- Una **lista de lo comprometido por producto y fecha de entrega**, para planear las compras.
- Registrar una **cancelación** con lo que se haya acordado con el cliente.

**Conclusión:** del sistema actual se aprovecha la ficha del cliente, el catálogo de productos, la
venta normal (con la que termina la preventa) y el cuadre de caja. **La preventa en sí hay que
construirla.**

### 2.4 Algo que hay que revisar antes de empezar

- **El Pavo navideño (código 157) está borrado** y sigue dentro de 4 recetas
  (`articulos-e-ingredientes.md`). Si se va a vender en preventa, hay que recuperarlo o crearlo
  bien antes de abrir la temporada.
- **Las tildes en los nombres de clientes.** El arreglo de tildes de 2026-08-22 dejó pendientes
  Clientes y Tarjetas de regalo. Antes de registrar cientos de clientes nuevos hay que comprobar
  que «José» se guarda como «José» y se encuentra al buscarlo (`docs/Tecnico/venta-anticipada.md`
  §8.7).

---

## 3. La solución: un módulo nuevo, «Preventas»

Una **preventa** es un acuerdo con un cliente registrado. Tiene seis partes:

- **los productos** que se lleva y su precio pactado;
- **la fecha de entrega**;
- **el plan de cuotas**, con fechas y montos acordados;
- **los abonos** que va haciendo;
- **el saldo**, que es lo que falta por pagar;
- **un estado**: al día, atrasada, pagada, entregada o cancelada.

**El producto no sale del inventario hasta la entrega**, y **la entrega solo se puede hacer con el
saldo en cero.** El día de la entrega, la preventa se vuelve una venta normal del punto de venta.

---

## 4. Cómo funciona

### 4.1 Nada de esto es obligatorio

El módulo viene **apagado** en todos los negocios. Un negocio que no lo enciende no ve ningún
cambio: ni menú, ni pantallas, ni nada distinto en la caja o en el cuadre. Lo enciende el
administrador del negocio (§4.11).

### 4.2 Registrar la preventa (el «contrato»)

Se hace uno a uno, con el cliente al frente o al teléfono:

1. **Cliente.** Se busca entre los registrados. Si no existe, se crea en el momento con la misma
   ficha de Clientes. **No hay preventas sin cliente**: es a quien se le cobra y a quien se le
   entrega.
2. **Productos.** Se agregan del catálogo, con cantidad. El precio que aparece es el de hoy y **ese
   queda pactado** (§4.6).
3. **Fecha de entrega.** La pone quien registra, según lo que se acordó con el cliente. Para
   facilitar que sean **fechas unificadas** por logística, el sistema sugiere las fechas de entrega
   que ya se están usando en otras preventas. También se puede escribir otra.
4. **Plan de cuotas.** Quien registra escribe las cuotas acordadas: fecha y monto de cada una. La
   primera es la **cuota inicial**. **La suma de las cuotas tiene que dar el total**; si no da, el
   sistema no deja guardar y dice cuánto falta o sobra. La última cuota no puede quedar después de
   la fecha de entrega.
5. **Cuota inicial.** Se cobra en ese mismo momento, como cualquier pago de la caja (efectivo,
   datáfono o transferencia).
6. **Comprobante.** Se imprime un comprobante de preventa para el cliente con los productos, el
   precio pactado, la fecha de entrega, el plan de cuotas, lo abonado y el saldo. Es el «contrato»
   en papel.

Cada preventa recibe un **número propio** (por ejemplo `PV-0001`), que es el que se le da al
cliente y el que se usa para buscarla.

### 4.3 Abonar

El cliente vuelve y paga una cuota, o lo que pueda:

1. Se busca la preventa por número, por nombre del cliente o por teléfono.
2. Se ve el resumen: total, abonado, saldo, próxima cuota y si va atrasada.
3. Se registra el abono: monto y medio de pago.
4. Se imprime el **comprobante de abono** con lo pagado hoy, el acumulado y el saldo.

Reglas:

- **El abono no tiene que coincidir con la cuota.** El cliente puede pagar menos, más o adelantar
  varias. Lo que importa es el acumulado frente al plan (§4.5).
- **No se puede abonar más del saldo.**
- **Medios de pago admitidos: efectivo, datáfono (débito o crédito) y transferencia.** No se admite
  «Adeudo», que contradice la idea de pagar antes, ni tarjeta de regalo ni puntos.
- **Se necesita un turno de caja abierto**, igual que para vender.

### 4.4 La plata entra al turno que la recibió

Esto es lo más importante para la operación. **Un abono cuenta en el cuadre del turno en que se
recibió**, como cualquier pago de la caja:

- Un abono en efectivo **suma a lo que debe haber en el cajón** de ese turno.
- Un abono por datáfono o transferencia suma a los ingresos de ese turno, en su medio de pago.
- En la pantalla de cierre del turno, los abonos aparecen **en un renglón propio, «Abonos de
  preventa»**, para que el cajero sepa de dónde salió esa plata.

Y el día de la entrega **esa plata no se vuelve a contar**: ya entró en su momento (§4.7).

### 4.5 Al día, atrasada, pagada

El sistema compara **lo abonado** con **lo que el plan decía que ya debía estar pagado a la fecha**:

| Estado | Cuándo |
|---|---|
| **Al día** | Lo abonado cubre todas las cuotas vencidas |
| **Atrasada** | Hay al menos una cuota vencida que lo abonado no alcanza a cubrir. **Solo se marca**: no hay recargo, ni intereses, ni pérdida de la reserva |
| **Pagada** | El saldo es cero. Lista para entregar |
| **Entregada** | Ya se entregó; es una venta |
| **Cancelada** | Se canceló (§4.9) |

Se muestra cuántos días lleva atrasada y cuánto falta para ponerse al día.

### 4.6 El precio queda pactado, y se puede ajustar

- El precio de cada producto **queda congelado el día de la preventa**. Si después sube en el
  catálogo, la preventa no cambia.
- **Se puede ajustar** si el negocio lo decide: una persona con permiso (§4.12) cambia el precio o
  las cantidades de una preventa abierta. **Tiene que escribir el motivo.** El sistema guarda quién
  lo hizo, cuándo, el valor anterior y el nuevo.
- Si el total cambia, **el plan de cuotas se tiene que volver a cuadrar** antes de guardar: las
  cuotas pendientes deben sumar el nuevo saldo.
- Una preventa pagada, entregada o cancelada **no se ajusta**.

### 4.7 Entregar

1. Se busca la preventa.
2. **Si tiene saldo, el botón de entregar no está habilitado** y el sistema dice cuánto falta. No
   hay excepción: es la regla del negocio.
3. Con saldo en cero se entrega. En ese momento:
   - la preventa se convierte en una **venta normal** del punto de venta, con su número de venta y
     los productos al precio pactado;
   - **el inventario baja ese día**, como en cualquier venta;
   - la venta queda **pagada con lo abonado**, y esos pagos **no se suman otra vez** a la caja del
     turno de la entrega;
   - se imprime el recibo normal de venta y la preventa queda **Entregada**.

**Fecha de entrega vencida:** si llega la fecha y el cliente no ha terminado de pagar, la preventa
sigue abierta y aparece señalada en la lista. Qué se hace con ella lo decide el negocio con el
cliente: esperar, mover la fecha o cancelar.

### 4.8 Cambiar la fecha de entrega o el plan

Con permiso (§4.12) y con motivo escrito se puede **mover la fecha de entrega** y **rehacer las
cuotas pendientes**, por ejemplo cuando el cliente pide más plazo. Las cuotas ya cubiertas no se
tocan. Queda registrado quién, cuándo y por qué.

### 4.9 Cancelar: lo que se acuerde con el cliente

La devolución del dinero **no tiene una regla fija**: se negocia con cada cliente. El sistema no la
decide; **registra lo que se acordó**:

1. Una persona con permiso (§4.12) cancela la preventa y escribe el motivo.
2. Indica **cuánto se le devuelve al cliente**: cualquier valor entre cero y lo abonado. Si es
   todo, la devolución es total; si es una parte o nada, el resto lo retiene el negocio.
3. Si hay devolución, se indica el medio. **Una devolución en efectivo sale del cajón del turno
   abierto** y aparece en su cierre, igual que un abono pero restando.
4. Se imprime un **comprobante de cancelación** con lo abonado, lo devuelto y lo retenido.

El producto nunca salió del inventario, así que no hay nada que devolver a bodega.

### 4.10 Listas y reportes

- **Lista de preventas**, con filtros por estado, fecha de entrega y cliente. Muestra total, abonado,
  saldo, próxima cuota y días de atraso. Sirve para llamar a quien va atrasado y para preparar las
  entregas de cada fecha.
- **Lo comprometido para comprar**: por producto, cuántas unidades están comprometidas en preventas
  abiertas, separadas por fecha de entrega, y cuántas hay hoy en inventario. **Es la lista de compras
  de la temporada.**
- **Cartera de preventas**: cuánto falta por cobrar en total, cuánto está vencido y cuánto entró en
  un rango de fechas.
- **Cuadre de caja**: el renglón «Abonos de preventa» en cada turno (§4.4).

### 4.11 Cómo se enciende para un negocio

Lo hace el **administrador del negocio**, no la plataforma:

1. En **Configuración**, pestaña **Preventas**, encender «Usar preventas». Ahí mismo se define el
   prefijo del número (por defecto `PV-`) y el texto que llevan los comprobantes, por ejemplo las
   condiciones del acuerdo.
2. En **Empleados**, dar el permiso **Preventas** a quien vaya a registrar, abonar y entregar.
3. Dar el permiso **«Gestionar preventas»** solo a quien pueda ajustar precios, mover fechas y
   cancelar.

Apagarlo **no borra nada**: las preventas quedan guardadas y reaparecen al encenderlo otra vez.
**Mientras haya preventas abiertas, no se debe apagar**, porque nadie podría abonar ni entregar. La
pantalla avisa cuántas hay abiertas antes de dejar apagarlo.

### 4.12 Permisos

| Permiso | Para quién | Qué permite |
|---|---|---|
| **Preventas** | El cajero | Ver, registrar preventas, abonar, entregar e imprimir comprobantes |
| **Gestionar preventas** | El encargado o el dueño | Además: ajustar precio o cantidades, mover la fecha de entrega, rehacer el plan de cuotas y cancelar con o sin devolución |

---

## 5. Lo que este requerimiento NO hace

- **No es fiado.** El producto no sale antes de estar pagado. El crédito con entrega inmediata
  («Adeudo») sigue como está, sin cambios.
- **No cobra recargos ni intereses por atraso.** Solo se marca (D4).
- **No envía recordatorios** por SMS, WhatsApp ni correo. Se puede pensar para después (§7).
- **No aparta inventario.** Lo comprometido se informa para planear las compras, pero el sistema
  no impide vender esas unidades en la caja mientras llega la entrega.
- **No cambia el tratamiento contable ni de impuestos.** La venta se registra como cualquier venta
  del POS (D7). La facturación electrónica sigue por fuera, como hoy.
- **No hay preventas por lotes ni importación masiva.** Una a una (D9).
- **No admite entregas parciales.** Una preventa se entrega completa en su fecha (D6).
- **No toca las órdenes de trabajo, las cotizaciones ni las ventas suspendidas.** Siguen igual.

---

## 6. Decisiones tomadas

| # | Decisión | Fecha |
|---|---|---|
| **D1** | Es un **módulo nuevo**, «Preventas», con su propio menú y permisos. No reutiliza ventas suspendidas ni órdenes de trabajo, porque reescriben los pagos con la fecha de la entrega (§2.2) | 2026-10-07 |
| **D2** | **Para todos los negocios, apagado por defecto**; lo enciende el administrador de cada negocio | 2026-10-07 |
| **D3** | **Solo clientes registrados.** La preventa exige cliente | 2026-10-07 |
| **D4** | **Atraso: solo se marca**, sin penalización, porque el producto no se ha entregado | 2026-10-07 |
| **D5** | **Fechas de cuotas libres**: las acuerda el negocio con el cliente y se escriben al registrar la preventa | 2026-10-07 |
| **D6** | **Fecha de entrega libre por preventa**, buscando fechas unificadas: el sistema sugiere las que ya se usan. Una sola entrega por preventa | 2026-10-07 |
| **D7** | **Se trata como una venta más del POS**: sin contabilidad ni impuestos aparte | 2026-10-07 |
| **D8** | **Precio congelado** al registrar, **ajustable** con permiso y motivo escrito | 2026-10-07 |
| **D9** | Pueden ser **cientos de preventas**, pero se registran **una a una** | 2026-10-07 |
| **D10** | **La cancelación queda abierta**: se registra lo que se negocie con el cliente, devolución de 0 al 100% de lo abonado | 2026-10-07 |
| **D11** | **No se entrega con saldo pendiente**, sin excepción | 2026-10-07 |
| **D12** | **Cada abono cuenta en el turno que lo recibió**, y no se vuelve a contar al entregar | 2026-10-07 |

### 6.1 Preguntas resueltas el 2026-10-07

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

### 6.2 Una interpretación por confirmar

La respuesta 6 («es una venta, solo que anticipada») se aplicó así:

- **La plata se cuenta el día que entra**, en su turno y en su medio de pago, como cualquier cobro
  del POS (D12).
- **La venta, con sus productos, se registra el día de la entrega**, que es cuando sale el
  inventario. En los reportes de ventas por producto, por categoría y por empleado, y en el modo
  «devengo» del reporte Ingresos vs Gastos, aparece ese día. El modo «caja» del mismo reporte
  muestra los abonos en las fechas en que entraron.

**La alternativa**, registrar la venta el día del contrato, haría que los reportes de octubre
mostraran productos que no salieron hasta diciembre y que el inventario no cuadrara. Si el dueño
prefiere esa alternativa, hay que decirlo antes de construir.

---

## 7. Alcance, en entregas

La temporada manda: **para vender preventas navideñas desde noviembre, la Entrega 1 tiene que estar
en producción a finales de octubre.** Por eso la Entrega 1 contiene solo lo indispensable para
vender, cobrar y entregar sin descuadrar la caja.

### Entrega 1 — Vender, abonar y entregar — **por construir**

- Interruptor en Configuración y los dos permisos (§4.11, §4.12).
- Registrar la preventa con cliente, productos, precio pactado, fecha de entrega y plan de cuotas;
  cobrar la cuota inicial (§4.2).
- Abonar, con comprobante (§4.3).
- Los abonos entran al cuadre del turno que los recibió, en su renglón propio (§4.4).
- Estados al día, atrasada y pagada (§4.5).
- Entregar solo con saldo cero, convirtiéndola en venta sin contar dos veces la plata (§4.7).
- Lista de preventas con filtros (§4.10).
- Reporte de lo comprometido por producto y fecha de entrega (§4.10).

### Entrega 2 — Gestionar — **por construir**

- Ajuste de precio o cantidades con motivo (§4.6).
- Mover la fecha de entrega y rehacer el plan (§4.8).
- Cancelación con devolución de 0 a 100% y su comprobante (§4.9).
- Cartera de preventas (§4.10).

**Mientras la Entrega 2 no esté**, un ajuste o una cancelación no tienen cómo registrarse en el
sistema. Si la temporada obliga a abrir solo con la Entrega 1, el negocio debe saberlo, y la
Entrega 2 debe llegar antes de que aparezca la primera cancelación.

### Después, si se pide

- Recordatorios de cuota al cliente.
- Estado de cuenta del cliente con todas sus preventas.

---

## 8. Cómo se va a comprobar que funciona

En staging, con el módulo encendido en un negocio de prueba:

1. Registrar una preventa de 3 productos con cuota inicial en efectivo y 3 cuotas más. El turno
   abierto muestra el abono en «Abonos de preventa» y el efectivo esperado en el cajón sube en ese
   monto.
2. Cerrar ese turno, abrir otro y abonar por transferencia. El abono aparece **en el segundo turno**,
   no en el primero.
3. Dejar vencer una cuota (fecha en el pasado): la preventa sale **Atrasada**.
4. Intentar entregar con saldo: no deja.
5. Pagar el saldo y entregar: sale una venta con los 3 productos al precio pactado, el inventario baja
   ese día, y **el cuadre del turno de la entrega no suma otra vez** la plata de los abonos.
6. El reporte de comprometidos muestra los productos de las preventas abiertas, y deja de mostrarlos
   al entregar o cancelar.
7. Con el módulo apagado en otro negocio, la caja, el cuadre y los reportes se ven exactamente igual
   que hoy.

Las pruebas de la Entrega 2 (ajuste, cambio de fecha, cancelación con devolución parcial en
efectivo) siguen el mismo patrón.
