<?php
/**
 * php/patient_search.php — AJAX: look up a patient by SA ID number or patient #
 *
 * GET ?q=<term>  → JSON array of matching patients (max 10).
 * Staff only — patients may not search other patients' records.
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';

require_login();

header('Content-Type: application/json');

if (current_role() === 'Patient') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo json_encode([]);
    exit;
}

$db = get_db();

$stmt = $db->prepare(
    'SELECT patient_id, first_name, last_name, id_number, date_of_birth, gender
     FROM patients
     WHERE id_number LIKE ? OR patient_id = ?
     ORDER BY last_name, first_name
     LIMIT 10'
);
$stmt->execute(['%' . $q . '%', ctype_digit($q) ? (int)$q : 0]);

$results = array_map(function ($p) {
    return [
        'patient_id' => (int)$p['patient_id'],
        'name'       => $p['last_name'] . ', ' . $p['first_name'],
        'id_number'  => $p['id_number'],
        'dob'        => $p['date_of_birth'],
        'gender'     => $p['gender'],
    ];
}, $stmt->fetchAll());

echo json_encode($results);
