/**
 * Asks a signed-in user with no linked W3DS identity to connect one with a
 * single wallet scan. Shown to people who arrived through an identity
 * provider, which named their eName but cannot prove it.
 */
(function () {
    'use strict';

    var POLL_INTERVAL = 2500;
    var MAX_POLLS = 120;

    var state;
    try {
        state = OCP.InitialState.loadState('w3ds_login', 'link-prompt');
    } catch (e) {
        return;
    }
    if (!state || !state.linkStartUrl) {
        return;
    }

    var pollTimer = null;
    var pollCount = 0;
    var root, qr, status, deeplink, connectBtn, laterBtn;

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

    function request(method, url, onDone) {
        var xhr = new XMLHttpRequest();
        xhr.open(method, url, true);
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
        root = el('div', 'w3ds-lp');
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-modal', 'true');
        root.setAttribute('aria-labelledby', 'w3ds-lp-title');

        var card = el('div', 'w3ds-lp-card');
        var title = el('h3', null, 'Connect your W3DS identity');
        title.id = 'w3ds-lp-title';
        card.appendChild(title);

        var text = el('p', 'w3ds-lp-text');
        if (state.eName) {
            text.appendChild(document.createTextNode('You signed in as '));
            text.appendChild(el('strong', null, state.eName));
            text.appendChild(document.createTextNode('. Confirm it with your eID wallet once to sync your chats and profile.'));
        } else {
            text.textContent = 'Link your eID wallet once to sync your chats and profile across W3DS platforms.';
        }
        card.appendChild(text);

        qr = el('div', 'w3ds-lp-qr');
        qr.hidden = true;
        card.appendChild(qr);

        status = el('div', 'w3ds-lp-status');
        status.setAttribute('aria-live', 'polite');
        card.appendChild(status);

        var actions = el('div', 'w3ds-lp-actions');
        connectBtn = el('button', 'primary', 'Connect wallet');
        connectBtn.type = 'button';
        connectBtn.addEventListener('click', start);
        actions.appendChild(connectBtn);

        deeplink = el('a', 'w3ds-lp-deeplink', 'Open in eID Wallet');
        deeplink.hidden = true;
        actions.appendChild(deeplink);

        laterBtn = el('button', null, 'Not now');
        laterBtn.type = 'button';
        laterBtn.addEventListener('click', dismiss);
        actions.appendChild(laterBtn);
        card.appendChild(actions);

        root.appendChild(el('div', 'w3ds-lp-backdrop'));
        root.appendChild(card);
        document.body.appendChild(root);
    }

    function setStatus(message, type) {
        status.className = 'w3ds-lp-status' + (type ? ' w3ds-lp-' + type : '');
        status.textContent = message;
    }

    function start() {
        connectBtn.disabled = true;
        setStatus('Loading...');
        request('POST', state.linkStartUrl, function (code, data) {
            if (code !== 200 || !data) {
                connectBtn.disabled = false;
                setStatus('Could not start linking. Try again.', 'error');
                return;
            }
            while (qr.firstChild) {
                qr.removeChild(qr.firstChild);
            }
            var svg = new DOMParser().parseFromString(data.qrSvg, 'image/svg+xml').documentElement;
            if (svg && svg.nodeName === 'svg') {
                qr.appendChild(document.importNode(svg, true));
                qr.hidden = false;
            }
            deeplink.href = data.w3dsUri;
            deeplink.hidden = false;
            connectBtn.hidden = true;
            setStatus('Waiting for your wallet...');
            pollCount = 0;
            poll(data.statusUrl);
        });
    }

    function poll(statusUrl) {
        pollTimer = setTimeout(function () {
            pollCount++;
            if (pollCount > MAX_POLLS) {
                return expired();
            }
            request('GET', statusUrl, function (code, data) {
                if (code !== 200 || !data) {
                    return poll(statusUrl);
                }
                if (data.status === 'linked') {
                    setStatus('Connected. Refreshing...', 'success');
                    setTimeout(function () {
                        window.location.reload();
                    }, 1000);
                    return;
                }
                if (data.status === 'failed') {
                    return retry(data.error || 'Linking failed.');
                }
                if (data.status === 'expired') {
                    return expired();
                }
                poll(statusUrl);
            });
        }, POLL_INTERVAL);
    }

    function expired() {
        retry('This code expired.');
    }

    function retry(message) {
        setStatus(message, 'error');
        qr.hidden = true;
        deeplink.hidden = true;
        connectBtn.hidden = false;
        connectBtn.disabled = false;
        connectBtn.textContent = 'Try again';
    }

    function dismiss() {
        if (pollTimer) {
            clearTimeout(pollTimer);
        }
        root.hidden = true;
        if (state.dismissUrl) {
            request('POST', state.dismissUrl, function () {});
        }
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && root && !root.hidden) {
            dismiss();
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', build);
    } else {
        build();
    }
})();
