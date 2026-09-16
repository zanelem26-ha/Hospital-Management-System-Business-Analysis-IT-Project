<?php
/**
 * php/prescriptions.php — FR-02: Prescription issuance & dispensing
 *
 * Doctor     : issue prescriptions for their patients; cancel own.
 * Nurse      : view all prescriptions (read-only).
 * Pharmacist : view issued prescriptions; mark as Dispensed.
 * Admin/SA   : view all; cancel any.
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/audit.php';

require_login();

$page_title = 'Prescriptions';
$db         = get_db();
$role       = current_role();
$errors     = [];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        flash_set('error', 'Invalid request. Please try again.');
        redirect('/pages/prescriptions.php');
    }

    $action = trim($_POST['action'] ?? '');

    // Create prescription (Doctor only)
    if ($action === 'create') {
        require_role(['Doctor']);

        $doctor_id    = (int)($_SESSION['doctor_id'] ?? 0);
        $patient_id   = (int)($_POST['patient_id']   ?? 0);
        $nurse_id     = (int)($_POST['nurse_id']     ?? 0) ?: null;
        $medication   = trim($_POST['medication']    ?? '');
        $dosage       = trim($_POST['dosage']        ?? '');
        $quantity     = (int)($_POST['quantity']     ?? 0);
        $instructions = trim($_POST['instructions']  ?? '');

        if (!$doctor_id)        $errors['_general']   = 'Doctor session missing — please log in again.';
        if (!$patient_id)       $errors['patient_id'] = 'Please select a patient.';
        if ($medication === '') $errors['medication']  = 'Medication name is required.';
        if ($dosage     === '') $errors['dosage']      = 'Dosage is required.';
        if ($quantity   <= 0)   $errors['quantity']    = 'Quantity must be greater than zero.';

        if (!isset($errors['patient_id']) && $patient_id) {
            $s = $db->prepare('SELECT patient_id FROM patients WHERE patient_id = ? LIMIT 1');
            $s->execute([$patient_id]);
            if (!$s->fetch()) $errors['patient_id'] = 'Selected patient does not exist.';
        }

        if (empty($errors)) {
            $stmt = $db->prepare(
                'INSERT INTO prescriptions
                     (patient_id, doctor_id, nurse_id, medication, dosage, quantity, instructions, issued_date, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), "Issued")'
            );
            $stmt->execute([
                $patient_id, $doctor_id, $nurse_id,
                $medication, $dosage, $quantity,
                $instructions ?: null,
            ]);
            $rx_id = (int)$db->lastInsertId();

            write_audit_log('INSERT', 'prescriptions', $rx_id, null, [
                'patient_id' => $patient_id, 'doctor_id' => $doctor_id,
                'medication' => $medication,  'dosage'    => $dosage,
                'quantity'   => $quantity,
            ]);

            flash_set('success', "Prescription for \"$medication\" issued successfully.");
            redirect('/pages/prescriptions.php');
        }

    // Dispense (Pharmacist / SuperAdmin / Admin)
    } elseif ($action === 'dispense') {
        require_role(['Pharmacist', 'SuperAdmin', 'Admin']);

        $rx_id = (int)($_POST['rx_id'] ?? 0);
        $s = $db->prepare('SELECT status FROM prescriptions WHERE rx_id = ? LIMIT 1');
        $s->execute([$rx_id]);
        $rx = $s->fetch();

        if (!$rx) {
            flash_set('error', 'Prescription not found.');
        } elseif ($rx['status'] !== 'Issued') {
            flash_set('error', 'Only "Issued" prescriptions can be dispensed.');
        } else {
            $db->prepare('UPDATE prescriptions SET status = "Dispensed" WHERE rx_id = ?')
               ->execute([$rx_id]);
            write_audit_log('UPDATE', 'prescriptions', $rx_id, ['status' => 'Issued'], ['status' => 'Dispensed']);
            flash_set('success', 'Prescription #' . $rx_id . ' marked as Dispensed.');
        }
        redirect('/pages/prescriptions.php');

    // Cancel (Doctor / Admin / SuperAdmin)
    } elseif ($action === 'cancel') {
        require_role(['Doctor', 'SuperAdmin', 'Admin']);

        $rx_id = (int)($_POST['rx_id'] ?? 0);
        $s = $db->prepare('SELECT status, doctor_id FROM prescriptions WHERE rx_id = ? LIMIT 1');
        $s->execute([$rx_id]);
        $rx = $s->fetch();

        if (!$rx) {
            flash_set('error', 'Prescription not found.');
        } elseif ($rx['status'] === 'Dispensed') {
            flash_set('error', 'Cannot cancel a prescription that has already been dispensed.');
        } elseif ($role === 'Doctor' && (int)$rx['doctor_id'] !== (int)($_SESSION['doctor_id'] ?? 0)) {
            flash_set('error', 'You can only cancel your own prescriptions.');
        } else {
            $db->prepare('UPDATE prescriptions SET status = "Cancelled" WHERE rx_id = ?')
               ->execute([$rx_id]);
            write_audit_log('UPDATE', 'prescriptions', $rx_id,
                ['status' => $rx['status']], ['status' => 'Cancelled']);
            flash_set('success', 'Prescription #' . $rx_id . ' cancelled.');
        }
        redirect('/pages/prescriptions.php');
    }
}

// Build list query (role-filtered)
$filter_status  = trim($_GET['status']  ?? '');
$filter_patient = trim($_GET['patient'] ?? '');

// Pharmacist defaults to 'Issued' view so they see what needs dispensing first
if ($role === 'Pharmacist' && $filter_status === '') {
    $filter_status = 'Issued';
}

$where  = [];
$params = [];

if ($role === 'Doctor') {
    $where[]  = 'rx.doctor_id = ?';
    $params[] = (int)($_SESSION['doctor_id'] ?? 0);
}

if ($filter_status !== '') {
    $where[]  = 'rx.status = ?';
    $params[] = $filter_status;
}

if ($filter_patient !== '') {
    $where[]  = '(p.first_name LIKE ? OR p.last_name LIKE ?)';
    $like     = '%' . $filter_patient . '%';
    $params[] = $like;
    $params[] = $like;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare(
    "SELECT rx.rx_id, rx.medication, rx.dosage, rx.quantity,
            rx.instructions, rx.issued_date, rx.status,
            rx.patient_id, rx.doctor_id, rx.nurse_id,
            CONCAT(p.first_name,' ',p.last_name)  AS patient_name,
            CONCAT(d.first_name,' ',d.last_name)  AS doctor_name,
            CONCAT(n.first_name,' ',n.last_name)  AS nurse_name
     FROM prescriptions rx
     JOIN patients p ON p.patient_id = rx.patient_id
     JOIN doctors  d ON d.doctor_id  = rx.doctor_id
     LEFT JOIN nurses n ON n.nurse_id = rx.nurse_id
     $where_sql
     ORDER BY rx.issued_date DESC, rx.rx_id DESC
     LIMIT 100"
);
$stmt->execute($params);
$prescriptions = $stmt->fetchAll();

// Data for create form (Doctor only)
$all_patients = [];
$all_nurses   = [];
if ($role === 'Doctor') {
    $all_patients = $db->query(
        'SELECT patient_id, first_name, last_name FROM patients ORDER BY last_name, first_name'
    )->fetchAll();
    $all_nurses = $db->query(
        'SELECT nurse_id, first_name, last_name FROM nurses ORDER BY last_name, first_name'
    )->fetchAll();
}

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-center">
    <div class="flex-1">
        <h1>Prescriptions</h1>
        <p>Manage prescriptions and dispensary.</p>
    </div>
</div>

<!--  Filters  -->
<form method="GET" class="d-flex gap-8 mb-24" style="flex-wrap:wrap;align-items:flex-end;">
    <div class="form-group" style="margin:0;min-width:180px;">
        <label style="font-size:.8rem;">Status</label>
        <select name="status" style="padding:8px 12px;">
            <option value="">All Statuses</option>
            <?php foreach (['Issued','Dispensed','Cancelled'] as $st): ?>
            <option value="<?= $st ?>" <?= ($filter_status === $st) ? 'selected' : '' ?>><?= $st ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group" style="margin:0;min-width:200px;">
        <label style="font-size:.8rem;">Patient Name</label>
        <input type="text" name="patient" placeholder="Search patient…"
               value="<?= h($filter_patient) ?>" style="padding:8px 12px;">
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <?php if ($filter_status !== '' || $filter_patient !== ''): ?>
    <a href="<?= BASE_URL ?>/pages/prescriptions.php" class="btn btn-secondary btn-sm">Clear</a>
    <?php endif; ?>
</form>

<!--  Prescriptions Table  -->
<div class="card mb-24">
    <div class="card-body" style="padding:0;">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Patient</th>
                        <th>Doctor</th>
                        <th>Medication</th>
                        <th>Dosage</th>
                        <th>Qty</th>
                        <th>Issued</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($prescriptions)): ?>
                    <tr><td colspan="9" class="text-center text-muted" style="padding:28px;">
                        No prescriptions found.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($prescriptions as $rx): ?>
                    <tr>
                        <td class="text-muted" style="font-size:.85rem;"><?= (int)$rx['rx_id'] ?></td>
                        <td>
                            <?php if (in_array($role, ['SuperAdmin','Admin','Doctor','Nurse','Pharmacist'], true)): ?>
                            <a href="<?= BASE_URL ?>/pages/patient_profile.php?id=<?= (int)$rx['patient_id'] ?>">
                                <?= h($rx['patient_name']) ?>
                            </a>
                            <?php else: ?>
                            <?= h($rx['patient_name']) ?>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.88rem;"><?= h('Dr ' . $rx['doctor_name']) ?></td>
                        <td><strong><?= h($rx['medication']) ?></strong></td>
                        <td style="font-size:.88rem;"><?= h($rx['dosage']) ?></td>
                        <td><?= (int)$rx['quantity'] ?></td>
                        <td style="white-space:nowrap;font-size:.88rem;">
                            <?= h(date('d M Y', strtotime($rx['issued_date']))) ?>
                        </td>
                        <td>
                            <span class="status-badge status-<?= strtolower(h($rx['status'])) ?>">
                                <?= h($rx['status']) ?>
                            </span>
                        </td>
                        <td style="white-space:nowrap;">
                            <?php if ($rx['status'] === 'Issued' && in_array($role, ['Pharmacist','SuperAdmin','Admin'], true)): ?>
                            <form method="POST" action="<?= BASE_URL ?>/pages/prescriptions.php" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action"  value="dispense">
                                <input type="hidden" name="rx_id"   value="<?= (int)$rx['rx_id'] ?>">
                                <button type="submit" class="btn btn-success btn-sm">Dispense</button>
                            </form>
                            <?php endif; ?>

                            <?php
                            $can_cancel = ($rx['status'] !== 'Dispensed') && (
                                in_array($role, ['SuperAdmin','Admin'], true) ||
                                ($role === 'Doctor' && (int)$rx['doctor_id'] === (int)($_SESSION['doctor_id'] ?? 0))
                            );
                            if ($can_cancel): ?>
                            <form method="POST" action="<?= BASE_URL ?>/pages/prescriptions.php" style="display:inline;"
                                  onsubmit="return confirmAction('Cancel this prescription?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action"  value="cancel">
                                <input type="hidden" name="rx_id"   value="<?= (int)$rx['rx_id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Cancel</button>
                            </form>
                            <?php endif; ?>

                            <?php if ($rx['status'] === 'Cancelled' || $rx['status'] === 'Dispensed'): ?>
                            <span class="text-muted" style="font-size:.8rem;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if (!empty($rx['instructions'])): ?>
                    <tr style="background:#fafbff;">
                        <td></td>
                        <td colspan="8" style="font-size:.82rem;color:var(--text-muted);padding-top:4px;padding-bottom:10px;">
                            <strong>Instructions:</strong> <?= h($rx['instructions']) ?>
                            <?php if ($rx['nurse_name']): ?>
                             &nbsp;|&nbsp; <strong>Attending Nurse:</strong> <?= h($rx['nurse_name']) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!--  Issue New Prescription (Doctor only)  -->
<?php if ($role === 'Doctor'): ?>
<div class="card">
    <div class="card-header"><h2>Issue New Prescription</h2></div>
    <div class="card-body">

        <?php if (!empty($errors['_general'])): ?>
        <div class="alert alert-error"><?= h($errors['_general']) ?></div>
        <?php endif; ?>

        <form method="POST" action="<?= BASE_URL ?>/pages/prescriptions.php" data-validate novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">

            <div class="form-grid">

                <div class="form-group full-width">
                    <label for="patient_id" class="required">Patient</label>
                    <select id="patient_id" name="patient_id" required>
                        <option value="">— Select Patient —</option>
                        <?php foreach ($all_patients as $p): ?>
                        <option value="<?= (int)$p['patient_id'] ?>"
                            <?= ((int)($_POST['patient_id'] ?? 0) === (int)$p['patient_id']) ? 'selected' : '' ?>>
                            <?= h($p['last_name'] . ', ' . $p['first_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field-error"><?= h($errors['patient_id'] ?? '') ?></span>
                </div>

                <div class="form-group">
                    <label for="medication" class="required">Medication</label>
                    <input type="text" id="medication" name="medication" required maxlength="255"
                           placeholder="e.g. Amoxicillin 500mg"
                           value="<?= h($_POST['medication'] ?? '') ?>">
                    <span class="field-error"><?= h($errors['medication'] ?? '') ?></span>
                </div>

                <div class="form-group">
                    <label for="dosage" class="required">Dosage</label>
                    <input type="text" id="dosage" name="dosage" required maxlength="100"
                           placeholder="e.g. 1 tablet 3× daily"
                           value="<?= h($_POST['dosage'] ?? '') ?>">
                    <span class="field-error"><?= h($errors['dosage'] ?? '') ?></span>
                </div>

                <div class="form-group">
                    <label for="quantity" class="required">Quantity</label>
                    <input type="number" id="quantity" name="quantity" required min="1"
                           value="<?= (int)($_POST['quantity'] ?? 1) ?>">
                    <span class="field-error"><?= h($errors['quantity'] ?? '') ?></span>
                </div>

                <div class="form-group">
                    <label for="nurse_id">Attending Nurse <span style="font-weight:400;">(optional)</span></label>
                    <select id="nurse_id" name="nurse_id">
                        <option value="">— None —</option>
                        <?php foreach ($all_nurses as $n): ?>
                        <option value="<?= (int)$n['nurse_id'] ?>"
                            <?= ((int)($_POST['nurse_id'] ?? 0) === (int)$n['nurse_id']) ? 'selected' : '' ?>>
                            <?= h($n['first_name'] . ' ' . $n['last_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group full-width">
                    <label for="instructions">Instructions / Notes</label>
                    <textarea id="instructions" name="instructions" rows="2"
                              maxlength="500"
                              placeholder="Take with food, avoid alcohol, etc."><?= h($_POST['instructions'] ?? '') ?></textarea>
                </div>

            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Issue Prescription</button>
            </div>
        </form>

    </div>
</div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
