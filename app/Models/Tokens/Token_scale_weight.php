<?php

namespace App\Models\Tokens;

/**
 * Weight token for scale frames.
 *
 * A separate class from Token_barcode_weight on purpose. That one answers '\d', which is correct
 * for a barcode -- a printed weight barcode carries no decimal point, the divisor puts it back --
 * and it is what parse_barcode() feeds on today. Widening it would change how every already
 * configured barcode format matches, which is exactly what must not happen.
 *
 * A scale frame is the opposite case: the point travels inside the frame. The Moresco format
 * documented in docs/Tecnico/venta-por-peso-y-hardware-de-caja.md section 5.10b emits
 * "N12.395<LF><CR>", so a pattern of 'N{W:6}' has to capture "12.395", point included.
 *
 * Splitting it into 'N{W:2}\.{W:3}' is not an option either: Token_lib::parse() indexes the token
 * tree by token id and array_shifts a single length out of it, so the second group is dropped and
 * its '{W:3}' is left in the pattern as literal text that never matches. Verified against the real
 * parse() in tests/Libraries/ScaleParseTest.php.
 *
 * The class stays deliberately narrow: digits and the decimal point, nothing else. Sign characters
 * and the blank padding some protocols put in front of the number are matched by the configured
 * pattern around the token (for example 'ST,GS,\+  {W:5}kg'), not swallowed by it, so what the
 * token captures is always a number and never needs cleaning up afterwards.
 */
class Token_scale_weight extends Token
{
    /**
     * Same id as the barcode weight token. The two never meet -- each reader passes its own token
     * set to parse() -- and keeping 'W' means one weight placeholder to document and to teach.
     *
     * @return string
     */
    public function token_id(): string
    {
        return 'W';
    }

    /**
     * Spliced into a regex by parse() as "([\d.]{n})". The point needs no escaping inside a
     * character class.
     *
     * @return string
     */
    public function get_value(): string
    {
        return '[\d.]';
    }
}
