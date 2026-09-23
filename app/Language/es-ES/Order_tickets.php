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
    "disabled"         => "Las comandas están apagadas en este negocio. Un administrador puede encenderlas en Configuración, pestaña Comandas.",
    "dishes"           => "Platos: {0}",
    "new_ticket"       => "Nueva comanda",
    "no_tickets"       => "No hay comandas abiertas en este local.",
    "pending"          => "Sin enviar: {0}",
    "status_cancelled" => "Cancelada",
    "status_charged"   => "Cobrada",
    "status_delivered" => "Entregada",
    "status_open"      => "Abierta",
    "void"             => "Permitir cancelar una comanda",
];
