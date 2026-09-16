<?php
/**
 * php/staff_profiles.php — FR-06: Doctor & Nurse profile management
 *
 * SuperAdmin : full CRUD — create/edit/deactivate doctors and nurses.
 * Admin      : view-only.
 *
 * Creating a Doctor / Nurse provisions three records in a transaction:
 *   1. users  (role = Doctor | Nurse)
 *   2. doctors | nurses
 *   3. staff  (links the two)
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/audit.php';

require_login();
require_role(['SuperAdmin', 'Admin']);

$page_title = 'Staff Profiles';
$db         = get_db();
$role       = current_role();
$can_write  = ($role === 'SuperAdmin');
$errors     = [];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        flash_set('error', 'Invalid request. Please try again.');
        redirect('/pages/staff_profiles.php');
    }

    if (!$can_write) {
        flash_set('error', 'Only SuperAdmin can manage staff profiles.');
        redirect('/pages/staff_profiles.php');
    }

    $action = trim($_POST['action'] ?? '');

    // Helper: common inputs & validation
    $first_name  = trim($_POST['first_name']  ?? '');
    $last_name   = trim($_POST['last_name']   ?? '');
    $email       = trim($_POST['email']       ?? '');
    $phone       = trim($_POST['phone']       ?? '');
    $license     = trim($_POST['license']     ?? '');
    $department  = trim($_POST['department']  ?? '');
    $position    = trim($_POST['position']    ?? '');
    $username    = trim($_POST['username']    ?? '');
    $password    = $_POST['password']         ?? '';
    $password_cf = $_POST['password_confirm'] ?? '';
    $staff_type  = trim($_POST['staff_type']  ?? '');   // 'doctor' | 'nurse'
    $specialization  = trim($_POST['specialization']  ?? '');
    $practice_number = trim($_POST['practice_number'] ?? '');

    // Create staff member (doctor or nurse)
    if ($action === 'create') {

        if (!in_array($staff_type, ['doctor','nurse'], true)) {
            flash_set('error', 'Invalid staff type.');
            redirect('/pages/staff_profiles.php');
        }

        if ($first_name  === '') $errors['first_name']  = 'First name is required.';
        if ($last_name   === '') $errors['last_name']   = 'Last name is required.';
        if ($username    === '') $errors['username']    = 'Username is required.';
        if ($license     === '') $errors['license']     = 'License number is required.';
        if ($practice_number === '') $errors['practice_number'] = 'Practice number is required.';

        // Require specialization for doctors
        if ($staff_type === 'doctor' && $specialization === '') {
            $errors['specialization'] = 'Specialization is required for doctors.';
        }

        // Require department for nurses
        if ($staff_type === 'nurse' && $department === '') {
            $errors['department'] = 'Department is required for nurses.';
        }

        if ($password    === '') {
            $errors['password'] = 'Password is required.';
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        } elseif ($password !== $password_cf) {
            $errors['password_confirm'] = 'Passwords do not match.';
        }
        if ($email === '') {
            $errors['email'] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($phone !== '' && !preg_match('/^0\d{9}$/', $phone)) {
            $errors['phone'] = 'Phone must be 10 digits and start with 0.';
        }

        // Username uniqueness
        if (!isset($errors['username'])) {
            $s = $db->prepare('SELECT user_id FROM users WHERE username = ? LIMIT 1');
            $s->execute([$username]);
            if ($s->fetch()) $errors['username'] = 'Username is already taken.';
        }
        // Email uniqueness
        if ($email !== '' && !isset($errors['email'])) {
            $s = $db->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
            $s->execute([$email]);
            if ($s->fetch()) $errors['email'] = 'This email is already registered.';
        }

        // Practice number: required, alphanumeric, unique within table
        if ($practice_number === '') {
            $errors['practice_number'] = 'Practice number is required.';
        } elseif (!preg_match('/^[a-zA-Z0-9]+$/', $practice_number)) {
            $errors['practice_number'] = 'Practice number must be alphanumeric (letters and numbers only).';
        } else {
            $ptbl = $staff_type === 'doctor' ? 'doctors' : 'nurses';
            $s = $db->prepare("SELECT 1 FROM $ptbl WHERE practice_number = ? LIMIT 1");
            $s->execute([$practice_number]);
            if ($s->fetch()) $errors['practice_number'] = 'This practice number is already registered.';
        }

        if (empty($errors)) {
            try {
                $db->beginTransaction();

                $db_role = ($staff_type === 'doctor') ? 'Doctor' : 'Nurse';

                // 1. Create user account
                $db->prepare(
                    'INSERT INTO users (username, password_hash, role, email, full_name, is_active)
                     VALUES (?, ?, ?, ?, ?, 1)'
                )->execute([
                    $username,
                    password_hash($password, PASSWORD_DEFAULT),
                    $db_role,
                    $email ?: null,
                    $first_name . ' ' . $last_name,
                ]);
                $user_id = (int)$db->lastInsertId();

                $profile_id = null;

                // 2. Create doctor / nurse record
                if ($staff_type === 'doctor') {
                    $db->prepare(
                        'INSERT INTO doctors (user_id, first_name, last_name, specialization, license_number, practice_number, phone, email)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $user_id, $first_name, $last_name,
                        $specialization ?: null, $license ?: null,
                        $practice_number, $phone ?: null, $email ?: null,
                    ]);
                    $profile_id = (int)$db->lastInsertId();

                    // 3. Create staff record
                    $db->prepare(
                        'INSERT INTO staff (user_id, doctor_id, first_name, last_name, department, position, role, status, created_by, phone)
                         VALUES (?, ?, ?, ?, ?, ?, "Doctor", "Active", ?, ?)'
                    )->execute([
                        $user_id, $profile_id, $first_name, $last_name,
                        $department ?: null, $position ?: null,
                        current_user_id(), $phone ?: null,
                    ]);

                } else {
                    $db->prepare(
                        'INSERT INTO nurses (user_id, first_name, last_name, department, license_number, practice_number, phone)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $user_id, $first_name, $last_name,
                        $department ?: null, $license ?: null, $practice_number, $phone ?: null,
                    ]);
                    $profile_id = (int)$db->lastInsertId();

                    $db->prepare(
                        'INSERT INTO staff (user_id, nurse_id, first_name, last_name, department, position, role, status, created_by, phone)
                         VALUES (?, ?, ?, ?, ?, ?, "Nurse", "Active", ?, ?)'
                    )->execute([
                        $user_id, $profile_id, $first_name, $last_name,
                        $department ?: null, $position ?: null,
                        current_user_id(), $phone ?: null,
                    ]);
                }

                $db->commit();

                write_audit_log('INSERT', $staff_type === 'doctor' ? 'doctors' : 'nurses', $profile_id, null, [
                    'first_name' => $first_name, 'last_name'  => $last_name,
                    'username'   => $username,   'role'       => $db_role,
                ]);

                $label = ($staff_type === 'doctor' ? 'Dr' : 'Nurse') . " $first_name $last_name";
                flash_set('success', "$label created successfully with login \"$username\".");
                redirect('/pages/staff_profiles.php');

            } catch (PDOException $e) {
                $db->rollBack();
                error_log('[HMS] staff_profiles create error: ' . $e->getMessage());
                flash_set('error', 'A database error occurred. Please try again.');
                redirect('/pages/staff_profiles.php');
            }
        }

    // Deactivate user account
    } elseif ($action === 'deactivate') {
        $target_user_id = (int)($_POST['target_user_id'] ?? 0);
        if (!$target_user_id) {
            flash_set('error', 'Invalid user.');
            redirect('/pages/staff_profiles.php');
        }
        if ($target_user_id === current_user_id()) {
            flash_set('error', 'You cannot deactivate your own account.');
            redirect('/pages/staff_profiles.php');
        }

        $db->prepare('UPDATE users SET is_active = 0 WHERE user_id = ?')
           ->execute([$target_user_id]);
        $db->prepare('UPDATE staff SET status = "Inactive" WHERE user_id = ?')
           ->execute([$target_user_id]);

        write_audit_log('UPDATE', 'users', $target_user_id,
            ['is_active' => 1], ['is_active' => 0]);
        flash_set('success', 'Account deactivated.');
        redirect('/pages/staff_profiles.php');

    // Reactivate user account
    } elseif ($action === 'reactivate') {
        $target_user_id = (int)($_POST['target_user_id'] ?? 0);
        if ($target_user_id) {
            $db->prepare('UPDATE users SET is_active = 1 WHERE user_id = ?')
               ->execute([$target_user_id]);
            $db->prepare('UPDATE staff SET status = "Active" WHERE user_id = ?')
               ->execute([$target_user_id]);
            write_audit_log('UPDATE', 'users', $target_user_id,
                ['is_active' => 0], ['is_active' => 1]);
            flash_set('success', 'Account reactivated.');
        }
        redirect('/pages/staff_profiles.php');

    // Update practice number (SuperAdmin only)
    } elseif ($action === 'update_practice') {
        $upd_type    = trim($_POST['staff_type']       ?? '');
        $upd_id      = (int)($_POST['profile_id']      ?? 0);
        $upd_prac    = trim($_POST['practice_number']  ?? '');
        $upd_tab     = $upd_type === 'doctor' ? 'doctors' : 'nurses';

        if (!in_array($upd_type, ['doctor','nurse'], true) || !$upd_id) {
            flash_set('error', 'Invalid request.');
            redirect('/pages/staff_profiles.php');
        }
        if ($upd_prac === '') {
            flash_set('error', 'Practice number is required.');
            redirect('/pages/staff_profiles.php?tab=' . $upd_tab);
        }
        if (!preg_match('/^[a-zA-Z0-9]+$/', $upd_prac)) {
            flash_set('error', 'Practice number must be alphanumeric.');
            redirect('/pages/staff_profiles.php?tab=' . $upd_tab);
        }
        $tbl    = $upd_type === 'doctor' ? 'doctors' : 'nurses';
        $id_col = $upd_type === 'doctor' ? 'doctor_id' : 'nurse_id';
        $s = $db->prepare("SELECT $id_col FROM $tbl WHERE practice_number = ? AND $id_col != ? LIMIT 1");
        $s->execute([$upd_prac, $upd_id]);
        if ($s->fetch()) {
            flash_set('error', 'That practice number is already in use.');
            redirect('/pages/staff_profiles.php?tab=' . $upd_tab);
        }
        $db->prepare("UPDATE $tbl SET practice_number = ? WHERE $id_col = ?")->execute([$upd_prac, $upd_id]);
        write_audit_log('UPDATE', $tbl, $upd_id, null, ['practice_number' => $upd_prac]);
        flash_set('success', 'Practice number updated.');
        redirect('/pages/staff_profiles.php?tab=' . $upd_tab);
    }
}

// Load doctor & nurse lists
$doctors = $db->query(
    'SELECT d.doctor_id, d.first_name, d.last_name, d.specialization,
            d.license_number, d.practice_number, d.phone, d.email,
            u.username, u.is_active, u.user_id
     FROM doctors d
     JOIN users u ON u.user_id = d.user_id
     ORDER BY d.last_name, d.first_name'
)->fetchAll();

$nurses = $db->query(
    'SELECT n.nurse_id, n.first_name, n.last_name, n.department,
            n.license_number, n.practice_number, n.phone,
            u.username, u.is_active, u.user_id
     FROM nurses n
     JOIN users u ON u.user_id = n.user_id
     ORDER BY n.last_name, n.first_name'
)->fetchAll();

// Active tab
$tab = ($_GET['tab'] ?? 'doctors') === 'nurses' ? 'nurses' : 'doctors';

require_once '../includes/header.php';
?>

<div class="page-header">
    <h1>Staff Profiles</h1>
    <p>Manage staff accounts</p>
</div>

<!--  Tabs  -->
<div style="display:flex;gap:4px;margin-bottom:20px;border-bottom:2px solid var(--border);">
    <?php foreach (['doctors' => 'Doctors (' . count($doctors) . ')', 'nurses' => 'Nurses (' . count($nurses) . ')'] as $t_key => $t_label): ?>
    <a href="<?= BASE_URL ?>/pages/staff_profiles.php?tab=<?= $t_key ?>"
       style="padding:10px 18px;font-size:.9rem;font-weight:600;text-decoration:none;border-bottom:2px solid <?= ($tab === $t_key) ? 'var(--secondary)' : 'transparent' ?>;color:<?= ($tab === $t_key) ? 'var(--secondary)' : 'var(--text-muted)' ?>;margin-bottom:-2px;">
        <?= $t_label ?>
    </a>
    <?php endforeach; ?>
</div>

<?php
// Shared table renderer for doctors & nurses
$list     = ($tab === 'doctors') ? $doctors : $nurses;
$is_doc   = ($tab === 'doctors');
$col_spec = $is_doc ? 'Specialization / License' : 'Department / License';
?>

<!--  Staff List  -->
<div class="card mb-24">
    <div class="card-body" style="padding:0;">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Username</th>
                        <th><?= $col_spec ?></th>
                        <th>Phone</th>
                        <th>Account</th>
                        <?php if ($can_write): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($list)): ?>
                    <tr><td colspan="7" class="text-center text-muted" style="padding:28px;">
                        No <?= $is_doc ? 'doctors' : 'nurses' ?> registered yet.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($list as $member): ?>
                    <tr>
                        <td class="text-muted" style="font-size:.85rem;">
                            <?= (int)($member[$is_doc ? 'doctor_id' : 'nurse_id']) ?>
                        </td>
                        <td>
                            <strong>
                                <?= $is_doc ? 'Dr ' : 'Nurse ' ?>
                                <?= h($member['first_name'] . ' ' . $member['last_name']) ?>
                            </strong>
                        </td>
                        <td style="font-family:var(--font-mono);font-size:.88rem;">
                            <?= h($member['username']) ?>
                        </td>
                        <td style="font-size:.85rem;">
                            <?php if ($is_doc && !empty($member['specialization'])): ?>
                            <?= h($member['specialization']) ?><br>
                            <?php endif; ?>
                            <?php if ($is_doc && empty($member['specialization']) && empty($member['email'])): ?>
                            <span class="text-muted">—</span>
                            <?php elseif (!$is_doc && !empty($member['department'])): ?>
                            <?= h($member['department']) ?><br>
                            <?php endif; ?>
                            <?php if (!empty($member['license_number'])): ?>
                            <small class="text-muted"><?= h($member['license_number']) ?></small><br>
                            <?php endif; ?>
                            <small class="text-muted">Prac.&nbsp;#&nbsp;<?= h($member['practice_number']) ?></small>
                        </td>
                        <td style="font-size:.88rem;"><?= $member['phone'] ? h($member['phone']) : '<span class="text-muted">—</span>' ?></td>
                        <td>
                            <?php if ($member['is_active']): ?>
                            <span class="status-badge status-confirmed">Active</span>
                            <?php else: ?>
                            <span class="status-badge status-cancelled">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($can_write): ?>
                        <td>
                            <?php if ($member['is_active']): ?>
                            <form method="POST" action="<?= BASE_URL ?>/pages/staff_profiles.php?tab=<?= $tab ?>"
                                  style="display:inline;"
                                  onsubmit="return confirmAction('Deactivate this account? The user will no longer be able to log in.')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action"         value="deactivate">
                                <input type="hidden" name="target_user_id" value="<?= (int)$member['user_id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Deactivate</button>
                            </form>
                            <?php else: ?>
                            <form method="POST" action="<?= BASE_URL ?>/pages/staff_profiles.php?tab=<?= $tab ?>"
                                  style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action"         value="reactivate">
                                <input type="hidden" name="target_user_id" value="<?= (int)$member['user_id'] ?>">
                                <button type="submit" class="btn btn-success btn-sm">Reactivate</button>
                            </form>
                            <?php endif; ?>
                            <!-- Edit practice number (SuperAdmin only) -->
                            <button type="button" class="btn btn-outline btn-sm"
                                    style="margin-top:6px;display:block;"
                                    onclick="this.style.display='none';this.nextElementSibling.style.display='block';">
                                Prac.&nbsp;#
                            </button>
                            <form method="POST"
                                  action="<?= BASE_URL ?>/pages/staff_profiles.php?tab=<?= $tab ?>"
                                  style="display:none;margin-top:6px;" data-validate novalidate>
                                <?= csrf_field() ?>
                                <input type="hidden" name="action"          value="update_practice">
                                <input type="hidden" name="staff_type"      value="<?= $is_doc ? 'doctor' : 'nurse' ?>">
                                <input type="hidden" name="profile_id"      value="<?= (int)$member[$is_doc ? 'doctor_id' : 'nurse_id'] ?>">
                                <div style="display:flex;gap:4px;align-items:center;">
                                    <input type="text" name="practice_number" required data-alphanumeric
                                           maxlength="20"
                                           value="<?= h($member['practice_number']) ?>"
                                           style="width:110px;font-size:.83rem;padding:4px 8px;">
                                    <button type="submit" class="btn btn-primary btn-sm">&#10003;</button>
                                    <button type="button" class="btn btn-outline btn-sm"
                                            onclick="var f=this.closest('form');f.style.display='none';f.previousElementSibling.style.display='block';">&#10005;</button>
                                </div>
                                <span class="field-error" style="font-size:.8rem;"></span>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!--  Create Form (SuperAdmin only)  -->
<?php if ($can_write): ?>
<div class="card">
    <div class="card-header">
        <h2>Add New <?= $is_doc ? 'Doctor' : 'Nurse' ?></h2>
    </div>
    <div class="card-body">

        <form method="POST" action="<?= BASE_URL ?>/pages/staff_profiles.php?tab=<?= $tab ?>"
              data-validate novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action"     value="create">
            <input type="hidden" name="staff_type" value="<?= $is_doc ? 'doctor' : 'nurse' ?>">

            <fieldset style="border:none;margin-bottom:28px;">
                <legend style="font-weight:700;color:var(--primary);margin-bottom:16px;font-size:1rem;">
                    Personal &amp; Professional Details
                </legend>
                <div class="form-grid">

                    <div class="form-group">
                        <label for="first_name" class="required">First Name</label>
                        <input type="text" id="first_name" name="first_name" required maxlength="50"
                               value="<?= h($_POST['first_name'] ?? '') ?>">
                        <span class="field-error"><?= h($errors['first_name'] ?? '') ?></span>
                    </div>

                    <div class="form-group">
                        <label for="last_name" class="required">Last Name</label>
                        <input type="text" id="last_name" name="last_name" required maxlength="50"
                               value="<?= h($_POST['last_name'] ?? '') ?>">
                        <span class="field-error"><?= h($errors['last_name'] ?? '') ?></span>
                    </div>

                    <?php if ($is_doc): ?>
                    <div class="form-group">
                        <label for="specialization" class="required">Specialization</label>
                        <input type="text" id="specialization" name="specialization" maxlength="100" required
                               placeholder="e.g. Cardiology, General Practice"
                               value="<?= h($_POST['specialization'] ?? '') ?>">
                        <span class="field-error"><?= h($errors['specialization'] ?? '') ?></span>
                    </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="license" class="required">
                            <?= $is_doc ? 'HPCSA License No.' : 'SANC License No.' ?>
                        </label>
                        <input type="text" id="license" name="license" maxlength="50" required
                               value="<?= h($_POST['license'] ?? '') ?>">
                        <span class="field-error"><?= h($errors['license'] ?? '') ?></span>
                    </div>

                    <div class="form-group">
                        <label for="practice_number" class="required">Practice Number</label>
                        <input type="text" id="practice_number" name="practice_number" required
                               maxlength="20" data-alphanumeric
                               value="<?= h($_POST['practice_number'] ?? '') ?>">
                        <span class="field-error"><?= h($errors['practice_number'] ?? '') ?></span>
                    </div>

                    <div class="form-group">
                        <label for="department">Department</label>
                        <input type="text" id="department" name="department" maxlength="100"
                               placeholder="e.g. Cardiology, Emergency, ICU"
                               value="<?= h($_POST['department'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="position">Position / Title</label>
                        <input type="text" id="position" name="position" maxlength="100"
                               placeholder="e.g. Senior Doctor, Ward Nurse"
                               value="<?= h($_POST['position'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone</label>
                        <input type="tel" id="phone" name="phone" maxlength="10"
                               value="<?= h($_POST['phone'] ?? '') ?>">
                        <span class="field-error"></span>
                    </div>

                    <div class="form-group">
                        <label for="email" class="required">Email</label>
                        <input type="email" id="email" name="email" maxlength="255" required
                               value="<?= h($_POST['email'] ?? '') ?>">
                        <span class="field-error"><?= h($errors['email'] ?? '') ?></span>
                    </div>

                </div>
            </fieldset>

            <fieldset style="border:none;">
                <legend style="font-weight:700;color:var(--primary);margin-bottom:16px;font-size:1rem;">
                    Login Credentials
                </legend>
                <div class="form-grid">

                    <div class="form-group">
                        <label for="username" class="required">Username</label>
                        <input type="text" id="username" name="username" required
                               autocomplete="off" maxlength="50"
                               value="<?= h($_POST['username'] ?? '') ?>">
                        <span class="field-error"><?= h($errors['username'] ?? '') ?></span>
                    </div>

                    <div class="form-group">
                        <label for="password" class="required">Password</label>
                        <input type="password" id="password" name="password" required
                               autocomplete="new-password" data-min-length="8">
                        <span class="field-error"><?= h($errors['password'] ?? '') ?><small> Min. 8 characters</small></span>
                    </div>

                    <div class="form-group">
                        <label for="password_confirm" class="required">Confirm Password</label>
                        <input type="password" id="password_confirm" name="password_confirm"
                               required autocomplete="new-password">
                        <span class="field-error"><?= h($errors['password_confirm'] ?? '') ?></span>
                    </div>

                </div>
            </fieldset>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    Create <?= $is_doc ? 'Doctor' : 'Nurse' ?> Profile
                </button>
            </div>
        </form>

    </div>
</div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
