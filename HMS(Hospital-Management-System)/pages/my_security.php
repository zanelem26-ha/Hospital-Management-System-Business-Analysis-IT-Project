<?php
/**
 * pages/my_security.php — User Security Settings
 *
 * GET : Display username card and password reset button.
 * POST action=change_username : Update username (must be valid email).
 * POST action=change_password : AJAX — verify old hash, write new hash, destroy session.
 *
 * Accessible by: all authenticated users.
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/audit.php';

require_login();

$db  = get_db();
$uid = current_user_id();

// AJAX: change password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    header('Content-Type: application/json');

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request token.']);
        exit;
    }

    $old_pass  = $_POST['old_password']     ?? '';
    $new_pass  = $_POST['new_password']     ?? '';
    $conf_pass = $_POST['confirm_password'] ?? '';

    $s = $db->prepare('SELECT password_hash FROM users WHERE user_id = ? LIMIT 1');
    $s->execute([$uid]);
    $row = $s->fetch(PDO::FETCH_ASSOC);

    if (!$row || !password_verify($old_pass, $row['password_hash'])) {
        echo json_encode(['ok' => false, 'error' => 'Current password is incorrect.']);
        exit;
    }
    if (strlen($new_pass) < 10
        || !preg_match('/[A-Z]/', $new_pass)
        || !preg_match('/[a-z]/', $new_pass)
        || !preg_match('/[0-9]/', $new_pass)
        || !preg_match('/[^a-zA-Z0-9]/', $new_pass)
    ) {
        echo json_encode(['ok' => false, 'error' => 'Password must be at least 10 characters and include an uppercase letter, a lowercase letter, a number, and a special character.']);
        exit;
    }
    if ($new_pass !== $conf_pass) {
        echo json_encode(['ok' => false, 'error' => 'New passwords do not match.']);
        exit;
    }
    if (password_verify($new_pass, $row['password_hash'])) {
        echo json_encode(['ok' => false, 'error' => 'New password must differ from your current one.']);
        exit;
    }

    // Update hash — old value is never persisted
    $s = $db->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
    $s->execute([password_hash($new_pass, PASSWORD_DEFAULT), $uid]);

    write_audit_log('UPDATE', 'users', $uid, null, ['action' => 'password_changed']);

    session_destroy();
    echo json_encode(['ok' => true]);
    exit;
}

// POST: change email (SuperAdmin only)
$email_errors    = [];
$show_email_form = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_email') {
    if (current_role() !== 'SuperAdmin') {
        flash_set('error', 'Access denied.');
        redirect('/pages/my_security.php');
    }
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        flash_set('error', 'Invalid request token.');
        redirect('/pages/my_security.php');
    }

    $new_email = trim($_POST['new_email'] ?? '');

    if ($new_email !== '' && !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $email_errors['new_email'] = 'Enter a valid email address.';
    } elseif ($new_email !== '') {
        $s = $db->prepare('SELECT user_id FROM users WHERE email = ? AND user_id != ? LIMIT 1');
        $s->execute([$new_email, $uid]);
        if ($s->fetch()) $email_errors['new_email'] = 'That email address is already in use.';
    }

    if (empty($email_errors)) {
        $s = $db->prepare('SELECT email FROM users WHERE user_id = ? LIMIT 1');
        $s->execute([$uid]);
        $old_email = $s->fetchColumn();

        $s = $db->prepare('UPDATE users SET email = ? WHERE user_id = ?');
        $s->execute([$new_email ?: null, $uid]);

        write_audit_log('UPDATE', 'users', $uid,
            ['email' => $old_email],
            ['email' => $new_email ?: null]
        );

        flash_set('success', 'Email address updated.');
        redirect('/pages/my_security.php');
    } else {
        $show_email_form = true;
    }
}

// POST: change username
$username_errors    = [];
$show_username_form = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_username') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        flash_set('error', 'Invalid request token.');
        redirect('/pages/my_security.php');
    }

    $new_username = trim($_POST['new_username'] ?? '');

    if ($new_username === '') {
        $username_errors['new_username'] = 'New username is required.';
    } elseif (strlen($new_username) > 50) {
        $username_errors['new_username'] = 'Username may not exceed 50 characters.';
    } else {
        $s = $db->prepare('SELECT user_id FROM users WHERE username = ? AND user_id != ? LIMIT 1');
        $s->execute([$new_username, $uid]);
        if ($s->fetch()) {
            $username_errors['new_username'] = 'That username is already in use.';
        }
    }

    if (empty($username_errors)) {
        $old_username = current_username();
        $s = $db->prepare('UPDATE users SET username = ? WHERE user_id = ?');
        $s->execute([$new_username, $uid]);

        write_audit_log('UPDATE', 'users', $uid,
            ['username' => $old_username],
            ['username' => $new_username]
        );

        $_SESSION['username'] = $new_username;
        flash_set('success', 'Username updated successfully.');
        redirect('/pages/my_security.php');
    } else {
        $show_username_form = true;
    }
}

// Fetch current user record
$s = $db->prepare('SELECT username, email, full_name FROM users WHERE user_id = ? LIMIT 1');
$s->execute([$uid]);
$user = $s->fetch(PDO::FETCH_ASSOC);

// Fetch practice number for Doctor / Nurse
$practice_number = null;
if (current_role() === 'Doctor') {
    $s = $db->prepare('SELECT practice_number FROM doctors WHERE user_id = ? LIMIT 1');
    $s->execute([$uid]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    $practice_number = $row['practice_number'] ?? null;
} elseif (current_role() === 'Nurse') {
    $s = $db->prepare('SELECT practice_number FROM nurses WHERE user_id = ? LIMIT 1');
    $s->execute([$uid]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    $practice_number = $row['practice_number'] ?? null;
}

$page_title = 'My Security';
require_once '../includes/header.php';
?>

<div class="page-header">
    <h1>&#128274; My Security</h1>
    <p>Manage your account security settings.</p>
</div>

<div style="display:grid;gap:24px;max-width:680px;">

    <!-- Username card -->
    <div class="card">
        <div class="card-header">
            <h2>Account Username</h2>
        </div>
        <div class="card-body">
            <p style="margin-bottom:16px;">
                <strong>Current username: </strong>
                <code style="background:var(--bg);padding:3px 10px;border-radius:6px;font-size:15px;">
                    <?= h($user['username']) ?>
                </code>
            </p>

            <form id="username-form" method="POST"
                  action="<?= BASE_URL ?>/pages/my_security.php"
                  style="<?= $show_username_form ? '' : 'display:none;' ?>"
                  data-validate novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="change_username">
                <div class="form-group" style="max-width:400px;">
                    <label for="new_username" class="required">New Username</label>
                    <input type="text" id="new_username" name="new_username" required
                           maxlength="50" placeholder="e.g. john_doe"
                           value="<?= h($_POST['new_username'] ?? '') ?>">
                    <span class="field-error"><?= h($username_errors['new_username'] ?? '') ?></span>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Save Username</button>
                    <button type="button" class="btn btn-outline"
                            onclick="toggleUsernameForm(false)">Cancel</button>
                </div>
            </form>

            <button id="edit-username-btn" type="button" class="btn btn-outline"
                    style="<?= $show_username_form ? 'display:none;' : '' ?>"
                    onclick="toggleUsernameForm(true)">
                &#9998;&nbsp;Change Username
            </button>

            <p style="margin-top:12px;font-size:13px;color:var(--text-muted);">
                Your new username will take effect on your next login.
            </p>
        </div>
    </div>

    <!-- Email address card -->
    <div class="card">
        <div class="card-header">
            <h2>Email Address</h2>
        </div>
        <div class="card-body">
            <p style="margin-bottom:16px;">
                <strong>Current email: </strong>
                <?php if (!empty($user['email'])): ?>
                    <code style="background:var(--bg);padding:3px 10px;border-radius:6px;font-size:15px;">
                        <?= h($user['email']) ?>
                    </code>
                <?php else: ?>
                    <span style="color:var(--text-muted);font-style:italic;">Not set</span>
                <?php endif; ?>
            </p>

            <?php if (current_role() === 'SuperAdmin'): ?>

                <form id="email-form" method="POST"
                      action="<?= BASE_URL ?>/pages/my_security.php"
                      style="<?= $show_email_form ? '' : 'display:none;' ?>"
                      data-validate novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_email">
                    <div class="form-group" style="max-width:400px;">
                        <label for="new_email" class="required">New Email Address</label>
                        <input type="email" id="new_email" name="new_email" required
                               maxlength="100" placeholder="yourname@example.com"
                               value="<?= h($_POST['new_email'] ?? $user['email'] ?? '') ?>">
                        <span class="field-error"><?= h($email_errors['new_email'] ?? '') ?></span>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Save Email</button>
                        <button type="button" class="btn btn-outline"
                                onclick="toggleEmailForm(false)">Cancel</button>
                    </div>
                </form>

                <button id="edit-email-btn" type="button" class="btn btn-outline"
                        style="<?= $show_email_form ? 'display:none;' : '' ?>"
                        onclick="toggleEmailForm(true)">
                    &#9998;&nbsp;Change Email
                </button>

            <?php else: ?>
                <p style="font-size:13px;color:var(--text-muted);">
                    Contact a SuperAdmin to update your email address.
                </p>
            <?php endif; ?>

            <p style="margin-top:12px;font-size:13px;color:var(--text-muted);">
                You can log in using either your username or email address.
            </p>
        </div>
    </div>

    <!-- Password card -->
    <div class="card">
        <div class="card-header">
            <h2>Password</h2>
        </div>
        <div class="card-body">
            <p style="margin-bottom:20px;color:var(--text-muted);">
                Use a strong password — min 10 characters with uppercase, lowercase, a number, and a special character.
                After a successful change you will be redirected to the login screen.
            </p>
            <button type="button" class="btn btn-primary" onclick="openPasswordModal()">
                &#128272;&nbsp;Reset Password
            </button>
        </div>
    </div>

    <?php if (!is_null($practice_number)): ?>
    <!-- Practice Number card (read-only) -->
    <div class="card">
        <div class="card-header">
            <h2>Practice Number</h2>
        </div>
        <div class="card-body">
            <p style="margin-bottom:12px;">
                <strong>Practice Number: </strong>
                <code style="background:var(--bg);padding:3px 10px;border-radius:6px;font-size:15px;">
                    <?= h($practice_number) ?>
                </code>
            </p>
            <p style="font-size:13px;color:var(--text-muted);">
                Your practice number is managed by the system administrator and cannot be changed here.
            </p>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Password modal -->
<div id="pw-modal" class="modal-overlay" hidden aria-modal="true" role="dialog"
     aria-labelledby="pw-modal-title">
    <div class="modal-box">

        <div class="modal-header">
            <h3 id="pw-modal-title">&#128272; Reset Password</h3>
            <button class="modal-close" onclick="closePasswordModal()" aria-label="Close">&times;</button>
        </div>

        <!-- Form -->
        <div id="pw-form-wrap">
            <div class="modal-body">
                <div class="form-group">
                    <label for="old_password" class="required">Current Password</label>
                    <input type="password" id="old_password" required
                           autocomplete="current-password">
                    <span class="field-error" id="old_password_err"></span>
                </div>
                <div class="form-group">
                    <label for="new_password" class="required">New Password</label>
                    <input type="password" id="new_password" required
                           data-min-length="8" autocomplete="new-password">
                    <span class="field-hint">Min 10 characters · uppercase · lowercase · number · special character</span>
                    <span class="field-error" id="new_password_err"></span>
                </div>
                <div class="form-group">
                    <label for="confirm_password" class="required">Confirm New Password</label>
                    <input type="password" id="confirm_password" required
                           autocomplete="new-password">
                    <span class="field-error" id="confirm_password_err"></span>
                </div>
                <p id="pw-server-err" class="field-error" style="margin-top:10px;font-size:14px;"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline"
                        onclick="closePasswordModal()">Cancel</button>
                <button type="button" class="btn btn-primary" id="pw-update-btn"
                        onclick="submitPasswordChange()">Update Password</button>
            </div>
        </div>

        <!-- Success message (shown after update) -->
        <div id="pw-success-wrap" hidden class="modal-body"
             style="text-align:center;padding:36px 24px;">
            <div style="font-size:52px;margin-bottom:12px;">&#9989;</div>
            <h4 style="color:var(--success);margin-bottom:8px;font-size:18px;">Password Updated!</h4>
            <p style="color:var(--text-muted);">
                Redirecting to the login screen in
                <strong id="pw-countdown">5</strong> second(s)&hellip;
            </p>
        </div>

    </div>
</div>

<script>
var _pwCsrf = '<?= h(get_csrf_token()) ?>';

function toggleUsernameForm(show) {
    document.getElementById('username-form').style.display     = show ? '' : 'none';
    document.getElementById('edit-username-btn').style.display = show ? 'none' : '';
    if (show) document.getElementById('new_username').focus();
}

function toggleEmailForm(show) {
    var form = document.getElementById('email-form');
    var btn  = document.getElementById('edit-email-btn');
    if (form) form.style.display = show ? '' : 'none';
    if (btn)  btn.style.display  = show ? 'none' : '';
    if (show) { var el = document.getElementById('new_email'); if (el) el.focus(); }
}

function openPasswordModal() {
    ['old_password', 'new_password', 'confirm_password'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) { el.value = ''; el.classList.remove('is-invalid'); }
    });
    ['old_password_err', 'new_password_err', 'confirm_password_err'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.textContent = '';
    });
    document.getElementById('pw-server-err').textContent     = '';
    document.getElementById('pw-form-wrap').hidden            = false;
    document.getElementById('pw-success-wrap').hidden         = true;
    document.getElementById('pw-update-btn').disabled         = false;
    document.getElementById('pw-update-btn').textContent      = 'Update Password';
    document.getElementById('pw-modal').hidden                = false;
    document.getElementById('old_password').focus();
    if (typeof setRequiredTooltips === 'function') setRequiredTooltips();
}

function closePasswordModal() {
    document.getElementById('pw-modal').hidden = true;
}

// Close on backdrop click or Escape key
document.getElementById('pw-modal').addEventListener('click', function (e) {
    if (e.target === this) closePasswordModal();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !document.getElementById('pw-modal').hidden) closePasswordModal();
});

function submitPasswordChange() {
    var oldEl  = document.getElementById('old_password');
    var newEl  = document.getElementById('new_password');
    var confEl = document.getElementById('confirm_password');
    var valid  = true;

    ['old_password', 'new_password', 'confirm_password'].forEach(function (id) {
        document.getElementById(id).classList.remove('is-invalid');
    });
    ['old_password_err', 'new_password_err', 'confirm_password_err'].forEach(function (id) {
        document.getElementById(id).textContent = '';
    });
    document.getElementById('pw-server-err').textContent = '';

    function markErr(el, errId, msg) {
        el.classList.add('is-invalid');
        document.getElementById(errId).textContent = msg;
        if (valid) el.focus();
        valid = false;
    }

    if (!oldEl.value) markErr(oldEl, 'old_password_err', 'Current password is required.');

    if (newEl.value) {
        var missing = [];
        if (newEl.value.length < 10)            missing.push('at least 10 characters');
        if (!/[A-Z]/.test(newEl.value))          missing.push('an uppercase letter');
        if (!/[a-z]/.test(newEl.value))          missing.push('a lowercase letter');
        if (!/[0-9]/.test(newEl.value))          missing.push('a number');
        if (!/[^a-zA-Z0-9]/.test(newEl.value))   missing.push('a special character');
        if (missing.length) markErr(newEl, 'new_password_err', 'Password must contain ' + missing.join(', ') + '.');
    } else {
        markErr(newEl, 'new_password_err', 'New password is required.');
    }

    if (confEl.value !== newEl.value) markErr(confEl, 'confirm_password_err', 'Passwords do not match.');

    if (!valid) return;

    var btn = document.getElementById('pw-update-btn');
    btn.disabled    = true;
    btn.textContent = 'Updating…';

    var fd = new FormData();
    fd.append('action',           'change_password');
    fd.append('csrf_token',       _pwCsrf);
    fd.append('old_password',     oldEl.value);
    fd.append('new_password',     newEl.value);
    fd.append('confirm_password', confEl.value);

    fetch(window.HMS_BASE_URL + '/pages/my_security.php', {
        method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        btn.disabled    = false;
        btn.textContent = 'Update Password';

        if (data.ok) {
            document.getElementById('pw-form-wrap').hidden    = true;
            document.getElementById('pw-success-wrap').hidden = false;
            var secs = 5;
            document.getElementById('pw-countdown').textContent = secs;
            var timer = setInterval(function () {
                secs--;
                var el = document.getElementById('pw-countdown');
                if (el) el.textContent = secs;
                if (secs <= 0) {
                    clearInterval(timer);
                    window.location.href = window.HMS_BASE_URL + '/index.php';
                }
            }, 1000);
        } else {
            document.getElementById('pw-server-err').textContent = data.error || 'An error occurred.';
        }
    })
    .catch(function () {
        btn.disabled    = false;
        btn.textContent = 'Update Password';
        document.getElementById('pw-server-err').textContent = 'Network error — please try again.';
    });
}
</script>

<?php require_once '../includes/footer.php'; ?>
