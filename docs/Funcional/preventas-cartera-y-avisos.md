# Alcance funcional — Preventas: cartera y avisos por correo al cliente

> **Estado (2026-10-27):** **la Cartera (§3), los avisos por correo (§4) y los recordatorios están
> construidos y certificados en el ambiente de pruebas** (resultado en §9). Todavía no están en
> producción. Es la segunda entrega del módulo
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
- **Los correos saldrán por Resend**, el servicio de envío que ya usa PLAGIE, con plan pago.
  PLAGIE envía desde `plagie.cloud`, que es el único dominio verificado allí. **Hay que agregar
  `micronuba.net` a Resend** para poder enviar como `ospos@micronuba.net`. Verificado por consulta de
  DNS el 2026-10-26.

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

**Cómo se lee, en detalle** (construido el 2026-10-26):

- El botón **«Cartera»** está en la lista de Preventas, junto a «Campañas». Solo lo ve quien tiene
  «Gestionar preventas» (S4); quien no lo tiene y escribe la dirección a mano es enviado a la pantalla
  de «sin acceso».
- **Vencido por antigüedad:** cada preventa atrasada cuenta en un solo tramo, según los días que lleva
  vencida su cuota más vieja sin pagar, y con todo lo que tiene vencido.
- **Próximos 7 días:** son hoy y los seis días siguientes. Cuenta lo que **falta** de cada cuota: si el
  cliente ya abonó una parte, solo suma lo que le queda. Un abono se aplica primero a la cuota más vieja.
- **Atrasados:** el número de cada preventa abre su detalle, donde se le puede registrar el abono.
- **El CSV trae todas las preventas vigentes del filtro**, no solo las atrasadas: cliente, teléfono,
  correo, campaña, fecha de entrega, total, pagado, saldo, vencido, días de atraso y lo que vence en los
  próximos 7 días. Sirve para llamar a los atrasados y para revisar las cifras en una hoja de cálculo.
- Una preventa **pagada y sin entregar** aparece con saldo 0. Las canceladas y las entregadas no
  aparecen.

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

- **Una sola dirección para toda la plataforma: `ospos@micronuba.net`**, enviada por Resend.
  Sirve para todos los negocios y ningún negocio tiene que configurar nada. No hace falta crear un
  buzón para esa dirección: solo envía.
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
| **Entrega** | Productos entregados, diferencia por peso cobrada o devuelta si la hubo | Comprobante de entrega en PDF (nuevo: también se imprime desde el detalle de la preventa) |
| **Recordatorio de cuota** | Que una cuota está por vencer o venció, cuánto, cuándo, y el saldo total | Sin adjunto (supuesto S2, §7) |

**Recordatorios** (supuestos S1 y S3, §7):

- Uno **2 días antes** de que venza cada cuota.
- Uno **al día siguiente** de una cuota vencida y no pagada.
- **Uno de cada tipo por cuota, nunca más.** El cliente no recibe un correo diario por una cuota
  atrasada.
- Salen **por la mañana, a las 9:00**. Solo a preventas con saldo: una preventa pagada, cancelada
  o entregada no recibe recordatorios.

### 4.3 Si el cliente no tiene correo

La preventa, el abono o la entrega se registran **igual**. Al registrar, abonar o cancelar, la página
del comprobante avisa en pantalla, sin que salga en el papel: «El cliente no tiene correo: no se le
envió el aviso». En la entrega el aviso no aparece en la caja, pero sí en la pestaña «Correos» de la
preventa. Si después se le agrega el correo en su ficha, el aviso se puede **reenviar** desde esa
pestaña.

### 4.4 Si el correo falla

- **Los correos salen solos, cada minuto**, desde un proceso propio del sistema que se instala con cada
  actualización. No hay que configurar nada en el servidor (desde el 2026-10-27).
- **El correo nunca frena la caja.** La preventa o el abono se guardan primero. El correo sale unos
  segundos después, por su cuenta. Si el servidor de correo está caído, el negocio sigue vendiendo.
- Si un correo no sale, el sistema **lo reintenta solo** durante un rato.
- En el detalle de cada preventa hay una sección **«Correos enviados»**: qué se mandó, a qué
  dirección, cuándo y si salió o falló. Cada uno tiene un botón **«Reenviar»**.

### 4.5 Cómo se enciende

- En **Configuración → Preventas**, una casilla nueva: **«Avisar al cliente por correo»**. Viene
  **apagada** en todos los negocios, como todo el módulo.
- Junto a la casilla: **cuántos días antes** se recuerda la cuota (2 por defecto; 0 = no recordar) y si
  se recuerda **la cuota vencida** al día siguiente (encendido por defecto).
- Al apagarla, no sale ningún correo, ni siquiera los que estaban en cola: esperan hasta que se vuelva
  a encender.
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
| **A6** | Se envía por **Resend** (plan pago, el mismo de PLAGIE), con `micronuba.net` verificado como dominio | 2026-10-26 |

---

## 7. Supuestos por confirmar

Se construye con estos valores salvo que el dueño diga otra cosa:

| # | Supuesto | Por qué |
|---|---|---|
| **S1** | Recordatorio **2 días antes** de cada cuota y **al día siguiente** si queda vencida; uno de cada tipo por cuota | Avisa a tiempo sin cansar al cliente. Los dos números quedan configurables por negocio |
| **S2** | El recordatorio **va sin PDF**: solo el detalle de la cuota y el saldo | El PDF ya le llegó con cada abono; un recordatorio con adjunto cae más fácil en spam |
| **S3** | Los recordatorios salen **a las 9:00** hora de Colombia | Antes de abrir, para que el cliente pase ese día |
| **S4** | La **cartera la ve quien tiene «Gestionar preventas»**, no todo el que vende | Muestra cifras del negocio y datos de contacto de todos los clientes |
| **S5** | ~~Servidor de correo del dominio~~ **Resuelto el 2026-10-26: se envía por Resend**, con `micronuba.net` agregado como dominio (A6) | — |
| **S6** | «Enviar cotización» **no se toca** en esta entrega | Ese botón usa la cuenta de cada negocio; pasarlo a la de la plataforma es otra decisión |

---

## 8. Alcance, en entregas

| Entrega | Qué trae | Depende de |
|---|---|---|
| **2a · Cartera** | La pantalla de §3, con su CSV | Nada. Puede salir primero |
| **2b · Avisos** | El envío de la plataforma, los cuatro avisos (registro, abono, cancelación y entrega) con PDF, «Correos enviados» y «Reenviar», la casilla en Configuración | `micronuba.net` verificado en Resend y la clave de envío en el servidor |
| **2c · Recordatorios** | Los recordatorios de cuota (§4.2) | 2b, y una tarea programada en el servidor |

---

## 9. Cómo se va a comprobar que funciona

**En staging, ningún correo llega a un cliente.** El ambiente de pruebas tiene copias de clientes
reales, así que sus correos no salen a internet: quedan en una **bandeja de pruebas (Mailpit)**,
protegida con contraseña, donde se leen tal como los recibiría el cliente. Solo producción envía de
verdad, por Resend. *(Configurado el 2026-10-26.)*

En staging, en dos negocios con configuración distinta, como la primera entrega:

1. **Cartera.** Con preventas al día, atrasadas, pagadas, canceladas y entregadas, las cuatro cifras
   cuadran con la suma hecha a mano. Una cuota que vence hoy no cuenta como vencida. Las canceladas y
   entregadas no suman.
2. **Registro con correo.** El correo aparece en la bandeja de pruebas con el nombre del negocio como
   remitente, su logo y el PDF, y con «responder a» el correo del negocio.
3. **Abono, cancelación y entrega:** llega cada correo con su PDF y sus cifras correctas.
4. **Cliente sin correo:** la preventa se registra, aparece el aviso y, tras agregar el correo,
   «Reenviar» lo envía.
5. **Servidor de correo caído** (contraseña errada a propósito): la caja registra igual, el correo
   queda «Fallido» y sale al corregir la contraseña.
6. **Recordatorios:** con una cuota que vence en 2 días y otra vencida ayer, sale un correo de cada
   tipo, y al día siguiente no se repite.
7. **Apagado:** con la casilla apagada no sale ningún correo.
8. **Que no llegue a spam:** esto solo se puede probar en producción, donde el correo sale de verdad:
   con `platform:mail-test` a una cuenta de Gmail y una de Outlook, revisar que llegue a la bandeja de
   entrada.

**Resultado en staging (2026-10-27, Casaletto staging, recorrido en el navegador).** Pasaron las
pruebas 1 a 7. Los correos se leyeron en la bandeja de pruebas:

| Prueba | Resultado |
|---|---|
| Cartera | Vendido $78.000, recaudado $45.000, por cobrar $33.000 y vencido $3.000: iguales a la suma a mano. La cuota del día no contó como vencida |
| Registro, abono, cancelación y entrega | Llegó cada correo con su PDF, el nombre del negocio como remitente y «responder a» el correo del negocio. Las cifras coinciden con la pantalla |
| Cliente sin correo | La preventa se registró y la página avisó. Al agregarle el correo, «Reenviar» lo envió a la dirección nueva |
| Servidor de correo caído | Con el servidor de pruebas apagado, el abono se registró igual. El correo esperó y salió solo en el reintento del minuto siguiente |
| Recordatorios | Salió uno «por vencer» (cuota a 2 días) y uno «vencida» (cuota de ayer). Ninguno se repitió en las corridas siguientes |
| Casilla apagada | El abono se registró y no se encoló ningún correo |

Hallazgo de la prueba: **las condiciones de la preventa se imprimían cortadas** en los comprobantes de
todos los negocios. Está corregido y explicado en `venta-anticipada.md` §4.14. Cada negocio tiene que
volver a guardar sus condiciones.

Observación: una preventa con una cuota a 2 días recibe el recordatorio el mismo día del registro, justo
después del correo de registro. Es lo que dice la regla («2 días antes»). Si molesta, se puede cambiar
para que no se recuerde una cuota pactada hace menos de 2 días.
