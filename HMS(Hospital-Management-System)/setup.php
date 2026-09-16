<?php
/**
 * setup.php - One-time database setup and seed script
 * creates the DB first time - avoid rerunning
 *
 *    Run this ONCE from the browser or CLI after placing the project on the server.
 *    Delete or restrict access to this file after setup is complete.
 *
 * CLI:  php setup.php
 * Web:  http://localhost/HMS/setup.php
 */

// Bootstrap
define('SETUP_MODE', true);
require_once __DIR__ . '/config/config.php';

// Connect without specifying the database (so we can create it)
$dsn = sprintf('mysql:host=%s;port=%s;charset=%s', DB_HOST, DB_PORT, DB_CHARSET);
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Azure MySQL rejects plaintext connections (--require_secure_transport=ON)
if (defined('DB_SSL_CA')) {
    $options[PDO::MYSQL_ATTR_SSL_CA] = DB_SSL_CA;
    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
}

$log = [];

function log_msg(string $msg): void
{
    global $log;
    $log[] = $msg;
    if (PHP_SAPI === 'cli') {
        echo $msg . PHP_EOL;
    }
}

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    log_msg('Connected to MySQL server.');

    // Create database
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '`
                    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . DB_NAME . '`');
    log_msg('Database "' . DB_NAME . '" ready.');

    // Load and execute schema using a DELIMITER-aware parser.
    
    $sql = file_get_contents(__DIR__ . '/sql/schema.sql');
    $sql = preg_replace('/^CREATE DATABASE[^;]+;/im', '', $sql);
    $sql = preg_replace('/^USE[^;]+;/im', '', $sql);

    $statements = [];
    $delimiter  = ';';
    $buffer     = '';

    foreach (explode("\n", str_replace("\r\n", "\n", $sql)) as $line) {
        $trimmed = trim($line);

        // DELIMITER is a MySQL CLI directive - switch modes, never execute
        if (preg_match('/^DELIMITER\s+(\S+)\s*$/i', $trimmed, $m)) {
            $delimiter = $m[1];
            continue;
        }


        if ($delimiter === ';' && ($trimmed === '' || str_starts_with($trimmed, '--'))) {
            continue;
        }

        $buffer .= $line . "\n";

        // Flush when the buffer ends with the current delimiter
        $check = rtrim($buffer);
        if (str_ends_with($check, $delimiter)) {
            $stmt = trim(substr($check, 0, -strlen($delimiter)));
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $buffer = '';
        }
    }

    // Catch any trailing statement without a final delimiter
    if (trim($buffer) !== '') {
        $stmt = trim(rtrim($buffer, ';'));
        if ($stmt !== '') {
            $statements[] = $stmt;
        }
    }

    foreach ($statements as $stmt) {
        $pdo->exec($stmt);
    }
    log_msg('Schema created / verified.');

    // Seed data
    // Default password for ALL seed accounts: d3f@uL+Ed1
    $default_password = password_hash('d3f@uL+Ed1', PASSWORD_DEFAULT);

    $seed_users = [
        ['superadmin', $default_password, 'SuperAdmin', 'superadmin@hms.co.za', 'Super Admin'],
        ['admin1',     $default_password, 'Admin',      'admin@hms.co.za',      'Admin User'],
        ['DR_Kazenga',   $default_password, 'Doctor',     'drsmith@hms.co.za',    'Dr. John Smith'],
        ['DR_Lupa',   $default_password, 'Doctor',     'drpatel@hms.co.za',    'Dr. Priya Lupa'],
        ['nurse_tim',  $default_password, 'Nurse',      'timmytea@hms.co.za',        'Timmy Tea'],
        ['nurse_joy',  $default_password, 'Nurse',      'joyndlovu@hms.co.za',       'Joy Ndlovu'],
        ['patient_1',  $default_password, 'Patient',    'mbekezelim@test.gmail.com',    'Mbekezeli Moyo'],
        ['patient_2',  $default_password, 'Patient',    'samantham@test.gmail.com',      'Samantha Maekoni'],
    ];

    $insert_user = $pdo->prepare(
        'INSERT IGNORE INTO users (username, password_hash, role, email, full_name, is_active)
         VALUES (?, ?, ?, ?, ?, 1)'
    );

    foreach ($seed_users as $u) {
        $insert_user->execute($u);
    }
    log_msg('Seed users inserted (INSERT IGNORE — skips existing).');

    // Helper: get user_id by username
    $get_uid = function (string $username) use ($pdo): int {
        $s = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
        $s->execute([$username]);
        return (int)($s->fetchColumn() ?: 0);
    };

    // Doctors
    $insert_doctor = $pdo->prepare(
        'INSERT IGNORE INTO doctors (user_id, first_name, last_name, specialization, phone, license_number, practice_number)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $insert_doctor->execute([$get_uid('DR_Kazenga'), 'John',  'Smith', 'General Medicine', '0115550101', 'LIC-001', 'PR0110001']);
    $insert_doctor->execute([$get_uid('DR_Lupa'), 'Priya', 'Patel', 'Cardiology',       '0115550102', 'LIC-002', 'PR0110002']);
    log_msg('Seed doctors inserted.');

    // Staff (Admin)
    $insert_staff = $pdo->prepare(
        'INSERT IGNORE INTO staff (user_id, first_name, last_name, department, position)
         VALUES (?, ?, ?, ?, ?)'
    );
    $insert_staff->execute([$get_uid('admin1'), 'Admin', 'User', 'Administration', 'System Administrator']);
    log_msg('Seed staff inserted.');

    // Nurse
    $insert_nurse = $pdo->prepare(
        'INSERT IGNORE INTO nurses (user_id, first_name, last_name, department, phone, practice_number)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $insert_nurse->execute([$get_uid('nurse_tim'), 'Timothy', 'Dube',   'General Ward', '0775550201', 'PR0220001']);
    $insert_nurse->execute([$get_uid('nurse_joy'), 'Joy',     'Ndlovu', 'ICU Ward',     '0785550301', 'PR0220002']);
    log_msg('Seed nurses inserted.');

    // Patients - now includes id_number (SA 13-digit ID) and medical_notes
    $insert_patient = $pdo->prepare(
        'INSERT IGNORE INTO patients
             (user_id, first_name, last_name, id_number, date_of_birth, gender,
              phone, email, blood_group, medical_notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert_patient->execute([$get_uid('patient_1'), 'Mbekezeli', 'Moyo',    '9005140123085', '1990-05-14', 'Female', '0711234567', 'mbekezelim@test.gmail.com', 'O+',
        'No known allergies. Hypertension (managed). Annual check-up due.'
    ]);
    $insert_patient->execute([$get_uid('patient_2'), 'Samantha',  'Maekoni', '8511220098086', '1985-11-22', 'Female', '0719876543', 'samantham@test.gmail.com',   'A+',
        'History of cardiac arrhythmia. Cardiology follow-up required.'
    ]);
    log_msg('Seed patients inserted.');

    // Sample appointment (scheduled for tomorrow)
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $get_pid  = function (string $username) use ($pdo): int {
        $s = $pdo->prepare(
            'SELECT p.patient_id FROM patients p JOIN users u ON u.user_id = p.user_id WHERE u.username = ?'
        );
        $s->execute([$username]);
        return (int)($s->fetchColumn() ?: 0);
    };
    $get_did  = function (string $username) use ($pdo): int {
        $s = $pdo->prepare(
            'SELECT d.doctor_id FROM doctors d JOIN users u ON u.user_id = d.user_id WHERE u.username = ?'
        );
        $s->execute([$username]);
        return (int)($s->fetchColumn() ?: 0);
    };

    $pdo->prepare(
        'INSERT IGNORE INTO appointments
             (patient_id, doctor_id, appointment_date, appointment_time, reason, status)
         VALUES (?, ?, ?, ?, ?, "Requested")'
    )->execute([$get_pid('patient_1'), $get_did('DR_Kazenga'), $tomorrow, '09:00:00', 'Annual check-up']);

    $pdo->prepare(
        'INSERT IGNORE INTO appointments
             (patient_id, doctor_id, appointment_date, appointment_time, reason, status)
         VALUES (?, ?, ?, ?, ?, "Requested")'
    )->execute([$get_pid('patient_2'), $get_did('DR_Lupa'), $tomorrow, '10:30:00', 'Chest pain consultation']);

    log_msg('Sample appointments created.');

    // FR-02: Prescriptions
    $p1_id = $get_pid('patient_1');
    $p2_id = $get_pid('patient_2');
    $d1_id = $get_did('DR_Kazenga');
    $d2_id = $get_did('DR_Lupa');

    $get_nid = function (string $username) use ($pdo): int {
        $s = $pdo->prepare(
            'SELECT n.nurse_id FROM nurses n JOIN users u ON u.user_id = n.user_id WHERE u.username = ?'
        );
        $s->execute([$username]);
        return (int)($s->fetchColumn() ?: 0);
    };
    $n1_id = $get_nid('nurse_tim');
    $n2_id = $get_nid('nurse_joy');

    $ins_rx = $pdo->prepare(
        'INSERT IGNORE INTO prescriptions
             (patient_id, doctor_id, nurse_id, medication, dosage, quantity, instructions, issued_date, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ins_rx->execute([$p1_id, $d1_id, $n1_id, 'Amlodipine 5mg', 'Once daily in the morning', 30,
        'Take with water. Monitor blood pressure weekly.', date('Y-m-d'), 'Issued']);
    $ins_rx->execute([$p2_id, $d2_id, $n1_id, 'Bisoprolol 2.5mg', 'Once daily', 30,
        'Take at the same time each day. Report any dizziness.', date('Y-m-d'), 'Issued']);
    log_msg('Seed prescriptions inserted.');

    // FR-04: Billing Records and Payments
    $a1_id = (int)$pdo->query(
        "SELECT appointment_id FROM appointments WHERE patient_id = $p1_id ORDER BY appointment_id LIMIT 1"
    )->fetchColumn();
    $a2_id = (int)$pdo->query(
        "SELECT appointment_id FROM appointments WHERE patient_id = $p2_id ORDER BY appointment_id LIMIT 1"
    )->fetchColumn();

    $ins_bill = $pdo->prepare(
        'INSERT IGNORE INTO billing_records
             (patient_id, appointment_id, amount, payment_status, account_balance, account_term, billing_date)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $ins_bill->execute([$p1_id, $a1_id ?: null, 850.00, 'Account', 850.00, 6,  date('Y-m-d')]);
    $b1_id = (int)$pdo->lastInsertId();
    $ins_bill->execute([$p2_id, $a2_id ?: null, 1200.00, 'Owing',  1200.00, null, date('Y-m-d')]);
    $b2_id = (int)$pdo->lastInsertId();
    log_msg('Seed billing records inserted.');

    // Part payment on bill 1
    if ($b1_id) {
        $pdo->prepare(
            'INSERT IGNORE INTO payments (bill_id, amount_paid, payment_method, payment_date, reference_no)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$b1_id, 200.00, 'Cash', date('Y-m-d'), 'PAY-' . date('Ymd') . '-001']);
        log_msg('Seed payment inserted.');
    }

    // FR-05: Inventory
    $ins_inv = $pdo->prepare(
        'INSERT IGNORE INTO inventory
             (item_name, category, stock_level, reorder_point, unit_price)
         VALUES (?, ?, ?, ?, ?)'
    );
    $ins_inv->execute(['Adcodol 250mg Tablets',   'Medication',   250, 50,  0.80]);
    $ins_inv->execute(['Nexiprax 200 Tablets',  'Medication',   180, 50,  1.20]);
    $ins_inv->execute(['Paracetamol 500mg',         'Medication',   500, 100, 0.25]);
    $ins_inv->execute(['Surgical Gloves (Box/100)', 'Consumable',    40, 20,  85.00]);
    $ins_inv->execute(['Disposable Syringes 5ml',   'Consumable',   300, 100,  0.45]);
    log_msg('Seed inventory items inserted.');

    // FR-05: Suppliers
    $ins_sup = $pdo->prepare(
        'INSERT IGNORE INTO suppliers (supplier_name, contact_email, phone) VALUES (?, ?, ?)'
    );
    $ins_sup->execute(['Medirite Distributors',  'orders@medirite.co.za',  '0124948875']);
    $ins_sup->execute(['PharmaCo Supply Chain',  'supply@pharmaco.co.za',  '0114405839']);
    $sup1_id = (int)$pdo->query("SELECT supplier_id FROM suppliers WHERE contact_email='orders@medirite.co.za' LIMIT 1")->fetchColumn();
    $sup2_id = (int)$pdo->query("SELECT supplier_id FROM suppliers WHERE contact_email='supply@pharmaco.co.za' LIMIT 1")->fetchColumn();
    log_msg('Seed suppliers inserted.');

    // FR-05: Purchase Orders
    $item1_id = (int)$pdo->query("SELECT item_id FROM inventory WHERE item_name='Amlodipine 5mg Tablets' LIMIT 1")->fetchColumn();
    $item4_id = (int)$pdo->query("SELECT item_id FROM inventory WHERE item_name='Surgical Gloves (Box/100)' LIMIT 1")->fetchColumn();

    if ($item1_id && $sup1_id) {
        $pdo->prepare(
            'INSERT IGNORE INTO purchase_orders (item_id, supplier_id, quantity, total_cost, status, order_date)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$item1_id, $sup1_id, 500, 400.00, 'Confirmed', date('Y-m-d')]);
        log_msg('Seed purchase order inserted.');
    }
    if ($item4_id && $sup2_id) {
        $pdo->prepare(
            'INSERT IGNORE INTO purchase_orders (item_id, supplier_id, quantity, total_cost, status, order_date)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$item4_id, $sup2_id, 30, 2550.00, 'Pending', date('Y-m-d')]);
    }

    // FR-05/FR-06: Staff Shifts
    // Link doctors/nurses to staff table first
    $ins_staff = $pdo->prepare(
        'INSERT IGNORE INTO staff (user_id, doctor_id, first_name, last_name, role, status, created_by)
         VALUES (?, ?, ?, ?, "Doctor", "Active", ?)'
    );
    $sa_uid = $get_uid('superadmin');
    $ins_staff->execute([$get_uid('DR_Kazenga'), $d1_id, 'John',  'Kazenga', $sa_uid]);
    $ins_staff->execute([$get_uid('DR_Lupa'),    $d2_id, 'Priya', 'Lupa',    $sa_uid]);

    $ins_nurse_staff = $pdo->prepare(
        'INSERT IGNORE INTO staff (user_id, nurse_id, first_name, last_name, role, status, created_by)
         VALUES (?, ?, ?, ?, "Nurse", "Active", ?)'
    );
    $ins_nurse_staff->execute([$get_uid('nurse_tim'), $n1_id, 'Timothy', 'Dube',   $sa_uid]);
    $ins_nurse_staff->execute([$get_uid('nurse_joy'), $n2_id, 'Joy',     'Ndlovu', $sa_uid]);

    $get_staff_id = function (int $user_id) use ($pdo): int {
        $s = $pdo->prepare('SELECT staff_id FROM staff WHERE user_id = ? LIMIT 1');
        $s->execute([$user_id]);
        return (int)($s->fetchColumn() ?: 0);
    };

    $s1_id = $get_staff_id($get_uid('DR_Kazenga'));
    $s2_id = $get_staff_id($get_uid('DR_Lupa'));
    $s3_id = $get_staff_id($get_uid('nurse_tim'));
    $s4_id = $get_staff_id($get_uid('nurse_joy'));

    // Rotation pattern: 4× Scheduled (12hr), 3× Off Day, 2× Standby (8hr) = 9-day cycle
    // Each staff member is offset in the cycle so they're never all off on the same day.
    $pattern = [
        'Scheduled', 'Scheduled', 'Scheduled', 'Scheduled',   // days 0-3
        'Off Day',   'Off Day',   'Off Day',                   // days 4-6
        'Standby',   'Standby',                                // days 7-8
    ];

    // [staff_id, role label, cycle offset]
    $shift_roster = [
        [$s1_id, 'Doctor', 0],   // DR_Kazenga  — starts at cycle position 0
        [$s2_id, 'Doctor', 4],   // DR_Lupa     — offset 4 (never off same days as Kazenga)
        [$s3_id, 'Nurse',  0],   // nurse_tim   — same pattern as Kazenga
        [$s4_id, 'Nurse',  5],   // nurse_joy   — offset 5 (staggered from Tim)
    ];

    $ins_shift = $pdo->prepare(
        'INSERT IGNORE INTO staff_shifts (staff_id, shift_date, start_time, end_time, role, status)
         VALUES (?, ?, ?, ?, ?, ?)'
    );

    $cycle_len  = count($pattern);
    $loop_start = new DateTime('2026-06-20');
    $loop_end   = new DateTime('2026-08-31');   // a couple of days into Aug is fine

    foreach ($shift_roster as [$sid, $shift_role, $offset]) {
        if (!$sid) continue;
        $current = clone $loop_start; // Reset for each staff member
        $day_num = 0;
        while ($current <= $loop_end) {
            $status   = $pattern[($day_num + $offset) % $cycle_len];
            $date_str = $current->format('Y-m-d');

            if ($status === 'Off Day') {
                $ins_shift->execute([$sid, $date_str, null, null, $shift_role, 'Off Day']);
            } elseif ($status === 'Scheduled') {
                $ins_shift->execute([$sid, $date_str, '07:00:00', '19:00:00', $shift_role, 'Scheduled']);
            } else {
                $ins_shift->execute([$sid, $date_str, '07:00:00', '15:00:00', $shift_role, 'Standby']);
            }

            $current->modify('+1 day');
            $day_num++;
        }
    }
    log_msg('Seed staff + staff shifts inserted (June 20 – Aug 31, 9-day rotation).');

    // Seed audit_log entries
    // Provides sample rows so the Audit Log page is not empty after first setup.
    $insert_audit = $pdo->prepare(
        'INSERT INTO audit_log
             (user_id, username, role, action, table_name, record_id,
              old_value, new_value, ip_address, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $now    = date('Y-m-d H:i:s');
    $minus1 = date('Y-m-d H:i:s', strtotime('-1 hour'));
    $minus2 = date('Y-m-d H:i:s', strtotime('-2 hours'));
    $minus3 = date('Y-m-d H:i:s', strtotime('-3 hours'));

    $sa_uid = $get_uid('superadmin');
    $a_uid  = $get_uid('admin1');
    $p1_uid = $get_uid('patient_1');
    $p2_uid = $get_uid('patient_2');

    // SuperAdmin login
    $insert_audit->execute([$sa_uid, 'superadmin', 'SuperAdmin', 'LOGIN', null, null, null, null, '127.0.0.1', $minus3]);

    // Admin registers patient_1
    $p1_id = $get_pid('patient_1');
    $insert_audit->execute([$a_uid, 'admin1', 'Admin', 'LOGIN', null, null, null, null, '127.0.0.1', $minus3]);
    $insert_audit->execute([$a_uid, 'admin1', 'Admin', 'INSERT', 'patients', $p1_id ?: 1, null,
        json_encode(['first_name' => 'Mbekezeli', 'last_name' => 'Moyo',
                     'dob' => '1990-05-14', 'gender' => 'Female', 'blood_group' => 'O+']),
        '127.0.0.1', $minus2]);

    // Admin registers patient_2
    $p2_id = $get_pid('patient_2');
    $insert_audit->execute([$a_uid, 'admin1', 'Admin', 'INSERT', 'patients', $p2_id ?: 2, null,
        json_encode(['first_name' => 'Samantha', 'last_name' => 'Maekoni',
                     'dob' => '1985-11-22', 'gender' => 'Female', 'blood_group' => 'A+']),
        '127.0.0.1', $minus2]);

    // Admin books appointment 1
    $insert_audit->execute([$a_uid, 'admin1', 'Admin', 'INSERT', 'appointments', 1, null,
        json_encode(['patient_id' => $p1_id, 'doctor_id' => $get_did('DR_Kazenga'),
                     'appointment_date' => $tomorrow, 'appointment_time' => '09:00',
                     'status' => 'Scheduled']),
        '127.0.0.1', $minus1]);

    // Admin books appointment 2
    $insert_audit->execute([$a_uid, 'admin1', 'Admin', 'INSERT', 'appointments', 2, null,
        json_encode(['patient_id' => $p2_id, 'doctor_id' => $get_did('DR_Lupa'),
                     'appointment_date' => $tomorrow, 'appointment_time' => '10:30',
                     'status' => 'Scheduled']),
        '127.0.0.1', $minus1]);

    // Patient_1 updates own profile
    $insert_audit->execute([$p1_uid, 'patient_1', 'Patient', 'UPDATE', 'patients', $p1_id ?: 1,
        json_encode(['phone' => null, 'email' => 'mbekezelim@test.gmail.com']),
        json_encode(['phone' => '0734546676', 'email' => 'mbekezelim@test.gmail.com']),
        '192.168.1.5', $minus1]);

    // Admin logout
    $insert_audit->execute([$a_uid, 'admin1', 'Admin', 'LOGOUT', null, null, null, null, '127.0.0.1', $now]);

    log_msg('Seed audit_log entries inserted.');
    log_msg('');

    // Seed sync_log entries
    // Provides sample rows illustrating Local → Synced lifecycle (NFR-3 / Table 6-2).
    $insert_sync = $pdo->prepare(
        'INSERT INTO sync_log
             (table_name, record_id, action, payload, sync_status,
              device_id, ip_address, created_at, synced_at, error_msg)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $device_mobile = 'dev-seed-mobile-001';
    $device_tablet = 'dev-seed-tablet-002';

    // Op 1 — appointment queued offline on mobile device, later synced
    $insert_sync->execute([
        'appointments', 1, 'INSERT',
        json_encode(['patient_id' => $p1_id, 'doctor_id' => $get_did('DR_Kazenga'),
                     'appt_date' => $tomorrow, 'appt_time' => '09:00', 'status' => 'Scheduled']),
        'Synced', $device_mobile, '192.168.1.10',
        $minus2, $minus1, null,
    ]);

    // Op 2 — patient profile updated offline on tablet, synced on reconnect
    $insert_sync->execute([
        'patients', $p1_id ?: 1, 'UPDATE',
        json_encode(['first_name' => 'Mbekezeli', 'last_name' => 'Moyo',
                     'phone' => '0734546676', 'blood_group' => 'O+']),
        'Synced', $device_tablet, '192.168.1.15',
        $minus2, $minus1, null,
    ]);

    // Op 3 — appointment status update queued offline, still pending (not yet online)
    $insert_sync->execute([
        'appointments', 2, 'UPDATE',
        json_encode(['appointment_id' => 2, 'status' => 'Confirmed', 'notes' => 'Patient confirmed via phone']),
        'Local', $device_mobile, '192.168.1.10',
        $now, null, null,
    ]);

    // Op 4 — failed sync attempt (doctor ID no longer valid)
    $insert_sync->execute([
        'appointments', null, 'INSERT',
        json_encode(['patient_id' => $p2_id, 'doctor_id' => 999,
                     'appt_date' => $tomorrow, 'appt_time' => '11:00']),
        'Failed', $device_tablet, '192.168.1.15',
        $minus1, null, 'Doctor not found: 999',
    ]);

    log_msg('Seed sync_log entries inserted.');
    log_msg('');
    log_msg('=== Setup complete! ===');
    log_msg('');
    log_msg('Default credentials (change immediately in production):');
    log_msg('  SuperAdmin : superadmin  / d3f@uL+Ed1');
    log_msg('  Admin      : admin1      / d3f@uL+Ed1');
    log_msg('  Doctor     : DR_Kazenga  / d3f@uL+Ed1');
    log_msg('  Nurse      : nurse_tim   / d3f@uL+Ed1');
    log_msg('  Patient    : patient_1   / d3f@uL+Ed1');
    log_msg('');
    log_msg('⚠  DELETE or protect this setup.php file before going live!');

} catch (PDOException $e) {
    log_msg('ERROR: ' . $e->getMessage());
}

// HTML output (browser mode)
if (PHP_SAPI !== 'cli'):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HMS Setup</title>
    <style>
        body { font-family: monospace; background: #1e1e2e; color: #cdd6f4; padding: 40px; }
        h1   { color: #89b4fa; margin-bottom: 24px; }
        pre  { background: #181825; padding: 24px; border-radius: 8px; line-height: 1.8; }
        a    { color: #89b4fa; }
    </style>
</head>
<body>
<h1>&#127973; HMS Database Setup</h1>
<pre><?= htmlspecialchars(implode("\n", $log), ENT_QUOTES, 'UTF-8') ?></pre>
<p><a href="<?= BASE_URL ?>/index.php">&rarr; Go to Login</a></p>
<p style="color:#f38ba8;">&#9888; Delete or restrict access to this file now that setup is done.</p>
</body>
</html>
<?php endif; ?>
