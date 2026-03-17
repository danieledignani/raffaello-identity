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
            'Studente'           => 'studente',
            'Docente'            => 'docente',
            'DocenteDiSostegno'  => 'docente_sostegno',
            'Dirigente'          => 'dirigente',
            'Altro'              => 'subscriber',
        ],
        'claim_mapping'     => [
            'nome'      => 'first_name',
            'cognome'   => 'last_name',
            'email'     => 'user_email',
        ],
        'extra_claims'      => 'consensoMarketing,consensoProfilazione,joomla_sub',
        'auto_register'     => true,
        'profile_page_id'   => 0,
        'login_button_text' => 'Accedi con Raffaello',
        'override_wp_login' => false,
        'wc_override_login' => true,
        'nav_menu_location' => '',
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
