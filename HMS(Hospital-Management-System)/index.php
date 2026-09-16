<?php
/**
 * index.php — Main Landing Page / Login page
 * If the user is already authenticated, redirect straight to the dashboard.
 * Otherwise, render the login form.
 */

require_once 'includes/auth.php';

// Already logged in → go to dashboard
if (is_logged_in()) {
    redirect('/pages/dashboard.php');
}

$error   = flash_get('error');
$timeout = isset($_GET['timeout']) && $_GET['timeout'] === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/style.css">
</head>
<body>

<div class="login-page">
    <div class="login-box">

        <div class="login-logo">
            <span><img src="<?= BASE_URL ?>/img/hms-logo.png" alt="<?= APP_SUBTITLE ?> Logo"></span>
            <h1><?= APP_NAME ?></h1>
            <p>Sign in to your profile</p>
        </div>

        <!-- Display session timeout or error messages -->
        <?php if ($timeout): ?>
        <div class="alert alert-warning" role="alert">
            <span>Your log in session timed out, please log in again.</span>
            <button class="alert-close" onclick="this.parentElement.remove()">&#215;</button>
        </div>
        <?php endif; ?>

        <!-- Display any flash error messages -->
        <?php if ($error): ?>
        <div class="alert alert-error" role="alert">
            <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
            <button class="alert-close" onclick="this.parentElement.remove()">&#215;</button>
        </div>
        <?php endif; ?>

        <!-- Login form -->
        <form class="login-form" method="POST" action="<?= BASE_URL ?>/pages/login.php"
              data-validate novalidate>
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="username" class="required">Username or Email</label>
                <input type="text" id="username" name="username"
                       autocomplete="username" required
                       placeholder="Enter your username or email"
                       value="<?= h($_POST['username'] ?? '') ?>">
                <span class="field-error"></span>
            </div>

            <div class="form-group">
                <label for="password-login" class="required">Password</label>
                <input type="password" id="password-login" name="password-login"
                       autocomplete="current-password" required
                       data-min-length="6"
                       placeholder="Enter your password">
                <span class="field-error"></span>
            </div>

            <button type="submit" class="btn btn-primary login-btn">Sign In</button>

            <div style="margin-top: 10px;">
                <button type="button" class="btn-link forgot-password-link" onclick="openForgotPasswordModal()">Forgot password?</button>
                <span> | </span>
                <button type="button" class="btn-link forgot-username-link" onclick="openForgotUsernameModal()">Forgot username?</button>
            </div>
        </form>

        <div class="login-footer">
            &copy; <?= date('Y') ?> <?= APP_NAME ?>
        </div>

    </div>
</div>

<!-- Forgot password modal -->
<div id="fp-modal" class="modal-overlay" hidden aria-modal="true" role="dialog" aria-labelledby="fp-modal-title">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="fp-modal-title">&#128272; &nbsp;Reset Password</h3>
            <button class="modal-close" onclick="closeForgotPasswordModal()" aria-label="Close">&times;</button>
        </div>

        <!-- Step 1: identify the account -->
        <div id="fp-step-verify">
            <div class="modal-body">
                <p id="fp-verify-err" class="field-error"></p>

                <div class="form-group">
                    <label for="fp_role" class="required">Role</label>
                    <select id="fp_role" required onchange="fpOnRoleChange()">
                        <option value="">— Select —</option>
                        <?php foreach (ROLES as $r): ?>
                        <option value="<?= h($r) ?>"><?= h($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field-error" id="fp_role_err"></span>
                </div>

                <div class="form-group" id="fp-id-number-group" hidden>
                    <label for="fp_id_number" class="required">ID Number</label>
                    <input type="text" id="fp_id_number" inputmode="numeric" maxlength="13" placeholder="13-digit SA ID">
                    <span class="field-error" id="fp_id_number_err"></span>
                </div>

                <div class="form-group" id="fp-username-group" hidden>
                    <label for="fp_username" class="required">Username or Email</label>
                    <input type="text" id="fp_username" autocomplete="username" placeholder="Enter your username or email">
                    <span class="field-error" id="fp_username_err"></span>
                </div>

                <div>
                    <button type="button" class="btn-link" onclick="fpGoToForgotUsername()">Forgot username?</button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeForgotPasswordModal()">Cancel</button>
                <button type="button" class="btn btn-primary" id="fp-verify-btn" onclick="fpSubmitVerify()">Verify</button>
            </div>
        </div>

        <!-- Step 2: set a new password -->
        <div id="fp-step-reset" hidden>
            <div class="modal-body">
                <p id="fp-greeting" class="field-hint"></p>
                <p id="fp-reset-err" class="field-error"></p>

                <div class="form-group">
                    <label for="fp_new_password" class="required">New Password</label>
                    <input type="password" id="fp_new_password" autocomplete="new-password" data-min-length="10">
                    <span class="field-hint">Min 10 characters &middot; uppercase &middot; lowercase &middot; number &middot; special character</span>
                    <span class="field-error" id="fp_new_password_err"></span>
                </div>
                <div class="form-group">
                    <label for="fp_confirm_password" class="required">Confirm Password</label>
                    <input type="password" id="fp_confirm_password" autocomplete="new-password">
                    <span class="field-error" id="fp_confirm_password_err"></span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeForgotPasswordModal()">Cancel</button>
                <button type="button" class="btn btn-primary" id="fp-reset-btn" onclick="fpSubmitReset()">OK</button>
            </div>
        </div>

        <!-- Step 3: success -->
        <div id="fp-step-success" hidden>
            <div class="modal-body">
                <p>&#9989; Password updated. You can now log in with your new password.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="closeForgotPasswordModal()">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Forgot username modal -->
<div id="forg-user-modal" class="modal-overlay" hidden aria-modal="true" role="dialog" aria-labelledby="forg-user-modal-title">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="forg-user-modal-title">&#128100; &nbsp;Find Username</h3>
            <button class="modal-close" onclick="closeForgotUsernameModal()" aria-label="Close">&times;</button>
        </div>

        <!-- Step 1: look up the account -->
        <div id="forg-user-step-lookup">
            <div class="modal-body">
                <p id="forg-user-lookup-err" class="field-error"></p>

                <div class="form-group">
                    <label for="forg_user_role" class="required">Select User Type/Role</label>
                    <select id="forg_user_role" required onchange="forgUserOnRoleChange()">
                        <option value="">— Select —</option>
                        <?php foreach (ROLES as $r): if ($r === 'Pharmacist') continue; ?>
                        <option value="<?= h($r) ?>"><?= h($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field-error" id="forg_user_role_err"></span>
                </div>

                <div class="form-group" id="forg-user-value-group" hidden>
                    <label for="forg_user_value" class="required" id="forg_user_value_label">Value</label>
                    <input type="text" id="forg_user_value">
                    <span class="field-error" id="forg_user_value_err"></span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeForgotUsernameModal()">Cancel</button>
                <button type="button" class="btn btn-primary" id="forg-user-lookup-btn" onclick="forgUserSubmitLookup()">Confirm</button>
            </div>
        </div>

        <!-- Step 2: result -->
        <div id="forg-user-step-result" hidden>
            <div class="modal-body">
                <p>&#9989; Your username is: <strong id="forg-user-result-username"></strong></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeForgotUsernameModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="forgUserGoToForgotPassword()">Forgot password?</button>
            </div>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>/js/main.js"></script>
<script>
var _fpCsrf = '<?= get_csrf_token() ?>';

function openForgotPasswordModal() {
    document.getElementById('fp_role').value = '';
    ['fp_id_number', 'fp_username'].forEach(function (id) {
        document.getElementById(id).value = '';
    });
    fpOnRoleChange();
    fpClearErrors();
    document.getElementById('fp-step-verify').hidden  = false;
    document.getElementById('fp-step-reset').hidden   = true;
    document.getElementById('fp-step-success').hidden = true;
    document.getElementById('fp-modal').hidden = false;
}

function closeForgotPasswordModal() {
    document.getElementById('fp-modal').hidden = true;
}

// Bridge to the "Forgot username?" modal, with that modal's own fields reset
// (openForgotUsernameModal() already clears them on every open).
function fpGoToForgotUsername() {
    closeForgotPasswordModal();
    openForgotUsernameModal();
}

document.getElementById('fp-modal').addEventListener('click', function (e) {
    if (e.target === this) closeForgotPasswordModal();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !document.getElementById('fp-modal').hidden) closeForgotPasswordModal();
});

function fpClearErrors() {
    ['fp-verify-err', 'fp-reset-err', 'fp_role_err', 'fp_id_number_err',
     'fp_username_err', 'fp_new_password_err', 'fp_confirm_password_err'
    ].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.textContent = '';
    });
    ['fp_role', 'fp_id_number', 'fp_username', 'fp_new_password', 'fp_confirm_password'
    ].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.classList.remove('is-invalid');
    });
}

// Patient -> SA ID required, username hidden. Any staff role -> username
// (accepts username or email, same as the main login field) required, SA ID hidden.
function fpOnRoleChange() {
    var role      = document.getElementById('fp_role').value;
    var idGroup   = document.getElementById('fp-id-number-group');
    var userGroup = document.getElementById('fp-username-group');
    var idInput   = document.getElementById('fp_id_number');
    var userInput = document.getElementById('fp_username');

    idGroup.hidden   = true;
    userGroup.hidden = true;
    idInput.required   = false;
    userInput.required = false;

    switch (role) {
        case 'Patient':
            idGroup.hidden    = false;
            idInput.required  = true;
            break;
        case 'Doctor':
        case 'Nurse':
        case 'Admin':
        case 'SuperAdmin':
            userGroup.hidden    = false;
            userInput.required  = true;
            break;
        default:
            break; // no role selected yet — both stay hidden
    }
}

function fpSubmitVerify() {
    fpClearErrors();
    var role = document.getElementById('fp_role');
    var valid = true;

    function markErr(el, errId, msg) {
        el.classList.add('is-invalid');
        document.getElementById(errId).textContent = msg;
        valid = false;
    }

    if (!role.value) markErr(role, 'fp_role_err', 'Please select a role.');

    var fd = new FormData();
    fd.append('action',     'verify');
    fd.append('csrf_token', _fpCsrf);
    fd.append('role',       role.value);

    switch (role.value) {
        case 'Patient': {
            // Lightweight shape check only, matching forgot_password.php: this
            // is looking up an existing record, not validating new input, so
            // it deliberately skips the full validateSaId() Luhn/structure
            // check — older records can have an id_number on file that never
            // passed it.
            var idEl = document.getElementById('fp_id_number');
            if (!idEl.value) {
                markErr(idEl, 'fp_id_number_err', 'ID number is required.');
            } else if (!/^\d{13}$/.test(idEl.value)) {
                markErr(idEl, 'fp_id_number_err', 'ID number must be exactly 13 digits.');
            }
            fd.append('id_number', idEl.value);
            break;
        }
        case 'Doctor':
        case 'Nurse':
        case 'Admin':
        case 'SuperAdmin': {
            var userEl = document.getElementById('fp_username');
            if (!userEl.value) markErr(userEl, 'fp_username_err', 'Username or email is required.');
            fd.append('username', userEl.value);
            break;
        }
        default:
            break;
    }

    if (!valid) return;

    var btn = document.getElementById('fp-verify-btn');
    btn.disabled = true;
    btn.textContent = 'Verifying…';

    fetch('<?= BASE_URL ?>/pages/forgot_password.php', {
        method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        btn.disabled = false;
        btn.textContent = 'Verify';

        if (data.ok) {
            document.getElementById('fp-greeting').textContent = 'Hi ' + data.first_name + ', set your new password below.';
            document.getElementById('fp_new_password').value = '';
            document.getElementById('fp_confirm_password').value = '';
            document.getElementById('fp-step-verify').hidden = true;
            document.getElementById('fp-step-reset').hidden  = false;
            document.getElementById('fp_new_password').focus();
        } else {
            document.getElementById('fp-verify-err').textContent = data.error || 'Verification failed.';
        }
    })
    .catch(function () {
        btn.disabled = false;
        btn.textContent = 'Verify';
        document.getElementById('fp-verify-err').textContent = 'Network error — please try again.';
    });
}

function fpSubmitReset() {
    var newEl  = document.getElementById('fp_new_password');
    var confEl = document.getElementById('fp_confirm_password');
    var valid  = true;

    ['fp_new_password_err', 'fp_confirm_password_err', 'fp-reset-err'].forEach(function (id) {
        document.getElementById(id).textContent = '';
    });
    [newEl, confEl].forEach(function (el) { el.classList.remove('is-invalid'); });

    function markErr(el, errId, msg) {
        el.classList.add('is-invalid');
        document.getElementById(errId).textContent = msg;
        valid = false;
    }

    if (newEl.value) {
        var missing = [];
        if (newEl.value.length < 10)           missing.push('at least 10 characters');
        if (!/[A-Z]/.test(newEl.value))         missing.push('an uppercase letter');
        if (!/[a-z]/.test(newEl.value))         missing.push('a lowercase letter');
        if (!/[0-9]/.test(newEl.value))         missing.push('a number');
        if (!/[^a-zA-Z0-9]/.test(newEl.value))  missing.push('a special character');
        if (missing.length) markErr(newEl, 'fp_new_password_err', 'Password must contain ' + missing.join(', ') + '.');
    } else {
        markErr(newEl, 'fp_new_password_err', 'New password is required.');
    }

    if (confEl.value !== newEl.value) markErr(confEl, 'fp_confirm_password_err', 'Passwords do not match.');

    if (!valid) return;

    var btn = document.getElementById('fp-reset-btn');
    btn.disabled = true;
    btn.textContent = 'Updating…';

    var fd = new FormData();
    fd.append('action',           'reset_password');
    fd.append('csrf_token',       _fpCsrf);
    fd.append('new_password',     newEl.value);
    fd.append('confirm_password', confEl.value);

    fetch('<?= BASE_URL ?>/pages/forgot_password.php', {
        method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        btn.disabled = false;
        btn.textContent = 'OK';

        if (data.ok) {
            document.getElementById('fp-step-reset').hidden   = true;
            document.getElementById('fp-step-success').hidden = false;
        } else if (data.restart) {
            document.getElementById('fp-reset-err').textContent = data.error || 'Please start again.';
            setTimeout(openForgotPasswordModal, 1500);
        } else if (data.clear) {
            document.getElementById('fp-reset-err').textContent = data.error || 'Please choose a different password.';
            newEl.value = '';
            confEl.value = '';
            newEl.focus();
        } else {
            document.getElementById('fp-reset-err').textContent = data.error || 'An error occurred.';
        }
    })
    .catch(function () {
        btn.disabled = false;
        btn.textContent = 'OK';
        document.getElementById('fp-reset-err').textContent = 'Network error — please try again.';
    });
}

// Forgot username

// kind: 'sa-id' (13-digit numeric), 'alphanumeric' (letters+digits only), or 'text' (free text, length-capped only)
function forgUserRoleConfig(role) {
    switch (role) {
        case 'Patient':
            return { label: 'Enter your SA ID Number', type: 'text', inputmode: 'numeric', maxlength: 13, kind: 'sa-id' };
        case 'Doctor':
            return { label: 'Practice Number', type: 'text', inputmode: '', maxlength: 20, kind: 'alphanumeric' };
        case 'Nurse':
            return { label: 'SANC Number', type: 'text', inputmode: '', maxlength: 20, kind: 'alphanumeric' };
        case 'Admin':
        case 'SuperAdmin':
            return { label: 'Email Address', type: 'text', inputmode: '', maxlength: 60, kind: 'text' };
        default:
            return null;
    }
}

function openForgotUsernameModal() {
    document.getElementById('forg_user_role').value = '';
    document.getElementById('forg_user_value').value = '';
    forgUserOnRoleChange();
    forgUserClearErrors();
    document.getElementById('forg-user-step-lookup').hidden = false;
    document.getElementById('forg-user-step-result').hidden  = true;
    document.getElementById('forg-user-modal').hidden = false;
}

function closeForgotUsernameModal() {
    document.getElementById('forg-user-modal').hidden = true;
}

// Bridge from the "username found" result straight into the reset-password
// flow, with that modal's fields reset (openForgotPasswordModal() already
// clears them on every open).
function forgUserGoToForgotPassword() {
    closeForgotUsernameModal();
    openForgotPasswordModal();
}

document.getElementById('forg-user-modal').addEventListener('click', function (e) {
    if (e.target === this) closeForgotUsernameModal();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !document.getElementById('forg-user-modal').hidden) closeForgotUsernameModal();
});

function forgUserClearErrors() {
    ['forg-user-lookup-err', 'forg_user_role_err', 'forg_user_value_err'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.textContent = '';
    });
    ['forg_user_role', 'forg_user_value'].forEach(function (id) {
        document.getElementById(id).classList.remove('is-invalid');
    });
}

function forgUserOnRoleChange() {
    var role = document.getElementById('forg_user_role').value;
    var cfg  = forgUserRoleConfig(role);
    var valueEl = document.getElementById('forg_user_value');

    document.getElementById('forg-user-value-group').hidden = !cfg;
    valueEl.required = !!cfg;
    if (!cfg) return;

    document.getElementById('forg_user_value_label').textContent = cfg.label;
    valueEl.type = cfg.type;
    valueEl.value = '';
    if (cfg.inputmode) { valueEl.setAttribute('inputmode', cfg.inputmode); } else { valueEl.removeAttribute('inputmode'); }
    if (cfg.maxlength) { valueEl.setAttribute('maxlength', cfg.maxlength); } else { valueEl.removeAttribute('maxlength'); }
}

function forgUserSubmitLookup() {
    forgUserClearErrors();
    var role  = document.getElementById('forg_user_role');
    var value = document.getElementById('forg_user_value');
    var valid = true;

    function markErr(el, errId, msg) {
        el.classList.add('is-invalid');
        document.getElementById(errId).textContent = msg;
        valid = false;
    }

    var cfg = forgUserRoleConfig(role.value);

    if (!role.value) {
        markErr(role, 'forg_user_role_err', 'Please select a role.');
    } else if (!value.value) {
        markErr(value, 'forg_user_value_err', 'This field is required.');
    } else if (cfg.kind === 'sa-id' && !/^\d{13}$/.test(value.value)) {
        markErr(value, 'forg_user_value_err', 'ID number must be exactly 13 digits.');
    } else if (cfg.kind === 'alphanumeric' && !/^[a-zA-Z0-9]{1,20}$/.test(value.value)) {
        markErr(value, 'forg_user_value_err', cfg.label + ' must be letters and numbers only, up to 20 characters.');
    } else if (cfg.kind === 'text' && value.value.length > 60) {
        markErr(value, 'forg_user_value_err', cfg.label + ' must be at most 60 characters.');
    }

    if (!valid) return;

    var btn = document.getElementById('forg-user-lookup-btn');
    btn.disabled = true;
    btn.textContent = 'Searching…';

    var fd = new FormData();
    fd.append('action',     'lookup');
    fd.append('csrf_token', _fpCsrf);
    fd.append('role',       role.value);
    fd.append('value',      value.value);

    fetch('<?= BASE_URL ?>/pages/forgot_username.php', {
        method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        btn.disabled = false;
        btn.textContent = 'Confirm';

        if (data.ok) {
            document.getElementById('forg-user-result-username').textContent = data.username;
            document.getElementById('forg-user-step-lookup').hidden = true;
            document.getElementById('forg-user-step-result').hidden = false;
        } else {
            document.getElementById('forg-user-lookup-err').textContent = data.error || 'Lookup failed.';
        }
    })
    .catch(function () {
        btn.disabled = false;
        btn.textContent = 'Confirm';
        document.getElementById('forg-user-lookup-err').textContent = 'Network error — please try again.';
    });
}
</script>
</body>
</html>
