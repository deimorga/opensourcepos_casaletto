<?php

namespace Config;

use App\Libraries\PlatformContext;
use App\Libraries\TenantContext;
use App\Models\Appconfig;
use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Config\BaseConfig;
use Exception;

/**
 * This class holds the configuration options stored from the database so that on launch those settings can be cached
 * once in memory.  The settings are referenced frequently, so there is a significant performance hit to not storing
 * them.
 */
class OSPOS extends BaseConfig
{
    public array $settings     = [];
    public string $commit_sha1 = 'dev';    // TODO: Travis scripts need to be updated to replace this with the commit hash on build
    private CacheInterface $cache;

    public function __construct()
    {
        parent::__construct();
        $this->cache = Services::cache();
        $this->set_settings();
    }

    public function set_settings(): void
    {
        if (PlatformContext::isPlatform()) {
            $this->settings = $this->getDefaultSettings();

            return;
        }

        $cacheKey = $this->settingsCacheKey();
        $cache    = $this->cache->get($cacheKey);

        if ($cache) {
            $this->settings = decode_array($cache);

            return;
        }

        try {
            $db = Database::connect();

            if (! $db->tableExists('app_config')) {
                $this->settings = $this->getDefaultSettings();

                return;
            }

            $appconfig = model(Appconfig::class);

            foreach ($appconfig->get_all()->getResult() as $app_config) {
                $this->settings[$app_config->key] = $app_config->value;
            }
            $this->cache->save($cacheKey, encode_array($this->settings));
        } catch (Exception $e) {
            $this->settings = $this->getDefaultSettings();
        }
    }

    /**
     * "settings" alone would leak one tenant's config (name, logo,
     * currency, taxes) into another's if they share this cache backend
     * -- suffixed by the resolved tenant slug when there is one,
     * unchanged ("settings") for the single-tenant/unresolved case.
     *
     * NOT reachable from the platform console, and that is the point.
     * The console resolves no tenant, so this would hand it the bare
     * "settings" key -- and CASALETTO RESOLVES NO TENANT EITHER, since
     * it trades on its own legacy address. The bare key IS Casaletto's
     * configuration. Reading it would show the console a business's
     * settings; writing it would overwrite the settings of the business
     * that is trading with whatever the console managed to load from a
     * control schema that has no app_config at all. Both paths into
     * this method short-circuit above.
     */
    private function settingsCacheKey(): string
    {
        return TenantContext::isResolved()
            ? 'settings_' . TenantContext::slug()
            : 'settings';
    }

    private function getDefaultSettings(): array
    {
        return [
            'language'      => 'english',
            'language_code' => 'en',
            'company'       => 'Home',
            'barcode_type'  => 'Code39',
        ];
    }

    public function update_settings(): void
    {
        // Guarded separately from set_settings(), and this is the more destructive of the two:
        // delete() on the bare "settings" key would throw away the cached configuration of the
        // business that is trading. Reaching set_settings()'s short circuit is not enough, because
        // the damage would already be done one line earlier.
        if (PlatformContext::isPlatform()) {
            $this->set_settings();

            return;
        }

        $this->cache->delete($this->settingsCacheKey());
        $this->set_settings();
    }
}
