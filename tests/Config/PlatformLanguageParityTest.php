<?php

declare(strict_types=1);

namespace Tests\Config;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The two Platform language files must carry the same keys.
 *
 * This is the trap this project keeps stepping on, and it never announces itself: the application
 * runs in es-MX, CodeIgniter falls back es-MX -> es (a directory that does not exist here) and
 * never to English, so a key present only in `en` renders on screen as the literal string
 * "Platform.whatever", and a whole screen written only in es-ES comes out in English without a
 * single error anywhere. On 2026-08-30 a weight warning shipped in es-ES and was simply invisible.
 *
 * There is nothing clever here. It is a spelling check that runs in CI, placed in Fase 0 because
 * two agents are about to add screens to these files in parallel and neither can see the other's
 * half.
 *
 * @internal
 */
final class PlatformLanguageParityTest extends CIUnitTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function load(string $locale): array
    {
        return require APPPATH . 'Language/' . $locale . '/Platform.php';
    }

    public function testEveryEnglishKeyExistsInSpanish(): void
    {
        $missing = array_diff(array_keys($this->load('en')), array_keys($this->load('es-MX')));

        $this->assertSame(
            [],
            array_values($missing),
            'These keys would render on screen as "Platform.<key>": the console runs in es-MX.',
        );
    }

    /**
     * The other direction matters less on screen -- the console never runs in English -- but a key
     * that exists only in Spanish is a key nobody wrote down in the file that acts as the register
     * of what the console can say.
     */
    public function testEverySpanishKeyExistsInEnglish(): void
    {
        $extra = array_diff(array_keys($this->load('es-MX')), array_keys($this->load('en')));

        $this->assertSame([], array_values($extra));
    }

    /**
     * A key present but empty is worse than a missing one: it renders as nothing at all, so the
     * screen looks finished and says less than it should.
     */
    public function testNoKeyIsEmptyInEitherFile(): void
    {
        foreach (['en', 'es-MX'] as $locale) {
            foreach ($this->load($locale) as $key => $value) {
                $this->assertIsString($value, "{$locale}/Platform.php: {$key} is not a string.");
                $this->assertNotSame('', trim($value), "{$locale}/Platform.php: {$key} is empty.");
            }
        }
    }

    /**
     * The placeholders have to match, or a translated message quietly loses the one piece of
     * information it exists to carry -- which slug, which database, how many codes are left.
     */
    public function testThePlaceholdersMatchBetweenTheTwoFiles(): void
    {
        $spanish = $this->load('es-MX');

        foreach ($this->load('en') as $key => $english) {
            preg_match_all('/\{\d+\}/', $english, $inEnglish);
            preg_match_all('/\{\d+\}/', $spanish[$key] ?? '', $inSpanish);

            sort($inEnglish[0]);
            sort($inSpanish[0]);

            $this->assertSame($inEnglish[0], $inSpanish[0], "The placeholders of '{$key}' do not match.");
        }
    }
}
