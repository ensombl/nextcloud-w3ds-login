(function () {
    'use strict';

    var POLL_INTERVAL = 2500;
    var MAX_POLLS = 120;

    var container = document.getElementById('w3ds-settings');
    if (!container) {
        return;
    }

    var linkStartUrl = container.getAttribute('data-link-start-url');
    var unlinkUrl = container.getAttribute('data-unlink-url');
    var requestToken = container.getAttribute('data-request-token');
    var modal = document.getElementById('w3ds-link-modal');
    var pollTimer = null;
    var pollCount = 0;

    // Link button
    var linkBtn = document.getElementById('w3ds-link-btn');
    if (linkBtn) {
        linkBtn.addEventListener('click', startLinking);
    }

    // Unlink button
    var unlinkBtn = document.getElementById('w3ds-unlink-btn');
    if (unlinkBtn) {
        unlinkBtn.addEventListener('click', doUnlink);
    }

    // Modal close handlers
    if (modal) {
        modal.querySelector('.w3ds-modal-close').addEventListener('click', closeModal);
        modal.querySelector('.w3ds-modal-backdrop').addEventListener('click', closeModal);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.hidden) {
                closeModal();
            }
        });
    }

    function startLinking() {
        linkBtn.disabled = true;
        linkBtn.textContent = 'Loading...';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', linkStartUrl, true);
        xhr.setRequestHeader('requesttoken', requestToken);
        xhr.setRequestHeader('Accept', 'application/json');

        xhr.onload = function () {
            if (xhr.status !== 200) {
                linkBtn.disabled = false;
                linkBtn.textContent = 'Link W3DS identity';
                return;
            }

            var data;
            try {
                data = JSON.parse(xhr.responseText);
            } catch (e) {
                linkBtn.disabled = false;
                linkBtn.textContent = 'Link W3DS identity';
                return;
            }

            showModal(data);
        };

        xhr.onerror = function () {
            linkBtn.disabled = false;
            linkBtn.textContent = 'Link W3DS identity';
        };

        xhr.send();
    }

    function showModal(data) {
        var qrContainer = document.getElementById('w3ds-modal-qr');
        var deeplink = document.getElementById('w3ds-modal-deeplink');

        // Parse QR SVG safely via DOMParser
        while (qrContainer.firstChild) {
            qrContainer.removeChild(qrContainer.firstChild);
        }
        var parser = new DOMParser();
        var svgDoc = parser.parseFromString(data.qrSvg, 'image/svg+xml');
        var svgEl = svgDoc.documentElement;
        if (svgEl && svgEl.nodeName === 'svg') {
            qrContainer.appendChild(document.importNode(svgEl, true));
        }

        deeplink.href = data.w3dsUri;
        setModalStatus('Waiting for wallet...', null, true);

        modal.hidden = false;
        pollCount = 0;
        startPolling(data.statusUrl);
    }

    function closeModal() {
        modal.hidden = true;
        stopPolling();
        if (linkBtn) {
            linkBtn.disabled = false;
            linkBtn.textContent = 'Link W3DS identity';
        }
    }

    function startPolling(statusUrl) {
        stopPolling();

        function poll() {
            pollCount++;
            if (pollCount > MAX_POLLS) {
                setModalStatus('Session expired. Close and try again.', 'error', false);
                return;
            }

            var xhr = new XMLHttpRequest();
            xhr.open('GET', statusUrl, true);
            xhr.setRequestHeader('Accept', 'application/json');

            xhr.onload = function () {
                if (xhr.status !== 200) {
                    pollTimer = setTimeout(poll, POLL_INTERVAL);
                    return;
                }

                var data;
                try {
                    data = JSON.parse(xhr.responseText);
                } catch (e) {
                    pollTimer = setTimeout(poll, POLL_INTERVAL);
                    return;
                }

                if (data.status === 'linked') {
                    setModalStatus('Linked! Refreshing...', 'success', false);
                    setTimeout(function () {
                        window.location.reload();
                    }, 1000);
                    return;
                }

                if (data.status === 'failed') {
                    setModalStatus(data.error || 'Linking failed', 'error', false);
                    return;
                }

                if (data.status === 'expired') {
                    setModalStatus('Session expired. Close and try again.', 'error', false);
                    return;
                }

                pollTimer = setTimeout(poll, POLL_INTERVAL);
            };

            xhr.onerror = function () {
                pollTimer = setTimeout(poll, POLL_INTERVAL);
            };

            xhr.send();
        }

        pollTimer = setTimeout(poll, 1000);
    }

    function stopPolling() {
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
    }

    function setModalStatus(message, type, showSpinner) {
        var statusEl = document.getElementById('w3ds-modal-status');
        statusEl.className = 'w3ds-modal-status' + (type ? ' w3ds-status-' + type : '');

        while (statusEl.firstChild) {
            statusEl.removeChild(statusEl.firstChild);
        }

        if (showSpinner) {
            var spinner = document.createElement('span');
            spinner.className = 'w3ds-spinner';
            statusEl.appendChild(spinner);
        }

        var text = document.createElement('span');
        text.textContent = message;
        statusEl.appendChild(text);
    }

    function doUnlink() {
        if (!confirm('Are you sure you want to unlink your W3DS identity?')) {
            return;
        }

        unlinkBtn.disabled = true;

        var xhr = new XMLHttpRequest();
        xhr.open('POST', unlinkUrl, true);
        xhr.setRequestHeader('requesttoken', requestToken);
        xhr.setRequestHeader('Accept', 'application/json');

        xhr.onload = function () {
            if (xhr.status === 200) {
                window.location.reload();
            } else {
                unlinkBtn.disabled = false;
                var data;
                try {
                    data = JSON.parse(xhr.responseText);
                } catch (e) {}
                alert(data && data.error ? data.error : 'Failed to unlink');
            }
        };

        xhr.onerror = function () {
            unlinkBtn.disabled = false;
            alert('Network error');
        };

        xhr.send();
    }
})();
