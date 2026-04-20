=== Raffaello Identity ===
Contributors: grupporaffaello
Tags: oidc, openid-connect, identity, sso, login
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later

Integrazione OIDC con GruppoRaffaello.Identity — login, profilo utente, ruoli e claim configurabili.

== Descrizione ==

Plugin WordPress per l'integrazione SSO con il server GruppoRaffaello.Identity tramite OpenID Connect (Authorization Code Flow).

Funzionalità:
* Login SSO con il server Identity Raffaello
* Mappatura automatica ruoli (Studente, Docente) → ruoli WordPress
* Claim e scope configurabili dall'admin WordPress
* Pagina profilo utente frontend con dati Identity
* Auto-registrazione utenti al primo login
* Logout federato
* Pulsante login nella pagina wp-login.php standard

== Configurazione ==

1. Attivare il plugin in WordPress
2. Andare su Impostazioni → Raffaello Identity
3. Configurare:
   - **Issuer URL**: `https://account.raffaellolibri.it` (produzione) o `https://account-dev.raffaellolibri.it` (sviluppo)
   - **Client ID**: `raffaelloScuolaClient`
   - **Client Secret**: il secret configurato nel server Identity
   - **Scope**: `openid email profile offline_access roles`
4. Configurare il redirect_uri nel server Identity con il valore mostrato nella pagina impostazioni
5. Configurare la mappatura ruoli Identity → WordPress

== Shortcode ==

Shortcode che restituiscono HTML (pulsante o blocco completo):

* `[ri_login]` — Pulsante di login OIDC (visibile solo se non autenticato)
* `[ri_logout]` — Pulsante di logout federato (visibile solo se autenticato)
* `[ri_login_form]` — Form di login completo con card
* `[ri_profilo]` — Pagina profilo utente completa
* `[ri_user_menu]` — Menu utente con dropdown (login / profilo / logout)

Shortcode che restituiscono SOLO l'URL (utili per costruttori visuali come YooTheme/Elementor):

* `[ri_login_url]` — URL per avviare il login OIDC
* `[ri_logout_url]` — URL del logout federato
* `[ri_account_url]` — URL della pagina Profilo sul server Identity (con returnUrl al sito corrente)
  * Attributo opzionale: `return_to="https://..."` per un URL di ritorno personalizzato

Attributi opzionali per `[ri_login]`:
* `text` — Testo del pulsante (default: "Accedi con Raffaello")
* `class` — Classe CSS aggiuntiva

== Placeholder nei menu WordPress ==

Puoi inserire voci di menu con i seguenti URL speciali; il plugin li sostituisce a runtime con gli URL reali e nasconde la voce se non applicabile allo stato di login:

* `#ri-login` — Voce visibile solo agli utenti non loggati
* `#ri-logout` — Voce visibile solo agli utenti loggati
* `#ri-account` — Voce visibile solo agli utenti loggati, punta al profilo su Identity

== Helper PHP ==

* `ri_login_url()` — string
* `ri_logout_url()` — string
* `ri_account_url(?string $return_to = null)` — string

Esempio pagina profilo:
```
[ri_profilo]
```

Esempio pagina login:
```
[ri_login_form]
```

== Hook disponibili ==

* `ri_user_synced` (action) — Chiamato dopo la sincronizzazione dell'utente. Parametri: `$user_id`, `$userinfo`, `$tokens`

== Changelog ==

= 1.5.0 =
* Nuovi shortcode URL-only: `[ri_login_url]`, `[ri_logout_url]`, `[ri_account_url]`
* Nuovi placeholder menu: `#ri-login`, `#ri-logout`, `#ri-account`
* Helper PHP `ri_login_url()`, `ri_logout_url()`, `ri_account_url()`
* Il link "Il mio profilo" punta di default alla pagina Manage su Identity (con returnUrl)

= 1.4.0 =
* Gestione ruolo "sostegno" e mapping claim

= 1.0.0 =
* Prima release
* Login OIDC con Authorization Code Flow
* Mappatura ruoli Identity → WordPress
* Pagina profilo frontend
* Pannello admin per configurazione scope, claim e ruoli
