<?php
/**
 * includes/header.php
 *
 * Outputs: full HTML <head>, sidebar navigation, top bar.
 * Must be included AFTER require_login() / require_role() has already been called.
 * Expects $page_title to be set in the calling page.
 */

require_once __DIR__ . '/../includes/auth.php';

$page_title   = isset($page_title) ? $page_title : APP_NAME;
$current_file = basename($_SERVER['PHP_SELF']);
$role         = current_role();

// Navigation items per role
$nav_items = [
    ['label' => 'Dashboard', 'href' => BASE_URL . '/pages/dashboard.php', 'icon' => '&#9783;', 'file' => 'dashboard.php'],
];

if ($role === 'SuperAdmin') {
    $nav_items = array_merge($nav_items, [
        ['label' => 'Register Patient',  'href' => BASE_URL . '/pages/register_patient.php', 'icon' => '&#43;',      'file' => 'register_patient.php'],
        ['label' => 'Patient Profiles',  'href' => BASE_URL . '/pages/patient_profile.php',  'icon' => '&#128100;',  'file' => 'patient_profile.php'],
        ['label' => 'All Appointments',  'href' => BASE_URL . '/pages/appointment_list.php', 'icon' => '&#128197;',  'file' => 'appointment_list.php'],
        ['label' => 'Book Appointment',  'href' => BASE_URL . '/pages/appointment_book.php', 'icon' => '&#43;',      'file' => 'appointment_book.php'],
        ['label' => 'Prescriptions',     'href' => BASE_URL . '/pages/prescriptions.php',    'icon' => '&#128138;',  'file' => 'prescriptions.php'],
        ['label' => 'Billing',           'href' => BASE_URL . '/pages/billing.php',          'icon' => '&#128179;',  'file' => 'billing.php'],
        ['label' => 'Inventory',         'href' => BASE_URL . '/pages/inventory.php',        'icon' => '&#128200;',  'file' => 'inventory.php'],
        ['label' => 'Staff Profiles',    'href' => BASE_URL . '/pages/staff_profiles.php',   'icon' => '&#128101;',  'file' => 'staff_profiles.php'],
        ['label' => 'Staff & Shifts',    'href' => BASE_URL . '/pages/staff_shifts.php',     'icon' => '&#128203;',  'file' => 'staff_shifts.php'],
        ['label' => 'Audit Log',         'href' => BASE_URL . '/pages/audit_log.php',        'icon' => '&#128196;',  'file' => 'audit_log.php'],
    ]);
} elseif ($role === 'Admin') {
    $nav_items = array_merge($nav_items, [
        ['label' => 'Register Patient',  'href' => BASE_URL . '/pages/register_patient.php', 'icon' => '&#43;',     'file' => 'register_patient.php'],
        ['label' => 'Patient Profiles',  'href' => BASE_URL . '/pages/patient_profile.php',  'icon' => '&#128100;', 'file' => 'patient_profile.php'],
        ['label' => 'All Appointments',  'href' => BASE_URL . '/pages/appointment_list.php', 'icon' => '&#128197;', 'file' => 'appointment_list.php'],
        ['label' => 'Book Appointment',  'href' => BASE_URL . '/pages/appointment_book.php', 'icon' => '&#43;',     'file' => 'appointment_book.php'],
        ['label' => 'Prescriptions',     'href' => BASE_URL . '/pages/prescriptions.php',    'icon' => '&#128138;', 'file' => 'prescriptions.php'],
        ['label' => 'Billing',           'href' => BASE_URL . '/pages/billing.php',          'icon' => '&#128179;', 'file' => 'billing.php'],
        ['label' => 'Inventory',         'href' => BASE_URL . '/pages/inventory.php',        'icon' => '&#128200;', 'file' => 'inventory.php'],
    ]);
} elseif ($role === 'Doctor') {
    $nav_items = array_merge($nav_items, [
        ['label' => 'My Appointments',   'href' => BASE_URL . '/pages/appointment_list.php', 'icon' => '&#128197;', 'file' => 'appointment_list.php'],
        ['label' => 'Patient Profiles',  'href' => BASE_URL . '/pages/patient_profile.php',  'icon' => '&#128100;', 'file' => 'patient_profile.php'],
        ['label' => 'Prescriptions',     'href' => BASE_URL . '/pages/prescriptions.php',    'icon' => '&#128138;', 'file' => 'prescriptions.php'],
        ['label' => 'My Shifts',         'href' => BASE_URL . '/pages/staff_shifts.php',     'icon' => '&#128197;', 'file' => 'staff_shifts.php'],
    ]);
} elseif ($role === 'Nurse') {
    $nav_items = array_merge($nav_items, [
        ['label' => 'Register Patient',  'href' => BASE_URL . '/pages/register_patient.php', 'icon' => '&#43;',     'file' => 'register_patient.php'],
        ['label' => 'Patient Profiles',  'href' => BASE_URL . '/pages/patient_profile.php',  'icon' => '&#128100;', 'file' => 'patient_profile.php'],
        ['label' => 'Appointments',      'href' => BASE_URL . '/pages/appointment_list.php', 'icon' => '&#128197;', 'file' => 'appointment_list.php'],
        ['label' => 'Book Appointment',  'href' => BASE_URL . '/pages/appointment_book.php', 'icon' => '&#43;',     'file' => 'appointment_book.php'],
        ['label' => 'Prescriptions',     'href' => BASE_URL . '/pages/prescriptions.php',    'icon' => '&#128138;', 'file' => 'prescriptions.php'],
        ['label' => 'Inventory',         'href' => BASE_URL . '/pages/inventory.php',        'icon' => '&#128200;', 'file' => 'inventory.php'],
        ['label' => 'My Shifts',         'href' => BASE_URL . '/pages/staff_shifts.php',     'icon' => '&#128197;', 'file' => 'staff_shifts.php'],
    ]);
} elseif ($role === 'Pharmacist') {
    $nav_items = array_merge($nav_items, [
        ['label' => 'Prescriptions',     'href' => BASE_URL . '/pages/prescriptions.php',    'icon' => '&#128138;', 'file' => 'prescriptions.php'],
        ['label' => 'Inventory',         'href' => BASE_URL . '/pages/inventory.php',        'icon' => '&#128200;', 'file' => 'inventory.php'],
    ]);
} elseif ($role === 'Patient') {
    $nav_items = array_merge($nav_items, [
        ['label' => 'My Profile',       'href' => BASE_URL . '/pages/patient_profile.php',  'icon' => '&#128100;', 'file' => 'patient_profile.php'],
        ['label' => 'My Appointments',  'href' => BASE_URL . '/pages/appointment_list.php', 'icon' => '&#128197;', 'file' => 'appointment_list.php'],
        ['label' => 'Book Appointment', 'href' => BASE_URL . '/pages/appointment_book.php', 'icon' => '&#43;',     'file' => 'appointment_book.php'],
    ]);
}

// My Security — available to all authenticated users
$nav_items[] = ['label' => 'My Security', 'href' => BASE_URL . '/pages/my_security.php', 'icon' => '&#128274;', 'file' => 'my_security.php'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/style.css">
    <script>
        window.HMS_BASE_URL        = '<?= BASE_URL ?>';
        window.HMS_SYNC_TOKEN      = '<?= get_sync_token() ?>';
        window.HMS_ENV             = '<?= APP_ENV ?>';
        window.HMS_SESSION_LIFETIME = <?= SESSION_LIFETIME ?>;
    </script>
</head>
<body>

<div class="layout">

    <!-- Sidebar  -->
    <aside class="sidebar" id="sidebar" aria-label="Main navigation">
        <div class="sidebar-brand">
            <img src="<?= BASE_URL ?>/img/hms-logo.png" width="56" height="56" alt="<?= APP_SUBTITLE ?> Logo" class="brand-logo">
            <span class="brand-text"><?= APP_TAGLINE ?></span>
        </div>

        <nav class="sidebar-nav">
            <?php foreach ($nav_items as $item): ?>
            <a href="<?= $item['href'] ?>"
               class="nav-item <?= ($current_file === $item['file']) ? 'active' : '' ?>">
                <span class="nav-icon"><?= $item['icon'] ?></span>
                <span class="nav-label"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
            </a>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer">
            <a href="<?= BASE_URL ?>/pages/logout.php" class="nav-item logout-link">
                <span class="nav-icon">&#9211;</span>
                <span class="nav-label">Logout</span>
            </a>
        </div>
    </aside>

    <!-- Main wrapper  -->
    <div class="main-wrapper">

        <!-- Top bar -->
        <header class="topbar">
            <button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">&#9776;</button>
            <span class="topbar-title"><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?></span>
            <div class="topbar-right">
                <!-- Offline sync status badge (managed by sync_manager.js) -->
                <span id="sync-badge" class="sync-badge sync-ok" title="All changes synced">&#10003; Synced</span>
                <span class="badge-role badge-<?= strtolower(h($role)) ?>">
                    <?= h($role) ?>
                </span>
                <span class="topbar-user"><?= h(current_full_name()) ?></span>
            </div>
        </header>

        <!-- Flash messages (output once per page load) -->
        <?php foreach (['success', 'error', 'warning', 'info'] as $_ftype):
            $_fmsg = flash_get($_ftype);
            if ($_fmsg !== null): ?>
        <div class="alert alert-<?= $_ftype ?>" role="alert">
            <span><?= htmlspecialchars($_fmsg, ENT_QUOTES, 'UTF-8') ?></span>
            <button class="alert-close" onclick="this.parentElement.remove()" aria-label="Close">&#215;</button>
        </div>
        <?php endif; endforeach; ?>

        <!-- Page content starts here -->
        <main class="main-content">
