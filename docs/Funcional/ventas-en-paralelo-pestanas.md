# Alcance funcional — Ventas en paralelo por mesa/cliente (pestañas en Register)

- **Estado:** Pendiente de desarrollo (aún no iniciado)
- **Solicitado:** 2026-07-05
- **Revisado contra documentación funcional:** 2026-07-09 (ver sección 5.1)
- **Módulo afectado:** Sales / Register

## 1. Contexto y motivación

Casaletto opera como gastrobar: los meseros/cajeros deben poder atender varias mesas o clientes al mismo tiempo, cada uno acumulando su propia cuenta (items, cantidades, descuentos) a lo largo del servicio, **sin cobrar hasta que el cliente decide cerrar y pagar**.

El punto de venta debe permitir tener varias cuentas abiertas en simultáneo, cada una identificada por mesa (o cliente, cuando no aplica mesa), y poder alternar entre ellas de forma rápida mientras se sigue tomando/agregando pedidos en las demás.

## 2. Objetivo funcional

Que el cajero pueda ver y operar **varias ventas abiertas en paralelo** desde la pantalla de Register, cada una representada como una **pestaña** identificable (por mesa o cliente), cambiando de una a otra con un clic, sin perder el estado de ninguna.

## 3. Alcance

- Múltiples ventas "en curso" (no cobradas) abiertas al mismo tiempo, una por mesa/cliente.
- Cada pestaña mantiene su propio: carrito de items, cliente asociado, comentarios, y estado de pago parcial si aplica.
- Cambiar de pestaña debe ser instantáneo (sin tener que buscar la cuenta en una lista ni recargar la pantalla completa).
- Identificación visual clara de qué mesa/cliente corresponde a cada pestaña.
- El pago (cierre de cuenta) se sigue haciendo solo cuando el cliente decide pagar — no antes.

## 4. Fuera de alcance (por ahora)

- Cambios al flujo de cobro/pago en sí (formas de pago, descuentos, impuestos) — se mantiene el mecanismo actual de OSPOS.
- Traspaso de cuenta entre cajeros/dispositivos en tiempo real (multi-usuario simultáneo sobre la misma mesa).
- Notificaciones o KDS (kitchen display system) — no está pedido en este alcance.

## 5. Estado actual del sistema (punto de partida)

OSPOS ya cubre el resultado de negocio (varias cuentas abiertas sin cobrar) mediante un mecanismo existente, que sirve de base para este desarrollo — **no se parte de cero**:

- **Modo Mesas** (`dinner_table_enable`, configurable en Config): permite dar de alta mesas con nombre y asociarlas a una venta.
- **Suspender / Suspendidas** (atajos `ALT+3` / `ALT+4`): el cajero arma un carrito, lo "suspende" (queda guardado con estado `SUSPENDED` asociado a la mesa), y puede retomarlo luego desde una lista de ventas suspendidas.

**La limitación actual es de experiencia de uso, no de capacidad:** hoy retomar una cuenta implica ir a una lista y buscarla; lo que se pide es reemplazar (o complementar) esa lista por pestañas visibles y clicables directamente en la pantalla de Register.

### 5.1 Hallazgos al revisar la documentación funcional (2026-07-09)

Siguiendo la regla de revisar `docs/Funcional/` antes de definir/refinar un requerimiento, se contrastó este alcance contra `docs/Funcional/referencia-ospos-wiki/` (wiki oficial de OSPOS, vendorizada y traducida) y contra el código actual (3.4.2). Tres hallazgos relevantes:

1. **Limitación conocida y documentada por el proyecto original**: [`Sales-Restaurant.md`](../referencia-ospos-wiki/Sales-Restaurant.md) cita el [issue upstream #1933](https://github.com/opensourcepos/opensourcepos/issues/1933#issuecomment-379600903) — *"actualmente solo funciona con 2 mesas"*. No hay un límite duro de 2 visible en el código de nuestra versión (`Dinner_table.php`, `Config.php`) al revisarlo estáticamente, por lo que **hay que confirmarlo empíricamente en staging antes de diseñar la solución técnica** (dar de alta 3+ mesas reales, suspender varias en simultáneo, retomarlas, verificar que ninguna se pierda o mezcle). Si el bug sigue vigente, hay que resolverlo como parte de este mismo desarrollo, no como algo aparte.

2. **Existe un mecanismo hermano ya implementado que vale la pena evaluar como base alternativa/complementaria**: [`Work-Orders.md`](../referencia-ospos-wiki/Work-Orders.md) describe una Orden de Trabajo como *"esencialmente una venta suspendida que se puede recuperar en cualquier momento para actualizarla"* — conceptualmente muy cercano a lo que necesitamos (una cuenta que vive en el tiempo, se actualiza en varias visitas, y se factura al final). La wiki decía que esto era "una propuesta, próximamente en un PR", pero **se confirmó en el código que ya está implementado de verdad** (`SALE_TYPE_WORK_ORDER`, `work_order_number`, `Token_work_order_sequence`, soporte en `Config.php` y en reportes) — la doc estaba desactualizada. Vale la pena revisar si el mecanismo de Work Order (con su propio `sale_status`/`sales_type`) es más apropiado como base técnica que extender el mecanismo de mesas, o si conviene combinar ambos.

3. **Distinción a no confundir**: `Payment-Processing.md` documenta un concepto distinto y **posterior** al que nos ocupa — una venta ya *completada* puede quedar con "saldo pendiente" (due) si tiene cliente asignado. Eso es diferente de una venta *suspendida* (aún no completada), que es el estado sobre el que trabaja este requerimiento. Las pestañas en paralelo operan sobre ventas en estado `SUSPENDED`, no sobre ventas completadas con saldo pendiente — no mezclar ambos flujos en el diseño.

## 6. Actores

- **Cajero / mesero (Employee):** abre, alterna y cierra las cuentas por mesa.

## 7. Flujo funcional esperado

1. El cajero abre Register y ve las pestañas de las mesas/cuentas actualmente activas (si no hay ninguna, ve la pantalla vacía actual).
2. Selecciona "Nueva pestaña" o una mesa libre → arranca una cuenta nueva.
3. Agrega items al carrito de esa pestaña con normalidad.
4. Sin cerrar/cobrar, hace clic en otra pestaña (u otra mesa libre) → el sistema conserva intacto el carrito de la primera y muestra el de la segunda.
5. Puede repetir esto con tantas mesas como estén dadas de alta.
6. Cuando un cliente decide pagar, el cajero va a su pestaña y completa el cobro como se hace hoy — esa pestaña se cierra/libera al completarse la venta.

## 8. Preguntas abiertas / a definir antes de pasar a diseño técnico

- **[Bloqueante, con acción concreta]** Validar en staging si la limitación del issue #1933 ("solo funciona con 2 mesas") sigue vigente en 3.4.2 con 3+ mesas reales — ver 5.1.1.
- ¿Conviene construir las pestañas sobre el mecanismo de mesas (`dinner_table_id` + `SUSPENDED`), sobre el mecanismo de Work Orders (`SALE_TYPE_WORK_ORDER`), o combinar ambos? — ver 5.1.2.
- ¿Hay un número máximo razonable de pestañas simultáneas a soportar (según cantidad real de mesas del local)?
- ¿Qué debe pasar si se cierra el navegador o se pierde la sesión con pestañas abiertas — deben quedar recuperables igual que hoy quedan las suspendidas?
- ¿Se requiere impresión de comanda/ticket parcial por pestaña mientras sigue abierta, o solo al cerrar?

## 9. Referencia técnica

El diseño técnico (qué archivos tocar, cómo manejar múltiples carritos en paralelo, etc.) se aborda por separado una vez validado este alcance funcional. Puntos de partida ya identificados:

- `app/Libraries/Sale_lib.php` — carrito de sesión actual (a rediseñar para múltiples carritos en paralelo).
- `app/Models/Dinner_table.php` — mecanismo de mesas (`dinner_table_id`, ocupar/liberar).
- `app/Models/Sale.php` — estado `SUSPENDED`, y también `SALE_TYPE_WORK_ORDER`/`work_order_number` (mecanismo hermano, ver 5.1.2).
- `app/Models/Tokens/Token_work_order_sequence.php` — numeración de órdenes de trabajo, por si se reutiliza ese camino.
- `app/Views/sales/register.php` — vista del Register donde iría la barra de pestañas.
