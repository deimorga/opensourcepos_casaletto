# Alcance funcional — Gestión de la plataforma y de los negocios-cliente

> **Estado a 2026-09-01: Entrega 1 EN PRODUCCIÓN. Entrega 2 certificada en staging, pendiente de
> desplegar.** Las entregas 3, 4 y 5 siguen sin construir. Alcance cerrado con trece decisiones.
>
> Nace de una pregunta al aprovisionar el segundo negocio real: *"¿cuál es el usuario del negocio que
> creamos, cuál es su contraseña, quién la crea?"*. Buscando la respuesta quedó claro que el panel
> cubre el ciclo de vida del **negocio** y casi nada del de las **personas** que lo administran — y,
> al mirar más a fondo, que tampoco deja el negocio en condiciones de vender.
>
> Diseño técnico en `docs/Tecnico/gestion-de-plataforma-y-negocios.md`.

---

## 1. Para quién es esto

| Papel | Dónde vive su identidad | Qué puede hacer |
|---|---|---|
| **Superadministrador** (nosotros) | `platform_control.platform_accounts` | Todo el ciclo de vida de cualquier negocio, y entrar a gestionarlo |
| **Dueño de un negocio** (el cliente) | `platform_accounts` + su `employees` | Entrar a su negocio, y a ninguno más |
| **Empleado del negocio** | `employees` de su tenant | Operar el punto de venta. Es OSPOS de siempre y no se toca |

**Los superadministradores son usuarios nuestros.** El cliente **no los ve en su lista de empleados y
no los puede editar ni eliminar**. Su credencial no vive en la base del cliente.

---

## 2. La regla que ordena todo: dónde entra cada quién

**Una sola credencial, dos resultados, según la puerta.**

| Dirección | Qué obtienes |
|---|---|
| `ospos-saas.micronuba.net` | La **consola de plataforma**: negocios, su configuración, superadministradores |
| `<negocio>.ospos-saas.micronuba.net` | **El punto de venta de ese negocio**, con todos los privilegios |

Un superadministrador entra en las dos con el mismo usuario y la misma contraseña. La cambia una vez
y le sirve en todos los negocios, incluidos los que se creen mañana.

**Estado real, verificado contra el servidor el 2026-08-31:**

| Pieza | Hoy | Qué falta |
|---|---|---|
| DNS de la raíz | Ya apunta al servidor | Nada |
| Certificado | El SAN ya incluye la raíz además del comodín | Nada |
| La raíz responde | **404** — el proxy no tiene regla para ese host | Una regla de enrutado |
| El panel | Vive en `pos-casaletto.micronuba.net/platform/login`, la dirección de un cliente | Mudarlo a la raíz y **redirigir** la vieja |
| El panel en subdominios | `paraisodelacanasta…/platform/login` responde **200** | Que deje de existir ahí |

---

## 3. Lo que hay hoy, verificado

**Sí existe** un panel protegido que lista negocios y permite crear, suspender, reactivar y eliminar
(con confirmación, y con la opción aparte de borrar la base de datos). Crear un negocio deja el
esquema montado, migrado y con su propio usuario de base.

**Al crear un negocio el sistema inventa la contraseña:** el usuario siempre se llama `admin`, la
contraseña es aleatoria de 16 caracteres, y **se muestra una sola vez**.

Ese reemplazo no es un detalle: el molde con el que nace cada negocio trae **el usuario y la
contraseña reales de Casaletto**. Sin ese paso, cada cliente nuevo nacería pudiendo entrar con las
credenciales de otro cliente. Está resuelto, y conviene que quede escrito por qué.

---

## 4. Lo que falta, y por qué duele

### 4.1 Hay una llave suelta: la cuenta huérfana

`platform_accounts` tiene dos filas, **ambas con poder total sobre todos los negocios**:

- `deimorga@gmail.com` — la del dueño de la plataforma.
- `admin@ospos-saas.micronuba.net` — creada al montar la plataforma con
  `php spark platform:create-account … --admin`. **Nadie anotó su contraseña.**

Se llama huérfana porque no pertenece a nadie: nadie la usa y nadie puede usarla, pero existe y puede
crear, suspender y **eliminar cualquier negocio junto con su base de datos**. No se puede borrar desde
ninguna pantalla ni rotar, porque no se conoce su clave. La única salida hoy es entrar a la base.

> **Mientras el login de cada negocio no acepte credenciales de plataforma, esa cuenta solo administra
> negocios. En cuanto lo acepte, podrá entrar a todos.** Por eso eliminarla deja de ser higiene y pasa
> a ser **condición previa** de la entrada por URL.

### 4.2 Un negocio nuevo nace con la configuración de otro país

El aprovisionador escribe **una sola** clave de configuración: el nombre de la empresa. Todo lo demás
queda como lo siembra el molde, pensado para Estados Unidos.

| Clave | Semilla | Paraíso hoy | Qué implica |
|---|---|---|---|
| `quantity_decimals` | `0` | `3` | Sin el 3, **el peso se pierde en silencio**. Corregido a mano |
| `barcode_content` | `id` | `item_number` | Sin `item_number`, **un código tecleado vende otro producto**. Corregido a mano |
| `number_locale` | `en_US` | `es_CO` | Corregido a mano |
| `currency_decimals` | `2` | `0` | El peso colombiano no tiene centavos. Corregido a mano |
| `language_code` | `en` | `es-MX` *(era `es-ES`)* | **Se escapó al aprovisionar.** Corregido el 2026-08-31 |
| `country_codes` | `us` | `us` | Sin corregir en los **dos** negocios |
| `tax_included` | `0` | `0` | Igual en los dos. Queda por decidir, no por asumir |
| `timezone` | `America/Bogota` | `America/Bogota` | Correcto |

**Paraíso no está roto** —se comprobó en producción, alguien ya corrigió casi todo a mano—. Y eso es
justamente la prueba del problema: dejar un negocio funcionando depende de que una persona recuerde
diez ajustes, uno por uno, sin lista. **Y uno se escapó:** `language_code` quedó en `es-ES` mientras
Casaletto corría `es-MX`, así que las traducciones escritas para un negocio no se veían en el otro.
Se corrigió el 2026-08-31 —negocio y empleados—, pero nadie lo habría notado hasta que una pantalla
saliera en inglés.

Aprovisionar deja de ser "crear el esquema" y pasa a ser **"dejar el negocio en condiciones de
vender"**.

> **Estado a 2026-09-01: corregido, sin desplegar y sin certificar.** El alta aplica el perfil
> completo de §5, así que un negocio nuevo nace pudiendo vender al peso, leyendo bien los códigos
> de barras y hablando español. `country_codes` pasa a `co`; `tax_included` se queda como está y
> sigue siendo lo único pendiente del dueño. **Los dos negocios que ya existen no cambian.**

### 4.3 No podemos entrar a gestionar el negocio de un cliente

Hoy **no existe ningún camino** de una cuenta de plataforma hacia el punto de venta de un negocio.
`PlatformLogin::select()` **solo redirige** a la dirección del negocio: te lleva hasta la puerta y
sigue pidiéndote las credenciales de ese negocio. La pantalla "elija su negocio" es, en la práctica,
un directorio de enlaces.

### 4.4 Si se pierde la contraseña de un negocio, no hay salida por pantalla

Se muestra una vez y nunca más. Si el cliente la pierde —y va a pasar— hoy toca entrar a la base de
datos a mano. Eso no es algo que se pueda pedir en una llamada de soporte.

> **Estado a 2026-09-01: resuelto, sin desplegar y sin certificar.** La ficha del negocio muestra la
> contraseña mientras el cliente no la haya cambiado, y la restablece cuando sí. **Solo para los
> negocios creados a partir de ahora:** de Casaletto y de Paraíso la plataforma no guarda ninguna
> copia y no hay forma honesta de deducirla, así que en esos dos la única opción es restablecer.

### 4.5 El listado de negocios no se puede leer

Muestra la dirección corta, el nombre técnico de la base y el estado. **No muestra el nombre del
negocio** —ni se guarda— ni cuándo se creó, ni si alguien ha entrado alguna vez.

> **Estado a 2026-09-01: corregido a medias, sin desplegar y sin certificar.** El nombre se guarda al
> dar de alta, y el listado pasa a mostrarlo junto con la dirección como enlace y la fecha de alta.
> Las dos filas que ya existen se quedan **sin nombre** —se les muestra el slug y se dice que no lo
> tienen— hasta que alguien se lo ponga. **«Si alguien ha entrado alguna vez» sigue sin existir**:
> requiere leer dentro de cada negocio y no entra en esta entrega.

### 4.6 Los negocios nuevos se llaman "John Doe"

El sistema cambia el usuario y la contraseña, pero no la fila de la persona.

> **Estado a 2026-09-01: corregido, sin desplegar y sin certificar.** El administrador de un negocio
> nuevo se llama «Administrador» y el nombre del negocio como apellido. Lo que **sigue** con los
> datos de relleno de la semilla es su correo (`changeme@example.com`) y su teléfono: no se tocan
> porque nadie los ha decidido, y son datos del cliente, no de la plataforma.

---

## 5. Las decisiones, cerradas el 2026-08-31

| # | Decisión |
|---|---|
| **D1** | El superadministrador es **invisible** para el cliente: no aparece en su lista de empleados ni lo puede editar o eliminar |
| **D2** | **Existe "entrar a gestionar"** un negocio. Era el requisito central; deja de estar fuera de alcance |
| **D3** | **La raíz es la plataforma; el subdominio es el negocio.** La dirección actual del panel **redirige**, no se corta. **Casaletto aparece en el listado** como un negocio más, gestionable |
| **D4** | **La credencial de soporte es central.** En cada negocio se crea el empleado de soporte igual que hoy se crea el administrador —usuario, nombre, todos los permisos—, pero **su contraseña propia nunca se usa**: quien abre esa sesión es la cuenta de plataforma |
| **D5** | La contraseña del negocio **se autogenera y se puede volver a ver** mientras el cliente no la haya cambiado. **Sin cambio obligatorio** en el primer ingreso. En cuanto el cliente la cambia, deja de verse y solo queda restablecerla |
| **D6** | **Se registran las modificaciones, no los accesos.** Consecuencia asumida: el sistema no podrá responder "¿quién entró y cuándo?", solo "quién cambió qué" |
| **D7** | La sesión de soporte tiene **todos los permisos** |
| **D8** | **Tres intentos fallidos por cada dos horas**, contados **sobre la cuenta**. La ventana se cura sola. Otro superadministrador puede desbloquear, y el mensaje de error no revela si el correo existe |
| **D9** | **Un solo usuario inicial por negocio**, con todos los permisos, que después crea a los demás |
| **D10** | Los registros hechos desde una sesión de soporte muestran la etiqueta **«Soporte»**, sin exponer el usuario |
| **D11** | **Segundo factor TOTP**, solo para `is_platform_admin = 1` |
| **D12** | **Un solo perfil de configuración**, «Colombia · comercio al detal», aplicado en el alta. **Tres claves quedan bloqueadas** y el cliente no las puede cambiar; el resto sí es configuración suya |
| **D13** | El perfil se aplica a **Paraíso de forma explícita y revisada**. A **Casaletto no se le toca nada** sin comparar antes clave por clave |

### Sobre D11, el segundo factor

**Solo para superadministradores.** Hoy son dos cuentas, y una es la huérfana que se elimina: en la
práctica, **una persona**.

- **Los empleados de cada negocio no instalan nada** y nada cambia para ellos.
- **Los dueños de negocio tampoco**: cuando tengan cuenta de plataforma (Entrega 5) la llevarán con
  `is_platform_admin = 0`.

La asimetría es deliberada: la credencial de un cajero abre una caja; la de superadministrador abre
todos los negocios de todos los clientes.

**No hace falta elegir aplicación.** TOTP es un estándar: la app Contraseñas de Apple lo hace de forma
nativa, y también sirven 1Password, Bitwarden, Google Authenticator, Microsoft Authenticator o Authy.
La aplicación **no muestra el secreto**: el secreto se genera una vez al registrar y se guarda en los
dos lados; a partir de ahí la app muestra un código de seis dígitos que cambia cada 30 segundos, y la
plataforma calcula el mismo para compararlo. **El secreto no vuelve a viajar**, y por eso no hace
falta correo, SMS ni WhatsApp.

El factor va pegado **a la credencial, no a la pantalla**: se pide tanto en la consola raíz como al
entrar a un negocio. Protegerla en un sitio y dejarla suelta en el otro no tendría sentido.

#### Cómo se ve al darse de alta (escrito el 2026-09-01; sin desplegar y sin certificar)

**No hay imagen que escanear.** La pantalla de alta muestra la clave escrita, en ocho grupos de
cuatro, y además el enlace que la aplicación entiende. Hay dos maneras de darse de alta y las dos
funcionan:

- **Tecleando la clave.** Contraseñas de Apple, 1Password, Bitwarden y cualquier Authenticator
  aceptan la clave escrita a mano. Son 32 caracteres, y su alfabeto no tiene ni cero, ni uno, ni
  ocho, así que las confusiones de siempre (O con 0, I con 1, B con 8) no pueden darse.
- **Abriendo el enlace desde el propio teléfono.** Deja la entrada rellena y no hay nada que teclear.

La entrada quedará en el teléfono con el nombre **«OSPOS Plataforma»** y debajo el correo de la
cuenta. Conviene saber una cosa antes: quien se dé de alta **en staging y también en producción**
tendrá dos entradas llamadas igual, y le convendrá renombrar una en su aplicación.

**No se enciende nada hasta que un código real lo demuestre.** El alta pide escribir el código que
muestre el teléfono, y hasta que ese código sea correcto la cuenta sigue exactamente como estaba.
Es lo que impide el único final del que no se vuelve: un segundo factor encendido que no funciona,
en la única cuenta que administra la plataforma, sin correo ni SMS por donde devolvérsela.

Al encenderlo se entregan **diez códigos de rescate**, de un solo uso, en la misma pantalla y una
sola vez. Son la única forma de entrar si se pierde el teléfono.

**Apagarlo pide la contraseña**, porque apagarlo deja la cuenta detrás de una contraseña sola y es
justo lo primero que haría quien se encontrase una sesión abierta. Cambiar de teléfono es apagarlo y
volver a encenderlo.

#### El freno de los tres intentos también cuenta aquí

Los intentos fallidos **en la pantalla del código** cuentan igual que los de la contraseña, sobre la
misma cuenta y con la misma ventana de dos horas. Sin eso, quien ya hubiera acertado la contraseña
podría probar códigos de seis dígitos sin límite, y el freno de la contraseña no le estorbaría
porque ya la pasó.

### Sobre D12, qué contiene el perfil «Colombia · comercio al detal»

El perfil no es una idea, es esta lista. Sin ella escrita, «aplicar el perfil» no significa nada.

#### Las tres que quedan bloqueadas

No se bloquea el perfil entero: hay claves que legítimamente cambian entre negocios. Se bloquean estas
tres porque **cambiarlas no es una preferencia, es un daño**, y las tres ya causaron incidentes reales
en este proyecto.

| Clave | Valor obligatorio | Qué pasa si cambia |
|---|---|---|
| `quantity_decimals` | `3` | En `0` **el peso se pierde en silencio**: la venta cuadra en plata y el inventario queda mal |
| `barcode_content` | `item_number` | En `id` **un código tecleado vende otro producto**. Pasó en Paraíso el 2026-08-31: teclear `56` (aguacate, al peso) metía gelatina de cereza. **212 de 1.184 referencias colisionaban** |
| `language_code` | `es-MX` | Una variante distinta **parte el mantenimiento en dos**. El 2026-08-30 el aviso de peso salió en inglés porque la traducción se escribió solo en `es-ES` |

**Esas tres no son configuración, son cableado.** El resto sí es configuración del cliente.

> **El bloqueo no basta en la consola.** El negocio puede cambiar las tres desde **su propia pantalla
> de configuración**. Si solo se bloquean en la consola de plataforma, el candado es decorativo: hay
> que impedirlo también del lado del punto de venta.

#### Cómo lo ve el negocio en su pantalla (escrito el 2026-09-01; sin desplegar y sin certificar)

Las tres siguen **a la vista** en la configuración del negocio, en el mismo sitio de siempre: dos en
la pestaña **Regional** (los decimales de cantidad y el idioma) y una en **Código de barras** (qué
lleva el código). Lo que cambia es que **están fijas**: se ven en gris, no se pueden tocar, y al lado
hay un candado y una frase que dice **por qué** está fija y **a quién pedirle el cambio**.

**No se esconden a propósito.** Un ajuste que desaparece hace creer al cliente que su sistema no lo
tiene, y la llamada que llega después es «¿por qué mi punto de venta no puede hacer esto?». Prefiere
verse fijo y explicado que no verse.

**Y no es solo la pantalla.** Quien intente el cambio por fuera de la pantalla —enviando el formulario
a mano— también se lo encuentra rechazado. El freno de verdad está en el servidor; lo de la pantalla
es que al cliente no se le invite a intentarlo.

**Si se rechaza, no se guarda nada de esa pestaña.** Esas pantallas guardan sus quince ajustes de una
sola vez, así que dejar pasar los otros catorce e ignorar el bloqueado significaría poner un mensaje
verde sobre una pantalla que no hizo lo que dice. Es justo el tipo de silencio que puso a estas tres
claves en la lista. El mensaje dice cuál ajuste se rechazó, que no se guardó nada, y que lo pida a su
proveedor.

**A un negocio que hoy esté en el valor equivocado, la pantalla se lo dice.** Debajo del ajuste
aparece un aviso en rojo: este negocio no está en el valor requerido, que es tal, pídaselo a su
proveedor. El candado **no corrige nada por su cuenta** —cambiarle un valor a un negocio que está
vendiendo es una operación revisada, clave por clave, según D13—, pero tampoco lo congela en silencio.

> **Lo que falta para cerrar el círculo.** Ese aviso manda al cliente a pedirlo, y **la consola de
> plataforma todavía no tiene la pantalla que fije estas tres claves sobre un negocio ya existente**.
> Hasta que exista, corregir un negocio mal cableado sigue siendo trabajo a mano.

#### Las demás, que el perfil fija pero el negocio puede cambiar

| Clave | Valor inicial | Por qué es suya |
|---|---|---|
| `number_locale` | `es_CO` | |
| `currency_decimals` | `0` | Un cliente podría querer centavos |
| `language` | `spanish` | Va de la mano de `language_code`. **En la práctica queda fijo también**: es el mismo desplegable, y cada opción que dice «spanish» lleva un código distinto |
| `timezone` | `America/Bogota` | Un cliente fuera de Bogotá lo necesitaría distinto |
| `company` | el nombre del negocio | Evidentemente suya |
| `country_codes` | `co` *(decidido el 2026-09-01)* | Hoy `us` en los dos negocios, que es sencillamente incorrecto para Colombia |
| `tax_included` | **sigue por decidir** | Hoy `0` en los dos. El perfil **no lo toca**, y un negocio nuevo se queda con el `0` de la semilla |

**Sobre `tax_included`, y por qué el perfil lo deja quieto.** El documento de venta por peso
recomendaba `1`, pero los dos negocios de producción corren con `0`. Fijarlo en `1` desde el perfil
cambiaría en silencio cómo se calculan los precios de todo negocio futuro a partir de una
recomendación que producción ya desmiente, y eso **no lo decide quien escribe el código**. Queda
como pendiente del dueño: mientras no lo resuelva, cada negocio nuevo nace igual que los dos que ya
funcionan, que es la opción que no sorprende a nadie.

#### Estado a 2026-09-01: construido, **sin desplegar y sin certificar**

El perfil existe y se aplica solo al **dar de alta** un negocio. Escribe la configuración del
negocio **y** la fila del empleado inicial, que es lo que exige el hallazgo de abajo.

**A los negocios que ya existen no les hace nada**, a propósito y según D13: Paraíso ya se corrigió
a mano el 2026-08-31 y a Casaletto no se le toca sin comparar antes clave por clave. Aplicárselo a
un negocio existente sigue siendo una decisión aparte, que hoy no tiene pantalla.

**Lo que este perfil todavía no cubre:** el candado que impide al cliente cambiar las tres claves
de cableado desde **su propia** pantalla de configuración —eso vive en el punto de venta, no aquí—
y el **alta de empleados posteriores**, que sigue dejando el idioma suelto. Un empleado creado por
el cliente después del alta nace con el campo vacío; hoy eso funciona porque cae al idioma del
negocio, que el perfil ya dejó en `es-MX`, pero se romperá el día que alguien cambie el global.

#### El idioma vive en DOS sitios, y el del empleado manda

Hallazgo que cambia cómo se implementa el perfil. `ospos_employees` tiene **sus propias columnas**
`language` y `language_code`, y **ganan sobre la configuración del negocio**: si el empleado que entró
tiene un idioma propio no vacío, ese es el que se usa.

**Un perfil que solo escriba la configuración del negocio no funciona.** Todo empleado que se cree
después nace con el suyo, y el del aprovisionador no lo alcanza. El perfil tiene que cubrir **los dos
sitios**, y **el alta de empleados tiene que heredar del perfil** en vez de dejar el campo suelto.

#### Estado actual, verificado el 2026-08-31

- **Paraíso quedó en `es-MX`**: la configuración del negocio y sus dos empleados (`admin`,
  `angela.rodriguez`). La tarea que salía de aquí **ya se ejecutó**.
- **A Casaletto no se le tocó nada**, según D13. De paso: tres de sus seis empleados tienen el idioma
  vacío, así que caen al del negocio, que ya es `es-MX`. **Están bien**, pero conviene saberlo el día
  que se le aplique el perfil.

---

## 6. Las pantallas del módulo

Todas viven en la consola de plataforma, que es una aplicación aparte del punto de venta y la usan una
o dos personas. Debe ser densa y sobria, no bonita.

### 6.1 Superadministradores *(nueva)*
No es un CRUD genérico: tiene que contestar **¿cuál de estas cuentas no debería existir?**
- Correo, fecha de alta, quién la creó y **último ingreso** — la columna que delata a la cuenta huérfana.
- Crear, eliminar, cambiar la propia contraseña, activar el segundo factor.
- Nadie se borra a sí mismo; no se puede borrar el último; se confirma **escribiendo el correo**.
- Desbloquear a otro superadministrador tras los tres intentos fallidos.

### 6.2 Listado de negocios *(rehecha)*
Hoy muestra el nombre técnico de la base de datos. Con diez negocios es inservible.
- Nombre real, dirección como enlace, estado, fecha de alta.
- **Si alguien ha entrado alguna vez**: un negocio entregado y nunca usado es información que se quiere ver.
- Suspender, reactivar, eliminar — **con las protecciones de 6.6**.

> **Estado a 2026-09-01: rehecha, sin desplegar y sin certificar.** Nombre, dirección como enlace,
> base de datos, fecha de alta, estado y acciones. **Falta «si alguien ha entrado alguna vez»**, que
> obliga a leer dentro de cada negocio: es la única línea de esta pantalla que queda pendiente.

### 6.3 Ficha del negocio *(nueva)*
Donde de verdad se gestiona un cliente. Es la pantalla que hoy no existe y que hace falta.
- Su configuración editable: idioma, IVA, decimales, contenido del código de barras.
- **Consultar** la contraseña del administrador mientras no la haya cambiado, y **restablecerla** después.
- Sus dueños vinculados.
- El botón de **Entrar a gestionar**.

> **Estado a 2026-09-01: existe a medias, sin desplegar y sin certificar.** Tiene la identificación
> del negocio, la contraseña consultable con su bloque de entrega, el restablecimiento, y la
> configuración del perfil **en solo lectura** —leída del propio negocio, para poder comprobar que
> el perfil de verdad se aplicó, y señalando en rojo cualquiera de las tres claves de cableado que
> no esté en su valor.
>
> **Lo que NO tiene todavía:** editar esa configuración desde aquí, los dueños vinculados y el botón
> de «Entrar a gestionar». Los dos últimos son de las Entregas 4 y 5; editar la configuración se
> quedó fuera de esta y hay que decidir si vuelve.

### 6.4 Alta de negocio *(nueva)*
Un formulario que deja el negocio listo, no uno que deja un esquema vacío.
- Nombre, dirección, perfil de configuración y **correo del dueño** — el vínculo se crea aquí, que es
  el único momento en que se tiene ese dato. Una pantalla aparte para llenarlo seguiría vacía, como
  hoy.
- Al terminar, el **bloque de entrega completo** —dirección, usuario, contraseña— **copiable de una
  vez**, porque eso es lo que se pega en un mensaje al cliente.
- Cerrar exige un clic explícito («Ya los guardé»), no desaparecer al navegar. Y la pantalla dice que
  se puede volver a consultar, que es lo que quita el pánico de perderla.

> **Estado a 2026-09-01: a medias, sin desplegar y sin certificar.** El formulario sigue pidiendo
> solo nombre y dirección; el perfil no se elige porque **hay uno solo** (D12) y el **correo del
> dueño no se pide todavía** —el vínculo cuenta↔negocio es de la Entrega 5—.
>
> Lo que sí cambió: al terminar ya no aparece un mensaje con la contraseña dentro que se pierde al
> navegar. Se aterriza en la **ficha del negocio**, con el bloque de entrega —dirección, usuario y
> contraseña, todo junto y seleccionable— y la frase que dice que se puede volver a consultar. Por
> eso mismo **no hace falta el botón de «Ya los guardé»**: no hay nada que se pierda al salir.

### 6.5 Registro de actividad *(nueva)*
- Qué modificamos dentro del negocio de un cliente, con la etiqueta «Soporte».
- Altas, suspensiones y bajas de negocios.
- Cambios de configuración y restablecimientos de contraseña hechos desde la consola.

### 6.6 La pantalla de eliminar, con freno *(a corregir — riesgo vivo)*
Hoy eliminar un negocio es **una casilla y un botón**, y la casilla borra su base de datos completa.
El listado incluye a **Casaletto**, así que ese botón está hoy a dos clics de destruir la base del
negocio que está vendiendo.
- Confirmación **escribiendo el slug del negocio**, no una casilla.
- Los negocios **adoptados** (Casaletto) no se pueden eliminar desde la consola.
- Borrar la base de datos exige **una confirmación aparte** y deja constancia en el registro.

> **Estado a 2026-09-01: EN PRODUCCIÓN**, certificado por el dueño sobre la interfaz real. La
> pantalla pide escribir el slug; el borrado de la base es una confirmación aparte que exige además
> escribir su nombre (para Casaletto sería `ospos`, que avisa mucho más que `casaletto`), y
> cualquiera de las dos mal escrita rechaza la operación entera. Casaletto sigue en el listado, sin
> enlace de eliminar y con el motivo a la vista. Mientras no exista el registro de actividad
> (§6.5), cada baja, cada borrado de base y cada rechazo quedan en el registro técnico de errores
> con quién, qué y cuándo. **Falta probarlo en staging**, que es la única verificación que vale.

### 6.7 Elegir negocio *(corregida)*
Existe pero no funciona para nadie: la tabla que la alimenta está vacía y, aunque se llenara, solo
redirige. Con el vínculo creado en el alta y la entrada por URL, el dueño aterriza **dentro** de su
negocio.

---

## 7. Alcance, en cinco entregas

Ordenadas para que cada una deje el sistema mejor aunque la siguiente se demore.

### Entrega 1 — La casa propia
- Regla de proxy para `ospos-saas.micronuba.net`.
- Tratamiento explícito de la raíz, para que no corra sobre la base de un cliente.
- Sesión de plataforma con almacenamiento propio.
- **Quitar el panel de los subdominios de cliente.**
- Redirección desde la dirección actual.
- **Freno en la pantalla de eliminar** (§6.6): confirmar escribiendo el slug, y bloquear la baja de
  negocios adoptados. Es lo único de esta entrega que corrige un riesgo ya existente.

### Entrega 2 — Cerrar la llave suelta

> **Estado a 2026-09-01: construido y CERTIFICADO EN STAGING, sin desplegar a producción.** El
> segundo factor lo certificó el dueño con la aplicación de su teléfono, y el código de rescate de un
> solo uso también. Se
> escribió primero la parte que no se ve —dónde se guardan el último ingreso, quién creó cada
> cuenta, el contador de intentos, el segundo factor, los códigos de rescate y el registro de
> actividad—, más las salvaguardas de «ni a sí mismo, ni al último». Las pantallas vienen después.
>
> **Nada de esto se ha probado todavía**: la máquina donde se escribió no tenía la base de datos
> de pruebas levantada, así que las pruebas están escritas pero no ejecutadas. **No está
> desplegado y no está certificado.** La cuenta huérfana sigue existiendo.

- Pantalla de **superadministradores**: ver, crear, eliminar, con último ingreso.
- **Cambiar la propia contraseña** y **activar el segundo factor**, con códigos de rescate.
- Salvaguardas: ni a sí mismo, ni al último, confirmación escribiendo el correo, desbloqueo por otro
  superadministrador.
- Registro de actividad del panel.
- **Eliminar la cuenta huérfana** — y en el mismo movimiento **crear una segunda cuenta real**, para
  no quedarse con una sola: es la que desbloquea tras los tres intentos y la que salva si se pierde el
  teléfono con el segundo factor.

### Entrega 3 — Que un negocio nazca funcionando

> **Estado a 2026-09-01: construido a medias, sin desplegar y sin certificar.** Están el perfil de
> configuración, el fin del «John Doe», el nombre del negocio guardado, el listado legible, la
> contraseña consultable y el restablecimiento, y una primera ficha del negocio. **Las pruebas están
> escritas pero no se han ejecutado**: la máquina donde se escribió no tiene la base de datos de
> pruebas levantada. Nada de esto está desplegado ni certificado, y la certificación no la firma
> quien escribió el código.
>
> **Queda fuera, y hay que decidir cuándo entra:** el candado de las tres claves de cableado en la
> pantalla de configuración del propio negocio, la herencia del idioma al crear empleados
> posteriores, y editar la configuración desde la ficha.

- **Perfil de configuración** aplicado en el alta.
- **Ficha del negocio** con su configuración editable.
- Nombre del negocio guardado, listado legible, fin del "John Doe".
- **Consultar** la contraseña inicial mientras no se haya cambiado, y **restablecerla** después.
- Bloque de entrega copiable: dirección, usuario y contraseña, todo junto.
- ~~**Bloquear las tres claves de cableado** también en la pantalla de configuración del negocio, no
  solo en la consola.~~ — **escrito el 2026-09-01; sin desplegar y sin certificar.** Ver §5, «Cómo lo
  ve el negocio en su pantalla».
- El alta de empleados **hereda el idioma del perfil**.
- ~~Aplicar el perfil a Paraíso~~ — **ya ejecutado el 2026-08-31**.

### Entrega 4 — Entrar a gestionar
- Empleado de soporte, invisible para el cliente, creado en el alta y en los negocios existentes.
- **Entrada por la URL del negocio** con la credencial de plataforma.
- Aviso permanente en pantalla mientras dure la sesión de soporte.
- Registro de las **modificaciones** hechas dentro del negocio, con la etiqueta «Soporte».

### Entrega 5 — El dueño entra a lo suyo
- Vínculo cuenta↔negocio creado desde el alta.
- Qué empleado es esa cuenta dentro de ese negocio.
- El dueño aterriza **dentro** de su negocio, no en un formulario de entrada.

---

## 8. Lo que este trabajo NO incluye

- **Cobros, facturación o planes.** No existe nada de eso y no se aborda aquí.
- **Autoservicio de registro.** Los negocios los damos de alta nosotros; no hay formulario público y
  no debe haberlo.
- **Cambiar cómo se aísla un negocio de otro.** Eso ya funciona y no se toca.
- **Segundo factor para usuarios de negocio.** Ver D11.
- **Carga masiva de artículos.** Requerimiento aparte, detenido por decisión del 2026-08-31.

---

## 9. Cómo sabremos que quedó bien

- `ospos-saas.micronuba.net` abre la consola, y `…/platform` **ya no responde** desde el subdominio de
  ningún cliente.
- Se crea un superadministrador **sin abrir una terminal**, y se elimina la cuenta huérfana.
- El sistema **impide** quedarse sin ningún administrador.
- Tres intentos fallidos frenan el acceso **sin dejar fuera de la plataforma al dueño**, porque otro
  superadministrador puede desbloquear.
- Un negocio recién creado **vende un artículo al peso correctamente** sin que nadie toque su
  configuración, y no sale en inglés ni dice "John Doe".
- Se entra a gestionar cualquier negocio **con la misma contraseña de siempre**, y las modificaciones
  quedan registradas.
- El cliente **no ve el usuario de soporte** en su lista de empleados ni lo puede modificar.
- Un cliente que perdió su contraseña se resuelve **en una llamada**: se consulta si no la ha
  cambiado, se restablece si sí.
- **Eliminar un negocio exige escribir su slug**, y Casaletto no se puede eliminar desde la consola.
- Un cliente **no puede cambiar** los decimales de cantidad, el contenido del código de barras ni el
  idioma **desde su propia pantalla de configuración**.
- Un empleado creado después del alta **nace con el idioma del perfil**, no con el campo vacío.
- Quedan **dos superadministradores reales**, de modo que ninguno pueda quedar encerrado fuera.
- **Casaletto y Paraíso siguen comportándose exactamente igual.**
