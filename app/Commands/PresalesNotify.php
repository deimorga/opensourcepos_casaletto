<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Email_lib;
use App\Libraries\Presale_notifier;
use App\Libraries\TenantContext;
use App\Models\Presale_notification;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Config\OSPOS;
use Config\Services;
use Throwable;

/**
 * Envía los correos de preventas que esperan en la bandeja de salida de cada negocio y, desde las
 * 9:00 hora del negocio, encola los recordatorios de cuota del día
 * (docs/Tecnico/preventas-cartera-y-avisos.md §4.5).
 *
 * LO CORRE EL CRON DEL HOST, CADA MINUTO
 *
 *     * * * * * cd /root/POS_Casaletto && docker compose exec -T ospos php spark presales:notify ...
 *
 * (línea completa en docker/cron/presales-notify). No va en el arranque del contenedor, por la misma
 * razón que platform:support-employee: un fallo del correo no puede tumbar la caja.
 *
 * UN PROCESO POR NEGOCIO
 *
 * Sin argumento, recorre los negocios activos del registro y se lanza a sí mismo una vez por negocio,
 * con MYSQL_DB_NAME apuntando a su esquema: el mismo mecanismo de scripts/migrate-tenants.sh, y por
 * la misma razón (TenantMigrateOne.php): cambiar la conexión por defecto dentro de un comando ya
 * arrancado no es fiable. Además, cada negocio tiene su configuración en caché, su zona horaria y su
 * idioma, y un proceso propio es la única forma de que no se mezclen. Con un slug, hace solo ese.
 *
 * Un negocio que falla no detiene a los demás; el código de salida es 1 si alguno falló.
 */
class PresalesNotify extends BaseCommand
{
    /**
     * Correos por negocio y por corrida: muy por debajo del límite de envío de Resend.
     */
    private const BATCH = 50;

    /**
     * Desde esta hora del negocio se encolan los recordatorios del día (S3).
     */
    private const REMINDER_HOUR = 9;

    protected $group       = 'Platform';
    protected $name        = 'presales:notify';
    protected $description = 'Envía los correos de preventas pendientes y encola los recordatorios de cuota. Lo corre el cron cada minuto.';
    protected $usage       = 'presales:notify [slug]';
    protected $arguments   = [
        'slug' => 'Opcional: solo ese negocio. Sin él, todos los negocios activos del registro.',
    ];

    public function run(array $params)
    {
        $slug = trim((string) ($params[0] ?? ''));

        try {
            $tenants = $this->tenants($slug);
        } catch (Throwable $e) {
            CLI::error('No se pudo leer el registro de negocios: ' . $e->getMessage());

            return 1;
        }

        if ($slug !== '' && $tenants === []) {
            CLI::error("No hay ningún negocio con el slug «{$slug}».");

            return 1;
        }

        // Una instalación de un solo negocio: el esquema por defecto, en este mismo proceso.
        if ($tenants === []) {
            return $this->process('');
        }

        if ($slug !== '' && getenv('MYSQL_DB_NAME') === $tenants[0]['db_name']) {
            return $this->process($slug, $tenants[0]);
        }

        $failures = [];

        foreach ($tenants as $tenant) {
            if ($this->spawn($tenant) !== 0) {
                $failures[] = $tenant['slug'];
            }
        }

        if ($failures !== []) {
            CLI::error('Fallaron: ' . implode(', ', $failures));

            return 1;
        }

        return 0;
    }

    /**
     * Un negocio, ya con su esquema: recordatorios si es la hora y envío de lo pendiente.
     *
     * @param array{id: int|string, slug: string, db_name: string}|null $tenant
     */
    private function process(string $slug, ?array $tenant = null): int
    {
        $label = $slug === '' ? 'esquema por defecto' : $slug;

        helper('locale');

        try {
            if ($tenant !== null) {
                $this->use_tenant($tenant);
            }

            $config = config(OSPOS::class);
            // Cualquier configuración cargada antes de fijar el negocio sería la de otro.
            $config->settings = [];
            $config->set_settings();

            $settings = $config->settings;

            date_default_timezone_set((string) ($settings['timezone'] ?? '') ?: (ini_get('date.timezone') ?: 'UTC'));
            Services::language()->setLocale(current_language_code());
            bcscale(max(2, totals_decimals() + tax_decimals()));

            if ((string) ($settings['presales_enable'] ?? '0') !== '1' || ! Presale_notification::is_on()) {
                return 0;
            }

            $outbox = model(Presale_notification::class);

            if (! db_connect()->tableExists('presale_notifications')) {
                CLI::write("{$label}: sin la tabla presale_notifications (¿migración pendiente?).", 'yellow');

                return 0;
            }

            $today    = date('Y-m-d');
            $reminded = 0;

            if ((int) date('G') >= self::REMINDER_HOUR) {
                $reminded = $outbox->enqueue_reminders(
                    $today,
                    max(0, (int) ($settings['presales_reminder_days_before'] ?? 2)),
                    (string) ($settings['presales_reminder_late'] ?? '1') === '1',
                );
            }

            $rows = $outbox->claim(self::BATCH);

            if ($rows === [] && $reminded === 0) {
                return 0;
            }

            $notifier = new Presale_notifier(Email_lib::platform());
            $counts   = [];

            foreach ($rows as $row) {
                $status          = $notifier->deliver($row, $today);
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }

            $summary = [];

            foreach ($counts as $status => $count) {
                $summary[] = "{$count} {$status}";
            }

            CLI::write(date('Y-m-d H:i:s') . " {$label}: {$reminded} recordatorio(s) encolado(s); " . ($summary === [] ? 'nada que enviar' : implode(', ', $summary)) . '.');
        } catch (Throwable $e) {
            CLI::error("{$label}: " . $e->getMessage());

            return 1;
        }

        return 0;
    }

    /**
     * Se lanza a sí mismo para un negocio, con su esquema en MYSQL_DB_NAME.
     *
     * @param array{id: int|string, slug: string, db_name: string} $tenant
     */
    private function spawn(array $tenant): int
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOTPATH . 'spark') . ' presales:notify ' . escapeshellarg($tenant['slug']) . ' --no-header';
        $env     = getenv();

        $env['MYSQL_DB_NAME'] = $tenant['db_name'];

        $process = proc_open($command, [1 => STDOUT, 2 => STDERR], $pipes, ROOTPATH, $env);

        if (! is_resource($process)) {
            CLI::error("{$tenant['slug']}: no se pudo lanzar el proceso.");

            return 1;
        }

        return proc_close($process);
    }

    /**
     * Fija el negocio de este proceso: sus credenciales de base de datos cuando tiene propias (mismo
     * razonamiento que TenantMigrateOne::useTenantCredentials()) y su contexto, del que depende la
     * clave de su configuración en caché (Config\OSPOS::settingsCacheKey()).
     *
     * @param array{id: int|string, slug: string, db_name: string} $tenant
     */
    private function use_tenant(array $tenant): void
    {
        $row = db_connect('platform')->table('tenants')->where('db_name', $tenant['db_name'])->get()->getRow();

        if ($row !== null && ! empty($row->db_user) && ! empty($row->db_password)) {
            $db_config = config(Database::class);
            $group     = &$db_config->{$db_config->defaultGroup};

            $group['database'] = $tenant['db_name'];
            $group['username'] = $row->db_user;
            $group['password'] = service('encrypter')->decrypt($row->db_password);

            Database::connect($db_config->defaultGroup, false)->close();
        }

        TenantContext::set((int) $tenant['id'], $tenant['slug'], $tenant['db_name']);
    }

    /**
     * Los negocios activos del registro, o el pedido por slug (sin filtrar por estado). Vacío en una
     * instalación sin registro de negocios.
     *
     * @return list<array{id: int|string, slug: string, db_name: string}>
     */
    private function tenants(string $slug): array
    {
        $platform = db_connect('platform');

        if (! $platform->tableExists('tenants')) {
            return [];
        }

        $builder = $platform->table('tenants')->select('id, slug, db_name');

        if ($slug !== '') {
            $builder->where('slug', $slug);
        } else {
            $builder->where('status', 'active');
        }

        /** @var list<array{id: int|string, slug: string, db_name: string}> $rows */
        return $builder->orderBy('slug', 'asc')->get()->getResultArray();
    }
}
