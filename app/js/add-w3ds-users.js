(function () {
    'use strict';

    var BUTTON_ID = 'w3ds-add-users-fab';
    var MODAL_ID = 'w3ds-add-users-modal';

    function ocBase() {
        if (typeof OC !== 'undefined' && typeof OC.generateUrl === 'function') {
            return OC.generateUrl('/apps/w3ds_login');
        }
        return '/index.php/apps/w3ds_login';
    }

    function requestToken() {
        if (typeof OC !== 'undefined' && typeof OC.requestToken === 'string') {
            return OC.requestToken;
        }
        var el = document.querySelector('head[data-requesttoken]');
        return el ? el.getAttribute('data-requesttoken') : '';
    }

    function extractRoomToken() {
        var hashMatch = window.location.hash.match(/\/(?:call|conversation)\/([a-zA-Z0-9]{4,})/);
        if (hashMatch) return hashMatch[1];
        var pathMatch = window.location.pathname.match(/\/(?:call|conversation)\/([a-zA-Z0-9]{4,})/);
        if (pathMatch) return pathMatch[1];
        return null;
    }

    function ensureStyles() {
        if (document.getElementById('w3ds-add-users-styles')) return;
        var s = document.createElement('style');
        s.id = 'w3ds-add-users-styles';
        s.textContent = [
            '#' + BUTTON_ID + '{position:fixed;bottom:24px;right:24px;z-index:9999;',
            'background:var(--color-primary,#0082c9);color:#fff;border:none;',
            'border-radius:24px;padding:10px 18px;font-size:14px;font-weight:500;',
            'box-shadow:0 2px 8px rgba(0,0,0,.18);cursor:pointer;display:flex;',
            'align-items:center;gap:8px;transition:opacity .15s,transform .1s}',
            '#' + BUTTON_ID + ':hover{opacity:.92}',
            '#' + BUTTON_ID + ':active{transform:translateY(1px)}',
            '#' + MODAL_ID + '{position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.45);',
            'display:flex;align-items:center;justify-content:center}',
            '#' + MODAL_ID + ' .w3ds-modal-card{background:var(--color-main-background,#fff);',
            'color:var(--color-main-text,#222);border-radius:14px;width:min(480px,92vw);',
            'max-height:80vh;display:flex;flex-direction:column;box-shadow:0 6px 24px rgba(0,0,0,.25);',
            'padding:1.25rem 1.25rem 1rem}',
            '#' + MODAL_ID + ' h3{margin:0 0 .75rem;font-size:1.1rem;font-weight:600}',
            '#' + MODAL_ID + ' input[type=search]{width:100%;padding:.55rem .75rem;',
            'border:1px solid var(--color-border,#ddd);border-radius:8px;font-size:.95rem;',
            'box-sizing:border-box;background:var(--color-main-background,#fff);',
            'color:var(--color-main-text,#222)}',
            '#' + MODAL_ID + ' .w3ds-modal-list{margin-top:.75rem;overflow-y:auto;flex:1;',
            'border-top:1px solid var(--color-border,#eee)}',
            '#' + MODAL_ID + ' .w3ds-modal-row{display:flex;align-items:center;gap:.6rem;',
            'padding:.55rem .25rem;border-bottom:1px solid var(--color-border,#f0f0f0);',
            'cursor:pointer}',
            '#' + MODAL_ID + ' .w3ds-modal-row:hover{background:var(--color-background-hover,#f5f5f5)}',
            '#' + MODAL_ID + ' .w3ds-modal-row label{flex:1;cursor:pointer;display:flex;',
            'flex-direction:column;line-height:1.25}',
            '#' + MODAL_ID + ' .w3ds-modal-row .name{font-weight:500;font-size:.9rem}',
            '#' + MODAL_ID + ' .w3ds-modal-row .meta{font-size:.75rem;',
            'color:var(--color-text-maxcontrast,#888)}',
            '#' + MODAL_ID + ' .w3ds-modal-empty{padding:1rem;text-align:center;',
            'color:var(--color-text-maxcontrast,#888);font-size:.85rem}',
            '#' + MODAL_ID + ' .w3ds-modal-actions{display:flex;justify-content:flex-end;',
            'gap:.5rem;margin-top:.75rem}',
            '#' + MODAL_ID + ' button{padding:.5rem 1rem;border-radius:18px;border:none;',
            'cursor:pointer;font-size:.9rem;font-weight:500}',
            '#' + MODAL_ID + ' button.primary{background:var(--color-primary,#0082c9);color:#fff}',
            '#' + MODAL_ID + ' button.primary:disabled{opacity:.5;cursor:not-allowed}',
            '#' + MODAL_ID + ' button.ghost{background:transparent;',
            'color:var(--color-main-text,#222)}',
            '#' + MODAL_ID + ' .w3ds-modal-error{margin-top:.5rem;color:#c0392b;',
            'font-size:.8rem;text-align:center}'
        ].join('');
        document.head.appendChild(s);
    }

    function ensureButton() {
        var token = extractRoomToken();
        var existing = document.getElementById(BUTTON_ID);
        if (!token) {
            if (existing) existing.remove();
            return;
        }
        if (existing) return;
        ensureStyles();
        var btn = document.createElement('button');
        btn.id = BUTTON_ID;
        btn.type = 'button';
        btn.textContent = '+ Add W3DS users';
        btn.addEventListener('click', function () { openModal(token); });
        document.body.appendChild(btn);
    }

    function closeModal() {
        var m = document.getElementById(MODAL_ID);
        if (m) m.remove();
    }

    function openModal(roomToken) {
        ensureStyles();
        closeModal();

        var overlay = document.createElement('div');
        overlay.id = MODAL_ID;
        overlay.innerHTML = [
            '<div class="w3ds-modal-card" role="dialog" aria-modal="true">',
            '<h3>Add W3DS users to this conversation</h3>',
            '<input type="search" placeholder="Filter by name, email or W3ID" autocomplete="off" />',
            '<div class="w3ds-modal-list" aria-live="polite"></div>',
            '<div class="w3ds-modal-error" hidden></div>',
            '<div class="w3ds-modal-actions">',
            '<button type="button" class="ghost" data-act="cancel">Cancel</button>',
            '<button type="button" class="primary" data-act="add" disabled>Add 0</button>',
            '</div>',
            '</div>'
        ].join('');
        document.body.appendChild(overlay);

        var input = overlay.querySelector('input[type=search]');
        var list = overlay.querySelector('.w3ds-modal-list');
        var errorEl = overlay.querySelector('.w3ds-modal-error');
        var addBtn = overlay.querySelector('button[data-act=add]');
        var allProfiles = [];   // populated once after fetch
        var selected = {};      // w3id -> profile

        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeModal();
        });
        overlay.querySelector('button[data-act=cancel]').addEventListener('click', closeModal);
        document.addEventListener('keydown', escHandler);
        function escHandler(e) {
            if (e.key === 'Escape') {
                document.removeEventListener('keydown', escHandler);
                closeModal();
            }
        }

        function showError(msg) {
            if (!msg) {
                errorEl.hidden = true;
                errorEl.textContent = '';
                return;
            }
            errorEl.hidden = false;
            errorEl.textContent = msg;
        }

        function renderResults(results) {
            list.innerHTML = '';
            if (!results.length) {
                var empty = document.createElement('div');
                empty.className = 'w3ds-modal-empty';
                empty.textContent = allProfiles.length === 0
                    ? 'No W3DS profiles available.'
                    : (input.value.trim() ? 'No matches.' : 'Showing all profiles.');
                list.appendChild(empty);
                if (allProfiles.length > 0 && !input.value.trim()) {
                    // fall through to render full list
                } else {
                    return;
                }
            }
            results.forEach(function (r) {
                var row = document.createElement('div');
                row.className = 'w3ds-modal-row';
                var checked = !!selected[r.w3id];
                row.innerHTML = [
                    '<input type="checkbox" ' + (checked ? 'checked' : '') + ' />',
                    '<label>',
                    '<span class="name"></span>',
                    '<span class="meta"></span>',
                    '</label>'
                ].join('');
                row.querySelector('.name').textContent = r.displayName;
                var metaParts = [];
                metaParts.push(r.w3id);
                if (r.existingUid) metaParts.push('(linked)');
                row.querySelector('.meta').textContent = metaParts.join(' · ');
                var cb = row.querySelector('input[type=checkbox]');
                row.addEventListener('click', function (ev) {
                    if (ev.target !== cb) cb.checked = !cb.checked;
                    if (cb.checked) {
                        selected[r.w3id] = r;
                    } else {
                        delete selected[r.w3id];
                    }
                    updateAddBtn();
                });
                list.appendChild(row);
            });
        }

        function updateAddBtn() {
            var n = Object.keys(selected).length;
            addBtn.disabled = n === 0;
            addBtn.textContent = 'Add ' + n;
        }

        function applyFilter() {
            var needle = input.value.trim().toLowerCase();
            if (!needle) {
                renderResults(allProfiles);
                return;
            }
            var filtered = allProfiles.filter(function (p) {
                return (
                    (p.displayName && p.displayName.toLowerCase().indexOf(needle) !== -1)
                    || (p.username && p.username.toLowerCase().indexOf(needle) !== -1)
                    || (p.w3id && p.w3id.toLowerCase().indexOf(needle) !== -1)
                );
            });
            renderResults(filtered);
        }

        input.addEventListener('input', applyFilter);

        addBtn.addEventListener('click', function () {
            var w3ids = Object.keys(selected);
            if (!w3ids.length) return;
            addBtn.disabled = true;
            addBtn.textContent = 'Adding...';
            var url = ocBase() + '/api/contacts/add-to-room';
            var xhr = new XMLHttpRequest();
            xhr.open('POST', url, true);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('requesttoken', requestToken());
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function () {
                if (xhr.status >= 400) {
                    showError('Add failed (' + xhr.status + ')');
                    updateAddBtn();
                    return;
                }
                closeModal();
                setTimeout(function () {
                    window.dispatchEvent(new Event('hashchange'));
                }, 100);
            };
            xhr.onerror = function () {
                showError('Network error');
                updateAddBtn();
            };
            xhr.send(JSON.stringify({ w3ids: w3ids, roomToken: roomToken }));
        });

        // Initial load: fetch all visible profiles once.
        list.innerHTML = '<div class="w3ds-modal-empty">Loading...</div>';
        var listUrl = ocBase() + '/api/contacts/list?roomToken=' + encodeURIComponent(roomToken);
        var loadXhr = new XMLHttpRequest();
        loadXhr.open('GET', listUrl, true);
        loadXhr.setRequestHeader('Accept', 'application/json');
        loadXhr.setRequestHeader('requesttoken', requestToken());
        loadXhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        loadXhr.onload = function () {
            if (loadXhr.status >= 400) {
                showError('Load failed (' + loadXhr.status + ')');
                renderResults([]);
                return;
            }
            try {
                var body = JSON.parse(loadXhr.responseText);
                if (body.reason === 'searcher-not-linked') {
                    showError('Link your W3DS identity in Personal Settings first.');
                }
                allProfiles = Array.isArray(body.results) ? body.results : [];
                renderResults(allProfiles);
                input.focus();
            } catch (e) {
                showError('Bad response from server');
                renderResults([]);
            }
        };
        loadXhr.onerror = function () { showError('Network error loading profiles'); };
        loadXhr.send();
    }

    function tick() {
        ensureButton();
    }

    function start() {
        tick();
        window.addEventListener('hashchange', tick);
        window.addEventListener('popstate', tick);
        setInterval(tick, 1000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
