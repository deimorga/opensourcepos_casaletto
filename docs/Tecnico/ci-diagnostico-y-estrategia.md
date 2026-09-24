# CI de este fork — diagnóstico y estrategia

> **Relevado:** 2026-09-20 sobre las últimas 100 ejecuciones · **Revisado y aplicado:** 2026-09-23
> **Workflows activos:** 7 · **Estado:** causas 1 y 3 cerradas; la 2 (dependencias) pendiente
>
> ⚠️ **Este repositorio es público.** Aquí no van direcciones de servidores, rutas de despliegue,
> nombres de ficheros de clave ni referencias a otros proyectos. La operación del servidor se
> documenta en el repositorio de infraestructura, que es privado.

## El dato

**52 de las últimas 100 ejecuciones fallaron.** En los 14 días previos al 2026-09-20 este
repositorio generó **42 correos de fallo**, repartidos así:

| Workflow | Correos | ¿Verifica algo de este fork? |
|---|---:|---|
| Build and Release | 24 | no |
| Coding Standards | 11 | sí, pero nunca llega a ejecutarse |
| Delete Unstable Release | 7 | no |
| PHP Linting | 0 | **sí — este funciona** |
| PHPUnit Tests | 0 | **sí — este funciona** |

Los dos workflows que sí prueban el código de este fork —`PHP Linting` y `PHPUnit Tests`— pasan de
forma consistente. **Todo el ruido viene de maquinaria heredada del proyecto original.**

Como el repositorio es público, los minutos de Actions son ilimitados y gratuitos: este 52 % no
cuesta dinero. Cuesta credibilidad, que es peor — cuando la mitad de los correos son rojos, se dejan
de leer todos.

## Las tres causas

### 1. Build and Release — un fichero con `?` en el nombre

```
##[error]The path for one of the files in artifact is not valid:
/docs/Funcional/referencia-ospos-wiki/Why-my-issue-was-closed?.md.
Contains the following character:  Question mark ?
```

`actions/upload-artifact` rechaza `"`, `:`, `<`, `>`, `|`, `*`, `?`, `\r` y `\n` en los nombres de
fichero, por compatibilidad con NTFS. El paso «Upload build context for Docker» muere ahí y los jobs
`Create Release` y `Build Docker Image` quedan en `skipped`.

El fichero entró el **2026-07-09** en `1b4446b07` (traducción del wiki de OSPOS al español). Es el
único del repositorio con caracteres inválidos:

```bash
git ls-files | grep -E '[?":<>|*]'
```

**Un renombrado lo arregla, y con él se van 24 de los 42 correos.**

### 2. Coding Standards — composer bloqueado por advisories

```
Your requirements could not be resolved to an installable set of packages.
  Problem 1
    - Root composer.json requires codeigniter4/framework 4.7.2 ... affected by security advisories
  Problem 2
    - Root composer.json requires dompdf/dompdf ^2.0.3 ... affected by security advisories
```

`composer update` se niega a instalar paquetes con avisos de seguridad abiertos. El workflow corre
una matriz de PHP 8.2, 8.3 y 8.4, así que **cada push produce tres fallos idénticos**.

El fallo es en `Install dependencies`: PHP CS Fixer nunca llega a ejecutarse. El workflow no está
diciendo «tu código viola el estándar», está diciendo «no pude ni empezar».

Salidas posibles, por orden de preferencia:

1. Subir `codeigniter4/framework` y `dompdf/dompdf` a versiones sin advisory. Es la única que además
   arregla el problema de seguridad real.
2. Declarar los IDs concretos en `policy.advisories.ignore-id` de `composer.json`, con la razón
   escrita al lado y fecha de revisión.
3. Deshabilitar el workflow. Válido solo si se acepta que este fork no comprueba estándar de código.

### 3. Delete Unstable Release — un secreto que no existe

```
Error: Parameter token or opts.auth is required
```

`delete-unstable-release.yml` usa `secrets.TOKEN`, que es del repositorio original. Este fork no lo
tiene y no debería tenerlo: **este fork no publica releases**.

Es maquinaria del upstream que no aplica aquí.

## El multiplicador: `on: push:` sin filtro de rama

> **Revisado el 2026-09-23: esta sección llevaba a una conclusión equivocada.** Acotar el
> disparador no apaga ningún fallo —una ejecución verde no manda correo— y deja las ramas de
> trabajo sin la única comprobación del build de assets. Ver «Por qué el job `build` se
> conserva». El diagnóstico del volumen es correcto; la acción que se derivaba de él, no.

`build-release.yml` declara:

```yaml
on:
  push:
  pull_request:
    branches:
      - master
```

Ese `push:` pelado dispara en **cada rama**, incluidas las de trabajo. Medido: **4 a 5 workflows por
cada push**, en ramas como `feat/cajon-y-impresion` o `fix/cuadre-ignora-ventas-anuladas`, donde no
tiene ningún sentido construir un release.

> **El patrón que hay que reconocer:** al forkear un proyecto se heredan sus workflows, pensados para
> publicar *sus* releases con *sus* secretos y *sus* ramas. Aquí llegaron tal cual y nunca se
> decidió qué hacer con ellos. Tres años después siguen corriendo y fallando.
>
> **Un fork trae los workflows del upstream, y esa decisión hay que tomarla explícitamente:**
> adaptar o deshabilitar, uno por uno, con la razón escrita.

## Lo que sí está bien y no hay que tocar

Esto merece decirse, porque en un repositorio público es lo que separa un incidente de un día normal:

- **Los despliegues son `workflow_dispatch`**, nunca automáticos por push.
- **Usan *environments* con política de rama**: el de producción solo acepta `master`, el de staging
  solo acepta `develop`. Un despliegue desde una rama cualquiera no arranca.
- **`deploy-pr.yml` lleva guarda contra forks:**

  ```yaml
  if: github.event.pull_request.head.repo.full_name == github.repository
  ```

  Sin esa línea, cualquiera podría abrir un PR desde su fork y —con una aprobación— ejecutar código
  con los secretos del repositorio. En un repositorio público es la diferencia entre aceptar
  contribuciones y regalar el servidor. **No se toca.**

- **El escaneo de secretos y la protección de push están activos** (son gratuitos en repositorios
  públicos).

## Qué se hizo — 2026-09-23

| # | Acción | Estado |
|---|---|---|
| 1 | ~~Renombrar el fichero con `?`~~ → **descartado, ver abajo** | ✅ resuelto por otra vía |
| 2 | Decidir sobre `Coding Standards`: dependencias, ignorar con razón, o deshabilitar | pendiente |
| 3 | `Delete Unstable Release` — neutralizado, con la razón escrita en el propio fichero | ✅ hecho |
| 4 | ~~Acotar el `on: push:` de `build-release.yml`~~ → **innecesario, ver abajo** | ✅ sin objeto |
| 5 | Revisar el resto de la maquinaria heredada, uno por uno | parcial (1, 3, 4) |
| 6 | Configurar un *ruleset* en `master` | pendiente |
| 7 | Fijar `dbfx/github-phplint/8.x@master` a una etiqueta | pendiente |
| 8 | **Habilitar Dependabot** | pendiente |

### El renombrado era la respuesta equivocada

La acción 1 de la versión anterior de este documento decía «un renombrado lo arregla». **No.**
Al ir a aplicarlo aparecieron dos cosas que no se habían mirado:

- El fichero está **referenciado dos veces dentro del propio wiki congelado** (`README.md:77` y
  `_Sidebar.md:105`). Renombrarlo obliga a editar el wiki en tres sitios, y `AGENTS.md` dice
  literalmente *«Do not edit it»*.
- Y sobre todo: **arreglar el paso que fallaba habría despertado los jobs `docker` y `release`**,
  que llevaban en `skipped` desde julio únicamente porque `build` moría antes. El fork no tiene
  los secretos de Docker Hub —comprobado: `gh secret list` está vacío— así que «Build and
  Release» habría seguido en rojo, solo que fallando más tarde. Y si los tuviera, habría empezado
  a publicar imágenes y prereleases que nadie consume.

**Arreglar un fallo no puede introducir comportamiento nuevo.** Esa es la lección de esta pasada.

### Lo que se hizo en su lugar

`build-release.yml` se recortó a lo único que este fork sí aprovecha: **el job `build`**.

Se quitaron los dos pasos `upload-artifact` y los jobs `docker` y `release`. Con eso el fichero
con `?` deja de importar —sin subida de artefacto no hay validación de nombres—, no hace falta
tocar el wiki congelado, y no queda nada que publique.

Un detalle que apareció al revisar y que conviene dejar escrito: el segundo `upload-artifact`
subía `path: .` con `include-hidden-files: true`, es decir **el árbol de trabajo entero, con
`.env` y `vendor/` dentro**, como artefacto descargable por cualquiera —este repositorio es
público—. Llevaba fallando desde julio, así que nunca llegó a subirse nada. Arreglarlo «bien»
habría empezado a subirlo.

### Por qué el job `build` se conserva

La tabla de la versión anterior decía que «Build and Release» no verificaba nada de este fork.
**Es falso, y era la premisa de la que colgaban las acciones 1 y 4.**

`build-release.yml` es el **único** sitio del CI donde se ejecutan `npm run build` y
`gulp compress`. Comprobado: `phpunit.yml` solo hace `npm install`; `main.yml` y `php-linter.yml`
ni tocan node. Y como el job fallaba en su **último** paso, todos los anteriores sí corrían y sí
pasaban en cada push: el workflow «roto» llevaba meses verificando algo real.

Eso importa por un motivo concreto de este proyecto: **un build de assets roto no da error**.
Sirve la página con 200 y sin una línea de CSS ni de JavaScript, y ningún *smoke test* lo detecta.
Es el mismo modo de fallo que documenta la regla de `AGENTS.md` sobre el `docker compose up
--build` manual.

Por eso también se descartó la acción 4: acotar el disparador a `master` y `develop` habría
dejado las ramas de trabajo **sin ninguna comprobación de assets**, y no habría apagado ni un
solo fallo —una ejecución verde no manda correo—. Se pagaba una pérdida real por un beneficio
que el propio documento ya había calculado en cero: *«los minutos de Actions son ilimitados y
gratuitos»*.

### Cómo se validó este cambio

El plan pasó por **revisión adversarial** antes de aplicarse, y no sobrevivió entero: de cuatro
cambios propuestos, uno se mantuvo, dos se reemplazaron y uno se descartó. Los hallazgos que lo
tumbaron —los jobs `docker`/`release` despertando, la afirmación falsa sobre `.dockerignore`, y
el job `build` siendo la única comprobación de assets— se verificaron uno por uno contra el
repositorio antes de aceptarlos.

Detalle que conviene recordar porque se usó como justificación y era **falso**: se afirmó que
«los docs no hacen falta en el contexto Docker porque `.dockerignore` ya excluye `*.md`». No es
cierto: `.dockerignore` usa reglas donde `*` **no cruza `/`**, así que `*.md` solo excluye los
markdown de la raíz. Comprobado en la imagen de producción: **72 ficheros `.md` de `docs/` están
dentro**.

## Las reglas que quedan

1. **Un fork trae los workflows del upstream, y esa decisión hay que tomarla.** Adaptar o
   deshabilitar, con la razón escrita. Nunca «a ver qué pasa».
2. **El CI rojo permanente se arregla o se apaga.** Un workflow que lleva semanas fallando sin que
   nadie actúe está mintiendo sobre el estado del proyecto.
3. **Ninguna acción de terceros anclada a rama (`@master`, `@main`) recibe un secreto.** Aquí las que
   tocan despliegue ya van fijadas a etiqueta; falta `dbfx/github-phplint`, que no recibe secretos.
4. **Este repositorio es público y se trata como público.** Nada de direcciones de servidores, rutas
   de despliegue, nombres de claves ni datos de otros proyectos, en el código ni en la documentación.

## Cómo se verificó

```bash
gh run list --repo <owner>/<repo> --limit 100 --json conclusion,name,headBranch,createdAt
gh run view <id> --log-failed
gh api repos/<owner>/<repo>/actions/workflows --jq '.workflows[] | "\(.state) \(.path)"'
gh api repos/<owner>/<repo>/environments
git ls-files | grep -E '[?":<>|*]'
```
