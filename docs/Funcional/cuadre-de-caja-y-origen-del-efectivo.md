# Alcance funcional — Cuadre de caja: origen del efectivo, conciliación del turno y reporte

> **Estado a 2026-08-23: alcance cerrado.** Sin implementar. Diseño técnico en
> `docs/Tecnico/cuadre-de-caja-y-origen-del-efectivo.md`.

---

## 1. De dónde sale esto

El usuario pidió un reporte de turnos. Al analizarlo apareció que **el problema no es de reporte sino
de modelo de datos**: el sistema registra que un gasto se pagó "en efectivo", pero no de **cuál**
efectivo. Y sin esa distinción ningún cuadre de caja puede ser correcto.

Frase del usuario que define el requerimiento:

> *"Hay pago efectivo que se hace del dinero que se deja en caja, y hay pago efectivo del dinero que
> nosotros estamos recolectando. Si el pago se hace directamente de lo que nosotros recolectamos,
> pues no salga en el cierre del turno."*

## 2. Lo que se midió en producción (2026-08-23)

**El cuadre por día ya funciona en el 80% de los casos** si se restan los gastos en efectivo:

| Turno | Día | Entró efectivo | Gastos efectivo | Esperado | Declarado | Descuadre |
|---|---|---|---|---|---|---|
| 36 | 18/08 | 458.635 | 13.200 | 445.435 | 445.435 | **0** |
| 32 | 14/08 | 396.577 | 84.700 | 311.877 | 311.877 | **0** |
| 40 | 22/08 | 603.115 | 344.800 | 258.315 | 257.850 | −465 |
| 37 | 19/08 | 353.818 | 50.000 | 303.818 | 304.800 | +982 |

Dos turnos cuadran exactos y el resto por cientos de pesos — ruido normal de vueltas.

**Pero dos turnos tienen ~1,8 millones sin explicar:**

| Turno | Día | Esperado | Declarado | Descuadre |
|---|---|---|---|---|
| 39 | 21/08 | 376.470 | −423.900 | **−800.370** |
| 35 | 17/08 | −176.300 | −1.179.200 | **−1.002.900** |

Los gastos registrados esos días ($122.250 y $270.000) **no explican la diferencia**. Salió efectivo
del cajón sin que quedara registrado en ningún lado.

## 3. El hallazgo mayor: el Total del cierre no es lo que parece

Turno 40, del 22/08. Lo que el cierre muestra: **Total $587.650**.

Lo que realmente pasó:

| Concepto | Monto |
|---|---|
| Entró en efectivo | 603.115 |
| Entró por datáfono | 218.500 |
| Entró por transferencia | 111.300 |
| **Ingresos reales** | **932.915** |
| Salió en efectivo | 344.800 |

**$932.915 − $587.650 = $345.265**, que son los gastos más la diferencia de conteo.

La causa: el datáfono y el banco se declaran **brutos**, pero el efectivo entra como **movimiento
neto del cajón** (cierre − apertura), que ya viene descontado de los gastos pagados. **El Total suma
dos magnitudes de naturaleza distinta.** Quien lee "$587.650" entiende que eso vendió el turno, y el
turno vendió $932.915.

**Esto no es un error de cálculo** — la fórmula es consistente consigo misma. Es que el número no es
interpretable sin las piezas que faltan.

## 4. Alcance: tres piezas

### 4.1 Origen del efectivo (campo nuevo en el gasto)

Un campo **aparte** del medio de pago, que **solo aparece cuando el pago es en efectivo**:

- **Efectivo de caja** — salió del cajón del punto de venta. Afecta el cuadre del turno.
- **Efectivo recolectado** — salió de dinero ya retirado del cajón o de otra fuente. **No afecta el
  cuadre.**

**Por qué un campo aparte y no dos tipos de pago** (decidido con el usuario, 2026-08-23): el medio de
pago responde *cómo se pagó*, el origen responde *de qué bolsillo salió*. Son dos preguntas distintas
y mezclarlas en un solo campo se paga después. Además, los tipos de pago de gastos y de ventas se
comparan entre sí en el reporte de Ingresos vs Gastos que ya está en producción; partir "Efectivo" en
dos romperia esa simetría.

### 4.2 Sesgo por rol

**Un cajero solo puede pagar con efectivo de caja.** No tiene acceso a las cuentas bancarias ni a las
tarjetas del negocio. Para él el campo no se pregunta: se fija en "efectivo de caja".

**Un administrador elige**, porque sí puede pagar de cualquier origen.

**Un cajero que edita un gasto ajeno no le cambia el origen** *(añadido el 2026-08-23)*. Si un
administrador marcó un gasto como pagado con efectivo recolectado y luego un cajero le corrige la
descripción, el gasto **conserva** el origen que el administrador le puso. Lo contrario dejaría que
una corrección de texto moviera dinero de un bolsillo al otro, y el cuadre de ese día saldría corto
sin nada que lo explique. Se fija en "efectivo de caja" únicamente cuando no hay una decisión previa
que conservar.

**Validado con los datos**: de los 59 gastos registrados, el cajero Juan David Nieto registró **los 55
en efectivo, sin excepción**, y el administrador Deiby Moreno las 2 transferencias. La separación ya
existe en la práctica.

**Cómo se distingue un rol de otro**: quien tiene el permiso `config` es administrador. Hoy separa
limpio a 4 administradores (Rodrigo Tovar, Luis Eduardo Suarez, Deiby Moreno, Rocio Beltran) de 2
cajeros (Juan David Nieto, Karen Rincón).

### 4.3 Conciliación en el cierre del turno

Hoy la pantalla de cierre es una **declaración**: el cajero escribe lo que contó y nadie le dice si
cuadra. La propuesta la convierte en **conciliación**, agregando lo que falta **sin cambiar el Total
actual**:

```
Ingresos del turno    $932.915   ← nuevo, bruto por medio de pago
  Efectivo              603.115
  Datáfono              218.500
  Transferencia         111.300

Gastos de caja       −$344.800   ← nuevo, solo los de origen "caja"
Esperado en cajón     $258.315   ← nuevo
Contado (declarado)   $257.850
Descuadre                −$465   ← nuevo, lo que de verdad importa

Total (como hoy)      $587.650   ← se queda igual
```

**El Total no se toca a propósito.** Lleva 40 turnos calculándose así; cambiar la fórmula rompería la
comparación con el histórico y confundiría a quien ya lo interpreta a su manera.

**El valor real es el momento**: el sistema le dice al cajero "esperaba 258.315, contaste 257.850,
faltan 465" **mientras cierra**, no tres semanas después. Es el control que habría hecho visible el
descuadre del turno 29 el mismo día.

## 5. Decisiones tomadas al repasar los bordes (2026-08-23)

### 5.1 Un solo turno abierto a la vez — y con eso, la venta sabe a qué turno pertenece

**Decisión del usuario:** *"No se debe permitir abrir un turno sin haber cerrado el anterior."* Y:
*"Si una venta no se cerró en el turno que era, se aplica para el turno que esté vivo cuando se
cierre."*

Esto resuelve de raíz el problema que parecía más difícil. Hoy los turnos **se solapan** — el 31
cierra el 14/08 a las 16:21 y el 32 abrió el 14/08 a las 13:08, antes de que el otro cerrara — y por
eso ni el cruce por día ni por ventana horaria era limpio.

Con la restricción, la atribución es inequívoca. Y la forma correcta de implementarla **no es cruzar
por fecha sino sellar la venta**: al completarse, la venta guarda el turno que estaba abierto en ese
momento. Queda fijo para siempre, no depende de fechas que alguien pueda editar después, y no se
recalcula nunca.

**Que haya dos turnos en un mismo día deja de ser un problema** — lo que importa es que no haya dos
abiertos a la vez.

**Consecuencia para el histórico:** las 792 ventas existentes no tienen turno. Se pueden asignar
hacia atrás por la ventana horaria donde sea inequívoco, pero **en el solape del 13 al 15 de agosto
no lo es**. Ahí se dejan sin turno y se reportan, en vez de adivinar.

### 5.2 Registro de recogidas de caja (reemplaza al campo "Entrada/Salida de Efectivo")

**El campo actual no sirve**, verificado en el código (`app/Views/cashups/form.php:14`):

```php
$open_field_attrs = $is_new ? [] : ['disabled' => 'disabled'];
```

Solo es editable **al crear el turno**, cuando todavía no ha pasado nada. Un retiro a media tarde no
tiene dónde registrarse. Por eso está en cero en los 40 turnos.

**En su lugar va un registro de recogidas**, definido con el usuario el 2026-08-23. Su frase encuadra
el modelo:

> *"Ese debería ser un momento donde el efectivo de caja se convierte, y lo que se saca se convierte
> en efectivo recogido."*

**Una recogida no es un gasto: es un traslado entre bolsillos.** El dinero no se gastó, cambió de
sitio. Por eso no encajaba ni como gasto ni como un campo del turno.

Cada recogida registra:

- **Monto** recogido
- **Fecha y hora del movimiento** — la real, no la del turno
- **Quién recogió el dinero** — obligatoriamente un administrador
- **Quién registró** el movimiento
- Nota opcional

**No está atada a la apertura ni al cierre.** Se registra en cualquier momento del día. En el cierre
**se muestra y se puede editar**, para poder anotar lo que se olvidó durante la jornada.

**Esto cierra el círculo con el origen del efectivo (4.1).** Lo recogido sale del efectivo de caja y
entra al efectivo recolectado, así que cuando un administrador pague un gasto con "efectivo
recolectado" esa plata tiene una procedencia trazable: salió del cajón tal día, la recogió tal
persona. Sin las recogidas, "efectivo recolectado" sería una categoría sin origen.

### 5.3 La fecha del gasto: solo los administradores la editan

**Decisión del usuario:** el cajero registra el gasto con la fecha del día en que lo hace, sin poder
cambiarla. El administrador sí puede ajustarla, solo cuando haga falta corregir un olvido del cajero.

Es la misma separación de rol que la del origen del efectivo (4.2), así que se resuelve con el mismo
mecanismo y sin costo adicional.

**Decidido también (2026-08-23):** el cajero **ve el campo de origen del efectivo en gris**, fijo en
"Efectivo de caja" y no modificable. Verlo enseña que la distinción existe y por qué su gasto siempre
sale del cajón. Y para el administrador **no hay valor por defecto**: tiene que elegir de dónde está
pagando, porque en su caso la deducción no es posible.

**Por qué importa:** un gasto con fecha de ayer mueve el cuadre de un turno ya cerrado. Que solo un
administrador pueda hacerlo convierte un accidente en una decisión.

### 5.4 Sigue abierto, sin urgencia

**Un turno cerrado se puede reabrir y editar sin dejar rastro.** Si alguien corrige un importe después,
la conciliación cambia y nadie se entera. Una bitácora mínima —quién, cuándo, qué cambió— haría
auditable el cuadre. Cobra más peso ahora que el cierre pasa a ser una conciliación y no solo una
declaración.

**Los turnos no guardan sede.** Hoy hay una sola. Con dos, el modelo no distingue de cuál cajón se
habla. Relevante para el proyecto multi-negocio.

### 5.5 Revisado y descartado

**Las vueltas ya están contempladas.** `cash_refund` es el cambio que se le devuelve al cliente, no
devoluciones de venta: 106 pagos por $2.665.132. El efectivo que entra al cajón es `pagado − vueltas`,
y así está calculado en todo este documento.

**El campo "Cuotas" está en cero en los 40 turnos.** No se usa; fuera de alcance.

**No hay días con ventas sin turno.** Los 39 días operativos tienen el suyo.

**No existen gastos con tarjeta ni cheque** — solo efectivo (55) y transferencia (2). El origen del
efectivo no aplica a ningún otro medio.

## 6. Lo que el cuadre destapó en el histórico

Aplicando la conciliación a los 40 turnos aparecen **11 con diferencia mayor a $10.000**, que suman
**−$2.460.845**. Tres concentran casi todo:

| Turno | Día | Sin explicar | Explicación |
|---|---|---|---|
| 35 | 17/08 | −$1.002.900 | **Confirmado**: Rodrigo recogió $1.000.000. Queda $2.900 de residuo. |
| 39 | 21/08 | −$800.370 | **Confirmado**: Rodrigo recogió $800.000. Queda $370 de residuo. |
| 18 | 31/07 | −$708.775 | Sin confirmar. Ver la advertencia abajo. |

Los residuos de $2.900 y $370 están en el mismo rango que los turnos que cuadran bien (−465, +240,
+982, −210): **son ruido normal de conteo**. La confirmación del usuario, sacada del chat del equipo,
cierra el cuadre de esos dos días.

**Advertencia sobre el turno 18:** el 31/07 es el único día con dos turnos, y el cruce por día le
atribuye a cada uno las ventas del día completo. Esa cifra no es confiable hasta que las ventas se
puedan separar por turno (5.1). No debe tratarse como una recogida confirmada.

Los ocho restantes están entre −$70.400 y +$35.675 — pueden ser diferencias de conteo o recogidas
pequeñas. Hay que revisarlos, sin urgencia.

### 6.1 Cómo se ha venido conciliando este efectivo: no se ha conciliado

Pregunta del usuario, y la respuesta importa para dimensionar el problema:

> *"Hemos estado sacando efectivo desde que pusimos el sistema en operación. ¿Cómo se ha venido
> conciliando? ¿En qué campo lo estamos identificando?"*

**En ninguno.** El cajero cuenta lo que hay físicamente en el cajón y lo escribe. Si esa mañana se
recogió un millón, cuenta un millón menos y lo anota. El sistema acepta el número **sin poder
compararlo con nada**, porque hasta hoy no existe la cifra esperada.

**Y hay una consecuencia peor.** El Total del turno se calcula como
`efectivo cerrado − efectivo abierto − traslados + adeudo + datáfono + banco`. Una recogida baja el
efectivo cerrado, así que **baja también el Total del turno**.

El turno 35 es el ejemplo extremo: su Total quedó en **−$951.800**. Un día que vendió $321.100 quedó
registrado con total negativo.

**Las recogidas no solo no se registran: vienen distorsionando hacia abajo la única cifra que sí se
mira.** Ese es el argumento más fuerte a favor de construir esto.

## 7. Regularización del histórico

**55 gastos en efectivo a marcar como "efectivo de caja"**, y las 2 transferencias no llevan origen.

**Por qué se puede afirmar sin revisarlos uno por uno**: los 55 los registró el cajero, que solo tiene
acceso al cajón. Y la aritmética lo respalda — el cuadre por día funciona precisamente porque esos
gastos salieron de ahí. El caso extremo lo confirma: el 13/08 se pagaron $4.113.000 en efectivo y el
cajón bajó $3.973.000.

**Aun así el usuario quiere revisarlos uno por uno**, y es razonable: la regularización toca datos de
dinero y el valor por defecto debe poder corregirse.

**Las recogidas confirmadas se cargan como los dos primeros registros** del nuevo módulo, con fecha
retroactiva y Rodrigo como quien recogió: $1.000.000 el 17/08 y $800.000 el 21/08. Con eso el cuadre
de esos días se calcula solo y dejan de aparecer como anomalías.

**Sobre anotar el comentario en los turnos ahora:** se puede, pero hay que reabrirlos —el campo
Descripción está deshabilitado en un turno cerrado— y **los importes seguirán sin poder corregirse**,
porque "Entrada/Salida de Efectivo" sigue deshabilitado incluso reabierto. El comentario sirve como
respaldo mientras la información está fresca, pero **es texto libre: ningún reporte podrá calcular a
partir de él.** El valor real está en cargarlo como movimiento.

## 7. Preguntas abiertas

1. **Cuando un administrador registra un gasto en efectivo, ¿cuál es el origen por defecto?**
   Sugerencia: ninguno — obligarlo a elegir. Para el cajero se puede deducir; para el administrador
   no, y un valor por defecto invita a dejarlo mal.
2. **¿El cajero ve el campo de origen en gris, o no lo ve?** Verlo enseña que existe; no verlo es más
   simple.
3. **El campo "Entrada/Salida de Efectivo": ¿se retira del formulario o se habilita hasta el cierre?**
   (ver 5.2)
4. **¿Qué se hace con los ~1,8 millones de los turnos 35 y 39?** No es un problema de software: hay
   que averiguar con quien estuvo presente qué pasó, antes de dar el histórico por regularizado.

## 8. Referencia técnica

`docs/Tecnico/cuadre-de-caja-y-origen-del-efectivo.md`
