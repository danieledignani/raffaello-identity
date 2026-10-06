<?php

namespace RaffaelloIdentity;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Aligns the database with the code. Updates do not run the activation hook, so it runs
 * whenever the version saved in the database differs from RI_VERSION.
 */
class Upgrade {
    private const VERSION_OPTION = 'ri_db_version';
    private const REALIGN_HOOK = 'ri_realign_roles';
    // Last user id realigned; present only while a realignment is pending.
    private const REALIGN_CURSOR = 'ri_realign_cursor';
    private const REALIGN_BATCH = 200;
    private const REALIGN_SECONDS = 20;

    private const ROLES = [
        'studente'       => 'Studente',
        'docente'        => 'Docente',
        'concessionario' => 'Concessionario',
    ];

    private OidcClient $oidc;

    public function __construct(OidcClient $oidc) {
        $this->oidc = $oidc;
    }

    public function init(): void {
        add_action(self::REALIGN_HOOK, [$this, 'realignRoles']);

        // WordPress drops an event before running it: a run that died leaves the cursor without one.
        if (wp_doing_cron() && get_option(self::REALIGN_CURSOR) !== false && !wp_next_scheduled(self::REALIGN_HOOK)) {
            wp_schedule_single_event(time(), self::REALIGN_HOOK);
        }
    }

    public static function maybeRun(): void {
        if (get_option(self::VERSION_OPTION) !== RI_VERSION) {
            self::run();
        }
    }

    public static function run(): void {
        $from = (string) get_option(self::VERSION_OPTION, '');

        Logger::createTable();

        foreach (self::ROLES as $slug => $label) {
            if (!get_role($slug)) {
                add_role($slug, $label, ['read' => true]);
            }
        }

        if (version_compare($from, '1.8.0', '<')) {
            $saved = get_option('ri_options');

            // What the mapping of the old version may have given, for the accounts without ri_mapped_roles.
            $legacy_mapping = $saved['role_mapping'] ?? ['Studente' => 'studente', 'Docente' => 'docente'];
            update_option('ri_legacy_role_targets', array_values($legacy_mapping), false);

            // Concessionario gets its own role. A saved mapping replaces the default one as a whole
            // (wp_parse_args is shallow), so the new entry goes into it; an emptied one stays empty.
            if (!empty($saved['role_mapping']) && !isset($saved['role_mapping']['Concessionario'])) {
                $saved['role_mapping']['Concessionario'] = 'concessionario';
                ri_save_options($saved);
            }

            // The id_token is no longer used.
            delete_metadata('user', 0, 'ri_id_token', '', true);

            self::scheduleRealign();
        }

        update_option(self::VERSION_OPTION, RI_VERSION);

        Logger::info('db_upgraded', "Database del plugin aggiornato da " . ($from ?: 'nessuna versione') . " a " . RI_VERSION);
    }

    /**
     * Starts the realignment over from the first user, replacing one already under way.
     */
    public static function scheduleRealign(): void {
        update_option(self::REALIGN_CURSOR, 0, false);
        wp_clear_scheduled_hook(self::REALIGN_HOOK);
        wp_schedule_single_event(time(), self::REALIGN_HOOK);
    }

    /**
     * Maps again the roles of the linked users from the claims saved at their last login.
     * Works through batches for a few seconds, then hands over to the next cron run.
     */
    public function realignRoles(): void {
        global $wpdb;

        $started = time();
        do {
            $cursor = self::readCursor();
            if ($cursor === false) {
                return;
            }

            $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'ri_oidc_sub' AND user_id > %d ORDER BY user_id LIMIT %d",
                (int) $cursor,
                self::REALIGN_BATCH
            )));

            foreach ($ids as $id) {
                $userinfo = get_user_meta($id, 'ri_oidc_userinfo', true);
                if (is_array($userinfo)) {
                    $this->oidc->mapRoles($id, $userinfo);
                }
            }

            // A new mapping restarted the realignment meanwhile: the run it scheduled reads the new options.
            if (self::readCursor() !== $cursor) {
                return;
            }

            if (count($ids) < self::REALIGN_BATCH) {
                delete_option(self::REALIGN_CURSOR);
                Logger::info('roles_realigned', 'Riallineamento dei ruoli degli utenti collegati completato');
                return;
            }

            update_option(self::REALIGN_CURSOR, end($ids), false);
        } while (time() - $started < self::REALIGN_SECONDS);

        wp_schedule_single_event(time(), self::REALIGN_HOOK);
    }

    /**
     * From the database, not the options cache of this request: another request may have reset it.
     */
    private static function readCursor() {
        wp_cache_delete(self::REALIGN_CURSOR, 'options');
        return get_option(self::REALIGN_CURSOR);
    }
}
