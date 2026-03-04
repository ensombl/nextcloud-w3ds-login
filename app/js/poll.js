(function () {
    'use strict';

    var POLL_INTERVAL = 2500;
    var MAX_POLLS = 120; // 5 minutes at 2.5s intervals

    var container = document.getElementById('w3ds-auth');
    if (!container) {
        return;
    }

    var statusUrl = container.getAttribute('data-status-url');
    if (!statusUrl) {
        return;
    }

    var statusEl = document.getElementById('w3ds-status');
    var pollCount = 0;
    var timer = null;

    function updateStatus(html, className) {
        if (statusEl) {
            statusEl.innerHTML = '<div class="' + className + '">' + html + '</div>';
        }
    }

    function poll() {
        pollCount++;

        if (pollCount > MAX_POLLS) {
            updateStatus('Session expired. Please reload to try again.', 'w3ds-status-error');
            return;
        }

        var xhr = new XMLHttpRequest();
        xhr.open('GET', statusUrl, true);
        xhr.setRequestHeader('Accept', 'application/json');

        xhr.onload = function () {
            if (xhr.status !== 200) {
                timer = setTimeout(poll, POLL_INTERVAL);
                return;
            }

            var data;
            try {
                data = JSON.parse(xhr.responseText);
            } catch (e) {
                timer = setTimeout(poll, POLL_INTERVAL);
                return;
            }

            if (data.status === 'authenticated' && data.loginUrl) {
                updateStatus(
                    '<span class="w3ds-spinner"></span> Authenticated! Redirecting...',
                    'w3ds-status-success'
                );
                window.location.href = data.loginUrl;
                return;
            }

            if (data.status === 'expired') {
                updateStatus('Session expired. Please reload to try again.', 'w3ds-status-error');
                return;
            }

            // Still pending
            timer = setTimeout(poll, POLL_INTERVAL);
        };

        xhr.onerror = function () {
            timer = setTimeout(poll, POLL_INTERVAL);
        };

        xhr.send();
    }

    // Start polling after a short initial delay
    timer = setTimeout(poll, 1000);
})();
