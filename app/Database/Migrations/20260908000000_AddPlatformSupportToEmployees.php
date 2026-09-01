<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;

/**
 * Marca al empleado de soporte de la plataforma, para poder esconderlo sin mentir.
 *
 * POR QUÉ UNA COLUMNA PROPIA Y NO `deleted`
 *
 * `employees` tiene un solo mecanismo para esconder una fila, `deleted`, y está usado en todas
 * partes -- incluido el propio login (`Employee.php:69`, `:80`, y ocho sitios más). Marcar como
 * borrado algo que no lo está funciona hoy y miente mañana: la primera consulta que cuente
 * empleados «vivos» para otra cosa dará un número distinto al de la pantalla, y nadie sabrá por qué.
 *
 * `DEFAULT 0` NO ES UN DETALLE
 *
 * Esta migración corre sobre la base de CADA negocio -- `scripts/migrate-tenants.sh`, desde el
 * entrypoint -- así que la ejecuta la base de Casaletto, que está vendiendo. Con `DEFAULT 0` y
 * `NOT NULL`, **ninguna fila existente cambia de comportamiento**: los seis empleados de Casaletto
 * quedan exactamente como estaban, y todo el código que todavía no conoce esta columna sigue
 * leyendo lo mismo que leía ayer.
 *
 * Y SI ESTO FALLA, EL PUNTO DE VENTA NO ARRANCA
 *
 * `docker/entrypoint.sh:123` hace `exit 1` --y Apache no levanta-- si una sola migración falla en
 * un solo negocio. Por eso es idempotente: se comprueba si la columna ya está antes de añadirla, y
 * el `down()` comprueba antes de quitarla. Un reintento tras un despliegue a medias tiene que poder
 * pasar por aquí sin romperse.
 *
 * Ver docs/Tecnico/gestion-de-plataforma-y-negocios.md §4.3.
 */
class Migration_AddPlatformSupportToEmployees extends Migration
{
    private const TABLE  = 'employees';
    private const COLUMN = 'is_platform_support';

    public function up(): void
    {
        // resetDataCache() antes de fieldExists(): la lista de campos se cachea por conexión, y una
        // migración anterior del mismo proceso puede haber añadido una columna después de llenarla.
        // Contestar desde la lista vieja es como una recarga se quedó sin hacer en silencio una vez
        // (ver 20260904000000_RerunUnitOfMeasureBackfill).
        $this->db->resetDataCache();

        if ($this->db->fieldExists(self::COLUMN, self::TABLE)) {
            return;
        }

        $this->forge->addColumn(self::TABLE, [
            self::COLUMN => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 0,
                'after'      => 'deleted',
            ],
        ]);

        CLI::write('AddPlatformSupportToEmployees: employees.is_platform_support disponible. Todas las filas existentes quedan en 0 -- ningún empleado cambia de comportamiento.');
    }

    public function down(): void
    {
        $this->db->resetDataCache();

        if ($this->db->fieldExists(self::COLUMN, self::TABLE)) {
            $this->forge->dropColumn(self::TABLE, self::COLUMN);
        }
    }
}
