# Tipo y número de documento de las personas — diseño técnico

> **Estado (2026-10-08):** **construido** en la rama `feat/identity-document` (desde `develop` en `68e7bacd4`),
> **sin desplegar**: falta staging y la certificación del negocio, junto con Preventas. Decisiones de negocio
> en `docs/Funcional/documento-de-identidad.md` §6. Lo construido y lo decidido al construir, en §6 y §7.

---

## 1. Lo que existe

| Pieza | Dónde | Nota |
|---|---|---|
| Datos personales comunes | Tabla `people`, modelo `App\Models\Person` (`allowedFields` sin documento), vista compartida `app/Views/people/form_basic_info.php` | La usan `customers/form.php`, `employees/form.php` y `suppliers/form.php`. «Cliente Nuevo» de la caja y de Preventas abre `customers/view` |
| `customers.tax_id` | `Customer::$allowedFields`, `Customers::postSave` (`:276`), `customers/form.php:111-117` | Texto libre «Id Impuesto», opcional, repetible |
| `suppliers.tax_id` | `Supplier::$allowedFields`, `suppliers/form.php` | Igual |
| Impresión | `Sales.php:1357` (`$data['tax_id']`) y `:1762` (`customer_info` con «Id Impuesto: …») | Recibos, facturas, cotizaciones y órdenes de trabajo heredan `customer_info` |
| `customers.account_number` | Único, con validación remota (`Customers::postCheckAccountNumber`) | Código interno; no se toca |
| Búsqueda de clientes | `Customer::get_search_suggestions()` y `search()` (`Customer.php:321-375`) | Nombre, correo, teléfono, cuenta, empresa; no documento |
| Importación CSV | `Customers::postImportCsvFile` (`$data[14]` = cuenta) | Columnas por posición |

Cada rol crea su propia fila en `people`: una persona que es empleada y cliente existe dos veces. Por eso la
unicidad es **por rol** (I3).

## 2. Decisiones técnicas

| # | Decisión | Por qué | Descartado |
|---|---|---|---|
| **IT1** | **Columnas en `people`**: `document_type` varchar(8) NULL y `document_number` varchar(32) NULL, con índice `(document_type, document_number)` | Es un dato de la persona, no del rol; un solo lugar para las tres fichas y para el bloque común | Columnas en `customers`, `employees` y `suppliers` por separado |
| **IT2** | **Unicidad por rol, en el servidor**: el modelo del rol busca otra persona **no borrada** de ese rol con el mismo par (tipo, número) | Una restricción UNIQUE en `people` impediría que la misma persona sea cliente y empleada | UNIQUE en base de datos |
| **IT3** | **Obligatoriedad en el controlador del rol**: `Customers::postSave` y `Employees::postSave` lo exigen; `Suppliers::postSave` no; el empleado con `is_platform_support = 1` queda exento | I2 | Validación solo en la vista (el error de origen de «Adeudo») |
| **IT4** | **Una librería** `App\Libraries\Identity_document`: lista de tipos (código → etiqueta y código DIAN), `normalize(tipo, número)`, `nit_check_digit()` (módulo 11), `validate()` y `format()` («CC 1020345678», «NIT 900123456-8») | Una sola definición para formularios, búsqueda, CSV e impresión | Repetir las reglas en cada controlador |
| **IT5** | **Migración de datos**: `people.document_number` ← `customers.tax_id` / `suppliers.tax_id` cuando está vacío; tipo NULL. Las columnas `tax_id` **no se borran** (vuelta atrás segura) pero dejan de mostrarse | I5; conservar lo guardado | Borrar `tax_id` |
| **IT6** | **Impresión**: `customer_info` usa `Identity_document::format()`; si la persona no tiene tipo, cae al número solo; si no hay número, no imprime la línea | Recibos de clientes antiguos siguen saliendo | — |
| **IT7** | **Texto sin sanear con `FILTER_SANITIZE_*`**: el número se lee crudo y se normaliza con la librería | `docs/Tecnico/correccion-codificacion-tildes.md` | — |

## 3. Piezas a construir

1. **Migración** `AddPersonIdentityDocument`: columnas, índice y copia de `tax_id` (IT5), idempotente.
2. **Librería** `Identity_document` (IT4) con pruebas: normalización por tipo, dígito NIT (casos conocidos),
   formato.
3. **`Person`**: `allowedFields` + método `document_owner(type, number, role, except_person_id)` que
   devuelve la persona del rol con ese documento.
4. **Vista** `people/form_basic_info.php`: selector de tipo y número, al principio; obligatorios según el rol
   (variable que pasa cada formulario); aviso de duplicado en otro rol (consulta asíncrona propia, sin
   exigir permisos de otros módulos).
5. **Controladores**: `Customers::postSave`, `Employees::postSave`, `Suppliers::postSave` — validar,
   normalizar, unicidad por rol (IT2), aviso entre roles; `Customers` quita «Id Impuesto» del formulario.
6. **Búsqueda**: `Customer::get_search_suggestions()` y `search()` por `document_number` (normalizado con
   la misma regla); lo heredan la caja y `Presales::getSuggestCustomer`.
7. **Listas**: columna «Documento» en clientes, empleados y proveedores; filtro «Sin documento» en clientes.
8. **Impresión** (IT6): `Sales.php` (`:1357`, `:1762`), comprobantes de Preventas
   (`app/Views/presales/receipt_head.php`) y cotización/orden de trabajo vía `customer_info`.
9. **CSV de clientes**: dos columnas nuevas (al final, para no correr las posiciones existentes), plantilla
   actualizada, errores por fila.
10. **Idiomas** es-MX, es-ES y en.

## 4. Trampas

- **Autoguardado de «Cliente Nuevo» en la caja y en Preventas**: usan `customers/save`; el formulario ya
  incluye el bloque común, así que el documento llega solo, pero hay que probar los dos caminos.
- **`Home::postSave`** (perfil propio del empleado) no debe exigir documento si solo cambia la contraseña.
- **Proveedores con NIT y empresa**: `company_name` sigue siendo el nombre; el documento es el NIT.
- **Comparación del número**: siempre normalizado, o «1.020.345.678» y «1020345678» serían dos clientes.
- **Base de pruebas compartida**: las pruebas crean sus propias personas y las borran; nunca truncar `people`.

## 5. Pruebas

| Prueba | Fija |
|---|---|
| Cliente sin tipo o sin número rechazado; proveedor sin documento aceptado | IT3 |
| «1.020.345.678» se guarda como 1020345678; pasaporte con letras aceptado; CC con letras rechazado | IT4 |
| NIT: dígito calculado; NIT con dígito equivocado rechazado | IT4 |
| Cliente repetido (mismo par) rechazado; mismo documento en un empleado: guarda y avisa | IT2 |
| Cliente borrado no bloquea | IT2 |
| Búsqueda por número (con y sin puntos) encuentra al cliente, también en `presales/suggestCustomer` | §3.6 |
| Recibo y comprobante de preventa imprimen «CC …»; cliente antiguo sin tipo imprime el número | IT6 |
| Migración: copia `tax_id`, idempotente, no toca `account_number` | IT5 |
| CSV: filas válidas entran; sin documento o repetido, rechazadas con motivo | §3.9 |
| Soporte de plataforma exento; cambiar la contraseña propia no exige documento | §4 |

## 6. Lo construido (2026-10-08)

Commits en `feat/identity-document`, en este orden: librería, migración, fichas/búsqueda/listas/CSV,
impresión, documentación.

| Pieza (§3) | Dónde quedó |
|---|---|
| 1. Migración | `app/Database/Migrations/20261008040000_AddPersonIdentityDocument.php`. Columnas `after last_name`, índice `people_document (document_type, document_number)` creado con `ALTER TABLE` si no existe, copia `UPDATE people JOIN customers/suppliers SET document_number = TRIM(tax_id)` solo donde `document_number` está vacío. `down()` quita índice y columnas; `tax_id` nunca se toca |
| 2. Librería | `app/Libraries/Identity_document.php`: `TYPES` (código → código DIAN y si es solo dígitos), `is_type()`, `dian_code()`, `label()`, `options()`, `normalize()`, `validate()` (devuelve la clave de idioma del error o null), `nit_check_digit()`, `format()`, `search_key()` |
| 3. `Person` | `allowedFields` + `DOCUMENT_ROLES`, `DOCUMENT_NUMBER_KEY_SQL`, `document_owner()` y `document_other_roles()` |
| 4. Vista | `people/form_basic_info.php`: los dos campos primero; `$document_required` lo pasa cada ficha (`required` + `data-msg-required`, que jQuery Validate lee solo); define `identity_document_remote`, la regla remota que usan las tres fichas |
| 5. Controladores | `Persons::read_identity_document()` (lectura, validación, unicidad, avisos) y `Persons::postCheckDocument()`; lo llaman `Customers::postSave` (obligatorio), `Employees::postSave` (obligatorio salvo `is_platform_support = 1`) y `Suppliers::postSave` (opcional) **antes** de escribir nada. La respuesta de éxito trae `warning` |
| 6. Búsqueda | `Customer::or_like_document()` dentro de `get_search_suggestions()` (modo único: caja, Preventas) y `search()` (lista); en modo no único, una sugerencia más con el documento formateado |
| 7. Listas | `tabular_helper.php`: columna `document_number` en `person_headers()` (empleados), `customer_headers()` y `supplier_headers()`; filtro `no_document` en `Customers::getIndex/getSearch` y `people/manage.php` (multiselect + `partial/table_filter_persistence`) |
| 8. Impresión | `Sales::_load_customer_data()` deja `$data['customer_document']` y lo añade a `customer_info`; `sales/receipt_default|short|email.php` lo muestran bajo el cliente; `presales/receipt_presale|payment|cancel.php` una fila «Documento». **No** en `receipt_head.php`: esa cabecera no recibe al cliente |
| 9. CSV | `Customers::postImportCsvFile`: columnas 18 (tipo) y 19 (número); plantilla `writable/uploads/importCustomers.csv`; error por fila `Customers.csv_row_error` («Fila {0}: {1}») |
| 10. Idiomas | `Common.document*` y `Customers.no_document`, `Customers.csv_row_error` en es-MX, es-ES y en. `Customers.csv_import_partially_failed` en es-MX no tenía `{0}`/`{1}` y nunca mostró qué filas fallaron: corregido |

## 7. Decisiones tomadas al construir

| # | Decisión | Por qué | Descartado |
|---|---|---|---|
| **IT8** | Un número **viejo sin tipo** cuenta para la unicidad y la búsqueda, comparado sin puntos, comas, espacios ni guiones (`DOCUMENT_NUMBER_KEY_SQL`) | La migración copia `tax_id` tal como estaba escrito; sin esto, «1.020.345.678» viejo y CC 1020345678 nuevo serían dos clientes, la trampa de §4 | Exigir el par exacto (deja pasar duplicados con los clientes de antes); normalizar en la migración (no se sabe el tipo, y un NIT viejo con dígito perdería el guion) |
| **IT9** | El dígito de verificación del NIT solo se reconoce **después de un guion** («900123456-8»); sin guion, todos los dígitos son el NIT | «9001234568» no dice si el 8 es dígito o parte del número | Adivinar por la longitud |
| **IT10** | `tax_id` deja de **escribirse** al guardar (clientes y proveedores), no solo de mostrarse | Al quitar el campo del formulario, el guardar lo habría vaciado; así conserva lo de antes para la vuelta atrás | Seguir escribiéndolo con el número (dos fuentes de verdad) |
| **IT11** | La consulta mientras se escribe es la regla `remote` de jQuery Validate con `dataFilter`: `{valid, message, warning}` → `true` o el mensaje, y el aviso a `#document_warning` | Usa el mismo camino que el correo y la cuenta; un duplicado en el rol bloquea el envío en la pantalla, no después | Un `$.post` aparte que no bloquea el envío |
| **IT12** | Los mensajes que nombran a una persona escapan el nombre en el servidor (`esc()`) | `$.notify()` y el contenedor de errores pintan HTML; así se guarda el nombre con sus tildes y se escapa a la salida | — |
| **IT13** | El filtro «Sin documento» incluye a los que tienen número **sin tipo** | Son los que la ficha va a pedir completar; el filtro existe para eso (Funcional §4.4) | Solo los que no tienen número |

### 7.1 Comportamiento de la migración sobre los datos existentes

- Clientes y proveedores con `tax_id` no vacío → `people.document_number = TRIM(tax_id)`, `document_type`
  NULL, salvo que la persona ya tuviera número. Con espacios solamente, no se copia.
- Clientes borrados también se copian (no estorban: la unicidad mira solo los no borrados).
- Empleados: nada que copiar; todos quedan sin documento hasta que se editen.
- Una segunda corrida no cambia nada; el log del contenedor dice cuántos se copiaron por rol.

### 7.2 Pruebas

| Archivo | Qué fija |
|---|---|
| `tests/Libraries/IdentityDocumentTest.php` | Lista y códigos DIAN, limpieza por tipo, dígito NIT contra NIT reales (DIAN 800197268-4, Bancolombia 890903938-8, Ecopetrol 899999068-1, Éxito 890900608-9, Davivienda 860034313-7) y restos 0/1, errores, formato, clave de búsqueda |
| `tests/Database/PersonIdentityDocumentMigrationTest.php` | Columnas e índice; copia de `tax_id` de clientes y proveedores sin tipo; no pisa un documento; vacío queda vacío; idempotente; no toca `tax_id` ni `account_number` |
| `tests/Controllers/PersonIdentityDocumentTest.php` | Todo §5 por las pantallas reales: obligatorio, limpieza, NIT, duplicados (con y sin puntos, cliente viejo sin tipo, borrado no bloquea), aviso entre roles con el nombre escapado, `checkDocument`, proveedor opcional y único, empleado obligatorio y soporte exento, contraseña propia sin documento, búsqueda en Clientes, caja (`customers/suggest`) y `presales/suggestCustomer`, plantilla y carga CSV |
| `tests/Controllers/IdentityDocumentPrintTest.php` | `customer_info` con «CC …», NIT con dígito, número solo, sin línea; recibos y comprobantes de preventa |

Pruebas existentes ajustadas porque guardaban fichas sin documento: `CustomersAccentTest` (cliente con CC),
`EmployeesControllerTest` (las cuatro que editan por `/employees/save` llevan `documentFor()`),
`CustomersCsvImportTest` (las filas llevan las dos columnas nuevas, con número al azar porque esa clase no
borra lo que importa).

### 7.3 Lo que queda fuera o pendiente

- **Sin desplegar ni certificar.** Va a staging con Preventas; la guía es
  `docs/Funcional/venta-anticipada-guia-de-certificacion.md` (pasos 2 y 12).
- Si el servidor rechaza una ficha (algo que la pantalla no atajó), el diálogo se cierra y sale el error,
  como ya pasaba con cualquier error de guardado: lo escrito se pierde. Con la consulta mientras se escribe
  debería ser raro.
- El mensaje de éxito de `Customers::postSave` sigue llevando el nombre sin escapar (ya era así; Preventas
  lo esquiva leyendo la etiqueta aparte). No se tocó.
- Los códigos DIAN quedan en la librería, sin uso todavía: son para una factura electrónica que este
  requerimiento no hace.
- Proveedores: un número sin tipo no se puede guardar; un proveedor viejo con «Id Impuesto» pide el tipo
  si se edita y se deja el número.

