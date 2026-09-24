# Formato de fecha: día/mes/año

> **Estado (2026-09-24):** decidido por el dueño (D27), probado en staging y **en producción desde
> la mañana del 2026-09-24** en los tres negocios. Verificado a las 12:53: ese mediodía Casaletto ya
> abrió un turno y registró un gasto con el formato nuevo, ambos con la fecha correcta.
>
> Documento hermano: `docs/Tecnico/formato-de-fecha.md`.

---

## 1. Qué cambió

Todo el sistema pasa a mostrar y a pedir las fechas como **día/mes/año**, como se leen en Colombia:
**30/09/2026** es el 30 de septiembre.

Antes estaba en **mes/día/año**, el formato de Estados Unidos que trae el programa de fábrica. Con
ese formato, «10/09/2026» significaba 9 de octubre, y aquí cualquiera lo leía como 10 de septiembre.
Con las cotizaciones esto era un riesgo con el cliente: la fecha «Válida hasta» se leía al revés.

## 2. A quién aplica

A los negocios que trabajan en español y seguían con el formato de fábrica: **Casaletto, Paraíso de la
Canasta y Diverso Soluciones**. Un negocio que ya hubiera elegido otro formato lo conserva.

Un negocio **nuevo** nace ya con día/mes/año y en español (desde el 2026-09-24: lo pone el alta).

## 3. Dónde se nota

- **Documentos impresos:** recibos, facturas y cotizaciones.
- **Pantallas:** el reloj de arriba, las listas de ventas, gastos, turnos y compras, y los reportes.
- **Calendarios:** los filtros de fechas («Hoy», «Mes actual», fechas escritas a mano) y los campos de
  fecha de los formularios.
- **Formularios donde se escribe una fecha:** apertura y cierre de turnos, gastos, cambiar la fecha de
  una venta, recepciones, mermas y clientes. Ahí la fecha se escribe **día/mes/año**.

## 4. Lo que NO cambia

- **Ninguna venta, turno, gasto ni reporte histórico cambia.** El sistema guarda las fechas en un
  formato interno propio; solo cambia cómo se muestran.
- Los reportes y totales dan exactamente lo mismo que antes.

## 5. Al escribir una fecha a mano (desde el 2026-09-24)

**Debajo de cada campo de fecha aparece la fecha en palabras**, para confirmar que es la que se quiso:

| Se escribe | Qué pasa |
|---|---|
| `30/09/2026` | Se lee tal cual: «= miércoles, 30 de septiembre de 2026» |
| `09/30/2026` (la costumbre vieja) | Solo tiene sentido al revés: **se reacomoda sola** a `30/09/2026` y lo dice: «(se reacomodó al orden día/mes/año)» |
| `05/09/2026` | Existe en los dos órdenes: se toma como **5 de septiembre** (día/mes). La frase en palabras deja ver cuál entendió el sistema; si era otra, se corrige antes de guardar |
| `31/31/2026`, `30/02/2026` | No existe: el campo se pone en rojo y el sistema **no guarda**: «La fecha … no es válida. Escríbala como en este ejemplo: 24/09/2026» |

Aplica en turnos (apertura, cierre, recaudo), gastos, recepciones, cambiar la fecha de una venta,
clientes y atributos de fecha. Antes una fecha imposible se guardaba en silencio como otra (el
30 de febrero pasaba a ser 2 de marzo; `09/30/2026`, 9 de junio de 2028), y en recepciones, ventas y
clientes daba un error de pantalla.

## 6. Cómo se cambia

Configuración → **Local** → **Formato de fecha**. Las opciones van desde dd/mm/aaaa hasta aaaa/mm/dd.
Cambiarlo afecta a todas las pantallas del negocio a la vez; conviene hacerlo con el local cerrado,
porque un formulario que alguien tenga abierto en ese momento puede guardar la fecha mal.
