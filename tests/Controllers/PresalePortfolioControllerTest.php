<?php

namespace Tests\Controllers;

use App\Models\Presale;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\OSPOS;

/**
 * The portfolio screen and its CSV (docs/Tecnico/preventas-cartera-y-avisos.md §3.2): who reaches
 * them and what they show. The figures themselves are tested on the model (PresalePortfolioTest).
 *
 * SHARED STATE: grants for person 1 are added only when missing and removed afterwards; a grant this
 * file takes away is put back. The presales_enable switch is restored. Own campaign, customer and
 * presale (PORTFOLIO-CTRL), removed by id.
 *
 * @internal
 */
final class PresalePortfolioControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate            = true;
    protected $migrateOnce        = true;
    protected $refresh            = false;
    protected $namespace          = 'App';
    private ?string $switchBefore = null;
    private int $campaign_id      = 0;
    private int $customer_id      = 0;
    private int $presale_id       = 0;

    /**
     * @var list<string> grants this file added
     */
    private array $grantedHere = [];

    /**
     * @var list<array<string, mixed>> grant rows this file removed, put back in tearDown
     */
    private array $revokedHere = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->resetDataCache();

        $row                = $this->db->table('app_config')->where('key', 'presales_enable')->get()->getRow();
        $this->switchBefore = $row === null ? null : (string) $row->value;

        $this->db->table('presale_campaigns')->insert([
            'name'             => 'PORTFOLIO-CTRL campana', 'sale_starts' => '2026-10-01', 'sale_ends' => '2026-12-15',
            'discount_percent' => '0', 'min_initial_percent' => '0', 'active' => 1, 'deleted' => 0,
            'created_by'       => 1, 'created_at' => '2026-10-01 00:00:00',
        ]);
        $this->campaign_id = (int) $this->db->insertID();

        // A name that starts like a formula and carries markup: escaped on screen, defused in the CSV.
        $this->db->table('people')->insert([
            'first_name' => '=José <b>', 'last_name' => 'PORTFOLIO-CTRL', 'phone_number' => '+57 300 111 2233',
            'email'      => 'jose@example.com', 'address_1' => '', 'address_2' => '', 'city' => '', 'state' => '',
            'zip'        => '', 'country' => '', 'comments' => '',
        ]);
        $this->customer_id = (int) $this->db->insertID();
        $this->db->table('customers')->insert(['person_id' => $this->customer_id, 'taxable' => 1, 'deleted' => 0, 'employee_id' => 1]);

        // Ten days late with nothing paid, and a second instalment due in three days.
        $this->db->table('presales')->insert([
            'campaign_id'   => $this->campaign_id, 'customer_id' => $this->customer_id, 'employee_id' => 1,
            'location_id'   => 1, 'created_at' => '2026-10-01 10:00:00', 'delivery_date_id' => 0,
            'delivery_date' => date('Y-m-d', strtotime('+30 days')), 'status' => 'open', 'total' => '50000.00',
        ]);
        $this->presale_id = (int) $this->db->insertID();

        $this->db->table('presale_installments')->insert(['presale_id' => $this->presale_id, 'due_date' => date('Y-m-d', strtotime('-10 days')), 'amount' => '20000.00']);
        $this->db->table('presale_installments')->insert(['presale_id' => $this->presale_id, 'due_date' => date('Y-m-d', strtotime('+3 days')), 'amount' => '30000.00']);
    }

    protected function tearDown(): void
    {
        foreach ($this->grantedHere as $permission) {
            $this->db->table('grants')->where('permission_id', $permission)->where('person_id', 1)->delete();
        }

        foreach ($this->revokedHere as $row) {
            $this->db->table('grants')->replace($row);
        }

        $this->db->table('presale_installments')->where('presale_id', $this->presale_id)->delete();
        $this->db->table('presales')->where('presale_id', $this->presale_id)->delete();
        $this->db->table('presale_campaigns')->where('campaign_id', $this->campaign_id)->delete();
        $this->db->table('customers')->where('person_id', $this->customer_id)->delete();
        $this->db->table('people')->where('person_id', $this->customer_id)->delete();

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
        if ($this->db->table('grants')->where(['permission_id' => $permission, 'person_id' => 1])->countAllResults() === 0) {
            $this->db->table('grants')->insert(['permission_id' => $permission, 'person_id' => 1, 'menu_group' => $permission === 'presales' ? 'home' : '--']);
            $this->grantedHere[] = $permission;
        }
    }

    private function revoke(string $permission): void
    {
        $row = $this->db->table('grants')->where(['permission_id' => $permission, 'person_id' => 1])->get()->getRowArray();

        if ($row === null) {
            return;
        }

        if (in_array($permission, $this->grantedHere, true)) {
            $this->grantedHere = array_values(array_diff($this->grantedHere, [$permission]));
        } else {
            $this->revokedHere[] = $row;
        }

        $this->db->table('grants')->where(['permission_id' => $permission, 'person_id' => 1])->delete();
    }

    private function switchTo(string $value): void
    {
        $this->db->table('app_config')->replace(['key' => 'presales_enable', 'value' => $value]);
        config(OSPOS::class)->update_settings();
    }

    private function getAs(string $uri): TestResponse
    {
        $session = Services::session();
        $session->destroy();
        $session->set('person_id', 1);
        $session->set('menu_group', 'office');
        $this->withSession(['person_id' => 1, 'menu_group' => 'office']);

        return $this->get($uri);
    }

    private function csvOf(TestResponse $response): string
    {
        ob_start();
        $response->response()->sendBody();

        return (string) ob_get_clean();
    }

    public function testWithoutPresalesManageTheScreenAndTheCsvAreRefused(): void
    {
        $this->grant('presales');
        $this->revoke('presales_manage');
        $this->switchTo('1');

        $response = $this->getAs('/presales/portfolio');
        $response->assertRedirect();
        $this->assertStringContainsString('no_access', (string) $response->getRedirectUrl());

        $csv = $this->getAs('/presales/portfolio/csv');
        $csv->assertRedirect();
        $this->assertStringContainsString('no_access', (string) $csv->getRedirectUrl());
    }

    public function testWithTheModuleOffTheScreenSaysSo(): void
    {
        $this->grant('presales');
        $this->grant('presales_manage');
        $this->switchTo('0');

        $response = $this->getAs('/presales/portfolio');

        $response->assertStatus(200);
        $this->assertStringContainsString(esc(lang('Presales.disabled')), (string) $response->getBody());

        $this->getAs('/presales/portfolio/csv')->assertRedirect();
    }

    public function testTheScreenShowsTheLateCustomerEscapedWithALinkToThePresale(): void
    {
        $this->grant('presales');
        $this->grant('presales_manage');
        $this->switchTo('1');

        $response = $this->getAs('/presales/portfolio?campaign_id=' . $this->campaign_id);

        $response->assertStatus(200);
        $body = (string) $response->getBody();

        $this->assertStringContainsString(esc(lang('Presale_reports.portfolio')), $body);
        $this->assertStringContainsString(esc(lang('Presale_reports.overdue')), $body);
        $this->assertStringContainsString(esc('=José <b> PORTFOLIO-CTRL'), $body);
        $this->assertStringNotContainsString('=José <b>', $body);
        $this->assertStringContainsString('+57 300 111 2233', $body);
        $this->assertStringContainsString('jose@example.com', $body);
        $this->assertStringContainsString(esc(model(Presale::class)->number($this->presale_id)), $body);
        $this->assertStringContainsString('presales/view/' . $this->presale_id, $body);
        $this->assertStringContainsString(to_currency('20000.00'), $body, 'The overdue instalment');
        $this->assertStringContainsString(to_currency('30000.00'), $body, 'The instalment due in three days');
    }

    public function testAnUnknownCampaignFallsBackToAllCampaigns(): void
    {
        $this->grant('presales');
        $this->grant('presales_manage');
        $this->switchTo('1');

        $body = (string) $this->getAs('/presales/portfolio?campaign_id=999999999&delivery_date=nonsense')->getBody();

        $this->assertStringContainsString(esc('=José <b> PORTFOLIO-CTRL'), $body);
    }

    public function testTheCsvCarriesEveryOpenPresaleAndDefusesFormulas(): void
    {
        $this->grant('presales');
        $this->grant('presales_manage');
        $this->switchTo('1');

        $response = $this->getAs('/presales/portfolio/csv?campaign_id=' . $this->campaign_id);

        $response->assertStatus(200);
        $csv = $this->csvOf($response);

        $this->assertStringContainsString(lang('Presale_reports.days_late'), $csv);
        $this->assertStringContainsString("'=José <b> PORTFOLIO-CTRL", $csv);
        $this->assertStringContainsString("'+57 300 111 2233", $csv);
        $this->assertStringContainsString('jose@example.com', $csv);
        $this->assertStringContainsString(model(Presale::class)->number($this->presale_id), $csv);
        $this->assertStringNotContainsString(',=José', $csv);
    }

    public function testTheListShowsThePortfolioButtonOnlyToWhoManages(): void
    {
        $this->grant('presales');
        $this->switchTo('1');

        $this->revoke('presales_manage');
        $this->assertStringNotContainsString(site_url('presales/portfolio'), (string) $this->getAs('/presales')->getBody());

        $this->grant('presales_manage');
        $this->assertStringContainsString(site_url('presales/portfolio'), (string) $this->getAs('/presales')->getBody());
    }
}
