# Multi-tenant: de Casaletto a plataforma SaaS multi-negocio

> **Documento vivo.** Se actualiza al cierre de cada fase del desarrollo si algo cambia respecto a lo aquí descrito. Ver también el documento técnico: `docs/Tecnico/multi-tenant-arquitectura.md`.

## 1. Contexto y motivación

opensourcepos_casaletto es un fork de Open Source POS que hoy opera en producción para un único negocio: Casaletto. El plan de negocio es empezar a vender la misma solución a otros restaurantes-cliente, manteniendo Casaletto como cliente propio y primero.

Escala esperada: 10-20 negocios-cliente en el primer año, hasta ~100 eventualmente. Cada negocio-cliente puede tener, además, varias sedes propias (sucursales) — esto **ya existe hoy** en el sistema vía el concepto de "sede" (`stock_locations`) y no se rediseña; el cambio de este proyecto agrega una capa por encima: la de **negocio** (tenant).

Distinción clave que usa este documento:
- **Negocio / tenant**: un cliente que le compra la plataforma (ej. Casaletto, o el restaurante que se sume después). Cada negocio es independiente y no comparte datos con otro.
- **Sede**: una sucursal física de un mismo negocio (ej. Casaletto Centro, Casaletto Norte). Varias sedes pueden pertenecer al mismo negocio.

## 2. Objetivo

Convertir la plataforma en un SaaS multi-negocio donde:
- Cada negocio-cliente tiene sus datos completamente aislados de los demás (ventas, inventario, empleados, configuración).
- Dar de alta un negocio nuevo es una operación de rutina, sin migraciones de datos, sin tocar infraestructura, sin afectar a los negocios ya activos.
- Casaletto sigue operando exactamente igual que hoy durante y después de la transición — se convierte en el primer negocio-cliente ("tenant #1") de la plataforma, sin migración de datos ni corte de servicio.

## 3. Roles

| Rol | Quién es | Qué cambia respecto a hoy |
|---|---|---|
| **Empleado de un negocio** | Mesero, cajero, administrador del restaurante | **Nada.** Sigue entrando por la URL de su negocio (ej. `casaletto.midominio.com`) con su usuario y contraseña de siempre. Sus permisos y sedes siguen funcionando igual. |
| **Dueño de negocio** | Propietario de uno o más negocios-cliente registrados en la plataforma | **Nuevo.** Si tiene un solo negocio, no ve diferencia. Si tiene más de un negocio (ej. compró dos franquicias distintas), entra por un login neutral y elige a cuál de sus negocios quiere entrar. |
| **Administrador de plataforma** | Equipo de Casaletto / operador del SaaS | **Nuevo.** Login separado, exclusivo para gestionar el alta de negocios nuevos en la plataforma. No puede "entrar como" un negocio-cliente para operar en su nombre (fuera de alcance de este proyecto). |

## 4. Flujos funcionales

### 4.1 Login de empleado (sin cambios)
El empleado entra por la URL de su negocio, pone usuario/contraseña, y usa el sistema exactamente igual que hoy — ventas, inventario, sedes, reportes, todo sin diferencia perceptible.

### 4.2 Login de dueño con más de un negocio (nuevo)
1. El dueño entra a un login neutral de la plataforma (no a la URL de un negocio específico).
2. Si su cuenta está ligada a un solo negocio, entra directo a ese negocio.
3. Si está ligada a varios, ve un selector de negocio.
4. Al elegir uno, se le redirige a la URL de ese negocio, donde vuelve a autenticarse con las credenciales de empleado de ese negocio específico (no es un "entrar sin contraseña" — es una comodidad de no tener que recordar la URL de cada negocio, no un inicio de sesión único real).

### 4.3 Alta de un negocio nuevo (nuevo)
El administrador de plataforma da de alta un negocio-cliente nuevo desde la **plataforma de gestión de negocios** (panel web, ver sección 5): define un identificador único (slug/subdominio), y el sistema aprovisiona todo lo necesario para que ese negocio pueda empezar a operar de inmediato, con sus propios datos completamente separados de cualquier otro negocio. Desde ese mismo panel también puede modificar los datos de un negocio existente, suspenderlo (deja de ser accesible sin borrar su información) o eliminarlo.

### 4.4 Gestión de sedes dentro de un negocio (sin cambios)
Cada negocio sigue pudiendo tener varias sedes, gestionarlas, y filtrar reportes/ventas/inventario por sede, exactamente como ya funciona hoy.

## 4b. Qué pasa cuando una dirección no corresponde a ningún negocio (corregido 2026-08-30)

Cada negocio entra por su propia dirección. Faltaba definir qué debe ver alguien que llega a una
dirección **que no corresponde a ningún negocio activo**, y hasta esta fecha veía algo indebido:
**la pantalla de entrada de Casaletto**.

No era una fuga de información —seguía pidiendo usuario y contraseña, y las claves de un negocio no
sirven en la base de otro—, pero era el comportamiento equivocado, y tenía una consecuencia concreta
que se iba a notar apenas entrara el segundo cliente:

> **Suspender un negocio no lo bloqueaba: lo mandaba a la caja de Casaletto.**

Si algún día suspendemos a un cliente por falta de pago, su personal habría terminado frente a la
pantalla de otro negocio.

**Cómo quedó:**

| Quién llega | Qué ve ahora |
|---|---|
| Un negocio activo, por su dirección | Su propia caja, como siempre |
| Un negocio **suspendido** | *"Este negocio está suspendido. Comuníquese con su proveedor del servicio."* |
| Una dirección **mal escrita o inexistente** | *"Este negocio no existe."* |
| Casaletto, por su dirección de siempre | **Exactamente igual que antes.** No cambia nada |

Se distinguen a propósito los dos avisos: un cliente suspendido tiene que poder saber que está
suspendido, y no confundirlo con haber escrito mal la dirección.

**Lo que esto NO cambia:** la dirección de siempre de Casaletto, y la de cualquier negocio activo,
se comportan idénticamente. La corrección solo actúa sobre direcciones que antes no debían haber
funcionado.

## 5. Alcance y fuera de alcance

**Dentro de alcance de este proyecto:**
- Aislamiento completo de datos entre negocios.
- Alta de negocios nuevos sin afectar a los existentes.
- Login de dueño con selector simple entre sus negocios.
- **Plataforma de gestión de negocios**: un panel web (no solo una herramienta de línea de comandos) para que el administrador de plataforma pueda crear, modificar, suspender y eliminar negocios-cliente — confirmado explícitamente como requisito, no como algo opcional, el 2026-07-31.
- Actualización del stack tecnológico (ver documento técnico) como parte del mismo trabajo, dado que se toca la misma infraestructura de base de datos y despliegue.

**Fuera de alcance (decisión explícita, no se construye en este proyecto):**
- Inicio de sesión único real (SSO) entre negocios de un mismo dueño — la versión actual requiere volver a autenticarse al cambiar de negocio.
- "Entrar como" un negocio-cliente desde el panel de administrador de plataforma (impersonation de soporte).
- Analítica o reportes consolidados entre varios negocios-cliente (se podría resolver más adelante con una integración separada, sin necesidad de rediseñar el aislamiento de datos).

## 6. Estado de avance

> **Estado actual — verificado el 2026-08-22.** El proyecto está **terminado y en producción**. Las
> once fases (0 a 10) están completas desde el **2026-08-03**, y Casaletto opera como el primer
> negocio-cliente real de la plataforma, con certificado HTTPS válido. Comprobado el mismo 2026-08-22
> con una petición de solo lectura: `casaletto.ospos-saas.micronuba.net` responde 200 con TLS válido,
> y la dirección de siempre `pos-casaletto.micronuba.net` responde 200 en paralelo, tal como se
> diseñó. Las tres ramas (`develop`, `master`, `feature/multi-tenant-saas`) quedaron niveladas en el
> mismo commit el 2026-08-04. **No hay pendientes bloqueantes.**
>
> Lo que sigue es el registro fase por fase, con las fechas en que cada una se cerró. Donde un párrafo
> dice "solo en la rama de desarrollo" o "sin desplegar", describe el estado **de esa fecha** — todo
> eso quedó desplegado en la Fase 10.


Fase 0 (documentación), Fase 1 (cerrar huecos de sede en ventas, turnos, gastos, recepciones y mesas) y Fase 2 (actualización de MariaDB y PHP a versiones con soporte vigente) completas — sin impacto visible para el usuario final. **Las tres ya están desplegadas en staging y producción reales** (Fase 1 el 2026-07-30, verificado 100% de filas con `location_id`, sin errores). Fase 3 (schema donde vivirá el registro de negocios-cliente), Fase 4 (el mecanismo que detecta a qué negocio pertenece cada visita según la URL, y aísla su conexión a datos) y Fase 5 (la herramienta que, cuando se publique un cambio de base de datos, lo aplica automáticamente a cada negocio registrado, avisando si alguno falla) completas. *(Estado al cerrarlas: solo en la rama de desarrollo, sin nada visible para ningún usuario, y probado a fondo que Casaletto seguía funcionando igual mientras no estuviera registrado como negocio-cliente. Desplegadas a producción en la Fase 10, el 2026-08-03.)*

Fase 6 (la infraestructura de enrutamiento que permitirá que cada negocio tenga su propia dirección web) completa, con dos ajustes importantes decididos el 2026-07-31:

- **Cambio de marca del dominio del SaaS**: la dirección de cada negocio-cliente ya no cuelga de la marca de Casaletto (`negocio.pos-casaletto.micronuba.net`, diseño original) sino de un dominio propio de la plataforma: **`negocio.ospos-saas.micronuba.net`**. Razón: Casaletto es un cliente más de la plataforma, no la plataforma misma — no tiene sentido atar la marca de todo el SaaS a la de uno solo de sus clientes. Cuando Casaletto se dé de alta formalmente como negocio-cliente (Fase 10), su nueva dirección será `casaletto.ospos-saas.micronuba.net`; su dirección actual (`pos-casaletto.micronuba.net`) sigue funcionando en paralelo sin ningún cambio. El nombre `ospos-saas` es **provisional** — se podrá ajustar más adelante, siempre respetando las políticas de licenciamiento de Open Source POS, cuyo código se reutiliza en este proyecto.
- **Incidente real y revertido en producción**: al aplicar el cambio de dominio, un detalle de configuración de Traefik (la pieza de infraestructura que dirige el tráfico web) provocó que tanto producción como staging dejaran de responder brevemente. Se identificó la causa, se corrigió, y **por instrucción explícita del usuario se revirtió producción** al estado anterior (la dirección de Casaletto de siempre, sin el dominio nuevo del SaaS) mientras el sistema seguía operando y vendiendo en vivo — **producción no se toca mientras está en operación activa, solo después de las 10pm hora Colombia**, salvo autorización puntual explícita. Staging sí conserva el dominio nuevo, sin ningún negocio-cliente activo todavía, así que no hay ningún riesgo para datos reales de Casaletto. **El despliegue del dominio nuevo a producción se ejecutó después, en la Fase 10 (2026-08-03, ~21:48–22:20 hora Colombia), con la operación de Casaletto ya cerrada esa noche.**

Fase 7 (la herramienta interna que da de alta un negocio nuevo con un solo comando: crea su espacio de datos separado, sus propias credenciales de acceso a base de datos, aplica la estructura de tablas completa, y reemplaza el usuario/contraseña de administrador por defecto por uno nuevo y aleatorio, nunca reutilizando ninguna credencial de Casaletto) completa. *(En su momento solo en la rama de desarrollo; desplegada en la Fase 10.)* Probada de punta a punta creando un negocio de prueba real y confirmando que sus datos quedan completamente separados de los de Casaletto, incluso si algo en el código fallara. Detalle técnico en `docs/Tecnico/multi-tenant-arquitectura.md`.

Fase 8 (login de dueño + **plataforma de gestión de negocios**, el panel web para crear/modificar/suspender/eliminar negocios-cliente) **completa**. *(En su momento solo en la rama de desarrollo; desplegada y funcionando en producción desde la Fase 10.)* Su alcance quedó confirmado y ampliado explícitamente el 2026-07-31, a raíz de que el usuario preguntó directamente cómo se manejarían distintas empresas, si cada una tendría su propia URL de login, y si existiría algún módulo o plataforma para gestionar la creación, modificación o eliminación de negocios — la respuesta es sí, y ese panel quedó como requisito confirmado de esta fase, no un extra.

Probado de punta a punta con un negocio de prueba real: login del administrador de plataforma, alta de un negocio nuevo **desde el panel web** (no desde la línea de comandos), suspensión y reactivación, y confirmación de que el negocio nuevo, al entrar por su propia dirección, ve sus propios datos (no los de Casaletto ni los de ningún otro negocio).

**Durante esta prueba de punta a punta se encontraron y corrigieron 4 fallas reales**, 2 de ellas preexistentes desde antes de este proyecto de negocios múltiples y potencialmente graves si hubieran llegado a producción sin corregir:
- Una falla que, de no haberse corregido, habría hecho que **ningún empleado pudiera mantener la sesión iniciada** en ningún negocio (incluido Casaletto) tan pronto el registro de negocios (Fase 3) se desplegara a un ambiente real — la sesión se cerraba sola en cada acción.
- Una falla en el mecanismo que detecta a qué negocio pertenece cada visita, que hacía que a veces no se aplicara correctamente.
- Una falla en cómo se guardaba la contraseña de acceso a la base de datos de cada negocio, que se guardaba incompleta y por lo tanto no servía.
- Un ajuste al diseño original de permisos del panel de gestión, tras confirmar con el usuario cómo prefería resolver una limitación real de la base de datos.

Las cuatro quedaron corregidas y verificadas de nuevo con el mismo negocio de prueba antes de dar la fase por cerrada. Detalle técnico completo en `docs/Tecnico/multi-tenant-arquitectura.md`.

**Fase 9 (validación en staging) completa — primer ensayo real de cómo será el paso de Casaletto a producción (2026-07-31).** Con autorización explícita del usuario, y con el cuidado explícito de no perder ni un dato de la Casaletto real que ya vive en staging: se tomó respaldo completo antes de tocar nada (tres copias independientes), se desplegó toda esta fase al ambiente de staging por primera vez, y se ejecutó ahí el mismo procedimiento que se usará en producción: **registrar la Casaletto real de staging como el primer negocio del SaaS, sin migrar ni tocar un solo dato suyo.**

Verificado exhaustivamente después: los conteos de empleados, ventas, artículos y personas quedaron **exactamente iguales** a los de antes de empezar; el usuario administrador de Casaletto no se tocó; la dirección web de siempre de Casaletto en staging siguió funcionando idéntica todo el tiempo; y entrando por la nueva dirección de negocio, se ve exactamente la misma información que por la dirección de siempre — confirmando que "convertir a Casaletto en un negocio más del SaaS" no mueve ni copia ningún dato, solo activa una nueva forma de llegar a los mismos datos de siempre.

También se probó el panel de gestión de negocios funcionando de verdad en staging (no solo en una prueba local), mostrando a Casaletto ya listado como negocio activo.

**Importante para el despliegue de la Fase 2 en staging/producción** (no en desarrollo local): el salto de versión de MariaDB requiere un procedimiento cuidadoso porque esos ambientes tienen datos reales de Casaletto — no es un cambio que se aplique solo con el deploy automático, hay que planificarlo como una ventana de mantenimiento explícita.

**Actualización (2026-07-30)**: ejecutado en **staging y producción**, ambos con verificación de que ningún dato se perdió (checksums idénticos entre la BD vieja y la nueva antes de dar cada cambio por bueno). Los dos ambientes corren en PHP 8.4 + MariaDB 11.4 desde ahora. Los volúmenes viejos en MariaDB 10.5 se conservan sin tocar en el VPS como respaldo adicional.

**Fase 10 (puesta en producción) completa — 2026-08-03.** Es la fase que convirtió todo lo anterior
en algo real para el negocio. Se ejecutó entre las ~21:48 y las ~22:20 hora Colombia, con el usuario
confirmando explícitamente que la operación de Casaletto ya había cerrado esa noche, siguiendo el
mismo procedimiento ya ensayado en staging en la Fase 9 y sin sorpresas nuevas.

Qué quedó funcionando ese día:

- **Casaletto dado de alta como el primer negocio-cliente real de la plataforma**, sin migrar ni copiar
  un solo dato: sigue usando su misma base de datos de siempre.
- **Su dirección nueva `casaletto.ospos-saas.micronuba.net` operativa con certificado HTTPS válido**,
  y **su dirección de siempre `pos-casaletto.micronuba.net` funcionando en paralelo sin cambios** —
  nadie tuvo que aprenderse una dirección nueva ni hubo interrupción del servicio.
- El panel de gestión de negocios disponible en producción, con Casaletto ya listado.
- Cualquier negocio-cliente nuevo que se dé de alta a partir de ahora obtiene su dirección propia con
  HTTPS automático, sin tocar la infraestructura de nuevo.

El certificado comodín (el que cubre de una vez a todos los negocios futuros) se resolvió de fondo ese
mismo día, después de cerrar la fase, no quedó como deuda.

**Qué falta, sin urgencia y sin bloquear nada:** dar de alta negocios de prueba adicionales para una
validación cruzada de aislamiento más exhaustiva, e igualar el nombre y el tema visual de staging a
los de producción (una diferencia de datos que ya existía antes de este proyecto). El trabajo real que
sigue no es técnico: es sumar negocios-cliente nuevos, y la plataforma ya está lista para eso.

## 7. Continuidad de Casaletto

Un punto central de este proyecto: **Casaletto no migra datos a ningún lado.** El negocio actual se convierte en el primer negocio-cliente de la plataforma usando su base de datos actual tal cual está. El resto de los negocios se suman después, cada uno con sus propios datos, sin tocar los de Casaletto.
