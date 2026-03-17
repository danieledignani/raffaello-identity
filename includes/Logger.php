<?php

namespace RaffaelloIdentity;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sistema di logging per il flusso OIDC.
 * I log vengono salvati nella tabella custom `ri_logs` e sono consultabili dall'admin.
 */
class Logger {
    const TABLE_NAME = 'ri_logs';
    const MAX_ENTRIES = 500;

    const LEVEL_DEBUG = 'DEBUG';
    const LEVEL_INFO = 'INFO';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_ERROR = 'ERROR';

    private static bool $enabled = true;

    /**
     * Crea la tabella log se non esiste.
     */
    public static function createTable(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            level VARCHAR(10) NOT NULL DEFAULT 'INFO',
            event VARCHAR(100) NOT NULL,
            message TEXT NOT NULL,
            context LONGTEXT DEFAULT NULL,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_level (level),
            KEY idx_event (event),
            KEY idx_created_at (created_at)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Scrive un log.
     */
    public static function log(string $level, string $event, string $message, array $context = []): void {
        if (!self::$enabled) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // Verifica che la tabella esista
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            self::createTable();
        }

        // Maschera dati sensibili nel context
        $safe_context = self::maskSensitiveData($context);

        $wpdb->insert($table, [
            'level'      => $level,
            'event'      => sanitize_text_field($event),
            'message'    => sanitize_text_field($message),
            'context'    => !empty($safe_context) ? wp_json_encode($safe_context, JSON_UNESCAPED_UNICODE) : null,
            'user_id'    => get_current_user_id() ?: null,
            'ip_address' => self::getClientIp(),
        ]);

        // Pulizia automatica: mantieni solo le ultime N entry
        self::pruneOldEntries();
    }

    public static function debug(string $event, string $message, array $context = []): void {
        self::log(self::LEVEL_DEBUG, $event, $message, $context);
    }

    public static function info(string $event, string $message, array $context = []): void {
        self::log(self::LEVEL_INFO, $event, $message, $context);
    }

    public static function warning(string $event, string $message, array $context = []): void {
        self::log(self::LEVEL_WARNING, $event, $message, $context);
    }

    public static function error(string $event, string $message, array $context = []): void {
        self::log(self::LEVEL_ERROR, $event, $message, $context);
    }

    /**
     * Recupera i log per la visualizzazione admin.
     */
    public static function getLogs(int $limit = 100, int $offset = 0, string $level_filter = '', string $event_filter = ''): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            return [];
        }

        $where = '1=1';
        $params = [];

        if (!empty($level_filter)) {
            $where .= ' AND level = %s';
            $params[] = $level_filter;
        }

        if (!empty($event_filter)) {
            $where .= ' AND event LIKE %s';
            $params[] = '%' . $wpdb->esc_like($event_filter) . '%';
        }

        $query = "SELECT * FROM $table WHERE $where ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A);
    }

    /**
     * Conta i log totali.
     */
    public static function countLogs(string $level_filter = '', string $event_filter = ''): int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            return 0;
        }

        $where = '1=1';
        $params = [];

        if (!empty($level_filter)) {
            $where .= ' AND level = %s';
            $params[] = $level_filter;
        }

        if (!empty($event_filter)) {
            $where .= ' AND event LIKE %s';
            $params[] = '%' . $wpdb->esc_like($event_filter) . '%';
        }

        if (empty($params)) {
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        }

        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE $where", $params));
    }

    /**
     * Svuota tutti i log.
     */
    public static function clearLogs(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $wpdb->query("TRUNCATE TABLE $table");
    }

    /**
     * Rimuove i log più vecchi mantenendo solo le ultime MAX_ENTRIES.
     */
    private static function pruneOldEntries(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");

        if ($count > self::MAX_ENTRIES) {
            $delete_count = $count - self::MAX_ENTRIES;
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $table ORDER BY created_at ASC LIMIT %d",
                $delete_count
            ));
        }
    }

    /**
     * Maschera dati sensibili (token, secret, password).
     */
    private static function maskSensitiveData(array $data): array {
        $sensitive_keys = ['access_token', 'refresh_token', 'id_token', 'client_secret', 'code', 'password'];

        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                $value = self::maskSensitiveData($value);
            } elseif (is_string($value) && in_array($key, $sensitive_keys, true)) {
                if (strlen($value) > 8) {
                    $value = substr($value, 0, 4) . '****' . substr($value, -4);
                } else {
                    $value = '****';
                }
            }
        }

        return $data;
    }

    /**
     * Recupera l'IP del client in modo sicuro.
     */
    private static function getClientIp(): string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public static function setEnabled(bool $enabled): void {
        self::$enabled = $enabled;
    }
}
