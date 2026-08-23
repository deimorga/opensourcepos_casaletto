# Alcance funcional — Cuadre de caja: origen del efectivo, conciliación del turno y reporte

> **Estado a 2026-08-23: en definición.** Sin implementar. Documento en construcción con el usuario.

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

## 5. Lo que apareció al repasar, y qué hacer con cada cosa

### 5.1 Hay que resolverlo antes de implementar

**a) Tres turnos cruzan medianoche, y dos se solapan.** El turno 31 abre el 13/08 y cierra el 14/08 a
las 16:21; el turno 32 abre el 14/08 a las 13:08 — **antes de que el 31 cerrara**. Ni el cruce por
día calendario ni por ventana horaria es limpio.
**Decisión necesaria:** ¿a qué turno pertenece una venta hecha en ese solape?

**b) Un día tiene dos turnos** (31/07, turnos 17 y 18). Con cruce por día no se pueden separar.

**c) El campo "Entrada/Salida de Efectivo" existe y está en cero en los 40 turnos.** Es exactamente
el campo para registrar plata que entra o sale del cajón fuera de las ventas — un retiro, una
consignación. **Probablemente ahí deberían haber ido los ~1,8 millones sin explicar.**
**Decisión necesaria:** ¿se empieza a usar, o se reemplaza por un registro de movimientos con
detalle? Un solo número por turno no dice quién sacó, cuándo ni para qué.

**d) La fecha del gasto la escribe el usuario.** Alguien puede registrar hoy un gasto con fecha de
ayer, y eso movería el cuadre de un turno ya cerrado.

### 5.2 Vale la pena contemplarlo, sin urgencia

**e) Un turno cerrado se puede reabrir y editar, y no queda rastro.** Si alguien corrige un importe
después, la conciliación cambia y nadie se entera. Una bitácora mínima —quién, cuándo, qué cambió—
haría auditable el cuadre.

**f) Los turnos no guardan sede.** Hoy hay una sola y no importa. Con dos, el modelo no distingue de
cuál cajón se habla. Relevante para el proyecto multi-negocio.

### 5.3 Revisado y descartado

**g) Las vueltas ya están contempladas.** `cash_refund` es el cambio que se le devuelve al cliente
(no devoluciones de venta): 106 pagos por $2.665.132. El efectivo que entra al cajón es
`pagado − vueltas`, y así está calculado en todo lo anterior.

**h) El campo "Cuotas" está en cero en los 40 turnos.** No se usa; no entra al alcance.

**i) No hay días con ventas sin turno.** Los 39 días operativos tienen su turno.

**j) Los gastos con tarjeta o cheque no existen** — solo efectivo (55) y transferencia (2). El campo
de origen no aplica a ninguno de los otros medios.

## 6. Regularización del histórico

**55 gastos en efectivo a marcar como "efectivo de caja"**, y las 2 transferencias no llevan origen.

**Por qué se puede afirmar sin revisarlos uno por uno**: los 55 los registró el cajero, que solo tiene
acceso al cajón. Y la aritmética lo respalda — el cuadre por día funciona precisamente porque esos
gastos salieron de ahí. El caso extremo lo confirma: el 13/08 se pagaron $4.113.000 en efectivo y el
cajón bajó $3.973.000.

**Aun así el usuario quiere revisarlos uno por uno**, y es razonable: la regularización toca datos de
dinero y el valor por defecto debe poder corregirse.

**Los dos turnos descuadrados (35 y 39) son un caso aparte.** No es un problema de clasificación: ahí
falta un registro que nunca se hizo. Hay que averiguar con quien estuvo presente qué pasó con esos
~1,8 millones antes de dar el histórico por regularizado.

## 7. Preguntas abiertas

1. **¿A qué turno pertenece una venta cuando dos turnos se solapan?** (punto 5.1.a)
2. **¿El campo Entrada/Salida se empieza a usar, o se reemplaza por un registro con detalle?**
   (punto 5.1.c)
3. **Cuando un administrador registra un gasto en efectivo, ¿cuál debería ser el origen por defecto?**
   Sugerencia: ninguno — obligarlo a elegir, porque para él la deducción no es posible.
4. **¿El cajero debe ver el campo en gris, o no verlo?** Verlo en gris enseña que existe; no verlo es
   más simple.

## 8. Referencia técnica

Pendiente. Se escribirá cuando se cierren las preguntas de la sección 7.
