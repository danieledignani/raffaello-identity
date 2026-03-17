<?php

namespace RaffaelloIdentity;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pagina di amministrazione del plugin.
 */
class Admin {
    private Settings $settings;

    public function __construct(Settings $settings) {
        $this->settings = $settings;
    }

    public function init(): void {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'handleSave']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function addMenuPage(): void {
        add_options_page(
            'Raffaello Identity',
            'Raffaello Identity',
            'manage_options',
            'raffaello-identity',
            [$this, 'renderPage']
        );
        add_management_page(
            'Raffaello Identity — Log',
            'RI Log',
            'manage_options',
            'raffaello-identity-logs',
            [$this, 'renderLogPage']
        );
    }

    public function enqueueAssets(string $hook): void {
        if (!in_array($hook, ['settings_page_raffaello-identity', 'tools_page_raffaello-identity-logs'], true)) {
            return;
        }
        wp_enqueue_style('ri-admin', RI_PLUGIN_URL . 'assets/css/admin.css', [], RI_VERSION);
    }

    public function handleSave(): void {
        if (!isset($_POST['ri_save_settings']) || !current_user_can('manage_options')) {
            return;
        }

        if (!wp_verify_nonce($_POST['ri_nonce'] ?? '', 'ri_save_settings')) {
            add_settings_error('ri_settings', 'nonce_fail', 'Verifica di sicurezza fallita.', 'error');
            return;
        }

        $options = ri_get_options();

        // Connessione OIDC
        $options['issuer'] = esc_url_raw(trim($_POST['ri_issuer'] ?? ''));
        $options['client_id'] = sanitize_text_field($_POST['ri_client_id'] ?? '');
        $options['client_secret'] = sanitize_text_field($_POST['ri_client_secret'] ?? '');
        $options['scopes'] = sanitize_text_field($_POST['ri_scopes'] ?? '');

        // Redirect
        $options['login_redirect'] = esc_url_raw($_POST['ri_login_redirect'] ?? '');
        $options['logout_redirect'] = esc_url_raw($_POST['ri_logout_redirect'] ?? '');

        // Registrazione e login
        $options['auto_register'] = isset($_POST['ri_auto_register']);
        $options['login_button_text'] = sanitize_text_field($_POST['ri_login_button_text'] ?? '');
        $options['override_wp_login'] = isset($_POST['ri_override_wp_login']);

        // WooCommerce
        $options['wc_override_login'] = isset($_POST['ri_wc_override_login']);

        // Menu navigazione
        $options['nav_menu_location'] = sanitize_text_field($_POST['ri_nav_menu_location'] ?? '');

        // Mappatura ruoli
        $role_mapping = [];
        if (isset($_POST['ri_role_map_identity']) && is_array($_POST['ri_role_map_identity'])) {
            foreach ($_POST['ri_role_map_identity'] as $i => $identity_role) {
                $wp_role = sanitize_text_field($_POST['ri_role_map_wp'][$i] ?? '');
                $identity_role = sanitize_text_field($identity_role);
                if (!empty($identity_role) && !empty($wp_role)) {
                    $role_mapping[$identity_role] = $wp_role;
                }
            }
        }
        $options['role_mapping'] = $role_mapping;

        // Mappatura claim
        $claim_mapping = [];
        if (isset($_POST['ri_claim_oidc']) && is_array($_POST['ri_claim_oidc'])) {
            foreach ($_POST['ri_claim_oidc'] as $i => $oidc_claim) {
                $wp_field = sanitize_text_field($_POST['ri_claim_wp'][$i] ?? '');
                $oidc_claim = sanitize_text_field($oidc_claim);
                if (!empty($oidc_claim) && !empty($wp_field)) {
                    $claim_mapping[$oidc_claim] = $wp_field;
                }
            }
        }
        $options['claim_mapping'] = $claim_mapping;

        // Claim extra
        $options['extra_claims'] = sanitize_text_field($_POST['ri_extra_claims'] ?? '');

        ri_save_options($options);
        $this->settings->reload();

        add_settings_error('ri_settings', 'saved', 'Impostazioni salvate.', 'success');
    }

    public function renderLogPage(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Gestione azioni
        if (isset($_POST['ri_clear_logs']) && wp_verify_nonce($_POST['ri_log_nonce'] ?? '', 'ri_clear_logs')) {
            Logger::clearLogs();
            echo '<div class="notice notice-success"><p>Log svuotati.</p></div>';
        }

        $level_filter = sanitize_text_field($_GET['level'] ?? '');
        $event_filter = sanitize_text_field($_GET['event'] ?? '');
        $page_num = max(1, (int) ($_GET['paged'] ?? 1));
        $per_page = 50;
        $offset = ($page_num - 1) * $per_page;

        $logs = Logger::getLogs($per_page, $offset, $level_filter, $event_filter);
        $total = Logger::countLogs($level_filter, $event_filter);
        $total_pages = ceil($total / $per_page);

        include RI_PLUGIN_DIR . 'admin/logs-page.php';
    }

    public function renderPage(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $opts = $this->settings->getAll();
        $identity_roles = RoleMapper::getAvailableIdentityRoles();
        $wp_roles = RoleMapper::getAvailableWpRoles();

        include RI_PLUGIN_DIR . 'admin/settings-page.php';
    }
}
