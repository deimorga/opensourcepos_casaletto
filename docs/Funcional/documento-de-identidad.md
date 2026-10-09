# Alcance funcional — Tipo y número de documento de las personas

> **Estado (2026-10-08):** **construido**, en la rama `feat/identity-document`, **pendiente de desplegar a
> staging y certificar** junto con Preventas (§7). Pedido del dueño el 2026-10-08, durante la certificación
> de Preventas en staging. Decisiones en §6. Cómo quedó, en §9.
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
  («900123456-8»). Si se escribe con dígito después de un guion («900.123.456-8») y está bien, se acepta y
  se guarda sin él; si está mal, **no deja guardar** y lo dice.

### 4.3 Dónde aparece

- **Fichas** de clientes, empleados y proveedores, y **«Cliente Nuevo»** en la caja y en Preventas.
- **Búsqueda**: escribir el número de documento encuentra al cliente en Clientes, en la caja y en Preventas.
- **Documentos impresos**: recibos, facturas, cotizaciones, órdenes de trabajo y comprobantes de preventa
  muestran «CC 1020345678» (o «NIT 900123456-8») en lugar de «Id Impuesto: …».
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
| **I5** | «Id Impuesto» se convierte en «Número de documento»; lo ya guardado se conserva | 2026-10-08 |
| **I6** | NIT con dígito de verificación calculado | 2026-10-08 |
| **I7** | Sale junto con Preventas | 2026-10-08 |

### 6.1 Preguntas resueltas el 2026-10-08

| Pregunta | Respuesta del dueño |
|---|---|
| ¿Qué tipos? | Lista DIAN de Colombia |
| ¿Obligatorio? | Siempre, al crear clientes |
| ¿Único? | Sí, sin duplicados |
| ¿Dónde más? | Proveedores, búsqueda por documento, documentos impresos, carga masiva, y también empleados: todos los usuarios. Ojo: empleados, funcionarios o proveedores también pueden ser clientes, y los proveedores no necesariamente tienen documento |

## 7. Cuándo sale

**Junto con Preventas** (decisión del dueño, 2026-10-08): se construye y se despliega a staging antes de
la salida, para que los clientes de las preventas nazcan con documento. El equipo del negocio certifica las
dos cosas juntas con la guía actualizada. **I7**.

## 8. Cómo se va a comprobar

En staging, en dos negocios: crear cliente sin documento (rechazado), con CC con puntos (se limpia), con NIT
(muestra dígito), repetido entre clientes (rechazado), igual a un empleado (avisa y guarda); buscar por
número en Clientes, caja y Preventas; ver el documento en recibo, factura y comprobante de preventa; editar un
cliente antiguo (pide el tipo); cargar un CSV con filas buenas y malas; crear un proveedor sin documento
(permitido).

## 9. Cómo quedó (construido el 2026-10-08)

Lo que va a ver el equipo del negocio cuando se despliegue. Todavía **no** está en staging ni en producción.

### 9.1 En las fichas

- Las fichas de **clientes**, **empleados** y **proveedores** empiezan con **Tipo de documento** (una lista
  con los diez tipos de §4.1, «CC - Cédula de ciudadanía», …) y **Número de documento**.
- En clientes y empleados los dos campos van marcados como obligatorios: sin ellos la ficha no se envía.
  En proveedores no.
- Si el NIT es el tipo elegido, bajo el número aparece «El NIT va sin dígito de verificación: el sistema lo
  calcula».
- **Mientras se escribe**, la ficha consulta el número:
  - si ya lo tiene **otro cliente** (u otro empleado, u otro proveedor, según la ficha), lo dice en rojo
    con el nombre — «Ya hay un cliente con este documento: Ana Gómez.» — y no deja guardar;
  - si lo tiene alguien **en otro papel**, lo dice en amarillo bajo el campo — «Este documento también
    corresponde al empleado Juan Pérez.» — y deja guardar. Al guardar, el aviso vuelve a salir.
- La ficha de clientes ya **no** tiene «Id Impuesto»; la de proveedores tampoco. Lo que tenían quedó como
  número de documento (§9.4).
- Lo mismo pasa en **«Cliente Nuevo»** de la caja y de Preventas: es la misma ficha.

### 9.2 Búsqueda, listas y filtro

- En **Clientes**, en la **caja** y en **Preventas**, escribir el número —con o sin puntos— encuentra al
  cliente.
- Las listas de **Clientes**, **Empleados** y **Proveedores** tienen la columna **«Documento»**
  («CC 1020345678», «NIT 900123456-8»).
- La lista de **Clientes** tiene el filtro **«Sin documento»**: los clientes sin número o con un número
  viejo todavía sin tipo, para completarlos de a poco.

### 9.3 En el papel

- **Recibo** (normal, corto y por correo): una línea con el documento bajo «Cliente».
- **Factura, cotización y orden de trabajo**: el documento en el bloque del cliente, donde antes salía
  «Id Impuesto: …».
- **Comprobantes de preventa** (preventa, abono y cancelación): una fila «Documento» bajo el cliente.
- Un cliente viejo sin tipo imprime el número solo; un cliente sin número no imprime nada.

### 9.4 Lo que ya existía

- Al desplegar, lo que cada cliente y proveedor tenía en «Id Impuesto» pasa a ser su **número de
  documento, sin tipo**, tal como estaba escrito. Nada se borra: «Id Impuesto» sigue guardado por si hay
  que volver atrás.
- Esos clientes siguen vendiéndose, buscándose e imprimiéndose igual. **La primera vez que alguien los
  edite**, la ficha pide el tipo (y el número si no tenían).
- Un cliente viejo con «1.020.345.678» sin tipo **cuenta** como dueño de ese documento: no se puede crear
  otro cliente con CC 1020345678.
- Los empleados no tenían ningún dato de identificación: **todos** quedan sin documento y lo piden la
  primera vez que se editen. El de soporte de la plataforma, nunca.
- Un proveedor viejo con número pero sin tipo pide el tipo si se edita y se deja el número; si se borra el
  número, guarda sin documento.
- Cambiar la contraseña propia (Inicio → contraseña) no pide documento.

### 9.5 Carga masiva de clientes (CSV)

- La plantilla tiene dos columnas nuevas **al final**: «Document Type» (CC, NIT, PA…) y «Document Number».
  Las demás columnas no se movieron.
- Una fila sin documento, con un documento que no es válido para su tipo, o con un documento que ya tiene
  otro cliente (o una fila anterior del mismo archivo), **no entra**, y el mensaje dice la fila y el motivo:
  «Fila 3: Ya hay un cliente con este documento: Ana Gómez.» Las filas buenas entran.

