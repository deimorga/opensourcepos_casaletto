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
 */
return [
    "login"                  => "Entrar a la plataforma",
    "email"                  => "Correo",
    "password"               => "Contraseña",
    "go"                     => "Entrar",
    "invalid_credentials"    => "Correo y/o contraseña incorrectos.",
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
];
