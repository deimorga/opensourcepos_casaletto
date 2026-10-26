<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Email_lib;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Envía un correo de prueba por la cuenta de correo de la plataforma.
 *
 * PARA QUÉ EXISTE
 *
 * Los avisos de Preventas salen por una sola cuenta de la plataforma, configurada con las variables
 * `PLATFORM_SMTP_*` del ambiente (docs/Tecnico/preventas-cartera-y-avisos.md §4.1): Resend en
 * producción y Mailpit en staging. Este comando comprueba esa configuración de punta a punta --que
 * las variables llegaron al contenedor, que el servidor de correo contesta y que acepta la clave--
 * antes de que dependa de ella un aviso a un cliente:
 *
 *     docker compose exec -T ospos php spark platform:mail-test deimorga@gmail.com
 *
 * En staging el correo no sale de verdad: queda en Mailpit, que lo guarda y no lo entrega.
 *
 * SOBRE LOS CÓDIGOS DE SALIDA
 *
 * 0 solo si el servidor de correo aceptó el mensaje. 1 si falta configuración, si el destinatario no
 * es un correo o si el servidor lo rechazó; en ese caso se muestra lo que contestó, que nunca incluye
 * la clave.
 *
 * @internal
 */
final class PlatformMailTest extends BaseCommand
{
    protected $group       = 'Platform';
    protected $name        = 'platform:mail-test';
    protected $description = 'Envía un correo de prueba por la cuenta de correo de la plataforma (PLATFORM_SMTP_*). Sale con 1 si no se pudo.';
    protected $usage       = 'platform:mail-test <correo>';
    protected $arguments   = [
        'correo' => 'A quién enviar la prueba.',
    ];

    public function run(array $params)
    {
        $to = trim((string) ($params[0] ?? ''));

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            CLI::error('Uso: php spark ' . $this->usage);

            return 1;
        }

        $mailer = Email_lib::platform();

        if ($mailer === null) {
            CLI::error('El correo de la plataforma no está configurado: faltan PLATFORM_SMTP_HOST o PLATFORM_MAIL_FROM en el entorno del contenedor.');

            return 1;
        }

        $host = (string) getenv('PLATFORM_SMTP_HOST');
        $when = date('d/m/Y H:i:s');
        $body = '<p>Este es un correo de prueba de la plataforma OSPOS de Micronuba.</p>'
            . '<p>Servidor: <strong>' . esc($host) . '</strong><br>Enviado: ' . esc($when) . '</p>'
            . '<p>Si lo está leyendo, la configuración de correo funciona.</p>';

        try {
            $sent = $mailer->send_as('Micronuba OSPOS', $to, 'Prueba de correo de la plataforma', $body);
        } catch (Throwable $e) {
            CLI::error('El envío falló: ' . $e->getMessage());

            return 1;
        }

        if (! $sent) {
            CLI::error('El servidor de correo (' . $host . ') no aceptó el mensaje:');
            CLI::write($mailer->last_error());

            return 1;
        }

        CLI::write("Enviado a {$to} por {$host}.", 'green');

        return 0;
    }
}
