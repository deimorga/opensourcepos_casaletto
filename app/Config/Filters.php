<?php

namespace Config;

use CodeIgniter\Config\Filters as BaseFilters;
use CodeIgniter\Filters\Cors;
use CodeIgniter\Filters\CSRF;
use CodeIgniter\Filters\DebugToolbar;
use CodeIgniter\Filters\ForceHTTPS;
use CodeIgniter\Filters\Honeypot;
use CodeIgniter\Filters\InvalidChars;
use CodeIgniter\Filters\PageCache;
use CodeIgniter\Filters\PerformanceMetrics;
use CodeIgniter\Filters\SecureHeaders;
use App\Filters\TenantResolver;

class Filters extends BaseFilters
{
    /**
     * Configures aliases for Filter classes to
     * make reading things nicer and simpler.
     *
     * @var array<string, class-string|list<class-string>>
     *
     * [filter_name => classname]
     * or [filter_name => [classname1, classname2, ...]]
     */
    public array $aliases = [
        'csrf'          => CSRF::class,
        'toolbar'       => DebugToolbar::class,
        'honeypot'      => Honeypot::class,
        'invalidchars'  => InvalidChars::class,
        'secureheaders' => SecureHeaders::class,
        'cors'          => Cors::class,
        'forcehttps'    => ForceHTTPS::class,
        'pagecache'     => PageCache::class,
        'performance'   => PerformanceMetrics::class,
        'tenantresolver' => TenantResolver::class,
    ];

    /**
     * List of special required filters.
     *
     * The filters listed here are special. They are applied before and after
     * other kinds of filters, and always applied even if a route does not exist.
     *
     * Filters set by default provide framework functionality. If removed,
     * those functions will no longer work.
     *
     * @see https://codeigniter.com/user_guide/incoming/filters.html#provided-filters
     *
     * @var array{before: list<string>, after: list<string>}
     */
    public array $required = [
        'before' => [
            'forcehttps', // Force Global Secure Requests
            'pagecache',  // Web Page Caching
        ],
        'after' => [
            'pagecache',   // Web Page Caching
            'performance', // Performance Metrics
            'toolbar',     // Debug Toolbar
        ],
    ];

    /**
     * List of filter aliases that are always
     * applied before and after every request.
     *
     * @var array{
     *     before: array<string, array{except: list<string>|string}>|list<string>,
     *     after: array<string, array{except: list<string>|string}>|list<string>
     * }
     */
    public array $globals = [
        'before' => [
            // Must stay first, and must live in $globals (not $required)
            // to actually run first: resolves which tenant schema this
            // request uses before anything else opens a `default`
            // connection. See app/Filters/TenantResolver.php.
            //
            // Confirmed empirically (not assumed) that this had to move
            // here: CodeIgniter's Filters::initialize(), with the
            // project's default Config\Feature::$oldFilterOrder = false,
            // builds the final filter list as
            // array_merge($globals['before'], $required['before']) --
            // i.e. $globals['before'] entries run BEFORE $required['before']
            // ones, the opposite of what "required" suggests. With
            // tenantresolver in $required['before'], the 'csrf' filter
            // below ran first, and its session() call opened the
            // `default` connection against the UNRESOLVED (pre-swap)
            // schema; CodeIgniter\Database\Config::connect() then caches
            // that connection by group name, so every later
            // Database::connect('default') in the same request kept
            // returning that stale connection even after TenantResolver
            // correctly mutated the config array -- config(Database::class)
            // ->default['database'] showed the right tenant schema, but
            // the actual connected database did not. Found while testing
            // Fase 8's tenant login end to end: a freshly provisioned
            // tenant's own login page rendered Casaletto's app_config
            // instead of the new tenant's.
            'tenantresolver',
            'honeypot',
            'csrf' => ['except' => 'login|migrate'],
            'invalidchars',
        ],
        'after' => [
            'toolbar',
            'honeypot',
            'secureheaders',
        ],
    ];

    /**
     * List of filter aliases that works on a
     * particular HTTP method (GET, POST, etc.).
     *
     * Example:
     * 'POST' => ['foo', 'bar']
     *
     * If you use this, you should disable auto-routing because auto-routing
     * permits any HTTP method to access a controller. Accessing the controller
     * with a method you don't expect could bypass the filter.
     *
     * @var array<string, list<string>>
     */
    public array $methods = [];

    /**
     * List of filter aliases that should run on any
     * before or after URI patterns.
     *
     * Example:
     * 'isLoggedIn' => ['before' => ['account/*', 'profiles/*']]
     *
     * @var array<string, array<string, list<string>>>
     */
    public array $filters = [];

    /**
     * Constructor to conditionally disable CSRF filter in testing environment
     */
    public function __construct()
    {
        // Check for testing environment via env variable or constant
        $isTesting = ($_ENV['CI_ENVIRONMENT'] ?? $_SERVER['CI_ENVIRONMENT'] ?? getenv('CI_ENVIRONMENT')) === 'testing'
            || (defined('ENVIRONMENT') && ENVIRONMENT === 'testing');

        // Remove CSRF filter from globals in testing environment
        if ($isTesting) {
            // Remove the 'csrf' key from $globals['before'] while preserving array structure
            $this->globals['before'] = array_filter($this->globals['before'], static fn($key) => $key !== 'csrf', ARRAY_FILTER_USE_KEY);
        }
    }
}
