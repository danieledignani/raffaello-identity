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
