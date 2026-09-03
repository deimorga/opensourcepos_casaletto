<?php

namespace Tests\Libraries;

use App\Libraries\Sale_lib;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The paper a receipt is printed on.
 *
 * @internal
 */
final class ReceiptPaperTest extends CIUnitTestCase
{
    /**
     * The shipped value has to mean "change nothing". A till that already prints correctly must not
     * start printing differently on the day this feature migrates.
     */
    public function testNoPaperConfiguredMeansPrintExactlyAsBefore(): void
    {
        $this->assertNull(Sale_lib::receipt_printable_width_mm(''));
    }

    /**
     * A roll is sold by a width it cannot print on. Laying the receipt out at the nominal width is
     * how the right-hand column -- the totals -- ends up off the edge of the paper.
     */
    public function testARollPrintsNarrowerThanItIsSold(): void
    {
        $this->assertSame(48, Sale_lib::receipt_printable_width_mm('58mm'));
        $this->assertSame(72, Sale_lib::receipt_printable_width_mm('80mm'));
    }

    public function testAnUnknownPaperFallsBackToChangingNothing(): void
    {
        $this->assertNull(Sale_lib::receipt_printable_width_mm('A4'));
        $this->assertNull(Sale_lib::receipt_printable_width_mm('58'));
    }

    public function testOnlyTheThreeKnownPapersAreAccepted(): void
    {
        $this->assertTrue(Sale_lib::isValidReceiptPaper(''));
        $this->assertTrue(Sale_lib::isValidReceiptPaper('58mm'));
        $this->assertTrue(Sale_lib::isValidReceiptPaper('80mm'));
        $this->assertFalse(Sale_lib::isValidReceiptPaper('carta'));
        $this->assertFalse(Sale_lib::isValidReceiptPaper('58 mm'));
    }

    public function testTheDropdownOffersEveryAcceptedPaper(): void
    {
        $opciones = Sale_lib::get_receipt_paper_options();

        foreach (Sale_lib::RECEIPT_PAPERS as $papel) {
            $this->assertArrayHasKey($papel, $opciones);
            $this->assertNotSame('', trim($opciones[$papel]), "la opción $papel no tiene etiqueta");
        }
    }
}
