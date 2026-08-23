# Alcance funcional — Reportes Analíticos: Ingresos vs Gastos

Requerimiento planteado el 2026-08-22. Documento de alcance funcional; el diseño técnico vive en
`docs/Tecnico/reportes-analiticos-ingresos-gastos.md`.

---

## 1. Contexto y motivación

Hoy el negocio puede ver **cuánto vendió** y puede ver **cuánto gastó**, pero nunca las dos cosas
en la misma pantalla. Para saber si un mes cerró en ganancia o en pérdida hay que abrir dos
reportes distintos, exportarlos y restar a mano.

La pregunta que el negocio necesita responder es sencilla y hoy no tiene respuesta directa:

> ¿Cuánto entró y cuánto salió en este período, y cuál fue el resultado?

## 2. Objetivo funcional

Una vista que compare, sobre el mismo período y con los mismos filtros, **los ingresos por ventas
contra los gastos operativos**, mostrando el resultado y el margen.

## 3. Estado actual del sistema (punto de partida)

Verificado el 2026-08-22 revisando los 21 modelos de `app/Models/Reports/`, los 47 métodos de
`app/Controllers/Reports.php`, las rutas y la wiki funcional vendorizada.

**No existe ningún reporte que cruce ingresos con gastos.** Lo que hay son dos mundos separados:

| Reporte existente | Qué entrega | Qué le falta |
|---|---|---|
| Reporte Resumido de Transacciones (`summary_sales`) | Por día: ventas, cantidad, subtotal, impuesto, total, costo y "Ganancias" | Su columna *Ganancias* es **margen bruto** (`subtotal − costo de mercancía`). No descuenta un solo peso de gasto operativo. |
| Reporte Resumido de Gastos por Categoría (`summary_expenses_categories`) | Por categoría: conteo, monto, impuesto | Pantalla aparte, sin ninguna referencia a ventas. Ignora el filtro de ubicación y recibe `sale_type` en la firma pero nunca lo usa. |
| Reporte de Pagos (`summary_payments`) | Dinero cobrado por medio de pago, con saldo pendiente y devoluciones | Solo el lado del ingreso. |

## 4. Decisiones de producto (tomadas con el usuario el 2026-08-22)

### 4.1 Qué cuenta como "ingreso"

**El total facturado de las ventas completadas** — el mismo `total` que ya muestra el Reporte
Resumido de Transacciones.

Se descartaron dos alternativas que estaban sobre la mesa:

- *Dinero efectivamente cobrado* (descontando saldo pendiente y devoluciones). Más fiel a "cuánta
  plata entró de verdad", pero incoherente con el resto de los reportes que el negocio ya lee.
- *Subtotal sin impuestos*.

**Consecuencia que hay que tener presente:** una venta a crédito todavía no cobrada **sí** cuenta
como ingreso del período en que se facturó.

### 4.2 El costo de la mercancía NO entra

El comparativo es literal: **Ingresos − Gastos operativos = Resultado**.

Se descartó el estado de resultados completo (restar además el costo de mercancía para llegar a un
resultado neto real) por dos razones: es más difícil de leer, y su exactitud depende de que los
costos de los 235 artículos importados desde Siigo estén bien cargados — algo que no se ha
verificado. El costo de mercancía sigue disponible en el Reporte Resumido de Transacciones.

### 4.3 Categoría nueva: "Reportes Analíticos"

El reporte **no** se mete en ninguna de las tres columnas existentes (Gráficos / Resumidos /
Detallados). Se crea un **cuarto panel, "Reportes Analíticos"**, en la pantalla de Reportes.

**Por qué:** este reporte se maneja con un control de filtros distinto al de los otros 20 (ver 5.1).
Meterlo entre ellos produciría una inconsistencia visible — el usuario haría clic esperando el
formulario de siempre y encontraría otra cosa. Un panel propio comunica de entrada que estos
reportes funcionan diferente, y deja lugar para los siguientes reportes analíticos que se pidan.

### 4.4 Gastos eliminados

**No se muestran por defecto**, pero están disponibles como filtro — igual que en la grilla de
Gastos, que ya tiene la casilla "Eliminados".

## 5. Filtros

### 5.1 Se adopta el control de la grilla, no el formulario de reportes

Hoy conviven **tres mecanismos de filtrado distintos** en la aplicación:

| | Grilla de Ventas | Grilla de Gastos | Reportes |
|---|:--:|:--:|:--:|
| Rango de fechas (14 presets) | ✅ | ✅ | ✅ |
| Efectivo · Adeudo · Cheque · Crédito · Débito | ✅ | ✅ | ❌ |
| Transferencia · Billetera · Facturas · Cliente | ✅ | ❌ | ❌ |
| Eliminados | ❌ | ✅ | ❌ |
| Tipo de venta · Ubicación | ❌ | ❌ | ✅ |
| Se aplica sin recargar la página | ✅ | ✅ | ❌ |
| Los filtros quedan en la URL (compartible) | ✅ | ✅ | ❌ |

Las grillas usan una **barra sobre la tabla** (daterangepicker + multiselect) que refresca la tabla
por AJAX y persiste el estado en la URL. Los reportes usan un **formulario previo** que arma una URL
posicional y recarga la página entera.

**Decisión: el reporte analítico usa el control de la grilla.** Es el que el negocio maneja todos
los días, permite cambiar filtros sin volver a empezar, y deja la vista filtrada compartible por URL.

### 5.2 Filtros del reporte

- **Rango de fechas** — el mismo selector, los mismos 14 presets (Hoy, Ayer, Últimos 7, Últimos 30,
  Este mes, Mes pasado, Este año, Año pasado, Año fiscal actual/anterior, Todo el tiempo y tres
  comparativos contra el año anterior). Se aplica a ambos lados por igual.
- **Medio de pago** — multiselect combinable, aplicado a ingresos y gastos. **Activarlo cambia lo
  que el reporte mide, y el reporte lo dice en pantalla** (ver 5.4).
- **Granularidad** — Día / Semana / Mes.
- **Incluir gastos eliminados** — apagado por defecto.

### 5.3 Qué queda deliberadamente fuera, y por qué

- **Ubicación.** La tabla `ospos_expenses` **no tiene columna de ubicación**. Ofrecer el filtro
  filtraría los ingresos pero no los gastos, produciendo un comparativo que miente sin avisar —
  exactamente el patrón que costó los 217.000 del turno 29.
- **Tipo de venta.** Ya está definido que el ingreso son las ventas completadas. Exponer el
  dropdown permitiría elegir "Canceladas" y obtener un comparativo sin sentido.

### 5.4 El filtro de medio de pago cambia el modo del reporte

Decidido el 2026-08-22, después de encontrar que el filtro choca con la definición de ingreso.

**Sin filtro de medio de pago**, el reporte compara **facturación contra gastos**: cuánto se vendió
y cuánto se gastó, se haya cobrado o no.

**Con un medio de pago seleccionado**, el reporte pasa a comparar **dinero cobrado contra dinero
pagado por ese mismo canal**. Los ingresos dejan de ser la facturación y pasan a ser los pagos
efectivamente recibidos.

**Por qué no puede ser de otra manera:** una venta a crédito que todavía no se cobra no tiene ningún
pago asociado, así que no pertenece a ningún medio de pago. Y una venta pagada mitad en efectivo y
mitad con tarjeta contaría completa en ambos filtros si se usara el total facturado — inflando la
cifra. El único número que se puede filtrar honestamente por medio de pago es el pago mismo.

**El cambio se anuncia en pantalla**, en el subtítulo del reporte y en el encabezado de la columna.
Un reporte que cambia lo que mide sin decirlo es justo lo que produjo el descuadre del turno 29.

Como efecto secundario, el modo de caja responde algo que hoy ningún reporte responde: *"¿cuánto
entró y cuánto salió en efectivo este mes?"*.

### 5.5 Por qué la granularidad importa

Los gastos son irregulares: un arriendo pagado el día 5 hunde ese día en pérdida aunque el mes
cierre bien. El comparativo diario es ruidoso; el mensual es el que dice la verdad. Por eso la
granularidad es un control visible y no una decisión fija.

## 6. Salida esperada

### 6.1 Tabla

Una fila por período, con fila de totales al pie:

| Período | Ingresos | Gastos | Resultado | Margen % |
|---|---|---|---|---|
| Agosto 2026 | 24.800.000 | 9.150.000 | 15.650.000 | 63,1% |
| Julio 2026 | 19.400.000 | 21.100.000 | **−1.700.000** | −8,8% |

Con exportación a Excel / PDF / CSV, búsqueda y ordenamiento, igual que las demás tablas.

### 6.2 Gráfico

Dos series sobre el mismo eje temporal — ingresos y gastos — para ver de un vistazo dónde se cruzan.

## 7. Defecto preexistente que este trabajo corrige

Al inventariar los filtros se encontró un **bug real, hoy en producción**, en el filtro de medio de
pago de la grilla de Gastos.

El formulario de gastos guarda el medio de pago como **texto traducido** tomado de las claves
`Sales.*`, pero los filtros de la grilla comparan contra las claves `Expenses.*`. Son dos archivos
de idioma distintos, y no dicen lo mismo:

| Idioma | Se guarda (`Sales.due`) | Se filtra (`Expenses.due`) | ¿Coincide? |
|---|---|---|---|
| en | "Due" | "Due" | ✅ |
| es-ES | "Adeudado" | "Hasta" | ❌ |
| **es-MX** (el que corre esta instalación) | **"Adeudo"** | **"A Crédito"** | ❌ |

**Efecto para el usuario:** en la grilla de Gastos, el filtro **"A Crédito" no devuelve nada nunca**
— aunque existan gastos registrados con ese medio de pago. Sin mensaje de error: una lista vacía que
parece un dato ("no hubo gastos a crédito") cuando en realidad es una falla.

Hay dos problemas más en el mismo sitio:

1. **El formulario ofrece 7 medios de pago; la grilla solo filtra por 5.** Los gastos pagados por
   **Transferencia Bancaria** o **Monedero** no son alcanzables por ningún filtro. En Colombia la
   transferencia bancaria es un medio de pago corriente, así que esto no es hipotético.
2. **Los otros cuatro filtros funcionan de casualidad.** Coinciden solo porque la tabla usa la
   colación `utf8_general_ci`, que ignora mayúsculas y tildes: se guarda "Tarjeta de débito" y se
   busca "Tarjeta de Débito". El día que alguien migre a una colación sensible, se rompen los cinco.

**Se corrige de fondo** (decisión del usuario, 2026-08-22): el medio de pago deja de guardarse como
texto traducido y pasa a guardarse como un código estable, con la etiqueta traducida resuelta al
mostrar. Detalle en el documento técnico.

**Por qué no se puede dejar para después:** el reporte nuevo va a filtrar gastos por medio de pago.
Construirlo encima de este mecanismo sería heredar un comparativo que devuelve cifras incompletas
sin avisar.

## 8. Preguntas abiertas

- **No se ha verificado con qué regularidad y bajo qué categorías se registran los gastos en
  producción.** El usuario confirmó que se registran, pero no se pudo consultar la base (la consulta
  de solo lectura fue bloqueada por permisos de la herramienta). Antes de dar el reporte por bueno
  hay que contrastarlo contra el histórico real.
- Si con el tiempo el negocio quiere el resultado neto real (restando costo de mercancía), sería un
  segundo reporte dentro de la misma categoría analítica, no un cambio a este.

## 9. Referencia técnica

`docs/Tecnico/reportes-analiticos-ingresos-gastos.md`
