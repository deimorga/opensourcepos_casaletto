# Alcance funcional — Ventas en paralelo por mesa/cliente (pestañas en Register)

- **Estado:** Pendiente de desarrollo (aún no iniciado)
- **Solicitado:** 2026-07-05
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

- ¿Hay un número máximo razonable de pestañas simultáneas a soportar (según cantidad real de mesas del local)?
- ¿Qué debe pasar si se cierra el navegador o se pierde la sesión con pestañas abiertas — deben quedar recuperables igual que hoy quedan las suspendidas?
- ¿Se requiere impresión de comanda/ticket parcial por pestaña mientras sigue abierta, o solo al cerrar?

## 9. Referencia técnica

El diseño técnico (qué archivos tocar, cómo manejar múltiples carritos en paralelo, etc.) se aborda por separado una vez validado este alcance funcional. Puntos de partida ya identificados: `app/Libraries/Sale_lib.php`, `app/Models/Dinner_table.php`, `app/Models/Sale.php` (estado `SUSPENDED`), `app/Views/sales/register.php`.
