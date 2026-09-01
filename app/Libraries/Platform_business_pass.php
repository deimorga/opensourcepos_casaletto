<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\PlatformAccount;

/**
 * El pase que lleva de la consola a DENTRO del punto de venta de un negocio.
 *
 * EL PROBLEMA QUE RESUELVE
 *
 * Hasta ahora, «Abrir» dejaba al operador en el FORMULARIO de entrada del negocio, con la sesión de
 * la consola --y su segundo factor-- ya superados y sin servir de nada. Había que volver a teclear
 * correo, contraseña y código para llegar a un sitio al que ya se tenía derecho.
 *
 * QUÉ ES UN PASE, EXACTAMENTE
 *
 * 32 bytes al azar que viajan UNA vez, en la redirección de la consola al negocio, y que valen
 * SESENTA SEGUNDOS. Sirven para una cuenta y un negocio concretos, y se tachan al usarse.
 *
 * De lo que protege cada propiedad, porque ninguna sobra:
 *
 * | Propiedad | Lo que impide |
 * |---|---|
 * | Un solo uso, tachado con `DELETE` + `affectedRows()` | Que el enlace del historial del navegador vuelva a abrir la caja mañana |
 * | Sesenta segundos | Que un pase copiado de un registro o de un hombro sirva para algo |
 * | Atado a la cuenta Y al negocio | Que un pase para un cliente abra el de otro |
 * | Se guarda el hash, no el pase | Que leer esta tabla --un respaldo, una consulta de más-- alcance para entrar |
 *
 * LO QUE EL PASE NO SUSTITUYE
 *
 * No sustituye al segundo factor: lo presupone. Solo se emite desde una sesión de consola ya
 * autenticada, y `PlatformAccount::getLoggedInAccount()` no devuelve nada hasta que
 * `completeSecondFactor()` ha corrido. Un pase no es una credencial más débil, es la MISMA
 * credencial trasladada un solo salto.
 */
final class Platform_business_pass
{
    /**
     * Sesenta segundos. Es un salto de una redirección, no una sesión: si tarda más, algo va mal y
     * es preferible pedir de nuevo que dejar un pase vivo dando vueltas.
     */
    public const VALID_SECONDS = 60;

    private const TABLE = 'platform_business_passes';

    /**
     * Emite un pase y devuelve su texto, que es lo ÚNICO que sale de aquí sin cifrar y que no se
     * guarda en ningún sitio.
     *
     * base64url y no hexadecimal: cabe en una URL sin escapar nada, y 32 bytes son 256 bits de
     * entropía -- adivinarlo no es una posibilidad que haya que sopesar.
     */
    public function mint(int $accountId, int $tenantId): string
    {
        $this->forgetExpired();

        $pase = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        db_connect('platform')->table(self::TABLE)->insert([
            'token_hash' => $this->hash($pase),
            'account_id' => $accountId,
            'tenant_id'  => $tenantId,
            'expires_at' => date('Y-m-d H:i:s', time() + self::VALID_SECONDS),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $pase;
    }

    /**
     * Lo canjea. Devuelve la cuenta y el negocio a los que da derecho, o null si no vale.
     *
     * EL ORDEN ES LO QUE HACE QUE «UN SOLO USO» SEA VERDAD
     *
     * Se lee y se BORRA antes de mirar si caducó. Comprobar primero y borrar después deja una
     * ventana en la que dos peticiones simultáneas leen la misma fila y las dos la dan por buena.
     * Aquí el borrado es la comprobación: si `affectedRows()` no es exactamente 1, alguien llegó
     * antes -- o el pase nunca existió-- y en los dos casos la respuesta es la misma.
     *
     * @return array{account_id: int, tenant_id: int}|null
     */
    public function redeem(string $pase): ?array
    {
        if ($pase === '') {
            return null;
        }

        $db   = db_connect('platform');
        $hash = $this->hash($pase);

        $fila = $db->table(self::TABLE)->where('token_hash', $hash)->get()->getRow();

        if ($fila === null) {
            return null;
        }

        $db->table(self::TABLE)->where('token_hash', $hash)->delete();

        if ($db->affectedRows() !== 1) {
            return null;
        }

        if (strtotime((string) $fila->expires_at) < time()) {
            return null;
        }

        return ['account_id' => (int) $fila->account_id, 'tenant_id' => (int) $fila->tenant_id];
    }

    /**
     * Los caducados se barren al emitir uno nuevo, que es el momento en el que ya estamos
     * escribiendo en esta tabla. Un proceso aparte para esto sería una pieza más que puede fallar
     * sola y en silencio.
     */
    private function forgetExpired(): void
    {
        db_connect('platform')->table(self::TABLE)
            ->where('expires_at <', date('Y-m-d H:i:s'))
            ->delete();
    }

    private function hash(string $pase): string
    {
        return hash('sha256', $pase);
    }

    /**
     * ¿La cuenta sigue teniendo derecho a entrar a un negocio?
     *
     * Se vuelve a preguntar AL CANJEAR y no solo al emitir: entre las dos cosas pasan sesenta
     * segundos, pero también podrían pasar la eliminación de la cuenta o el apagado de su segundo
     * factor. Un pase no puede sobrevivir a la cuenta que lo pidió.
     */
    public function accountStillMayEnter(int $accountId): bool
    {
        $cuenta = model(PlatformAccount::class)->find($accountId);

        return $cuenta !== null && $cuenta->totp_enabled_at !== null;
    }
}
