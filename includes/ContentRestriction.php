<?php

namespace RaffaelloIdentity;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Restrizione dei contenuti in base al ruolo utente.
 * Supporta ACF (campo "Ruoli richiesti" su pagine/post) con fallback meta box nativo.
 * Include lo shortcode [ri_restrict].
 */
class ContentRestriction {
    private Settings $settings;

    public function __construct(Settings $settings) {
        $this->settings = $settings;
    }

    public function init(): void {
        // Enforcement: blocca pagine ristrette su template_redirect
        add_action('template_redirect', [$this, 'enforceRestriction']);

        // Shortcode [ri_restrict]
        add_shortcode('ri_restrict', [$this, 'renderRestrictShortcode']);

        if ($this->isAcfActive()) {
            // ACF gestisce il campo "Ruoli richiesti" su pagine/post
            add_action('acf/init', [$this, 'registerContentFieldGroup']);
        } else {
            // Fallback: meta box nativo
            add_action('add_meta_boxes', [$this, 'addRestrictionMetaBox']);
            add_action('save_post', [$this, 'saveRestrictionMetaBox']);
        }
    }

    // =========================================================================
    // ACF Field Group per pagine/post
    // =========================================================================

    /**
     * Registra il field group ACF per la restrizione contenuti.
     */
    public function registerContentFieldGroup(): void {
        if (!function_exists('acf_add_local_field_group')) {
            return;
        }

        $roles = $this->getAvailableRolesChoices();

        acf_add_local_field_group([
            'key'      => 'group_ri_content_restriction',
            'title'    => 'Raffaello Identity — Accesso',
            'fields'   => [
                [
                    'key'          => 'field_ri_required_roles',
                    'label'        => 'Ruoli richiesti',
                    'name'         => 'ri_required_roles',
                    'type'         => 'checkbox',
                    'instructions' => 'Seleziona i ruoli che possono visualizzare questa pagina. Se nessuno è selezionato, la pagina è pubblica.',
                    'choices'      => $roles,
                    'layout'       => 'horizontal',
                    'return_format' => 'value',
                ],
                [
                    'key'          => 'field_ri_restriction_action',
                    'label'        => 'Azione per utenti non autorizzati',
                    'name'         => 'ri_restriction_action',
                    'type'         => 'select',
                    'instructions' => 'Cosa fare quando un utente non ha il ruolo richiesto.',
                    'choices'      => [
                        'login'    => 'Redirect al login Identity',
                        'home'     => 'Redirect alla home page',
                        'message'  => 'Mostra messaggio di accesso negato',
                    ],
                    'default_value' => 'login',
                ],
                [
                    'key'          => 'field_ri_restriction_message',
                    'label'        => 'Messaggio personalizzato',
                    'name'         => 'ri_restriction_message',
                    'type'         => 'textarea',
                    'instructions' => 'Messaggio mostrato quando l\'azione è "Mostra messaggio". Lascia vuoto per il messaggio predefinito.',
                    'rows'         => 3,
                    'conditional_logic' => [
                        [
                            [
                                'field'    => 'field_ri_restriction_action',
                                'operator' => '==',
                                'value'    => 'message',
                            ],
                        ],
                    ],
                ],
            ],
            'location' => [
                [
                    [
                        'param'    => 'post_type',
                        'operator' => '==',
                        'value'    => 'page',
                    ],
                ],
                [
                    [
                        'param'    => 'post_type',
                        'operator' => '==',
                        'value'    => 'post',
                    ],
                ],
            ],
            'position'    => 'side',
            'style'       => 'default',
            'active'      => true,
        ]);
    }

    // =========================================================================
    // Fallback: Meta Box nativo (senza ACF)
    // =========================================================================

    /**
     * Aggiunge meta box nativo per la restrizione contenuti.
     */
    public function addRestrictionMetaBox(): void {
        $post_types = ['page', 'post'];
        foreach ($post_types as $post_type) {
            add_meta_box(
                'ri_content_restriction',
                'Raffaello Identity — Accesso',
                [$this, 'renderRestrictionMetaBox'],
                $post_type,
                'side'
            );
        }
    }

    /**
     * Renderizza il meta box nativo.
     */
    public function renderRestrictionMetaBox(\WP_Post $post): void {
        $saved_roles = get_post_meta($post->ID, 'ri_required_roles', true);
        if (!is_array($saved_roles)) {
            $saved_roles = [];
        }

        $action = get_post_meta($post->ID, 'ri_restriction_action', true) ?: 'login';
        $message = get_post_meta($post->ID, 'ri_restriction_message', true) ?: '';

        $roles = $this->getAvailableRolesChoices();

        wp_nonce_field('ri_restriction_meta', 'ri_restriction_nonce');
        ?>
        <p><strong>Ruoli richiesti:</strong></p>
        <p class="description">Se nessuno è selezionato, la pagina è pubblica.</p>
        <?php foreach ($roles as $value => $label) : ?>
            <label style="display:block;margin:4px 0;">
                <input type="checkbox" name="ri_required_roles[]" value="<?php echo esc_attr($value); ?>"
                    <?php checked(in_array($value, $saved_roles, true)); ?>>
                <?php echo esc_html($label); ?>
            </label>
        <?php endforeach; ?>

        <p style="margin-top:12px;"><strong>Azione:</strong></p>
        <select name="ri_restriction_action" style="width:100%;">
            <option value="login" <?php selected($action, 'login'); ?>>Redirect al login</option>
            <option value="home" <?php selected($action, 'home'); ?>>Redirect alla home</option>
            <option value="message" <?php selected($action, 'message'); ?>>Mostra messaggio</option>
        </select>

        <p style="margin-top:8px;"><strong>Messaggio personalizzato:</strong></p>
        <textarea name="ri_restriction_message" rows="3" style="width:100%;"><?php echo esc_textarea($message); ?></textarea>
        <?php
    }

    /**
     * Salva i dati del meta box.
     */
    public function saveRestrictionMetaBox(int $post_id): void {
        if (!isset($_POST['ri_restriction_nonce']) || !wp_verify_nonce($_POST['ri_restriction_nonce'], 'ri_restriction_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $roles = isset($_POST['ri_required_roles']) && is_array($_POST['ri_required_roles'])
            ? array_map('sanitize_text_field', $_POST['ri_required_roles'])
            : [];

        $action = sanitize_text_field($_POST['ri_restriction_action'] ?? 'login');
        $message = sanitize_textarea_field($_POST['ri_restriction_message'] ?? '');

        update_post_meta($post_id, 'ri_required_roles', $roles);
        update_post_meta($post_id, 'ri_restriction_action', $action);
        update_post_meta($post_id, 'ri_restriction_message', $message);
    }

    // =========================================================================
    // Enforcement
    // =========================================================================

    /**
     * Verifica l'accesso alla pagina/post corrente in base ai ruoli richiesti.
     */
    public function enforceRestriction(): void {
        if (is_admin() || !is_singular()) {
            return;
        }

        $post_id = get_queried_object_id();
        if (empty($post_id)) {
            return;
        }

        $required_roles = $this->getRequiredRoles($post_id);
        if (empty($required_roles)) {
            return; // Pagina pubblica
        }

        // Admin può sempre vedere tutto
        if (current_user_can('manage_options')) {
            return;
        }

        // Utente non loggato → azione predefinita
        if (!is_user_logged_in()) {
            $this->handleUnauthorized($post_id);
            return;
        }

        // Verifica se l'utente ha almeno uno dei ruoli richiesti
        $user = wp_get_current_user();
        $has_role = !empty(array_intersect($user->roles, $required_roles));

        if (!$has_role) {
            $this->handleUnauthorized($post_id);
        }
    }

    /**
     * Gestisce l'accesso non autorizzato.
     */
    private function handleUnauthorized(int $post_id): void {
        $action = get_post_meta($post_id, 'ri_restriction_action', true) ?: 'login';

        switch ($action) {
            case 'login':
                $oidc = new OidcClient($this->settings);
                wp_redirect($oidc->getAuthorizationUrl());
                exit;

            case 'home':
                wp_redirect(home_url('/'));
                exit;

            case 'message':
            default:
                $message = get_post_meta($post_id, 'ri_restriction_message', true);
                if (empty($message)) {
                    $message = 'Non hai i permessi necessari per visualizzare questa pagina.';
                }
                wp_die(
                    esc_html($message),
                    'Accesso negato',
                    ['response' => 403, 'back_link' => true]
                );
        }
    }

    /**
     * Recupera i ruoli richiesti per un post/pagina (compatibile ACF e meta nativo).
     */
    private function getRequiredRoles(int $post_id): array {
        $roles = get_post_meta($post_id, 'ri_required_roles', true);

        if (is_string($roles) && !empty($roles)) {
            // ACF serializza come stringa singola quando c'è un solo valore
            $roles = [$roles];
        }

        if (!is_array($roles)) {
            return [];
        }

        return array_filter($roles);
    }

    // =========================================================================
    // Shortcode [ri_restrict]
    // =========================================================================

    /**
     * Shortcode [ri_restrict role="docente,dirigente" logged_in="true"]contenuto[/ri_restrict]
     *
     * Attributi:
     * - role: lista ruoli separati da virgola (l'utente deve averne almeno uno)
     * - logged_in: "true" per richiedere solo il login (qualsiasi ruolo)
     * - message: messaggio alternativo per utenti non autorizzati (vuoto = nulla)
     */
    public function renderRestrictShortcode($atts, $content = null): string {
        $atts = shortcode_atts([
            'role'      => '',
            'logged_in' => '',
            'message'   => '',
        ], $atts);

        // Verifica login
        if (!empty($atts['logged_in']) && $atts['logged_in'] === 'true') {
            if (!is_user_logged_in()) {
                return $this->restrictedMessage($atts['message']);
            }
        }

        // Verifica ruoli
        if (!empty($atts['role'])) {
            if (!is_user_logged_in()) {
                return $this->restrictedMessage($atts['message']);
            }

            $required = array_map('trim', explode(',', $atts['role']));
            $user = wp_get_current_user();

            if (empty(array_intersect($user->roles, $required))) {
                return $this->restrictedMessage($atts['message']);
            }
        }

        return do_shortcode($content);
    }

    /**
     * Messaggio per contenuto ristretto (vuoto se nessun messaggio specificato).
     */
    private function restrictedMessage(string $message): string {
        if (empty($message)) {
            return '';
        }

        return sprintf(
            '<div class="ri-restricted-content"><p>%s</p></div>',
            esc_html($message)
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function isAcfActive(): bool {
        return class_exists('ACF') || function_exists('acf_add_local_field_group');
    }

    /**
     * Restituisce le scelte dei ruoli disponibili per il selettore.
     */
    private function getAvailableRolesChoices(): array {
        $wp_roles = wp_roles()->get_names();
        $choices = [];

        // Metti in cima i ruoli Identity custom
        $identity_first = ['studente', 'docente', 'docente_sostegno', 'dirigente', 'customer'];
        foreach ($identity_first as $slug) {
            if (isset($wp_roles[$slug])) {
                $choices[$slug] = $wp_roles[$slug];
            }
        }

        // Poi tutti gli altri (escluso administrator)
        foreach ($wp_roles as $slug => $name) {
            if ($slug !== 'administrator' && !isset($choices[$slug])) {
                $choices[$slug] = $name;
            }
        }

        return $choices;
    }
}
