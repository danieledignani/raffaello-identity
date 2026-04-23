<?php

namespace RaffaelloIdentity;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Client OIDC: gestisce Authorization Code Flow con il server Identity.
 */
class OidcClient {
    private Settings $settings;

    public function __construct(Settings $settings) {
        $this->settings = $settings;
    }

    public function init(): void {
        // Callback OIDC via admin-ajax (compatibile con il redirect_uri già configurato sul server)
        add_action('wp_ajax_nopriv_openid-connect-authorize', [$this, 'handleCallback']);
        add_action('wp_ajax_openid-connect-authorize', [$this, 'handleCallback']);

        // Logout
        add_action('wp_ajax_ri_logout', [$this, 'handleLogout']);
        add_action('wp_ajax_nopriv_ri_logout', [$this, 'handleLogout']);

        // Logout locale (senza federated). Usato quando l'utente clicca "Esci" dall'header
        // di Identity: Identity fa sign-out locale e rimanda a questo endpoint che chiude
        // solo la sessione WP e redirige a home (o al return_to passato). Evita il loop
        // Identity→WP→Identity del logout federato quando l'utente è già sloggato da Identity.
        add_action('wp_ajax_ri_local_logout', [$this, 'handleLocalLogout']);
        add_action('wp_ajax_nopriv_ri_local_logout', [$this, 'handleLocalLogout']);

        // Token refresh automatico ad ogni page load per utenti loggati
        add_action('wp_loaded', [$this, 'ensureTokensFresh']);

        // Sovrascrive l'avatar Gravatar con quello dal server Identity
        add_filter('get_avatar_url', [$this, 'filterAvatarUrl'], 10, 3);

        // Per gli utenti OIDC, tutti i link di logout (WooCommerce, WP, ecc.)
        // devono passare dal logout federato sul server Identity
        add_filter('logout_url', [$this, 'filterLogoutUrl'], 10, 2);
    }

    /**
     * Genera l'URL di login OIDC (redirect al server Identity).
     */
    public function getAuthorizationUrl(): string {
        $state = ri_generate_state();
        $nonce = ri_generate_nonce();

        $params = [
            'response_type' => 'code',
            'client_id'     => $this->settings->get('client_id'),
            'redirect_uri'  => $this->settings->getCallbackUrl(),
            'scope'         => $this->settings->getScopes(),
            'state'         => $state,
            'nonce'         => $nonce,
        ];

        $url = $this->settings->getAuthorizationEndpoint() . '?' . http_build_query($params);

        Logger::info('auth_redirect', 'Generato URL di redirect al server Identity', [
            'client_id'    => $params['client_id'],
            'redirect_uri' => $params['redirect_uri'],
            'scope'        => $params['scope'],
            'issuer'       => $this->settings->getIssuer(),
        ]);

        return $url;
    }

    /**
     * Gestisce il callback dal server Identity dopo l'autenticazione.
     */
    public function handleCallback(): void {
        Logger::info('callback_received', 'Callback ricevuto dal server Identity', [
            'has_code'  => isset($_GET['code']),
            'has_state' => isset($_GET['state']),
            'has_error' => isset($_GET['error']),
        ]);

        // Verifica errori dal server Identity
        if (isset($_GET['error'])) {
            $error = sanitize_text_field($_GET['error']);
            $desc = sanitize_text_field($_GET['error_description'] ?? '');

            Logger::error('auth_error', "Errore dal server Identity: $error", [
                'error'             => $error,
                'error_description' => $desc,
            ]);

            wp_die(
                sprintf('Errore di autenticazione: %s — %s', esc_html($error), esc_html($desc)),
                'Errore Login',
                ['response' => 403]
            );
        }

        $code = sanitize_text_field($_GET['code'] ?? '');
        $state = sanitize_text_field($_GET['state'] ?? '');

        if (empty($code) || empty($state)) {
            Logger::error('callback_invalid', 'Parametri mancanti nel callback', [
                'has_code'  => !empty($code),
                'has_state' => !empty($state),
            ]);
            wp_die('Parametri mancanti nella risposta di autenticazione.', 'Errore', ['response' => 400]);
        }

        // Verifica state CSRF
        if (!ri_verify_state($state)) {
            Logger::error('state_mismatch', 'Verifica state CSRF fallita — possibile attacco CSRF o sessione scaduta');
            wp_die('Verifica state CSRF fallita. Prova a effettuare nuovamente il login.', 'Errore di sicurezza', ['response' => 403]);
        }

        Logger::debug('state_verified', 'State CSRF verificato con successo');

        // Scambia il code per i token
        Logger::info('token_exchange_start', 'Inizio scambio authorization code per token', [
            'token_endpoint' => $this->settings->getTokenEndpoint(),
        ]);

        $tokens = $this->exchangeCode($code);
        if (is_wp_error($tokens)) {
            Logger::error('token_exchange_failed', 'Scambio code fallito: ' . $tokens->get_error_message(), [
                'error_code'    => $tokens->get_error_code(),
                'error_message' => $tokens->get_error_message(),
            ]);
            wp_die(
                'Errore nello scambio del codice: ' . esc_html($tokens->get_error_message()),
                'Errore Token',
                ['response' => 500]
            );
        }

        Logger::info('token_exchange_ok', 'Token ricevuti con successo', [
            'has_access_token'  => !empty($tokens['access_token']),
            'has_refresh_token' => !empty($tokens['refresh_token']),
            'has_id_token'      => !empty($tokens['id_token']),
            'token_type'        => $tokens['token_type'] ?? 'N/D',
            'expires_in'        => $tokens['expires_in'] ?? 'N/D',
        ]);

        // Recupera le informazioni utente
        Logger::info('userinfo_start', 'Richiesta informazioni utente', [
            'userinfo_endpoint' => $this->settings->getUserInfoEndpoint(),
        ]);

        $userinfo = $this->getUserInfo($tokens['access_token']);
        if (is_wp_error($userinfo)) {
            Logger::error('userinfo_failed', 'Recupero userinfo fallito: ' . $userinfo->get_error_message(), [
                'error_code'    => $userinfo->get_error_code(),
                'error_message' => $userinfo->get_error_message(),
            ]);
            wp_die(
                'Errore nel recupero del profilo: ' . esc_html($userinfo->get_error_message()),
                'Errore Profilo',
                ['response' => 500]
            );
        }

        Logger::info('userinfo_ok', 'Informazioni utente ricevute', [
            'sub'     => $userinfo['sub'] ?? 'N/D',
            'email'   => $userinfo['email'] ?? 'N/D',
            'nome'    => $userinfo['nome'] ?? $userinfo['given_name'] ?? 'N/D',
            'cognome' => $userinfo['cognome'] ?? $userinfo['family_name'] ?? 'N/D',
            'roles'   => $userinfo['role'] ?? [],
            'profilo' => $userinfo['profilo'] ?? 'N/D',
        ]);

        // Crea o aggiorna l'utente WP
        $user_id = $this->syncUser($userinfo, $tokens);
        if (is_wp_error($user_id)) {
            Logger::error('user_sync_failed', 'Sincronizzazione utente fallita: ' . $user_id->get_error_message(), [
                'error_code'    => $user_id->get_error_code(),
                'error_message' => $user_id->get_error_message(),
                'email'         => $userinfo['email'] ?? 'N/D',
            ]);
            wp_die(
                'Errore nella sincronizzazione utente: ' . esc_html($user_id->get_error_message()),
                'Errore Utente',
                ['response' => 500]
            );
        }

        // Login WP
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);
        $user_data = get_userdata($user_id);
        do_action('wp_login', $user_data->user_login, $user_data);

        // Salva i token per eventuali usi futuri (refresh, logout)
        $this->saveTokens($user_id, $tokens);

        // Debug mode: salva la risposta userinfo completa (token, claim, timestamp)
        if ($this->settings->get('debug_mode', false)) {
            update_user_meta($user_id, 'ri_debug_userinfo', [
                'userinfo'   => $userinfo,
                'tokens_meta'=> [
                    'has_access_token'  => !empty($tokens['access_token']),
                    'has_refresh_token' => !empty($tokens['refresh_token']),
                    'has_id_token'      => !empty($tokens['id_token']),
                    'token_type'        => $tokens['token_type'] ?? null,
                    'expires_in'        => $tokens['expires_in'] ?? null,
                    'scope'             => $tokens['scope'] ?? null,
                ],
                'timestamp'  => current_time('mysql'),
                'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
            Logger::debug('debug_userinfo_saved', "Dati debug salvati per utente #$user_id");
        }

        /**
         * Filtro: consente di modificare l'URL di redirect post-login.
         *
         * @param string   $redirect URL di redirect.
         * @param \WP_User $user     Utente WordPress appena loggato.
         */
        $redirect = apply_filters('ri_login_redirect', $this->settings->get('login_redirect', home_url('/')), $user_data);

        do_action('ri_user_logged_in', $user_data, $tokens, $userinfo);

        Logger::info('login_success', "Login completato per {$user_data->user_email}", [
            'user_id'    => $user_id,
            'email'      => $user_data->user_email,
            'username'   => $user_data->user_login,
            'roles'      => $user_data->roles,
            'redirect'   => $redirect,
        ]);

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Scambia l'authorization code per access/refresh/id token.
     */
    private function exchangeCode(string $code) {
        $request = [
            'body' => [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $this->settings->getCallbackUrl(),
                'client_id'     => $this->settings->get('client_id'),
                'client_secret' => $this->settings->get('client_secret'),
            ],
            'timeout' => 30,
        ];

        /** @see ri_alter_request */
        $request = apply_filters('ri_alter_request', $request, 'get-token');

        $response = wp_remote_post($this->settings->getTokenEndpoint(), $request);

        if (is_wp_error($response)) {
            Logger::error('token_http_error', 'Errore HTTP nella richiesta token', [
                'error_message' => $response->get_error_message(),
                'endpoint'      => $this->settings->getTokenEndpoint(),
            ]);
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $parsed = json_decode($raw_body, true);

        if ($status !== 200 || empty($parsed['access_token'])) {
            $error = $parsed['error_description'] ?? $parsed['error'] ?? 'Risposta non valida dal server token';

            Logger::error('token_response_error', "Risposta token non valida (HTTP $status)", [
                'http_status'       => $status,
                'error'             => $parsed['error'] ?? 'N/D',
                'error_description' => $parsed['error_description'] ?? 'N/D',
                'response_body'     => mb_substr($raw_body, 0, 500),
            ]);

            return new \WP_Error('token_error', $error);
        }

        return $parsed;
    }

    /**
     * Recupera le informazioni utente dall'endpoint userinfo.
     */
    public function getUserInfo(string $access_token) {
        $request = [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
            ],
            'timeout' => 15,
        ];

        /** @see ri_alter_request */
        $request = apply_filters('ri_alter_request', $request, 'get-userinfo');

        $response = wp_remote_get($this->settings->getUserInfoEndpoint(), $request);

        if (is_wp_error($response)) {
            Logger::error('userinfo_http_error', 'Errore HTTP nella richiesta userinfo', [
                'error_message' => $response->get_error_message(),
                'endpoint'      => $this->settings->getUserInfoEndpoint(),
            ]);
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $parsed = json_decode($raw_body, true);

        if ($status !== 200 || empty($parsed['sub'])) {
            Logger::error('userinfo_response_error', "Risposta userinfo non valida (HTTP $status)", [
                'http_status'   => $status,
                'response_body' => mb_substr($raw_body, 0, 500),
            ]);
            return new \WP_Error('userinfo_error', 'Impossibile recuperare le informazioni utente.');
        }

        return $parsed;
    }

    /**
     * Crea o aggiorna un utente WordPress in base ai dati OIDC.
     */
    private function syncUser(array $userinfo, array $tokens) {
        $sub = sanitize_text_field($userinfo['sub']);
        $email = sanitize_email($userinfo['email'] ?? '');

        if (empty($email)) {
            Logger::error('sync_no_email', 'Email mancante nei dati OIDC', [
                'sub'      => $sub,
                'userinfo' => array_keys($userinfo),
            ]);
            return new \WP_Error('no_email', 'Email mancante nei dati del profilo.');
        }

        // Cerca utente esistente per subject ID
        $users = get_users([
            'meta_key'   => 'ri_oidc_sub',
            'meta_value' => $sub,
            'number'     => 1,
        ]);

        $user = !empty($users) ? $users[0] : null;

        // Fallback: cerca per email
        if (!$user) {
            $user = get_user_by('email', $email);
            if ($user) {
                Logger::info('sync_found_by_email', "Utente trovato per email (non per sub), collegamento in corso", [
                    'user_id' => $user->ID,
                    'email'   => $email,
                    'sub'     => $sub,
                ]);
            }
        } else {
            Logger::debug('sync_found_by_sub', "Utente esistente trovato per subject ID", [
                'user_id' => $user->ID,
                'sub'     => $sub,
            ]);
        }

        /**
         * Filtro: consente di modificare i claim utente prima della sincronizzazione.
         *
         * @param array $userinfo Dati utente dal server Identity.
         */
        $userinfo = apply_filters('ri_alter_user_claim', $userinfo);

        /**
         * Filtro: consente di bloccare il login in base ai claim (es. gruppo, ruolo).
         * Restituire false o un WP_Error per bloccare.
         *
         * @param bool  $allow    True per consentire il login.
         * @param array $userinfo Dati utente dal server Identity.
         */
        $allow_login = apply_filters('ri_user_login_test', true, $userinfo);
        if ($allow_login instanceof \WP_Error) {
            return $allow_login;
        }
        if ($allow_login === false) {
            Logger::warning('login_denied_by_filter', 'Login bloccato dal filtro ri_user_login_test', [
                'sub'   => $sub,
                'email' => $email,
            ]);
            return new \WP_Error('login_denied', 'Accesso non consentito per questo utente.');
        }

        $nome = sanitize_text_field($userinfo['nome'] ?? $userinfo['given_name'] ?? '');
        $cognome = sanitize_text_field($userinfo['cognome'] ?? $userinfo['family_name'] ?? '');

        if ($user) {
            // Aggiorna utente esistente
            $update_data = ['ID' => $user->ID];

            if (!empty($nome)) {
                $update_data['first_name'] = $nome;
            }
            if (!empty($cognome)) {
                $update_data['last_name'] = $cognome;
            }
            $update_data['display_name'] = trim("$nome $cognome") ?: $user->display_name;

            wp_update_user($update_data);
            $user_id = $user->ID;

            Logger::info('sync_user_updated', "Utente aggiornato", [
                'user_id' => $user_id,
                'email'   => $email,
            ]);
        } else {
            // Auto-registrazione
            if (!$this->settings->get('auto_register')) {
                Logger::warning('sync_auto_register_disabled', "Tentativo di auto-registrazione bloccato (disabilitata)", [
                    'email' => $email,
                    'sub'   => $sub,
                ]);
                return new \WP_Error('no_auto_register', 'La registrazione automatica è disabilitata.');
            }

            /**
             * Filtro: consente di bloccare la creazione di nuovi utenti in base ai claim.
             * Restituire false o un WP_Error per bloccare.
             *
             * @param bool  $allow    True per consentire la creazione.
             * @param array $userinfo Dati utente dal server Identity.
             */
            $allow_create = apply_filters('ri_user_creation_test', true, $userinfo);
            if ($allow_create instanceof \WP_Error) {
                return $allow_create;
            }
            if ($allow_create === false) {
                Logger::warning('creation_denied_by_filter', 'Creazione utente bloccata dal filtro ri_user_creation_test', [
                    'sub'   => $sub,
                    'email' => $email,
                ]);
                return new \WP_Error('creation_denied', 'La creazione di questo utente non è consentita.');
            }

            $username = sanitize_user(explode('@', $email)[0]);
            $base_username = $username;
            $counter = 1;
            while (username_exists($username)) {
                $username = $base_username . $counter;
                $counter++;
            }

            $default_role = get_role('customer') ? 'customer' : 'subscriber';

            $user_data = [
                'user_login'   => $username,
                'user_email'   => $email,
                'user_pass'    => wp_generate_password(32, true),
                'first_name'   => $nome,
                'last_name'    => $cognome,
                'display_name' => trim("$nome $cognome") ?: $username,
                'role'         => $default_role,
            ];

            /**
             * Filtro: consente di modificare i dati utente WordPress prima della creazione.
             *
             * @param array $user_data Dati per wp_insert_user().
             * @param array $userinfo  Claim OIDC originali.
             */
            $user_data = apply_filters('ri_alter_user_data', $user_data, $userinfo);

            $user_id = wp_insert_user($user_data);

            if (is_wp_error($user_id)) {
                Logger::error('sync_create_failed', "Creazione utente WP fallita", [
                    'email'         => $email,
                    'username'      => $username,
                    'error_message' => $user_id->get_error_message(),
                ]);
                return $user_id;
            }

            Logger::info('sync_user_created', "Nuovo utente WordPress creato", [
                'user_id'  => $user_id,
                'email'    => $email,
                'username' => $username,
            ]);
        }

        // Salva subject ID e claim
        update_user_meta($user_id, 'ri_oidc_sub', $sub);
        update_user_meta($user_id, 'ri_oidc_userinfo', $userinfo);

        // Scarica e salva l'avatar dal server Identity
        $this->syncAvatar($user_id, $userinfo);

        // Salva claim extra configurati
        foreach ($this->settings->getExtraClaims() as $claim) {
            if (isset($userinfo[$claim])) {
                update_user_meta($user_id, 'ri_claim_' . sanitize_key($claim), sanitize_text_field($userinfo[$claim]));
            }
        }

        // Mappa i ruoli
        $this->mapRoles($user_id, $userinfo);

        do_action('ri_user_synced', $user_id, $userinfo, $tokens);

        return $user_id;
    }

    /**
     * Mappa i ruoli OIDC ai ruoli WordPress.
     */
    private function mapRoles(int $user_id, array $userinfo): void {
        $role_mapping = $this->settings->getRoleMapping();
        $roles = $userinfo['role'] ?? [];

        if (is_string($roles)) {
            $roles = [$roles];
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }

        // Rimuovi ruoli precedenti mappati dal plugin
        $mapped_wp_roles = array_values($role_mapping);
        foreach ($user->roles as $existing_role) {
            if (in_array($existing_role, $mapped_wp_roles, true)) {
                $user->remove_role($existing_role);
            }
        }

        // Assegna nuovi ruoli
        $assigned = false;
        $assigned_roles = [];
        foreach ($roles as $oidc_role) {
            if (isset($role_mapping[$oidc_role])) {
                $user->add_role($role_mapping[$oidc_role]);
                $assigned_roles[] = "$oidc_role → {$role_mapping[$oidc_role]}";
                $assigned = true;
            }
        }

        // Mappa anche in base al profilo se presente
        $profilo = $userinfo['profilo'] ?? '';
        if (!empty($profilo) && isset($role_mapping[$profilo]) && !$assigned) {
            $user->add_role($role_mapping[$profilo]);
            $assigned_roles[] = "profilo:$profilo → {$role_mapping[$profilo]}";
            $assigned = true;
        }

        // Se nessun ruolo mappato, assegna "customer" (WooCommerce) se esiste, altrimenti "subscriber"
        if (!$assigned) {
            $default_role = get_role('customer') ? 'customer' : 'subscriber';
            $user->add_role($default_role);
            $assigned_roles[] = "(default) → $default_role";
        }

        Logger::info('roles_mapped', "Ruoli mappati per utente #$user_id", [
            'user_id'        => $user_id,
            'oidc_roles'     => $roles,
            'profilo'        => $profilo,
            'mapped'         => $assigned_roles,
            'wp_roles_final' => get_userdata($user_id)->roles,
        ]);
    }

    /**
     * Gestisce il logout federato.
     */
    public function handleLogout(): void {
        $user_id = get_current_user_id();
        $id_token = get_user_meta($user_id, 'ri_id_token', true);

        Logger::info('logout_start', "Logout avviato per utente #$user_id", [
            'user_id'      => $user_id,
            'has_id_token' => !empty($id_token),
        ]);

        // Logout WordPress
        wp_logout();

        // Redirect al logout del server Identity
        $params = [
            'post_logout_redirect_uri' => $this->settings->get('logout_redirect', home_url('/')),
        ];

        if (!empty($id_token)) {
            $params['id_token_hint'] = $id_token;
        }

        $logout_url = $this->settings->getEndSessionEndpoint() . '?' . http_build_query($params);

        Logger::info('logout_redirect', 'Redirect al logout Identity', [
            'logout_endpoint'          => $this->settings->getEndSessionEndpoint(),
            'post_logout_redirect_uri' => $params['post_logout_redirect_uri'],
        ]);

        wp_redirect($logout_url);
        exit;
    }

    /**
     * Logout solo-WordPress senza passare da /connect/logout di Identity.
     *
     * Usato quando l'utente clicca "Esci" direttamente sull'header Identity:
     * Identity ha già chiuso la sua sessione e redirige qui per chiudere anche
     * la sessione WP. Non richiamiamo end_session (lo farebbe in loop perché
     * Identity ci rimanderebbe di nuovo qui come post_logout_redirect_uri).
     *
     * Parametro query opzionale "return_to" = URL locale WP dove atterrare dopo
     * il logout. Default: home_url('/').
     */
    public function handleLocalLogout(): void {
        $user_id = get_current_user_id();

        Logger::info('local_logout', "Logout locale WP (richiesto da Identity) per utente #$user_id", [
            'user_id' => $user_id,
        ]);

        wp_logout();

        $return_to = isset($_GET['return_to']) ? esc_url_raw(wp_unslash($_GET['return_to'])) : '';

        // Accetta solo URL dello stesso sito (anti open-redirect).
        if (!empty($return_to) && wp_validate_redirect($return_to, '') !== '') {
            wp_safe_redirect($return_to);
        } else {
            wp_safe_redirect(home_url('/'));
        }
        exit;
    }

    /**
     * Salva i token e le scadenze nei user_meta.
     */
    private function saveTokens(int $user_id, array $tokens): void {
        update_user_meta($user_id, 'ri_access_token', $tokens['access_token']);

        if (!empty($tokens['refresh_token'])) {
            update_user_meta($user_id, 'ri_refresh_token', $tokens['refresh_token']);
        }
        if (!empty($tokens['id_token'])) {
            update_user_meta($user_id, 'ri_id_token', $tokens['id_token']);
        }

        // Salva il timestamp di scadenza dell'access token
        if (!empty($tokens['expires_in'])) {
            $expires_at = time() + (int) $tokens['expires_in'];
            update_user_meta($user_id, 'ri_token_expires_at', $expires_at);
        }

        // Salva il timestamp di scadenza del refresh token (se fornito dal server)
        if (!empty($tokens['refresh_expires_in'])) {
            $refresh_expires_at = time() + (int) $tokens['refresh_expires_in'];
            update_user_meta($user_id, 'ri_refresh_expires_at', $refresh_expires_at);
        }
    }

    /**
     * Controlla ad ogni page load se l'access token è scaduto e lo rinnova.
     * Se il refresh token è scaduto, esegue il logout.
     */
    public function ensureTokensFresh(): void {
        if (!is_user_logged_in()) {
            return;
        }

        $user_id = get_current_user_id();

        // Verifica che sia un utente OIDC (ha un subject ID)
        $sub = get_user_meta($user_id, 'ri_oidc_sub', true);
        if (empty($sub)) {
            return;
        }

        $expires_at = (int) get_user_meta($user_id, 'ri_token_expires_at', true);
        if (empty($expires_at) || time() < $expires_at) {
            return; // Token ancora valido o nessuna scadenza salvata
        }

        // Access token scaduto — proviamo il refresh
        $refresh_token = get_user_meta($user_id, 'ri_refresh_token', true);
        if (empty($refresh_token)) {
            Logger::warning('auto_refresh_no_token', "Access token scaduto ma nessun refresh token per utente #$user_id — logout");
            $this->forceLogout($user_id);
            return;
        }

        // Controlla se il refresh token stesso è scaduto
        $refresh_expires_at = (int) get_user_meta($user_id, 'ri_refresh_expires_at', true);
        if (!empty($refresh_expires_at) && time() >= $refresh_expires_at) {
            Logger::warning('auto_refresh_expired', "Refresh token scaduto per utente #$user_id — logout");
            do_action('ri_session_expired', $user_id);
            $this->forceLogout($user_id);
            return;
        }

        // Esegui il refresh — se il server Identity è irraggiungibile,
        // non forzare il logout: l'utente può continuare a navigare
        // e il refresh verrà ritentato al prossimo page load.
        $result = $this->refreshToken($user_id);
        if (is_wp_error($result)) {
            Logger::warning('auto_refresh_failed', "Refresh automatico fallito per utente #$user_id — sessione mantenuta, ritenterà al prossimo caricamento", [
                'error' => $result->get_error_message(),
            ]);
        }
    }

    /**
     * Rinnova l'access token usando il refresh token.
     */
    public function refreshToken(int $user_id) {
        $refresh_token = get_user_meta($user_id, 'ri_refresh_token', true);
        if (empty($refresh_token)) {
            Logger::warning('refresh_no_token', "Nessun refresh token per utente #$user_id");
            return new \WP_Error('no_refresh_token', 'Nessun refresh token disponibile.');
        }

        Logger::info('refresh_start', "Rinnovo token per utente #$user_id");

        $request = [
            'body' => [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refresh_token,
                'client_id'     => $this->settings->get('client_id'),
                'client_secret' => $this->settings->get('client_secret'),
            ],
            'timeout' => 30,
        ];

        /**
         * Filtro: consente di modificare la richiesta HTTP prima dell'invio al server Identity.
         *
         * @param array  $request   Parametri della richiesta wp_remote_post.
         * @param string $operation Tipo di operazione: 'refresh-token'.
         */
        $request = apply_filters('ri_alter_request', $request, 'refresh-token');

        $response = wp_remote_post($this->settings->getTokenEndpoint(), $request);

        if (is_wp_error($response)) {
            Logger::error('refresh_http_error', 'Errore HTTP nel rinnovo token', [
                'error_message' => $response->get_error_message(),
            ]);
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['access_token'])) {
            Logger::error('refresh_failed', 'Rinnovo token fallito', [
                'http_status' => wp_remote_retrieve_response_code($response),
                'error'       => $body['error'] ?? 'N/D',
            ]);
            return new \WP_Error('refresh_error', 'Impossibile rinnovare il token.');
        }

        // Salva i nuovi token e le scadenze
        $this->saveTokens($user_id, $body);

        Logger::info('refresh_ok', "Token rinnovato per utente #$user_id");

        do_action('ri_token_refreshed', $user_id, $body);

        return $body;
    }

    /**
     * Scarica l'avatar dal server Identity e lo salva nella cartella uploads di WordPress.
     */
    private function syncAvatar(int $user_id, array $userinfo): void {
        $picture_url = $userinfo['picture'] ?? '';

        if (empty($picture_url)) {
            // Nessun avatar sul server Identity: rimuovi eventuale avatar locale
            $old_path = get_user_meta($user_id, 'ri_avatar_path', true);
            if (!empty($old_path) && file_exists($old_path)) {
                @unlink($old_path);
            }
            delete_user_meta($user_id, 'ri_avatar_url');
            delete_user_meta($user_id, 'ri_avatar_path');
            return;
        }

        // Controlla se l'URL è cambiato dall'ultimo sync
        $current_url = get_user_meta($user_id, 'ri_avatar_source_url', true);
        if ($current_url === $picture_url) {
            return; // Avatar non cambiato
        }

        // Scarica l'immagine dal server Identity
        $response = wp_remote_get($picture_url, ['timeout' => 15]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            Logger::warning('avatar_download_failed', "Download avatar fallito per utente #$user_id", [
                'picture_url' => $picture_url,
                'error'       => is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($response),
            ]);
            return;
        }

        $image_data = wp_remote_retrieve_body($response);
        $content_type = wp_remote_retrieve_header($response, 'content-type');

        // Determina estensione dal content-type
        $ext_map = [
            'image/jpeg' => '.jpg',
            'image/png'  => '.png',
            'image/gif'  => '.gif',
            'image/webp' => '.webp',
        ];
        $ext = $ext_map[$content_type] ?? '.jpg';

        // Salva nella cartella uploads/ri-avatars/
        $upload_dir = wp_upload_dir();
        $avatar_dir = $upload_dir['basedir'] . '/ri-avatars';
        if (!is_dir($avatar_dir)) {
            wp_mkdir_p($avatar_dir);
        }

        $filename = 'avatar-' . $user_id . $ext;
        $filepath = $avatar_dir . '/' . $filename;
        $fileurl = $upload_dir['baseurl'] . '/ri-avatars/' . $filename;

        // Rimuovi vecchio avatar se estensione diversa
        $old_path = get_user_meta($user_id, 'ri_avatar_path', true);
        if (!empty($old_path) && $old_path !== $filepath && file_exists($old_path)) {
            @unlink($old_path);
        }

        // Scrivi il file
        $written = file_put_contents($filepath, $image_data);
        if ($written === false) {
            Logger::error('avatar_save_failed', "Impossibile salvare avatar per utente #$user_id", [
                'filepath' => $filepath,
            ]);
            return;
        }

        update_user_meta($user_id, 'ri_avatar_url', $fileurl);
        update_user_meta($user_id, 'ri_avatar_path', $filepath);
        update_user_meta($user_id, 'ri_avatar_source_url', $picture_url);

        Logger::info('avatar_synced', "Avatar sincronizzato per utente #$user_id", [
            'picture_url' => $picture_url,
            'local_url'   => $fileurl,
        ]);
    }

    /**
     * Per gli utenti OIDC, sostituisce l'URL di logout standard di WordPress
     * con il logout federato che passa dal server Identity.
     * Risolve il problema del "Logout" WooCommerce che fa solo logout locale.
     */
    public function filterLogoutUrl(string $logout_url, string $redirect): string {
        if (!is_user_logged_in()) {
            return $logout_url;
        }

        $user_id = get_current_user_id();
        $sub = get_user_meta($user_id, 'ri_oidc_sub', true);
        if (empty($sub)) {
            return $logout_url; // Non è un utente OIDC
        }

        return admin_url('admin-ajax.php?action=ri_logout');
    }

    /**
     * Sovrascrive l'URL dell'avatar Gravatar con quello scaricato dal server Identity.
     */
    public function filterAvatarUrl($url, $id_or_email, $args) {
        $user_id = 0;

        if (is_numeric($id_or_email)) {
            $user_id = (int) $id_or_email;
        } elseif ($id_or_email instanceof \WP_User) {
            $user_id = $id_or_email->ID;
        } elseif ($id_or_email instanceof \WP_Post) {
            $user_id = (int) $id_or_email->post_author;
        } elseif ($id_or_email instanceof \WP_Comment) {
            if (!empty($id_or_email->user_id)) {
                $user_id = (int) $id_or_email->user_id;
            }
        } elseif (is_string($id_or_email)) {
            $user = get_user_by('email', $id_or_email);
            if ($user) {
                $user_id = $user->ID;
            }
        }

        if ($user_id > 0) {
            $custom_url = get_user_meta($user_id, 'ri_avatar_url', true);
            if (!empty($custom_url)) {
                return $custom_url;
            }
        }

        return $url;
    }

    /**
     * Forza il logout e redirect alla pagina di login.
     */
    private function forceLogout(int $user_id): void {
        wp_logout();

        $redirect = $this->settings->get('logout_redirect', home_url('/'));

        /**
         * Filtro: consente di modificare l'URL di redirect dopo un logout forzato per sessione scaduta.
         *
         * @param string $redirect URL di redirect.
         * @param int    $user_id  ID dell'utente.
         */
        $redirect = apply_filters('ri_session_expired_redirect', $redirect, $user_id);

        if (!wp_doing_ajax() && !wp_doing_cron() && !defined('REST_REQUEST')) {
            wp_safe_redirect($redirect);
            exit;
        }
    }
}
