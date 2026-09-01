<?php

/**
 * La consola de plataforma, en español.
 *
 * Va en es-MX, no en es-ES: la aplicación corre en es-MX y CodeIgniter solo
 * cae del locale al idioma sin región (es-MX -> es), nunca al inglés. Una
 * cadena escrita únicamente en es-ES no se ve, y la pantalla sale en inglés
 * sin dar el menor error. Por lo mismo este archivo lleva TODAS las claves de
 * app/Language/en/Platform.php: la que falte aquí saldrá en pantalla como
 * "Platform.loquesea".
 *
 * Las claves de la Entrega 2 están todas declaradas de una vez, antes de
 * construir ninguna de sus dos mitades. Dos agentes escriben esas pantallas en
 * paralelo y este par de archivos es lo que ambos tendrían que editar.
 */
return [
    "login"                  => "Entrar a la plataforma",
    "email"                  => "Correo",
    "password"               => "Contraseña",
    "go"                     => "Entrar",

    // Un solo mensaje para la contraseña equivocada, el correo que no existe Y
    // la cuenta frenada. D8 exige que el error no revele si el correo existe, y
    // una dirección que conteste "demasiados intentos" mientras otra contesta
    // "contraseña incorrecta" acaba de confirmarse a sí misma. Nombrar aquí el
    // freno hace que la frase sea cierta en los tres casos sin separarlos.
    "invalid_credentials"    => "Correo y/o contraseña incorrectos. Tras tres intentos fallidos el acceso se frena durante dos horas; otro superadministrador puede desbloquearlo.",

    "no_tenants_linked"      => "Esta cuenta todavía no está vinculada a ningún negocio activo.",
    "tenant_not_linked"      => "Ese negocio no está vinculado a esta cuenta.",
    "select_business"        => "Elija un negocio",
    "logout"                 => "Salir",
    "admin_panel_title"      => "Plataforma de gestión de negocios",
    "new_business"           => "Negocio nuevo",
    "slug"                   => "Slug (subdominio)",
    "company_name"           => "Nombre del negocio",
    "create"                 => "Crear",
    "status"                 => "Estado",
    "actions"                => "Acciones",
    "suspend"                => "Suspender",
    "activate"               => "Activar",
    "delete"                 => "Eliminar",
    "cancel"                 => "Cancelar",
    "database"               => "Base de datos",

    // Negocios adoptados: se registraron sobre un esquema que ya existía antes
    // de la plataforma, así que nunca se les creó un usuario propio de base de
    // datos. Casaletto es el caso real. Siguen listados y gestionables (D3),
    // pero esta consola no los desmonta.
    "adopted"                => "Adoptado",
    "adopted_not_deletable"  => "No se puede eliminar desde aquí",
    "adopted_explained"      => "Este negocio fue adoptado: su base de datos ({0}) existía antes que la plataforma y no le pertenece. Darlo de baja es una operación manual, y con respaldo hecho antes.",

    // La pantalla de eliminar.
    "confirm_delete_title"   => "Eliminar negocio",
    "confirm_delete_body"    => "Esto da de baja el negocio y revoca su usuario de base de datos. Dejará de responder en su dirección. Sus datos se conservan, salvo que además confirme abajo la destrucción de la base de datos.",
    "confirm_slug_label"     => "Escriba el slug del negocio para confirmar",
    "confirm_slug_help"      => "Escriba {0} exactamente.",
    "drop_schema_title"      => "Destruir también la base de datos",
    "drop_schema"            => "Sí, destruir para siempre esta base de datos y todo lo que contiene",
    "drop_schema_warning"    => "Cada venta, artículo, cliente y turno de este negocio vive en {0}. Destruirla no tiene vuelta atrás, y esta consola no guarda ninguna copia.",
    "confirm_db_name_label"  => "Escriba el nombre de la base de datos para confirmar",
    "confirm_db_name_help"   => "Escriba {0} exactamente. Déjelo vacío para conservar la base de datos.",
    "delete_business"        => "Eliminar este negocio",

    // Resultados.
    "deleted"                => "Negocio {0} eliminado.",
    "delete_refused_adopted" => "El negocio {0} fue adoptado: su base de datos ({1}) existía antes que la plataforma. No se puede eliminar desde esta consola. No se cambió nada.",
    "delete_refused_slug"    => "El slug no se escribió exacto (se esperaba {0}). No se eliminó nada.",
    "delete_refused_db_name" => "Para destruir la base de datos hay que escribir su nombre ({0}) exacto. No se eliminó nada ni se destruyó ninguna base de datos.",

    // =========================================================================================
    // ENTREGA 2 -- Superadministradores, segundo factor, registro de actividad.
    // =========================================================================================

    // ----- Comunes -----
    "save"                   => "Guardar",
    "back"                   => "Volver",
    "confirm"                => "Confirmar",
    "yes"                    => "Sí",
    "no"                     => "No",
    "never"                  => "Nunca",
    "nav_businesses"         => "Negocios",
    "nav_accounts"           => "Superadministradores",
    "nav_activity"           => "Registro de actividad",
    "nav_my_password"        => "Mi contraseña",
    "nav_second_factor"      => "Segundo factor",

    // ----- 6.1 El listado de superadministradores -----
    "accounts_title"         => "Superadministradores",
    "accounts_intro"         => "Estas cuentas pueden crear, suspender y eliminar cualquier negocio junto con su base de datos. La pregunta que contesta esta pantalla es cuál de ellas no debería existir.",
    "account_email"          => "Correo",
    "account_created_at"     => "Fecha de alta",
    "account_created_by"     => "Quién la creó",
    "account_created_from_cli" => "Desde la terminal",
    "account_created_from_cli_help" => "Nadie creó esta cuenta desde la consola: se hizo con `php spark platform:create-account`, y puede que su contraseña no la haya anotado nadie.",
    "account_last_login"     => "Último ingreso",
    "account_never_logged_in" => "Nunca se usó",
    "account_role"           => "Papel",
    "account_role_admin"     => "Superadministrador",
    "account_role_owner"     => "Dueño de negocio",
    "account_second_factor"  => "Segundo factor",
    "account_second_factor_on" => "Activo",
    "account_second_factor_off" => "Sin configurar",
    "account_you"            => "Usted",
    "accounts_empty"         => "No hay ninguna cuenta.",
    "accounts_only_one_admin" => "Queda un solo superadministrador. Cree un segundo antes de eliminar ninguno: es la cuenta que puede desbloquear tras los intentos fallidos y la que salva si se pierde el teléfono con el segundo factor.",

    // ----- Crear una cuenta -----
    "new_account"            => "Superadministrador nuevo",
    "new_account_title"      => "Superadministrador nuevo",
    "new_account_intro"      => "La cuenta se crea con la contraseña que escriba aquí. No se envía a ninguna parte: entréguela usted, y quien la reciba la cambia desde su propia pantalla.",
    "account_password"       => "Contraseña",
    "account_password_confirm" => "Repita la contraseña",
    "account_password_help"  => "Al menos 12 caracteres.",
    "account_is_admin"       => "Administrador de toda la plataforma",
    "account_is_admin_help"  => "Un superadministrador gestiona todos los negocios y se le pide segundo factor. Sin esto, la cuenta solo llega a los negocios a los que esté vinculada.",
    "account_create"         => "Crear cuenta",
    "account_created"        => "Cuenta {0} creada.",
    "account_create_failed_email_invalid" => "Ese no es un correo válido. No se creó nada.",
    "account_create_failed_email_taken"   => "Ya existe una cuenta con la dirección {0}. No se creó nada.",
    "account_create_failed_password_short" => "La contraseña debe tener al menos {0} caracteres. No se creó nada.",
    "account_create_failed_password_mismatch" => "Las dos contraseñas no coinciden. No se creó nada.",

    // ----- Eliminar una cuenta -----
    "account_delete"         => "Eliminar",
    "account_confirm_delete_title" => "Eliminar superadministrador",
    "account_confirm_delete_body"  => "Esto elimina la cuenta y sus códigos de rescate. Lo que haya hecho sigue en el registro de actividad, con su dirección, para que quede legible.",
    "account_confirm_email_label"  => "Escriba el correo de la cuenta para confirmar",
    "account_confirm_email_help"   => "Escriba {0} exactamente.",
    "account_delete_button"        => "Eliminar esta cuenta",
    "account_deleted"              => "Cuenta {0} eliminada.",
    "account_delete_refused_self"  => "Nadie elimina su propia cuenta. Pídaselo al otro superadministrador. No se cambió nada.",
    "account_delete_refused_last_admin" => "Este es el último superadministrador. Eliminarlo dejaría la plataforma sin nadie que pueda administrarla y sin ninguna pantalla desde la que crear otro. No se cambió nada.",
    "account_delete_refused_email" => "El correo no se escribió exacto (se esperaba {0}). No se eliminó nada.",
    "account_delete_refused_missing" => "Esa cuenta ya no existe. No se cambió nada.",

    // ----- El freno de D8 -----
    "account_locked"         => "Bloqueada",
    "account_locked_since"   => "Bloqueada desde {0}",
    "account_locked_explained" => "Tres intentos fallidos dentro de dos horas. El bloqueo se levanta solo al cerrarse la ventana, y otro superadministrador puede levantarlo ahora.",
    "account_unlock"         => "Desbloquear",
    "account_unlocked"       => "Cuenta {0} desbloqueada.",
    "account_not_locked"     => "Esa cuenta no estaba bloqueada. No se cambió nada.",
    "account_failed_attempts" => "{0} intentos fallidos",

    // ----- Cambiar la propia contraseña -----
    "password_title"         => "Cambiar mi contraseña",
    "password_intro"         => "Una sola contraseña para todos los negocios. Cambiarla aquí la cambia en todas partes, incluidos los negocios que se creen mañana.",
    "password_current"       => "Contraseña actual",
    "password_new"           => "Contraseña nueva",
    "password_new_confirm"   => "Repita la contraseña nueva",
    "password_change"        => "Cambiar la contraseña",
    "password_changed"       => "Su contraseña quedó cambiada.",
    "password_change_failed_current" => "La contraseña actual no es correcta. No se cambió nada.",
    "password_change_failed_mismatch" => "Las dos contraseñas nuevas no coinciden. No se cambió nada.",
    "password_change_failed_short"    => "La contraseña nueva debe tener al menos {0} caracteres. No se cambió nada.",
    "password_change_failed_same"     => "La contraseña nueva es igual a la actual. No se cambió nada.",

    // ----- D11, el segundo factor -----
    "totp_title"             => "Segundo factor",
    "totp_intro"             => "Un código de seis dígitos que cambia cada 30 segundos, desde una aplicación en su teléfono. Solo se le pide a los superadministradores: la credencial de un cajero abre una caja, esta abre todos los negocios de todos los clientes.",
    "totp_apps_help"         => "No hace falta elegir aplicación. La app Contraseñas de Apple lo hace de forma nativa, y también sirven 1Password, Bitwarden, Google Authenticator, Microsoft Authenticator o Authy.",
    "totp_state_off"         => "Sin configurar",
    "totp_state_on"          => "Activo desde {0}",
    "totp_enroll"            => "Activar el segundo factor",
    "totp_enroll_title"      => "Activar el segundo factor",
    "totp_enroll_intro"      => "Escriba esta clave en su aplicación de autenticación. Después escriba el código que le muestre: no se activa nada hasta que un código real demuestre que funciona.",
    "totp_secret_label"      => "Clave de configuración",
    "totp_secret_help"       => "Se muestra una sola vez y no se vuelve a mostrar. Guárdela mientras la registra.",
    "copy"                   => "Copiar",
    "totp_code"              => "Código de la aplicación",
    "totp_code_help"         => "Seis dígitos.",
    "totp_confirm"           => "Encender el segundo factor",
    "totp_confirm_failed"    => "Ese código no es válido. El segundo factor NO quedó encendido. Si sigue fallando, revise que el reloj del teléfono esté en automático.",
    "totp_enabled"           => "Segundo factor encendido. Guarde los códigos de rescate: son la única forma de volver a entrar si se pierde el teléfono.",
    "totp_disable"           => "Apagar el segundo factor",
    "totp_disable_confirm"   => "Apagarlo deja esta cuenta detrás de una contraseña sola. Escriba su contraseña para confirmar.",
    "totp_disabled"          => "Segundo factor apagado.",
    "totp_disable_failed"    => "La contraseña no es correcta. El segundo factor sigue encendido.",
    "totp_clock_note"        => "Los códigos dependen del reloj, no de la zona horaria. Si dejan de servir sin motivo, es que el reloj del servidor o el del teléfono se corrió.",

    // El reto al entrar.
    "totp_challenge_title"   => "Segundo factor",
    "totp_challenge_intro"   => "Escriba el código que muestra su aplicación, o uno de sus códigos de rescate.",
    "totp_challenge_field"   => "Código",
    "totp_challenge_go"      => "Continuar",
    "totp_challenge_failed"  => "Ese código no es válido.",
    "totp_challenge_expired" => "El intento caducó. Vuelva a entrar.",
    "totp_challenge_used_recovery" => "Entró con un código de rescate. Ese código quedó gastado: le quedan {0}.",

    // ----- Códigos de rescate -----
    "recovery_codes_title"   => "Códigos de rescate",
    "recovery_codes_intro"   => "Diez códigos de un solo uso. Son la ÚNICA forma de entrar si se pierde el teléfono con el segundo factor, porque la plataforma no tiene por dónde enviarle nada a nadie.",
    "recovery_codes_shown_once" => "Se muestran una vez y no se vuelven a mostrar. Guárdelos en un sitio que no sea ese mismo teléfono.",
    "recovery_codes_saved"   => "Ya los guardé",
    "recovery_codes_remaining" => "Quedan {0} códigos sin usar",
    "recovery_codes_none_left" => "No queda ningún código sin usar. Genere una tanda nueva ahora, mientras todavía puede entrar.",
    "recovery_codes_regenerate" => "Generar códigos nuevos",
    "recovery_codes_regenerated" => "Códigos nuevos generados. Los anteriores ya no sirven.",

    // ----- 6.5 El registro de actividad -----
    "activity_title"         => "Registro de actividad",
    "activity_intro"         => "Lo que esta consola cambió. Los ingresos no se registran, por decisión: este registro contesta quién cambió qué, no quién entró y cuándo.",
    "activity_when"          => "Cuándo",
    "activity_who"           => "Quién",
    "activity_action"        => "Qué",
    "activity_target"        => "Sobre",
    "activity_detail"        => "Detalle",
    "activity_ip"            => "Dirección",
    "activity_empty"         => "Todavía no se ha registrado nada.",
    "activity_from_cli"      => "Desde la terminal",
    "activity_target_tenant" => "Negocio",
    "activity_target_account" => "Cuenta",

    "action_tenant_created"          => "Negocio creado",
    "action_tenant_suspended"        => "Negocio suspendido",
    "action_tenant_activated"        => "Negocio reactivado",
    "action_tenant_deleted"          => "Negocio eliminado",
    "action_tenant_schema_dropped"   => "Base de datos destruida",
    "action_account_created"         => "Cuenta creada",
    "action_account_deleted"         => "Cuenta eliminada",
    "action_account_password_changed" => "Contraseña cambiada",
    "action_account_locked"          => "Cuenta bloqueada tras intentos fallidos",
    "action_account_unlocked"        => "Cuenta desbloqueada",
    "action_account_totp_enabled"    => "Segundo factor encendido",
    "action_account_totp_disabled"   => "Segundo factor apagado",
    "action_tenant_password_reset"   => "Contraseña del negocio restablecida",

    // =========================================================================================
    // ENTREGA 3 -- Que un negocio nazca funcionando: el perfil de configuración, la ficha del
    // negocio y la contraseña que se puede volver a consultar (D5, D12).
    // =========================================================================================

    // ----- 6.2 El listado, que ahora se puede leer -----
    "business_name"          => "Negocio",
    "business_name_unknown"  => "Sin nombre guardado",
    "created_at"             => "Alta",
    "open_business"          => "Abrir",

    // ----- 6.3 La ficha del negocio -----
    "business_title"         => "Negocio: {0}",
    "business_identity"      => "Identificación",
    "business_address"       => "Dirección",
    "business_back"          => "Volver al listado",

    // La configuración que escribe el perfil, tal como el negocio la tiene hoy.
    "settings_title"         => "Configuración",
    "settings_intro"         => "Lo que este negocio tiene ahora mismo, leído de su propia configuración. El perfil que se aplica al darlo de alta es «{0}».",
    "settings_key"           => "Clave",
    "settings_value"         => "Valor",
    "settings_wired"         => "Cableado",
    "settings_wired_help"    => "Cambiar esto no cambia una preferencia, rompe el negocio. Valor esperado: {0}.",
    "settings_missing"       => "sin definir",
    "settings_unreachable"   => "No se pudo llegar a este negocio, así que su configuración no se muestra. Todo lo demás de esta pantalla sale del registro de la plataforma.",
    "settings_not_editable_here" => "Editarlas desde la consola todavía no está construido: se cambian desde la pantalla de configuración del propio negocio.",

    // ----- D5, la contraseña consultable -----
    "credential_title"       => "Contraseña del administrador",
    "credential_username"    => "Usuario",
    "credential_password"    => "Contraseña",
    "credential_set_at"      => "Generada",
    "credential_reveal"      => "Ver la contraseña",
    "credential_hide"        => "Ocultar",
    "credential_available"   => "Sigue siendo la contraseña que generamos: el cliente no la ha cambiado.",
    "credential_none"        => "La plataforma no tiene copia de la contraseña de este negocio. O se dio de alta antes de que la consola guardara una, o el cliente ya la cambió. La única salida es restablecerla.",
    "credential_changed"     => "El cliente cambió esta contraseña, así que la copia se acaba de descartar. Ahora la única salida es restablecerla.",
    "credential_unreadable"  => "Hay una copia guardada pero no se puede descifrar: la clave de cifrado no es la que la guardó. Es una avería de la plataforma, no un cambio del cliente — no restablezca nada antes de revisar la clave.",
    "credential_unreachable" => "No se pudo llegar a este negocio, así que no se pudo comprobar si su contraseña sigue siendo válida. No se descartó nada.",
    "credential_delivery"    => "Bloque de entrega",
    "credential_delivery_help" => "Esto es lo que se le manda al cliente: dirección, usuario y contraseña, todo junto.",
    "credential_never_logged" => "Se puede volver a consultar aquí mientras el cliente no la cambie. No hace falta apuntarla en ningún otro sitio.",

    // ----- Restablecerla -----
    "reset_password"         => "Restablecer la contraseña",
    "reset_password_title"   => "Restablecer la contraseña del administrador",
    "reset_password_body"    => "Se genera una contraseña nueva y se escribe en el negocio de inmediato. Quien estuviera usando la anterior deja de poder entrar en ese mismo momento.",
    "reset_password_user"    => "Usuario al que se le restablece",
    "reset_password_user_help" => "Tiene que existir ya en ese negocio. Un negocio aprovisionado por nosotros usa «{0}»; uno adoptado conserva el nombre que ya tenía.",
    "reset_password_button"  => "Restablecerla ahora",
    "reset_password_done"    => "Contraseña nueva generada para {0}. Se muestra abajo.",
    "reset_password_uncopied" => "ANÓTELA AHORA. La contraseña de {0} ya quedó cambiada en el negocio, y es esta: {1}. La plataforma no pudo guardar su copia, así que esta pantalla no va a poder volver a mostrarla. Revise que se haya corrido «php spark platform:migrate».",
];
