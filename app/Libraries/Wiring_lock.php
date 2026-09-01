<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * The three configuration keys a business is not allowed to move.
 *
 * D12 calls them wiring rather than configuration, and the distinction is the whole point: the rest
 * of the configuration screens are the business's own preferences, and these three are not. Each of
 * them has already cost this project a real incident:
 *
 * - `quantity_decimals` at anything below 3 loses the weight of everything sold by the kilo without
 *   saying so. The sale still adds up in money; the stock silently does not.
 * - `barcode_content` at `id` makes a typed code sell a different product. It happened at Paraiso on
 *   2026-08-31: typing `56` (avocado, sold by weight) rang up cherry jelly. 212 of 1.184 references
 *   collided.
 * - `language_code` on any variant other than `es-MX` splits the translations in two. A string
 *   written only in `es-ES` is invisible under `es-MX`: the screen comes out in English and nothing
 *   anywhere reports an error.
 *
 * This class holds the rule, not the enforcement. It is deliberately pure -- it never reads the
 * database, it never renders anything -- so the rule can be stated once and then applied from the
 * two places that need it: `Config::postSaveLocale()` / `Config::postSaveBarcode()`, which refuse
 * the change server-side, and `configs/locale_config.php` / `configs/barcode_config.php`, which show
 * the field fixed and say why.
 *
 * WHAT IT DOES NOT DO, ON PURPOSE:
 *
 * - It does not repair anything. A business already sitting on the wrong value keeps it; the screen
 *   says so out loud (`Config.wired_setting_mismatch`) instead of freezing it in silence. Writing
 *   the right value into a business that is trading is a reviewed, key-by-key operation (D13), not
 *   a side effect of somebody saving an unrelated tab.
 * - It is not wired into `Appconfig::batch_save()`. The model is also how the platform writes a
 *   tenant's configuration when a business is provisioned, and a lock down there would lock out the
 *   only party that is supposed to set these. The lock belongs at the boundary the *customer*
 *   reaches, which is the two controller endpoints.
 *
 * See docs/Funcional/gestion-de-plataforma-y-negocios.md section 5 (D12) and
 * docs/Tecnico/gestion-de-plataforma-y-negocios.md section 9.13.
 */
final class Wiring_lock
{
    /**
     * The locked keys and the value the platform requires for each. Being a key of this array is
     * what makes a setting locked -- there is no second list to keep in step with this one.
     *
     * @var array<string, string>
     */
    public const WIRED_VALUES = [
        'barcode_content'   => 'item_number',
        'language_code'     => 'es-MX',
        'quantity_decimals' => '3',
    ];

    /**
     * The label each locked key is known by on screen, so a refusal names the setting the way the
     * person reading it saw it. `language_code` has no label of its own: it is derived from the
     * language select, which is labelled `Config.language`.
     *
     * @var array<string, string>
     */
    private const LABEL_KEYS = [
        'barcode_content'   => 'Config.barcode_content',
        'language_code'     => 'Config.language',
        'quantity_decimals' => 'Config.quantity_decimals',
    ];

    public static function is_locked(string $key): bool
    {
        return array_key_exists($key, self::WIRED_VALUES);
    }

    /**
     * The value the platform requires for a locked key, or an empty string for anything else.
     */
    public static function required_value(string $key): string
    {
        return self::WIRED_VALUES[$key] ?? '';
    }

    /**
     * Whether a business is already sitting on the required value. A key that is not locked at all
     * is reported as matching, so callers do not have to ask twice.
     */
    public static function matches_wiring(string $key, string $current): bool
    {
        return ! self::is_locked($key) || $current === self::WIRED_VALUES[$key];
    }

    /**
     * The locked keys a request would have moved.
     *
     * The rule is one sentence: a locked key may stay exactly where it is, or move to the value the
     * platform requires. Anything else is refused.
     *
     * The second half of that rule is a safety valve, not a path through the interface. The fields
     * are rendered disabled, so nothing the customer can click produces either move; what it buys
     * is that a business that was provisioned before the profile existed is never trapped on the
     * dangerous value with no way back to the right one.
     *
     * A key that is absent from $attempted was never offered by the screen -- a disabled field is
     * not submitted at all -- and that is not a change. Callers must therefore only put in
     * $attempted the keys the request actually carried, never a null standing in for a missing one.
     *
     * @param array<string, mixed> $attempted Posted value per locked key, for the keys present in the request.
     * @param array<string, mixed> $current   What each of those keys holds right now.
     *
     * @return list<string> The locked keys that have to be refused, in the order given.
     */
    public static function refused(array $attempted, array $current): array
    {
        $refused = [];

        foreach ($attempted as $key => $value) {
            if ($value === null || ! self::is_locked($key)) {
                continue;
            }

            $value = (string) $value;

            if ($value === (string) ($current[$key] ?? '') || $value === self::WIRED_VALUES[$key]) {
                continue;
            }

            $refused[] = $key;
        }

        return $refused;
    }

    /**
     * The on-screen name of a locked key, translated.
     */
    public static function label(string $key): string
    {
        return lang(self::LABEL_KEYS[$key] ?? $key);
    }
}
