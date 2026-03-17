<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $opts */
/** @var array $identity_roles */
/** @var array $wp_roles */
?>
<?php
// Mappa costanti PHP → chiavi opzioni (per mostrare avvisi nella UI)
$ri_constants = [
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

/**
 * Restituisce true se il campo è sovrascitto da una costante PHP.
 */
function ri_is_locked(string $option_key, array $ri_constants): bool {
    $const = array_search($option_key, $ri_constants, true);
    return $const !== false && defined($const);
}

function ri_locked_notice(string $option_key, array $ri_constants): void {
    $const = array_search($option_key, $ri_constants, true);
    if ($const !== false && defined($const)) {
        printf(
            '<span class="description" style="color:#d63638;margin-left:8px;">⛔ Sovrascitto dalla costante <code>%s</code> in wp-config.php</span>',
            esc_html($const)
        );
    }
}
?>
<div class="wrap ri-admin-wrap">
    <h1>Raffaello Identity — Impostazioni</h1>

    <?php settings_errors('ri_settings'); ?>

    <form method="post" action="">
        <?php wp_nonce_field('ri_save_settings', 'ri_nonce'); ?>
        <input type="hidden" name="ri_save_settings" value="1">

        <!-- Connessione OIDC -->
        <div class="ri-section">
            <h2>Connessione OIDC</h2>
            <table class="form-table">
                <tr>
                    <th><label for="ri_issuer">Issuer URL</label></th>
                    <td>
                        <input type="url" id="ri_issuer" name="ri_issuer" class="regular-text"
                               value="<?php echo esc_attr($opts['issuer']); ?>"
                               placeholder="https://account.raffaellolibri.it"
                               <?php echo ri_is_locked('issuer', $ri_constants) ? 'readonly' : ''; ?>>
                        <?php ri_locked_notice('issuer', $ri_constants); ?>
                        <p class="description">URL base del server Identity (es. https://account.raffaellolibri.it)</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="ri_client_id">Client ID</label></th>
                    <td>
                        <input type="text" id="ri_client_id" name="ri_client_id" class="regular-text"
                               value="<?php echo esc_attr($opts['client_id']); ?>"
                               <?php echo ri_is_locked('client_id', $ri_constants) ? 'readonly' : ''; ?>>
                        <?php ri_locked_notice('client_id', $ri_constants); ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="ri_client_secret">Client Secret</label></th>
                    <td>
                        <input type="password" id="ri_client_secret" name="ri_client_secret" class="regular-text"
                               value="<?php echo esc_attr($opts['client_secret']); ?>"
                               <?php echo ri_is_locked('client_secret', $ri_constants) ? 'readonly' : ''; ?>>
                        <?php ri_locked_notice('client_secret', $ri_constants); ?>
                        <p class="description">Secret del client confidenziale registrato su Identity.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="ri_scopes">Scope richiesti</label></th>
                    <td>
                        <input type="text" id="ri_scopes" name="ri_scopes" class="large-text"
                               value="<?php echo esc_attr($opts['scopes']); ?>"
                               <?php echo ri_is_locked('scopes', $ri_constants) ? 'readonly' : ''; ?>>
                        <?php ri_locked_notice('scopes', $ri_constants); ?>
                        <p class="description">
                            Separati da spazio. Disponibili: <code>openid</code> <code>email</code>
                            <code>profile</code> <code>offline_access</code> <code>roles</code>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>Redirect URI (callback)</th>
                    <td>
                        <code><?php echo esc_html(admin_url('admin-ajax.php?action=openid-connect-authorize')); ?></code>
                        <p class="description">Configurare questo URL come redirect_uri nel client Identity.</p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Redirect -->
        <div class="ri-section">
            <h2>Redirect</h2>
            <table class="form-table">
                <tr>
                    <th><label for="ri_login_redirect">Dopo il login</label></th>
                    <td>
                        <input type="url" id="ri_login_redirect" name="ri_login_redirect" class="regular-text"
                               value="<?php echo esc_attr($opts['login_redirect']); ?>">
                    </td>
                </tr>
                <tr>
                    <th><label for="ri_logout_redirect">Dopo il logout</label></th>
                    <td>
                        <input type="url" id="ri_logout_redirect" name="ri_logout_redirect" class="regular-text"
                               value="<?php echo esc_attr($opts['logout_redirect']); ?>">
                    </td>
                </tr>
            </table>
        </div>

        <!-- Registrazione e Login -->
        <div class="ri-section">
            <h2>Registrazione e Login</h2>
            <table class="form-table">
                <tr>
                    <th>Auto-registrazione</th>
                    <td>
                        <label>
                            <input type="checkbox" name="ri_auto_register" value="1"
                                <?php checked($opts['auto_register']); ?>>
                            Crea automaticamente gli utenti WordPress al primo login OIDC
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label for="ri_login_button_text">Testo pulsante login</label></th>
                    <td>
                        <input type="text" id="ri_login_button_text" name="ri_login_button_text" class="regular-text"
                               value="<?php echo esc_attr($opts['login_button_text']); ?>">
                    </td>
                </tr>
                <tr>
                    <th>Override login WP</th>
                    <td>
                        <label>
                            <input type="checkbox" name="ri_override_wp_login" value="1"
                                <?php checked($opts['override_wp_login'] ?? false); ?>>
                            Sostituisci wp-login.php con redirect diretto a Identity
                        </label>
                        <p class="description">Se attivo, qualsiasi accesso a wp-login.php viene reindirizzato al server Identity.</p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- WooCommerce -->
        <div class="ri-section">
            <h2>WooCommerce</h2>
            <table class="form-table">
                <tr>
                    <th>Sostituisci login WooCommerce</th>
                    <td>
                        <label>
                            <input type="checkbox" name="ri_wc_override_login" value="1"
                                <?php checked($opts['wc_override_login'] ?? true); ?>>
                            Nasconde i form login/registrazione di WooCommerce e reindirizza a Identity
                        </label>
                        <p class="description">
                            La pagina "Il mio account" di WooCommerce (quando non loggati) farà redirect
                            diretto al login Identity. Il form di registrazione WooCommerce viene disabilitato.
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Menu Navigazione -->
        <div class="ri-section">
            <h2>Menu Navigazione</h2>
            <p class="description">Aggiunge automaticamente un elemento utente (nome + dropdown) o un link "Accedi" a un menu di navigazione.</p>
            <table class="form-table">
                <tr>
                    <th><label for="ri_nav_menu_location">Menu location</label></th>
                    <td>
                        <select id="ri_nav_menu_location" name="ri_nav_menu_location">
                            <option value="">— Disabilitato —</option>
                            <?php
                            $locations = get_registered_nav_menus();
                            foreach ($locations as $slug => $label) : ?>
                                <option value="<?php echo esc_attr($slug); ?>"
                                    <?php selected($opts['nav_menu_location'] ?? '', $slug); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            Seleziona la posizione menu dove aggiungere il nome utente/link login.<br>
                            In alternativa, usa lo shortcode <code>[ri_user_menu]</code> in un widget.
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Mappatura Ruoli -->
        <div class="ri-section">
            <h2>Mappatura Ruoli</h2>
            <p class="description">Associa i ruoli/profili di Identity ai ruoli di WordPress.</p>
            <table class="widefat ri-mapping-table" id="ri-role-mapping">
                <thead>
                    <tr>
                        <th>Ruolo/Profilo Identity</th>
                        <th>Ruolo WordPress</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $role_mapping = $opts['role_mapping'] ?? [];
                    $index = 0;
                    foreach ($role_mapping as $identity_role => $wp_role) : ?>
                        <tr>
                            <td>
                                <select name="ri_role_map_identity[<?php echo $index; ?>]">
                                    <option value="">— Seleziona —</option>
                                    <?php foreach ($identity_roles as $key => $label) : ?>
                                        <option value="<?php echo esc_attr($key); ?>"
                                            <?php selected($identity_role, $key); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="<?php echo esc_attr($identity_role); ?>"
                                        <?php if (!isset($identity_roles[$identity_role])) : ?>selected<?php endif; ?>>
                                        <?php if (!isset($identity_roles[$identity_role])) echo esc_html($identity_role); ?>
                                    </option>
                                </select>
                            </td>
                            <td>
                                <select name="ri_role_map_wp[<?php echo $index; ?>]">
                                    <?php foreach ($wp_roles as $slug => $label) : ?>
                                        <option value="<?php echo esc_attr($slug); ?>"
                                            <?php selected($wp_role, $slug); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <button type="button" class="button ri-remove-row">&times;</button>
                            </td>
                        </tr>
                    <?php
                        $index++;
                    endforeach; ?>
                </tbody>
            </table>
            <p><button type="button" class="button" id="ri-add-role-row">+ Aggiungi mappatura</button></p>
        </div>

        <!-- Mappatura Claim -->
        <div class="ri-section">
            <h2>Mappatura Claim</h2>
            <p class="description">Associa i claim OIDC ai campi utente WordPress.</p>
            <table class="widefat ri-mapping-table" id="ri-claim-mapping">
                <thead>
                    <tr>
                        <th>Claim OIDC</th>
                        <th>Campo WordPress</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $claim_mapping = $opts['claim_mapping'] ?? [];
                    $index = 0;
                    foreach ($claim_mapping as $oidc_claim => $wp_field) : ?>
                        <tr>
                            <td>
                                <input type="text" name="ri_claim_oidc[<?php echo $index; ?>]"
                                       value="<?php echo esc_attr($oidc_claim); ?>" class="regular-text"
                                       placeholder="es. nome, cognome, email">
                            </td>
                            <td>
                                <input type="text" name="ri_claim_wp[<?php echo $index; ?>]"
                                       value="<?php echo esc_attr($wp_field); ?>" class="regular-text"
                                       placeholder="es. first_name, last_name, user_email">
                            </td>
                            <td>
                                <button type="button" class="button ri-remove-row">&times;</button>
                            </td>
                        </tr>
                    <?php
                        $index++;
                    endforeach; ?>
                </tbody>
            </table>
            <p><button type="button" class="button" id="ri-add-claim-row">+ Aggiungi claim</button></p>
        </div>

        <!-- Claim Extra -->
        <div class="ri-section">
            <h2>Claim Extra</h2>
            <table class="form-table">
                <tr>
                    <th><label for="ri_extra_claims">Claim aggiuntivi da salvare</label></th>
                    <td>
                        <input type="text" id="ri_extra_claims" name="ri_extra_claims" class="large-text"
                               value="<?php echo esc_attr($opts['extra_claims']); ?>">
                        <p class="description">
                            Separati da virgola. Questi claim verranno salvati come user_meta
                            (<code>ri_claim_*</code>) e mostrati nel profilo.<br>
                            Disponibili: <code>consensoMarketing</code>, <code>consensoProfilazione</code>,
                            <code>consensoTerzeParti</code>, <code>joomla_sub</code>, <code>profilo</code>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <?php submit_button('Salva impostazioni'); ?>
    </form>

    <div class="ri-section">
        <h2>Costanti PHP</h2>
        <p class="description">
            Puoi sovrascrivere le impostazioni definendo costanti in <code>wp-config.php</code>.
            Utile per deploy automatizzati dove i secrets non vanno salvati nel database.
        </p>
        <table class="widefat" style="max-width:600px;">
            <thead>
                <tr><th>Costante</th><th>Opzione</th><th>Stato</th></tr>
            </thead>
            <tbody>
                <?php foreach ($ri_constants as $const => $key) : ?>
                <tr>
                    <td><code><?php echo esc_html($const); ?></code></td>
                    <td><?php echo esc_html($key); ?></td>
                    <td><?php echo defined($const) ? '<span style="color:#00a32a;">✓ Attiva</span>' : '<span style="color:#999;">—</span>'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="ri-section">
        <h2>Hook disponibili per sviluppatori</h2>
        <p class="description">Filtri e action esposti dal plugin per estensioni personalizzate.</p>
        <table class="widefat" style="max-width:700px;">
            <thead>
                <tr><th>Tipo</th><th>Nome</th><th>Descrizione</th></tr>
            </thead>
            <tbody>
                <tr><td>Filter</td><td><code>ri_alter_request</code></td><td>Modifica le richieste HTTP verso il server Identity</td></tr>
                <tr><td>Filter</td><td><code>ri_alter_user_claim</code></td><td>Modifica i claim utente prima della sincronizzazione</td></tr>
                <tr><td>Filter</td><td><code>ri_user_login_test</code></td><td>Blocca il login in base ai claim</td></tr>
                <tr><td>Filter</td><td><code>ri_user_creation_test</code></td><td>Blocca la creazione utente in base ai claim</td></tr>
                <tr><td>Filter</td><td><code>ri_alter_user_data</code></td><td>Modifica i dati WP prima della creazione utente</td></tr>
                <tr><td>Filter</td><td><code>ri_login_redirect</code></td><td>Modifica l'URL di redirect post-login</td></tr>
                <tr><td>Filter</td><td><code>ri_session_expired_redirect</code></td><td>URL redirect dopo logout per sessione scaduta</td></tr>
                <tr><td>Action</td><td><code>ri_user_synced</code></td><td>Dopo creazione/aggiornamento utente</td></tr>
                <tr><td>Action</td><td><code>ri_user_logged_in</code></td><td>Dopo login completato con successo</td></tr>
                <tr><td>Action</td><td><code>ri_token_refreshed</code></td><td>Dopo un refresh token riuscito</td></tr>
                <tr><td>Action</td><td><code>ri_session_expired</code></td><td>Quando la sessione OIDC è scaduta</td></tr>
            </tbody>
        </table>
    </div>

    <div class="ri-section">
        <h2>Diagnostica</h2>
        <p>
            <a href="<?php echo esc_url(admin_url('tools.php?page=raffaello-identity-test')); ?>" class="button button-primary">
                Test Connessione
            </a>
            <a href="<?php echo esc_url(admin_url('tools.php?page=raffaello-identity-logs')); ?>" class="button" style="margin-left:8px;">
                Visualizza Log
            </a>
            <span class="description" style="margin-left:8px;">
                Verifica la connettività con il server Identity e consulta i log OIDC.
            </span>
        </p>
    </div>
</div>

<script>
(function() {
    // Aggiungi riga mappatura ruoli
    document.getElementById('ri-add-role-row').addEventListener('click', function() {
        const tbody = document.querySelector('#ri-role-mapping tbody');
        const index = tbody.rows.length;
        const row = tbody.insertRow();
        row.innerHTML = `
            <td>
                <input type="text" name="ri_role_map_identity[${index}]" class="regular-text"
                       placeholder="Nome ruolo Identity">
            </td>
            <td>
                <select name="ri_role_map_wp[${index}]">
                    <?php foreach ($wp_roles as $slug => $label) : ?>
                    <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><button type="button" class="button ri-remove-row">&times;</button></td>
        `;
    });

    // Aggiungi riga mappatura claim
    document.getElementById('ri-add-claim-row').addEventListener('click', function() {
        const tbody = document.querySelector('#ri-claim-mapping tbody');
        const index = tbody.rows.length;
        const row = tbody.insertRow();
        row.innerHTML = `
            <td><input type="text" name="ri_claim_oidc[${index}]" class="regular-text" placeholder="Claim OIDC"></td>
            <td><input type="text" name="ri_claim_wp[${index}]" class="regular-text" placeholder="Campo WP"></td>
            <td><button type="button" class="button ri-remove-row">&times;</button></td>
        `;
    });

    // Rimuovi riga
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('ri-remove-row')) {
            e.target.closest('tr').remove();
        }
    });
})();
</script>
