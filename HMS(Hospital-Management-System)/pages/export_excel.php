<?php

require_once '../includes/auth.php';
require_once '../includes/db.php';

require_login();

$role = current_role();

if (!in_array($role, ['SuperAdmin','Admin'], true)) {
    exit('Access Denied');
}

$db = get_db();

$report = $_GET['report'] ?? '';
$from   = $_GET['from'] ?? '';
$to     = $_GET['to'] ?? '';


header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="'.$report.'_report.csv"');

$output = fopen('php://output','w');

if($report=="patients"){

    fputcsv($output,[
        'Patient ID',
        'First Name',
        'Last Name',
        'Gender',
        'Phone',
        'Registered'
    ]);

    $sql="
        SELECT
            patient_id,
            first_name,
            last_name,
            gender,
            phone,
            created_at
        FROM patients
    ";

    if($from!="" && $to!=""){

        $sql.=" WHERE DATE(created_at) BETWEEN :from AND :to";

    }

    $stmt=$db->prepare($sql);

    if($from!="" && $to!=""){

        $stmt->bindValue(':from',$from);
        $stmt->bindValue(':to',$to);

    }

    $stmt->execute();

    while($row=$stmt->fetch(PDO::FETCH_ASSOC)){

        fputcsv($output,$row);

    }

}

elseif($report=="appointments"){

    fputcsv($output,[
        'Appointment ID',
        'Patient',
        'Doctor',
        'Date',
        'Time',
        'Status'
    ]);

    $sql="

    SELECT

        a.appointment_id,

        CONCAT(p.first_name,' ',p.last_name) patient,

        CONCAT(d.first_name,' ',d.last_name) doctor,

        a.appointment_date,

        a.appointment_time,

        a.status

    FROM appointments a

    JOIN patients p

        ON p.patient_id=a.patient_id

    JOIN doctors d

        ON d.doctor_id=a.doctor_id

    ";

    if($from!="" && $to!=""){

        $sql.="

        WHERE DATE(a.appointment_date)

        BETWEEN :from AND :to

        ";

    }

    $stmt=$db->prepare($sql);

    if($from!="" && $to!=""){

        $stmt->bindValue(':from',$from);
        $stmt->bindValue(':to',$to);

    }

    $stmt->execute();

    while($row=$stmt->fetch(PDO::FETCH_NUM)){

        fputcsv($output,$row);

    }

}

elseif($report=="status"){

    fputcsv($output,[

        'Status',

        'Total'

    ]);

    $sql="

    SELECT

        status,

        COUNT(*) total

    FROM appointments

    ";

    if($from!="" && $to!=""){

        $sql.="

        WHERE DATE(appointment_date)

        BETWEEN :from AND :to

        ";

    }

    $sql.=" GROUP BY status";

    $stmt=$db->prepare($sql);

    if($from!="" && $to!=""){

        $stmt->bindValue(':from',$from);
        $stmt->bindValue(':to',$to);

    }

    $stmt->execute();

    while($row=$stmt->fetch(PDO::FETCH_NUM)){

        fputcsv($output,$row);

    }

}


elseif ($report == "inventory") {

    fputcsv($output, [
        'Item ID',
        'Item Name',
        'Category',
        'Stock Level',
        'Reorder Point',
        'Unit Price'
    ]);

    $stmt = $db->query("
        SELECT
            item_id,
            item_name,
            category,
            stock_level,
            reorder_point,
            unit_price
        FROM inventory
        ORDER BY item_name
    ");

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }
}

elseif ($report == "billing") {

    fputcsv($output, [
        'Bill ID',
        'Patient',
        'Billing Date',
        'Amount',
        'Outstanding Balance',
        'Payment Status'
    ]);

    $sql = "SELECT
                b.bill_id,
                CONCAT(p.first_name,' ',p.last_name) AS patient,
                b.billing_date,
                b.amount,
                b.account_balance,
                b.payment_status
            FROM billing_records b
            JOIN patients p
                ON p.patient_id = b.patient_id";

    if ($from != "" && $to != "") {
        $sql .= " WHERE DATE(b.billing_date) BETWEEN :from AND :to";
    }

    $sql .= " ORDER BY b.billing_date DESC";

    $stmt = $db->prepare($sql);

    if ($from != "" && $to != "") {
        $stmt->bindValue(':from', $from);
        $stmt->bindValue(':to', $to);
    }

    $stmt->execute();

    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        fputcsv($output, $row);
    }
}
fclose($output);
exit;

