<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once '/var/www/Lynx/bootstrap.php';
lum_page('configs', 'view');

$can_delete_configs = lum_can('configs', 'delete'); // Delete buttons

// Meter brands & types (bootstrap.php)
$info_db_conn = lum_db('information', true);

// Audit trail (switches itself off if the logger is missing)
lum_use('audit');

// --- METER MANAGEMENT PROCESSING ---
$mm_message = "";
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['mm_action'])) {
    // Adding needs edit rights, deleting needs delete rights
    lum_require_access('configs', (strpos((string)$_POST['mm_action'], 'delete_') === 0) ? 'delete' : 'edit');
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        die("<div class='alert alert-danger m-4 text-center'>Security Error: CSRF Token Validation Failed. Request Blocked.</div>");
    }
    
    // --- ELECTRICAL TYPES ---
    if ($_POST['mm_action'] === 'delete_elec_type' && isset($_POST['delete_type_id'])) {
        $del_id = intval($_POST['delete_type_id']);
        try {
            $audit_before = lum_audit_fetch_row($info_db_conn, 'lum_meter_type_electrical', 'id', $del_id);
            $stmt = $info_db_conn->prepare("DELETE FROM lum_meter_type_electrical WHERE id = ?");
            $stmt->execute([$del_id]);
            if ($audit_before && $stmt->rowCount() > 0) {
                lum_audit_log('DELETE', 'meter_type_electrical', $del_id, $audit_before['type_name'] ?? null, null, $audit_before, null);
            }
            header("Location: meter-configs.php?msg=type_deleted");
            exit();
        } catch (PDOException $e) {
            error_log('LUM meter-configs: Error deleting type: ' . $e->getMessage()); $mm_message = "<div class='alert alert-danger'>Error deleting type. Please try again or contact the system administrator.</div>";
        }
    }

    if ($_POST['mm_action'] === 'add_elec_type') {
        $type_name = trim($_POST['type_name']);
        if (!empty($type_name)) {
            try {
                $stmt = $info_db_conn->prepare("INSERT INTO lum_meter_type_electrical (type_name) VALUES (?)");
                $stmt->execute([$type_name]);
                $new_id = $info_db_conn->lastInsertId();
                lum_audit_log('INSERT', 'meter_type_electrical', $new_id, $type_name, null, null, lum_audit_fetch_row($info_db_conn, 'lum_meter_type_electrical', 'id', $new_id));
                header("Location: meter-configs.php?msg=type_added");
                exit();
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) { 
                    $mm_message = "<div class='alert alert-danger border-0 shadow-sm'><i class='bi bi-exclamation-triangle-fill me-2'></i>This electrical type already exists in the system.</div>";
                } else {
                    error_log('LUM meter-configs: Database Error: ' . $e->getMessage()); $mm_message = "<div class='alert alert-danger'>Database Error. Please try again or contact the system administrator.</div>";
                }
            }
        }
    }

    // --- WATER TYPES ---
    if ($_POST['mm_action'] === 'delete_water_type' && isset($_POST['delete_type_id'])) {
        $del_id = intval($_POST['delete_type_id']);
        try {
            $audit_before = lum_audit_fetch_row($info_db_conn, 'lum_meter_type_water', 'id', $del_id);
            $stmt = $info_db_conn->prepare("DELETE FROM lum_meter_type_water WHERE id = ?");
            $stmt->execute([$del_id]);
            if ($audit_before && $stmt->rowCount() > 0) {
                lum_audit_log('DELETE', 'meter_type_water', $del_id, $audit_before['type_name'] ?? null, null, $audit_before, null);
            }
            header("Location: meter-configs.php?msg=water_type_deleted");
            exit();
        } catch (PDOException $e) {
            error_log('LUM meter-configs: Error deleting type: ' . $e->getMessage()); $mm_message = "<div class='alert alert-danger'>Error deleting type. Please try again or contact the system administrator.</div>";
        }
    }

    if ($_POST['mm_action'] === 'add_water_type') {
        $type_name = trim($_POST['type_name']);
        if (!empty($type_name)) {
            try {
                $stmt = $info_db_conn->prepare("INSERT INTO lum_meter_type_water (type_name) VALUES (?)");
                $stmt->execute([$type_name]);
                $new_id = $info_db_conn->lastInsertId();
                lum_audit_log('INSERT', 'meter_type_water', $new_id, $type_name, null, null, lum_audit_fetch_row($info_db_conn, 'lum_meter_type_water', 'id', $new_id));
                header("Location: meter-configs.php?msg=water_type_added");
                exit();
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) { 
                    $mm_message = "<div class='alert alert-danger border-0 shadow-sm'><i class='bi bi-exclamation-triangle-fill me-2'></i>This water type already exists in the system.</div>";
                } else {
                    error_log('LUM meter-configs: Database Error: ' . $e->getMessage()); $mm_message = "<div class='alert alert-danger'>Database Error. Please try again or contact the system administrator.</div>";
                }
            }
        }
    }

    // --- ELECTRICAL BRANDS ---
    if ($_POST['mm_action'] === 'delete_elec_brand' && isset($_POST['delete_brand_id'])) {
        $del_id = intval($_POST['delete_brand_id']);
        try {
            $audit_before = lum_audit_fetch_row($info_db_conn, 'lum_meters_electrical_brands', 'id', $del_id);
            $stmt = $info_db_conn->prepare("DELETE FROM lum_meters_electrical_brands WHERE id = ?");
            $stmt->execute([$del_id]);
            if ($audit_before && $stmt->rowCount() > 0) {
                lum_audit_log('DELETE', 'meter_brand_electrical', $del_id, $audit_before['brand_name'] ?? null, null, $audit_before, null);
            }
            header("Location: meter-configs.php?msg=brand_deleted");
            exit();
        } catch (PDOException $e) {
            error_log('LUM meter-configs: Error deleting brand: ' . $e->getMessage()); $mm_message = "<div class='alert alert-danger'>Error deleting brand. Please try again or contact the system administrator.</div>";
        }
    }

    if ($_POST['mm_action'] === 'add_elec_brand') {
        $brand_name = trim($_POST['brand_name']);
        if (!empty($brand_name)) {
            try {
                $stmt = $info_db_conn->prepare("INSERT INTO lum_meters_electrical_brands (brand_name) VALUES (?)");
                $stmt->execute([$brand_name]);
                $new_id = $info_db_conn->lastInsertId();
                lum_audit_log('INSERT', 'meter_brand_electrical', $new_id, $brand_name, null, null, lum_audit_fetch_row($info_db_conn, 'lum_meters_electrical_brands', 'id', $new_id));
                header("Location: meter-configs.php?msg=brand_added");
                exit();
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) { 
                    $mm_message = "<div class='alert alert-danger border-0 shadow-sm'><i class='bi bi-exclamation-triangle-fill me-2'></i>This electrical brand already exists in the system.</div>";
                } else {
                    error_log('LUM meter-configs: Database Error: ' . $e->getMessage()); $mm_message = "<div class='alert alert-danger'>Database Error. Please try again or contact the system administrator.</div>";
                }
            }
        }
    }

    // --- WATER BRANDS ---
    if ($_POST['mm_action'] === 'delete_water_brand' && isset($_POST['delete_brand_id'])) {
        $del_id = intval($_POST['delete_brand_id']);
        try {
            $audit_before = lum_audit_fetch_row($info_db_conn, 'lum_meters_water_brands', 'id', $del_id);
            $stmt = $info_db_conn->prepare("DELETE FROM lum_meters_water_brands WHERE id = ?");
            $stmt->execute([$del_id]);
            if ($audit_before && $stmt->rowCount() > 0) {
                lum_audit_log('DELETE', 'meter_brand_water', $del_id, $audit_before['brand_name'] ?? null, null, $audit_before, null);
            }
            header("Location: meter-configs.php?msg=brand_deleted");
            exit();
        } catch (PDOException $e) {
            error_log('LUM meter-configs: Error deleting brand: ' . $e->getMessage()); $mm_message = "<div class='alert alert-danger'>Error deleting brand. Please try again or contact the system administrator.</div>";
        }
    }

    if ($_POST['mm_action'] === 'add_water_brand') {
        $brand_name = trim($_POST['brand_name']);
        if (!empty($brand_name)) {
            try {
                $stmt = $info_db_conn->prepare("INSERT INTO lum_meters_water_brands (brand_name) VALUES (?)");
                $stmt->execute([$brand_name]);
                $new_id = $info_db_conn->lastInsertId();
                lum_audit_log('INSERT', 'meter_brand_water', $new_id, $brand_name, null, null, lum_audit_fetch_row($info_db_conn, 'lum_meters_water_brands', 'id', $new_id));
                header("Location: meter-configs.php?msg=brand_added");
                exit();
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) { 
                    $mm_message = "<div class='alert alert-danger border-0 shadow-sm'><i class='bi bi-exclamation-triangle-fill me-2'></i>This water brand already exists in the system.</div>";
                } else {
                    error_log('LUM meter-configs: Database Error: ' . $e->getMessage()); $mm_message = "<div class='alert alert-danger'>Database Error. Please try again or contact the system administrator.</div>";
                }
            }
        }
    }
}

// Map redirect messages
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'type_added') $mm_message = "<div class='alert alert-success border-0 shadow-sm'><i class='bi bi-check-circle-fill me-2'></i>Meter type added successfully.</div>";
    if ($_GET['msg'] === 'type_deleted') $mm_message = "<div class='alert alert-success border-0 shadow-sm'><i class='bi bi-check-circle-fill me-2'></i>Meter type deleted successfully.</div>";
    if ($_GET['msg'] === 'water_type_added') $mm_message = "<div class='alert alert-success border-0 shadow-sm'><i class='bi bi-check-circle-fill me-2'></i>Water meter type added successfully.</div>";
    if ($_GET['msg'] === 'water_type_deleted') $mm_message = "<div class='alert alert-success border-0 shadow-sm'><i class='bi bi-check-circle-fill me-2'></i>Water meter type deleted successfully.</div>";
    if ($_GET['msg'] === 'brand_added') $mm_message = "<div class='alert alert-success border-0 shadow-sm'><i class='bi bi-check-circle-fill me-2'></i>Meter brand added successfully.</div>";
    if ($_GET['msg'] === 'brand_deleted') $mm_message = "<div class='alert alert-success border-0 shadow-sm'><i class='bi bi-check-circle-fill me-2'></i>Meter brand deleted successfully.</div>";
}

$elec_types = [];
$water_types = [];
$elec_brands = [];
$water_brands = [];

try {
    // Fetch Types
    $stmt_elec_type = $info_db_conn->query("SELECT * FROM `lum_meter_type_electrical` ORDER BY `type_name` ASC");
    if ($stmt_elec_type) {
        $elec_types = $stmt_elec_type->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $stmt_water_type = $info_db_conn->query("SELECT * FROM `lum_meter_type_water` ORDER BY `type_name` ASC");
    if ($stmt_water_type) {
        $water_types = $stmt_water_type->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Fetch Brands
    $stmt_elec = $info_db_conn->query("SELECT * FROM `lum_meters_electrical_brands` ORDER BY `brand_name` ASC");
    if ($stmt_elec) {
        $elec_brands = $stmt_elec->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $stmt_water = $info_db_conn->query("SELECT * FROM `lum_meters_water_brands` ORDER BY `brand_name` ASC");
    if ($stmt_water) {
        $water_brands = $stmt_water->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log('LUM meter-configs: Error fetching data: ' . $e->getMessage()); $mm_message = "<div class='alert alert-danger'>Error fetching data. Please try again or contact the system administrator.</div>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meter Configurations - Lynx Utility Management</title>
    
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>

    <style>
        body { background-color: #121212; color: #ffffff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .action-bar { background-color: #0a0a0a; border-bottom: 1px solid #333333; padding: 15px 0; }
        .btn-brand-elec { background-color: #e3000f; color: #ffffff; border: none; transition: 0.3s; font-weight: 500; }
        .btn-brand-elec:hover { background-color: #bf000c; color: #ffffff; transform: translateY(-1px); }
        .btn-brand-water { background-color: #0dcaf0; color: #000000; border: none; transition: 0.3s; font-weight: 500; }
        .btn-brand-water:hover { background-color: #0bacce; color: #000000; transform: translateY(-1px); }
        .table-container { background-color: #000000; border: 1px solid #333333; border-radius: 8px; overflow: hidden; }
        .table { margin-bottom: 0; color: #e0e0e0; }
        .table thead th { background-color: #1a1a1a; color: #aaaaaa; border-bottom: 2px solid #333333; font-weight: 600; text-transform: uppercase; font-size: 0.85rem; letter-spacing: 0.5px; padding: 12px 16px; }
        .table tbody td { border-bottom: 1px solid #222222; padding: 12px 16px; vertical-align: middle; background-color: transparent; color: #cccccc; }
        .table tbody tr:hover td { background-color: #111111; }
        .admin-form-container { background-color: #ffffff; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); color: #212529; }
        .form-control:focus { border-color: #0dcaf0 !important; box-shadow: 0 0 0 0.25rem rgba(13, 202, 240, 0.25) !important; }
        /* Accordion Styles */
        .collapse-header {
            background-color: #1a1a1a;
            border: 1px solid #333333;
            border-radius: 8px;
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
    </style>
</head>
<body>

    <nav class="navbar navbar-dark py-1 border-bottom" style="background-color: #000000; border-color: #333333 !important;">
        <div class="container-fluid px-3">
            <a class="navbar-brand fs-6 mb-0 text-white lynx-brand-hover transition-colors" href="view-configs.php" style="transition: color 0.2s ease-in-out;">
                <i class="bi bi-arrow-left me-2"></i>Back to System Configurations
            </a>
            <div class="d-flex align-items-center">
                <div class="text-secondary small d-flex align-items-center">
                    <i class="bi bi-person-fill text-white me-2"></i>
                    <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?>
                </div>
                <a href="https://lynx-um.co.za/Sec/logout.php" class="text-decoration-none small ms-3 ps-3 border-start lynx-logout transition-colors" style="border-color: #333333 !important;">
                    Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="action-bar mb-4">
        <div class="container-fluid px-4">
            <div class="row align-items-center">
                <div class="col-12">
                    <h4 class="mb-0">Meter Management Configurations</h4>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid px-4 pb-5">
        <?php if($mm_message) echo "<div class='mb-4'>{$mm_message}</div>"; ?>

        <!-- ELECTRICAL TYPES ACCORDION -->
        <div class="collapse-header d-flex justify-content-between align-items-center mb-3 mt-4 collapsed" data-bs-toggle="collapse" data-bs-target="#elecTypeCollapse" aria-expanded="false">
            <h5 class="text-white fw-bold mb-0 fs-6">Electrical Meter Types</h5>
            <i class="bi bi-plus-circle fs-5 toggle-icon"></i>
        </div>
        
        <div class="collapse" id="elecTypeCollapse">
            <div class="row g-4 mb-5 mt-1">
                <div class="col-xl-8 col-lg-7">
                    <div class="table-container shadow h-100">
                        <div class="p-3 border-bottom border-secondary bg-dark d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-white fw-bold mb-0"><i class="bi bi-lightning-charge text-danger me-2"></i>Electrical Meter Types</h6>
                                <small class="text-muted">Manage the list of electrical meter types available during meter registration.</small>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover table-borderless align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 15%;"># ID</th>
                                        <th style="width: 60%;">Type Name</th>
                                        <th class="text-end" style="width: 25%;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($elec_types)): ?>
                                        <tr><td colspan="3" class="text-center text-muted py-4">No electrical types found.</td></tr>
                                    <?php else: foreach($elec_types as $t): ?>
                                        <tr>
                                            <td class="text-muted fw-bold">#<?php echo htmlspecialchars($t['id']); ?></td>
                                            <td class="text-white fw-semibold"><?php echo htmlspecialchars($t['type_name']); ?></td>
                                            <td class="text-end">
                                                <?php if ($can_delete_configs): ?>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to permanently delete this electrical type?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                    <input type="hidden" name="mm_action" value="delete_elec_type">
                                                    <input type="hidden" name="delete_type_id" value="<?php echo $t['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3-fill"></i></button>
                                                </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-xl-4 col-lg-5">
                    <div class="admin-form-container p-4 w-100 h-100 m-0" style="border-top: 6px solid #e3000f;">
                        <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-3">
                            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-lightning-charge text-danger me-2"></i>Add Electrical Type</h5>
                        </div>

                        <form method="POST" action="meter-configs.php">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="mm_action" value="add_elec_type">
                            
                            <div class="mb-4">
                                <label class="form-label fw-semibold text-dark">Type Name</label>
                                <input type="text" class="form-control bg-light border-danger" name="type_name" required placeholder="e.g. Smart Meter">
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-brand-elec btn-lg shadow-sm">Save Type</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- WATER TYPES ACCORDION -->
        <div class="collapse-header d-flex justify-content-between align-items-center mb-3 mt-4 collapsed" data-bs-toggle="collapse" data-bs-target="#waterTypeCollapse" aria-expanded="false">
            <h5 class="text-white fw-bold mb-0 fs-6">Water Meter Types</h5>
            <i class="bi bi-plus-circle fs-5 toggle-icon"></i>
        </div>
        
        <div class="collapse" id="waterTypeCollapse">
            <div class="row g-4 mb-5 mt-1">
                <div class="col-xl-8 col-lg-7">
                    <div class="table-container shadow h-100">
                        <div class="p-3 border-bottom border-secondary bg-dark d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-white fw-bold mb-0"><i class="bi bi-droplet text-info me-2"></i>Water Meter Types</h6>
                                <small class="text-muted">Manage the list of water meter types available during meter registration.</small>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover table-borderless align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 15%;"># ID</th>
                                        <th style="width: 60%;">Type Name</th>
                                        <th class="text-end" style="width: 25%;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($water_types)): ?>
                                        <tr><td colspan="3" class="text-center text-muted py-4">No water types found.</td></tr>
                                    <?php else: foreach($water_types as $t): ?>
                                        <tr>
                                            <td class="text-muted fw-bold">#<?php echo htmlspecialchars($t['id']); ?></td>
                                            <td class="text-white fw-semibold"><?php echo htmlspecialchars($t['type_name']); ?></td>
                                            <td class="text-end">
                                                <?php if ($can_delete_configs): ?>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to permanently delete this water type?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                    <input type="hidden" name="mm_action" value="delete_water_type">
                                                    <input type="hidden" name="delete_type_id" value="<?php echo $t['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3-fill"></i></button>
                                                </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-xl-4 col-lg-5">
                    <div class="admin-form-container p-4 w-100 h-100 m-0" style="border-top: 6px solid #0dcaf0;">
                        <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-3">
                            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-droplet text-info me-2"></i>Add Water Type</h5>
                        </div>

                        <form method="POST" action="meter-configs.php">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="mm_action" value="add_water_type">
                            
                            <div class="mb-4">
                                <label class="form-label fw-semibold text-dark">Type Name</label>
                                <input type="text" class="form-control bg-light border-info" name="type_name" required placeholder="e.g. Irrigation">
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-brand-water btn-lg shadow-sm">Save Type</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- ELECTRICAL BRANDS ACCORDION -->
        <div class="collapse-header d-flex justify-content-between align-items-center mb-3 mt-4 collapsed" data-bs-toggle="collapse" data-bs-target="#elecBrandCollapse" aria-expanded="false">
            <h5 class="text-white fw-bold mb-0 fs-6">Electrical Meter Brands</h5>
            <i class="bi bi-plus-circle fs-5 toggle-icon"></i>
        </div>
        
        <div class="collapse" id="elecBrandCollapse">
            <div class="row g-4 mb-5 mt-1">
                <div class="col-xl-8 col-lg-7">
                    <div class="table-container shadow h-100">
                        <div class="p-3 border-bottom border-secondary bg-dark d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-white fw-bold mb-0"><i class="bi bi-lightning-charge text-danger me-2"></i>Electrical Meter Brands</h6>
                                <small class="text-muted">Manage the list of electrical meter brands available during meter registration.</small>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover table-borderless align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 15%;"># ID</th>
                                        <th style="width: 60%;">Brand Name</th>
                                        <th class="text-end" style="width: 25%;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($elec_brands)): ?>
                                        <tr><td colspan="3" class="text-center text-muted py-4">No electrical brands found.</td></tr>
                                    <?php else: foreach($elec_brands as $b): ?>
                                        <tr>
                                            <td class="text-muted fw-bold">#<?php echo htmlspecialchars($b['id']); ?></td>
                                            <td class="text-white fw-semibold"><?php echo htmlspecialchars($b['brand_name']); ?></td>
                                            <td class="text-end">
                                                <?php if ($can_delete_configs): ?>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to permanently delete this electrical brand?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                    <input type="hidden" name="mm_action" value="delete_elec_brand">
                                                    <input type="hidden" name="delete_brand_id" value="<?php echo $b['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3-fill"></i></button>
                                                </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-xl-4 col-lg-5">
                    <div class="admin-form-container p-4 w-100 h-100 m-0" style="border-top: 6px solid #e3000f;">
                        <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-3">
                            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-lightning-charge text-danger me-2"></i>Add Electrical Brand</h5>
                        </div>

                        <form method="POST" action="meter-configs.php">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="mm_action" value="add_elec_brand">
                            
                            <div class="mb-4">
                                <label class="form-label fw-semibold text-dark">Brand Name</label>
                                <input type="text" class="form-control bg-light border-danger" name="brand_name" required placeholder="e.g. Landis+Gyr">
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-brand-elec btn-lg shadow-sm">Save Brand</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- WATER BRANDS ACCORDION -->
        <div class="collapse-header d-flex justify-content-between align-items-center mb-3 mt-4 collapsed" data-bs-toggle="collapse" data-bs-target="#waterBrandCollapse" aria-expanded="false">
            <h5 class="text-white fw-bold mb-0 fs-6">Water Meter Brands</h5>
            <i class="bi bi-plus-circle fs-5 toggle-icon"></i>
        </div>
        
        <div class="collapse" id="waterBrandCollapse">
            <div class="row g-4 mb-5 mt-1">
                <div class="col-xl-8 col-lg-7">
                    <div class="table-container shadow h-100">
                        <div class="p-3 border-bottom border-secondary bg-dark d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-white fw-bold mb-0"><i class="bi bi-droplet text-info me-2"></i>Water Meter Brands</h6>
                                <small class="text-muted">Manage the list of water meter brands available during meter registration.</small>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover table-borderless align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 15%;"># ID</th>
                                        <th style="width: 60%;">Brand Name</th>
                                        <th class="text-end" style="width: 25%;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($water_brands)): ?>
                                        <tr><td colspan="3" class="text-center text-muted py-4">No water brands found.</td></tr>
                                    <?php else: foreach($water_brands as $b): ?>
                                        <tr>
                                            <td class="text-muted fw-bold">#<?php echo htmlspecialchars($b['id']); ?></td>
                                            <td class="text-white fw-semibold"><?php echo htmlspecialchars($b['brand_name']); ?></td>
                                            <td class="text-end">
                                                <?php if ($can_delete_configs): ?>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to permanently delete this water brand?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                    <input type="hidden" name="mm_action" value="delete_water_brand">
                                                    <input type="hidden" name="delete_brand_id" value="<?php echo $b['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3-fill"></i></button>
                                                </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-xl-4 col-lg-5">
                    <div class="admin-form-container p-4 w-100 h-100 m-0" style="border-top: 6px solid #0dcaf0;">
                        <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-3">
                            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-droplet text-info me-2"></i>Add Water Brand</h5>
                        </div>

                        <form method="POST" action="meter-configs.php">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="mm_action" value="add_water_brand">
                            
                            <div class="mb-4">
                                <label class="form-label fw-semibold text-dark">Brand Name</label>
                                <input type="text" class="form-control bg-light border-info" name="brand_name" required placeholder="e.g. Sensus">
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-brand-water btn-lg shadow-sm">Save Brand</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

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
            
            // Process Accordion State Persistence
            document.querySelectorAll('.collapse').forEach(collapseEl => {
                const id = collapseEl.id;
                
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

                collapseEl.addEventListener('shown.bs.collapse', () => {
                    sessionStorage.setItem('lynx_collapse_' + id, 'open');
                });
                collapseEl.addEventListener('hidden.bs.collapse', () => {
                    sessionStorage.setItem('lynx_collapse_' + id, 'closed');
                });
            });

            // Synchronize UI Icons with State
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

            // Re-inject Scroll Position Seamlessly
            const savedScrollPos = sessionStorage.getItem('lum_config_scroll');
            if (savedScrollPos) {
                setTimeout(function() {
                    window.scrollTo({ top: parseInt(savedScrollPos), left: 0, behavior: 'instant' });
                }, 10);
            }
        });

        // Capture exact scroll depth the moment before the browser executes a reload/navigation request
        window.addEventListener('beforeunload', function() {
            sessionStorage.setItem('lum_config_scroll', window.scrollY);
        });
    </script>
</body>
</html>