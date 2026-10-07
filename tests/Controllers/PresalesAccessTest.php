<?php

namespace Tests\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\OSPOS;

/**
 * Who reaches the presales screens, and what they see with the module off.
 *
 * Person 1 is given the grants this file needs only when it did not already hold them, and they are
 * taken away again afterwards: the grants table is shared with every other test file.
 *
 * @internal
 */
final class PresalesAccessTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate            = true;
    protected $migrateOnce        = true;
    protected $refresh            = false;
    protected $namespace          = 'App';
    private ?string $switchBefore = null;

    /**
     * @var list<string>
     */
    private array $grantedHere = [];

    protected function setUp(): void
    {
        parent::setUp();

        $row                = $this->db->table('app_config')->where('key', 'presales_enable')->get()->getRow();
        $this->switchBefore = $row === null ? null : (string) $row->value;
    }

    protected function tearDown(): void
    {
        foreach ($this->grantedHere as $permission) {
            $this->db->table('grants')->where('permission_id', $permission)->where('person_id', 1)->delete();
        }

        if ($this->switchBefore === null) {
            $this->db->table('app_config')->where('key', 'presales_enable')->delete();
        } else {
            $this->db->table('app_config')->replace(['key' => 'presales_enable', 'value' => $this->switchBefore]);
        }

        config(OSPOS::class)->update_settings();

        parent::tearDown();
    }

    private function grant(string $permission): void
    {
        $exists = $this->db->table('grants')->where('permission_id', $permission)->where('person_id', 1)->countAllResults() > 0;

        if (! $exists) {
            $this->db->table('grants')->insert(['permission_id' => $permission, 'person_id' => 1, 'menu_group' => $permission === 'presales' ? 'both' : '--']);
            $this->grantedHere[] = $permission;
        }
    }

    private function switchTo(string $value): void
    {
        $this->db->table('app_config')->replace(['key' => 'presales_enable', 'value' => $value]);
        config(OSPOS::class)->update_settings();
    }

    private function getAs(string $uri)
    {
        $session = Services::session();
        $session->destroy();
        $session->set('person_id', 1);
        $session->set('menu_group', 'office');
        $this->withSession(['person_id' => 1, 'menu_group' => 'office']);

        return $this->get($uri);
    }

    public function testWithTheModuleOffTheScreenSaysSoInsteadOfFailing(): void
    {
        $this->grant('presales');
        $this->switchTo('0');

        $response = $this->getAs('/presales');

        $response->assertStatus(200);
        $this->assertStringContainsString(esc(lang('Presales.disabled')), (string) $response->getBody());
    }

    public function testWithTheModuleOnTheScreenOpens(): void
    {
        $this->grant('presales');
        $this->switchTo('1');

        $response = $this->getAs('/presales');

        $response->assertStatus(200);
        $this->assertStringNotContainsString(esc(lang('Presales.disabled')), (string) $response->getBody());
    }

    /**
     * Campaigns set prices: a cashier with only the module permission is sent away.
     */
    public function testCampaignsNeedTheManageSubpermission(): void
    {
        $this->grant('presales');
        $this->switchTo('1');

        $hadManage = $this->db->table('grants')->where('permission_id', 'presales_manage')->where('person_id', 1)->countAllResults() > 0;

        if ($hadManage) {
            $this->markTestSkipped('Person 1 already holds presales_manage in this database.');
        }

        $response = $this->getAs('/presales/campaigns');

        $response->assertRedirect();
        $this->assertStringContainsString('no_access', (string) $response->getRedirectUrl());

        $this->grant('presales_manage');

        $this->getAs('/presales/campaigns')->assertStatus(200);
    }
}
