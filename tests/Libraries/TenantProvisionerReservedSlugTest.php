<?php

declare(strict_types=1);

namespace Tests\Libraries;

use App\Libraries\TenantProvisioner;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Infrastructure subdomains can never be a business's slug. 'mailpit' joined the list on 2026-10-26:
 * mailpit.staging.ospos-saas.micronuba.net is staging's mail inbox, and a test business with that slug
 * would fight it for the same host name (docker-compose.staging.yml).
 *
 * A reserved slug is refused before the registry is read, so no database is needed here.
 *
 * @internal
 */
final class TenantProvisionerReservedSlugTest extends CIUnitTestCase
{
    public function testTheMailInboxSlugIsReserved(): void
    {
        $this->assertSame(lang('Platform.error_slug_reserved', ['mailpit']), (new TenantProvisioner())->validateSlug('mailpit'));
    }

    public function testTheEarlierReservedSlugsStayReserved(): void
    {
        foreach (['staging', 'www', 'admin', 'platform', 'login', 'api', 'app'] as $slug) {
            $this->assertSame(lang('Platform.error_slug_reserved', [$slug]), (new TenantProvisioner())->validateSlug($slug), $slug);
        }
    }
}
