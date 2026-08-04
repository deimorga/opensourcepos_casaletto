<?php

namespace Config;

use CodeIgniter\Database\Config;

/**
 * Database Configuration
 */
class Database extends Config
{
    /**
     * The directory that holds the Migrations and Seeds directories.
     */
    public string $filesPath = APPPATH . 'Database' . DIRECTORY_SEPARATOR;

    /**
     * Lets you choose which connection group to use if no other is specified.
     */
    public string $defaultGroup = 'default';

    /**
     * The default database connection.
     *
     * @var array<string, mixed>
     */
    public array $default = [
        'DSN'          => '',
        'hostname'     => 'localhost',
        'username'     => 'admin',
        'password'     => 'pointofsale',
        'database'     => 'ospos',
        'DBDriver'     => 'MySQLi',
        'DBPrefix'     => 'ospos_',
        'pConnect'     => false,
        'DBDebug'      => (ENVIRONMENT !== 'production'),
        'charset'      => 'utf8mb4',
        'DBCollat'     => 'utf8mb4_general_ci',
        'swapPre'      => '',
        'encrypt'      => false,
        'compress'     => false,
        'strictOn'     => false,
        'failover'     => [],
        'port'         => 3306,
        'numberNative' => false,
        'foundRows'    => false,
        'dateFormat'   => [
            'date'     => 'Y-m-d',
            'datetime' => 'Y-m-d H:i:s',
            'time'     => 'H:i:s',
        ],
    ];

    /**
     * This database connection is used when running PHPUnit database tests.
     *
     * @var array<string, mixed>
     */
    public array $tests = [
        'DSN'         => '',
        'hostname'    => 'localhost',
        'username'    => 'admin',
        'password'    => 'pointofsale',
        'database'    => 'ospos',
        'DBDriver'    => 'MySQLi',
        'DBPrefix'    => 'ospos_',
        'pConnect'    => false,
        'DBDebug'     => (ENVIRONMENT !== 'production'),
        'charset'     => 'utf8mb4',
        'DBCollat'    => 'utf8mb4_general_ci',
        'swapPre'     => '',
        'encrypt'     => false,
        'compress'    => false,
        'strictOn'    => false,
        'failover'    => [],
        'port'        => 3306,
        'foreignKeys' => true,
        'busyTimeout' => 1000,
        'synchronous' => null,
        'dateFormat'  => [
            'date'     => 'Y-m-d',
            'datetime' => 'Y-m-d H:i:s',
            'time'     => 'H:i:s',
        ],
    ];

    /**
     * This database connection is used when developing against non-production data.
     *
     * @var array
     */
    public $development = [
        'DSN'          => '',
        'hostname'     => 'localhost',
        'username'     => 'admin',
        'password'     => 'pointofsale',
        'database'     => 'ospos',
        'DBDriver'     => 'MySQLi',
        'DBPrefix'     => 'ospos_',
        'pConnect'     => false,
        'DBDebug'      => (ENVIRONMENT !== 'production'),
        'charset'      => 'utf8mb4',
        'DBCollat'     => 'utf8mb4_general_ci',
        'swapPre'      => '',
        'encrypt'      => false,
        'compress'     => false,
        'strictOn'     => false,
        'failover'     => [],
        'port'         => 3306,
        'foreignKeys'  => true,
        'busyTimeout'  => 1000,
        'dateFormat'   => [
            'date'     => 'Y-m-d',
            'datetime' => 'Y-m-d H:i:s',
            'time'     => 'H:i:s',
        ],
    ];

    /**
     * Fixed connection to the platform control schema (tenant registry,
     * platform accounts). Unlike `default`/`tests`/`development`, this
     * group is never overwritten by the per-tenant connection swap -- it
     * always points at the one control schema, resolved via its own
     * PLATFORM_DB_* env vars instead of MYSQL_*.
     *
     * @var array<string, mixed>
     */
    public array $platform = [
        'DSN'          => '',
        'hostname'     => 'localhost',
        'username'     => 'admin',
        'password'     => 'pointofsale',
        'database'     => 'platform_control',
        'DBDriver'     => 'MySQLi',
        'DBPrefix'     => '',
        'pConnect'     => false,
        'DBDebug'      => (ENVIRONMENT !== 'production'),
        'charset'      => 'utf8mb4',
        'DBCollat'     => 'utf8mb4_general_ci',
        'swapPre'      => '',
        'encrypt'      => false,
        'compress'     => false,
        'strictOn'     => false,
        'failover'     => [],
        'port'         => 3306,
        'foreignKeys'  => true,
        'busyTimeout'  => 1000,
        'dateFormat'   => [
            'date'     => 'Y-m-d',
            'datetime' => 'Y-m-d H:i:s',
            'time'     => 'H:i:s',
        ],
    ];

    public function __construct()
    {
        parent::__construct();

        // Ensure that we always set the database group to 'tests' if
        // we are currently running an automated test suite, so that
        // we don't overwrite live data on accident.
        switch (ENVIRONMENT) {
            case 'testing':
                $this->defaultGroup = 'tests';
                break;
            case 'development';
                $this->defaultGroup = 'development';
                break;
        }

        foreach ([&$this->development, &$this->tests, &$this->default] as &$config) {
            $config['hostname'] = !getenv('MYSQL_HOST_NAME') ? $config['hostname'] : getenv('MYSQL_HOST_NAME');
            $config['username'] = !getenv('MYSQL_USERNAME') ? $config['username'] : getenv('MYSQL_USERNAME');
            $config['password'] = !getenv('MYSQL_PASSWORD') ? $config['password'] : getenv('MYSQL_PASSWORD');
            $config['database'] = !getenv('MYSQL_DB_NAME') ? $config['database'] : getenv('MYSQL_DB_NAME');
        }

        $this->platform['hostname'] = !getenv('PLATFORM_DB_HOST_NAME') ? $this->platform['hostname'] : getenv('PLATFORM_DB_HOST_NAME');
        $this->platform['username'] = !getenv('PLATFORM_DB_USERNAME') ? $this->platform['username'] : getenv('PLATFORM_DB_USERNAME');
        $this->platform['password'] = !getenv('PLATFORM_DB_PASSWORD') ? $this->platform['password'] : getenv('PLATFORM_DB_PASSWORD');
        $this->platform['database'] = !getenv('PLATFORM_DB_NAME') ? $this->platform['database'] : getenv('PLATFORM_DB_NAME');
    }
}
