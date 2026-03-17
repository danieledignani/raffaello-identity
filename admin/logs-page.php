<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $logs */
/** @var int $total */
/** @var int $total_pages */
/** @var int $page_num */
/** @var string $level_filter */
/** @var string $event_filter */

$base_url = add_query_arg(['page' => 'raffaello-identity', 'tab' => 'log'], admin_url('options-general.php'));
$levels = ['', 'DEBUG', 'INFO', 'WARNING', 'ERROR'];
$level_colors = [
    'DEBUG'   => '#6c757d',
    'INFO'    => '#0073aa',
    'WARNING' => '#dba617',
    'ERROR'   => '#dc3232',
];
?>
<h2>Log <span class="ri-log-count" style="font-weight:normal;color:#666;">(<?php echo number_format_i18n($total); ?> voci)</span></h2>

    <!-- Filtri -->
    <div class="ri-section ri-log-filters">
        <form method="get" action="<?php echo esc_url($base_url); ?>">
            <input type="hidden" name="page" value="raffaello-identity">
            <input type="hidden" name="tab" value="log">
            <label for="ri-log-level">Livello:</label>
            <select name="level" id="ri-log-level">
                <option value="">Tutti</option>
                <?php foreach (['DEBUG', 'INFO', 'WARNING', 'ERROR'] as $lv) : ?>
                    <option value="<?php echo esc_attr($lv); ?>" <?php selected($level_filter, $lv); ?>>
                        <?php echo $lv; ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="ri-log-event">Evento:</label>
            <input type="text" name="event" id="ri-log-event" value="<?php echo esc_attr($event_filter); ?>"
                   placeholder="es. token_exchange, login_success" class="regular-text">

            <button type="submit" class="button">Filtra</button>
            <a href="<?php echo esc_url($base_url); ?>" class="button">Reset</a>
        </form>
    </div>

    <!-- Svuota log -->
    <form method="post" style="display:inline; margin-bottom:12px;">
        <?php wp_nonce_field('ri_clear_logs', 'ri_log_nonce'); ?>
        <button type="submit" name="ri_clear_logs" value="1" class="button"
                onclick="return confirm('Svuotare tutti i log?');">Svuota tutti i log</button>
    </form>

    <!-- Tabella log -->
    <?php if (empty($logs)) : ?>
        <p>Nessun log trovato.</p>
    <?php else : ?>
        <table class="widefat ri-log-table">
            <thead>
                <tr>
                    <th style="width:140px;">Data/Ora</th>
                    <th style="width:70px;">Livello</th>
                    <th style="width:160px;">Evento</th>
                    <th>Messaggio</th>
                    <th style="width:60px;">Utente</th>
                    <th style="width:110px;">IP</th>
                    <th style="width:30px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log) :
                    $color = $level_colors[$log['level']] ?? '#333';
                    $context = $log['context'] ? json_decode($log['context'], true) : null;
                    $row_id = 'ri-log-' . $log['id'];
                ?>
                <tr>
                    <td><code style="font-size:12px;"><?php echo esc_html($log['created_at']); ?></code></td>
                    <td>
                        <span class="ri-log-badge" style="background:<?php echo $color; ?>; color:#fff; padding:2px 8px; border-radius:3px; font-size:11px; font-weight:600;">
                            <?php echo esc_html($log['level']); ?>
                        </span>
                    </td>
                    <td><code><?php echo esc_html($log['event']); ?></code></td>
                    <td><?php echo esc_html($log['message']); ?></td>
                    <td><?php echo $log['user_id'] ? '#' . esc_html($log['user_id']) : '—'; ?></td>
                    <td><code style="font-size:11px;"><?php echo esc_html($log['ip_address'] ?? ''); ?></code></td>
                    <td>
                        <?php if ($context) : ?>
                            <button type="button" class="button button-small ri-toggle-context"
                                    data-target="<?php echo $row_id; ?>" title="Mostra dettagli">&darr;</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($context) : ?>
                <tr id="<?php echo $row_id; ?>" style="display:none;" class="ri-context-row">
                    <td colspan="7">
                        <pre style="background:#f5f5f5; padding:10px; margin:0; overflow-x:auto; font-size:12px; border-radius:4px;"><?php
                            echo esc_html(json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        ?></pre>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Paginazione -->
        <?php if ($total_pages > 1) : ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo number_format_i18n($total); ?> voci</span>
                <?php
                echo paginate_links([
                    'base'      => add_query_arg('paged', '%#%'),
                    'format'    => '',
                    'current'   => $page_num,
                    'total'     => $total_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ]);
                ?>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>

<script>
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('ri-toggle-context')) {
        var targetId = e.target.getAttribute('data-target');
        var row = document.getElementById(targetId);
        if (row) {
            var isVisible = row.style.display !== 'none';
            row.style.display = isVisible ? 'none' : '';
            e.target.textContent = isVisible ? '\u2193' : '\u2191';
        }
    }
});
</script>
