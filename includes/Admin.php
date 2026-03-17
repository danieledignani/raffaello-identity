<?php

namespace RaffaelloIdentity;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pagina di amministrazione del plugin — unica pagina con tab.
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
        add_action('wp_ajax_ri_test_connection', [$this, 'handleTestConnection']);
    }

    public function addMenuPage(): void {
        add_options_page(
            'Raffaello Identity',
            'Raffaello Identity',
            'manage_options',
            'raffaello-identity',
            [$this, 'renderPage']
        );
    }

    public function enqueueAssets(string $hook): void {
        if ($hook !== 'settings_page_raffaello-identity') {
            return;
        }
        wp_enqueue_style('ri-admin', RI_PLUGIN_URL . 'assets/css/admin.css', [], RI_VERSION);
        wp_enqueue_script('ri-test', RI_PLUGIN_URL . 'assets/js/admin-test.js', [], RI_VERSION, true);
        wp_localize_script('ri-test', 'riTest', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('ri_test_connection'),
        ]);
    }

    /**
     * Pagina principale con tab: Impostazioni, Test Connessione, Log, Debug.
     */
    public function renderPage(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $current_tab = sanitize_text_field($_GET['tab'] ?? 'settings');
        $tabs = [
            'settings' => 'Impostazioni',
            'test'     => 'Test Connessione',
            'log'      => 'Log',
            'debug'    => 'Debug',
        ];

        echo '<div class="wrap ri-admin-wrap">';
        echo '<h1>Raffaello Identity</h1>';

        // Tab navigation
        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $slug => $label) {
            $url = add_query_arg(['page' => 'raffaello-identity', 'tab' => $slug], admin_url('options-general.php'));
            $active = ($slug === $current_tab) ? ' nav-tab-active' : '';
            printf('<a href="%s" class="nav-tab%s">%s</a>', esc_url($url), $active, esc_html($label));
        }
        echo '</nav>';

        echo '<div class="ri-tab-content" style="margin-top:16px;">';

        switch ($current_tab) {
            case 'test':
                $this->renderTestTab();
                break;
            case 'log':
                $this->renderLogTab();
                break;
            case 'debug':
                $this->renderDebugTab();
                break;
            default:
                $this->renderSettingsTab();
                break;
        }

        echo '</div></div>';
    }

    // =========================================================================
    // Tab: Impostazioni
    // =========================================================================

    private function renderSettingsTab(): void {
        $opts = $this->settings->getAll();
        $identity_roles = RoleMapper::getAvailableIdentityRoles();
        $wp_roles = RoleMapper::getAvailableWpRoles();

        include RI_PLUGIN_DIR . 'admin/settings-page.php';
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

        // Debug mode
        $options['debug_mode'] = isset($_POST['ri_debug_mode']);

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

    // =========================================================================
    // Tab: Test Connessione
    // =========================================================================

    private function renderTestTab(): void {
        $settings = $this->settings;
        include RI_PLUGIN_DIR . 'admin/test-page.php';
    }

    /**
     * Handler AJAX: esegue i test di connessione OIDC step by step.
     */
    public function handleTestConnection(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permesso negato.');
        }

        check_ajax_referer('ri_test_connection', 'nonce');

        $step = sanitize_text_field($_POST['step'] ?? '');
        $result = [];

        switch ($step) {
            case 'discovery':
                $result = $this->testDiscovery();
                break;
            case 'token_endpoint':
                $result = $this->testTokenEndpoint();
                break;
            case 'userinfo_endpoint':
                $result = $this->testUserInfoEndpoint();
                break;
            case 'full_flow':
                $result = $this->testFullFlow();
                break;
            default:
                wp_send_json_error('Step non valido.');
        }

        wp_send_json($result);
    }

    // =========================================================================
    // Tab: Log
    // =========================================================================

    private function renderLogTab(): void {
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

    // =========================================================================
    // Tab: Debug
    // =========================================================================

    private function renderDebugTab(): void {
        $debug_enabled = $this->settings->get('debug_mode', false);

        echo '<h2>Modalità Debug</h2>';
        echo '<p class="description">Quando attiva, il plugin salva la risposta completa della userinfo ad ogni login. I dati sono consultabili qui sotto per ogni utente OIDC.</p>';

        // Stato attuale
        if ($debug_enabled) {
            echo '<div class="notice notice-warning inline" style="margin:12px 0;"><p><strong>Debug attivo</strong> — tutti i dati userinfo vengono salvati ad ogni login. Disattivare in produzione.</p></div>';
        } else {
            echo '<div class="notice notice-info inline" style="margin:12px 0;"><p>Debug non attivo. Attivalo nella tab Impostazioni per registrare i dati userinfo completi.</p></div>';
        }

        // Mostra dati debug degli utenti OIDC
        $oidc_users = get_users([
            'meta_key'   => 'ri_oidc_sub',
            'meta_query' => [['key' => 'ri_oidc_sub', 'compare' => 'EXISTS']],
            'number'     => 50,
            'orderby'    => 'ID',
            'order'      => 'DESC',
        ]);

        if (empty($oidc_users)) {
            echo '<p>Nessun utente OIDC trovato.</p>';
            return;
        }

        echo '<h3>Utenti OIDC registrati (' . count($oidc_users) . ')</h3>';
        echo '<table class="widefat striped" style="max-width:100%;">';
        echo '<thead><tr><th>ID</th><th>Email</th><th>Subject ID</th><th>Ruoli WP</th><th>Ultimo login OIDC</th><th>Dati</th></tr></thead><tbody>';

        foreach ($oidc_users as $user) {
            $sub = get_user_meta($user->ID, 'ri_oidc_sub', true);
            $userinfo = get_user_meta($user->ID, 'ri_oidc_userinfo', true);
            $debug_data = get_user_meta($user->ID, 'ri_debug_userinfo', true);
            $roles = implode(', ', $user->roles);

            // Mostra i dati debug se disponibili, altrimenti la userinfo standard
            $data_to_show = !empty($debug_data) ? $debug_data : $userinfo;
            $data_source = !empty($debug_data) ? 'debug' : 'standard';

            echo '<tr>';
            echo '<td>' . esc_html($user->ID) . '</td>';
            echo '<td>' . esc_html($user->user_email) . '</td>';
            echo '<td><code style="font-size:11px;">' . esc_html($sub) . '</code></td>';
            echo '<td>' . esc_html($roles) . '</td>';
            echo '<td>' . esc_html($user->user_registered) . '</td>';
            echo '<td>';
            if (!empty($data_to_show)) {
                $badge = $data_source === 'debug'
                    ? '<span style="background:#f0c33c;padding:2px 6px;border-radius:3px;font-size:11px;">DEBUG</span> '
                    : '';
                echo '<details><summary>' . $badge . 'Mostra userinfo</summary>';
                echo '<pre style="max-height:300px;overflow:auto;font-size:11px;background:#f5f5f5;padding:8px;margin-top:4px;">';
                echo esc_html(wp_json_encode($data_to_show, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                echo '</pre></details>';
            } else {
                echo '<em style="color:#999;">Nessun dato</em>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        // Azione: pulisci dati debug
        if (!empty($oidc_users)) {
            echo '<form method="post" style="margin-top:12px;">';
            wp_nonce_field('ri_clear_debug', 'ri_debug_nonce');
            echo '<button type="submit" name="ri_clear_debug" class="button" onclick="return confirm(\'Eliminare tutti i dati debug userinfo?\');">Pulisci dati debug</button>';
            echo '</form>';

            // Handle clear
            if (isset($_POST['ri_clear_debug']) && wp_verify_nonce($_POST['ri_debug_nonce'] ?? '', 'ri_clear_debug')) {
                foreach ($oidc_users as $user) {
                    delete_user_meta($user->ID, 'ri_debug_userinfo');
                }
                echo '<div class="notice notice-success"><p>Dati debug eliminati.</p></div>';
            }
        }
    }

    // =========================================================================
    // Test methods (usati dal tab Test e dall'AJAX handler)
    // =========================================================================

    private function testDiscovery(): array {
        $issuer = $this->settings->getIssuer();
        $discovery_url = $issuer . '/.well-known/openid-configuration';

        $start = microtime(true);
        $response = wp_remote_get($discovery_url, ['timeout' => 10]);
        $elapsed = round((microtime(true) - $start) * 1000);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'step'    => 'discovery',
                'message' => 'Impossibile raggiungere il server Identity.',
                'error'   => $response->get_error_message(),
                'url'     => $discovery_url,
                'fix'     => 'Verifica che l\'URL issuer sia corretto e che il server sia raggiungibile.',
            ];
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status !== 200 || empty($body)) {
            return [
                'success' => false,
                'step'    => 'discovery',
                'message' => "Il server ha risposto con HTTP $status ma non ha restituito una configurazione OIDC valida.",
                'url'     => $discovery_url,
                'fix'     => 'Verifica che l\'issuer supporti OpenID Connect Discovery.',
            ];
        }

        $expected = ['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint'];
        $missing = [];
        foreach ($expected as $key) {
            if (empty($body[$key])) {
                $missing[] = $key;
            }
        }

        $endpoints = [
            'authorization_endpoint' => $body['authorization_endpoint'] ?? '—',
            'token_endpoint'         => $body['token_endpoint'] ?? '—',
            'userinfo_endpoint'      => $body['userinfo_endpoint'] ?? '—',
            'end_session_endpoint'   => $body['end_session_endpoint'] ?? '—',
        ];

        $mismatches = [];
        if (!empty($body['authorization_endpoint']) && $body['authorization_endpoint'] !== $this->settings->getAuthorizationEndpoint()) {
            $mismatches['authorization'] = [
                'configurato' => $this->settings->getAuthorizationEndpoint(),
                'discovery'   => $body['authorization_endpoint'],
            ];
        }
        if (!empty($body['token_endpoint']) && $body['token_endpoint'] !== $this->settings->getTokenEndpoint()) {
            $mismatches['token'] = [
                'configurato' => $this->settings->getTokenEndpoint(),
                'discovery'   => $body['token_endpoint'],
            ];
        }

        $supported_scopes = $body['scopes_supported'] ?? [];
        $our_scopes = explode(' ', $this->settings->getScopes());
        $unsupported = array_diff($our_scopes, $supported_scopes);

        $grant_types = $body['grant_types_supported'] ?? [];

        return [
            'success'           => empty($missing),
            'step'              => 'discovery',
            'message'           => empty($missing)
                ? "Server Identity raggiungibile e configurato correttamente ({$elapsed}ms)."
                : 'Endpoint mancanti nella discovery: ' . implode(', ', $missing),
            'url'               => $discovery_url,
            'response_time_ms'  => $elapsed,
            'issuer'            => $body['issuer'] ?? '—',
            'endpoints'         => $endpoints,
            'mismatches'        => $mismatches,
            'scopes_supported'  => $supported_scopes,
            'unsupported_scopes'=> $unsupported,
            'grant_types'       => $grant_types,
        ];
    }

    private function testTokenEndpoint(): array {
        $start = microtime(true);

        $request = [
            'body' => [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->settings->get('client_id'),
                'client_secret' => $this->settings->get('client_secret'),
                'scope'         => 'openid',
            ],
            'timeout' => 10,
        ];

        $response = wp_remote_post($this->settings->getTokenEndpoint(), $request);
        $elapsed = round((microtime(true) - $start) * 1000);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'step'    => 'token_endpoint',
                'message' => 'Impossibile raggiungere il token endpoint.',
                'error'   => $response->get_error_message(),
                'url'     => $this->settings->getTokenEndpoint(),
                'fix'     => 'Verifica la connettività di rete verso il server Identity.',
            ];
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        $client_valid = true;
        $fix = '';

        if ($status === 400 && isset($body['error'])) {
            if ($body['error'] === 'invalid_client') {
                $client_valid = false;
                $fix = 'Client ID o Client Secret non validi. Verifica le credenziali nelle impostazioni.';
            } elseif ($body['error'] === 'unsupported_grant_type') {
                $client_valid = true;
            }
        } elseif ($status === 401) {
            $client_valid = false;
            $fix = 'Autenticazione client fallita (HTTP 401). Verifica Client ID e Client Secret.';
        }

        return [
            'success'          => $client_valid,
            'step'             => 'token_endpoint',
            'message'          => $client_valid
                ? "Token endpoint raggiungibile, credenziali client valide ({$elapsed}ms)."
                : "Token endpoint raggiungibile ma credenziali client non valide.",
            'url'              => $this->settings->getTokenEndpoint(),
            'http_status'      => $status,
            'response_time_ms' => $elapsed,
            'error'            => $body['error'] ?? null,
            'error_description'=> $body['error_description'] ?? null,
            'fix'              => $fix,
        ];
    }

    private function testUserInfoEndpoint(): array {
        $start = microtime(true);

        $response = wp_remote_get($this->settings->getUserInfoEndpoint(), [
            'headers' => ['Authorization' => 'Bearer test-invalid-token'],
            'timeout' => 10,
        ]);
        $elapsed = round((microtime(true) - $start) * 1000);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'step'    => 'userinfo_endpoint',
                'message' => 'Impossibile raggiungere l\'endpoint userinfo.',
                'error'   => $response->get_error_message(),
                'url'     => $this->settings->getUserInfoEndpoint(),
                'fix'     => 'Verifica che l\'endpoint userinfo sia accessibile.',
            ];
        }

        $status = wp_remote_retrieve_response_code($response);
        $reachable = in_array($status, [200, 401, 403], true);

        $user_id = get_current_user_id();
        $access_token = get_user_meta($user_id, 'ri_access_token', true);
        $userinfo_data = null;

        if (!empty($access_token)) {
            $oidc = new OidcClient($this->settings);
            $userinfo_result = $oidc->getUserInfo($access_token);
            if (!is_wp_error($userinfo_result)) {
                $userinfo_data = $userinfo_result;
            }
        }

        return [
            'success'          => $reachable,
            'step'             => 'userinfo_endpoint',
            'message'          => $reachable
                ? "Endpoint userinfo raggiungibile ({$elapsed}ms)."
                : "L'endpoint userinfo ha risposto con HTTP $status inatteso.",
            'url'              => $this->settings->getUserInfoEndpoint(),
            'http_status'      => $status,
            'response_time_ms' => $elapsed,
            'has_saved_token'  => !empty($access_token),
            'userinfo'         => $userinfo_data,
        ];
    }

    private function testFullFlow(): array {
        $checks = [];

        $checks['issuer'] = [
            'label'  => 'Issuer URL',
            'value'  => $this->settings->getIssuer(),
            'ok'     => !empty($this->settings->getIssuer()),
            'fix'    => 'Configura l\'URL del server Identity nelle impostazioni.',
        ];

        $checks['client_id'] = [
            'label'  => 'Client ID',
            'value'  => $this->settings->get('client_id'),
            'ok'     => !empty($this->settings->get('client_id')),
            'fix'    => 'Configura il Client ID nelle impostazioni.',
        ];

        $checks['client_secret'] = [
            'label'  => 'Client Secret',
            'value'  => !empty($this->settings->get('client_secret')) ? '****' : '(vuoto)',
            'ok'     => !empty($this->settings->get('client_secret')),
            'fix'    => 'Configura il Client Secret nelle impostazioni.',
        ];

        $checks['scopes'] = [
            'label'  => 'Scope',
            'value'  => $this->settings->getScopes(),
            'ok'     => str_contains($this->settings->getScopes(), 'openid'),
            'fix'    => 'Lo scope "openid" è obbligatorio per OIDC.',
        ];

        $checks['callback_url'] = [
            'label'  => 'Callback URL',
            'value'  => $this->settings->getCallbackUrl(),
            'ok'     => true,
        ];

        $checks['redirect_uri'] = [
            'label'  => 'Redirect URI registrata sul server',
            'value'  => $this->settings->getCallbackUrl(),
            'ok'     => true,
            'note'   => 'Assicurati che questo URL sia registrato come redirect_uri nel client sul server Identity.',
        ];

        $oidc_users = get_users(['meta_key' => 'ri_oidc_sub', 'count_total' => true, 'number' => 0]);
        $checks['oidc_users'] = [
            'label'  => 'Utenti OIDC collegati',
            'value'  => count($oidc_users),
            'ok'     => true,
        ];

        $custom_roles = ['studente', 'docente', 'docente_sostegno', 'dirigente'];
        $missing_roles = [];
        foreach ($custom_roles as $role) {
            if (!get_role($role)) {
                $missing_roles[] = $role;
            }
        }
        $checks['roles'] = [
            'label'  => 'Ruoli WP personalizzati',
            'value'  => empty($missing_roles) ? 'Tutti registrati' : 'Mancanti: ' . implode(', ', $missing_roles),
            'ok'     => empty($missing_roles),
            'fix'    => 'Disattiva e riattiva il plugin per registrare i ruoli mancanti.',
        ];

        $checks['debug_mode'] = [
            'label'  => 'Modalità debug',
            'value'  => $this->settings->get('debug_mode', false) ? 'Attiva' : 'Disattiva',
            'ok'     => true,
        ];

        $all_ok = true;
        foreach ($checks as $check) {
            if (!$check['ok']) {
                $all_ok = false;
                break;
            }
        }

        return [
            'success' => $all_ok,
            'step'    => 'full_flow',
            'message' => $all_ok
                ? 'Configurazione completa e valida.'
                : 'Alcuni controlli hanno rilevato problemi.',
            'checks'  => $checks,
        ];
    }
}
