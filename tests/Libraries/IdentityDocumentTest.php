<?php

declare(strict_types=1);

namespace Tests\Libraries;

use App\Libraries\Identity_document;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The rules of a person's identity document, in one place (docs/Tecnico/documento-de-identidad.md IT4):
 * the DIAN list of types, how a number is cleaned, the NIT check digit and how a document is printed.
 *
 * Pure: no database.
 *
 * @internal
 */
final class IdentityDocumentTest extends CIUnitTestCase
{
    public function testTheTypesAreTheDianList(): void
    {
        $this->assertSame(['CC', 'CE', 'TI', 'RC', 'NIT', 'PA', 'PPT', 'PEP', 'DIE', 'NUIP'], array_keys(Identity_document::TYPES));
    }

    public function testEachTypeCarriesItsDianCode(): void
    {
        $this->assertSame('13', Identity_document::dian_code('CC'));
        $this->assertSame('22', Identity_document::dian_code('CE'));
        $this->assertSame('12', Identity_document::dian_code('TI'));
        $this->assertSame('11', Identity_document::dian_code('RC'));
        $this->assertSame('31', Identity_document::dian_code('NIT'));
        $this->assertSame('41', Identity_document::dian_code('PA'));
        $this->assertSame('48', Identity_document::dian_code('PPT'));
        $this->assertSame('47', Identity_document::dian_code('PEP'));
        $this->assertSame('42', Identity_document::dian_code('DIE'));
        $this->assertSame('91', Identity_document::dian_code('NUIP'));
        $this->assertNull(Identity_document::dian_code('XX'));
    }

    public function testOnlyTheListedCodesAreTypes(): void
    {
        $this->assertTrue(Identity_document::is_type('CC'));
        $this->assertTrue(Identity_document::is_type('NUIP'));
        $this->assertFalse(Identity_document::is_type('cc'), 'The code is stored as listed; the select posts it that way.');
        $this->assertFalse(Identity_document::is_type(''));
        $this->assertFalse(Identity_document::is_type(null));
        $this->assertFalse(Identity_document::is_type('<script>'));
    }

    public function testEveryTypeHasALabelInTheLanguageFile(): void
    {
        foreach (array_keys(Identity_document::TYPES) as $type) {
            $label = Identity_document::label($type);

            $this->assertNotSame('', $label);
            $this->assertStringNotContainsString('Common.', $label, "{$type} has no label in the language file.");
        }
    }

    public function testTheSelectOptionsStartEmptyAndListEveryType(): void
    {
        $options = Identity_document::options();

        $this->assertSame('', array_key_first($options));
        $this->assertSame(array_merge([''], array_keys(Identity_document::TYPES)), array_map('strval', array_keys($options)));
        $this->assertStringStartsWith('CC ', $options['CC']);
    }

    public function testANumericTypeLosesTheDotsCommasSpacesAndHyphensTypedOutOfHabit(): void
    {
        $this->assertSame('1020345678', Identity_document::normalize('CC', '1.020.345.678'));
        $this->assertSame('1020345678', Identity_document::normalize('CC', ' 1,020 345-678 '));
        $this->assertSame('1020345678', Identity_document::normalize('NUIP', '1020345678'));
    }

    public function testAnAlphanumericTypeIsUppercasedAndKeepsItsLetters(): void
    {
        $this->assertSame('AB123456', Identity_document::normalize('PA', 'ab 123-456'));
        $this->assertSame('X12345', Identity_document::normalize('PPT', 'x12345'));
    }

    public function testANitTypedWithItsCheckDigitIsStoredWithoutIt(): void
    {
        $this->assertSame('900123456', Identity_document::normalize('NIT', '900.123.456-8'));
        $this->assertSame('900123456', Identity_document::normalize('NIT', '900123456'));
    }

    public function testTheNitCheckDigitMatchesKnownNits(): void
    {
        // Real NITs with their published check digit: DIAN, Bancolombia, Ecopetrol, Exito, Davivienda.
        $this->assertSame(4, Identity_document::nit_check_digit('800197268'));
        $this->assertSame(8, Identity_document::nit_check_digit('890903938'));
        $this->assertSame(1, Identity_document::nit_check_digit('899999068'));
        $this->assertSame(9, Identity_document::nit_check_digit('890900608'));
        $this->assertSame(7, Identity_document::nit_check_digit('860034313'));
    }

    public function testARemainderOfZeroOrOneIsTheCheckDigitItself(): void
    {
        $this->assertSame(0, Identity_document::nit_check_digit('900000009'));
        $this->assertSame(1, Identity_document::nit_check_digit('900000002'));
    }

    public function testAValidDocumentHasNoError(): void
    {
        $this->assertNull(Identity_document::validate('CC', '1.020.345.678'));
        $this->assertNull(Identity_document::validate('PA', 'AB123456'));
        $this->assertNull(Identity_document::validate('NIT', '800197268'));
        $this->assertNull(Identity_document::validate('NIT', '800197268-4'));
    }

    public function testANumericTypeWithLettersIsRefused(): void
    {
        $this->assertSame('Common.document_number_invalid', Identity_document::validate('CC', '10203A5678'));
    }

    public function testAnAlphanumericTypeWithSymbolsIsRefused(): void
    {
        $this->assertSame('Common.document_number_invalid', Identity_document::validate('PA', 'AB12<34'));
    }

    public function testANitTypedWithAWrongCheckDigitIsRefused(): void
    {
        $this->assertSame('Common.document_nit_check_digit_wrong', Identity_document::validate('NIT', '800197268-5'));
    }

    public function testAnUnknownTypeIsRefused(): void
    {
        $this->assertSame('Common.document_type_invalid', Identity_document::validate('XX', '123'));
    }

    public function testAnEmptyNumberIsRefused(): void
    {
        $this->assertSame('Common.document_number_required', Identity_document::validate('CC', ' . - '));
    }

    public function testANumberLongerThanTheColumnIsRefused(): void
    {
        $this->assertSame('Common.document_number_invalid', Identity_document::validate('CC', str_repeat('1', Identity_document::MAX_LENGTH + 1)));
    }

    public function testTheFormatIsTypeThenNumber(): void
    {
        $this->assertSame('CC 1020345678', Identity_document::format('CC', '1020345678'));
        $this->assertSame('PA AB123456', Identity_document::format('PA', 'AB123456'));
    }

    public function testANitIsPrintedWithItsCheckDigit(): void
    {
        $this->assertSame('NIT 800197268-4', Identity_document::format('NIT', '800197268'));
    }

    public function testAnOldNumberWithoutTypeIsPrintedAlone(): void
    {
        $this->assertSame('900.123.456-8', Identity_document::format(null, '900.123.456-8'));
        $this->assertSame('123', Identity_document::format('', '123'));
    }

    public function testNoNumberPrintsNothing(): void
    {
        $this->assertSame('', Identity_document::format('CC', null));
        $this->assertSame('', Identity_document::format('CC', '  '));
        $this->assertSame('', Identity_document::format(null, null));
    }

    public function testTheSearchKeyIgnoresTheSameSeparatorsTheNumberLoses(): void
    {
        $this->assertSame('1020345678', Identity_document::search_key('1.020.345.678'));
        $this->assertSame('AB123', Identity_document::search_key('ab 123'));
        $this->assertSame('', Identity_document::search_key(' .-, '));
    }
}
