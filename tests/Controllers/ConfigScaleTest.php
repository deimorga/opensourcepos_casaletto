<?php

namespace Tests\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The two endpoints behind the scale configuration screen.
 *
 * NOT RUN YET: this needs the database up (docker compose) and it was written with the container
 * down. The interpreter underneath it is covered without a database in
 * tests/Libraries/ScaleParseTest.php, and the screen's own rendering in
 * tests/Views/ScaleConfigViewTest.php -- both of those run and pass. What is left uncovered until
 * this file runs is the saving and the request plumbing.
 */
class ConfigScaleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    protected function resetSession(): void
    {
        $session = Services::session();
        $session->destroy();
        $session->set('person_id', 1);
        $session->set('menu_group', 'office');

        // See the long note in ConfigTest::resetSession(): FeatureTestTrait::call() overwrites
        // $_SESSION with its own property, and without this every request runs anonymous and
        // Secure_Controller calls a real exit() that kills the PHPUnit process with no output.
        $this->withSession(['person_id' => 1, 'menu_group' => 'office']);
    }

    private function save(array $post): array
    {
        $this->resetSession();

        $response = $this->post('/config/saveScale', $post);
        $response->assertStatus(200);

        return json_decode($response->getJSON(), true);
    }

    private function preview(array $post): array
    {
        $this->resetSession();

        $response = $this->post('/config/scalePreview', $post);
        $response->assertStatus(200);

        return json_decode($response->getJSON(), true);
    }

    // ========== Saving ==========

    public function testSavesTheFourSettings(): void
    {
        $result = $this->save([
            'scale_format'    => 'N{W:6}',
            'scale_divisor'   => '1',
            'scale_port'      => 'COM3',
            'scale_transport' => 'agent'
        ]);

        $this->assertTrue($result['success']);
        $this->seeInDatabase('app_config', ['key' => 'scale_format', 'value' => 'N{W:6}']);
        $this->seeInDatabase('app_config', ['key' => 'scale_divisor', 'value' => '1']);
        $this->seeInDatabase('app_config', ['key' => 'scale_port', 'value' => 'COM3']);
        $this->seeInDatabase('app_config', ['key' => 'scale_transport', 'value' => 'agent']);
    }

    public function testStoresThePatternExactlyAsTyped(): void
    {
        // The reason the pattern is read with no filter at all: it is a regular expression, and
        // FILTER_SANITIZE would turn the backslashes and the accented characters around it into
        // entities that match nothing. This is the test that would have caught that.
        $pattern = 'ST,GS,\+\s+{W:5}kg';

        $this->assertTrue($this->save([
            'scale_format'    => $pattern,
            'scale_divisor'   => '1',
            'scale_port'      => '',
            'scale_transport' => 'agent'
        ])['success']);

        $this->seeInDatabase('app_config', ['key' => 'scale_format', 'value' => $pattern]);
    }

    public function testAnEmptyPatternIsAcceptedAndMeansNoScale(): void
    {
        $result = $this->save([
            'scale_format'    => '',
            'scale_divisor'   => '1',
            'scale_port'      => '',
            'scale_transport' => 'keys'
        ]);

        $this->assertTrue($result['success']);
        $this->seeInDatabase('app_config', ['key' => 'scale_format', 'value' => '']);
    }

    public function testRefusesAPatternThatDoesNotCompile(): void
    {
        $this->save([
            'scale_format'    => 'N{W:6}',
            'scale_divisor'   => '1',
            'scale_port'      => '',
            'scale_transport' => 'agent'
        ]);

        $result = $this->save([
            'scale_format'    => 'N({W:6}',
            'scale_divisor'   => '1',
            'scale_port'      => '',
            'scale_transport' => 'agent'
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Config.scale_format_invalid'), $result['message']);

        // Nothing was written: the pattern that worked is still the one in the database.
        $this->seeInDatabase('app_config', ['key' => 'scale_format', 'value' => 'N{W:6}']);
    }

    public function testRefusesAPatternWithNoWeightToken(): void
    {
        $result = $this->save([
            'scale_format'    => 'N12.395',
            'scale_divisor'   => '1',
            'scale_port'      => '',
            'scale_transport' => 'agent'
        ]);

        $this->assertFalse($result['success']);
    }

    public function testRefusesANonPositiveDivisor(): void
    {
        $result = $this->save([
            'scale_format'    => 'N{W:6}',
            'scale_divisor'   => '0',
            'scale_port'      => '',
            'scale_transport' => 'agent'
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Config.scale_divisor_invalid'), $result['message']);
    }

    public function testFallsBackToKeysForATransportItDoesNotKnow(): void
    {
        $result = $this->save([
            'scale_format'    => 'N{W:6}',
            'scale_divisor'   => '1',
            'scale_port'      => '',
            'scale_transport' => 'carrier_pigeon'
        ]);

        $this->assertTrue($result['success']);
        $this->seeInDatabase('app_config', ['key' => 'scale_transport', 'value' => 'keys']);
    }

    // ========== Preview ==========

    public function testPreviewReadsTheFrameWithThePostedPattern(): void
    {
        $result = $this->preview([
            'scale_raw'     => "N12.395  \r\n",
            'scale_format'  => 'N{W:6}',
            'scale_divisor' => '1'
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('12.395', $result['weight']);
        $this->assertSame(lang('Config.scale_preview_ok'), $result['message']);
    }

    public function testPreviewUsesWhatIsOnTheScreenAndNotWhatIsSaved(): void
    {
        // The whole point of the preview: a pattern that has not been saved yet has to be testable.
        $this->save([
            'scale_format'    => 'N{W:6}',
            'scale_divisor'   => '1',
            'scale_port'      => '',
            'scale_transport' => 'agent'
        ]);

        $result = $this->preview([
            'scale_raw'     => '+000735 g',
            'scale_format'  => '\+{W:6} g',
            'scale_divisor' => '1000'
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('0.735', $result['weight']);
        $this->seeInDatabase('app_config', ['key' => 'scale_format', 'value' => 'N{W:6}']);
    }

    public function testPreviewSuggestsAPatternWhenTheOneOnScreenDoesNotMatch(): void
    {
        $result = $this->preview([
            'scale_raw'     => 'N12.395',
            'scale_format'  => 'X{W:6}',
            'scale_divisor' => '1'
        ]);

        $this->assertFalse($result['success']);
        $this->assertNull($result['weight']);
        $this->assertSame(lang('Config.scale_preview_no_match'), $result['message']);
        $this->assertSame('N{W:6}', $result['suggested_format']);
        $this->assertSame(1, $result['suggested_divisor']);
    }

    public function testPreviewAnswersTheSameFiveKeysWhateverHappens(): void
    {
        $keys = ['success', 'message', 'weight', 'suggested_format', 'suggested_divisor'];

        $cases = [
            ['scale_raw' => '', 'scale_format' => 'N{W:6}', 'scale_divisor' => '1'],
            ['scale_raw' => 'N12.395', 'scale_format' => '', 'scale_divisor' => '1'],
            ['scale_raw' => 'N12.395', 'scale_format' => 'N{W:6}', 'scale_divisor' => '0'],
            ['scale_raw' => 'garbage', 'scale_format' => 'N{W:6}', 'scale_divisor' => '1'],
            ['scale_raw' => 'N12.395', 'scale_format' => 'N({W:6}', 'scale_divisor' => '1'],
            ['scale_raw' => 'N12.395', 'scale_format' => 'N{W:6}', 'scale_divisor' => '1']
        ];

        foreach ($cases as $case) {
            $result = $this->preview($case);

            $this->assertSame($keys, array_keys($result), 'The shape of the answer must never depend on the outcome.');
            $this->assertIsBool($result['success']);
            $this->assertNotSame('', $result['message']);
        }
    }

    public function testPreviewSaysSoWhenThereIsNothingToTest(): void
    {
        $result = $this->preview(['scale_raw' => '', 'scale_format' => 'N{W:6}', 'scale_divisor' => '1']);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Config.scale_preview_empty'), $result['message']);
    }

    public function testPreviewSaysSoWhenThereIsNoPatternYet(): void
    {
        $result = $this->preview(['scale_raw' => 'N12.395', 'scale_format' => '', 'scale_divisor' => '1']);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Config.scale_preview_no_format'), $result['message']);
        $this->assertSame('N{W:6}', $result['suggested_format']);
    }

    public function testPreviewWritesNothing(): void
    {
        $before = $this->db->table('app_config')->where('key', 'scale_format')->get()->getRow()->value;

        $this->preview([
            'scale_raw'     => 'N12.395',
            'scale_format'  => 'N{W:6}',
            'scale_divisor' => '1'
        ]);

        $this->seeInDatabase('app_config', ['key' => 'scale_format', 'value' => $before]);
    }
}
