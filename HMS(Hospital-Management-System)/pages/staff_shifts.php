<?php
/**
 * php/staff_shifts.php — FR-05/FR-06: Staff shift management
 *
 * SuperAdmin : full CRUD — assign, update shifts for any staff member.
 * Admin      : view all shifts (table view).
 * Doctor/Nurse : calendar view of own shifts only.
 *
 * Statuses : Scheduled (green) | Off Day (gray) | Standby (amber)
 * Nurse rotation rule: 4× Scheduled (12hr), 3× Off Day, 2× Standby (8hr) per cycle.
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/audit.php';

require_login();
require_role(['SuperAdmin', 'Admin', 'Doctor', 'Nurse']);

$page_title = 'Staff Shifts';
$db         = get_db();
$role       = current_role();
$can_write  = ($role === 'SuperAdmin');
$errors     = [];

$valid_statuses = ['Scheduled', 'Off Day', 'Standby'];

//  POST handler (SuperAdmin only) 
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        flash_set('error', 'Invalid request. Please try again.');
        redirect('/pages/staff_shifts.php');
    }

    if (!$can_write) {
        flash_set('error', 'Only SuperAdmin can manage shifts.');
        redirect('/pages/staff_shifts.php');
    }

    $action = trim($_POST['action'] ?? '');

    //  Create staff shift 
    if ($action === 'create') {
        $staff_id   = (int)($_POST['staff_id']   ?? 0);
        $shift_date = trim($_POST['shift_date']  ?? '');
        $shift_type = trim($_POST['shift_type']  ?? '');
        $start_time = trim($_POST['start_time']  ?? '');
        $end_time   = trim($_POST['end_time']    ?? '');
        $shift_role = trim($_POST['shift_role']  ?? '');

        if (!$staff_id)        $errors['staff_id']   = 'Please select a staff member.';
        if ($shift_date === '') $errors['shift_date'] = 'Shift date is required.';
        if (!in_array($shift_type, $valid_statuses, true)) $errors['shift_type'] = 'Please select a shift type.';
        if ($shift_role === '') $errors['shift_role'] = 'Role is required.';

        if ($shift_type !== 'Off Day') {
            if ($start_time === '') $errors['start_time'] = 'Start time is required.';
            if ($end_time   === '') $errors['end_time']   = 'End time is required.';
            if (empty($errors['start_time']) && empty($errors['end_time']) && $end_time <= $start_time) {
                $errors['end_time'] = 'End time must be after start time.';
            }
        }

        if (!isset($errors['staff_id'])) {
            $s = $db->prepare('SELECT staff_id FROM staff WHERE staff_id = ? LIMIT 1');
            $s->execute([$staff_id]);
            if (!$s->fetch()) $errors['staff_id'] = 'Staff member not found.';
        }

        if (empty($errors)) {
            $st = ($shift_type === 'Off Day') ? null : $start_time;
            $et = ($shift_type === 'Off Day') ? null : $end_time;

            $stmt = $db->prepare(
                'INSERT INTO staff_shifts (staff_id, shift_date, start_time, end_time, role, status)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$staff_id, $shift_date, $st, $et, $shift_role, $shift_type]);
            $shift_id = (int)$db->lastInsertId();

            write_audit_log('INSERT', 'staff_shifts', $shift_id, null, [
                'staff_id'   => $staff_id, 'shift_date' => $shift_date,
                'status'     => $shift_type, 'role'     => $shift_role,
            ]);

            flash_set('success', 'Shift assigned for ' . date('d M Y', strtotime($shift_date)) . '.');
            redirect('/pages/staff_shifts.php');
        }

    //  Update shift status
    } elseif ($action === 'update_status') {
        $shift_id   = (int)($_POST['shift_id']   ?? 0);
        $new_status = trim($_POST['status']      ?? '');
        $start_time = trim($_POST['start_time']  ?? '');
        $end_time   = trim($_POST['end_time']    ?? '');

        if (!in_array($new_status, $valid_statuses, true)) {
            flash_set('error', 'Invalid status.');
            redirect('/pages/staff_shifts.php');
        }

        // chk_shift_times requires: status = 'Off Day' OR both times set with end > start
        if ($new_status !== 'Off Day' && ($start_time === '' || $end_time === '' || $end_time <= $start_time)) {
            flash_set('error', 'A valid start and end time (end after start) is required for this status.');
            redirect('/pages/staff_shifts.php');
        }

        $s = $db->prepare('SELECT status FROM staff_shifts WHERE shift_id = ? LIMIT 1');
        $s->execute([$shift_id]);
        $old = $s->fetch();

        if (!$old) {
            flash_set('error', 'Shift not found.');
            redirect('/pages/staff_shifts.php');
        }

        if ($new_status === 'Off Day') {
            $db->prepare('UPDATE staff_shifts SET status = ?, start_time = NULL, end_time = NULL WHERE shift_id = ?')
               ->execute([$new_status, $shift_id]);
        } else {
            $db->prepare('UPDATE staff_shifts SET status = ?, start_time = ?, end_time = ? WHERE shift_id = ?')
               ->execute([$new_status, $start_time, $end_time, $shift_id]);
        }

        write_audit_log('UPDATE', 'staff_shifts', $shift_id,
            ['status' => $old['status']], ['status' => $new_status]);

        flash_set('success', 'Shift status updated to "' . $new_status . '".');
        redirect('/pages/staff_shifts.php');
    }
}

//  Calendar month navigation 
$cal_year  = max(2020, min(2035, (int)($_GET['cal_year']  ?? date('Y'))));
$cal_month = max(1,    min(12,   (int)($_GET['cal_month'] ?? date('n'))));

$cal_prev = ($cal_month === 1)
    ? ['year' => $cal_year - 1, 'month' => 12]
    : ['year' => $cal_year,     'month' => $cal_month - 1];
$cal_next = ($cal_month === 12)
    ? ['year' => $cal_year + 1, 'month' => 1]
    : ['year' => $cal_year,     'month' => $cal_month + 1];

$month_start   = sprintf('%04d-%02d-01', $cal_year, $cal_month);
$days_in_month = (int)date('t', strtotime($month_start));
$first_weekday = (int)date('w', strtotime($month_start)); // 0=Sun

//  Own staff record (Doctor/Nurse) 
$own_staff_id = 0;
if (in_array($role, ['Doctor', 'Nurse'], true)) {
    $s = $db->prepare('SELECT staff_id FROM staff WHERE user_id = ? LIMIT 1');
    $s->execute([(int)($_SESSION['user_id'] ?? 0)]);
    $row = $s->fetch();
    $own_staff_id = $row ? (int)$row['staff_id'] : 0;
}

//  Query shifts for calendar month 
$month_end    = sprintf('%04d-%02d-%02d', $cal_year, $cal_month, $days_in_month);
$params_cal   = [$month_start, $month_end];
$where_cal    = 'ss.shift_date BETWEEN ? AND ?';

if ($own_staff_id) {
    $where_cal  .= ' AND ss.staff_id = ?';
    $params_cal[] = $own_staff_id;
}

$stmt = $db->prepare(
    "SELECT ss.shift_id, ss.shift_date, ss.start_time, ss.end_time,
            ss.role AS shift_role, ss.status,
            CONCAT(s.first_name,' ',s.last_name) AS staff_name
     FROM staff_shifts ss
     JOIN staff s ON s.staff_id = ss.staff_id
     WHERE $where_cal
     ORDER BY ss.shift_date ASC, ss.start_time ASC"
);
$stmt->execute($params_cal);

$shifts_by_date = [];
foreach ($stmt->fetchAll() as $sh) {
    $shifts_by_date[$sh['shift_date']][] = $sh;
}

//  Query for Admin/SA table view 
$filter_date   = trim($_GET['date']   ?? '');
$filter_status = trim($_GET['status'] ?? '');
$where  = [];
$params = [];

if ($filter_date !== '') {
    $where[]  = 'ss.shift_date = ?';
    $params[] = $filter_date;
}
if ($filter_status !== '') {
    $where[]  = 'ss.status = ?';
    $params[] = $filter_status;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare(
    "SELECT ss.shift_id, ss.shift_date, ss.start_time, ss.end_time,
            ss.role AS shift_role, ss.status, ss.staff_id,
            CONCAT(s.first_name,' ',s.last_name) AS staff_name,
            s.department
     FROM staff_shifts ss
     JOIN staff s ON s.staff_id = ss.staff_id
     $where_sql
     ORDER BY ss.shift_date DESC, ss.start_time ASC
     LIMIT 100"
);
$stmt->execute($params);
$shifts = $stmt->fetchAll();

// All active staff for the create form (SuperAdmin only)
$all_staff = [];
if ($can_write) {
    $all_staff = $db->query(
        'SELECT staff_id, first_name, last_name, department, role
         FROM staff WHERE status = "Active" ORDER BY last_name, first_name'
    )->fetchAll();
}

require_once '../includes/header.php';

// Helper: CSS class for a shift status
function shift_status_class(string $status): string {
    return match($status) {
        'Scheduled' => 'shift-scheduled',
        'Off Day'   => 'shift-offday',
        'Standby'   => 'shift-standby',
        default     => 'shift-scheduled',
    };
}

function status_badge_class(string $status): string {
    return match($status) {
        'Scheduled' => 'status-shift-scheduled',
        'Off Day'   => 'status-shift-offday',
        'Standby'   => 'status-shift-standby',
        default     => 'status-shift-scheduled',
    };
}
?>

<div class="page-header">
    <h1><?= in_array($role, ['Doctor','Nurse'], true) ? 'My Shifts' : 'Staff Shifts' ?></h1>
    <p>
        <?php if ($role === 'Nurse'): ?>
            Nurse rotation: <strong>4× Scheduled (12hr)</strong> &nbsp;·&nbsp;
            <strong>3× Off Day</strong> &nbsp;·&nbsp; <strong>2× Standby (8hr)</strong>
        <?php elseif ($role === 'Doctor'): ?>
            Your scheduled shifts for the month.
        <?php else: ?>
            Assign and manage staff shift schedules.
        <?php endif; ?>
    </p>
</div>

<?php if (in_array($role, ['Doctor','Nurse'], true) && !$own_staff_id): ?>
<div class="alert alert-warning">
    <span>Your account does not have a linked staff record yet. Contact a SuperAdmin to set up your profile.</span>
</div>
<?php else: ?>

<!--  CALENDAR VIEW (Doctor / Nurse)  -->
<?php if (in_array($role, ['Doctor', 'Nurse'], true)): ?>

<div class="shift-calendar card mb-24">
    <div class="card-header">

        <!-- Month navigation -->
        <div class="cal-nav">
            <a href="?cal_year=<?= $cal_prev['year'] ?>&cal_month=<?= $cal_prev['month'] ?>"
               class="btn btn-secondary btn-sm">&#8592; Prev</a>

            <span class="cal-month-label">
                <?= date('F Y', strtotime($month_start)) ?>
            </span>

            <a href="?cal_year=<?= $cal_next['year'] ?>&cal_month=<?= $cal_next['month'] ?>"
               class="btn btn-secondary btn-sm">Next &#8594;</a>
        </div>

        <!-- Legend -->
        <div class="cal-legend">
            <span class="cal-dot shift-scheduled"></span> Scheduled &nbsp;
            <span class="cal-dot shift-offday"></span> Off Day &nbsp;
            <span class="cal-dot shift-standby"></span> Standby
        </div>

    </div><!-- /.card-header ending -->

    <div class="card-body" style="padding:16px;">
        <div class="cal-grid">

            <!-- Day-of-week headers -->
            <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dh): ?>
            <div class="cal-day-header"><?= $dh ?></div>
            <?php endforeach; ?>

            <!-- Empty cells before first day -->
            <?php for ($e = 0; $e < $first_weekday; $e++): ?>
            <div class="cal-cell cal-empty"></div>
            <?php endfor; ?>

            <!-- Day cells -->
            <?php for ($d = 1; $d <= $days_in_month; $d++):
                $date_key  = sprintf('%04d-%02d-%02d', $cal_year, $cal_month, $d);
                $day_shifts = $shifts_by_date[$date_key] ?? [];
                $is_today   = ($date_key === date('Y-m-d'));

                // Determine dominant status for cell colour (first shift wins)
                $cell_status = !empty($day_shifts) ? $day_shifts[0]['status'] : null;
                $cell_class  = $cell_status ? shift_status_class($cell_status) : '';
            ?>
            <div class="cal-cell <?= $cell_class ?> <?= $is_today ? 'cal-today' : '' ?>">
                <span class="cal-day-num <?= $is_today ? 'cal-today-num' : '' ?>"><?= $d ?></span>

                <?php foreach ($day_shifts as $sh): ?>
                <div class="cal-shift-entry">
                    <?php if ($sh['status'] === 'Off Day'): ?>
                        <span class="cal-shift-label">Off Day</span>
                    <?php else: ?>
                        <span class="cal-shift-label">
                            <?= $sh['status'] ?>
                        </span>
                        <?php if ($sh['start_time']): ?>
                        <span class="cal-shift-time">
                            <?= date('H:i', strtotime($sh['start_time'])) ?>–<?= date('H:i', strtotime($sh['end_time'])) ?>
                        </span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endfor; ?>

        </div><!-- /.cal-grid ending-->
    </div><!-- /.card-body ending-->
</div><!-- /.shift-calendar ending -->

<?php else: ?>

<!--  TABLE VIEW (Admin / SuperAdmin)  -->

<!-- Also show mini calendar for context -->
<div class="shift-calendar card mb-24">
    <div class="card-header">
        <div class="cal-nav">
            <a href="?cal_year=<?= $cal_prev['year'] ?>&cal_month=<?= $cal_prev['month'] ?>"
               class="btn btn-secondary btn-sm">&#8592; Prev</a>
            <span class="cal-month-label"><?= date('F Y', strtotime($month_start)) ?></span>
            <a href="?cal_year=<?= $cal_next['year'] ?>&cal_month=<?= $cal_next['month'] ?>"
               class="btn btn-secondary btn-sm">Next &#8594;</a>
        </div>
        <div class="cal-legend">
            <span class="cal-dot shift-scheduled"></span> Scheduled &nbsp;
            <span class="cal-dot shift-offday"></span> Off Day &nbsp;
            <span class="cal-dot shift-standby"></span> Standby
        </div>
    </div>
    <div class="card-body" style="padding:16px;">
        <div class="cal-grid">
            <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dh): ?>
            <div class="cal-day-header"><?= $dh ?></div>
            <?php endforeach; ?>
            <?php for ($e = 0; $e < $first_weekday; $e++): ?>
            <div class="cal-cell cal-empty"></div>
            <?php endfor; ?>
            <?php for ($d = 1; $d <= $days_in_month; $d++):
                $date_key   = sprintf('%04d-%02d-%02d', $cal_year, $cal_month, $d);
                $day_shifts = $shifts_by_date[$date_key] ?? [];
                $is_today   = ($date_key === date('Y-m-d'));
                $cell_class = !empty($day_shifts) ? shift_status_class($day_shifts[0]['status']) : '';
            ?>
            <div class="cal-cell <?= $cell_class ?> <?= $is_today ? 'cal-today' : '' ?>">
                <span class="cal-day-num <?= $is_today ? 'cal-today-num' : '' ?>"><?= $d ?></span>
                <?php foreach ($day_shifts as $sh): ?>
                <div class="cal-shift-entry">
                    <span class="cal-shift-label"><?= h($sh['staff_name']) ?></span>
                    <span class="cal-shift-label" style="font-weight:400;"><?= h($sh['status']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<!-- Filters -->
<form method="GET" class="d-flex gap-8 mb-24" style="flex-wrap:wrap;align-items:flex-end;">
    <input type="hidden" name="cal_year"  value="<?= $cal_year ?>">
    <input type="hidden" name="cal_month" value="<?= $cal_month ?>">
    <div class="form-group" style="margin:0;min-width:180px;">
        <label style="font-size:.8rem;">Date</label>
        <input type="date" name="date" value="<?= h($filter_date) ?>" style="padding:8px 12px;">
    </div>
    <div class="form-group" style="margin:0;min-width:160px;">
        <label style="font-size:.8rem;">Status</label>
        <select name="status" style="padding:8px 12px;">
            <option value="">All Statuses</option>
            <?php foreach ($valid_statuses as $st): ?>
            <option value="<?= $st ?>" <?= ($filter_status === $st) ? 'selected' : '' ?>><?= $st ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <?php if ($filter_date !== '' || $filter_status !== ''): ?>
    <a href="?cal_year=<?= $cal_year ?>&cal_month=<?= $cal_month ?>" class="btn btn-secondary btn-sm">Clear</a>
    <?php endif; ?>
</form>

<!-- Shifts table -->
<div class="card mb-24">
    <div class="card-body" style="padding:0;">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Staff Member</th>
                        <th>Department</th>
                        <th>Role</th>
                        <th>Date</th>
                        <th>Hours</th>
                        <th>Status</th>
                        <?php if ($can_write): ?><th>Action</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($shifts)): ?>
                    <tr><td colspan="8" class="text-center text-muted" style="padding:28px;">No shifts found.</td></tr>
                <?php else: ?>
                    <?php foreach ($shifts as $shift): ?>
                    <tr>
                        <td class="text-muted" style="font-size:.85rem;"><?= (int)$shift['shift_id'] ?></td>
                        <td><strong><?= h($shift['staff_name']) ?></strong></td>
                        <td style="font-size:.88rem;"><?= $shift['department'] ? h($shift['department']) : '<span class="text-muted">—</span>' ?></td>
                        <td style="font-size:.88rem;"><?= h($shift['shift_role']) ?></td>
                        <td style="white-space:nowrap;font-size:.88rem;"><?= h(date('d M Y', strtotime($shift['shift_date']))) ?></td>
                        <td style="white-space:nowrap;font-size:.88rem;">
                            <?php if ($shift['status'] === 'Off Day'): ?>
                                <span class="text-muted">All day</span>
                            <?php else: ?>
                                <?= h(date('H:i', strtotime($shift['start_time']))) ?>–<?= h(date('H:i', strtotime($shift['end_time']))) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-badge <?= status_badge_class($shift['status']) ?>">
                                <?= h($shift['status']) ?>
                            </span>
                        </td>
                        <?php if ($can_write): ?>
                        <td>
                            <button type="button" class="btn btn-secondary btn-sm"
                                    onclick="toggleStatusForm(<?= (int)$shift['shift_id'] ?>)">Update</button>
                        </td>
                        <?php endif; ?>
                    </tr>

                    <?php if ($can_write): ?>
                    <tr id="status-row-<?= (int)$shift['shift_id'] ?>" style="display:none;background:#f8fafc;">
                        <td colspan="8" style="padding:14px 20px;">
                            <form method="POST" action="<?= BASE_URL ?>/pages/staff_shifts.php"
                                  style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action"   value="update_status">
                                <input type="hidden" name="shift_id" value="<?= (int)$shift['shift_id'] ?>">
                                <div class="form-group" style="margin:0;min-width:180px;">
                                    <label style="font-size:.8rem;">New Status</label>
                                    <select name="status" style="padding:8px 10px;"
                                            onchange="onStatusSelectChange(<?= (int)$shift['shift_id'] ?>, this.value)">
                                        <?php foreach ($valid_statuses as $st): ?>
                                        <option value="<?= $st ?>" <?= ($shift['status'] === $st) ? 'selected' : '' ?>><?= $st ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group" id="status-start-wrap-<?= (int)$shift['shift_id'] ?>"
                                     style="margin:0;min-width:120px;<?= $shift['status'] === 'Off Day' ? 'display:none;' : '' ?>">
                                    <label style="font-size:.8rem;">Start Time</label>
                                    <input type="time" id="status-start-<?= (int)$shift['shift_id'] ?>" name="start_time"
                                           style="padding:8px 10px;"
                                           value="<?= $shift['status'] !== 'Off Day' ? h(date('H:i', strtotime($shift['start_time']))) : '07:00' ?>">
                                </div>
                                <div class="form-group" id="status-end-wrap-<?= (int)$shift['shift_id'] ?>"
                                     style="margin:0;min-width:120px;<?= $shift['status'] === 'Off Day' ? 'display:none;' : '' ?>">
                                    <label style="font-size:.8rem;">End Time</label>
                                    <input type="time" id="status-end-<?= (int)$shift['shift_id'] ?>" name="end_time"
                                           style="padding:8px 10px;"
                                           value="<?= $shift['status'] !== 'Off Day' ? h(date('H:i', strtotime($shift['end_time']))) : '19:00' ?>">
                                </div>
                                <button type="submit" class="btn btn-success btn-sm"
                                        onclick="return confirm('Are you sure you want to update this shift status?')">Save</button>
                                <button type="button" class="btn btn-secondary btn-sm"
                                        onclick="toggleStatusForm(<?= (int)$shift['shift_id'] ?>)">Cancel</button>
                            </form>
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

<!--  Assign Shift (SuperAdmin only)  -->
<?php if ($can_write): ?>
<div class="card">
    <div class="card-header"><h2>Assign New Shift</h2></div>
    <div class="card-body">

        <?php if (empty($all_staff)): ?>
        <div class="alert alert-warning">
            <span>No active staff members found. <a href="<?= BASE_URL ?>/pages/staff_profiles.php">Add staff profiles</a> first.</span>
        </div>
        <?php else: ?>
        <form method="POST" action="<?= BASE_URL ?>/pages/staff_shifts.php" data-validate novalidate id="assign-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">

            <div class="form-grid">

                <div class="form-group full-width">
                    <label for="staff_id" class="required">Staff Member</label>
                    <select id="staff_id" name="staff_id" required>
                        <option value="">— Select Staff —</option>
                        <?php foreach ($all_staff as $s): ?>
                        <option value="<?= (int)$s['staff_id'] ?>"
                            <?= ((int)($_POST['staff_id'] ?? 0) === (int)$s['staff_id']) ? 'selected' : '' ?>>
                            <?= h($s['first_name'] . ' ' . $s['last_name']) ?>
                            <?= $s['department'] ? ' — ' . h($s['department']) : '' ?>
                            (<?= h($s['role']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field-error"><?= h($errors['staff_id'] ?? '') ?></span>
                </div>

                <div class="form-group">
                    <label for="shift_date" class="required">Shift Date</label>
                    <input type="date" id="shift_date" name="shift_date" required
                           value="<?= h($_POST['shift_date'] ?? '') ?>">
                    <span class="field-error"><?= h($errors['shift_date'] ?? '') ?></span>
                </div>

                <div class="form-group">
                    <label for="shift_role" class="required">Shift Role</label>
                    <select id="shift_role" name="shift_role" required>
                        <option value="">— Select Role —</option>
                        <?php foreach (['Doctor','Nurse','Admin','Pharmacist','Other'] as $r): ?>
                        <option value="<?= $r ?>" <?= (($_POST['shift_role'] ?? '') === $r) ? 'selected' : '' ?>><?= $r ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field-error"><?= h($errors['shift_role'] ?? '') ?></span>
                </div>

                <div class="form-group">
                    <label for="shift_type" class="required">Shift Type</label>
                    <select id="shift_type" name="shift_type" required onchange="onShiftTypeChange(this.value)">
                        <option value="">— Select Type —</option>
                        <option value="Scheduled" <?= (($_POST['shift_type'] ?? '') === 'Scheduled') ? 'selected' : '' ?>>Scheduled (12hr)</option>
                        <option value="Off Day"   <?= (($_POST['shift_type'] ?? '') === 'Off Day')   ? 'selected' : '' ?>>Off Day (all day)</option>
                        <option value="Standby"   <?= (($_POST['shift_type'] ?? '') === 'Standby')   ? 'selected' : '' ?>>Standby (8hr)</option>
                    </select>
                    <span class="field-error"><?= h($errors['shift_type'] ?? '') ?></span>
                </div>

                <div id="time-fields" style="display:contents;">
                    <div class="form-group">
                        <label for="start_time" class="required">Start Time</label>
                        <input type="time" id="start_time" name="start_time"
                               value="<?= h($_POST['start_time'] ?? '07:00') ?>">
                        <span class="field-error"><?= h($errors['start_time'] ?? '') ?></span>
                    </div>

                    <div class="form-group">
                        <label for="end_time" class="required">End Time</label>
                        <input type="time" id="end_time" name="end_time"
                               value="<?= h($_POST['end_time'] ?? '19:00') ?>">
                        <span class="field-error"><?= h($errors['end_time'] ?? '') ?></span>
                    </div>
                </div>

            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Assign Shift</button>
            </div>
        </form>
        <?php endif; ?>

    </div>
</div>

<div class="card mt-24" style="border-left:4px solid var(--warning);">
    <div class="card-body" style="padding:16px 20px;">
        <strong>Nurse Rotation Rule:</strong>
        Per rotation cycle — <span style="color:var(--success);">4 × Scheduled</span> (12hr each) &nbsp;·&nbsp;
        <span style="color:var(--text-muted);">3 × Off Day</span> &nbsp;·&nbsp;
        <span style="color:var(--warning);">2 × Standby</span> (8hr each)
    </div>
</div>
<?php endif; ?>

<?php endif; /* ending Admin/SA view */ ?>

<?php endif; /* ending own_staff_id guard */ ?>

<script>
function toggleStatusForm(shiftId) {
    var row = document.getElementById('status-row-' + shiftId);
    if (row) row.style.display = (row.style.display === 'none' ? 'table-row' : 'none');
}

// Show/require start & end time on the quick status-update row whenever the
// new status isn't 'Off Day' — the DB's chk_shift_times constraint rejects a
// non-'Off Day' status with null times, so switching off Off Day must supply them.
function onStatusSelectChange(shiftId, status) {
    var startWrap = document.getElementById('status-start-wrap-' + shiftId);
    var endWrap   = document.getElementById('status-end-wrap-' + shiftId);
    var startEl   = document.getElementById('status-start-' + shiftId);
    var endEl     = document.getElementById('status-end-' + shiftId);
    var show      = (status !== 'Off Day');

    [startWrap, endWrap].forEach(function (el) { if (el) el.style.display = show ? '' : 'none'; });
    [startEl, endEl].forEach(function (el) { if (el) el.required = show; });

    if (show && startEl && endEl && !startEl.value && !endEl.value) {
        if (status === 'Scheduled') { startEl.value = '07:00'; endEl.value = '19:00'; }
        else if (status === 'Standby') { startEl.value = '07:00'; endEl.value = '15:00'; }
    }
}

// Auto-fill start/end times and toggle visibility based on shift type
function onShiftTypeChange(type) {
    var startEl = document.getElementById('start_time');
    var endEl   = document.getElementById('end_time');
    var labels  = document.querySelectorAll('#time-fields label');
    var show    = (type !== 'Off Day');

    [startEl, endEl].forEach(function(el) {
        if (!el) return;
        el.closest('.form-group').style.display = show ? '' : 'none';
        el.required = show;
        if (show && typeof attachRequiredTooltip === 'function') {
            el.title = ''; // reset so attachRequiredTooltip re-sets it
            attachRequiredTooltip(el);
        } else if (!show) {
            el.title = '';
        }
    });

    if (type === 'Scheduled') {
        if (startEl) startEl.value = '07:00';
        if (endEl)   endEl.value   = '19:00';
    } else if (type === 'Standby') {
        if (startEl) startEl.value = '07:00';
        if (endEl)   endEl.value   = '15:00';
    }
}

// Apply on page load if POST repopulated the form
(function() {
    var sel = document.getElementById('shift_type');
    if (sel && sel.value) onShiftTypeChange(sel.value);
})();
</script>

<?php require_once '../includes/footer.php'; ?>
