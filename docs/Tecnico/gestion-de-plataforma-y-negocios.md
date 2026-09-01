# Diseño técnico — Gestión de la plataforma y de los negocios-cliente

> **Estado a 2026-09-01: Entregas 1, 2 y 3 EN PRODUCCIÓN** (`c6775c55a`). Desplegadas por SSH,
> con `php spark platform:migrate` a mano después del `up -d --build`, como manda §9.14. La suite
> quedó verde en 8.2/8.3/8.4 tras arreglar siete fallos que arrastraba desde E1 -- cuatro de ellos
> por contaminación de la base de pruebas compartida, ver §9.15.

---

## 1. Mapa de lo que ya existe

Todo verificado leyendo el código, no de memoria.

| Archivo | Qué hace |
|---|---|
| `app/Controllers/PlatformAdmin.php` | Panel. `index`, `newTenant`, `create`, `suspend`, `activate`, `confirmDelete`, `delete` |
| `app/Controllers/PlatformLogin.php` | `index` (entrar), `selectIndex`, `select`, `logout` |
| `app/Models/PlatformAccount.php` | `login`, `logout`, `isLoggedIn`, `getLoggedInAccount`, `isPlatformAdmin`, `getTenantsForAccount`, `createAccount` |
| `app/Libraries/TenantProvisioner.php` | `create`, `adopt`, `setStatus`, `delete` — la lógica real |
| `app/Filters/TenantResolver.php` | Resuelve el negocio desde el Host y cambia la conexión |
| `app/Commands/PlatformAccountCreate.php` | `php spark platform:create-account <email> [--admin]` |
| `app/Views/platform/` | `login.php`, `select_business.php`, `admin/{index,form,confirm_delete}.php` |
| `app/Config/Routes.php` líneas 18-29 | Las rutas de plataforma |

**Esquema `platform_control`** (`app/Platform/Database/Migrations/`):

| Tabla | Estado real en producción |
|---|---|
| `tenants` | 2 filas: `casaletto` (adoptado, `db_user` vacío) y `paraisodelacanasta` |
| `platform_accounts` | 2 filas, **ambas** `is_platform_admin = 1` |
| `platform_account_tenants` | **VACÍA** |

Columnas de `tenants`: `id, slug, db_name, db_user, db_password, status, created_at, updated_at`.
**No hay `company_name`.**

La protección del panel es **una sola línea**, en el constructor:

```php
if (!$this->account->isPlatformAdmin()) {
    throw new RedirectException('platform/login');
}
```

---

## 2. Cómo nace hoy un negocio

### 2.1 El usuario

En `TenantProvisioner::create()`, después de migrar:

```php
$adminPassword = bin2hex(random_bytes(8));      // 16 hex
$tenantDb->table('employees')->where('person_id', 1)->update([
    'username' => 'admin',
    'password' => password_hash($adminPassword, PASSWORD_DEFAULT),
]);
```

Se devuelve en el array de retorno y `PlatformAdmin::create()` lo pone en un mensaje flash. **No se
guarda en ninguna parte.**

**Por qué ese reemplazo existe y no se puede quitar:**
`app/Database/Migrations/sqlscripts/initial_schema.sql` siembra cada esquema nuevo con el **usuario y
el hash bcrypt reales de Casaletto**. Sin este paso, todo negocio nuevo nacería con la contraseña de
administrador de otro cliente. Documentado en `docs/Tecnico/multi-tenant-arquitectura.md` §16.

**Lo que NO reemplaza:** la fila de `people`. El administrador del negocio nuevo se llama
**"John Doe"**.

> **Corregido el 2026-09-01; EN PRODUCCIÓN desde 2026-09-01, certificado en staging sobre la interfaz real.** Los tres pasos —credencial, nombre de
> la persona y perfil de configuración— viven ahora juntos en
> `TenantProvisioner::seedInitialAdmin()`, que recibe la conexión al negocio en vez de abrirla.
>
> **Es público y recibe la conexión porque si no, no se puede probar.** Atado a `create()`, el
> bloque empezaba por `CREATE DATABASE`, y el usuario de la base de pruebas no tiene ese permiso —a
> propósito—, así que los dos ajustes que más caro han salido en este proyecto no tenían ninguna
> cobertura posible. Separado, se le pasa cualquier esquema OSPOS y se comprueba lo que dejó escrito.
>
> **El orden de las tres escrituras importa:** el perfil va el ÚLTIMO. Escribe el idioma en la fila
> de `employees`, y el `UPDATE` que pone usuario y contraseña se lo llevaría por delante si fuera
> después.
>
> Lo que sigue sin probarse por prueba automática, y hay que mirar en la certificación: que
> `create()` de verdad llame a esto, que la fila de `tenants` se escriba entera, y que las
> migraciones del esquema nuevo pasen. Un negocio creado a mano en staging lo enseña todo de una vez.

### 2.2 La configuración — el hueco más grave

`create()` escribe **una sola** clave:

```php
$tenantDb->table('app_config')->where('key', 'company')->update(['value' => $companyName]);
```

Todo lo demás queda con los valores que siembra `initial_schema.sql`, que son los del proyecto
original. **Medido en producción el 2026-08-31:**

| Clave | Semilla | Casaletto | Paraíso |
|---|---|---|---|
| `quantity_decimals` | `0` | `3` | `3` |
| `barcode_content` | `id` | — | `item_number` |
| `number_locale` | `en_US` | — | `es_CO` |
| `currency_decimals` | `2` | — | `0` |
| `language_code` | `en` | `es-MX` | `es-MX` *(era `es-ES`, corregido 2026-08-31)* |
| `country_codes` | `us` | `us` | `us` |
| `tax_included` | `0` | `0` | `0` |
| `timezone` | `America/Bogota` | — | `America/Bogota` |

Dos de las claves de la semilla son **los dos defectos de producción que más caro han salido** en este
proyecto: `quantity_decimals = 0` pierde el peso en silencio, y `barcode_content = id` hace que un
código tecleado venda otro producto.

> **Corregido el 2026-09-01; EN PRODUCCIÓN desde 2026-09-01, certificado en staging sobre la interfaz real.** El `update` de una sola clave lo
> sustituye `App\Libraries\TenantConfigProfile`, que es el perfil de D12 escrito una vez y en un solo
> sitio: `WIRING` (las tres bloqueadas), `PREFERENCES` (las que el negocio puede cambiar) y el nombre
> de la empresa. `applyTo()` escribe `app_config` **y** la fila del empleado, porque el idioma vive
> en los dos sitios (§2.4).
>
> **Cada clave se escribe con «existe o no existe», no con un `UPDATE` a secas.** `app_config` es una
> tabla clave/valor cuyo contenido depende de por qué migraciones pasó cada esquema; un `UPDATE`
> sobre una clave ausente no escribe nada, no falla, y el negocio se queda con lo que el código asuma
> por defecto. Es la misma clase de fallo mudo que todo lo demás de esta sección.
>
> `tax_included` **no está en el perfil**, ni en una lista ni en la otra, así que un negocio nuevo se
> queda con el `0` de la semilla. `country_codes` pasa a `co`. Las dos decisiones están escritas en
> §5 del documento funcional.

**Paraíso está bien configurado** porque alguien lo corrigió a mano, no porque el sistema lo hiciera.
Y **una clave se escapó**: `language_code` quedó en `es-ES` frente al `es-MX` de Casaletto, así que
una cadena traducida en un idioma no se veía en el otro negocio. **Corregido el 2026-08-31**, negocio
y empleados. Nadie lo habría notado hasta que una pantalla saliera en inglés.

### 2.3 Los permisos

`ospos_permissions` tiene **40 filas** y `ospos_modules` **19**. En Paraíso, tanto `admin`
(person_id 1) como `angela.rodriguez` (person_id 2) tienen **los 40**. Lo que pide D9 ya ocurre; lo
que falta es garantizar que siga ocurriendo en cada alta.

### 2.4 El idioma vive en dos sitios, y el del empleado manda

`ospos_employees` tiene sus propias columnas `language` y `language_code`
(`Employee.php:27-28`), y **ganan sobre `app_config`**. En `app/Helpers/locale_helper.php:17-22`:

```php
if ($employee->is_logged_in() && !$load_system_language) {
    $employee_info = $employee->get_logged_in_employee_info();
    if (property_exists($employee_info, 'language_code') && !empty($employee_info->language_code)) {
        return $employee_info->language_code;   // gana sobre app_config
    }
}
```

Solo cuando el empleado lo tiene **vacío** se cae al valor del negocio.

**Consecuencia para el perfil:** escribir solo `app_config` no basta. Todo empleado creado después
nace con el suyo —`Employees.php:183` y `:190` lo toman del formulario— y el aprovisionador no lo
alcanza. El perfil tiene que cubrir **los dos sitios**, y el alta de empleados debe **heredar** del
perfil en vez de dejar el campo suelto.

**Estado verificado el 2026-08-31:**

| Negocio | `app_config` | Empleados |
|---|---|---|
| Paraíso | `es-MX` | `admin` y `angela.rodriguez`, los dos en `es-MX` |
| Casaletto | `es-MX` | 3 de 6 en `es-MX`; los otros 3 **vacíos**, y por eso caen al del negocio |

Los tres vacíos de Casaletto están bien hoy porque el global ya es `es-MX`. Conviene saberlo el día
que se le aplique el perfil: **cambiar el global les cambiaría el idioma a ellos y no a los otros
tres**.

---

## 3. Arquitectura de direcciones y sesiones

### 3.1 Lo que hay

- **DNS**: la raíz `ospos-saas.micronuba.net` y el comodín `*.` apuntan al mismo servidor.
- **Certificado**: su SAN incluye `*.ospos-saas.micronuba.net` **y** la raíz. La trampa clásica —un
  comodín no cubre el dominio raíz— ya está resuelta.
- **La raíz devuelve `404 page not found`** en texto plano: es la respuesta por defecto del proxy, no
  de la aplicación. Falta una regla de enrutado, no un servidor.
- Cada contenedor ya tiene **dos routers**: uno exacto para la URL legacy de Casaletto y uno
  `HostRegexp` para el comodín (`docs/Tecnico/multi-tenant-arquitectura.md` §6). Añadir la raíz es el
  mismo tipo de cambio.

### 3.2 Tres trampas antes de mudar el panel

**a) `extractSlug()` no reconoce la raíz.** Busca hosts que terminen en `.ospos-saas.micronuba.net`;
la raíz no termina en eso, devuelve `null`, y el filtro la trata como "esto no es una petición de
tenant" — que hoy significa **caer a la conexión por defecto, la base de Casaletto**. Mudar el panel
a la raíz sin tocar esto pondría la consola de toda la plataforma a correr sobre la base del negocio
que está vendiendo. **La raíz necesita un tratamiento propio y explícito.**

**b) La plataforma no tiene dónde guardar su sesión.** `Config\Session` usa `DatabaseHandler` con
`savePath = 'sessions'` sobre la conexión por defecto, así que la sesión vive **en la base del host
por el que se entró**. Hoy la sesión del panel está dentro de la base de Casaletto.
`platform_control` no tiene tabla de sesiones y la necesita.

**c) Las rutas no distinguen el host.** `platform/*` está registrada globalmente y por eso
`paraisodelacanasta…/platform/login` responde **200**. Acotarla por host es lo que hace real la
delimitación de D3.

### 3.3 Lo que NO hay que cambiar

`Cookie::$domain` está vacío, así que la cookie es **host-only**: una sesión de Paraíso no es visible
desde el subdominio de Casaletto. Eso es correcto. **Compartir cookies entre subdominios de clientes
distintos sería un agujero**; el diseño de la entrada a un negocio no debe apoyarse en ello.

---

## 4. Identidad de superadministrador

### 4.1 El modelo

Separar **quién eres** de **qué eres dentro de cada negocio**:

- La contraseña vive **solo** en `platform_accounts`. Se cambia una vez y sirve en todos los negocios.
- En cada negocio hay un **empleado de soporte**, creado por el aprovisionador igual que se crea el
  administrador —usuario, nombre, los 40 permisos, fila en `people`—, pero **con la contraseña
  inutilizable**: no hay ninguna que sirva por el login del cliente.
- La sesión en el negocio se abre autenticando contra `platform_accounts`, no contra `employees`.

El empleado sombra no es burocracia: todo OSPOS cuelga de `person_id` —ventas, turnos, permisos,
auditoría—. Sin una fila de empleado, nada de lo que se haga ahí dentro tiene autor.

### 4.2 Cómo se entra

Dos caminos, misma credencial:

1. **Por la URL del negocio** (D3). El formulario de entrada del negocio pasa a aceptar **dos orígenes
   de identidad**: `employees` de ese negocio, y `platform_accounts`. Es el camino principal.
2. **Desde la consola**, como atajo: un pase firmado, de un solo uso y vida corta, atado a ese
   negocio. Conviene notar que el diseño original **descartó explícitamente** un token de un solo uso
   (`multi-tenant-arquitectura.md` §10, *"se descartó por ser esfuerzo innecesario para el MVP"*).
   Esta decisión lo revierte, y el motivo es nuevo: entonces no existía el requisito de gestionar los
   negocios de los clientes.

### 4.3 Cómo se oculta

`employees` tiene **un solo mecanismo para esconder**, la columna `deleted`, y está usada en todas
partes —incluido el propio login (`Employee.php:370` y `:511`)—. Usarla para esto sería marcar como
borrado algo que no lo está: funciona hoy y miente mañana.

**Se necesita una columna propia**, `is_platform_support`, con `DEFAULT 0` para que **ningún empleado
existente cambie de comportamiento**, y excluirla en todas las superficies donde se lista un empleado.
`Employee.php` filtra por `deleted` en **nueve** sitios; hay que auditarlos uno por uno, más como
mínimo:

- La grilla de **Empleados** (`Employee::search()` y `getFoundRows()`).
- El **selector de empleado de los reportes** (`app/Views/reports/specific_input.php`).
- El filtro de **Turnos** (`Cashups`).
- Cualquier desplegable de asignación.

**Lo que no se puede ocultar:** un registro escrito desde una sesión de soporte apunta a un empleado
invisible. Por D10, esas filas se presentan con la etiqueta literal **«Soporte»** — es un cambio de
presentación, no de modelo de datos.

---

## 5. La contraseña consultable (D5)

`password_hash()` es irreversible por diseño, así que mostrarla después obliga a guardar una copia
recuperable. El mecanismo:

1. Al crear el negocio, se guarda en **`platform_control`** —nunca en la base del cliente— la
   contraseña inicial **cifrada**, junto al **hash que generamos**.
2. El panel la muestra **solo mientras** el `employees.password` actual siga siendo ese hash.
3. En cuanto difiere, el cliente la cambió: se borra la copia y la ficha solo ofrece **restablecer**.

**Hereda la dependencia de la clave de cifrado** — ver §9.1. Y no hay cambio obligatorio en el primer
ingreso (D5), así que esta es la única vía de recuperación además del restablecimiento.

### 5.1 Cómo quedó construido (escrito el 2026-09-01; EN PRODUCCIÓN desde 2026-09-01, certificado en staging sobre la interfaz real)

Cuatro columnas aditivas sobre `platform_control.tenants`, en la migración
`20260903000001_AddAdminCredentialToTenants` (namespace `Platform`, `$DBGroup='platform'`, Forge,
`down()` reversible), y toda la lógica en `App\Libraries\TenantProvisioner`.

| Columna | Qué guarda |
|---|---|
| `admin_username` | A quién pertenece. Hoy siempre `admin`, pero en la Entrega 4 habrá dos usuarios por negocio y suponerlo sería falso |
| `admin_password_hash` | El hash que **nosotros** escribimos. Es el testigo, no la llave |
| `admin_password_cipher` | La copia cifrada, **TEXT** |
| `admin_password_set_at` | Cuándo se generó la que está guardada |

**`admin_password_cipher` es TEXT y no `VARCHAR(255)`.** No es una elección de comodidad: es el
mismo defecto que ya rompió `tenants.db_password` (§9.1 y el comentario largo dentro de `create()`).
El cifrado se guarda **sin `base64_encode()` encima** —`service('encrypter')->encrypt()` con
`rawData=false` ya devuelve texto imprimible— y además la columna no tiene un techo que pueda
alcanzarse, de modo que ni una contraseña más larga en el futuro ni un cambio de cifrado puedan
volver a producir un truncamiento mudo. La prueba lo comprueba **metiendo un cifrado real por la
base y volviéndolo a leer y a descifrar**, no mirando la definición de la columna: MySQL fuera de
modo estricto corta el valor sin dar error, y esa es exactamente la forma que tiene el defecto.

#### Los cinco estados, y por qué son cinco

`TenantProvisioner::adminCredential()` devuelve uno de estos. Separarlos es la parte que importa:
tres de ellos parecen «no se puede ver» y significan cosas muy distintas.

| Estado | Qué pasó | Qué hace la consola |
|---|---|---|
| `available` | El hash del negocio sigue siendo el nuestro | La muestra |
| `changed` | El hash difiere, o el usuario ya no existe | **Borra la copia** y ofrece restablecer |
| `none` | Nunca hubo copia, o ya se borró | Ofrece restablecer. Es el caso de Casaletto y Paraíso |
| `unreadable` | Hay copia pero no se descifra: la clave de cifrado cambió | **No borra nada.** Es una avería nuestra, no un cambio del cliente |
| `unreachable` | No se pudo llegar al negocio | **No borra nada.** No comprobamos, luego no sabemos |

Los dos últimos existen para no mentir. Decir «el cliente la cambió» cuando lo que pasó es que
perdimos la clave manda a buscar el problema al sitio equivocado; y borrar la copia porque la base
estuvo caída un segundo destruye lo único que había.

**Se consulta el negocio de verdad, no una marca.** Sería más barato guardar una bandera «ya la
cambió», y sería mentira: el cliente la cambia desde su propio punto de venta, que no le avisa a la
consola de nada. La única fuente de verdad es la fila de `employees` de ese negocio.

#### Restablecer

`resetAdminPassword($slug, $username)` es la lógica que ya estaba dentro de `create()`, extraída. El
usuario **se pide, no se supone**: un negocio nuestro tiene `admin`, Casaletto es adoptado y su
administrador se llama de otra forma. Si el usuario no existe en ese negocio, se niega y lo dice. La
copia en `platform_control` se escribe **después** de que el negocio tenga la contraseña nueva; al
revés, un fallo al escribir en el negocio dejaría en la consola una contraseña que allí no abre nada.

#### Dos trampas que se encontraron por el camino

**`config(Database::class)` no sirve para abrir la conexión a un negocio.** TenantResolver
**reescribe ese arreglo en sitio**, y en una petición de la consola de plataforma
`pointAtControlSchema()` ya le puso el host, el usuario y la contraseña de `platform_control`.
Usarlo habría hecho que un negocio **adoptado** —el que cae a las credenciales compartidas, o sea
Casaletto— se intentara abrir con el usuario de la plataforma, que no tiene ningún permiso en el
esquema del cliente. Habría fallado **solo en Casaletto, solo desde la consola y solo en
producción**. `TenantProvisioner::hostConfig()` construye una instancia nueva de `Config\Database`,
que vuelve a leer las variables `MYSQL_*` del entorno. Se cambiaron también `adopt()` y `delete()`,
que tenían el mismo patrón.

**La contraseña ya no viaja en un mensaje flash.** La sesión de la consola vive en una tabla de
`platform_control` (§3), así que el mensaje de `PlatformAdmin::create()` escribía la contraseña del
cliente **en claro en nuestra base de datos** hasta la petición siguiente. Ahora el alta redirige a
la ficha con `?reveal=1` y el texto en claro solo existe en el cuerpo de una respuesta.

**Mostrar es una GET; restablecer es una POST que redirige.** Mostrar no cambia nada, así que
refrescar da lo mismo y la dirección lleva la marca, nunca el secreto. Restablecer sí cambia, y
redirige en vez de pintar el resultado porque `Config\Security::$regenerate` es `false` en este
proyecto: una respuesta HTML servida directamente desde ese POST se podría reenviar con F5 y dejar
al cliente con una contraseña distinta de la que se le dictó.

#### Deuda anotada al cerrar

- **Consultar no se registra en el registro de actividad y restablecer sí.** Es D6 aplicado tal
  cual: modificaciones sí, accesos no. Consecuencia asumida: el sistema no podrá contestar «¿quién
  miró la contraseña de este cliente?».
- **Un administrador dado de baja pero con la contraseña sin cambiar** aparece como `available`. El
  hash coincide y la afirmación es cierta —esa contraseña sigue siendo la suya—, pero la ficha no
  avisa de que ese empleado está de baja y no podría entrar. Falta un aviso y esta entrega no tiene
  dónde ponerlo.
- **La ficha abre dos conexiones al negocio** por cada carga: una para comprobar la contraseña y
  otra para leer su configuración. En una consola que usan una o dos personas no molesta; si algún
  día hay veinte negocios en una sola pantalla, esto no escala y hay que unirlas.

---

## 6. Segundo factor (D11)

TOTP, **solo para `is_platform_admin = 1`**. No es una app concreta sino un estándar: la app
Contraseñas de Apple lo hace de forma nativa, igual que 1Password, Bitwarden o cualquier
Authenticator.

Lo que hay que construir:

- Un paquete de Composer para verificar los códigos (candidato: `spomky-labs/otphp`; se fija la
  versión al implementar).
- En `platform_accounts`: el **secreto cifrado** y la fecha de activación.
- **Códigos de rescate**: ocho o diez, de un solo uso, mostrados una vez y guardados **con hash**,
  nunca en claro. Es la única recuperación posible sin canales de envío.
- Pantalla de registro que **exige escribir un código válido antes de activar**, para que nunca quede
  activado algo que no funciona.
- Verificación en **los dos caminos** de §4.2: la credencial lleva el factor, no la pantalla.

**Dependencia que en este proyecto no es trivial:** TOTP usa el epoch UTC, no la zona horaria
configurada, así que depende del **reloj del servidor**. Hay que confirmar que el VPS tenga NTP
activo, o los códigos empezarán a fallar sin explicación. Y aceptar ±1 ventana de 30 s por deriva.

### 6.1 Límite de intentos (D8)

Tres intentos fallidos por cada dos horas, **contados sobre la cuenta**, con ventana que se cura sola.
Dos añadidos que lo hacen seguro: que **otro superadministrador pueda desbloquear** desde el panel, y
que el mensaje de error **no revele si el correo existe**. Aplica en los dos caminos de entrada.

**La ventana no se extiende.** El ancla es el primer fallo y no se mueve: tres fallos a las 10:00
cierran hasta las 12:00 exactas, y un cuarto intento a las 11:59 no empuja el final a las 13:59. Es
lo que quiere decir «se cura sola», y la alternativa —reescribir el ancla en cada intento— convierte
el freno en una cárcel: un atacante que golpee cada media hora deja al dueño legítimo fuera para
siempre, y gratis.

### 6.2 Cómo quedó construido (escrito el 2026-09-01; EN PRODUCCIÓN desde 2026-09-01, certificado en staging sobre la interfaz real)

Las piezas y las decisiones que no estaban en el diseño y hubo que tomar al implementarlo.

| Pieza | Dónde vive |
|---|---|
| El cálculo TOTP y el secreto | `app/Libraries/PlatformTotp.php` |
| El freno de D8, con reloj inyectado | `app/Libraries/PlatformLoginThrottle.php` |
| Alta, confirmación y baja | `app/Controllers/PlatformTotp.php` + `app/Views/platform/accounts/totp*.php` |
| El reto al entrar | `PlatformLogin::totp()` + `app/Views/platform/login_2fa.php` |

**`spomky-labs/otphp` 11.5.0**, ya en `composer.lock` desde la Fase 0.

#### Las seis decisiones de implementación

1. **La ventana de ±1 período NO se pide con el `$leeway` de otphp.** Su margen está en segundos y
   no puede llegar a 30 (`$leeway < $period`), así que compara `at($t-29)`, `at($t)` y `at($t+29)`.
   En el último segundo de un período, `$t-29` cae **dentro del mismo contador** y el anterior nunca
   se comprueba: el mismo código de hace medio minuto se acepta o se rechaza según en qué segundo
   se pulse el botón. Aquí se calculan los tres contadores contiguos —`at($t-30)`, `at($t)`,
   `at($t+30)`— y el borde deja de existir.

2. **Secreto de 160 bits (20 bytes, 32 caracteres base32), no los 64 bytes que otphp genera por
   defecto.** Son los del RFC 4226 §4 (R6), los que emite todo el mundo y los que toda aplicación
   acepta. Sin QR, la clave **se teclea**: 104 caracteres serían una fuente de errores por sí solos.

3. **Sin librería de código QR, y sin añadir ninguna.** La pantalla muestra la clave en grupos de
   cuatro y la URI `otpauth://` copiable y como enlace.

4. **Diez códigos de rescate, no ocho.** El diseño decía «ocho o diez»; manda
   `PlatformAccount::RECOVERY_CODE_COUNT`, que la Fase 0 fijó en 10, y la cadena de idioma
   `Platform.recovery_codes_intro` ya dice «Diez códigos» y está congelada.

5. **El secreto viaja cifrado también en la sesión**, entre el POST que lo acuña y el que lo
   confirma. La sesión de la consola es la de base de datos (`platform_sessions`): en claro, el
   secreto quedaría legible en una segunda tabla que nadie mira.

6. **El freno de D8 se aplica también al reto del segundo factor**, sobre las mismas dos columnas.
   D8 cuenta sobre la cuenta, no sobre la pantalla; sin esto, seis dígitos con tres valores válidos
   a la vez son un espacio recorrible por quien ya pasó la contraseña.

#### Deuda anotada al cerrar

- **`PlatformAccount::login()` conserva su propia copia del freno.** Ese archivo estaba congelado en
  esta entrega, así que hay dos cuerpos de código para una sola regla. Coinciden hoy —las constantes
  se importan del modelo, no se copian—, pero el paso siguiente es borrar los métodos privados del
  modelo y delegar en la librería.
- **`PlatformLogin` repite la cadena `'platform_pending_account_id'`**, que en el modelo es una
  constante privada, para poder retirar la marca sin ascenderla. Su sitio natural es un
  `abandonSecondFactor()` en el modelo.
- **Tres cadenas de idioma prometen una imagen que no existe**: `totp_enroll_intro`,
  `totp_secret_help` y `totp_qr_alt` hablan de escanear un QR. Las vistas **no las usan**. Hay que
  reescribirlas, o añadir la librería de QR que describen.
- **Falta una cadena `Platform.copy`**, así que la pantalla de alta no tiene botón de copiar: los
  campos se seleccionan enteros con un clic.
- **Las tres pantallas de dentro de la consola extienden `platform/console_layout`**, que es de la
  otra mitad de la Entrega 2 y llega en su rama. Pasan `title` y `nav = 'totp'`. Mientras las dos
  ramas no se junten, las pruebas de `PlatformTotpEnrolmentTest` no pueden renderizar nada.
  **`platform/login_2fa.php` NO la extiende**, a propósito: en el reto la sesión solo está
  pendiente, y una barra de navegación cuyos cinco enlaces devuelven al mismo sitio no informa,
  confunde. Esa pantalla continúa la de entrada y comparte su marco.
- **`failed_login_first_at` sigue siendo un DATETIME sin zona.** La librería fija la zona al
  construirse y la usa para escribir y para leer, así que un cambio de `date_default_timezone_set()`
  a media petición no la mueve. Lo que eso no arregla es una fila escrita por el modelo bajo otra
  zona: eso solo lo cierra guardar UTC en la columna, que es un cambio de esquema.

#### Qué comprobar en la certificación

- El alta **con la aplicación real del teléfono del dueño**: que la clave tecleada funcione, que el
  enlace `otpauth://` abra la entrada rellena, y que la entrada se lea bien.
- Que un código de la ventana anterior (30 s) entre y uno de dos ventanas (60 s) no.
- Que el reloj del VPS tenga NTP: **TOTP no depende de la zona horaria, depende del reloj**.
- Que `totp_secret` en la base tenga la longitud del cifrado y **no esté truncado** en 512.

---

## 7. El registro de modificaciones (D6)

Se registran las modificaciones, no los accesos. Pero «modificación dentro del negocio de un cliente»
tiene **dos niveles con costos muy distintos**, y conviene separarlos antes de estimar.

### 7.1 Nivel 1 — lo que hace la consola

Cambios de configuración de un negocio, restablecer o consultar una contraseña, y el ciclo de vida
—alta, suspensión, reactivación, baja—. Se registra **en el propio controlador**, en una tabla nueva
de `platform_control`. Es completo, barato y no toca la base del cliente.

### 7.2 Nivel 2 — lo que se hace dentro del POS en una sesión de soporte

**OSPOS no tiene ninguna capa de auditoría general**, así que no hay dónde «engancharse» sin tocar
cada camino de escritura. La forma proporcionada es un **filtro `after`** que, cuando la sesión es de
soporte y la petición es `POST`, registre ruta, método e identificadores del cuerpo.

**Lo que eso da, y lo que no:** el registro dirá *"se tocó la pantalla de artículos sobre el artículo
1234"*, no *"el precio pasó de 12.000 a 14.000"*. Un diff real exigiría leer el estado antes y después
en cada camino de escritura, y eso es otro tamaño de trabajo — **si se quiere, hay que decidirlo
aparte y presupuestarlo aparte**.

### 7.3 La etiqueta «Soporte» (D10)

Es una capa de presentación sobre las filas que ya existen: donde una pantalla del cliente resuelve el
nombre de un empleado, si ese empleado tiene `is_platform_support = 1` se muestra «Soporte». No se
duplica el dato ni se cambia el modelo.

---

## 8. Los huecos, con su ubicación

| Hueco | Dónde tendría que vivir |
|---|---|
| CRUD de superadministradores | Controlador nuevo, `PlatformAccounts`; el modelo ya tiene `createAccount()` |
| Cambiar la propia contraseña y activar TOTP | Rutas nuevas + métodos en `PlatformAccount` |
| Último ingreso, fecha de alta, quién creó la cuenta | Migración aditiva sobre `platform_accounts` |
| ~~Perfil de configuración~~ | **Hecho y EN PRODUCCIÓN el 2026-09-01:** `App\Libraries\TenantConfigProfile`, aplicado desde `seedInitialAdmin()`. Escribe `app_config` y `employees` |
| ~~Candado de las tres claves de cableado~~ | **Hecho y EN PRODUCCIÓN el 2026-09-01:** `Wiring_lock` + `Config::refuse_wired_changes()` + las dos vistas del POS (§9.13) |
| Idioma heredado al crear un empleado | `Employees.php:183` y `:190`. **Sigue pendiente**, y hasta que exista el candado no está cerrado: un empleado puede ponerse otra variante en su perfil y el de `app_config` no lo alcanza |
| ~~Restablecer y consultar la clave del admin de un negocio~~ | **Hecho y EN PRODUCCIÓN el 2026-09-01:** `TenantProvisioner::adminCredential()` y `::resetAdminPassword()`, más `admin/detail.php` (§5.1) |
| ~~`company_name` en el listado~~ | **Hecho y EN PRODUCCIÓN el 2026-09-01:** migración `20260903000000` + `create()` + `admin/index.php` |
| Empleado de soporte | `TenantProvisioner::create()`, y un comando para los negocios existentes |
| `is_platform_support` | Migración sobre `employees` de cada tenant, `DEFAULT 0` |
| Vincular cuenta↔negocio | La tabla existe; falta pantalla y una columna de qué empleado es |
| ~~Nombre real en vez de "John Doe"~~ | **Hecho y EN PRODUCCIÓN el 2026-09-01:** en `seedInitialAdmin()`, junto al `update` de `employees` |
| Registro de modificaciones | Tabla nueva en `platform_control`, más el filtro `after` de §7.2 |
| Freno al eliminar un negocio | `PlatformAdmin::confirmDelete/delete` + `admin/confirm_delete.php` |

---

## 9. Trampas que este trabajo se va a encontrar

Escritas porque ya costaron tiempo en este proyecto. **Leerlas antes de empezar.**

### 9.1 La clave de cifrado es una dependencia viva
`tenants.db_password` se guarda cifrado. Hasta el 2026-08-31 **la clave de cifrado se regeneraba en
cada despliegue** y dejaba ilegible todo lo cifrado. Está arreglado (§8c de
`multi-tenant-arquitectura.md`), pero **este trabajo añade dos cosas cifradas más** —la contraseña
inicial del negocio y el secreto TOTP—, así que hereda esa dependencia por partida doble. La clave
vive en `.env` y la fija el entrypoint.

### 9.2 Hay dos sitios que lanzan migraciones, no uno
`TenantProvisioner::create()` y `scripts/migrate-tenants.sh`. Un defecto corregido en uno y no en el
otro **tumbó producción 7 minutos** el 2026-08-31 (§8d). Si se toca la provisión, revisar ambos.

> **Revisados los dos el 2026-09-01.** La Entrega 3 toca la provisión, así que tocaba mirar. **No
> hay nada que replicar en el script**, y la razón vale la pena escribirla: lo que se añadió al alta
> son escrituras *posteriores* a la migración —el perfil, el nombre de la persona, la credencial— y
> el script no aprovisiona, solo corre las migraciones del namespace `App` sobre negocios que ya
> existen. Las dos migraciones nuevas son del namespace **`Platform`**, que ese script no ejecuta
> nunca: van con `php spark platform:migrate` (§9.14) y **son un paso manual del despliegue**.
>
> Consecuencia deliberada, y es la misma que D13: **a los negocios existentes no les pasa nada**. Ni
> se les aplica el perfil ni se les guarda contraseña. Es lo correcto —Casaletto no se toca sin
> comparar clave por clave— pero significa que después de desplegar esto, Casaletto y Paraíso siguen
> exactamente igual, y su ficha dirá que de ellos no hay contraseña guardada.

### 9.3 El arranque es todo-o-nada
El entrypoint migra todos los negocios antes de que Apache atienda. Si uno falla, **ninguno atiende**.
Este módulo agrega migraciones sobre `employees` de cada tenant: es un riesgo de caída total.

### 9.4 Un Host sin negocio activo se rechaza, no cae a la base por defecto
Desde el 2026-08-31 (§4b). Si se agrega un estado nuevo de negocio, hay que decidir explícitamente qué
responde `TenantResolver`, o el negocio quedará inaccesible sin que nadie lo haya decidido.

### 9.5 Casaletto es un tenant adoptado
Su fila tiene `db_user` y `db_password` vacíos y cae a las credenciales compartidas. D3 exige que
aparezca en el listado **y sea gestionable**, así que cualquier código que escriba configuración en un
tenant tiene que soportar los dos caminos — o fallará justo en el negocio que está vendiendo.

### 9.6 `platform_provisioner` tiene permisos a propósito limitados
Su `GRANT` con comodín cubre solo esquemas `tenant_%` y **no tiene `RELOAD`**, así que no puede hacer
`FLUSH PRIVILEGES`. Comprobado empíricamente. Si hace falta un permiso nuevo, es un cambio deliberado
y hay que documentarlo.

### 9.7 Las pruebas y el esquema de plataforma
`phpunit.xml.dist` apunta el grupo `platform` **al mismo esquema de pruebas**, porque el usuario de
pruebas no puede crear bases. Su prefijo es vacío, así que su tabla `tenants` convive con las
`ospos_`. Ejemplo en `tests/Filters/TenantResolverTest.php`.

**Y dos trampas de las pruebas mismas**, que las hacen pasar sin probar nada:
- Bajo PHPUnit el framework está en CLI: `Services::request()` devuelve un `CLIRequest` cuyo
  `getServer()` siempre es `null`. Hay que construir un `IncomingRequest` a mano.
- Desde CodeIgniter 4.7 la petición lee su arreglo de servidor del servicio `superglobals`, que
  fotografía `$_SERVER` al construirse. Escribir en `$_SERVER` no cambia nada; usar
  `service('superglobals')->setServer(...)`.

### 9.8 No hay ninguna prueba del panel hoy
`PlatformAdmin`, `PlatformLogin` y `PlatformAccount` **no tienen cobertura**. Lo que se construya debe
traer la suya, y es buen momento para cubrir lo que ya existe.

### 9.9 Las traducciones van en es-MX
Una cadena escrita solo en `es-ES` es invisible: la pantalla sale en inglés y no da ningún error. Y
ojo: **Paraíso corre `es-ES`** (§2.2), así que hasta que se corrija, los dos negocios no ven las
mismas cadenas.

### 9.10 Eliminar un negocio hoy es una casilla, y el listado incluye a Casaletto

**Es un riesgo vivo, no uno que introduzca este diseño.** `PlatformAdmin::index()` lista *todos* los
tenants, Casaletto incluido. La pantalla de confirmación es una casilla `drop_schema` y un botón, y
`delete()` ejecuta:

```php
$provisioner->query("DROP USER IF EXISTS `{$tenant->db_user}`@'%'");
if ($dropSchema) {
    $provisioner->query("DROP DATABASE IF EXISTS `{$tenant->db_name}`");
}
```

Para Casaletto, `db_name` es `ospos`: **la base del negocio que está vendiendo, a dos clics y una
casilla.** Su `db_user` está vacío, así que el `DROP USER` podría abortar antes de llegar al
`DROP DATABASE` — pero eso sería una **protección accidental**, no un diseño, y no se puede confiar en
ella.

D3 exige que Casaletto siga apareciendo en el listado, así que la respuesta no es esconderlo:
- Confirmación **escribiendo el slug**, no una casilla.
- **Los tenants adoptados no se eliminan** desde la consola.
- Borrar el esquema exige una **confirmación aparte** y deja constancia en el registro.

**Estado: EN PRODUCCIÓN desde el 2026-09-01.** Certificado en el navegador sobre staging -- la
ficha de Casaletto no ofrece eliminar y lo dice-- y comprobado igual en producción.

| Dónde | Qué |
|---|---|
| `TenantProvisioner::isAdopted()` | Un tenant adoptado es el que tiene `db_user` vacío, que es justo lo que deja `adopt()` |
| `TenantProvisioner::delete()` | Lanza `RuntimeException` para un adoptado **antes de abrir la conexión de provisión** |
| `PlatformAdmin::confirmDelete()` | Carga la fila; 404 si el slug no existe; pasa a la vista si es adoptado |
| `PlatformAdmin::delete()` | `hash_equals()` contra el slug; el `drop_schema` exige además el `db_name`; cualquier fallo rechaza la operación **entera** |
| `PlatformAdmin` | `log_message('critical', ...)` en cada baja, cada borrado de esquema y cada rechazo, con quién, qué y cuándo, **antes** de la llamada destructiva |
| `platform/admin/index.php` | Los adoptados no llevan enlace de eliminar, y sí el motivo |
| `Language/en` y `Language/es-MX` | Las cadenas nuevas, en los dos idiomas (§9.9) |
| `tests/Libraries/TenantProvisionerDeleteTest.php` | El rechazo ocurre sin abrir la conexión; el control con credenciales inservibles demuestra que sí se abriría para un tenant normal |
| `tests/Controllers/PlatformAdminDeleteTest.php` | Sin slug, con slug mal escrito, adoptado, esquema sin nombrar y anónimo: no se borra nada |

La garantía se puso en la librería **además** de en el controlador porque `delete()` es la única
puerta al `DROP DATABASE`: un comando o una segunda pantalla tendrían que repetir cada uno una
comprobación que viviera solo en el controlador.

### 9.11 Son dos mundos de interfaz, y la Entrega 4 los cruza

La consola de plataforma es **Bootstrap 5** (`bootswatch5/flatly`), autónoma, sin la cáscara de OSPOS.
El punto de venta es **Bootstrap 3.4.1** con jQuery, `bootstrap-table` y glyphicons. Cada pantalla usa
el idioma del sitio donde vive y no se mezclan.

Esto importa porque **la Entrega 4 toca el lado del POS**: el formulario de entrada del negocio, que
pasa a aceptar dos orígenes de identidad, y el aviso permanente de sesión de soporte. Las dos cosas se
construyen en Bootstrap 3, no en 5.

### 9.12 Perder el teléfono y los códigos de rescate deja fuera de la plataforma

Con un solo superadministrador, TOTP sin códigos de rescate a mano significa que la única salida es
entrar a la base de datos. La mitigación ya está en la lista por otra razón —**tener dos
superadministradores reales**, que es lo que permite desbloquear tras los tres intentos fallidos— y
por eso eliminar la cuenta huérfana debe ir acompañado de **crear una segunda cuenta real**, no de
quedarse con una sola.

### 9.13 El candado de las tres claves no vive en la consola, vive en el POS

D12 bloquea `quantity_decimals`, `barcode_content` y `language_code`. Pero **el propio negocio puede
cambiarlas desde su pantalla de configuración**, no desde la consola:

| Clave | Dónde la escribe el cliente |
|---|---|
| `language_code` | `Config.php:498` — vista `configs/locale_config.php` |
| `quantity_decimals` | `Config.php:507` — vista `configs/locale_config.php` |
| `barcode_content` | `Config.php:924` — vista `configs/barcode_config.php` |

Bloquearlas solo en la consola sería un candado decorativo. **El bloqueo tiene que estar en
`Config.php`**, rechazando el cambio del lado del servidor, y la vista debe mostrar el campo
deshabilitado con el motivo —esconderlo haría que el cliente crea que el ajuste no existe.

Esto arrastra dos cosas: se construye en **Bootstrap 3** (§9.11) y **toca el punto de venta**, así que
necesita la misma compuerta de pruebas que cualquier cambio que afecte a Casaletto.

#### Cómo quedó construido (escrito el 2026-09-01; EN PRODUCCIÓN desde 2026-09-01, certificado en staging sobre la interfaz real)

La regla vive en **`app/Libraries/Wiring_lock.php`**, una clase sin estado, sin base de datos y sin
vista: solo la lista de claves con su valor obligatorio y la comparación. Se aplica desde dos sitios,
y en ninguno de los dos se repite la lista.

| Dónde | Qué hace |
|---|---|
| `Config::refuse_wired_changes()` | Rechaza la petición del lado del servidor. Lo llaman `postSaveLocale()` y `postSaveBarcode()` antes de tocar nada |
| `configs/locale_config.php`, `configs/barcode_config.php` | Muestran el campo `disabled`, con el motivo en un `help-block` y `aria-describedby` |

**La regla, en una frase: una clave bloqueada puede quedarse donde está, o moverse al valor que la
plataforma exige. Cualquier otra cosa se rechaza.**

La segunda mitad de esa frase no es un camino por la interfaz —los campos van deshabilitados, así que
nada de lo que el cliente pueda pulsar produce ninguno de los dos movimientos—. Es la salida de
emergencia: sin ella, un negocio dado de alta **antes** de que existiera el perfil se quedaría
atrapado para siempre en el valor peligroso, y el candado terminaría cementando exactamente lo que
existe para impedir. La semilla de `initial_schema.sql` trae `quantity_decimals = 0` y
`barcode_content = id`, que son justo los dos valores que costaron los incidentes.

**Se rechaza la petición entera, no la clave sola.** `Appconfig::batch_save()` guarda las quince
claves de cada pantalla en una sola transacción y no tiene ninguna noción de clave protegida. Dejar
pasar las otras catorce e ignorar la bloqueada obligaba a elegir entre dos mentiras: un mensaje verde
sobre una pantalla que no hizo lo que dice, o un guardado a medias sin forma de contarlo. Rechazar en
bloque no le cuesta nada a un cliente honesto, porque **un cliente honesto no puede llegar ahí**: el
campo va deshabilitado y un campo deshabilitado no se envía. Lo que sí llega es un POST hecho a mano,
y contra eso el candado tiene que ser ruidoso.

**Qué NO se tocó, a propósito:**

- **`Appconfig::batch_save()` no lleva el candado dentro.** El modelo es también por donde la
  plataforma escribe la configuración de un tenant al darlo de alta; un candado ahí abajo dejaría
  fuera al único que sí debe fijar estas claves. El candado va en la frontera que alcanza el
  *cliente*, que son los dos endpoints del controlador.
- **No repara nada.** Un negocio que ya esté en el valor equivocado se queda como está; lo que hace
  la pantalla es **decirlo en voz alta** (`Config.wired_setting_mismatch`) en vez de congelarlo en
  silencio. Escribir el valor correcto sobre un negocio que está vendiendo es una operación revisada
  clave por clave (D13), no un efecto colateral de que alguien guarde otra pestaña.

**Tres trampas que había que sortear, y cómo:**

1. **`language_code` no es un campo del formulario.** Sale de partir por `:` el valor del select
   `language`, que viaja como `codigo:nombre`. El candado se aplica al código derivado, no a un
   nombre de campo; si no, elegir «Español (España)» pasaba de largo. Como consecuencia asumida,
   `language` —que D12 deja al negocio— queda congelado también: es el mismo select, y todas las
   opciones que dicen `spanish` llevan un código distinto.
2. **Un campo `disabled` no se envía.** Si «ausente» contara como cambio, cada guardado honesto de
   esas dos pantallas quedaría rechazado; y si contara como «vacío», guardar la pestaña de idioma
   dejaría `language_code` en blanco y todas las pantallas en inglés. Ausente significa «la pantalla
   no lo ofreció», y esas claves sencillamente no se escriben.
3. **El valor actual se lee de `app_config`, no de la caché de configuración.** La caché puede ir por
   detrás de un valor que la plataforma escribiera directo en la base del tenant, y comparar contra
   una copia vieja dejaría que esta pantalla devolviera el valor anterior sin que nadie se enterara.

**Un arreglo que salió de paso.** El radio de `barcode_content` valía `number` y se marcaba
comparando contra `number`, pero `Barcode_lib` lee **todo lo que no sea `id`** como número de
artículo (`Barcode_lib.php:97` y `:183`). Un negocio en `item_number` —el valor que exige D12, y el
que tiene Paraíso desde la colisión— veía **ningún radio marcado**. Ahora el radio vale
`item_number` y se marca por `!== 'id'`.

**Cadenas nuevas** en `Config.php` de `en` y `es-MX`: `wired_setting_refused`,
`wired_setting_mismatch` y una explicación por clave (`wired_quantity_decimals_help`,
`wired_barcode_content_help`, `wired_language_help`). Van en el archivo de idioma del punto de venta,
no en `Platform.php`.

**Lo que queda abierto:** si un negocio está en el valor equivocado, la única salida por pantalla es
que alguien envíe el valor exigido a `/config/saveLocale` o `/config/saveBarcode`. **La consola de
plataforma todavía no tiene una pantalla que fije estas tres claves sobre un negocio ya existente**,
y sin ella el aviso de la pantalla del cliente no tiene a quién mandarlo. Es trabajo del otro carril.

### 9.14 El historial de migraciones de plataforma vivía en la base de Casaletto

> **Corregido el 2026-08-31.** Se conserva el diagnóstico porque la causa sigue viva en el framework y
> volverá a morder a quien use el comando de siempre.

Verificado en producción: **no existía `platform_control.migrations`**. Las cuatro migraciones del
namespace `Platform` estaban registradas en **`ospos.ospos_migrations`**, la base de Casaletto, con
`group = 'platform'`. Paraíso tenía cero filas de ese namespace. Staging estaba igual.

La causa está en el framework y no se puede configurar: `MigrationRunner::latest()` llama a
`ensureTable()` **antes** que a `setGroup()`, y `$this->db` se resolvió una sola vez en el constructor
a partir del **grupo por defecto** (`MigrationRunner.php:150-156`). El `-g platform` dirige el DDL de
cada migración, nunca el historial.

Tres consecuencias, que es lo que se corrigió:

1. **`platform_control` no era autocontenido.** Restaurar la base de Casaletto de un respaldo anterior
   se habría llevado por delante el historial de migraciones de la plataforma.
2. **Correr el comando desde otro contexto era peligroso.** Si la conexión por defecto apuntaba a otro
   esquema —porque `TenantResolver` la reapuntó, o porque se pasó `MYSQL_DB_NAME`— el runner no veía
   el historial, daba las cuatro por pendientes e **intentaba volver a crear `tenants`,
   `platform_accounts` y `platform_account_tenants`**.
3. **La plataforma dependía de la base de un cliente**, justo lo contrario de lo que persigue el
   aislamiento por esquema.

#### El arreglo: dos comandos

| Comando | Qué hace |
|---|---|
| `php spark platform:migrate` | Corre el namespace `Platform` **pasando la conexión de plataforma al runner**, así que `ensureTable()` y todo el historial caen en `platform_control`. Devuelve 0/1 de verdad, a diferencia del `migrate` de serie |
| `php spark platform:adopt-history` | Paso único por ambiente: importa a `platform_control` las filas que ya existen en el esquema del cliente |

`platform:migrate` **se niega a correr** si detecta las tablas de plataforma sin historial propio, en
vez de intentarlo y fallar en el `CREATE TABLE`. Es el mismo criterio de `TenantProvisioner::adopt()`:
comprobar y rechazar antes que apañárselas.

**La adopción no borra las filas del esquema del cliente**, a propósito y por dos razones: escribir en
la base de un cliente es justo lo que este módulo quiere dejar de hacer, y esas filas son la red que
impide que un `php spark migrate -n Platform -g platform` hecho por costumbre vuelva a crear las
tablas — las lee, las ve aplicadas y no hace nada.

**A partir de ahora, toda migración de plataforma se corre con `platform:migrate`.** El comando de
serie con `-g platform` queda desaconsejado: funciona, pero escribe el historial donde no debe.

Pruebas: `tests/Commands/PlatformMigrationHistoryTest.php`.

### 9.15 El paquete nuevo de Composer no viaja en el repositorio

La Entrega 2 añade la primera dependencia de Composer desde que existe el despliegue actual:
`spomky-labs/otphp` (fijada en `^11.3`, resuelta a 11.5.0), para verificar los códigos TOTP.

Dos hechos que hay que leer juntos:

- **`vendor/` sí entra en la imagen.** El `Dockerfile` es un `COPY . /app` y `vendor/` no está en
  `.dockerignore`.
- **`composer.json` y `composer.lock` NO entran.** Los dos están en `.dockerignore`. Dentro de la
  imagen no hay forma de instalar nada: lo que haya en `vendor/` **en el servidor** en el momento
  del build es lo único que existirá.

`.github/workflows/deploy-staging.yml` y `deploy-production.yml` **ya lo hacen bien**: corren
`composer install --no-dev --optimize-autoloader` dentro de un `php:8.4-cli` sobre el directorio del
VPS **antes** del `docker compose up --build`. Por el despliegue automático no hay nada que cambiar.

**Donde muerde es en un despliegue manual.** Un `docker compose up --build` a mano —el mismo caso
que ya avisa `AGENTS.md` para los assets— construiría una imagen con el `vendor/` viejo, y la
primera pantalla que instancie `OTPHP\TOTP` moriría con «Class not found» en un HTTP 500, sin que
ningún smoke test que solo mire el código de estado de la portada lo note.

**Regla:** cualquier despliegue manual de la Entrega 2 corre `composer install --no-dev
--optimize-autoloader` en el VPS **antes** del build, igual que el `npm run build`.

Y una nota sobre versiones de PHP, porque en esta máquina no es evidente: el repositorio declara
`php: ^8.2`, CI corre 8.2, 8.3 y 8.4, y el VPS instala con `php:8.4-cli`. **El host de desarrollo
tiene PHP 8.5**, y `sabberworm/php-css-parser` (una dependencia transitiva de `dompdf`) tiene tope
`~8.4.0`, así que `composer install` falla en local si no se le pasa `--ignore-platform-req=php+`.
No es un problema del paquete nuevo: `otphp` y sus tres dependencias (`paragonie/constant_time_encoding`,
`psr/clock`, `symfony/deprecation-contracts`) no ponen tope superior de PHP, y se comprobó que el
conjunto resuelve e instala limpio fijando `platform.php = 8.4.0`.

---

### 9.16 Una prueba que escribe `app_config` rompe pruebas de otro archivo

Encontrado el 2026-09-01, después de que la suite pasara una mañana entera en rojo con siete fallos.
**Ninguno estaba donde parecía.**

`ConfigWiringLockTest` hace POST de verdad contra `/config/saveLocale`, que escribe `app_config`. Toda
la suite comparte una sola base y `$refresh` está en falso --como en el resto de la casa--, así que el
idioma, los decimales y el país que dejaba puestos se los encontraban las pruebas que corrieran
después. `CustomersCsvImportTest` empezó a recibir sus mensajes en español y `ExpensesCashSourceTest`
a leer un origen de efectivo nulo: **dos archivos del punto de venta, rojos por un cambio que no los
tocaba.** Un fallo así se busca durante horas en el sitio equivocado, porque el archivo culpable pasa.

La regla, para cualquier prueba que llame a un endpoint que escriba configuración:

```php
protected function setUp(): void
{
    parent::setUp();
    // La tabla entera, no las claves que uno cree que toca: enumerarlas vuelve a fallar
    // en cuanto alguien añade una.
    foreach ($this->db->table('app_config')->get()->getResultArray() as $fila) {
        $this->configuracionOriginal[$fila['key']] = $fila['value'];
    }
}
```

y devolverla entera en `tearDown()`, borrando además las claves que no existían antes.

**Y un hash sembrado nunca puede compararse con `hash_equals()` contra otro hash.**
`TenantProvisionerCredentialTest` sembraba `admin_password_hash` con un segundo `password_hash()` de
la misma contraseña. bcrypt sala cada llamada, así que las dos cadenas siempre difieren y el estado
salía `changed` desde el primer instante: la prueba central del archivo --«deja de mostrarse en cuanto
el cliente la cambia»-- pasaba en verde **sin ejercitar nada**. Se siembra el hash real de la fila de
`employees`, no otro de la misma palabra.

**Y el texto de una excepción termina en `writable/logs/`.** Poner ahí la contraseña generada la
escribía en claro y para siempre; peor aún, en producción el operador ve la pantalla de error genérica
y no la leería nunca. Lo que hay que enseñar una sola vez va por `flashdata`, que se consume al
leerse, nunca por una excepción ni por el registro de actividad, que se guarda para siempre.

### 9.17 El texto de una excepción es interfaz de usuario

`PlatformAdmin` y `PlatformAccounts` muestran `$e->getMessage()` tal cual en el aviso rojo de la
consola. Eso convierte cada `RuntimeException` del aprovisionador en una cadena que lee el operador,
no un programador —y estuvieron en inglés dentro de una consola en español hasta el 2026-09-01.

Se vio en producción, el mismo día del despliegue: al restablecer con un usuario que no existía, la
pantalla contestó *«Business 'casaletto' has no employee with the username 'admin'»*.

**La regla:** todo `throw` en un camino que llegue a una pantalla saca su texto de
`lang('Platform.error_*')`. Las excepciones declaradas, y son solo dos:

| Dónde | Por qué se queda en inglés |
|---|---|
| `TenantProvisioner::adopt()` | No tiene pantalla. Se corre con `php spark tenant:adopt`, por SSH y por nosotros; quien lo lee está en una terminal |
| `setStatus()` | El controlador solo le pasa literales (`'suspended'` / `'active'`), así que un estado inválido es un error de programación que no puede llegar por pantalla |

`tests/Language/PlatformErrorStringsTest.php` lo vigila: exige que las dos listas de idioma lleven las
mismas claves, que todas resuelvan, que las que nombran un negocio lo interpolen de verdad, y que
nadie vuelva a lanzar texto escrito a mano fuera de esas dos excepciones —que van nombradas en la
prueba, para que sean una decisión y no un hueco.

**Y las comillas simples de ICU.** En MessageFormat la comilla simple ESCAPA lo que viene detrás, así
que `'{0}'` se imprime literal y no sustituye nada. Las doce cadenas inglesas escritas ese día lo
llevaban, y habrían dicho `Slug '{0}' is reserved` en pantalla. Lo encontró la prueba de arriba
mientras se escribía. Para entrecomillar dentro de una cadena con huecos: «» o "", nunca ''.

**Y no se afirma sobre el texto.** Cinco pruebas comprobaban el rechazo buscando «adopted» o
«not found» en el mensaje, y se pusieron rojas al traducir sin que cambiara nada de lo que
comprueban. Se compara contra `lang('Platform.error_*')`, que dice lo mismo en cualquier idioma.

## 10. Orden de implementación

Coincide con las cinco entregas del funcional.

1. **La casa propia.** Regla de proxy para la raíz, tratamiento explícito en `TenantResolver`, tabla
   de sesiones en `platform_control`, rutas acotadas por host, redirección de la URL vieja. No toca la
   provisión: el menor riesgo, y quita superficie de ataque. **Incluye el freno de §9.10**, que es lo
   único de esta entrega que corrige un riesgo ya existente.
2. **Superadministradores + TOTP + registro de actividad** (§7.1), **eliminar la cuenta huérfana** y
   **crear una segunda cuenta real** — ver §9.12. No toca la provisión ni el arranque.

   > **Estado a 2026-08-31: los cimientos escritos, sin pantallas y sin certificar.** Nada de esto
   > se ha desplegado ni se ha probado contra una base de datos: en la máquina donde se escribió no
   > había MariaDB levantada. Las pruebas están escritas y **no se han ejecutado**.
   >
   > Se cerraron primero los archivos que las dos mitades siguientes tendrían que editar a la vez
   > —rutas, claves de idioma, migraciones, `composer.lock`—, para que ninguna de las dos tenga que
   > tocarlos. Lo que queda de la Entrega son las pantallas y sus controladores.

   | Dónde | Qué quedó escrito |
   |---|---|
   | `app/Platform/Database/Migrations/20260902000000_AddAccountLifecycleToPlatformAccounts.php` | `last_login_at`, `created_by_account_id` (NULL = creada desde la terminal, la señal que delata a la huérfana), `failed_login_count`, `failed_login_first_at` |
   | `…20260902000001_AddTotpToPlatformAccounts.php` | `totp_secret VARCHAR(512)` y `totp_enabled_at`. 512 y no 255: lo que se guarda es el cifrado, y ahí es donde se truncó `tenants.db_password` |
   | `…20260902000002_CreatePlatformAccountRecoveryCodes.php` | Tabla propia, no una columna JSON: el «un solo uso» tiene que ser un `UPDATE … WHERE used_at IS NULL` con `affectedRows() === 1` |
   | `…20260902000003_CreatePlatformActivityLog.php` | El registro de §7.1, con `account_email` **denormalizado** para que la fila que dice quién eliminó la cuenta huérfana siga siendo legible |
   | `app/Models/PlatformAccount.php` | `login()` devuelve `PlatformLoginResult` (cuatro desenlaces) en vez de `?object`; `countAdmins()`, `changePassword()`, `deleteAccount()`, `unlock()`, `touchLastLogin()`, códigos de rescate, y el freno de D8 |
   | `app/Models/PlatformActivity.php` | `record()` y las doce acciones de D6 como constantes |
   | `app/Controllers/Platform_Controller.php` | Base de la consola: guarda de administrador, guarda de «TOTP pendiente», `currentAccount()`, `logActivity()`, locale |
   | `app/Config/Routes.php` | **Todas** las rutas de la Entrega, de una vez |
   | `app/Language/{en,es-MX}/Platform.php` | **Todas** las claves, en los dos idiomas, con una prueba que compara los dos archivos |
   | `composer.json` / `composer.lock` | `spomky-labs/otphp ^11.3` → 11.5.0. Leer **§9.15** antes de desplegar |

   **Las migraciones se corren con `php spark platform:migrate`**, nunca con el `migrate` de serie
   (§9.14). Son cuatro y no una para que el freno de intentos pueda desplegarse aunque el TOTP se
   retrase, y para que cada `down()` revierta una sola preocupación.

   **Una decisión que la pantalla de entrada tiene que respetar:** `login()` distingue «bloqueada»
   de «credenciales inválidas» porque el controlador necesita esa diferencia —registra
   `account.locked` justo cuando el contador salta—, pero **las dos se muestran con el mismo
   mensaje**. Una dirección que conteste «demasiados intentos» mientras otra contesta «contraseña
   incorrecta» acaba de confirmar que existe, y D8 lo prohíbe. `Platform.invalid_credentials`
   nombra el freno de dos horas, así que es cierto en los dos casos.
3. **Perfil de configuración, ficha del negocio, `company_name`, "John Doe", consultar/restablecer la
   clave.** Toca `TenantProvisioner`: releer §9.2 y §9.5 antes. El perfil escribe en **los dos sitios
   del idioma** (§2.4), y el **candado de §9.13 va del lado del POS**, no de la consola.
4. **Entrada a un negocio**: `is_platform_support`, empleado de soporte, doble origen de identidad en
   el login del negocio, aviso en pantalla, registro de nivel 2 (§7.2). Se construye en **Bootstrap 3**,
   no en 5 — ver §9.11. **Requiere la cuenta huérfana ya eliminada.**
5. **Vínculo cuenta↔negocio.** El único que toca el camino de entrada de un usuario final; va último.

---

## 11. Cobertura de pruebas

Lo mínimo que debería traer este trabajo:

- No se puede eliminar el último superadministrador, ni eliminarse a sí mismo.
- Tres intentos fallidos bloquean; el cuarto tras la ventana de dos horas entra.
- Un código TOTP válido entra; uno de la ventana anterior con más de una deriva, no.
- Un código de rescate sirve **una sola vez**.
- La contraseña inicial se consulta mientras el hash no cambie, y deja de verse cuando cambia.
- Un negocio recién creado tiene las claves del perfil con el valor correcto, **en `app_config` y en
  el empleado inicial**.
- Un empleado creado después del alta hereda el idioma del perfil, no queda vacío.
- **Un POST a la configuración del negocio que intente cambiar las tres claves bloqueadas se rechaza**,
  y las otras claves del perfil sí se dejan cambiar. *(Escrito el 2026-09-01, sin ejecutar:
  `tests/Libraries/WiringLockTest.php` para la regla, `tests/Controllers/ConfigWiringLockTest.php`
  para los dos endpoints —incluido el intento por `language` con formato `codigo:nombre`— y
  `tests/Views/WiredSettingsViewTest.php` para que el campo se vea, se vea fijo y diga por qué.)*
- El empleado de soporte **no aparece** en la grilla de empleados ni en el selector de reportes.
- `platform/*` responde en la raíz y **no** en un subdominio de negocio.
- El login de un negocio acepta una credencial de plataforma y una de `employees`, y rechaza la de
  plataforma de otro entorno.
- **Eliminar un negocio sin escribir bien el slug no borra nada**, y un tenant adoptado se rechaza.

### 11.1 Lo que la Entrega 3 dejó escrito (2026-09-01; **escrito, sin ejecutar**)

| Archivo | Qué fija |
|---|---|
| `tests/Libraries/TenantConfigProfileTest.php` | El perfil escrito contra un OSPOS de verdad: las tres claves de cableado, el resto, `country_codes = co`, que `tax_included` **no se toca**, que el empleado nace en `es-MX`, que una clave ausente se inserta, y que aplicarlo dos veces da lo mismo |
| `tests/Libraries/TenantProvisionerInitialAdminTest.php` | Que el administrador ya no se llama «John Doe» sino como el negocio, que la credencial sembrada de Casaletto se reemplaza, que el hash devuelto es el escrito, y que el perfil lo aplica el alta |
| `tests/Libraries/TenantProvisionerCredentialTest.php` | D5 entero: se ve mientras el hash coincide, **deja de verse y la copia se borra** cuando el cliente la cambia, un usuario que ya no existe cuenta como cambiada, y el restablecimiento escribe una contraseña que de verdad abre. Corre **contra un negocio adoptado**, que es el camino de Casaletto |
| `tests/Database/TenantRegistryColumnsMigrationTest.php` | Las columnas nuevas, que son nullable, que el cifrado **cabe** (metiéndolo por la base y releyéndolo), y que las dos migraciones se deshacen |

Estas pruebas **no se han ejecutado**: se escribieron en una máquina sin la base de datos de pruebas
levantada. Ejecutarlas y certificar sobre la interfaz desplegada es un paso aparte, y no lo firma
quien escribió el código.

**Lo que ninguna prueba cubre y hay que mirar a mano en staging:** que `create()` completo funcione
—empieza por `CREATE DATABASE` y el usuario de pruebas no tiene ese permiso—, es decir, que un
negocio creado desde la consola nazca con su esquema, su fila en `tenants` con nombre y credencial,
y su configuración aplicada.

---

## 12. Compuerta antes de producción

La misma que rige todo este repositorio, sin excepciones:

- Suite completa verde contra MariaDB real.
- Probado en staging **con los dos negocios montados**.
- Verificación en producción **en solo lectura**: conteos y códigos de estado, nunca transacciones de
  prueba.
- Despliegue **después de las 22:00 hora Colombia**, salvo autorización explícita en el momento.
- **Casaletto se comporta idénticamente.** Es el negocio que está vendiendo.
- Y, por la política de ramas: todo despliegue manual por SSH termina avanzando `master` al mismo
  commit que quedó en el servidor.
