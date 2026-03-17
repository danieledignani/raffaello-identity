=== Raffaello Identity ===
Contributors: grupporaffaello
Tags: oidc, openid-connect, identity, sso, login
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Integrazione OIDC con GruppoRaffaello.Identity — login, profilo utente, ruoli e claim configurabili.

== Descrizione ==

Plugin WordPress per l'integrazione SSO con il server GruppoRaffaello.Identity tramite OpenID Connect (Authorization Code Flow).

Funzionalità:
* Login SSO con il server Identity Raffaello
* Mappatura automatica ruoli (Studente, Docente, Dirigente, ecc.) → ruoli WordPress
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

* `[ri_login]` — Pulsante di login OIDC (visibile solo se non autenticato)
* `[ri_logout]` — Pulsante di logout federato (visibile solo se autenticato)
* `[ri_login_form]` — Form di login completo con card
* `[ri_profilo]` — Pagina profilo utente completa

Attributi opzionali per `[ri_login]`:
* `text` — Testo del pulsante (default: "Accedi con Raffaello")
* `class` — Classe CSS aggiuntiva

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

= 1.0.0 =
* Prima release
* Login OIDC con Authorization Code Flow
* Mappatura ruoli Identity → WordPress
* Pagina profilo frontend
* Pannello admin per configurazione scope, claim e ruoli
