<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once __DIR__ . '/bootstrap.php';
lum_page('configs', 'view');
// Start Output Buffering to prevent header conflicts with the embedded Universal Import script
ob_start();

$can_manage_users = lum_can('users', 'view'); // User management section

// --- DATABASE CONNECTIONS (bootstrap.php) ---
$core_db_conn = lum_db('properties', true);   // Properties table
$info_db_conn = lum_db('information', true);  // OBIS and estimate time ranges

$searchTerm = $_GET['search'] ?? '';

// Fetch Property Configurations
try {
    $query = "SELECT * FROM `lum_properties`";
    if (!empty($searchTerm)) {
        $query .= " WHERE `Property` LIKE :search OR `Location` LIKE :search OR `municipality` LIKE :search";
    }
    $query .= " ORDER BY `Property` ASC";
    
    $stmt = $core_db_conn->prepare($query);
    
    if (!empty($searchTerm)) {
        $stmt->bindValue(':search', '%' . $searchTerm . '%');
    }
    
    $stmt->execute();
    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('LUM view-configs query failed: ' . $e->getMessage());
    $error_message = 'Unable to load this data. Please contact the system administrator.';
}

// Fetch OBIS Time Ranges
try {
    $stmt_obis = $info_db_conn->query("SELECT * FROM `lum_obis_time_ranges` ORDER BY `obis_code` ASC");
    $obis_ranges = $stmt_obis->fetchAll(PDO::FETCH_ASSOC);
    // Order and names from the table (obis-codes-setup.sql adds them)
    usort($obis_ranges, function ($a, $b) {
        return [(int)($a['sort_order'] ?? 0), $a['obis_code']] <=> [(int)($b['sort_order'] ?? 0), $b['obis_code']];
    });
} catch (Exception $e) {
    error_log('LUM view-configs query failed: ' . $e->getMessage());
    $error_message_obis = 'Unable to load this data. Please contact the system administrator.';
}

// "Grid Supply (kWh)" per OBIS code, for both time range lists
$obis_desc_by_code = [];
foreach (($obis_ranges ?? []) as $o_row) {
    $o_desc = trim((string)($o_row['obis_label'] ?? ''));
    if ($o_desc === '') continue;
    if (trim((string)($o_row['obis_unit'] ?? '')) !== '') $o_desc .= ' (' . trim($o_row['obis_unit']) . ')';
    $obis_desc_by_code[$o_row['obis_code']] = $o_desc;
}

// Fetch Estimate Time Ranges
try {
    $stmt_estimate = $info_db_conn->query("SELECT * FROM `lum_estimate_time_ranges` ORDER BY `obis_code` ASC");
    $estimate_ranges = $stmt_estimate->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('LUM view-configs query failed: ' . $e->getMessage());
    $error_message_estimate = 'Unable to load this data. Please contact the system administrator.';
}

// --- USER MANAGEMENT PROCESSING (Admin Only) ---
$um_message = "";
$edit_user_id = isset($_GET['edit_user']) ? intval($_GET['edit_user']) : 0;
$show_user_form = false;
$user_form_mode = '';
$users = [];
$target_user = null;
$user_props = [];

// Dynamically build allowed properties array for User Management assignment from the DB lookup above
$all_properties_um = [];
if (!empty($properties)) {
    foreach ($properties as $p) {
        $all_properties_um[] = $p['Property'];
    }
}

if ($can_manage_users) {
    $login_db_conn = lum_db_mysqli_users(true);
    // Audit trail (switches itself off if the logger is missing)
    lum_use('audit');

    // Every role that exists: Admin, roles with page-access rules, and roles still assigned to users
    // (the same list as user-management.php, so custom roles are never reset to Standard here)
    $um_available_roles = ['Standard'];
    $um_access_db = lum_guard_db();
    if ($um_access_db) {
        try {
            $um_available_roles = array_merge($um_available_roles, $um_access_db->query("SELECT DISTINCT role FROM lum_sys_role_access")->fetchAll(PDO::FETCH_COLUMN));
        } catch (\Throwable $e) {
            // Access tables not created yet: the roles in use below still apply
        }
    }
    $um_role_res = $login_db_conn->query("SELECT DISTINCT user_role FROM lum_sys_users");
    if ($um_role_res) {
        while ($um_r = $um_role_res->fetch_row()) { $um_available_roles[] = (string)$um_r[0]; }
    }
    $um_available_roles = array_values(array_diff(array_unique($um_available_roles), ['Admin', '']));
    sort($um_available_roles);
    array_unshift($um_available_roles, 'Admin');

    if (isset($_GET['msg'])) {
        if ($_GET['msg'] === 'user_added') $um_message = "<div class='alert alert-success border-0 shadow-sm'><i class='bi bi-check-circle-fill me-2'></i>User registered successfully.</div>";
        if ($_GET['msg'] === 'user_updated') $um_message = "<div class='alert alert-success border-0 shadow-sm'><i class='bi bi-check-circle-fill me-2'></i>User profile updated successfully.</div>";
        if ($_GET['msg'] === 'user_deleted') $um_message = "<div class='alert alert-success border-0 shadow-sm'><i class='bi bi-check-circle-fill me-2'></i>User successfully deleted.</div>";
    }

    if (isset($_GET['action']) && $_GET['action'] === 'new_user') {
        $show_user_form = true;
        $user_form_mode = 'add';
    }

    if ($edit_user_id > 0) {
        $show_user_form = true;
        $user_form_mode = 'edit';
    }

    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['um_action'])) {
        
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
            die("<div class='alert alert-danger m-4 text-center'>Security Error: CSRF Token Validation Failed. Request Blocked.</div>");
        }
        // Adding/editing users needs edit rights, deleting needs delete rights
        lum_require_access('users', ($_POST['um_action'] ?? '') === 'delete' ? 'delete' : 'edit');
        // Only administrators may create, change or delete administrator accounts
        if (!lum_is_admin()) {
            $lum_target_id = (int)($_POST['delete_user_id'] ?? ($_POST['edit_id'] ?? 0));
            $lum_target_role = null;
            if ($lum_target_id > 0) {
                $lum_st = $login_db_conn->prepare("SELECT user_role FROM lum_sys_users WHERE user_id = ?");
                $lum_st->bind_param("i", $lum_target_id);
                $lum_st->execute();
                $lum_st->bind_result($lum_target_role);
                $lum_st->fetch();
                $lum_st->close();
            }
            if (($_POST['user_role'] ?? '') === 'Admin' || $lum_target_role === 'Admin') {
                lum_deny('Only administrators can create or change administrator accounts.');
            }
        }
        
        if ($_POST['um_action'] === 'delete' && isset($_POST['delete_user_id'])) {
            $del_id = intval($_POST['delete_user_id']);
            if ($del_id === (int)($_SESSION['user_id'] ?? 0)) {
                $um_message = "<div class='alert alert-danger border-0 shadow-sm'><i class='bi bi-exclamation-triangle-fill me-2'></i>You cannot delete your own active account.</div>";
            } else {
                $audit_before = lum_audit_fetch_user($del_id);
                $stmt = $login_db_conn->prepare("DELETE FROM lum_sys_users WHERE user_id = ?");
                $stmt->bind_param("i", $del_id);
                if ($stmt->execute()) {
                    if ($audit_before && $stmt->affected_rows > 0) {
                        lum_audit_log('DELETE', 'user', $del_id, $audit_before['user_name'] ?? null, null, $audit_before, null);
                    }
                    header("Location: view-configs.php?msg=user_deleted");
                    exit();
                } else {
                    error_log('LUM user delete failed: ' . $login_db_conn->error);
                $um_message = "<div class='alert alert-danger'>Error deleting user. Please try again or contact the system administrator.</div>";
                }
                $stmt->close();
            }
        }

        if ($_POST['um_action'] === 'add') {
            $name = trim($_POST['user_name']);
            $email = trim($_POST['user_email']);
            $organization = trim($_POST['user_organization'] ?? '');
            $password = $_POST['user_password'];
            $role = in_array($_POST['user_role'] ?? '', $um_available_roles, true) ? $_POST['user_role'] : 'Standard';
            if (!lum_is_admin()) { $role = 'Standard'; } // Only administrators choose roles
            $status = in_array($_POST['user_status'] ?? '', ['Active', 'Disabled'], true) ? $_POST['user_status'] : 'Active';
            $assigned_properties = isset($_POST['assigned_properties']) ? implode(',', $_POST['assigned_properties']) : null;

            $stmt_check = $login_db_conn->prepare("SELECT user_id FROM lum_sys_users WHERE user_name = ? OR user_email = ?");
            $stmt_check->bind_param("ss", $name, $email);
            $stmt_check->execute();
            $stmt_check->store_result();

            if ($stmt_check->num_rows > 0) {
                $um_message = "<div class='alert alert-danger border-0 shadow-sm'><i class='bi bi-exclamation-triangle-fill me-2'></i>A user with that Username or Email already exists.</div>";
                $show_user_form = true;
                $user_form_mode = 'add';
            } elseif (strlen($password) < 8) {
                $um_message = "<div class='alert alert-warning border-0 shadow-sm'><i class='bi bi-exclamation-triangle-fill me-2'></i>Password must be at least 8 characters long.</div>";
                $show_user_form = true;
                $user_form_mode = 'add';
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $login_db_conn->prepare("INSERT INTO lum_sys_users (user_name, user_email, user_password, user_role, user_status, assigned_properties, user_organization) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssss", $name, $email, $hashed_password, $role, $status, $assigned_properties, $organization);
                
                if ($stmt->execute()) {
                    $new_user_id = $stmt->insert_id;
                    lum_audit_log('INSERT', 'user', $new_user_id, $name, null, null, lum_audit_fetch_user($new_user_id));
                    header("Location: view-configs.php?msg=user_added");
                    exit();
                } else {
                    error_log('LUM user save failed: ' . $login_db_conn->error);
                $um_message = "<div class='alert alert-danger'>The user could not be saved. Please try again or contact the system administrator.</div>";
                    $show_user_form = true;
                    $user_form_mode = 'add';
                }
                $stmt->close();
            }
            $stmt_check->close();
        }

        if ($_POST['um_action'] === 'edit' && isset($_POST['edit_id'])) {
            $edit_target = intval($_POST['edit_id']);
            $name = trim($_POST['user_name']);
            $email = trim($_POST['user_email']);
            $organization = trim($_POST['user_organization'] ?? '');
            $role = in_array($_POST['user_role'] ?? '', $um_available_roles, true) ? $_POST['user_role'] : 'Standard';
            if (!lum_is_admin()) { $role = $lum_target_role ?? 'Standard'; } // Only administrators change roles (current role kept)
            $status = in_array($_POST['user_status'] ?? '', ['Active', 'Disabled'], true) ? $_POST['user_status'] : 'Active';
            if ($edit_target === (int)($_SESSION['user_id'] ?? 0)) { $status = 'Active'; } // Admins cannot disable themselves
            $new_password = $_POST['user_password'];
            $assigned_properties = isset($_POST['assigned_properties']) ? implode(',', $_POST['assigned_properties']) : null;

            $stmt_check = $login_db_conn->prepare("SELECT user_id FROM lum_sys_users WHERE user_email = ? AND user_id != ?");
            $stmt_check->bind_param("si", $email, $edit_target);
            $stmt_check->execute();
            $stmt_check->store_result();

            if ($stmt_check->num_rows > 0) {
                $um_message = "<div class='alert alert-danger border-0 shadow-sm'><i class='bi bi-exclamation-triangle-fill me-2'></i>That Email Address is already registered to another user.</div>";
                $show_user_form = true;
                $user_form_mode = 'edit';
                $edit_user_id = $edit_target;
            } else {
                $audit_before = lum_audit_fetch_user($edit_target);
                if (!empty($new_password)) {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $login_db_conn->prepare("UPDATE lum_sys_users SET user_name=?, user_email=?, user_password=?, user_role=?, user_status=?, assigned_properties=?, user_organization=? WHERE user_id=?");
                    $stmt->bind_param("sssssssi", $name, $email, $hashed_password, $role, $status, $assigned_properties, $organization, $edit_target);
                } else {
                    $stmt = $login_db_conn->prepare("UPDATE lum_sys_users SET user_name=?, user_email=?, user_role=?, user_status=?, assigned_properties=?, user_organization=? WHERE user_id=?");
                    $stmt->bind_param("ssssssi", $name, $email, $role, $status, $assigned_properties, $organization, $edit_target);
                }
                
                if ($stmt->execute()) {
                    $audit_after = lum_audit_fetch_user($edit_target);
                    if (!empty($new_password) && is_array($audit_before) && is_array($audit_after)) {
                        $audit_before['password'] = null;
                        $audit_after['password'] = 'changed';
                    }
                    lum_audit_log('UPDATE', 'user', $edit_target, $name, null, $audit_before, $audit_after);
                    header("Location: view-configs.php?msg=user_updated");
                    exit();
                } else {
                    error_log('LUM user save failed: ' . $login_db_conn->error);
                $um_message = "<div class='alert alert-danger'>The user could not be saved. Please try again or contact the system administrator.</div>";
                    $show_user_form = true;
                    $user_form_mode = 'edit';
                    $edit_user_id = $edit_target;
                }
                $stmt->close();
            }
            $stmt_check->close();
        }
    }

    // Fetch users list
    $result = $login_db_conn->query("SELECT user_id, user_name, user_email, user_role, user_status, last_login, user_organization FROM lum_sys_users ORDER BY user_name ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
    }

    if ($show_user_form && $user_form_mode === 'edit' && $edit_user_id > 0) {
        $stmt = $login_db_conn->prepare("SELECT * FROM lum_sys_users WHERE user_id = ?");
        $stmt->bind_param("i", $edit_user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows === 1) {
            $target_user = $res->fetch_assoc();
            $user_props = !empty($target_user['assigned_properties']) ? explode(',', $target_user['assigned_properties']) : [];
        } else {
            $show_user_form = false; 
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Configurations - Lynx Utility Management</title>
    
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>

    <style>
        /* Enforce Absolute Sharpness and Remove All Default Bolding */
        * {
            border-radius: 0 !important;
        }
        /* Specific exemption to slightly round buttons */
        .btn { border-radius: 4px !important; }
        h1, h2, h3, h4, h5, h6, th, td, p, span, div, a, label, button, input, select {
            font-weight: normal !important;
        }
        /* SCROLL GLITCH FIX: Prevents main body from scrolling and controls layout via flexbox */
        body {
            background-color: #121212;
            color: #ffffff;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow: hidden; 
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .navbar, .action-bar {
            flex-shrink: 0; /* Ensures Navbars don't shrink */
        }
        /* Dedicated scroll container below the navbars */
        .main-scroll-area {
            flex-grow: 1;
            overflow-y: auto;
            overflow-x: hidden;
        }
        /* Global Custom Scrollbar */
        ::-webkit-scrollbar { width: 14px; height: 14px; }
        ::-webkit-scrollbar-track { background: #0a0a0a; border-radius: 0; }
        ::-webkit-scrollbar-thumb { background: #4a4a4a; border-radius: 0; }
        /* Matched Action Bar Spacing */
        .action-bar {
            background-color: #0a0a0a;
            border-bottom: 1px solid #333333;
            padding: 10px 0;
        }
        .btn-brand {
            background-color: #e3000f;
            color: #ffffff;
            border: none;
            transition: 0.3s;
        }
        .btn-brand:hover {
            background-color: #bf000c;
            color: #ffffff;
            transform: translateY(-1px);
        }
        .collapse-header {
            background-color: #1a1a1a;
            border: 1px solid #333333;
            padding: 16px 20px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .collapse-header:hover {
            background-color: #222222;
        }
        .toggle-icon {
            color: #e3000f;
            transition: transform 0.3s ease;
        }
        .table-container {
            background-color: #000000;
            border: 1px solid #333333;
            overflow: hidden;
        }
        .table {
            margin-bottom: 0;
            color: #e0e0e0;
        }
        .table thead th {
            background-color: #1a1a1a;
            color: #aaaaaa;
            border-bottom: 2px solid #333333;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
            padding: 12px 16px;
        }
        .table tbody td {
            border-bottom: 1px solid #222222;
            padding: 12px 16px;
            vertical-align: middle;
            background-color: transparent;
            color: #cccccc;
        }
        .table tbody tr:hover td {
            background-color: #111111;
        }
        .config-key {
            color: #e3000f;
            font-family: monospace;
            font-size: 0.95rem;
        }
        .obis-code {
            color: #0dcaf0;
            font-family: monospace;
            font-size: 1rem;
        }
        .admin-form-container { 
            background-color: #ffffff; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.1); 
            color: #212529; 
        }
    </style>
</head>
<body>

    <!-- 1. Top Navbar -->
    <?php include LUM_ROOT . '/Layout/top-navbar.php'; ?>

    <!-- 2. Sub-Category Action Bar -->
<?php
$lum_sub_title = 'System Configurations';
$lum_sub_back = 'https://lynx-um.co.za/index.php';
ob_start();
?>
<div class="row align-items-center">
                <!-- Left: Action Button -->
                <div class="col-12 d-flex align-items-center">
<?php
$lum_sub_filters = ob_get_clean();
include LUM_ROOT . '/Layout/sub-navbar.php';
?>
        </div>
    </div>

    <!-- 3. SCROLLING CONTAINER (Keeps scrollbar strictly below navbars) -->
    <div class="container-fluid px-4 pb-5 main-scroll-area" id="mainScrollArea">
        
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show border-0 mt-4" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i> <span class="fw-normal">Configuration successfully updated.</span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- SECTION 1: PROPERTIES ACCORDION -->
        <div class="collapse-header d-flex justify-content-between align-items-center mb-3 mt-4 collapsed" data-bs-toggle="collapse" data-bs-target="#propCollapse" aria-expanded="false">
            <h5 class="text-white fw-normal mb-0 fs-6">Property Directory Configurations</h5>
            <i class="bi bi-plus-circle fs-5 toggle-icon"></i>
        </div>
        
        <div class="collapse" id="propCollapse">
            <div class="table-container shadow mb-5 mt-3">
                <div class="p-3 border-bottom border-secondary d-flex justify-content-between align-items-center bg-dark">
                    <div>
                        <h5 class="text-white fw-normal mb-0 fs-6">Property Directory</h5>
                    </div>
                    <a href="edit-property.php" class="btn btn-brand btn-sm shadow-sm fw-normal">
                        <i class="bi bi-plus-lg me-1"></i>Add Property
                    </a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-borderless align-middle">
                        <thead>
                            <tr>
                                <th scope="col" style="width: 3%;">ID</th>
                                <th scope="col" style="width: 12%;">Property Name</th>
                                <th scope="col" style="width: 10%;">Organization</th>
                                <th scope="col" style="width: 10%;">Portfolio</th>
                                <th scope="col" style="width: 8%;">Location</th>
                                <th scope="col" style="width: 8%;">Account No.</th>
                                <th scope="col" style="width: 10%;">Municipality</th>
                                <th scope="col" style="width: 8%;">Elec Billing</th>
                                <th scope="col" style="width: 5%;">PF</th>
                                <th scope="col" style="width: 8%;">Shared NAC</th>
                                <th scope="col" style="width: 8%;">Area (m&sup2;)</th>
                                <th scope="col" class="text-end" style="width: 10%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (isset($error_message)) {
                                echo "<tr><td colspan='12' class='text-center py-4 text-danger fw-normal'>Database Error: " . htmlspecialchars($error_message) . "</td></tr>";
                            } elseif (!empty($properties)) {
                                foreach ($properties as $row) {
                                    $org = !empty($row['property_owner_organization']) ? htmlspecialchars($row['property_owner_organization']) : '<span class="text-muted fst-italic fw-normal">None</span>';
                                    $port = !empty($row['property_owner_portfolio']) ? htmlspecialchars($row['property_owner_portfolio']) : '<span class="text-muted fst-italic fw-normal">None</span>';
                                    $pf = isset($row['power_factor']) ? number_format((float)$row['power_factor'], 2) : '0.85';
                                    $acc = !empty($row['account_number']) ? htmlspecialchars($row['account_number']) : '<span class="text-muted fst-italic fw-normal">None</span>';
                                    $muni = !empty($row['municipality']) ? htmlspecialchars($row['municipality']) : '<span class="text-muted fst-italic fw-normal">None</span>';
                                    $ebill = !empty($row['electrical_billing_type']) ? htmlspecialchars($row['electrical_billing_type']) : '<span class="text-muted fst-italic fw-normal">None</span>';
                                    
                                    $snk = (isset($row['shared_nac_kva']) && $row['shared_nac_kva'] !== null) 
                                           ? "<span class='config-key px-2 py-1 fw-normal' style='background: rgba(255,193,7,0.1); color: #ffc107; border-radius: 0;'>" . number_format((float)$row['shared_nac_kva'], 4) . "</span>" 
                                           : '<span class="text-muted fst-italic fw-normal">None</span>';

                                    $area = (isset($row['property_area']) && $row['property_area'] !== null) 
                                           ? "<span class='text-info fw-normal'>" . number_format((float)$row['property_area'], 2) . "</span>" 
                                           : '<span class="text-muted fst-italic fw-normal">None</span>';
                                    
                                    echo "<tr>";
                                    echo "<td><span class='text-muted fw-normal'>#" . htmlspecialchars($row['id']) . "</span></td>";
                                    echo "<td><span class='text-white fw-normal'>" . htmlspecialchars($row['Property']) . "</span></td>";
                                    echo "<td><span class='text-success fw-normal'>" . $org . "</span></td>";
                                    echo "<td><span class='text-success fw-normal'>" . $port . "</span></td>";
                                    echo "<td><span class='text-light fw-normal'>" . htmlspecialchars($row['Location']) . "</span></td>";
                                    echo "<td><span class='text-info fw-normal'>" . $acc . "</span></td>";
                                    echo "<td><span class='text-warning fw-normal'>" . $muni . "</span></td>";
                                    echo "<td><span class='text-light fw-normal'>" . $ebill . "</span></td>";
                                    echo "<td><span class='config-key px-2 py-1 fw-normal' style='background: rgba(227,0,15,0.1); border-radius: 0;'>" . htmlspecialchars($pf) . "</span></td>";
                                    echo "<td>" . $snk . "</td>";
                                    echo "<td>" . $area . "</td>";
                                    
                                    echo "<td class='text-end'>
                                            <a href='edit-property.php?id=" . htmlspecialchars($row['id']) . "' class='btn btn-sm btn-outline-secondary me-1 fw-normal' title='Edit Property Details'><i class='bi bi-pencil'></i> Edit</a>
                                          </td>";
                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr><td colspan='12' class='text-center py-4 text-muted fw-normal'>No properties found matching your search criteria.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- SECTION 2: OBIS TIME RANGES ACCORDION -->
        <?php if (empty($searchTerm)): ?>
        <div class="collapse-header d-flex justify-content-between align-items-center mb-3 mt-4 collapsed" data-bs-toggle="collapse" data-bs-target="#obisCollapse" aria-expanded="false">
            <h5 class="text-white fw-normal mb-0 fs-6">OBIS Date & Time Constraints</h5>
            <i class="bi bi-plus-circle fs-5 toggle-icon"></i>
        </div>
        
        <div class="collapse" id="obisCollapse">
            <div class="table-container shadow mb-5 mt-3">
                <div class="table-responsive">
                    <table class="table table-hover table-borderless align-middle">
                        <thead>
                            <tr>
                                <th scope="col" style="width: 15%;">Target Variable</th>
                                <th scope="col" style="width: 25%;">OBIS Identifier Code</th>
                                <th scope="col" style="width: 20%;">Start Time Boundary</th>
                                <th scope="col" style="width: 20%;">End Time Boundary</th>
                                <th scope="col" class="text-end" style="width: 20%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (isset($error_message_obis)) {
                                echo "<tr><td colspan='5' class='text-center py-4 text-danger fw-normal'>Database Error: " . htmlspecialchars($error_message_obis) . "</td></tr>";
                            } elseif (!empty($obis_ranges)) {
                                foreach ($obis_ranges as $row) {
                                    
                                    // Name and unit of the code (OBIS Time Ranges; obis-codes-setup.sql)
                                    $desc = $obis_desc_by_code[$row['obis_code']] ?? 'System Metric';

                                    echo "<tr>";
                                    echo "<td><span class='text-white small text-uppercase fw-normal'>" . htmlspecialchars($desc) . "</span></td>";
                                    echo "<td><span class='obis-code px-2 py-1 fw-normal' style='background: rgba(13,202,240,0.1); border-radius: 0;'>" . htmlspecialchars($row['obis_code']) . "</span></td>";
                                    echo "<td><span class='text-white fw-normal'><i class='bi bi-clock me-1 text-secondary'></i>" . htmlspecialchars($row['start_time']) . "</span></td>";
                                    echo "<td><span class='text-white fw-normal'><i class='bi bi-clock me-1 text-secondary'></i>" . htmlspecialchars($row['end_time']) . "</span></td>";
                                    
                                    echo "<td class='text-end'>
                                            <a href='edit-obis-time.php?id=" . htmlspecialchars($row['id']) . "' class='btn btn-sm btn-outline-info me-1 fw-normal' title='Edit Time Constraints'><i class='bi bi-pencil'></i> Edit</a>
                                          </td>";
                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr><td colspan='5' class='text-center py-4 text-muted fw-normal'>No OBIS constraints configured in the database. Please run the setup script.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- SECTION 2.5: ESTIMATE TIME RANGES ACCORDION -->
        <?php if (empty($searchTerm)): ?>
        <div class="collapse-header d-flex justify-content-between align-items-center mb-3 mt-4 collapsed" data-bs-toggle="collapse" data-bs-target="#estimateCollapse" aria-expanded="false">
            <h5 class="text-white fw-normal mb-0 fs-6">Estimated Date & Time Constraints</h5>
            <i class="bi bi-plus-circle fs-5 toggle-icon"></i>
        </div>
        
        <div class="collapse" id="estimateCollapse">
            <div class="table-container shadow mb-5 mt-3">
                <div class="table-responsive">
                    <table class="table table-hover table-borderless align-middle">
                        <thead>
                            <tr>
                                <th scope="col" style="width: 15%;">Target Variable</th>
                                <th scope="col" style="width: 25%;">OBIS Identifier Code</th>
                                <th scope="col" style="width: 20%;">Start Datetime Boundary</th>
                                <th scope="col" style="width: 20%;">End Datetime Boundary</th>
                                <th scope="col" class="text-end" style="width: 20%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (isset($error_message_estimate)) {
                                echo "<tr><td colspan='5' class='text-center py-4 text-danger fw-normal'>Database Error: " . htmlspecialchars($error_message_estimate) . "</td></tr>";
                            } elseif (!empty($estimate_ranges)) {
                                foreach ($estimate_ranges as $row) {
                                    // Name and unit of the code (OBIS Time Ranges; obis-codes-setup.sql)
                                    $desc = $obis_desc_by_code[$row['obis_code']] ?? 'System Metric';

                                    echo "<tr>";
                                    echo "<td><span class='text-white small text-uppercase fw-normal'>" . htmlspecialchars($desc) . "</span></td>";
                                    echo "<td><span class='obis-code px-2 py-1 fw-normal' style='background: rgba(13,202,240,0.1); border-radius: 0;'>" . htmlspecialchars($row['obis_code']) . "</span></td>";
                                    echo "<td><span class='text-white fw-normal'><i class='bi bi-calendar me-1 text-secondary'></i>" . htmlspecialchars($row['start_time']) . "</span></td>";
                                    echo "<td><span class='text-white fw-normal'><i class='bi bi-calendar me-1 text-secondary'></i>" . htmlspecialchars($row['end_time']) . "</span></td>";
                                    
                                    echo "<td class='text-end'>
                                            <a href='edit-estimate-time.php?id=" . htmlspecialchars($row['id']) . "' class='btn btn-sm btn-outline-info me-1 fw-normal' title='Edit Estimate Constraints'><i class='bi bi-pencil'></i> Edit</a>
                                          </td>";
                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr><td colspan='5' class='text-center py-4 text-muted fw-normal'>No Estimate constraints configured in the database.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- SECTION 3: TARIFF CONFIGURATIONS -->
        <?php if (empty($searchTerm)): ?>
        <div class="collapse-header d-flex justify-content-between align-items-center mb-3 mt-4" onclick="window.location.href='../Tarrifs/view-tariffs.php'">
            <h5 class="text-white fw-normal mb-0 fs-6">System Tariffs & Rates</h5>
            <i class="bi bi-arrow-right-circle fs-5" style="color: #e3000f;"></i>
        </div>
        <?php endif; ?>

        <!-- SECTION 4: METER MANAGEMENT CONFIGURATIONS -->
        <?php if (empty($searchTerm)): ?>
        <div class="collapse-header d-flex justify-content-between align-items-center mb-3 mt-4" onclick="window.location.href='meter-configs.php'">
            <h5 class="text-white fw-normal mb-0 fs-6">Meter Management Configurations</h5>
            <i class="bi bi-arrow-right-circle fs-5" style="color: #e3000f;"></i>
        </div>
        <?php endif; ?>
        
        <!-- SECTION 5: BILLING CYCLE MANAGEMENT -->
        <?php if (empty($searchTerm)): ?>
        <div class="collapse-header d-flex justify-content-between align-items-center mb-3 mt-4" onclick="window.location.href='billing-cycles.php'">
            <h5 class="text-white fw-normal mb-0 fs-6">Billing Cycle Management</h5>
            <i class="bi bi-arrow-right-circle fs-5" style="color: #e3000f;"></i>
        </div>
        <?php endif; ?>

        <!-- PUBLIC HOLIDAYS (Time Of Use billing) -->
        <?php if (empty($searchTerm)): ?>
        <div class="collapse-header d-flex justify-content-between align-items-center mb-3 mt-4" onclick="window.location.href='public-holidays.php'">
            <h5 class="text-white fw-normal mb-0 fs-6">Public Holidays</h5>
            <i class="bi bi-arrow-right-circle fs-5" style="color: #e3000f;"></i>
        </div>
        <?php endif; ?>

        <!-- PROPERTY METERS & DASHBOARD -->
        <?php if (empty($searchTerm)): ?>
        <div class="collapse-header d-flex justify-content-between align-items-center mb-3 mt-4" onclick="window.location.href='property-meters.php'">
            <h5 class="text-white fw-normal mb-0 fs-6">Property Meters &amp; Dashboard</h5>
            <i class="bi bi-arrow-right-circle fs-5" style="color: #e3000f;"></i>
        </div>
        <?php endif; ?>

        <!-- COMPANY DETAILS (printed on slips and notes) -->
        <?php if (empty($searchTerm)): ?>
        <div class="collapse-header d-flex justify-content-between align-items-center mb-3 mt-4" onclick="window.location.href='company-details.php'">
            <h5 class="text-white fw-normal mb-0 fs-6">Company Details</h5>
            <i class="bi bi-arrow-right-circle fs-5" style="color: #e3000f;"></i>
        </div>
        <?php endif; ?>

        <!-- SECTION 6: SYSTEM DATA IMPORT ACCORDION -->
        <?php if (empty($searchTerm)): ?>
        <div class="collapse-header d-flex justify-content-between align-items-center mb-3 mt-4 collapsed" data-bs-toggle="collapse" data-bs-target="#importCollapse" aria-expanded="false">
            <h5 class="text-white fw-normal mb-0 fs-6">System Data Import</h5>
            <i class="bi bi-plus-circle fs-5 toggle-icon"></i>
        </div>
        
        <div class="collapse" id="importCollapse">
            <div class="mb-5 mt-3 border">
                <?php
                $lum_import_file = lum_resolve_path('/Import/universal-import.php');
                if (!$lum_import_file) $lum_import_file = lum_resolve_path('/universal-import.php');
                if ($lum_import_file && is_readable($lum_import_file)) {
                    include $lum_import_file;
                } else {
                    echo '<div class="alert alert-warning m-3">Import module is not available on this deployment.</div>';
                }
                ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- SECTION 7: USER MANAGEMENT -->
        <?php if ($can_manage_users && empty($searchTerm)): ?>
        <a href="user-management.php" class="collapse-header d-flex justify-content-between align-items-center mb-3 mt-4 text-decoration-none">
            <h5 class="text-white fw-normal mb-0 fs-6">User Management Dashboard</h5>
            <i class="bi bi-box-arrow-up-right fs-5 toggle-icon"></i>
        </a>
        <?php endif; ?>

    </div>

    <!-- Bootstrap JS Bundle -->
    <?php include LUM_ROOT . '/Layout/foot-assets.php'; ?>

    <!-- Seamless Memory State & Scroll Retention Engine -->
    <script>
        // Override native browser scroll-jumping behavior
        if ('scrollRestoration' in history) {
            history.scrollRestoration = 'manual';
        }

        document.addEventListener('DOMContentLoaded', function() {
            
            // 1. Process Accordion State Persistence
            document.querySelectorAll('.collapse').forEach(collapseEl => {
                const id = collapseEl.id;
                
                // If it was marked open before the refresh, force it open immediately
                if (sessionStorage.getItem('lynx_collapse_' + id) === 'open') {
                    collapseEl.classList.add('show');
                    const header = document.querySelector(`[data-bs-target="#${id}"]`);
                    if(header) {
                        header.classList.remove('collapsed');
                        header.setAttribute('aria-expanded', 'true');
                        const icon = header.querySelector('.toggle-icon');
                        if(icon) {
                            icon.classList.remove('bi-plus-circle');
                            icon.classList.add('bi-dash-circle');
                        }
                    }
                }

                // Attach listeners to update background memory upon interaction
                collapseEl.addEventListener('shown.bs.collapse', () => {
                    sessionStorage.setItem('lynx_collapse_' + id, 'open');
                });
                collapseEl.addEventListener('hidden.bs.collapse', () => {
                    sessionStorage.setItem('lynx_collapse_' + id, 'closed');
                });
            });

            // 2. Synchronize UI Icons with State
            document.querySelectorAll('.collapse-header').forEach(header => {
                if (header.hasAttribute('onclick')) return; // Ignore headers acting as direct links
                
                const targetId = header.getAttribute('data-bs-target');
                const targetEl = document.querySelector(targetId);
                const icon = header.querySelector('.toggle-icon');
                
                if (targetEl) {
                    targetEl.addEventListener('show.bs.collapse', () => {
                        icon.classList.remove('bi-plus-circle');
                        icon.classList.add('bi-dash-circle');
                    });
                    targetEl.addEventListener('hide.bs.collapse', () => {
                        icon.classList.remove('bi-dash-circle');
                        icon.classList.add('bi-plus-circle');
                    });
                }
            });

            // 3. Re-inject Scroll Position Seamlessly
            const scrollArea = document.getElementById('mainScrollArea');
            const savedScrollPos = sessionStorage.getItem('lum_config_scroll');
            if (savedScrollPos && scrollArea) {
                // A micro-delay permits the browser to render the opened accordions before calculating exact window height
                setTimeout(function() {
                    scrollArea.scrollTo({ top: parseInt(savedScrollPos), left: 0, behavior: 'instant' });
                }, 10);
            }
        });

        // 4. Capture exact scroll depth the moment before the browser executes a reload/navigation request
        window.addEventListener('beforeunload', function() {
            const scrollArea = document.getElementById('mainScrollArea');
            if (scrollArea) {
                sessionStorage.setItem('lum_config_scroll', scrollArea.scrollTop);
            }
        });
    </script>
</body>
</html>