/**
 * Keeps W3DS conversations in Talk current, and shows that it is doing so.
 *
 * Opening a conversation fetches new W3DS messages straight away, and again
 * every few seconds while it stays open; with none open, every half minute.
 * A small indicator at the foot of the conversation list says what is going
 * on: syncing, up to date as of when, fetching history, or why it cannot.
 *
 * The first time someone opens Talk the history of their eVault is fetched
 * too, and an incomplete fetch is retried later without anyone asking.
 *
 * Talk exposes no extension point for its conversation list, so the
 * indicator is placed once Talk has rendered and put back if Talk re-renders
 * over it.
 */
(function () {
    'use strict';

    var OPEN_CHAT_INTERVAL = 10000;
    var IDLE_INTERVAL = 30000;
    var BUSY_RETRY = 3000;
    var HISTORY_CHECK_INTERVAL = 5000;
    var HISTORY_RETRY_AFTER = 15 * 60; // seconds
    var PLACE_TIMEOUT = 15000;

    var state;
    try {
        state = OCP.InitialState.loadState('w3ds_login', 'talk-sync');
    } catch (e) {
        return;
    }
    if (!state || !state.pollUrl) {
        return;
    }

    var root, icon, label;
    var currentChat = null;
    var pollTimer = null;
    var pollInFlight = false;
    var lastPolledAt = null;
    var pollError = null;
    var historyBusy = false;

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

    function request(method, url, body, onDone) {
        var xhr = new XMLHttpRequest();
        xhr.open(method, url, true);
        xhr.setRequestHeader('requesttoken', OC.requestToken);
        xhr.setRequestHeader('Accept', 'application/json');
        if (body) {
            xhr.setRequestHeader('Content-Type', 'application/json');
        }
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
        xhr.send(body ? JSON.stringify(body) : null);
    }

    // ---- indicator ----------------------------------------------------

    function build() {
        root = el('div', 'w3ds-ts');
        root.setAttribute('role', 'status');
        root.setAttribute('aria-live', 'polite');
        icon = el('span', 'w3ds-ts-icon');
        icon.setAttribute('aria-hidden', 'true');
        label = el('span', 'w3ds-ts-label');
        root.appendChild(icon);
        root.appendChild(label);
    }

    function ago(seconds) {
        if (seconds < 10) {
            return 'just now';
        }
        if (seconds < 60) {
            return seconds + 's ago';
        }
        var minutes = Math.round(seconds / 60);
        return minutes < 60 ? minutes + ' min ago' : Math.round(minutes / 60) + ' h ago';
    }

    /** One place decides what the indicator says, by priority. */
    function render() {
        if (!root) {
            return;
        }
        var mode, text, title;
        if (historyBusy) {
            mode = 'busy';
            text = 'Fetching your W3DS history…';
            title = 'Bringing in your earlier W3DS conversations and messages. They appear as they arrive.';
        } else if (pollInFlight) {
            mode = 'busy';
            text = 'Syncing W3DS…';
            title = 'Checking for new W3DS messages.';
        } else if (pollError) {
            mode = 'error';
            text = pollError.short;
            title = pollError.long;
        } else if (lastPolledAt) {
            mode = 'ok';
            text = 'W3DS up to date · ' + ago(Math.max(0, Math.round(Date.now() / 1000 - lastPolledAt)));
            title = 'New W3DS messages are fetched every ' + (currentChat ? '10' : '30') + ' seconds while Talk is open.';
        } else {
            mode = 'busy';
            text = 'Syncing W3DS…';
            title = 'Checking for new W3DS messages.';
        }
        root.className = 'w3ds-ts w3ds-ts-' + mode + (root.classList.contains('w3ds-ts-floating') ? ' w3ds-ts-floating' : '');
        label.textContent = text;
        root.title = title;
    }

    // ---- polling ------------------------------------------------------

    function schedule(delay) {
        if (pollTimer) {
            clearTimeout(pollTimer);
        }
        pollTimer = setTimeout(function () {
            poll(false);
        }, delay);
    }

    function poll(chatJustOpened) {
        if (document.visibilityState !== 'visible') {
            // Picked up again by the visibility listener.
            return;
        }
        if (pollInFlight) {
            return;
        }
        pollInFlight = true;
        render();
        // The open conversation's token lets the server bring in its recent
        // messages from its group and participants' eVaults too.
        request('POST', state.pollUrl, { chatOpen: currentChat !== null || chatJustOpened, chatToken: currentChat || '' }, function (code, data) {
            pollInFlight = false;
            var next = currentChat ? OPEN_CHAT_INTERVAL : IDLE_INTERVAL;

            if (code === 429) {
                next = IDLE_INTERVAL;
            } else if (code !== 200 || !data) {
                pollError = { short: 'W3DS sync interrupted', long: code === 0 ? 'No connection to the server. Retrying.' : 'The server could not check for new W3DS messages. Retrying.' };
            } else if (data.configured === false) {
                pollError = { short: 'W3DS sync not set up', long: data.error || 'Incoming W3DS messages are not set up on this server.' };
                next = 5 * 60000;
            } else if (data.error) {
                pollError = { short: 'W3DS sync interrupted', long: data.error };
            } else {
                pollError = null;
                if (data.lastPolledAt) {
                    lastPolledAt = data.lastPolledAt;
                } else if (!data.polled) {
                    // Another tab or the server's own poller is fetching
                    // right now; ask again in a moment.
                    next = BUSY_RETRY;
                }
            }
            render();
            schedule(next);
        });
    }

    // ---- which conversation is open ------------------------------------

    function chatFromLocation() {
        var match = window.location.pathname.match(/\/call\/([^/?#]+)/);
        return match ? match[1] : null;
    }

    function onLocationChange() {
        var chat = chatFromLocation();
        if (chat === currentChat) {
            return;
        }
        currentChat = chat;
        if (chat) {
            // Someone just opened a conversation: fetch now, not on the
            // next tick.
            poll(true);
        } else {
            schedule(IDLE_INTERVAL);
        }
    }

    function watchLocation() {
        // Talk navigates with the history API, which fires no event for
        // pushes; wrap it, and check periodically for anything that slips by.
        ['pushState', 'replaceState'].forEach(function (method) {
            var original = window.history[method];
            window.history[method] = function () {
                var result = original.apply(this, arguments);
                setTimeout(onLocationChange, 0);
                return result;
            };
        });
        window.addEventListener('popstate', onLocationChange);
        setInterval(onLocationChange, 1000);
    }

    // ---- history ------------------------------------------------------

    function checkHistory(mayStart) {
        if (!state.syncUrl) {
            return;
        }
        request('GET', state.syncUrl, null, function (code, data) {
            if (code !== 200 || !data || data.linked === false) {
                historyBusy = false;
                return render();
            }
            var now = Date.now() / 1000;
            var running = data.state === 'queued' || data.state === 'running';
            var needsFetch = !data.state
                || ((data.state === 'failed' || (data.state === 'done' && data.complete === false))
                    && now - (data.finishedAt || 0) > HISTORY_RETRY_AFTER);

            if (mayStart && needsFetch) {
                return request('POST', state.syncUrl, null, function (startCode) {
                    historyBusy = startCode === 200;
                    render();
                    if (historyBusy) {
                        setTimeout(function () {
                            checkHistory(false);
                        }, HISTORY_CHECK_INTERVAL);
                    }
                });
            }

            historyBusy = running;
            render();
            if (running) {
                setTimeout(function () {
                    checkHistory(false);
                }, HISTORY_CHECK_INTERVAL);
            }
        });
    }

    // ---- placement ----------------------------------------------------

    /** At the foot of Talk's conversation list, above its settings entry. */
    function place() {
        if (root.isConnected) {
            return true;
        }
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

    function mount() {
        var observer = new MutationObserver(function () {
            if (!root.isConnected && !root.classList.contains('w3ds-ts-floating')) {
                place();
            }
        });
        place();
        observer.observe(document.body, { childList: true, subtree: true });

        setTimeout(function () {
            if (!root.isConnected) {
                observer.disconnect();
                root.classList.add('w3ds-ts-floating');
                document.body.appendChild(root);
            }
        }, PLACE_TIMEOUT);
    }

    function init() {
        build();
        render();
        mount();

        currentChat = chatFromLocation();
        watchLocation();
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                poll(false);
            }
        });
        // Keep "12s ago" honest between polls.
        setInterval(render, 5000);

        poll(currentChat !== null);
        checkHistory(true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
