# Diseño técnico — Venta por peso, hardware de caja e inventario para supermercado

> **Estado a 2026-08-28: en construcción.** Vías **V1, V2 y V3 implementadas y en `develop`**
> (`524da70b2`): el entrypoint que migra antes de servir, la columna `unit_of_measure`, y los cuatro
> defectos de precisión del peso.
>
> **Verificación pendiente y NO menor:** el demonio de Docker está caído en la máquina de
> desarrollo, así que **las 138 pruebas con base de datos nunca se han ejecutado** — incluidas las
> que prueban estos arreglos. Las 130 sin base de datos pasan, y ninguna falla es de aserción: los
> 138 errores son todos `Connection refused`. **Esto no está verificado hasta correr la suite
> completa contra una base real.**
>
> **Actualización del 2026-08-28:** báscula documentada del todo — ROCHI RC-A01E, chip CH340,
> **9600 8-N-1** sobre puerto COM virtual (§5.8). El terminal del cliente será **Windows**, así que
> el agente en Go sigue siendo válido tal cual. La etiqueta de la base dice **MultiProtocolo /
> POS-II**, y con eso se ubicó el manual del diseño hermano: **tabla de formatos y trama byte por
> byte en §5.10b**, incluido un **formato por comando** (`W` → peso) que es el que buscamos.
> El multiprotocolo es firmware de **Mavin**, no de ROCHI — y **Mavin cerró el soporte**
> (§5.10b-bis), así que la tabla de formatos no se va a conseguir. El **modo de descubrimiento**
> del §5.10 deja de ser red de seguridad y pasa a ser el mecanismo.
>
> **Plan de implementación aprobado el 2026-08-28 — ver §12.** Decisiones nuevas de ese día:
> **corte en seco** (se apaga el POS anterior el día de salida, sin plan de retorno) e
> **inspección del instalador de POS Online** como vía para averiguar el formato.
>
> **§7c es de lectura obligatoria antes de escribir código:** cómo no romper a Casaletto, que vende
> todos los días con este mismo programa.
>
> Alcance funcional en `docs/Funcional/venta-por-peso-y-hardware-de-caja.md`.
> Análisis de origen sobre el commit `bac37a392` de `develop`.

---

## 1. Principio rector

**El peso es una cantidad decimal que atraviesa todo el sistema, y hoy hay cuatro puntos donde se
pierde en silencio.** Antes de conectar una báscula hay que cerrarlos, porque un error de peso no
se ve: la venta cuadra en plata y el inventario queda mal para siempre.

Un corolario que ordena las decisiones de hardware: **el navegador es el límite, no el protocolo.**
Todo lo que sigue sobre la báscula existe para cruzar esa frontera de la forma más barata y más
reutilizable posible.

## 2. Los cuatro defectos bloqueantes

Verificados leyendo el código, no inferidos. Hoy son invisibles porque Casaletto no vende por peso.

### 2.1 `quantity_decimals = 0` destruye el peso en cualquier edición de línea

`to_quantity_decimals()` ([`app/Helpers/locale_helper.php:410`](../../app/Helpers/locale_helper.php))
formatea con `MAX_FRACTION_DIGITS = quantity_decimals`. Con el valor por defecto `0`, una cantidad de
`0.735` se renderiza como `1` en el `input` editable de la línea
([`app/Views/sales/register.php:247`](../../app/Views/sales/register.php)).

El cajero corrige cualquier cosa de esa línea, el formulario envía el `1` que está viendo, y el peso
real desaparece. Sin error y sin traza.

Además `Sale_lib::get_quantity_sold()`
([`app/Libraries/Sale_lib.php:1643`](../../app/Libraries/Sale_lib.php)) hace
`bcdiv($total, $price, quantity_decimals())`: con escala 0 la cantidad derivada de un importe queda
en entero.

**Arreglo:** `quantity_decimals = 3` en la configuración del tenant. Es configuración, no código,
pero **sin ella el peso no funciona**, así que va en la fase 1 y con prueba que lo fije.

**Verificado y descartado como problema:** `parse_decimals()`
([`app/Helpers/locale_helper.php:464`](../../app/Helpers/locale_helper.php)) sí conserva los
decimales al leer. Se comprobó empíricamente que `NumberFormatter::FRACTION_DIGITS` no afecta a
`parse()`: `es_CO` con 0 decimales devuelve `0.735` intacto. El daño está en el formateo de salida,
no en la lectura.

### 2.2 `Item_quantity::change_quantity()` recibe la cantidad como entero

```php
public function change_quantity(int $item_id, int $location_id, int $quantity_change): bool
```

[`app/Models/Item_quantity.php:91`](../../app/Models/Item_quantity.php). Dos llamadores le pasan
cantidades fraccionarias:

- [`app/Models/Sale.php:927`](../../app/Models/Sale.php) — anular una venta, para reponer el stock.
- [`app/Models/Receiving.php:247`](../../app/Models/Receiving.php) — anular una compra.

Con `quantity_purchased = '0.735'`, PHP en modo coercitivo convierte a `0`. **El stock no se repone.**
Y la fila de auditoría que se inserta un par de líneas antes sí guarda el `0.735`, así que
`item_quantities` y la tabla `inventory` quedan diciendo cosas distintas sin que nada avise.

**Arreglo — IMPLEMENTADO el 2026-08-28 (vía V3).** Firma a `string $quantity_change` y `bcadd` con
**escala explícita**.

> **La escala NO es `quantity_decimals()`.** Así lo especificaba la primera versión de este
> documento y estaba **mal**: habría estrenado un defecto nuevo en Casaletto. `quantity_decimals` es
> un ajuste de **presentación**, mientras que las columnas son `decimal(15,3)`. Casaletto lo tiene
> en `0` pero puede tener `5.500` unidades guardadas de una recepción vieja, y
> `bcadd('5.500', '2', 0)` da **`7`**: media unidad evaporada, dentro de un cambio que se presentaba
> como arreglo. Comprobado ejecutando PHP.
>
> La escala correcta vive en **`Item_quantity::quantity_scale()`** y es
> `max(quantity_decimals(), 3)` — nunca por debajo de lo que la columna puede guardar. Mostrar menos
> decimales es trabajo del formateador, no de la aritmética.

**Sin la escala explícita el arreglo no arregla nada.** `Load_config.php:57` fija
`bcscale(max(2, totals_decimals() + tax_decimals()))`, y `totals_decimals()` devuelve
**`currency_decimals`** (`locale_helper.php:309`), no `quantity_decimals`. Con la configuración de
este cliente (`currency_decimals = 0`, `tax_decimals = 2`) la escala global queda en **2**.
Comprobado ejecutando PHP:

```
escala 2 → bcadd("10", "0.735")     = 10.73    ← pierde 5 g
escala 3 → bcadd("0.735", "0.740")  = 1.475    ← correcto
```

Pasaría de perder 735 g a perder 5 g, con el mismo síntoma: `item_quantities` desalineado de
`inventory`.

*(Nota: el documento decía antes que `Sale::save()` "sí es correcto". En realidad `Sale.php:740`
usa aritmética float plana, no bcmath. Es una referencia, no un modelo a copiar.)*

### 2.3 Los artículos no tienen unidad de medida

La tabla `items` no tiene ninguna columna que distinga un artículo que se vende por kilo de uno que
se vende por unidad. Ver el esquema base en
`app/Database/Migrations/sqlscripts/initial_schema.sql`.

Con la báscula en la caja esto dejó de ser cosmético: **es lo que le dice al POS a qué artículo
pedirle el peso.** Es infraestructura del requerimiento, no un detalle de presentación.

### 2.4 `Sale_lib::add_item()` trunca al sumar el mismo artículo dos veces

Descubierto el 2026-08-28 auditando el anterior. En el camino "el artículo ya está en el carrito,
súmale" ([`app/Libraries/Sale_lib.php:1110`](../../app/Libraries/Sale_lib.php)):

```php
$quantity = bcadd($quantity, $items[$updatekey]['quantity']);
```

Sin escala explícita, con la escala global en 2. **Pesar dos bolsas del mismo producto guarda
1,47 kg en vez de 1,475.**

Es el más frecuente de los cuatro: cinco gramos por repetición, en silencio,
en la operación más común de un supermercado de hortalizas.

**IMPLEMENTADO el 2026-08-28 (vía V3)** con la misma escala explícita de §2.2. La corrección de
`Receiving::delete_value()` fue más allá de lo pedido y conviene registrarlo: ahora el valor se
calcula **una sola vez** en bcmath y alimenta tanto la fila de auditoría de `inventory` como el
movimiento de `item_quantities`, de modo que **no pueden divergir por construcción** — que es el
defecto de raíz, no solo su síntoma. Además evita que un `float` llegue a `bcadd()` como
`"1.0E-6"`, que lanzaría `ValueError` en mitad de la anulación.

## 3. Modelo de datos: unidad de medida

Migración nueva sobre `ospos_items`:

```
unit_of_measure  VARCHAR(10)  NOT NULL  DEFAULT 'unit'   -- 'unit' | 'kg'
```

Códigos estables e independientes del idioma, como `payment_type_code` y `cash_source`. Las
etiquetas se resuelven al mostrar.

`'unit'` por defecto para que **la migración no cambie el comportamiento de Casaletto**: todos sus
artículos quedan exactamente como están hoy.

Consumidores:
- Formulario de artículo: un selector nuevo.
- Registradora: decide si el artículo abre el campo de peso.
- Recibo e informes: sufijo de unidad en la cantidad.
- Importación CSV: columna nueva al final, opcional.

**Se decidió no crear una tabla de unidades.** Dos valores no justifican una tabla, y una tabla
invita a que alguien agregue "libra" y "gramo" sin que nadie haya definido las conversiones. Si
algún día hace falta una tercera unidad, se agrega al enum y se decide en ese momento.

### 3.1 Cómo quedó implementado (vía V2, 2026-08-28)

Migración `20260901000000_AddItemUnitOfMeasure.php`, idempotente y reversible. `NOT NULL DEFAULT
'unit'` hace que MariaDB rellene la columna en el propio `ALTER`: **no existe un instante en que un
artículo no tenga unidad**, y ninguna ruta de lectura tiene que lidiar con `NULL`. Sin índice a
propósito — dos valores no le dan selectividad y la columna se lee por clave primaria.

Todo valor que entra pasa por `Item::normalize_unit_of_measure()`. Eso importa más de lo que parece:
`Item::save_value()` escribe con el query builder crudo y **nunca consulta `$allowedFields`**, así
que el normalizador es lo único que separa un POST arbitrario de la columna. Un valor irreconocible
(`'kilogramo'`, `'lb'`) normaliza a `unit` y queda en el log; no reprueba la fila, porque el campo es
opcional por diseño.

**Dos decisiones sobre escritura masiva que evitan un daño silencioso:**

1. **La importación CSV escribe la clave solo si la celda trae algo.** Si viene vacía, o si el
   archivo se generó con una plantilla anterior a la columna, **se omite la clave**. Escribir
   `'unit'` incondicionalmente habría **degradado a unidades cada artículo pesado en la siguiente
   reimportación** — y reimportar es justamente el flujo con el que un supermercado corrige precios.
   Omitida, el `INSERT` toma el `DEFAULT` y el `UPDATE` deja el valor en paz.
2. **La edición masiva normaliza solo si el campo se envió.** Incondicionalmente habría metido
   `unit_of_measure => 'unit'` dentro de un `UPDATE` masivo de precios.

La cabecera nueva del CSV va **al final y nunca intercalada**: los clientes conservan copias llenas
de la plantilla, y reordenar columnas correría en silencio todos los valores que ya escribieron.

### 3.2 Cabos sueltos conocidos de la unidad de medida

Encontrados al implementar, deliberadamente **no** resueltos por quedar fuera del alcance de esa vía:

- **La grilla de artículos todavía no muestra la columna.** Agregarla al `SELECT` de `search()` es
  necesario pero **no suficiente**: `item_headers()`
  ([`app/Helpers/tabular_helper.php:395`](../../app/Helpers/tabular_helper.php)) y la lista
  `$columns` de `get_item_data_row()` (`:489`) enumeran los campos a mano, y `sanitizeSortColumn`
  rechaza ordenar por ella.
- **La edición masiva quedó a medio cablear**: el campo está en `ALLOWED_BULK_EDIT_FIELDS` y el
  backend lo normaliza, pero `getBulkEdit()` no pasa opciones y `form_bulk.php` no tiene control.
  Es una rama muerta hasta que alguien toque esa vista.
- **`Sale_lib::add_item()` no copiaba la unidad a la línea del carrito** (~`:1140-1175`), que es el
  eslabón que le da sentido a la columna en la venta. Corresponde a la vía de la caja.

## 4. Arquitectura del peso: el transporte es un enchufe

### 4.1 Las tres capas y dónde está el muro

| Capa | Qué es | ¿Es el problema? |
|---|---|---|
| **Idioma** | El protocolo de la báscula: qué bytes emite | No. Es lo fácil, y ya tenemos intérprete |
| **Tubo** | El puerto serie o HID | No |
| **Muro** | Una página web no puede abrir un puerto | **Sí. Este es el problema** |

El muro no se cruza sabiendo el protocolo. Por eso a POS Online "le funciona bien": su versión de
escritorio no tiene muro. No resolvieron algo que nosotros no podamos — juegan en otra cancha.

### 4.2 La consecuencia de diseño

Las tres vías posibles (Web Serial, agente local, teclas) **terminan todas en el mismo sitio: un
número dentro de un campo de nuestra página.** Y las tres necesitan de nuestro lado exactamente lo
mismo:

1. El marcador `unit_of_measure` en el artículo.
2. Un campo de peso en la registradora, con manejo de foco.
3. Un intérprete configurable del texto que llega.

**Por eso el transporte se implementa como un enchufe intercambiable, no como una bifurcación.**
Se construye una vez lo compartido y cambiar de vía después no rehace nada.

### 4.3 El intérprete ya existe

`Token_lib::parse()` ([`app/Libraries/Token_lib.php:188`](../../app/Libraries/Token_lib.php)) es
público y completamente genérico: recibe un texto cualquiera, un patrón con tokens tipo `{W:5}` y un
conjunto de tokens, y devuelve los campos. Hoy se usa para códigos de barras, pero no depende de
ellos.

Distintas básculas emiten `ST,GS,+  0.735kg`, `+000735 g` o `0.735`. El mismo intérprete las lee
todas con un patrón distinto, **configurado en pantalla y no programado**. La báscula del próximo
cliente se configura.

Configuración nueva, hermana de `barcode_formats`:

```
scale_format      VARCHAR   -- patrón con {W:n}, p.ej. 'ST,GS,{W:7}kg'
scale_divisor     INT       -- 1 si viene en kg, 1000 si viene en gramos
scale_port        VARCHAR   -- puerto/identificador que el agente debe abrir
scale_transport   VARCHAR   -- 'keys' | 'agent'
```

### 4.4 Defectos del intérprete que hay que corregir al reutilizarlo

- **`parse_barcode()` no se detiene en el primer formato que coincide**
  ([`app/Libraries/Token_lib.php:169-185`](../../app/Libraries/Token_lib.php)): el bucle recorre
  todos y el último reinicia `quantity` a 1 y `price` a null. Con dos formatos configurados, solo
  sirve el último. Falta un `break`.
- **Divisor de peso fijo en 1000** (`Token_lib.php:177`): `(int) $parsed_results['W'] / 1000`. Debe
  salir de configuración.
- **El patrón no está anclado** (`Token_lib.php:205`): `preg_match("/$pattern/", ...)` puede
  reconocer un formato en la mitad de una cadena más larga.

Los tres son de baja gravedad para el camino elegido, pero se arreglan al tocar el archivo.

## 5. El agente local

### 5.1 Qué es

Un ejecutable propio en el PC de la caja que abre el puerto de la báscula y lo publica por WebSocket
en `localhost`. Sin ventanas, arranca con el sistema, no pide nada al usuario.

**Tecnología: Go.** Compila a un binario estático único, sin runtime que instalar en el equipo del
cliente — ni JVM, ni .NET, ni Python. Es la diferencia entre un archivo y un procedimiento de
instalación.

### 5.2 Alcance: tres funciones, no una

Se construye una sola vez y cubre:

| Función | Qué reemplaza |
|---|---|
| Leer la báscula | El enchufe de teclas |
| Imprimir el recibo en ESC/POS crudo | La impresión del navegador y su diálogo |
| Abrir el cajón (`ESC p`, `27,112`) | Nada. Hoy no existe de ninguna forma |

Construirlo solo para la báscula sería desperdiciar el 80% del trabajo, que es la instalación y la
distribución, no la lectura del puerto.

### 5.3 Las tres dificultades reales, y ninguna es el código

#### 5.3.1 El permiso de red local del navegador — el riesgo principal

Chrome está restringiendo que una página pública haga peticiones a la red local del usuario
(*Local Network Access*), y **el alcance incluye explícitamente loopback: `127.0.0.1` y `::1`**, no
solo las IP privadas de la LAN. La consecuencia práctica es un aviso de permiso al usuario.

**Sin prever esto, el agente "deja de funcionar solo" tras una actualización de Chrome**, y el
cajero ve un diálogo que no sabe atender.

**Mitigación:** Chrome expone una **política empresarial** para pre-conceder ese permiso a un
dominio específico. Se deja puesta en el montaje y el cajero nunca ve el aviso. Solo puede hacerlo
quien tiene acceso al equipo — es decir, nosotros. Es una ventaja del modelo de instalación en
sitio, no un obstáculo.

**Verificado y descartado como problema:** el contenido mixto **no** aplica. `localhost` y
`127.0.0.1` son orígenes potencialmente confiables por especificación, así que una página servida
por HTTPS **sí** puede abrir `ws://127.0.0.1` sin que el navegador lo bloquee.

#### 5.3.2 Firma del ejecutable

Un `.exe` sin firmar dispara SmartScreen. Un certificado de firma de código cuesta del orden de
**USD 200–400 anuales**, que es exactamente el tipo de costo recurrente que este proyecto quiere
evitar.

**Decisión: no comprarlo por ahora.** Como el montaje lo hacemos nosotros, absorbemos una vez el
"ejecutar de todos modos". Se compra el día que queramos que el cliente se autoinstale.

#### 5.3.3 Actualización de N instalaciones

Cada caja es una copia que envejece. Con tres clientes son doce cajas, y corregir un error son doce
visitas si no se previó.

**La auto-actualización se diseña desde el día uno.** Agregarla después significa rehacer el
instalador y volver a pasar por cada caja — exactamente el problema que se quería evitar.

### 5.4 Contrato con la página

WebSocket en `ws://127.0.0.1:<puerto>`. Mensajes JSON. Tres operaciones:

| Operación | De → a | Contenido |
|---|---|---|
| `scale.read` | página → agente | solicitud de peso |
| `scale.weight` | agente → página | texto crudo de la báscula, tal cual |
| `printer.raw` | página → agente | bytes ESC/POS del recibo |
| `drawer.open` | página → agente | sin parámetros |

**El agente devuelve el texto crudo, no el peso interpretado.** La interpretación se hace en el
servidor con `Token_lib::parse()` y el patrón configurado. Así el agente queda tonto y estable, y
todo lo que cambia entre básculas vive en configuración del sistema, donde se puede corregir sin
reinstalar nada en la caja.

### 5.5 Estabilidad de la lectura

Los protocolos de báscula traen un dígito que indica si el peso está estable (`0`) o inestable
(`1`) — confirmado en la documentación de protocolo RS-232 de fabricantes del mercado. **El agente
no entrega una lectura marcada como inestable.** Con el enchufe de teclas esa marca no llega, pero
ahí la estabilidad la decide el operario al presionar transmitir.

### 5.6 Antes de escribir código

**Leer el fuente de QZ Tray.** Es abierto y ya resolvió el saludo del WebSocket, el certificado de
localhost, el ícono de bandeja y el instalador. Un día leyéndolo ahorra tropezar donde ellos ya
tropezaron.

**Leerlo, no derivarlo.** Es LGPL: distribuir un derivado obliga a publicar las modificaciones de
las partes cubiertas. Escribir el nuestro desde cero en Go no arrastra esa obligación.

### 5.7 Averiguar el protocolo de la báscula

Media hora, no un proyecto: conectar la báscula, abrir un terminal serial, presionar transmitir y
mirar los bytes. De ahí salen velocidad, forma de la trama y el dígito de estabilidad. **Va a la
pantalla de configuración, no al código.**

### 5.8 La báscula del cliente, identificada (2026-08-27)

**ROCHI RC-A01E**, serie RC, primera generación. De la placa del equipo:

| Dato | Valor | Consecuencia |
|---|---|---|
| Capacidad máxima | 30 kg | Sobrada para hortalizas |
| **Capacidad mínima** | **200 g** | **Restricción operativa real — ver §5.9** |
| **División** | **5 g** | El tercer decimal solo puede ser 0 o 5 |
| Clase de precisión | 3 (OIML III) | Es apta para comercio, que es lo que exige la norma |
| Conexión a PC | **Cable USB tipo B a tipo A**, con driver de descarga | **Puerto COM virtual** |

**Es el caso bueno, y ya está confirmado con el manual del fabricante** (ver §5.10 para las fuentes):

| Parámetro | Valor confirmado |
|---|---|
| Chip USB-serie | **WCH CH340.** En el Administrador de dispositivos aparece como `USB-SERIAL CH340 (COMx)` bajo *Puertos (COM y LPT)* |
| Driver | Paquete **CH341SER** (`SETUP.EXE` como administrador). Gratuito, del fabricante del chip. El manual lo publica en `https://bit.ly/driver-ch341` |
| Velocidad | **9600 baudios** |
| Bits de datos | **8** |
| Paridad | **Ninguna** |
| Bits de parada | **1** |
| Control de flujo | **Ninguno** |

Es decir **9600 8-N-1** sobre un puerto COM virtual: exactamente el transporte para el que se diseñó
el agente en §5.1. **No hay que cambiar nada del diseño**, y el CH340 es el puente USB-serie más
común y mejor soportado que existe — la librería serial de Go lo abre como cualquier otro COM.

El manual cubre toda la serie RC (**D03D, A01D, A01E, A02**), así que estos parámetros deberían
valer para los cuatro modelos.

Es además una **báscula liquidadora**: calcula precio × peso internamente y su trama suele traer
peso, precio unitario y total. **Nosotros solo tomamos el peso**; el patrón configurable `{W:n}` de
§4.3 descarta el resto sin trabajo extra. El precio lo pone el POS, nunca la báscula.

**Nota sobre PV-COM**: su lista de modelos soportados incluye `ROCHI RC-G01`, **no** el RC-A01E. Si
alguna vez se recurre a esa red de seguridad hay que pedirle a Mavin que lo configuren — lo ofrecen,
pero no es inmediato. Un argumento más a favor del agente propio.

### 5.9 Dos límites físicos que el negocio tiene que conocer

No son defectos, son la báscula. Pero se descubren en la caja si no se dicen antes:

- **Nada por debajo de 200 g se puede pesar en este equipo.** Para papa o cebolla no importa; para
  unos ajos sueltos o un puñado de hierbas, sí. El negocio tiene que decidir qué hace con esos
  casos: venderlos por unidad, en bolsa preempacada con peso fijo, o con precio mínimo.
- **La resolución es de 5 g.** El sistema guarda tres decimales y eso está bien, pero la báscula
  nunca va a reportar `0.737`: reportará `0.735` o `0.740`. No hay nada que corregir; conviene
  saberlo antes de que alguien reporte como error que "los pesos siempre terminan en 0 o 5".
  El manual indica que la división es configurable en **1 / 5 / 10 g**; la placa del equipo del
  cliente dice 5 g.

### 5.10 Lo que el manual NO dice, y cómo se cierra sin tener la báscula

**El manual documenta cómo dejar el puerto listo, pero no documenta la trama.** Llega hasta
"configure el puerto a 9600 8-N-1" y ahí se detiene. No dice si la báscula transmite de forma
continua o solo cuando se le pide, ni con qué formato, ni si manda peso solo o peso + precio +
total.

Como **no vamos a tener la báscula** — el cliente la usa a diario y no puede pararla — y como
**Mavin ya no da soporte** (§5.10b-bis), ese vacío no se llena con documentación. Se cierra por
diseño.

#### Qué se puede tocar de la báscula, y cuándo (decidido 2026-08-28)

**El corte será en seco:** el día de salida se apaga POS Online. Eso cambia el margen de maniobra.

Mientras el cliente siga usando su POS actual, la báscula está programada en *algún* formato que ese
sistema entiende, y reprogramarla se lo rompería. Pero como ese sistema se apaga el día del corte,
**sí podemos dejarla en el formato 9 (por comando)**, que es el más confiable: petición y respuesta,
sin flujo continuo del que haya que elegir una lectura.

La regla operativa queda así:

> **Antes del corte: solo escuchar. El día del corte: escuchar primero, reprogramar después.**

El orden importa por dos razones. Primero, porque **si resulta que el formato actual ya lo podemos
leer, no hay que tocar nada** — el intérprete es configurable justamente para eso, y no cambiar la
báscula es siempre menos riesgo. Segundo, porque **hay que saber en qué formato estaba antes de
cambiarlo**: es lo único que permite devolverla a su estado original si hace falta.

**Lo que el corte en seco elimina es el plan de retorno**, y eso condiciona toda la Fase 7: no hay a
qué volver si la báscula no habla. Por eso el peso digitado a mano no es una comodidad, es la
contingencia que mantiene la tienda abierta, y tiene que estar probado **y el personal entrenado en
él** antes del día del corte.

#### El modo de descubrimiento del agente

Es la pieza que determina el protocolo real, así que se construye con cuidado:

1. **Escucha pasiva primero.** Abre el puerto en 9600 8-N-1 — confirmado por el manual ROCHI — y
   vuelca lo que llegue **en ASCII y en hexadecimal**, sin enviar nada. Si la báscula está en modo
   continuo, con esto ya está resuelto y no hubo que tocarla.
2. **Si en unos segundos no llega nada**, prueba los parámetros alternos que documenta la familia:
   **9600 7-E-1** (el de CAS PD-II) y **9600 7-O-1**. Sigue sin enviar nada.
3. **Solo entonces, sondeos activos.** Envía uno por uno los disparadores conocidos — `W` (formato
   por comando), `$` (protocolo Dólar), `ENQ` (`0x05`), `CR` — y muestra qué contesta a cada uno.
   Enviar bytes a un puerto no reprograma nada: es seguro.
4. **Ayuda para armar el patrón.** El técnico pega la trama capturada en la pantalla de
   configuración y **el sistema propone el patrón `{W:n}` y el divisor**, mostrando en vivo qué peso
   saldría. Se corrige a mano si hace falta y se guarda.

El paso 4 es el que convierte el montaje en diez minutos. Sin él, alguien tiene que contar
caracteres a mano parado frente a la caja.

**Y todo el resultado vive en configuración (§4.3):** si en el local la trama no es la esperada, se
ajusta desde la pantalla de administración, **sin recompilar, sin reinstalar y sin volver al local**.

#### Que el descubrimiento no sea de un solo uso

Lo mismo que sirve el día del montaje sirve el día que algo se rompa. El modo de descubrimiento
queda **permanente en el agente**, accesible desde la pantalla de configuración, para que un soporte
futuro pueda ver qué está mandando la báscula sin instalar herramientas ni pedir ayuda.

### 5.10b La segunda etiqueta: "MultiProtocolo" y "Versión POS-II" (2026-08-28)

La base del equipo del cliente trae una segunda etiqueta con tres datos que no estaban en la ficha:

```
Puerto USB-A
Version: POS-II
MultiProtocolo          [QR]      16-06-2025
```

**"MultiProtocolo" significa que la báscula emite el formato que uno le pida**, no uno fijo. Es el
estándar de facto del mercado colombiano: la báscula se adapta al POS, no al revés.

Con eso se pudo ubicar el manual del **mismo diseño OEM** — la familia ACS-268 que Básculas Moresco
vende como *"Balanza POS Esencial POS-2"* — que **sí documenta la tabla de protocolos y la trama
completa**. Coincide en todo lo verificable con el equipo del cliente: 30 kg, división 5 g, clase
III, y los mismos 9600 8-N-1 que da el manual ROCHI.

**Es documentación de una marca hermana, no del equipo exacto.** Se toma como hipótesis muy
probable, no como certeza — y por eso el modo de descubrimiento del §5.10 sigue siendo obligatorio.

#### Tabla de formatos programables

| Nº | Marca que emula | Baud | Datos | Paridad | Stop | Transmisión |
|---|---|---|---|---|---|---|
| 0 | BBG TAG 30 kg | 9600 | 8 | Ninguna | 1 | Continua |
| 1 | FILLUX 30 kg plana | 9600 | 8 | Ninguna | 1 | Continua |
| 2 | FILLUX torre / CLEVER | 9600 | 8 | Ninguna | 1 | Continua |
| 3 | MORESCO 30 kg (peso neto) | 9600 | 8 | Ninguna | 1 | Continua |
| 4 | MOTEX R-30N | 9600 | 8 | Ninguna | 1 | Continua |
| 5 | LEXUS WNC 30 kg (solo peso) | 9600 | 8 | Ninguna | 1 | Continua |
| 7 | LEXUS XIC 30 kg | 9600 | 8 | Ninguna | 1 | Continua |
| 8 | CLEVER 30 kg (MERLIN POS) | 9600 | 8 | Ninguna | 1 | Comando |
| **9** | **MORESCO por comando** | **9600** | **8** | **Ninguna** | **1** | **Por comando** |
| F | CAS PD-II | 9600 | **7** | Par | 1 | Requiere activación de fábrica |

#### El formato 9 es el que queremos

> *"Cuando se selecciona el formato 9 el usuario debe enviar por el puerto la letra `W` para que la
> balanza le responda con el peso que tiene en ese momento sobre la plataforma."*

**Petición y respuesta, no chorro continuo.** El agente manda `W` justo cuando el cajero necesita el
peso y recibe una sola lectura. Eso elimina de raíz el problema de "qué lectura del flujo tomo", que
es donde se cuelan los pesos tomados a mitad del bamboleo.

**Decisión: el formato 9 es el objetivo; el modo continuo es el respaldo.** Si en el montaje resulta
que este equipo no expone el 9, se cae al formato 3 (continuo) y el agente toma la última lectura
estable. Ambos se soportan; es configuración, no código.

#### La trama del modo continuo, byte por byte

Documentada para el formato Moresco:

| Byte | Contenido |
|---|---|
| 1 | Bandera `0x4E` — se ve como **`N`** |
| 2–3 | Kilogramos |
| 4 | Punto decimal, siempre `0x2E` (`.`) |
| 5–7 | Gramos |
| 8–9 | Dos espacios `0x20` |
| 10 | `LF` `0x0A` |
| 11 | `CR` `0x0D` |

Ejemplo del manual para 12,395 kg: `N12.395··<LF><CR>`

#### Cómo se cambia el formato en la báscula

1. Encender y esperar a que todas las pantallas queden en ceros.
2. Digitar el número de formato en el teclado numérico.
3. **Mantener presionada la tecla de configuración 10 segundos completos**, sin soltar.
4. Verificar con un terminal serial.

En la Moresco la tecla de configuración es `*`. **El teclado de la RC-A01E no tiene `*`** — tiene
`M`, `M1`, `CNT`, `CERO`, `TARA`, `C`, `kg/lb`. Cuál es la equivalente es de las pocas cosas que
quedan por descubrir en sitio, y el QR de la etiqueta es el primer sitio donde buscarla.

#### Consecuencia para nuestro intérprete

`Token_barcode_weight::get_value()` devuelve `'\d'`
([`app/Models/Tokens/Token_barcode_weight.php`](../../app/Models/Tokens/Token_barcode_weight.php)):
**solo dígitos**. La trama trae un punto decimal en la mitad, así que `N{W:6}` no engancharía con
`12.395`.

Y partirla en dos tokens tampoco sirve: `Token_lib::parse()` indexa el árbol por id de token y con
`array_shift` se queda **solo con la primera longitud**, así que un patrón `N{W:2}\.{W:3}` perdería
el segundo grupo.

**Arreglo:** un token de peso para báscula cuya clase de caracteres sea `[\d.]`. Con eso `N{W:6}`
captura `12.395` y el divisor queda en 1. Es un archivo nuevo de veinte líneas, no un rediseño —
pero hay que preverlo o el patrón no engancha y se pierde una tarde averiguando por qué.

### 5.10b-bis A dónde lleva el QR: el multiprotocolo es de Mavin (2026-08-28)

El QR de la etiqueta apunta a `https://www.mavincolombia.com/rochi-usb.html`. Hoy esa URL da 404 —
Mavin rehízo el sitio — pero la copia archivada del 2026-02-17 trae el dato que importa:

> **Puerto: USB Multiformatos Mavin (Exclusiva Mavin)** · Driver CH340 · Clase III · Capacidad 30 kg
> · Carga mínima 200 g

**El firmware multiprotocolo no es de ROCHI: es de Mavin.** ROCHI fabrica la báscula; Mavin le pone
el puerto USB multiformato y la distribuye. Eso reordena quién es la fuente autorizada:

| Fuente | Qué vale |
|---|---|
| Manual ROCHI | Parámetros del puerto (9600 8-N-1) y driver. **Confirmado, es del equipo exacto** |
| Manual Moresco ACS-268 (§5.10b) | Tabla de formatos y trama. **Analogía de familia, no del equipo** |
| ~~Mavin~~ | Tenía la tabla real de este equipo, sin publicar. **Cerró el soporte el 2026-08-28: fuente perdida** |

**Cerrado el 2026-08-28: Mavin ya no da soporte.** No hay a quién pedirle la tabla. La fila de
"fuente autorizada" queda vacía y no se va a llenar.

Conviene ser explícito sobre lo que eso significa, porque cambia el peso de tres decisiones:

1. **La tabla del §5.10b es lo mejor que vamos a tener sobre papel.** Deja de ser "hipótesis
   mientras conseguimos la buena" y pasa a ser **la referencia de trabajo**. Sigue siendo de una
   marca hermana, así que se usa para *anticipar*, nunca para dar por hecho.
2. **El modo de descubrimiento (§5.10) deja de ser red de seguridad y pasa a ser el mecanismo.**
   Es lo que va a determinar el protocolo real, y por eso sube de prioridad: sin él no hay montaje.
3. **PV-COM se debilita como plan B.** Su lista no trae el RC-A01E — trae el RC-G01 — y quien
   agregaba modelos era justamente Mavin. Sigue siendo una salida si el equipo resulta hablar un
   formato que PV-COM ya soporta, pero **ya no se puede contar con que nos configuren el nuestro**.

No es una mala noticia para el diseño: el transporte siempre estuvo pensado como enchufe y el patrón
siempre vivió en configuración. Es exactamente el escenario para el que se hizo así. Lo que sí
cambia es que **la calidad del modo de descubrimiento ahora determina si el montaje dura diez
minutos o una tarde**.

**Detalle que ya no se puede preguntar y hay que asumir:** la ficha publicada era del **RC-A01 LED
con división de 10 g** y el equipo del cliente es el **RC-A01E de 5 g**. Son variantes de la misma
familia. Se asume que comparten el firmware multiformato — es lo más probable — pero se verifica en
sitio.

### 5.10c Dos advertencias operativas del manual hermano

- **Abrir el puerto antes de encender la báscula.** Si la báscula ya está transmitiendo cuando el
  agente abre el puerto, Windows puede reportarlo como ocupado. El agente debe reintentar la
  apertura en vez de rendirse al primer error.
- **Herramientas de verificación:** AccessPort o HyperTerminal, ambas gratuitas y para Windows. Es
  lo que se usa en el montaje antes de conectar nuestro agente.

### 5.11 Fuentes de estos datos

- Manual de usuario ROCHI RC-SERIE (11 páginas), sección *"Conexión USB a PC"*:
  `http://basculasybalanzastek.com/wp-content/uploads/2025/06/Manual-de-usuario-ROCHI.pdf`
- Ficha técnica RC-A01E:
  `http://basculasybalanzastek.com/wp-content/uploads/2025/06/Ficha-tecnica-RC-A01E.pdf`
- Driver CH341SER publicado por el distribuidor:
  `https://drive.google.com/file/d/1CKlY0-QqLtGPr_mRe43mm7C4oa3J17fM/view`
- Página de producto con las tres descargas:
  `https://basculasybalanzastek.com/product/balanza-electronica-liquidadora-a-01e-30kg-led/`
- **Manual del diseño hermano (ACS-268 / "Balanza POS Esencial POS-2"), de donde salen la tabla de
  formatos y la trama del §5.10b** — 18 páginas, firmware 4.0:
  `http://www.basculasmoresco.com/uploads/1/7/4/0/1740594/manual_balanza_moresco_pos.pdf`
- Video del fabricante sobre la configuración del cable: `https://www.youtube.com/watch?v=DEFYsV-TqJ0`
- Etiqueta de la base del equipo del cliente (foto, 2026-08-28): *Puerto USB-A · Version: POS-II ·
  MultiProtocolo*, QR y fecha manuscrita 16-06-2025. **El QR lleva a
  `https://www.mavincolombia.com/rochi-usb.html`**, hoy 404; copia archivada utilizable en
  `http://web.archive.org/web/20260217173716/https://www.mavincolombia.com/rochi-usb.html`

Los dos PDF son escaneos sin capa de texto: hubo que renderizarlos a imagen para leerlos. Si alguien
los vuelve a necesitar, ese es el motivo por el que buscar texto dentro no devuelve nada.

## 6. Inventario

### 6.1 Merma

Tabla nueva `ospos_inventory_write_offs`, o columna de motivo sobre los ajustes existentes. La
decisión se toma al implementar, pero el requisito es fijo: **la causa tiene que ser un código
estable y clasificable**, no texto libre.

```
reason_code  VARCHAR(20)   -- 'damaged' | 'shrinkage' | 'theft' | 'data_entry'
```

Reporte: merma por artículo y por causa, contra las compras del período.

Se apoya en la tabla `inventory` que ya existe y ya registra usuario, fecha, sede y comentario
([`app/Models/Inventory.php`](../../app/Models/Inventory.php)): no se inventa un rastro nuevo, se
clasifica el que ya hay.

### 6.2 Toma de inventario

Hoy no existe. La wiki del proyecto base propone ajustar artículo por artículo y después correr
consultas SQL a mano (`docs/Funcional/referencia-ospos-wiki/Stocktake.md`), lo cual no escala.

Se construye: cargar el conteo, calcular diferencias contra `item_quantities`, y **aplicarlas todas
en una transacción** dejando las filas de auditoría correspondientes en `inventory`.

### 6.3 Lotes opcionales

```
ospos_item_lots
  lot_id, item_id, location_id, lot_code NULL, received_at, expires_at NULL, quantity
```

Y en `ospos_items`:

```
tracks_lots  TINYINT(1)  NOT NULL  DEFAULT 0
```

Las cuatro reglas del documento funcional, traducidas a implementación:

1. **`tracks_lots = 0` por defecto.** Ningún artículo existente cambia de comportamiento.
2. **`item_quantities` sigue siendo la fuente de verdad del saldo.** `item_lots` es una vista
   detallada opcional. Nunca se calcula el saldo sumando lotes.
3. **La venta no consulta lotes si `tracks_lots = 0`.** Cuando sí los lleva, consume del que vence
   primero, en silencio. **Ningún camino de venta puede fallar por falta de lote.**
4. **La recepción no valida lote ni vencimiento.** Campos opcionales al final del formulario.

Reporte de próximos a vencer: solo lista artículos con `tracks_lots = 1`.

## 7. Configuración del tenant

Lista que hoy no existe en ninguna parte y que hay que escribir para este cliente y reutilizar en
los siguientes.

| Clave | Valor | Por qué |
|---|---|---|
| `quantity_decimals` | `3` | **Bloqueante.** Sin esto el peso se pierde (§2.1) |
| `barcode_content` | `item_number` | Evita que un código impreso choque con el `item_id` de otro artículo |
| `tax_included` | `1` | Al detal en Colombia los precios se muestran con IVA |
| `currency_decimals` | `0` | El peso colombiano no tiene centavos |
| `number_locale` | `es_CO` | |
| `cash_rounding_code` | según decisión | Redondeo de efectivo |
| `dinner_table_enable` | `0` | Es de restaurante; no aplica |
| `receiving_calculate_average_price` | `1` | Costo promedio ponderado |
| `timezone` | `America/Bogota` | El seed ya lo trae correcto |

**Verificado y descartado como problema:** el redondeo del dinero está bien.
`Load_config.php:57` fija `bcscale(max(2, currency_decimals + tax_decimals))`, así que con
`currency_decimals = 0` y `tax_decimals = 2` la escala interna es 2 y `0.735 × 4500` da `3307.50`,
no `3307`.

## 7b. Terminal táctil: lo que agrega al alcance

El cliente reemplaza el PC por un terminal táctil todo-en-uno. **Confirmado: Windows** (2026-08-28),
así que el agente en Go, el puerto COM y la política de Chrome siguen valiendo sin cambios. Si
hubiera sido Android, §5 completo quedaba sin efecto — vale la pena dejarlo escrito por si algún
cliente futuro llega con Android.

Lo que sí agrega:

- **Teclado numérico en pantalla para el campo de peso.** Sin teclado físico, la digitación manual
  del §3.3 funcional no existe. Es el respaldo de toda la operación cuando la báscula falla, así que
  el teclado en pantalla **no es opcional**: es parte del campo de peso, misma fase 4.
- **Objetivos táctiles en la registradora.** `app/Views/sales/register.php` está hecha para mouse:
  `input-sm`, iconos de 12 px, filas densas. Hay que agrandar lo que el cajero toca todo el día —
  agregar producto, editar cantidad, borrar línea, cobrar. No es rehacer la vista; es una hoja de
  estilos para el modo táctil. Se dimensiona con el terminal ya elegido, no antes.
- **Puertos USB.** Báscula + pistola + impresora = tres mínimo. Verificar al comprar el terminal;
  si no alcanzan, un hub alimentado (la impresora y la báscula no deben colgar de un hub sin
  alimentación propia).

## 7c. No romper a los tenants que ya operan

Este es el primer requerimiento que se construye **para un cliente distinto al que ya está en
producción**. Casaletto vende todos los días con este mismo código, así que la regla que ordena todo
el capítulo es:

> **Los datos son por tenant; el código es de todos.** Una migración toca un solo negocio. Un cambio
> en `Sale.php` toca a Casaletto en el segundo en que se despliega.

### 7c.1 El riesgo real: una migración pendiente deja al tenant sin poder entrar

> **Reescrito el 2026-08-28.** Las dos versiones anteriores de esta sección estaban equivocadas: la
> primera decía que no existía orquestador de migraciones (sí existe), y la segunda describía el
> síntoma como "errores de columna inexistente". **Es mucho peor que eso.**

`app/Config/Events.php:61` engancha `Load_config::load_config()` a `post_controller_constructor`, o
sea **en cada request**. Y ahí ([`app/Events/Load_config.php:46`](../../app/Events/Load_config.php)):

```php
if (!$migration->is_latest()) {
    $this->session->destroy();
}
```

`is_latest()` compara la migración más alta en disco contra la más alta aplicada en el esquema. Si se
despliega código con una migración que el tenant aún no aplicó, **cada petición destruye la sesión**:
nadie se mantiene autenticado. No es un error en un flujo puntual — **la caja no abre**.

#### Y el orden que este documento prescribía es imposible de ejecutar

"Migrar todos los esquemas y después desplegar el código" no se puede hacer: la cabecera de
[`scripts/migrate-tenants.sh`](../../scripts/migrate-tenants.sh) dice que debe correr *dentro del
contenedor/imagen de la app*, porque **los archivos de migración viven en la imagen nueva**. No
existen antes de desplegarla.

Tampoco se resuelve desde el workflow: `deploy-core.yml:92` dispara un webhook al VPS y termina —
GitHub Actions no tiene shell en el host.

Y la mitigación que proponía la versión anterior (leer con `??` para tolerar la columna ausente)
**no cierra esta ventana**, porque el daño no viene de leer una columna sino del comparador de
versiones.

#### Lo que sí existe y hay que usar

`scripts/migrate-tenants.sh` está bien construido y no hay que reescribirlo:

- Toma la lista de `tenant:list`, que marca cada línea con `TENANT_DB:` **porque spark escribe su
  propio banner en stdout** ([`app/Commands/TenantList.php:33`](../../app/Commands/TenantList.php)).
- Usa `tenant:migrate-one` y no el `migrate` de serie, **porque el segundo se traga las excepciones
  y siempre sale 0**.
- Acumula fallos y sale distinto de cero.

#### Cómo quedó resuelto (V1, implementado el 2026-08-28)

**La migración corre desde el entrypoint del contenedor, antes de que Apache acepte una petición.**
Se descartó volver `is_latest()` tolerante: esa comprobación existe para impedir que alguien opere
contra un esquema a medias, y relajarla cambiaría un fallo ruidoso por uno silencioso.

- **`docker/entrypoint.sh`** (nuevo) — espera a que la base responda, corre
  `scripts/migrate-tenants.sh` y solo entonces encadena al entrypoint de la imagen base. Si alguna
  migración falla, **sale distinto de cero y Apache no arranca**.
- **`Dockerfile`** — `ENTRYPOINT ["/app/docker/entrypoint.sh", "docker-php-entrypoint"]` con
  `CMD ["apache2-foreground"]`, para no perder la preparación de PHP de la imagen base.

**Política de fallo, y es deliberada:** un contenedor que se niega a servir es ruidoso y permite
redesplegar la imagen anterior; uno que sirve contra un esquema a medias es silencioso y corrompe
datos. Se prefiere el fallo ruidoso. Hay un escape (`SKIP_MIGRATIONS=1`) para inspeccionar un
esquema roto a mano, que registra la advertencia en el log y no es parte del despliegue normal.

#### Un fallo silencioso que apareció al revisar el script

`scripts/migrate-tenants.sh` **reportaba éxito sin migrar nada** cuando el registro de tenants era
inalcanzable. Tomaba la salida de `tenant:list` y la filtraba por el prefijo `TENANT_DB:`; si la
consulta fallaba, el filtro quedaba vacío, el script imprimía *"No active tenants to migrate"* y
salía **cero**. Comprobado ejecutando la versión anterior contra un `php` simulado.

Con el entrypoint llamándolo, ese cero habría significado "seguí, todo bien" y Apache habría
arrancado con todos los esquemas atrás — justo el escenario que este capítulo intenta evitar.

Corregido: el estado de salida de `tenant:list` se captura aparte, y una traza de excepción con
salida cero también se trata como fallo. Se distinguen tres casos que antes se confundían:

| Caso | Antes | Ahora |
|---|---|---|
| Registro inalcanzable | "sin tenants", exit 0 | **Falla**, exit 1 |
| Traza de error con exit 0 | "sin tenants", exit 0 | **Falla**, exit 1 |
| Registro OK, cero tenants | "sin tenants", exit 0 | Migra el esquema por defecto (instalación de un solo negocio) |

**Corolario que vale para todo el proyecto:** cada `git merge` que traiga una migración es un evento
de despliegue, no un commit más.

### 7c.2 Cambio por cambio: qué ve Casaletto

| Cambio | ¿Lo toca? | Por qué es seguro / qué hay que hacer |
|---|---|---|
| `unit_of_measure` con `DEFAULT 'unit'` | Columna nueva | Todos sus artículos quedan `unit`, que es el comportamiento de hoy. Nada lo consulta salvo el flujo de peso |
| `tracks_lots` con `DEFAULT 0` | Columna nueva | La venta ni siquiera consulta lotes cuando es 0 |
| Tablas nuevas (lotes, merma) | No las usa | Vacías. Los módulos van detrás de permisos que su tenant no otorga |
| **`change_quantity()` de `int` a `string`** | **Sí, código compartido** | Es la corrección de un defecto que también le aplica a él. Con cantidades enteras el resultado es idéntico — **hay que probarlo con enteros, no solo con decimales** |
| **`parse_barcode()` con `break`** | **Sí, código compartido** | **Verificar antes qué tiene Casaletto en `barcode_formats`.** Con cero o un formato el `break` es inocuo; con dos o más **cambia el resultado** |
| **Anclar el patrón del intérprete** | **Sí, código compartido** | El más traicionero: si Casaletto tiene un formato que hoy engancha por coincidencia parcial, anclarlo lo rompe. **Verificar antes, no después** |
| Divisor de peso configurable | Sí | El valor por defecto sigue siendo 1000 |
| Campo de peso en la registradora | Vista compartida | Solo se renderiza para artículos `kg`. Casaletto no tiene ninguno, así que no lo ve nunca |
| Teclado numérico en pantalla | Vista compartida | Vive dentro del campo de peso. Invisible para él |
| **Objetivos táctiles / CSS de la registradora** | **Sí, y este SÍ se nota** | Es el único cambio visual que le llegaría sin razón. **Va detrás de una bandera por tenant**, no global |
| Selector de unidad en el formulario de artículo | Visible | Campo opcional con valor por defecto. No puede volverse obligatorio ni romper el guardado |
| Columna nueva en la importación CSV | Sí | **Al final y opcional.** Nunca reordenar columnas: rompería las plantillas que ya usen |
| Sufijo de unidad en el recibo | Sí | Solo cuando la unidad no es `unit` |
| `quantity_decimals = 3` | **No** | Es configuración por tenant. Casaletto conserva su `0` — y hay que **verificarlo explícitamente** después de desplegar |

### 7c.3 La caché de configuración, que muerde callado

`Config\OSPOS::set_settings()` cachea el `app_config` completo bajo `settings_<slug>`
([`app/Config/OSPOS.php`](../../app/Config/OSPOS.php)). Una migración que **agrega claves de
configuración** no invalida esa caché, así que durante un rato el sistema sigue leyendo el mapa
viejo — sin la clave nueva.

Y no todo el código lee con `??`: `quantity_decimals()` sí, pero hay accesos directos tipo
`$config['clave']` que lanzarían error con la clave ausente.

**Regla:** toda migración que agregue claves de `app_config` termina con la caché limpia, y eso
también es por tenant.

### 7c.4 Lo que hay que verificar ANTES de tocar `Token_lib`

Consultas de solo lectura contra el esquema de Casaletto — la verificación en producción es de
lectura, nunca transaccional:

```sql
SELECT `key`, `value` FROM ospos_app_config
 WHERE `key` IN ('barcode_formats','quantity_decimals','barcode_content','tax_included');
```

- `barcode_formats` vacío o con un solo formato → el `break` y el anclaje son inocuos, se hacen sin
  más.
- Con dos o más formatos → hay que revisar uno por uno si el anclaje los rompe, **antes** de
  desplegar.

### 7c.5 Pruebas: la suite tiene que correr los dos mundos

No basta con probar el escenario del supermercado. Cada prueba que toque cantidades corre **dos
veces**:

- **Tenant "unidad"** — `quantity_decimals = 0`, artículos `unit`. Es Casaletto. Prueba que lo nuevo
  no cambió nada.
- **Tenant "peso"** — `quantity_decimals = 3`, artículos `kg`. Es el supermercado.

La regresión que de verdad importa no es "¿funciona el peso?" sino **"¿sigue funcionando todo lo que
ya funcionaba?"**.

### 7c.6 Lista de chequeo antes de desplegar a producción

1. Suite completa en verde, en los dos escenarios de §7c.5.
2. Consultas de §7c.4 ejecutadas contra Casaletto y revisadas.
3. Desplegado y probado en **staging** primero, con los dos tenants presentes.
4. **Migrar todos los tenants** con el bucle de §7c.1, verificando que cada uno salga en cero.
5. Limpiar la caché de configuración de cada tenant.
6. Desplegar el código.
7. Verificar en Casaletto, **solo lectura**: que `quantity_decimals` siga en `0`, que la
   registradora se vea igual, y que una venta reciente se lea bien.
8. **Después de las 22:00 hora Colombia**, como manda la regla del repositorio — nunca con el
   negocio vendiendo.

### 7c.7 Y una decisión de fondo: qué se hace por bandera y qué no

Tentación: meter todo detrás de una bandera "modo supermercado" para no tocar a nadie. **No.** Las
banderas se acumulan y terminan en dos sistemas que hay que probar por separado para siempre.

El criterio que se aplica acá:

- **Los arreglos de defectos NO van detrás de bandera.** `change_quantity()` está mal para todos.
  Esconderlo tras una bandera sería dejar el error vivo en Casaletto a propósito.
- **Lo que depende de datos que el tenant no tiene tampoco necesita bandera.** El campo de peso no
  aparece porque Casaletto no tiene artículos `kg`. Eso ya es aislamiento suficiente, y es más
  robusto que una bandera porque no hay nada que configurar mal.
- **Solo lleva bandera lo que cambiaría la experiencia sin motivo**, que hoy es exactamente una
  cosa: el modo táctil de la registradora.

## 8. Riesgos conocidos que no bloquean

| Riesgo | Estado |
|---|---|
| **Cada escaneo recarga la página completa** ([`app/Controllers/Sales.php:571-643`](../../app/Controllers/Sales.php)), y no existe el "3 × código" | Medir con el catálogo real antes de comprometer velocidad de atención |
| **`get_info_by_id_or_number()` acepta `item_number` **o** `item_id`** ([`app/Models/Item.php:357-386`](../../app/Models/Item.php)) | Mitigado por `barcode_content = item_number`, pero la ambigüedad de lectura persiste |
| **Categorías en texto libre** sin tabla ni jerarquía | Aceptado para un catálogo de decenas de productos |
| **El peso por teclas llega "donde esté el cursor"** | Manejo de foco en la registradora; es donde se van las pruebas |

## 9. Archivos a tocar

| Archivo | Cambio |
|---|---|
| `app/Database/Migrations/` | `unit_of_measure`, `tracks_lots`, `item_lots`, merma, claves de config |
| `app/Models/Item_quantity.php` | Firma de `change_quantity()` a string + `bcadd` (§2.2) |
| `app/Models/Item.php` | `unit_of_measure` en `allowedFields` y consultas |
| `app/Models/Sale.php` | Consumo de lotes en `save()`, condicionado a `tracks_lots` |
| `app/Models/Receiving.php` | Captura opcional de lote y vencimiento |
| `app/Models/Inventory.php` | Motivo clasificado de ajuste |
| `app/Libraries/Token_lib.php` | `break` en `parse_barcode()`, divisor configurable, patrón anclado |
| `app/Libraries/Sale_lib.php` | Cantidad por peso en el carrito |
| `app/Controllers/Sales.php` | Campo de peso y su validación |
| `app/Controllers/Items.php` | `unit_of_measure` en guardado, CSV y edición masiva |
| `app/Views/sales/register.php` | Campo de peso, manejo de foco, enchufe de transporte |
| `app/Views/items/form.php` | Selector de unidad de medida e interruptor de lotes |
| `app/Views/configs/` | Pantalla de configuración de báscula |
| *(repositorio nuevo)* | El agente local en Go |

## 10. Pruebas

- **Regresión de peso**: venta de `0.735`, edición de la línea, y verificación de que sigue siendo
  `0.735`. Es la prueba que fija §2.1 y que hoy fallaría.
- **Anulación con decimales**: anular una venta y una compra de `0.735` y verificar que
  `item_quantities` y `inventory` coinciden **al gramo**. Fija §2.2 — y falla si el `bcadd` no lleva
  escala explícita, que es justo el punto.
- **Pesar dos veces el mismo artículo**: agregar `0.735` y luego `0.740` del mismo producto y
  verificar que el carrito dice `1.475`, no `1.47`. Fija §2.4.
- **`parse_barcode` con dos formatos**: que el primero que coincide gane. Fija §4.4.
- **Venta sin lotes**: con `tracks_lots = 0`, que la venta descuente del total sin tocar `item_lots`
  y sin fallar.
- **Venta con lotes**: consumo del que vence primero.
- **Migración inocua**: que `unit_of_measure` y `tracks_lots` no cambien el comportamiento de los
  artículos existentes de Casaletto.

## 11. Lo que se descartó, y por qué

| Descartado | Razón |
|---|---|
| **Balanza etiquetadora de mostrador** | El cliente decidió pesar en la caja (2026-08-26). El mecanismo de códigos de barras con peso incrustado (`barcode_formats`) se deja configurado como plan B, cuesta casi nada |
| **QZ Tray con certificado comprado** (USD 749/año) | Costo recurrente en dólares. Es pagar por no montar la firma propia, que el propio fabricante documenta como alternativa válida |
| **QZ Tray con certificado propio** | Viable y gratis, pero se prefirió el agente propio para no depender de un tercero ni de su licencia LGPL |
| **PV-COM** (USD 99,99 perpetua por PC) | Era la red de seguridad, pero **se debilitó el 2026-08-28**: su lista no trae el RC-A01E y quien agregaba modelos era Mavin, que ya no da soporte. Sirve solo si el equipo resulta hablar un formato que PV-COM ya soporta. **Ya no se puede contar con ella como respaldo garantizado** |
| **Web Serial** | Gratis y sin instalar nada; hoy en Chrome, Edge, Opera y Firefox 151+. Se descartó frente al agente porque el agente además resuelve impresora y cajón. Queda como enchufe alterno si el permiso de puerto resulta molesto |
| **Tabla de unidades de medida** | Dos valores no justifican una tabla (§3) |
| **Facturación electrónica DIAN** | El cliente no está obligado. Revisar en un año |
| **Contingencia de internet** | Riesgo aceptado por el cliente. Requiere aceptación firmada |

## 12. Orden de implementación

**Estado a 2026-08-28.** El trabajo se organizó en vías disjuntas en archivos, ejecutadas en
paralelo y mergeadas en orden fijo. Detalle del análisis de paralelización y del mapa de colisiones
en el plan (`~/.claude/plans/prancy-puzzling-umbrella.md`).

| Vía | Contenido | Estado |
|---|---|---|
| **V1 · Despliegue** | Entrypoint que migra antes de servir (§7c.1) | **Hecha** — `8f92b4901` |
| **V3 · Precisión** | §2.2 y §2.4, escala explícita de bcmath | **Hecha** — `ba43e270c` |
| **V2 · Unidad de medida** | §3, §3.1 | **Hecha** — `524da70b2` |
| **V5a · Intérprete de báscula** | Token `[\d.]`, claves `scale_*`, pantalla de configuración | En curso |
| **V5b · `parse_barcode()`** | `break`, divisor configurable, patrón anclado (§4.4) | **Bloqueada** — requiere la consulta de §7c.4 contra Casaletto |
| **V6 · Caja** | Unidad en la línea del carrito, campo de peso, foco, teclado en pantalla | En curso |
| **V4 · Operación** | Provisionar el tenant (§7), inspección del instalador | Pendiente — depende del cliente |
| **Inventario** | §6.1, §6.2, §6.3 | Después del corte, por decisión del 2026-08-28 |
| **Agente local** | §5 | Después del corte |

**Con V6 terminada el cliente puede salir a producción** con el peso digitado a mano. El agente
local no está en el camino crítico.

**V5b es el único bloqueo real**, y no es técnico: hace falta leer `barcode_formats` del esquema de
Casaletto (§7c.4) antes de anclar el patrón, porque podría romperle un formato que hoy engancha por
coincidencia parcial. V5a se separó de V5b precisamente para no quedar bloqueada: la báscula usa
claves nuevas y `Token_lib::parse()`, sin tocar el camino de códigos de barras.

**Orden de merge respetado:** V1 → V3 → V2. V3 fue primero entre las de código porque **no trae
migración**, lo que la hacía la candidata segura para estrenar el pipeline nuevo del entrypoint.

### Recordatorio de despliegue

> **Corregido el 2026-08-28.** Una versión anterior de este documento decía "migrar todos los
> esquemas primero y solo entonces desplegar el código". **Ese orden es imposible de ejecutar** y
> está explicado en §7c.1: los archivos de migración viajan dentro de la imagen, así que no existen
> antes de desplegarla.

El procedimiento real es el de §7c.1: **el entrypoint del contenedor migra todos los esquemas antes
de que Apache acepte una petición**, y si alguno falla Apache no arranca. Ya no hay paso manual que
recordar. Lo que sí sigue vigente es la compuerta de §7c.6 antes de tocar producción, y que **cada
merge que traiga una migración es un evento de despliegue, no un commit más**.
