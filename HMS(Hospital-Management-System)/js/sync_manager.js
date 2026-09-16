/**
 * HMS Sync Manager — Offline-First IndexedDB ↔ Cloud DB
 *
 * Architecture (NFR-3 Reliability / Table 6-2 Offline Sync Data):
 *  1. Service Worker caches the app shell for offline navigation.
 *  2. When offline, form submissions are intercepted and stored in
 *     IndexedDB ('hms_offline' → 'pending_ops' store).
 *  3. On reconnect, all pending ops are flushed to /pages/sync_receive.php
 *     which writes them to MySQL and records each entry in sync_log.
 *  4. A topbar badge shows syncing status.
 *
 * Required globals injected by header.php:
 *   window.HMS_BASE_URL  — e.g. '/HMS'
 *   window.HMS_SYNC_TOKEN — per-session token for sync_receive.php auth
 *   window.HMS_ENV        — 'prod' or 'stage' (affects ping interval)
 */

'use strict';

// A validation-failure round trip on a data-offline-sync form re-renders the
// page via document.write(), which re-parses this same <script src> tag into
// the SAME global scope (document.write does not reset window). Guard against
// redeclaring HMSSyncManager so that replay is a no-op instead of a crash.
if (!window.HMSSyncManager) {
window.HMSSyncManager = (() => {

    // Config
    const DB_NAME        = 'hms_offline';
    const DB_VER         = 2;           // bumped: adds session + data_cache stores
    const STORE          = 'pending_ops';
    const SESSION_STORE  = 'session';
    const DATA_STORE     = 'data_cache';
    const SYNC_URL       = () => (window.HMS_BASE_URL || '') + '/pages/sync_receive.php';
    const SESSION_URL    = () => (window.HMS_BASE_URL || '') + '/pages/session_info.php';
    const SW_URL         = () => (window.HMS_BASE_URL || '') + '/js/service_worker.js';
    const SW_SCOPE       = () => (window.HMS_BASE_URL || '') + '/';

    // Pages pre-fetched into the SW cache so they are available offline
    // This defines which pages are usable offline — only pages that don't require a fresh network fetch
    const SHELL_PAGES = [
        '/pages/dashboard.php',
        '/pages/register_patient.php',
        '/pages/appointment_book.php',
        '/pages/appointment_list.php',
        '/pages/prescriptions.php',
    ];

    // Internal state
    let _db        = null;
    let _syncing   = false;
    let _connected = true; // tracks real connectivity — more reliable than navigator.onLine

    // IndexedDB 

    function _openDB() {
        return new Promise((resolve, reject) => {
            if (_db) { resolve(_db); return; }
            const req = indexedDB.open(DB_NAME, DB_VER);

            req.onupgradeneeded = (e) => {
                const db      = e.target.result;
                const oldVer  = e.oldVersion;

                // v1 → pending_ops store
                // (v1 schema: id, operation, table_name, fields, device_id, timestamp, status, error)
                if (oldVer < 1 && !db.objectStoreNames.contains(STORE)) {
                    const s = db.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
                    s.createIndex('by_status',    'status',    { unique: false });
                    s.createIndex('by_operation', 'operation', { unique: false });
                    s.createIndex('by_ts',        'timestamp', { unique: false });
                }

                // v2 → session cache store (key = 'current')
                if (oldVer < 2) {
                    if (!db.objectStoreNames.contains(SESSION_STORE)) {
                        db.createObjectStore(SESSION_STORE, { keyPath: 'key' });
                    }
                    // v2 → data cache store (key = endpoint path)
                    if (!db.objectStoreNames.contains(DATA_STORE)) {
                        const ds = db.createObjectStore(DATA_STORE, { keyPath: 'key' });
                        ds.createIndex('by_ts', 'cached_at', { unique: false });
                    }
                }
            };

            req.onsuccess = (e) => { _db = e.target.result; resolve(_db); };
            req.onerror   = (e) => reject(e.target.error);
        });
    }

    // Pending operations store
    function _addOp(op) {
        return _openDB().then(db => new Promise((resolve, reject) => {
            const tx  = db.transaction(STORE, 'readwrite');
            const req = tx.objectStore(STORE).add({
                operation:  op.operation,
                table_name: op.table_name,
                fields:     op.fields,
                device_id:  _deviceId(),
                timestamp:  Date.now(),
                status:     'pending',
                error:      null,
            });
            req.onsuccess = () => resolve(req.result);
            req.onerror   = ()  => reject(req.error);
        }));
    }

    function _getPending() {
        return _openDB().then(db => new Promise((resolve, reject) => {
            const tx  = db.transaction(STORE, 'readonly');
            const req = tx.objectStore(STORE).index('by_status').getAll('pending');
            req.onsuccess = () => resolve(req.result);
            req.onerror   = () => reject(req.error);
        }));
    }

    function _countPending() {
        return _openDB().then(db => new Promise((resolve, reject) => {
            const tx  = db.transaction(STORE, 'readonly');
            const req = tx.objectStore(STORE).index('by_status').count('pending');
            req.onsuccess = () => resolve(req.result);
            req.onerror   = () => reject(req.error);
        }));
    }

    function _setOpStatus(id, status, error) {
        return _openDB().then(db => new Promise((resolve, reject) => {
            const tx    = db.transaction(STORE, 'readwrite');
            const store = tx.objectStore(STORE);
            const get   = store.get(id);
            get.onsuccess = () => {
                if (!get.result) { resolve(); return; }
                const rec   = get.result;
                rec.status  = status;
                rec.error   = error || null;
                store.put(rec).onsuccess = () => resolve();
            };
            get.onerror = () => reject(get.error);
        }));
    }

    // Session cache
    async function _cacheSession() {
        if (!_connected) return;
        try {
            const res = await fetch(SESSION_URL(), { credentials: 'same-origin' });
            if (!res.ok) return;
            // SW may serve cached offline.html when session_info.php has no cache entry yet
            if (!(res.headers.get('content-type') || '').includes('json')) return;
            const data = await res.json();
            if (!data.authenticated) return;

            // Update HMS_SYNC_TOKEN in memory so flush() always has the latest
            window.HMS_SYNC_TOKEN = data.sync_token;

            const db = await _openDB();
            await new Promise((resolve, reject) => {
                const tx  = db.transaction(SESSION_STORE, 'readwrite');
                const req = tx.objectStore(SESSION_STORE).put({ key: 'current', ...data });
                req.onsuccess = () => resolve();
                req.onerror   = () => reject(req.error);
            });

            // Warm the page cache so key pages are usable offline
            _prefetchShell();
        } catch (e) {
            console.warn('[HMS Sync] session cache failed:', e);
        }
    }

    // Warm the SW page cache so key pages are available offline
    async function _prefetchShell() {
        if (!_connected || !('serviceWorker' in navigator)) return;
        try {
            // Wait until SW is controlling the page before fetching,
            // otherwise the SW won't intercept the requests and cache them
            await navigator.serviceWorker.ready;
            await Promise.all(
                SHELL_PAGES.map(path =>
                    fetch((window.HMS_BASE_URL || '') + path, { credentials: 'same-origin' })
                        .catch(() => {})
                )
            );
        } catch (e) { /* silent — prefetch is best-effort */ }
    }

    async function getCachedSession() {
        try {
            const db = await _openDB();
            return await new Promise((resolve, reject) => {
                const tx  = db.transaction(SESSION_STORE, 'readonly');
                const req = tx.objectStore(SESSION_STORE).get('current');
                req.onsuccess = () => resolve(req.result || null);
                req.onerror   = () => reject(req.error);
            });
        } catch (e) {
            return null;
        }
    }

    async function _isSessionValid() {
        const session = await getCachedSession();
        if (!session) return false;
        return session.expires_at > Math.floor(Date.now() / 1000);
    }

    // Data cache 

    /**
     * Fetch a JSON endpoint and cache the result in IndexedDB.
     * When offline, returns the last cached value.
     *
     * @param {string} path  e.g. '/HMS/pages/dashboard.php?json=1'
     * @returns {Promise<any|null>}
     */
    async function fetchWithCache(path) {
        if (navigator.onLine) {
            try {
                const res  = await fetch(path, { credentials: 'same-origin' });
                if (res.ok) {
                    const data = await res.json();
                    const db   = await _openDB();
                    await new Promise((resolve, reject) => {
                        const tx  = db.transaction(DATA_STORE, 'readwrite');
                        const req = tx.objectStore(DATA_STORE).put({
                            key:       path,
                            data:      data,
                            cached_at: Date.now(),
                        });
                        req.onsuccess = () => resolve();
                        req.onerror   = () => reject(req.error);
                    });
                    return data;
                }
            } catch (e) { /* fall through to cache */ }
        }

        // Offline — return last cached value
        try {
            const db = await _openDB();
            const row = await new Promise((resolve, reject) => {
                const tx  = db.transaction(DATA_STORE, 'readonly');
                const req = tx.objectStore(DATA_STORE).get(path);
                req.onsuccess = () => resolve(req.result || null);
                req.onerror   = () => reject(req.error);
            });
            return row ? row.data : null;
        } catch (e) {
            return null;
        }
    }

    // Device ID (stable per browser)

    function _deviceId() {
        let id = localStorage.getItem('hms_device_id');
        if (!id) {
            id = 'dev-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
            localStorage.setItem('hms_device_id', id);
        }
        return id;
    }

    // UI badge displayed on top right of page (sync status)

    async function _refreshBadge() {
        const badge = document.getElementById('sync-badge');
        if (!badge) return;

        let count = 0;
        try { count = await _countPending(); } catch (e) { /* ignore */ }

        badge.onclick = null;
        badge.style.cursor = 'default';

        if (!_connected) {
            badge.className = 'sync-badge sync-offline';
            badge.title     = 'Offline mode — changes are queued locally';
            badge.innerHTML = '&#9679; Offline' + (count > 0 ? ' (' + count + ' pending)' : ' — no connection');
        } else if (_syncing) {
            badge.className = 'sync-badge sync-syncing';
            badge.title     = 'Uploading offline changes to cloud database…';
            badge.innerHTML = '&#8635; Syncing…';
        } else if (count > 0) {
            badge.className    = 'sync-badge sync-pending';
            badge.title        = count + ' offline change(s) awaiting upload — click to sync now';
            badge.innerHTML    = '&#8679; ' + count + ' pending';
            badge.style.cursor = 'pointer';
            badge.onclick      = () => flush();
        } else {
            badge.className = 'sync-badge sync-ok';
            badge.title     = 'All changes synced to cloud database';
            badge.innerHTML = '&#10003; Synced — Online';
        }
    }

    // Sidebar offline UI — grays out nav items whose pages are not in SHELL_PAGES
    function _applyOfflineUI(connected) {
        document.querySelectorAll('.sidebar-nav .nav-item').forEach(link => {
            const href      = link.getAttribute('href') || '';
            const available = connected || SHELL_PAGES.some(p => href.includes(p));

            if (!available) {
                link.classList.add('nav-offline');
                // Preserve original title so it can be restored when back online
                if (!link.hasAttribute('data-orig-title')) {
                    link.setAttribute('data-orig-title', link.title || '');
                }
                link.title = 'Service unavailable offline — available when connection is restored';
            } else {
                link.classList.remove('nav-offline');
                if (link.hasAttribute('data-orig-title')) {
                    link.title = link.getAttribute('data-orig-title');
                    link.removeAttribute('data-orig-title');
                }
            }
        });
    }

    // Alert helper (reuses existing HMS .alert classes)

    function _notify(type, html) {
        const target = document.querySelector('.main-content') || document.body;
        const div    = document.createElement('div');
        div.className = 'alert alert-' + type;
        div.innerHTML = '<span>' + html + '</span>'
            + '<button class="alert-close" onclick="this.parentElement.remove()">&#215;</button>';
        target.prepend(div);
        setTimeout(() => { if (div.parentNode) div.remove(); }, 7000);
    }

    // Connectivity probe
    // navigator.onLine is unreliable under DevTools Network throttling — it stays
    // true even when all requests are blocked. A HEAD request to ping.php (which is
    // in NETWORK_FIRST so it always hits the real server) correctly detects offline
    // in both DevTools and production scenarios.
    async function _probeOnline() {
        try {
            const ctrl  = new AbortController();
            const timer = setTimeout(() => ctrl.abort(), 5000); // 5s timeout
            const res   = await fetch((window.HMS_BASE_URL || '') + '/pages/ping.php', {
                method:      'HEAD',
                credentials: 'same-origin',
                signal:      ctrl.signal,
            });
            clearTimeout(timer);
            return res.ok; // If 200 = server reachable; 503 = SW offline fallback
        } catch (_) {
            return false;  // network error or timeout
        }
    }

    // Form interception

    function _hookForms() {
        // Forms without offline support: probe before submit, show clear warning if offline
        document.querySelectorAll('form[data-validate]:not([data-offline-sync])').forEach(form => {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                e.stopImmediatePropagation();
                // Run validation manually — stopImmediatePropagation blocked main.js listener
                if (typeof validateForm === 'function' && !validateForm(form)) return;
                if (await _probeOnline()) {
                    form.submit(); // native submit bypasses our listener and goes to server
                } else {
                    _notify('warning',
                        '&#9888; This action requires a connection. '
                        + 'Reconnect and try again — your inputs have not been lost.'
                    );
                }
            }, true);
        });

        // Offline-capable forms: submit via fetch so network failure is caught and queued
        document.querySelectorAll('form[data-offline-sync]').forEach(form => {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                e.stopImmediatePropagation();
                // Run validation manually
                if (typeof validateForm === 'function' && !validateForm(form)) return;

                const [table, action] = (form.dataset.offlineSync || '/').split('/');
                if (!table || !action) {
                    _notify('error', 'Offline sync not configured for this form.');
                    return;
                }

                const formData = new FormData(form);

                try {
                    const ctrl  = new AbortController();
                    const timer = setTimeout(() => ctrl.abort(), 8000);
                    // SW does not intercept POST — throws directly when offline
                    const res = await fetch(window.location.href, {
                        method:      'POST',
                        body:        formData,
                        credentials: 'same-origin',
                        signal:      ctrl.signal,
                    });
                    clearTimeout(timer);

                    // Follow server's PRG redirect (success or error flash)
                    if (res.redirected) {
                        window.location.href = res.url;
                    } else {
                        // Server re-rendered the page inline (validation errors shown)
                        const html = await res.text();
                        document.open();
                        document.write(html);
                        document.close();
                    }

                } catch (_networkErr) {
                    // Network failure — save to IndexedDB for later sync
                    const fields = {};
                    formData.forEach((v, k) => {
                        if (k !== 'csrf_token' && k !== 'password_confirm') {
                            fields[k] = v;
                        }
                    });
                    try {
                        const opId = await _addOp({
                            operation:  table + '/' + action,
                            table_name: table,
                            fields,
                        });
                        await _refreshBadge();
                        _notify('warning',
                            '&#128190; Saved offline (queue #' + opId + '). '
                            + 'Will upload automatically when your connection is restored.'
                        );
                    } catch (queueErr) {
                        console.error('[HMS Sync] queue failed:', queueErr);
                        _notify('error', 'Could not save offline — please retry.');
                    }
                }
            }, true);
        });
    }

    // Sync flush 

    async function flush() {
        if (_syncing || !_connected) return;

        let pending;
        try { pending = await _getPending(); } catch (e) { return; }
        if (!pending.length) { await _refreshBadge(); return; }

        _syncing = true;
        await _refreshBadge();

        try {
            const res = await fetch(SYNC_URL(), {
                method:      'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type':     'application/json',
                    'X-Sync-Token':     window.HMS_SYNC_TOKEN || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ ops: pending }),
            });

            // The require_login() redirects to the login page when the session has expired.
            if (res.redirected) {
                _notify('warning',
                    'Your session expired while offline. Your changes are safely queued — '
                    + 'please <a href="' + (window.HMS_BASE_URL || '') + '/index.php">log back in</a> to sync them.'
                );
                return;
            }

            // If the server returns a non-200, throw an error to trigger the catch block
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();

            // Update each op in IndexedDB
            for (const r of (data.results || [])) {
                await _setOpStatus(r.id, r.success ? 'synced' : 'failed', r.error);
            }

            const remaining = await _countPending();

            // Show notifications for processed / failed ops
            if ((data.processed || 0) > 0) {
                _notify('success',
                    '&#10003; ' + data.processed + ' offline change(s) synced to the cloud database.'
                );
                if (remaining === 0) {
                    setTimeout(() => window.location.reload(), 1800);
                }
            }
            if ((data.failed || 0) > 0) {
                _notify('error',
                    '&#9888; ' + data.failed + ' change(s) failed to sync. '
                    + 'They remain queued — contact your system administrator if this persists.'
                );
            }

        } catch (err) {
            console.error('[HMS Sync] flush error:', err);
            _notify('warning', 'Sync attempt failed — will retry when online.');
        } finally {
            _syncing = false;
            await _refreshBadge();
        }
    }

    // Service Worker registration 
    function _registerSW() {
        if (!('serviceWorker' in navigator)) return;
        navigator.serviceWorker
            .register(SW_URL(), { scope: SW_SCOPE() })
            .catch(e => console.warn('[HMS Sync] SW register failed:', e));
    }

    // Connectivity state change handler — centralises all online/offline reactions
    async function _onConnectivityChange(nowOnline) {
        if (nowOnline === _connected) return; // no change
        _connected = nowOnline;
        _applyOfflineUI(_connected);
        await _refreshBadge();
        if (_connected) {
            await _cacheSession();
            const n = await _countPending();
            if (n > 0) setTimeout(flush, 1200);
        }
    }

    // Public init 

    async function init() {
        // Eagerly open IndexedDB
        try { await _openDB(); } catch (e) {
            console.warn('[HMS Sync] IndexedDB unavailable — offline mode disabled:', e);
            return;
        }

        _registerSW();
        _hookForms();

        // One-time: block clicks on grayed-out nav items while offline
        document.querySelectorAll('.sidebar-nav .nav-item').forEach(link => {
            link.addEventListener('click', e => {
                if (link.classList.contains('nav-offline')) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            });
        });

        // browser online/offline events (reliable for real network changes)
        window.addEventListener('online',  () => _onConnectivityChange(true));
        window.addEventListener('offline', () => _onConnectivityChange(false));

        // Probe interval: 120 s in production, 60 s in stage (ms)
        const _pingInterval = window.HMS_ENV === 'prod' ? 120_000 : 60_000;
        setInterval(async () => {
            const nowOnline = await _probeOnline();
            await _onConnectivityChange(nowOnline);
        }, _pingInterval);

        // Initial connectivity check and UI setup
        _connected = await _probeOnline();
        _applyOfflineUI(_connected);
        await _refreshBadge();
        if (_connected) {
            await _cacheSession();
            const n = await _countPending();
            if (n > 0) setTimeout(flush, 2500);
        }
    }

    return { init, flush, fetchWithCache, getCachedSession };
})();
}

// Bootstrap
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => window.HMSSyncManager.init());
} else {
    window.HMSSyncManager.init();
}
