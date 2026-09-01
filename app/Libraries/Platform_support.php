<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Quién es el empleado de soporte dentro de un negocio, definido UNA vez.
 *
 * Todo OSPOS cuelga de `person_id`: ventas, turnos, permisos, auditoría. Una sesión de soporte que
 * no tenga fila de empleado deja registros sin autor, así que en cada negocio existe un empleado que
 * somos nosotros. No es burocracia: es el único modo de que lo que hagamos ahí dentro tenga nombre.
 *
 * ESTA FILA NO SE PUEDE AUTENTICAR POR EL LOGIN DEL CLIENTE
 *
 * La contraseña se guarda con un valor que NINGUNA contraseña produce. `password_verify()` contra
 * una cadena que no es un hash válido devuelve `false` siempre --no lanza-- y la rama de
 * `hash_version = 1` compara contra `md5()`, que son 32 caracteres hexadecimales y tampoco puede
 * coincidir. Así que la puerta de los empleados del negocio no abre esta fila ni por casualidad ni
 * por fuerza bruta: no existe la entrada que buscar.
 *
 * La sesión de soporte se abre autenticando contra `platform_accounts`, nunca contra `employees`.
 *
 * SE ESCONDE POR COLUMNA PROPIA, NO POR `deleted`
 *
 * Ver la migración 20260908000000 y §4.3 del documento técnico: marcar como borrado algo que no lo
 * está funciona hoy y miente mañana.
 */
final class Platform_support
{
    /**
     * El usuario de la fila. Nunca se teclea en ningún formulario --no hay contraseña que ponerle al
     * lado-- así que puede ser todo lo explícito que haga falta para quien lo encuentre en la base.
     */
    public const USERNAME = 'soporte_micronuba';

    public const FIRST_NAME = 'Soporte';
    public const LAST_NAME  = 'Micronuba';

    /**
     * Lo que va en `employees.password`.
     *
     * No es un hash y no puede serlo: si aquí hubiera un `password_hash()` de algo, ese algo abriría
     * la puerta del cliente. `password_verify()` sobre esto devuelve false para toda entrada.
     */
    public const UNUSABLE_PASSWORD = '*sin-contrasena-usa-la-plataforma*';

    /** La misma versión que el resto de empleados: la rama de md5 tampoco puede coincidir. */
    public const HASH_VERSION = 2;

    /**
     * Cómo se presenta en pantalla una fila escrita desde una sesión de soporte (D10).
     *
     * Un registro escrito por soporte apunta a un empleado que el cliente no ve, así que la etiqueta
     * es lo que hace legible su historial en vez de dejarle un hueco. Es presentación, no modelo.
     */
    public const LABEL = 'Soporte';

    /**
     * Los datos de `people` con los que nace la fila. Las columnas de esa tabla son NOT NULL sin
     * default en esta versión del esquema, así que van todas y van vacías a propósito: no vamos a
     * inventarle una dirección ni un teléfono a una fila que no es una persona.
     *
     * @return array<string, string>
     */
    public static function personData(): array
    {
        return [
            'first_name'   => self::FIRST_NAME,
            'last_name'    => self::LAST_NAME,
            'phone_number' => '',
            'email'        => '',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => 'Cuenta de soporte de la plataforma. No la elimine ni la edite.',
        ];
    }

    /**
     * Los datos de `employees`.
     *
     * @return array<string, mixed>
     */
    public static function employeeData(): array
    {
        return [
            'username'            => self::USERNAME,
            'password'            => self::UNUSABLE_PASSWORD,
            'hash_version'        => self::HASH_VERSION,
            'deleted'             => 0,
            'is_platform_support' => 1,
        ];
    }
}
