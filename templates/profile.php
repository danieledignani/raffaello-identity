<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var WP_User $user */
/** @var array $userinfo */
/** @var array $extra_claims */
/** @var string $logout_url */

$nome = esc_html($user->first_name ?: ($userinfo['nome'] ?? ''));
$cognome = esc_html($user->last_name ?: ($userinfo['cognome'] ?? ''));
$email = esc_html($user->user_email);
$roles = array_map(function ($role) {
    $role_obj = get_role($role);
    return $role_obj ? translate_user_role(ucfirst($role)) : ucfirst($role);
}, $user->roles);
$profilo = esc_html($userinfo['profilo'] ?? '');
?>
<div class="ri-profile">
    <div class="ri-profile-card">
        <div class="ri-profile-header">
            <div class="ri-profile-avatar">
                <?php echo get_avatar($user->ID, 80); ?>
            </div>
            <div class="ri-profile-name">
                <h2><?php echo trim("$nome $cognome") ?: esc_html($user->display_name); ?></h2>
                <?php if ($profilo) : ?>
                    <span class="ri-profile-badge"><?php echo $profilo; ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="ri-profile-body">
            <div class="ri-profile-section">
                <h3>Informazioni personali</h3>
                <div class="ri-profile-grid">
                    <div class="ri-profile-field">
                        <label>Nome</label>
                        <span><?php echo $nome ?: '—'; ?></span>
                    </div>
                    <div class="ri-profile-field">
                        <label>Cognome</label>
                        <span><?php echo $cognome ?: '—'; ?></span>
                    </div>
                    <div class="ri-profile-field">
                        <label>Email</label>
                        <span><?php echo $email; ?></span>
                    </div>
                    <div class="ri-profile-field">
                        <label>Ruoli</label>
                        <span><?php echo implode(', ', $roles) ?: '—'; ?></span>
                    </div>
                </div>
            </div>

            <?php if (!empty($extra_claims)) : ?>
            <div class="ri-profile-section">
                <h3>Informazioni aggiuntive</h3>
                <div class="ri-profile-grid">
                    <?php foreach ($extra_claims as $claim) :
                        $value = get_user_meta($user->ID, 'ri_claim_' . sanitize_key($claim), true);
                        if ($value === '') continue;

                        // Label leggibile
                        $labels = [
                            'consensoMarketing'    => 'Consenso Marketing',
                            'consensoProfilazione'  => 'Consenso Profilazione',
                            'consensoTerzeParti'    => 'Consenso Terze Parti',
                            'joomla_sub'            => 'ID Joomla',
                        ];
                        $label = $labels[$claim] ?? ucfirst($claim);

                        // Formatta valori booleani
                        if (in_array(strtolower($value), ['true', 'false'], true)) {
                            $display = strtolower($value) === 'true' ? 'Sì' : 'No';
                        } else {
                            $display = esc_html($value);
                        }
                    ?>
                    <div class="ri-profile-field">
                        <label><?php echo esc_html($label); ?></label>
                        <span><?php echo $display; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="ri-profile-footer">
            <a href="<?php echo esc_url($this->settings->getIssuer() . '/Account/Manage'); ?>"
               class="ri-btn ri-btn-secondary" target="_blank">
                Modifica profilo su Raffaello
            </a>
            <a href="<?php echo esc_url($logout_url); ?>" class="ri-btn ri-btn-outline">
                Esci
            </a>
        </div>
    </div>
</div>
