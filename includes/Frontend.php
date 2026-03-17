<?php

namespace RaffaelloIdentity;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gestisce il frontend: shortcode login, profilo, rewrite rules.
 */
class Frontend {
    private Settings $settings;
    private OidcClient $oidc;

    public function __construct(Settings $settings, OidcClient $oidc) {
        $this->settings = $settings;
        $this->oidc = $oidc;
    }

    public function init(): void {
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);

        // Shortcodes
        add_shortcode('ri_login', [$this, 'renderLoginButton']);
        add_shortcode('ri_logout', [$this, 'renderLogoutButton']);
        add_shortcode('ri_profilo', [$this, 'renderProfile']);
        add_shortcode('ri_login_form', [$this, 'renderLoginForm']);
        add_shortcode('ri_user_menu', [$this, 'renderUserMenu']);

        // Intercetta il login form standard di WP
        add_action('login_form', [$this, 'addOidcButtonToLoginForm']);

        // Redirect login WP al login OIDC (opzionale)
        add_filter('login_url', [$this, 'filterLoginUrl'], 10, 3);

        // Integrazione WooCommerce
        if ($this->settings->get('wc_override_login', true)) {
            add_action('init', [$this, 'initWooCommerceOverrides']);
        }
    }

    /**
     * Sovrascrive login/registrazione WooCommerce se attivo.
     */
    public function initWooCommerceOverrides(): void {
        if (!class_exists('WooCommerce')) {
            return;
        }

        // Redirect della pagina My Account (quando non loggato) al login Identity
        add_action('template_redirect', [$this, 'redirectWcMyAccount']);

        // Nasconde form login/registrazione di WooCommerce
        add_filter('woocommerce_login_form_start', [$this, 'replaceWcLoginForm']);
        add_filter('woocommerce_registration_form_start', [$this, 'replaceWcRegistrationForm']);

        // Disabilita la registrazione WooCommerce nativa
        add_filter('woocommerce_registration_enabled', '__return_false');

        // Sovrascrive il link "Il mio account" nel menu WooCommerce
        add_filter('woocommerce_login_redirect', [$this, 'wcLoginRedirect'], 10, 2);
        add_filter('woocommerce_logout_redirect', [$this, 'wcLogoutRedirect']);

        // Intercetta il checkout per utenti non loggati
        add_filter('woocommerce_checkout_must_be_logged_in_message', [$this, 'wcCheckoutLoginMessage']);

        // Nav menu: mostra nome utente o link login
        add_filter('wp_nav_menu_items', [$this, 'addUserMenuItemToNav'], 10, 2);
    }

    public function enqueueAssets(): void {
        wp_enqueue_style('ri-frontend', RI_PLUGIN_URL . 'assets/css/frontend.css', [], RI_VERSION);
        wp_enqueue_script('ri-frontend', RI_PLUGIN_URL . 'assets/js/frontend.js', [], RI_VERSION, true);
        wp_localize_script('ri-frontend', 'riData', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'logoutUrl' => admin_url('admin-ajax.php?action=ri_logout'),
            'isLoggedIn' => is_user_logged_in(),
        ]);
    }

    /**
     * Shortcode [ri_login] — pulsante di login OIDC.
     */
    public function renderLoginButton($atts): string {
        if (is_user_logged_in()) {
            return '';
        }

        $atts = shortcode_atts([
            'text'  => $this->settings->get('login_button_text', 'Accedi con Raffaello'),
            'class' => 'ri-login-btn',
        ], $atts);

        $url = esc_url($this->oidc->getAuthorizationUrl());

        return sprintf(
            '<a href="%s" class="%s">%s</a>',
            $url,
            esc_attr($atts['class']),
            esc_html($atts['text'])
        );
    }

    /**
     * Shortcode [ri_logout] — pulsante di logout federato.
     */
    public function renderLogoutButton($atts): string {
        if (!is_user_logged_in()) {
            return '';
        }

        $atts = shortcode_atts([
            'text'  => 'Esci',
            'class' => 'ri-logout-btn',
        ], $atts);

        $url = esc_url(admin_url('admin-ajax.php?action=ri_logout'));

        return sprintf(
            '<a href="%s" class="%s">%s</a>',
            $url,
            esc_attr($atts['class']),
            esc_html($atts['text'])
        );
    }

    /**
     * Shortcode [ri_login_form] — form di login completo con pulsante OIDC.
     */
    public function renderLoginForm($atts): string {
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            return sprintf(
                '<div class="ri-logged-in"><p>Benvenuto, <strong>%s</strong>!</p><p><a href="%s">Vai al profilo</a> | <a href="%s">Esci</a></p></div>',
                esc_html($user->display_name),
                esc_url($this->settings->get('login_redirect', home_url('/profilo/'))),
                esc_url(admin_url('admin-ajax.php?action=ri_logout'))
            );
        }

        ob_start();
        include RI_PLUGIN_DIR . 'templates/login-form.php';
        return ob_get_clean();
    }

    /**
     * Shortcode [ri_profilo] — pagina profilo utente.
     */
    public function renderProfile($atts): string {
        if (!is_user_logged_in()) {
            return sprintf(
                '<div class="ri-not-logged-in"><p>Devi effettuare l\'accesso per visualizzare il profilo.</p>%s</div>',
                $this->renderLoginButton([])
            );
        }

        ob_start();
        $user = wp_get_current_user();
        $userinfo = get_user_meta($user->ID, 'ri_oidc_userinfo', true) ?: [];
        $extra_claims = $this->settings->getExtraClaims();
        $logout_url = admin_url('admin-ajax.php?action=ri_logout');
        include RI_PLUGIN_DIR . 'templates/profile.php';
        return ob_get_clean();
    }

    /**
     * Aggiunge il pulsante OIDC al form di login standard di WordPress.
     */
    public function addOidcButtonToLoginForm(): void {
        $url = $this->oidc->getAuthorizationUrl();
        $text = esc_html($this->settings->get('login_button_text', 'Accedi con Raffaello'));
        printf(
            '<div class="ri-wp-login-separator"><span>oppure</span></div><p><a href="%s" class="button button-primary button-large ri-wp-login-btn">%s</a></p>',
            esc_url($url),
            $text
        );
    }

    /**
     * Filtra l'URL di login standard per reindirizzare al login OIDC.
     */
    public function filterLoginUrl(string $login_url, string $redirect, bool $force_reauth): string {
        if ($this->settings->get('override_wp_login', false)) {
            return $this->oidc->getAuthorizationUrl();
        }
        return $login_url;
    }

    // =========================================================================
    // Shortcode [ri_user_menu] — elemento menu con nome utente o link login
    // =========================================================================

    /**
     * Shortcode [ri_user_menu] — mostra nome utente + dropdown, oppure link login.
     * Pensato per essere inserito in un widget menu o in un template.
     */
    public function renderUserMenu($atts): string {
        $atts = shortcode_atts([
            'login_text'   => $this->settings->get('login_button_text', 'Accedi'),
            'profile_url'  => $this->settings->get('login_redirect', home_url('/profilo/')),
            'show_avatar'  => 'true',
        ], $atts);

        if (!is_user_logged_in()) {
            $url = esc_url($this->oidc->getAuthorizationUrl());
            return sprintf(
                '<div class="ri-user-menu ri-user-guest"><a href="%s" class="ri-user-menu-login">%s</a></div>',
                $url,
                esc_html($atts['login_text'])
            );
        }

        $user = wp_get_current_user();
        $display = esc_html($user->display_name);
        $avatar = $atts['show_avatar'] === 'true' ? get_avatar($user->ID, 32) : '';
        $profile_url = esc_url($atts['profile_url']);
        $logout_url = esc_url(admin_url('admin-ajax.php?action=ri_logout'));

        // Se WooCommerce è attivo, aggiungi link agli ordini
        $orders_link = '';
        if (class_exists('WooCommerce') && function_exists('wc_get_account_endpoint_url')) {
            $orders_url = esc_url(wc_get_account_endpoint_url('orders'));
            $orders_link = sprintf('<li><a href="%s">I miei ordini</a></li>', $orders_url);
        }

        return sprintf(
            '<div class="ri-user-menu ri-user-logged-in">
                <button class="ri-user-menu-toggle" aria-expanded="false">
                    %s<span class="ri-user-menu-name">%s</span><span class="ri-user-menu-arrow">&#9662;</span>
                </button>
                <ul class="ri-user-menu-dropdown">
                    <li><a href="%s">Il mio profilo</a></li>
                    %s
                    <li class="ri-user-menu-separator"></li>
                    <li><a href="%s" class="ri-user-menu-logout">Esci</a></li>
                </ul>
            </div>',
            $avatar,
            $display,
            $profile_url,
            $orders_link,
            $logout_url
        );
    }

    // =========================================================================
    // Integrazione WooCommerce
    // =========================================================================

    /**
     * Redirect della pagina My Account al login Identity se non loggato.
     */
    public function redirectWcMyAccount(): void {
        if (is_user_logged_in()) {
            return;
        }

        if (!function_exists('is_account_page')) {
            return;
        }

        if (is_account_page()) {
            Logger::info('wc_redirect_login', 'Utente non loggato su My Account, redirect a Identity');
            wp_redirect($this->oidc->getAuthorizationUrl());
            exit;
        }
    }

    /**
     * Sostituisce il form login WooCommerce con il pulsante Identity.
     */
    public function replaceWcLoginForm(): string {
        $url = esc_url($this->oidc->getAuthorizationUrl());
        $text = esc_html($this->settings->get('login_button_text', 'Accedi con Raffaello'));

        return sprintf(
            '<div class="ri-wc-login-override">
                <p>Per accedere, utilizza il tuo account Raffaello.</p>
                <p><a href="%s" class="button ri-login-btn ri-login-btn-primary">%s</a></p>
            </div>',
            $url,
            $text
        );
    }

    /**
     * Nasconde il form di registrazione WooCommerce.
     */
    public function replaceWcRegistrationForm(): string {
        $register_url = esc_url($this->settings->getIssuer() . '/Account/Register');

        return sprintf(
            '<div class="ri-wc-register-override">
                <p>Per registrarti, crea un account sul portale Raffaello.</p>
                <p><a href="%s" class="button" target="_blank">Registrati su Raffaello</a></p>
            </div>',
            $register_url
        );
    }

    /**
     * Redirect dopo login WooCommerce.
     */
    public function wcLoginRedirect(string $redirect, \WP_User $user): string {
        if (function_exists('wc_get_page_permalink')) {
            return wc_get_page_permalink('myaccount');
        }
        return $redirect;
    }

    /**
     * Redirect dopo logout WooCommerce → logout federato via Identity.
     */
    public function wcLogoutRedirect(): string {
        return admin_url('admin-ajax.php?action=ri_logout');
    }

    /**
     * Messaggio personalizzato al checkout per utenti non loggati.
     */
    public function wcCheckoutLoginMessage(): string {
        $url = esc_url($this->oidc->getAuthorizationUrl());
        $text = esc_html($this->settings->get('login_button_text', 'Accedi con Raffaello'));

        return sprintf(
            'Devi effettuare l\'accesso per completare l\'ordine. <a href="%s" class="button ri-login-btn-primary">%s</a>',
            $url,
            $text
        );
    }

    /**
     * Aggiunge voce utente/login al menu di navigazione (se abilitato).
     */
    public function addUserMenuItemToNav(string $items, object $args): string {
        // Aggiunge solo al menu con location configurata
        $target_menu = $this->settings->get('nav_menu_location', '');
        if (empty($target_menu) || $args->theme_location !== $target_menu) {
            return $items;
        }

        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $profile_url = esc_url($this->settings->get('login_redirect', home_url('/profilo/')));
            $logout_url = esc_url(admin_url('admin-ajax.php?action=ri_logout'));

            $items .= sprintf(
                '<li class="menu-item ri-nav-user"><a href="%s">%s</a>
                    <ul class="sub-menu">
                        <li class="menu-item"><a href="%s">Il mio profilo</a></li>',
                $profile_url,
                esc_html($user->display_name),
                $profile_url
            );

            if (class_exists('WooCommerce') && function_exists('wc_get_account_endpoint_url')) {
                $items .= sprintf(
                    '<li class="menu-item"><a href="%s">I miei ordini</a></li>',
                    esc_url(wc_get_account_endpoint_url('orders'))
                );
            }

            $items .= sprintf(
                '<li class="menu-item"><a href="%s">Esci</a></li></ul></li>',
                $logout_url
            );
        } else {
            $login_url = esc_url($this->oidc->getAuthorizationUrl());
            $text = esc_html($this->settings->get('login_button_text', 'Accedi'));
            $items .= sprintf(
                '<li class="menu-item ri-nav-login"><a href="%s">%s</a></li>',
                $login_url,
                $text
            );
        }

        return $items;
    }
}
