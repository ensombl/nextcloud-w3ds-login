(function () {
    'use strict';

    var POLL_INTERVAL = 15000;
    // Chat-list (room discovery) polling runs less often than per-room
    // message polling -- new chats are rarer than new messages, and
    // pullSyncForUser lists the entire chat ontology so it's heavier.
    var CHAT_POLL_INTERVAL = 30000;
    var timer = null;
    var chatTimer = null;
    var currentToken = null;
    // Only run the chat-list poll on Talk pages. Anywhere else (Files,
    // Settings, etc.) doesn't need it -- saves a request every 30s on
    // every tab the user has open.
    var TALK_PATH_RE = /(?:^|\/)(?:apps\/spreed|call|conversation)(?:\/|$)/;

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

    function postBestEffort(url) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', url, true);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('requesttoken', getRequestToken());
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function () { /* best-effort */ };
        xhr.onerror = function () { /* ignore */ };
        xhr.send();
    }

    function pollOnce() {
        if (!currentToken) {
            return;
        }
        postBestEffort(getOcBase() + '/api/rooms/' + encodeURIComponent(currentToken) + '/poll');
    }

    function pollUserChats() {
        // Skip on non-Talk pages so other tabs don't burn requests on
        // server-side eVault listings they'll never use.
        var path = window.location.pathname || '';
        var hash = window.location.hash || '';
        if (!TALK_PATH_RE.test(path) && !TALK_PATH_RE.test(hash)) {
            return;
        }
        postBestEffort(getOcBase() + '/api/chats/poll');
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
        // Kick off chat-list discovery immediately, then on its own
        // slower cadence. Independent of per-room polling so it runs
        // even when not currently inside a room.
        pollUserChats();
        chatTimer = setInterval(pollUserChats, CHAT_POLL_INTERVAL);
        window.addEventListener('hashchange', tick);
        window.addEventListener('popstate', tick);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
