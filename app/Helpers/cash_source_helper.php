<?php

/**
 * Where the cash for an expense came out of.
 *
 * The business pays cash expenses from two different pockets: the till at the point of sale, and
 * money that has already been taken out of the till. Recording only that an expense was paid "in
 * cash" cannot tell the two apart, and without that distinction the expected cash in the till --
 * the whole point of a cash-up -- is not computable.
 *
 * This lives beside payment_type_helper.php and follows the same rule: the code is the contract and
 * the label is display only. Comparing translated labels is what left the expenses payment filters
 * matching nothing for months.
 *
 * See docs/Tecnico/cuadre-de-caja-y-origen-del-efectivo.md section 3.
 */

/**
 * Every cash source an expense can have, as code => language key.
 *
 * The codes are written to the database and put in the value attribute of the dropdown. They must
 * never be translated or renamed.
 */
function cash_source_code_map(): array
{
    return [
        'register'  => 'Expenses.cash_source_register',
        'collected' => 'Expenses.cash_source_collected',
    ];
}

/**
 * True when $code is one of the two stored codes. Anything else -- a label, a blank, a fabricated
 * POST value -- is not a cash source.
 */
function is_cash_source_code(?string $code): bool
{
    return $code !== null && isset(cash_source_code_map()[$code]);
}

/**
 * The label shown to the user for a cash source, in the language that is active now.
 *
 * An expense that is not paid in cash has no cash source, and its empty label is correct: the
 * question does not apply to a bank transfer.
 */
function cash_source_label(?string $code): string
{
    $map = cash_source_code_map();

    return isset($map[$code]) ? lang($map[$code]) : '';
}

/**
 * Cash sources for a dropdown, as code => translated label.
 *
 * Mirrors payment_type_options(): the code goes in the value attribute, the label is only what the
 * user reads.
 */
function cash_source_options(): array
{
    $options = [];

    foreach (array_keys(cash_source_code_map()) as $code) {
        $options[$code] = cash_source_label($code);
    }

    return $options;
}

/**
 * Decides which cash source to store for one expense.
 *
 * This is the authority, not the form. A disabled field is not submitted at all, and a fabricated
 * POST can carry anything, so the rule is applied here from the payment type and the employee's
 * permission rather than trusted from the request.
 *
 * @param string|null $payment_type_code The resolved code of the payment method, null when the
 *                                       posted label matched nothing known.
 * @param bool        $can_choose        Whether this employee is allowed to pick a source, i.e.
 *                                       holds the 'config' grant.
 * @param string|null $submitted         The raw value from the request. Read, but only honoured
 *                                       when $can_choose says so.
 *
 * @return string|false|null 'register' or 'collected' to store; null when the expense is not paid
 *                           in cash and the question does not apply; false when the value could not
 *                           be determined and the save has to be refused.
 *
 * False is not a value to store. An administrator reaches both pockets, so nothing about their
 * expense can be deduced, and a default would be a guess dressed up as data. Cash-up 29 lost
 * 217,000 pesos precisely because a blank was quietly accepted as zero: a datum that could not be
 * determined has to make noise.
 */
function resolve_expense_cash_source(?string $payment_type_code, bool $can_choose, ?string $submitted): string|false|null
{
    if ($payment_type_code !== 'cash') {
        return null;
    }

    if (!$can_choose) {
        return 'register';
    }

    $choice = trim($submitted ?? '');

    return is_cash_source_code($choice) ? $choice : false;
}
