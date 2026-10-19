/**
 * "Sync W3DS chats": fetches the user's W3DS conversations again on request.
 *
 * In Talk the button sits at the foot of the conversation list. Talk exposes
 * no extension point there, so it is placed once Talk has rendered, and put
 * back if Talk re-renders over it. On the personal settings page it goes
 * into the slot the settings template provides.
 */
(function () {
    'use strict';

    var POLL_INTERVAL = 4000;
    var MAX_POLLS = 450; // 30 minutes, the same horizon the server allows.
    var PLACE_TIMEOUT = 15000;

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
        xhr.open(method, state.url, true);
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

    function build() {
        root = el('div', 'w3ds-cs');
        button = el('button', 'w3ds-cs-button');
        button.type = 'button';
        button.appendChild(el('span', 'w3ds-cs-icon', '↻'));
        button.appendChild(el('span', null, 'Sync W3DS chats'));
        button.addEventListener('click', start);
        root.appendChild(button);

        status = el('div', 'w3ds-cs-status');
        status.setAttribute('aria-live', 'polite');
        root.appendChild(status);
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
                return setStatus('Too many sync requests. Try again in a few minutes.', 'error');
            }
            if (code !== 200 || !data) {
                setBusy(false);
                return setStatus((data && data.error) || (code === 0 ? 'Network error. Try again.' : 'Could not start the sync.'), 'error');
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
                return setStatus('The sync has not started. Background jobs may not be running on this server.', 'error');
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
            setStatus('Fetching your conversations…');
            return poll();
        case 'done':
            setBusy(false);
            setStatus(summary(data), data.complete === false ? 'error' : 'success');
            return;
        case 'failed':
            setBusy(false);
            setStatus(data.error || 'The sync failed.', 'error');
            return;
        default:
            setBusy(false);
            setStatus('');
        }
    }

    function summary(data) {
        var text = 'Synced ' + (data.chats || 0) + ' conversation' + (data.chats === 1 ? '' : 's')
            + ' and ' + (data.messages || 0) + ' message' + (data.messages === 1 ? '' : 's') + '.';
        if (data.complete === false) {
            text += ' Part of the history could not be read; try again later.';
        } else if (data.finishedAt) {
            text += ' Last run ' + new Date(data.finishedAt * 1000).toLocaleString() + '.';
        }
        return text;
    }

    /**
     * Where the button goes: the settings slot, else the foot of Talk's
     * conversation list, just above its settings entry when there is one.
     */
    function place() {
        if (root.isConnected) {
            return true;
        }
        var slot = document.getElementById('w3ds-chat-sync-slot');
        if (slot) {
            slot.appendChild(root);
            return true;
        }
        // The inner <nav> is the panel the conversation list lives in; the
        // outer .app-navigation also holds the collapse toggle, outside it.
        var nav = document.getElementById('app-navigation-vue')
            || document.querySelector('.app-navigation__content, .app-navigation');
        if (!nav) {
            return false;
        }
        var footer = nav.querySelector('.left-sidebar__settings-button-container, .app-navigation__settings, #app-settings');
        if (footer && footer.parentNode) {
            footer.parentNode.insertBefore(root, footer);
        } else {
            nav.appendChild(root);
        }
        return true;
    }

    function init() {
        build();
        // Pick up a sync already running, from another tab or an earlier visit.
        request('GET', function (code, data) {
            if (code === 200 && data) {
                show(data);
            }
        });

        if (place()) {
            if (!document.getElementById('w3ds-chat-sync-slot')) {
                watch();
            }
            return;
        }

        var observer = new MutationObserver(function () {
            if (place()) {
                observer.disconnect();
                watch();
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });

        // Talk laid out differently than expected: float the button rather
        // than not offer it at all.
        setTimeout(function () {
            if (!root.isConnected) {
                observer.disconnect();
                root.classList.add('w3ds-cs-floating');
                document.body.appendChild(root);
            }
        }, PLACE_TIMEOUT);
    }

    /** Talk re-renders its navigation; put the button back when it does. */
    function watch() {
        new MutationObserver(function () {
            if (!root.isConnected && !root.classList.contains('w3ds-cs-floating')) {
                place();
            }
        }).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
