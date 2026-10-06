<?php

namespace RaffaelloIdentity;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Receives the DeletedUtente webhook of Identity: POST {"Id", "Email"}, key in the Authorization header.
 */
class DeletionWebhook {
    private OidcClient $oidc;

    public function __construct(OidcClient $oidc) {
        $this->oidc = $oidc;
    }

    public function init(): void {
        add_action('rest_api_init', [$this, 'registerRoute']);
    }

    public static function getUrl(): string {
        return rest_url('raffaello-identity/v1/utente-eliminato');
    }

    public function registerRoute(): void {
        register_rest_route('raffaello-identity/v1', '/utente-eliminato', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle'],
            'permission_callback' => [$this, 'authorize'],
        ]);
    }

    // An empty key would let through a request without the header.
    public static function isConfigured(): bool {
        return defined('RI_WEBHOOK_KEY') && RI_WEBHOOK_KEY !== '';
    }

    public function authorize(\WP_REST_Request $request): bool {
        return self::isConfigured() && hash_equals(RI_WEBHOOK_KEY, (string) $request->get_header('authorization'));
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response {
        $sub = (string) ($request->get_json_params()['Id'] ?? '');

        // An empty meta_value would match every user linked to Identity.
        if ($sub === '') {
            return new \WP_REST_Response(['error' => 'Id mancante'], 400);
        }

        // Identity resends the event until every webhook answers: an unknown id gets a 200 too.
        $users = get_users([
            'meta_key'   => 'ri_oidc_sub',
            'meta_value' => $sub,
        ]);

        require_once ABSPATH . 'wp-admin/includes/user.php';

        foreach ($users as $user) {
            // Staff keep their account and the content they wrote.
            if (user_can($user, 'edit_posts')) {
                $this->oidc->unlink($user->ID);
                Logger::info('webhook_user_unlinked', "Utente #{$user->ID} scollegato: account cancellato su Identity", [
                    'user_id' => $user->ID,
                    'sub'     => $sub,
                ]);
                continue;
            }

            wp_delete_user($user->ID);
            Logger::info('webhook_user_deleted', "Utente #{$user->ID} eliminato: account cancellato su Identity", [
                'user_id' => $user->ID,
                'sub'     => $sub,
            ]);
        }

        return new \WP_REST_Response(['users' => count($users)], 200);
    }
}
