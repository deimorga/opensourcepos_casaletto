<?php

namespace Tests\Controllers;

use App\Libraries\Sale_lib;
use App\Models\Item;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;

/**
 * Picking a product from the register's autocomplete rings up THAT product.
 *
 * It did not, and the way it failed is the point. The register has one input box and two kinds of
 * value reach it: a code a cashier typed or a scanner sent, where items.item_number has to win, and
 * an item_id the autocomplete writes there when somebody clicks a suggestion. As bare numbers the
 * two are the same string, so the resolver could only ever be right about one of them.
 *
 * Reported from production: in Paraiso de la Canasta, CEBOLLA LARGA is item_id 41 and ZANAHORIA
 * carries item_number 41. Clicking CEBOLLA LARGA in the list made the till ask for the weight of a
 * ZANAHORIA. 212 of that shop's 1.184 items collide the same way, and a wrong-product sale is
 * silent -- the cashier sees a name they were not looking at only if they read the line.
 *
 * The test goes through the real search endpoint rather than assuming what it returns, because the
 * defect lived exactly in the gap between what that endpoint answers and what the resolver does
 * with the answer. Assuming the payload would have tested the two halves separately and found
 * nothing.
 */
class SalesItemPickTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const PREFIX = 'PICK-';

    /** The one the user clicks. Priced by the kilo, like the pair in the report. */
    private int $chosenId;

    /** The impostor: its printed code is the chosen item's internal id. */
    private int $impostorId;

    protected function setUp(): void
    {
        parent::setUp();

        db_connect()->resetDataCache();

        $this->deleteFixtures();

        $this->chosenId = $this->seed('CEBOLLA', 'CODIGO-LARGO-7702028000316', Item::UNIT_OF_MEASURE_KG);
        $this->impostorId = $this->seed('ZANAHORIA', (string) $this->chosenId, Item::UNIT_OF_MEASURE_KG);

        $sale_lib = new Sale_lib();
        $sale_lib->empty_cart();
        $sale_lib->clear_weight_entry();
        $sale_lib->clear_mode();

        $this->loginAsAdmin();
    }

    protected function tearDown(): void
    {
        $sale_lib = new Sale_lib();
        $sale_lib->empty_cart();
        $sale_lib->clear_weight_entry();

        $this->deleteFixtures();

        parent::tearDown();
    }

    private function deleteFixtures(): void
    {
        $db = db_connect();
        $ids = array_column(
            $db->table('items')->select('item_id')->like('name', self::PREFIX, 'after')->get()->getResultArray(),
            'item_id'
        );

        if ($ids !== []) {
            $db->table('item_quantities')->whereIn('item_id', $ids)->delete();
            $db->table('items')->whereIn('item_id', $ids)->delete();
        }
    }

    private function seed(string $nameSuffix, string $itemNumber, string $unitOfMeasure): int
    {
        $db = db_connect();
        $db->table('items')->insert([
            'name'                  => self::PREFIX . $nameSuffix,
            'category'              => 'Test',
            'item_number'           => $itemNumber,
            'cost_price'            => '1000.00',
            'unit_price'            => '4100.00',
            'unit_of_measure'       => $unitOfMeasure,
            'reorder_level'         => '0',
            'receiving_quantity'    => '1',
            'allow_alt_description' => 0,
            'is_serialized'         => 0
        ]);
        $itemId = (int) $db->insertID();

        $db->table('item_quantities')->insert([
            'item_id'     => $itemId,
            'location_id' => 1,
            'quantity'    => '1000'
        ]);

        return $itemId;
    }

    /**
     * See SalesControllerTest::loginAsAdmin() for why $_SESSION has to be seeded directly.
     */
    private function loginAsAdmin(): void
    {
        $_SESSION = ['person_id' => 1, 'menu_group' => 'home'];
        $this->withSession($_SESSION);

        $this->get('sales');
    }

    private function getReq(string $path): TestResponse
    {
        $this->withSession($_SESSION);

        return $this->get($path);
    }

    private function postReq(string $path, array $params): TestResponse
    {
        $this->withSession($_SESSION);

        return $this->post($path, $params);
    }

    /**
     * What the autocomplete would put in the box for a given label -- the real endpoint's answer,
     * not a guess at it.
     */
    private function whatTheListOffersFor(string $label): string
    {
        $raw = (string) $this->getReq('sales/itemSearch?term=' . rawurlencode($label))->getBody();

        // The feature-test client hands back the JSON wrapped in an HTML envelope, so the body is
        // not parseable as it stands. The array between the outermost brackets is the payload.
        $start = strpos($raw, '[');
        $end   = strrpos($raw, ']');
        $suggestions = ($start === false || $end === false)
            ? null
            : json_decode(substr($raw, $start, $end - $start + 1), true);

        foreach ((array) $suggestions as $suggestion) {
            // Contains, not equals: the label is assembled from the suggestions_*_column settings,
            // which live in app_config and which other test files change. Matching on the name
            // alone keeps this test about the value, which is what the defect was in.
            if (is_array($suggestion) && str_contains((string) ($suggestion['label'] ?? ''), $label)) {
                return (string) $suggestion['value'];
            }
        }

        $this->fail("The search never offered '$label'. It answered: " . substr($raw, 0, 400));
    }

    public function testTheDefect(): void
    {
        $picked = $this->whatTheListOffersFor(self::PREFIX . 'CEBOLLA');

        $this->postReq('sales/add', ['item' => $picked]);

        $this->assertSame(
            self::PREFIX . 'CEBOLLA',
            (new Sale_lib())->get_weight_entry()['name'] ?? null,
            'The till has to weigh the product the cashier clicked, not the one whose printed code '
            . 'happens to equal its internal id.'
        );
    }

    /**
     * The same claim about the cart, because most items are not weighed and the wrong line lands
     * there silently instead of behind a prompt.
     */
    public function testAPickedItemThatIsNotWeighedLandsInTheCartAsItself(): void
    {
        db_connect()->table('items')
            ->where('item_id', $this->chosenId)
            ->update(['unit_of_measure' => Item::UNIT_OF_MEASURE_UNIT]);

        $picked = $this->whatTheListOffersFor(self::PREFIX . 'CEBOLLA');

        $this->postReq('sales/add', ['item' => $picked]);

        $cart = (new Sale_lib())->get_cart();
        $this->assertCount(1, $cart);

        $this->assertSame($this->chosenId, (int) array_values($cart)[0]['item_id']);
    }

    /**
     * The half that already worked, kept as an assertion: a code typed at the till is a printed
     * code, and the fix must not have quietly turned every number into an id.
     */
    public function testATypedCodeStillMeansThePrintedCode(): void
    {
        $this->postReq('sales/add', ['item' => (string) $this->chosenId]);

        $this->assertSame(
            self::PREFIX . 'ZANAHORIA',
            (new Sale_lib())->get_weight_entry()['name'] ?? null,
            'Typing the number that is printed on the ZANAHORIA has to reach the ZANAHORIA.'
        );
    }

    /**
     * The impostor is reachable from the list too -- the fix must not have made one of the two
     * items unpickable.
     */
    public function testTheOtherItemIsStillPickableFromTheList(): void
    {
        $picked = $this->whatTheListOffersFor(self::PREFIX . 'ZANAHORIA');

        $this->postReq('sales/add', ['item' => $picked]);

        $this->assertSame(
            self::PREFIX . 'ZANAHORIA',
            (new Sale_lib())->get_weight_entry()['name'] ?? null
        );
    }
}
