<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var RaffaelloIdentity\OidcClient $this->oidc — disponibile tramite il contesto del Frontend */
$login_url = esc_url($this->oidc->getAuthorizationUrl());
$button_text = esc_html($this->settings->get('login_button_text', 'Accedi con Raffaello'));
?>
<div class="ri-login-form">
    <div class="ri-login-card">
        <div class="ri-login-header">
            <h2>Accedi</h2>
            <p>Verrai reindirizzato al portale Raffaello per effettuare l'accesso in modo sicuro.</p>
        </div>

        <div class="ri-login-body">
            <a href="<?php echo $login_url; ?>" class="ri-login-btn ri-login-btn-primary">
                <svg class="ri-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                    <polyline points="10 17 15 12 10 7"/>
                    <line x1="15" y1="12" x2="3" y2="12"/>
                </svg>
                <?php echo $button_text; ?>
            </a>
        </div>

        <div class="ri-login-footer">
            <p>Non hai un account? <a href="<?php echo esc_url($this->settings->getIssuer() . '/Account/Register'); ?>">Registrati</a></p>
        </div>
    </div>
</div>
