# Alcance funcional — Reportes Analíticos: Ingresos vs Gastos

> **Estado a 2026-08-22: definido y aprobado, sin implementar.** Sin decisiones abiertas.
> Los defectos que bloqueaban su modo caja ya están corregidos y en producción — ver
> `docs/Tecnico/correccion-codificacion-tildes.md`.

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

### 3.1 Datos reales de producción (consultados 2026-08-22, solo lectura)

El sistema lleva operando poco: **las ventas empiezan el 2026-07-15 y los gastos el 2026-07-16**.
Ambos lados cubren prácticamente el mismo período, así que **el reporte tiene datos desde el primer
día** — no nace como una vitrina vacía.

| Mes | Ventas | Cobrado | Gastos | Monto gastos | Resultado | Margen |
|---|---|---|---|---|---|---|
| Julio (desde el 15) | 314 | 14.311.235 | 20 | 5.691.800 | 8.619.435 | 60,2% |
| Agosto (al 22) | 414 | 18.539.057 | 36 | 6.471.550 | 12.067.507 | 65,1% |

56 gastos activos y 2 eliminados. **Siete categorías en uso** de ocho definidas: Carnes Frías
(4.763.000), Pago de Nómina (1.980.000), Insumos Casaletto (1.950.000), Insumos Cocacola
(1.239.250), Compras Insumos Supermercado (1.235.700), Personal de Apoyo (755.400) y Compensación
por festivos (240.000).

**`tax_amount` es 0,00 en los 56 gastos.** La columna de impuesto no se usa, así que el reporte no
la muestra.

**Solo hay dos medios de pago en gastos**: Efectivo (54 gastos, 10.513.350) y Transferencia Bancaria
(2 gastos, 1.650.000). Esto vuelve trivial el backfill descrito en el documento técnico.

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
- **Granularidad** — Día / Semana / Mes, **derivada automáticamente del rango elegido** (ver 5.5).
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

### 5.5 La granularidad se deriva del rango elegido

El selector de fechas trae **14 presets**: Hoy, Hoy año pasado, Ayer, Últimos 7, Últimos 30, Este
mes, Mismo mes hasta el mismo día del año pasado, Mismo mes del año pasado, Mes pasado, Este año,
Año pasado, Este año fiscal, Año fiscal pasado y Todo el tiempo.

Pedirle al usuario que **además** elija la granularidad a mano es pedirle que resuelva dos veces la
misma pregunta: quien elige "Todo el tiempo" no quiere una fila por día, y quien elige "Ayer" no
quiere una fila por mes. **La granularidad se calcula del tamaño del rango:**

| Rango seleccionado | Granularidad |
|---|---|
| Hasta 14 días | Día |
| De 15 a 92 días | Semana |
| Más de 92 días | Mes |

Así "Hoy", "Ayer" y "Últimos 7" abren en días; "Últimos 30", "Este mes" y "Mes pasado" en semanas;
"Este año", los dos años fiscales y "Todo el tiempo" en meses.

**Se calcula del tamaño y no de la etiqueta del preset, a propósito.** Las etiquetas son cadenas
traducidas, y compararlas es exactamente el mecanismo que hoy tiene rotos los filtros de medio de
pago (sección 7). El número de días no depende del idioma.

El selector sigue visible y editable. **Si el usuario lo cambia a mano, su elección manda** y deja de
recalcularse al mover el rango.

**Por qué importa:** los gastos son irregulares — un arriendo pagado el día 5 hunde ese día en
pérdida aunque el mes cierre bien. Con las cinco semanas de historia que hay hoy, la vista semanal es
la única que muestra una tendencia legible: mensual da dos filas y diaria da ruido.

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

## 7. Los defectos que bloqueaban este reporte, ya corregidos

Al inventariar los filtros para diseñar este reporte aparecieron dos fallas vivas en producción en
los filtros de medio de pago: los de Tarjeta de Débito y Crédito devolvían **cero** sobre 13,2
millones de pesos, y el de Adeudo en Gastos devolvía vacío teniendo datos. El modo caja de este
reporte (5.4) cruza ingresos y gastos justamente por medio de pago, así que construirlo encima
habría heredado esas cifras incompletas.

**Se corrigieron primero, y están en producción desde el 2026-08-22.** Historia completa en
`docs/Tecnico/correccion-codificacion-tildes.md` y diagnóstico técnico en
`docs/Tecnico/errores-produccion-upstream.md` sección 5.

## 8. Preguntas abiertas

- Ninguna bloqueante. Los datos de producción quedaron verificados (3.1) y las decisiones de
  producto tomadas.
- Si con el tiempo el negocio quiere el resultado neto real (restando costo de mercancía), sería un
  segundo reporte dentro de la misma categoría analítica, no un cambio a este.
- Con solo cinco semanas de historia, las comparaciones contra el año anterior que ofrece el
  selector de fechas (cuatro de los catorce presets) no devolverán nada hasta julio de 2027. No es un
  defecto; conviene saberlo antes de que alguien lo reporte como tal.

## 9. Referencia técnica

`docs/Tecnico/reportes-analiticos-ingresos-gastos.md`
