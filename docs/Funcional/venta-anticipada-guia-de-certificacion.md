# Preventas — guía de certificación en staging, paso a paso

> **Para:** el equipo del negocio que certifica el módulo antes de producción.
> **Estado (2026-10-08):** nosotros ya certificamos (informe: `venta-anticipada-certificacion-staging.md`).
> Esta guía es para la certificación del equipo del negocio. Desde el 2026-10-08 incluye el **documento de
> identidad** de las personas, que sale junto con Preventas (pasos 2 y 12; `documento-de-identidad.md`).

**Dónde:** empezar por **Casaletto staging** — `https://casaletto.staging.ospos-saas.micronuba.net`. Es el
negocio que va a usar el módulo, tiene mesas, recetas armadas y productos por peso, y el módulo ya está
encendido. **Tiempo:** de 1 a 2 horas.

**Tener a mano:** un producto por unidad, un producto **por peso** (p. ej. «Pavo súper especial») y una
**receta armada** (p. ej. «Tabla Toscana 8 personas»).

Si algo no sale como dice el ✅: anotar el paso, el número de la preventa (PV-…), el turno y una captura.

---

## Paso 0 — Preparación (administrador)

1. Entrar con el **administrador** del negocio.
2. **Configuración → pestaña Preventas:** «Usar preventas» marcado; revisar prefijo y condiciones; «Enviar».
   ✅ «Configuración guardada.»
3. **Empleados:** dos usuarios de prueba:
   - **Encargado:** permisos «Preventas» y «Gestionar preventas», más Caja y Turnos.
   - **Cajero:** «Preventas» **sin** «Gestionar preventas», más Caja y Turnos.
4. **Turnos → Nuevo turno:** abrirlo con base en efectivo (p. ej. $50.000).

## Paso 1 — Crear la campaña (encargado)

1. **Preventas → «Campañas» → «Nueva campaña»:** nombre, periodo de venta (hoy al 15/12), descuento general
   (p. ej. 10 %), cuota inicial mínima (p. ej. 30 %). ✅ «Activa» viene marcada.
2. **«Detalle de la campaña»:**
   - **Productos:** agregar los tres; a uno un descuento propio, a otro un precio propio.
   - **Fechas de entrega:** 23/12 y 24/12.

✅ El buscador encuentra la receta armada. Precio: precio propio → descuento propio → descuento de campaña.

## Paso 2 — Registrar una preventa (cajero)

1. Entrar con el **cajero**. ✅ La lista muestra «Lo comprometido» y **no** «Campañas».
2. **«Nueva preventa»:** campaña; cliente con «Cliente Nuevo», con tildes (p. ej. «José Muñoz»), marcar
   consentimiento. **Tipo y número de documento son obligatorios** (p. ej. CC «1.020.345.678»).
   ✅ Sin documento no deja enviar; con él, el cliente queda elegido en la preventa.
3. Productos (el de peso con decimales, p. ej. 2,5; y la receta) y fecha de entrega.
4. **Error a propósito:** cuota inicial **menor** al mínimo que muestra la pantalla. ✅ Rechazada.
5. Corregir: cuota inicial ≥ mínimo hoy en efectivo, y una segunda cuota en diciembre (deben sumar el
   total). Registrar.

✅ Comprobante de preventa con número PV-…, el **documento del cliente** («CC 1020345678»), productos,
plan, saldo y condiciones.

## Paso 3 — La plata en el turno

**Turnos → editar el turno abierto → conciliación.**
✅ Renglón **«Abonos de preventa»**; el **esperado en el cajón** subió exactamente lo cobrado.

## Paso 4 — Abonar en otro turno

1. **Cerrar** el turno y **abrir** uno nuevo.
2. **Preventas → «Ver preventa» → Registrar abono:** una parte por **transferencia**, con referencia.

✅ Comprobante de abono. Aparece **solo en el turno nuevo**; el esperado en efectivo no cambia.

## Paso 5 — Entregar desde la caja (cajero)

1. Abonar lo que falta. ✅ Mientras haya saldo el detalle dice cuánto falta y **no** hay «Entregar».
2. Con saldo cero (**«Pagada»**) → **«Entregar»**.
   ✅ La caja abre la pestaña **«PV-…»**, con el aviso de entrega y precios pactados; sin buscador de
   artículos, sin Suspender ni Cancelar.
3. Cambiar **el peso** del producto por peso:
   - **Más peso:** «Monto de adeudo»; no deja completar hasta cobrarlo.
   - **Menos peso, poco** (menos del 15 %): se devuelve como vuelto en efectivo.
   - Los ingredientes de la receta **no** son editables.
4. **Completar.** ✅ Recibo normal: la receta sin sus ingredientes y la línea «Preventa».

## Paso 6 — Cierre del turno de la entrega

**Turnos → turno abierto → conciliación.**
✅ «Entregas de preventa (cobradas antes)» aparte, sin sumar a los ingresos; esperado = base + abonos en
efectivo ± diferencia por peso; el formulario de cierre prellenado con esos mismos valores.

## Paso 7 — Permisos y tope de peso

1. **Cajero:** otra preventa pagada; en la entrega un peso **mucho menor** (devolución mayor al 15 %).
   ✅ No deja completar; pide a quien tenga «Gestionar preventas».
2. **Encargado:** la misma entrega sí se completa.
3. **Cajero:** en el detalle de una preventa abierta **no** puede cancelar.

## Paso 8 — Cancelar (encargado)

Otra preventa con abono → **«Cancelar preventa»**: motivo y devolución **parcial** en efectivo.
✅ Comprobante con abonado, devuelto y retenido; el esperado del cajón **baja** lo devuelto.

## Paso 9 — Campaña cerrada (encargado)

1. **Campañas → editar:** periodo de venta ya terminado. ✅ «Nueva preventa» dice que ninguna campaña
   recibe preventas.
2. Abonar a una preventa abierta de esa campaña. ✅ Se acepta.

## Paso 10 — Lo comprometido

**Preventas → «Lo comprometido»** → campaña.
✅ Producto × fecha de entrega, inventario y lo que falta comprar; la receta cuenta por ingredientes;
descarga CSV.

## Paso 11 — Un negocio que no usa el módulo

En otro negocio de staging (p. ej. «pruebas»): una venta y un cierre de turno normales.
✅ Nada de Preventas; todo igual que antes.

## Paso 12 — Documento de identidad (administrador y cajero)

En **Clientes**, en **Empleados** y en la **caja**:

1. **Clientes → Nuevo cliente** sin tipo ni número. ✅ No deja enviar.
2. Un cliente con **CC «1.020.345.678»**. ✅ Se guarda; la lista muestra «CC 1020345678» en «Documento».
3. **Otro** cliente con el **mismo número** (con o sin puntos). ✅ Dice «Ya hay un cliente con este documento:
   …» con el nombre, y no deja guardar.
4. **Empleados →** un empleado con ese **mismo documento**. ✅ Avisa «Este documento también corresponde al
   cliente …» y **sí** guarda.
5. En la **caja** y en **Preventas**, buscar el cliente escribiendo el **número** (con y sin puntos).
   ✅ Aparece.
6. Hacer una venta a ese cliente y abrir el **recibo** y la **factura**; abrir el **comprobante** de una
   preventa suya. ✅ Sale «CC 1020345678»; ya no sale «Id Impuesto».
7. Opcional: un cliente con **NIT** «800197268». ✅ Se muestra «NIT 800197268-4». Con «800197268-5»
   ✅ dice que el dígito de verificación no corresponde.

