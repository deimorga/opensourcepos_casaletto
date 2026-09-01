<?php

/**
 * The platform console, in English.
 *
 * This file is the register of every string the console can show; app/Language/es-MX/Platform.php
 * must carry the SAME keys, because the console runs in es-MX and a key missing there renders on
 * screen as "Platform.whatever". See section 9.9 of docs/Tecnico/gestion-de-plataforma-y-negocios.md.
 *
 * Every key of Entrega 2 is declared here at once, before either half of the work is built. Two
 * agents write these screens in parallel and this pair of files is what they would both have to
 * edit; the whole point of declaring them up front is that neither has to.
 */
return [
    "login"                  => "Platform Login",
    "email"                  => "Email",
    "password"               => "Password",
    "go"                     => "Go",

    // One message for a wrong password, an unknown address AND a shut account. D8 requires that
    // the error not reveal whether the email exists, and an address that answers "too many
    // attempts" while an unknown one answers "wrong password" has just confirmed itself. Naming
    // the brake here makes the sentence true in all three cases without separating them.
    "invalid_credentials"    => "Invalid email and/or password. After three failed attempts access is held for two hours; another superadministrator can lift it.",

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

    // Adopted businesses: registered from a schema that existed before the platform did, so no
    // dedicated database user was ever created for them. Casaletto is the real case. They stay
    // listed and manageable (D3), but this console never tears them down.
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

    // =========================================================================================
    // ENTREGA 2 -- Superadministrators, second factor, activity log.
    // =========================================================================================

    // ----- Shared -----
    "save"                   => "Save",
    "back"                   => "Back",
    "confirm"                => "Confirm",
    "yes"                    => "Yes",
    "no"                     => "No",
    "never"                  => "Never",
    "nav_businesses"         => "Businesses",
    "nav_accounts"           => "Superadministrators",
    "nav_activity"           => "Activity log",
    "nav_my_password"        => "My password",
    "nav_second_factor"      => "Second factor",

    // ----- 6.1 The superadministrator listing -----
    "accounts_title"         => "Superadministrators",
    "accounts_intro"         => "These accounts can create, suspend and delete any business, together with its database. The question this screen answers is which of them should not exist.",
    "account_email"          => "Email",
    "account_created_at"     => "Created",
    "account_created_by"     => "Created by",
    "account_created_from_cli" => "From a terminal",
    "account_created_from_cli_help" => "Nobody created this account from the console: it was made with `php spark platform:create-account`, and its password may never have been written down.",
    "account_last_login"     => "Last login",
    "account_never_logged_in" => "Never used",
    "account_role"           => "Role",
    "account_role_admin"     => "Superadministrator",
    "account_role_owner"     => "Business owner",
    "account_second_factor"  => "Second factor",
    "account_second_factor_on" => "Active",
    "account_second_factor_off" => "Not set up",
    "account_you"            => "You",
    "accounts_empty"         => "There are no accounts.",
    "accounts_only_one_admin" => "There is only one superadministrator left. Create a second one before removing any: it is the account that can lift a lock and the one that saves the day if a phone with the second factor is lost.",

    // ----- Creating an account -----
    "new_account"            => "New superadministrator",
    "new_account_title"      => "New superadministrator",
    "new_account_intro"      => "The account is created with the password typed here. It is not sent anywhere: hand it over yourself, and whoever receives it changes it from their own screen.",
    "account_password"       => "Password",
    "account_password_confirm" => "Repeat the password",
    "account_password_help"  => "At least 12 characters.",
    "account_is_admin"       => "Full platform administrator",
    "account_is_admin_help"  => "A superadministrator manages every business and is asked for a second factor. Without this, the account only reaches the businesses it is linked to.",
    "account_create"         => "Create account",
    "account_created"        => "Account {0} created.",
    "account_create_failed_email_invalid" => "That is not a valid email address. Nothing was created.",
    "account_create_failed_email_taken"   => "There is already an account with the address {0}. Nothing was created.",
    "account_create_failed_password_short" => "The password must be at least {0} characters. Nothing was created.",
    "account_create_failed_password_mismatch" => "The two passwords do not match. Nothing was created.",

    // ----- Deleting an account -----
    "account_delete"         => "Delete",
    "account_confirm_delete_title" => "Delete superadministrator",
    "account_confirm_delete_body"  => "This removes the account and its recovery codes. Whatever it did stays in the activity log, with its address, so the record remains readable.",
    "account_confirm_email_label"  => "Type the account's email to confirm",
    "account_confirm_email_help"   => "Type {0} exactly.",
    "account_delete_button"        => "Delete this account",
    "account_deleted"              => "Account {0} deleted.",
    "account_delete_refused_self"  => "Nobody deletes their own account. Ask the other superadministrator to do it. Nothing was changed.",
    "account_delete_refused_last_admin" => "This is the last superadministrator. Deleting it would leave the platform with nobody able to administer it and no screen left to create another. Nothing was changed.",
    "account_delete_refused_email" => "The email was not typed exactly ({0} was expected). Nothing was deleted.",
    "account_delete_refused_missing" => "That account no longer exists. Nothing was changed.",

    // ----- The brake of D8 -----
    "account_locked"         => "Locked",
    "account_locked_since"   => "Locked since {0}",
    "account_locked_explained" => "Three failed attempts inside two hours. The lock lifts by itself when the window closes, and another superadministrator can lift it now.",
    "account_unlock"         => "Unlock",
    "account_unlocked"       => "Account {0} unlocked.",
    "account_not_locked"     => "That account was not locked. Nothing was changed.",
    "account_failed_attempts" => "{0} failed attempts",

    // ----- Changing your own password -----
    "password_title"         => "Change my password",
    "password_intro"         => "One password, all the businesses. Changing it here changes it everywhere, including businesses created tomorrow.",
    "password_current"       => "Current password",
    "password_new"           => "New password",
    "password_new_confirm"   => "Repeat the new password",
    "password_change"        => "Change password",
    "password_changed"       => "Your password has been changed.",
    "password_change_failed_current" => "The current password is not right. Nothing was changed.",
    "password_change_failed_mismatch" => "The two new passwords do not match. Nothing was changed.",
    "password_change_failed_short"    => "The new password must be at least {0} characters. Nothing was changed.",
    "password_change_failed_same"     => "The new password is the same as the current one. Nothing was changed.",

    // ----- D11, the second factor -----
    "totp_title"             => "Second factor",
    "totp_intro"             => "A six-digit code that changes every 30 seconds, from an app on your phone. Only superadministrators are asked for it: a cashier's password opens one till, this one opens every business of every client.",
    "totp_apps_help"         => "No particular app is needed. Apple's Passwords app does it natively, and so do 1Password, Bitwarden, Google Authenticator, Microsoft Authenticator and Authy.",
    "totp_state_off"         => "Not set up",
    "totp_state_on"          => "Active since {0}",
    "totp_enroll"            => "Set up the second factor",
    "totp_enroll_title"      => "Set up the second factor",
    "totp_enroll_intro"      => "Scan the image with your app, or type the key into it by hand. Then type back the code it shows: nothing is switched on until a real code proves it works.",
    "totp_secret_label"      => "Setup key",
    "totp_secret_help"       => "Only needed if the image cannot be scanned. It is shown once and is never shown again.",
    "totp_qr_alt"            => "Setup code for the authenticator app",
    "totp_code"              => "Code from the app",
    "totp_code_help"         => "Six digits.",
    "totp_confirm"           => "Switch on the second factor",
    "totp_confirm_failed"    => "That code is not valid. The second factor was NOT switched on. If it keeps failing, check that the phone's clock is set automatically.",
    "totp_enabled"           => "Second factor switched on. Keep the recovery codes: they are the only way back in if the phone is lost.",
    "totp_disable"           => "Turn off the second factor",
    "totp_disable_confirm"   => "Turning it off leaves this account behind a password alone. Type your password to confirm.",
    "totp_disabled"          => "Second factor turned off.",
    "totp_disable_failed"    => "The password is not right. The second factor stays on.",
    "totp_clock_note"        => "The codes depend on the clock, not on the time zone. If they stop working for no reason, the server's or the phone's clock has drifted.",

    // The challenge at login.
    "totp_challenge_title"   => "Second factor",
    "totp_challenge_intro"   => "Type the code your app is showing, or one of your recovery codes.",
    "totp_challenge_field"   => "Code",
    "totp_challenge_go"      => "Continue",
    "totp_challenge_failed"  => "That code is not valid.",
    "totp_challenge_expired" => "The attempt expired. Sign in again.",
    "totp_challenge_used_recovery" => "You signed in with a recovery code. It has been spent: {0} left.",

    // ----- Recovery codes -----
    "recovery_codes_title"   => "Recovery codes",
    "recovery_codes_intro"   => "Ten single-use codes. They are the ONLY way in if the phone with the second factor is lost, because the platform has no way to send anything to anybody.",
    "recovery_codes_shown_once" => "They are shown once and are never shown again. Save them somewhere that is not the same phone.",
    "recovery_codes_saved"   => "I have saved them",
    "recovery_codes_remaining" => "{0} unused codes left",
    "recovery_codes_none_left" => "There are no unused codes left. Generate a new set now, while you can still get in.",
    "recovery_codes_regenerate" => "Generate new codes",
    "recovery_codes_regenerated" => "New codes generated. The previous ones no longer work.",

    // ----- 6.5 The activity log -----
    "activity_title"         => "Activity log",
    "activity_intro"         => "What this console changed. Sign-ins are not recorded, by decision: this log answers who changed what, not who came in and when.",
    "activity_when"          => "When",
    "activity_who"           => "Who",
    "activity_action"        => "What",
    "activity_target"        => "On",
    "activity_detail"        => "Detail",
    "activity_ip"            => "Address",
    "activity_empty"         => "Nothing has been recorded yet.",
    "activity_from_cli"      => "From a terminal",
    "activity_target_tenant" => "Business",
    "activity_target_account" => "Account",

    "action_tenant_created"          => "Business created",
    "action_tenant_suspended"        => "Business suspended",
    "action_tenant_activated"        => "Business reactivated",
    "action_tenant_deleted"          => "Business deleted",
    "action_tenant_schema_dropped"   => "Database destroyed",
    "action_account_created"         => "Account created",
    "action_account_deleted"         => "Account deleted",
    "action_account_password_changed" => "Password changed",
    "action_account_locked"          => "Account locked after failed attempts",
    "action_account_unlocked"        => "Account unlocked",
    "action_account_totp_enabled"    => "Second factor switched on",
    "action_account_totp_disabled"   => "Second factor turned off",
];
