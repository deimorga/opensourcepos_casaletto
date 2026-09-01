<?php

return [
    "login"                  => "Platform Login",
    "email"                  => "Email",
    "password"               => "Password",
    "go"                     => "Go",
    "invalid_credentials"    => "Invalid email and/or password.",
    "no_tenants_linked"      => "This account is not linked to any active business yet.",
    "tenant_not_linked"      => "That business is not linked to this account.",
    "select_business"        => "Select a business",
    "logout"                 => "Logout",
    "admin_panel_title"      => "Business Management Platform",
    "new_business"           => "New business",
    "slug"                   => "Slug (subdomain)",
    "company_name"           => "Company name",
    "create"                 => "Create",
    "status"                 => "Status",
    "actions"                => "Actions",
    "suspend"                => "Suspend",
    "activate"               => "Activate",
    "delete"                 => "Delete",
    "cancel"                 => "Cancel",
    "database"               => "Database",

    // Adopted businesses: registered from a schema that existed before the
    // platform did, so no dedicated database user was ever created for them.
    // Casaletto is the real case. They stay listed and manageable (D3), but
    // this console never tears them down.
    "adopted"                => "Adopted",
    "adopted_not_deletable"  => "Cannot be deleted here",
    "adopted_explained"      => "This business was adopted: its database ({0}) existed before the platform did and does not belong to it. Unregistering it is a manual operation, with a backup taken first.",

    // The delete screen.
    "confirm_delete_title"   => "Delete business",
    "confirm_delete_body"    => "This unregisters the business and revokes its dedicated database user. It will no longer be reachable at its address. Its data is kept unless you also confirm destroying the database below.",
    "confirm_slug_label"     => "Type the slug of the business to confirm",
    "confirm_slug_help"      => "Type {0} exactly.",
    "drop_schema_title"      => "Destroy the database as well",
    "drop_schema"            => "Yes, permanently destroy this database and everything in it",
    "drop_schema_warning"    => "Every sale, item, customer and shift of this business lives in {0}. Destroying it cannot be undone, and this console keeps no copy.",
    "confirm_db_name_label"  => "Type the name of the database to confirm",
    "confirm_db_name_help"   => "Type {0} exactly. Leave it empty to keep the database.",
    "delete_business"        => "Delete this business",

    // Outcomes.
    "deleted"                => "Business {0} deleted.",
    "delete_refused_adopted" => "Business {0} was adopted: its database ({1}) existed before the platform did. It cannot be deleted from this console. Nothing was changed.",
    "delete_refused_slug"    => "The slug was not typed exactly ({0} was expected). Nothing was deleted.",
    "delete_refused_db_name" => "Destroying the database needs its name ({0}) typed exactly. Nothing was deleted and no database was destroyed.",
];
