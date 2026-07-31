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

- **`platform`**: fijo, siempre apunta al schema de control, configurado vía sus propias variables de entorno (`PLATFORM_DB_HOST_NAME`, `PLATFORM_DB_USERNAME`, `PLATFORM_DB_PASSWORD`, `PLATFORM_DB_NAME` — mismo patrón que `MYSQL_*` para los otros grupos, en `app/Config/Database.php`). Aquí viven `tenants`, `platform_accounts`, `platform_account_tenants`. BD por defecto: `platform_control`.
- **`default`**: el grupo que ya usan los ~40 Models existentes sin modificarlos (ninguno especifica `$DBGroup` explícito). Se sobreescribe dinámicamente en cada request según el tenant resuelto.

### 4.1 Las migraciones de `platform` viven en un namespace PHP separado (`Platform`, no `App`)

Las migraciones de CI4 no se filtran por `$DBGroup` al decidir cuáles corren — `php spark migrate -g platform` sin más intenta correr **todas** las migraciones de la app (las ~50 de `App\Database\Migrations`, pensadas para el schema `ospos`) contra el schema `platform_control`, porque ninguna de ellas tiene tracking previo ahí. Confirmado empíricamente: revienta a mitad de camino con errores de columnas duplicadas.

La solución correcta es un **namespace PSR-4 separado**: las 3 migraciones de esta fase viven en `app/Platform/Database/Migrations/` con namespace `Platform\Database\Migrations`, registrado en `app/Config/Autoload.php` (`'Platform' => APPPATH . 'Platform'`). Se corren con `php spark migrate -n Platform -g platform`, y `php spark migrate` (sin `-n`) sigue tocando solo `App` como siempre — namespaces distintos, cero interferencia mutua.

**Gotcha real encontrado corriendo la suite completa de tests (no solo las migraciones sueltas)**: 9 archivos de test (`EmployeeTest`, `SalesControllerTest`, `SalesKitControllerTest`, `HomeTest`, `ConfigTest`, `EmployeesControllerTest`, `CustomersCsvImportTest`, `ItemsCsvImportTest`, `Summary_taxes_test`) declaraban `protected $namespace = null;` — en CI4, `$namespace = null` en `DatabaseTestTrait` significa **"migrar todos los namespaces registrados"**, no solo `App`. Antes de esta fase eso no importaba porque `App` era el único namespace; en cuanto se registró `Platform`, esos 9 tests empezaron a intentar correr también las migraciones de `platform` (con credenciales no configuradas en el entorno de test) y fallaban con `Unable to connect to the database`. Se corrigieron los 9 archivos a `protected $namespace = 'App';` (su intención real siempre fue "solo mis migraciones"), validado con la suite completa: 166/166 verdes de nuevo. **Cualquier test nuevo que use `DatabaseTestTrait` debe declarar `$namespace` explícito (`'App'`), nunca `null`**, ahora que hay más de un namespace de migraciones en el proyecto.

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
- **Fase 1 (huecos de sede)**: completa **y ya desplegada en staging y producción reales** (2026-07-30). `sales`, `cash_up`, `expenses`, `receivings` y `dinner_tables` ahora tienen `location_id` (FK a `stock_locations`), con backfill de filas existentes al `MIN(location_id)` disponible. Migración: `app/Database/Migrations/20260729120000_AddLocationIdToSedeHeaders.php` + `app/Database/Migrations/sqlscripts/add_location_id_to_sede_headers.sql`. Los Models `Sale`, `Cashup`, `Expense`, `Receiving` y `Dinner_table` pueblan `location_id` en cada alta nueva reusando `Item_lib::get_item_location()` (la misma sede activa que ya usa el resto del sistema vía sesión). Validado con MariaDB 10.5 real (esquema fresco y esquema histórico de dev), y con la suite `SalesControllerTest`/`SalesKitControllerTest` (6/6 verdes) corriendo dentro del contenedor `ospos_dev` (PHP 8.2).
  - **Despliegue real (2026-07-30)**: se desacopló de la Fase 2 originalmente (cherry-pick separado), y se desplegó después vía cherry-pick a `develop`→staging y `master`→producción, con `mariadb-dump` de respaldo antes de migrar en cada ambiente (no requería el runbook de dump/restore completo de la Fase 2, al ser una migración aditiva sin salto de versión de motor). `php spark migrate` corrido vía `docker compose exec ospos` (los workflows de deploy no corren migraciones automáticamente — paso manual siempre). Verificado 100% de filas con `location_id` poblado en ambos ambientes (staging: 5 sales/2 cash_up/4 dinner_tables; producción: 335 sales/16 cash_up/20 expenses/11 dinner_tables), sin errores, HTTP 200/302 correctos.
  - **Nota**: la rama `feature/multi-tenant-saas` vivía solo local hasta este punto (nunca pusheada) — se pusheó a `origin/feature/multi-tenant-saas` como respaldo antes de este despliegue.
- **Nota de entorno de pruebas local**: `phpunit.xml.dist` fuerza `MYSQL_HOST_NAME=127.0.0.1` (pensado para el runner de GitHub Actions, donde el servicio `mysql` se expone en esa IP). Esto no coincide con `docker-compose.dev.yml`, donde MySQL vive en el hostname `mysql` de la red de Docker. Para correr PHPUnit localmente contra ese compose hace falta un puente de puerto (ej. `socat TCP-LISTEN:3306,fork,reuseaddr TCP:mysql:3306` dentro del contenedor de la app) — no se modificó `phpunit.xml.dist` porque esa configuración es correcta para CI. Vale la pena tenerlo en cuenta en fases futuras que necesiten correr la suite completa localmente.
- **Fase 2 (actualización de stack)**: completa. `Dockerfile:1` → `php:8.4-apache` (era `8.2`); `docker/docker-mysql.yml:15` y `docker-compose.dev.yml` → `mariadb:11.4` (era `10.5`, EOL desde jun 2025; se eligió 11.4 sobre 10.11 por runway más largo — LTS a mayo 2029 vs feb 2028 — dado que la plataforma se piensa para varios años de operación). También se actualizaron a las mismas versiones los workflows que hoy corren contra un stack fijo: `.github/workflows/deploy-production.yml` y `deploy-staging.yml` (el paso `docker run ... php:8.2-cli` que instala dependencias y compila assets antes del build de imagen), `.github/workflows/phpunit.yml` (contenedor MariaDB de CI) y `.github/workflows/build-release.yml` (PHP para armar el release). CodeIgniter se dejó en 4.7.2 (no es urgente, solo 2 patches atrás de 4.7.4).
  - **Validado de verdad, no solo revisado**: se reconstruyó la imagen `ospos_dev` con `php:8.4-apache` (compila limpio, incluyendo la extensión xdebug vía PECL). Se recreó el volumen local de MariaDB desde cero en 11.4 (el salto directo 10.5→11.4 sobre datos existentes falla — MariaDB exige un apagado limpio de la versión vieja primero; para este entorno de desarrollo descartable se optó por recrear en vez de migrar datos) y se corrió **el historial completo de migraciones desde el esquema inicial de 2017 hasta hoy** sin ningún error. Se corrió la suite completa de PHPUnit (166 tests / 427 assertions) dentro del contenedor con PHP 8.4, contra MariaDB 11.4: **166/166 verdes**.
  - **Nota para staging/producción**: a diferencia del entorno de dev (datos descartables), las bases de staging y producción tienen datos reales de Casaletto. El salto de MariaDB 10.5→11.4 ahí **no puede hacerse recreando el volumen** — requiere el procedimiento estándar de MariaDB (apagar limpio la versión vieja antes de arrancar la nueva sobre los mismos datos, o un dump/restore lógico) como paso explícito y cuidadoso al desplegar esta fase, no algo que ocurra solo al hacer `docker compose up`.
  - **Decisión de negocio (2026-07-29)**: el upgrade de stack en staging/producción se desacopló del resto del proyecto multi-tenant y se ejecutó de inmediato, en vez de esperar al merge completo — MariaDB 10.5 llevaba más de un año sin parches de seguridad y no tenía sentido dejarlo esperando meses.
  - **Runbook ejecutado en staging (2026-07-29), con éxito y sin pérdida de datos**:
    1. Backup en 3 capas antes de tocar nada: dump lógico (`mariadb-dump --single-transaction`) guardado en el VPS y copiado fuera de él, tarball crudo del volumen 10.5 original, y el contenedor viejo simplemente detenido (nunca borrado).
    2. App detenida para congelar escrituras antes del dump; dump verificado contra los conteos en vivo (coincidencia exacta) antes de restaurar.
    3. MariaDB 11.4 levantado en un **volumen nuevo y separado** (`pos_casaletto_staging_mysql_11_4`), dump restaurado ahí — el volumen viejo (`pos_casaletto_staging_mysql`) queda intacto como respaldo frío.
    4. **Verificación cruzada por `CHECKSUM TABLE`**: se arrancó brevemente el contenedor viejo (10.5, sin tocar) y se comparó el checksum de `sales`, `people`, `items`, `sales_items`, `employees` contra el nuevo (11.4) — **idénticos byte a byte** antes de dar el cutover por bueno.
    5. Cutover: cherry-pick del commit de stack (`docker/docker-mysql.yml`, `Dockerfile`, workflows) a `develop` — sin los documentos de este proyecto multi-tenant, que no aplican todavía a esa rama — más un commit adicional en `docker-compose.staging.yml` para que el volumen `mysql` (declarado con `driver: local` en el `docker-mysql.yml` compartido) se resuelva como un volumen `external` distinto (`mysql_11_4`) apuntando al ya migrado; Compose no permite mezclar `driver` y `external` en la misma clave, así que se usó `volumes: !override` a nivel de servicio para apuntar el mount a esa clave nueva sin arrastrar la declaración original.
    6. Deploy real en el VPS: mismo paso de build que usa `deploy-staging.yml` (composer/npm/gulp vía `php:8.4-cli`) + `docker compose up -d --build`.
    7. Smoke test: `HTTP 200` en `/login`, `HTTP 302` (redirect correcto sin sesión) en `/home`, conexión real de la app a la BD nueva confirmando los mismos datos (5 ventas) por la red de Docker, sin errores en `writable/logs`.
  - **Producción (2026-07-30), ejecutada con éxito y sin pérdida de datos**: mismo runbook exacto que staging, en la ventana sin operación confirmada por el usuario. Backup en 3 capas (dump 325 ventas / 6 empleados / 278 items — verificado contra los conteos en vivo antes de restaurar), MariaDB 11.4 restaurado en volumen nuevo (`pos_casaletto_mysql_11_4`), **checksums idénticos byte a byte** en `sales`, `people`, `items`, `sales_items`, `employees`, `cash_up`, `receivings`, `expenses` entre el 10.5 original (intacto, respaldo) y el 11.4 restaurado. Cherry-pick del cambio de stack + override de volumen a `master` (mismo patrón `volumes: !override` que en staging), deploy real vía SSH, smoke test: `HTTP 200` en `/login`, `HTTP 302` en `/home`, la app viendo las 325 ventas reales por la red de Docker, sin errores en logs. Producción corre en PHP 8.4 + MariaDB 11.4 desde este despliegue.
  - Volúmenes viejos (`pos_casaletto_staging_mysql` y `pos_casaletto_mysql`, ambos en MariaDB 10.5) y sus tarballs de respaldo se conservan sin tocar en el VPS como red de seguridad adicional — no se eliminan de inmediato.
- **Fase 3 (schema de control y registro de tenants)**: completa, solo en `feature/multi-tenant-saas` (no se despliega a staging/producción todavía — no tiene ningún uso hasta que exista el resto del multi-tenant). Grupo de conexión `platform` en `app/Config/Database.php`; 3 migraciones nuevas en `app/Platform/Database/Migrations/` (namespace `Platform`, ver sección 4.1) creando `tenants`, `platform_accounts`, `platform_account_tenants` con sus FKs. Validado de verdad: `up`/`down`/`up` corridos contra un schema `platform_control` real en Docker, esquema resultante verificado columna por columna, y la suite completa de PHPUnit corrida dos veces (detectó y permitió corregir el gotcha de `$namespace = null` en 9 tests, documentado arriba) — 166/166 verdes al cierre.
- **Fase 4 (Filter de resolución de tenant + spike de validación)**: completa, solo en `feature/multi-tenant-saas`.
  - `app/Filters/TenantResolver.php` (nuevo), registrado como primer elemento de `required.before` en `app/Config/Filters.php`. Lee el header `Host`, extrae el slug quitando el sufijo wildcard configurado, busca en `platform.tenants` (conexión `platform`, fija) un tenant `status='active'` con ese slug. Si lo encuentra, muta `config(Database::class)->default['database']` con el `db_name` del tenant y puebla `TenantContext` (`app/Libraries/TenantContext.php`, nuevo — holder estático de solo lectura). **Solo se sobreescribe `database`**, no host/usuario/contraseña — todos los tenants comparten el mismo servidor y credenciales hasta la Fase 7 (provisión), que les da un usuario MySQL propio con `GRANT` limitado a su schema.
  - Si el Host no matchea ningún wildcard configurado, o matchea pero no hay tenant activo con ese slug (incluye el caso de un tenant con `status='suspended'`), el filtro simplemente no hace nada — la conexión `default` sigue usando lo que ya configuran las variables `MYSQL_*`, es decir el comportamiento actual de Casaletto, sin cambios.
  - `app/Config/App.php`: nueva propiedad `$allowedHostnameWildcards` (sufijos con punto inicial, ej. `.midominio.com`, vía env var `ALLOWED_HOSTNAME_WILDCARDS`), consultada en `getValidHost()` además del match exacto ya existente — necesario para que `baseURL`/links generados no se rompan en subdominios de tenant.
  - `app/Config/OSPOS.php`: la clave de cache de `'settings'` ahora se sufija con el slug del tenant activo (`settings_<slug>`) vía `TenantContext::isResolved()`/`::slug()`, cerrando la fuga de config entre tenants detectada en la auditoría original.
  - **Spike de validación ejecutado de verdad** (no solo revisado): 2 schemas de tenant reales (`tenant_demo1`, `tenant_demo2`, cada uno con una tabla `marker` de valor único) + 1 tenant `suspended`, servidos por el mismo contenedor de app, probados con `curl -H "Host: ..."` contra un endpoint de debug temporal (removido antes del commit). Resultado: cada Host devolvió el `SELECT DATABASE()` y el valor de `marker` correctos de su propio schema; el tenant suspendido y un Host sin wildcard cayeron correctamente a `ospos` (el comportamiento de hoy); `/login` sin tenant siguió devolviendo `HTTP 200` sin errores. Confirma que el swap de conexión ocurre antes de que `Config\Session` (o cualquier otra cosa) abra la conexión `default`.
  - Suite completa de PHPUnit corrida después de todo esto: 166/166 verdes, sin regresión.
- **Fase 5 (orquestador de migraciones multi-tenant)**: completa, solo en `feature/multi-tenant-saas` (no wireado a `deploy-staging.yml`/`deploy-production.yml` todavía — esos ambientes no tienen schema `platform_control`, eso llega en la Fase 10).
  - `scripts/migrate-tenants.sh`: recorre `platform.tenants` (activos) y corre las migraciones de `App` contra el schema de cada uno, uno a la vez, reportando éxito/fallo por tenant y con código de salida distinto de cero si alguno falló (para que el pipeline de deploy no promueva el contenedor nuevo en ese caso).
  - `app/Commands/TenantList.php` (`php spark tenant:list`): imprime los `db_name` de tenants activos, un slug por línea, prefijados con `TENANT_DB:` — necesario porque `spark` siempre escribe su propio banner a stdout sin importar el comando, así que el script filtra por ese prefijo en vez de confiar en la salida cruda.
  - `app/Commands/TenantMigrateOne.php` (`php spark tenant:migrate-one`): migra el schema que `MYSQL_DB_NAME` indique en ese momento. **No es un wrapper del comando `migrate` de CI4** — se comprobó empíricamente que `migrate` atrapa cualquier excepción internamente y nunca la propaga como código de salida (`Boot::runCommand()` convierte un `run()` que no retorna nada en éxito) — un schema deliberadamente inalcanzable seguía reportando "OK" y saliendo con código 0. Este comando propio sí retorna 0/1 real.
  - **Segundo hallazgo real, más sutil**: el primer intento de `TenantMigrateOne` mutaba `config(Database::class)->default['database']` en tiempo de ejecución (el mismo patrón que usa `TenantResolver` en la Fase 4) — funcionó para el Filter (que corre primero que cualquier otra cosa) pero **no de forma confiable dentro de un comando spark**, donde algo ya había resuelto la conexión `default` antes de que el comando mutara la config; el síntoma fue un tenant recién creado y vacío fallando con "Duplicate column" como si ya tuviera el historial completo aplicado (de otro schema). Se resolvió abandonando la mutación en runtime y usando en su lugar la variable de entorno `MYSQL_DB_NAME` (leída una sola vez, temprano, en el constructor de `Config\Database`) — el mismo mecanismo ya probado en las Fases 1-3 — pasada por el script una vez por tenant, en un proceso `php spark` nuevo cada vez.
  - **Validado de verdad** con 3 escenarios reales en Docker: 2 tenants nuevos migrados limpio (historial completo de ~48 migraciones cada uno, incluyendo el `location_id` de la Fase 1), 1 tenant con `db_name` inexistente fallando correctamente y reportado (código de salida 1, los otros dos igual se migran, no se abortan), y el caso de cero tenants activos (código de salida 0). Re-corrida del script confirmó idempotencia (segunda vez no reaplica nada). Suite completa de PHPUnit: 166/166, sin regresión.
- **Fases 6-10**: pendientes.
