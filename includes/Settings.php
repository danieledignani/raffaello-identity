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
     * @param string|null $return_to URL a cui tornare (default: home del sito corrente).
     */
    public function getIdentityAccountUrl(?string $return_to = null): string {
        $return_to = $return_to ?: home_url('/');
        return $this->getIssuer() . '/Identity/Account/Manage?returnUrl=' . rawurlencode($return_to);
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
