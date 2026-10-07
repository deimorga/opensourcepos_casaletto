<?php

/**
 * Delivering a presale through the register (App\Libraries\Presale_register).
 *
 * No single quotes around {0}: in ICU MessageFormat '{0}' is printed literally.
 */

return [
    'balance_pending'             => 'Presale {0} still owes {1}. A presale is not delivered with a balance.',
    'banner'                      => 'Delivery of presale {0} — only the weight of products sold by weight can be adjusted.',
    'cart_changed'                => 'The delivery lines no longer match the presale. Reopen the register and check before completing.',
    'delivery_failed'             => 'The delivery could not be completed: the presale is no longer open or no longer paid. Nothing was charged.',
    'disabled'                    => 'Presales are switched off for this business.',
    'items_missing'               => 'A product of this presale no longer exists in the catalogue; it cannot be loaded into the register.',
    'locked'                      => 'This sale is the delivery of a presale and cannot be changed. Only the weight of products sold by weight can be adjusted.',
    'mode_sale_only'              => 'A presale delivery is completed in Sale mode.',
    'no_open_cashup'              => 'Open the cash-up shift before delivering a presale.',
    'no_grant'                    => 'Delivering a presale needs the Presales permission.',
    'no_longer_open'              => 'Presale {0} is no longer open; its delivery was removed from this register.',
    'not_covered'                 => 'The payments do not cover the total. Take the weight difference before completing.',
    'not_found'                   => 'The presale does not exist.',
    'not_open'                    => 'Presale {0} is not open: it was delivered or cancelled.',
    'open_failed'                 => 'The delivery could not be opened in the register. Try again.',
    'payment_mismatch'            => 'The Presale payment does not match what was paid ({0}). Reopen the register before completing.',
    'payment_without_presale'     => 'This sale has a Presale payment but is not the delivery of any open presale. Remove that payment.',
    'presale_payment_refused'     => 'The Presale payment is only added by the system when a presale is delivered.',
    'register_busy'               => 'The register has a sale in progress. Complete, suspend or cancel it before delivering the presale; if it is another delivery, use Back to presales.',
    'release'                     => 'Back to presales',
    'release_confirm'             => 'The delivery leaves the register without charging anything. The presale stays open and paid and can be delivered again. Continue?',
    'released'                    => 'The delivery of presale {0} left the register. The presale stays open and paid.',
    'weight_only'                 => 'In a presale delivery only the weight of products sold by weight can be changed.',
    'weight_refund_needs_manager' => 'The refund for weight is above {0}%. It must be authorised by someone with the Manage presales permission.',
];
