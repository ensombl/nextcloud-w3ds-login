/**
 * Admin page for the awareness service connection.
 */
(function () {
    'use strict';

    var state;
    try {
        state = OCP.InitialState.loadState('w3ds_login', 'admin-settings');
    } catch (e) {
        return;
    }

    function $(id) {
        return document.getElementById(id);
    }

    function request(url, body, onDone) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', url, true);
        xhr.setRequestHeader('requesttoken', OC.requestToken);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.onload = function () {
            var data = null;
            try {
                data = JSON.parse(xhr.responseText);
            } catch (e) {}
            onDone(xhr.status, data);
        };
        xhr.onerror = function () {
            onDone(0, null);
        };
        xhr.send(JSON.stringify(body || {}));
    }

    function setMessage(text, type) {
        var el = $('w3ds-admin-message');
        el.className = 'w3ds-admin-message' + (type ? ' w3ds-admin-' + type : '');
        el.textContent = text || '';
    }

    function render(settings) {
        $('w3ds-admin-base-url').value = settings.baseUrl || '';
        $('w3ds-admin-registry-url').value = settings.registryUrl || '';

        // Secrets are never sent to the browser. An empty field means
        // "keep what is stored"; the hint says whether anything is.
        $('w3ds-admin-api-key').value = '';
        $('w3ds-admin-api-key').placeholder = settings.hasApiKey ? '•••••••• (saved)' : 'aaas_…';
        $('w3ds-admin-api-key-hint').textContent = settings.hasApiKey
            ? 'A key is saved. Leave empty to keep it, or type a new one to replace it.'
            : 'Issued from the consumer dashboard of the awareness service; shown there only once.';
        $('w3ds-admin-webhook-secret').value = '';
        $('w3ds-admin-webhook-secret').placeholder = settings.hasWebhookSecret ? '•••••••• (saved)' : '';
        $('w3ds-admin-webhook-hint').textContent = settings.hasWebhookSecret
            ? 'Pushed deliveries are signed and verified. Leave empty to keep the secret.'
            : 'Without a secret, deliveries are not pushed to this server and arrive by polling instead, within a minute of each cron run.';

        var configured = settings.baseUrl && settings.hasApiKey;
        var stateEl = $('w3ds-admin-state');
        stateEl.className = 'w3ds-admin-state ' + (configured ? 'w3ds-admin-success' : 'w3ds-admin-error');
        stateEl.textContent = configured
            ? 'The service URL and API key are set' + (settings.followingSince ? '; receiving deliveries since ' + new Date(settings.followingSince).toLocaleString() + '.' : '. Deliveries start with the next background job run.') + ' Use Test connection to check the key is accepted.'
            : 'Not configured: incoming W3DS chats are not being received.';

        $('w3ds-admin-webhook-url').textContent = settings.webhookUrl || '';

        var lastPoll = $('w3ds-admin-last-poll');
        lastPoll.className = '';
        if (!configured) {
            lastPoll.textContent = 'Not polling until the service is configured.';
        } else if (!settings.lastPolled) {
            lastPoll.textContent = 'Never. Polls run from background jobs, open Talk pages, and the occ w3ds_login:awareness-poll worker.';
            lastPoll.className = 'w3ds-admin-error';
        } else {
            var ago = Math.max(0, Math.round(Date.now() / 1000 - settings.lastPolled));
            lastPoll.textContent = (ago < 90 ? ago + ' seconds' : Math.round(ago / 60) + ' minutes') + ' ago.';
            if (ago > 90) {
                lastPoll.textContent += ' For messages within seconds, run the poll worker: sudo -u www-data php occ w3ds_login:awareness-poll';
            }
        }

        var cron = $('w3ds-admin-cron');
        cron.className = '';
        if (!settings.lastCron) {
            cron.textContent = 'Background jobs have never run. Incoming chats depend on them; set up cron as described in the installation guide.';
            cron.className = 'w3ds-admin-error';
        } else {
            var minutes = Math.round((Date.now() / 1000 - settings.lastCron) / 60);
            cron.textContent = 'Mode: ' + settings.backgroundJobsMode + '. Last run ' + (minutes < 1 ? 'less than a minute' : minutes + ' minute' + (minutes === 1 ? '' : 's')) + ' ago.';
            if (settings.backgroundJobsMode !== 'cron' || minutes > 15) {
                cron.className = 'w3ds-admin-error';
                cron.textContent += settings.backgroundJobsMode !== 'cron'
                    ? ' Use system cron: AJAX mode only runs jobs while someone has the web interface open.'
                    : ' That is longer than expected; check that cron is still running.';
            }
        }
    }

    function collect() {
        var body = {
            baseUrl: $('w3ds-admin-base-url').value,
            registryUrl: $('w3ds-admin-registry-url').value,
        };
        // Only send secrets the admin actually typed.
        if ($('w3ds-admin-api-key').value !== '') {
            body.apiKey = $('w3ds-admin-api-key').value;
        }
        if ($('w3ds-admin-webhook-secret').value !== '') {
            body.webhookSecret = $('w3ds-admin-webhook-secret').value;
        }
        return body;
    }

    function save(onSaved) {
        setMessage('Saving…');
        request(state.saveUrl, collect(), function (code, data) {
            if (code !== 200 || !data || !data.settings) {
                return setMessage((data && data.error) || 'Could not save the settings.', 'error');
            }
            state.settings = data.settings;
            render(data.settings);
            setMessage('Saved.', 'success');
            if (onSaved) {
                onSaved();
            }
        });
    }

    function test() {
        setMessage('Testing the connection…');
        request(state.testUrl, {}, function (code, data) {
            if (code !== 200 || !data) {
                return setMessage('Could not run the test.', 'error');
            }
            setMessage(data.message, data.ok ? 'success' : 'error');
        });
    }

    function generateSecret() {
        var bytes = new Uint8Array(32);
        window.crypto.getRandomValues(bytes);
        var field = $('w3ds-admin-webhook-secret');
        field.value = Array.prototype.map.call(bytes, function (b) {
            return ('0' + b.toString(16)).slice(-2);
        }).join('');
        field.type = 'text';
        setMessage('A new secret was generated. Save to use it.');
    }

    function init() {
        if (!$('w3ds-admin-form')) {
            return;
        }
        render(state.settings);
        $('w3ds-admin-form').addEventListener('submit', function (e) {
            e.preventDefault();
            save();
        });
        // Test what is on screen, not what was saved before.
        $('w3ds-admin-test').addEventListener('click', function () {
            save(test);
        });
        $('w3ds-admin-generate').addEventListener('click', generateSecret);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
