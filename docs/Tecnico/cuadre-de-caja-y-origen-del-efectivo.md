# Diseño técnico — Cuadre de caja: origen del efectivo, recogidas y conciliación del turno

> **Estado a 2026-08-23: diseñado, sin implementar.**

Alcance funcional en `docs/Funcional/cuadre-de-caja-y-origen-del-efectivo.md`.

---

## 1. Principio rector

**El sistema tiene que poder decir cuánto efectivo debería haber en el cajón.** Hoy no puede, y por
eso el cierre acepta cualquier número sin chistar. Todo lo demás de este diseño existe para llegar a
esa cifra: de dónde salió cada gasto, qué se recogió, y a qué turno pertenece cada venta.

Un corolario que ordena las decisiones: **si un dato no se puede calcular, se muestra como
descuadre — nunca se asume.** Es la misma regla que se aplicó al backfill de medios de pago y al
turno 29.

## 2. Piezas y orden de implementación

Las cuatro se pueden construir por separado, pero **la conciliación depende de las tres primeras**.

| # | Pieza | Depende de |
|---|---|---|
| 1 | Origen del efectivo en gastos | — |
| 2 | Registro de recogidas de caja | — |
| 3 | Un solo turno abierto + venta sellada con el turno | — |
| 4 | Conciliación en el cierre y reporte de turnos | 1, 2 y 3 |

## 3. Origen del efectivo (gastos)

### 3.1 Modelo

Columna nueva en `ospos_expenses`:

```
cash_source  VARCHAR(20)  NULL   -- 'register' | 'collected' | NULL
```

**`NULL` cuando el medio de pago no es efectivo.** No es un valor faltante: es que la pregunta no
aplica. Una transferencia bancaria no sale de ningún bolsillo de efectivo.

Los códigos son estables e independientes del idioma, igual que `payment_type_code`. Las etiquetas se
resuelven al mostrar.

### 3.2 Por qué una columna y no dos tipos de pago

Decidido con el usuario. El medio de pago responde *cómo se pagó*; el origen, *de qué bolsillo salió*.
Mezclarlos en un solo campo rompería además la simetría del reporte Ingresos vs Gastos que ya está en
producción, cuyo modo caja compara ingresos y gastos **por el mismo medio de pago**: si los gastos
tuvieran dos tipos de efectivo y las ventas uno, ese filtro dejaría de tener sentido.

### 3.3 Sesgo por rol

**Administrador** = tiene el permiso `config`. Hoy separa limpio a 4 administradores de 2 cajeros.

- **Cajero**: el campo se muestra **deshabilitado**, fijo en `register`. Ve que la distinción existe y
  por qué su gasto siempre sale del cajón.
- **Administrador**: **sin valor por defecto**. Tiene que elegir. Un valor por defecto invita a
  dejarlo mal, y en su caso la deducción es imposible porque sí tiene acceso a los dos bolsillos.

**La regla se aplica en el servidor, no solo en la vista.** Un campo deshabilitado no se envía en el
formulario, y aunque se enviara, un POST fabricado puede traer cualquier cosa. `Expenses::postSave()`
decide el valor así:

```
si el medio de pago no es efectivo        -> null
si quien registra NO tiene 'config'       -> 'register'   (ignora lo que venga en el POST)
si tiene 'config'                          -> lo que eligió, y si no eligió, se rechaza el guardado
```

Rechazar es deliberado: es la lección del turno 29 — **un dato que no se pudo determinar tiene que
hacer ruido, no convertirse en el valor más inocente disponible.**

## 4. Registro de recogidas de caja

### 4.1 Modelo

Tabla nueva `ospos_cash_collections`:

```
collection_id   INT           PK, auto
amount          DECIMAL(15,2) NOT NULL
collected_at    TIMESTAMP     NOT NULL   -- cuándo salió el dinero, no cuándo se registró
collected_by    INT           NOT NULL   -- quién se llevó el dinero (administrador)
registered_by   INT           NOT NULL   -- quién anotó el movimiento
note            VARCHAR(255)  NOT NULL DEFAULT ''
deleted         TINYINT(1)    NOT NULL DEFAULT 0
```

**`collected_at` es la hora real del movimiento**, no la del turno ni la del registro. Es lo que
permite atribuir la recogida al turno correcto aunque se anote horas después.

**No lleva `cashup_id`.** La recogida pertenece al turno que estaba abierto en `collected_at`, y ese
turno se resuelve igual que para las ventas (sección 5). Guardar el turno además de la hora sería
guardar dos veces el mismo hecho, con el riesgo de que discrepen.

### 4.2 Reglas

- **`collected_by` tiene que ser un administrador.** Validado en el servidor contra el permiso
  `config`, no solo filtrando el desplegable.
- **Cualquiera con acceso a gastos puede registrar** una recogida — el cajero anota que el
  administrador se llevó el dinero. Por eso `registered_by` es distinto de `collected_by`.
- **Editable en cualquier momento**, no atada a la apertura ni al cierre.
- **Se muestra y se puede editar desde el cierre del turno**, para anotar lo que se olvidó durante la
  jornada.

### 4.3 Efecto contable

Una recogida **no es un gasto**: es un traslado. Nunca entra en el reporte de Ingresos vs Gastos ni en
ningún total de gastos. Su único efecto es sobre el efectivo esperado en el cajón:

```
efectivo esperado = apertura + ventas en efectivo − gastos con origen 'register' − recogidas
```

Y le da procedencia trazable al `collected` de la sección 3: cuando un administrador pague con
efectivo recolectado, esa plata salió del cajón tal día y la recogió tal persona.

## 5. Un solo turno abierto, y la venta sellada

### 5.1 La restricción

`Cashups::postSave()`, al crear un turno, **rechaza si ya existe otro con `status = 'open'`**.

Hoy los 40 turnos están cerrados, así que la restricción no bloquea nada al activarse.

### 5.2 La venta se sella al completarse

Columna nueva en `ospos_sales`:

```
cashup_id  INT  NULL
```

Se escribe en `Sale::save_value()` con el turno abierto en ese momento. **Queda fijo para siempre.**

**Por qué sellar en vez de cruzar por fecha.** Los turnos hoy se solapan — el 31 cierra el 14/08 a las
16:21 y el 32 abrió ese mismo día a las 13:08 — así que ningún cruce por ventana horaria es limpio
sobre el histórico. Y aunque la restricción de 5.1 lo evite hacia adelante, un cruce calculado
depende de fechas que alguien puede editar después; un sello no.

Coincide además con la regla del usuario: *"si una venta no se cerró en el turno que era, se aplica
para el turno que esté vivo cuando se cierre."*

**Si no hay ningún turno abierto**, la venta se guarda con `cashup_id = NULL` y aparece en el reporte
como venta sin turno. No se inventa una atribución.

## 6. Conciliación en el cierre

### 6.1 Lo que se agrega

```
Ingresos del turno    $932.915   ← bruto, por medio de pago
  Efectivo              603.115
  Datáfono              218.500
  Transferencia         111.300

Gastos de caja       −$344.800   ← solo cash_source = 'register'
Recogidas                  0     ← nuevo
Esperado en cajón     $258.315
Contado (declarado)   $257.850
Descuadre                −$465

Total (como hoy)      $587.650   ← sin cambios
```

**El Total no se toca.** Lleva 40 turnos calculándose así; cambiar la fórmula rompería la comparación
con el histórico. Se agregan las piezas que faltan, no se altera lo que ya existe.

**Todo lo nuevo es calculado y de solo lectura.** Nadie lo escribe.

### 6.2 De dónde sale cada cifra

| Cifra | Fuente |
|---|---|
| Ingresos por medio de pago | `sales_payments` de las ventas con ese `cashup_id`, neto de vueltas (`payment_amount − cash_refund`) |
| Gastos de caja | `expenses` con `cash_source = 'register'` cuya fecha cae en el turno |
| Recogidas | `cash_collections` cuyo `collected_at` cae en el turno |
| Esperado | apertura + efectivo − gastos de caja − recogidas |
| Contado | `closed_amount_cash`, lo que el cajero escribe |
| Descuadre | contado − esperado |

**Las vueltas ya están contempladas**: `cash_refund` es el cambio que se le devuelve al cliente
(`Sales.php:855`, `'cash_refund' => $data['amount_change']`), no una devolución de venta.

### 6.3 El campo "Entrada/Salida de Efectivo" se retira

`transfer_amount_cash` sale del formulario. Su reemplazo es el registro de recogidas.

**La columna no se elimina de la base**: los 40 turnos la tienen en cero y la fórmula del Total la
usa. Quitarla cambiaría el Total histórico, que es justo lo que se decidió no tocar. Se deja de
escribir y se deja de mostrar.

## 7. Regularización del histórico

**Migración, con reporte de lo que toca y con `down()`.** Mismo criterio que la reparación de
entidades: respaldo antes, conteos después, y nada se asume en silencio.

**a) 55 gastos en efectivo → `cash_source = 'register'`.** Los registró el cajero, que solo alcanza el
cajón, y la aritmética lo respalda: el cuadre por día funciona precisamente porque salieron de ahí.
Las 2 transferencias quedan en `NULL`.

**b) Las 2 recogidas confirmadas se cargan como los primeros registros**: $1.000.000 el 17/08 y
$800.000 el 21/08, ambas con Rodrigo Tovar como `collected_by`. Con eso los turnos 35 y 39 dejan de
aparecer como anomalías y quedan con residuos de $2.900 y $370 — dentro del ruido normal.

**c) Las 792 ventas existentes reciben `cashup_id` por ventana horaria donde sea inequívoco.** Donde
los turnos se solapan (13 al 15 de agosto) **se dejan en `NULL` y se reportan**. La migración imprime
cuántas quedaron sin turno.

**d) No se toca ningún importe.** La regularización clasifica y ata; no corrige plata.

**Después de desplegar**, revisar contra el reporte qué quedó sin explicar y cargar las recogidas que
falten. El turno 18 del 31/07 (−$708.775) es el primer candidato, **pero su cifra no es confiable**
hasta que las ventas de ese día —el único con dos turnos— queden separadas por el sello de 5.2.

## 8. Archivos a tocar

**Origen del efectivo**
1. Migración: columna `cash_source` + backfill de los 55.
2. `app/Models/Expense.php` — `$allowedFields` (ojo: CI4 descarta en silencio lo que no esté ahí).
3. `app/Controllers/Expenses.php` — decisión por rol en el servidor, y filtro nuevo en la grilla.
4. `app/Views/expenses/form.php` — campo condicionado al medio de pago y al rol.
5. `app/Helpers/tabular_helper.php` — columna en la grilla de Gastos.

**Recogidas**
6. Migración: tabla `cash_collections` + carga de las 2 confirmadas.
7. `app/Models/Cash_collection.php` — nuevo.
8. `app/Controllers/Cash_collections.php` — nuevo, con su permiso y su entrada de menú.
9. `app/Views/cash_collections/` — grilla y formulario.

**Turno y venta**
10. Migración: columna `cashup_id` en `sales` + backfill por ventana.
11. `app/Controllers/Cashups.php` — rechazo de segundo turno abierto.
12. `app/Models/Sale.php` — sellar `cashup_id` al guardar.
13. `app/Views/cashups/form.php` — retirar `transfer_amount_cash`, agregar el bloque de conciliación.
14. `app/Controllers/Cashups.php` — cálculo de la conciliación.

**Idiomas:** `en`, `es-ES` y **`es-MX`**, que es el que corre esta instalación.

**Commits separados por pieza.** Son cuatro temas distintos aunque se trabajen seguidos.

## 9. Pruebas

- Un cajero guarda un gasto en efectivo → queda `register` aunque el POST diga otra cosa.
- Un administrador guarda sin elegir origen → **se rechaza**.
- Un gasto por transferencia → `cash_source` queda `NULL`, no `register`.
- Abrir un turno con otro abierto → se rechaza.
- Una venta completada se sella con el turno abierto; sin turno abierto queda en `NULL`.
- Una recogida con `collected_by` no administrador → se rechaza.
- El esperado en cajón cambia al agregar una recogida, y el descuadre se mueve en consecuencia.
- Una recogida registrada con hora de ayer cae en el turno de ayer, no en el de hoy.
- El Total del turno **no cambia** con ninguna de las piezas nuevas.
- Backfill: los 55 quedan `register`, las 2 transferencias `NULL`, y el conteo se reporta.
- Los turnos 35 y 39 pasan de −$1.002.900 y −$800.370 a −$2.900 y −$370.

## 10. Despliegue

Varias migraciones, así que **no termina con el workflow**: hay que lanzar `php spark migrate` por
SSH. Build de assets antes de cualquier `up --build` manual. **Producción solo fuera del horario de
operación**, con respaldo previo de `ospos_expenses`, `ospos_sales` y `ospos_cash_up`.

**Verificación con datos reales:** el reporte de turnos no puede contradecir al de Transacciones para
el mismo rango, y los turnos que hoy cuadran no pueden dejar de cuadrar.
