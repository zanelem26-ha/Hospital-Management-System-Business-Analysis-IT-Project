<?php
/**
 * php/audit_log.php — Audit Log viewer
 *
 * SuperAdmin eyes only. Read-only.
 * Contains no DB writes from this page.
 *
 * Features:
 *  - Filter by action, table, username, date range
 *  - Collapsible old/new value diffs per row
 *  - CSV export of current filtered view
 *  - Paginated (50 rows per page)
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';

require_role(['SuperAdmin']);

$page_title = 'Audit Log';
$db         = get_db();

// Constants
const PER_PAGE = 50;

// Read & validate filter inputs
$f_action    = trim($_GET['action']     ?? '');
$f_table     = trim($_GET['table_name'] ?? '');
$f_username  = trim($_GET['username']   ?? '');
$f_date_from = trim($_GET['date_from']  ?? '');
$f_date_to   = trim($_GET['date_to']    ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$export_csv  = isset($_GET['export']) && $_GET['export'] === 'csv';

// Build WHERE clause — all values go through prepared-statement params
$where  = [];
$params = [];

if ($f_action !== '') {
    $where[]  = 'l.action = ?';
    $params[] = $f_action;
}
if ($f_table !== '') {
    $where[]  = 'l.table_name = ?';
    $params[] = $f_table;
}
if ($f_username !== '') {
    $where[]  = 'l.username LIKE ?';
    $params[] = '%' . $f_username . '%';
}
if ($f_date_from !== '') {
    $where[]  = 'DATE(l.created_at) >= ?';
    $params[] = $f_date_from;
}
if ($f_date_to !== '') {
    $where[]  = 'DATE(l.created_at) <= ?';
    $params[] = $f_date_to;
}

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Fetch distinct filter options for dropdowns
$actions = $db->query('SELECT DISTINCT action FROM audit_log ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$tables  = $db->query('SELECT DISTINCT table_name FROM audit_log WHERE table_name IS NOT NULL ORDER BY table_name')
               ->fetchAll(PDO::FETCH_COLUMN);

// CSV export (runs before any HTML)
if ($export_csv) {
    $stmt = $db->prepare(
        "SELECT l.log_id, l.created_at, l.username, l.role, l.action,
                l.table_name, l.record_id, l.old_value, l.new_value,
                l.ip_address, l.user_agent
         FROM audit_log l
         $where_sql
         ORDER BY l.log_id DESC
         LIMIT 5000"   // safety cap for export
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_log_' . date('Y-m-d_His') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Log ID', 'Timestamp', 'Username', 'Role', 'Action',
                   'Table', 'Record ID', 'Old Value', 'New Value',
                   'IP Address', 'User Agent']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['log_id'],
            $row['created_at'],
            $row['username'] ?? '',
            $row['role']     ?? '',
            $row['action'],
            $row['table_name'] ?? '',
            $row['record_id']  ?? '',
            $row['old_value']  ?? '',
            $row['new_value']  ?? '',
            $row['ip_address'] ?? '',
            $row['user_agent'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// Total count for pagination
$count_stmt = $db->prepare("SELECT COUNT(*) FROM audit_log l $where_sql");
$count_stmt->execute($params);
$total_rows  = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / PER_PAGE));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * PER_PAGE;

// Fetch current page
$data_params   = array_merge($params, [PER_PAGE, $offset]);
$stmt = $db->prepare(
    "SELECT l.log_id, l.created_at, l.username, l.role, l.action,
            l.table_name, l.record_id, l.old_value, l.new_value, l.ip_address
     FROM audit_log l
     $where_sql
     ORDER BY l.log_id DESC
     LIMIT ? OFFSET ?"
);
$stmt->execute($data_params);
$logs = $stmt->fetchAll();

// Build current query string helper
function audit_qs(array $overrides = []): string
{
    $base = [
        'action'    => $GLOBALS['f_action'],
        'table_name'=> $GLOBALS['f_table'],
        'username'  => $GLOBALS['f_username'],
        'date_from' => $GLOBALS['f_date_from'],
        'date_to'   => $GLOBALS['f_date_to'],
    ];
    $merged = array_merge($base, $overrides);
    $filtered = array_filter($merged, fn($v) => $v !== '');
    return $filtered ? '?' . http_build_query($filtered) : '';
}

// Action badge colour map
function action_class(string $action): string
{
    return match ($action) {
        'LOGIN'         => 'status-confirmed',
        'LOGOUT'        => 'status-scheduled',
        'INSERT'        => 'status-completed',
        'UPDATE'        => 'badge-doctor',
        'DELETE'        => 'status-cancelled',
        'ACCESS_DENIED' => 'status-cancelled',
        default         => '',
    };
}

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-center">
    <div class="flex-1">
        <h1>Audit Log</h1>
        <p>
            Read-only record of all system changes.
            <?= number_format($total_rows) ?> total event(s).
        </p>
    </div>
    <a href="<?= BASE_URL ?>/pages/audit_log.php<?= audit_qs(['export' => 'csv']) ?>"
       class="btn btn-secondary">
        &#11123; Export CSV
    </a>
</div>

<!-- ── Filter form  -->
<form method="GET" action="<?= BASE_URL ?>/pages/audit_log.php"
      class="card mb-24" style="margin-bottom:24px;">
    <div class="card-body" style="padding:20px 24px;">
        <div class="form-grid" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;">

            <div class="form-group" style="margin:0;">
                <label style="font-size:.8rem;">Action</label>
                <select name="action">
                    <option value="">All Actions</option>
                    <?php foreach ($actions as $a): ?>
                    <option value="<?= h($a) ?>" <?= ($f_action === $a) ? 'selected' : '' ?>>
                        <?= h($a) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label style="font-size:.8rem;">Table</label>
                <select name="table_name">
                    <option value="">All Tables</option>
                    <?php foreach ($tables as $t): ?>
                    <option value="<?= h($t) ?>" <?= ($f_table === $t) ? 'selected' : '' ?>>
                        <?= h($t) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label style="font-size:.8rem;">Username</label>
                <input type="text" name="username" placeholder="Search username..."
                       value="<?= h($f_username) ?>">
            </div>

            <div class="form-group" style="margin:0;">
                <label style="font-size:.8rem;">Date From</label>
                <input type="date" name="date_from" value="<?= h($f_date_from) ?>">
            </div>

            <div class="form-group" style="margin:0;">
                <label style="font-size:.8rem;">Date To</label>
                <input type="date" name="date_to" value="<?= h($f_date_to) ?>">
            </div>

        </div>
        <div style="margin-top:16px;display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary btn-sm">Apply Filters</button>
            <a href="<?= BASE_URL ?>/pages/audit_log.php" class="btn btn-secondary btn-sm">Reset</a>
        </div>
    </div>
</form>

<!--  Log table  -->
<div class="card">
    <div class="card-body" style="padding:0;">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th style="width:60px;">ID</th>
                        <th style="width:155px;">Timestamp</th>
                        <th>User</th>
                        <th>Role</th>
                        <th style="width:110px;">Action</th>
                        <th>Table</th>
                        <th style="width:80px;">Rec&nbsp;ID</th>
                        <th style="width:80px;">IP</th>
                        <th style="width:80px;">Details</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="9" class="text-center text-muted" style="padding:28px;">
                        No audit entries match the current filters.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="text-muted" style="font-size:.8rem;"><?= (int)$log['log_id'] ?></td>
                        <td style="font-size:.8rem;white-space:nowrap;">
                            <?= h(date('d M Y H:i:s', strtotime($log['created_at']))) ?>
                        </td>
                        <td>
                            <strong><?= $log['username'] ? h($log['username']) : '<span class="text-muted">—</span>' ?></strong>
                        </td>
                        <td>
                            <?php if ($log['role']): ?>
                            <span class="badge-role badge-<?= strtolower(h($log['role'])) ?>">
                                <?= h($log['role']) ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-badge <?= action_class($log['action']) ?>">
                                <?= h($log['action']) ?>
                            </span>
                        </td>
                        <td style="font-size:.85rem;">
                            <?= $log['table_name'] ? h($log['table_name']) : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td class="text-muted" style="font-size:.85rem;">
                            <?= $log['record_id'] ?? '—' ?>
                        </td>
                        <td style="font-size:.75rem;font-family:var(--font-mono);">
                            <?= $log['ip_address'] ? h($log['ip_address']) : '—' ?>
                        </td>
                        <td>
                            <?php if ($log['old_value'] || $log['new_value']): ?>
                            <button class="btn btn-secondary btn-sm"
                                    onclick="toggleDiff(<?= (int)$log['log_id'] ?>)"
                                    style="padding:4px 10px;font-size:.75rem;">
                                Diff
                            </button>
                            <?php else: ?>
                            <span class="text-muted" style="font-size:.8rem;">none</span>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <!--  Diff expansion row  -->
                    <?php if ($log['old_value'] || $log['new_value']): ?>
                    <tr id="diff-<?= (int)$log['log_id'] ?>" style="display:none;background:#fafbff;">
                        <td colspan="9" style="padding:0;">
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;border-top:1px solid var(--border);">
                                <div style="padding:14px 20px;border-right:1px solid var(--border);">
                                    <p style="font-size:.75rem;font-weight:700;color:var(--danger);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px;">
                                        Before
                                    </p>
                                    <?php if ($log['old_value']): ?>
                                    <?php
                                        $old_arr = json_decode($log['old_value'], true);
                                        if (is_array($old_arr)):
                                    ?>
                                    <dl style="font-size:.82rem;display:grid;grid-template-columns:auto 1fr;gap:4px 16px;margin:0;">
                                        <?php foreach ($old_arr as $k => $v): ?>
                                        <dt style="color:var(--text-muted);white-space:nowrap;"><?= h($k) ?></dt>
                                        <dd style="word-break:break-all;"><?= h((string)($v ?? '—')) ?></dd>
                                        <?php endforeach; ?>
                                    </dl>
                                    <?php else: ?>
                                    <pre style="font-size:.78rem;white-space:pre-wrap;"><?= h($log['old_value']) ?></pre>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <span class="text-muted" style="font-size:.82rem;">No previous value.</span>
                                    <?php endif; ?>
                                </div>
                                <div style="padding:14px 20px;">
                                    <p style="font-size:.75rem;font-weight:700;color:var(--success);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px;">
                                        After
                                    </p>
                                    <?php if ($log['new_value']): ?>
                                    <?php
                                        $new_arr = json_decode($log['new_value'], true);
                                        if (is_array($new_arr)):
                                    ?>
                                    <dl style="font-size:.82rem;display:grid;grid-template-columns:auto 1fr;gap:4px 16px;margin:0;">
                                        <?php foreach ($new_arr as $k => $v): ?>
                                        <dt style="color:var(--text-muted);white-space:nowrap;"><?= h($k) ?></dt>
                                        <dd style="word-break:break-all;
                                            <?php
                                            // Highlight changed fields
                                            $old_for_diff = isset($old_arr) && is_array($old_arr) ? $old_arr : [];
                                            if (isset($old_for_diff[$k]) && (string)$old_for_diff[$k] !== (string)($v ?? '')):
                                            ?>
                                            font-weight:700;color:var(--primary);
                                            <?php endif; ?>
                                        "><?= h((string)($v ?? '—')) ?></dd>
                                        <?php endforeach; ?>
                                    </dl>
                                    <?php else: ?>
                                    <pre style="font-size:.78rem;white-space:pre-wrap;"><?= h($log['new_value']) ?></pre>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <span class="text-muted" style="font-size:.82rem;">No new value.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!--  Pagination  -->
        <?php if ($total_pages > 1): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-top:1px solid var(--border);">
            <span class="text-muted" style="font-size:.85rem;">
                Page <?= $page ?> of <?= $total_pages ?>
                &nbsp;&mdash;&nbsp; <?= number_format($total_rows) ?> total rows
            </span>
            <div style="display:flex;gap:8px;">
                <?php if ($page > 1): ?>
                <a href="<?= BASE_URL ?>/pages/audit_log.php<?= audit_qs(['page' => $page - 1]) ?>"
                   class="btn btn-secondary btn-sm">&larr; Prev</a>
                <?php endif; ?>
                <?php if ($page < $total_pages): ?>
                <a href="<?= BASE_URL ?>/pages/audit_log.php<?= audit_qs(['page' => $page + 1]) ?>"
                   class="btn btn-secondary btn-sm">Next &rarr;</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<script>
function toggleDiff(id) {
    var row = document.getElementById('diff-' + id);
    if (row) { row.style.display = row.style.display === 'none' ? 'table-row' : 'none'; }
}
</script>

<?php require_once '../includes/footer.php'; ?>
