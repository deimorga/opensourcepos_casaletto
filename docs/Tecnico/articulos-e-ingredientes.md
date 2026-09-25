# Artículos e ingredientes de recetas — borrado protegido y registrado

> **Estado (2026-09-24):** corrección de datos aplicada en producción (Casaletto); protección y
> registro construidos con pruebas (`ItemsDeleteGuardTest`).
>
> Documento hermano: `docs/Funcional/articulos-e-ingredientes.md`.

## 1. El defecto

`Sale_lib::add_item_kit()` agrega cada componente con `add_item()`, que falla para un artículo
borrado; `Sales::postAdd()` convierte cualquier fallo en `Sales.unable_to_add_item` («Falló al agregar
artículos para venta»). El kit se cobra al precio del kit (`price_option`), así que el total estaba
bien; el componente borrado no entraba al carrito y **no se descontaba de inventario**.

Medido en producción el 2026-09-24: 13 kits activos (item del kit con `deleted = 0`) con 9
componentes `deleted = 1`.

## 2. Cómo se averiguó quién y cuándo

- **Ventana por ventas:** última venta que trae el componente vs. primera venta posterior de uno de
  sus kits que ya no lo trae. P. ej. Jamón de pollo: 2026-09-09 20:08–20:48.
- **Historial de precios** (`item_price_history`, hora de la aplicación): `employee_id` 2 editando
  quesos 20:09–20:12 ese día; creó dos «PECHUGA DE POLLO» el 10-sep 14:56/14:57.
- **`inventory`, `trans_comment = Items.is_deleted`:** prueba directa para 5 artículos, todos
  `trans_user = 2`. **Ojo:** esas filas no fijan `trans_date` y toman el `current_timestamp` de la
  base, que está en UTC (2026-09-10 01:11 UTC = 2026-09-09 20:11 Colombia). Las filas que escribe la
  aplicación explícitamente van en hora local. Mezcla conocida, no corregida.
- `Inventory::reset_quantity()` solo escribe si la suma de existencias es positiva, y en es-MX
  `Items.is_deleted` estaba vacío (las filas quedaron en inglés, «Deleted»).
- `platform_activity_log`: soporte no borró artículos (solo `/sales/add`).

## 3. Corrección de datos (2026-09-24 ~19:58, autorizada por el dueño)

Respaldo: `/root/backups/prod-20260924-pre-articulos/ospos.sql`. Un solo `START TRANSACTION … COMMIT`,
ensayado antes con `ROLLBACK`. Sin usuarios con sesión en los 10 minutos previos.

- `items` 180 (C10143) y 173 (C10148), borrados → `C10143-BORRADO` / `C10148-BORRADO`.
- `items` 300 (PECHUGA DE POLLO, la que recibe las compras: RECV 6 y RECV 7) → `item_number C10143`,
  `unit_price 77000`; + existencias del duplicado 301 (1,70) → 7,01; 301 → `deleted = 1`. Dos filas
  de `inventory` (±1,70) y una de `item_price_history` con `employee_id` / `trans_user` 10 (soporte).
- `items` 298 (PERNIL DOLCETTO) → `C10148`.
- 14 filas de `item_kit_items`: 180→300, 173→298, 64→21 (C10192), 35 y 217→201 (C10076),
  174→31 (C10182), 196→30 (C10183), 141→4 (C10207). Ningún reemplazo estaba ya en el mismo kit
  (la PK es `item_kit_id, item_id, quantity`).
- Verificación: 0 códigos duplicados entre activos; solo 157 (Pavo navideño) sigue borrado dentro de
  kits activos (C20013, C20016, C20018, C20033), pendiente del dueño.

## 4. Lo que cambia en el código

- `Item::recipes_using(array $item_ids)`: kits activos que usan cada artículo.
- `Items::postDelete()`: si alguno de los seleccionados aparece ahí, no borra ninguno y responde
  `Items.cannot_delete_ingredient` por cada uno, con las recetas.
- `Inventory::record_deletion(int $item_id)`: fila `trans_inventory = 0`, `trans_comment =
  Items.deletion_record`, `trans_user` = empleado en sesión, `trans_date` en hora de la aplicación,
  primera ubicación activa. Se llama desde `Item::delete()` y `Item::delete_list()`, siempre, además de
  la de `reset_quantity()`. Sin empleado en sesión (CLI) no escribe.
- `Items.is_deleted` en es-MX/es-ES: «Artículo borrado: existencias llevadas a cero».

## 5. Fuera de alcance

- Otros caminos que ponen `deleted = 1` (importación CSV, carga masiva): no borran artículos hoy; si
  algún día lo hacen, deben pasar por la misma comprobación.
- Los 19 artículos activos sin código (algunos duplicados): pendiente de asignar códigos nuevos.
