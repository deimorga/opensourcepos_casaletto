# Alcance funcional — Preventas: cartera y avisos por correo al cliente

> **Estado (2026-10-26):** **alcance escrito, sin construir.** Es la segunda entrega del módulo
> Preventas, que está en producción desde el 2026-10-14 y que Casaletto ya usa para vender su
> temporada navideña. Decisiones del dueño en §6; lo que falta confirmar, en §7.
>
> Documento hermano: `docs/Tecnico/preventas-cartera-y-avisos.md`. El módulo completo se describe
> en `venta-anticipada.md`.

---

## 1. El problema, en una frase

Con preventas reales en curso, el negocio necesita saber **cuánta plata le falta por cobrar y quién
está atrasado**, y el cliente necesita **un comprobante en su correo** cada vez que paga, sin tener
que guardar el papel de la caja.

---

## 2. Qué hay hoy, verificado

- **Cada preventa ya calcula lo suyo:** lo pagado, el saldo, si está al día, atrasada o pagada, los
  días de atraso y la próxima cuota. La lista de preventas se puede filtrar por estado («Atrasada»).
- **Falta la mirada de conjunto:** no hay ningún total. Para saber cuánto se debe en toda la
  campaña hay que sumar a mano, preventa por preventa.
- **El sistema no envía correos en producción.** No hay ninguna cuenta de correo configurada. Es lo
  mismo que dejó pendiente el botón «Enviar cotización» el 2026-09-24
  (`cotizaciones.md` §5).
- **El correo del cliente es opcional** en su ficha. Algunos clientes con preventas ya registradas
  pueden no tenerlo.
- **El dominio micronuba.net ya está preparado para enviar correo**: tiene su propio servidor
  (`mail.micronuba.net`) y la autenticación que exigen Gmail y Outlook para no mandar los correos a
  spam (SPF, DKIM y DMARC). Verificado por consulta de DNS el 2026-10-26.

---

## 3. Cartera de preventas

Una pantalla nueva dentro de Preventas, **«Cartera»**, junto a «Lo comprometido». Se puede ver por
campaña o de todas las campañas abiertas, y por fecha de entrega.

### 3.1 Qué muestra

**Arriba, cuatro cifras:**

| Cifra | Qué es |
|---|---|
| **Vendido** | La suma de las preventas vigentes: ni canceladas ni entregadas. |
| **Recaudado** | Lo que esos clientes ya pagaron. |
| **Por cobrar** | Vendido menos recaudado. |
| **Vencido** | Lo que según el plan de cuotas ya debió entrar y no ha entrado. Una cuota que vence hoy todavía no cuenta como vencida. |

**Debajo:**

- **Vencido por antigüedad:** de 1 a 7 días, de 8 a 30 días y más de 30 días, con el valor y el
  número de preventas en cada tramo.
- **Lo que vence en los próximos 7 días:** cuánta plata debería entrar esta semana y de quién.
- **Lista de atrasados:** número de preventa, cliente, **teléfono y correo**, campaña, fecha de
  entrega, valor vencido y días de atraso, del más atrasado al menos. Cada fila lleva a la preventa
  para abonar desde ahí.
- **Descargar CSV**, igual que «Lo comprometido».

### 3.2 Para qué sirve

- Llamar a los atrasados antes de la entrega, con el teléfono a la vista.
- Saber cuánta plata va a entrar antes de la temporada y planear las compras con eso.
- Saber, cerca de la fecha de entrega, cuántas preventas llegan sin pagar completas.

### 3.3 Lo que no cambia

La cartera **solo muestra**: no cobra, no cambia estados y no agrega recargos. El atraso se sigue
marcando sin penalidad, como en la primera entrega (D4 de `venta-anticipada.md`).

---

## 4. Avisos por correo al cliente

### 4.1 Desde dónde se envían

- **Una sola cuenta para toda la plataforma: `ospos@micronuba.net`.** El dueño la crea en el
  servidor de correo del dominio. Sirve para todos los negocios y nadie tiene que configurar nada.
- **El correo habla del negocio, no de la plataforma.** El cliente ve como remitente el nombre del
  negocio, por ejemplo «Casaletto», y el contenido lleva el logo, el nombre, el NIT, la dirección y
  el teléfono del negocio.
- **Si el cliente responde, la respuesta le llega al negocio**, al correo que el negocio tenga en
  Configuración. No llega a la plataforma.

### 4.2 Cuándo se le escribe al cliente

| Momento | Qué dice el correo | Adjunto |
|---|---|---|
| **Registro de la preventa** | Número, productos y cantidades, precio pactado, total, cuota inicial pagada, saldo, plan de cuotas con fechas, fecha de entrega y condiciones | Comprobante de la preventa en PDF |
| **Cada abono** | Valor abonado, medio de pago, fecha, total pagado hasta hoy, saldo y próxima cuota | Comprobante del abono en PDF |
| **Cancelación** | Motivo, lo pagado, lo devuelto y por qué medio | Comprobante de cancelación en PDF |
| **Entrega** | Productos entregados, diferencia por peso cobrada o devuelta si la hubo | Recibo de la venta en PDF |
| **Recordatorio de cuota** | Que una cuota está por vencer o venció, cuánto, cuándo, y el saldo total | Sin adjunto (supuesto S2, §7) |

**Recordatorios** (supuestos S1 y S3, §7):

- Uno **2 días antes** de que venza cada cuota.
- Uno **al día siguiente** de una cuota vencida y no pagada.
- **Uno de cada tipo por cuota, nunca más.** El cliente no recibe un correo diario por una cuota
  atrasada.
- Salen **por la mañana, a las 9:00**. Solo a preventas con saldo: una preventa pagada, cancelada
  o entregada no recibe recordatorios.

### 4.3 Si el cliente no tiene correo

La preventa, el abono o la entrega se registran **igual**. La pantalla avisa: «El cliente no tiene
correo: no se le envió el aviso». Si después se le agrega el correo en su ficha, el aviso se puede
**reenviar** desde la preventa.

### 4.4 Si el correo falla

- **El correo nunca frena la caja.** La preventa o el abono se guardan primero. El correo sale unos
  segundos después, por su cuenta. Si el servidor de correo está caído, el negocio sigue vendiendo.
- Si un correo no sale, el sistema **lo reintenta solo** durante un rato.
- En el detalle de cada preventa hay una sección **«Correos enviados»**: qué se mandó, a qué
  dirección, cuándo y si salió o falló. Cada uno tiene un botón **«Reenviar»**.

### 4.5 Cómo se enciende

- En **Configuración → Preventas**, una casilla nueva: **«Avisar al cliente por correo»**. Viene
  **apagada** en todos los negocios, como todo el módulo.
- Al encenderla, **no se envían correos por lo que ya pasó**. Los avisos empiezan con el primer
  registro, abono, cancelación o entrega posterior. Los recordatorios empiezan a contar desde ese día.
- En Casaletto, que ya vende, conviene **completar el correo de los clientes con preventa** antes
  de encenderla. La cartera muestra el correo de cada atrasado, así que se ve rápido a quién le falta.

### 4.6 Datos personales

Estos correos informan al cliente sobre un contrato que él mismo firmó: lo que pagó, lo que debe y
cuándo recibe su producto. **No son publicidad.** El sistema no los usa para enviar ofertas ni los
comparte. Si en el futuro se quiere enviar publicidad, hará falta la autorización expresa del
cliente (Ley 1581 de 2012). Esa autorización está fuera de este alcance.

---

## 5. Lo que este requerimiento NO hace

- **No envía WhatsApp ni SMS.** Solo correo.
- **No envía publicidad** ni correos masivos.
- **No recibe las respuestas del cliente en el sistema.** Las respuestas llegan al correo del negocio.
- **No cobra recargos por atraso.** La cartera solo informa.
- **No cambia el botón «Enviar cotización»**, que sigue sin funcionar. Con la cuenta de la plataforma
  funcionando, arreglarlo es una decisión aparte (§7, S6).
- **No ajusta precios, cantidades ni fechas** de una preventa registrada. Eso sigue pendiente en la
  entrega «Gestionar» de `venta-anticipada.md` §7.

---

## 6. Decisiones tomadas

| # | Decisión | Fecha |
|---|---|---|
| **A1** | Se construye la **cartera** y los **avisos por correo**, en ese orden de prioridad | 2026-10-26 |
| **A2** | Los correos salen de **una cuenta de la plataforma, `ospos@micronuba.net`**, con el contenido y el remitente visible del negocio | 2026-10-26 |
| **A3** | Avisos al **registrar, abonar, cancelar y entregar**, más un **recordatorio de cuota** | 2026-10-26 |
| **A4** | Un cliente sin correo **no impide registrar**; la pantalla avisa y se puede reenviar después | 2026-10-26 |
| **A5** | El correo lleva **el detalle en el cuerpo y el comprobante en PDF adjunto** | 2026-10-26 |

---

## 7. Supuestos por confirmar

Se construye con estos valores salvo que el dueño diga otra cosa:

| # | Supuesto | Por qué |
|---|---|---|
| **S1** | Recordatorio **2 días antes** de cada cuota y **al día siguiente** si queda vencida; uno de cada tipo por cuota | Avisa a tiempo sin cansar al cliente. Los dos números quedan configurables por negocio |
| **S2** | El recordatorio **va sin PDF**: solo el detalle de la cuota y el saldo | El PDF ya le llegó con cada abono; un recordatorio con adjunto cae más fácil en spam |
| **S3** | Los recordatorios salen **a las 9:00** hora de Colombia | Antes de abrir, para que el cliente pase ese día |
| **S4** | La **cartera la ve quien tiene «Gestionar preventas»**, no todo el que vende | Muestra cifras del negocio y datos de contacto de todos los clientes |
| **S5** | La cuenta `ospos@micronuba.net` vive en el **servidor de correo actual del dominio** (`mail.micronuba.net`, alojado en Hivelocity). Hay que confirmar que **no se va con la salida de Neolo**: el portal se mudó desde ahí el 2026-10-23. Si se fuera, la alternativa es Resend, que PLAGIE ya usa, enviando igual como `ospos@micronuba.net` | Si el servidor de correo se apaga, se apagan todos los avisos |
| **S6** | «Enviar cotización» **no se toca** en esta entrega | Ese botón usa la cuenta de cada negocio; pasarlo a la de la plataforma es otra decisión |

---

## 8. Alcance, en entregas

| Entrega | Qué trae | Depende de |
|---|---|---|
| **2a · Cartera** | La pantalla de §3, con su CSV | Nada. Puede salir primero |
| **2b · Avisos** | La cuenta de la plataforma, los cuatro avisos (registro, abono, cancelación y entrega) con PDF, «Correos enviados» y «Reenviar», la casilla en Configuración | La cuenta `ospos@micronuba.net` creada y su contraseña en el servidor |
| **2c · Recordatorios** | Los recordatorios de cuota (§4.2) | 2b, y una tarea programada en el servidor |

---

## 9. Cómo se va a comprobar que funciona

En staging, en dos negocios con configuración distinta, como la primera entrega:

1. **Cartera.** Con preventas al día, atrasadas, pagadas, canceladas y entregadas, las cuatro cifras
   cuadran con la suma hecha a mano. Una cuota que vence hoy no cuenta como vencida. Las canceladas y
   entregadas no suman.
2. **Registro con correo.** El cliente recibe el correo con el nombre del negocio como remitente, su
   logo y el PDF. Responder ese correo llega al correo del negocio.
3. **Abono, cancelación y entrega:** llega cada correo con su PDF y sus cifras correctas.
4. **Cliente sin correo:** la preventa se registra, aparece el aviso y, tras agregar el correo,
   «Reenviar» lo envía.
5. **Servidor de correo caído** (contraseña errada a propósito): la caja registra igual, el correo
   queda «Fallido» y sale al corregir la contraseña.
6. **Recordatorios:** con una cuota que vence en 2 días y otra vencida ayer, sale un correo de cada
   tipo, y al día siguiente no se repite.
7. **Apagado:** con la casilla apagada no sale ningún correo.
8. **Que no llegue a spam:** recibirlo en una cuenta de Gmail y una de Outlook, y revisar que llegue
   a la bandeja de entrada.
