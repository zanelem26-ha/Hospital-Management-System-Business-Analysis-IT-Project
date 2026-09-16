/**
 * js/session_timeout.js — Warns the user shortly before their server-side
 * session expires from inactivity, and lets them extend it with one click
 * (session_keepalive.php) without needing to trigger a real DB action.
 *
 */
(function () {
    if (!window.HMS_SESSION_LIFETIME) return;

    var LIFETIME_MS   = window.HMS_SESSION_LIFETIME * 1000; // convert seconds to milliseconds
    var WARNING_MS    = Math.min(60000, LIFETIME_MS); // warn at 60s before expiry, or sooner if the session is shorter
    var KEEPALIVE_URL = (window.HMS_BASE_URL || '') + '/pages/session_keepalive.php';
    var LOGOUT_URL    = (window.HMS_BASE_URL || '') + '/pages/logout.php';
    var TIMEOUT_URL   = (window.HMS_BASE_URL || '') + '/index.php?timeout=1';

    var warnTimer, tickTimer, expiresAt;
    var modal, msgEl;

    function schedule() {
        clearTimeout(warnTimer);
        clearInterval(tickTimer);
        if (modal) modal.hidden = true;
        expiresAt = Date.now() + LIFETIME_MS;
        warnTimer = setTimeout(showWarning, LIFETIME_MS - WARNING_MS);
    }

    function showWarning() {
        modal = modal || document.getElementById('session-timeout-modal');
        msgEl = msgEl || document.getElementById('session-timeout-msg');
        if (!modal || !msgEl) return;
        modal.hidden = false;
        tick();
        tickTimer = setInterval(tick, 1000);
    }

    // Tick() updates the countdown message and checks for expiry. 
    // If the session has expired, it logs out the user and redirects to the timeout page.
    function tick() {
        var secondsLeft = Math.max(0, Math.round((expiresAt - Date.now()) / 1000));
        if (msgEl) {
            msgEl.textContent = 'The session will expire in ' + secondsLeft
                + ' seconds, click "Stay Online" to remain online or "Ignore" to dismiss this message.';
        }
        if (secondsLeft <= 0) {
            clearInterval(tickTimer);
            fetch(LOGOUT_URL, { credentials: 'same-origin' }).catch(function () {}).finally(function () {
                window.location.href = TIMEOUT_URL;
            });
        }
    }

    // Attempt to renew the session — Three possible paths:
    //  # server confirms renewal    -> resume the normal warn/expiry cycle
    //  # server confirms it's dead  -> actually log out
    //  # server unreachable         -> offline-first to keep user alive

    function renewOrDefer() {
        fetch(KEEPALIVE_URL, {
            method:      'POST',
            credentials: 'same-origin',
            headers:     { 'X-Sync-Token': window.HMS_SYNC_TOKEN || '' },
        }).then(function (res) {
            // require_login() redirects to index.php on a dead session; fetch()
            // follows that transparently, so res.ok alone would look like success.
            if (res.ok && !res.redirected) {
                schedule();
            } else {
                window.location.href = TIMEOUT_URL;
            }
        }).catch(function () {
            // Offline — can't confirm either way. Keep working locally and
            // check again on the next full session-lifetime window.
            if (modal) modal.hidden = true;
            warnTimer = setTimeout(renewOrDefer, LIFETIME_MS);
        });
    }

    // "Cancel" only hides the popup — the countdown keeps running in the
    // background toward the direct redirect in tick(), matching "cancel to ignore".
    window.dismissSessionTimeout = function () {
        if (modal) modal.hidden = true;
    };

    window.staySessionOnline = function () {
        clearTimeout(warnTimer);
        clearInterval(tickTimer);
        if (modal) modal.hidden = true;
        renewOrDefer();
    };

    document.addEventListener('DOMContentLoaded', schedule);
})();
