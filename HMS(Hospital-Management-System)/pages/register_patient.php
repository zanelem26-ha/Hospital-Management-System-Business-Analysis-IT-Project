<?php
/**
 * php/register_patient.php — FR-01: Patient Registration
 *
 * GET : Display the registration form.
 * POST: Validate input, create a users record + patients record (transaction).
 *
 * Accessible by: SuperAdmin, Admin, Nurse
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/audit.php';

require_role(['SuperAdmin', 'Admin', 'Nurse']);

$page_title = 'Register Patient';
$db         = get_db();
$errors     = [];
$old        = [];   // repopulate form on validation failure

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF (Cross-Site Request Forgery) protection
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        flash_set('error', 'Invalid request. Please try again.');
        redirect('/pages/register_patient.php');
    }

    // Collect & sanitise inputs
    $old = $_POST;

    // Trim whitespace and set defaults on required fields
    $first_name    = trim($_POST['first_name']    ?? ''); 
    $last_name     = trim($_POST['last_name']     ?? '');
    $id_number     = trim($_POST['id_number']     ?? '');
    $dob           = trim($_POST['dob']           ?? '');
    $gender        = trim($_POST['gender']        ?? '');
    $phone         = trim($_POST['phone']         ?? '');
    $email         = trim($_POST['email']         ?? '');
    $address_line1 = trim($_POST['address_line_1'] ?? '');
    $address_line2 = trim($_POST['address_line_2'] ?? '');
    $city          = trim($_POST['city']          ?? '');
    $postal_code   = trim($_POST['postal_code']   ?? '');
    $blood_group   = trim($_POST['blood_group']   ?? '');
    $ec_name       = trim($_POST['ec_name']       ?? '');
    $ec_phone      = trim($_POST['ec_phone']      ?? '');
    $ec_relationship = trim($_POST['ec_relationship'] ?? '');
    $username      = trim($_POST['username']      ?? '');
    $password      = $_POST['password']           ?? '';
    $password_conf = $_POST['password_confirm']   ?? '';

    // Server-side validation
    if ($first_name === '')   $errors['first_name']   = 'First name is required.';
    if ($last_name  === '')   $errors['last_name']    = 'Last name is required.';
    if ($dob        === '')   $errors['dob']          = 'Date of birth is required.';
    elseif ($dob > date('Y-m-d')) $errors['dob']       = 'Date of birth cannot be in the future.';
    if ($gender     === '')   $errors['gender']       = 'Gender is required.';
    if ($username   === '')   $errors['username']     = 'Username is required.';
    if ($email      === '') {
        $errors['email'] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if ($address_line1 === '') $errors['address_line_1'] = 'Address Line 1 is required.';
    if ($city          === '') $errors['city']           = 'City/Suburb is required.';
    if ($postal_code   === '') $errors['postal_code']    = 'Postal code is required.';

    // Combine structured address fields into a single display string
    $address = implode('<br>', [$address_line1, $address_line2, $city, $postal_code]);

    // SA ID number: full structural + Luhn validation, must be unique
    if ($id_number !== '') {
        $id_err = validate_sa_id($id_number);
        if ($id_err !== null) {
            $errors['id_number'] = $id_err;
        } else {
            $s = $db->prepare('SELECT patient_id FROM patients WHERE id_number = ? LIMIT 1');
            $s->execute([$id_number]);
            if ($s->fetch()) $errors['id_number'] = 'This SA ID number is already registered.';
        }
    }

    if (!in_array($gender, ['Male', 'Female', 'Other'], true)) {
        $errors['gender'] = 'Invalid gender value.';
    }

    if ($phone !== '' && !preg_match('/^0\d{9}$/', $phone)) {
        $errors['phone'] = 'Phone must be 10 digits.';
    }
    if ($ec_phone !== '' && !preg_match('/^0\d{9}$/', $ec_phone)) {
        $errors['ec_phone'] = 'Emergency contact phone must be 10 digits.';
    }

    // Relationship is mandatory only when a contact name or phone was captured
    $allowed_relationships = ['Parent', 'Sibling', 'Spouse', 'Child', 'Friend', 'Other'];
    if (($ec_name !== '' || $ec_phone !== '') && $ec_relationship === '') {
        $errors['ec_relationship'] = 'Emergency contact relationship is required when a contact name or phone is provided.';
    } elseif ($ec_relationship !== '' && !in_array($ec_relationship, $allowed_relationships, true)) {
        $errors['ec_relationship'] = 'Invalid emergency contact relationship selected.';
    }

    if ($password === '') {
        $errors['password'] = 'Password is required.';
    } elseif (strlen($password) < 10) {
        $errors['password'] = 'Password must be at least 10 characters long, contain 1 uppercase, 1 lowercase, 1 number, and 1 special character.';
    } elseif ($password !== $password_conf) {
        $errors['password_confirm'] = 'Passwords do not match.';
    }

    // Check username uniqueness
    if (!isset($errors['username']) && $username !== '') {
        $s = $db->prepare('SELECT user_id FROM users WHERE username = ? LIMIT 1');
        $s->execute([$username]);
        if ($s->fetch()) {
            $errors['username'] = 'Username is already taken.';
        }
    }

    // Check email uniqueness
    if (!isset($errors['email']) && $email !== '') {
        $s = $db->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
        $s->execute([$email]);
        if ($s->fetch()) {
            $errors['email'] = 'This email is already registered.';
        }
    }

    // Persist (transaction)
    if (empty($errors)) {
        try {
            $db->beginTransaction();

            // 1. Create user account
            $stmt = $db->prepare(
                'INSERT INTO users (username, password_hash, role, email, full_name, is_active)
                 VALUES (?, ?, "Patient", ?, ?, 1)'
            );
            $stmt->execute([
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                $email ?: null,
                $first_name . ' ' . $last_name,
            ]);
            $user_id = (int)$db->lastInsertId();

            // 2. Create patient profile
            $stmt = $db->prepare(
                'INSERT INTO patients
                    (user_id, first_name, last_name, id_number, date_of_birth, gender,
                     phone, email, address, blood_group,
                     emergency_contact_name, emergency_contact_phone, emergency_contact_relationship)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $user_id,
                $first_name,
                $last_name,
                $id_number ?: null,
                $dob,
                $gender,
                $phone       ?: null,
                $email       ?: null,
                $address     ?: null,
                $blood_group ?: null,
                $ec_name     ?: null,
                $ec_phone    ?: null,
                $ec_relationship ?: null,
            ]);

            $patient_id = (int)$db->lastInsertId();
            $db->commit();

            write_audit_log('INSERT', 'patients', $patient_id, null, [
                'first_name'  => $first_name, 'last_name'   => $last_name,
                'dob'         => $dob,        'gender'      => $gender,
                'phone'       => $phone,       'email'       => $email,
                'blood_group' => $blood_group,
                'emergency_contact_name' => $ec_name,
                'emergency_contact_phone' => $ec_phone,
                'emergency_contact_relationship' => $ec_relationship,
            ]);

            flash_set('success', "Patient '$first_name $last_name' registered successfully.");
            redirect('/pages/patient_profile.php');

        } catch (PDOException $e) {
            $db->rollBack();
            error_log('[HMS] register_patient error: ' . $e->getMessage());
            flash_set('error', 'A database error occurred. Please try again.');
            redirect('/pages/register_patient.php');
        }
    }
}

require_once '../includes/header.php';
?>

<div class="page-header">
    <h1>Register New Patient</h1>
    <p>Register a new patient</p>
</div>

<div class="card">
    <div class="card-header"><h2>Patient Information</h2></div>
    <div class="card-body">

        <form method="POST" action="<?= BASE_URL ?>/pages/register_patient.php"
              data-validate data-offline-sync="patients/insert" novalidate>
            <?= csrf_field() ?>

            <!--  Personal details  -->
            <fieldset style="border:1px solid var(--primary);margin-bottom:8px;padding:16px;border-radius:4px;">
                <legend style="font-weight:700;color:var(--primary);margin-bottom:16px;font-size:1rem;">
                    Personal Details
                </legend>
                <div class="form-grid">

                <!-- Name -->
                    <div class="form-group">
                        <label for="first_name" class="required">First Name</label>
                        <input type="text" id="first_name" name="first_name" required
                               maxlength="50"
                               value="<?= h($old['first_name'] ?? '') ?>">
                        <?php if (isset($errors['first_name'])): ?>
                        <span class="field-error"><?= h($errors['first_name']) ?></span>
                        <?php else: ?><span class="field-error"></span><?php endif; ?>
                    </div>

                    <!-- Surname -->
                    <div class="form-group">
                        <label for="last_name" class="required">Surname</label>
                        <input type="text" id="last_name" name="last_name" required
                               maxlength="50"
                               value="<?= h($old['last_name'] ?? '') ?>">
                        <?php if (isset($errors['last_name'])): ?>
                        <span class="field-error"><?= h($errors['last_name']) ?></span>
                        <?php else: ?><span class="field-error"></span><?php endif; ?>
                    </div>

                    <!-- SA ID Number -->
                    <div class="form-group">
                        <label for="id_number" class="required">SA ID Number</label>
                        <input type="text" id="id_number" name="id_number" required
                               maxlength="13" pattern="\d{13}"
                               placeholder="13-digit SA ID" data-sa-id
                               value="<?= h($old['id_number'] ?? '') ?>">
                        <?php if (isset($errors['id_number'])): ?>
                        <span class="field-error"><?= h($errors['id_number']) ?></span>
                        <?php else: ?><span class="field-hint"></span><?php endif; ?>
                    </div>

                    <!-- Date of Birth -->
                    <div class="form-group">
                        <label for="dob" class="required">Date of Birth</label>
                        <input type="date" id="dob" name="dob" required
                               max="<?= date('Y-m-d') ?>"
                               value="<?= h($old['dob'] ?? '') ?>">
                        <?php if (isset($errors['dob'])): ?>
                        <span class="field-error"><?= h($errors['dob']) ?></span>
                        <?php else: ?><span class="field-error"></span><?php endif; ?>
                    </div>

                    <!-- Gender -->
                    <div class="form-group">
                        <label for="gender" class="required">Gender</label>
                        <select id="gender" name="gender" required>
                            <option value="">— Select —</option>
                            <?php foreach (['Male', 'Female', 'Other'] as $g): ?>
                            <option value="<?= $g ?>"
                                <?= (($old['gender'] ?? '') === $g) ? 'selected' : '' ?>>
                                <?= $g ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['gender'])): ?>
                        <span class="field-error"><?= h($errors['gender']) ?></span>
                        <?php else: ?><span class="field-error"></span><?php endif; ?>
                    </div>

                    <!-- Email Address-->
                    <div class="form-group">
                        <label for="email" class="required">Email</label>
                        <input type="email" id="email" name="email" required
                               maxlength="100"
                               value="<?= h($old['email'] ?? '') ?>">
                        <?php if (isset($errors['email'])): ?>
                        <span class="field-error"><?= h($errors['email']) ?></span>
                        <?php else: ?><span class="field-error"></span><?php endif; ?>
                    </div>

                    <!-- Phone Number-->
                    <div class="form-group">
                        <label for="phone" class="required">Phone</label>
                        <input type="tel" id="phone" name="phone" required
                               maxlength="10"
                               value="<?= h($old['phone'] ?? '') ?>">
                        <span class="field-error"></span>
                    </div>

                    <!-- Blood Group -->
                    <div class="form-group">
                        <label for="blood_group">Blood Group</label>
                        <select id="blood_group" name="blood_group">
                            <option value="">— Unknown —</option>
                            <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bg): ?>
                            <option value="<?= $bg ?>"
                                <?= (($old['blood_group'] ?? '') === $bg) ? 'selected' : '' ?>>
                                <?= $bg ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </fieldset>

            <!--  Physical Address  -->
            <fieldset style="border:1px solid var(--primary);margin-bottom:8px;padding:16px;border-radius:4px;">
                <legend style="font-weight:700;color:var(--primary);margin-bottom:16px;font-size:1rem;">
                    Physical Address
                </legend>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="address_line1" class="required">Address Line 1</label>
                        <input type="text" id="address_line1" name="address_line_1" required
                               maxlength="100"
                               value="<?= h($old['address_line_1'] ?? '') ?>">
                        <?php if (isset($errors['address_line_1'])): ?>
                        <span class="field-error"><?= h($errors['address_line_1']) ?></span>
                        <?php else: ?><span class="field-error"></span><?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="address_line2">Address Line 2</label>
                        <input type="text" id="address_line2" name="address_line_2"
                               maxlength="100"
                               value="<?= h($old['address_line_2'] ?? '') ?>">
                        <?php if (isset($errors['address_line_2'])): ?>
                        <span class="field-error"><?= h($errors['address_line_2']) ?></span>
                        <?php else: ?><span class="field-error"></span><?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="city" class="required">City/Suburb</label>
                        <input type="text" id="city" name="city" required
                               maxlength="100"
                               value="<?= h($old['city'] ?? '') ?>">
                        <?php if (isset($errors['city'])): ?>
                        <span class="field-error"><?= h($errors['city']) ?></span>
                        <?php else: ?><span class="field-error"></span><?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="postal_code" class="required">Postal Code</label>
                        <input type="text" id="postal_code" name="postal_code" required
                               maxlength="100"
                               value="<?= h($old['postal_code'] ?? '') ?>">
                        <?php if (isset($errors['postal_code'])): ?>
                        <span class="field-error"><?= h($errors['postal_code']) ?></span>
                        <?php else: ?><span class="field-error"></span><?php endif; ?>
                    </div>
                </div>
            </fieldset>

            <!--  Emergency contact  -->
            <fieldset style="border:1px solid var(--primary);margin-bottom:8px;padding:16px;border-radius:4px;">
                <legend style="font-weight:700;color:var(--primary);margin-bottom:16px;font-size:1rem;">
                    Emergency Contact
                </legend>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="ec_name">Contact Name</label>
                        <input type="text" id="ec_name" name="ec_name"
                               maxlength="100"
                               value="<?= h($old['ec_name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="ec_phone">Contact Phone</label>
                        <input type="tel" id="ec_phone" name="ec_phone"
                               maxlength="10"
                               value="<?= h($old['ec_phone'] ?? '') ?>">
                        <span class="field-error"></span>
                    </div>

                    <div class="form-group">
                        <label for="ec_relationship">Relationship with Patient</label>
                        <select id="ec_relationship" name="ec_relationship">
                            <option value="">Not Selected</option>
                            <?php foreach (['Parent', 'Sibling', 'Spouse', 'Child', 'Friend', 'Other'] as $rel): ?>
                            <option value="<?= $rel ?>"
                                <?= (($old['ec_relationship'] ?? '') === $rel) ? 'selected' : '' ?>>
                                <?= $rel ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['ec_relationship'])): ?>
                        <span class="field-error"><?= h($errors['ec_relationship']) ?></span>
                        <?php else: ?><span class="field-error"></span><?php endif; ?>
                    </div>

                </div>
            </fieldset>

            <!--  Login credentials  -->
            <fieldset style="border:1px solid var(--primary);margin-bottom:8px;padding:16px;border-radius:4px;">
                <legend style="font-weight:700;color:var(--primary);margin-bottom:16px;font-size:1rem;">
                    Login Credentials
                </legend>
                <div class="form-grid">
                    <div class="form-group full-width" style="flex-direction:row;align-items:center;gap:8px;">
                        <input type="checkbox" id="use_email_as_username" name="use_email_as_username"
                               value="1" style="width:auto;"
                               <?= (!empty($old['use_email_as_username'])) ? 'checked' : '' ?>>
                        <label for="use_email_as_username" style="margin:0;font-weight:400;">Use email address</label>
                    </div>

                    <div class="form-group">
                        <label for="username" class="required">Username</label>
                        <input type="text" id="username" name="username" required
                               autocomplete="off" maxlength="50"
                               value="<?= h($old['username'] ?? '') ?>">
                        <?php if (isset($errors['username'])): ?>
                                <span class="field-error"><?= h($errors['username']) ?></span>
                        <span class="field-error"><?= h($errors['username']) ?></span>
                        <?php else: ?><span class="field-error"></span><?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="password" class="required">Password</label>
                        <input type="password" id="password" name="password" required
                               autocomplete="new-password"
                               data-min-length="10">
                        <?php if (isset($errors['password'])): ?>
                        <span class="field-error"><?= h($errors['password']) ?></span>
                        <?php else: ?><span class="field-error"><small>Minimum 10 characters</small></span><?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="password_confirm" class="required">Confirm Password</label>
                        <input type="password" id="password_confirm" name="password_confirm"
                               required autocomplete="new-password">
                        <?php if (isset($errors['password_confirm'])): ?>
                        <span class="field-error"><?= h($errors['password_confirm']) ?></span>
                        <?php else: ?><span class="field-error"></span><?php endif; ?>
                    </div>
                </div>
            </fieldset>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Register Patient</button>
                <a href="<?= BASE_URL ?>/pages/patient_profile.php" class="btn btn-secondary">Cancel</a>
            </div>

        </form>
    </div>
</div>

<!-- JS: Auto-populate username from email if checkbox is checked -->
<script>

(function () {
    var useEmailCheckbox = document.getElementById('use_email_as_username');
    var emailField        = document.getElementById('email');
    var usernameField      = document.getElementById('username');

    function syncUsernameFromEmail() {
        usernameField.value = emailField.value;
        flashField(usernameField); // highlight the username field to indicate it was auto-populated
    }

    function applyState() {
        if (useEmailCheckbox.checked) {
            syncUsernameFromEmail();
            usernameField.readOnly = true;
        } else {
            usernameField.readOnly = false;
        }
    }

    useEmailCheckbox.addEventListener('change', function () {
        if (!useEmailCheckbox.checked) {
            usernameField.value = ''; // clear on change event
        }
        applyState();
    });

    emailField.addEventListener('input', function () {
        if (useEmailCheckbox.checked) syncUsernameFromEmail();
    });

    applyState();
}());
</script>

<?php require_once '../includes/footer.php'; ?>
