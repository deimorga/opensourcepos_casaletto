<?php

namespace App\Events;

use App\Libraries\MY_Migration;
use App\Models\Appconfig;
use CodeIgniter\Session\Handlers\DatabaseHandler;
use CodeIgniter\Session\Handlers\FileHandler;
use CodeIgniter\Session\Session;
use Config\OSPOS;
use Config\Services;

/**
 * @property my_migration migration;
 * @property session session;
 * @property appconfig appconfig;
 * @property mixed $migration_config
 * @property mixed $config
 */
class Load_config
{
    public Session $session;

    public function load_config(): void
    {
        $migration_config = config('Migrations');
        $migration = new MY_Migration($migration_config);
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

        if (!$migration->is_latest()) {
            $this->session->destroy();
        }

        $this->setDefaultLanguage($config);

        $language = Services::language();
        $language->setLocale(current_language_code());

        date_default_timezone_set($config->settings['timezone'] ?? ini_get('date.timezone'));

        bcscale(max(2, totals_decimals() + tax_decimals()));
    }

    private function setDefaultLanguage(OSPOS $config): void
    {
        $languageCode = $config->settings['language_code'] ?? null;

        if (empty($config->settings) || $languageCode === null) {
            $config->settings['language'] = 'english';
            $config->settings['language_code'] = 'en';
            return;
        }

        if (!$this->languageExists($languageCode)) {
            $config->settings['language'] = 'english';
            $config->settings['language_code'] = 'en';
        }
    }

    private function languageExists(string $languageCode): bool
    {
        return file_exists(APPPATH . 'Language/' . $languageCode);
    }
}
