<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var \RaffaelloIdentity\Settings $settings */
?>
<div class="wrap ri-admin-wrap">
    <h1>Raffaello Identity — Test Connessione</h1>
    <p class="description">
        Verifica la configurazione OIDC e la connettività con il server Identity.<br>
        I test vengono eseguiti in sequenza per diagnosticare eventuali problemi.
    </p>

    <div class="ri-test-config-summary" style="background:#f9f9f9;border:1px solid #ddd;padding:12px 16px;margin:16px 0;border-radius:4px;">
        <strong>Configurazione attuale:</strong>
        <code><?php echo esc_html($settings->getIssuer()); ?></code> &middot;
        Client: <code><?php echo esc_html($settings->get('client_id')); ?></code> &middot;
        Callback: <code><?php echo esc_html($settings->getCallbackUrl()); ?></code>
    </div>

    <p>
        <button type="button" id="ri-run-tests" class="button button-primary button-hero">
            Esegui test connessione
        </button>
    </p>

    <div id="ri-test-results" style="margin-top:20px;">
        <!-- Step 1: Discovery -->
        <div class="ri-test-step" data-step="discovery" style="display:none;">
            <h3>
                <span class="ri-test-icon">⏳</span>
                1. OpenID Discovery
            </h3>
            <div class="ri-test-body"></div>
        </div>

        <!-- Step 2: Token Endpoint -->
        <div class="ri-test-step" data-step="token_endpoint" style="display:none;">
            <h3>
                <span class="ri-test-icon">⏳</span>
                2. Token Endpoint & Credenziali Client
            </h3>
            <div class="ri-test-body"></div>
        </div>

        <!-- Step 3: UserInfo Endpoint -->
        <div class="ri-test-step" data-step="userinfo_endpoint" style="display:none;">
            <h3>
                <span class="ri-test-icon">⏳</span>
                3. UserInfo Endpoint
            </h3>
            <div class="ri-test-body"></div>
        </div>

        <!-- Step 4: Configurazione -->
        <div class="ri-test-step" data-step="full_flow" style="display:none;">
            <h3>
                <span class="ri-test-icon">⏳</span>
                4. Verifica Configurazione
            </h3>
            <div class="ri-test-body"></div>
        </div>
    </div>

    <div id="ri-test-summary" style="display:none;margin-top:24px;padding:16px;border-radius:4px;font-size:14px;"></div>
</div>

<style>
.ri-test-step {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin-bottom: 12px;
    padding: 0;
}
.ri-test-step h3 {
    margin: 0;
    padding: 12px 16px;
    border-bottom: 1px solid #eee;
    font-size: 14px;
    background: #fafafa;
}
.ri-test-step.success h3 { border-left: 4px solid #00a32a; }
.ri-test-step.error h3   { border-left: 4px solid #d63638; }
.ri-test-step.running h3 { border-left: 4px solid #2271b1; }

.ri-test-body {
    padding: 12px 16px;
    font-size: 13px;
    line-height: 1.6;
}
.ri-test-body table {
    border-collapse: collapse;
    width: 100%;
    margin-top: 8px;
}
.ri-test-body table td,
.ri-test-body table th {
    padding: 6px 10px;
    text-align: left;
    border-bottom: 1px solid #eee;
    font-size: 13px;
}
.ri-test-body table th {
    width: 200px;
    color: #666;
    font-weight: normal;
}
.ri-test-body .ri-fix {
    background: #fef7e0;
    border: 1px solid #f0c33c;
    padding: 8px 12px;
    border-radius: 3px;
    margin-top: 8px;
    font-size: 13px;
}
.ri-test-body .ri-mismatch {
    background: #fef0f0;
    padding: 6px 10px;
    border-radius: 3px;
    margin-top: 4px;
    font-size: 12px;
}
.ri-test-body pre {
    background: #f5f5f5;
    padding: 10px;
    border-radius: 3px;
    overflow-x: auto;
    font-size: 12px;
    max-height: 300px;
}
.ri-check-ok { color: #00a32a; }
.ri-check-fail { color: #d63638; }
</style>
