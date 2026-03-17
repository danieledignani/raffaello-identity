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
})();
