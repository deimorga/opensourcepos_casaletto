# Tipo y número de documento de las personas — diseño técnico

> **Estado (2026-10-08):** **diseño aprobado, en construcción** (rama `feat/identity-document`; sale con Preventas). Decisiones de negocio en
> `docs/Funcional/documento-de-identidad.md` §6. Verificado contra `develop` en `ea184d274`.

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
| **IT4** | **Una librería** `App\Libraries\Identity_document`: lista de tipos (código → etiqueta y código DIAN), `normalize(tipo, número)`, `nit_check_digit()` (módulo 11), `validate()` y `format()` («CC 1020345678», «NIT 900123456-7») | Una sola definición para formularios, búsqueda, CSV e impresión | Repetir las reglas en cada controlador |
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
