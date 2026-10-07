<?php

/**
 * Presales.
 *
 * `manage` is the label of the presales_manage subpermission. The Employees screen builds that key as
 * ucfirst("<module_id>.<suffix>"), so it is RESERVED for that label.
 *
 * `terms_template` is the suggested wording of the conditions (docs/Funcional/venta-anticipada.md
 * §4.14). It names no business: each business saves its own in Configuration.
 */

return [
    "campaign_dates_invalid"      => "Check the campaign dates: the selling start and end must be valid dates, and the start cannot be after the end.",
    "campaign_name_required"      => "Enter the campaign name (up to 100 characters).",
    "campaign_not_found"          => "The campaign does not exist or was deleted.",
    "campaign_not_selling"        => "This campaign is not taking new presales: it is inactive or outside its selling period.",
    "campaigns"                   => "Campaigns",
    "cancel_reason_required"      => "Enter why the presale is being cancelled.",
    "customer_required"           => "A presale needs a registered customer.",
    "date_invalid"                => "The date is not valid.",
    "date_not_in_campaign"        => "The delivery date must be one of the campaign's dates.",
    "disabled"                    => "Presales are switched off for this business. The administrator can switch them on in Configuration, Presales tab.",
    "initial_below_minimum"       => "The initial instalment is below the minimum the campaign requires.",
    "initial_payment_required"    => "Record the payment of the initial instalment.",
    "installment_after_delivery"  => "No instalment can fall after the delivery date.",
    "installment_amount_invalid"  => "Every instalment must be greater than zero.",
    "installments_must_add_up"    => "The instalments must add up exactly to the presale total.",
    "installments_required"       => "Enter the payment plan agreed with the customer.",
    "item_not_in_campaign"        => "That product is not in the campaign.",
    "item_not_in_catalogue"       => "That product is not in the catalogue. Create it in Items first.",
    "kits_not_supported"          => "Item kits cannot be sold in presale yet.",
    "lines_required"              => "Add at least one product.",
    "manage"                      => "Manage presales: campaigns and cancellations",
    "no_open_cashup"              => "No cash-up shift is open. Open the shift before taking or giving back money.",
    "not_open"                    => "This presale is no longer open.",
    "payment_amount_invalid"      => "The payment must be greater than zero.",
    "payment_exceeds_balance"     => "The amount is greater than the presale balance.",
    "payment_type_invalid"        => "That payment type is not accepted for presales. Use cash, card or bank transfer.",
    "percent_invalid"             => "The percentage must be between 0 and 100.",
    "price_invalid"               => "The price is not valid.",
    "quantity_invalid"            => "The quantity must be greater than zero.",
    "quantity_must_be_whole"      => "That product is sold by the unit: the quantity must be a whole number.",
    "refund_exceeds_paid"         => "You cannot give back more than the customer has paid.",
    "refund_invalid"              => "The refund amount is not valid.",
    "save_failed"                 => "It could not be saved. Nothing was recorded; try again.",
    "state_canceled"              => "Cancelled",
    "state_delivered"             => "Delivered",
    "state_late"                  => "Late",
    "state_paid"                  => "Paid",
    "state_up_to_date"            => "Up to date",
    "terms_template"              => "PRESALE CONDITIONS\n1. This document reserves the products and prices shown here for the stated delivery date.\n2. The products are delivered only once the total has been paid.\n3. The customer agrees to pay the instalments on the agreed dates. Payments may be made before each date and for any amount, up to the balance.\n4. Late instalments carry no interest or charges.\n5. Products sold by weight are agreed at an approximate weight. The final amount is adjusted to the actual weight on delivery day, and the difference is paid or returned that same day.\n6. If the customer cancels the presale, any refund of what was paid will be agreed between the customer and the business, and recorded on the cancellation document.\n7. To make a payment or collect the products, show this document or your presale number.",
];
