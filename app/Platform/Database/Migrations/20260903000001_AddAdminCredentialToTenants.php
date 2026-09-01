<?php

namespace Platform\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * La contraseña consultable (D5): dónde se guarda la copia cifrada de la contraseña inicial de cada
 * negocio, y con qué se compara para saber si todavía vale.
 *
 * EL PROBLEMA QUE RESUELVE
 *
 * `password_hash()` es irreversible por diseño, así que la contraseña que el alta genera se muestra
 * una vez en un mensaje y se pierde. Cuando el cliente la pierde -- y ya pasó -- hoy hay que entrar
 * a la base de datos a mano, que no es algo que se pueda hacer en una llamada de soporte (§4.4).
 *
 * LAS TRES COLUMNAS, Y POR QUÉ SON TRES
 *
 * - `admin_username`: a quién pertenece. Hoy siempre es `admin`, pero guardarlo evita que la ficha
 *   tenga que suponerlo, y el día que el alta cree además el empleado de soporte (Entrega 4) habrá
 *   dos usuarios en ese negocio y la suposición sería falsa.
 * - `admin_password_hash`: el hash que ESTA plataforma generó y escribió en el negocio. Es el
 *   testigo, no la llave. Mientras `employees.password` siga siendo exactamente este valor, la
 *   copia cifrada sigue siendo la contraseña verdadera; en cuanto difiere, el cliente la cambió y
 *   la copia se borra. Comparar hashes y no contraseñas es lo que permite detectarlo sin conocer la
 *   contraseña nueva.
 * - `admin_password_cipher`: la copia, cifrada con el servicio de cifrado de la aplicación. Vive
 *   SOLO aquí, en `platform_control`, nunca en la base del cliente: escribir la contraseña en el
 *   propio negocio la pondría al alcance de cualquiera que ya tenga acceso a ese esquema.
 *
 * POR QUÉ `admin_password_cipher` ES TEXT Y NO VARCHAR(255)
 *
 * Porque el precedente existe y costó caro. `tenants.db_password` se dimensionó VARCHAR(255) con un
 * `base64_encode()` de más encima del cifrado, y MySQL truncó el resultado en silencio: el
 * descifrado empezó a fallar con «authentication failed» y nadie supo por qué. Aquí no hay
 * `base64_encode()` extra -- `service('encrypter')->encrypt()` ya devuelve texto imprimible con
 * `rawData=false` -- y además la columna es TEXT, de modo que ni una contraseña más larga en el
 * futuro ni un cambio de cifrado puedan volver a producir un truncamiento mudo. El coste de TEXT
 * frente a VARCHAR en una tabla de diez filas es exactamente cero.
 *
 * `admin_password_set_at` deja constancia de cuándo se generó la que está guardada, que es lo que
 * la ficha muestra para que quien la lee sepa si está viendo la del alta o la de un
 * restablecimiento de la semana pasada.
 *
 * NADA DE ESTO SE RELLENA HACIA ATRÁS
 *
 * Casaletto y Paraíso se quedan con las cuatro columnas en NULL, porque la plataforma no conoce sus
 * contraseñas y no hay forma honesta de deducirlas. Su ficha ofrecerá restablecer, que es
 * exactamente lo que D5 dice para un negocio cuya contraseña ya no se puede ver.
 *
 * Se corre con `php spark platform:migrate`. Ver §9.14 del técnico.
 */
class AddAdminCredentialToTenants extends Migration
{
    protected $DBGroup = 'platform';

    public function up(): void
    {
        $this->forge->addColumn('tenants', [
            'admin_username'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'admin_password_hash'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'admin_password_cipher' => ['type' => 'TEXT', 'null' => true],
            'admin_password_set_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('tenants', [
            'admin_username',
            'admin_password_hash',
            'admin_password_cipher',
            'admin_password_set_at',
        ]);
    }
}
