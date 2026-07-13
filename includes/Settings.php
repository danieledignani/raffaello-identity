<?php

namespace RaffaelloIdentity;

if (!defined('ABSPATH')) {
    exit;
}

class Settings {
    private array $options;

    public function __construct() {
        $this->options = ri_get_options();
    }

    public function init(): void {
        // Avvia sessione PHP per state/nonce OIDC
        add_action('init', function () {
            if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
                session_start();
            }
        }, 1);
    }

    public function get(string $key, $default = null) {
        return $this->options[$key] ?? $default;
    }

    public function getAll(): array {
        return $this->options;
    }

    public function reload(): void {
        $this->options = ri_get_options();
    }

    public function getIssuer(): string {
        return rtrim($this->get('issuer', ''), '/');
    }

    public function getAuthorizationEndpoint(): string {
        return $this->getIssuer() . '/connect/authorize';
    }

    public function getTokenEndpoint(): string {
        return $this->getIssuer() . '/connect/token';
    }

    public function getUserInfoEndpoint(): string {
        return $this->getIssuer() . '/connect/userinfo';
    }

    public function getEndSessionEndpoint(): string {
        return $this->getIssuer() . '/connect/logout';
    }

    public function getCallbackUrl(): string {
        return admin_url('admin-ajax.php?action=openid-connect-authorize');
    }

    /**
     * URL della pagina profilo sul server Identity, con returnUrl verso il sito chiamante.
     * Identity valida il returnUrl contro i RedirectUris/PostLogoutRedirectUris dell'applicazione OpenIddict.
     *
     * Il parametro logoutUrl indica a Identity DOVE mandare l'utente dopo il click su "Esci":
     * un endpoint WP locale (ri_local_logout) che fa solo wp_logout() e poi redirect a home.
     * In questo modo il click su Esci dall'header Identity sincronizza anche il logout WP,
     * senza creare un loop con /connect/logout.
     *
     * @param string|null $return_to URL a cui tornare (default: home del sito corrente).
     */
    public function getIdentityAccountUrl(?string $return_to = null): string {
        $return_to = $return_to ?: home_url('/');
        $logout_return = admin_url('admin-ajax.php?action=ri_local_logout&return_to=' . rawurlencode($return_to));
        $query = http_build_query([
            'returnUrl' => $return_to,
            'logoutUrl' => $logout_return,
        ]);
        return $this->getIssuer() . '/Identity/Account/Manage?' . $query;
    }

    /**
     * URL di logout federato che passa dal server Identity (compreso end_session).
     */
    public function getLogoutUrl(): string {
        return admin_url('admin-ajax.php?action=ri_logout');
    }

    public function getScopes(): string {
        return $this->get('scopes', 'openid email profile offline_access roles');
    }

    /**
     * Intervallo (secondi) di rivalidazione forzata della sessione: entro questo tempo un
     * logout eseguito su Identity si propaga a WordPress. Clampato a [30, 86400].
     */
    public function getSessionRecheckSeconds(): int {
        $value = (int) $this->get('session_recheck_seconds', 300);
        return max(30, min(86400, $value));
    }

    /**
     * Timeout (secondi) delle chiamate di refresh token. Basso per non saturare i worker
     * PHP-FPM se Identity è lento/irraggiungibile. Clampato a [3, 60].
     */
    public function getRefreshTimeoutSeconds(): int {
        $value = (int) $this->get('refresh_timeout_seconds', 10);
        return max(3, min(60, $value));
    }

    public function getRoleMapping(): array {
        return $this->get('role_mapping', []);
    }

    public function getClaimMapping(): array {
        return $this->get('claim_mapping', []);
    }

    public function getExtraClaims(): array {
        $raw = $this->get('extra_claims', '');
        if (empty($raw)) {
            return [];
        }
        return array_map('trim', explode(',', $raw));
    }
}
