# Despliegue — un solo camino, verificado y con vuelta atrás

> **Estado (2026-09-29):** vigente. Decisión del dueño esa noche: *se despliega por GitHub Actions*.
> Sustituye la práctica de desplegar a mano por SSH, que se usaba desde el 2026-08-12.

## 1. Por qué

El 2026-09-29 producción estuvo caída de **22:59 a 23:08** (8 comprobaciones fallidas del monitor).
Un despliegue a mano corrió `docker compose up -d --build ospos` **sin** `-f docker-compose.prod.yml`.
El `docker-compose.yml` por defecto publica el puerto 80, que es de Traefik, y define `mysql` sobre el
volumen viejo de la base. Reemplazó los contenedores de producción y el nuevo no arrancó. Además el
commit venía de `develop` sin pasar por staging. Se restauró lanzando el workflow de producción desde
`master`. Datos intactos: el volumen `pos_casaletto_mysql_11_4` no se tocó.

Nada de eso lo impedía ninguna herramienta: dependía de escribir bien un comando a mano.

## 2. Cómo se despliega

| | Staging | Producción |
|---|---|---|
| Rama | `develop` | `master` |
| Workflow | `deploy-staging.yml` | `deploy-production.yml` |
| Lanzarlo | `gh workflow run deploy-staging.yml --ref develop` | `gh workflow run deploy-production.yml --ref master` |

Orden: push a `develop` → staging → certificar en staging → `git push origin origin/develop:master`
(avance rápido) → producción.

Los dos workflows hacen lo mismo:

1. **Exigen PHPUnit en verde para ese código.** `phpunit.yml` solo corre cuando un push toca
   código, así que un commit de solo documentación o despliegue no tiene corrida propia. Se busca
   hacia atrás la primera corrida terminada: tiene que estar en verde y, entre ella y el commit, no
   puede haber cambios en nada de lo que dispara PHPUnit (mismos filtros, repetidos en el workflow).
   Si la corrida del commit aún está en curso, se espera.
2. **Conectan por SSH con reintentos** (8, con espera creciente) antes de tocar nada. No se usa
   `appleboy/ssh-action`: no reintenta, y Hostinger corta de forma intermitente el SSH que viene de
   GitHub (pasó otra vez el 2026-09-29).
3. Llevan `scripts/deploy.sh` **de ese commit** al servidor y lo lanzan **desacoplado** (`setsid
   nohup`), con registro en `/root/deploy-logs/<ambiente>-<run>-<intento>.log`. El workflow solo lo
   sigue y se reconecta: un corte de red a mitad ya no deja el despliegue a medias.

Todo lo demás vive en el script, versionado. **A mano, solo en emergencia, y con el mismo script**:
`cd /root/POS_Casaletto && git fetch origin master && git show <sha>:scripts/deploy.sh > /tmp/d.sh && bash /tmp/d.sh prod <sha>`.

## 3. Qué comprueba `scripts/deploy.sh`

**Antes de tocar nada** (si algo falla aquí, producción ni se entera):

- `COMPOSE_FILE` fijo, y escrito en el `.env` de la carpeta: un `docker compose` suelto allí ya usa el
  archivo correcto. Si el `.env` trae otro, se detiene.
- El commit existe y está en la rama del ambiente.
- **Producción:** el commit tiene que estar contenido en lo que corre staging (certificado allí);
  solo después de las 22:00 hora Colombia, salvo `autorizado_en_horario`; y sin acciones de usuarios
  (peticiones POST, sin contar el ingreso) en los últimos 15 minutos, salvo `ignorar_actividad`. Las
  dos casillas del workflow son para cuando el dueño lo autoriza en el momento.
- Al menos 5 GB libres.
- **Respaldo de todos los esquemas** del registro (`platform_control.tenants`) más `platform_control`,
  en `/root/backups/<ambiente>-<fecha>-<commit anterior>/`. Cada uno se **restaura en una base
  temporal** y se comparan los conteos de ventas, artículos, personas y gastos con la base viva.
- Etiqueta de vuelta atrás: `casaletto-ospos:rollback-<ambiente>-<fecha>-<commit anterior>`.
- **La imagen se construye antes de parar nada.** Si el build falla, sigue corriendo lo anterior.

**Después de cambiar:**

- La aplicación se recrea **siempre** (`--force-recreate --no-deps ospos`; la base solo si su
  definición cambió): con una imagen idéntica Docker no la reiniciaría y la verificación daría un
  falso fallo.
- El arranque tiene que decir `[entrypoint] All schemas current.` en 3 minutos, y el contenedor no
  puede haberse detenido.
- **Cada negocio activo**, por Traefik y como lo ve un cliente: `/login` con HTTP 200, su propio
  `<base href="https://<host>/">` y CSS. El `<base href>` descarta la página de otro contenedor; el
  CSS descarta el 200 sin estilos que ya pasó una vez (gulp inject). Hoy son 5 en producción
  (el dominio antiguo y los 4 negocios) y 5 en staging.
- Si todo está bien: `php spark platform:support-employee` (un permiso nuevo deja atrás al empleado
  de soporte; si falla, avisa y no tumba nada).
- Luego los **servicios auxiliares**: todo lo del compose que no es `ospos` ni `mysql` (hoy solo
  Mailpit, en staging) se crea o actualiza con `up -d --no-deps`. Si uno no arranca, avisa y no tumba
  nada. Antes del 2026-10-26 este paso no existía, y un servicio nuevo en el compose no llegaba a
  crearse: la bandeja de Mailpit respondió 404 después de un despliegue «exitoso».

**Si la verificación falla:**

- **Sin migraciones nuevas:** vuelve solo a la imagen anterior y la verifica igual.
- **Con migraciones nuevas: NO vuelve solo.** Una imagen más vieja que el esquema deja a todos sin
  poder entrar (`MY_Migration::is_latest()` → `Load_config` destruye la sesión). El script imprime
  los comandos exactos para restaurar imagen y respaldo juntos.

Cada despliegue deja una línea en `/root/deploys.log`: commit anterior → nuevo, resultado, respaldo y
etiqueta. Se conservan las últimas 8 etiquetas y los últimos 15 respaldos por ambiente.

## 4. Cómo se probó

- `shellcheck` y `actionlint` sin avisos.
- Condición de verificación comprobada contra los 9 dominios vivos antes de usarla.
- 2026-09-29, staging con el workflow nuevo: despliegue normal correcto (respaldo de 5 esquemas
  comprobado, 5 dominios en verde); con `probar_vuelta_atras` la verificación falló a propósito y la
  vuelta atrás automática dejó staging verificado otra vez.
- 2026-09-29 23:46, producción con el workflow nuevo (`37ec1803c`): certificado en staging, sin
  actividad, respaldo de 4 esquemas comprobado (3,1 MB), arranque y 4 dominios en verde.
- Lo que la prueba destapó y se corrigió antes de producción: la espera de PHPUnit se colgaba en
  commits sin código; el SSH desde GitHub falló una vez; y un despliegue de imagen idéntica no
  reiniciaba la aplicación.

## 5. Lo que no cubre

- Si Hostinger bloquea el SSH durante los 8 reintentos, el workflow falla sin tocar el servidor y se
  relanza. Si hay urgencia, el mismo script a mano (sección 2), nunca un `docker compose` suelto.
- El respaldo vive en el mismo servidor. Protege de un despliegue malo, no de perder el servidor.
- `docker-compose.yml` sigue siendo el de upstream (publica el 80). Lo neutraliza `COMPOSE_FILE`
  en cada `.env`, no se cambió el archivo.

## 6. Secretos del `.env` y correo de la plataforma

**Un secreto se escribe en el `.env` con `scripts/set-env-secret.sh`**, dentro de la carpeta del
ambiente. Pide el valor dos veces sin mostrarlo, lo reemplaza si ya estaba y deja el archivo en 600.
No queda en la pantalla, en el historial ni en un archivo temporal del editor. Se aplica en el
siguiente despliegue, que recrea el contenedor al cambiar su configuración.

Si la carpeta todavía no tiene el script (producción antes del despliegue que lo trae), se toma del
repositorio sin desplegar nada:

```
cd /root/POS_Casaletto && git fetch -q origin develop && bash <(git show origin/develop:scripts/set-env-secret.sh) PLATFORM_SMTP_PASS
```

| Ambiente | Variable | Qué es |
|---|---|---|
| Producción | `PLATFORM_SMTP_PASS` | Clave de API de Resend `ospos-produccion` (solo envío, dominio `micronuba.net`) |
| Staging | `MAILPIT_UI_AUTH` | `usuario:contraseña` de la bandeja de Mailpit. **Obligatoria:** sin ella, staging no despliega |

**Comprobar el correo** después del despliegue (diseño en `preventas-cartera-y-avisos.md` §4.1):

```
docker compose exec -T -u www-data ospos php spark platform:mail-test deimorga@gmail.com
```

**`-u www-data` en todo comando manual** (`docker compose exec`): sin él, el comando corre como root y,
si es el primero del día en escribir `writable/logs/log-<fecha>.log`, el archivo queda de root y Apache
(`www-data`) no puede escribir en él el resto del día. Pasó en staging el 2026-10-27.

- Staging: el correo no sale. Aparece en `https://mailpit.staging.ospos-saas.micronuba.net`.
- Producción: llega a la bandeja y figura en Resend → Emails. En producción es un correo real, pero no
  una transacción: no toca ventas ni caja, así que la regla de solo lectura no lo impide.

## 7. El worker del correo de preventas

Los avisos de preventas no salen en la petición de la caja: se encolan y los envía
`php spark presales:notify` (diseño en `preventas-cartera-y-avisos.md` §4.5). Lo corre cada minuto el
servicio **`presales-notifier`** de cada docker-compose (`docker/presales-notifier.sh`): la misma imagen
y la misma configuración que la aplicación, sin Apache, sin migraciones (`SKIP_MIGRATIONS=1`), sin Traefik,
solo en `app_net` y como `www-data`.

- **No hay nada que instalar en el servidor.** `deploy.sh` lo recrea junto con la aplicación
  (`switch_worker`), al desplegar y al volver atrás: nunca corre código de otra versión que el esquema.
  Si no arranca, avisa y no tumba el despliegue. `restart: always` lo levanta si se cae.
- **Por qué un servicio y no una línea de crontab** (decisión del dueño, 2026-10-27): todo lo que corre
  vive en los contenedores y en el repositorio, para poder migrar de servidor o de nube con el compose y
  el `.env` de cada ambiente. Una tarea en el host sería infraestructura escondida. La primera versión del
  2026-10-26 usaba crontab y se retiró sin llegar a instalarse.
- **Latido y salud** (revisión de infra, 2026-10-28): tras cada corrida buena el script toca
  `/tmp/presales-notifier.heartbeat`, y el healthcheck marca el contenedor `unhealthy` si tiene más de 3
  minutos. Un comando que fallara cada minuto ya no pasa por «running» sano. Verlo:
  `docker compose ps presales-notifier` (columna de estado).
- **Techo de recursos:** el worker 256 MB y medio núcleo; Mailpit de staging 128 MB y medio núcleo. Una fuga
  queda en su techo y no degrada a los demás proyectos del VPS.
- Al detenerlo, la corrida en curso termina antes de salir (`stop_grace_period: 90s`): cortar un envío a
  la mitad podría mandar un correo dos veces.
- Registros: `docker compose logs presales-notifier` (rotados por Docker, 3 archivos de 10 MB). Una corrida
  sin nada que enviar no escribe nada; una con envíos escribe una línea por negocio.
- Con el worker detenido, los avisos esperan en la bandeja como «En cola», visibles en la pestaña «Correos»
  de cada preventa. Nada se pierde.
- Apagar todos los envíos: `docker compose stop presales-notifier` (vuelve en el siguiente despliegue).
  Apagar un negocio: su casilla «Avisar al cliente por correo».
