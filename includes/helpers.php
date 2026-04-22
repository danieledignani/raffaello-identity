<?php
/**
 * Funzioni helper globali.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Restituisce le opzioni del plugin con valori di default.
 */
function ri_get_options(): array {
    $defaults = [
        'issuer'            => 'https://account.raffaellolibri.it',
        'client_id'         => 'raffaelloScuolaClient',
        'client_secret'     => '',
        'scopes'            => 'openid email profile offline_access roles',
        'login_redirect'    => home_url('/profilo/'),
        'logout_redirect'   => home_url('/'),
        'role_mapping'      => [
            'Studente' => 'studente',
            'Docente'  => 'docente',
        ],
        'claim_mapping'     => [
            'nome'      => 'first_name',
            'cognome'   => 'last_name',
            'email'     => 'user_email',
        ],
        'extra_claims'      => 'profilo,sostegno,consensoMarketing,consensoProfilazione,consensoTerzeParti,joomla_sub',
        'auto_register'     => true,
        'profile_page_id'   => 0,
        'login_button_text' => 'Accedi con Raffaello',
        'override_wp_login' => false,
        'wc_override_login' => true,
        'nav_menu_location' => '',
        'debug_mode'        => false,
        // Campo utente da usare per il display name pubblico (shortcode [ri_user_name]
        // e placeholder {ri_name} nei menu). Valori: 'display_name', 'first_name',
        // 'last_name', 'full_name', 'username', 'email'.
        'display_field'     => 'first_name',
    ];

    $saved = get_option('ri_options', []);
    $options = wp_parse_args($saved, $defaults);

    // Supporto costanti PHP: le costanti RI_* sovrascrivono le opzioni del DB.
    // Utile per deploy automatizzati dove i secrets non vanno salvati nel database.
    // Esempio in wp-config.php:
    //   define('RI_CLIENT_ID', 'myClient');
    //   define('RI_CLIENT_SECRET', 'mySecret');
    $constant_map = [
        'RI_ISSUER'            => 'issuer',
        'RI_CLIENT_ID'         => 'client_id',
        'RI_CLIENT_SECRET'     => 'client_secret',
        'RI_SCOPES'            => 'scopes',
        'RI_LOGIN_REDIRECT'    => 'login_redirect',
        'RI_LOGOUT_REDIRECT'   => 'logout_redirect',
        'RI_AUTO_REGISTER'     => 'auto_register',
        'RI_LOGIN_BUTTON_TEXT' => 'login_button_text',
        'RI_OVERRIDE_WP_LOGIN' => 'override_wp_login',
        'RI_WC_OVERRIDE_LOGIN' => 'wc_override_login',
        'RI_EXTRA_CLAIMS'      => 'extra_claims',
        'RI_DEBUG_MODE'        => 'debug_mode',
    ];

    foreach ($constant_map as $const => $key) {
        if (defined($const)) {
            $options[$key] = constant($const);
        }
    }

    return $options;
}

/**
 * Salva le opzioni del plugin.
 */
function ri_save_options(array $options): void {
    update_option('ri_options', $options);
}

/**
 * Genera lo state CSRF e lo salva in sessione.
 */
function ri_generate_state(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $state = wp_generate_password(40, false);
    $_SESSION['ri_oidc_state'] = $state;
    return $state;
}

/**
 * Verifica lo state CSRF.
 */
function ri_verify_state(string $state): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $valid = isset($_SESSION['ri_oidc_state']) && hash_equals($_SESSION['ri_oidc_state'], $state);
    unset($_SESSION['ri_oidc_state']);
    return $valid;
}

/**
 * Salva il nonce OIDC in sessione.
 */
function ri_generate_nonce(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $nonce = wp_generate_password(40, false);
    $_SESSION['ri_oidc_nonce'] = $nonce;
    return $nonce;
}

/**
 * Verifica il nonce OIDC.
 */
function ri_verify_nonce(string $nonce): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $valid = isset($_SESSION['ri_oidc_nonce']) && hash_equals($_SESSION['ri_oidc_nonce'], $nonce);
    unset($_SESSION['ri_oidc_nonce']);
    return $valid;
}

/**
 * URL della pagina profilo sul server Identity, con returnUrl verso il sito corrente.
 * Usabile in template, widget e menu personalizzati.
 */
function ri_account_url(?string $return_to = null): string {
    $settings = new \RaffaelloIdentity\Settings();
    return $settings->getIdentityAccountUrl($return_to);
}

/**
 * URL di logout federato (passa dal server Identity per chiudere la sessione SSO).
 */
function ri_logout_url(): string {
    return admin_url('admin-ajax.php?action=ri_logout');
}

/**
 * URL di login OIDC (inizio dell'Authorization Code Flow).
 */
function ri_login_url(): string {
    $settings = new \RaffaelloIdentity\Settings();
    $oidc = new \RaffaelloIdentity\OidcClient($settings);
    return $oidc->getAuthorizationUrl();
}

/**
 * Restituisce il nome utente da mostrare in UI, in base al campo scelto
 * nelle impostazioni del plugin (display_field). Se l'utente non è loggato
 * o il campo è vuoto, ritorna stringa vuota.
 *
 * Campi supportati: 'display_name' (default WP), 'first_name', 'last_name',
 * 'full_name' (nome + cognome), 'username' (user_login), 'email'.
 *
 * Utile per shortcode [ri_user_name], placeholder {ri_name} nei menu,
 * e per integrazioni custom in theme template.
 */
function ri_user_display_name(?string $field_override = null): string {
    if (!is_user_logged_in()) {
        return '';
    }

    $user = wp_get_current_user();
    if (!empty($field_override)) {
        $field = $field_override;
    } else {
        $options = ri_get_options();
        $field = $options['display_field'] ?? 'first_name';
    }

    switch ($field) {
        case 'username':
            return (string) $user->user_login;
        case 'email':
            return (string) $user->user_email;
        case 'first_name':
            $first = (string) get_user_meta($user->ID, 'first_name', true);
            return $first !== '' ? $first : (string) $user->display_name;
        case 'last_name':
            $last = (string) get_user_meta($user->ID, 'last_name', true);
            return $last !== '' ? $last : (string) $user->display_name;
        case 'full_name':
            $first = trim((string) get_user_meta($user->ID, 'first_name', true));
            $last  = trim((string) get_user_meta($user->ID, 'last_name', true));
            $full  = trim("$first $last");
            return $full !== '' ? $full : (string) $user->display_name;
        case 'display_name':
        default:
            return (string) $user->display_name;
    }
}
