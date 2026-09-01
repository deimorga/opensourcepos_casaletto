<?php

namespace Platform\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * El nombre real del negocio, guardado donde la consola puede leerlo.
 *
 * Hoy el alta pide el nombre de la empresa, lo escribe DENTRO del negocio (`app_config.company`) y
 * lo descarta. El listado queda mostrando el nombre técnico del esquema -- `tenant_paraiso`,
 * `ospos` -- que con dos negocios ya se lee mal y con diez es inservible (§4.5 del funcional).
 *
 * Leerlo del propio negocio no es alternativa: obligaría a abrir una conexión por fila para pintar
 * una tabla, y un negocio suspendido o con su base caída dejaría la lista entera sin dibujar.
 *
 * ADITIVA Y NULLABLE, A PROPÓSITO
 *
 * Las dos filas que ya existen -- Casaletto y Paraíso -- se quedan en NULL, y la pantalla cae al
 * slug cuando no hay nombre. Rellenarlas es un gesto de una línea que hace el dueño desde la ficha
 * el día que quiera; inventarles un nombre desde una migración sería escribir en producción un dato
 * que nadie confirmó.
 *
 * Se corre con `php spark platform:migrate`, NUNCA con el `migrate` de serie: ese escribe el
 * historial en el esquema del cliente. Ver §9.14 de
 * docs/Tecnico/gestion-de-plataforma-y-negocios.md.
 */
class AddCompanyNameToTenants extends Migration
{
    protected $DBGroup = 'platform';

    public function up(): void
    {
        $this->forge->addColumn('tenants', [
            // 255 igual que `ospos_app_config.value`, que es donde vive la otra copia del mismo
            // dato: una columna más corta aquí truncaría en silencio nombres que el negocio sí
            // acepta, y las dos dejarían de coincidir sin que nadie lo note.
            'company_name' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'slug'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('tenants', 'company_name');
    }
}
