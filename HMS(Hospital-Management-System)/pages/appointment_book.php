<?php
/**
 * php/appointment_book.php — FR-03: Book an appointment
 *
 * GET : Show the booking form.
 * POST: Validate and insert the appointment.
 *
 * All roles can book an appointment.
 * - Patients can only book for themselves.
 * - Staff can pass ?patient_id=X to pre-fill.
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/audit.php';

require_login();

$page_title = 'Book Appointment';
$db         = get_db();
$role       = current_role();
$errors     = [];
$old        = [];

// Determine patient context
if ($role === 'Patient') {
    $fixed_patient_id = (int)($_SESSION['patient_id'] ?? 0);
} else {
    $fixed_patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
}

// Load all active doctors for dropdown
$stmt = $db->prepare(
    'SELECT doctor_id, first_name, last_name, specialization
     FROM doctors
     ORDER BY last_name, first_name'
);
$stmt->execute();
$all_doctors = $stmt->fetchAll();

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        flash_set('error', 'Invalid request. Please try again.');
        redirect('/pages/appointment_book.php');
    }

    $old = $_POST;

    // Determine patient_id
    if ($role === 'Patient') {
        $req_patient_id = (int)($_SESSION['patient_id'] ?? 0);
    } else {
        $req_patient_id = (int)($_POST['patient_id'] ?? 0);
    }

    $doctor_id   = (int)($_POST['doctor_id']   ?? 0);
    $appt_date   = trim($_POST['appt_date']    ?? '');
    $appt_time   = trim($_POST['appt_time']    ?? '');
    $reason      = trim($_POST['reason']       ?? '');

    // Server-side validation
    if ($req_patient_id <= 0) $errors['patient_id'] = 'Please select a patient.';
    if ($doctor_id      <= 0) $errors['doctor_id']  = 'Please select a doctor.';
    if ($appt_date      === '') {
        $errors['appt_date'] = 'Date is required.';
    } elseif ($appt_date < date('Y-m-d')) {
        $errors['appt_date'] = 'Appointment date cannot be in the past.';
    }
    if ($appt_time === '') $errors['appt_time'] = 'Time is required.';

    // Validate patient and doctor exist
    if (!isset($errors['patient_id'])) {
        $s = $db->prepare('SELECT patient_id FROM patients WHERE patient_id = ? LIMIT 1');
        $s->execute([$req_patient_id]);
        if (!$s->fetch()) $errors['patient_id'] = 'Selected patient does not exist.';
    }

    if (!isset($errors['doctor_id'])) {
        $s = $db->prepare('SELECT doctor_id FROM doctors WHERE doctor_id = ? LIMIT 1');
        $s->execute([$doctor_id]);
        if (!$s->fetch()) $errors['doctor_id'] = 'Selected doctor does not exist.';
    }

    // Prevent double-booking and insert atomically
    if (empty($errors)) {
        $db->beginTransaction();
        try {
            $s = $db->prepare(
                'SELECT appointment_id FROM appointments
                 WHERE doctor_id = ? AND appointment_date = ? AND appointment_time = ?
                   AND status NOT IN ("Declined","Cancelled")
                 LIMIT 1 FOR UPDATE'
            );
            $s->execute([$doctor_id, $appt_date, $appt_time]);
            if ($s->fetch()) {
                $db->rollBack();
                $errors['appt_time'] = 'This time slot is already booked for the selected doctor.';
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO appointments
                        (patient_id, doctor_id, appointment_date, appointment_time, reason, status)
                     VALUES (?, ?, ?, ?, ?, "Requested")'
                );
                $stmt->execute([$req_patient_id, $doctor_id, $appt_date, $appt_time, $reason ?: null]);
                $appt_id = (int)$db->lastInsertId();
                $db->commit();

                write_audit_log('INSERT', 'appointments', $appt_id, null, [
                    'patient_id'       => $req_patient_id,
                    'doctor_id'        => $doctor_id,
                    'appointment_date' => $appt_date,
                    'appointment_time' => $appt_time,
                    'reason'           => $reason,
                    'status'           => 'Scheduled',
                ]);

                flash_set('success', 'Booking made — appointment scheduled for ' . date('d M Y', strtotime($appt_date)) . '.');
                redirect('/pages/dashboard.php');
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}

// Load the pre-selected patient's details (from ?patient_id= prefill or a failed re-submit)
// so the search box can show it as already selected, rather than listing every patient.
$selected_patient = null;
if ($role !== 'Patient') {
    $preselect_id = (int)($old['patient_id'] ?? $fixed_patient_id);
    if ($preselect_id > 0) {
        $s = $db->prepare(
            'SELECT patient_id, first_name, last_name, id_number FROM patients WHERE patient_id = ? LIMIT 1'
        );
        $s->execute([$preselect_id]);
        $selected_patient = $s->fetch() ?: null;
    }
}

require_once '../includes/header.php';
?>

<div class="page-header">
    <h1>Book an Appointment</h1>
    <p>Manage appointments and bookings.</p>
</div>

<div class="card" style="max-width:720px;">
    <div class="card-header"><h2>Appointment Details</h2></div>
    <div class="card-body">

        <form method="POST" action="<?= BASE_URL ?>/pages/appointment_book.php"
              data-validate data-offline-sync="appointments/insert" novalidate>
            <?= csrf_field() ?>

            <div class="form-grid">

                <!-- Patient -->
                <?php if ($role === 'Patient'): ?>
                <input type="hidden" name="patient_id" value="<?= (int)$fixed_patient_id ?>">
                <?php else: ?>
                <div class="form-group full-width">
                    <label for="patient_search" class="required">Select Patient</label>

                    <div id="patient-selected-box" class="patient-selected-box"
                         style="<?= $selected_patient ? '' : 'display:none;' ?>">
                        <div>
                            <strong id="selected-patient-name">
                                <?= $selected_patient ? h($selected_patient['last_name'] . ', ' . $selected_patient['first_name']) : '' ?>
                            </strong>
                            <div class="field-hint" id="selected-patient-meta">
                                <?php if ($selected_patient): ?>
                                Patient #<?= (int)$selected_patient['patient_id'] ?><?= $selected_patient['id_number'] ? ' &middot; SA ID ' . h($selected_patient['id_number']) : '' ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="clearSelectedPatient()">Change</button>
                    </div>

                    <div id="patient-search-box" style="<?= $selected_patient ? 'display:none;' : '' ?>">
                        <div class="d-flex gap-8">
                            <input type="text" id="patient_search" autocomplete="off" class="required"
                                   placeholder="Enter SA ID number or Patient #"
                                   onkeydown="if (event.key === 'Enter') { event.preventDefault(); searchPatients(); }">
                            <button type="button" class="btn btn-secondary" onclick="searchPatients()">Search</button>
                        </div>
                        <div id="patient-search-results"></div>
                        <p class="field-hint">
                            Not sure? <a href="<?= BASE_URL ?>/pages/patient_profile.php" target="_blank" rel="noopener">Browse the patient list</a> for details.
                        </p>
                    </div>

                    <input type="hidden" id="patient_id" name="patient_id"
                           value="<?= (int)($old['patient_id'] ?? $fixed_patient_id) ?>">
                    <span class="field-error" id="patient-id-error"><?= h($errors['patient_id'] ?? '') ?></span>
                </div>
                <?php endif; ?>

                <!-- Doctor -->
                <div class="form-group full-width">
                    <label for="doctor_id" class="required">Doctor</label>
                    <select id="doctor_id" name="doctor_id" required>
                        <option value="">— Select Doctor —</option>
                        <?php foreach ($all_doctors as $d): ?>
                        <option value="<?= (int)$d['doctor_id'] ?>"
                            <?= ((int)($old['doctor_id'] ?? 0) === (int)$d['doctor_id']) ? 'selected' : '' ?>>
                            Dr <?= h($d['first_name'] . ' ' . $d['last_name']) ?>
                            <?= $d['specialization'] ? ' — ' . h($d['specialization']) : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field-error"><?= h($errors['doctor_id'] ?? '') ?></span>
                </div>

                <!-- Date -->
                <div class="form-group">
                    <label for="appt_date" class="required">Date</label>
                    <input type="date" id="appt_date" name="appt_date" required
                           min="<?= date('Y-m-d') ?>"
                           value="<?= h($old['appt_date'] ?? '') ?>">
                    <span class="field-error"><?= h($errors['appt_date'] ?? '') ?></span>
                </div>

                <!-- Time -->
                <div class="form-group">
                    <label for="appt_time" class="required">Time</label>
                    <input type="time" id="appt_time" name="appt_time" required
                           value="<?= h($old['appt_time'] ?? '') ?>">
                    <span class="field-error"><?= h($errors['appt_time'] ?? '') ?></span>
                </div>

                <!-- Reason -->
                <div class="form-group full-width">
                    <label for="reason">Reason / Chief Complaint</label>
                    <textarea id="reason" name="reason" rows="3"
                              placeholder="Briefly describe the reason for the visit..."><?= h($old['reason'] ?? '') ?></textarea>
                </div>

            </div><!-- /.form-grid -->

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Book Appointment</button>
                <a href="<?= BASE_URL ?>/pages/appointment_list.php" class="btn btn-secondary">Cancel</a>
            </div>

        </form>
    </div>
</div>

<?php if ($role !== 'Patient'): ?>
<script>
var _patientSearchTimer = null;

function searchPatients() {
    var q = document.getElementById('patient_search').value.trim();
    var resultsEl = document.getElementById('patient-search-results');
    resultsEl.innerHTML = '';
    if (!q) {
        resultsEl.innerHTML = '<p class="field-error">Please enter a search text...</p>';
        return;
    }

    resultsEl.innerHTML = '<p class="field-hint">Searching…</p>';

    fetch('<?= BASE_URL ?>/pages/patient_search.php?q=' + encodeURIComponent(q))
        .then(function (res) { return res.json(); })
        .then(function (patients) {
            if (!Array.isArray(patients) || patients.length === 0) {
                resultsEl.innerHTML = '<p class="field-hint">No matching patient found. Try the patient list link below.</p>';
                return;
            }
            resultsEl.innerHTML = '';
            patients.forEach(function (p) {
                var item = document.createElement('div');
                item.className = 'patient-result-item';
                item.innerHTML = '<strong></strong><span class="field-hint"></span>';
                item.querySelector('strong').textContent = p.name;
                item.querySelector('span').textContent = 'Patient #' + p.patient_id + (p.id_number ? ' · SA ID ' + p.id_number : '');
                item.addEventListener('click', function () {
                    selectPatient(p.patient_id, p.name, 'Patient #' + p.patient_id + (p.id_number ? ' · SA ID ' + p.id_number : ''));
                });
                resultsEl.appendChild(item);
            });
        })
        .catch(function () {
            resultsEl.innerHTML = '<p class="field-hint">Search failed. Please check your connection and try again.</p>';
        });
}

// Debounce 600ms time-delay live search as user inputs characters, to avoid excessive requests to the server.
var _patientSearchInput = document.getElementById('patient_search');
if (_patientSearchInput) {
    _patientSearchInput.addEventListener('input', function () {
        clearTimeout(_patientSearchTimer);
        _patientSearchTimer = setTimeout(searchPatients, 600); 
    });
}

function selectPatient(id, name, meta) {
    document.getElementById('patient_id').value = id;
    document.getElementById('selected-patient-name').textContent = name;
    document.getElementById('selected-patient-meta').textContent = meta;
    document.getElementById('patient-selected-box').style.display = '';
    document.getElementById('patient-search-box').style.display = 'none';
    document.getElementById('patient-search-results').innerHTML = '';
    document.getElementById('patient_search').value = '';
    var errEl = document.getElementById('patient-id-error');
    if (errEl) errEl.textContent = '';
    document.getElementById('patient_id').classList.remove('is-invalid');
}

function clearSelectedPatient() {
    document.getElementById('patient_id').value = '';
    document.getElementById('patient-selected-box').style.display = 'none';
    document.getElementById('patient-search-box').style.display = '';
    document.getElementById('patient_search').focus();
}

// Block submission until a patient has been selected.
// Registered before DOMContentLoaded so it runs ahead of main.js's and
// sync_manager.js's submit listeners — stopImmediatePropagation() keeps
// the offline-sync handler (which has its own fetch-based submit path)
// from bypassing this check when no patient has been picked.
document.querySelector('form[data-validate]').addEventListener('submit', function (e) {
    var patientIdField = document.getElementById('patient_id');
    if (patientIdField && !(parseInt(patientIdField.value, 10) > 0)) {
        e.preventDefault();
        e.stopImmediatePropagation();
        var errEl = document.getElementById('patient-id-error');
        if (errEl) errEl.textContent = 'Please search for and select a patient.';
        document.getElementById('patient-search-box').style.display = '';
        document.getElementById('patient-selected-box').style.display = 'none';
    }
});
</script>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
