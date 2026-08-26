# Diseño técnico — Venta por peso, hardware de caja e inventario para supermercado

> **Estado a 2026-08-26: diseño cerrado, nada implementado.** No hay código, ni migraciones, ni
> configuración de este requerimiento en ninguna rama. Este documento es el punto de partida.
>
> Alcance funcional en `docs/Funcional/venta-por-peso-y-hardware-de-caja.md`.
> Análisis de origen sobre el commit `bac37a392` de `develop`.

---

## 1. Principio rector

**El peso es una cantidad decimal que atraviesa todo el sistema, y hoy hay tres puntos donde se
pierde en silencio.** Antes de conectar una báscula hay que cerrarlos, porque un error de peso no
se ve: la venta cuadra en plata y el inventario queda mal para siempre.

Un corolario que ordena las decisiones de hardware: **el navegador es el límite, no el protocolo.**
Todo lo que sigue sobre la báscula existe para cruzar esa frontera de la forma más barata y más
reutilizable posible.

## 2. Los tres defectos bloqueantes

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

**Arreglo:** cambiar la firma a `string $quantity_change` y operar con `bcadd`, igual que hace
`Sale::save()`, que sí es correcto porque calcula el nuevo saldo inline sin pasar por esta función.

### 2.3 Los artículos no tienen unidad de medida

La tabla `items` no tiene ninguna columna que distinga un artículo que se vende por kilo de uno que
se vende por unidad. Ver el esquema base en
`app/Database/Migrations/sqlscripts/initial_schema.sql`.

Con la báscula en la caja esto dejó de ser cosmético: **es lo que le dice al POS a qué artículo
pedirle el peso.** Es infraestructura del requerimiento, no un detalle de presentación.

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
  `item_quantities` y `inventory` coinciden. Fija §2.2.
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
| **PV-COM** (USD 99,99 perpetua por PC) | Buena opción y de proveedor colombiano. **No se borra del mapa: queda como red de seguridad** si el agente se demora o falla en una caja |
| **Web Serial** | Gratis y sin instalar nada; hoy en Chrome, Edge, Opera y Firefox 151+. Se descartó frente al agente porque el agente además resuelve impresora y cajón. Queda como enchufe alterno si el permiso de puerto resulta molesto |
| **Tabla de unidades de medida** | Dos valores no justifican una tabla (§3) |
| **Facturación electrónica DIAN** | El cliente no está obligado. Revisar en un año |
| **Contingencia de internet** | Riesgo aceptado por el cliente. Requiere aceptación firmada |

## 12. Orden de implementación

1. **Fase 1** — §2.1, §2.2, §3 y §4.4, con las pruebas de §10. No depende de nada y protege también
   a Casaletto de una regresión.
2. **Fase 2** — Provisionar el tenant y aplicar §7.
3. **Fase 3** — §6.1, §6.2 y §6.3, en ese orden. La merma primero porque es lo que este cliente usa
   a diario.
4. **Fase 4** — §4.2 y §4.3: campo de peso, foco e intérprete, con el enchufe de teclas.
5. **Fase 5** — Catálogo y montaje del hardware en el local.
6. **Fase 6** — §5, el agente local.
7. **Fase 7** — Acompañamiento de la primera semana.

**Recordatorio de despliegue:** los workflows de este repositorio **no ejecutan migraciones**. Cada
fase que agregue una necesita `php spark migrate` disparado a mano por SSH después del despliegue.
