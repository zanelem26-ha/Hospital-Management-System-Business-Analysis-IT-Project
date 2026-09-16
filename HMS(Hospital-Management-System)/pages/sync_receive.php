<?php
/**
 * php/sync_receive.php — Offline Sync Receiver (NFR-3 Reliability)
 *
 * Called by sync_manager.js when the device comes back online.
 * Accepts a JSON array of IndexedDB-queued operations, applies them to
 * MySQL using the same business rules as the regular PHP pages, and
 * records each outcome in sync_log (SyncStatus = 'Synced' | 'Failed').
 *
 * POST body:   { "ops": [ {id, operation, table_name, fields, device_id, timestamp}, … ] }
 * Response:    { "processed": N, "failed": N, "results": [ {id, success, record_id, error}, … ] }
 *
 * Auth:  requires active session (require_login) + per-session X-Sync-Token header.
 * CSRF:  not used here — X-Sync-Token + SameSite cookie + JSON Content-Type provide equivalent protection.
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/audit.php';

header('Content-Type: application/json; charset=utf-8');

// Require an active session (user must be logged in)
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Validate sync token
$headers        = function_exists('getallheaders') ? getallheaders() : [];
// Header names may be normalised differently across servers
$received_token = $headers['X-Sync-Token']
    ?? $headers['x-sync-token']
    ?? ($_SERVER['HTTP_X_SYNC_TOKEN'] ?? '');

if (!hash_equals(get_sync_token(), $received_token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid or missing sync token']);
    exit;
}

// Parse body
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!isset($body['ops']) || !is_array($body['ops'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body']);
    exit;
}

// Processing
$db        = get_db();
$role      = current_role();
$processed = 0;
$failed    = 0;
$results   = [];

foreach ($body['ops'] as $op) {
    $op_id      = isset($op['id']) ? (int)$op['id'] : 0;
    $operation  = trim($op['operation'] ?? '');
    $fields     = is_array($op['fields'] ?? null) ? $op['fields'] : [];
    $device_id  = mb_substr(trim($op['device_id'] ?? ''), 0, 100);
    $ip         = _sync_get_ip();

    $result = ['id' => $op_id, 'success' => false, 'record_id' => null, 'error' => null];

    try {
        switch ($operation) {

            // patients/insert
            case 'patients/insert':
                if (!in_array($role, ['SuperAdmin', 'Admin', 'Nurse'], true)) {
                    throw new RuntimeException('Role "' . $role . '" cannot register patients');
                }
                $first_name    = trim($fields['first_name']    ?? '');
                $last_name     = trim($fields['last_name']     ?? '');
                $id_number     = trim($fields['id_number']     ?? '') ?: null;
                $dob           = trim($fields['dob']           ?? '');
                $gender        = trim($fields['gender']        ?? '');
                $phone         = trim($fields['phone']         ?? '') ?: null;
                $email         = trim($fields['email']         ?? '') ?: null;
                $address_line1 = trim($fields['address_line_1'] ?? '');
                $address_line2 = trim($fields['address_line_2'] ?? '');
                $city          = trim($fields['city']          ?? '');
                $postal_code   = trim($fields['postal_code']   ?? '');
                $blood_group   = trim($fields['blood_group']   ?? '') ?: null;
                $ec_name       = trim($fields['ec_name']       ?? '') ?: null;
                $ec_phone      = trim($fields['ec_phone']      ?? '') ?: null;
                $ec_relationship = trim($fields['ec_relationship'] ?? '') ?: null;
                $username      = trim($fields['username']      ?? '');
                $password      = $fields['password']            ?? '';

                if (!$first_name || !$last_name || !$dob || !$gender || !$username || !$email
                    || $address_line1 === '' || $city === '' || $postal_code === ''
                    || strlen($password) < 10) {
                    throw new RuntimeException('Missing or invalid required fields');
                }
                if (!in_array($gender, ['Male', 'Female', 'Other'], true)) {
                    throw new RuntimeException('Invalid gender value');
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Invalid email address');
                }
                if ($id_number && !preg_match('/^\d{13}$/', $id_number)) {
                    throw new RuntimeException('SA ID number must be exactly 13 digits');
                }
                $allowed_relationships = ['Parent', 'Sibling', 'Spouse', 'Child', 'Friend', 'Other'];
                if (($ec_name || $ec_phone) && !$ec_relationship) {
                    throw new RuntimeException('Emergency contact relationship is required when a contact name or phone is provided');
                }
                if ($ec_relationship && !in_array($ec_relationship, $allowed_relationships, true)) {
                    throw new RuntimeException('Invalid emergency contact relationship selected');
                }

                $address = implode('<br>', [$address_line1, $address_line2, $city, $postal_code]);

                $db->beginTransaction();

                $s = $db->prepare('SELECT user_id FROM users WHERE username = ? LIMIT 1 FOR UPDATE');
                $s->execute([$username]);
                if ($s->fetch()) throw new RuntimeException('Username already taken: ' . $username);

                $s = $db->prepare(
                    'INSERT INTO users (username, password_hash, role, email, full_name, is_active)
                     VALUES (?, ?, "Patient", ?, ?, 1)'
                );
                $s->execute([
                    $username,
                    password_hash($password, PASSWORD_DEFAULT),
                    $email,
                    $first_name . ' ' . $last_name,
                ]);
                $new_user_id = (int)$db->lastInsertId();

                $s = $db->prepare(
                    'INSERT INTO patients
                         (user_id, first_name, last_name, id_number, date_of_birth, gender,
                          phone, email, address, blood_group,
                          emergency_contact_name, emergency_contact_phone, emergency_contact_relationship)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $s->execute([
                    $new_user_id, $first_name, $last_name, $id_number, $dob, $gender,
                    $phone, $email, $address, $blood_group, $ec_name, $ec_phone, $ec_relationship,
                ]);
                $record_id = (int)$db->lastInsertId();
                $db->commit();

                _sync_log($db, 'patients', $record_id, 'INSERT', $fields, 'Synced', $device_id, $ip);
                write_audit_log('INSERT', 'patients', $record_id, null, [
                    'first_name' => $first_name, 'last_name' => $last_name, 'via' => 'offline_sync',
                ]);
                $result['record_id'] = $record_id;
                break;

            // patients/update
            case 'patients/update':
                $patient_id  = (int)($fields['patient_id_hidden'] ?? 0);
                $first_name  = trim($fields['first_name']  ?? '');
                $last_name   = trim($fields['last_name']   ?? '');
                $id_number   = trim($fields['id_number']   ?? '') ?: null;
                $dob         = trim($fields['dob']         ?? '');
                $gender      = trim($fields['gender']      ?? '');
                $phone         = trim($fields['phone']         ?? '') ?: null;
                $email         = trim($fields['email']         ?? '') ?: null;
                $address_line1 = trim($fields['address_line_1'] ?? '');
                $address_line2 = trim($fields['address_line_2'] ?? '');
                $city          = trim($fields['city']          ?? '');
                $postal_code   = trim($fields['postal_code']   ?? '');
                $blood_group   = trim($fields['blood_group']   ?? '') ?: null;
                $ec_name       = trim($fields['ec_name']       ?? '') ?: null;
                $ec_phone      = trim($fields['ec_phone']      ?? '') ?: null;

                if (!$patient_id || !$first_name || !$last_name || !$dob || !$gender
                    || $address_line1 === '' || $city === '' || $postal_code === '') {
                    throw new RuntimeException('Missing required fields for patient update');
                }

                $address = implode('<br>', [$address_line1, $address_line2, $city, $postal_code]);
                // Patients can only update their own profile
                if ($role === 'Patient' && (int)($_SESSION['patient_id'] ?? 0) !== $patient_id) {
                    throw new RuntimeException('Access denied to patient #' . $patient_id);
                }

                $s = $db->prepare('SELECT user_id FROM patients WHERE patient_id = ? LIMIT 1');
                $s->execute([$patient_id]);
                $row = $s->fetch();
                if (!$row) throw new RuntimeException('Patient not found: ' . $patient_id);

                $record_updated_at = trim($fields['record_updated_at'] ?? '');
                $lock_clause = $record_updated_at !== '' ? ' AND updated_at = ?' : '';
                $upd = $db->prepare(
                    'UPDATE patients
                     SET first_name=?, last_name=?, id_number=?, date_of_birth=?, gender=?,
                         phone=?, email=?, address=?, blood_group=?,
                         emergency_contact_name=?, emergency_contact_phone=?
                     WHERE patient_id=?' . $lock_clause
                );
                $upd_params = [
                    $first_name, $last_name, $id_number, $dob, $gender,
                    $phone, $email, $address, $blood_group,
                    $ec_name, $ec_phone, $patient_id,
                ];
                if ($record_updated_at !== '') $upd_params[] = $record_updated_at;
                $upd->execute($upd_params);
                if ($upd->rowCount() === 0) {
                    throw new RuntimeException('Conflict: patient #' . $patient_id . ' was modified since this form was loaded');
                }

                $db->prepare('UPDATE users SET full_name = ? WHERE user_id = ?')
                   ->execute([$first_name . ' ' . $last_name, (int)$row['user_id']]);

                _sync_log($db, 'patients', $patient_id, 'UPDATE', $fields, 'Synced', $device_id, $ip);
                write_audit_log('UPDATE', 'patients', $patient_id, null, [
                    'first_name' => $first_name, 'last_name' => $last_name, 'via' => 'offline_sync',
                ]);
                $result['record_id'] = $patient_id;
                break;

            // appointments/insert
            case 'appointments/insert':
                $patient_id = ($role === 'Patient')
                    ? (int)($_SESSION['patient_id'] ?? 0)
                    : (int)($fields['patient_id'] ?? 0);
                $doctor_id  = (int)($fields['doctor_id']  ?? 0);
                $appt_date  = trim($fields['appt_date']   ?? '');
                $appt_time  = trim($fields['appt_time']   ?? '');
                $reason     = trim($fields['reason']      ?? '') ?: null;

                if (!$patient_id || !$doctor_id || !$appt_date || !$appt_time) {
                    throw new RuntimeException('Missing required fields for appointment booking');
                }

                // Validate patient & doctor still exist
                $s = $db->prepare('SELECT patient_id FROM patients WHERE patient_id = ? LIMIT 1');
                $s->execute([$patient_id]);
                if (!$s->fetch()) throw new RuntimeException('Patient not found: ' . $patient_id);

                $s = $db->prepare('SELECT doctor_id FROM doctors WHERE doctor_id = ? LIMIT 1');
                $s->execute([$doctor_id]);
                if (!$s->fetch()) throw new RuntimeException('Doctor not found: ' . $doctor_id);

                // Double-booking guard — atomic check+insert
                $db->beginTransaction();
                $s = $db->prepare(
                    'SELECT appointment_id FROM appointments
                     WHERE doctor_id = ? AND appointment_date = ? AND appointment_time = ?
                       AND status NOT IN ("Declined","Cancelled") LIMIT 1 FOR UPDATE'
                );
                $s->execute([$doctor_id, $appt_date, $appt_time]);
                if ($s->fetch()) throw new RuntimeException('Time slot already booked for this doctor');

                $db->prepare(
                    'INSERT INTO appointments
                         (patient_id, doctor_id, appointment_date, appointment_time, reason, status)
                     VALUES (?, ?, ?, ?, ?, "Requested")'
                )->execute([$patient_id, $doctor_id, $appt_date, $appt_time, $reason]);
                $record_id = (int)$db->lastInsertId();
                $db->commit();

                _sync_log($db, 'appointments', $record_id, 'INSERT', $fields, 'Synced', $device_id, $ip);
                write_audit_log('INSERT', 'appointments', $record_id, null, [
                    'patient_id' => $patient_id, 'doctor_id' => $doctor_id,
                    'date' => $appt_date, 'time' => $appt_time, 'via' => 'offline_sync',
                ]);
                $result['record_id'] = $record_id;
                break;

            // appointments/update
            case 'appointments/update':
                $appt_id    = (int)($fields['appointment_id'] ?? 0);
                $new_status = trim($fields['status']          ?? '');
                $notes      = trim($fields['notes']           ?? '') ?: null;

                $allowed_statuses = ['Requested', 'Confirmed', 'Amended', 'Declined', 'Completed', 'Cancelled'];
                if (!$appt_id || !in_array($new_status, $allowed_statuses, true)) {
                    throw new RuntimeException('Invalid appointment ID or status');
                }

                // Patients can only cancel their own
                if ($role === 'Patient') {
                    if ($new_status !== 'Cancelled') {
                        throw new RuntimeException('Patients may only cancel appointments');
                    }
                    $s = $db->prepare(
                        'SELECT appointment_id FROM appointments
                         WHERE appointment_id = ? AND patient_id = ? LIMIT 1'
                    );
                    $s->execute([$appt_id, (int)($_SESSION['patient_id'] ?? 0)]);
                    if (!$s->fetch()) throw new RuntimeException('Appointment not found or access denied');
                }

                $s = $db->prepare('SELECT status, notes FROM appointments WHERE appointment_id = ? LIMIT 1');
                $s->execute([$appt_id]);
                $old = $s->fetch() ?: [];

                $record_updated_at = trim($fields['record_updated_at'] ?? '');
                $lock_clause = $record_updated_at !== '' ? ' AND updated_at = ?' : '';
                $upd = $db->prepare(
                    'UPDATE appointments SET status = ?, notes = ? WHERE appointment_id = ?' . $lock_clause
                );
                $upd_params = [$new_status, $notes, $appt_id];
                if ($record_updated_at !== '') $upd_params[] = $record_updated_at;
                $upd->execute($upd_params);
                if ($upd->rowCount() === 0) {
                    throw new RuntimeException('Conflict: appointment #' . $appt_id . ' was modified since this form was loaded');
                }

                _sync_log($db, 'appointments', $appt_id, 'UPDATE', $fields, 'Synced', $device_id, $ip);
                write_audit_log('UPDATE', 'appointments', $appt_id,
                    ['status' => $old['status'] ?? null, 'notes' => $old['notes'] ?? null],
                    ['status' => $new_status, 'notes' => $notes, 'via' => 'offline_sync']
                );
                $result['record_id'] = $appt_id;
                break;

            default:
                throw new RuntimeException('Unknown operation: ' . htmlspecialchars($operation));
        }

        $result['success'] = true;
        $processed++;

    } catch (Throwable $e) {
        if ($db->inTransaction()) { try { $db->rollBack(); } catch (Throwable $r) {} }
        $result['error'] = $e->getMessage();
        $failed++;
        error_log('[HMS][sync_receive] op "' . $operation . '" failed: ' . $e->getMessage());
        _sync_log($db,
            $op['table_name'] ?? null,
            null,
            strtoupper(explode('/', $operation)[1] ?? 'OP'),
            $fields,
            'Failed',
            $device_id,
            $ip,
            $e->getMessage()
        );
    }

    $results[] = $result;
}

echo json_encode([
    'processed' => $processed,
    'failed'    => $failed,
    'results'   => $results,
]);

// Helper functions for sync_receive.php

function _sync_log(
    PDO     $db,
    ?string $table_name,
    ?int    $record_id,
    string  $action,
    array   $payload,
    string  $sync_status,
    ?string $device_id,
    ?string $ip,
    ?string $error_msg = null
): void {
    // Strip sensitive fields before storing
    unset($payload['password'], $payload['password_confirm'], $payload['password_hash']);

    try {
        $db->prepare(
            'INSERT INTO sync_log
                 (table_name, record_id, action, payload, sync_status,
                  device_id, ip_address, synced_at, error_msg)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $table_name,
            $record_id,
            $action,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $sync_status,
            $device_id,
            $ip,
            ($sync_status === 'Synced') ? date('Y-m-d H:i:s') : null,
            $error_msg,
        ]);
    } catch (Throwable $e) {
        error_log('[HMS][sync_receive] sync_log write failed: ' . $e->getMessage());
    }
}

function _sync_get_ip(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    return $ip ? mb_substr($ip, 0, 45) : null;
}
