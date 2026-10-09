<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * A person's identity document: type and number (docs/Funcional/documento-de-identidad.md).
 *
 * One definition for the forms, the search, the CSV import and the printed documents (IT4), so the
 * number is cleaned the same way everywhere. That matters for the duplicate check: compared raw,
 * "1.020.345.678" and "1020345678" would be two customers.
 *
 * Pure: it never reads the database. Who owns a document is App\Models\Person::document_owner().
 *
 * The number is read raw and cleaned here, never with FILTER_SANITIZE_* (IT7): that filter behaves
 * like htmlentities() and would store what it altered. Escaping belongs to the output.
 *
 * See docs/Tecnico/documento-de-identidad.md.
 */
final class Identity_document
{
    /**
     * The DIAN list (I1), in the order the select shows it, with each type's code in the DIAN
     * electronic invoicing table (Resolucion 000042 de 2020, 13.2.1) and whether its number is
     * digits only.
     *
     * @var array<string, array{dian_code: string, numeric: bool}>
     */
    public const TYPES = [
        'CC'   => ['dian_code' => '13', 'numeric' => true],
        'CE'   => ['dian_code' => '22', 'numeric' => true],
        'TI'   => ['dian_code' => '12', 'numeric' => true],
        'RC'   => ['dian_code' => '11', 'numeric' => true],
        'NIT'  => ['dian_code' => '31', 'numeric' => true],
        'PA'   => ['dian_code' => '41', 'numeric' => false],
        'PPT'  => ['dian_code' => '48', 'numeric' => false],
        'PEP'  => ['dian_code' => '47', 'numeric' => false],
        'DIE'  => ['dian_code' => '42', 'numeric' => false],
        'NUIP' => ['dian_code' => '91', 'numeric' => true],
    ];

    /**
     * people.document_number is varchar(32).
     */
    public const MAX_LENGTH = 32;

    /**
     * The DIAN weights for the NIT check digit, applied from the rightmost digit leftwards.
     */
    private const NIT_WEIGHTS = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];

    /**
     * What people type out of habit between the digits.
     */
    private const SEPARATORS = ['.', ',', ' ', '-', "\t"];

    public static function is_type(?string $type): bool
    {
        return $type !== null && array_key_exists($type, self::TYPES);
    }

    public static function dian_code(string $type): ?string
    {
        return self::TYPES[$type]['dian_code'] ?? null;
    }

    public static function label(string $type): string
    {
        return lang('Common.document_type_' . strtolower($type));
    }

    /**
     * The options of the type select: an empty first choice, then "CC - Cedula de ciudadania"...
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = ['' => lang('Common.document_type_choose')];

        foreach (array_keys(self::TYPES) as $type) {
            $options[$type] = $type . ' - ' . self::label($type);
        }

        return $options;
    }

    /**
     * The number as it is stored and compared.
     *
     * Numeric types keep only what is left after dropping dots, commas, spaces and hyphens; the
     * alphanumeric ones drop the same separators and are uppercased. A NIT typed with its check
     * digit after a hyphen ("900123456-8") is stored without it: the digit is worked out, never kept.
     * validate() is what refuses a check digit that does not match.
     */
    public static function normalize(string $type, ?string $number): string
    {
        $number = trim((string) $number);

        if ($type === 'NIT') {
            [$base] = self::split_nit($number);

            return $base;
        }

        $clean = str_replace(self::SEPARATORS, '', $number);

        return self::is_type($type) && ! self::TYPES[$type]['numeric'] ? strtoupper($clean) : $clean;
    }

    /**
     * Why the document cannot be saved, as a language key, or null when it can.
     */
    public static function validate(string $type, ?string $number): ?string
    {
        if (! self::is_type($type)) {
            return 'Common.document_type_invalid';
        }

        $normalized = self::normalize($type, $number);

        if ($normalized === '') {
            return 'Common.document_number_required';
        }

        if (strlen($normalized) > self::MAX_LENGTH) {
            return 'Common.document_number_invalid';
        }

        $valid = self::TYPES[$type]['numeric'] ? ctype_digit($normalized) : ctype_alnum($normalized);

        if (! $valid) {
            return 'Common.document_number_invalid';
        }

        if ($type === 'NIT') {
            [, $typed_digit] = self::split_nit(trim((string) $number));

            if ($typed_digit !== null && (int) $typed_digit !== self::nit_check_digit($normalized)) {
                return 'Common.document_nit_check_digit_wrong';
            }
        }

        return null;
    }

    /**
     * The DIAN modulo 11 check digit of a NIT given without it.
     */
    public static function nit_check_digit(string $nit): int
    {
        $digits = strrev($nit);
        $sum    = 0;

        for ($i = 0, $length = min(strlen($digits), count(self::NIT_WEIGHTS)); $i < $length; $i++) {
            $sum += (int) $digits[$i] * self::NIT_WEIGHTS[$i];
        }

        $remainder = $sum % 11;

        return $remainder > 1 ? 11 - $remainder : $remainder;
    }

    /**
     * How a document is printed: "CC 1020345678", "NIT 900123456-8". A person saved before types
     * existed has a number and no type, and prints the number alone (IT6). No number, nothing.
     */
    public static function format(?string $type, ?string $number): string
    {
        $number = trim((string) $number);

        if ($number === '') {
            return '';
        }

        if (! self::is_type($type)) {
            return $number;
        }

        if ($type === 'NIT' && ctype_digit($number)) {
            return 'NIT ' . $number . '-' . self::nit_check_digit($number);
        }

        return $type . ' ' . $number;
    }

    /**
     * A search term cleaned like a number, to compare against a document whatever separators either
     * side was typed with. Empty when the term has nothing a document could contain.
     */
    public static function search_key(?string $term): string
    {
        return strtoupper(str_replace(self::SEPARATORS, '', trim((string) $term)));
    }

    /**
     * Splits "900.123.456-8" into the NIT without separators and the check digit typed after the
     * hyphen, if there was one. A hyphen anywhere else is just a separator.
     *
     * @return array{0: string, 1: string|null}
     */
    private static function split_nit(string $number): array
    {
        $clean = str_replace(['.', ',', ' ', "\t"], '', $number);

        if (preg_match('/^(\d+)-(\d)$/', $clean, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        return [str_replace('-', '', $clean), null];
    }
}
