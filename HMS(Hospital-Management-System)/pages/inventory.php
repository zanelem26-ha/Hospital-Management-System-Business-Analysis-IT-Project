<?php
/**
 * php/inventory.php — FR-05: Inventory management
 *
 * SuperAdmin / Admin : full CRUD — items, purchase orders, stock receipts.
 * Nurse / Pharmacist : view-only — check stock levels and alerts.
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/audit.php';

require_login();
require_role(['SuperAdmin', 'Admin', 'Nurse', 'Pharmacist']);

$page_title = 'Inventory';
$db         = get_db();
$role       = current_role();
$can_write  = in_array($role, ['SuperAdmin', 'Admin'], true);
$errors     = [];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        flash_set('error', 'Invalid request. Please try again.');
        redirect('/pages/inventory.php');
    }

    if (!$can_write) {
        flash_set('error', 'You do not have permission to perform this action.');
        redirect('/pages/inventory.php');
    }

    $action = trim($_POST['action'] ?? '');

    // Add inventory item
    if ($action === 'add_item') {
        $item_name     = trim($_POST['item_name']      ?? '');
        $category      = trim($_POST['category']       ?? '');
        $stock_level   = (int)($_POST['stock_level']   ?? 0);
        $reorder_point = (int)($_POST['reorder_point'] ?? 10);
        $unit_price    = (float)($_POST['unit_price']  ?? 0);

        // Validate inputs - server side validations
        if ($item_name   === '') $errors['item_name']   = 'Item name is required.';
        if ($category    === 'Not Selected') $errors['category']    = 'Category is required.';
        if ($unit_price  === '') $errors['unit_price']  = 'Unit price is required.';
        if ($unit_price  <= 0)   $errors['unit_price']  = 'Unit price must be greater than zero.';
        if ($stock_level  < 0)   $errors['stock_level'] = 'Stock level cannot be negative.';

        if (empty($errors)) {
            $stmt = $db->prepare(
                'INSERT INTO inventory (item_name, category, stock_level, reorder_point, unit_price)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$item_name, $category ?: null, $stock_level, $reorder_point, $unit_price]);
            $item_id = (int)$db->lastInsertId();

            write_audit_log('INSERT', 'inventory', $item_id, null, [
                'item_name' => $item_name, 'stock_level' => $stock_level, 'unit_price' => $unit_price,
            ]);

            flash_set('success', "Item \"$item_name\" added to inventory.");
            redirect('/pages/inventory.php');
        }

    // Create purchase order
    } elseif ($action === 'create_po') {
        $item_id     = (int)($_POST['item_id']     ?? 0);
        $supplier_id = (int)($_POST['supplier_id'] ?? 0);
        $quantity    = (int)($_POST['po_quantity'] ?? 0);
        $total_cost  = (float)($_POST['total_cost'] ?? 0);

        if (!$item_id)      $errors['item_id']     = 'Please select an item.';
        if (!$supplier_id)  $errors['supplier_id'] = 'Please select a supplier.';
        if ($quantity <= 0) $errors['po_quantity']  = 'Quantity must be greater than zero.';
        if ($total_cost <= 0) $errors['total_cost'] = 'Total cost must be greater than zero.';

        if (empty($errors)) {
            $stmt = $db->prepare(
                'INSERT INTO purchase_orders (item_id, supplier_id, quantity, total_cost, status, order_date)
                 VALUES (?, ?, ?, ?, "Pending", CURDATE())'
            );
            $stmt->execute([$item_id, $supplier_id, $quantity, $total_cost]);
            $po_id = (int)$db->lastInsertId();

            write_audit_log('INSERT', 'purchase_orders', $po_id, null, [
                'item_id' => $item_id, 'supplier_id' => $supplier_id,
                'quantity' => $quantity, 'total_cost' => $total_cost,
            ]);

            flash_set('success', 'Purchase order #' . $po_id . ' created.');
            redirect('/pages/inventory.php?tab=orders');
        }

    // Receive stock (triggers inventory update)
    } elseif ($action === 'receive_stock') {
        $po_id    = (int)($_POST['po_id']              ?? 0);
        $received = (int)($_POST['quantity_received']  ?? 0);

        if (!$po_id)      $errors['po_id']              = 'Invalid purchase order.';
        if ($received <= 0) $errors['quantity_received'] = 'Received quantity must be greater than zero.';

        if (empty($errors)) {
            // trg_stock_receipt_update trigger handles inventory.stock_level increment
            $stmt = $db->prepare(
                'INSERT INTO stock_receipts (po_id, quantity_received, received_date)
                 VALUES (?, ?, CURDATE())'
            );
            $stmt->execute([$po_id, $received]);
            $receipt_id = (int)$db->lastInsertId();

            write_audit_log('INSERT', 'stock_receipts', $receipt_id, null, [
                'po_id' => $po_id, 'quantity_received' => $received,
            ]);

            flash_set('success', 'Stock receipt recorded. Inventory updated automatically.');
            redirect('/pages/inventory.php?tab=orders');
        }

    // Resolve stock alert
    } elseif ($action === 'resolve_alert') {
        $alert_id = (int)($_POST['alert_id'] ?? 0);
        if ($alert_id) {
            $db->prepare('UPDATE stock_alerts SET is_resolved = 1 WHERE alert_id = ?')
               ->execute([$alert_id]);
            flash_set('success', 'Alert resolved.');
        }
        redirect('/pages/inventory.php?tab=alerts');
    }
}

// Active tab
$tab = in_array($_GET['tab'] ?? '', ['items','alerts','orders','suppliers']) ? $_GET['tab'] : 'items';

// Load data
$inventory = $db->query(
    'SELECT item_id, item_name, category, stock_level, reorder_point, unit_price
     FROM inventory ORDER BY item_name'
)->fetchAll();

$alert_count = (int)$db->query(
    'SELECT COUNT(*) FROM stock_alerts WHERE is_resolved = 0'
)->fetchColumn();

$alerts = $db->query(
    'SELECT a.alert_id, a.stock_level AS alert_stock, a.alert_date, a.is_resolved,
            i.item_name, i.reorder_point
     FROM stock_alerts a
     JOIN inventory i ON i.item_id = a.item_id
     ORDER BY a.is_resolved ASC, a.alert_date DESC
     LIMIT 60'
)->fetchAll();

$purchase_orders = $db->query(
    'SELECT po.po_id, po.quantity, po.total_cost, po.status, po.order_date,
            i.item_name, s.supplier_name
     FROM purchase_orders po
     JOIN inventory i ON i.item_id = po.item_id
     JOIN suppliers s ON s.supplier_id = po.supplier_id
     ORDER BY po.order_date DESC
     LIMIT 60'
)->fetchAll();

$suppliers = $db->query(
    'SELECT supplier_id, supplier_name, contact_email, phone FROM suppliers ORDER BY supplier_name'
)->fetchAll();

require_once '../includes/header.php';
?>

<div class="page-header">
    <h1>Inventory</h1>
    <p>Manage stock, purchase orders and supplier information.</p>
</div>

<!--  Summary badges  -->
<div class="stats-grid" style="margin-bottom:24px;">
    <div class="stat-card">
        <div class="stat-icon blue">&#128200;</div>
        <div class="stat-info">
            <h3><?= count($inventory) ?></h3>
            <p>Inventory Items</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon <?= $alert_count > 0 ? 'orange' : 'green' ?>">&#9888;</div>
        <div class="stat-info">
            <h3><?= $alert_count ?></h3>
            <p>Active Stock Alerts</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon teal">&#128203;</div>
        <div class="stat-info">
            <h3><?= count($purchase_orders) ?></h3>
            <p>Purchase Orders</p>
        </div>
    </div>
</div>

<!--  Tabs  -->
<div style="display:flex;gap:4px;margin-bottom:20px;border-bottom:2px solid var(--border);">
    <?php
    $tabs = ['items' => 'Items', 'alerts' => 'Stock Alerts' . ($alert_count > 0 ? " ($alert_count)" : ''), 'orders' => 'Purchase Orders', 'suppliers' => 'Suppliers'];
    foreach ($tabs as $t_key => $t_label):
    ?>
    <a href="<?= BASE_URL ?>/pages/inventory.php?tab=<?= $t_key ?>"
       style="padding:10px 18px;font-size:.9rem;font-weight:600;text-decoration:none;border-bottom:2px solid <?= ($tab === $t_key) ? 'var(--secondary)' : 'transparent' ?>;color:<?= ($tab === $t_key) ? 'var(--secondary)' : 'var(--text-muted)' ?>;margin-bottom:-2px;">
        <?= $t_label ?>
    </a>
    <?php endforeach; ?>
</div>

<!--  TAB: Items  -->
<?php if ($tab === 'items'): ?>

<div class="card mb-24">
    <div class="card-body" style="padding:0;">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Item Name</th>
                        <th>Category</th>
                        <th>Stock Level</th>
                        <th>Reorder Point</th>
                        <th>Unit Price</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($inventory)): ?>
                    <tr><td colspan="7" class="text-center text-muted" style="padding:28px;">No items found.</td></tr>
                <?php else: ?>
                    <?php foreach ($inventory as $item):
                        $low_stock = $item['stock_level'] <= $item['reorder_point'];
                    ?>
                    <tr>
                        <td class="text-muted" style="font-size:.85rem;"><?= (int)$item['item_id'] ?></td>
                        <td><strong><?= h($item['item_name']) ?></strong></td>
                        <td style="font-size:.88rem;"><?= $item['category'] ? h($item['category']) : '<span class="text-muted">—</span>' ?></td>
                        <td>
                            <span style="font-weight:700;color:<?= $low_stock ? 'var(--danger)' : 'var(--success)' ?>;">
                                <?= (int)$item['stock_level'] ?>
                            </span>
                        </td>
                        <td><?= (int)$item['reorder_point'] ?></td>
                        <td>R<?= number_format((float)$item['unit_price'], 2) ?></td>
                        <td>
                            <?php if ($low_stock): ?>
                            <span class="status-badge status-cancelled">Low Stock</span>
                            <?php else: ?>
                            <span class="status-badge status-confirmed">In Stock</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!--  Add New Item Form  -->
<?php if ($can_write): ?>
<div class="card">
    <div class="card-header"><h2>Add New Item</h2></div>
    <div class="card-body">
        <form method="POST" action="<?= BASE_URL ?>/pages/inventory.php" data-validate novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_item">
            <div class="form-grid">

                <div class="form-group">
                    <label for="item_name" class="required">Item Name</label>
                    <input type="text" id="item_name" name="item_name" required maxlength="255"
                           value="<?= h($_POST['item_name'] ?? '') ?>">
                    <span class="field-error"><?= h($errors['item_name'] ?? '') ?></span>
                </div>

                <div class="form-group">
                    <label for="category" class="required">Category</label>
                    <select id="category" name="category" required>
                        <option value="">Not Selected</option>
                        <?php foreach (['Medication','Equipment','Consumable','Other'] as $cat): ?>
                        <option value="<?= $cat ?>"
                            <?= (($_POST['category'] ?? '') === $cat) ? 'selected' : '' ?>>
                            <?= $cat ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field-error"><?= h($errors['category'] ?? '') ?></span>
                </div>

                <div class="form-group">
                    <label for="stock_level">Initial Stock Level</label>
                    <input type="number" id="stock_level" name="stock_level" min="0"
                           value="<?= (int)($_POST['stock_level'] ?? 0) ?>">
                    <span class="field-error"><?= h($errors['stock_level'] ?? '') ?></span>
                </div>

                <div class="form-group">
                    <label for="reorder_point">Reorder Point</label>
                    <input type="number" id="reorder_point" name="reorder_point" min="0"
                           value="<?= (int)($_POST['reorder_point'] ?? 10) ?>">
                    <span class="field-error"><?= h($errors['reorder_point'] ?? '') ?></span>
                    <span class="field-hint">Alert fires when stock falls to or below this level.</span>
                </div>

                <div class="form-group">
                    <label for="unit_price" class="required">Unit Price (R)</label>
                    <input type="number" id="unit_price" name="unit_price" required
                           min="0.01" step="0.01" placeholder="0.00"
                           value="<?= h($_POST['unit_price'] ?? '') ?>">
                    <span class="field-error"><?= h($errors['unit_price'] ?? '') ?></span>
                </div>

            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Add Item</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!--  TAB: Stock Alerts  -->
<?php elseif ($tab === 'alerts'): ?>

<div class="card">
    <div class="card-body" style="padding:0;">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Item</th>
                        <th>Stock at Alert</th>
                        <th>Reorder Point</th>
                        <th>Alert Date</th>
                        <th>Status</th>
                        <?php if ($can_write): ?><th>Action</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($alerts)): ?>
                    <tr><td colspan="7" class="text-center text-muted" style="padding:28px;">No stock alerts.</td></tr>
                <?php else: ?>
                    <?php foreach ($alerts as $alert): ?>
                    <tr>
                        <td class="text-muted" style="font-size:.85rem;"><?= (int)$alert['alert_id'] ?></td>
                        <td><strong><?= h($alert['item_name']) ?></strong></td>
                        <td><span style="color:var(--danger);font-weight:700;"><?= (int)$alert['alert_stock'] ?></span></td>
                        <td><?= (int)$alert['reorder_point'] ?></td>
                        <td style="font-size:.88rem;white-space:nowrap;">
                            <?= h(date('d M Y H:i', strtotime($alert['alert_date']))) ?>
                        </td>
                        <td>
                            <?php if ($alert['is_resolved']): ?>
                            <span class="status-badge status-completed">Resolved</span>
                            <?php else: ?>
                            <span class="status-badge status-cancelled">Active</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($can_write): ?>
                        <td>
                            <?php if (!$alert['is_resolved']): ?>
                            <form method="POST" action="<?= BASE_URL ?>/pages/inventory.php?tab=alerts"
                                  style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action"   value="resolve_alert">
                                <input type="hidden" name="alert_id" value="<?= (int)$alert['alert_id'] ?>">
                                <button type="submit" class="btn btn-secondary btn-sm">Resolve</button>
                            </form>
                            <?php else: ?>
                            <span class="text-muted" style="font-size:.8rem;">—</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!--  TAB: Purchase Orders  -->
<?php elseif ($tab === 'orders'): ?>

<div class="card mb-24">
    <div class="card-body" style="padding:0;">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Item</th>
                        <th>Supplier</th>
                        <th>Qty</th>
                        <th>Total Cost</th>
                        <th>Order Date</th>
                        <th>Status</th>
                        <?php if ($can_write): ?><th>Receive</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($purchase_orders)): ?>
                    <tr><td colspan="8" class="text-center text-muted" style="padding:28px;">No purchase orders.</td></tr>
                <?php else: ?>
                    <?php foreach ($purchase_orders as $po):
                        $po_class = match($po['status']) {
                            'Received' => 'status-completed', 'Confirmed' => 'status-confirmed',
                            default    => 'status-scheduled',
                        };
                    ?>
                    <tr>
                        <td class="text-muted" style="font-size:.85rem;"><?= (int)$po['po_id'] ?></td>
                        <td><strong><?= h($po['item_name']) ?></strong></td>
                        <td style="font-size:.88rem;"><?= h($po['supplier_name']) ?></td>
                        <td><?= (int)$po['quantity'] ?></td>
                        <td>R<?= number_format((float)$po['total_cost'], 2) ?></td>
                        <td style="font-size:.88rem;white-space:nowrap;">
                            <?= h(date('d M Y', strtotime($po['order_date']))) ?>
                        </td>
                        <td><span class="status-badge <?= $po_class ?>"><?= h($po['status']) ?></span></td>
                        <?php if ($can_write): ?>
                        <td>
                            <?php if ($po['status'] !== 'Received'): ?>
                            <button type="button" class="btn btn-success btn-sm"
                                    onclick="toggleReceiveForm(<?= (int)$po['po_id'] ?>)">Receive</button>
                            <?php else: ?>
                            <span class="text-muted" style="font-size:.8rem;">Done</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php if ($can_write && $po['status'] !== 'Received'): ?>
                    <tr id="recv-row-<?= (int)$po['po_id'] ?>" style="display:none;background:#f0f9f0;">
                        <td colspan="8" style="padding:14px 20px;">
                            <form method="POST" action="<?= BASE_URL ?>/pages/inventory.php?tab=orders"
                                  style="display:flex;gap:12px;align-items:flex-end;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="receive_stock">
                                <input type="hidden" name="po_id" value="<?= (int)$po['po_id'] ?>">
                                <div class="form-group" style="margin:0;min-width:160px;">
                                    <label style="font-size:.8rem;">Quantity Received</label>
                                    <input type="number" name="quantity_received" min="1"
                                           value="<?= (int)$po['quantity'] ?>" style="padding:8px 10px;">
                                </div>
                                <button type="submit" class="btn btn-success btn-sm">Confirm Receipt</button>
                                <button type="button" class="btn btn-secondary btn-sm"
                                        onclick="toggleReceiveForm(<?= (int)$po['po_id'] ?>)">Cancel</button>
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

<?php if ($can_write): ?>
<div class="card">
    <div class="card-header"><h2>Create Purchase Order</h2></div>
    <div class="card-body">
        <form method="POST" action="<?= BASE_URL ?>/pages/inventory.php?tab=orders" data-validate novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_po">
            <div class="form-grid">

                <div class="form-group">
                    <label for="item_id" class="required">Item</label>
                    <select id="item_id" name="item_id" required>
                        <option value="">— Select Item —</option>
                        <?php foreach ($inventory as $i): ?>
                        <option value="<?= (int)$i['item_id'] ?>"
                            <?= ((int)($_POST['item_id'] ?? 0) === (int)$i['item_id']) ? 'selected' : '' ?>>
                            <?= h($i['item_name']) ?> (stock: <?= (int)$i['stock_level'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field-error"><?= h($errors['item_id'] ?? '') ?></span>
                </div>

                <div class="form-group">
                    <label for="supplier_id" class="required">Supplier</label>
                    <select id="supplier_id" name="supplier_id" required>
                        <option value="">— Select Supplier —</option>
                        <?php foreach ($suppliers as $s): ?>
                        <option value="<?= (int)$s['supplier_id'] ?>"
                            <?= ((int)($_POST['supplier_id'] ?? 0) === (int)$s['supplier_id']) ? 'selected' : '' ?>>
                            <?= h($s['supplier_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field-error"><?= h($errors['supplier_id'] ?? '') ?></span>
                </div>

                <div class="form-group">
                    <label for="po_quantity" class="required">Quantity</label>
                    <input type="number" id="po_quantity" name="po_quantity" required min="1"
                           value="<?= (int)($_POST['po_quantity'] ?? 1) ?>">
                    <span class="field-error"><?= h($errors['po_quantity'] ?? '') ?></span>
                </div>

                <div class="form-group">
                    <label for="total_cost" class="required">Total Cost (R)</label>
                    <input type="number" id="total_cost" name="total_cost" required
                           min="0.01" step="0.01" placeholder="0.00"
                           value="<?= h($_POST['total_cost'] ?? '') ?>">
                    <span class="field-error"><?= h($errors['total_cost'] ?? '') ?></span>
                </div>

            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Create PO</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!--  TAB: Suppliers  -->
<?php elseif ($tab === 'suppliers'): ?>

<div class="card">
    <div class="card-body" style="padding:0;">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr><th>#</th><th>Supplier Name</th><th>Email</th><th>Phone</th></tr>
                </thead>
                <tbody>
                <?php if (empty($suppliers)): ?>
                    <tr><td colspan="4" class="text-center text-muted" style="padding:28px;">No suppliers found.</td></tr>
                <?php else: ?>
                    <?php foreach ($suppliers as $s): ?>
                    <tr>
                        <td class="text-muted" style="font-size:.85rem;"><?= (int)$s['supplier_id'] ?></td>
                        <td><strong><?= h($s['supplier_name']) ?></strong></td>
                        <td><?= h($s['contact_email']) ?></td>
                        <td><?= h($s['phone']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

<script>
function toggleReceiveForm(poId) {
    var row = document.getElementById('recv-row-' + poId);
    if (row) { row.style.display = row.style.display === 'none' ? 'table-row' : 'none'; }
}
</script>

<?php require_once '../includes/footer.php'; ?>
