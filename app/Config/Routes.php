<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->setDefaultController('Login');

$routes->get('/', 'Login::index');
$routes->get('login', 'Login::index');
$routes->post('login', 'Login::index');

// El segundo factor de una entrada de plataforma al punto de venta de un negocio. Solo responde
// mientras haya una cuenta pendiente en la sesión; sin ella redirige a `login`, así que la ruta no
// sirve para averiguar nada. Ver App\Libraries\Platform_business_entry.
// Canjear el pase que emite la consola al abrir un negocio. Solo GET: es el destino de una
// redirección, no un formulario. Ver App\Libraries\Platform_business_pass.
$routes->get('login/pass', 'Login::pass');

$routes->get('login/totp', 'Login::totp');
$routes->post('login/totp', 'Login::totp');
$routes->post('migrate', 'Login::migrate');

// Fase 8: neutral login for business owners + platform admins (separate
// from Employee::login() above), and the business-management platform.
// See docs/Tecnico/multi-tenant-arquitectura.md section 10.
$routes->get('platform/login', 'PlatformLogin::index');
$routes->post('platform/login', 'PlatformLogin::index');
$routes->get('platform/logout', 'PlatformLogin::logout');
$routes->get('platform/select', 'PlatformLogin::selectIndex');
$routes->get('platform/select/(:segment)', 'PlatformLogin::select/$1');

$routes->get('platform/admin', 'PlatformAdmin::index');
$routes->get('platform/admin/new', 'PlatformAdmin::newTenant');
$routes->post('platform/admin/create', 'PlatformAdmin::create');
$routes->post('platform/admin/(:segment)/suspend', 'PlatformAdmin::suspend/$1');
$routes->post('platform/admin/(:segment)/activate', 'PlatformAdmin::activate/$1');
$routes->get('platform/admin/(:segment)/delete', 'PlatformAdmin::confirmDelete/$1');
$routes->post('platform/admin/(:segment)/delete', 'PlatformAdmin::delete/$1');

// Entrega 3 -- la ficha del negocio y el restablecimiento de su contraseña (secciones 6.3 y D5).
//
// La ficha va DESPUÉS de todas las rutas de arriba y no antes. `(:segment)` acepta cualquier cosa
// que no lleve barra, incluido `new`, así que puesta primero se tragaría `platform/admin/new` y el
// formulario de alta dejaría de existir. CodeIgniter resuelve por orden de declaración y esa es
// toda la protección que hay.
// Entrega 5 -- entrar al punto de venta del negocio desde la consola, con un pase de un solo uso.
// Va con las demás rutas de segmento y ANTES de la ficha, por la misma razón de orden de arriba.
$routes->get('platform/admin/(:segment)/enter', 'PlatformAdmin::enter/$1');

$routes->get('platform/admin/(:segment)/reset-password', 'PlatformAdmin::confirmResetPassword/$1');
$routes->post('platform/admin/(:segment)/reset-password', 'PlatformAdmin::resetPassword/$1');
$routes->get('platform/admin/(:segment)', 'PlatformAdmin::show/$1');

// ---------------------------------------------------------------------------------------------
// Entrega 2 -- "Cerrar la llave suelta". Superadministrators, the second factor, and the record
// of what the console changed. See docs/Funcional/gestion-de-plataforma-y-negocios.md section 6
// and docs/Tecnico/gestion-de-plataforma-y-negocios.md section 8.
//
// EVERY route of the Entrega is declared here AT ONCE, before either half of it is built. Two
// agents work these screens in parallel and this file is the one they would both have to edit;
// declaring the routes up front is what keeps that from being a merge conflict on the one file
// whose conflicts are silent -- a lost line here is a 404 nobody notices until the screen it
// belonged to is opened.
//
// Nothing here is host-restricted, and that is not an oversight: RouteCollection snapshots
// HTTP_HOST when it is constructed, so a host-restricted route would simply not exist under
// PHPUnit. The console is confined to its own address by App\Filters\PlatformHost instead.
//
// The static paths come before the (:num) patterns. They do not collide today -- "password" and
// "totp" are not numbers -- but the order costs nothing and survives whoever adds the next one.
// ---------------------------------------------------------------------------------------------

// The second factor at login (D11). Not under platform/accounts: at this point nobody is logged
// in, the account is only PENDING, and this is the screen that finishes what platform/login
// started. A recovery code is typed into the same field as a six-digit code -- one screen,
// because whoever is on it has already lost their usual way in and does not need a second
// decision to make.
$routes->get('platform/login/totp', 'PlatformLogin::totp');
$routes->post('platform/login/totp', 'PlatformLogin::totp');

// Superadministrators (section 6.1). The listing answers "which of these accounts should not
// exist?", so it is not a generic CRUD and there is no edit screen: an account is created,
// unlocked, or removed.
$routes->get('platform/accounts', 'PlatformAccounts::index');
$routes->get('platform/accounts/new', 'PlatformAccounts::newAccount');
$routes->post('platform/accounts/create', 'PlatformAccounts::create');

// Changing your OWN password. There is deliberately no route for changing somebody else's: a
// superadministrator who cannot get in is unlocked or replaced, never quietly re-keyed by a peer.
$routes->get('platform/accounts/password', 'PlatformAccounts::password');
$routes->post('platform/accounts/password', 'PlatformAccounts::changePassword');

// Enrolling, confirming and removing the second factor, always for the account in session.
// Enrolment is a POST because it mints a secret -- a GET that changes state would be armed by
// any prefetch. The confirmation is separate and demands a working code, so nothing is ever left
// switched on that has not been proven to work.
$routes->get('platform/accounts/totp', 'PlatformTotp::index');
$routes->post('platform/accounts/totp/enroll', 'PlatformTotp::enroll');
$routes->post('platform/accounts/totp/confirm', 'PlatformTotp::confirm');
$routes->post('platform/accounts/totp/disable', 'PlatformTotp::disable');
$routes->post('platform/accounts/totp/recovery-codes', 'PlatformTotp::regenerateRecoveryCodes');

// Deleting another superadministrator: GET confirms, POST acts, and the POST demands the email
// typed out. Same shape as the business delete screen above, for the same reason -- a checkbox is
// something you tick on the wrong row, a name is something you have to read first.
$routes->get('platform/accounts/(:num)/delete', 'PlatformAccounts::confirmDelete/$1');
$routes->post('platform/accounts/(:num)/delete', 'PlatformAccounts::delete/$1');

// Lifting the brake of D8 on somebody else. POST only: it changes state.
$routes->post('platform/accounts/(:num)/unlock', 'PlatformAccounts::unlock/$1');

// The activity log (section 6.5). Read-only, and there is no route that deletes from it.
$routes->get('platform/activity', 'PlatformActivityLog::index');

$routes->add('no_access/index/(:segment)', 'No_access::index/$1');
$routes->add('no_access/index/(:segment)/(:segment)', 'No_access::index/$1/$2');

// Write-offs: recording stock that was lost, with a classified reason, and the report on it.
//
// Declared explicitly even though auto-routing would find these methods anyway: the report takes
// its date range in the path, and with a tenant configured to show times those segments contain a
// space and two colons, so it is worth being able to see the shape of the URL in one place.
// (:any) rather than (:segment) for the same reason -- the dates arrive urlencoded.
$routes->get('writeoffs', 'Writeoffs::getIndex');
$routes->post('writeoffs/save', 'Writeoffs::postSave');
$routes->get('writeoffs/suggest', 'Writeoffs::getSuggest');
$routes->get('writeoffs/report', 'Writeoffs::getReport');
$routes->get('writeoffs/report/(:any)/(:any)/(:any)', 'Writeoffs::getReport/$1/$2/$3');

// Analytical reports. Declared before the wildcards below: they do not collide today, but an
// explicit order costs nothing and survives whoever adds the next (:any) pattern.
//
// The word order is not cosmetic. Reports::__construct() derives the required permission from the
// LAST underscore-separated word of URI segment 2, so a path ending in "_expenses" would demand a
// reports_expenses grant that does not exist. Ending it in "_analytics" derives reports_analytics,
// which is the permission this report actually has.
$routes->add('reports/income_expenses_analytics', 'Reports::income_expenses_analytics');
$routes->add('reports/income_expenses_analytics/search', 'Reports::getIncome_expenses_search');

$routes->add('reports/summary_(:any)/(:any)/(:any)', 'Reports::Summary_$1/$2/$3/$4');
$routes->add('reports/summary_expenses_categories', 'Reports::date_input_only');
$routes->add('reports/summary_payments', 'Reports::date_input_only');
$routes->add('reports/summary_discounts', 'Reports::summary_discounts_input');
$routes->add('reports/summary_(:any)', 'Reports::date_input');

$routes->add('reports/graphical_(:any)/(:any)/(:any)', 'Reports::Graphical_$1/$2/$3/$4');
$routes->add('reports/graphical_summary_expenses_categories', 'Reports::date_input_only');
$routes->add('reports/graphical_summary_discounts', 'Reports::summary_discounts_input');
$routes->add('reports/graphical_(:any)', 'Reports::date_input');

$routes->add('reports/inventory_(:any)/(:any)', 'Reports::Inventory_$1/$2');
$routes->add('reports/inventory_low', 'Reports::inventory_low');
$routes->add('reports/inventory_summary', 'Reports::inventory_summary_input');
$routes->add('reports/inventory_summary/(:any)/(:any)/(:any)', 'Reports::inventory_summary/$1/$2/$3');

$routes->add('reports/detailed_(:any)/(:any)/(:any)/(:any)', 'Reports::Detailed_$1/$2/$3/$4');
$routes->add('reports/detailed_sales', 'Reports::date_input_sales');
$routes->add('reports/detailed_receivings', 'Reports::date_input_recv');

$routes->add('reports/specific_(:any)/(:any)/(:any)/(:any)', 'Reports::Specific_$1/$2/$3/$4');
$routes->add('reports/specific_customers', 'Reports::specific_customer_input');
$routes->add('reports/specific_employees', 'Reports::specific_employee_input');
$routes->add('reports/specific_discounts', 'Reports::specific_discount_input');
$routes->add('reports/specific_suppliers', 'Reports::specific_supplier_input');
