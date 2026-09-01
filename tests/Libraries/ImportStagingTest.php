<?php

declare(strict_types=1);

namespace Tests\Libraries;

use App\Libraries\Import_staging;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * El archivo que sobrevive entre «ver qué va a pasar» y «aplicarlo».
 *
 * LO QUE HAY QUE DEMOSTRAR NO ES QUE GUARDE, ES QUE NO SE PUEDA APUNTAR A OTRO SITIO
 *
 * Este archivo lo sube un cliente y luego se lee del disco. Si el paso 2 aceptara un identificador
 * venido del formulario, habría que defenderse de `../../etc/passwd`. Por eso el testigo vive en la
 * sesión y nunca se recibe de fuera: el problema no se mitiga, no existe.
 *
 * @internal
 */
final class ImportStagingTest extends CIUnitTestCase
{
    private Import_staging $staging;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staging = new Import_staging();
        session()->destroy();
        $this->limpiar();
    }

    protected function tearDown(): void
    {
        $this->limpiar();
        session()->destroy();

        parent::tearDown();
    }

    private function limpiar(): void
    {
        foreach (glob($this->staging->directory() . '*') ?: [] as $archivo) {
            @unlink($archivo);
        }
    }

    /** Siembra un archivo como si `store()` lo hubiera dejado, sin depender de una subida real. */
    private function sembrar(string $contenido = "Id,Barcode\n1,C10210\n", int $edadSegundos = 0): string
    {
        @mkdir($this->staging->directory(), 0750, true);

        $token = bin2hex(random_bytes(16));
        $ruta  = $this->staging->directory() . $token . '.csv';

        file_put_contents($ruta, $contenido);

        if ($edadSegundos > 0) {
            touch($ruta, time() - $edadSegundos);
        }

        session()->set('item_import_token', $token);

        return $ruta;
    }

    // ========== Lo que encuentra y lo que no ==========

    public function testWithNothingStoredThereIsNoFile(): void
    {
        $this->assertNull($this->staging->currentPath());
        $this->assertFalse($this->staging->hasFile());
    }

    public function testItFindsTheFileOfThisSession(): void
    {
        $ruta = $this->sembrar();

        $this->assertSame($ruta, $this->staging->currentPath());
        $this->assertTrue($this->staging->hasFile());
    }

    /**
     * Un despliegue vacía `writable/uploads`, que no es un volumen. Que el archivo ya no esté es un
     * caso normal y tiene que contestarse con «vuelva a subirlo», no con una avería.
     */
    public function testAFileThatVanishedIsSimplyAbsent(): void
    {
        $ruta = $this->sembrar();
        unlink($ruta);

        $this->assertNull($this->staging->currentPath());
    }

    public function testAnExpiredFileIsNotUsedAndIsRemoved(): void
    {
        $ruta = $this->sembrar('x', Import_staging::MAX_AGE_SECONDS + 60);

        $this->assertNull($this->staging->currentPath());
        $this->assertFileDoesNotExist($ruta);
        $this->assertNull(session()->get('item_import_token'), 'Y la sesión deja de apuntar a la nada.');
    }

    // ========== El testigo no se recibe de fuera ==========

    public function testATokenThatIsNotInTheSessionOpensNothing(): void
    {
        $this->sembrar();
        session()->set('item_import_token', bin2hex(random_bytes(16)));

        $this->assertNull($this->staging->currentPath(), 'Un testigo que no es el de la sesión no vale.');
    }

    public function testAMalformedTokenIsRefusedWithoutTouchingTheDisk(): void
    {
        // Si esto se aceptara, habría que defender la ruta contra `../`. Como no se acepta, no hay
        // ruta que defender.
        foreach (['../../etc/passwd', 'no-es-hexadecimal', '', 'ABC'] as $malo) {
            session()->set('item_import_token', $malo);
            $this->assertNull($this->staging->currentPath(), $malo);
        }
    }

    // ========== Se borra ==========

    public function testDiscardRemovesTheFileAndForgetsIt(): void
    {
        $ruta = $this->sembrar();

        $this->staging->discard();

        $this->assertFileDoesNotExist($ruta);
        $this->assertNull(session()->get('item_import_token'));
    }

    public function testDiscardingWithNothingStoredIsNotAnError(): void
    {
        $this->staging->discard();

        $this->assertNull($this->staging->currentPath());
    }

    /**
     * El caso que nadie recuerda: el cliente ve la vista previa, se va a comer, y no vuelve. El
     * barrido por antigüedad lo cubre sin necesitar una tarea programada.
     */
    public function testTheSweepRemovesWhatIsOldAndKeepsWhatIsNot(): void
    {
        @mkdir($this->staging->directory(), 0750, true);

        $viejo = $this->staging->directory() . str_repeat('a', 32) . '.csv';
        $nuevo = $this->staging->directory() . str_repeat('b', 32) . '.csv';

        file_put_contents($viejo, 'x');
        file_put_contents($nuevo, 'x');
        touch($viejo, time() - Import_staging::MAX_AGE_SECONDS - 60);

        $this->staging->sweepExpired();

        $this->assertFileDoesNotExist($viejo);
        $this->assertFileExists($nuevo, 'Barrer no puede llevarse el archivo de quien está trabajando ahora.');
    }

    // ========== Dónde vive ==========

    public function testItLivesInItsOwnDirectoryAndNotWhereDownloadsAreServed(): void
    {
        // `writable/uploads/` a secas contiene `importCustomers.csv`, que Customers::getCsv() SIRVE
        // como descarga. Mezclar un buzón de subida con un directorio de descargas es cómo una
        // subida se convierte en una descarga.
        $this->assertStringEndsWith('uploads/item_import/', $this->staging->directory());
        $this->assertStringStartsWith(WRITEPATH, $this->staging->directory());
    }

    public function testTheSnapshotLivesAndDiesWithTheUpload(): void
    {
        $this->sembrar();
        $foto = $this->staging->previousSnapshotPath();

        $this->assertNotNull($foto);
        $this->assertStringEndsWith('.antes.csv', $foto);

        file_put_contents($foto, 'x');
        $this->staging->discard();

        $this->assertFileDoesNotExist($foto, 'La foto describe ESE cambio; conservarla sería ofrecer un archivo que ya no dice nada.');
    }
}
