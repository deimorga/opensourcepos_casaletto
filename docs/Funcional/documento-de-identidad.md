# Alcance funcional — Tipo y número de documento de las personas

> **Estado (2026-10-08):** **definición, sin construir.** Pedido del dueño el 2026-10-08, durante la
> certificación de Preventas en staging. Decisiones en §6. Falta decidir cuándo sale respecto a Preventas
> (§7).
>
> Documento hermano: `docs/Tecnico/documento-de-identidad.md`.

---

## 1. El problema, en una frase

Los clientes, empleados y proveedores no tienen **tipo y número de documento**. Hoy hay un campo de texto
libre, «Id Impuesto», que nadie está obligado a llenar, sin tipo (¿cédula?, ¿NIT?) y que se puede repetir.
Se necesita para identificar a cada cliente sin confusiones —sobre todo en las preventas, donde se le
entrega mercancía pagada por adelantado— y en cualquier parte del sistema que lo requiera.

## 2. Qué hay hoy, verificado

| Dato | Dónde | Cómo es |
|---|---|---|
| «Id Impuesto» | Ficha de clientes y de proveedores | Texto libre, opcional, se puede repetir, sin tipo. Ya sale en recibos y facturas como «Id Impuesto: …» |
| «Cuenta #» | Ficha de clientes | Código interno del cliente, único. **No es** un documento de identidad y no cambia |
| Tipo de documento | — | No existe |
| Empleados | Ficha de empleados | No tienen ningún dato de identificación |

Las tres fichas (clientes, empleados, proveedores) comparten el mismo bloque de datos personales, así que
el documento se agrega una vez y aparece en las tres, también en «Cliente Nuevo» de la caja y de Preventas.

**Algo importante del sistema actual:** cada rol crea su propia ficha. Si una empleada también compra como
cliente, existe dos veces: una como empleada y otra como cliente. Por eso el mismo documento **puede
repetirse entre roles** (es la misma persona), pero **no dentro del mismo rol**.

## 3. La solución

Cada persona —cliente, empleado o proveedor— tiene **tipo de documento** y **número de documento**:

| Rol | ¿Obligatorio? | ¿Único? |
|---|---|---|
| **Cliente** | **Sí**, al crearlo y al editarlo | **Sí**, entre los clientes del negocio |
| **Empleado** | **Sí**, al crearlo y al editarlo | **Sí**, entre los empleados del negocio |
| **Proveedor** | No (muchos se registran sin él) | Si se pone, sí: entre los proveedores |

El mismo documento **sí** puede estar a la vez en un cliente, un empleado y un proveedor: es la misma persona
en distintos papeles. Cuando eso pasa, el sistema **avisa** («este documento también corresponde al
empleado Juan Pérez») pero **deja guardar**.

## 4. Cómo funciona

### 4.1 Los tipos (lista de la DIAN)

| Código | Tipo |
|---|---|
| CC | Cédula de ciudadanía |
| CE | Cédula de extranjería |
| TI | Tarjeta de identidad |
| RC | Registro civil |
| NIT | NIT (empresas y personas con NIT) |
| PA | Pasaporte |
| PPT | Permiso por protección temporal |
| PEP | Permiso especial de permanencia |
| DIE | Documento de identificación extranjero |
| NUIP | Número único de identificación personal |

Es la misma lista que usa la facturación electrónica, para que un cliente quede listo si algún día se factura
desde aquí.

### 4.2 Cómo se escribe el número

- Para CC, CE, TI, RC, NIT y NUIP **solo números**: el sistema quita puntos, comas, espacios y guiones que
  se escriban por costumbre («1.020.345.678» se guarda como 1020345678).
- Para pasaporte, PPT, PEP y documento extranjero se aceptan **letras y números**.
- **NIT:** se escribe el número **sin** el dígito de verificación; el sistema lo calcula y lo muestra
  («900123456-7»). Si se escribe con dígito y está mal, avisa.

### 4.3 Dónde aparece

- **Fichas** de clientes, empleados y proveedores, y **«Cliente Nuevo»** en la caja y en Preventas.
- **Búsqueda**: escribir el número de documento encuentra al cliente en Clientes, en la caja y en Preventas.
- **Documentos impresos**: recibos, facturas, cotizaciones, órdenes de trabajo y comprobantes de preventa
  muestran «CC 1020345678» (o «NIT 900123456-7») en lugar de «Id Impuesto: …».
- **Listas**: columna «Documento» en Clientes, Empleados y Proveedores.
- **Carga masiva de clientes (CSV)**: dos columnas nuevas, tipo y número, con las mismas reglas; una fila sin
  documento o repetida se rechaza con su motivo.

### 4.4 Los clientes y proveedores que ya existen

- Lo que hoy tienen en «Id Impuesto» **se conserva** como número de documento, sin tipo.
- Siguen funcionando igual: se pueden vender, buscar y usar en preventas.
- **Al editarlos** por primera vez, la ficha pide completar el tipo (y el número si estaba vacío) antes de
  guardar.
- La lista de clientes tiene un filtro «Sin documento» para completarlos de a poco.

### 4.5 Excepciones

- **Cliente de mostrador** (venta sin cliente): no cambia nada; la caja sigue vendiendo sin cliente.
- **Empleado de soporte de la plataforma** (`soporte_micronuba`): no se le exige documento.

## 5. Lo que este requerimiento NO hace

- No une las fichas repetidas de una misma persona en distintos roles (eso sería otro proyecto).
- No valida contra la Registraduría ni la DIAN que el documento exista.
- No emite factura electrónica.
- No cambia «Cuenta #».

## 6. Decisiones tomadas

| # | Decisión | Fecha |
|---|---|---|
| **I1** | Lista de tipos de la DIAN (§4.1) | 2026-10-08 |
| **I2** | Obligatorio siempre para clientes y empleados; opcional para proveedores | 2026-10-08 |
| **I3** | Único dentro de cada rol en el negocio; se puede repetir entre roles, con aviso | 2026-10-08 |
| **I4** | Aplica a clientes, empleados y proveedores; búsqueda por documento; impreso en documentos; carga masiva | 2026-10-08 |
| **I5** | «Id Impuesto» se convierte en «Número de documento»; lo ya guardado se conserva | 2026-10-08 (propuesta) |
| **I6** | NIT con dígito de verificación calculado | 2026-10-08 (propuesta) |

### 6.1 Preguntas resueltas el 2026-10-08

| Pregunta | Respuesta del dueño |
|---|---|
| ¿Qué tipos? | Lista DIAN de Colombia |
| ¿Obligatorio? | Siempre, al crear clientes |
| ¿Único? | Sí, sin duplicados |
| ¿Dónde más? | Proveedores, búsqueda por documento, documentos impresos, carga masiva, y también empleados: todos los usuarios. Ojo: empleados, funcionarios o proveedores también pueden ser clientes, y los proveedores no necesariamente tienen documento |

## 7. Cuándo sale

Pendiente de decisión del dueño. Opciones:

- **Con Preventas**, antes de producción: el cliente de una preventa nace con documento. Agrega unos días a
  la salida de Preventas.
- **Después de Preventas**, en su propio despliegue: Preventas sale en la fecha prevista y los clientes se
  completan al editarlos.

## 8. Cómo se va a comprobar

En staging, en dos negocios: crear cliente sin documento (rechazado), con CC con puntos (se limpia), con NIT
(muestra dígito), repetido entre clientes (rechazado), igual a un empleado (avisa y guarda); buscar por
número en Clientes, caja y Preventas; ver el documento en recibo, factura y comprobante de preventa; editar un
cliente antiguo (pide el tipo); cargar un CSV con filas buenas y malas; crear un proveedor sin documento
(permitido).
