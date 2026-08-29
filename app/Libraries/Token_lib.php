<?php

namespace App\Libraries;

use App\Models\Tokens\Token;
use App\Models\Tokens\Token_scale_weight;
use Config\OSPOS;
use IntlDateFormatter;
use DateTime;
use Throwable;

/**
 * Token library
 *
 * Library with utilities to manage tokens
 */
class Token_lib
{
    /**
     * Decimals of the kilogram figure parse_scale() answers. Three is one gram, which is finer than
     * any trade scale on this market resolves (the client's divides by five grams).
     */
    public const SCALE_DECIMALS = 3;

    /**
     * A weight frame is a couple of dozen bytes. This cap is generous enough to hold several frames
     * at once -- a buffered read is legitimate and an unanchored pattern will find the first one --
     * and small enough that line noise can never turn into a long regex subject.
     */
    public const SCALE_MAX_FRAME_LENGTH = 256;

    private array $strftimeToIntlPatternMap = [
        '%a' => 'EEE',
        '%A' => 'EEEE',
        '%b' => 'MMM',
        '%B' => 'MMMM',
        '%d' => 'dd',
        '%D' => 'MM/dd/yy',
        '%e' => 'd',
        '%F' => 'yyyy-MM-dd',
        '%h' => 'MMM',
        '%j' => 'D',
        '%m' => 'MM',
        '%U' => 'w',
        '%V' => 'ww',
        '%W' => 'ww',
        '%y' => 'yy',
        '%Y' => 'yyyy',
        '%H' => 'HH',
        '%I' => 'hh',
        '%l' => 'h',
        '%M' => 'mm',
        '%p' => 'a',
        '%P' => 'a',
        '%r' => 'hh:mm:ss a',
        '%R' => 'HH:mm',
        '%S' => 'ss',
        '%T' => 'HH:mm:ss',
        '%X' => 'HH:mm:ss',
        '%z' => 'ZZZZZ',
        '%Z' => 'z',
        '%g' => 'yy',
        '%G' => 'yyyy',
        '%u' => 'e',
        '%w' => 'c',
    ];

    private array $validStrftimeFormats = [
        'a', 'A', 'b', 'B', 'c', 'd', 'D', 'e', 'F', 'g', 'G',
        'h', 'H', 'I', 'j', 'm', 'M', 'n', 'p', 'P', 'r', 'R',
        'S', 't', 'T', 'u', 'U', 'V', 'w', 'W', 'x', 'X', 'y', 'Y', 'z', 'Z'
    ];

    /**
     * Expands all the tokens found in a given text string and returns the results.
     */
    public function render(string $tokened_text, array $tokens = [], $save = true): string
    {
        if (str_contains($tokened_text, '%')) {
            $tokened_text = $this->applyDateFormats($tokened_text);
        }

        $token_tree = $this->scan($tokened_text);

        if (empty($token_tree)) {
            return $tokened_text;
        }

        $token_values = [];
        $tokens_to_replace = [];
        $this->generate($token_tree, $tokens, $tokens_to_replace, $token_values, $save);

        return str_replace($tokens_to_replace, $token_values, $tokened_text);
    }

    private function applyDateFormats(string $text): string
    {
        $formatter = new IntlDateFormatter(
            null,
            IntlDateFormatter::FULL,
            IntlDateFormatter::FULL,
            null,
            null,
            ''
        );

        $dateTime = new DateTime();

        return preg_replace_callback(
            '/%([a-zA-Z%])/',
            function ($match) use ($formatter, $dateTime) {
                $formatChar = $match[1];

                if ($formatChar === '%') {
                    return '%';
                }

                if ($formatChar === 'n') {
                    return "\n";
                }

                if ($formatChar === 't') {
                    return "\t";
                }

                if ($formatChar === 'C') {
                    return str_pad((string) intdiv((int) $dateTime->format('Y'), 100), 2, '0', STR_PAD_LEFT);
                }

                if ($formatChar === 'c') {
                    $formatter->setPattern('yyyy-MM-dd HH:mm:ss');
                    $result = $formatter->format($dateTime);
                    return $result !== false ? $result : $match[0];
                }

                if ($formatChar === 'x') {
                    $formatter->setPattern('yyyy-MM-dd');
                    $result = $formatter->format($dateTime);
                    return $result !== false ? $result : $match[0];
                }

                if (!in_array($formatChar, $this->validStrftimeFormats, true)) {
                    return $match[0];
                }

                $intlPattern = $this->strftimeToIntlPatternMap[$match[0]] ?? null;

                if ($intlPattern === null) {
                    return $match[0];
                }

                $formatter->setPattern($intlPattern);
                $result = $formatter->format($dateTime);

                return $result !== false ? $result : $match[0];
            },
            $text
        );
    }

    public function scan(string $text): array
    {
        preg_match_all('/
                \{             # [ - pattern start
                ([^\s\{\}:]+)  # match $token not containing whitespace : { or }
                (?:
                :              # : - separator
                ([^\s\{\}:]+)     # match $length not containing whitespace : { or }
                )?
                \}             # ] - pattern end
                /x', $text, $matches);

        $tokens = $matches[1];
        $lengths = $matches[2];

        $token_tree = [];
        for ($i = 0; $i < count($tokens); $i++) {
            $token_tree[$tokens[$i]][$lengths[$i]] = $matches[0][$i];
        }

        return $token_tree;
    }

    public function parse_barcode(?string &$quantity, ?string &$price, ?string &$item_id_or_number_or_item_kit_or_receipt): void
    {
        $config = config(OSPOS::class)->settings;
        $barcode_formats = json_decode($config['barcode_formats']);
        $barcode_tokens = Token::get_barcode_tokens();

        if (!empty($barcode_formats)) {
            foreach ($barcode_formats as $barcode_format) {
                $parsed_results = $this->parse($item_id_or_number_or_item_kit_or_receipt, $barcode_format, $barcode_tokens);
                $quantity = (isset($parsed_results['W'])) ? (int) $parsed_results['W'] / 1000 : 1;
                $item_id_or_number_or_item_kit_or_receipt = (isset($parsed_results['I'])) ?
                    $parsed_results['I'] : $item_id_or_number_or_item_kit_or_receipt;
                $price = (isset($parsed_results['P'])) ? (double) $parsed_results['P'] : null;
            }
        } else {
            $quantity = 1;
        }
    }

    public function parse(string $string, string $pattern, array $tokens = []): array
    {
        $token_tree = $this->scan($pattern);

        $found_tokens = [];
        foreach ($token_tree as $token_id => $token_length) {
            foreach ($tokens as $token) {
                if ($token->token_id() == $token_id) {
                    $found_tokens[] = $token;
                    $keys = array_keys($token_length);
                    $length = array_shift($keys);
                    $pattern = str_replace(array_shift($token_length), "({$token->get_value()}{" . $length . "})", $pattern);
                }
            }
        }

        $results = [];

        if (preg_match("/$pattern/", $string, $matches)) {
            foreach ($found_tokens as $token) {
                $index = array_search($token, $found_tokens);
                $match = $matches[$index + 1];
                $results[$token->token_id()] = $match;
            }
        }

        return $results;
    }

    /**
     * Interprets one raw scale frame and answers the weight in kilograms.
     *
     * The scale is read by a program on the till, not by the browser, and that program hands us the
     * bytes exactly as they came off the serial port (see
     * docs/Tecnico/venta-por-peso-y-hardware-de-caja.md section 5.4). Everything that differs
     * between one scale and the next lives in configuration, so a new client is a screen to fill
     * in, not a release to cut.
     *
     * Notes on the signature, since the choices are load-bearing:
     *
     * - It returns a **string**, not a float. Quantities in this system are decimal strings handled
     *   with bcmath precisely so a weight is not rounded on its way through a binary float, and the
     *   answer is always fixed at SCALE_DECIMALS so the caller never has to guess the shape.
     * - It returns **null**, never an exception. This runs behind a cashier with a queue in front of
     *   her: a scale that hiccups, a half-read frame, a pattern somebody mistyped in the admin
     *   screen -- none of those may become an error page. Null means "no weight in this frame" and
     *   the caller decides what to do about it.
     * - **$format and $divisor override configuration** instead of being read from it always. That
     *   is what lets the configuration screen show a technician the weight a pattern *would* yield
     *   before it is saved. Omit them and the tenant's saved settings are used, which is the
     *   register's case.
     * - It answers a weight of "0.000" rather than null when the platform is empty. Zero is a
     *   faithful reading; whether a zero weight may be sold is the register's rule, not this one's.
     *
     * A tenant with no scale keys at all -- every tenant that was already running before this
     * existed -- lands on an empty format and gets null. The settings are read with ?? for the same
     * reason.
     *
     * @param string  $raw     Bytes as the scale emitted them.
     * @param ?string $format  Pattern with a {W:n} token, e.g. 'N{W:6}'. Null reads scale_format.
     * @param ?int    $divisor 1 when the frame is in kilograms, 1000 in grams. Null reads
     *                         scale_divisor.
     * @return ?string Kilograms with SCALE_DECIMALS decimals, or null when nothing was recognised.
     */
    public function parse_scale(string $raw, ?string $format = null, ?int $divisor = null): ?string
    {
        $frame = $this->clean_scale_frame($raw);

        if ($frame === '' || strlen($frame) > self::SCALE_MAX_FRAME_LENGTH) {
            return null;
        }

        $settings = ($format === null || $divisor === null) ? config(OSPOS::class)->settings : [];
        $format ??= $settings['scale_format'] ?? '';
        $divisor ??= (int)($settings['scale_divisor'] ?? 1);

        // An empty format is the shipped state and means "no scale here". A divisor of zero or less
        // is a typo that would either divide by zero or silently multiply the weight, and a wrong
        // weight is money: refuse it loudly by returning nothing at all.
        if ($format === '' || $divisor < 1) {
            return null;
        }

        try {
            $parsed = $this->parse($frame, $format, [new Token_scale_weight()]);
        } catch (Throwable $e) {
            // parse() splices the pattern straight into preg_match(), so a pattern that does not
            // compile surfaces as a warning that CodeIgniter's handler turns into an exception.
            // The configuration screen refuses such a pattern on save; this is the second lock, for
            // the row that was already in the database.
            log_message('warning', 'parse_scale: scale_format is not a usable pattern: ' . $e->getMessage());

            return null;
        }

        $captured = $parsed['W'] ?? '';

        // The token's character class allows the point anywhere, so "1.2.3" and "." can come out of
        // a mis-measured pattern. Only a well-formed decimal goes any further.
        if (preg_match('/^(?:\d+(?:\.\d*)?|\.\d+)$/', $captured) !== 1) {
            return null;
        }

        return bcdiv($captured, (string)$divisor, self::SCALE_DECIMALS);
    }

    /**
     * Proposes a pattern and a divisor from a frame the technician captured off the port.
     *
     * This is the difference between a ten-minute installation and an afternoon spent counting
     * characters at the till (see section 5.10 of the design document, "el modo de descubrimiento").
     * It is a starting point and says so on the screen: padding that varies in width between
     * readings is the one thing a single frame cannot reveal, and the fix for it -- '\s+' where the
     * blanks are -- is a hand edit.
     *
     * The longest run of digits and points wins, the rest of the frame is quoted as literal text,
     * and a run without a point is read as grams, which is the only shape in which a scale sends a
     * whole number of units.
     *
     * @param string $raw Bytes as the scale emitted them.
     * @return ?array{format: string, divisor: int} Null when the frame holds no digits at all.
     */
    public function suggest_scale_format(string $raw): ?array
    {
        $frame = $this->clean_scale_frame($raw);

        if ($frame === '' || strlen($frame) > self::SCALE_MAX_FRAME_LENGTH) {
            return null;
        }

        if (!preg_match_all('/[\d.]*\d[\d.]*/', $frame, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $run = '';
        $offset = 0;

        foreach ($matches[0] as $candidate) {
            if (strlen($candidate[0]) > strlen($run)) {
                $run = $candidate[0];
                $offset = $candidate[1];
            }
        }

        // preg_quote() escapes the braces and the colon too, so a frame that happens to contain
        // "{W:5}" cannot smuggle a second token into the suggestion.
        $prefix = preg_quote(substr($frame, 0, $offset), '/');
        $suffix = preg_quote(substr($frame, $offset + strlen($run)), '/');

        return [
            'format'  => $prefix . '{W:' . strlen($run) . '}' . $suffix,
            'divisor' => str_contains($run, '.') ? 1 : 1000
        ];
    }

    /**
     * Whether a pattern can be used to read a scale at all.
     *
     * Two ways to fail, and the screen has to catch both before the pattern reaches a cashier: a
     * pattern with no weight token can never yield a weight, and a pattern that does not compile
     * takes preg_match() down with it. Compiling it here is the honest test -- the same parse() the
     * register will call, on a subject that cannot match.
     *
     * An empty format is not "invalid", it is "no scale configured", and the caller distinguishes
     * the two: this answers false for it because there is nothing to compile.
     */
    public function is_valid_scale_format(string $format): bool
    {
        if (!isset($this->scan($format)['W'])) {
            return false;
        }

        try {
            $this->parse('', $format, [new Token_scale_weight()]);
        } catch (Throwable $e) {
            return false;
        }

        return true;
    }

    /**
     * Strips the transport out of a frame, leaving the data.
     *
     * Serial framing bytes -- STX, ETX, CR, LF -- and the blank padding around them say where a
     * reading begins and ends, not what it weighs, and they are the part that differs between a
     * frame read live off the port and the same frame pasted into a text box by a technician.
     * Removing them is what makes the preview on the configuration screen tell the truth about what
     * the register will do with the same bytes.
     */
    private function clean_scale_frame(string $raw): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]/', '', $raw));
    }

    private function generate(array $used_tokens, array $tokens, array &$tokens_to_replace, array &$token_values, bool $save = true): void
    {
        foreach ($used_tokens as $token_code => $token_info) {
            $token_value = $this->resolve_token($token_code, $tokens, $save);

            foreach ($token_info as $length => $token_spec) {
                $tokens_to_replace[] = $token_spec;
                if (!empty($length)) {
                    $token_values[] = str_pad($token_value, $length, '0', STR_PAD_LEFT);
                } else {
                    $token_values[] = $token_value;
                }
            }
        }
    }

    private function resolve_token($token_code, array $tokens = [], bool $save = true): string
    {
        foreach (array_merge($tokens, Token::get_tokens()) as $token) {
            if ($token->token_id() == $token_code) {
                return $token->get_value($save);
            }
        }

        return '';
    }
}