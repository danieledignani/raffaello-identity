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

        // Shortcodes bottone / blocco completo
        add_shortcode('ri_login', [$this, 'renderLoginButton']);
        add_shortcode('ri_logout', [$this, 'renderLogoutButton']);
        add_shortcode('ri_profilo', [$this, 'renderProfile']);
        add_shortcode('ri_login_form', [$this, 'renderLoginForm']);
        add_shortcode('ri_user_menu', [$this, 'renderUserMenu']);

        // Shortcode che restituiscono solo l'URL: utili come href="" nei template
        // YooTheme / Elementor / costruttori visuali che supportano gli shortcode.
        add_shortcode('ri_login_url', [$this, 'renderLoginUrl']);
        add_shortcode('ri_logout_url', [$this, 'renderLogoutUrl']);
        add_shortcode('ri_account_url', [$this, 'renderAccountUrl']);

        // Nome utente configurabile (campo scelto in Impostazioni)
        add_shortcode('ri_user_name', [$this, 'renderUserName']);

        // Placeholder nei menu WP: #ri-login, #ri-logout, #ri-account
        // Utili per configurare voci di menu dinamiche senza hard-code di URL che cambiano per ambiente.
        add_filter('wp_nav_menu_objects', [$this, 'rewriteMenuPlaceholders'], 10, 2);

        // Hook di backup per walker custom (es. YooTheme Navbar) che non invocano
        // il filtro wp_nav_menu_objects ma comunque chiamano nav_menu_link_attributes
        // per ogni link renderizzato. Sostituisce solo l'href; per nascondere una
        // voce non valida (es. #ri-logout quando utente non loggato) aggiunge la
        // classe CSS "ri-menu-hide" gestita dal CSS del plugin.
        add_filter('nav_menu_link_attributes', [$this, 'rewriteMenuLinkAttributes'], 10, 4);

        // Placeholder {ri_name} nelle LABEL dei menu: sostituito dal nome utente
        // secondo display_field. Opera sia via wp_nav_menu_objects (per title)
        // sia via walker_nav_menu_start_el (per HTML completo, fallback YooTheme).
        add_filter('walker_nav_menu_start_el', [$this, 'rewriteMenuItemHtml'], 10, 4);

        // Non aggiungere il pulsante OIDC al form wp-login.php:
        // gli utenti Identity non sono amministratori WordPress.

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
     * Shortcode [ri_login_url] — restituisce SOLO l'URL di login OIDC.
     * Pensato per href="..." in template visuali (YooTheme, Elementor, Divi, ecc.).
     */
    public function renderLoginUrl($atts): string {
        return esc_url($this->oidc->getAuthorizationUrl());
    }

    /**
     * Shortcode [ri_logout_url] — restituisce SOLO l'URL di logout federato.
     */
    public function renderLogoutUrl($atts): string {
        return esc_url(admin_url('admin-ajax.php?action=ri_logout'));
    }

    /**
     * Shortcode [ri_user_name] — restituisce il nome utente secondo
     * il campo display_field configurato in Impostazioni.
     *
     * Attributi opzionali:
     *   field: sovrascrive il campo impostato (display_name|first_name|
     *          last_name|full_name|username|email).
     *   fallback: testo mostrato se l'utente non è loggato (default: vuoto).
     */
    public function renderUserName($atts): string {
        $atts = shortcode_atts([
            'field'    => '',
            'fallback' => '',
        ], $atts);

        if (!is_user_logged_in()) {
            return esc_html($atts['fallback']);
        }

        $field_override = !empty($atts['field']) ? $atts['field'] : null;
        return esc_html(ri_user_display_name($field_override));
    }

    /**
     * Shortcode [ri_account_url] — restituisce SOLO l'URL della pagina profilo
     * sul server Identity, già con returnUrl verso il sito corrente.
     *
     * Attributi opzionali:
     *   return_to: URL personalizzato a cui tornare (default: home_url('/')).
     */
    public function renderAccountUrl($atts): string {
        $atts = shortcode_atts([
            'return_to' => '',
        ], $atts);

        $return_to = !empty($atts['return_to']) ? $atts['return_to'] : null;
        return esc_url($this->settings->getIdentityAccountUrl($return_to));
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
                esc_url($this->settings->getIdentityAccountUrl()),
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
        // Non sovrascrivere il login di wp-admin: gli amministratori devono
        // sempre poter accedere con le credenziali WordPress locali.
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
            // Se profile_url non è passato, puntiamo di default alla pagina profilo su Identity.
            // Per usare una pagina locale: [ri_user_menu profile_url="/pagina-locale/"]
            'profile_url'  => $this->settings->getIdentityAccountUrl(),
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

    /**
     * Riscrive gli URL dei menu WordPress sostituendo i placeholder:
     *   #ri-login   → URL di login OIDC (nasconde la voce se utente già loggato)
     *   #ri-logout  → URL di logout federato (nasconde la voce se utente non loggato)
     *   #ri-account → URL della pagina profilo su Identity (nasconde se non loggato)
     *
     * Così l'admin inserisce voci statiche nei menu WP e il plugin gestisce
     * visibilità e risoluzione degli URL in runtime.
     */
    public function rewriteMenuPlaceholders(array $items, $args): array {
        $logged_in = is_user_logged_in();
        $user_name = $logged_in ? ri_user_display_name() : '';
        $options   = ri_get_options();

        foreach ($items as $key => $item) {
            // Sostituisce {ri_name} nella label del menu con il nome utente.
            // Retrocompatibilità con configurazioni precedenti al placeholder #ri-user.
            if (!empty($item->title) && strpos($item->title, '{ri_name}') !== false) {
                $item->title = str_replace('{ri_name}', $user_name, $item->title);
            }

            if (empty($item->url)) {
                continue;
            }

            switch ($item->url) {
                case '#ri-login':
                    if ($logged_in) {
                        unset($items[$key]);
                    } else {
                        $item->url = $this->oidc->getAuthorizationUrl();
                    }
                    break;

                case '#ri-logout':
                    if (!$logged_in) {
                        unset($items[$key]);
                    } else {
                        $item->url = admin_url('admin-ajax.php?action=ri_logout');
                    }
                    break;

                case '#ri-account':
                    if (!$logged_in) {
                        unset($items[$key]);
                    } else {
                        $item->url = $this->settings->getIdentityAccountUrl();
                    }
                    break;

                // Voce state-aware: visibile sia loggato che non, cambia URL e label.
                // Loggato: vai al profilo Identity, label = "menu_label_in".
                // Non loggato: vai al login OIDC, label = "menu_label_out".
                case '#ri-user':
                    if ($logged_in) {
                        $item->url = $this->settings->getIdentityAccountUrl();
                        $label = (string) ($options['menu_label_in'] ?? 'Ciao {name}!');
                        $item->title = str_replace('{name}', $user_name, $label);
                    } else {
                        $item->url = $this->oidc->getAuthorizationUrl();
                        $item->title = (string) ($options['menu_label_out'] ?? 'Accedi');
                    }
                    break;
            }
        }

        // Re-indicizza l'array dopo le unset() per evitare buchi nelle chiavi
        return array_values($items);
    }

    /**
     * Fallback per walker custom (YooTheme Navbar, Elementor, Oxygen, ecc.) che
     * renderizzano i menu senza far scattare wp_nav_menu_objects. Sostituisce
     * solo l'href; non può rimuovere la voce dal DOM, quindi aggiunge la classe
     * CSS "ri-menu-hide" su voci che non devono essere visibili nello stato
     * di login corrente (es. #ri-logout per utenti anonimi).
     *
     * @param array $atts Attributi HTML del link (href, class, title, target, rel)
     * @param object $item Oggetto menu item
     * @param object $args Argomenti di wp_nav_menu
     * @param int $depth Livello del menu
     */
    public function rewriteMenuLinkAttributes(array $atts, $item, $args, $depth): array {
        if (empty($atts['href'])) {
            return $atts;
        }

        $logged_in = is_user_logged_in();
        $hide = false;

        switch ($atts['href']) {
            case '#ri-login':
                if ($logged_in) {
                    $hide = true;
                } else {
                    $atts['href'] = $this->oidc->getAuthorizationUrl();
                }
                break;

            case '#ri-logout':
                if (!$logged_in) {
                    $hide = true;
                } else {
                    $atts['href'] = admin_url('admin-ajax.php?action=ri_logout');
                }
                break;

            case '#ri-account':
                if (!$logged_in) {
                    $hide = true;
                } else {
                    $atts['href'] = $this->settings->getIdentityAccountUrl();
                }
                break;

            case '#ri-user':
                $atts['href'] = $logged_in
                    ? $this->settings->getIdentityAccountUrl()
                    : $this->oidc->getAuthorizationUrl();
                // Non nascondiamo: la voce è state-aware, visibile sempre.
                break;

            default:
                return $atts;
        }

        if ($hide) {
            $atts['class'] = trim(($atts['class'] ?? '') . ' ri-menu-hide');
            $atts['aria-hidden'] = 'true';
            $atts['tabindex'] = '-1';
        }

        return $atts;
    }

    /**
     * Fallback per walker custom: sostituisce {ri_name} nell'HTML renderizzato
     * del menu item. Scatta anche quando wp_nav_menu_objects non fa la
     * sostituzione in $item->title (es. YooTheme Navbar).
     *
     * @param string $item_output HTML renderizzato della voce di menu
     * @param object $item Oggetto menu item
     * @param int $depth Profondità della voce
     * @param object $args Argomenti di wp_nav_menu
     */
    public function rewriteMenuItemHtml(string $item_output, $item, int $depth, $args): string {
        // Sostituzione {ri_name} legacy
        if (strpos($item_output, '{ri_name}') !== false) {
            $user_name = is_user_logged_in() ? ri_user_display_name() : '';
            $item_output = str_replace('{ri_name}', esc_html($user_name), $item_output);
        }

        // Sostituzione label per #ri-user: se il walker del tema non ha onorato
        // la modifica di $item->title fatta in wp_nav_menu_objects, intercettiamo
        // l'HTML renderizzato e sostituiamo il testo del link.
        if (!empty($item->url) && $item->url === '#ri-user') {
            $options = ri_get_options();
            if (is_user_logged_in()) {
                $user_name = ri_user_display_name();
                $label = (string) ($options['menu_label_in'] ?? 'Ciao {name}!');
                $label = str_replace('{name}', esc_html($user_name), $label);
            } else {
                $label = (string) ($options['menu_label_out'] ?? 'Accedi');
            }

            // Sostituisce il contenuto testuale del primo <a>...</a> con il label.
            // Usiamo regex non greedy per fermarci al primo </a>. Il label può
            // contenere HTML (icone ecc.) quindi non viene re-escapato qui.
            $item_output = preg_replace(
                '/(<a\b[^>]*>)(.*?)(<\/a>)/is',
                '$1' . $label . '$3',
                $item_output,
                1
            );
        }

        return $item_output;
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
            // Il link "Il mio profilo" punta alla pagina Manage su Identity, con
            // returnUrl pre-impostato al sito corrente.
            $profile_url = esc_url($this->settings->getIdentityAccountUrl());
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
