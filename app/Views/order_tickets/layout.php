<?php
/**
 * Shared shell of the waiter's order-ticket screens: top bar, flash messages, content, scripts.
 *
 * WHY THIS LAYOUT EXISTS INSTEAD OF THE USUAL HEADER
 *
 * The waiter opens these screens on the browser of their own phone, standing at the table (D16:
 * there is no mobile app). This is the only responsive screen in the system (D18). The shared POS
 * header cannot be reused: it declares no viewport, so a phone renders it at desktop width, and
 * adding one there would change the rendering of every internal screen at once, none of which was
 * designed for that width. Its gulp inject blocks are also real CSS/JS loading, so it is not a file
 * to experiment on. See docs/Tecnico/comandas-y-cuenta-abierta.md §3.13 and §9.
 *
 * WHAT WAS TAKEN FROM THE PLATFORM CONSOLE LAYOUT, AND WHAT WAS NOT
 *
 * Only the plumbing: viewport, <base>, robots, Bootstrap 5 (Bootswatch Flatly) and renderSection.
 * The console itself is a desktop page (navbar-expand, no breakpoints). The mobile behaviour lives
 * in public/css/order_tickets.css.
 *
 * NO JAVASCRIPT DEPENDENCY
 *
 * No Bootstrap 5 JS bundle is shipped in public/resources, so nothing here may need one: no
 * collapsible navbar, no dropdowns. It is a single-purpose screen; a flat bar is enough.
 *
 * CONTRACT FOR THE VIEWS THAT EXTEND IT
 *
 *     <?php $this->extend('order_tickets/layout'); $this->section('content'); ?> ... <?php $this->endSection(); ?>
 *
 * Optional section `scripts`, rendered at the end of <body>.
 *
 * @var string      $title         goes in <title> and in the <h1>
 * @var string      $employee_name shown in the top bar, so the waiter sees whose session this is
 * @var string|null $back_url      when set, a "←" link before the title (ticket -> list)
 */

// Every variable is optional: a stale controller or a half-applied deploy must render a page,
// not a white screen in the waiter's hand.
$title         ??= lang('Module.order_tickets');
$employee_name ??= '';
$back_url      ??= null;

$success = session()->getFlashdata('success');
$warning = session()->getFlashdata('warning');
$error   = session()->getFlashdata('error');
?>
<!doctype html>
<html lang="<?= esc(current_language_code()) ?>">

<head>
    <meta charset="utf-8">
    <base href="<?= base_url() ?>">
    <title><?= esc($title) ?></title>
    <?php // viewport-fit=cover is what makes env(safe-area-inset-*) return non-zero values on iPhones with a gesture bar. ?>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <?php // Flatly: same theme as the platform console. The POS's Bootstrap 3 must never be mixed in. ?>
    <link rel="stylesheet" href="resources/bootswatch5/flatly/bootstrap.min.css">
    <link rel="stylesheet" href="css/order_tickets.css">
</head>

<body class="ot-body">
    <header class="ot-topbar bg-primary text-white">
        <div class="ot-container ot-topbar-inner">
            <span class="ot-topbar-brand"><?= esc(lang('Module.order_tickets')) ?></span>
            <div class="ot-topbar-user">
                <?php if ($employee_name !== ''): ?>
                    <span class="ot-topbar-name"><?= esc($employee_name) ?></span>
                <?php endif; ?>
                <?php
                // NOT home/logout. Home is a Secure_Controller gated on the `home` grant, and a waiter
                // granted only order_tickets does not have it: that link would land them on
                // no_access, unable to leave. OrderTickets carries its own logout for that reason.
                ?>
                <a class="ot-topbar-link" href="<?= base_url('comandas/salir') ?>"><?= esc(lang('Login.logout')) ?></a>
            </div>
        </div>
    </header>

    <main class="ot-container ot-main">
        <div class="ot-heading">
            <?php if (! empty($back_url)): ?>
                <a class="ot-back" href="<?= esc($back_url, 'attr') ?>" aria-label="<?= esc(lang('Order_tickets.back'), 'attr') ?>">
                    <span aria-hidden="true">&larr;</span>
                </a>
            <?php endif; ?>
            <h1 class="ot-title"><?= esc($title) ?></h1>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success" role="status"><?= esc($success) ?></div>
        <?php endif; ?>
        <?php if ($warning): ?>
            <?php // D9: something the kitchen already had was touched. Allowed, and said out loud. ?>
            <div class="alert alert-warning" role="status"><?= esc($warning) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert"><?= esc($error) ?></div>
        <?php endif; ?>

        <?= $this->renderSection('content') ?>
    </main>

    <?= $this->renderSection('scripts') ?>
</body>

</html>
