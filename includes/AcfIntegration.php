<?php

namespace RaffaelloIdentity;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Integrazione con ACF: registra un field group per mostrare i dati OIDC
 * nei profili utente. Se ACF non è installato, mostra un avviso admin
 * e il plugin usa il fallback nativo (tabella in RoleMapper).
 */
class AcfIntegration {
    private Settings $settings;

    public function __construct(Settings $settings) {
        $this->settings = $settings;
    }

    public function init(): void {
        if ($this->isAcfActive()) {
            add_action('acf/init', [$this, 'registerFieldGroup']);
            // Disabilita la modifica dei campi OIDC (sono in sola lettura)
            add_filter('acf/prepare_field/key=field_ri_oidc_sub', [$this, 'makeFieldReadonly']);
            add_filter('acf/prepare_field/key=field_ri_profilo', [$this, 'makeFieldReadonly']);
            add_filter('acf/prepare_field/key=field_ri_ruoli_identity', [$this, 'makeFieldReadonly']);
            add_filter('acf/prepare_field/key=field_ri_consenso_marketing', [$this, 'makeFieldReadonly']);
            add_filter('acf/prepare_field/key=field_ri_consenso_profilazione', [$this, 'makeFieldReadonly']);
            add_filter('acf/prepare_field/key=field_ri_consenso_terze_parti', [$this, 'makeFieldReadonly']);
            add_filter('acf/prepare_field/key=field_ri_joomla_sub', [$this, 'makeFieldReadonly']);
            add_filter('acf/prepare_field/key=field_ri_token_status', [$this, 'makeFieldReadonly']);
            add_filter('acf/prepare_field/key=field_ri_last_sync', [$this, 'makeFieldReadonly']);

            // Carica i valori dal meta esistente per i campi derivati
            add_filter('acf/load_value/key=field_ri_profilo', [$this, 'loadProfilo'], 10, 3);
            add_filter('acf/load_value/key=field_ri_ruoli_identity', [$this, 'loadRuoli'], 10, 3);
            add_filter('acf/load_value/key=field_ri_token_status', [$this, 'loadTokenStatus'], 10, 3);
            add_filter('acf/load_value/key=field_ri_last_sync', [$this, 'loadLastSync'], 10, 3);
        } else {
            // Mostra avviso admin solo nella pagina utenti o impostazioni plugin
            add_action('admin_notices', [$this, 'showAcfNotice']);
        }
    }

    /**
     * Verifica se ACF (free o PRO) è attivo.
     */
    public function isAcfActive(): bool {
        return class_exists('ACF') || function_exists('acf_add_local_field_group');
    }

    /**
     * Registra il field group ACF per i profili utente OIDC.
     */
    public function registerFieldGroup(): void {
        if (!function_exists('acf_add_local_field_group')) {
            return;
        }

        $extra_claims = $this->settings->getExtraClaims();

        $fields = [
            [
                'key'   => 'field_ri_oidc_sub',
                'label' => 'Subject ID',
                'name'  => 'ri_oidc_sub',
                'type'  => 'text',
                'instructions' => 'Identificativo univoco dell\'utente sul server Identity.',
            ],
            [
                'key'   => 'field_ri_profilo',
                'label' => 'Profilo',
                'name'  => '_ri_profilo_display',
                'type'  => 'text',
                'instructions' => 'Tipo di profilo dal server Identity (Studente, Docente, ecc.).',
            ],
            [
                'key'   => 'field_ri_ruoli_identity',
                'label' => 'Ruoli Identity',
                'name'  => '_ri_ruoli_display',
                'type'  => 'text',
                'instructions' => 'Ruoli assegnati sul server Identity.',
            ],
        ];

        // Campi extra claims configurati
        if (in_array('consensoMarketing', $extra_claims, true)) {
            $fields[] = [
                'key'   => 'field_ri_consenso_marketing',
                'label' => 'Consenso Marketing',
                'name'  => 'ri_claim_consensomarketing',
                'type'  => 'text',
            ];
        }

        if (in_array('consensoProfilazione', $extra_claims, true)) {
            $fields[] = [
                'key'   => 'field_ri_consenso_profilazione',
                'label' => 'Consenso Profilazione',
                'name'  => 'ri_claim_consensoprofilazione',
                'type'  => 'text',
            ];
        }

        if (in_array('consensoTerzeParti', $extra_claims, true)) {
            $fields[] = [
                'key'   => 'field_ri_consenso_terze_parti',
                'label' => 'Consenso Terze Parti',
                'name'  => 'ri_claim_consensoterzeparti',
                'type'  => 'text',
            ];
        }

        if (in_array('joomla_sub', $extra_claims, true)) {
            $fields[] = [
                'key'   => 'field_ri_joomla_sub',
                'label' => 'Joomla Subscription',
                'name'  => 'ri_claim_joomla_sub',
                'type'  => 'text',
            ];
        }

        // Aggiungi eventuali altri extra claims non gestiti sopra
        $known_claims = ['profilo', 'consensoMarketing', 'consensoProfilazione', 'consensoTerzeParti', 'joomla_sub'];
        foreach ($extra_claims as $claim) {
            if (!in_array($claim, $known_claims, true)) {
                $fields[] = [
                    'key'   => 'field_ri_claim_' . sanitize_key($claim),
                    'label' => ucfirst($claim),
                    'name'  => 'ri_claim_' . sanitize_key($claim),
                    'type'  => 'text',
                ];
                // Rendi readonly anche i campi dinamici
                add_filter('acf/prepare_field/key=field_ri_claim_' . sanitize_key($claim), [$this, 'makeFieldReadonly']);
            }
        }

        // Campi di stato
        $fields[] = [
            'key'   => 'field_ri_token_status',
            'label' => 'Stato Token',
            'name'  => '_ri_token_status',
            'type'  => 'text',
            'instructions' => 'Stato di validità dell\'access token OIDC.',
        ];

        $fields[] = [
            'key'   => 'field_ri_last_sync',
            'label' => 'Ultimo Sync',
            'name'  => '_ri_last_sync',
            'type'  => 'text',
            'instructions' => 'Ultimo accesso tramite Identity.',
        ];

        acf_add_local_field_group([
            'key'      => 'group_ri_identity',
            'title'    => 'Raffaello Identity',
            'fields'   => $fields,
            'location' => [
                [
                    [
                        'param'    => 'user_form',
                        'operator' => '==',
                        'value'    => 'all',
                    ],
                ],
            ],
            'position'    => 'normal',
            'style'       => 'default',
            'label_placement' => 'left',
            'active'      => true,
        ]);
    }

    /**
     * Rende un campo ACF in sola lettura (non modificabile dall'admin).
     */
    public function makeFieldReadonly($field) {
        if (!$field) {
            return $field;
        }

        // Nascondi il campo se l'utente non è collegato a Identity
        $user_id = $this->getCurrentProfileUserId();
        if ($user_id) {
            $sub = get_user_meta($user_id, 'ri_oidc_sub', true);
            if (empty($sub)) {
                return false; // Nasconde il campo
            }
        }

        $field['readonly'] = 1;
        $field['disabled'] = 1;
        return $field;
    }

    /**
     * Carica il profilo dall'userinfo salvato.
     */
    public function loadProfilo($value, $post_id, $field) {
        $user_id = $this->extractUserId($post_id);
        if (!$user_id) {
            return $value;
        }
        $userinfo = get_user_meta($user_id, 'ri_oidc_userinfo', true);
        return !empty($userinfo['profilo']) ? $userinfo['profilo'] : 'N/D';
    }

    /**
     * Carica i ruoli Identity dall'userinfo salvato.
     */
    public function loadRuoli($value, $post_id, $field) {
        $user_id = $this->extractUserId($post_id);
        if (!$user_id) {
            return $value;
        }
        $userinfo = get_user_meta($user_id, 'ri_oidc_userinfo', true);
        $roles = $userinfo['role'] ?? [];
        if (is_string($roles)) {
            $roles = [$roles];
        }
        return !empty($roles) ? implode(', ', $roles) : 'Nessuno';
    }

    /**
     * Carica lo stato del token.
     */
    public function loadTokenStatus($value, $post_id, $field) {
        $user_id = $this->extractUserId($post_id);
        if (!$user_id) {
            return $value;
        }
        $expires_at = (int) get_user_meta($user_id, 'ri_token_expires_at', true);
        if (empty($expires_at)) {
            return 'Nessun token';
        }
        if (time() < $expires_at) {
            $remaining = human_time_diff(time(), $expires_at);
            return "Valido (scade tra $remaining)";
        }
        return 'Scaduto';
    }

    /**
     * Carica il timestamp dell'ultimo sync (dall'ultimo login debug o dalla data del token).
     */
    public function loadLastSync($value, $post_id, $field) {
        $user_id = $this->extractUserId($post_id);
        if (!$user_id) {
            return $value;
        }

        // Prova dal debug userinfo (ha il timestamp)
        $debug = get_user_meta($user_id, 'ri_debug_userinfo', true);
        if (!empty($debug['timestamp'])) {
            return $debug['timestamp'];
        }

        // Fallback: data scadenza token meno la durata (approssimativo)
        $expires_at = (int) get_user_meta($user_id, 'ri_token_expires_at', true);
        if (!empty($expires_at)) {
            return wp_date('Y-m-d H:i:s', $expires_at - 3600); // Approssimazione (1h token)
        }

        return 'N/D';
    }

    /**
     * Mostra un avviso admin se ACF non è installato.
     */
    public function showAcfNotice(): void {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        // Mostra solo nelle pagine utenti o nella pagina del plugin
        $relevant_screens = ['users', 'user-edit', 'profile', 'settings_page_raffaello-identity'];
        if (!in_array($screen->id, $relevant_screens, true)) {
            return;
        }

        // Non mostrare se l'utente ha già chiuso l'avviso
        if (get_user_meta(get_current_user_id(), 'ri_acf_notice_dismissed', true)) {
            return;
        }

        ?>
        <div class="notice notice-info is-dismissible" data-ri-notice="acf">
            <p>
                <strong>Raffaello Identity:</strong>
                Per una migliore visualizzazione dei dati OIDC nei profili utente, installa
                <a href="<?php echo esc_url(admin_url('plugin-install.php?s=Advanced+Custom+Fields&tab=search&type=term')); ?>">Advanced Custom Fields (ACF)</a>.
                I campi Identity (Subject ID, Profilo, Ruoli, Consensi) verranno configurati automaticamente.
            </p>
        </div>
        <script>
        jQuery(function($) {
            $(document).on('click', '[data-ri-notice="acf"] .notice-dismiss', function() {
                $.post(ajaxurl, { action: 'ri_dismiss_acf_notice', _wpnonce: '<?php echo wp_create_nonce('ri_dismiss_acf_notice'); ?>' });
            });
        });
        </script>
        <?php
    }

    /**
     * Estrae l'user ID dal $post_id di ACF (formato "user_123").
     */
    private function extractUserId($post_id): int {
        if (is_string($post_id) && strpos($post_id, 'user_') === 0) {
            return (int) str_replace('user_', '', $post_id);
        }
        return 0;
    }

    /**
     * Ottieni lo user ID del profilo attualmente visualizzato.
     */
    private function getCurrentProfileUserId(): int {
        if (defined('IS_PROFILE_PAGE') && IS_PROFILE_PAGE) {
            return get_current_user_id();
        }
        // Nella pagina edit utente
        return isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
    }
}
