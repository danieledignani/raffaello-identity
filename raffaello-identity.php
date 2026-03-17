<?php
/**
 * Plugin Name: Raffaello Identity
 * Plugin URI: https://raffaellolibri.it
 * Description: Integrazione OIDC con GruppoRaffaello.Identity — login, profilo utente, ruoli (Studente, Docente, ecc.) e scope/claim configurabili.
 * Version: 1.0.0
 * Author: Gruppo Raffaello
 * Text Domain: raffaello-identity
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 5.8
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RI_VERSION', '1.0.0');
define('RI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RI_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Autoload classi
spl_autoload_register(function ($class) {
    $prefix = 'RaffaelloIdentity\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = RI_PLUGIN_DIR . 'includes/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

require_once RI_PLUGIN_DIR . 'includes/helpers.php';

// Init plugin
add_action('plugins_loaded', function () {
    load_plugin_textdomain('raffaello-identity', false, dirname(RI_PLUGIN_BASENAME) . '/languages');

    $settings = new RaffaelloIdentity\Settings();
    $settings->init();

    $oidc = new RaffaelloIdentity\OidcClient($settings);
    $oidc->init();

    $roles = new RaffaelloIdentity\RoleMapper($settings);
    $roles->init();

    if (is_admin()) {
        $admin = new RaffaelloIdentity\Admin($settings);
        $admin->init();
    }

    $frontend = new RaffaelloIdentity\Frontend($settings, $oidc);
    $frontend->init();
});

// Attivazione: crea ruoli WP e tabella log
register_activation_hook(__FILE__, function () {
    $default_roles = [
        'studente'           => 'Studente',
        'docente'            => 'Docente',
        'docente_sostegno'   => 'Docente di Sostegno',
        'dirigente'          => 'Dirigente',
    ];
    foreach ($default_roles as $slug => $label) {
        if (!get_role($slug)) {
            add_role($slug, $label, ['read' => true]);
        }
    }

    // Crea tabella log
    RaffaelloIdentity\Logger::createTable();

    flush_rewrite_rules();
});

// Disattivazione
register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});
