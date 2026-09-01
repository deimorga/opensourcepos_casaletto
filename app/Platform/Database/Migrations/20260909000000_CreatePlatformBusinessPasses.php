<?php

namespace Platform\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * El pase de un solo uso que lleva de la consola a DENTRO del punto de venta de un negocio.
 *
 * POR QUÉ HACE FALTA UNA TABLA Y NO BASTA UN TOKEN FIRMADO
 *
 * Un token firmado --un JWT o un HMAC-- demuestra que lo emitimos nosotros y cuándo caduca, pero NO
 * puede ser de un solo uso: cualquiera que lo lea puede volver a presentarlo hasta que expire. Para
 * que «un solo uso» sea verdad hace falta un sitio donde tacharlo, y tacharlo de forma atómica:
 * `DELETE ... WHERE token_hash = ?` seguido de `affectedRows() === 1`. Dos peticiones simultáneas
 * con el mismo pase: una entra, la otra no.
 *
 * SE GUARDA EL HASH, NO EL PASE
 *
 * Esta tabla abre la caja de un cliente. Si alguien la lee --un respaldo, una consulta de más-- lo
 * que encuentra no le sirve para entrar: el pase viaja una sola vez, en la respuesta de la consola,
 * y aquí solo queda su huella. Es el mismo trato que los códigos de rescate.
 *
 * VIVE EN `platform_control`, NUNCA EN LA BASE DEL CLIENTE
 *
 * `$DBGroup = 'platform'`, como todas las de este espacio de nombres, así que esta migración no
 * puede alcanzar el esquema de un negocio ni por equivocación.
 */
class Migration_CreatePlatformBusinessPasses extends Migration
{
    protected $DBGroup = 'platform';

    private const TABLE = 'platform_business_passes';

    public function up(): void
    {
        if ($this->db->tableExists(self::TABLE)) {
            return;
        }

        $this->forge->addField([
            'token_hash' => [
                'type'       => 'CHAR',
                'constraint' => 64,
                'null'       => false,
                'comment'    => 'sha256 del pase. El pase mismo no se guarda en ninguna parte.',
            ],
            'account_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => false],
            'tenant_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => false],
            // Absoluto y no una duración: quien lo lea sabe cuándo dejó de valer sin tener que saber
            // cuánto duraban los pases el día que se emitió.
            'expires_at' => ['type' => 'DATETIME', 'null' => false],
            'created_at' => ['type' => 'DATETIME', 'null' => false],
        ]);

        $this->forge->addKey('token_hash', true);
        // Para poder barrer los caducados sin recorrer la tabla entera.
        $this->forge->addKey('expires_at');
        $this->forge->createTable(self::TABLE);
    }

    public function down(): void
    {
        $this->forge->dropTable(self::TABLE, true);
    }
}
