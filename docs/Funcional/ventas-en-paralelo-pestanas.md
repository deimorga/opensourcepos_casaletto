# Alcance funcional — Ventas en paralelo por mesa/cliente (pestañas en Register)

- **Estado:** Implementado y verificado funcionalmente en navegador (contenedor local) → ver [`docs/Tecnico/ventas-en-paralelo-pestanas.md`](../Tecnico/ventas-en-paralelo-pestanas.md), sección 9
- **Solicitado:** 2026-07-05
- **Revisado contra documentación funcional:** 2026-07-09 (ver sección 5.1)
- **Decisión de diseño aprobada:** 2026-07-09 (ver sección 5.2)
- **Verificación funcional completa:** 2026-07-10 (3+ mesas reales, alternancia, resiliencia ante reinicio, cobro — 2 bugs reales encontrados y corregidos en el ciclo, ver doc técnico sección 9)
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

- **Modo Mesas** (`dinner_table_enable`, configurable en Config): permite dar de alta mesas con nombre y asociarlas a una venta. Desde 2026-07-10 también se pueden crear mesas nuevas directamente desde Register ("+ New table"), sin ir a Config — ver 5.3.
- **Suspender / Suspendidas** (atajos `ALT+3` / `ALT+4`): el cajero arma un carrito, lo "suspende" (queda guardado con estado `SUSPENDED` asociado a la mesa), y puede retomarlo luego desde una lista de ventas suspendidas.

**La limitación actual es de experiencia de uso, no de capacidad:** hoy retomar una cuenta implica ir a una lista y buscarla; lo que se pide es reemplazar (o complementar) esa lista por pestañas visibles y clicables directamente en la pantalla de Register.

### 5.1 Hallazgos al revisar la documentación funcional (2026-07-09)

Siguiendo la regla de revisar `docs/Funcional/` antes de definir/refinar un requerimiento, se contrastó este alcance contra `docs/Funcional/referencia-ospos-wiki/` (wiki oficial de OSPOS, vendorizada y traducida) y contra el código actual (3.4.2). Tres hallazgos relevantes:

1. **Limitación conocida y documentada por el proyecto original**: [`Sales-Restaurant.md`](../referencia-ospos-wiki/Sales-Restaurant.md) cita el [issue upstream #1933](https://github.com/opensourcepos/opensourcepos/issues/1933#issuecomment-379600903) — *"actualmente solo funciona con 2 mesas"*. No hay un límite duro de 2 visible en el código de nuestra versión (`Dinner_table.php`, `Config.php`) al revisarlo estáticamente, por lo que **hay que confirmarlo empíricamente en staging antes de diseñar la solución técnica** (dar de alta 3+ mesas reales, suspender varias en simultáneo, retomarlas, verificar que ninguna se pierda o mezcle). Si el bug sigue vigente, hay que resolverlo como parte de este mismo desarrollo, no como algo aparte.

2. **Existe un mecanismo hermano ya implementado que vale la pena evaluar como base alternativa/complementaria**: [`Work-Orders.md`](../referencia-ospos-wiki/Work-Orders.md) describe una Orden de Trabajo como *"esencialmente una venta suspendida que se puede recuperar en cualquier momento para actualizarla"* — conceptualmente muy cercano a lo que necesitamos (una cuenta que vive en el tiempo, se actualiza en varias visitas, y se factura al final). La wiki decía que esto era "una propuesta, próximamente en un PR", pero **se confirmó en el código que ya está implementado de verdad** (`SALE_TYPE_WORK_ORDER`, `work_order_number`, `Token_work_order_sequence`, soporte en `Config.php` y en reportes) — la doc estaba desactualizada. Vale la pena revisar si el mecanismo de Work Order (con su propio `sale_status`/`sales_type`) es más apropiado como base técnica que extender el mecanismo de mesas, o si conviene combinar ambos.

3. **Distinción a no confundir**: `Payment-Processing.md` documenta un concepto distinto y **posterior** al que nos ocupa — una venta ya *completada* puede quedar con "saldo pendiente" (due) si tiene cliente asignado. Eso es diferente de una venta *suspendida* (aún no completada), que es el estado sobre el que trabaja este requerimiento. Las pestañas en paralelo operan sobre ventas que no han sido completadas todavía, no sobre ventas completadas con saldo pendiente — no mezclar ambos flujos en el diseño.

### 5.2 Decisión de diseño (aprobada 2026-07-09)

- **Base técnica: mecanismo de mesas (`dinner_table_id`), no Work Orders.** Work Orders está pensado para negocios de taller/reparación (depósito, entrega días después); no calza con el flujo de un gastrobar donde el mesero atiende en vivo. **Cada mesa dada de alta es, uno a uno, una pestaña posible en el Register.**
- **Nuevo estado `OPENED`, distinto de `SUSPENDED`.** Al revisar el código se confirmó que `SUSPENDED` ya se reutiliza para tres conceptos de negocio distintos (Cotización, Orden de Trabajo, y "pausar" un carrito normal) — usar el mismo valor para "cuenta de mesa abierta" habría sido un cuarto significado escondido detrás del mismo nombre, con riesgo real de que un desarrollador futuro lo interprete mal. Se evaluó el costo de agregar un estado nuevo (ver `docs/Tecnico/`) y resultó bajo: de los 9 archivos que usan `SUSPENDED` hoy, 6 (los reportes) no requieren ningún cambio porque siempre filtran por `sale_type` específico y nunca consultan el caso genérico.
- **Persistencia inmediata (autoguardado), no solo al "Suspender".** Hoy el carrito vive únicamente en la sesión del navegador hasta que se presiona Suspender/Completar — eso no cumple con "que se vaya guardando por si hay desconexión o reinicio". Cada cambio en una pestaña abierta (agregar/quitar/editar un item) debe guardarse de inmediato como fila real en la base, no solo al final.

### 5.3 Decisión de diseño: la mesa es una cuenta desechable por visita, no un mueble fijo (aprobada 2026-07-10)

En Casaletto una "mesa" no representa necesariamente un mueble físico numerado y permanente — puede ser una cuenta identificada con el nombre del cliente (p. ej. "Carlos"), abierta solo mientras dura esa visita. Confirmado explícitamente por el negocio: esto aplica **también** a las mesas numeradas (Mesa 1, Mesa 2, etc.), no solo a las creadas ad-hoc con nombre de cliente.

En consecuencia:
- **No hay una lista fija de mesas dadas de alta de antemano.** Se crean bajo demanda desde Register ("+ New table", con nombre libre) cuando se empieza a atender un lugar/cliente, y **se borran por completo** al pagarse o cancelarse — no quedan reutilizables en una lista.
- El dropdown de "mesa libre" y la lista de pestañas abiertas arrancan **vacíos** si no hay ninguna cuenta en curso; nunca muestran mesas ya cerradas.
- Esto responde además a la pregunta abierta de la sección 8 sobre "número máximo de pestañas": al no existir una lista fija ni acumularse mesas cerradas, no hay un límite artificial que gestionar — el único tope práctico es la cantidad de cuentas realmente abiertas en un momento dado.

## 6. Actores

- **Cajero / mesero (Employee):** abre, alterna y cierra las cuentas por mesa.

## 7. Flujo funcional esperado

1. El cajero abre Register y ve las pestañas de las mesas/cuentas actualmente activas (si no hay ninguna, ve la pantalla vacía actual).
2. Selecciona "Nueva pestaña" o una mesa libre → arranca una cuenta nueva.
3. Agrega items al carrito de esa pestaña con normalidad.
4. Sin cerrar/cobrar, hace clic en otra pestaña (u otra mesa libre) → el sistema conserva intacto el carrito de la primera y muestra el de la segunda.
5. Puede repetir esto con tantas mesas como estén dadas de alta.
6. Cuando un cliente decide pagar, el cajero va a su pestaña y completa el cobro como se hace hoy — esa pestaña se cierra y la mesa se **borra** (no solo se libera; ver 5.3), desapareciendo de las listas hasta que se vuelva a crear para la próxima visita. Lo mismo ocurre si el pedido se cancela.

## 8. Preguntas abiertas

- ¿Se requiere impresión de comanda/ticket parcial por pestaña mientras sigue abierta, o solo al cerrar?

Resueltas: base técnica (mesas, no Work Orders), manejo de estado (`OPENED` nuevo), y persistencia (autoguardado) — ver 5.2. Modelo de mesa como cuenta desechable (sin límite fijo de pestañas ni lista permanente) — ver 5.3.

**Issue #1933 validado (ver doc técnico, secciones 6 y 9):** no se reproduce con el diseño final. Sí se reprodujo una variante del mismo bug en la primera versión del código propio (cambiar a mesa vacía no vaciaba el carrito anterior) — corregida y re-verificada con 3 mesas reales alternadas repetidamente sin pérdida ni mezcla.

## 9. Referencia técnica

El diseño técnico completo (archivos exactos, nuevos endpoints, migración del estado `OPENED`, plan de pruebas) está en [`docs/Tecnico/ventas-en-paralelo-pestanas.md`](../Tecnico/ventas-en-paralelo-pestanas.md).
