<?php
/**
 * php/patient_profile.php — FR-01: View & edit patient profiles
 *
 * - Admin/SuperAdmin/Doctor/Nurse: browse all patients; view any profile.
 * - Patient role: can only view and update their own profile.
 *
 * Query-string:  ?id=<patient_id>   — view a specific patient
 * Without ?id:   list all patients (staff) or own profile (patient)
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/audit.php';

require_login();

$page_title = 'Patient Profiles';
$db         = get_db();
$role       = current_role();
$errors     = [];

// Determine which patient to show
$patient_id = null;

if ($role === 'Patient') {
    $patient_id = (int)($_SESSION['patient_id'] ?? 0);
} elseif (isset($_GET['id'])) {
    // Staff viewing a specific patient — only allow for authorised roles
    require_role(['SuperAdmin', 'Admin', 'Doctor', 'Nurse']);
    $patient_id = (int)$_GET['id'];
}

// LIST VIEW (staff, no specific patient selected)
if ($patient_id === null) {
    require_role(['SuperAdmin', 'Admin', 'Doctor', 'Nurse']);

    $search = trim($_GET['q'] ?? '');
    if ($search !== '') {
        $stmt = $db->prepare(
            'SELECT p.patient_id, p.first_name, p.last_name, p.date_of_birth,
                    p.gender, p.phone, p.blood_group
             FROM patients p
             WHERE p.first_name  LIKE ?
                OR p.last_name   LIKE ?
                OR p.phone       LIKE ?
             ORDER BY p.last_name, p.first_name
             LIMIT 60'
        );
        $like = '%' . $search . '%';
        $stmt->execute([$like, $like, $like]);
    } else {
        $stmt = $db->prepare(
            'SELECT patient_id, first_name, last_name, date_of_birth, gender, phone, blood_group
             FROM patients
             ORDER BY last_name, first_name
             LIMIT 60'
        );
        $stmt->execute();
    }
    $patients = $stmt->fetchAll();

    require_once '../includes/header.php';
    ?>

    <div class="page-header d-flex align-center">
        <div class="flex-1">
            <h1>Patient Profiles</h1>
            <p>View and manage patient profile</p>
        </div>
        <?php if (in_array($role, ['SuperAdmin', 'Admin', 'Nurse'], true)): ?>
        <a href="<?= BASE_URL ?>/pages/register_patient.php" class="btn btn-primary">
            + Register Patient
        </a>
        <?php endif; ?>
    </div>

    <!-- Search -->
    <form method="GET" class="d-flex gap-8 mb-24">
        <input type="text" name="q" placeholder="Search by name or phone..."
               value="<?= h($search) ?>" style="max-width:340px;">
        <button type="submit" class="btn btn-primary">Search</button>
        <?php if ($search): ?>
        <a href="<?= BASE_URL ?>/pages/patient_profile.php" class="btn btn-secondary">Clear</a>
        <?php endif; ?>
    </form>

    <div class="card">
        <div class="card-body" style="padding:0;">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>DOB</th>
                            <th>Gender</th>
                            <th>Phone</th>
                            <th>Blood Group</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($patients)): ?>
                        <tr><td colspan="7" class="text-center text-muted" style="padding:24px;">
                            No patients found.
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($patients as $p): ?>
                        <tr>
                            <td><?= (int)$p['patient_id'] ?></td>
                            <td><?= h($p['first_name'] . ' ' . $p['last_name']) ?></td>
                            <td><?= h($p['date_of_birth'] ? date('d M Y', strtotime($p['date_of_birth'])) : '—') ?></td>
                            <td><?= h($p['gender']) ?></td>
                            <td><?= h($p['phone'] ?? '—') ?></td>
                            <td><?= h($p['blood_group'] ?? '—') ?></td>
                            <td>
                                <a href="?id=<?= (int)$p['patient_id'] ?>"
                                   class="btn btn-secondary btn-sm">View / Edit</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php
    require_once '../includes/footer.php';
    exit;
}

// PROFILE VIEW / EDIT
$stmt = $db->prepare(
    'SELECT * FROM patients WHERE patient_id = ? LIMIT 1'
);
$stmt->execute([$patient_id]);
$patient = $stmt->fetch();

if (!$patient) {
    flash_set('error', 'Patient not found.');
    redirect('/pages/patient_profile.php');
}

// Patients can only access their own profile
if ($role === 'Patient' && (int)$patient['patient_id'] !== (int)($_SESSION['patient_id'] ?? 0)) {
    http_response_code(403);
    die('Access denied.');
}

// Handle profile update (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        flash_set('error', 'Invalid request. Please refresh and try again.');
        redirect('/pages/patient_profile.php?id=' . $patient_id);
    }

    $record_updated_at = trim($_POST['record_updated_at'] ?? '');
    $first_name        = trim($_POST['first_name']        ?? '');
    $last_name         = trim($_POST['last_name']         ?? '');
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
    
    // medical_notes only accepted from clinical staff (not patients editing own profile)
    $medical_notes = in_array($role, ['SuperAdmin','Admin','Doctor','Nurse'], true)
                     ? trim($_POST['medical_notes'] ?? '')
                     : ($patient['medical_notes'] ?? '');

    if ($first_name === '') $errors['first_name'] = 'First name is required.';
    if ($last_name  === '') $errors['last_name']  = 'Last name is required.';
    if ($dob        === '') $errors['dob']        = 'Date of birth is required.';
    elseif ($dob > date('Y-m-d')) $errors['dob']  = 'Date of birth cannot be in the future.';
    if ($gender     === '') $errors['gender']     = 'Gender is required.';

    if ($phone !== '' && !preg_match('/^0\d{9}$/', $phone)) {
        $errors['phone'] = 'Phone must be 10 digits and start with 0.';
    }
    if ($ec_phone !== '' && !preg_match('/^0\d{9}$/', $ec_phone)) {
        $errors['ec_phone'] = 'Emergency contact phone must be 10 digits and start with 0.';
    }

    // Relationship is mandatory only when a contact name or phone was captured
    $allowed_relationships = ['Parent', 'Sibling', 'Spouse', 'Child', 'Friend', 'Other'];
    if (($ec_name !== '' || $ec_phone !== '') && $ec_relationship === '') {
        $errors['ec_relationship'] = 'Emergency contact relationship is required when a contact name or phone is provided.';
    } elseif ($ec_relationship !== '' && !in_array($ec_relationship, $allowed_relationships, true)) {
        $errors['ec_relationship'] = 'Invalid emergency contact relationship selected.';
    }

    // SA ID number: full structural + Luhn validation
    if ($id_number !== '') {
        $id_err = validate_sa_id($id_number);
        if ($id_err !== null) {
            $errors['id_number'] = $id_err;
        }
    }
    // Check uniqueness — allow same patient to keep their own ID
    if (!isset($errors['id_number']) && $id_number !== '') {
        $s = $db->prepare('SELECT patient_id FROM patients WHERE id_number = ? AND patient_id != ? LIMIT 1');
        $s->execute([$id_number, $patient_id]);
        if ($s->fetch()) $errors['id_number'] = 'This SA ID number is already registered.';
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }

    if ($address_line1 === '') $errors['address_line_1'] = 'Address Line 1 is required.';
    if ($city          === '') $errors['city']           = 'City/Suburb is required.';
    if ($postal_code   === '') $errors['postal_code']    = 'Postal code is required.';

    // Combine structured address fields into the single stored column (schema unchanged)
    $address = implode('<br>', [$address_line1, $address_line2, $city, $postal_code]);

    if (empty($errors)) {
        // Capture snapshot before update for audit trail
        $old_snapshot = audit_sanitize($patient);

        $lock_clause = $record_updated_at !== '' ? ' AND updated_at = ?' : '';
        $stmt = $db->prepare(
            'UPDATE patients
             SET first_name = ?, last_name = ?, id_number = ?, date_of_birth = ?, gender = ?,
                 phone = ?, email = ?, address = ?, blood_group = ?,
                 emergency_contact_name = ?, emergency_contact_phone = ?, emergency_contact_relationship = ?
             WHERE patient_id = ?' . $lock_clause
        );
        $params = [
            $first_name, $last_name, $id_number ?: null, $dob, $gender,
            $phone ?: null, $email ?: null, $address ?: null, $blood_group ?: null,
            $ec_name ?: null, $ec_phone ?: null, $ec_relationship ?: null,
            $patient_id,
        ];
        if ($record_updated_at !== '') $params[] = $record_updated_at;
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            $errors['_conflict'] = 'This profile was updated by someone else while you had it open. Please reload and try again.';
        }

        // Keep users.full_name in sync
        $db->prepare('UPDATE users SET full_name = ? WHERE user_id = ?')
           ->execute([$first_name . ' ' . $last_name, (int)$patient['user_id']]);

        write_audit_log('UPDATE', 'patients', $patient_id, $old_snapshot, [
            'first_name'               => $first_name,  'last_name'               => $last_name,
            'id_number'                => $id_number,
            'date_of_birth'            => $dob,          'gender'                  => $gender,
            'phone'                    => $phone,         'email'                   => $email,
            'address'                  => $address,       'blood_group'             => $blood_group,
            'emergency_contact_name'   => $ec_name,       'emergency_contact_phone' => $ec_phone,
            'emergency_contact_relationship' => $ec_relationship,
        ]);

        flash_set('success', 'Profile updated successfully.');
        redirect('/pages/patient_profile.php?id=' . $patient_id);
    }

    // Re-merge submitted values for display
    $patient = array_merge($patient, [
        'first_name' => $first_name, 'last_name' => $last_name,
        'date_of_birth' => $dob, 'gender' => $gender,
        'phone' => $phone, 'email' => $email, 'address' => $address,
        'blood_group' => $blood_group,
        'emergency_contact_name' => $ec_name,
        'emergency_contact_phone' => $ec_phone,
        'emergency_contact_relationship' => $ec_relationship,
    ]);
}

// GET (or any path that didn't just parse $_POST): split the stored address
// column back into the four display fields. Legacy free-text addresses that
// don't have exactly 4 '<br>'-joined parts land in Address Line 1 as-is.
if (!isset($address_line1)) {
    $addr_parts    = explode('<br>', $patient['address'] ?? '', 4);
    $address_line1 = $addr_parts[0] ?? '';
    $address_line2 = $addr_parts[1] ?? '';
    $city          = $addr_parts[2] ?? '';
    $postal_code   = $addr_parts[3] ?? '';
}

$page_title = h($patient['first_name'] . ' ' . $patient['last_name']) . ' — Profile';

// Medical History
// All completed appointments with doctor, nurse, and prescriptions per visit
$stmt = $db->prepare(
    "SELECT a.appointment_id, a.appointment_date, a.appointment_time, a.status,
            a.reason, a.notes,
            CONCAT(d.first_name,' ',d.last_name) AS doctor_name,
            d.specialization,
            CONCAT(n.first_name,' ',n.last_name) AS nurse_name
     FROM appointments a
     JOIN doctors  d ON d.doctor_id = a.doctor_id
     LEFT JOIN (
         SELECT DISTINCT rx.patient_id,
                CONCAT(nu.first_name,' ',nu.last_name) AS first_name,
                nu.last_name, rx.doctor_id, nu.nurse_id
         FROM prescriptions rx
         JOIN nurses nu ON nu.nurse_id = rx.nurse_id
     ) n ON n.patient_id = a.patient_id AND n.doctor_id = a.doctor_id
     WHERE a.patient_id = ?
     ORDER BY a.appointment_date DESC, a.appointment_time DESC"
);
$stmt->execute([$patient_id]);
$visit_history = $stmt->fetchAll();

// Prescriptions — all for this patient
$stmt = $db->prepare(
    "SELECT rx.rx_id, rx.medication, rx.dosage, rx.quantity, rx.instructions,
            rx.issued_date, rx.status,
            CONCAT(d.first_name,' ',d.last_name) AS doctor_name,
            d.specialization,
            CONCAT(n.first_name,' ',n.last_name) AS nurse_name
     FROM prescriptions rx
     JOIN doctors d ON d.doctor_id = rx.doctor_id
     LEFT JOIN nurses n ON n.nurse_id = rx.nurse_id
     WHERE rx.patient_id = ?
     ORDER BY rx.issued_date DESC"
);
$stmt->execute([$patient_id]);
$prescriptions = $stmt->fetchAll();

// Billing
$stmt = $db->prepare(
    "SELECT b.bill_id, b.amount, b.payment_status, b.account_balance,
            b.account_term, b.billing_date, b.appointment_id,
            a.appointment_date
     FROM billing_records b
     LEFT JOIN appointments a ON a.appointment_id = b.appointment_id
     WHERE b.patient_id = ?
     ORDER BY b.billing_date DESC"
);
$stmt->execute([$patient_id]);
$bills = $stmt->fetchAll();

// Payment history per bill (keyed by bill_id)
$payments_by_bill = [];
if (!empty($bills)) {
    $bill_ids = implode(',', array_map(fn($b) => (int)$b['bill_id'], $bills));
    $pmt_rows = $db->query(
        "SELECT p.payment_id, p.bill_id, p.amount_paid, p.payment_method,
                p.payment_date, p.reference_no
         FROM payments p
         WHERE p.bill_id IN ($bill_ids)
         ORDER BY p.payment_date DESC"
    )->fetchAll();
    foreach ($pmt_rows as $pmt) {
        $payments_by_bill[(int)$pmt['bill_id']][] = $pmt;
    }
}

// Handle doctor note add — INSERT-only, no edit/delete path exists anywhere
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_note'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        flash_set('error', 'Invalid request.');
        redirect('/pages/patient_profile.php?id=' . $patient_id);
    }
    if ($role !== 'Doctor') {
        flash_set('error', 'Only doctors can add clinical notes.');
        redirect('/pages/patient_profile.php?id=' . $patient_id);
    }
    $note_text = trim($_POST['note_text'] ?? '');
    if ($note_text === '') {
        flash_set('error', 'Note text cannot be empty.');
        redirect('/pages/patient_profile.php?id=' . $patient_id);
    }

    $s = $db->prepare('SELECT first_name, last_name FROM doctors WHERE doctor_id = ? LIMIT 1');
    $s->execute([(int)($_SESSION['doctor_id'] ?? 0)]);
    $doc = $s->fetch();
    if (!$doc) {
        flash_set('error', 'Doctor record not found.');
        redirect('/pages/patient_profile.php?id=' . $patient_id);
    }

    $header    = '=== Dr ' . $doc['first_name'] . ' ' . $doc['last_name']
               . ' | ' . date('d M Y H:i') . ' ===';
    $new_entry = $header . "\n" . $note_text;

    // Prepend so newest note appears first
    $db->prepare(
        'UPDATE patients
         SET medical_notes = CONCAT(?, "\n\n", COALESCE(NULLIF(medical_notes,""), ""))
         WHERE patient_id = ?'
    )->execute([$new_entry, $patient_id]);

    write_audit_log('UPDATE', 'patients', $patient_id, null, [
        'action' => 'doctor_note_added', 'doctor_id' => (int)($_SESSION['doctor_id'] ?? 0),
    ]);
    flash_set('success', 'Note permanently added to patient record.');
    redirect('/pages/patient_profile.php?id=' . $patient_id . '#tab-history');
}

// Handle payment POST (Patient pays own bill; staff can pay for any)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_bill'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        flash_set('error', 'Invalid request.');
    } else {
        $pay_bill_id  = (int)($_POST['pay_bill_id']     ?? 0);
        $pay_amount   = (float)($_POST['pay_amount']    ?? 0);
        $pay_method   = trim($_POST['pay_method']       ?? '');
        $pay_ref      = trim($_POST['pay_ref']          ?? '');

        $allowed_methods = ['Cash', 'Medical Aid', 'Card', 'Account'];
        $bill_chk = $db->prepare('SELECT bill_id, account_balance, payment_status FROM billing_records WHERE bill_id = ? AND patient_id = ? LIMIT 1');
        $bill_chk->execute([$pay_bill_id, $patient_id]);
        $bill_row = $bill_chk->fetch();

        if (!$bill_row) {
            flash_set('error', 'Billing record not found.');
        } elseif ($bill_row['payment_status'] === 'Paid') {
            flash_set('error', 'This bill is already paid in full.');
        } elseif ($pay_amount <= 0) {
            flash_set('error', 'Payment amount must be greater than zero.');
        } elseif (!in_array($pay_method, $allowed_methods, true)) {
            flash_set('error', 'Invalid payment method.');
        } else {
            $db->prepare(
                'INSERT INTO payments (bill_id, amount_paid, payment_method, payment_date, reference_no)
                 VALUES (?, ?, ?, CURDATE(), ?)'
            )->execute([$pay_bill_id, $pay_amount, $pay_method, $pay_ref ?: null]);

            write_audit_log('INSERT', 'payments', (int)$db->lastInsertId(), null, [
                'bill_id' => $pay_bill_id, 'amount_paid' => $pay_amount,
                'method' => $pay_method, 'patient_id' => $patient_id,
            ]);
            flash_set('success', 'Payment of R' . number_format($pay_amount, 2) . ' recorded successfully.');
        }
    }
    redirect('/pages/patient_profile.php?id=' . $patient_id);
}

require_once '../includes/header.php';
?>

<!--  Patient record tab layout  -->

<!-- Page header -->
<div class="page-header" style="margin-bottom:16px;">
    <h1><?= h($patient['first_name'] . ' ' . $patient['last_name']) ?></h1>
    <p style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <span>Patient ID: <strong>#<?= (int)$patient_id ?></strong></span>
        <?php if ($patient['blood_group']): ?>
        <span class="badge-role badge-doctor"><?= h($patient['blood_group']) ?></span>
        <?php endif; ?>
        <span class="text-muted"><?= $patient['gender'] ? h($patient['gender']) : '' ?><?= $patient['date_of_birth'] ? ' &bull; DOB: ' . date('d M Y', strtotime($patient['date_of_birth'])) : '' ?></span>
        <?php
        $total_owing_hdr = 0;
        foreach ($bills as $b) { $total_owing_hdr += (float)$b['account_balance']; }
        ?>
        <?php if ($total_owing_hdr > 0): ?>
        <span style="color:var(--danger);font-weight:700;font-size:.85rem;">
            &#9888; Outstanding: R<?= number_format($total_owing_hdr, 2) ?>
        </span>
        <?php endif; ?>
    </p>
</div>

<!-- Two-column layout: inner tab sidebar + content -->
<div style="display:flex;gap:0;align-items:flex-start;">

    <!--  Inner tab sidebar  -->
    <nav id="profile-tabs"
         style="width:200px;flex-shrink:0;background:var(--card-bg);border:1px solid var(--border);
                border-radius:var(--radius-lg);overflow:hidden;position:sticky;top:calc(var(--topbar-height) + 16px);
                box-shadow:var(--shadow);">

        <?php
        $tabs = [
            ['id' => 'tab-profile',  'icon' => '&#128100;', 'label' => 'Profile'],
            ['id' => 'tab-history',  'icon' => '&#128203;', 'label' => 'Medical History',
             'badge' => count($visit_history)],
            ['id' => 'tab-rx',       'icon' => '&#128138;', 'label' => 'Prescriptions',
             'badge' => count($prescriptions)],
            ['id' => 'tab-billing',  'icon' => '&#128179;', 'label' => 'Billing',
             'badge' => $total_owing_hdr > 0 ? 'R' . number_format($total_owing_hdr, 2) : null,
             'badge_danger' => $total_owing_hdr > 0],
        ];
        ?>

        <?php foreach ($tabs as $t): ?>
        <a href="#<?= $t['id'] ?>"
           class="profile-tab-item <?= ($t['id'] === 'tab-profile') ? 'active' : '' ?>"
           onclick="switchTab('<?= $t['id'] ?>'); return false;"
           style="display:flex;align-items:center;justify-content:space-between;gap:8px;
                  padding:12px 16px;text-decoration:none;font-size:.88rem;font-weight:500;
                  color:var(--text-muted);border-left:3px solid transparent;
                  transition:background .15s,color .15s,border-color .15s;">
            <span style="display:flex;align-items:center;gap:8px;">
                <span style="font-size:1rem;"><?= $t['icon'] ?></span>
                <?= $t['label'] ?>
            </span>
            <?php if (!empty($t['badge'])): ?>
            <span style="font-size:.7rem;font-weight:700;padding:2px 7px;border-radius:12px;
                         background:<?= !empty($t['badge_danger']) ? 'var(--danger)' : 'var(--primary)' ?>;
                         color:#fff;white-space:nowrap;">
                <?= h((string)$t['badge']) ?>
            </span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>

        <?php if ($role !== 'Patient'): ?>
        <div style="border-top:1px solid var(--border);padding:10px 12px;">
            <a href="<?= BASE_URL ?>/pages/appointment_book.php?patient_id=<?= (int)$patient_id ?>"
               class="btn btn-primary btn-sm" style="width:100%;text-align:center;">
                + Book Appointment
            </a>
        </div>
        <?php endif; ?>
    </nav>

    <!--  Tab content area  -->
    <div style="flex:1;min-width:0;padding-left:20px;">

        <!--  TAB 1 — PROFILE EDIT  -->
        <div id="tab-profile" class="profile-tab-panel">
        <div class="card">
            <div class="card-header"><h2>Edit Profile</h2></div>
            <div class="card-body">

                <form method="POST"
                      action="<?= BASE_URL ?>/pages/patient_profile.php?id=<?= (int)$patient_id ?>"
                      data-validate data-offline-sync="patients/update" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="patient_id_hidden"   value="<?= (int)$patient_id ?>">
                    <input type="hidden" name="record_updated_at"   value="<?= h($patient['updated_at'] ?? '') ?>">

                    <?php if (!empty($errors['_conflict'])): ?>
                    <div class="alert alert-error" style="margin-bottom:16px;">
                        <?= h($errors['_conflict']) ?>
                    </div>
                    <?php endif; ?>

                    <!--  Personal Details  -->
                    <fieldset style="border:1px solid var(--primary);margin-bottom:8px;padding:16px;border-radius:4px;">
                        <legend style="font-weight:700;color:var(--primary);margin-bottom:16px;font-size:1rem;">
                            Personal Details
                        </legend>
                        <div class="form-grid">

                            <div class="form-group">
                                <label for="first_name" class="required">First Name</label>
                                <input type="text" id="first_name" name="first_name" required maxlength="50"
                                       value="<?= h($patient['first_name']) ?>">
                                <span class="field-error"><?= h($errors['first_name'] ?? '') ?></span>
                            </div>

                            <div class="form-group">
                                <label for="last_name" class="required">Last Name</label>
                                <input type="text" id="last_name" name="last_name" required maxlength="50"
                                       value="<?= h($patient['last_name']) ?>">
                                <span class="field-error"><?= h($errors['last_name'] ?? '') ?></span>
                            </div>

                            <div class="form-group">
                                <label for="id_number" class="required">SA ID Number</label>
                                <input type="text" id="id_number" name="id_number" required
                                       maxlength="13" pattern="\d{13}" placeholder="13-digit SA ID"
                                       data-sa-id value="<?= h($patient['id_number'] ?? '') ?>">
                                <span class="field-error"><?= h($errors['id_number'] ?? '') ?></span>
                            </div>

                            <div class="form-group">
                                <label for="dob" class="required">Date of Birth</label>
                                <input type="date" id="dob" name="dob" required
                                       max="<?= date('Y-m-d') ?>"
                                       value="<?= h($patient['date_of_birth']) ?>">
                                <span class="field-error"><?= h($errors['dob'] ?? '') ?></span>
                            </div>

                            <div class="form-group">
                                <label for="gender" class="required">Gender</label>
                                <select id="gender" name="gender" required>
                                    <option value="">— Select —</option>
                                    <?php foreach (['Male', 'Female', 'Other'] as $g): ?>
                                    <option value="<?= $g ?>" <?= ($patient['gender'] === $g) ? 'selected' : '' ?>>
                                        <?= $g ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="field-error"><?= h($errors['gender'] ?? '') ?></span>
                            </div>

                            <div class="form-group">
                                <label for="blood_group">Blood Group</label>
                                <select id="blood_group" name="blood_group">
                                    <option value="">— Unknown —</option>
                                    <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bg): ?>
                                    <option value="<?= $bg ?>"
                                        <?= ($patient['blood_group'] === $bg) ? 'selected' : '' ?>>
                                        <?= $bg ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="phone" class="required">Phone</label>
                                <input type="tel" id="phone" name="phone" required
                                       maxlength="10"
                                       value="<?= h($patient['phone'] ?? '') ?>">
                                <span class="field-error"><?= h($errors['phone'] ?? '') ?></span>
                            </div>

                            <div class="form-group">
                                <label for="email" class="required">Email</label>
                                <input type="email" id="email" name="email" maxlength="100"
                                       value="<?= h($patient['email'] ?? '') ?>">
                                <span class="field-error"><?= h($errors['email'] ?? '') ?></span>
                            </div>

                            <?php if (in_array($role, ['SuperAdmin','Admin','Doctor','Nurse'], true) && $patient['medical_notes']): ?>
                            <div class="form-group full-width">
                                <label>Clinical Notes / Medical History</label>
                                <div style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);
                                            padding:10px 14px;font-size:.88rem;white-space:pre-wrap;max-height:160px;
                                            overflow-y:auto;color:var(--text-muted);">
                                    <?= h($patient['medical_notes']) ?>
                                </div>
                                <span class="field-hint">Notes are only added by assigned Doctor.</span>
                            </div>
                            <?php endif; ?>

                        </div>
                    </fieldset>


                    <!-- Address  -->
                    <fieldset style="border:1px solid var(--primary);margin-bottom:8px;padding:16px;border-radius:4px;">
                        <legend style="font-weight:700;color:var(--primary);margin-bottom:16px;font-size:1rem;">
                            Physical Address
                        </legend>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="address_line1" class="required">Address Line 1</label>
                                <input type="text" id="address_line1" name="address_line_1" required
                                       maxlength="100"
                                       value="<?= h($address_line1) ?>">
                                <span class="field-error"><?= h($errors['address_line_1'] ?? '') ?></span>
                            </div>

                            <div class="form-group">
                                <label for="address_line2" >Address Line 2</label>
                                <input type="text" id="address_line2" name="address_line_2"
                                       maxlength="100"
                                       value="<?= h($address_line2) ?>">
                                <span class="field-error"><?= h($errors['address_line_2'] ?? '') ?></span>
                            </div>

                            <div class="form-group">
                                <label for="city" class="required">City/Suburb</label>
                                <input type="text" id="city" name="city" required
                                       maxlength="100"
                                       value="<?= h($city) ?>">
                                <span class="field-error"><?= h($errors['city'] ?? '') ?></span>
                            </div>

                            <div class="form-group">
                                <label for="postal_code" class="required">Postal Code</label>
                                <input type="text" id="postal_code" name="postal_code" required
                                       maxlength="100"
                                       value="<?= h($postal_code) ?>">
                                <span class="field-error"><?= h($errors['postal_code'] ?? '') ?></span>
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
                               value="<?= h($patient['emergency_contact_name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="ec_phone">Contact Phone</label>
                        <input type="tel" id="ec_phone" name="ec_phone"
                               maxlength="10"
                               value="<?= h($patient['emergency_contact_phone'] ?? '') ?>">
                        <span class="field-error"><?= h($errors['ec_phone'] ?? '') ?></span>
                    </div>

                    <div class="form-group">
                        <label for="ec_relationship">Relationship with Patient</label>
                        <select id="ec_relationship" name="ec_relationship">
                            <option value="">Not Selected</option>
                            <?php foreach (['Parent', 'Sibling', 'Spouse', 'Child', 'Friend', 'Other'] as $rel): ?>
                            <option value="<?= $rel ?>"
                                <?= (($patient['emergency_contact_relationship'] ?? '') === $rel) ? 'selected' : '' ?>>
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

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <a href="<?= BASE_URL ?>/pages/patient_profile.php" class="btn btn-secondary">
                            Back to List
                        </a>
                    </div>

                </form>
            </div>
        </div>
        </div><!-- /#tab-profile ending -->

        <!--  TAB 2 — MEDICAL HISTORY  -->
        <div id="tab-history" class="profile-tab-panel" style="display:none;">
        <div class="card">
            <div class="card-header">
                <h2>&#128203; Medical History &amp; Visit Log</h2>
                <?php if ($role !== 'Patient'): ?>
                <a href="<?= BASE_URL ?>/pages/appointment_book.php?patient_id=<?= (int)$patient_id ?>"
                   class="btn btn-primary btn-sm">+ Book Visit</a>
                <?php endif; ?>
            </div>
            <div class="card-body" style="padding:0;">

                <?php if (empty($visit_history)): ?>
                <div class="text-center text-muted" style="padding:32px;">
                    No visit history found for this patient.
                </div>
                <?php else: ?>

                <?php foreach ($visit_history as $i => $visit): ?>
                <div style="border-bottom:1px solid var(--border);">

                    <div style="display:flex;align-items:center;gap:16px;padding:14px 20px;cursor:pointer;
                                background:<?= ($i % 2 === 0) ? '#fafbfc' : '#fff' ?>;"
                         onclick="toggleVisit(<?= (int)$visit['appointment_id'] ?>)">

                        <span class="status-badge status-<?= strtolower(h($visit['status'])) ?>"
                              style="flex-shrink:0;"><?= h($visit['status']) ?></span>

                        <div style="flex:1;">
                            <strong><?= h(date('d M Y', strtotime($visit['appointment_date']))) ?></strong>
                            <span class="text-muted" style="font-size:.85rem;">
                                &nbsp;at <?= h(date('H:i', strtotime($visit['appointment_time']))) ?>
                            </span>
                            &nbsp;&mdash;&nbsp;
                            <span style="font-size:.9rem;">
                                Dr <?= h($visit['doctor_name']) ?>
                                <?= $visit['specialization'] ? '<span class="text-muted">(' . h($visit['specialization']) . ')</span>' : '' ?>
                            </span>
                            <?php if ($visit['nurse_name']): ?>
                            &nbsp;&bull;&nbsp;<span class="text-muted" style="font-size:.85rem;">Nurse: <?= h($visit['nurse_name']) ?></span>
                            <?php endif; ?>
                        </div>

                        <span style="font-size:.75rem;color:var(--text-muted);">
                            <?= $visit['reason'] ? '&ldquo;' . h(mb_substr($visit['reason'], 0, 50)) . (mb_strlen($visit['reason']) > 50 ? '&hellip;' : '') . '&rdquo;' : '' ?>
                        </span>

                        <span style="font-size:1.1rem;color:var(--text-muted);"
                              id="chevron-<?= (int)$visit['appointment_id'] ?>">&#8250;</span>
                    </div>

                    <div id="visit-<?= (int)$visit['appointment_id'] ?>"
                         style="display:none;padding:18px 24px 20px;background:#fff;">

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:16px;">
                            <div>
                                <p style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:6px;">Reason / Chief Complaint</p>
                                <p style="font-size:.92rem;"><?= $visit['reason'] ? h($visit['reason']) : '<em class="text-muted">Not recorded</em>' ?></p>
                            </div>
                            <div>
                                <p style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:6px;">
                                    Doctor&rsquo;s Notes
                                    <?php if ($visit['status'] === 'Completed'): ?>
                                    <span style="color:var(--info);font-size:.72rem;">(read-only after completion)</span>
                                    <?php endif; ?>
                                </p>
                                <?php if ($visit['notes']): ?>
                                <p style="font-size:.92rem;white-space:pre-wrap;"><?= h($visit['notes']) ?></p>
                                <?php else: ?>
                                <p class="text-muted" style="font-size:.88rem;"><em>No notes recorded yet.</em></p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php
                        $visit_rxs = array_filter($prescriptions, fn($rx) =>
                            $rx['doctor_name'] === $visit['doctor_name'] &&
                            abs(strtotime($rx['issued_date']) - strtotime($visit['appointment_date'])) <= 86400 * 3
                        );
                        ?>
                        <?php if (!empty($visit_rxs)): ?>
                        <div style="margin-top:12px;">
                            <p style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:8px;">Prescriptions Issued</p>
                            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px;">
                                <?php foreach ($visit_rxs as $rx): ?>
                                <div style="background:#f0f8ff;border:1px solid #b3d9f7;border-radius:8px;padding:12px 14px;">
                                    <p style="font-weight:700;color:var(--primary);margin-bottom:4px;font-size:.9rem;">
                                        &#128138; <?= h($rx['medication']) ?>
                                    </p>
                                    <p style="font-size:.82rem;color:var(--text-muted);margin-bottom:2px;">
                                        <?= h($rx['dosage']) ?> &bull; Qty: <?= (int)$rx['quantity'] ?>
                                    </p>
                                    <?php if ($rx['instructions']): ?>
                                    <p style="font-size:.8rem;"><?= h($rx['instructions']) ?></p>
                                    <?php endif; ?>
                                    <p style="font-size:.75rem;margin-top:6px;">
                                        <span class="status-badge status-<?= strtolower(h($rx['status'])) ?>"><?= h($rx['status']) ?></span>
                                        &nbsp;&bull;&nbsp;<?= h(date('d M Y', strtotime($rx['issued_date']))) ?>
                                        <?php if ($rx['nurse_name']): ?>
                                        &nbsp;&bull;&nbsp;Nurse: <?= h($rx['nurse_name']) ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <p class="text-muted" style="font-size:.85rem;margin-top:10px;">No prescriptions were issued for this visit.</p>
                        <?php endif; ?>

                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

            </div>
        </div>

        <!--  Doctor's Notes  -->
        <div class="card" style="margin-top:20px;">
            <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
                <h2>&#128221; Doctor's Notes</h2>
                <span class="text-muted" style="font-size:.82rem;">Permanent record — cannot be edited or deleted</span>
            </div>
            <div class="card-body">

                <?php if ($role === 'Doctor'): ?>
                <form method="POST"
                      action="<?= BASE_URL ?>/pages/patient_profile.php?id=<?= (int)$patient_id ?>"
                      style="margin-bottom:24px;padding-bottom:20px;border-bottom:1px solid var(--border);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="add_note" value="1">
                    <div class="form-group">
                        <label for="note_text" class="required">New Clinical Note</label>
                        <textarea id="note_text" name="note_text" rows="4" required
                                  placeholder="Clinical observations, diagnoses, treatment plan, follow-up instructions..."
                                  style="width:100%;"></textarea>
                        <span class="field-hint">
                            Your name and the date/time will be stamped automatically.
                            This note is <strong>permanent</strong> — it cannot be edited or removed.
                        </span>
                    </div>
                    <button type="submit" class="btn btn-primary">Add Note</button>
                </form>
                <?php endif; ?>

                <?php
                $raw_notes = trim($patient['medical_notes'] ?? '');
                if ($raw_notes !== ''):
                    // Split on === headers; each block starts with one
                    $blocks = preg_split('/(?=^===)/m', $raw_notes, -1, PREG_SPLIT_NO_EMPTY);
                ?>
                <div style="display:flex;flex-direction:column;gap:12px;">
                    <?php foreach ($blocks as $block):
                        $block = trim($block);
                        if ($block === '') continue;
                        if (preg_match('/^===\s*(.+?)\s*===\s*\n?(.*)/s', $block, $m)) {
                            $note_header = trim($m[1]);
                            $note_body   = trim($m[2]);
                        } else {
                            $note_header = null;
                            $note_body   = $block;
                        }
                    ?>
                    <div style="background:#f8fafc;border:1px solid var(--border);
                                border-left:4px solid var(--primary);border-radius:8px;padding:14px 16px;">
                        <?php if ($note_header): ?>
                        <p style="font-size:.8rem;font-weight:700;color:var(--primary);margin-bottom:8px;">
                            &#128221; <?= h($note_header) ?>
                        </p>
                        <?php endif; ?>
                        <p style="font-size:.9rem;white-space:pre-wrap;margin:0;"><?= h($note_body) ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-center text-muted" style="padding:24px;">
                    No clinical notes have been recorded for this patient yet.
                    <?php if ($role !== 'Doctor'): ?>
                    A doctor must add the first note.
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            </div>
        </div><!-- /doctor notes card ending -->

        </div><!-- /#tab-history ending -->

        <!--  TAB 3 — ALL PRESCRIPTIONS  -->
        <div id="tab-rx" class="profile-tab-panel" style="display:none;">
        <div class="card">
            <div class="card-header">
                <h2>&#128138; All Prescriptions</h2>
                <span class="text-muted" style="font-size:.85rem;"><?= count($prescriptions) ?> record(s)</span>
            </div>
            <div class="card-body" style="padding:0;">
                <?php if (empty($prescriptions)): ?>
                <div class="text-center text-muted" style="padding:32px;">No prescriptions found.</div>
                <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Medication</th>
                                <th>Dosage</th>
                                <th>Qty</th>
                                <th>Doctor</th>
                                <th>Nurse</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($prescriptions as $rx): ?>
                        <tr>
                            <td style="white-space:nowrap;"><?= h(date('d M Y', strtotime($rx['issued_date']))) ?></td>
                            <td><strong><?= h($rx['medication']) ?></strong>
                                <?php if ($rx['instructions']): ?>
                                <br><small class="text-muted"><?= h($rx['instructions']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= h($rx['dosage']) ?></td>
                            <td><?= (int)$rx['quantity'] ?></td>
                            <td>Dr <?= h($rx['doctor_name']) ?>
                                <?php if ($rx['specialization']): ?>
                                <br><small class="text-muted"><?= h($rx['specialization']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= $rx['nurse_name'] ? h($rx['nurse_name']) : '<span class="text-muted">—</span>' ?></td>
                            <td><span class="status-badge status-<?= strtolower(h($rx['status'])) ?>"><?= h($rx['status']) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        </div><!-- /#tab-rx ending -->

        <!--  TAB 4 — BILLING & PAYMENTS  -->
        <div id="tab-billing" class="profile-tab-panel" style="display:none;">
        <div class="card" style="margin-bottom:48px;">
            <div class="card-header">
                <h2>&#128179; Billing &amp; Payments</h2>
                <?php if ($total_owing_hdr > 0): ?>
                <span style="font-size:.9rem;font-weight:700;color:var(--danger);">
                    Outstanding: R<?= number_format($total_owing_hdr, 2) ?>
                </span>
                <?php else: ?>
                <span style="font-size:.9rem;font-weight:700;color:var(--success);">&#10003; Account clear</span>
                <?php endif; ?>
            </div>

            <?php if (empty($bills)): ?>
            <div class="card-body text-center text-muted">No billing records found.</div>

            <?php else: ?>
            <?php foreach ($bills as $bill): ?>
            <?php
                $bill_pmts    = $payments_by_bill[(int)$bill['bill_id']] ?? [];
                $is_paid      = ($bill['payment_status'] === 'Paid');
                $balance      = (float)$bill['account_balance'];
                $monthly_inst = ($bill['account_term'] > 0) ? round($balance / $bill['account_term'], 2) : null;
            ?>

            <div style="border-bottom:1px solid var(--border);padding:20px 24px;">

                <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:14px;">
                    <div style="flex:1;">
                        <span style="font-size:.8rem;color:var(--text-muted);">Bill #<?= (int)$bill['bill_id'] ?></span>
                        <?php if ($bill['appointment_date']): ?>
                        &bull; <span style="font-size:.8rem;color:var(--text-muted);">Visit: <?= h(date('d M Y', strtotime($bill['appointment_date']))) ?></span>
                        <?php endif; ?>
                        &bull; <span style="font-size:.8rem;color:var(--text-muted);">Issued: <?= h(date('d M Y', strtotime($bill['billing_date']))) ?></span>
                    </div>
                    <span class="status-badge <?= $is_paid ? 'status-completed' : ($bill['payment_status'] === 'Account' ? 'status-scheduled' : 'status-cancelled') ?>">
                        <?= h($bill['payment_status']) ?>
                    </span>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:14px;margin-bottom:16px;">
                    <div style="background:var(--bg);border-radius:8px;padding:12px 14px;">
                        <p style="font-size:.75rem;color:var(--text-muted);margin-bottom:4px;">Total Invoice</p>
                        <p style="font-size:1.2rem;font-weight:800;">R<?= number_format((float)$bill['amount'], 2) ?></p>
                    </div>
                    <div style="background:<?= $balance > 0 ? 'var(--danger-light)' : 'var(--success-light)' ?>;border-radius:8px;padding:12px 14px;">
                        <p style="font-size:.75rem;color:var(--text-muted);margin-bottom:4px;">Balance Owing</p>
                        <p style="font-size:1.2rem;font-weight:800;color:<?= $balance > 0 ? 'var(--danger)' : 'var(--success)' ?>;">R<?= number_format($balance, 2) ?></p>
                    </div>
                    <?php if ($bill['account_term'] && $monthly_inst !== null): ?>
                    <div style="background:var(--info-light);border-radius:8px;padding:12px 14px;">
                        <p style="font-size:.75rem;color:var(--text-muted);margin-bottom:4px;">Monthly Instalment</p>
                        <p style="font-size:1.2rem;font-weight:800;color:var(--info);">R<?= number_format($monthly_inst, 2) ?></p>
                        <p style="font-size:.72rem;color:var(--text-muted);"><?= (int)$bill['account_term'] ?>-month &bull; 0% interest</p>
                    </div>
                    <?php endif; ?>
                    <div style="background:var(--bg);border-radius:8px;padding:12px 14px;">
                        <p style="font-size:.75rem;color:var(--text-muted);margin-bottom:4px;">Paid To Date</p>
                        <p style="font-size:1.2rem;font-weight:800;color:var(--success);">R<?= number_format((float)$bill['amount'] - $balance, 2) ?></p>
                    </div>
                </div>

                <?php if (!empty($bill_pmts)): ?>
                <details style="margin-bottom:14px;">
                    <summary style="cursor:pointer;font-size:.85rem;font-weight:600;color:var(--secondary);margin-bottom:8px;">
                        &#8595; <?= count($bill_pmts) ?> payment(s) made
                    </summary>
                    <table style="font-size:.82rem;width:100%;border-collapse:collapse;margin-top:8px;">
                        <thead>
                            <tr style="background:var(--bg);">
                                <th style="padding:6px 12px;text-align:left;border-bottom:1px solid var(--border);">Date</th>
                                <th style="padding:6px 12px;text-align:left;border-bottom:1px solid var(--border);">Amount</th>
                                <th style="padding:6px 12px;text-align:left;border-bottom:1px solid var(--border);">Method</th>
                                <th style="padding:6px 12px;text-align:left;border-bottom:1px solid var(--border);">Reference</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($bill_pmts as $pmt): ?>
                        <tr style="border-bottom:1px solid var(--border);">
                            <td style="padding:6px 12px;"><?= h(date('d M Y', strtotime($pmt['payment_date']))) ?></td>
                            <td style="padding:6px 12px;font-weight:700;">R<?= number_format((float)$pmt['amount_paid'], 2) ?></td>
                            <td style="padding:6px 12px;"><?= h($pmt['payment_method']) ?></td>
                            <td style="padding:6px 12px;color:var(--text-muted);"><?= $pmt['reference_no'] ? h($pmt['reference_no']) : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
                <?php endif; ?>

                <?php if (!$is_paid && $balance > 0): ?>
                <div style="background:var(--primary-light);border-radius:10px;padding:18px 20px;margin-top:8px;">
                    <p style="font-weight:700;color:var(--primary);margin-bottom:14px;font-size:.95rem;">
                        &#128179; Make a Payment — Bill #<?= (int)$bill['bill_id'] ?>
                    </p>
                    <form method="POST"
                          action="<?= BASE_URL ?>/pages/patient_profile.php?id=<?= (int)$patient_id ?>"
                          data-validate novalidate
                          style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px;align-items:end;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="pay_bill"    value="1">
                        <input type="hidden" name="pay_bill_id" value="<?= (int)$bill['bill_id'] ?>">

                        <div class="form-group" style="margin:0;">
                            <label style="font-size:.82rem;" class="required">Amount (R)</label>
                            <input type="number" name="pay_amount" required
                                   min="1" max="<?= number_format($balance, 2, '.', '') ?>"
                                   step="0.01"
                                   placeholder="<?= $monthly_inst ? number_format($monthly_inst, 2) : number_format($balance, 2) ?>"
                                   value="<?= $monthly_inst ? number_format($monthly_inst, 2, '.', '') : '' ?>">
                            <span class="field-error"></span>
                        </div>

                        <div class="form-group" style="margin:0;">
                            <label style="font-size:.82rem;" class="required">Payment Method</label>
                            <select name="pay_method" required>
                                <option value="">— Select —</option>
                                <option value="Cash">Cash</option>
                                <option value="Card">Card</option>
                                <option value="Medical Aid">Medical Aid</option>
                                <option value="Account">Account / EFT</option>
                            </select>
                            <span class="field-error"></span>
                        </div>

                        <div class="form-group" style="margin:0;">
                            <label style="font-size:.82rem;">Reference No.</label>
                            <input type="text" name="pay_ref" maxlength="100"
                                   placeholder="Bank / Medical Aid ref (optional)">
                        </div>

                        <div style="align-self:end;">
                            <button type="submit" class="btn btn-success" style="width:100%;">Confirm Payment</button>
                        </div>
                    </form>
                    <?php if ($bill['account_term']): ?>
                    <p style="font-size:.78rem;color:var(--text-muted);margin-top:10px;">
                        &#128712; Government subsidy: 0% interest on account plans up to 12 months.
                        <?php if ($monthly_inst): ?>Suggested instalment: <strong>R<?= number_format($monthly_inst, 2) ?></strong>/month.<?php endif; ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php elseif ($is_paid): ?>
                <div style="display:flex;align-items:center;gap:8px;color:var(--success);font-weight:700;font-size:.9rem;margin-top:8px;">
                    &#10003; This bill has been paid in full.
                </div>
                <?php endif; ?>

            </div><!-- /bill row ending -->
            <?php endforeach; ?>
            <?php endif; ?>

        </div>
        </div><!-- /#tab-billing ending -->

    </div><!-- /.tab content area ending -->
</div><!-- /.two-column layout ending -->

<style>
.profile-tab-item.active {
    background: var(--primary-light);
    color: var(--primary) !important;
    border-left-color: var(--primary) !important;
    font-weight: 700;
}
.profile-tab-item:hover {
    background: var(--bg);
    color: var(--text) !important;
    text-decoration: none;
}
@media (max-width: 700px) {
    #profile-tabs { width: 100%; position: static; }
    [style*="display:flex;gap:0"] { flex-direction: column; }
    [style*="padding-left:20px"]  { padding-left: 0; padding-top: 16px; }
}
</style>

<script>
function switchTab(id) {
    // Hide all panels
    document.querySelectorAll('.profile-tab-panel').forEach(function(p) {
        p.style.display = 'none';
    });
    // Deactivate all tab links
    document.querySelectorAll('.profile-tab-item').forEach(function(a) {
        a.classList.remove('active');
    });
    // Show selected panel
    var panel = document.getElementById(id);
    if (panel) panel.style.display = 'block';
    // Activate the clicked link
    var link = document.querySelector('[onclick*="' + id + '"]');
    if (link) link.classList.add('active');
    // Persist in URL hash
    history.replaceState(null, '', '#' + id);
}

function toggleVisit(id) {
    var el  = document.getElementById('visit-' + id);
    var chv = document.getElementById('chevron-' + id);
    if (!el) return;
    var open = el.style.display !== 'none';
    el.style.display  = open ? 'none' : 'block';
    if (chv) chv.innerHTML = open ? '&#8250;' : '&#8964;';
}

// On load: restore tab from URL hash, or activate billing if redirected after payment
(function() {
    var hash = window.location.hash.replace('#', '');
    var valid = ['tab-profile','tab-history','tab-rx','tab-billing'];
    if (valid.indexOf(hash) !== -1) {
        switchTab(hash);
    }
    // If there is an outstanding balance badge on billing tab, auto-open it for patients
    <?php if ($role === 'Patient' && $total_owing_hdr > 0 && !isset($_GET['tab'])): ?>
    // Only auto-switch on first visit, not on form errors
    <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
    // Don't auto-switch — let patient land on Profile tab by default
    <?php endif; ?>
    <?php endif; ?>
}());
</script>

<?php require_once '../includes/footer.php'; ?>
