<?php

namespace App\Libraries;

/**
 * Single read point for "which tenant is this request for", populated
 * once per request by TenantResolver (app/Filters/TenantResolver.php)
 * before anything else runs. A static holder rather than a Service,
 * because it must be trivially readable from anywhere (cache key
 * suffixing, future provisioning tooling) without pulling in DI.
 *
 * Unresolved (no matching row in platform.tenants for the request
 * Host) is a valid state, not an error -- it means "fall through to
 * the single-tenant default connection", which is how Casaletto itself
 * keeps working unchanged until it's registered as a tenant.
 */
final class TenantContext
{
    private static ?int $tenantId = null;
    private static ?string $slug = null;
    private static ?string $dbName = null;

    public static function set(int $tenantId, string $slug, string $dbName): void
    {
        self::$tenantId = $tenantId;
        self::$slug = $slug;
        self::$dbName = $dbName;
    }

    public static function isResolved(): bool
    {
        return self::$tenantId !== null;
    }

    public static function tenantId(): ?int
    {
        return self::$tenantId;
    }

    public static function slug(): ?string
    {
        return self::$slug;
    }

    public static function dbName(): ?string
    {
        return self::$dbName;
    }

    /**
     * Test-only: clears state between PHPUnit cases, since static
     * properties would otherwise leak across tests in the same process.
     */
    public static function reset(): void
    {
        self::$tenantId = null;
        self::$slug = null;
        self::$dbName = null;
    }
}
