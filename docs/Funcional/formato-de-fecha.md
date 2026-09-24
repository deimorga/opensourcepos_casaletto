# Formato de fecha: día/mes/año

> **Estado (2026-09-24):** decidido por el dueño (D27). Probado en staging; producción en el mismo
> despliegue que lo documenta.
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

Un negocio **nuevo** nace en inglés y con el formato de Estados Unidos: al darlo de alta hay que
ponerle el idioma español y el formato día/mes/año en Configuración → Local (§5).

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

## 5. Cuidado al escribir una fecha a mano

El sistema **no avisa** si una fecha se escribe en el orden viejo (mes/día). Escribir «09/30/2026»
(la costumbre anterior) no da error: se guarda una fecha absurda, **9 de junio de 2028**, porque no
existe el mes 30. Esto ya pasaba antes al revés.

Recomendación al equipo: usar el calendario en lugar de escribir la fecha, y fijarse en que el
primer número sea el **día**. Quedó propuesto que el sistema rechace una fecha imposible.

## 6. Cómo se cambia

Configuración → **Local** → **Formato de fecha**. Las opciones van desde dd/mm/aaaa hasta aaaa/mm/dd.
Cambiarlo afecta a todas las pantallas del negocio a la vez; conviene hacerlo con el local cerrado,
porque un formulario que alguien tenga abierto en ese momento puede guardar la fecha mal.
