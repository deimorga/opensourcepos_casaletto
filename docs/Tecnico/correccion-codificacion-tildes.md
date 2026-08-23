# Corrección: las tildes se guardaban como entidades HTML

> **Estado a 2026-08-22: fases 0, 1 y 1b completas y EN PRODUCCIÓN.** Quedan tres módulos menores.

El diagnóstico completo del bug —qué lo causa, cómo se descartó todo lo demás, y el registro de los
dos despliegues a producción— vive en `docs/Tecnico/errores-produccion-upstream.md` **sección 5**,
que es la bitácora de errores heredados de upstream. Este documento es el **plan de trabajo**: qué
fases hay, qué se hizo en cada una y qué falta.

---

## 1. El problema, en una línea

`FILTER_SANITIZE_FULL_SPECIAL_CHARS` no es equivalente a `htmlspecialchars()` pese a lo que dice la
documentación de PHP: se comporta como `htmlentities()` y convierte las vocales acentuadas en
entidades HTML con nombre. Se usaba **147 veces en 19 controladores**.

## 2. Estado por fases

| Fase | Alcance | Estado |
|---|---|---|
| **0 · Documentación** | Corregir la deriva entre los documentos y el código; regla permanente en `AGENTS.md` | ✅ Hecha |
| **1 · Medios de pago y búsqueda** | 5 lecturas de medio de pago + 2 de `search`; reparación de datos; columna `payment_type_code` | ✅ **En producción** (2026-08-22, ~21:45) |
| **1b · Resto de módulos** | 15 controladores, 63 lecturas, ~20 salidas escapadas | ✅ **En producción** (2026-08-22, ~22:55) |
| **1c · Módulos restantes** | `Customers`, `Giftcards`, `Home`, doble escapado de `Attributes` | ⬜ Pendiente, sin urgencia |

## 3. Lo que se corrigió, con cifras

**Datos reparados en producción: 1.548 filas**, ninguna sin resolver.

| Columna | Filas | Qué contenían |
|---|---|---|
| `sales_payments.payment_type` | 201 | `Tarjeta de d&eacute;bito`, `Tarjeta de Cr&eacute;dito` |
| `items.description` | 50 | `Unidad: n&uacute;mero…` (venían del archivo de Siigo) |
| `sales_items.description` | 1.297 | lo mismo, copiado a cada línea al completar la venta |

**Bugs de usuario que esto arregló:**

- Los filtros de **Tarjeta de Débito y Crédito** de la grilla de Ventas devolvían **cero**
  coincidencias sobre **13,2 millones de pesos** (195 y 6 pagos).
- El filtro **Adeudo** de Gastos devolvía "No hay gastos a mostrar" con gastos registrados.
- **Transferencia Bancaria y Monedero no tenían filtro** en Gastos: 1.650.000 inalcanzables.
- Buscar **"Jamón"** en Artículos devolvía **0** teniendo **12**.
- El filtro de **Efectivo** capturaba además los **ajustes de caja**, por comparar substrings.

**Dos vulnerabilidades preexistentes**, encontradas por dos agentes de forma independiente:

- **`$.notify` es un sink de HTML.** `bootstrap-notify` interpola el mensaje en una plantilla y se la
  pasa a `$()`. Los nombres de kit y de definición de atributo **nunca estuvieron filtrados**, así que
  ya eran explotables. Hay 33 sitios que llaman a `$.notify`, así que no existe un sink único:
  **escapar en el controlador es el patrón** a seguir.
- **`form_dropdown()` escapa el `value` de la opción pero no la etiqueta.** Patrón de toda la
  aplicación: medios de pago, categorías, ubicaciones, mesas, códigos de impuesto, atributos.

## 4. La regla que ordenó el trabajo

**Primero se escapa la salida, después se quita el filtro. Nunca al revés, ni por un rato.**

Ese filtro hacía doble oficio: además de deformar el dato, era lo único entre lo posteado y las ~255
salidas sin escapar de las vistas. Quitarlo sin auditar la salida cambia un problema de datos por uno
de seguridad.

Para valores de lista conocida (medios de pago) la solución es mejor todavía: **validar contra lista
blanca** en vez de sanear. No deforma el dato y es más seguro.

## 5. Cómo se ejecutó

**Fase 1: un solo hilo.** Cinco lecturas y tres migraciones; el trabajo caro no era escribir código
sino sembrar staging con datos representativos y probar que la migración no movía un peso.

**Fase 1b: tres agentes en paralelo**, un grupo de módulos cada uno (Personas / Catálogos e
Impuestos / Transaccional), en worktrees separados y cargando la skill `security-and-hardening` para
la auditoría de escapado. `tabular_helper.php` quedó fuera del alcance de los tres por ser
compartido; se integró aparte. Las tres ramas mezclaron sin un solo conflicto.

**Regla que no cambió:** todo lo que tocó staging o producción lo ejecutó un solo hilo.

## 6. Lo que los agentes encontraron en el trabajo previo

Dos huecos reales en la fase 1, ambos confirmados:

1. **Tres de seis plantillas de recibo** imprimían el medio de pago en crudo. Se había revisado
   `receipt_default`, visto que escapaba, y **extrapolado al resto**. Como las lecturas ya estaban sin
   filtro en producción, era una ruta viva.
2. **El barrido de producción estaba incompleto**: faltaban `ospos_receivings` y `ospos_sales_items`.
   Esta última tenía las 1.297 filas.

Es el argumento a favor de la revisión adversarial: quien escribió el código no ve lo que dio por
supuesto.

## 7. Verificación

**Staging, con navegador real.** 11 pantallas sin errores de JavaScript. Guardados por la aplicación
con caracteres difíciles a propósito —tildes, `ñ`, `ü`, `ç`, `¿?`, `¡!`, una raya em de 3 bytes y un
`&` literal— en empleado, proveedor, comentario de venta, gasto y categoría de gasto: **todos los
bytes correctos**. El lado de la salida verificado contra el error opuesto: las grillas muestran los
caracteres, el `&` aparece escapado **una sola vez** sin doble escapado, y buscar "Muñoz" encuentra al
empleado recién creado.

**Producción, contra totales de control.** En los dos despliegues, el número de filas y las sumas de
importes quedaron **idénticos al peso**: 742 pagos por 36.098.640,00 y 2.665.132,50; 8.937 líneas de
venta con 5.040,652 de cantidad y 39.763.160,00 de precios. Cero errores en el log.

**Trampa de método, ya cometida:** el primer barrido de errores de JavaScript reportó "0 errores" en
14 páginas y era **falso** — el patrón de conteo no leía el formato real de la herramienta. Se detectó
contrastando con la salida cruda. Un conteo demasiado limpio hay que contrastarlo antes de creerlo.

## 8. Reversión

Las tres migraciones tienen `down()` probado. `ospos_html_entity_repair_backup` guarda el valor
original de las **1.548 filas** reparadas en producción. Respaldos previos en
`/root/backups_encoding_fix/` (`prod_pre_fase1_20260822.sql` y `prod_pre_fase1b_20260822.sql`).

## 9. Pendiente

**Fase 1c**, sin urgencia:

- `Customers` — sus 2 usos son `sort`/`order`, así que no hay nada que corregir en la entrada. **Pero
  antes de tocarlo hay que escapar `app/Views/reports/specific_customer_input.php:36`**, que arma un
  `form_dropdown` con nombre y empresa del cliente sin escapar.
- `Giftcards` (`giftcard_number`) y `Home` (`username`) — ASCII en la práctica.
- **El doble escapado de `Attributes`** (`form.php:26,72`, `item.php:58`): `esc()` envuelve un valor
  que `form_input()` ya escapa, así que cada edición corrompe un poco más (`A & B` → `A &amp; B` →
  `A &amp;amp; B`). Viene de upstream. Arreglar `item.php:58` es el requisito para retirar el cuarto
  `html_entity_decode()` de ese controlador.

**Punto ciego aparte, sin diagnosticar:** un POST incompleto a `item_kits/save` devuelve **500 y el
log de la aplicación no crece ni una línea**; tampoco el stderr del contenedor. Solo consta en el log
de acceso del servidor web, y eso que `Config\Exceptions::$log = true` y solo ignora el 404. No es
regresión de este trabajo. **Un 500 que no llega al log es exactamente el punto ciego que este
trabajo vino a corregir**, así que merece investigarse por su cuenta.

**Otros hallazgos reportados por los agentes y no corregidos**, para no perderlos: cinco métodos de
los controladores de impuestos renderizan vistas que no existen; `Expenses_categories::postSave`
devuelve `success: true` en su rama de fallo; `sales/form.php:158` interpola el correo del cliente
crudo en un `confirm()` de JavaScript; y `Item_kits::postSave` llama a `parse_decimals()` sobre un
campo que puede no venir, la misma fragilidad que se corrigió en Turnos.
