<?php
/**
 * php/appointment_list.php — FR-03: View & manage appointments
 *
 * Workflow:
 *   Patient books           → Requested
 *   Doctor: Accept          → Confirmed
 *   Doctor: Amend           → Amended  (proposed_date/time set; patient must respond)
 *   Doctor: Decline         → Declined (reason stored in reason column)
 *   Patient (on Amended): Accept  → Confirmed (proposed date/time becomes the booking)
 *   Patient (on Amended): Decline → Declined
 *   Patient: cancel own Requested/Confirmed → Cancelled
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/audit.php';

require_login();

$page_title = 'Appointments';
$db         = get_db();
$role       = current_role();

// POST handler 
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        flash_set('error', 'Invalid request. Please try again.');
        redirect('/pages/appointment_list.php');
    }

    $action            = trim($_POST['action'] ?? (isset($_POST['update_status']) ? 'staff_update' : ''));
    $appt_id           = (int)($_POST['appointment_id'] ?? 0);
    $record_updated_at = trim($_POST['record_updated_at'] ?? '');

    // Load current appointment
    $s = $db->prepare('SELECT * FROM appointments WHERE appointment_id = ? LIMIT 1');
    $s->execute([$appt_id]);
    $cur = $s->fetch() ?: null;

    if (!$cur) {
        flash_set('error', 'Appointment not found.');
        redirect('/pages/appointment_list.php');
    }

    // Optimistic lock
    if ($record_updated_at !== '' && ($cur['updated_at'] ?? '') !== $record_updated_at) {
        flash_set('error', 'This appointment was updated by someone else while you had it open. Please review and try again.');
        redirect('/pages/appointment_list.php');
    }

    // Doctor: respond to Requested 
    if ($action === 'doctor_respond') {
        if ($role !== 'Doctor') {
            flash_set('error', 'Only doctors can respond to appointment requests.');
            redirect('/pages/appointment_list.php');
        }
        if ((int)$cur['doctor_id'] !== (int)($_SESSION['doctor_id'] ?? 0)) {
            flash_set('error', 'Access denied.');
            redirect('/pages/appointment_list.php');
        }
        if ($cur['status'] !== 'Requested') {
            flash_set('error', 'This appointment is no longer awaiting a doctor response.');
            redirect('/pages/appointment_list.php');
        }

        $response = trim($_POST['response'] ?? '');

        if ($response === 'Accept') {
            $db->prepare(
                'UPDATE appointments SET status = "Confirmed", updated_at = CURRENT_TIMESTAMP
                 WHERE appointment_id = ?'
            )->execute([$appt_id]);
            write_audit_log('UPDATE', 'appointments', $appt_id,
                ['status' => 'Requested'], ['status' => 'Confirmed']
            );
            flash_set('success', 'Appointment confirmed.');

        } elseif ($response === 'Amend') {
            $proposed_date = trim($_POST['proposed_date'] ?? '');
            $proposed_time = trim($_POST['proposed_time'] ?? '');
            if (!$proposed_date || !$proposed_time) {
                flash_set('error', 'Please provide both a proposed date and time.');
                redirect('/pages/appointment_list.php');
            }
            // Check proposed slot isn't already taken by another booking
            $s2 = $db->prepare(
                'SELECT appointment_id FROM appointments
                 WHERE doctor_id = ? AND appointment_date = ? AND appointment_time = ?
                   AND status NOT IN ("Declined","Cancelled")
                   AND appointment_id != ?
                 LIMIT 1'
            );
            $s2->execute([(int)$cur['doctor_id'], $proposed_date, $proposed_time, $appt_id]);
            if ($s2->fetch()) {
                flash_set('error', 'That proposed time slot is already booked. Please choose another.');
                redirect('/pages/appointment_list.php');
            }
            $db->prepare(
                'UPDATE appointments
                 SET status = "Amended", proposed_date = ?, proposed_time = ?,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE appointment_id = ?'
            )->execute([$proposed_date, $proposed_time, $appt_id]);
            write_audit_log('UPDATE', 'appointments', $appt_id,
                ['status' => 'Requested'],
                ['status' => 'Amended', 'proposed_date' => $proposed_date, 'proposed_time' => $proposed_time]
            );
            flash_set('success', 'Amendment proposed. Awaiting patient confirmation.');

        } elseif ($response === 'Decline') {
            $reason = trim($_POST['decline_reason'] ?? '');
            $db->prepare(
                'UPDATE appointments
                 SET status = "Declined", reason = ?,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE appointment_id = ?'
            )->execute([$reason ?: null, $appt_id]);
            write_audit_log('UPDATE', 'appointments', $appt_id,
                ['status' => 'Requested'],
                ['status' => 'Declined', 'reason' => $reason]
            );
            flash_set('success', 'Appointment declined.');
        } else {
            flash_set('error', 'Invalid response.');
        }
        redirect('/pages/appointment_list.php');
    }

    // Patient: respond to Amended 
    if ($action === 'patient_respond') {
        if ($role !== 'Patient') {
            flash_set('error', 'Invalid action.');
            redirect('/pages/appointment_list.php');
        }
        if ((int)$cur['patient_id'] !== (int)($_SESSION['patient_id'] ?? 0)) {
            flash_set('error', 'Access denied.');
            redirect('/pages/appointment_list.php');
        }
        if ($cur['status'] !== 'Amended') {
            flash_set('error', 'This appointment is no longer awaiting your response.');
            redirect('/pages/appointment_list.php');
        }

        $response = trim($_POST['response'] ?? '');

        if ($response === 'Accept') {
            $db->prepare(
                'UPDATE appointments
                 SET status         = "Confirmed",
                     appointment_date = proposed_date,
                     appointment_time = proposed_time,
                     proposed_date  = NULL,
                     proposed_time  = NULL,
                     updated_at     = CURRENT_TIMESTAMP
                 WHERE appointment_id = ?'
            )->execute([$appt_id]);
            $new_date = $cur['proposed_date'] ?? '';
            write_audit_log('UPDATE', 'appointments', $appt_id,
                ['status' => 'Amended'],
                ['status' => 'Confirmed', 'appointment_date' => $new_date, 'appointment_time' => $cur['proposed_time'] ?? '']
            );
            flash_set('success', 'Amendment accepted. Appointment confirmed for ' .
                ($new_date ? date('d M Y', strtotime($new_date)) : 'the proposed date') . '.');

        } elseif ($response === 'Decline') {
            $db->prepare(
                'UPDATE appointments
                 SET status = "Declined",
                     proposed_date = NULL, proposed_time = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE appointment_id = ?'
            )->execute([$appt_id]);
            write_audit_log('UPDATE', 'appointments', $appt_id,
                ['status' => 'Amended'], ['status' => 'Declined']
            );
            flash_set('success', 'Amendment declined.');
        } else {
            flash_set('error', 'Invalid response.');
        }
        redirect('/pages/appointment_list.php');
    }

    // Patient: cancel own Requested / Confirmed appointment 
    if ($action === 'patient_cancel') {
        if ($role !== 'Patient') {
            flash_set('error', 'Invalid action.');
            redirect('/pages/appointment_list.php');
        }
        if ((int)$cur['patient_id'] !== (int)($_SESSION['patient_id'] ?? 0)) {
            flash_set('error', 'Access denied.');
            redirect('/pages/appointment_list.php');
        }
        if (!in_array($cur['status'], ['Requested', 'Confirmed'], true)) {
            flash_set('error', 'This appointment cannot be cancelled at its current status.');
            redirect('/pages/appointment_list.php');
        }
        $db->prepare(
            'UPDATE appointments SET status = "Cancelled", updated_at = CURRENT_TIMESTAMP
             WHERE appointment_id = ?'
        )->execute([$appt_id]);
        write_audit_log('UPDATE', 'appointments', $appt_id,
            ['status' => $cur['status']], ['status' => 'Cancelled']
        );
        flash_set('success', 'Appointment cancelled.');
        redirect('/pages/appointment_list.php');
    }

    // Staff / Admin / SuperAdmin / Nurse (and Doctor for non-Requested): general update of status & notes
    if ($action === 'staff_update' || isset($_POST['update_status'])) {
        if (!in_array($role, ['SuperAdmin', 'Admin', 'Nurse', 'Doctor'], true)) {
            flash_set('error', 'Access denied.');
            redirect('/pages/appointment_list.php');
        }
        $new_status = trim($_POST['status'] ?? '');
        $notes      = trim($_POST['notes']  ?? '');
        $allowed    = ['Requested', 'Confirmed', 'Amended', 'Declined', 'Completed', 'Cancelled'];
        if (!in_array($new_status, $allowed, true)) {
            flash_set('error', 'Invalid status value.');
            redirect('/pages/appointment_list.php');
        }
        $db->prepare('UPDATE appointments SET status = ?, notes = ? WHERE appointment_id = ?')
           ->execute([$new_status, $notes ?: null, $appt_id]);
        write_audit_log('UPDATE', 'appointments', $appt_id,
            ['status' => $cur['status'] ?? null, 'notes' => $cur['notes'] ?? null],
            ['status' => $new_status,             'notes' => $notes ?: null]
        );
        flash_set('success', 'Appointment updated to "' . $new_status . '".');
        redirect('/pages/appointment_list.php');
    }
}

// Build query 
$filter_patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : null;
$filter_status     = trim($_GET['status'] ?? '');
$filter_date       = trim($_GET['date']   ?? '');

$where  = [];
$params = [];

if ($role === 'Patient') {
    $where[]  = 'a.patient_id = ?';
    $params[] = (int)($_SESSION['patient_id'] ?? 0);
} elseif ($role === 'Doctor') {
    $where[]  = 'a.doctor_id = ?';
    $params[] = (int)($_SESSION['doctor_id'] ?? 0);
} else {
    if ($filter_patient_id) {
        $where[]  = 'a.patient_id = ?';
        $params[] = $filter_patient_id;
    }
}

if ($filter_status !== '') {
    $where[]  = 'a.status = ?';
    $params[] = $filter_status;
}
if ($filter_date !== '') {
    $where[]  = 'a.appointment_date = ?';
    $params[] = $filter_date;
}

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Sort: items needing action first, then by date
$stmt = $db->prepare(
    "SELECT a.appointment_id, a.appointment_date, a.appointment_time,
            a.status, a.reason, a.notes, a.created_at, a.updated_at,
            a.proposed_date, a.proposed_time,
            CONCAT(p.first_name,' ',p.last_name) AS patient_name,
            p.patient_id,
            CONCAT(d.first_name,' ',d.last_name) AS doctor_name,
            d.specialization, d.doctor_id
     FROM appointments a
     JOIN patients p ON p.patient_id = a.patient_id
     JOIN doctors  d ON d.doctor_id  = a.doctor_id
     $where_sql
     ORDER BY FIELD(a.status,'Requested','Amended','Confirmed','Completed','Cancelled','Declined'),
              a.appointment_date ASC, a.appointment_time ASC
     LIMIT 200"
);
$stmt->execute($params);
$appointments = $stmt->fetchAll();

// Column count for colspan in inline rows
$col_count = 5; // #, Date/Time, Reason, Status, Action
if ($role !== 'Patient') $col_count++;
if ($role !== 'Doctor')  $col_count++;

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-center">
    <div class="flex-1">
        <h1>Appointments</h1>
        <p>
            <?= count($appointments) ?> record(s) found.
            <?php if ($filter_patient_id || $filter_status || $filter_date): ?>
            <a href="<?= BASE_URL ?>/pages/appointment_list.php" class="text-muted">(clear filters)</a>
            <?php endif; ?>
        </p>
    </div>
    <a href="<?= BASE_URL ?>/pages/appointment_book.php" class="btn btn-primary">
        + Book Appointment
    </a>
</div>

<!--  Filters  -->
<form method="GET" class="d-flex gap-8 mb-24" style="flex-wrap:wrap;align-items:flex-end;">
    <div class="form-group" style="margin:0;min-width:160px;">
        <label style="font-size:.8rem;">Date</label>
        <input type="date" name="date" value="<?= h($filter_date) ?>" style="padding:8px 12px;">
    </div>
    <div class="form-group" style="margin:0;min-width:160px;">
        <label style="font-size:.8rem;">Status</label>
        <select name="status" style="padding:8px 12px;">
            <option value="">All Statuses</option>
            <?php foreach (['Requested','Confirmed','Amended','Declined','Completed','Cancelled'] as $st): ?>
            <option value="<?= $st ?>" <?= ($filter_status === $st) ? 'selected' : '' ?>><?= $st ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-primary" style="padding:9px 18px;">Filter</button>
</form>

<!--  Table  -->
<div class="card">
    <div class="card-body" style="padding:0;">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <?php if ($role !== 'Patient'): ?><th>Patient</th><?php endif; ?>
                        <?php if ($role !== 'Doctor'): ?><th>Doctor</th><?php endif; ?>
                        <th>Date / Time</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($appointments)): ?>
                    <tr><td colspan="<?= $col_count ?>" class="text-center text-muted" style="padding:28px;">
                        No appointments found.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($appointments as $a):
                        $aid    = (int)$a['appointment_id'];
                        $status = $a['status'];

                        $show_doctor_respond  = ($role === 'Doctor')
                                                && $status === 'Requested'
                                                && (int)$a['doctor_id'] === (int)($_SESSION['doctor_id'] ?? 0);

                        $show_patient_respond = ($role === 'Patient') && $status === 'Amended';

                        $show_patient_cancel  = ($role === 'Patient')
                                                && in_array($status, ['Requested','Confirmed'], true);

                        $show_staff_update    = in_array($role, ['SuperAdmin','Admin','Nurse'], true)
                                                || ($role === 'Doctor'
                                                    && in_array($status, ['Confirmed','Completed','Cancelled'], true)
                                                    && (int)$a['doctor_id'] === (int)($_SESSION['doctor_id'] ?? 0));

                        $has_action = $show_doctor_respond || $show_patient_respond
                                   || $show_patient_cancel || $show_staff_update;

                        $btn_label = ($show_doctor_respond || $show_patient_respond)
                                   ? 'Respond'
                                   : ($show_patient_cancel ? 'Cancel' : 'Update');
                    ?>
                    <tr>
                        <td><?= $aid ?></td>
                        <?php if ($role !== 'Patient'): ?>
                        <td>
                            <a href="<?= BASE_URL ?>/pages/patient_profile.php?id=<?= (int)$a['patient_id'] ?>">
                                <?= h($a['patient_name']) ?>
                            </a>
                        </td>
                        <?php endif; ?>
                        <?php if ($role !== 'Doctor'): ?>
                        <td>
                            <?= h('Dr ' . $a['doctor_name']) ?>
                            <?php if ($a['specialization']): ?>
                            <br><small class="text-muted"><?= h($a['specialization']) ?></small>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td>
                            <?= h(date('d M Y', strtotime($a['appointment_date']))) ?>
                            <br><small><?= h(date('h:i A', strtotime($a['appointment_time']))) ?></small>
                            <?php if ($status === 'Amended' && $a['proposed_date']): ?>
                            <br><small style="color:#b45309;">
                                Proposed:
                                <?= h(date('d M Y', strtotime($a['proposed_date']))) ?>
                                <?= h(date('h:i A', strtotime($a['proposed_time']))) ?>
                            </small>
                            <?php endif; ?>
                        </td>
                        <td style="max-width:180px;word-break:break-word;">
                            <?= $a['reason'] ? h($a['reason']) : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td>
                            <span class="status-badge status-<?= strtolower(h($status)) ?>">
                                <?= h($status) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($has_action): ?>
                            <button type="button"
                                    class="btn btn-secondary btn-sm"
                                    onclick="toggleRow(<?= $aid ?>)">
                                <?= h($btn_label) ?>
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <!--  Inline action row  -->
                    <?php if ($has_action): ?>
                    <tr id="row-<?= $aid ?>" style="display:none;background:#f8fafc;">
                        <td colspan="<?= $col_count ?>" style="padding:16px 20px;">

                        <?php if ($show_doctor_respond): ?>
                            <!-- DOCTOR: Accept / Amend / Decline -->
                            <form method="POST" action="<?= BASE_URL ?>/pages/appointment_list.php"
                                  id="drform-<?= $aid ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action"            value="doctor_respond">
                                <input type="hidden" name="appointment_id"    value="<?= $aid ?>">
                                <input type="hidden" name="record_updated_at" value="<?= h($a['updated_at'] ?? '') ?>">
                                <input type="hidden" name="response"          id="dr-response-<?= $aid ?>" value="">

                                <div class="d-flex gap-8" style="flex-wrap:wrap;">
                                    <button type="button" class="btn btn-success btn-sm"
                                            onclick="drSubmit(<?= $aid ?>,'Accept')">
                                        &#10003; Accept
                                    </button>
                                    <button type="button" class="btn btn-warning btn-sm"
                                            onclick="drPanel(<?= $aid ?>,'amend')">
                                        &#9998; Amend
                                    </button>
                                    <button type="button" class="btn btn-danger btn-sm"
                                            onclick="drPanel(<?= $aid ?>,'decline')">
                                        &#10007; Decline
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-sm"
                                            onclick="toggleRow(<?= $aid ?>)">Close</button>
                                </div>

                                <!-- Amend panel -->
                                <div id="dr-amend-<?= $aid ?>" style="display:none;margin-top:14px;padding:12px;background:#fffbeb;border-radius:6px;border:1px solid #fcd34d;">
                                    <p style="font-size:.85rem;font-weight:600;margin:0 0 10px;">Propose a new date &amp; time:</p>
                                    <div class="d-flex gap-8" style="flex-wrap:wrap;align-items:flex-end;">
                                        <div class="form-group" style="margin:0;min-width:160px;">
                                            <label style="font-size:.8rem;">New Date</label>
                                            <input type="date" name="proposed_date" min="<?= date('Y-m-d') ?>">
                                        </div>
                                        <div class="form-group" style="margin:0;min-width:140px;">
                                            <label style="font-size:.8rem;">New Time</label>
                                            <input type="time" name="proposed_time">
                                        </div>
                                        <button type="button" class="btn btn-primary btn-sm"
                                                onclick="drSubmit(<?= $aid ?>,'Amend')">
                                            Send Amendment
                                        </button>
                                    </div>
                                </div>

                                <!-- Decline panel -->
                                <div id="dr-decline-<?= $aid ?>" style="display:none;margin-top:14px;padding:12px;background:#fef2f2;border-radius:6px;border:1px solid #fca5a5;">
                                    <p style="font-size:.85rem;font-weight:600;margin:0 0 10px;">Reason for declining <span style="font-weight:400;">(optional)</span>:</p>
                                    <div class="d-flex gap-8" style="flex-wrap:wrap;align-items:flex-end;">
                                        <div class="form-group" style="margin:0;flex:1;min-width:260px;">
                                            <textarea name="decline_reason" rows="2"
                                                      placeholder="Enter reason for declining..."
                                                      style="width:100%;"></textarea>
                                        </div>
                                        <button type="button" class="btn btn-danger btn-sm"
                                                onclick="drSubmit(<?= $aid ?>,'Decline')">
                                            Confirm Decline
                                        </button>
                                    </div>
                                </div>
                            </form>

                        <?php elseif ($show_patient_respond): ?>
                            <!-- PATIENT: respond to doctor's amendment (Accept or Decline only — no re-amend) -->
                            <div style="margin-bottom:14px;padding:12px;background:#fffbeb;border-radius:6px;border:1px solid #fcd34d;">
                                <p style="margin:0 0 4px;font-size:.9rem;">
                                    <strong>Doctor proposes:</strong>
                                    <?= h(date('d M Y', strtotime($a['proposed_date']))) ?>
                                    at <?= h(date('h:i A', strtotime($a['proposed_time']))) ?>
                                </p>
                                <p style="margin:0;font-size:.82rem;color:#78716c;">
                                    Your original request: <?= h(date('d M Y', strtotime($a['appointment_date']))) ?>
                                    at <?= h(date('h:i A', strtotime($a['appointment_time']))) ?>
                                </p>
                            </div>
                            <div class="d-flex gap-8">
                                <form method="POST" action="<?= BASE_URL ?>/pages/appointment_list.php">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action"            value="patient_respond">
                                    <input type="hidden" name="appointment_id"    value="<?= $aid ?>">
                                    <input type="hidden" name="record_updated_at" value="<?= h($a['updated_at'] ?? '') ?>">
                                    <input type="hidden" name="response"          value="Accept">
                                    <button type="submit" class="btn btn-success btn-sm">
                                        &#10003; Accept Amendment
                                    </button>
                                </form>
                                <form method="POST" action="<?= BASE_URL ?>/pages/appointment_list.php">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action"            value="patient_respond">
                                    <input type="hidden" name="appointment_id"    value="<?= $aid ?>">
                                    <input type="hidden" name="record_updated_at" value="<?= h($a['updated_at'] ?? '') ?>">
                                    <input type="hidden" name="response"          value="Decline">
                                    <button type="submit" class="btn btn-danger btn-sm">
                                        &#10007; Decline Amendment
                                    </button>
                                </form>
                                <button type="button" class="btn btn-secondary btn-sm"
                                        onclick="toggleRow(<?= $aid ?>)">Close</button>
                            </div>

                        <?php elseif ($show_patient_cancel): ?>
                            <!-- PATIENT: cancel own Requested / Confirmed appointment -->
                            <p style="font-size:.88rem;margin:0 0 12px;color:#666;">
                                Cancel this appointment? This cannot be undone.
                            </p>
                            <form method="POST" action="<?= BASE_URL ?>/pages/appointment_list.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action"            value="patient_cancel">
                                <input type="hidden" name="appointment_id"    value="<?= $aid ?>">
                                <input type="hidden" name="record_updated_at" value="<?= h($a['updated_at'] ?? '') ?>">
                                <div class="d-flex gap-8">
                                    <button type="submit" class="btn btn-danger btn-sm"
                                            onclick="return confirm('Are you sure you want to cancel this appointment?')">
                                        Yes, Cancel Appointment
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-sm"
                                            onclick="toggleRow(<?= $aid ?>)">Keep It</button>
                                </div>
                            </form>

                        <?php elseif ($show_staff_update): ?>
                            <!-- STAFF / DOCTOR (on non-Requested): general status update -->
                            <form method="POST" action="<?= BASE_URL ?>/pages/appointment_list.php"
                                  data-offline-sync="appointments/update"
                                  style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action"            value="staff_update">
                                <input type="hidden" name="appointment_id"    value="<?= $aid ?>">
                                <input type="hidden" name="update_status"     value="1">
                                <input type="hidden" name="record_updated_at" value="<?= h($a['updated_at'] ?? '') ?>">

                                <div class="form-group" style="margin:0;min-width:160px;">
                                    <label style="font-size:.8rem;">Status</label>
                                    <select name="status">
                                        <?php
                                        $avail = ($role === 'Doctor')
                                            ? ['Confirmed','Completed','Cancelled']
                                            : ['Requested','Confirmed','Amended','Declined','Completed','Cancelled'];
                                        foreach ($avail as $st):
                                        ?>
                                        <option value="<?= $st ?>" <?= ($status === $st) ? 'selected' : '' ?>>
                                            <?= $st ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group" style="margin:0;min-width:260px;flex:1;">
                                    <label style="font-size:.8rem;">Notes</label>
                                    <input type="text" name="notes" placeholder="Optional notes..."
                                           value="<?= h($a['notes'] ?? '') ?>">
                                </div>

                                <button type="submit" class="btn btn-success btn-sm">Save</button>
                                <button type="button" class="btn btn-secondary btn-sm"
                                        onclick="toggleRow(<?= $aid ?>)">Cancel</button>
                            </form>
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

<script>
function toggleRow(id) {
    var row = document.getElementById('row-' + id);
    if (row) row.style.display = (row.style.display === 'none' ? 'table-row' : 'none');
}
function drPanel(id, panel) {
    ['amend','decline'].forEach(function(p) {
        var el = document.getElementById('dr-' + p + '-' + id);
        if (el) el.style.display = (p === panel ? 'block' : 'none');
    });
}
function drSubmit(id, response) {
    document.getElementById('dr-response-' + id).value = response;
    document.getElementById('drform-' + id).submit();
}
</script>

<?php require_once '../includes/footer.php'; ?>
