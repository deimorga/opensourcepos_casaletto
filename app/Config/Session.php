<?php

namespace Config;

use App\Libraries\PlatformContext;
use CodeIgniter\Config\BaseConfig;
use CodeIgniter\Session\Handlers\BaseHandler;
use CodeIgniter\Session\Handlers\DatabaseHandler;
use CodeIgniter\Session\Handlers\FileHandler;
use Exception;

class Session extends BaseConfig
{
    /**
     * --------------------------------------------------------------------------
     * Session Driver
     * --------------------------------------------------------------------------
     *
     * The session storage driver to use:
     * - `CodeIgniter\Session\Handlers\FileHandler`
     * - `CodeIgniter\Session\Handlers\DatabaseHandler`
     * - `CodeIgniter\Session\Handlers\MemcachedHandler`
     * - `CodeIgniter\Session\Handlers\RedisHandler`
     *
     * @var class-string<BaseHandler>
     */
    public string $driver = DatabaseHandler::class;

    /**
     * --------------------------------------------------------------------------
     * Session Cookie Name
     * --------------------------------------------------------------------------
     *
     * The session cookie name, must contain only [0-9a-z_-] characters
     */
    public string $cookieName = 'ospos_session';

    /**
     * --------------------------------------------------------------------------
     * Session Expiration
     * --------------------------------------------------------------------------
     *
     * The number of SECONDS you want the session to last.
     * Setting to 0 (zero) means expire when the browser is closed.
     */
    public int $expiration = 7200;

    /**
     * --------------------------------------------------------------------------
     * Session Save Path
     * --------------------------------------------------------------------------
     *
     * The location to save sessions to and is driver dependent.
     *
     * For the 'files' driver, it's a path to a writable directory.
     * WARNING: Only absolute paths are supported!
     *
     * For the 'database' driver, it's a table name.
     * Please read up the manual for the format with other session drivers.
     *
     * IMPORTANT: You are REQUIRED to set a valid save path!
     */
    public string $savePath = 'sessions';

    /**
     * --------------------------------------------------------------------------
     * Session Match IP
     * --------------------------------------------------------------------------
     *
     * Whether to match the user's IP address when reading the session data.
     *
     * WARNING: If you're using the database driver, don't forget to update
     *          your session table's PRIMARY KEY when changing this setting.
     */
    public bool $matchIP = true;

    /**
     * --------------------------------------------------------------------------
     * Session Time to Update
     * --------------------------------------------------------------------------
     *
     * How many seconds between CI regenerating the session ID.
     */
    public int $timeToUpdate = 300;

    /**
     * --------------------------------------------------------------------------
     * Session Regenerate Destroy
     * --------------------------------------------------------------------------
     *
     * Whether to destroy session data associated with the old session ID
     * when auto-regenerating the session ID. When set to FALSE, the data
     * will be later deleted by the garbage collector.
     */
    public bool $regenerateDestroy = true;

    /**
     * --------------------------------------------------------------------------
     * Session Database Group
     * --------------------------------------------------------------------------
     *
     * DB Group for the database session.
     */
    public ?string $DBGroup = null;

    /**
     * --------------------------------------------------------------------------
     * Lock Retry Interval (microseconds)
     * --------------------------------------------------------------------------
     *
     * This is used for RedisHandler.
     *
     * Time (microseconds) to wait if lock cannot be acquired.
     * The default is 100,000 microseconds (= 0.1 seconds).
     */
    public int $lockRetryInterval = 100_000;

    /**
     * --------------------------------------------------------------------------
     * Lock Max Retries
     * --------------------------------------------------------------------------
     *
     * This is used for RedisHandler.
     *
     * Maximum number of lock acquisition attempts.
     * The default is 300 times. That is lock timeout is about 30 (0.1 * 300)
     * seconds.
     */
    public int $lockMaxRetries = 300;

    public function __construct()
    {
        parent::__construct();

        // The platform console keeps its session in the control schema, in a table of its own.
        // Everybody else is left exactly as they were -- DBGroup null (the default connection, i.e.
        // this business's own database) and savePath 'sessions'. That is not a formality: if the
        // host test ever answered true for one business too many, every logged-in cashier there
        // would be thrown out at once and their session looked for in a table that, for them, does
        // not exist. See App\Libraries\PlatformContext, which is where that test lives, exactly
        // once, as an exact match.
        if (PlatformContext::isPlatform()) {
            $this->DBGroup  = 'platform';
            $this->savePath = 'platform_sessions';
        }

        if ($this->driver === DatabaseHandler::class) {
            try {
                // The group MATTERS, and it was missing until 2026-09-01. Database::connect() with
                // no argument always hands back the default connection, so this check asked the
                // wrong database whether the table was there. Harmless while DBGroup was always
                // null; the moment it is 'platform' the answer is always no -- different schema,
                // and the default group prefixes every table name with `ospos_` -- so the driver
                // silently downgraded to file storage and the console's session table would have
                // stayed empty forever with nothing anywhere explaining it.
                $db = Database::connect($this->DBGroup);

                if (! $db->tableExists($this->savePath)) {
                    $this->driver   = FileHandler::class;
                    $this->savePath = WRITEPATH . 'session';
                }
            } catch (Exception $e) {
                // Database not available yet (e.g. fresh install before migrations).
                // Fall back to file-based sessions so the login/migration page
                // can still be served. Catches mysqli_sql_exception which is
                // not a subclass of DatabaseException but is a RuntimeException.
                $this->driver   = FileHandler::class;
                $this->savePath = WRITEPATH . 'session';
            }
        }
    }
}
