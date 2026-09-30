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

1. **Esperan el workflow «PHPUnit Tests» de ese mismo commit** y no siguen si no está en verde.
2. Llevan `scripts/deploy.sh` **de ese commit** al servidor y lo ejecutan.

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

- El arranque tiene que decir `[entrypoint] All schemas current.` en 3 minutos, y el contenedor no
  puede haberse detenido.
- **Cada negocio activo**, por Traefik y como lo ve un cliente: `/login` con HTTP 200, su propio
  `<base href="https://<host>/">` y CSS. El `<base href>` descarta la página de otro contenedor; el
  CSS descarta el 200 sin estilos que ya pasó una vez (gulp inject). Hoy son 5 en producción
  (el dominio antiguo y los 4 negocios) y 5 en staging.
- Si todo está bien: `php spark platform:support-employee` (un permiso nuevo deja atrás al empleado
  de soporte; si falla, avisa y no tumba nada).

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
- Staging con el workflow nuevo, normal y con `probar_vuelta_atras` (fuerza el fallo de la
  verificación): la vuelta atrás automática tiene que dejar staging verificado en el commit anterior.

## 5. Lo que no cubre

- **Hostinger bloquea de forma intermitente el SSH desde GitHub** (visto en agosto). Si pasa, el
  workflow falla al conectar, antes de tocar el servidor; se reintenta. Si hay urgencia, el mismo
  script a mano (sección 2), nunca un `docker compose` suelto.
- El respaldo vive en el mismo servidor. Protege de un despliegue malo, no de perder el servidor.
- `docker-compose.yml` sigue siendo el de upstream (publica el 80). Lo neutraliza `COMPOSE_FILE`
  en cada `.env`, no se cambió el archivo.
