# Alcance funcional — Gestión de la plataforma y de los negocios-cliente

> **Estado a 2026-08-31: requerimiento planteado, sin construir nada.**
>
> Nace de una pregunta del dueño de la plataforma al aprovisionar el segundo negocio real:
> *"¿cuál es el usuario del negocio que creamos, cuál es su contraseña, quién la crea?"*. Al
> buscar las respuestas quedó claro que el panel de administración cubre el ciclo de vida del
> **negocio**, pero casi nada del de las **personas** que lo administran.
>
> **Se trabaja en paralelo, en otra conversación.** No mezclar con la implementación del cliente
> supermercado, que sigue por su cuenta.
>
> Diseño técnico en `docs/Tecnico/gestion-de-plataforma-y-negocios.md`.

---

## 1. Para quién es esto

Tres papeles distintos, y hoy solo el primero está resuelto:

| Papel | Qué necesita | Hoy |
|---|---|---|
| **Superadministrador de la plataforma** (nosotros) | Dar de alta negocios, suspenderlos, cobrar, dar soporte | Puede crear/suspender/eliminar negocios, **nada más** |
| **Dueño de un negocio** | Entrar a su negocio, y a ninguno más | El mecanismo existe a medias y **no funciona** |
| **Administrador dentro del negocio** | Operar su punto de venta | Funciona, es OSPOS de siempre |

---

## 2. Lo que hay hoy, verificado

No es una impresión: se revisó el código y el estado real de producción.

**Sí existe** un panel en `/platform/admin`, protegido, que lista negocios y permite **crear,
suspender, reactivar y eliminar** (con confirmación, y con la opción aparte de borrar además la base
de datos). Crear un negocio deja el esquema montado, migrado, con su propio usuario de base y su
configuración.

**Al crear un negocio, el sistema inventa la contraseña:**

- El usuario **siempre se llama `admin`**. No se puede elegir.
- La contraseña es **aleatoria**, de 16 caracteres. Nadie la escoge.
- **Se muestra una sola vez**, en el mensaje de confirmación. Si se cierra esa pantalla, se perdió y
  no hay forma de recuperarla desde ninguna pantalla.

Ese reemplazo no es un detalle: el molde con el que nace cada negocio trae **el usuario y la
contraseña reales de Casaletto**. Si el sistema no los cambiara, cada cliente nuevo nacería pudiendo
entrar con las credenciales de otro cliente. Está resuelto, y conviene que quede escrito por qué.

---

## 3. Lo que falta, y por qué duele

### 3.1 No se puede gestionar quién es superadministrador

| Necesidad | Hoy |
|---|---|
| Ver quiénes son superadministradores | No hay pantalla |
| Crear otro | **Solo por línea de comandos en el servidor** |
| Editar o eliminar uno | No existe |
| Que cambie su propia contraseña | No existe pantalla |

**El riesgo es concreto y está vivo.** Hay dos cuentas con poder total sobre todos los negocios. Una
de ellas es la que se creó al montar la plataforma y **nadie sabe su contraseña**. No se puede
eliminar sin entrar a la base de datos. Es una llave suelta, y va a seguir suelta hasta que exista
esa pantalla.

### 3.2 Si se pierde la contraseña de un negocio, no hay salida por pantalla

Se muestra una vez y nunca más. Si el cliente la pierde —y va a pasar— hoy toca entrar a la base de
datos a mano. Eso no es algo que se pueda pedir en una llamada de soporte.

### 3.3 El dueño de un negocio no puede entrar

Existe la idea de que un dueño entre y elija su negocio, pero **la tabla que los vincula está
vacía** y **no hay pantalla para llenarla**. En la práctica ese camino no funciona para nadie.

### 3.4 El listado no se puede leer

Muestra tres datos: la dirección corta, el nombre técnico de la base y el estado. **No muestra el
nombre del negocio** —de hecho ni se guarda— ni cuándo se creó, ni quién lo administra. Con dos
negocios se tolera; con diez es inservible.

### 3.5 Los negocios nuevos se llaman "John Doe"

El sistema cambia el usuario y la contraseña, pero **no el nombre de la persona**. Un cliente nuevo
entra y ve un nombre en inglés que no tiene nada que ver con él. Es pequeño y da muy mala impresión.

---

## 4. Decisiones que hay que tomar antes de construir

No son detalles de implementación: cambian lo que se construye.

**4.1 ¿Quién elige la contraseña del negocio nuevo?**
Hoy la inventa el sistema y la muestra una vez. Tres caminos:
- *(a)* Seguir igual, pero pudiendo regenerarla desde el panel.
- *(b)* Que el superadministrador la escriba al crear el negocio.
- *(c)* Que el sistema genere una temporal y **obligue a cambiarla en el primer ingreso**.

**Recomendación: (c) combinada con (a).** Es la única en la que, pasado el primer día, la contraseña
del cliente **no la conoce nadie más** — ni nosotros. Y regenerar sigue disponible para soporte.

**4.2 ¿Guardamos el nombre del negocio en el registro?**
Hoy solo se usa para escribirlo dentro del negocio y se descarta. Guardarlo es barato y hace legible
el listado. **Recomendación: sí.**

**4.3 ¿Debe existir "entrar como" para dar soporte?**
Se descartó al diseñar la plataforma. Con clientes reales pagando, vale repensarlo: es lo que
convierte un problema de una hora en uno de cinco minutos. Pero es **la función más peligrosa del
sistema** —da acceso a los datos de un cliente sin su contraseña— y no debería existir sin registro
de auditoría de quién entró, cuándo y a qué. **Recomendación: decidirlo aparte, no meterlo en este
primer paquete.**

**4.4 ¿Un superadministrador puede eliminarse a sí mismo?**
Hay que decidir la regla que impide quedarse sin ningún administrador. **Recomendación: no permitir
borrar el último, y no permitir que alguien se borre a sí mismo.**

**4.5 ¿Qué pasa con la cuenta huérfana que ya existe?**
Hay que decidir si se elimina o se recupera. **Recomendación: eliminarla en cuanto exista la
pantalla**, y que el primer uso del módulo sea justamente ese.

---

## 5. Alcance propuesto, en tres entregas

Ordenadas por lo que quita riesgo primero.

### Entrega 1 — Cerrar la llave suelta
- Pantalla de **superadministradores**: ver, crear, eliminar.
- **Cambiar la propia contraseña.**
- Reglas de seguridad de 4.4.

*Con esto se puede eliminar la cuenta huérfana, que es el riesgo abierto de hoy.*

### Entrega 2 — Soporte sin entrar a la base de datos
- **Restablecer la contraseña del administrador de un negocio** desde el panel, mostrándola una vez.
- **Guardar y mostrar el nombre del negocio**, más la fecha de creación.
- **Corregir el "John Doe"** al crear.

### Entrega 3 — El dueño entra a lo suyo
- Pantalla para **vincular una persona con sus negocios**.
- Que el flujo de "elija su negocio" funcione de verdad.
- Obligar el cambio de contraseña en el primer ingreso, si se aprueba 4.1(c).

---

## 6. Lo que este trabajo NO incluye

- **"Entrar como" un negocio.** Decisión 4.3, va aparte.
- **Cobros, facturación o planes.** No existe nada de eso y no se aborda aquí.
- **Autoservicio de registro.** Los negocios los damos de alta nosotros; no hay un formulario
  público y no debe haberlo.
- **Cambiar cómo se aísla un negocio de otro.** Eso ya funciona y no se toca.

---

## 7. Cómo sabremos que quedó bien

- Se puede crear un segundo superadministrador **sin abrir una terminal**.
- Se puede eliminar la cuenta huérfana, y el sistema **impide** quedarse sin ninguna.
- Un cliente que perdió su contraseña se resuelve **desde el panel, en una llamada**.
- El listado, con diez negocios, se lee de un vistazo.
- Un negocio recién creado **no dice "John Doe"** en ninguna parte.
- Casaletto y Paraíso de la Canasta siguen comportándose exactamente igual.
