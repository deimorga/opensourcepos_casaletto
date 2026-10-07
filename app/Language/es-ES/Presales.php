<?php

/**
 * Preventas.
 *
 * `manage` es la etiqueta del subpermiso presales_manage. La pantalla de Empleados arma esa clave como
 * ucfirst("<module_id>.<sufijo>"), así que queda RESERVADA para esa etiqueta.
 *
 * `terms_template` es el texto sugerido de las condiciones (docs/Funcional/venta-anticipada.md §4.14).
 * No menciona ningún negocio: cada negocio guarda el suyo en Configuración.
 */

return [
    "campaign_dates_invalid"      => "Revise las fechas de la campaña: el inicio y el cierre de venta deben ser fechas válidas, y el inicio no puede ser posterior al cierre.",
    "campaign_name_required"      => "Escriba el nombre de la campaña (hasta 100 caracteres).",
    "campaign_not_found"          => "La campaña no existe o fue eliminada.",
    "campaign_not_selling"        => "Esta campaña no está recibiendo preventas nuevas: está inactiva o fuera de su periodo de venta.",
    "campaigns"                   => "Campañas",
    "cancel_reason_required"      => "Escriba por qué se cancela la preventa.",
    "customer_required"           => "La preventa necesita un cliente registrado.",
    "date_invalid"                => "La fecha no es válida.",
    "date_not_in_campaign"        => "La fecha de entrega tiene que ser una de las fechas de la campaña.",
    "disabled"                    => "Las preventas están apagadas para este negocio. El administrador puede encenderlas en Configuración, pestaña Preventas.",
    "initial_below_minimum"       => "La cuota inicial es menor que el mínimo que pide la campaña.",
    "initial_payment_required"    => "Registre el pago de la cuota inicial.",
    "installment_after_delivery"  => "Ninguna cuota puede quedar después de la fecha de entrega.",
    "installment_amount_invalid"  => "Cada cuota debe tener un valor mayor que cero.",
    "installments_must_add_up"    => "Las cuotas tienen que sumar exactamente el total de la preventa.",
    "installments_required"       => "Escriba el plan de cuotas acordado con el cliente.",
    "item_not_in_campaign"        => "Ese producto no está en la campaña.",
    "item_not_in_catalogue"       => "Ese producto no existe en el catálogo. Créelo primero en Artículos.",
    "kits_not_supported"          => "Las recetas armadas (kits) todavía no se pueden vender en preventa.",
    "lines_required"              => "Agregue al menos un producto.",
    "manage"                      => "Gestionar preventas: campañas y cancelaciones",
    "no_open_cashup"              => "No hay un turno de caja abierto. Abra el turno antes de recibir o devolver dinero.",
    "not_open"                    => "Esta preventa ya no está abierta.",
    "payment_amount_invalid"      => "El valor del abono debe ser mayor que cero.",
    "payment_exceeds_balance"     => "El valor es mayor que el saldo de la preventa.",
    "payment_type_invalid"        => "Ese medio de pago no se admite en preventas. Use efectivo, datáfono o transferencia.",
    "percent_invalid"             => "El porcentaje debe estar entre 0 y 100.",
    "price_invalid"               => "El precio no es válido.",
    "quantity_invalid"            => "La cantidad debe ser mayor que cero.",
    "quantity_must_be_whole"      => "Ese producto se vende por unidades: la cantidad debe ser un número entero.",
    "refund_exceeds_paid"         => "No se puede devolver más de lo que el cliente ha pagado.",
    "refund_invalid"              => "El valor a devolver no es válido.",
    "save_failed"                 => "No se pudo guardar. No se registró nada; intente de nuevo.",
    "state_canceled"              => "Cancelada",
    "state_delivered"             => "Entregada",
    "state_late"                  => "Atrasada",
    "state_paid"                  => "Pagada",
    "state_up_to_date"            => "Al día",
    "terms_template"              => "CONDICIONES DE LA PREVENTA\n1. Este comprobante reserva los productos y los precios aquí indicados para la fecha de entrega señalada.\n2. Los productos se entregan únicamente cuando el valor total esté pagado.\n3. El cliente se compromete a pagar las cuotas en las fechas acordadas. Puede abonar antes de cada fecha y por cualquier valor, hasta completar el saldo.\n4. Los atrasos en las cuotas no generan intereses ni recargos.\n5. Los productos que se venden por peso se pactan con un peso aproximado. El valor final se ajusta al peso real el día de la entrega, y la diferencia se paga o se devuelve ese mismo día.\n6. Si el cliente cancela la preventa, la devolución de lo abonado se acordará entre el cliente y el negocio, y quedará registrada en el comprobante de cancelación.\n7. Para abonar y para reclamar los productos, presente este comprobante o su número de preventa.",
];
