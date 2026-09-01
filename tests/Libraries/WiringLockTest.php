<?php

declare(strict_types=1);

namespace Tests\Libraries;

use App\Libraries\Wiring_lock;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The rule behind D12's three locked keys, on its own and with no database under it.
 *
 * WRITTEN, NOT RUN: certification happens against staging, and not by whoever wrote the code.
 *
 * @internal
 */
final class WiringLockTest extends CIUnitTestCase
{
    // ========== Which keys are wiring and which are the business's own ==========

    public function testTheThreeKeysOfD12AreLocked(): void
    {
        $this->assertTrue(Wiring_lock::is_locked('quantity_decimals'));
        $this->assertTrue(Wiring_lock::is_locked('barcode_content'));
        $this->assertTrue(Wiring_lock::is_locked('language_code'));
    }

    public function testTheRequiredValuesAreTheOnesD12Writes(): void
    {
        $this->assertSame('3', Wiring_lock::required_value('quantity_decimals'));
        $this->assertSame('item_number', Wiring_lock::required_value('barcode_content'));
        $this->assertSame('es-MX', Wiring_lock::required_value('language_code'));
    }

    public function testNoOtherSettingOnThoseTwoScreensIsLocked(): void
    {
        $theirs = [
            'currency_decimals',
            'tax_decimals',
            'cash_decimals',
            'number_locale',
            'timezone',
            'country_codes',
            'language',
            'barcode_type',
            'barcode_width',
            'barcode_generate_if_empty',
        ];

        foreach ($theirs as $key) {
            $this->assertFalse(Wiring_lock::is_locked($key), $key . ' is the business own setting');
        }
    }

    // ========== What gets refused ==========

    public function testARequestCarryingNoneOfThemIsRefusedNothing(): void
    {
        $this->assertSame([], Wiring_lock::refused([], []));
    }

    public function testAKeyTheScreenDidNotSubmitIsNotAChange(): void
    {
        // A disabled field is not submitted at all. If "absent" counted as a change, every honest
        // save of these two screens would be refused.
        $this->assertSame([], Wiring_lock::refused([], ['quantity_decimals' => '3']));
    }

    public function testResendingTheSameValueIsNotAChange(): void
    {
        $this->assertSame(
            [],
            Wiring_lock::refused(['quantity_decimals' => '3'], ['quantity_decimals' => '3'])
        );
    }

    public function testDroppingTheDecimalsThatCarryTheWeightIsRefused(): void
    {
        $this->assertSame(
            ['quantity_decimals'],
            Wiring_lock::refused(['quantity_decimals' => '0'], ['quantity_decimals' => '3'])
        );
    }

    public function testPuttingTheInternalIdBackIntoTheBarcodeIsRefused(): void
    {
        $this->assertSame(
            ['barcode_content'],
            Wiring_lock::refused(['barcode_content' => 'id'], ['barcode_content' => 'item_number'])
        );
    }

    public function testAnotherSpanishVariantIsRefused(): void
    {
        $this->assertSame(
            ['language_code'],
            Wiring_lock::refused(['language_code' => 'es-ES'], ['language_code' => 'es-MX'])
        );
    }

    public function testEnglishIsRefusedTheSameWay(): void
    {
        $this->assertSame(
            ['language_code'],
            Wiring_lock::refused(['language_code' => 'en'], ['language_code' => 'es-MX'])
        );
    }

    public function testAllThreeAreReportedAtOnce(): void
    {
        $refused = Wiring_lock::refused(
            [
                'barcode_content'   => 'id',
                'language_code'     => 'es-ES',
                'quantity_decimals' => '0',
            ],
            [
                'barcode_content'   => 'item_number',
                'language_code'     => 'es-MX',
                'quantity_decimals' => '3',
            ]
        );

        $this->assertSame(['barcode_content', 'language_code', 'quantity_decimals'], $refused);
    }

    public function testAKeyThatIsNotLockedIsNeverRefused(): void
    {
        $this->assertSame(
            [],
            Wiring_lock::refused(['currency_decimals' => '2'], ['currency_decimals' => '0'])
        );
    }

    public function testNumbersAndStringsAreTheSameValue(): void
    {
        // quantity_decimals arrives from the request as a string and is stored as one, but nothing
        // stops a caller handing over the integer it just cast.
        $this->assertSame(
            [],
            Wiring_lock::refused(['quantity_decimals' => 3], ['quantity_decimals' => '3'])
        );
    }

    public function testANullIsTreatedAsAKeyThatWasNotSent(): void
    {
        $this->assertSame(
            [],
            Wiring_lock::refused(['quantity_decimals' => null], ['quantity_decimals' => '3'])
        );
    }

    // ========== The safety valve for a business that was never wired right ==========

    public function testMovingToTheRequiredValueIsAllowed(): void
    {
        // A business provisioned before the profile existed sits on the seed's 0. Refusing this
        // move too would trap it there: the lock would cement the very value it exists to prevent.
        $this->assertSame(
            [],
            Wiring_lock::refused(['quantity_decimals' => '3'], ['quantity_decimals' => '0'])
        );
    }

    public function testAMiswiredBusinessStillCannotPickAnythingElse(): void
    {
        $this->assertSame(
            ['quantity_decimals'],
            Wiring_lock::refused(['quantity_decimals' => '2'], ['quantity_decimals' => '0'])
        );
    }

    public function testAMissingCurrentValueIsNotAnExcuseToChangeIt(): void
    {
        $this->assertSame(
            ['barcode_content'],
            Wiring_lock::refused(['barcode_content' => 'id'], [])
        );
    }

    // ========== Telling the business which setting was refused ==========

    public function testTheLanguageIsNamedAfterTheFieldTheBusinessActuallySees(): void
    {
        // There is no "language code" box on the screen: the code is derived from the language
        // select, and that is the name a refusal has to use.
        $this->assertSame(lang('Config.language'), Wiring_lock::label('language_code'));
    }

    public function testEveryLockedKeyHasAName(): void
    {
        foreach (array_keys(Wiring_lock::WIRED_VALUES) as $key) {
            $label = Wiring_lock::label($key);

            $this->assertNotSame('', $label, $key);
            $this->assertStringNotContainsString('Config.', $label, $key . ' has no translation');
        }
    }

    // ========== Whether a business is already where it should be ==========

    public function testABusinessOnTheRequiredValueMatches(): void
    {
        $this->assertTrue(Wiring_lock::matches_wiring('quantity_decimals', '3'));
        $this->assertTrue(Wiring_lock::matches_wiring('barcode_content', 'item_number'));
        $this->assertTrue(Wiring_lock::matches_wiring('language_code', 'es-MX'));
    }

    public function testTheSeedValuesDoNotMatch(): void
    {
        // What initial_schema.sql leaves behind, which is what a business provisioned before the
        // profile existed is still carrying.
        $this->assertFalse(Wiring_lock::matches_wiring('quantity_decimals', '0'));
        $this->assertFalse(Wiring_lock::matches_wiring('barcode_content', 'id'));
        $this->assertFalse(Wiring_lock::matches_wiring('language_code', 'en'));
    }

    public function testTheOtherSpanishThatCausedTheIncidentDoesNotMatch(): void
    {
        $this->assertFalse(Wiring_lock::matches_wiring('language_code', 'es-ES'));
    }

    public function testAKeyThatIsNotLockedAlwaysMatches(): void
    {
        $this->assertTrue(Wiring_lock::matches_wiring('currency_decimals', 'anything'));
    }

    /**
     * `number` es el valor histórico de esta opción y `Barcode_lib` lo lee exactamente igual que
     * `item_number`: cualquier cosa que no sea `id` significa número de artículo. Sin esta
     * equivalencia, un negocio con el valor viejo arrastraría una alarma roja permanente sobre un
     * ajuste que ya es el correcto -- y con los radios deshabilitados no tendría forma de quitarla.
     */
    public function testTheLegacyBarcodeValueCountsAsTheRequiredOne(): void
    {
        $this->assertTrue(Wiring_lock::matches_wiring('barcode_content', 'number'));
    }

    /**
     * La equivalencia es de una sola clave y un solo valor. Que `number` pase en `barcode_content`
     * no puede abrirle la puerta a nada más.
     */
    public function testTheLegacyValueDoesNotLeakIntoTheOtherLockedKeys(): void
    {
        $this->assertFalse(Wiring_lock::matches_wiring('language_code', 'number'));
        $this->assertFalse(Wiring_lock::matches_wiring('quantity_decimals', 'number'));
        $this->assertFalse(Wiring_lock::matches_wiring('barcode_content', 'id'));
    }
}
