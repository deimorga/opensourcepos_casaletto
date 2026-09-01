<?php

declare(strict_types=1);

namespace Tests\Views;

use App\Libraries\PlatformContext;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Que todas las páginas de la consola DECLAREN el idioma que de verdad hablan.
 *
 * `Platform_Controller` fija el idioma del texto desde `PlatformContext::LOCALE`, pero la etiqueta
 * `<html lang>` iba por su cuenta: dos páginas la tenían escrita a mano en `en` y otras dos leían
 * `service('request')->getLocale()`, que en la consola no lo fija nadie y cae al idioma por defecto
 * de la aplicación. Resultado: páginas marcadas como inglés con todo el contenido en español. Un
 * lector de pantalla las lee con la voz equivocada y el traductor del navegador se ofrece a
 * traducir de un idioma al mismo.
 *
 * Se lee el archivo, no se renderiza: lo que se comprueba es que nadie vuelva a escribir el idioma
 * a mano, y eso está en la fuente.
 *
 * @internal
 */
final class PlatformPageLanguageTest extends CIUnitTestCase
{
    /** @return array<string, array{string}> */
    public static function paginas(): array
    {
        $paginas = [];

        foreach (glob(APPPATH . 'Views/platform/*.php') as $ruta) {
            $paginas[basename($ruta)] = [$ruta];
        }

        return $paginas;
    }

    #[DataProvider('paginas')]
    public function testTheLanguageIsNeverWrittenByHand(string $ruta): void
    {
        $fuente = (string) file_get_contents($ruta);

        if (! str_contains($fuente, '<html')) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertMatchesRegularExpression(
            '/<html lang="<\?= esc\(PlatformContext::LOCALE\) \?>">/',
            $fuente,
            basename($ruta) . ' declara un idioma que no sale de PlatformContext::LOCALE.',
        );
    }

    public function testThereIsAPageToCheck(): void
    {
        $this->assertNotSame([], self::paginas(), 'Si no hay páginas, esta prueba no prueba nada.');
    }

    public function testTheConsoleSpeaksTheLanguageTheApplicationActuallyRunsIn(): void
    {
        // es-MX y no es-ES: una traducción escrita solo para la otra variante es invisible, y la
        // pantalla sale en inglés sin dar ningún error.
        $this->assertSame('es-MX', PlatformContext::LOCALE);
    }
}
