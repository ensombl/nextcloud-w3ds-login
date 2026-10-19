/**
 * "Fetch my W3DS history again", on the personal settings page.
 *
 * Talk keeps conversations current on its own, and fetches someone's
 * history the first time they open it; this is the manual way to run that
 * history fetch again.
 */
(function () {
    'use strict';

    var POLL_INTERVAL = 4000;
    var MAX_POLLS = 450; // 30 minutes, the same horizon the server allows.

    var state;
    try {
        state = OCP.InitialState.loadState('w3ds_login', 'chat-sync');
    } catch (e) {
        return;
    }
    if (!state || !state.url) {
        return;
    }

    var root, button, status;
    var pollTimer = null;
    var pollCount = 0;

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text) {
            node.textContent = text;
        }
        return node;
    }

    function request(method, onDone) {
        var xhr = new XMLHttpRequest();
        // Asked for by hand: the whole history, not only what is new.
        xhr.open(method, method === 'POST' ? state.url + '?full=1' : state.url, true);
        xhr.setRequestHeader('requesttoken', OC.requestToken);
        xhr.setRequestHeader('Accept', 'application/json');
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
        xhr.send();
    }

    function build(slot) {
        root = el('div', 'w3ds-cs');
        button = el('button', 'w3ds-cs-button');
        button.type = 'button';
        button.appendChild(el('span', 'w3ds-cs-icon', '↻'));
        button.appendChild(el('span', null, 'Fetch my W3DS history again'));
        button.addEventListener('click', start);
        root.appendChild(button);

        status = el('div', 'w3ds-cs-status');
        status.setAttribute('aria-live', 'polite');
        root.appendChild(status);
        slot.appendChild(root);
    }

    function setStatus(message, type) {
        status.className = 'w3ds-cs-status' + (type ? ' w3ds-cs-' + type : '');
        status.textContent = message || '';
    }

    function setBusy(busy) {
        button.disabled = busy;
        root.classList.toggle('w3ds-cs-busy', busy);
    }

    function start() {
        setBusy(true);
        setStatus('Starting…');
        request('POST', function (code, data) {
            if (code === 429) {
                setBusy(false);
                return setStatus('Too many requests. Try again in a few minutes.', 'error');
            }
            if (code !== 200 || !data) {
                setBusy(false);
                return setStatus((data && data.error) || (code === 0 ? 'Network error. Try again.' : 'Could not start fetching.'), 'error');
            }
            pollCount = 0;
            show(data);
        });
    }

    function poll() {
        pollTimer = setTimeout(function () {
            pollCount++;
            if (pollCount > MAX_POLLS) {
                setBusy(false);
                return setStatus('This has not started. Background jobs may not be running on this server.', 'error');
            }
            request('GET', function (code, data) {
                if (code !== 200 || !data) {
                    return poll();
                }
                show(data);
            });
        }, POLL_INTERVAL);
    }

    function show(data) {
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }

        switch (data.state) {
        case 'queued':
            setBusy(true);
            setStatus('Queued. It starts with the next background job run, usually within a few minutes.');
            return poll();
        case 'running':
            setBusy(true);
            setStatus('Fetching your W3DS history…');
            return poll();
        case 'done':
            setBusy(false);
            if (data.complete === false) {
                setStatus('Part of your history could not be fetched. Talk retries it automatically.', 'error');
            } else {
                setStatus('Your W3DS history is up to date' + (data.finishedAt ? ' (fetched ' + new Date(data.finishedAt * 1000).toLocaleString() + ').' : '.'), 'success');
            }
            return;
        case 'failed':
            setBusy(false);
            setStatus(data.error || 'Fetching your history failed.', 'error');
            return;
        default:
            setBusy(false);
            setStatus('');
        }
    }

    function init() {
        var slot = document.getElementById('w3ds-chat-sync-slot');
        if (!slot) {
            return;
        }
        build(slot);
        // Show a fetch already running, from Talk or an earlier visit.
        request('GET', function (code, data) {
            if (code === 200 && data) {
                show(data);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
