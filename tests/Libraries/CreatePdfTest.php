<?php

declare(strict_types=1);

namespace Tests\Libraries;

use CodeIgniter\Test\CIUnitTestCase;
use Dompdf\Helpers as DompdfHelpers;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * create_pdf() -- app/Helpers/dompdf_helper.php -- had no test at all. It is the only thing in the
 * application that turns HTML into a PDF, it is reached from exactly one place
 * (Sales::getSendPdf(), which emails the customer a PDF of the sale), and `dompdf/dompdf` is about
 * to go from 2.0.8 to 3.1.6: a major version, six open advisories. This file is the net under that
 * upgrade.
 *
 * getSendPdf() renders "sales/{$type}_email", and $type arrives as 'receipt', 'quote' or
 * 'work_order' -- from receipt.php, quote.php and work_order.php -- or not at all, from invoice.php,
 * tax_invoice.php and form.php, which all fall through to the 'invoice' default. So all four of
 * app/Views/sales/*_email.php go through this helper, and there is no tax_invoice_email.php to test
 * because the tax invoice screen asks for the plain invoice.
 *
 * WHY REPRESENTATIVE HTML AND NOT THE REAL VIEWS
 * ----------------------------------------------
 * Rendering app/Views/sales/*_email.php for real needs a populated `$config` (which comes from the
 * `app_config` table), a logo file on disk under public/uploads, to_currency()/to_quantity_decimals()
 * with their locale configuration, the language files, and some fifteen fabricated keys per view --
 * cart lines, taxes, payments. That turns a pure unit test into a database test, and the shared test
 * schema is exactly where this repository has been bitten before: a test that writes app_config
 * breaks tests in other files.
 *
 * What is actually under test here is not the views. It is dompdf's handling of the four HTML
 * constructs the views emit, because that is what a major-version upgrade can change. So the
 * fixtures below reproduce those constructs verbatim -- a `data:` URI logo, an SVG barcode as a
 * `data:` URI, a relative `uploads/...` src, and percentage table widths -- and
 * testTheFixturesStillMatchTheShippedTemplates() greps the real views to prove the fixtures have not
 * drifted away from them. If a view changes shape, that test fails and these fixtures get updated.
 *
 * WHAT WAS MEASURED WRITING THIS (dompdf 2.0.8, the version in composer.lock today)
 * --------------------------------------------------------------------------------
 *   - An empty document renders to 919 bytes. The invoice shape renders to ~2.9 KB, the receipt
 *     shape to ~3.0 KB, the quote and work-order shapes to ~2.1 KB. Hence the threshold used below:
 *     at least 500 bytes more than an empty document, with the empty document rendered by the test
 *     itself rather than hard-coded, so the floor follows dompdf instead of going stale.
 *   - The `data:` URI logo IS embedded: the PDF gains an `/XObject` with `/Subtype /Image`.
 *   - The relative `uploads/{company_logo}` of the quote and the work order is NOT. dompdf's default
 *     chroot is the dompdf package directory itself, so no file belonging to this application can
 *     ever be read, and the PDF gets dompdf's broken-image placeholder instead of the logo. That is
 *     today's behaviour on 2.0.8, not something 3.x will introduce -- these two PDFs have never
 *     carried the company logo.
 *   - The `<link rel="stylesheet" href="<?= base_url('css/invoice_email.css') ?>">` that the invoice,
 *     the quote and the work order carry is refused too, because it is an absolute http(s) URL and
 *     `isRemoteEnabled` is false. These PDFs are laid out by their inline styles only.
 *     receipt_email.php does not even link it: unlike the other three it is not a document at all,
 *     just a bare <div>, with no doctype and no <head>. Its fixture is a fragment for that reason.
 *
 * The two options the helper passes -- isRemoteEnabled: false, isPhpEnabled: false -- are the
 * mitigation for the SSRF and RCE advisories. testARemoteImageIsNotFetched() and
 * testInlinePhpIsNotExecuted() are there so the upgrade cannot quietly drop them.
 *
 * The stream() branch of create_pdf() (called when $filename is non-empty) is deliberately not
 * exercised: nothing in the application passes a second argument, and stream() sends HTTP headers
 * and echoes the document, which phpunit.xml.dist's beStrictAboutOutputDuringTests would flag.
 *
 * @internal
 */
final class CreatePdfTest extends CIUnitTestCase
{
    /**
     * A real 16x16 JFIF stream, 675 bytes. Small enough to sit in a test file, and a genuine JPEG
     * that getimagesize() identifies and that Cpdf embeds with DCTDecode.
     *
     * JPEG and not PNG on purpose: dompdf's PNG path (Cpdf::addPngFromFile) throws outright when
     * ext-gd is missing, and the GitHub Actions job installs only intl, mbstring and mysqli, while
     * composer.json requires only ext-intl. The JPEG path says so in its own comment -- "using no GD
     * commands" -- so it is the one construct that behaves the same in CI and on the server. A
     * company logo uploaded as a PNG takes the other path; production's image does install gd.
     */
    private const LOGO_JPEG_BASE64 = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDACgcHiMeGSgjISMtKygwPGRBPDc3PHtYXUlkkYCZlo+AjIqg'
        . 'tObDoKrarYqMyP/L2u71////m8H////6/+b9//j/2wBDASstLTw1PHZBQXb4pYyl+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4'
        . '+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj4+Pj/wAARCAAQABADASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAA'
        . 'AAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2Jy'
        . 'ggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZ'
        . 'mqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEB'
        . 'AQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHB'
        . 'CSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaH'
        . 'iImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oA'
        . 'DAMBAAIRAxEAPwAtbX7Tv+fbtx2zRdWv2bZ8+7dntii1uvs2/wCTdux3xRdXX2nZ8m3bnvms9LHX73N5H//Z';

    /**
     * Diagnostics raised by dompdf while a fixture rendered. Collected rather than let through, and
     * that is not tidiness: dompdf 2.0.8 destructures getimagesize()'s `false` when it probes an SVG
     * -- `[$width, $height, $type] = getimagesize($filename)` in Helpers::dompdf_getimagesize() --
     * which raises E_WARNING "Cannot use bool as array" for every SVG on every PHP up to 8.4.
     * phpunit.xml.dist sets failOnWarning="true", so without this the barcode tests would fail in CI
     * on upstream's noise rather than on anything about our helper.
     * testNothingOfOursIsHiddenByTheScopedErrorHandler() keeps the collection from hiding ours.
     *
     * @var list<array{level: int, message: string, file: string}>
     */
    private array $diagnostics = [];

    /**
     * Anything printed while the last fixture rendered. Captured rather than let through so that a
     * dompdf that starts writing to standard output produces a named failure instead of
     * phpunit.xml.dist's beStrictAboutOutputDuringTests marking the whole test risky.
     */
    private string $printedOutput = '';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        helper('dompdf');

        // dompdf records its own refusals in this global. backupGlobals is off in
        // phpunit.xml.dist, so it is reset here and removed again in tearDown rather than left
        // behind for whatever test file runs next.
        $GLOBALS['_dompdf_warnings'] = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];

        unset($GLOBALS['_dompdf_warnings'], $GLOBALS['OSPOS_PDF_RCE_CANARY']);

        parent::tearDown();
    }

    // ========== The four templates, end to end ==========

    /**
     * @return array<string, array{0: string}>
     */
    public static function templateShapeProvider(): array
    {
        return [
            'factura (logo data: + código de barras SVG)' => ['invoice'],
            'recibo (SVG + anchos de tabla en %)'         => ['receipt'],
            'cotización (uploads/ relativo)'              => ['quote'],
            'orden de trabajo (uploads%2F relativo)'      => ['work_order'],
        ];
    }

    /**
     * The floor every one of the four has to clear. "It did not throw" is not evidence: a dompdf
     * that silently dropped the whole body would still hand back a syntactically valid 919-byte
     * document with a %PDF- header on it.
     */
    #[DataProvider('templateShapeProvider')]
    public function testEveryTemplateShapeProducesAPdfWithRealContentInIt(string $shape): void
    {
        $pdf   = $this->renderPdf($this->fixture($shape));
        $empty = $this->renderPdf('<!doctype html><html><head><meta charset="utf-8"></head><body></body></html>');

        $this->assertStringStartsWith('%PDF-', substr($pdf, 0, 8), "$shape no devolvió un PDF");
        $this->assertTrue(str_contains($pdf, '%%EOF'), "el PDF de $shape está truncado: no termina en %%EOF");
        $this->assertGreaterThan(
            strlen($empty) + 500,
            strlen($pdf),
            "el PDF de $shape (" . strlen($pdf) . ' B) no pesa medio kilobyte más que un documento vacío ('
            . strlen($empty) . ' B): la plantilla no llegó a la página'
        );
    }

    // ========== The logo: the data: URI works, the relative path does not ==========

    /**
     * The invoice is the only one of the three PDF templates whose logo actually arrives, because
     * it inlines the file as a `data:` URI instead of asking dompdf to open it.
     */
    public function testTheInvoiceTemplateEmbedsTheDataUriLogoAsAnImageObject(): void
    {
        $pdf = $this->renderPdf($this->fixture('invoice'));

        $this->assertTrue(
            str_contains($pdf, '/Subtype /Image'),
            'la factura no lleva ningún objeto de imagen: el logo en data: URI no se incrustó'
        );
        $this->assertTrue(
            str_contains($pdf, 'DCTDecode'),
            'el JPEG no se incrustó como flujo DCTDecode; dompdf lo habrá recodificado o descartado'
        );
    }

    /**
     * Same logo, plus the percentage column widths the receipt lays its items table out with. Kept
     * as its own test because a table whose widths are percentages is a different reflow path, and
     * it is the one that decides whether the totals column lands on the paper.
     */
    public function testTheReceiptTemplateWithPercentageColumnWidthsStillEmbedsTheLogo(): void
    {
        $pdf = $this->renderPdf($this->fixture('receipt'));

        $this->assertTrue(
            str_contains($pdf, '/Subtype /Image'),
            'el recibo perdió el logo al maquetar la tabla con anchos en porcentaje'
        );
        $this->assertTrue(str_contains($this->contentStreams($pdf), 'Subtotal'), 'la tabla del recibo no se dibujó');
    }

    /**
     * NOT a wish: this is what 2.0.8 does today. app/Views/sales/quote_email.php writes
     * `src="uploads/{company_logo}"`, and dompdf refuses every local file outside its own package
     * directory, which is where its default chroot points. The quote PDF the customer receives has
     * dompdf's broken-image placeholder where the logo should be.
     *
     * The assertion is deliberately structural -- no image object in the document -- and not a match
     * on dompdf's placeholder wording, which a major version is free to reword. If 3.1.6 starts
     * resolving the path, this test fails and somebody gets to decide whether that is the fix or a
     * new way to read files off the server.
     */
    public function testTheQuoteTemplateRelativeLogoNeverReachesThePdf(): void
    {
        $pdf = $this->renderPdf($this->fixture('quote'));

        $this->assertStringStartsWith('%PDF-', substr($pdf, 0, 8));
        $this->assertFalse(
            str_contains($pdf, '/Subtype /Image'),
            'la cotización incrustó una imagen: la resolución de rutas locales cambió, revísalo'
        );
    }

    /**
     * The work order is worse than the quote and for a second reason. quote_email.php escapes only
     * the file name -- `'uploads/' . esc($config['company_logo'], 'url')` -- but
     * work_order_email.php escapes the whole path: `esc('uploads/' . $config['company_logo'],
     * 'url')`. esc(..., 'url') is rawurlencode(), so the separator becomes %2F and the src is a
     * single file name with an encoded slash in it, `uploads%2Fcasaletto.png`, not a path at all.
     */
    public function testTheWorkOrderTemplateUrlEncodesTheWholePathAndAlsoLosesTheLogo(): void
    {
        $pdf = $this->renderPdf($this->fixture('work_order'));

        $this->assertStringStartsWith('%PDF-', substr($pdf, 0, 8));
        $this->assertFalse(
            str_contains($pdf, '/Subtype /Image'),
            'la orden de trabajo incrustó una imagen pese al %2F en la ruta'
        );
    }

    /**
     * Separates the two reasons a local file can fail, so the upgrade can be read properly. The
     * relative paths above can fail merely because they cannot be resolved against an empty base
     * path. This one hands dompdf an absolute path to a file that exists and is readable, and it is
     * still refused -- by the chroot, which defaults to the dompdf package directory. That is the
     * mechanism the two templates are up against, and the one dompdf 3.x tightens further.
     */
    public function testAnExistingLocalFileIsRefusedBecauseTheChrootIsTheDompdfPackageDirectory(): void
    {
        $logo = $this->writeTempFile('logo_chroot', 'jpg', base64_decode(self::LOGO_JPEG_BASE64, true));

        $this->assertFileIsReadable($logo, 'el fichero de prueba no se pudo crear: la prueba no probaría nada');

        $pdf = $this->renderPdf($this->document(
            '<table id="info"><tr><td id="logo"><img id="image" src="' . $logo . '" alt="company_logo"></td>'
            . '<td id="customer-title">Casa Letto</td></tr></table>'
        ));

        $this->assertFalse(
            str_contains($pdf, '/Subtype /Image'),
            'dompdf leyó un fichero local fuera de su chroot; la restricción dejó de aplicarse'
        );
    }

    // ========== The SVG barcode ==========

    /**
     * That the barcode is drawn, and not merely that the page came out. An SVG is not embedded as a
     * raster object -- dompdf converts it to vector operators -- so there is no `/Subtype /Image` to
     * look for. What there is: more bars means more operators means a bigger document. A barcode
     * that dompdf dropped produces the same bytes whether it has three bars or sixty.
     *
     * THE SKIP: dompdf 2.0.8 works out an image's type from getimagesize(), and only falls back to
     * sniffing for "<svg" when getimagesize() returns false. PHP 8.5 taught getimagesize() to read
     * SVG, so it now returns dimensions plus IMAGETYPE_SVG -- which 2.0.8's type map does not
     * contain -- and the fallback never runs. On PHP 8.5 this version of dompdf cannot render an SVG
     * at all; every barcode on an emailed invoice comes out as "Image not found or type unknown".
     * CI (8.2, 8.3, 8.4) and the server's image (8.4) are unaffected, which is where this assertion
     * runs. Delete the guard after the 3.1.6 upgrade and check that it passes: if it does, that is
     * the upgrade fixing a bug we did not know we had.
     *
     * The assertion itself was checked on the pre-8.5 path before being committed -- the same
     * barcode with its width/height dropped so getimagesize() misses it, which is the path every PHP
     * up to 8.4 takes: 3 bars rendered to 1645 bytes and 60 bars to 1843.
     */
    public function testTheSvgBarcodeIsDrawnOntoThePage(): void
    {
        if (! $this->dompdfCanDetectSvg()) {
            $this->markTestSkipped(
                'Esta combinación de PHP y dompdf no reconoce un SVG (getimagesize() ya lo entiende y el '
                . 'mapa de tipos de dompdf 2.x no): el código de barras no se dibuja en ningún caso.'
            );
        }

        $few  = $this->renderPdf($this->document($this->barcodeMarkup($this->barcodeSvg(3))));
        $many = $this->renderPdf($this->document($this->barcodeMarkup($this->barcodeSvg(60))));

        $this->assertGreaterThan(
            strlen($few),
            strlen($many),
            'un código de barras de 60 barras pesa lo mismo que uno de 3: el SVG no llegó a la página'
        );
    }

    /**
     * A `data:` URI is local data, not a fetch. With isRemoteEnabled false it would be easy for a
     * stricter version to lump the two together and start refusing the barcode as if it were a
     * remote resource; this pins that it does not.
     */
    public function testTheBarcodeDataUriIsNotTreatedAsARemoteResource(): void
    {
        $pdf = $this->renderPdf($this->document($this->barcodeMarkup($this->barcodeSvg(24))));

        $this->assertStringStartsWith('%PDF-', substr($pdf, 0, 8));

        foreach ($this->dompdfWarnings() as $warning) {
            if (! str_contains($warning, 'data:image/svg+xml')) {
                continue;
            }

            $this->assertFalse(
                stripos($warning, 'remote') !== false,
                "dompdf rechazó el código de barras como recurso remoto: $warning"
            );
        }
    }

    // ========== The two options that mitigate the open advisories ==========

    /**
     * isRemoteEnabled: false. The SSRF half of the mitigation. The address is one nothing listens on,
     * so nothing leaves the build even if the refusal stops working.
     *
     * Which is exactly why "no image in the document" is not the assertion. It was, at first, and it
     * was worthless: with isRemoteEnabled turned back on, dompdf does reach for the URL, the
     * connection is refused, and the document comes out with no image in it either. The two cases
     * only differ in the reason dompdf recorded -- refused for being remote, versus a stream that
     * failed to open. So the test asserts the reason: something must say "remote", and nothing may
     * look like a connection that was actually attempted. Verified both ways before committing.
     */
    public function testARemoteImageIsNotFetched(): void
    {
        $url = 'http://127.0.0.1:1/logo.png';

        $pdf = $this->renderPdf($this->document(
            '<table id="info"><tr><td id="logo"><img id="image" src="' . $url . '" alt="company_logo"></td></tr></table>'
        ));

        $warnings = array_values(array_filter($this->dompdfWarnings(), static fn (string $w): bool => str_contains($w, $url)));

        $this->assertNotSame([], $warnings, "dompdf no registró nada sobre $url: ¿la descargó sin más?");

        $refusedAsRemote = false;

        foreach ($warnings as $warning) {
            $refusedAsRemote = $refusedAsRemote || stripos($warning, 'remote') !== false;

            foreach (['open stream', 'refused', 'timed out', 'timeout', 'resolve host'] as $attempted) {
                $this->assertFalse(
                    stripos($warning, $attempted) !== false,
                    "dompdf intentó conectarse a $url: isRemoteEnabled dejó de tener efecto. Motivo registrado: $warning"
                );
            }
        }

        $this->assertTrue(
            $refusedAsRemote,
            'ninguna negativa dice que el recurso se rechazó por ser remoto: ' . implode(' | ', $warnings)
        );
        $this->assertFalse(
            str_contains($pdf, '/Subtype /Image'),
            'se incrustó una imagen remota'
        );
    }

    /**
     * The same refusal, applied to something the invoice, the quote and the work order all carry: a
     * `<link rel="stylesheet">` built with base_url(). It is an absolute http(s) URL, so it is
     * refused as well -- which is why these PDFs are laid out by their inline styles and
     * css/invoice_email.css has never reached any of them.
     */
    public function testTheStylesheetTheTemplatesLinkIsNotFetchedEither(): void
    {
        $this->renderPdf($this->fixture('invoice'));

        $this->assertTrue(
            $this->someWarningMentions('invoice_email.css'),
            'dompdf no rechazó la hoja de estilos remota: o la descargó, o dejó de registrarlo'
        );
    }

    /**
     * isPhpEnabled: false. The RCE half.
     *
     * Two assertions, and both had to be chosen by checking what changes when the option is turned
     * back on. Looking for the marker inside the PDF proves nothing: an executed `echo` does not
     * reach the canvas, it goes to standard output, so the marker is absent from the document either
     * way. What does change: the script's side effect on the process, and nine bytes printed. Hence
     * the canary global and the captured output. The rest of the page still has to render, so a
     * dompdf that simply gave up on documents containing a script would not pass either.
     */
    public function testInlinePhpIsNotExecuted(): void
    {
        $pdf = $this->renderPdf($this->document(
            '<script type="text/php">$GLOBALS["OSPOS_PDF_RCE_CANARY"] = true; echo "RCEMARKER";</script>'
            . '<p>despues del script</p>'
        ));

        $this->assertArrayNotHasKey(
            'OSPOS_PDF_RCE_CANARY',
            $GLOBALS,
            'el PHP incrustado en el HTML se ejecutó: isPhpEnabled dejó de tener efecto'
        );
        $this->assertSame(
            '',
            $this->printedOutput,
            'algo se imprimió durante el renderizado; con isPhpEnabled activo el echo del script sale por aquí'
        );
        $this->assertTrue(
            str_contains($this->contentStreams($pdf), 'despues del script'),
            'el resto de la página no se dibujó: dompdf descartó el documento entero por llevar un script'
        );
    }

    // ========== The helper's own one non-obvious line ==========

    /**
     * create_pdf() does str_replace(['\n', '\r'], '', $html) with single quotes, so what it strips is
     * not newlines -- it is the two-character sequence backslash-n wherever it appears, including
     * inside the customer's own text. A comment or an article name holding a Windows path comes out
     * altered. Recorded, not fixed: the helper is out of scope here, and this test is what will say
     * so when somebody does fix it.
     */
    public function testALiteralBackslashNSequenceIsStrippedFromTheHtml(): void
    {
        $pdf = $this->renderPdf($this->document('<p>Ruta C:\\ndocs y algo más</p>'));

        $streams = $this->contentStreams($pdf);

        $this->assertTrue(str_contains($streams, 'C:docs'), 'la secuencia barra-n dejó de eliminarse');
        $this->assertFalse(str_contains($streams, 'ndocs'), 'quedó la n suelta: el reemplazo cambió de forma');
    }

    // ========== Keeping the fixtures honest ==========

    /**
     * The fixtures above stand in for the real views. This is what stops them drifting: each of the
     * four templates still has to contain the construct its fixture reproduces. If a view is
     * rewritten, this fails first and the fixtures get rewritten with it.
     */
    public function testTheFixturesStillMatchTheShippedTemplates(): void
    {
        $views = APPPATH . 'Views/sales/';

        $invoice    = (string)file_get_contents($views . 'invoice_email.php');
        $receipt    = (string)file_get_contents($views . 'receipt_email.php');
        $quote      = (string)file_get_contents($views . 'quote_email.php');
        $workOrder  = (string)file_get_contents($views . 'work_order_email.php');

        $this->assertStringContainsString('src="data:', $invoice, 'la factura ya no inlinea el logo como data: URI');
        $this->assertStringContainsString('data:image/svg+xml;base64,', $invoice, 'la factura ya no lleva el código de barras SVG');

        $this->assertStringContainsString('data:image/svg+xml;base64,', $receipt, 'el recibo ya no lleva el código de barras SVG');
        $this->assertStringContainsString('width: 40%;', $receipt, 'el recibo ya no maqueta sus columnas en porcentaje');

        $this->assertStringContainsString(
            "'uploads/' . esc(\$config['company_logo'], 'url')",
            $quote,
            'la cotización ya no referencia el logo por ruta relativa'
        );
        $this->assertStringContainsString(
            "esc('uploads/' . \$config['company_logo'], 'url')",
            $workOrder,
            'la orden de trabajo ya no pasa la ruta completa por esc(..., \'url\')'
        );

        foreach (['invoice' => $invoice, 'quote' => $quote, 'work_order' => $workOrder] as $name => $source) {
            $this->assertStringContainsString(
                "base_url('css/invoice_email.css')",
                $source,
                "$name ya no enlaza la hoja de estilos remota que dompdf rechaza"
            );
        }

        // El recibo es el único que no es un documento: sin doctype y sin <head>, así que tampoco
        // enlaza la hoja de estilos. Su fixture es un fragmento por eso.
        $this->assertStringNotContainsString('<head>', $receipt, 'el recibo pasó a ser un documento completo');
        $this->assertStringNotContainsString(
            "base_url('css/invoice_email.css')",
            $receipt,
            'el recibo pasó a enlazar la hoja de estilos'
        );
    }

    /**
     * renderPdf() swallows dompdf's diagnostics and its output. That is there so upstream's noise on
     * modern PHP cannot decide whether our helper works -- and this is what stops it hiding anything
     * of ours. Every diagnostic raised while the four templates render has to come from inside
     * vendor/dompdf, and nothing may be printed at all.
     */
    public function testNothingOfOursIsHiddenByTheScopedErrorHandler(): void
    {
        foreach (self::templateShapeProvider() as $label => [$shape]) {
            $this->renderPdf($this->fixture($shape));

            $this->assertSame('', $this->printedOutput, "{$label} imprimió algo al renderizarse");

            foreach ($this->diagnostics as $diagnostic) {
                $this->assertStringNotContainsString(
                    APPPATH,
                    $diagnostic['file'],
                    "{$label} levantó un diagnóstico en nuestro propio código: "
                    . "{$diagnostic['message']} en {$diagnostic['file']}"
                );
            }
        }
    }

    // ========== Fixtures and plumbing ==========

    /**
     * The HTML shape of one of the four templates, stripped to the constructs that decide whether
     * dompdf copes: the logo, the barcode, and the items table with its spans and its totals.
     */
    private function fixture(string $shape): string
    {
        $logoDataUri = '<img id="image" src="data:image/jpeg;base64,' . self::LOGO_JPEG_BASE64 . '" alt="company_logo">';
        $barcode     = $this->barcodeMarkup($this->barcodeSvg(6));

        return match ($shape) {
            // app/Views/sales/invoice_email.php: logo inlined as a data: URI, barcode as an SVG
            // data: URI.
            'invoice' => $this->document(
                '<table id="info"><tr><td id="logo">' . $logoDataUri . '</td>'
                . '<td id="customer-title">Casa Letto</td></tr></table>' . $barcode
            ),

            // app/Views/sales/receipt_email.php: a fragment, not a document -- no doctype, no
            // <head>, no stylesheet link. The logo arrives already built as an <img> tag from
            // Email_lib::buildLogoImgTag(), and the items table is the only one in the four views
            // whose columns are sized in percentages, which is a different reflow path and the one
            // that decides whether the totals column lands on the paper.
            'receipt' => '<div id="receipt_wrapper" style="width: 100%;">'
                . '<div id="receipt_header" style="text-align: center;"><div id="company_name">' . $logoDataUri . '</div>'
                . '<div id="company_name" style="font-size: 150%; font-weight: bold;">Casa Letto</div></div>'
                . '<table id="receipt_items" style="text-align: left; width: 100%;">'
                . '<tr><th style="width: 40%;">Desc.</th><th style="width: 20%;">Precio</th>'
                . '<th style="width: 20%;">Cant.</th><th style="width: 20%; text-align: right;">Total</th></tr>'
                . '<tr><td>Sandwich jamon y queso</td><td>$ 12.000</td><td>3,000</td>'
                . '<td style="text-align: right;">$ 36.000</td></tr>'
                . '<tr><td colspan="3" style="text-align: right; border-top: 2px solid #000000;">Subtotal</td>'
                . '<td style="text-align: right;">$ 36.000</td></tr>'
                . '<tr><td colspan="3" style="text-align: right;">19% IVA</td>'
                . '<td style="text-align: right;">$ 6.840</td></tr>'
                . '<tr><td colspan="3" style="text-align: right; border-top: 2px solid black;">Total</td>'
                . '<td style="text-align: right;">$ 42.840</td></tr></table>'
                . '<div id="terms"><div id="sale_return_policy" style="text-align: center;">Sin devoluciones</div>'
                . $barcode . '</div></div>',

            // app/Views/sales/quote_email.php: only the file name goes through esc(..., 'url'), so
            // the src stays a relative path.
            'quote' => $this->document(
                '<table id="info"><tr><td id="logo">'
                . '<img id="image" src="uploads/casaletto.png" alt="company_logo"></td>'
                . '<td id="customer-title"><pre>Casa Letto</pre></td></tr></table><div id="barcode">COT 17</div>'
            ),

            // app/Views/sales/work_order_email.php: the whole path goes through esc(..., 'url'), so
            // the separator arrives as %2F.
            'work_order' => $this->document(
                '<table id="info"><tr><td id="logo">'
                . '<img id="image" src="uploads%2Fcasaletto.png" alt="company_logo"></td>'
                . '<td id="customer-title"><pre>Casa Letto</pre></td></tr></table><div id="barcode">OT 17</div>'
            ),

            default => throw new \InvalidArgumentException("plantilla desconocida: $shape"),
        };
    }

    /**
     * The frame all four views share: the remote stylesheet link, and an items table with the spans,
     * the subtotal, the tax line and the total.
     */
    private function document(string $body): string
    {
        return '<!doctype html><html lang="es-MX"><head><meta charset="utf-8"><title>Factura</title>'
            . '<link rel="stylesheet" href="http://example.com/css/invoice_email.css"></head><body>'
            . '<div id="page-wrap"><div id="header">Factura</div>' . $body
            . '<table id="items">'
            . '<tr><th>#</th><th>Articulo</th><th>Cant.</th><th>Precio</th><th>Total</th></tr>'
            . '<tr class="item-row"><td>1001</td><td class="item-name">Sandwich jamon y queso</td>'
            . '<td>3,000</td><td>$ 12.000</td><td class="total-line">$ 36.000</td></tr>'
            . '<tr><td colspan="3" class="blank"> </td><td class="total-line">Subtotal</td><td>$ 36.000</td></tr>'
            . '<tr><td colspan="3" class="blank"> </td><td class="total-line">19% IVA</td><td>$ 6.840</td></tr>'
            . '<tr><td colspan="3" class="blank"> </td><td class="total-line">Total</td><td>$ 42.840</td></tr>'
            . '</table></div></body></html>';
    }

    /**
     * The barcode as the views emit it: an <img> whose src is the SVG that
     * Barcode_lib::generate_receipt_barcode() produces, base64'd into a data: URI.
     */
    private function barcodeMarkup(string $svg): string
    {
        return '<div id="barcode" style="text-align: center;">'
            . '<img alt="17" src="data:image/svg+xml;base64,' . base64_encode($svg) . '"><br>POS 17</div>';
    }

    /**
     * The shape picqer's BarcodeGeneratorSVG really emits, which is what
     * Barcode_lib::generate_receipt_barcode() hands the views: XML declaration, SVG 1.1 DOCTYPE,
     * width and height AND viewBox on the root, a <desc>, and the bars as plain <rect> elements
     * inside <g id="bars" fill="black" stroke="none">. Reproduced rather than generated so the
     * fixture does not quietly change shape when the barcode library is upgraded -- and
     * testTheFixturesStillMatchTheShippedTemplates() is what catches it if the views stop using it.
     */
    private function barcodeSvg(int $bars): string
    {
        $rects = '';

        for ($i = 0; $i < $bars; $i++) {
            $rects .= "\t\t" . '<rect x="' . ($i * 3) . '" y="0" width="' . (1 + ($i % 3)) . '" height="30" />' . "\n";
        }

        $width = max(40, $bars * 3);

        return '<?xml version="1.0" standalone="no" ?>' . "\n"
            . '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">' . "\n"
            . '<svg width="' . $width . '" height="30" viewBox="0 0 ' . $width . ' 30" version="1.1" '
            . 'xmlns="http://www.w3.org/2000/svg">' . "\n"
            . "\t<desc>17</desc>\n"
            . "\t" . '<g id="bars" fill="black" stroke="none">' . "\n"
            . $rects
            . "\t</g>\n</svg>\n";
    }

    /**
     * Calls the helper under a scoped error handler. See $diagnostics for why, and
     * testNoDiagnosticComesFromOurOwnCode() for what keeps it from hiding anything of ours.
     */
    private function renderPdf(string $html): string
    {
        $this->diagnostics           = [];
        $this->printedOutput         = '';
        $GLOBALS['_dompdf_warnings'] = [];

        ob_start();
        set_error_handler(function (int $level, string $message, string $file = '', int $line = 0): bool {
            $this->diagnostics[] = ['level' => $level, 'message' => $message, 'file' => $file];

            return true;
        });

        try {
            return create_pdf($html);
        } finally {
            restore_error_handler();
            $this->printedOutput = (string)ob_get_clean();
        }
    }

    /**
     * The drawing operators, inflated. dompdf compresses its content streams, so the text and the
     * vector operators are not readable in the raw bytes.
     */
    private function contentStreams(string $pdf): string
    {
        $text = '';

        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches) !== false) {
            foreach ($matches[1] as $stream) {
                $inflated = @gzuncompress($stream);

                if ($inflated === false) {
                    $inflated = @gzinflate($stream);
                }

                if ($inflated !== false) {
                    $text .= $inflated . "\n";
                }
            }
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    private function dompdfWarnings(): array
    {
        return array_map('strval', $GLOBALS['_dompdf_warnings'] ?? []);
    }

    private function someWarningMentions(string $needle): bool
    {
        foreach ($this->dompdfWarnings() as $warning) {
            if (str_contains($warning, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this PHP and this dompdf, together, can work out that an SVG is an SVG. Asked through
     * dompdf's own function so the answer is the one dompdf will act on. See
     * testTheSvgBarcodeIsDrawnOntoThePage().
     */
    private function dompdfCanDetectSvg(): bool
    {
        // A name nothing else has used: dompdf_getimagesize() memoises by file name for the whole
        // process.
        $file = $this->writeTempFile('svg_probe_' . uniqid(), 'svg', $this->barcodeSvg(4));

        $this->diagnostics = [];
        set_error_handler(function (int $level, string $message, string $file = '', int $line = 0): bool {
            $this->diagnostics[] = ['level' => $level, 'message' => $message, 'file' => $file];

            return true;
        });

        try {
            [, , $type] = DompdfHelpers::dompdf_getimagesize($file);
        } finally {
            restore_error_handler();
        }

        return $type === 'svg';
    }

    private function writeTempFile(string $name, string $extension, string $contents): string
    {
        $path = sys_get_temp_dir() . '/ospos_createpdf_' . $name . '.' . $extension;

        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
