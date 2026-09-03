<?php

namespace Tests\Libraries;

use App\Libraries\Sale_lib;
use CodeIgniter\Config\Factories;
use CodeIgniter\Session\Handlers\ArrayHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockSession;
use Config\OSPOS;
use Config\Services;

/**
 * Propagar un precio nuevo a todas las líneas del mismo artículo.
 *
 * Sin base de datos a propósito: el carrito vive en la sesión, no en una tabla, así que todo lo que
 * hay que probar aquí se reproduce con un array -- y sigue siendo verificable aunque la suite con
 * base de datos no pueda correr.
 *
 * **La prueba que justifica este archivo es la de la cantidad.** `edit_item()` deriva la cantidad
 * del `discounted_total` cuando este cambió, así que un bucle escrito de la forma obvia --llamar a
 * `edit_item()` por cada línea hermana-- recalcularía la cantidad de cada una desde su
 * `discounted_total` viejo y cambiaría **cuánto se está vendiendo**. Es un error de inventario y de
 * plata que no se ve en ninguna prueba manual.
 */
class SaleLibRepriceTest extends CIUnitTestCase
{
    private Sale_lib $sale_lib;

    protected function setUp(): void
    {
        parent::setUp();

        $config           = new OSPOS();
        $config->settings = [
            'quantity_decimals'  => '3',
            'currency_decimals'  => '0',
            'tax_decimals'       => '2',
            'multi_pack_enabled' => '0',
            'line_sequence'      => '0'
        ];
        Factories::injectMock('config', OSPOS::class, $config);

        $sessionConfig = config('Session');
        $session       = new MockSession(new ArrayHandler($sessionConfig, '0.0.0.0'), $sessionConfig);
        $session->setLogger(service('logger'));
        $session->start();
        Services::injectMock('session', $session);

        $this->sale_lib = new Sale_lib();
    }

    private function linea(int $item_id, string $price, string $quantity, array $extra = []): array
    {
        return array_merge([
            'item_id'          => $item_id,
            'name'             => 'Artículo ' . $item_id,
            'price'            => $price,
            'quantity'         => $quantity,
            'discount'         => '0',
            'discount_type'    => PERCENT,
            'item_type'        => ITEM,
            'print_option'     => PRINT_YES,
            'total'            => '0',
            'discounted_total' => '0',
        ], $extra);
    }

    private function conCarrito(array $lineas): void
    {
        $carrito = [];

        foreach ($lineas as $key => $linea) {
            $linea['line']  = $key;
            $carrito[$key]  = $linea;
        }

        $this->sale_lib->set_cart($carrito);
    }

    // ========== La propagación ==========

    public function testRepricingOneLineReachesEveryOtherLineOfTheSameItem(): void
    {
        $this->conCarrito([
            1 => $this->linea(7, '0', '1'),
            2 => $this->linea(9, '3000', '1'),
            3 => $this->linea(7, '0', '2'),
        ]);

        $tocadas = $this->sale_lib->apply_reprice('1', '4500', ['price' => '4500']);

        $carrito = $this->sale_lib->get_cart();

        $this->assertSame(2, $tocadas, 'Las dos líneas del artículo 7.');
        $this->assertSame('4500', $carrito[1]['price']);
        $this->assertSame('4500', $carrito[3]['price']);
        $this->assertSame('3000', $carrito[2]['price'], 'El otro artículo no se toca.');
    }

    /**
     * LA PRUEBA QUE JUSTIFICA NO REUSAR edit_item().
     *
     * Cada línea conserva SU cantidad y SU descuento. Repreciar no puede cambiar cuánto se vende.
     */
    public function testPropagationNeverChangesAnotherLinesQuantityOrDiscount(): void
    {
        $this->conCarrito([
            1 => $this->linea(7, '0', '1'),
            2 => $this->linea(7, '0', '3', ['discount' => '10', 'discounted_total' => '999']),
        ]);

        $this->sale_lib->apply_reprice('1', '2000', ['price' => '2000']);

        $carrito = $this->sale_lib->get_cart();

        $this->assertSame('3', $carrito[2]['quantity'], 'La cantidad de la hermana es suya.');
        $this->assertSame('10', $carrito[2]['discount'], 'Y su descuento también.');
    }

    public function testEachLineTotalIsRecomputedFromItsOwnQuantity(): void
    {
        $this->conCarrito([
            1 => $this->linea(7, '0', '1'),
            2 => $this->linea(7, '0', '4'),
        ]);

        $this->sale_lib->apply_reprice('1', '1000', ['price' => '1000']);

        $carrito = $this->sale_lib->get_cart();

        $this->assertSame('1000', (string) (int) $carrito[1]['total']);
        $this->assertSame('4000', (string) (int) $carrito[2]['total'], 'Cuatro unidades a 1.000.');
    }

    // ========== Lo que nunca se reprecia ==========

    public function testAKitIngredientOfTheSameItemIsNotDraggedAlong(): void
    {
        $this->conCarrito([
            1 => $this->linea(7, '0', '1'),
            2 => $this->linea(7, '0.00', '1', ['print_option' => PRINT_NO]),
        ]);

        $this->sale_lib->apply_reprice('1', '5000', ['price' => '5000']);

        $carrito = $this->sale_lib->get_cart();

        $this->assertSame('0.00', $carrito[2]['price'], 'El precio de un ingrediente lo compone el kit, no la caja.');
        $this->assertNull(Sale_lib::line_pending_reprice($carrito[2]));
    }

    public function testRepricingALineThatCannotBeRepricedDoesNothingAtAll(): void
    {
        $this->conCarrito([
            1 => $this->linea(7, '0', '1', ['item_type' => ITEM_AMOUNT_ENTRY]),
        ]);

        $this->assertSame(0, $this->sale_lib->apply_reprice('1', '5000', ['price' => '5000']));
        $this->assertSame('0', $this->sale_lib->get_cart()[1]['price']);
    }

    public function testTheLinesThatCanBeRepricedAreOnlyOrdinaryItems(): void
    {
        $this->assertTrue(Sale_lib::line_can_be_repriced($this->linea(7, '100', '1')));
        $this->assertFalse(Sale_lib::line_can_be_repriced($this->linea(7, '100', '1', ['item_type' => ITEM_KIT])));
        $this->assertFalse(Sale_lib::line_can_be_repriced($this->linea(7, '100', '1', ['item_type' => ITEM_AMOUNT_ENTRY])));
        $this->assertFalse(Sale_lib::line_can_be_repriced($this->linea(7, '100', '1', ['item_type' => ITEM_TEMP])));
        $this->assertFalse(Sale_lib::line_can_be_repriced($this->linea(7, '100', '1', ['print_option' => PRINT_NO])));
        $this->assertFalse(Sale_lib::line_can_be_repriced($this->linea(0, '100', '1')));
    }

    // ========== Los carritos que ya estaban abiertos ==========

    /**
     * La que mantiene la caja abierta el día del despliegue. Toda venta en curso cuando esto salga
     * tiene líneas escritas antes de que la clave existiera, y ninguna migración las alcanza: el
     * carrito está en la sesión.
     */
    public function testALineWrittenBeforeThisFeatureExistedIsReadWithoutRaising(): void
    {
        $vieja = ['item_id' => 3, 'name' => 'Empanada', 'quantity' => '2', 'price' => '5000'];

        $this->assertNull(Sale_lib::line_pending_reprice($vieja));
        $this->assertTrue(Sale_lib::line_can_be_repriced($vieja), 'Sin print_option ni item_type, se trata como artículo normal.');
    }

    public function testAnIntentionThatIsNotAnArrayIsIgnored(): void
    {
        $this->assertNull(Sale_lib::line_pending_reprice(['reprice' => 'lo que sea']));
        $this->assertNull(Sale_lib::line_pending_reprice(['reprice' => null]));
    }
}
