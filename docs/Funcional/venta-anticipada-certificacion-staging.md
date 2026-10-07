# Preventas — informe de nuestra certificación en staging

> **Estado (2026-10-07, 18:30):** certificado por nosotros en staging, en dos negocios con configuración
> distinta, sobre la versión `4e17cb62d`. Encontró cinco cosas; las cinco se corrigieron, se volvieron a
> desplegar y se verificaron en staging. **Listo para la certificación del equipo del negocio.**
>
> Alcance y reglas: `docs/Funcional/venta-anticipada.md`. Detalle técnico: `docs/Tecnico/venta-anticipada.md`
> §7.10.

---

## 1. Dónde y con qué se probó

| | Negocio 1 | Negocio 2 |
|---|---|---|
| Negocio de staging | Panadería La Espiga | Casaletto (staging) |
| Mesas | Apagadas | Encendidas |
| Formato de números | Colombiano (coma decimal), 0 decimales | Punto decimal, 2 decimales |
| Papel del recibo | 58 mm | Predeterminado |
| Productos usados | Aceite, arroz (por unidad) y panela (por peso) | Tabla Toscana 8 personas (receta armada de 15 ingredientes) y pavo por peso |

Usuario de prueba creado para esto en los dos negocios: `cert_preventas`, con todos los permisos. Todo se
hizo desde las pantallas, como lo haría un cajero; los datos se comprobaron después en la base.

## 2. Lo que se comprobó

| # | Prueba | Resultado |
|---|---|---|
| 1 | Encender el módulo en Configuración, con el texto sugerido de condiciones | ✅ |
| 2 | Crear una campaña con descuento general, cuota inicial mínima, dos fechas de entrega y periodo de venta | ✅ |
| 3 | Precio de cada producto: descuento de la campaña, descuento propio (aceite 20 %) y precio propio (arroz $2.000) | ✅ $6.480 → $5.760, $2.000, panela $3.690/kg |
| 4 | Cuota inicial menor al mínimo de la campaña | ✅ Rechazada con mensaje claro |
| 5 | Registrar una preventa con cliente nuevo creado en el momento, con tildes («José Muñoz», «Ángela Núñez») | ✅ Se guarda y se encuentra buscando «José» |
| 6 | Comprobante de preventa con productos, plan, saldo y condiciones | ✅ |
| 7 | El abono en efectivo entra al cajón del turno abierto, en el renglón «Abonos de preventa» | ✅ Esperado subió exactamente lo abonado |
| 8 | Cerrar el turno, abrir otro y abonar por transferencia: el abono queda **en el segundo turno** | ✅ El primero no cambió |
| 9 | Cuota vencida: la preventa sale «Atrasada», con días de atraso, y el filtro la encuentra | ✅ Sin recargos |
| 10 | Con saldo, la preventa no se puede entregar | ✅ El botón no aparece y dice cuánto falta |
| 11 | Entregar desde la caja **sin mesas**: precios pactados, nada editable salvo el peso, sin suspender ni cancelar | ✅ |
| 12 | Entregar desde la caja **con mesas**: aparece como pestaña «PV-000001» y se cierra sola al completar | ✅ |
| 13 | Peso real **mayor** (panela 2,8 kg en vez de 2,5): la caja cobra la diferencia y no deja completar sin ella | ✅ $1.107 cobrados en efectivo |
| 14 | Peso real **menor** (pavo 2,7 kg en vez de 3): se devuelve como vuelto en efectivo | ✅ $23.400 de vuelto, bajo el tope del 15 % |
| 15 | Receta armada: se guarda con sus 15 ingredientes, el recibo muestra solo la tabla, y el inventario baja por ingrediente | ✅ 16 movimientos de inventario |
| 16 | **La plata no se cuenta dos veces** en el cierre del turno de la entrega | ✅ «Entregas de preventa (cobradas antes)» aparte; esperado exacto |
| 17 | El cierre de turno propone exactamente lo que entró (efectivo y datáfono) | ✅ Descuadre $0 |
| 18 | Campaña con el periodo de venta cerrado: no deja registrar preventas nuevas, pero sí abonar | ✅ |
| 19 | Cancelar con devolución parcial en efectivo: el cajón baja lo devuelto; comprobante con abonado, devuelto y retenido | ✅ |
| 20 | «Devolver a preventas» desde la caja, y cancelar con devolución total | ✅ |
| 21 | Lo comprometido por producto y fecha, con lo que hay en inventario y lo que falta comprar; la receta cuenta por ingredientes | ✅ |
| 22 | Los negocios que no usan el módulo no ven nada distinto | ✅ Apagado y sin permisos en los otros dos negocios |

## 3. Lo que encontramos y ya está corregido

1. **Una campaña nueva nacía inactiva** y no aparecía para vender. Ahora nace activa.
2. **El buscador de la campaña no encontraba las recetas armadas** (canastas, tablas, combos). Ahora sí.
3. **El cierre de turno proponía también los abonos de otro turno del mismo día.** Ahora propone solo
   los suyos.
4. **No había botones para llegar a «Campañas» ni a «Lo comprometido»** desde la lista. Ahora sí.
5. **En la entrega de una receta armada se podían editar los pesos de sus ingredientes.** Ahora solo
   el peso de lo que el cliente compró por peso.

De paso: un producto por peso se muestra siempre con sus decimales y su unidad (2,5 kg), y el aviso
«Configuración guardada» ya sale en español.

## 4. Límites conocidos (no son errores, están documentados)

- **El impuesto no queda congelado** entre el registro y la entrega: si el negocio cambia sus impuestos en
  ese tiempo, la caja cobra o devuelve la diferencia al entregar.
- **Sin mesas**, una entrega abierta vive solo en la caja de quien la abrió; si esa caja cierra sesión, se
  vuelve a abrir desde la preventa.
- La **devolución por peso que pase del tope** (15 % por defecto) la tiene que completar alguien con el
  permiso «Gestionar preventas». En esta certificación no se probó con un cajero sin ese permiso; lo
  cubren las pruebas automáticas.

## 5. Para la certificación del equipo del negocio

- Staging ya tiene el módulo **encendido** en Casaletto staging y en Panadería La Espiga, con las campañas y
  preventas de esta prueba (sirven de ejemplo). En los demás negocios sigue apagado.
- Recomendado repetir los puntos 2, 5, 7, 8, 12, 14, 16 y 19 con un usuario del negocio, y probar una vez
  con un **cajero sin el permiso «Gestionar preventas»** (no debe ver Campañas ni poder cancelar).
- El usuario `cert_preventas` se elimina cuando el equipo termine.
