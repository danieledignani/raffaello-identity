(function () {
    'use strict';

    var steps = ['discovery', 'token_endpoint', 'userinfo_endpoint', 'full_flow'];
    var results = {};

    document.getElementById('ri-run-tests').addEventListener('click', function () {
        this.disabled = true;
        this.textContent = 'Test in corso...';
        results = {};

        // Mostra tutti gli step
        document.querySelectorAll('.ri-test-step').forEach(function (el) {
            el.style.display = 'block';
            el.className = 'ri-test-step';
            el.querySelector('.ri-test-icon').textContent = '⏳';
            el.querySelector('.ri-test-body').innerHTML = '';
        });

        document.getElementById('ri-test-summary').style.display = 'none';

        runStep(0);
    });

    function runStep(index) {
        if (index >= steps.length) {
            showSummary();
            var btn = document.getElementById('ri-run-tests');
            btn.disabled = false;
            btn.textContent = 'Riesegui test';
            return;
        }

        var step = steps[index];
        var el = document.querySelector('[data-step="' + step + '"]');
        el.classList.add('running');
        el.querySelector('.ri-test-icon').textContent = '🔄';

        var data = new FormData();
        data.append('action', 'ri_test_connection');
        data.append('nonce', riTest.nonce);
        data.append('step', step);

        fetch(riTest.ajaxUrl, { method: 'POST', body: data })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                var result = resp.data || resp;
                results[step] = result;

                el.classList.remove('running');
                el.classList.add(result.success ? 'success' : 'error');
                el.querySelector('.ri-test-icon').textContent = result.success ? '✅' : '❌';
                el.querySelector('.ri-test-body').innerHTML = renderResult(step, result);

                runStep(index + 1);
            })
            .catch(function (err) {
                results[step] = { success: false, message: 'Errore di rete: ' + err.message };
                el.classList.remove('running');
                el.classList.add('error');
                el.querySelector('.ri-test-icon').textContent = '❌';
                el.querySelector('.ri-test-body').innerHTML = '<p>' + err.message + '</p>';

                runStep(index + 1);
            });
    }

    function renderResult(step, r) {
        var html = '<p><strong>' + esc(r.message) + '</strong></p>';

        if (step === 'discovery') {
            if (r.endpoints) {
                html += '<table>';
                html += row('URL Discovery', '<code>' + esc(r.url) + '</code>');
                html += row('Issuer', '<code>' + esc(r.issuer) + '</code>');
                for (var key in r.endpoints) {
                    html += row(key, '<code>' + esc(r.endpoints[key]) + '</code>');
                }
                if (r.response_time_ms) {
                    html += row('Tempo di risposta', r.response_time_ms + 'ms');
                }
                if (r.scopes_supported && r.scopes_supported.length) {
                    html += row('Scopes supportati', r.scopes_supported.map(function(s) { return '<code>' + esc(s) + '</code>'; }).join(' '));
                }
                if (r.unsupported_scopes && r.unsupported_scopes.length) {
                    html += row('Scopes non supportati', '<span class="ri-check-fail">' + r.unsupported_scopes.join(', ') + '</span>');
                }
                if (r.grant_types && r.grant_types.length) {
                    html += row('Grant types', r.grant_types.map(function(g) { return '<code>' + esc(g) + '</code>'; }).join(' '));
                }
                html += '</table>';
            }

            if (r.mismatches && Object.keys(r.mismatches).length) {
                for (var mk in r.mismatches) {
                    html += '<div class="ri-mismatch">⚠️ <strong>' + esc(mk) + '</strong>: endpoint configurato (<code>' +
                        esc(r.mismatches[mk].configurato) + '</code>) diverso dalla discovery (<code>' +
                        esc(r.mismatches[mk].discovery) + '</code>)</div>';
                }
            }
        }

        if (step === 'token_endpoint') {
            html += '<table>';
            html += row('URL', '<code>' + esc(r.url) + '</code>');
            html += row('HTTP Status', r.http_status);
            if (r.response_time_ms) html += row('Tempo di risposta', r.response_time_ms + 'ms');
            if (r.error) html += row('Errore', '<code>' + esc(r.error) + '</code>');
            if (r.error_description) html += row('Dettaglio', esc(r.error_description));
            html += '</table>';
        }

        if (step === 'userinfo_endpoint') {
            html += '<table>';
            html += row('URL', '<code>' + esc(r.url) + '</code>');
            html += row('HTTP Status', r.http_status);
            if (r.response_time_ms) html += row('Tempo di risposta', r.response_time_ms + 'ms');
            html += row('Token salvato', r.has_saved_token ? '<span class="ri-check-ok">Sì</span>' : 'No');
            html += '</table>';

            if (r.userinfo) {
                html += '<p><strong>Dati utente corrente (dal token salvato):</strong></p>';
                html += '<pre>' + esc(JSON.stringify(r.userinfo, null, 2)) + '</pre>';
            }
        }

        if (step === 'full_flow' && r.checks) {
            html += '<table>';
            for (var ck in r.checks) {
                var c = r.checks[ck];
                var icon = c.ok ? '<span class="ri-check-ok">✓</span>' : '<span class="ri-check-fail">✗</span>';
                var val = esc(String(c.value));
                if (c.note) val += ' <em style="color:#666;">(' + esc(c.note) + ')</em>';
                html += '<tr><td>' + icon + '</td><th>' + esc(c.label) + '</th><td>' + val + '</td></tr>';
            }
            html += '</table>';
        }

        if (r.fix) {
            html += '<div class="ri-fix">💡 <strong>Suggerimento:</strong> ' + esc(r.fix) + '</div>';
        }

        return html;
    }

    function showSummary() {
        var el = document.getElementById('ri-test-summary');
        var allOk = true;
        for (var k in results) {
            if (!results[k].success) {
                allOk = false;
                break;
            }
        }

        el.style.display = 'block';
        if (allOk) {
            el.style.background = '#edfaef';
            el.style.border = '1px solid #00a32a';
            el.innerHTML = '✅ <strong>Tutti i test superati!</strong> La configurazione OIDC è corretta e il server Identity è raggiungibile.';
        } else {
            el.style.background = '#fef0f0';
            el.style.border = '1px solid #d63638';
            el.innerHTML = '❌ <strong>Alcuni test hanno fallito.</strong> Controlla i suggerimenti sopra per risolvere i problemi.';
        }
    }

    function row(label, value) {
        return '<tr><th>' + esc(label) + '</th><td>' + value + '</td></tr>';
    }

    function esc(str) {
        if (str === null || str === undefined) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }
})();
