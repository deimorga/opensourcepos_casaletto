<?php

/**
 * La entrega de una preventa en la pantalla de caja (App\Libraries\Presale_register).
 *
 * Sin comillas simples alrededor de {0}: en ICU MessageFormat '{0}' se imprime tal cual.
 */

return [
    'balance_pending'         => 'La preventa {0} tiene un saldo de {1}. No se entrega con saldo pendiente.',
    'banner'                  => 'Entrega de preventa {0} — solo se puede ajustar el peso de los productos por peso.',
    'cart_changed'            => 'Las líneas de la entrega no coinciden con la preventa. Vuelva a abrir la caja y revise antes de completar.',
    'delivery_failed'         => 'No se pudo completar la entrega: la preventa ya no está abierta o ya no está pagada. No se cobró nada.',
    'disabled'                => 'Las preventas están apagadas para este negocio.',
    'items_missing'           => 'Un producto de la preventa ya no existe en el catálogo; no se puede cargar en la caja.',
    'locked'                  => 'Esta venta es la entrega de una preventa y no se puede cambiar. Solo se ajusta el peso de los productos por peso.',
    'mode_sale_only'          => 'La entrega de una preventa se completa en modo Venta.',
    'no_grant'                => 'Para entregar una preventa hace falta el permiso de Preventas.',
    'no_longer_open'          => 'La preventa {0} ya no está abierta; su entrega se quitó de esta caja.',
    'not_covered'             => 'Los pagos no cubren el total. Cobre la diferencia por peso antes de completar.',
    'not_found'               => 'La preventa no existe.',
    'not_open'                => 'La preventa {0} no está abierta: ya se entregó o se canceló.',
    'open_failed'             => 'No se pudo abrir la entrega en la caja. Intente de nuevo.',
    'payment_mismatch'        => 'El pago «Preventa» no coincide con lo abonado ({0}). Vuelva a abrir la caja antes de completar.',
    'payment_without_presale' => 'Esta venta tiene un pago «Preventa» pero no es la entrega de ninguna preventa abierta. Quite ese pago.',
    'presale_payment_refused' => 'El pago «Preventa» solo lo pone el sistema al entregar una preventa.',
    'register_busy'           => 'La caja tiene una venta en curso. Complétela o suspéndala antes de entregar la preventa.',
    'weight_only'             => 'En la entrega de una preventa solo se puede cambiar el peso de los productos por peso.',
];
