<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * El perfil «Colombia · comercio al detal» (D12), que es lo único que separa un esquema recién
 * migrado de un negocio en condiciones de vender.
 *
 * POR QUÉ EXISTE ESTE ARCHIVO
 *
 * Hasta hoy `TenantProvisioner::create()` escribía UNA sola clave -- el nombre de la empresa -- y
 * todo lo demás se quedaba con la semilla de `initial_schema.sql`, que es estadounidense. Medido en
 * producción el 2026-08-31: un negocio nuevo nacía con `barcode_content=id` (un código tecleado
 * vende otro producto), `quantity_decimals=0` (el peso se pierde en silencio), `language_code=en`,
 * `number_locale=en_US`, `currency_decimals=2` y `country_codes=us`. Paraíso funciona porque
 * alguien corrigió diez ajustes a mano, uno por uno, sin lista -- y aun así uno se escapó.
 *
 * El perfil es esa lista, escrita una vez y en un solo sitio.
 *
 * LAS TRES CLAVES DE self::WIRING NO SON CONFIGURACIÓN, SON CABLEADO
 *
 * Cambiarlas no es una preferencia sino un daño, y las tres ya causaron incidentes reales en este
 * proyecto (§5 del funcional). Aquí solo se FIJAN al crear el negocio; el candado que impide que el
 * cliente las cambie desde su propia pantalla de configuración vive del lado del punto de venta,
 * porque un candado que solo existe en esta consola es decorativo.
 *
 * EL IDIOMA VIVE EN DOS SITIOS Y EL DEL EMPLEADO MANDA
 *
 * `ospos_employees` tiene sus propias columnas `language` y `language_code` y ganan sobre
 * `app_config` (`app/Helpers/locale_helper.php`). Un perfil que solo escriba `app_config` NO
 * funciona: basta con que el empleado inicial tenga un idioma propio distinto para que la pantalla
 * salga en otro idioma sin dar ningún error. Por eso `applyTo()` escribe los dos sitios y no ofrece
 * una versión que escriba solo uno.
 *
 * LO QUE ESTE PERFIL DELIBERADAMENTE NO TOCA
 *
 * `tax_included` NO está en ninguna de las dos listas, así que un negocio nuevo se queda con el `0`
 * de la semilla. Los dos negocios de producción corren con `0`; el documento de venta por peso
 * recomendaba `1`. La contradicción es real y la decide el dueño, no este archivo: fijarlo aquí en
 * `1` cambiaría en silencio cómo se calculan los precios de todo negocio futuro a partir de una
 * recomendación que producción ya desmiente. Queda anotado como pendiente en §5 del funcional.
 *
 * Tampoco toca formatos de fecha, símbolo de moneda ni separador de miles: no están en la lista
 * cerrada de D12 y añadirlos sería decidir por el dueño.
 */
final class TenantConfigProfile
{
    /**
     * El identificador que queda en el registro de actividad y en la ficha del negocio. Hay un solo
     * perfil (D12) y la constante existe para que el día que haya dos, el que se aplicó a cada
     * negocio siga siendo legible en las filas ya escritas.
     */
    public const ID = 'co-retail';

    /**
     * Cómo se llama el administrador que el alta deja creado, en lugar del «John Doe» de la
     * semilla. El apellido es el nombre del negocio, así que en pantalla se lee «Administrador
     * Casaletto» y no hay dos negocios con el mismo nombre de persona.
     *
     * Vive en el perfil y no en el aprovisionador porque es una cadena en español, y lo que decide
     * que este negocio habla español es precisamente el perfil. El día que exista un segundo perfil
     * para otro país, esta palabra viaja con él.
     */
    public const ADMIN_FIRST_NAME = 'Administrador';

    /**
     * Las tres bloqueadas. Cambiar cualquiera de ellas rompe el negocio en silencio:
     *
     * - `quantity_decimals` en `0` pierde el peso: la venta cuadra en plata y el inventario queda
     *   mal, sin ningún aviso.
     * - `barcode_content` en `id` hace que un código tecleado venda otro producto. Pasó en Paraíso
     *   el 2026-08-31: teclear `56` (aguacate, al peso) metía gelatina de cereza; 212 de 1.184
     *   referencias colisionaban.
     * - `language_code` en otra variante parte el mantenimiento en dos. El 2026-08-30 el aviso de
     *   peso salió en inglés porque la traducción se escribió solo en `es-ES`.
     */
    public const WIRING = [
        'quantity_decimals' => '3',
        'barcode_content'   => 'item_number',
        'language_code'     => 'es-MX',
    ];

    /**
     * Las que el perfil fija y el negocio sí puede cambiar después desde su propia configuración.
     *
     * `country_codes` va en `co` y no en el `us` de la semilla. Hoy los dos negocios de producción
     * están en `us`, que es sencillamente incorrecto para Colombia; es una decisión tomada aquí y
     * anotada en la documentación, no una que se hereda de nadie.
     */
    public const PREFERENCES = [
        'number_locale'     => 'es_CO',
        'currency_decimals' => '0',
        'language'          => 'spanish',
        'timezone'          => 'America/Bogota',
        'country_codes'     => 'co',
    ];

    /**
     * Todo lo que va a `app_config`, con el nombre de la empresa incluido.
     *
     * El orden importa poco para la base, pero `company` va al final a propósito: es el único valor
     * que viene de fuera, y verlo separado de los fijos hace evidente al leer el arreglo cuáles son
     * decisión del perfil y cuál es del negocio.
     *
     * @return array<string, string>
     */
    public static function appConfig(string $companyName): array
    {
        return self::WIRING + self::PREFERENCES + ['company' => $companyName];
    }

    /**
     * El idioma tal como tiene que quedar en la fila del empleado, que es la que gana.
     *
     * Las dos columnas juntas y nunca una sola: `language_code` es la que consulta
     * `locale_helper.php`, y `language` es la que muestra la pantalla de perfil del empleado. Con
     * una sola escrita, la pantalla diría un idioma y la aplicación hablaría otro.
     *
     * @return array<string, string>
     */
    public static function employeeLanguage(): array
    {
        return [
            'language'      => self::PREFERENCES['language'],
            'language_code' => self::WIRING['language_code'],
        ];
    }

    /**
     * Escribe el perfil en un negocio: su configuración Y la fila del empleado indicado.
     *
     * Recibe la conexión ya abierta en vez de abrirla: quien llama sabe si el negocio tiene usuario
     * propio o cae a las credenciales compartidas (Casaletto es adoptado), y ese conocimiento no
     * tiene por qué duplicarse aquí.
     *
     * @param int $personId el empleado inicial, `person_id` 1 en un esquema recién sembrado
     */
    public function applyTo(BaseConnection $tenantDb, string $companyName, int $personId = 1): void
    {
        foreach (self::appConfig($companyName) as $key => $value) {
            $this->writeSetting($tenantDb, $key, $value);
        }

        $this->applyToEmployee($tenantDb, $personId);
    }

    /**
     * Solo la fila del empleado. Separado de `applyTo()` porque el día que se aplique el perfil a un
     * negocio existente habrá que decidir empleado por empleado -- Casaletto tiene tres de seis con
     * el idioma vacío (§2.4 del técnico), y cambiarles el global se lo cambiaría a esos tres y no a
     * los otros tres.
     */
    public function applyToEmployee(BaseConnection $tenantDb, int $personId): void
    {
        $tenantDb->table('employees')
            ->where('person_id', $personId)
            ->update(self::employeeLanguage());
    }

    /**
     * Una clave de `app_config`, exista ya o no.
     *
     * El UPDATE a secas era la forma anterior y es una trampa: si la clave no está en la tabla, no
     * escribe nada, no falla, y el negocio se queda con el valor que el código asuma por defecto.
     * `app_config` es una tabla clave/valor cuyo contenido depende de por qué migración pasó cada
     * esquema, así que "la clave siempre está" no es una suposición que se pueda hacer.
     */
    private function writeSetting(BaseConnection $tenantDb, string $key, string $value): void
    {
        $exists = $tenantDb->table('app_config')->where('key', $key)->countAllResults() > 0;

        if ($exists) {
            $tenantDb->table('app_config')->where('key', $key)->update(['value' => $value]);

            return;
        }

        $tenantDb->table('app_config')->insert(['key' => $key, 'value' => $value]);
    }
}
