<?php
/**
 * php/billing.php — FR-04: Billing & Payment management
 *
 * SuperAdmin / Admin : create bills, record payments, view all.
 * Doctor / Nurse     : view-only.
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/audit.php';

require_login();
require_role(['SuperAdmin', 'Admin', 'Doctor', 'Nurse']);

$page_title = 'Billing & Payments';
$db         = get_db();
$role       = current_role();
$can_write  = in_array($role, ['SuperAdmin', 'Admin'], true);
$errors     = [];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        flash_set('error', 'Invalid request. Please try again.');
        redirect('/pages/billing.php');
    }

    if (!$can_write) {
        flash_set('error', 'You do not have permission to perform this action.');
        redirect('/pages/billing.php');
    }

    $action = trim($_POST['action'] ?? '');

    // Create billing record
    if ($action === 'create_bill') {
        $patient_id     = (int)($_POST['patient_id']      ?? 0);
        $appointment_id = (int)($_POST['appointment_id']  ?? 0) ?: null;
        $amount         = (float)($_POST['amount']        ?? 0);
        $payment_status = trim($_POST['payment_status']   ?? 'Owing');
        $account_term   = (int)($_POST['account_term']    ?? 0) ?: null;
        $billing_date   = trim($_POST['billing_date']     ?? date('Y-m-d'));

        if (!$patient_id)      $errors['patient_id'] = 'Please select a patient.';
        if ($amount <= 0)      $errors['amount']      = 'Amount must be greater than zero.';
        if (!in_array($payment_status, ['Owing','Paid','Account'], true)) {
            $errors['payment_status'] = 'Invalid payment status.';
        }
        if ($billing_date === '') $errors['billing_date'] = 'Billing date is required.';

        if (!isset($errors['patient_id'])) {
            $s = $db->prepare('SELECT patient_id FROM patients WHERE patient_id = ? LIMIT 1');
            $s->execute([$patient_id]);
            if (!$s->fetch()) $errors['patient_id'] = 'Selected patient does not exist.';
        }

        if (empty($errors)) {
            $account_balance = ($payment_status === 'Owing') ? $amount : 0.00;

            $stmt = $db->prepare(
                'INSERT INTO billing_records
                     (patient_id, appointment_id, amount, payment_status,
                      account_balance, account_term, billing_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $patient_id, $appointment_id, $amount, $payment_status,
                $account_balance, $account_term, $billing_date,
            ]);
            $bill_id = (int)$db->lastInsertId();

            write_audit_log('INSERT', 'billing_records', $bill_id, null, [
                'patient_id'     => $patient_id, 'amount'          => $amount,
                'payment_status' => $payment_status, 'billing_date' => $billing_date,
            ]);

            flash_set('success', 'Billing record #' . $bill_id . ' created successfully.');
            redirect('/pages/billing.php');
        }

    // Record payment
    } elseif ($action === 'pay') {
        $bill_id        = (int)($_POST['bill_id']        ?? 0);
        $amount_paid    = (float)($_POST['amount_paid']  ?? 0);
        $payment_method = trim($_POST['payment_method']  ?? 'Cash');
        $reference_no   = trim($_POST['reference_no']    ?? '');
        $payment_date   = trim($_POST['payment_date']    ?? date('Y-m-d'));

        if (!$bill_id)      $errors['bill_id']     = 'Invalid billing record.';
        if ($amount_paid <= 0) $errors['amount_paid'] = 'Payment amount must be greater than zero.';
        if (!in_array($payment_method, ['Cash','Medical Aid','Card','Account'], true)) {
            $errors['payment_method'] = 'Invalid payment method.';
        }

        if (empty($errors)) {
            $stmt = $db->prepare(
                'INSERT INTO payments (bill_id, amount_paid, payment_method, payment_date, reference_no)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $bill_id, $amount_paid, $payment_method,
                $payment_date, $reference_no ?: null,
            ]);
            $payment_id = (int)$db->lastInsertId();

            write_audit_log('INSERT', 'payments', $payment_id, null, [
                'bill_id'        => $bill_id,    'amount_paid'    => $amount_paid,
                'payment_method' => $payment_method, 'payment_date' => $payment_date,
            ]);

            flash_set('success', 'Payment of R' . number_format($amount_paid, 2) . ' recorded.');
            redirect('/pages/billing.php');
        }
    }
}

// Filters
$filter_status  = trim($_GET['status']     ?? '');
$filter_patient = trim($_GET['patient']    ?? '');

$where  = [];
$params = [];

if ($filter_status !== '') {
    $where[]  = 'b.payment_status = ?';
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
    "SELECT b.bill_id, b.amount, b.payment_status, b.account_balance,
            b.account_term, b.billing_date, b.patient_id,
            CONCAT(p.first_name,' ',p.last_name) AS patient_name,
            b.appointment_id
     FROM billing_records b
     JOIN patients p ON p.patient_id = b.patient_id
     $where_sql
     ORDER BY b.billing_date DESC, b.bill_id DESC
     LIMIT 100"
);
$stmt->execute($params);
$bills = $stmt->fetchAll();

// Stats for summary cards
$stats = [];
if ($can_write) {
    $stats['total_billed'] = $db->query(
        'SELECT COALESCE(SUM(amount),0) FROM billing_records'
    )->fetchColumn();
    $stats['total_owing'] = $db->query(
        'SELECT COALESCE(SUM(account_balance),0) FROM billing_records WHERE payment_status != "Paid"'
    )->fetchColumn();
    $stats['paid_count'] = $db->query(
        'SELECT COUNT(*) FROM billing_records WHERE payment_status = "Paid"'
    )->fetchColumn();
    $stats['owing_count'] = $db->query(
        'SELECT COUNT(*) FROM billing_records WHERE payment_status != "Paid"'
    )->fetchColumn();
}

// Patient list for the create form
$all_patients = [];
if ($can_write) {
    $all_patients = $db->query(
        'SELECT patient_id, first_name, last_name FROM patients ORDER BY last_name, first_name'
    )->fetchAll();
}

require_once '../includes/header.php';
?>

<div class="page-header">
    <h1>Billing &amp; Payments</h1>
    <p>Manage Invoices and Payments.</p>
</div>

<!-- Summary cards  -->
<?php if ($can_write): ?>
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue">&#128179;</div>
        <div class="stat-info">
            <h3>R<?= number_format((float)$stats['total_billed'], 2) ?></h3>
            <p>Total Billed</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange">&#8987;</div>
        <div class="stat-info">
            <h3>R<?= number_format((float)$stats['total_owing'], 2) ?></h3>
            <p>Outstanding Balance</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">&#10003;</div>
        <div class="stat-info">
            <h3><?= (int)$stats['paid_count'] ?></h3>
            <p>Bills Paid</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange">&#128197;</div>
        <div class="stat-info">
            <h3><?= (int)$stats['owing_count'] ?></h3>
            <p>Bills Outstanding</p>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Filters ─── -->
<form method="GET" class="d-flex gap-8 mb-24" style="flex-wrap:wrap;align-items:flex-end;">
    <div class="form-group" style="margin:0;min-width:180px;">
        <label style="font-size:.8rem;">Status</label>
        <select name="status" style="padding:8px 12px;">
            <option value="">All Statuses</option>
            <?php foreach (['Owing','Account','Paid'] as $st): ?>
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
    <a href="<?= BASE_URL ?>/pages/billing.php" class="btn btn-secondary btn-sm">Clear</a>
    <?php endif; ?>
</form>

<!--  Billing Records Table  -->
<div class="card mb-24">
    <div class="card-body" style="padding:0;">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Patient</th>
                        <th>Billing Date</th>
                        <th>Amount</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <?php if ($can_write): ?><th>Payment</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($bills)): ?>
                    <tr><td colspan="7" class="text-center text-muted" style="padding:28px;">
                        No billing records found.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($bills as $bill): ?>
                    <tr>
                        <td class="text-muted" style="font-size:.85rem;"><?= (int)$bill['bill_id'] ?></td>
                        <td>
                            <a href="<?= BASE_URL ?>/pages/patient_profile.php?id=<?= (int)$bill['patient_id'] ?>">
                                <?= h($bill['patient_name']) ?>
                            </a>
                        </td>
                        <td style="white-space:nowrap;font-size:.88rem;">
                            <?= h(date('d M Y', strtotime($bill['billing_date']))) ?>
                        </td>
                        <td>R<?= number_format((float)$bill['amount'], 2) ?></td>
                        <td>
                            <?php if ($bill['payment_status'] === 'Paid'): ?>
                            <span class="text-success">R0.00</span>
                            <?php else: ?>
                            <strong class="text-danger">R<?= number_format((float)$bill['account_balance'], 2) ?></strong>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $badge_class = match($bill['payment_status']) {
                                'Paid'    => 'status-completed',
                                'Owing'   => 'status-cancelled',
                                'Account' => 'status-scheduled',
                                default   => '',
                            };
                            ?>
                            <span class="status-badge <?= $badge_class ?>">
                                <?= h($bill['payment_status']) ?>
                            </span>
                        </td>
                        <?php if ($can_write): ?>
                        <td>
                            <?php if ($bill['payment_status'] !== 'Paid'): ?>
                            <button type="button"
                                    class="btn btn-success btn-sm"
                                    onclick="togglePayForm(<?= (int)$bill['bill_id'] ?>)">
                                Record Payment
                            </button>
                            <?php else: ?>
                            <span class="text-muted" style="font-size:.8rem;">Paid ✓</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>

                    <!-- Inline payment form -->
                    <?php if ($can_write && $bill['payment_status'] !== 'Paid'): ?>
                    <tr id="pay-row-<?= (int)$bill['bill_id'] ?>" style="display:none;background:#f0f9f0;">
                        <td colspan="7" style="padding:16px 20px;">
                            <form method="POST" action="<?= BASE_URL ?>/pages/billing.php"
                                  style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;"
                                  data-validate novalidate>
                                <?= csrf_field() ?>
                                <input type="hidden" name="action"  value="pay">
                                <input type="hidden" name="bill_id" value="<?= (int)$bill['bill_id'] ?>">

                                <div class="form-group" style="margin:0;min-width:140px;">
                                    <label style="font-size:.8rem;" class="required">Amount (R)</label>
                                    <input type="number" name="amount_paid" min="0.01" step="0.01" required
                                           value="<?= number_format((float)$bill['account_balance'], 2, '.', '') ?>"
                                           style="padding:8px 10px;">
                                    <span class="field-error"></span>
                                </div>

                                <div class="form-group" style="margin:0;min-width:160px;">
                                    <label style="font-size:.8rem;">Method</label>
                                    <select name="payment_method" style="padding:8px 10px;">
                                        <?php foreach (['Cash','Card','Medical Aid','Account'] as $m): ?>
                                        <option value="<?= $m ?>"><?= $m ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group" style="margin:0;min-width:150px;">
                                    <label style="font-size:.8rem;">Date</label>
                                    <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>"
                                           style="padding:8px 10px;">
                                </div>

                                <div class="form-group" style="margin:0;min-width:180px;">
                                    <label style="font-size:.8rem;">Reference No.</label>
                                    <input type="text" name="reference_no" placeholder="Optional"
                                           maxlength="100" style="padding:8px 10px;">
                                </div>

                                <button type="submit" class="btn btn-success btn-sm">Save Payment</button>
                                <button type="button" class="btn btn-secondary btn-sm"
                                        onclick="togglePayForm(<?= (int)$bill['bill_id'] ?>)">Cancel</button>
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

<!--  Create Billing Record (Admin / SuperAdmin only)  -->
<?php if ($can_write): ?>
<div class="card">
    <div class="card-header"><h2>Create Billing Record</h2></div>
    <div class="card-body">

        <form method="POST" action="<?= BASE_URL ?>/pages/billing.php" data-validate novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_bill">

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
                    <label for="amount" class="required">Amount (R)</label>
                    <input type="number" id="amount" name="amount" required
                           min="0.01" step="0.01" placeholder="0.00"
                           value="<?= h($_POST['amount'] ?? '') ?>">
                    <span class="field-error"><?= h($errors['amount'] ?? '') ?></span>
                </div>

                <div class="form-group">
                    <label for="payment_status" class="required">Initial Status</label>
                    <select id="payment_status" name="payment_status" required>
                        <?php foreach (['Owing','Account','Paid'] as $s): ?>
                        <option value="<?= $s ?>"
                            <?= (($_POST['payment_status'] ?? 'Owing') === $s) ? 'selected' : '' ?>>
                            <?= $s ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field-error"><?= h($errors['payment_status'] ?? '') ?></span>
                </div>

                <div class="form-group">
                    <label for="billing_date" class="required">Billing Date</label>
                    <input type="date" id="billing_date" name="billing_date" required
                           value="<?= h($_POST['billing_date'] ?? date('Y-m-d')) ?>">
                    <span class="field-error"><?= h($errors['billing_date'] ?? '') ?></span>
                </div>

                <div class="form-group">
                    <label for="account_term">Account Term (months)</label>
                    <select id="account_term" name="account_term">
                        <option value="">— N/A —</option>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>"
                            <?= ((int)($_POST['account_term'] ?? 0) === $m) ? 'selected' : '' ?>>
                            <?= $m ?> month<?= $m > 1 ? 's' : '' ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                    <span class="field-hint">Required for Account payment status.</span>
                </div>

            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Create Billing Record</button>
            </div>
        </form>

    </div>
</div>
<?php endif; ?>

<script>
function togglePayForm(billId) {
    var row = document.getElementById('pay-row-' + billId);
    if (row) { row.style.display = row.style.display === 'none' ? 'table-row' : 'none'; }
}
</script>

<?php require_once '../includes/footer.php'; ?>
