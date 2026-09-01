<?php

namespace Platform\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The second factor of D11, stored on the account it belongs to.
 *
 * TOTP is a shared secret: the app on the phone and this row hold the same bytes, and from then on
 * neither ever sends them again -- the phone shows six digits, the platform computes the same six
 * and compares. That is why no email, SMS or WhatsApp channel is needed, and why the secret is the
 * one thing here that has to be protected at rest.
 *
 * VARCHAR(512), AND THAT NUMBER IS NOT A GUESS
 *
 * The secret is stored encrypted with `service('encrypter')->encrypt()`, with NO `base64_encode()`
 * wrapped around it. That combination has already cost this project a silent failure: with the
 * configured rawData=false the encrypter ALREADY returns a printable string (hex HMAC + base64
 * ciphertext), so the extra encode roughly doubled the length, overflowed `tenants.db_password`'s
 * VARCHAR(255), and MySQL truncated it without a word. Decryption then failed with "authentication
 * failed" much later and somewhere else entirely. See the comment in
 * app/Libraries/TenantProvisioner.php next to $encryptedDbPassword.
 *
 * A base32 TOTP secret is short -- 32 characters for 160 bits -- but the ciphertext around it is
 * not: the HMAC and the IV are fixed overhead of about 150 characters before the payload. 512
 * leaves room for that, for a longer secret, and for a future cipher, and costs nothing on InnoDB
 * where a VARCHAR only occupies what it holds. 255 would fit today and would be the same trap
 * armed again.
 *
 * `totp_enabled_at` IS THE SWITCH, NOT A BOOLEAN
 *
 * The secret exists from the moment enrolment starts, but the factor is only demanded once a valid
 * code has been typed back (section 6 of the technical design: nothing gets activated that has not
 * been proven to work). A separate timestamp expresses both facts -- enrolled, and since when --
 * where a flag would express neither well.
 *
 * Run with `php spark platform:migrate`. See docs/Tecnico/gestion-de-plataforma-y-negocios.md
 * section 9.14 for why the stock migrate command is not an option here.
 */
class AddTotpToPlatformAccounts extends Migration
{
    protected $DBGroup = 'platform';

    public function up(): void
    {
        $this->forge->addColumn('platform_accounts', [
            'totp_secret' => [
                'type'       => 'VARCHAR',
                'constraint' => 512,
                'null'       => true,
                'after'      => 'failed_login_first_at',
            ],
            'totp_enabled_at' => [
                'type'  => 'DATETIME',
                'null'  => true,
                'after' => 'totp_secret',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('platform_accounts', ['totp_secret', 'totp_enabled_at']);
    }
}
