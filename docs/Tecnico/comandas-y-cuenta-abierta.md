# Diseño técnico — Comandas: el pedido que se toma en la mesa

> **Estado:** requerimiento **cerrado** el 2026-09-22. **Nada construido todavía.**
> Alcance y decisiones de negocio en `docs/Funcional/comandas-y-cuenta-abierta.md`.
>
> Relevado sobre `32f280c07`.

---

## 1. Mapa de lo que existe

| Pieza | Dónde | Sirve |
|---|---|---|
| Cuenta abierta real | `sales.sale_status = OPENED` (`app/Config/Constants.php:139`) | **Sí, es la base de todo** |
| Persistencia de la cuenta | `Sales::_autosave_open_tab()` (`app/Controllers/Sales.php:1746`) | Sí, con reservas (§3.1) |
| Barra de pestañas | `Sale::get_all_opened()` (`app/Models/Sale.php:1300`) | Sí |
| Mesas | tabla `dinner_tables`, `app/Models/Dinner_table.php` | Parcialmente (§3.2) |
| Agente local en la caja | `tools/pos-agent/`, WebSocket en `127.0.0.1:7878` | Sí, para imprimir |
| Orden por categoría | ajuste `line_sequence = 2` (`app/Models/Sale.php:1000`) | Marginal |

Y lo que **no** existe, dicho sin rodeos: **no hay estaciones** (ni tabla, ni columna, ni ajuste),
**no hay estados de línea** (`sales_items` no tiene ciclo de vida), **no hay notas de cocina**, **no
hay impresoras múltiples** y **no hay forma de empujar nada al navegador** — la pantalla de venta no
tiene ni un `isAJAX()`, todo es POST de formulario y re-render completo.

`OPENED` es desarrollo propio de este fork. Upstream no aporta nada: «Impresión en cocina» es una
línea suelta en una tabla de características de su wiki, sin página, sin código y sin documentación
detrás. Quien la lea y asuma que existe algo, se equivoca.

---

## 2. El hecho que define el diseño

**La comanda no es un documento nuevo: es una cuenta abierta que ya se sabe representar.**

Tomar un pedido crea una fila en `sales` con `sale_status = OPENED` y sus `sales_items`. Eso ya
funciona, ya se persiste y ya se muestra como pestaña. Lo que falta es (a) que una cuenta pueda
existir sin ser una mesa, (b) que se sepa qué líneas ya fueron a cocina, y (c) imprimir.

---

## 3. Trampas que este trabajo se va a encontrar

### 3.1 `sales_items` se BORRA y se reinserta en cada autoguardado

**Es la trampa mayor y hundiría la primera implementación.**

`Sale::save_value()` (`app/Models/Sale.php:595`) llama en su línea 619 a
`clear_suspended_sale_detail()` (`:1472`), que hace `DELETE` de **todos** los `sales_items`,
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

Una comanda que diga «ya mandé la línea 3» está diciendo algo que puede dejar de ser cierto.

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
o se reabre ese bug.

### 3.4 La mesa se BORRA al cobrar, no se libera

`postComplete()` (`app/Controllers/Sales.php:1455`) y `postCancel()` (`:2219`) hacen
`Dinner_table::delete()` — soft-delete. Es deliberado: *«la mesa es una pestaña desechable, no un
mueble fijo»*. Cualquier diseño que asuma un catálogo estable de mesas se equivoca.

### 3.5 `print_option` no sirve para enrutar

Dos vocabularios de constantes con valores solapados (`PRINT_ALL == PRINT_YES == 0`,
`PRINT_PRICED == PRINT_NO == 1`, `app/Config/Constants.php:116-121`). Su semántica real es binaria y
sobre un único documento: *«¿este ingrediente de kit sale impreso en el recibo del cliente?»*.

No es un mecanismo de destino y no debe reutilizarse como tal.

### 3.6 El recibo NO pasa por el agente, y fue una decisión

Se imprime con `window.print()` más `--kiosk-printing`, a la **impresora predeterminada de Windows**.
`printer.raw` existe en el agente, está probado, y **no tiene ningún consumidor en la aplicación**
(verificado: no aparece en ningún `.php` ni `.js`). La decisión del 2026-09-02 fue no acoplarse a
ESC/POS ni al ancho de 58 mm.

Para D6 —una sola impresora, la de la caja— **la comanda puede seguir el mismo camino que el
recibo** y no tocar el agente. El día que se quiera una impresora en la cocina hay que cambiar la
estructura de configuración del agente (hoy el nombre de impresora es un texto, no una lista), su
protocolo (el mensaje no lleva destino) y reinstalar el binario en cada caja.

### 3.7 `print_silently` no hace nada

Alimenta `jsPrintSetup`, una extensión de Firefox retirada en 2017. Está dicho en
`docs/Tecnico/venta-por-peso-y-hardware-de-caja.md:1434`. No confundirlo con impresión silenciosa
real, que la da el modo quiosco de Chrome.

### 3.8 La descripción de línea ya está ocupada

`sales_items.description` es `varchar(255)` y hoy lleva `Unidad: kilogramo` en el 90 % de las líneas
— metadato de la importación de Siigo. **No es un campo libre**: es la descripción del artículo,
sobrescribible por línea, y se imprime en el recibo del cliente.

Las notas de cocina de D7 necesitan **un campo propio**, y además hay que limpiar ese arrastre.

### 3.9 Una cuenta abierta existe en `sales` pero no en los reportes

Los reportes fijan `sale_status` explícitamente (`app/Models/Reports/Detailed_sales.php:126-161`), así
que una cuenta `OPENED` no aparece. **Pero la fila existe, con importe y líneas y sin pagos.**
Cualquier consulta nueva que no filtre por `sale_status` la va a contar. Relacionado con P4.

### 3.10 Las pestañas no filtran por sede ni por empleado

`Sale::get_all_opened()` (`app/Models/Sale.php:1300`) y `Dinner_table::get_empty_tables()` no filtran
por `location_id`, pese a que ambas tablas lo tienen desde
`20260729120000_AddLocationIdToSedeHeaders.php`. **Todas las cajas de todas las sedes del mismo
negocio ven las mismas cuentas abiertas.** Con comandas eso deja de ser teórico.

### 3.11 Cambiar cliente o comentario de una cuenta abierta no se guarda

`_autosave_open_tab()` no se invoca desde `postSelectCustomer` ni desde `postSetComment`, contra lo
que dice el propio diseño de pestañas (§3 de `ventas-en-paralelo-pestanas.md`). Si la comanda va a
llevar un comentario de pedido, eso hay que arreglarlo primero.

### 3.12 El carrito vive en la SESIÓN, y una sesión guarda uno solo

`Sale_lib` no guarda el carrito en la base: lo guarda en `session('sales_cart')`
(`app/Libraries/Sale_lib.php:359-363` para leer, `:536` para escribir). El propio archivo ya lo
advierte en su comentario de la línea 370.

**Esto es lo que decide la arquitectura de la pantalla del mesero.** El celular del mesero es otra
sesión distinta de la de la caja, de modo que nada de lo que el mesero toque puede pasar por
`sale_lib`: el cajero no lo vería, porque no comparten sesión. Y aunque la compartieran, **una
sesión guarda un carrito**, así que un mesero atendiendo tres mesas se pisaría a sí mismo.

La pantalla de comanda tiene que leer y escribir `sales`/`sales_items` **por `sale_id`**, no por el
carrito de sesión. La caja sigue usando `sale_lib` como hoy; son dos caminos hacia el mismo dato.

### 3.13 Fuera del ingreso, ninguna pantalla está preparada para un celular

`app/Views/partial/header.php` —el encabezado de **todas** las pantallas internas— no declara
`viewport`. Solo lo declaran `login.php`, `login_totp.php` y las vistas de la consola de plataforma.

En un teléfono eso significa que el navegador finge un ancho de escritorio y encoge la página. El
mesero puede **entrar** desde el celular hoy mismo; lo que ve después, no.

Agregar el `viewport` a `header.php` volvería responsive… nada, y en cambio cambiaría el renderizado
de todas las pantallas del sistema de golpe, sin una sola de ellas diseñada para ese ancho. **Es
justo el arreglito de una línea que parece gratis y no lo es.**

La pantalla de comanda lleva entonces su propio layout. `app/Views/platform/console_layout.php` es
el precedente que ya funciona en este repositorio y la plantilla a copiar.

---

## 4. Forma del diseño

### 4.1 Dónde vive el estado de envío

Tabla propia, **nunca una columna en `sales_items`** (§3.1). Tiene que responder: qué se mandó, de
qué cuenta, cuándo, quién, y en qué ronda. Y su identidad de línea no puede ser `line` (§3.2).

### 4.2 Cómo se identifica una cuenta sin ser una mesa

El nombre libre de D5 vive en la cuenta, no en `dinner_tables` (§3.3). Eso evita reabrir el bug de
las pestañas fantasma y sirve igual para salón que para domicilio.

### 4.3 La impresión

Mismo camino que el recibo: una vista propia, `window.print()`, impresora predeterminada (§3.6).
**Con precios** (D12) y con el kit como plato, nunca desglosado (D13).

### 4.4 Los estados de la comanda

D14 pide abierta / entregada / cancelada / cobrada. `sales.sale_status` **no** sirve para esto: solo
distingue `SUSPENDED` de `OPENED`, y es la columna de la que dependen las pestañas y los reportes.
El estado de la comanda va en la misma tabla propia de §4.1, que es la que ya existe para no tocar
`sales_items`.

«Cancelada» es un estado, **no** un borrado: una comanda que se cae después de haberse impreso ya
consumió papel y quizá cocina, y esa es precisamente la información que la operación quiere ver.

### 4.5 La pantalla del mesero, responsive

Pantalla propia, con layout propio (§3.13) y acceso directo a `sales`/`sales_items` por `sale_id`
(§3.12). No es la pantalla de venta encogida: la de venta pesa, depende de atajos de teclado y de
`sale_lib`, y ninguna de esas tres cosas sirve en un teléfono.

El mesero entra por el ingreso normal, que ya funciona en móvil, con un permiso propio que le da
**solo** esta pantalla. Hoy, con los permisos existentes, darle acceso a comandas le daría la caja
entera.

Lo que esta entrega tiene que resolver y no se puede posponer: **dos meseros sobre la misma comanda**
—§3.1 borra y reinserta todas las líneas en cada guardado, así que el último en guardar gana y el
otro pierde su ronda sin enterarse— y qué se hace cuando el teléfono pierde señal a mitad del pedido.

### 4.6 La pantalla de cocina

**Es la Entrega 3, es opcional y es la parte cara.** Hoy no hay ningún canal servidor→navegador: ni
AJAX, ni polling, ni WebSocket hacia la aplicación. El agente local tiene un WebSocket, pero es del
navegador hacia la máquina de la caja, no del servidor hacia una pantalla en cocina.

Va de última porque el dueño la separó del resto (D15): un comercio puede usar comandas sin tener
nada en cocina, así que son **dos interruptores**, no uno.

---

## 5. Lo que hay que medir antes de construir

- **Cuántas cuentas abiertas simultáneas** aguanta la barra de pestañas antes de estorbar.
- **El arrastre de `Unidad: …`**: cuántos artículos hay que limpiar y si la limpieza se hace en el
  catálogo o solo en la impresión.
- **Si `_autosave_open_tab()` a cada tecla** es aceptable con pedidos de hasta 48 líneas, porque cada
  uno borra y reinserta todas las líneas de la venta (§3.1).
- **La red del local desde un teléfono.** Toda la captura del mesero depende de que el celular
  alcance el servidor de pie junto a la mesa, y eso no se ha probado en ningún local.
- **Qué permisos existentes arrastra un mesero.** Antes de crear el permiso de §4.5 hay que ver qué
  le abre hoy el módulo de ventas a quien lo tiene.
