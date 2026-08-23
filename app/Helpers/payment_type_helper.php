<?php

/**
 * Payment types as stable codes.
 *
 * Payment types used to live in the database only as translated labels, which made every filter a
 * string comparison against lang(). That broke in three separate ways, all of them silent:
 *
 *  - Expenses saved the label from the Sales.* keys and filtered against the Expenses.* keys. In
 *    es-MX "Adeudo" is stored and "A Crédito" is searched, so that filter never matched anything.
 *  - The comparisons only worked at all because the tables use utf8_general_ci, which ignores case
 *    and accents. A stricter collation would have broken all of them.
 *  - LIKE matches substrings, so the cash filter also caught "Ajuste de efectivo".
 *
 * The code is what filters and grouping compare on. The translated label stays in payment_type for
 * display and for the receipts, which append the gift card number to it.
 *
 * See docs/Tecnico/errores-produccion-upstream.md section 5.
 */

/**
 * Every payment type the application can record, as code => language key.
 *
 * The codes are the contract: they are written to the database, used in filters and put in the
 * value attribute of the payment dropdowns. They must never be translated or renamed.
 */
function payment_type_code_map(): array
{
    return [
        'cash'            => 'Sales.cash',
        'debit'           => 'Sales.debit',
        'credit'          => 'Sales.credit',
        'due'             => 'Sales.due',
        'check'           => 'Sales.check',
        'bank_transfer'   => 'Sales.bank_transfer',
        'wallet'          => 'Sales.wallet',
        'upi'             => 'Sales.upi',
        'giftcard'        => 'Sales.giftcard',
        'rewards'         => 'Sales.rewards',
        'cash_adjustment' => 'Sales.cash_adjustment',
        'cash_deposit'    => 'Sales.cash_deposit',
        'credit_deposit'  => 'Sales.credit_deposit',
    ];
}

/**
 * The label shown to the user for a payment code, in the language that is active now.
 *
 * $fallback carries the legacy payment_type column so a row whose code could not be resolved -- an
 * old row the backfill did not recognise -- still shows what it always showed instead of vanishing
 * or rendering as a bare code.
 */
function payment_type_label(?string $code, ?string $fallback = null): string
{
    $map = payment_type_code_map();

    if ($code !== null && isset($map[$code])) {
        return lang($map[$code]);
    }

    return $fallback ?? '';
}

/**
 * Resolves a stored label back to its code using the language that is active now.
 *
 * Both the Sales.* and the Expenses.* keys are checked because expenses historically saved labels
 * from one file and filtered against the other. Gift cards arrive as "<label>:<number>" and resolve
 * on the part before the colon.
 *
 * Returns null when nothing matches. A caller must treat that as "unknown", never as a default:
 * quietly filing an unrecognised value under some innocent code is how 217,000 pesos went missing
 * in cash-up 29.
 */
function payment_type_code_from_label(?string $label): ?string
{
    if ($label === null || $label === '') {
        return null;
    }

    $label = trim($label);

    foreach (payment_type_code_map() as $code => $langKey) {
        $suffix = substr($langKey, strlen('Sales.'));

        foreach ([$langKey, 'Expenses.' . $suffix] as $candidateKey) {
            $candidate = lang($candidateKey);

            // lang() echoes the key back when it is not defined, e.g. "Expenses.wallet".
            if ($candidate === $candidateKey) {
                continue;
            }

            if (mb_strtolower($candidate) === mb_strtolower($label)) {
                return $code;
            }
        }
    }

    if (str_contains($label, ':')) {
        return payment_type_code_from_label(strstr($label, ':', true));
    }

    return null;
}

/**
 * Payment options for a dropdown, as code => translated label.
 *
 * Mirrors the ordering that get_payment_options() applies from the payment_options_order setting,
 * so the register keeps behaving the way the cashiers are used to.
 */
function payment_type_options(bool $giftcard = false, bool $rewards = false): array
{
    $config = config(Config\OSPOS::class)->settings;

    $orders = [
        'debitcreditcash' => ['debit', 'credit', 'cash'],
        'debitcashcredit' => ['debit', 'cash', 'credit'],
        'creditdebitcash' => ['credit', 'debit', 'cash'],
        'creditcashdebit' => ['credit', 'cash', 'debit'],
    ];

    $codes = $orders[$config['payment_options_order']] ?? ['cash', 'debit', 'credit'];
    $codes = array_merge($codes, ['due', 'check']);

    if (stripos($config['country_codes'], 'IN') !== false) {
        $codes[] = 'upi';
    }

    $codes = array_merge($codes, ['bank_transfer', 'wallet']);

    if ($giftcard) {
        $codes[] = 'giftcard';
    }

    if ($rewards) {
        $codes[] = 'rewards';
    }

    $options = [];
    foreach ($codes as $code) {
        $options[$code] = payment_type_label($code);
    }

    return $options;
}
