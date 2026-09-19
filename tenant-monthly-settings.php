<?php
// TEMPORARY ERROR REPORTING - REMOVE IN PRODUCTION
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

if (!isset($_SESSION['user_name'])) {
    header("Location: https://lynx-um.co.za/Sec/login.php");
    exit();
}

require_once __DIR__ . '/db-conn-tenants.php';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $p_year = (int)$_POST['period_year'];
    $p_month = (int)$_POST['period_month'];
    $prop = $_POST['property'];
    
    // Insert new rules, or update them if they already exist for that month
    $sql = "INSERT INTO lum_tenant_monthly_settings (tenant_id, period_month, period_year, elec_comm_discount, water_comm_discount, elec_obis_code) 
            VALUES (:tid, :pm, :py, :ed, :wd, :ob) 
            ON DUPLICATE KEY UPDATE 
            elec_comm_discount = VALUES(elec_comm_discount), 
            water_comm_discount = VALUES(water_comm_discount), 
            elec_obis_code = VALUES(elec_obis_code)";
    
    $stmt = $tenant_db_conn->prepare($sql);
    
    if (!empty($_POST['tenant_ids'])) {
        foreach ($_POST['tenant_ids'] as $tid) {
            $stmt->execute([
                'tid' => $tid,
                'pm' => $p_month,
                'py' => $p_year,
                'ed' => $_POST['elec_disc'][$tid] ?? 0,
                'wd' => $_POST['water_disc'][$tid] ?? 0,
                'ob' => $_POST['obis'][$tid] ?? '1.1.1.8.0'
            ]);
        }
    }
    $success_msg = "Configurations securely saved for " . htmlspecialchars($prop) . " (" . str_pad($p_month, 2, '0', STR_PAD_LEFT) . "/" . $p_year . ").";
}

// Fetch Filter Properties
$prop_list_stmt = $tenant_db_conn->query("SELECT DISTINCT tenant_property FROM lum_tenants WHERE tenant_property IS NOT NULL AND tenant_property != '' ORDER BY tenant_property ASC");
$property_list = $prop_list_stmt->fetchAll(PDO::FETCH_COLUMN);

$custom_props = ['DHL', 'DHL Hatfield', 'Greystone Crossing', 'Groenkloof Chambers', 'Lambton Gardens', "Linton's Corner", 'Lynnwood Lane', 'N2 Woodhill', 'Thatchfield Centre', 'Thatchfield Retail', 'The Marketsquare'];
$property_list = array_unique(array_merge($custom_props, $property_list));
sort($property_list);

// Setup Active Filters
$selected_prop = $_GET['property'] ?? ($_POST['property'] ?? '');
$selected_month_raw = $_GET['month_selector'] ?? ($_POST['month_selector'] ?? date('Y-m'));

// If the user has assigned properties, restrict their options
if (!empty($_SESSION['assigned_properties'])) {
    $allowed_props = explode(',', $_SESSION['assigned_properties']);
    $property_list = array_intersect($property_list, $allowed_props);
    
    // If a property is selected that isn't allowed, reset it
    if (!empty($selected_prop) && !in_array($selected_prop, $allowed_props)) {
        $selected_prop = reset($allowed_props);
    }
}

$parts = explode('-', $selected_month_raw);
$period_year = (int)$parts[0];
$period_month = (int)$parts[1];

// Fetch Tenants + Historical Rules
$tenants = [];
if (!empty($selected_prop)) {
    $stmt = $tenant_db_conn->prepare("
        SELECT t.*, s.elec_comm_discount, s.water_comm_discount, s.elec_obis_code
        FROM lum_tenants t
        LEFT JOIN lum_tenant_monthly_settings s 
            ON t.tenant_id = s.tenant_id 
            AND s.period_month = :pm 
            AND s.period_year = :py
        WHERE t.tenant_property = :prop 
        ORDER BY t.tenant_shop ASC
    ");
    $stmt->execute(['pm' => $period_month, 'py' => $period_year, 'prop' => $selected_prop]);
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Monthly Tenant Configurations</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #121212; color: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding-bottom: 50px; }
        .lynx-brand-hover:hover { color: #e3000f !important; }
        .action-bar { background-color: #0a0a0a; padding: 15px 20px; border-bottom: 1px solid #333; margin-bottom: 30px; }
        .filter-box { background-color: #1a1a1a; padding: 20px; border-radius: 8px; border: 1px solid #333; margin-bottom: 20px; }
        .table-dark { --bs-table-bg: #1e1e1e; }
        .form-control, .form-select { background-color: #2b2b2b; color: #fff; border: 1px solid #444; }
        .form-control:focus, .form-select:focus { background-color: #333; color: #fff; border-color: #e3000f; box-shadow: none; }
        .btn-brand { background-color: #e3000f; color: #ffffff; border: none; }
        .btn-brand:hover { background-color: #bf000c; color: #ffffff; }
    </style>
</head>
<body>

<nav class="navbar navbar-dark py-1 border-bottom" style="background-color: #000000; border-color: #333333 !important;">
    <div class="container-fluid px-3">
        <a class="navbar-brand fs-6 mb-0 text-white lynx-brand-hover" href="https://lynx-um.co.za/index.php">Lynx Utility Management</a>
    </div>
</nav>

<div class="action-bar d-flex justify-content-between align-items-center shadow-sm">
    <h4 class="mb-0"><i class="bi bi-clock-history me-2 text-danger"></i>Monthly Tenant Configurations</h4>
    <a href="tenant-overview.php" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-2"></i>Back to Tenants</a>
</div>

<div class="container-fluid px-4">
    <?php if (isset($success_msg)): ?>
        <div class="alert alert-success alert-dismissible fade show border-0" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> <?php echo $success_msg; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Filter Form -->
    <div class="filter-box shadow-sm">
        <form method="GET" action="" class="row align-items-end g-3">
            <div class="col-md-4">
                <label class="form-label text-muted small fw-bold text-uppercase mb-1">Billing Month</label>
                <input type="month" class="form-control" name="month_selector" value="<?php echo htmlspecialchars($selected_month_raw); ?>" required>
            </div>
            <div class="col-md-5">
                <label class="form-label text-muted small fw-bold text-uppercase mb-1">Property</label>
                <select name="property" class="form-select" required>
                    <option value="" disabled <?php echo empty($selected_prop) ? 'selected' : ''; ?>>-- Select Property --</option>
                    <?php foreach($property_list as $prop): ?>
                        <option value="<?php echo htmlspecialchars($prop); ?>" <?php echo $selected_prop === $prop ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($prop); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-light w-100 fw-bold">Load Configuration Grid</button>
            </div>
        </form>
    </div>

    <!-- Configuration Table -->
    <?php if (!empty($selected_prop)): ?>
        <form method="POST" action="">
            <input type="hidden" name="period_month" value="<?php echo $period_month; ?>">
            <input type="hidden" name="period_year" value="<?php echo $period_year; ?>">
            <input type="hidden" name="property" value="<?php echo htmlspecialchars($selected_prop); ?>">
            <input type="hidden" name="month_selector" value="<?php echo htmlspecialchars($selected_month_raw); ?>">

            <div class="card bg-dark border-secondary shadow-sm">
                <div class="card-header border-secondary d-flex justify-content-between align-items-center py-3">
                    <h5 class="mb-0 text-white"><i class="bi bi-building me-2"></i><?php echo htmlspecialchars($selected_prop); ?> — <?php echo date('F Y', strtotime($selected_month_raw . '-01')); ?></h5>
                    <button type="submit" name="save_settings" class="btn btn-brand px-4 shadow"><i class="bi bi-save me-2"></i>Save Configurations</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-dark table-hover align-middle mb-0">
                        <thead class="table-active">
                            <tr>
                                <th>Tenant</th>
                                <th>Shop</th>
                                <th>Billing OBIS Code</th>
                                <th>Elec Comm Area Discount (%)</th>
                                <th>Water Comm Area Discount (%)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tenants as $t): 
                                $tid = $t['tenant_id'];
                                $e_disc = floatval($t['elec_comm_discount'] ?? 0);
                                $w_disc = floatval($t['water_comm_discount'] ?? 0);
                                $obis = $t['elec_obis_code'] ?? '1.1.1.8.0';
                                $elec_tariff = $t['tenant_electrical_tariff_charge'] ?? '';
                                $is_tou = (stripos($elec_tariff, 'TOU') !== false);
                            ?>
                            <tr>
                                <td class="fw-semibold text-white">
                                    <input type="hidden" name="tenant_ids[]" value="<?php echo $tid; ?>">
                                    <?php echo htmlspecialchars($t['tenant_name']); ?>
                                    <div class="small text-muted"><?php echo htmlspecialchars($t['tenant_code']); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($t['tenant_shop']); ?></td>
                                <td>
                                    <?php if ($is_tou): ?>
                                        <input type="hidden" name="obis[<?php echo $tid; ?>]" value="1.1.1.8.0">
                                        <select class="form-select form-select-sm" style="width: auto;" disabled>
                                            <option value="1.1.1.8.0" selected>1.1.1.8.0 (TOU)</option>
                                        </select>
                                    <?php else: ?>
                                        <select name="obis[<?php echo $tid; ?>]" class="form-select form-select-sm" style="width: auto;">
                                            <option value="1.1.1.8.0" <?php echo $obis == '1.1.1.8.0' ? 'selected' : ''; ?>>1.1.1.8.0 (Total)</option>
                                            <option value="1.1.1.8.1" <?php echo $obis == '1.1.1.8.1' ? 'selected' : ''; ?>>1.1.1.8.1 (Tariff 1)</option>
                                        </select>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="input-group input-group-sm w-50">
                                        <input type="number" step="0.01" class="form-control text-center" name="elec_disc[<?php echo $tid; ?>]" value="<?php echo $e_disc; ?>">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="input-group input-group-sm w-50">
                                        <input type="number" step="0.01" class="form-control text-center border-info" name="water_disc[<?php echo $tid; ?>]" value="<?php echo $w_disc; ?>">
                                        <span class="input-group-text border-info text-info">%</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if (empty($tenants)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No tenants found for this property.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </form>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>