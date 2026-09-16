<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';

require_login();

$role = current_role();
if (!in_array($role,['SuperAdmin','Admin'],true)) {
    exit('Access denied');
}

$db = get_db();
$page_title = "Reports";
include '../includes/header.php';

$report = $_GET['report'] ?? '';
$from   = $_GET['from'] ?? '';
$to     = $_GET['to'] ?? '';

function dateFilter(&$sql,$field,$from,$to){
    if($from!=='' && $to!==''){
        $sql.=" WHERE DATE($field) BETWEEN :from AND :to";
        return true;
    }
    return false;
}
?>
<div class="container mt-4">
<h2>Hospital Reports</h2>

<form method="get" class="row g-3 mb-4">
<div class="col-md-3">
<select name="report" class="form-select" required>
<option value="">Select Report</option>
<option value="patients" <?= $report=='patients'?'selected':'' ?>>Patients</option>
<option value="appointments" <?= $report=='appointments'?'selected':'' ?>>Appointments</option>
<option value="status" <?= $report=='status'?'selected':'' ?>>Appointment Status</option>
<option value="inventory" <?= $report=='inventory'?'selected':'' ?>>Inventory Report</option>
<option value="billing" <?= $report=='billing'?'selected':'' ?>>Billing Report</option>
</select>
</div>
<div class="col-md-3"><input type="date" name="from" value="<?=htmlspecialchars($from)?>" class="form-control"></div>
<div class="col-md-3"><input type="date" name="to" value="<?=htmlspecialchars($to)?>" class="form-control"></div>
<div class="col-md-3"><button class="btn btn-primary">Generate</button></div>
</form>

<?php
if ($report === 'patients') {

    $sql = "SELECT patient_id, first_name, last_name, gender, phone, created_at
            FROM patients";

    $has = dateFilter($sql, 'created_at', $from, $to);

    $sql .= " ORDER BY created_at DESC";

    $stmt = $db->prepare($sql);

    if ($has) {
        $stmt->bindValue(':from', $from);
        $stmt->bindValue(':to', $to);
    }

    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<h3>Patient Report</h3>";
    echo "<p><strong>Total:</strong> " . count($rows) . "</p>";

    echo '<a class="btn btn-success me-2"
            href="export_excel.php?report=patients&from=' . urlencode($from) .
            '&to=' . urlencode($to) . '">
            Download Excel
          </a>';

       echo '<br><br>';

    echo '<table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Gender</th>
                    <th>Phone</th>
                    <th>Registered</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($rows as $r) {

        echo "<tr>
                <td>{$r['patient_id']}</td>
                <td>" . htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) . "</td>
                <td>{$r['gender']}</td>
                <td>{$r['phone']}</td>
                <td>{$r['created_at']}</td>
              </tr>";

    }

    echo '</tbody></table>';
}
elseif ($report === 'appointments') {

    $sql = "SELECT
                a.appointment_id,
                CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                CONCAT(d.first_name, ' ', d.last_name) AS doctor_name,
                a.appointment_date,
                a.appointment_time,
                a.status
            FROM appointments a
            JOIN patients p ON p.patient_id = a.patient_id
            JOIN doctors d ON d.doctor_id = a.doctor_id";

    $has = dateFilter($sql, 'a.appointment_date', $from, $to);

    $sql .= " ORDER BY a.appointment_date DESC, a.appointment_time DESC";

    $stmt = $db->prepare($sql);

    if ($has) {
        $stmt->bindValue(':from', $from);
        $stmt->bindValue(':to', $to);
    }

    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalAppointments = count($rows);
    $completed = 0;
    $requested = 0;
    $cancelled = 0;

    foreach ($rows as $row) {

        switch (strtolower($row['status'])) {

            case 'completed':
                $completed++;
                break;

            case 'requested':
                $requested++;
                break;

            case 'cancelled':
                $cancelled++;
                break;
        }
    }

    echo "<h3>Appointment Report</h3>";

    echo "<p>
            <strong>Total:</strong> {$totalAppointments} |
            <strong>Completed:</strong> {$completed} |
            <strong>Requested:</strong> {$requested} |
            <strong>Cancelled:</strong> {$cancelled}
          </p>";

    echo '<a class="btn btn-success me-2"
            href="export_excel.php?report=appointments&from=' . urlencode($from) .
            '&to=' . urlencode($to) . '">
            Download Excel
          </a>';

    echo '<br><br>';

    echo '<table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Patient</th>
                    <th>Doctor</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($rows as $row) {

        echo "<tr>
                <td>{$row['appointment_id']}</td>
                <td>" . htmlspecialchars($row['patient_name']) . "</td>
                <td>" . htmlspecialchars($row['doctor_name']) . "</td>
                <td>{$row['appointment_date']}</td>
                <td>{$row['appointment_time']}</td>
                <td>{$row['status']}</td>
              </tr>";
    }

    echo '</tbody></table>';
}

elseif ($report === 'status') {

    $sql = "SELECT
                status,
                COUNT(*) AS total
            FROM appointments";

    $has = dateFilter($sql, 'appointment_date', $from, $to);

    $sql .= " GROUP BY status ORDER BY status";

    $stmt = $db->prepare($sql);

    if ($has) {
        $stmt->bindValue(':from', $from);
        $stmt->bindValue(':to', $to);
    }

    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<h3>Appointment Status Report</h3>";

    echo '<a class="btn btn-success me-2"
            href="export_excel.php?report=status&from=' . urlencode($from) .
            '&to=' . urlencode($to) . '">
            Download Excel
          </a>';

    echo '<br><br>';

    echo '<table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Total Appointments</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($rows as $row) {

        echo "<tr>
                <td>{$row['status']}</td>
                <td>{$row['total']}</td>
              </tr>";
    }

    echo '</tbody></table>';
}

elseif ($report === 'inventory') {

    $sql = "SELECT
                item_id,
                item_name,
                category,
                stock_level,
                reorder_point,
                unit_price
            FROM inventory
            ORDER BY item_name";

    $stmt = $db->prepare($sql);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalItems = count($rows);
    $lowStock = 0;
    $totalStock = 0;
    $inventoryValue = 0;

    foreach ($rows as $row) {

        $totalStock += (int)$row['stock_level'];

        $inventoryValue +=
            ((int)$row['stock_level'] * (float)$row['unit_price']);

        if ($row['stock_level'] <= $row['reorder_point']) {
            $lowStock++;
        }
    }

    echo "<h3>Inventory Report</h3>";

    echo "<p>
            <strong>Total Items:</strong> {$totalItems} |
            <strong>Low Stock:</strong> {$lowStock} |
            <strong>Total Units:</strong> {$totalStock} |
            <strong>Inventory Value:</strong> R" . number_format($inventoryValue,2) . "
          </p>";

    echo '<a class="btn btn-success me-2"
            href="export_excel.php?report=inventory">
            Download Excel
          </a>';

    echo '<br><br>';

    echo '<table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Item</th>
                    <th>Category</th>
                    <th>Stock</th>
                    <th>Reorder Point</th>
                    <th>Unit Price</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($rows as $row) {

        $status = ($row['stock_level'] <= $row['reorder_point'])
            ? '<span class="badge bg-danger">Low Stock</span>'
            : '<span class="badge bg-success">In Stock</span>';

        echo "<tr>

                <td>{$row['item_id']}</td>

                <td>" . htmlspecialchars($row['item_name']) . "</td>

                <td>{$row['category']}</td>

                <td>{$row['stock_level']}</td>

                <td>{$row['reorder_point']}</td>

                <td>R" . number_format($row['unit_price'],2) . "</td>

                <td>{$status}</td>

              </tr>";
    }

    echo '</tbody></table>';
}

elseif ($report === 'billing') {

    $sql = "SELECT
                b.bill_id,
                CONCAT(p.first_name,' ',p.last_name) AS patient_name,
                b.billing_date,
                b.amount,
                b.account_balance,
                b.payment_status
            FROM billing_records b
            JOIN patients p
                ON p.patient_id = b.patient_id";

    $has = dateFilter($sql, 'b.billing_date', $from, $to);

    $sql .= " ORDER BY b.billing_date DESC";

    $stmt = $db->prepare($sql);

    if ($has) {
        $stmt->bindValue(':from', $from);
        $stmt->bindValue(':to', $to);
    }

    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalBills = count($rows);
    $totalAmount = 0;
    $totalOutstanding = 0;
    $paid = 0;
    $owing = 0;
    $account = 0;

    foreach ($rows as $row) {

        $totalAmount += (float)$row['amount'];
        $totalOutstanding += (float)$row['account_balance'];

        switch ($row['payment_status']) {

            case 'Paid':
                $paid++;
                break;

            case 'Owing':
                $owing++;
                break;

            case 'Account':
                $account++;
                break;
        }
    }

    echo "<h3>Billing Report</h3>";

    echo "<p>

            <strong>Total Bills:</strong> {$totalBills} |

            <strong>Total Billed:</strong> R" . number_format($totalAmount,2) . " |

            <strong>Outstanding:</strong> R" . number_format($totalOutstanding,2) . "

          </p>";

    echo "<p>

            <strong>Paid:</strong> {$paid} |

            <strong>Owing:</strong> {$owing} |

            <strong>Account:</strong> {$account}

          </p>";

    echo '<a class="btn btn-success me-2"
            href="export_excel.php?report=billing&from=' . urlencode($from) .
            '&to=' . urlencode($to) . '">
            Download Excel
          </a>';

    echo '<br><br>';

    echo '<table class="table table-bordered table-striped">

            <thead>

                <tr>

                    <th>Bill ID</th>
                    <th>Patient</th>
                    <th>Billing Date</th>
                    <th>Amount</th>
                    <th>Outstanding</th>
                    <th>Status</th>

                </tr>

            </thead>

            <tbody>';

    foreach ($rows as $row) {

        $badge = '';

        switch ($row['payment_status']) {

            case 'Paid':
                $badge = '<span class="badge bg-success">Paid</span>';
                break;

            case 'Owing':
                $badge = '<span class="badge bg-danger">Owing</span>';
                break;

            case 'Account':
                $badge = '<span class="badge bg-warning text-dark">Account</span>';
                break;
        }

        echo "<tr>

                <td>{$row['bill_id']}</td>

                <td>" . htmlspecialchars($row['patient_name']) . "</td>

                <td>{$row['billing_date']}</td>

                <td>R" . number_format($row['amount'],2) . "</td>

                <td>R" . number_format($row['account_balance'],2) . "</td>

                <td>{$badge}</td>

              </tr>";
    }

    echo '</tbody></table>';
}

?>
</div>
<?php include '../includes/footer.php'; ?>
