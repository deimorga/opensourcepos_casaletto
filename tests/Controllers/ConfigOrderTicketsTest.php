<?php

namespace Tests\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\OSPOS;

/**
 * The endpoint behind the order tickets tab of the configuration screen.
 *
 * The screen's own rendering, and where the tab sits, are covered without a database in
 * tests/Views/OrderTicketsConfigViewTest.php. This file covers the two rules the save enforces:
 * order tickets are refused while Tables is off, and the kitchen switch can never be on while order
 * tickets are off.
 *
 * SHARED STATE: the test database is shared between files, and this one writes three app_config
 * rows (the two order_tickets_* switches and dinner_table_enable). setUp() records what each of them
 * held -- including "no row at all" -- and tearDown() puts exactly that back and rebuilds the
 * settings cache. Without that, ConfigTest, ConfigScaleTest or WiredSettingsViewTest would fail
 * later, in another file, far from the cause.
 *
 * @internal
 */
final class ConfigOrderTicketsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    /**
     * Every app_config key this file writes, directly or through the endpoint.
     */
    private const TOUCHED_KEYS = ['order_tickets_enable', 'order_tickets_kitchen_enable', 'dinner_table_enable'];

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    /**
     * What each touched key held before the test: its value, or null when the row did not exist.
     *
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

        // The cache is what every other file reads through; restoring the rows is not enough.
        config(OSPOS::class)->update_settings();

        parent::tearDown();
    }

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

    /**
     * Sets the stored configuration the request will find.
     *
     * Written straight to the table and then the cache rebuilt, in that order: Config::__construct()
     * copies config(OSPOS::class)->settings once, so a row changed after the cache was built would
     * not be what the controller sees.
     *
     * @param array<string, string> $settings
     */
    private function given(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $this->db->table('app_config')->replace(['key' => $key, 'value' => $value]);
        }

        config(OSPOS::class)->update_settings();
    }

    private function save(array $post): array
    {
        $this->resetSession();

        $response = $this->post('/config/saveOrderTickets', $post);
        $response->assertStatus(200);

        return json_decode($response->getJSON(), true);
    }

    public function testTurningOrderTicketsOnWithTablesOnSavesIt(): void
    {
        $this->given(['dinner_table_enable' => '1', 'order_tickets_enable' => '0', 'order_tickets_kitchen_enable' => '0']);

        $result = $this->save(['order_tickets_enable' => 'order_tickets_enable']);

        $this->assertTrue($result['success']);
        $this->assertSame(lang('Config.saved_successfully'), $result['message']);
        $this->seeInDatabase('app_config', ['key' => 'order_tickets_enable', 'value' => '1']);
        $this->seeInDatabase('app_config', ['key' => 'order_tickets_kitchen_enable', 'value' => '0']);
    }

    public function testTurningOrderTicketsOnWithTablesOffIsRefused(): void
    {
        // Refused, not corrected: turning Tables on underneath would change every till's register
        // without anybody having decided it.
        $this->given(['dinner_table_enable' => '0', 'order_tickets_enable' => '0', 'order_tickets_kitchen_enable' => '0']);

        $result = $this->save([
            'order_tickets_enable'         => 'order_tickets_enable',
            'order_tickets_kitchen_enable' => 'order_tickets_kitchen_enable',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Config.order_tickets_requires_tables'), $result['message']);
        $this->seeInDatabase('app_config', ['key' => 'order_tickets_enable', 'value' => '0']);
        $this->seeInDatabase('app_config', ['key' => 'order_tickets_kitchen_enable', 'value' => '0']);
        $this->seeInDatabase('app_config', ['key' => 'dinner_table_enable', 'value' => '0']);
    }

    public function testTablesMissingFromTheCacheCountsAsOff(): void
    {
        $this->given(['order_tickets_enable' => '0']);
        $this->db->table('app_config')->where('key', 'dinner_table_enable')->delete();
        config(OSPOS::class)->update_settings();

        $result = $this->save(['order_tickets_enable' => 'order_tickets_enable']);

        $this->assertFalse($result['success']);
        $this->seeInDatabase('app_config', ['key' => 'order_tickets_enable', 'value' => '0']);
    }

    /**
     * The other direction of the same rule, enforced in postSaveTables(). Turning Tables off while
     * order tickets are on would hide the tab bar, and every open ticket with it, while the orders
     * are still in the kitchen.
     *
     * Only the "turn Tables OFF" path is exercised here, deliberately. With Tables ON,
     * postSaveTables() deletes every dinner table missing from the POST, and the test database is
     * shared between files: exercising that branch would wipe other tests' tables.
     */
    public function testTurningTablesOffWhileOrderTicketsAreOnIsRefused(): void
    {
        $this->given(['dinner_table_enable' => '1', 'order_tickets_enable' => '1', 'order_tickets_kitchen_enable' => '0']);

        $this->resetSession();
        $response = $this->post('/config/saveTables', []);
        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Config.tables_required_by_order_tickets'), $result['message']);
        $this->seeInDatabase('app_config', ['key' => 'dinner_table_enable', 'value' => '1']);
    }

    public function testTurningTablesOffWithOrderTicketsOffIsStillAllowed(): void
    {
        $this->given(['dinner_table_enable' => '1', 'order_tickets_enable' => '0', 'order_tickets_kitchen_enable' => '0']);

        $this->resetSession();
        $response = $this->post('/config/saveTables', []);
        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);

        $this->assertTrue($result['success'], 'The guard must not change anything for a business that does not use order tickets.');
        $this->seeInDatabase('app_config', ['key' => 'dinner_table_enable', 'value' => '0']);
    }

    public function testTurningOrderTicketsOffForcesTheKitchenOff(): void
    {
        $this->given(['dinner_table_enable' => '1', 'order_tickets_enable' => '1', 'order_tickets_kitchen_enable' => '1']);

        // The kitchen box arrives ticked -- a hand-made request, or the page's JavaScript not
        // having run -- and still has to land as '0'.
        $result = $this->save(['order_tickets_kitchen_enable' => 'order_tickets_kitchen_enable']);

        $this->assertTrue($result['success']);
        $this->seeInDatabase('app_config', ['key' => 'order_tickets_enable', 'value' => '0']);
        $this->seeInDatabase('app_config', ['key' => 'order_tickets_kitchen_enable', 'value' => '0']);
    }

    public function testTurningOrderTicketsOffIsAllowedWithTablesOff(): void
    {
        // Somebody who turned Tables off first must still be able to switch order tickets off.
        $this->given(['dinner_table_enable' => '0', 'order_tickets_enable' => '1', 'order_tickets_kitchen_enable' => '0']);

        $result = $this->save([]);

        $this->assertTrue($result['success']);
        $this->seeInDatabase('app_config', ['key' => 'order_tickets_enable', 'value' => '0']);
    }

    public function testBothSwitchesOnAreSavedTogether(): void
    {
        $this->given(['dinner_table_enable' => '1', 'order_tickets_enable' => '0', 'order_tickets_kitchen_enable' => '0']);

        $result = $this->save([
            'order_tickets_enable'         => 'order_tickets_enable',
            'order_tickets_kitchen_enable' => 'order_tickets_kitchen_enable',
        ]);

        $this->assertTrue($result['success']);
        $this->seeInDatabase('app_config', ['key' => 'order_tickets_enable', 'value' => '1']);
        $this->seeInDatabase('app_config', ['key' => 'order_tickets_kitchen_enable', 'value' => '1']);
    }

    public function testUncheckingLeavesAZeroRowInsteadOfDeletingIt(): void
    {
        // An unchecked box is simply absent from the POST. It has to become an explicit '0', not a
        // missing row: a missing row is what an unmigrated tenant looks like.
        $this->given(['dinner_table_enable' => '1', 'order_tickets_enable' => '1', 'order_tickets_kitchen_enable' => '1']);

        $result = $this->save([]);

        $this->assertTrue($result['success']);
        $this->seeInDatabase('app_config', ['key' => 'order_tickets_enable', 'value' => '0']);
        $this->seeInDatabase('app_config', ['key' => 'order_tickets_kitchen_enable', 'value' => '0']);
    }
}
