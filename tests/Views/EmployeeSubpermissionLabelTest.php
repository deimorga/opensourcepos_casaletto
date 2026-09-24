<?php

declare(strict_types=1);

namespace Tests\Views;

use App\Models\Module;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Que un subpermiso se lea en español en la pantalla de Empleados.
 *
 * El defecto vivía en `app/Views/employees/form.php` y era una comparación siempre verdadera:
 *
 *     $lang_line = lang(ucfirst($lang_key));
 *     $lang_line = (lang(ucfirst($lang_key)) == $lang_line) ? ucwords(...) : $lang_line;
 *
 * La segunda línea compara `lang(...)` contra la variable a la que acababa de asignarle `lang(...)`.
 * Es siempre cierto, así que TODO subpermiso caía al sufijo humanizado en inglés y **ningún archivo
 * de idioma se consultaba jamás**. `Cashups.delete` y `Cashups.reopen` llevaban traducidos a es-MX
 * desde siempre y nunca aparecieron en pantalla.
 *
 * Sin esto, `order_tickets_void` se le mostraría como «Void» a un encargado que habla español.
 *
 * La prueba no renderiza la vista: hacerlo arrastra el modelo de empleados, el menú y la base. Lo
 * que comprueba es el mecanismo del que depende el arreglo -- qué devuelve `lang()` cuando encuentra
 * la línea y cuándo no -- más el guardarraíl sobre el archivo, porque la forma anterior es
 * exactamente la que un «arreglito» futuro volvería a escribir.
 *
 * @internal
 */
final class EmployeeSubpermissionLabelTest extends CIUnitTestCase
{
    private string $view;

    protected function setUp(): void
    {
        parent::setUp();

        $this->view = file_get_contents(APPPATH . 'Views/employees/form.php');
        service('language')->setLocale('es-MX');
    }

    /**
     * El mecanismo entero: `Language::getLine()` termina en `$output ??= $line`, así que una línea
     * ausente vuelve como la clave misma. Comparar contra la clave es la ÚNICA forma de distinguir
     * una traducción real de una ausencia.
     */
    public function testLangHandsTheKeyBackWhenThereIsNoTranslation(): void
    {
        $missing = 'Order_tickets.no_hay_una_clave_con_este_nombre';

        $this->assertSame($missing, lang($missing));
    }

    /**
     * La traducción que el defecto llevaba años escondiendo.
     */
    public function testAnExistingSubpermissionLineNoLongerLooksLikeAMiss(): void
    {
        $key = 'Cashups.delete';

        $this->assertNotSame($key, lang($key), 'Cashups.delete está traducida y tiene que distinguirse de una ausencia.');
        $this->assertSame('Permitir borrar', lang($key));
    }

    /**
     * El sufijo se corta por el largo del módulo, no en el primer guion bajo. Esta prueba antes
     * ESCRIBÍA 'void' a mano en vez de pedírselo al código de la vista, y por eso no vio que la vista
     * sacaba 'tickets_void': en staging la etiqueta salía «Tickets Void» (2026-09-23).
     */
    public function testTheSuffixIsCutByTheModuleNotAtTheFirstUnderscore(): void
    {
        $this->assertSame('void', Module::subpermission_suffix('order_tickets_void', 'order_tickets'));
        $this->assertSame('delete', Module::subpermission_suffix('cashups_delete', 'cashups'));
        $this->assertSame('stock', Module::subpermission_suffix('sales_stock', 'sales'));
        $this->assertSame('sales_taxes', Module::subpermission_suffix('reports_sales_taxes', 'reports'));
    }

    /**
     * La clave del subpermiso nuevo, construida con la MISMA función que usa la vista.
     */
    public function testTheOrderTicketVoidSubpermissionReadsInSpanish(): void
    {
        $key = ucfirst('order_tickets' . '.' . Module::subpermission_suffix('order_tickets_void', 'order_tickets'));

        $this->assertSame('Order_tickets.void', $key);
        $this->assertNotSame($key, lang($key));
        $this->assertStringContainsString('cancelar', mb_strtolower(lang($key)));
    }

    /**
     * Los tres locales que el fork mantiene llevan las mismas claves. La aplicación corre en es-MX:
     * una cadena escrita solo en es-ES es invisible y la pantalla sale en inglés sin dar error.
     */
    public function testTheThreeMaintainedLocalesCarryTheSameKeys(): void
    {
        $en = array_keys(require APPPATH . 'Language/en/Order_tickets.php');
        $mx = array_keys(require APPPATH . 'Language/es-MX/Order_tickets.php');
        $es = array_keys(require APPPATH . 'Language/es-ES/Order_tickets.php');

        sort($en);
        sort($mx);
        sort($es);

        $this->assertSame($en, $mx);
        $this->assertSame($en, $es);
    }

    /**
     * El guardarraíl. La comparación tiene que ser contra la CLAVE, no contra la variable recién
     * asignada.
     */
    public function testTheViewComparesTheTranslationAgainstTheKeyAndNotAgainstItself(): void
    {
        $this->assertStringContainsString(
            '$lang_line === $lang_key',
            $this->view,
            'La etiqueta se decide comparando lo que devolvió lang() contra la clave que se le pidió.'
        );

        $this->assertStringNotContainsString(
            'lang(ucfirst($lang_key)) == $lang_line',
            $this->view,
            'Ésta es la comparación siempre verdadera que hacía invisible todo archivo de idioma.'
        );
    }

    /**
     * El respaldo sigue existiendo: un subpermiso sin traducción no puede quedar en blanco.
     */
    public function testAnUntranslatedSubpermissionStillFallsBackToItsHumanisedSuffix(): void
    {
        $this->assertStringContainsString(
            "ucwords(str_replace('_', ' ', \$suffix))",
            $this->view
        );
    }

    /**
     * El guardarraíl del segundo defecto: la vista usa la función, no un explode en el primer '_'.
     */
    public function testTheViewUsesTheSuffixFunctionAndNotAnExplode(): void
    {
        $this->assertStringContainsString('Module::subpermission_suffix(', $this->view);
        $this->assertStringNotContainsString("explode('_', \$permission->permission_id", $this->view);
    }
}
