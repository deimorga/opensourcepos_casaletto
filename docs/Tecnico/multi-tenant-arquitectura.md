# Arquitectura técnica: multi-tenant (schema-por-negocio)

> **Documento vivo.** Se actualiza al cierre de cada fase si algo cambia respecto a lo aquí descrito. Ver también el documento funcional: `docs/Funcional/multi-tenant-multi-negocio.md`. Plan de implementación original: `/Users/deibymorenogarcia/.claude/plans/bueno-empieza-a-generar-soft-metcalfe.md`.

## 1. Estado de partida (auditoría)

El código es 100% single-tenant:
- Una sola conexión de BD fija por variables de entorno (`app/Config/Database.php:119-141`, lee `MYSQL_HOST_NAME`/`MYSQL_USERNAME`/`MYSQL_PASSWORD`/`MYSQL_DB_NAME` una sola vez en el constructor).
- Login sin ningún concepto de empresa (`app/Models/Employee.php::login()`, `app/Controllers/Secure_Controller.php:36-78`).
- Configuración global de una sola fila por clave para toda la instalación (`ospos_app_config`, cacheada en `app/Config/OSPOS.php:33,52,73` bajo la clave literal `'settings'`).
- `app/Config/Filters.php:110` tiene `$filters = []` vacío — no existe ningún filtro custom hoy.
- Ya existe el concepto de "sede" (`stock_locations`), pero con huecos: `sales` (cabecera), `cash_up`, `expenses`, `receivings` (cabecera) y `dinner_tables` no tienen `location_id`, a diferencia de `item_quantities`, `inventory`, `permissions`, `sales_items`.

## 2. Decisión de arquitectura

**Aislamiento por schema-por-tenant**: un único servidor MariaDB, un schema por negocio, en vez de una base de datos compartida con columna `tenant_id`.

**Por qué**: con ~40 Models existentes sin ninguna disciplina de scoping (CodeIgniter 4 no tiene "global scopes" nativos como otros frameworks), forzar `tenant_id` en cada query es alto riesgo de fuga de datos financieros entre negocios. El aislamiento físico de schema evita esa clase de bug por diseño, y requiere cambios mínimos al código de negocio ya existente y probado — la mayoría de los Models no se tocan.

**No es "un contenedor de MySQL por tenant"**: a la escala esperada (10-100 negocios), un solo servidor MariaDB con N schemas es suficiente y evita el overhead de una instancia de BD por cliente.

## 3. Topología

- **Un solo contenedor de app** (CodeIgniter) sirve a todos los tenants. Un solo deploy actualiza el código para todos simultáneamente — no hay deploy por negocio.
- **Un solo servidor MariaDB**, con un schema por tenant + un schema de control (`platform_control` o similar) para el registro de tenants y las cuentas de plataforma.
- **Traefik con una regla wildcard** (`Host(\`{tenant:[a-z0-9-]+}.midominio.com\`)`) apuntando al servicio de app único — dar de alta un negocio no toca Traefik ni la infraestructura.

## 4. Dos grupos de conexión de BD

- **`platform`**: fijo, siempre apunta al schema de control, configurado vía sus propias variables de entorno. Aquí viven `tenants`, `platform_accounts`, `platform_account_tenants`.
- **`default`**: el grupo que ya usan los ~40 Models existentes sin modificarlos (ninguno especifica `$DBGroup` explícito). Se sobreescribe dinámicamente en cada request según el tenant resuelto.

## 5. Resolución de tenant (el punto técnico más delicado)

### 5.1 Por qué el orden de ejecución importa

`Config\Session::__construct()` (`app/Config/Session.php:133-149`) llama a `Database::connect()` apenas se instancia, para comprobar si existe la tabla de sesiones. La instanciación de `Config\Session` ocurre la primera vez que algo llama al helper `session()` — típicamente dentro de `Secure_Controller` (`app/Controllers/Secure_Controller.php:56`). Esto significa que **la resolución de tenant debe ocurrir antes de que cualquier cosa llame a `session()`**, o la sesión se conecta al schema equivocado.

### 5.2 Mecanismo

Un Filter nuevo de CI4 (`app/Filters/TenantResolver.php`), registrado como **primer elemento de `$required['before']`** en `app/Config/Filters.php:52-56` (no en `$globals['before']` — los filtros `required` corren antes que cualquier otro, incluso antes de que exista una ruta coincidente).

El filtro:
1. Lee el header `Host` del request.
2. Consulta `platform.tenants` (grupo de conexión `platform`, fijo — evita el problema de "necesito saber el tenant para conectarme, pero necesito conectarme para saber el tenant").
3. **Muta el array del grupo `default`** en la instancia compartida de `config('Database')` (ej. `config('Database')->default = [...]`) con el schema/usuario/contraseña del tenant resuelto, antes de que nada más llame a `db()`/`Database::connect('default')`.
4. Puebla `TenantContext` (`app/Libraries/TenantContext.php`, nuevo) con `tenant_id`, `slug` y nombre de schema — un punto único de lectura para el resto del código, en vez de esparcir parsing de `Host` por todos lados.

**Riesgo a validar con un spike aislado antes de construir nada más encima** (ver Fase 4 del plan): confirmar que ninguna otra pieza del framework abre una conexión `default` antes de que este filtro corra. Se valida con un endpoint de debug que imprime a qué schema está conectado, probado contra dos Hosts distintos.

## 6. Otros ajustes de código necesarios

### 6.1 Whitelist de hosts (`getValidHost()`)
`app/Config/App.php:319-356` hace un match **exacto** contra `allowedHostnames` (`in_array($httpHost, $this->allowedHostnames, true)`, línea 346) — bloquearía cualquier subdominio de tenant no listado literalmente. Hay que extender esta función para aceptar un patrón `*.midominio.com` con match por sufijo, sin romper el comportamiento de match exacto para hosts que no usan wildcard (`casaletto.local`, `staging.pos-casaletto.micronuba.net`, etc. seguirían funcionando igual).

### 6.2 Fuga de cache de configuración (confirmada)
`app/Config/OSPOS.php` usa la clave literal `'settings'` en `Services::cache()` (líneas 33, 52, 73). Con varios tenants compartiendo el mismo proceso PHP/cache, el negocio B leería la configuración cacheada (nombre, logo, moneda, impuestos) del negocio A. Se resuelve sufijando la clave con el slug del tenant activo, leído de `TenantContext`.

### 6.3 Defensa en profundidad a nivel de motor de BD
Cada tenant tiene su propio usuario MySQL/MariaDB con `GRANT` limitado únicamente a su propio schema, creado en el momento de provisión. Así, incluso si el Filter tuviera un bug, el motor de base de datos por sí solo rechazaría cualquier intento de cruce entre schemas — es una segunda capa de seguridad independiente del código de la aplicación.

## 7. Migraciones multi-tenant

CI4 corre `php spark migrate` contra una conexión a la vez. La tabla de tracking de migraciones (`app/Config/Migrations.php:30`, tabla `migrations` con el prefijo `ospos_` aplicado automáticamente → `ospos_migrations`) vive **dentro de cada schema**, así que el historial de cada tenant es independiente y naturalmente idempotente — no hay que inventar tracking propio.

Se necesita un **orquestador** (script bash o comando spark custom) que:
1. Recorra `platform.tenants`.
2. Corra las migraciones pendientes contra el schema de cada uno.
3. Se detenga y alerte si falla un tenant puntual, sin dejarlo a medio migrar sirviendo tráfico real.
4. Corra **antes** de promover el contenedor de app nuevo (orden: migrar todos → desplegar código).

## 8. Provisión de un tenant nuevo

Comando spark nuevo (ej. `php spark tenant:create <slug>`):
1. Crea el schema.
2. Crea el usuario MySQL con `GRANT` limitado a ese schema (sección 6.3).
3. Corre las migraciones (reusa el orquestador de la sección 7).
4. Siembra datos default (empleado admin inicial, `app_config`, sede default) reusando `app/Database/Seeds/` existente.
5. Inserta la fila correspondiente en `platform.tenants`.

## 9. Infraestructura Docker/Traefik

Hoy `docker/docker-mysql.yml` es un único contenedor MariaDB (imagen `mariadb:10.5`, EOL desde jun 2025) con una sola BD creada vía `MYSQL_DATABASE`, incluido por `docker-compose.local.yml`, `docker-compose.staging.yml` y `docker-compose.prod.yml`, cada uno con un solo contenedor de app y un router Traefik **estático** (`Host()` fijo, ej. `docker-compose.prod.yml:57`).

Cambios:
- El contenedor MariaDB sigue siendo uno solo; los schemas adicionales se crean vía la herramienta de provisión (sección 8), no vía variables de entorno del contenedor.
- El router Traefik estático se reemplaza por la regla wildcard (sección 3).

## 10. Login de plataforma

`app/Controllers/PlatformLogin.php` + `app/Models/PlatformAccount.php` (nuevos): login neutral en un subdominio propio (ej. `login.midominio.com`). Si la cuenta tiene un solo tenant, entra directo; si tiene varios, selector de negocio. Al elegir, redirección al subdominio de ese negocio con **re-autenticación local** contra el `Employee::login()` existente (sin cambios) — no hay token SSO de un solo uso, se descartó por ser esfuerzo innecesario para el MVP.

Panel de administrador de plataforma separado (gestiona el registro de tenants, usa la herramienta de la sección 8) — sin capacidad de impersonation.

## 11. Actualización de stack (incluida en el mismo trabajo)

- **MariaDB**: 10.5 (`docker/docker-mysql.yml:15`, EOL desde jun 2025) → 10.11 (LTS a feb 2028) o 11.4/11.8 (a 2028-2029).
- **PHP**: 8.2 (`Dockerfile:1`, `composer.json` `^8.2`, seguridad-solo hasta dic 2026) → 8.3 u 8.4. Bajo riesgo: la matriz de CI ya testea 8.2/8.3/8.4 (`.github/workflows/main.yml:31-33`, `phpunit.yml:37-39`).
- **CodeIgniter**: 4.7.2 → 4.7.4 no es urgente (solo 2 patches atrás).

Secuenciado **antes** de la complejidad multi-tenant, validado en staging primero, para no combinar dos cambios de riesgo en un mismo paso.

## 12. Inventario de archivos críticos

- `app/Config/Filters.php`, `app/Filters/TenantResolver.php` (nuevo), `app/Libraries/TenantContext.php` (nuevo) — resolución de tenant.
- `app/Config/Database.php` — nuevo grupo `platform`, mecánica de swap del grupo `default`.
- `app/Config/App.php` (`getValidHost()`) — soporte wildcard.
- `app/Config/OSPOS.php` (líneas 33, 52, 73) — cache key scoping.
- `app/Database/Migrations/` — migraciones de sede faltante y de las tablas de `platform`.
- `app/Database/Seeds/` — reusado por la herramienta de provisión.
- Nuevo comando spark de provisión (`app/Commands/`), nuevo script orquestador de migraciones.
- `docker/docker-mysql.yml`, `docker-compose.staging.yml`, `docker-compose.prod.yml` — regla Traefik wildcard.
- `app/Controllers/PlatformLogin.php`, `app/Models/PlatformAccount.php` (nuevos) — login de plataforma.

## 13. Verificación

- **Spike de resolución de tenant**: endpoint de debug que confirma el swap de conexión por `Host`, antes de construir el resto.
- **Staging con 2+ tenants ficticios**: aislamiento de datos, aislamiento de cache/config, `GRANT`s de BD rechazando cruce entre schemas, migraciones aplicadas correctamente a cada uno.
- **Regresión de Casaletto**: en cada fase, confirmar que el subdominio actual de Casaletto en staging/producción sigue funcionando exactamente igual (login, ventas, sedes, reportes).
- **CI existente**: mantener la matriz de PHPUnit verde en cada fase; correrla también contra la versión de PHP objetivo antes de cambiar el Dockerfile de producción.

## 14. Estrategia de rama y despliegue

Todo este trabajo se desarrolla en la rama `feature/multi-tenant-saas` (creada desde `develop`), y no se mergea a `develop` hasta que la validación en staging con tenants ficticios pase completa. Después de eso, sigue el flujo ya existente: `develop` → staging, `master` → producción.

## 15. Estado de avance

- **Fase 0 (documentación)**: completa.
- **Fase 1 (huecos de sede)**: completa. `sales`, `cash_up`, `expenses`, `receivings` y `dinner_tables` ahora tienen `location_id` (FK a `stock_locations`), con backfill de filas existentes al `MIN(location_id)` disponible. Migración: `app/Database/Migrations/20260729120000_AddLocationIdToSedeHeaders.php` + `app/Database/Migrations/sqlscripts/add_location_id_to_sede_headers.sql`. Los Models `Sale`, `Cashup`, `Expense`, `Receiving` y `Dinner_table` pueblan `location_id` en cada alta nueva reusando `Item_lib::get_item_location()` (la misma sede activa que ya usa el resto del sistema vía sesión). Validado con MariaDB 10.5 real (esquema fresco y esquema histórico de dev), y con la suite `SalesControllerTest`/`SalesKitControllerTest` (6/6 verdes) corriendo dentro del contenedor `ospos_dev` (PHP 8.2).
- **Nota de entorno de pruebas local**: `phpunit.xml.dist` fuerza `MYSQL_HOST_NAME=127.0.0.1` (pensado para el runner de GitHub Actions, donde el servicio `mysql` se expone en esa IP). Esto no coincide con `docker-compose.dev.yml`, donde MySQL vive en el hostname `mysql` de la red de Docker. Para correr PHPUnit localmente contra ese compose hace falta un puente de puerto (ej. `socat TCP-LISTEN:3306,fork,reuseaddr TCP:mysql:3306` dentro del contenedor de la app) — no se modificó `phpunit.xml.dist` porque esa configuración es correcta para CI. Vale la pena tenerlo en cuenta en fases futuras que necesiten correr la suite completa localmente.
- **Fases 2-10**: pendientes.
