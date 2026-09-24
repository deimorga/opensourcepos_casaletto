# Diseño técnico — Comandas: el pedido que se toma en la mesa

> **Estado (2026-09-23, noche):** **Entregas 1 y 2 en producción** (`bcfac895f`, `master` al mismo
> commit), piloto en Casaletto (§14.2). CI verde, 1.393 pruebas. Los otros negocios tienen las tablas
> y el módulo con el interruptor apagado. Entrega 3 (cocina) sin empezar. La certificación formal por
> otra persona se reemplazó, por decisión del dueño, por el piloto.
>
> Construir la Entrega 1 corrigió varias cosas de este diseño. Están juntas en §0 y corregidas en su
> sección; donde el texto original quedó por historia, lo dice.
>
> Alcance y decisiones de negocio en `docs/Funcional/comandas-y-cuenta-abierta.md`.

**Vocabulario.** El negocio dice «comanda»; las tablas y el código dicen `order_ticket`. Los nombres
en inglés no son un capricho: todo lo que este fork ha agregado a la base —`cash_collections`,
`item_price_history`, `platform_activity_log`— está en inglés, y mezclar idiomas dentro del esquema
es peor que elegir uno. La equivalencia se escribe aquí una vez y no se repite:

| negocio | código |
|---|---|
| comanda | `order_ticket` |
| línea de la comanda | `order_ticket_line` |
| ronda (envío a cocina) | `order_ticket_round` |

---

## 0. Lo que la Entrega 1 construyó, y dónde se apartó de este diseño

Todo lo de abajo está en código y probado. Cada punto dice qué afirmaba el diseño y qué resultó.

Lo que construyó la Entrega 2 (concurrencia, formularios de un solo uso, sin señal) está en §11.2.

### 0.1 La caja JALA la comanda; el celular nunca escribe `sales_items`

**Es el cambio de fondo, y era un hueco del plan.** §2 decía que la comanda «escribe `sales`/`sales_items`
por `sale_id`», pero ninguna tarea la escribía, y la forma obvia pierde plata: el cajero guarda el
carrito en la sesión y cada edición de una pestaña borra y reinserta todos los `sales_items` desde ese
carrito (§3.1). Un plato escrito desde el celular mientras el cajero tiene esa pestaña abierta
desaparecería con su siguiente tecla: servido y nunca cobrado.

Lo que quedó:

- El celular escribe **solo** `order_ticket_lines`.
- Mientras la pestaña de una comanda es la venta activa, `Sales::_reload()` —antes de leer el
  carrito— llama `_sync_order_ticket()`, que agrega al CARRITO los platos que la caja no tiene, con la
  **misma lógica de agregar que usa el cajero** (`Order_ticket_register::add_line()`), autoguarda, y
  **solo si ese guardado llegó a la base** los estampa `billed_at`. Si no llegó, el carrito vuelve
  exactamente a como estaba; si no, el siguiente dibujo los jalaría otra vez y saldrían dos veces.
- Como el celular nunca toca `sales_items`, **el autoguardado del cajero no puede borrar un plato del
  mesero**: ese plato vive en `order_ticket_lines` hasta que la caja lo jala. Esa es la propiedad que
  hace seguro el diseño, y la prueba `OrderTicketsRegisterTest::testTheCashierAddingAnItemDoesNotLoseTheTicketLines`
  la vigila contra la caja real.

Columnas nuevas, en una migración nueva (`20260923030000_AddOrderTicketLineBilling`), porque la
primera ya estaba aplicada en staging: `billed_at` (NULL = aún no está en la venta) y
`changed_after_billed` (el mesero tocó un plato que la caja ya tenía).

### 0.2 La caja nunca cobra un total que el cajero no vio

Si entre el último dibujo de la pantalla y el toque en «Completar» llegó un plato —o la comanda se
canceló desde un celular—, `postComplete()` se detiene al principio, redibuja (que jala el plato o
quita la pestaña) y lo dice. Nada se cobra en ese toque.

### 0.3 Cambios después de pasar a la caja: se listan, no se aplican (D9, lado del cajero)

Un plato que el mesero edita o anula **después** de que la caja lo trajo sube `changed_after_billed`.
La caja **no** toca el carrito por su cuenta —el cajero puede haberlo ajustado ya—: lo lista en un aviso
con un botón «Entendido» (`POST sales/acknowledgeOrderTicket`). La bandera, como la de cocina, solo
sube si algo cambió de verdad: un formulario reenviado con los mismos valores no avisa a nadie.

### 0.4 Cobrar, cancelar y resucitar

- **Cobrar** marca la comanda `charged`: una llamada junto a `_apply_pending_reprices`, en las dos ramas
  que confirman la venta. No lanza nunca.
- **Cancelar la pestaña en la caja** cancela la comanda, con motivo «Cancelada desde la caja».
- **Cancelar la comanda en el celular** cierra su pestaña como la cierra la caja: la venta pasa a
  `CANCELED` y la mesa desechable se borra. Si el cajero tenía esa pestaña abierta,
  `_autosave_open_tab()` se niega a reescribir la venta como `OPENED` (la habría resucitado) y el
  dibujo siguiente la quita de esa caja y avisa.

### 0.5 Los kits no necesitaron columna

Los 40 kits de staging tienen su **artículo representativo** en `items` (`item_kits.item_id`,
`item_type = ITEM_KIT`). El mesero busca en artículos, la línea guarda ese `item_id`, y la caja lo
agrega como `postAdd()` agrega un kit: el representativo en `PRICE_MODE_KIT` y los componentes con
`add_item_kit()`. **Una diferencia deliberada:** `postAdd()` agrega los componentes ×1 sea cual sea la
cantidad; al jalar se pasa la cantidad como multiplicador, para que dos kits descuenten los
ingredientes de dos. La hoja de cocina muestra el kit como un plato por construcción (D13): la línea
nunca contiene los ingredientes.

### 0.6 Comandas exige Mesas, en los dos sentidos

Por la decisión de la mesa desechable (§7.4), la barra de pestañas —que vive detrás de
`dinner_table_enable`— es el único camino de la comanda a la caja. Por eso:

- encender Comandas con Mesas apagado se **rechaza** (`Config::postSaveOrderTickets()`);
- apagar Mesas con Comandas encendido **también** se rechaza (`Config::postSaveTables()`), o las
  comandas abiertas quedarían inalcanzables con los pedidos en la cocina.

Rechazar y no corregir, como `postSaveScale()`: encender o apagar el otro interruptor por debajo sería
una decisión que nadie tomó. **Esto corrige D4**, que prometía un interruptor independiente.

La pestaña «Mesas» se llamaba **«Table»** en es-MX —upstream nunca la tradujo— y el mensaje remite a
ella; ahora dice «Mesas».

### 0.7 El mesero: aterrizaje, salida y sede

> **Comandas es un permiso, no un rol (D24, 2026-09-23).** Todo lo de abajo describe al empleado con
> *solo* `order_tickets`. Un cajero con `sales` + `order_tickets` usa las dos pantallas; nada lo
> impide y nada debe impedirlo. Dos defectos lo hacían inservible y se corrigieron ese día:
> - **El mosaico del menú daba 404.** `home/home.php` y `partial/header.php` enlazan
>   `base_url($module_id)` = `/order_tickets`, y la pantalla solo tenía rutas bajo `/comandas`.
>   `Routes.php` agrega `addRedirect('order_tickets', 'comandas')`.
> - **Sin camino de vuelta a la caja.** El layout solo ofrecía «Salir». `OrderTickets::layout_data()`
>   pasa `register_url` cuando el empleado pasa `has_module_grant('sales')` —la misma comprobación de
>   `Secure_Controller`, así que el enlace nunca lleva a `no_access`—, y el layout pinta «Caja».
> **D26 (2026-09-23): el celular lleva a Comandas.** `Employee::landing_route($person_id, $from_phone)`
> devuelve `comandas` también para quien tiene `home` **si** entra desde un celular, tiene
> `order_tickets` y el interruptor está encendido. `Login::from_phone()` es
> `$this->request->getUserAgent()->isMobile()` y lo pasan los tres caminos de ingreso (normal,
> segundo factor, entrada de soporte). Solo elige la pantalla de llegada, nunca un permiso: el
> user agent es lo que el navegador dice. Lo fijan `EmployeeLandingRouteTest` (cuatro casos) y
> `LoginPhoneDetectionTest` (iPhone y Android sí, Mac y Windows no; los tres caminos pasan el dato).
> **El dispositivo no se guarda** en ningún lado: `ospos_sessions` solo tiene id, IP, hora y datos.
> Lo fijan `OrderTicketsPermissionTest::testTheMenuTileLeadsToTheScreen`,
> `testACashierWhoAlsoTakesOrdersReachesBothAndCanGoBackToTheTill` y `testAWaiterIsNotOfferedTheTill`.

Tres cosas que un mesero con **solo** el permiso de comandas no podía hacer, y ninguna estaba prevista:

- **Entrar.** Los tres caminos del login mandaban a todos a `home`, que exige el permiso `home` (en
  staging solo lo tienen las personas 1, 2 y 4). `Employee::landing_route()` manda a `comandas` a
  quien no tiene `home` pero sí `order_tickets`; para todo empleado existente no cambia nada. Trampa
  esquivada: en `Login`, `$this->employee` solo existe dentro de `index()`; usarla en `totp()` o
  `pass()` habría dado un 500 en el login con segundo factor y en la entrada de soporte.
- **Salir.** `home/logout` exige `home`. La pantalla tiene su salida propia, `comandas/salir`.
- **Tener sede.** La caja resuelve la sede por permisos de venta, y
  `Stock_location::get_default_location_id()` revienta cuando no hay fila (su propio `TODO`). Al mesero
  no se le pueden dar permisos de sede de venta: con dos sedes, dos de esos permisos pasarían
  `has_module_grant('sales')` y le abrirían la caja. Orden de resolución: la sede que la caja eligió en
  la sesión, la sede de venta del empleado, la primera sede activa. `Dinner_table::create()` tenía el
  mismo problema y ganó `create_at()` con sede explícita.

### 0.8 Permisos: un solo subpermiso, a propósito

> **Etiqueta del subpermiso (corregido 2026-09-23).** La pantalla de Empleados partía el id en el
> primer `_`: `order_tickets_void` daba `tickets_void`, no encontraba `Order_tickets.void` y mostraba
> «Tickets Void» en inglés. Ahora usa `Module::subpermission_suffix()`, que corta por el largo del
> módulo. La prueba anterior escribía `'void'` a mano en vez de usar el código de la vista, y por eso
> no lo vio.

`Employee::has_module_grant()` resuelve con `LIKE 'x%'` y, si hay ≠1 coincidencia, devuelve
`count != 0`: **con dos o más subpermisos bajo un prefijo y sin el permiso base, el módulo se abre
igual.** Comandas trae exactamente uno (`order_tickets_void`), y la cocina de la Entrega 3 irá a un
módulo propio (`kitchen_display`) cuyo prefijo no empieza por `order_tickets`. Así el hueco queda
cerrado por construcción sin tocar una función de la que dependen todos los módulos. El hueco sigue
abierto en otros (`sales` tiene tres subpermisos); arreglarlo es tarea aparte.

La clave de idioma `Order_tickets.void` es la etiqueta de ese permiso en la pantalla de Empleados y
queda **reservada**: el botón «Anular plato» usa `void_line`. Reusarla le rompió la etiqueta al
permiso de cancelar comandas y CI lo atrapó.

De paso se arregló un defecto preexistente: en `employees/form.php` la etiqueta de TODO subpermiso
salía en inglés, porque la comparación que decidía si había traducción era siempre verdadera.
`Cashups.delete` y `Cashups.reopen` estaban traducidas y nunca se veían.

### 0.9 Las migraciones SÍ corren al desplegar

§4.6, §12 y §14 dicen que los despliegues no migran y que hay que correr `php spark migrate` a mano.
**Ya no es cierto desde el 2026-08-28 (`8f92b4901`):** `docker/entrypoint.sh` migra **todos los esquemas
de negocio** con `scripts/migrate-tenants.sh` antes de que Apache acepte una petición, y si un esquema
falla Apache no arranca. Verificado al migrar staging el 2026-09-23: el log del contenedor muestra las
tres migraciones corriendo en los cuatro esquemas y `[entrypoint] All schemas current.`

La regla de `AGENTS.md` quedó desactualizada; se dejó señalado para que el dueño decida corregirla.
Las guardas `tableExists()` del camino de la caja se conservan como seguro barato, no como el camino
normal: cubren `SKIP_MIGRATIONS=1` y una tabla perdida a mano.

### 0.10 Otras correcciones al diseño

- `console_layout.php` **no es responsive** (§9.1 decía que sí): su docblock dice que es de escritorio,
  no tiene media queries y su barra no colapsa. De él solo se tomó el `viewport` y Bootstrap 5; lo
  responsive se escribió en `public/css/order_tickets.css`. **Luego descartado (D25):** la pantalla
  pasó a usar la cabecera de la caja; ver §9.1.
- `public/images/menubar/` es **salida de build** y está en `.gitignore`: el icono se agrega como una
  línea en la tarea `copy-menubar` del gulpfile. `MenubarIconsTest` exige una línea por módulo.
- Las fechas de las tres tablas son `DATETIME`, no `TIMESTAMP`: así la regla de MySQL que le pone
  `ON UPDATE CURRENT_TIMESTAMP` a la primera `TIMESTAMP` sin default no puede aparecer nunca.

---

## 1. Mapa de lo que existe

| Pieza | Dónde | Sirve |
|---|---|---|
| Cuenta abierta real | `sales.sale_status = OPENED` (`app/Config/Constants.php:139`) | **Sí, es la base de todo** |
| Persistencia de la cuenta | `Sales::_autosave_open_tab()` (`app/Controllers/Sales.php:1746`) | Sí, con reservas (§3.1) |
| Barra de pestañas | `Sale::get_all_opened()` (`app/Models/Sale.php:1334`) | Sí, con un cambio (§7.4) |
| Mesas | tabla `dinner_tables`, `app/Models/Dinner_table.php` | Parcialmente (§3.2) |
| Agente local en la caja | `tools/pos-agent/`, WebSocket en `127.0.0.1:7878` | No hace falta (§8) |
| Registro de módulo + permiso | `20260906001000_AddWriteoffsModule.php` | **Sí, es la plantilla exacta** (§6) |
| Claves de configuración | `20260902000000_AddScaleConfigKeys.php` | **Sí, es la plantilla exacta** (§5) |
| Layout responsive que ya funciona | `app/Views/platform/console_layout.php` | ~~Sí, es la plantilla exacta~~ — no era responsive, y al final no se usó: la pantalla es la de la caja (§9.1, D25) |
| Tabla propia con escritura que no puede tumbar nada | `app/Models/Item_price_history.php` | Sí, es el criterio (§4.6) |

Y lo que **no** existe, dicho sin rodeos: **no hay estaciones** (ni tabla, ni columna, ni ajuste),
**no hay estados de línea** (`sales_items` no tiene ciclo de vida), **no hay notas de cocina**, **no
hay impresoras múltiples** y **no hay forma de empujar nada al navegador** — la pantalla de venta no
tiene ni un `isAJAX()`, todo es POST de formulario y re-render completo.

`OPENED` es desarrollo propio de este fork. Upstream no aporta nada: «Impresión en cocina» es una
línea suelta en una tabla de características de su wiki, sin página, sin código y sin documentación
detrás. Quien la lea y asuma que existe algo, se equivoca.

---

## 2. El hecho que define el diseño

**La comanda es dueña de sus líneas. `sales_items` es la proyección de cobro, no la fuente.**

Esa frase es la decisión de arquitectura de todo el documento, y no se eligió por gusto: la fuerzan
§3.1 y §3.2. `sales_items` se borra entero y se reinserta en cada tecla que el cajero toca, y el
número de línea se reasigna al cambiar de pestaña. Una comanda que guarde su estado ahí **pierde el
pedido** la primera vez que alguien agregue un plato.

De modo que hay dos caminos hacia el mismo pedido, y conviene tenerlos separados en la cabeza:

```
  MESERO (celular)                        CAJERO (caja)
       │                                       │
       ▼                                       ▼
  order_tickets ──────────────────────►  sales (OPENED)
  order_ticket_lines                     sales_items
  order_ticket_rounds                          │
       │                                       ▼
       │  fuente de verdad                  cobro
       │  de LO PEDIDO                  de LO FACTURADO
       └──────────► comanda impresa
```

> **Corregido en la Entrega 1 (§0.1).** El texto original decía que la comanda escribe
> `sales`/`sales_items`. Lo que quedó es al revés: la comanda crea solo la venta `OPENED` vacía (para
> que aparezca en la barra de pestañas), y **la caja jala** los platos al carrito con su propia lógica.
> El celular nunca escribe `sales_items`.

Si el cajero edita la venta en la caja, `sales_items` cambia y las líneas de la comanda no: esa
diferencia es información, no un error, y §4.5 dice qué se hace con ella.

---

## 3. Trampas que este trabajo se va a encontrar

### 3.1 `sales_items` se BORRA y se reinserta en cada autoguardado

**Es la trampa mayor y hundiría la primera implementación.**

`Sale::save_value()` (`app/Models/Sale.php:629`) llama en su línea 654 a
`clear_suspended_sale_detail()` (`:1506`), que hace `DELETE` de **todos** los `sales_items`,
`sales_payments`, `sales_items_taxes` y `sales_taxes` de esa venta. Después reinserta las líneas
desde el carrito de sesión.

Y `_autosave_open_tab()` se dispara en **cada** agregar, editar o borrar ítem de una cuenta abierta
(`app/Controllers/Sales.php:667, 727, 1157, 1176`).

> **Consecuencia: una columna `enviado_a_cocina` en `sales_items` se pierde con el siguiente plato
> que el cajero agregue.** Quien lo dé por sentado descubrirá el defecto en producción, cuando la
> cocina empiece a recibir dos veces lo mismo.

**El estado de envío no puede vivir en `sales_items`.** Tiene que estar en una tabla propia que
nadie borre, con su propia identidad de línea.

### 3.2 `line` no es identidad estable

La PK de `sales_items` es `(sale_id, item_id, line)`, pero `line` no sobrevive conceptualmente a un
ida y vuelta por la sesión: al cambiar de pestaña, `Sale_lib::copy_entire_sale()`
(`app/Libraries/Sale_lib.php:1851`) vacía el carrito y lo reconstruye llamando `add_item()` por cada
fila, **asignando números de línea nuevos**.

Una comanda que diga «ya mandé la línea 3» está diciendo algo que puede dejar de ser cierto. Por eso
`order_ticket_lines` tiene su propia clave autoincremental (§4.2), que es la identidad estable que
`sales_items` no ofrece.

### 3.3 «Delivery» es el valor por defecto, y está excluido de las pestañas

`Sale_lib::get_dinner_table()` (`app/Libraries/Sale_lib.php:1281-1290`) asigna `1` (=`Delivery`)
cuando no hay mesa en sesión y las mesas están habilitadas. Toda venta que no escoge mesa cae ahí.

Y `_autosave_open_tab()` **sale sin hacer nada si `dinner_table <= 2`** (`:1770`), porque
`Delivery`/`Take Away` son pseudo-mesas que nunca se ocupan ni se liberan
(`Dinner_table::occupy()`/`release()`, `app/Models/Dinner_table.php:164-187`).

> **Por lo tanto: hoy la inmensa mayoría de las ventas NO pueden tener cuenta abierta.** Y esa
> exclusión no es accidental: se puso para cerrar el bug de las «pestañas fantasma»
> (`docs/Tecnico/ventas-en-paralelo-pestanas.md` §11).

La comanda necesita su propia identidad —el nombre libre de D5— **sin colgarse de `dinner_tables`**,
o se reabre ese bug. Y por lo mismo **no pasa por `_autosave_open_tab()`** (§7.3): escribe su venta
ella misma, y el guardarraíl de las pseudo-mesas queda intacto.

### 3.4 La mesa se BORRA al cobrar, no se libera

`postComplete()` (`app/Controllers/Sales.php:1206`) y `postCancel()` (`:2208`) hacen
`Dinner_table::delete()` en sus líneas `1456` y `2220` — soft-delete. Es deliberado: *«la mesa es una pestaña desechable, no un
mueble fijo»*. Cualquier diseño que asuma un catálogo estable de mesas se equivoca.

### 3.5 `print_option` no sirve para enrutar

Dos vocabularios de constantes con valores solapados (`PRINT_ALL == PRINT_YES == 0`,
`PRINT_PRICED == PRINT_NO == 1`, `app/Config/Constants.php:116-121`). Su semántica real es binaria y
sobre un único documento: *«¿este ingrediente de kit sale impreso en el recibo del cliente?»*.

No es un mecanismo de destino y no debe reutilizarse como tal. Lo que sí hace es resolver D13 de
regalo: un ingrediente de kit lleva `print_option = PRINT_NO`, así que **filtrar por esa bandera es
exactamente «el kit sale como plato y no desglosado»** (§8.2).

### 3.6 El recibo NO pasa por el agente, y fue una decisión

Se imprime con `window.print()` más `--kiosk-printing`, a la **impresora predeterminada de Windows**.
`printer.raw` existe en el agente, está probado, y **no tiene ningún consumidor en la aplicación**
(verificado: no aparece en ningún `.php` ni `.js`). La decisión del 2026-09-02 fue no acoplarse a
ESC/POS ni al ancho de 58 mm.

Para D6 —una sola impresora, la de la caja— **la comanda sigue el mismo camino que el recibo** y no
toca el agente. El día que se quiera una impresora en la cocina hay que cambiar la estructura de
configuración del agente (hoy el nombre de impresora es un texto, no una lista), su protocolo (el
mensaje no lleva destino) y reinstalar el binario en cada caja.

### 3.7 `print_silently` no hace nada

Alimenta `jsPrintSetup`, una extensión de Firefox retirada en 2017. Está dicho en
`docs/Tecnico/venta-por-peso-y-hardware-de-caja.md:1434`. No confundirlo con impresión silenciosa
real, que la da el modo quiosco de Chrome.

### 3.8 La descripción de línea no sirve, y por dos razones

`sales_items.description` es **`varchar(30)`** — `initial_schema.sql`, y ninguna migración posterior
lo amplió (verificado sobre los 21 scripts SQL y las 24 migraciones PHP propias del fork).

> Una versión anterior de este documento decía `varchar(255)`. Era falso, y la corrección importa:
> con 255 el campo estaba *ocupado*; con 30 **no cabe una instrucción de cocina** aunque estuviera
> libre. «sin cebolla y sin tomate» son 25 caracteres y ya casi no entra.

Encima está ocupado: hoy lleva `Unidad: kilogramo` en el 90 % de las líneas, metadato que arrastró la
importación de Siigo, y se imprime en el recibo del cliente.

Las notas de cocina de D7 van entonces en **un campo propio** de `order_ticket_lines`, con holgura
real (§4.2).

### 3.9 Una cuenta abierta existe en `sales` pero no en los reportes

Los reportes fijan `sale_status` explícitamente (`app/Models/Reports/Detailed_sales.php:128-159`), así
que una cuenta `OPENED` no aparece. **Pero la fila existe, con importe y líneas y sin pagos.**
Cualquier consulta nueva que no filtre por `sale_status` la va a contar.

### 3.10 Las pestañas no filtran por sede ni por empleado

`Sale::get_all_opened()` (`app/Models/Sale.php:1334`) y `Dinner_table::get_empty_tables()` no filtran
por `location_id`, pese a que ambas tablas lo tienen desde
`20260729120000_AddLocationIdToSedeHeaders.php`. **Todas las cajas de todas las sedes del mismo
negocio ven las mismas cuentas abiertas.**

Con comandas eso deja de ser teórico, y por eso `order_tickets` lleva `location_id` desde el primer
día (§4.1) aunque la consulta de pestañas siga sin filtrar: arreglar la barra de pestañas es un
trabajo aparte, pero **nacer sin la columna sería irreparable**.

### 3.11 Cambiar cliente o comentario de una cuenta abierta no se guarda

`_autosave_open_tab()` no se invoca desde `postSelectCustomer` ni desde `postSetComment`, contra lo
que dice el propio diseño de pestañas (§3 de `ventas-en-paralelo-pestanas.md`).

Como la comanda lleva su propia nota (§4.1) y no usa `sales.comment`, este defecto **no la afecta** y
no hay que arreglarlo para esta entrega. Queda anotado porque la tentación de reusar `comment` va a
aparecer.

### 3.12 El carrito vive en la SESIÓN, y una sesión guarda uno solo

`Sale_lib` no guarda el carrito en la base: lo guarda en `session('sales_cart')`
(`app/Libraries/Sale_lib.php:359-363` para leer, `:536` para escribir). El propio archivo ya lo
advierte en su comentario de la línea 370.

**Esto es lo que decide la arquitectura de la pantalla del mesero.** El celular del mesero es otra
sesión distinta de la de la caja, de modo que nada de lo que el mesero toque puede pasar por
`sale_lib`: el cajero no lo vería, porque no comparten sesión. Y aunque la compartieran, **una
sesión guarda un carrito**, así que un mesero atendiendo tres mesas se pisaría a sí mismo.

La pantalla de comanda lee y escribe **por `sale_id` y `order_ticket_id`**, nunca por el carrito de
sesión. La caja sigue usando `sale_lib` como hoy; son dos caminos hacia el mismo dato.

### 3.13 Fuera del ingreso, ninguna pantalla está preparada para un celular

`app/Views/partial/header.php` —el encabezado de **todas** las pantallas internas— no declara
`viewport`. Solo lo declaran `login.php`, `login_totp.php` y las vistas de la consola de plataforma.

En un teléfono eso significa que el navegador finge un ancho de escritorio y encoge la página. El
mesero puede **entrar** desde el celular hoy mismo; lo que ve después, no.

Agregar el `viewport` a `header.php` volvería responsive… nada, y en cambio cambiaría el renderizado
de todas las pantallas del sistema de golpe, sin una sola de ellas diseñada para ese ancho. **Es
justo el arreglito de una línea que parece gratis y no lo es.**

La pantalla de comanda lleva entonces su propio layout (§9).

> **Superado por D25 (2026-09-23).** La salida no fue un layout propio sino **opciones apagadas por
> defecto** en `header.php` (`$responsive`, `$extra_stylesheets`, `$logout_route`, `$profile_link`):
> la cabecera solo emite el `viewport` cuando una pantalla lo pide, así que el razonamiento de arriba
> sigue en pie —ninguna otra pantalla cambia— y comandas usa la cabecera de todos. Ver §9.1.

### 3.14 El mapa de configuración está cacheado

`config(OSPOS::class)->settings` se sirve de una caché, y la migración de la báscula ya dejó escrito
el problema: *«un tenant puede estar corriendo el código nuevo contra una caché que precede a esta
migración»*.

Por eso **toda lectura de las claves nuevas usa `?? '0'`** y nunca asume que la clave existe. Un
interruptor ausente se lee como apagado, que es el estado correcto para una función que D3 y D4
definen como opcional.

---

## 4. El modelo de datos

Tres tablas nuevas. Ninguna columna nueva en `sales` ni en `sales_items` — §3.1 las borraría.

### 4.1 `order_tickets` — la comanda

| columna | tipo | por qué |
|---|---|---|
| `order_ticket_id` | INT AI PK | La identidad estable que `line` no da (§3.2) |
| `sale_id` | INT NULL | La venta `OPENED` que la cobra. NULL solo entre crear la comanda y crear su venta, dentro de la misma transacción |
| `name` | VARCHAR(64) NOT NULL | El nombre libre de D5: «ANDREA», «mesa 4», «domicilio Juan» |
| `status` | VARCHAR(16) NOT NULL | `open` / `delivered` / `cancelled` / `charged` (D14). Código estable, nunca etiqueta: el mismo criterio que `payment_type_code`, `cash_source`, `unit_of_measure` y `item_price_history.source` |
| `location_id` | INT NOT NULL | §3.10. Nace con ella aunque nadie la filtre todavía |
| `note` | VARCHAR(255) NOT NULL DEFAULT '' | Nota de pedido. **No se usa `sales.comment`** (§3.11) |
| `opened_by` | INT NOT NULL | `people.person_id`. D17: cada comanda tiene un responsable |
| `opened_at` | DATETIME NOT NULL | |
| `delivered_by` / `delivered_at` | INT NULL / DATETIME NULL | La gestión de orden de D14 |
| `cancelled_by` / `cancelled_at` | INT NULL / DATETIME NULL | |
| `cancel_reason` | VARCHAR(255) NOT NULL DEFAULT '' | Si se cae una comanda ya impresa, la operación quiere saber por qué |
| `charged_at` | DATETIME NULL | Lo escribe `postComplete()` |

Índices: `idx_status_location (status, location_id)` — la pregunta de toda pantalla es «las comandas
vivas de esta sede»; `idx_sale_id`; `idx_opened_at` para el corte del día de D14/P3.

**Sin llaves foráneas**, por el mismo criterio que `item_price_history` y `platform_activity_log`: un
fallo de FK convertiría un registro en un fallo de escritura, y la comanda debe sobrevivir a que
alguien borre la venta.

`status` es texto y no un `tinyint`: `sale_status` ya enseñó lo que cuesta un número cuyo significado
vive en otro archivo.

### 4.2 `order_ticket_lines` — lo que se pidió

| columna | tipo | por qué |
|---|---|---|
| `order_ticket_line_id` | INT AI PK | **La identidad estable de línea.** Es la respuesta a §3.2 |
| `order_ticket_id` | INT NOT NULL | |
| `item_id` | INT NOT NULL | |
| `item_name` | VARCHAR(255) NOT NULL | Copia al momento de capturar. Si mañana renombran el artículo, la comanda impresa y la pantalla siguen diciendo lo que el mesero pidió |
| `quantity` | DECIMAL(15,3) NOT NULL | Mismo tipo que `sales_items.quantity_purchased` |
| `unit_price` | DECIMAL(15,2) NOT NULL | Copia. D12 pide precios en la comanda, y el precio se puede repreciar desde la caja (`item_price_history`) |
| `kitchen_note` | VARCHAR(255) NOT NULL DEFAULT '' | **D7.** Campo propio porque `sales_items.description` son 30 caracteres y están ocupados (§3.8) |
| `round_id` | INT NULL | NULL = pedida pero **no enviada** a cocina. Es la mitad de D8 |
| `status` | VARCHAR(16) NOT NULL | `pending` / `sent` / `voided`. `voided` es anulación lógica: una línea ya impresa **nunca se borra** |
| `changed_after_send` | TINYINT(1) NOT NULL DEFAULT 0 | **D9.** Se tocó algo que ya estaba en cocina: se permite, y se avisa |
| `captured_by` | INT NOT NULL | |
| `captured_at` | DATETIME NOT NULL | |

Índices: `idx_ticket (order_ticket_id, status)`; `idx_round (round_id)`.

`item_name` y `unit_price` copiados es deliberado y es el mismo criterio con el que
`item_price_history` guarda `previous_price`: **un documento que se reinterpreta cada vez que se lee
no es un documento.**

### 4.3 `order_ticket_rounds` — cada envío a cocina

| columna | tipo | por qué |
|---|---|---|
| `round_id` | INT AI PK | |
| `order_ticket_id` | INT NOT NULL | |
| `number` | INT NOT NULL | 1, 2, 3… dentro de la comanda. Es lo que se imprime como «RONDA 2» |
| `sent_at` | DATETIME NOT NULL | |
| `sent_by` | INT NOT NULL | |
| `printed_at` | DATETIME NULL | NULL = se mandó pero no se confirmó impresión |

Índice: `idx_ticket_number (order_ticket_id, number)` UNIQUE — dos rondas con el mismo número en la
misma comanda es un defecto, y conviene que la base lo diga.

### 4.4 Cómo se resuelve D8 sin ambigüedad

«La segunda comanda imprime solo lo agregado» es, con este modelo, **una sola consulta**:

```sql
SELECT * FROM order_ticket_lines
 WHERE order_ticket_id = ? AND round_id IS NULL AND status <> 'voided'
```

Enviar a cocina es: crear la ronda, asignarle esas líneas, pasarlas a `sent`, imprimir esa ronda.
Todo dentro de una transacción. **Si no hay líneas con `round_id IS NULL`, no hay nada que enviar y
el botón no hace nada** — que es lo que evita que la cocina reciba dos veces lo mismo cuando alguien
pulsa dos veces.

### 4.5 Qué pasa si el cajero edita la venta

Nada se rompe, y nada se sincroniza hacia atrás. La comanda registra **lo que se pidió**; la venta
registra **lo que se cobró**. Que difieran es el caso normal de D9: se quitó un plato que ya estaba
en cocina, o el cajero corrigió una cantidad.

Lo único que se hace es **no perder la diferencia**: al cobrar, `charged_at` queda escrito y la
comanda con sus líneas queda tal cual. Quien compare las dos cosas —un reporte futuro— tiene los dos
lados. Sobrescribir las líneas de la comanda con las de la venta borraría exactamente la evidencia
que D9 pide conservar.

### 4.6 La escritura no puede tumbar la venta

Todo lo que este módulo escriba **desde el camino de la caja** —marcar `charged` en `postComplete()`,
sobre todo— va envuelto en `try/catch (Throwable)` con `log_message('critical', …)` y una guarda
`tableExists()` cacheada, copiando `Item_price_history::record()`
(`app/Models/Item_price_history.php:117-152`).

**OBSERVAR NO PUEDE TUMBAR LO OBSERVADO.** La razón que se escribió aquí —«los despliegues no corren
migraciones»— quedó vieja (§0.9): el contenedor migra al arrancar. La guarda se conserva como seguro
barato para `SKIP_MIGRATIONS=1` y para una tabla perdida a mano, caso en que `is_latest()` sigue en
verdadero y la caja sigue vendiendo. Toda la lógica de la caja vive en
`App\Libraries\Order_ticket_register`, cuyos métodos públicos nunca lanzan.

Esto aplica al camino de la caja. En las pantallas propias del módulo un fallo sí debe verse: ahí el
usuario está usando la comanda, no vendiendo.

---

## 5. Los dos interruptores

D4 enciende las comandas por comercio; D15 enciende la cocina **aparte**. Son dos claves en
`app_config`, sembradas apagadas, con la plantilla de `20260902000000_AddScaleConfigKeys.php`:

| clave | por defecto | qué apaga |
|---|---|---|
| `order_tickets_enable` | `'0'` | Todo el módulo. Apagado, la aplicación se comporta **exactamente** como hoy: sin menú, sin rutas útiles, sin nada en la pantalla de venta |
| `order_tickets_kitchen_enable` | `'0'` | Solo la pantalla de cocina (Entrega 3). Sin efecto si el anterior está apagado |

Ambas se leen **siempre** con `?? '0'` (§3.14).

**Con la cocina apagada, las comandas funcionan completas, y eso no depende del interruptor.** La
caja jala lo **no cobrado** (`Order_ticket_line::get_unbilled()`: `billed_at IS NULL` y no anulado),
nunca lo **enviado**: un plato que jamás pasó por «Enviar a cocina» llega a la caja y se cobra igual.
Hoy `order_tickets_kitchen_enable` solo se guarda desde Configuración; ningún camino de ejecución lo
lee, porque la pantalla de cocina (Entrega 3) no existe todavía.

Lo fija `OrderTicketsRegisterTest::testWithTheKitchenOffADishNeverSentIsBilledAndCharged`, y se
certificó a mano en staging el 2026-09-23 (comanda 4 «SIN COCINA», POS 990007, cero rondas).
**Condición para la Entrega 3:** la pantalla de cocina puede leer rondas y líneas, pero la caja no
puede empezar a exigir `round_id`. Si alguien condiciona el jalón a «enviado», un comercio sin
cocina deja de cobrar lo que toman sus meseros sin que nada falle a la vista. Esa prueba es la que
lo impide.

### 5.1 Dónde se configuran — decidido el 2026-09-22

> **Los dos interruptores viven en la pantalla de Configuración del propio comercio**, en una
> pestaña nueva «Comandas», al lado de la pestaña «Mesas» que ya existe
> (`app/Views/configs/table_config.php`, clave `dinner_table_enable`).

Las razones: es donde vive el interruptor de la función más parecida; es el patrón que el sistema ya
tiene; y **la consola de plataforma hoy no escribe ni una fila en el `app_config` de ningún negocio**
(verificado sobre `PlatformAdmin`, `PlatformContext` y los modelos de plataforma).

La alternativa que se consideró y se descartó —que lo encendiera el superadministrador desde la
consola— habría obligado a abrir un camino de escritura desde `platform_control` hacia el esquema de
cada negocio, que no existe y que es justo el tipo de acoplamiento que el aislamiento multi-tenant
evita.

---

## 6. Módulo y permisos

Plantilla literal: `20260906001000_AddWriteoffsModule.php`. Los menús se construyen desde `modules`
unido a los grants del empleado (`Module::get_allowed_home_modules`), así que **un módulo sin grants
es invisible**: no sale en la barra, no sale en los mosaicos, y `Secure_Controller` convierte una URL
tecleada en una redirección a `no_access`.

| permiso | quién | qué abre |
|---|---|---|
| `order_tickets` | el mesero, el cajero | Tomar y editar comandas, enviarlas a cocina, marcarlas entregadas |
| `order_tickets_void` | el encargado | **Cancelar** una comanda (D14). Separado porque cancelar una comanda ya impresa es la acción que alguien va a querer auditar |
| `order_tickets_kitchen` | la pantalla de cocina | Solo ver. Entrega 3 |

**La migración no concede ni un grant, y eso es el punto** — la misma razón escrita en
`AddWriteoffsModule`: conceder automáticamente metería un módulo que el negocio no pidió en el menú
de una tienda que vende con este código todos los días. Los grants se hacen a mano desde Empleados,
para el comercio que lo pida.

### 6.1 El mesero no puede ver la caja

Un mesero con **solo** `order_tickets` ve solo esa pantalla. Eso ya lo garantiza la maquinaria
existente, y está probado en producción por el caso contrario: Ángela Rodríguez, con 19 módulos de
inicio y cero de oficina, provocó el 500 del 2026-09-01 documentado en `Secure_Controller`.

Lo que **sí** hay que verificar antes de construir (§15): qué le abre hoy el módulo `sales` a quien
lo tiene, porque la tentación de reusarlo en vez de crear `order_tickets` va a aparecer y le daría la
caja entera al mesero.

---

## 7. Componentes

### 7.1 Rutas — explícitas, no auto-routing

```php
$routes->get ('comandas',                        'OrderTickets::getIndex');
$routes->get ('comandas/nueva',                  'OrderTickets::getNew');
$routes->post('comandas/crear',                  'OrderTickets::postCreate');
$routes->get ('comandas/(:num)',                 'OrderTickets::getShow/$1');
$routes->post('comandas/(:num)/linea',           'OrderTickets::postAddLine/$1');
$routes->post('comandas/(:num)/linea/(:num)',    'OrderTickets::postEditLine/$1/$2');
$routes->post('comandas/(:num)/enviar',          'OrderTickets::postSend/$1');
$routes->get ('comandas/(:num)/ronda/(:num)',    'OrderTickets::getRound/$1/$2');   // la hoja a imprimir
$routes->post('comandas/(:num)/entregada',       'OrderTickets::postDelivered/$1');
$routes->post('comandas/(:num)/cancelar',        'OrderTickets::postCancel/$1');
$routes->get ('comandas/cocina',                 'OrderTicketsKitchen::getIndex');  // Entrega 3
```

Explícitas por la misma razón que `items/bulk` (`app/Config/Routes.php:18-23`): **el mesero va a dejar
la pantalla abierta en el teléfono y la va a recargar.** Las direcciones tienen que ser estables, y
el auto-routing las ata al nombre del método.

La ruta en español mientras las clases están en inglés es deliberada: es la URL que un mesero puede
llegar a teclear.

### 7.2 Modelos

- `app/Models/Order_ticket.php` — la comanda y sus transiciones de estado.
- `app/Models/Order_ticket_line.php` — las líneas, la consulta de D8 (§4.4), la anulación lógica.
- `app/Models/Order_ticket_round.php` — crear ronda y cerrarla.

Cada uno con `$allowedFields` **idéntico** a la constante `WRITABLE_COLUMNS` de su migración, y una
prueba que compara las dos listas: CodeIgniter descarta en silencio un campo ausente de
`$allowedFields`, **y este proyecto ya perdió datos por eso dos veces**.

Las transiciones de estado viven en el modelo, no en el controlador, y son explícitas:

```
open      → delivered | cancelled | charged
delivered → charged  | cancelled
cancelled → (terminal)
charged   → (terminal)
```

Una transición no permitida devuelve `false` y no lanza. Cancelar una comanda ya cobrada es el caso
que D14 excluye —«antes de que se haya solicitado el pago»— y el modelo es el sitio donde esa regla
no se puede saltar desde ninguna pantalla.

### 7.3 Controladores

- `app/Controllers/OrderTickets.php` — `extends Secure_Controller` con `$module_id = 'order_tickets'`.
- `app/Controllers/OrderTicketsKitchen.php` — Entrega 3.

**Ninguno de los dos toca `Sale_lib`.** Escriben `sales`/`sales_items` por `sale_id` a través de
`Sale`, y **no pasan por `_autosave_open_tab()`**: así el guardarraíl de las pseudo-mesas (§3.3)
queda intacto y no se reabre el bug de las pestañas fantasma.

`Sales::postComplete()` gana **una sola llamada**, envuelta como manda §4.6: marcar la comanda
`charged`. Nada más. Es el único punto en que este módulo toca el camino del dinero.

### 7.4 La cuenta visible en la pantalla de venta

El dueño pidió que la comanda *«debe ser visible en el módulo de venta como una cuenta nueva, y se
debe ir actualizando en la medida que la comanda se actualice»* (§4.8 del funcional).

> **Corregido en la Entrega 1.** Esta sección decía que bastaba un LEFT JOIN. Era falso: la barra de
> pestañas entera vive dentro de `if ($config['dinner_table_enable'])` (`register.php`) y el clic
> **reabre por `dinner_table_id`** (`.open_tab_button` → `#mode_form` → `Sales::postChangeMode()` →
> `Sale::get_open_sale_by_table()`). No hay camino de reapertura por `sale_id`.

**Decisión del dueño (2026-09-23): la mesa desechable.** Al abrir una comanda se crea una mesa propia,
nacida ocupada, con el nombre cortado a 30 caracteres —lo mismo que hace el botón «nueva mesa» y lo
que el negocio ya hacía a mano—. Con eso la barra la lista, el clic la reabre y `postComplete()` la
borra al cobrar, sin código nuevo en esa mecánica. El costo: **Comandas exige Mesas** (§0.6).

El rótulo cae en cascada: `Sale::get_all_opened()` hace LEFT JOIN a `order_tickets` —solo si la tabla
existe— y la vista muestra **nombre de comanda (64), si no nombre de mesa, si no `#id`.**

«Se va actualizando» significa que **cada dibujo de la pantalla de venta jala los platos nuevos**
(§0.1). No hay empuje del servidor al navegador; el cajero ve la comanda al día en cualquier acción
que haga sobre esa pestaña, y la caja se niega a cobrar si llegó algo después del último dibujo
(§0.2).

### 7.5 Vistas

| vista | entrega | layout |
|---|---|---|
| `app/Views/order_tickets/screen.php` | 1 | **el de la caja** (`partial/header`), responsive (§9.1, D25) |
| `app/Views/order_tickets/new.php` | 1 | el de la caja; formulario sin JavaScript para abrir una comanda |
| `app/Views/order_tickets/round_print.php` | 1 | **sin layout**: hoja limpia para imprimir (§8) |
| `app/Views/order_tickets/kitchen.php` | 3 | propio |
| `app/Views/configs/order_tickets_config.php` | 1 | el de Configuración, como `table_config.php` |

### 7.6 Idioma

Claves nuevas en `en/`, `es-ES/` **y `es-MX/`**. **La aplicación corre en es-MX**: una cadena escrita
solo en es-ES es invisible y la pantalla sale en inglés sin dar ningún error.

Y nada de comillas simples alrededor de un marcador: en ICU MessageFormat `'{0}'` se imprime
literal, no da error, y solo sale mal.

---

## 8. La impresión

### 8.1 El camino

El mismo que el recibo (§3.6): una vista propia, `window.print()`, impresora predeterminada, modo
quiosco de Chrome. **No se toca el agente local.** `getRound()` devuelve la hoja de una ronda
concreta y la imprime; reimprimir es volver a abrir esa URL, que es una propiedad útil y gratis.

### 8.2 Qué sale en el papel

- **Con precios** (D12).
- **El kit como plato, nunca desglosado** (D13). Se resuelve filtrando `print_option = PRINT_NO`,
  que es exactamente la bandera del ingrediente de kit (§3.5).
- **Solo las líneas de esa ronda** (D8), con «RONDA n» visible.
- **La nota de cocina bajo cada plato** (D7), desde `kitchen_note`.
- **Sin el `Unidad: kilogramo`** de §2.4 del funcional. En la comanda no se imprime la descripción de
  línea: ese arrastre se queda en el recibo del cliente, donde ya vive, y limpiarlo del catálogo es
  un trabajo aparte que esta entrega **no necesita**.

### 8.3 Del teléfono no sale papel

El celular del mesero no alcanza la impresora de la caja. La comanda se imprime **desde la caja**.
Si el mesero envía a cocina desde la mesa, lo que ocurre es que la ronda queda creada y marcada como
enviada; el papel sale cuando alguien abre esa ronda en la caja.

Esa es la costura honesta del diseño sin aplicación móvil, y hay que decirla en la capacitación: **el
envío y la impresión son dos actos**, y `printed_at` en NULL es precisamente «se mandó y todavía no
se imprimió».

---

## 9. La pantalla del mesero, responsive

### 9.1 La pantalla es la de la caja (D25)

**Corregido el 2026-09-23.** La primera versión tenía layout propio (`order_tickets/layout.php`,
Bootstrap 5 Flatly, búsqueda por GET que recargaba la página). El dueño la rechazó por romper la
línea de diseño: quien usa la caja tiene que reconocer la pantalla. Se rehízo así:

- `order_tickets/screen.php` usa `partial/header` y `partial/footer`: el **tema del negocio**, el menú,
  el aviso de sesión de soporte y el paquete de CSS/JS de siempre, que ya trae `register.css` y
  jQuery UI.
- Usa los **mismos ids** de `sales/register.php` —`register_wrapper`, `open_tabs_bar`,
  `add_item_form`, `register`, `overall_sale`, `sale_totals`, `payment_totals`— y los mismos textos
  (`Sales.item_number`, `Sales.quantity_of_items`…), así que `register.css` la dibuja igual que la venta.
- **Búsqueda en vivo** con el mismo `autocomplete` de jQuery UI, contra `comandas/buscar`
  (`OrderTickets::getSearch()`, JSON `{value, label}` desde `Item::search_orderable()`). Endpoint
  propio porque el mesero no tiene el permiso `sales` y no se le debe dar para buscar. Elegir un
  resultado envía `#ot_add_form` y el plato se agrega de una vez.
- La tabla de platos: los campos de cada fila apuntan con el atributo `form` a formularios fuera de
  la tabla (un `<form>` no puede envolver un `<tr>`); el de edición lleva `seen_quantity`/`seen_note`.
- El panel derecho reemplaza el pago por **Enviar a cocina / Marcar entregada / Cancelar**. No hay
  ninguna ruta de cobro en la vista (`OrderTicketsScreenDesignTest::testTheScreenCannotCharge`).
- La cabecera recibe cuatro opciones que solo pasa `OrderTickets::layout_data()`, todas apagadas por
  defecto: `responsive` (emite el `viewport`), `extra_stylesheets` (`css/order_tickets.css`),
  `logout_route` (`comandas/salir`: `home/logout` exige el permiso `home`) y `profile_link` (el
  cambio de clave también está detrás de `home`).
- `public/css/order_tickets.css` **solo** agrega lo que la caja no necesita, y todas sus reglas
  empiezan por `#ot_screen` (lo verifica una prueba): no puede alcanzar la caja. Bajo 768 px apila los
  paneles, vuelve cada plato una tarjeta con etiquetas (`data-label`), entradas a 16 px (con menos,
  iOS hace zoom) y objetivos de 44 px. La lista del autocompletado se monta dentro de `#ot_screen` y
  se limita al ancho de la pantalla: sin eso, un nombre largo corría la página hacia los lados.
- Sin JavaScript sigue funcionando: la búsqueda cae a un GET que lista los resultados, y «+ Nueva
  comanda» es un enlace al formulario.

Verificado en staging el 2026-09-23 con Chrome sin ventana a 1440 px y a 390 px (sin desplazamiento
horizontal): lista, búsqueda en vivo y agregar un plato eligiéndolo de la lista.

**La hoja lleva huella de contenido** (`css/order_tickets.css?v=<8 hex de md5>`,
`OrderTickets::stylesheet_url()`). El servidor la entrega sin `Cache-Control` y cada navegador adivina
cuánto guardarla: el iPhone del dueño se quedó con la hoja del diseño rechazado —mismo nombre, reglas
de otra pantalla— y la nueva salió con los paneles encimados. El paquete común no tiene el problema
porque gulp-rev le pone la huella en el nombre; esta hoja no va en el paquete.

### 9.2 Lo que la pantalla tiene que hacer bien en un móvil

- Buscar un artículo y agregarlo con el pulgar, sin teclado físico.
- Escribir la nota de cocina sin que el teclado virtual tape el botón de guardar.
- Enviar a cocina con un gesto claro y **no repetible por accidente** (§4.4 ya lo hace idempotente).
- Sobrevivir a una recarga: toda la información está en la base, nada en `sessionStorage`.

### 9.3 El mesero entra por el ingreso normal

Ya funciona en móvil: `login.php` declara `viewport`. Con solo el grant `order_tickets`, el mesero
aterriza en su pantalla y no ve nada más (§6.1).

---

## 10. La pantalla de cocina — Entrega 3

Opcional (D15), con su propio interruptor (§5), y **es la parte cara**: hoy no hay ningún canal
servidor→navegador. El WebSocket del agente local va del navegador a la máquina de la caja, no del
servidor a una pantalla en cocina.

La forma barata y honesta es **polling**: la pantalla se recarga sola cada N segundos contra
`comandas/cocina`. Con un solo monitor por local y comandas que se cuentan por decenas al día, eso
alcanza de sobra y no obliga a montar infraestructura de tiempo real.

Lo que esta entrega necesita y la 1 no: saber **qué** cambió en una línea ya enviada, no solo que
cambió. El `changed_after_send` de §4.2 responde «esto se tocó»; una pantalla de cocina útil quiere
«esto pasó de 2 a 3». Eso pide una tabla de eventos de línea, y **es la razón por la que la pantalla
de cocina es una entrega aparte y no un añadido**.

---

## 11. Concurrencia — la Entrega 2 y su trampa

Dos meseros sobre la misma comanda es el caso que **no se puede posponer** cuando la captura se hace
desde varios teléfonos.

Con el modelo de §4 el daño ya está acotado, y esa es media solución: como cada línea es una fila
propia con su `order_ticket_line_id`, **dos meseros agregando platos distintos no se pisan** — es un
INSERT cada uno. El problema queda reducido a dos casos:

1. **Editar la misma línea a la vez.** Se resuelve con control optimista, sin bloqueos: un candado
   sobre una mesa en un restaurante lleno es peor que un reintento. (Este párrafo decía que la
   pantalla mandaría `changed_after_send` y `captured_at`. **No sirven**: `captured_at` no cambia al
   editar, y `changed_after_send` se queda en 1 después del primer cambio. Lo construido compara los
   **valores**; ver §11.2.)
2. **Enviar a cocina dos veces a la vez.** Idempotente por §4.4, y con el bloqueo de la fila de la
   comanda para que dos envíos no se intercalen (§11.2).

### 11.2 Lo que construyó la Entrega 2

**Edición con lo que la pantalla vio** — `Order_ticket_line::edit_line_seen()`. El formulario de
edición lleva dos campos ocultos, `seen_quantity` y `seen_note`, con la cantidad y la nota **tal como
están guardadas** al dibujar la página. La comprobación va en el `WHERE` del mismo `UPDATE` que
escribe, así que «¿sigue igual?» y «escribir» son un solo paso en la base; leer primero y escribir
después reabriría la ventana. La cantidad se compara como número (`2` contra `2.000` no es cambio) y
la nota byte a byte (`BINARY`: la colación ignora mayúsculas, y «SIN CEBOLLA» sobre «sin cebolla» es
un cambio que la cocina lee). Cero filas afectadas se desambigua releyendo la fila **solo para
elegir el mensaje**: línea anulada o inexistente → rechazo; ya tiene exactamente lo pedido → éxito
(el mismo formulario enviado dos veces no es un conflicto); cualquier otra cosa → `EDIT_CONFLICT`.
Un POST sin los campos `seen_*` —una página dibujada antes de este cambio— se trata como página
desactualizada: no se guarda nada. Dejarlo pasar sin comprobar convertiría en silencio una edición
protegida en una sin proteger.

**Envío** — `Order_ticket_round::send()`:

- `SELECT … FOR UPDATE` sobre la fila de la comanda **antes** de leer nada: el segundo envío espera
  al primero. `OrderTicketRoundTest` lo prueba con una segunda conexión real que sostiene el bloqueo.
- «¿Hay algo que enviar?» se pregunta **bajo el bloqueo y antes de crear la ronda**. Nunca nace una
  ronda vacía.
- **Un fallo nunca es un `null`.** `null` significa «no había nada que enviar». Un fallo de la base
  —bloqueo que no se liberó a tiempo, conexión perdida— lanza `Order_ticket_send_failed` después de
  cerrar la transacción y limpiar su estado (`resetTransStatus()`). Lo encontró la prueba de
  concurrencia: una versión anterior contestaba `null` a un *lock wait timeout*, y la pantalla le
  decía al mesero «no hay platos nuevos» cuando la cocina no tenía ninguno. Son mensajes opuestos
  (`nothing_to_send` y `send_failed`) porque llevan al mesero a hacer cosas opuestas.
- Por qué se revisa cada consulta contra `false`: **dentro de una transacción CodeIgniter 4.7 no
  lanza por una consulta fallida, ni con `DBDebug` encendido** (`BaseConnection::query()`: «In
  transactions, do not throw exception by default»), salvo `transException(true)`. La consulta
  devuelve `false` y el estado de la transacción queda marcado como fallido para el resto de la
  petición.

**Formularios de un solo uso** — `Order_ticket_request_guard`. Cada página dibujada lleva un token
de 32 hexadecimales en todos sus formularios. El primer POST que lo trae lo consume (se guarda en la
sesión, los últimos 200); el segundo con el mismo token no hace nada y dice «ya se había guardado».
Un POST sin token es una página vieja: no se guarda nada y se recarga. Es lo que cubre el reenvío
del navegador al recuperar la señal y el doble toque que el JavaScript no alcanzó a frenar.

**Sin señal** — `screen.php`, mejora progresiva, el servidor no depende de ella: si
`navigator.onLine` es `false` al enviar un formulario, no se envía y se muestra «Sin señal: no se
envió nada». Distingue «no se guardó» de la página de error del navegador, que no dice nada. Los
botones se deshabilitan mientras viaja la petición y se rehabilitan en `pageshow` (volver atrás o
una página restaurada tras un fallo), excepto el de enviar cuando el servidor lo dibujó deshabilitado
(`data-disabled-by-server`). **No se inventa ningún estado optimista**: la pantalla solo muestra lo
que la base tiene.

**Lo que no está construido y no se va a construir aquí:** modo sin conexión, cola de reenvío,
Service Worker. Lo que se estaba escribiendo cuando cayó la señal se pierde (§11.1).

**Lo que queda de la Entrega 2 y no se puede automatizar:** medir §9.2 en un teléfono real (teclado
virtual sobre el botón de guardar, objetivos táctiles) y el turno de certificación en staging con dos
meseros, dos teléfonos y la caja cobrando.

### 11.1 La conectividad no es un riesgo del proyecto: es un requisito no funcional del local

**La aplicación se sirve por internet público**, con HTTPS a través de Traefik sobre
`*.ospos-saas.micronuba.net` (`docker-compose.staging.yml:109-112`). El teléfono del mesero la
alcanza como alcanzaría cualquier página web: por WiFi **o por los datos móviles de su plan**. No
hay servidor en el local, no hay red local de por medio y no hay nada que descubrir sobre eso.

> Una versión anterior de este documento listaba «probar la red del local desde un teléfono» como el
> riesgo con más capacidad de hundir la Entrega 2. **Era falso**, y nacía de suponer un servidor en
> el local que este sistema no tiene.

Lo correcto es tratarlo como lo que es: **un requisito no funcional que el comercio garantiza**, al
mismo nivel que tener luz o tener impresora. Va en la ficha de requisitos que se le entrega a cada
cliente antes de encender las comandas:

- Cobertura de datos móviles aceptable dentro del local, o WiFi que llegue a las mesas.
- Un teléfono por mesero con navegador actualizado.

Lo que **sí** es asunto del software, y es distinto: **no hay modo sin conexión.** Si la señal se
cae a mitad de un pedido, lo ya guardado está a salvo —cada línea es un POST propio— y lo que se
estaba escribiendo se pierde. Montar captura sin conexión sería otro proyecto. El mesero que se
queda sin red vuelve al papel, que es lo que hace hoy.

---

## 12. Migraciones

En orden, con la convención de nombres del repositorio:

| archivo | qué hace |
|---|---|
| `20260923000000_AddOrderTickets.php` | Las tres tablas de §4, con `WRITABLE_COLUMNS_*` públicas |
| `20260923010000_AddOrderTicketsConfigKeys.php` | Las dos claves de §5, sembradas en `'0'` |
| `20260923020000_AddOrderTicketsModule.php` | Módulo y **dos** permisos (`order_tickets`, `order_tickets_void`), §0.8. **Sin conceder ni un grant** |
| `20260923030000_AddOrderTicketLineBilling.php` | `billed_at` y `changed_after_billed` en las líneas (§0.1) |

Reglas que este repositorio ya aprendió a golpes y que aplican aquí:

- **`$this->db->resetDataCache()` antes de cualquier `tableExists()`.** La lista de esquemas del
  driver se arma al arrancar el proceso, así que en el mismo despliegue que creó la tabla una guarda
  puesta antes del reset responde «no existe». Ya pasó en producción con el backfill de unidades de
  medida.
- **Nunca sobrescribir una clave existente**: un comercio puede haberla configurado a mano.
- ~~`php spark migrate` a mano por SSH.~~ **Corregido (§0.9):** el contenedor migra todos los
  esquemas al arrancar. Lo que va en el runbook es **comprobar** que migró: el log del contenedor debe
  terminar en `[entrypoint] All schemas current.`

---

## 13. Pruebas

Las que de verdad protegen, no cobertura de adorno:

- `tests/Models/OrderTicketTest.php`
  - `testAllowedFieldsCoverEveryWritableColumn()` — el descarte silencioso de CI4, siguiendo
    `CashCollectionTest::testAllowedFieldsCoverEveryWritableColumnOfTheTable()`.
  - `testTheStateMachineRefusesToCancelAChargedTicket()` — la regla de D14 donde no se puede saltar.
- `tests/Models/OrderTicketLineTest.php`
  - **`testSendingTwiceDoesNotSendTheSameLineAgain()`** — la prueba más valiosa del conjunto: es el
    defecto que le llega a la cocina.
  - `testVoidingASentLineKeepsTheRow()` — la anulación es lógica, nunca un DELETE.
- `tests/Controllers/OrderTicketsAutosaveTest.php`
  - **`testTheCashierAddingAnItemDoesNotLoseTheTicketLines()`** — §3.1, la trampa mayor, probada de
    frente: abrir comanda, enviar ronda, que el cajero agregue un ítem a la venta, y comprobar que
    las líneas de la comanda siguen enteras y siguen marcadas como enviadas.
- `tests/Controllers/OrderTicketsPermissionTest.php`
  - `testAWaiterCannotReachTheRegister()` — lo que §6.1 promete.
  - `testCancellingNeedsItsOwnPermission()`.
- `tests/Models/OrderTicketMigrationTest.php`
  - Correr la migración dos veces es no-op.
  - **`testCompletingASaleStillWorksWhenTheTicketTablesAreMissing()`** — la ventana de §4.6, que es
    la diferencia entre un despliegue olvidado y un negocio que no puede cobrar.

La base de pruebas es **compartida** entre archivos: una prueba que escriba `app_config` rompe
pruebas de otros archivos, y el fallo sale donde no está la causa. Las dos claves de §5 se tocan con
esa precaución.

---

## 14. Entregas y despliegue

| # | Qué | Se puede desplegar sola porque… |
|---|---|---|
| **1** | Migraciones + modelos + `OrderTickets` + vistas responsive + impresión + interruptor | Apagada por defecto. Un comercio que no la enciende **no nota nada** |
| **2** | Concurrencia (§11), el mesero en el teléfono en un local real, capacitación | No hay esquema nuevo: es endurecer lo de la 1 contra varios teléfonos |
| **3** | Pantalla de cocina + su interruptor + eventos de línea | Segundo interruptor, apagado. Sin efecto en quien no lo encienda |

La pantalla responsive se construye **en la Entrega 1**, no en la 2: hacerla de escritorio y
rehacerla después sería trabajo tirado. Lo que la Entrega 2 agrega no es la pantalla, es lo que hace
falta para que varios teléfonos la usen a la vez sin pisarse.

**Compuerta antes de producción**, cada entrega: suite verde en CI (workflow «PHPUnit Tests»),
certificado **en staging sobre la interfaz real y no por quien escribió el código**, respaldo de las
tres bases, imagen de retorno etiquetada, comprobar en el log que el entrypoint migró (§0.9), y
**producción después de las 22:00 hora Colombia**.
La verificación contra producción es de solo lectura: conteos, logs, y **ninguna transacción de
prueba**.

---

### 14.1 Runbook de la Entrega 1

**Desplegar** (staging primero; producción solo después de las 22:00 hora Colombia):

1. Etiquetar la imagen que sirve como punto de retorno:
   `docker tag <id-de-la-imagen-que-corre> casaletto-ospos:rollback-AAAAMMDD`.
2. Respaldar las bases: un `mariadb-dump --single-transaction` por esquema, y comprobar que cada
   volcado termina en `-- Dump completed`.
3. `git fetch origin <rama>` + `git reset --hard` + `git clean -fd`, y
   `docker compose -f docker-compose.<env>.yml up -d --build ospos`. La imagen construye sola vendor,
   assets e iconos.
4. Comprobar: el log termina en `[entrypoint] All schemas current.`; en cada esquema existen las tres
   tablas y las dos claves en `'0'`; **cero grants** de `order_tickets%`; 21 iconos en la imagen, entre
   ellos `order_tickets.svg`; el login y el encabezado interno con sus referencias de CSS/JS iguales a
   las de producción.

5. **Completar los permisos del empleado de soporte** en todos los negocios:
   `docker compose -f docker-compose.<env>.yml exec -T ospos php spark platform:support-employee`
   (sin slug recorre todos los activos; idempotente, solo agrega lo que falte). Las migraciones de
   comandas agregan `order_tickets` y `order_tickets_void`, y el empleado de soporte recibe los
   permisos que existían **cuando se creó**: sin este paso, una sesión de soporte **no ve el módulo
   Comandas en el menú** aunque todo lo demás esté bien. Pasó en staging el 2026-09-23: el dueño dio
   el permiso a su empleado, entró por soporte —que es otro empleado, `soporte_micronuba`— y no vio
   nada. No va en el arranque a propósito (cabecera de `PlatformSupportEmployee.php`: un fallo ahí
   tumbaría Apache).

**Encender para un comercio** (con el comercio, no por defecto):

1. Configuración → pestaña **Mesas** → encender (si no lo está).
2. Configuración → pestaña **Comandas** → encender.
3. Empleados → crear a cada mesero con **solo** el permiso **Comandas**. «Permitir cancelar una
   comanda» se le da solo a quien supervisa.
4. Entregar la ficha de requisitos (funcional §4.10) y explicar que **enviar e imprimir son dos
   actos** (§8.3).

**Certificación en staging** (por alguien que no escribió el código), sobre la interfaz real: los once
pasos del plan, empezando con los dos interruptores apagados para comprobar que la caja se comporta
exactamente como hoy.

### 14.2 Producción, 2026-09-23 — lo que se hizo

Autorizado por el dueño con el local cerrado (última venta 20:50; despliegue desde las 21:21).

1. Imagen de retorno `casaletto-ospos:rollback-20260923` (`b9b4bb351149`, lo que corría: `32f280c07`).
2. Respaldo de las cuatro bases en `/root/backups/prod-20260923-pre-comandas/`, cada una terminada en
   `Dump completed`.
3. Despliegue de `4b94cc051`; el entrypoint migró los tres negocios (`All active tenants migrated
   cleanly`). Las tres tablas quedaron en `ospos`, `tenant_paraisodelacanasta` y
   `tenant_diversosoluciones`.
4. `php spark platform:support-employee`: 2 permisos completados en cada uno de los tres negocios.
5. En `ospos` (Casaletto): `order_tickets_enable = 1`, `order_tickets_kitchen_enable = 0`; permisos
   `order_tickets` (menú Inicio) y `order_tickets_void` a las personas 1–6. Caché de configuración
   borrada (`writable/cache/settings_*`).
6. Verificación de solo lectura: ingreso 200, `/comandas` redirige al ingreso, hoja e icono servidos,
   58/3 referencias de assets iguales, cero comandas, ninguna venta nueva.
7. Segundo despliegue, `bcfac895f` (D26), con imagen de retorno `casaletto-ospos:rollback-20260923b`
   (= `4b94cc051`). `master` adelantado a `bcfac895f`.

> **Vuelta atrás: usar `rollback-20260923b`, NO `rollback-20260923`.** Esta última es de antes de las
> migraciones de comandas. Volver a ella con las bases ya migradas hace que `MY_Migration::is_latest()`
> dé falso y `Load_config` destruya la sesión en cada petición: nadie puede entrar, con todo en verde.
> Si hubiera que volver al código anterior a comandas, es imagen **y** respaldo de base juntos.
> Apagar el piloto no necesita volver atrás: basta `order_tickets_enable = 0` y borrar la caché.

## 15. Lo que hay que medir o confirmar antes de construir

- **Qué permisos arrastra hoy el módulo `sales`.** Antes de crear `order_tickets` hay que ver qué le
  abriría a un mesero reusar el que existe.
- **Cuántas cuentas abiertas simultáneas** aguanta la barra de pestañas antes de estorbar.
- **Si `_autosave_open_tab()` a cada tecla** es aceptable con pedidos de hasta 48 líneas, porque cada
  uno borra y reinserta todas las líneas de la venta (§3.1).

---

## 16. Riesgos anotados

1. **La ventana sin migración.** Código vivo y tablas ausentes en algún esquema, porque los
   despliegues no migran. Mitigado por §4.6, y es la razón de que exista esa sección.
2. **Que alguien «simplifique» metiendo el estado en `sales_items`.** Es la forma más probable de
   romper esto, parece más limpia, y §3.1 explica por qué se pierde. La prueba
   `testTheCashierAddingAnItemDoesNotLoseTheTicketLines()` existe para que esa simplificación falle
   en CI y no en la cocina.
3. **El `viewport` en `header.php`.** El arreglito de una línea que parece gratis y cambia el
   renderizado de todo el sistema (§3.13).
4. **Comanda impresa y luego cancelada.** La cocina ya preparó. El sistema no puede resolverlo; lo
   que hace es **dejar constancia** con `cancel_reason`, que es lo que D14 pide.
5. **Las pestañas no filtran por sede** (§3.10). Con dos sedes y comandas, una caja ve las cuentas de
   la otra. `location_id` nace con la tabla; **arreglar la barra de pestañas es trabajo aparte** y
   hay que decidirlo antes de que un segundo comercio con dos sedes encienda esto.
6. **Sin señal no hay modo sin conexión** (§11.1). Lo guardado está a salvo; lo que se estaba
   escribiendo se pierde. La cobertura en sí **no es un riesgo del proyecto**: es un requisito no
   funcional que el comercio garantiza, como la luz o la impresora.
7. **El envío y la impresión son dos actos** (§8.3). Es consecuencia directa de no tener aplicación
   móvil ni impresora en cocina, y va en la capacitación o se vive como un defecto.
