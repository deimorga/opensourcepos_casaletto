<?php

declare(strict_types=1);

namespace Tests\Views;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Guards the decisions behind the waiter's responsive layout and its stylesheet.
 *
 * Reads the files as source instead of rendering them, deliberately: the layout calls
 * current_language_code(), which reads the OSPOS settings and the logged-in employee from the
 * database, and this test must run without one. What is pinned here is exactly what a later
 * "small fix" would quietly undo: the viewport that makes env() work, the separation from the
 * shared POS header, the Bootstrap 5 theme instead of the POS's Bootstrap 3, and the handful of
 * CSS rules that only fail on a real phone (iOS input zoom, thumb-size targets, the gesture bar).
 * See docs/Tecnico/comandas-y-cuenta-abierta.md §3.13 and §9.
 *
 * @internal
 */
final class OrderTicketsLayoutTest extends CIUnitTestCase
{
    private string $layout;
    private string $css;

    protected function setUp(): void
    {
        parent::setUp();

        $this->layout = (string) file_get_contents(APPPATH . 'Views/order_tickets/layout.php');
        $this->css    = (string) file_get_contents(FCPATH . 'css/order_tickets.css');
    }

    /**
     * Without width=device-width a phone renders at ~980px and shrinks the page; without
     * viewport-fit=cover, env(safe-area-inset-bottom) is always 0 and the action bar sits under
     * the iPhone gesture bar.
     */
    public function testTheLayoutDeclaresAMobileViewportThatEnablesSafeAreaInsets(): void
    {
        $this->assertMatchesRegularExpression('/<meta name="viewport" content="[^"]*width=device-width/', $this->layout);
        $this->assertMatchesRegularExpression('/<meta name="viewport" content="[^"]*viewport-fit=cover/', $this->layout);
    }

    /**
     * The shared header declares no viewport and carries gulp inject blocks that are real asset
     * loading. Pulling it in here would make this page desktop-only again, and "fixing" that by
     * adding a viewport to the header would change every screen in the system.
     */
    public function testTheLayoutNeverUsesTheSharedPosHeader(): void
    {
        $this->assertStringNotContainsString('partial/header', $this->layout);
        $this->assertStringNotContainsString('partial/footer', $this->layout);
    }

    /**
     * Bootstrap 5 (Flatly) plus the screen's own stylesheet; never the POS's Bootstrap 3 nor the
     * register stylesheet, whose rules assume a desktop sales screen.
     */
    public function testTheLayoutLoadsBootstrapFiveAndItsOwnStylesheetOnly(): void
    {
        $this->assertStringContainsString('resources/bootswatch5/flatly/bootstrap.min.css', $this->layout);
        $this->assertStringContainsString('css/order_tickets.css', $this->layout);
        $this->assertStringNotContainsString('bootswatch/', $this->layout);
        $this->assertStringNotContainsString('register.css', $this->layout);
    }

    /**
     * No Bootstrap 5 JS bundle is shipped, so the layout must work with no script at all.
     */
    public function testTheLayoutDoesNotDependOnBootstrapJavascript(): void
    {
        $this->assertStringNotContainsString('data-bs-toggle', $this->layout);
        $this->assertStringNotContainsString('bootstrap.bundle', $this->layout);
        $this->assertStringNotContainsString('<script', $this->layout);
    }

    /**
     * The contract the ticket views (tasks 1.13-1.15) rely on.
     */
    public function testTheLayoutHonoursItsContractWithTheViews(): void
    {
        $this->assertStringContainsString("\$this->renderSection('content')", $this->layout);
        $this->assertStringContainsString("\$this->renderSection('scripts')", $this->layout);

        foreach (['$title', '$employee_name', '$back_url'] as $variable) {
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($variable, '/') . '\s*\?\?=/',
                $this->layout,
                "{$variable} must default with ??= so a missing variable never breaks the page.",
            );
        }

        $this->assertStringContainsString("getFlashdata('success')", $this->layout);
        $this->assertStringContainsString("getFlashdata('error')", $this->layout);
        $this->assertStringContainsString('role="status"', $this->layout);
        $this->assertStringContainsString('role="alert"', $this->layout);
        // The waiter's own logout. home/logout is gated on the `home` grant, which a waiter granted
        // only order_tickets does not have: that link would strand them on no_access.
        $this->assertStringContainsString("base_url('comandas/salir')", $this->layout);
        $this->assertStringNotContainsString("base_url('home/logout')", $this->layout);
        $this->assertStringContainsString('current_language_code()', $this->layout);
    }

    /**
     * Media queries that actually do something: one for phones, one for the tablet at the till.
     */
    public function testTheStylesheetHasPhoneAndTabletBreakpoints(): void
    {
        $this->assertGreaterThanOrEqual(2, preg_match_all('/@media\s*\((?:max|min)-width/', $this->css));
        $this->assertStringContainsString('@media (max-width: 575.98px)', $this->css);
        $this->assertStringContainsString('@media (min-width: 768px)', $this->css);
    }

    public function testTouchTargetsAreAtLeastFortyFourPixels(): void
    {
        $this->assertStringContainsString('min-height: 44px', $this->css);
    }

    /**
     * iOS Safari zooms in on focus when an input's font is under 16px and does not zoom back.
     */
    public function testInputsUseAtLeastSixteenPixelFont(): void
    {
        $matched = preg_match('/\.ot-body input,[^{]*\{\s*font-size:\s*(\d+)px;/s', $this->css, $m);

        $this->assertSame(1, $matched, 'The input font-size rule is missing or was restructured.');
        $this->assertGreaterThanOrEqual(16, (int) $m[1]);
    }

    public function testTheActionBarIsStickyAndClearsTheGestureBar(): void
    {
        $this->assertMatchesRegularExpression('/\.ot-actionbar\s*\{[^}]*position:\s*sticky;[^}]*bottom:\s*0;/s', $this->css);
        $this->assertStringContainsString('env(safe-area-inset-bottom)', $this->css);
    }

    public function testTheUtilityClassesTheViewsUseExist(): void
    {
        foreach (['.ot-list', '.ot-line-voided', '.ot-line-sent', '.ot-badge-changed', '.ot-actionbar'] as $class) {
            $this->assertStringContainsString($class, $this->css);
        }
    }

    public function testReducedMotionIsHonoured(): void
    {
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $this->css);
    }

    /**
     * public/resources is built, not versioned; in CI it does not exist. Only when it does is the
     * theme's presence something this test can assert.
     */
    public function testTheBootstrapFiveThemeIsPresentWhenAssetsAreBuilt(): void
    {
        if (! is_dir(FCPATH . 'resources')) {
            $this->markTestSkipped('public/resources is not built here (assets are not built in CI); nothing to check.');
        }

        $this->assertFileExists(FCPATH . 'resources/bootswatch5/flatly/bootstrap.min.css');
    }
}
