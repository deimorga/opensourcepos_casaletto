<?php

/**
 * Comandas.
 *
 * `void` es la etiqueta del subpermiso order_tickets_void. La pantalla de Empleados arma esa clave
 * como ucfirst("<module_id>.<sufijo>"), así que tiene que vivir en este archivo con ese nombre
 * exacto.
 */

return [
    "back"             => "Volver",
    "create"           => "Abrir comanda",
    "create_failed"    => "No se pudo abrir la comanda. Intente de nuevo.",
    "disabled"         => "Las comandas están apagadas en este negocio. Un administrador puede encenderlas en Configuración, pestaña Comandas.",
    "dishes"           => "Platos: {0}",
    "name"             => "Nombre de la comanda",
    "name_help"        => "Como la van a reconocer en la caja: «ANDREA», «mesa 4», «domicilio Juan».",
    "name_required"    => "Escriba un nombre para la comanda.",
    "new_ticket"       => "Nueva comanda",
    "no_tickets"       => "No hay comandas abiertas en este local.",
    "note"             => "Nota del pedido (opcional)",
    "pending"          => "Sin enviar: {0}",
    "status_cancelled" => "Cancelada",
    "status_charged"   => "Cobrada",
    "status_delivered" => "Entregada",
    "status_open"      => "Abierta",
    "tables_off"       => "Las comandas necesitan Mesas encendido en este negocio. Avise a un administrador.",
    "void"             => "Permitir cancelar una comanda",
];
