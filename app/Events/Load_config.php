<?php

namespace App\Events;

use App\Libraries\MY_Migration;
use App\Libraries\PlatformContext;
use App\Models\Appconfig;
use CodeIgniter\Session\Session;
use Config\App;
use Config\OSPOS;
use Config\Services;

/**
 * @property Appconfig appconfig;
 * @property MY_Migration migration;
 * @property Session session;
 * @property mixed $config
 * @property mixed $migration_config
 */
class Load_config
{
    public Session $session;

    public function load_config(): void
    {
        if (PlatformContext::isPlatform()) {
            $this->loadPlatformConsoleConfig();

            return;
        }

        $migration_config = config('Migrations');
        $migration        = new MY_Migration($migration_config);
        // Without this, findMigrations() (called by is_latest()) scans
        // every registered namespace, including 'Platform' (added in
        // Fase 3 of the multi-tenant project -- see
        // docs/Tecnico/multi-tenant-arquitectura.md section 4.1). Its
        // migration version numbers are higher than App's, so
        // get_latest_migration() would return Platform's version while
        // get_current_version() checks App's own `migrations` table --
        // the two can never match, so is_latest() would always be
        // false and destroy the session on every single request.
        // Confirmed empirically while building the Fase 8 login flow:
        // every response carried a second Set-Cookie deleting the
        // session that had just been created one line earlier.
        $migration->setNamespace('App');

        $this->session = session();

        $config = config(OSPOS::class);

        if (! $migration->is_latest()) {
            $this->session->destroy();
        }

        $this->setDefaultLanguage($config);

        $language = Services::language();
        $language->setLocale(current_language_code());

        date_default_timezone_set($config->settings['timezone'] ?? ini_get('date.timezone'));

        bcscale(max(2, totals_decimals() + tax_decimals()));
    }

    /**
     * The platform console's version of everything above, which is deliberately almost nothing.
     *
     * WHY THE EARLY RETURN IS NOT OPTIONAL
     *
     * This method is hooked on post_controller_constructor, so it runs on EVERY request, and its
     * first act above is to destroy the session when the schema is behind the migration files. On
     * the console the default connection points at the control schema, which has no
     * `ospos_migrations` table at all: get_current_version() returns 0, it can never equal the
     * newest migration's version, and the session would be destroyed once per request. The login
     * form would set a session and the next request would throw it away -- nobody could ever be
     * logged in, and nothing anywhere would say why.
     *
     * WHY THE VALUES ARE FIXED HERE INSTEAD OF READ
     *
     * Everything the branch above reads comes from a business's app_config. The console has none,
     * and reading the nearest one would be reading somebody's data (see Config\OSPOS, which
     * short-circuits for the same reason).
     *
     *   - Locale: es-MX, the one Spanish variant this project uses. Not left to negotiation --
     *     Accept-Language from a Colombian browser negotiates against $supportedLocales and lands
     *     on es-ES, and a string written in one variant is invisible in the other with no error
     *     anywhere. CodeIgniter falls back es-MX -> es -> en per key, so the console still reads
     *     correctly while only Language/en/Platform.php exists.
     *   - Timezone: App::$appTimezone. Note that for the POS this property is NOT what governs --
     *     `app_config.timezone` in each business's database is -- but the console has no such row,
     *     so here it is exactly the right source.
     *   - bcscale: the literal 2. The POS derives it from totals_decimals() + tax_decimals(), and
     *     both read app_config keys the console does not have.
     */
    private function loadPlatformConsoleConfig(): void
    {
        Services::language()->setLocale(PlatformContext::LOCALE);

        date_default_timezone_set(config(App::class)->appTimezone);

        bcscale(2);
    }

    private function setDefaultLanguage(OSPOS $config): void
    {
        $languageCode = $config->settings['language_code'] ?? null;

        if (empty($config->settings) || $languageCode === null) {
            $config->settings['language']      = 'english';
            $config->settings['language_code'] = 'en';

            return;
        }

        if (! $this->languageExists($languageCode)) {
            $config->settings['language']      = 'english';
            $config->settings['language_code'] = 'en';
        }
    }

    private function languageExists(string $languageCode): bool
    {
        return file_exists(APPPATH . 'Language/' . $languageCode);
    }
}
