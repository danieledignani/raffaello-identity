/**
 * Raffaello Identity — Frontend JS
 */
(function () {
    'use strict';

    // Dropdown menu utente [ri_user_menu]
    document.addEventListener('click', function (e) {
        var toggle = e.target.closest('.ri-user-menu-toggle');

        if (toggle) {
            e.preventDefault();
            var dropdown = toggle.nextElementSibling;
            var isOpen = dropdown.classList.contains('ri-open');

            // Chiudi tutti i dropdown aperti
            document.querySelectorAll('.ri-user-menu-dropdown.ri-open').forEach(function (d) {
                d.classList.remove('ri-open');
                d.previousElementSibling.setAttribute('aria-expanded', 'false');
            });

            if (!isOpen) {
                dropdown.classList.add('ri-open');
                toggle.setAttribute('aria-expanded', 'true');
            }
            return;
        }

        // Chiudi dropdown se click fuori
        if (!e.target.closest('.ri-user-menu')) {
            document.querySelectorAll('.ri-user-menu-dropdown.ri-open').forEach(function (d) {
                d.classList.remove('ri-open');
                d.previousElementSibling.setAttribute('aria-expanded', 'false');
            });
        }

        // Conferma logout
        var logoutBtn = e.target.closest('.ri-logout-btn, .ri-btn-outline[href*="ri_logout"]');
        if (logoutBtn) {
            if (!confirm('Sei sicuro di voler uscire?')) {
                e.preventDefault();
            }
        }
    });

    // Chiudi dropdown con ESC
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.ri-user-menu-dropdown.ri-open').forEach(function (d) {
                d.classList.remove('ri-open');
                d.previousElementSibling.setAttribute('aria-expanded', 'false');
            });
        }
    });

    // Avviso "sessione terminata" dopo un logout automatico (guard prompt=none).
    // Renderizzato via JS e non in PHP: le pagine servite dalla page cache non
    // eseguono PHP, ma questo script gira comunque. Il testo arriva da riData
    // (filtro PHP ri_session_ended_message) con fallback locale.
    try {
        var params = new URLSearchParams(window.location.search);
        if (params.get('ri_session_ended') === '1') {
            var message = (window.riData && window.riData.sessionEndedMessage) ||
                'La tua sessione è terminata. Effettua di nuovo l\'accesso per continuare.';

            var notice = document.createElement('div');
            notice.className = 'ri-session-ended-notice';
            notice.setAttribute('role', 'status');

            var text = document.createElement('span');
            text.className = 'ri-session-ended-text';
            text.textContent = message;

            var close = document.createElement('button');
            close.type = 'button';
            close.className = 'ri-session-ended-close';
            close.setAttribute('aria-label', 'Chiudi');
            close.innerHTML = '&times;';
            close.addEventListener('click', function () { notice.remove(); });

            notice.appendChild(text);
            notice.appendChild(close);
            document.body.appendChild(notice);

            // Pulisce l'URL: un refresh o un bookmark non devono rimostrare l'avviso.
            params.delete('ri_session_ended');
            var clean = window.location.pathname +
                (params.toString() ? '?' + params.toString() : '') +
                window.location.hash;
            window.history.replaceState(null, '', clean);
        }
    } catch (err) {
        // URLSearchParams non disponibile su browser molto datati: nessun avviso, nessun errore.
    }
})();
