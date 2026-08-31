# Diseño técnico — Gestión de la plataforma y de los negocios-cliente

> **Estado a 2026-08-31: requerimiento planteado, sin escribir una línea de código.**
>
> Alcance de negocio en `docs/Funcional/gestion-de-plataforma-y-negocios.md`. **Leerlo primero**:
> trae cinco decisiones abiertas (§4) que cambian lo que se construye.
>
> **Se trabaja en paralelo, en otra conversación.** Este documento tiene que bastar para tomarlo en
> frío. La otra línea de trabajo —el cliente supermercado, venta por peso y hardware— vive en
> `docs/*/venta-por-peso-y-hardware-de-caja.md` y **no se toca desde aquí**.

---

## 1. Mapa de lo que ya existe

Todo verificado leyendo el código, no de memoria.

| Archivo | Qué hace |
|---|---|
| `app/Controllers/PlatformAdmin.php` | Panel. `index`, `newTenant`, `create`, `suspend`, `activate`, `confirmDelete`, `delete` |
| `app/Controllers/PlatformLogin.php` | `index` (entrar), `selectIndex`, `select`, `logout` |
| `app/Models/PlatformAccount.php` | `login`, `logout`, `isLoggedIn`, `getLoggedInAccount`, `isPlatformAdmin`, `getTenantsForAccount`, `createAccount` |
| `app/Libraries/TenantProvisioner.php` | `create`, `adopt`, `setStatus`, `delete` — la lógica real |
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
**No hay `company_name`** — decisión 4.2 del funcional.

**La protección del panel es una sola línea**, en el constructor:

```php
if (!$this->account->isPlatformAdmin()) {
    throw new RedirectException('platform/login');
}
```

---

## 2. Cómo nace hoy el usuario de un negocio

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
administrador de otro cliente. Está documentado en `docs/Tecnico/multi-tenant-arquitectura.md` §16.

**Lo que NO reemplaza:** la fila de `people`. El administrador del negocio nuevo se llama
**"John Doe"**, verificado en Paraíso de la Canasta.

---

## 3. Los huecos, con su ubicación

| Hueco | Dónde tendría que vivir |
|---|---|
| CRUD de superadministradores | Controlador nuevo, `PlatformAccounts`; el modelo ya tiene `createAccount()` |
| Cambiar la propia contraseña | Ruta nueva + método en `PlatformAccount` |
| Restablecer la clave del admin de un negocio | `TenantProvisioner`; la lógica ya está dentro de `create()` y hay que extraerla |
| `company_name` en el listado | Migración aditiva sobre `platform_control.tenants` + `create()` + vista `admin/index.php` |
| Vincular cuenta↔negocio | La tabla existe; falta pantalla. `getTenantsForAccount()` ya consulta bien |
| Nombre real en vez de "John Doe" | `TenantProvisioner::create()`, en el mismo `update` que ya toca `employees` |

---

## 4. Trampas que este trabajo se va a encontrar

Escritas porque ya costaron tiempo en este proyecto. **Leerlas antes de empezar.**

### 4.1 La contraseña de un tenant se cifra, y la clave de cifrado es frágil
`tenants.db_password` se guarda cifrado. Hasta el 2026-08-31 **la clave de cifrado se regeneraba en
cada despliegue** y dejaba ilegible todo lo cifrado. Está arreglado (§8c de
`multi-tenant-arquitectura.md`), pero cualquier cosa que se agregue y se cifre hereda esa
dependencia: **la clave vive en `.env` y la fija el entrypoint**.

### 4.2 Hay dos sitios que lanzan migraciones, no uno
`TenantProvisioner::create()` y `scripts/migrate-tenants.sh`. Un defecto corregido en uno y no en el
otro **tumbó producción 7 minutos** el 2026-08-31 (§8d). Si se toca la provisión, revisar ambos.

### 4.3 El arranque es todo-o-nada
El entrypoint migra todos los negocios antes de que Apache atienda. Si uno falla, **ninguno atiende**.
Está en el backlog revisarlo (§6c del funcional). Mientras tanto, cualquier cambio que pueda dejar un
esquema inalcanzable es un riesgo de caída total.

### 4.4 Un Host sin negocio activo se rechaza, no cae a la base por defecto
Desde el 2026-08-31 (§4b). Si se agrega un estado nuevo de negocio —más allá de `active` y
`suspended`— hay que decidir explícitamente qué responde `TenantResolver`, o el negocio quedará
inaccesible sin que nadie lo haya decidido.

### 4.5 `platform_provisioner` tiene permisos a propósito limitados
Su `GRANT` con comodín cubre solo esquemas `tenant_%` y **no tiene `RELOAD`**, así que no puede hacer
`FLUSH PRIVILEGES`. Comprobado empíricamente, no supuesto. Si hace falta un permiso nuevo, es un
cambio deliberado y hay que documentarlo.

### 4.6 Las pruebas y el esquema de plataforma
`phpunit.xml.dist` apunta el grupo `platform` **al mismo esquema de pruebas**, porque el usuario de
pruebas no puede crear bases. Su prefijo es vacío, así que su tabla `tenants` convive con las
`ospos_`. Ejemplo de uso en `tests/Filters/TenantResolverTest.php`.

**Y dos trampas de las pruebas mismas**, que las hacen pasar sin probar nada:
- Bajo PHPUnit el framework está en CLI: `Services::request()` devuelve un `CLIRequest` cuyo
  `getServer()` siempre es `null`. Hay que construir un `IncomingRequest` a mano.
- Desde CodeIgniter 4.7 la petición lee su arreglo de servidor del servicio `superglobals`, que
  fotografía `$_SERVER` al construirse. Escribir en `$_SERVER` no cambia nada; usar
  `service('superglobals')->setServer(...)`.

### 4.7 No hay ninguna prueba del panel hoy
`PlatformAdmin`, `PlatformLogin` y `PlatformAccount` **no tienen cobertura**. Lo que se construya
debería traer la suya, y sería buen momento para cubrir lo que ya existe.

---

## 5. Orden sugerido de implementación

Coincide con las tres entregas del funcional, y está ordenado para que cada paso deje el sistema
mejor que antes aunque el siguiente se demore.

1. **CRUD de superadministradores + cambio de contraseña propia.** No toca la provisión ni el
   arranque, así que es el de menor riesgo. Permite cerrar la cuenta huérfana.
2. **Restablecer la clave de un negocio + `company_name` + nombre real.** Toca
   `TenantProvisioner`: releer §4.2 antes.
3. **Vincular cuenta↔negocio y hacer funcionar la selección de negocio.** Es el único que toca el
   camino de entrada de un usuario final; va último a propósito.

---

## 6. Compuerta antes de producción

La misma que rige todo este repositorio, sin excepciones:

- Suite completa verde contra MariaDB real.
- Probado en staging **con los dos negocios montados**.
- Verificación en producción **en solo lectura**: conteos y códigos de estado, nunca transacciones
  de prueba.
- Despliegue **después de las 22:00 hora Colombia**, salvo autorización explícita en el momento.
- **Casaletto se comporta idénticamente.** Es el negocio que está vendiendo.
