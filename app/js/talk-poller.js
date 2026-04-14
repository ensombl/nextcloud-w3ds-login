(function () {
    'use strict';

    var POLL_INTERVAL = 15000;
    var timer = null;
    var currentToken = null;

    function getOcBase() {
        if (typeof OC !== 'undefined' && typeof OC.generateUrl === 'function') {
            return OC.generateUrl('/apps/w3ds_login');
        }
        return '/index.php/apps/w3ds_login';
    }

    function getRequestToken() {
        if (typeof OC !== 'undefined' && typeof OC.requestToken === 'string') {
            return OC.requestToken;
        }
        var el = document.querySelector('head[data-requesttoken]');
        return el ? el.getAttribute('data-requesttoken') : '';
    }

    // Talk URLs: /call/TOKEN, /index.php/call/TOKEN, or the legacy
    // /apps/spreed/#/call/TOKEN. Presence of a room token in the URL IS
    // the "in a Talk room" signal -- don't gate on a pathname prefix,
    // because modern Talk uses a bare /call/ route that doesn't contain
    // /apps/spreed at all.
    function extractRoomToken() {
        var hashMatch = window.location.hash.match(/\/(?:call|conversation)\/([a-zA-Z0-9]{4,})/);
        if (hashMatch) {
            return hashMatch[1];
        }
        var pathMatch = window.location.pathname.match(/\/(?:call|conversation)\/([a-zA-Z0-9]{4,})/);
        if (pathMatch) {
            return pathMatch[1];
        }
        return null;
    }

    function pollOnce() {
        if (!currentToken) {
            return;
        }
        var url = getOcBase() + '/api/rooms/' + encodeURIComponent(currentToken) + '/poll';
        var xhr = new XMLHttpRequest();
        xhr.open('POST', url, true);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('requesttoken', getRequestToken());
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function () {
            // best-effort: we don't need to do anything with the response.
            // Talk's own long-poll will pick up new messages shortly after
            // we've inserted them into the local DB.
        };
        xhr.onerror = function () { /* ignore */ };
        xhr.send();
    }

    function tick() {
        var token = extractRoomToken();
        if (token !== currentToken) {
            currentToken = token;
        }
        if (currentToken) {
            pollOnce();
        }
    }

    function start() {
        if (timer) {
            return;
        }
        tick();
        timer = setInterval(tick, POLL_INTERVAL);
        window.addEventListener('hashchange', tick);
        window.addEventListener('popstate', tick);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
