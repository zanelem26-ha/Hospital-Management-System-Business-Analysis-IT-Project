<?php
/**
 * php/dashboard.php — Role-based dashboard
 *
 * Shows summary statistics and quick links relevant to the logged-in user's role.
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';

require_login();

$page_title = 'Dashboard';
$role       = current_role();
$db         = get_db();


// Fetch stats (role-dependent)

$stats = [];
if (in_array($role, ['SuperAdmin', 'Admin'], true)) {

    try {
                $stats['total_patients']     = $db->query('SELECT COUNT(*) FROM patients')->fetchColumn();
                $stats['total_doctors']      = $db->query('SELECT COUNT(*) FROM doctors')->fetchColumn();
                $stats['total_nurses']       = $db->query('SELECT COUNT(*) FROM nurses')->fetchColumn();
                $stats['total_users']        = $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
                $stats['new_patients_month'] = $db->query("SELECT COUNT(*) FROM patients WHERE MONTH(created_at)=MONTH(CURDATE())
                    AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();
                $stats['appointments_month'] = $db->query("SELECT COUNT(*) FROM appointments WHERE MONTH(appointment_date)=MONTH(CURDATE())
                    AND YEAR(appointment_date)=YEAR(CURDATE())")->fetchColumn();
                $stats['completed_today']     =$db->query("SELECT COUNT(*) FROM appointments WHERE appointment_date=CURDATE()
                    AND status='Completed'")->fetchColumn();
                $stats['cancelled_today'] =$db->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE()
                    AND status = 'Cancelled'")->fetchColumn();
                $stats['requested_today'] =$db->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE()
                    AND status = 'Requested'")->fetchColumn();


    // Recent appointments
    $stmt = $db->prepare(
        'SELECT a.appointment_id, a.appointment_date, a.appointment_time, a.status,
                CONCAT(p.first_name," ",p.last_name) AS patient_name,
                CONCAT(d.first_name," ",d.last_name) AS doctor_name
         FROM appointments a
         JOIN patients p ON p.patient_id = a.patient_id
         JOIN doctors  d ON d.doctor_id  = a.doctor_id
         ORDER BY a.appointment_date DESC, a.appointment_time DESC
         LIMIT 8'
    );
 

    $stmt->execute();
    $recent_appointments = $stmt->fetchAll();

    $stmt = $db->query("
    SELECT
        DATE_FORMAT(created_at, '%b') AS month,
        COUNT(*) AS total
    FROM patients
    WHERE YEAR(created_at) = YEAR(CURDATE())
    GROUP BY MONTH(created_at)
    ORDER BY MONTH(created_at)
");

$patientGrowth = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {

        error_log($e->getMessage());

        $stats = [];

    }

} elseif ($role === 'Doctor') {
    $doctor_id = $_SESSION['doctor_id'] ?? 0;

    $s = $db->prepare(
        'SELECT COUNT(*) FROM appointments WHERE doctor_id = ? AND appointment_date = CURDATE()'
    );
    $s->execute([$doctor_id]);
    $stats['today_appointments'] = $s->fetchColumn();

    $s = $db->prepare('SELECT COUNT(*) FROM appointments WHERE doctor_id = ?');
    $s->execute([$doctor_id]);
    $stats['total_appointments'] = $s->fetchColumn();

    $stmt = $db->prepare(
        'SELECT a.appointment_id, a.appointment_date, a.appointment_time, a.status,
                CONCAT(p.first_name," ",p.last_name) AS patient_name
         FROM appointments a
         JOIN patients p ON p.patient_id = a.patient_id
         WHERE a.doctor_id = ?
         ORDER BY a.appointment_date DESC, a.appointment_time DESC
         LIMIT 8'
    );
    $stmt->execute([$doctor_id]);
    $recent_appointments = $stmt->fetchAll();

} elseif ($role === 'Nurse') {
    $stats['total_patients']     = $db->query('SELECT COUNT(*) FROM patients')->fetchColumn();
    $s = $db->prepare(
        'SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE()'
    );
    $s->execute();
    $stats['today_appointments'] = $s->fetchColumn();

    $stmt = $db->prepare(
        'SELECT a.appointment_id, a.appointment_date, a.appointment_time, a.status,
                CONCAT(p.first_name," ",p.last_name) AS patient_name,
                CONCAT(d.first_name," ",d.last_name) AS doctor_name
         FROM appointments a
         JOIN patients p ON p.patient_id = a.patient_id
         JOIN doctors  d ON d.doctor_id  = a.doctor_id
         WHERE a.appointment_date = CURDATE()
         ORDER BY a.appointment_time ASC
         LIMIT 10'
    );
    $stmt->execute();
    $recent_appointments = $stmt->fetchAll();

} elseif ($role === 'Patient') {
    $patient_id = $_SESSION['patient_id'] ?? 0;

    $s = $db->prepare(
        'SELECT COUNT(*) FROM appointments WHERE patient_id = ? AND status NOT IN ("Cancelled","Completed")'
    );
    $s->execute([$patient_id]);
    $stats['upcoming'] = $s->fetchColumn();

    $s = $db->prepare(
        'SELECT COUNT(*) FROM appointments WHERE patient_id = ? AND status = "Completed"'
    );
    $s->execute([$patient_id]);
    $stats['completed'] = $s->fetchColumn();

    $stmt = $db->prepare(
        'SELECT a.appointment_id, a.appointment_date, a.appointment_time, a.status,
                CONCAT(d.first_name," ",d.last_name) AS doctor_name, d.specialization
         FROM appointments a
         JOIN doctors d ON d.doctor_id = a.doctor_id
         WHERE a.patient_id = ?
         ORDER BY a.appointment_date DESC, a.appointment_time DESC
         LIMIT 8'
    );
    $stmt->execute([$patient_id]);
    $recent_appointments = $stmt->fetchAll();
}

$executiveSummary = [
    'patients' => $stats['total_patients'] ?? 0,
    'doctors' => $stats['total_doctors'] ?? 0,
    'nurses' => $stats['total_nurses'] ?? 0,
    'users' => $stats['total_users'] ?? 0,
    'departments' => $stats['total_departments'] ?? 0,
    'appointments_today' => $stats['today_appointments'] ?? 0,
    'appointments_month' => $stats['appointments_month'] ?? 0,
    'new_patients' => $stats['new_patients_month'] ?? 0,
    'completed_today' => $stats['completed_today'] ?? 0,
    'cancelled_today' => $stats['cancelled_today'] ?? 0,
    'requested_today' => $stats['requested_today'] ?? 0,
];

require_once '../includes/header.php';
?>

<div class="page-header">
    <h1>Welcome, <?= h(current_full_name()) ?></h1>
    <p>Here is a summary of today's activity — <?= date('l, d F Y') ?></p>
</div>

<!-- ── Stat cards ── -->
<div class="stats-grid">


<?php if (in_array($role, ['SuperAdmin','Admin'], true)): ?>

<div class="stat-card">
    <div class="stat-icon blue">👥</div>
    <div class="stat-info">
        <h3><?= (int)($stats['total_patients'] ?? 0) ?></h3>
        <p>Total Patients</p>
    </div>
</div>

<div class="stat-card">
    <div class="stat-icon green">👨‍⚕️</div>
    <div class="stat-info">
        <h3><?= (int)($stats['total_doctors'] ?? 0) ?></h3>
        <p>Doctors</p>
    </div>
</div>

<div class="stat-card">
    <div class="stat-icon purple">👩‍⚕️</div>
    <div class="stat-info">
        <h3><?= (int)($stats['total_nurses'] ?? 0) ?></h3>
        <p>Nurses</p>
    </div>
</div>

<div class="stat-card">
    <div class="stat-icon orange">📅</div>
    <div class="stat-info">
        <h3><?= (int)($stats['appointments_month'] ?? 0) ?></h3>
        <p>Appointments This Month</p>
    </div>
</div>

<?php elseif ($role === 'Doctor'): ?>
    <div class="stat-card">
        <div class="stat-icon orange">&#128197;</div>
        <div class="stat-info">
            <h3><?= (int)$stats['today_appointments'] ?></h3>
            <p>Appointments Today</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">&#128100;</div>
        <div class="stat-info">
            <h3><?= (int)$stats['total_appointments'] ?></h3>
            <p>Total Appointments</p>
        </div>
    </div>

<?php elseif ($role === 'Nurse'): ?>
    <div class="stat-card">
        <div class="stat-icon blue">&#128100;</div>
        <div class="stat-info">
            <h3><?= (int)$stats['total_patients'] ?></h3>
            <p>Registered Patients</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange">&#128197;</div>
        <div class="stat-info">
            <h3><?= (int)$stats['today_appointments'] ?></h3>
            <p>Appointments Today</p>
        </div>
    </div>

<?php elseif ($role === 'Patient'): ?>
    <div class="stat-card">
        <div class="stat-icon orange">&#128197;</div>
        <div class="stat-info">
            <h3><?= (int)$stats['upcoming'] ?></h3>
            <p>Upcoming Appointments</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">&#10003;</div>
        <div class="stat-info">
            <h3><?= (int)$stats['completed'] ?></h3>
            <p>Completed Visits</p>
        </div>
    </div>
<?php endif; ?>

</div><!-- /.stats-grid -->


<?php if (in_array($role,['SuperAdmin','Admin'],true)): ?>
<div class="card" style="margin-bottom:20px;">
<div class="card-header"><h2>Executive Summary</h2></div>
<div class="card-body">
<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:12px;">
<div><strong>Total Patients:</strong> <?= $stats['total_patients'] ?? 0 ?></div>
<div><strong>Total Doctors:</strong> <?= $stats['total_doctors'] ?? 0 ?></div>
<div><strong>Total Nurses:</strong> <?= $stats['total_nurses'] ?? 0 ?></div>
<div><strong>Total Users:</strong> <?= $stats['total_users'] ?? 0 ?></div>
<div><strong>Appointments This Month:</strong> <?= $stats['appointments_month'] ?? 0 ?></div>
<div><strong>New Patients This Month:</strong> <?= $stats['new_patients_month'] ?? 0 ?></div>
<div><strong>Completed Today:</strong> <?= $stats['completed_today'] ?? 0 ?></div>
<div><strong>Requested Today:</strong> <?= $stats['requested_today'] ?? 0 ?></div>
<div><strong>Cancelled Today:</strong> <?= $stats['cancelled_today'] ?? 0 ?></div>
</div>
<hr>
<div class="card-body">
<h3>Quick Reports</h3>
<p>Generate management reports for stakeholders.</p>
<a class="btn btn-primary" href="reports.php">Open Reports</a>
</div>
</div>
</div>
<?php endif; ?>



<!--  Recent appointments table  -->
<?php if (!empty($recent_appointments)): ?>
<div class="card">
    <div class="card-header">
        <h2>Recent Appointments</h2>
        <a href="<?= BASE_URL ?>/pages/appointment_list.php" class="btn btn-secondary btn-sm">View All</a>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <?php if ($role !== 'Patient'): ?>
                        <th>Patient</th>
                        <?php endif; ?>
                        <?php if ($role !== 'Doctor'): ?>
                        <th>Doctor</th>
                        <?php endif; ?>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recent_appointments as $appt): ?>
                    <tr>
                        <?php if ($role !== 'Patient'): ?>
                        <td><?= h($appt['patient_name']) ?></td>
                        <?php endif; ?>
                        <?php if ($role !== 'Doctor'): ?>
                        <td>
                            <?= h($appt['doctor_name'] ?? '') ?>
                            <?php if (!empty($appt['specialization'])): ?>
                            <br><small class="text-muted"><?= h($appt['specialization']) ?></small>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td><?= h(date('d M Y', strtotime($appt['appointment_date']))) ?></td>
                        <td><?= h(date('h:i A', strtotime($appt['appointment_time']))) ?></td>
                        <td>
                            <span class="status-badge status-<?= strtolower(h($appt['status'])) ?>">
                                <?= h($appt['status']) ?>
                            </span>
                        </td>
                        <td>
                            <a href="<?= BASE_URL ?>/pages/appointment_list.php?id=<?= (int)$appt['appointment_id'] ?>"
                               class="btn btn-secondary btn-sm">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-body text-center text-muted">
        <p>No appointments to display.</p>
        <a href="<?= BASE_URL ?>/pages/appointment_book.php" class="btn btn-primary mt-16">
            Book an Appointment
        </a>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<?php endif; ?>
<?php require_once '../includes/footer.php'; ?>


