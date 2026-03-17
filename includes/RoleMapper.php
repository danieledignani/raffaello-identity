<?php

namespace RaffaelloIdentity;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gestione e mappatura dei ruoli Identity → WordPress.
 */
class RoleMapper {
    private Settings $settings;

    public function __construct(Settings $settings) {
        $this->settings = $settings;
    }

    public function init(): void {
        // Mostra il ruolo Identity nel profilo admin
        add_action('show_user_profile', [$this, 'showIdentityRoles']);
        add_action('edit_user_profile', [$this, 'showIdentityRoles']);
    }

    /**
     * Mostra i ruoli/claim Identity nella pagina profilo admin.
     */
    public function showIdentityRoles(\WP_User $user): void {
        $userinfo = get_user_meta($user->ID, 'ri_oidc_userinfo', true);
        if (empty($userinfo)) {
            return;
        }

        $sub = get_user_meta($user->ID, 'ri_oidc_sub', true);
        $roles = $userinfo['role'] ?? [];
        if (is_string($roles)) {
            $roles = [$roles];
        }
        $profilo = $userinfo['profilo'] ?? 'N/D';
        ?>
        <h3>Raffaello Identity</h3>
        <table class="form-table">
            <tr>
                <th>Subject ID</th>
                <td><code><?php echo esc_html($sub); ?></code></td>
            </tr>
            <tr>
                <th>Profilo</th>
                <td><?php echo esc_html($profilo); ?></td>
            </tr>
            <tr>
                <th>Ruoli Identity</th>
                <td><?php echo esc_html(implode(', ', $roles) ?: 'Nessuno'); ?></td>
            </tr>
            <?php foreach ($this->settings->getExtraClaims() as $claim) :
                $value = get_user_meta($user->ID, 'ri_claim_' . sanitize_key($claim), true);
                if ($value !== '') : ?>
            <tr>
                <th><?php echo esc_html($claim); ?></th>
                <td><?php echo esc_html($value); ?></td>
            </tr>
                <?php endif;
            endforeach; ?>
        </table>
        <?php
    }

    /**
     * Restituisce i ruoli Identity disponibili per la configurazione.
     */
    public static function getAvailableIdentityRoles(): array {
        return [
            'Studente'          => 'Studente',
            'Docente'           => 'Docente',
            'DocenteDiSostegno' => 'Docente di Sostegno',
            'Dirigente'         => 'Dirigente',
            'Altro'             => 'Altro',
        ];
    }

    /**
     * Restituisce i ruoli WordPress disponibili.
     */
    public static function getAvailableWpRoles(): array {
        $wp_roles = wp_roles()->get_names();
        return $wp_roles;
    }
}
