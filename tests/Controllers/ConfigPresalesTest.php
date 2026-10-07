<?php

namespace Tests\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\OSPOS;

/**
 * The endpoint behind the presales tab of the configuration screen.
 *
 * SHARED STATE: the test database is shared between files, and this one writes the three presales_*
 * rows. setUp() records what each held -- including "no row at all" -- and tearDown() puts exactly
 * that back and rebuilds the settings cache.
 *
 * @internal
 */
final class ConfigPresalesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private const TOUCHED_KEYS = ['presales_enable', 'presales_prefix', 'presales_terms'];

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    /**
     * @var array<string, string|null>
     */
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::TOUCHED_KEYS as $key) {
            $row                  = $this->db->table('app_config')->where('key', $key)->get()->getRow();
            $this->previous[$key] = $row === null ? null : (string) $row->value;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) {
            if ($value === null) {
                $this->db->table('app_config')->where('key', $key)->delete();
            } else {
                $this->db->table('app_config')->replace(['key' => $key, 'value' => $value]);
            }
        }

        config(OSPOS::class)->update_settings();

        parent::tearDown();
    }

    /**
     * See ConfigTest::resetSession(): without the session re-armed, Secure_Controller calls a real
     * exit() that kills the PHPUnit process with no output.
     */
    protected function resetSession(): void
    {
        $session = Services::session();
        $session->destroy();
        $session->set('person_id', 1);
        $session->set('menu_group', 'office');

        $this->withSession(['person_id' => 1, 'menu_group' => 'office']);
    }

    private function save(array $post): array
    {
        $this->resetSession();

        $response = $this->post('/config/savePresales', $post);
        $response->assertStatus(200);

        return json_decode($response->getJSON(), true);
    }

    private function setting(string $key): ?string
    {
        $row = $this->db->table('app_config')->where('key', $key)->get()->getRow();

        return $row === null ? null : (string) $row->value;
    }

    public function testTurningPresalesOnSavesAnExplicitOne(): void
    {
        $result = $this->save(['presales_enable' => 'presales_enable', 'presales_prefix' => 'PV-', 'presales_terms' => '']);

        $this->assertTrue($result['success']);
        $this->assertSame('1', $this->setting('presales_enable'));
    }

    /**
     * An unchecked box is absent from the POST. The switch is still written as '0', never left
     * missing: a missing row is what an unmigrated tenant looks like.
     */
    public function testAnUncheckedBoxSavesAnExplicitZero(): void
    {
        $this->save(['presales_enable' => 'presales_enable', 'presales_prefix' => 'PV-', 'presales_terms' => '']);
        $result = $this->save(['presales_prefix' => 'PV-', 'presales_terms' => '']);

        $this->assertTrue($result['success']);
        $this->assertSame('0', $this->setting('presales_enable'));
    }

    /**
     * The conditions keep their accents. A FILTER_SANITIZE_* read here would store "José" as
     * "Jos&eacute;" (docs/Tecnico/correccion-codificacion-tildes.md).
     */
    public function testTheConditionsAreStoredWithTheirAccentsAndLineBreaks(): void
    {
        $terms = "CONDICIONES DE LA PREVENTA\r\n1. Se entrega únicamente con el pago completo.\r\n2. Señor José: \"gracias\".";

        $result = $this->save(['presales_prefix' => 'PV-', 'presales_terms' => $terms]);

        $this->assertTrue($result['success']);
        $this->assertSame(str_replace("\r\n", "\n", $terms), $this->setting('presales_terms'));
    }

    public function testAPrefixWithSpacesOrSymbolsIsRefused(): void
    {
        $this->save(['presales_prefix' => 'PV-', 'presales_terms' => '']);

        $result = $this->save(['presales_prefix' => 'PV <b>', 'presales_terms' => '']);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Config.presales_prefix_invalid'), $result['message']);
        $this->assertSame('PV-', $this->setting('presales_prefix'), 'A refused save writes nothing.');
    }

    public function testConditionsLongerThanTheLimitAreRefused(): void
    {
        $result = $this->save(['presales_prefix' => 'PV-', 'presales_terms' => str_repeat('a', 4001)]);

        $this->assertFalse($result['success']);
    }
}
