<?php
require_once __DIR__ . '/bootstrap.php';
lum_page('tenants', 'view');

lum_connect('tenants', 'obis');
$core_db_conn = lum_db('properties');
if (!$core_db_conn) {
    die("Core Database Connection failed. Please contact the system administrator.");
}

// Audit trail (does nothing when the logger is missing)
lum_use('audit');

// Delete a tenant (requires delete permission)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_tenant_id'])) {
    
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        die("Security Error: Invalid CSRF Token.");
    }
    
    if (lum_can('tenants', 'delete')) {
        $del_id = (int)$_POST['delete_tenant_id'];
        try {
            
            $fetch_stmt = $tenant_db_conn->prepare("SELECT * FROM lum_tenants WHERE tenant_id = :id");
            $fetch_stmt->execute(['id' => $del_id]);
            $t = $fetch_stmt->fetch(PDO::FETCH_ASSOC);

            // Record the row in lum_tenants_legacy before it is deleted
            if ($t) {
                $legacy_sql = "INSERT INTO lum_tenants_legacy (
                    action_type, tenant_id, tenant_property, tenant_name, tenant_code, tenant_shop, tenant_shop_area, 
                    tenant_amps, tenant_comm_area, tenant_electricalMeter_01, tenant_electricalMeter_01_ct_ratio, 
                    tenant_electricalMeter_02, tenant_electricalMeter_02_ct_ratio, tenant_electricalMeter_03, 
                    tenant_electricalMeter_03_ct_ratio, tenant_waterMeter_01, tenant_waterMeter_02, 
                    tenant_waterMeter_03, tenant_waterMeter_04, tenant_electrical_tariff_charge, 
                    tenant_electrical_commArea_charge, tenant_water_tariff_charge, tenant_water_sewer_tariff_charge, 
                    tenant_water_commArea_charge, tenant_generator_tariff_charge, tenant_pays_electrical_commArea, 
                    tenant_pays_water_commArea, tenant_council_refuse_charge, shared_nac_contribute, pays_shared_nac,
                    tenant_occupancy_start_date, tenant_occupancy_end_date, tenant_email, tenant_cell_number, tenant_onsite,
                    tenant_pays_electrical_basic_charge, tenant_pays_water_basic_charge, tenant_pays_sewer_basic_charge,
                    tenant_pays_electrical_tariff_charge, tenant_pays_generator_tariff_charge, tenant_pays_demand_tariff_charge
                ) VALUES (
                    'DELETE', :tenant_id, :tenant_property, :tenant_name, :tenant_code, :tenant_shop, :tenant_shop_area, 
                    :tenant_amps, :tenant_comm_area, :tenant_electricalMeter_01, :tenant_electricalMeter_01_ct_ratio, 
                    :tenant_electricalMeter_02, :tenant_electricalMeter_02_ct_ratio, :tenant_electricalMeter_03, 
                    :tenant_electricalMeter_03_ct_ratio, :tenant_waterMeter_01, :tenant_waterMeter_02, 
                    :tenant_waterMeter_03, :tenant_waterMeter_04, :tenant_electrical_tariff_charge, 
                    :tenant_electrical_commArea_charge, :tenant_water_tariff_charge, :tenant_water_sewer_tariff_charge, 
                    :tenant_water_commArea_charge, :tenant_generator_tariff_charge, :tenant_pays_electrical_commArea, 
                    :tenant_pays_water_commArea, :tenant_council_refuse_charge, :shared_nac_contribute, :pays_shared_nac,
                    :tenant_occupancy_start_date, :tenant_occupancy_end_date, :tenant_email, :tenant_cell_number, :tenant_onsite,
                    :tenant_pays_electrical_basic_charge, :tenant_pays_water_basic_charge, :tenant_pays_sewer_basic_charge,
                    :tenant_pays_electrical_tariff_charge, :tenant_pays_generator_tariff_charge, :tenant_pays_demand_tariff_charge
                )";
                
                $legacy_stmt = $tenant_db_conn->prepare($legacy_sql);
                $legacy_stmt->execute([
                    ':tenant_id' => $t['tenant_id'],
                    ':tenant_property' => $t['tenant_property'],
                    ':tenant_name' => $t['tenant_name'],
                    ':tenant_code' => $t['tenant_code'],
                    ':tenant_shop' => $t['tenant_shop'],
                    ':tenant_shop_area' => $t['tenant_shop_area'],
                    ':tenant_amps' => $t['tenant_amps'],
                    ':tenant_comm_area' => $t['tenant_comm_area'],
                    ':tenant_electricalMeter_01' => $t['tenant_electricalMeter_01'],
                    ':tenant_electricalMeter_01_ct_ratio' => $t['tenant_electricalMeter_01_ct_ratio'],
                    ':tenant_electricalMeter_02' => $t['tenant_electricalMeter_02'],
                    ':tenant_electricalMeter_02_ct_ratio' => $t['tenant_electricalMeter_02_ct_ratio'],
                    ':tenant_electricalMeter_03' => $t['tenant_electricalMeter_03'],
                    ':tenant_electricalMeter_03_ct_ratio' => $t['tenant_electricalMeter_03_ct_ratio'],
                    ':tenant_waterMeter_01' => $t['tenant_waterMeter_01'],
                    ':tenant_waterMeter_02' => $t['tenant_waterMeter_02'],
                    ':tenant_waterMeter_03' => $t['tenant_waterMeter_03'],
                    ':tenant_waterMeter_04' => $t['tenant_waterMeter_04'],
                    ':tenant_electrical_tariff_charge' => $t['tenant_electrical_tariff_charge'],
                    ':tenant_electrical_commArea_charge' => $t['tenant_electrical_commArea_charge'],
                    ':tenant_water_tariff_charge' => $t['tenant_water_tariff_charge'],
                    ':tenant_water_sewer_tariff_charge' => $t['tenant_water_sewer_tariff_charge'],
                    ':tenant_water_commArea_charge' => $t['tenant_water_commArea_charge'],
                    ':tenant_generator_tariff_charge' => $t['tenant_generator_tariff_charge'],
                    ':tenant_pays_electrical_commArea' => $t['tenant_pays_electrical_commArea'],
                    ':tenant_pays_water_commArea' => $t['tenant_pays_water_commArea'],
                    ':tenant_council_refuse_charge' => $t['tenant_council_refuse_charge'],
                    ':shared_nac_contribute' => $t['shared_nac_contribute'],
                    ':pays_shared_nac' => $t['pays_shared_nac'],
                    ':tenant_occupancy_start_date' => $t['tenant_occupancy_start_date'],
                    ':tenant_occupancy_end_date' => $t['tenant_occupancy_end_date'],
                    ':tenant_email' => $t['tenant_email'],
                    ':tenant_cell_number' => $t['tenant_cell_number'],
                    ':tenant_onsite' => $t['tenant_onsite'],
                    ':tenant_pays_electrical_basic_charge' => $t['tenant_pays_electrical_basic_charge'],
                    ':tenant_pays_water_basic_charge' => $t['tenant_pays_water_basic_charge'],
                    ':tenant_pays_sewer_basic_charge' => $t['tenant_pays_sewer_basic_charge'],
                    ':tenant_pays_electrical_tariff_charge' => $t['tenant_pays_electrical_tariff_charge'],
                    ':tenant_pays_generator_tariff_charge' => $t['tenant_pays_generator_tariff_charge'],
                    ':tenant_pays_demand_tariff_charge' => $t['tenant_pays_demand_tariff_charge']
                ]);
                if (function_exists('lum_audit_stamp_tenant_legacy')) {
                    lum_audit_stamp_tenant_legacy($tenant_db_conn, $tenant_db_conn->lastInsertId());
                }
            }

            $del_stmt = $tenant_db_conn->prepare("DELETE FROM lum_tenants WHERE tenant_id = :id");
            $del_stmt->execute(['id' => $del_id]);

            if ($t && $del_stmt->rowCount() > 0 && function_exists('lum_audit_log')) {
                lum_audit_log('DELETE', 'tenant', $t['tenant_id'], lum_audit_tenant_label($t), $t['tenant_property'], $t, null);
            }
            $_SESSION['success_message'] = "Tenant successfully deleted and logged to archive.";
        } catch (PDOException $e) {
            error_log('LUM tenant delete failed: ' . $e->getMessage());
            $_SESSION['error_message'] = "Error deleting tenant. Please try again or contact the system administrator.";
        }
    } else {
        $_SESSION['error_message'] = "Unauthorized: You do not have permission to delete tenants.";
    }
    header("Location: tenant-overview.php");
    exit();
}

// Filter fields and the session keys that remember them
$lum_filter_fields = [
    'search'   => 'tenant_overview_search',
    'property' => 'tenant_overview_property',
    'meters'   => 'tenant_overview_meters',   // Electrical / Water
];

// Clear everything (search text and all dropdowns)
if (isset($_GET['clear']) && $_GET['clear'] == '1') {
    foreach ($lum_filter_fields as $lum_key) {
        unset($_SESSION[$lum_key]);
    }
    header("Location: tenant-overview.php");
    exit();
}

// Clear one field only; the other filters stay as they are
if (isset($_GET['clear_field']) && isset($lum_filter_fields[$_GET['clear_field']])) {
    unset($_SESSION[$lum_filter_fields[$_GET['clear_field']]]);
    header("Location: tenant-overview.php");
    exit();
}

if (isset($_GET['search']) || isset($_GET['property']) || isset($_GET['meters'])) {
    foreach ($lum_filter_fields as $lum_field => $lum_key) {
        $_SESSION[$lum_key] = trim((string)($_GET[$lum_field] ?? ''));
    }
}

// Filters are kept in the session, so Back returns to the same view
$searchTerm = $_SESSION['tenant_overview_search'] ?? '';
$searchProperty = $_SESSION['tenant_overview_property'] ?? '';
$searchMeters = $_SESSION['tenant_overview_meters'] ?? '';
if (!in_array($searchMeters, ['', 'Electrical', 'Water'], true)) {
    $searchMeters = '';
}

// Meter columns shown in the table (the Electrical / Water filter shows only its own column)
$show_elec_col = ($searchMeters !== 'Water');
$show_water_col = ($searchMeters !== 'Electrical');
$table_cols = 7 + ($show_elec_col ? 1 : 0) + ($show_water_col ? 1 : 0);

$isAdmin = lum_can('tenants', 'delete'); // Shows the delete buttons (Admins, or roles/users granted delete)

// The table is shown only once a filter is applied
$showTable = (!empty($searchTerm) || !empty($searchProperty) || !empty($searchMeters));

$db_props_stmt = $core_db_conn->query("SELECT Property FROM lum_properties ORDER BY Property ASC");
$property_list = $db_props_stmt->fetchAll(PDO::FETCH_COLUMN);

$property_list = array_unique($property_list);
sort($property_list);
$property_register = $property_list; // Every property in the register (tenants of other names are flagged below)

// Restricted users only see their own properties
if (!empty($_SESSION['assigned_properties'])) {
    $allowed_props = explode(',', $_SESSION['assigned_properties']);
    $property_list = array_intersect($property_list, $allowed_props);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tenant Overview - Lynx Utility Management</title>
    
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>

    <style>
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
            border-radius: 0 !important; 
            font-weight: normal !important;
            text-transform: none !important;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important;
        }
        ::-webkit-scrollbar-track { background: #0a0a0a; border-radius: 0; }
        ::-webkit-scrollbar-thumb { background: #4a4a4a; border-radius: 0; }
        body { background-color: #121212; color: #ffffff; overflow: hidden; }
        .navbar { background-color: #000000; border-bottom: 1px solid #333333 !important; z-index: 1050; }
        .action-bar { background-color: #0a0a0a; border-bottom: 1px solid #333333; padding: 10px 0; }
        .btn-brand { background-color: #e3000f; color: #ffffff; border: none; transition: 0.3s; }
        .btn-brand:hover { background-color: #bf000c; color: #ffffff; transform: translateY(-1px); }
        .search-input { background-color: #1e1e1e !important; border: 1px solid #444 !important; color: #fff !important; }
        .table-container { background-color: #000000; border: 1px solid #333333; overflow: hidden; }
        .table-responsive { max-height: calc(100vh - 125px); overflow-y: auto; overflow-x: auto; }
        .table { margin-bottom: 0; color: #e0e0e0; font-size: 0.85rem;}
        .table thead th { 
            background-color: #1a1a1a; 
            color: #aaaaaa; 
            border-bottom: 2px solid #333333; 
            letter-spacing: 0.5px; 
            padding: 10px 14px; 
            position: sticky; 
            top: 0; 
            z-index: 10; 
            box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.4);
        }
        .table tbody td { border-bottom: 1px solid #222222; padding: 10px 14px; vertical-align: middle; background-color: transparent; color: #cccccc; }
        .table tbody tr:hover td { background-color: #111111; }
        .badge-type { 
            display: inline-block;
            width: 130px; 
            text-align: center;
            letter-spacing: 0.5px; 
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            vertical-align: middle;
            background-color: #2a2a2a;
            border: 1px solid #444;
            color: #fff;
        }
    </style>
</head>
<body>

    <?php include LUM_ROOT . '/Layout/top-navbar.php'; ?>

    <div class="action-bar mb-2">
        <div class="container-fluid px-2">
            <div class="row align-items-center">
                <div class="col-12 col-xl-5 mb-2 mb-xl-0 d-flex align-items-center">
                    <a href="https://lynx-um.co.za/index.php" class="btn btn-brand btn-sm px-2 shadow-sm me-3">
                        Back
                    </a>
                    <h5 class="mb-0 text-white me-3 fs-6">Tenant Overview</h5>
                    <div class="d-flex gap-2 border-start border-secondary ps-3">
                        <a href="Tenant Registration/tenant-registration-form.php" class="btn btn-brand btn-sm px-2 shadow-sm">
                            <i class="bi bi-plus-lg me-1"></i>Register New
                        </a>

                    </div>
                </div>
                
                <div class="col-12 col-xl-7 d-flex justify-content-xl-end">
                    <?php
                    $lum_any_filter = ($searchTerm !== '' || $searchProperty !== '' || $searchMeters !== '');
                    // Small "x" that clears one field only
                    $lum_clear_one = function ($field, $title) {
                        return "<a href='tenant-overview.php?clear_field=" . $field . "' class='btn search-btn d-flex align-items-center px-2' title='" . htmlspecialchars($title, ENT_QUOTES) . "'><i class='bi bi-x text-danger'></i></a>";
                    };
                    ?>
                    <form action="tenant-overview.php" method="GET" class="d-flex flex-wrap align-items-center w-100 gap-2 justify-content-xl-end mb-0">

                        <div class="input-group input-group-sm" style="width: 230px;">
                            <select name="property" class="form-select form-select-sm search-input" onchange="this.form.submit()">
                                <option value="">All Properties...</option>
                                <?php foreach($property_list as $prop): ?>
                                    <option value="<?php echo htmlspecialchars($prop); ?>" <?php echo $searchProperty === $prop ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($prop); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($searchProperty !== '') echo $lum_clear_one('property', 'Clear property'); ?>
                        </div>

                        <div class="input-group input-group-sm" style="width: 190px;">
                            <select name="meters" class="form-select form-select-sm search-input" onchange="this.form.submit()">
                                <option value="">Electrical &amp; Water...</option>
                                <option value="Electrical" <?php echo $searchMeters === 'Electrical' ? 'selected' : ''; ?>>Electrical Meters</option>
                                <option value="Water" <?php echo $searchMeters === 'Water' ? 'selected' : ''; ?>>Water Meters</option>
                            </select>
                            <?php if ($searchMeters !== '') echo $lum_clear_one('meters', 'Clear Electrical / Water'); ?>
                        </div>

                        <div class="input-group input-group-sm" style="width: 280px;">
                            <input type="text" name="search" class="form-control search-input" placeholder="Name, code, shop or meter..." value="<?php echo htmlspecialchars($searchTerm); ?>">
                            <?php if ($searchTerm !== '') echo $lum_clear_one('search', 'Clear search text only'); ?>

                            <button class="btn search-btn" type="submit" id="button-addon2" title="Search">
                                <i class="bi bi-search"></i>
                            </button>

                            <?php if ($lum_any_filter): ?>
                                <a href="tenant-overview.php?clear=1" class="btn search-btn d-flex align-items-center" title="Clear the search text and all filters">
                                    <i class="bi bi-x-lg text-danger me-1"></i><span class="small">All</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid px-2">
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show mb-2" role="alert">
                <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-2" role="alert">
                <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
    </div>

    <div class="container-fluid px-2 pb-2">
        <?php if ($showTable): ?>
            <div class="table-container shadow">
                <div class="table-responsive" id="scrollableTableContainer">
                    <table class="table table-hover table-borderless align-middle">
                        <thead>
                            <tr>
                                <th scope="col">Property</th>
                                <th scope="col">Tenant Name</th>
                                <th scope="col">Tenant Code</th>
                                <th scope="col">Shop No</th>
                                <th scope="col">Shop Area (m&sup2;)</th>
                                <?php if ($show_elec_col): ?><th scope="col">Elec Meters</th><?php endif; ?>
                                <?php if ($show_water_col): ?><th scope="col">Water Meters</th><?php endif; ?>
                                <th scope="col">Occupancy Term</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            try {
                                $query = "SELECT * FROM lum_tenants WHERE 1=1";
                                $params = [];

                                // Restricted users: only their own properties
                                if (!empty($_SESSION['assigned_properties'])) {
                                    $allowed_props = explode(',', $_SESSION['assigned_properties']);
                                    
                                    // A property they may not access falls back to their first property
                                    if (!empty($searchProperty) && !in_array($searchProperty, $allowed_props)) {
                                        $searchProperty = reset($allowed_props);
                                    }
                                    
                                    // No property selected: all of their properties
                                    if (empty($searchProperty)) {
                                        $inClause = [];
                                        foreach ($allowed_props as $i => $ap) {
                                            $pName = "allowed_prop_$i";
                                            $inClause[] = ":$pName";
                                            $params[$pName] = $ap;
                                        }
                                        $query .= " AND tenant_property IN (" . implode(',', $inClause) . ")";
                                    }
                                }

                                if (!empty($searchProperty)) {
                                    $query .= " AND tenant_property = :prop";
                                    $params['prop'] = $searchProperty;
                                }

                                $elec_cols = ['tenant_electricalMeter_01', 'tenant_electricalMeter_02', 'tenant_electricalMeter_03'];
                                $water_cols = ['tenant_waterMeter_01', 'tenant_waterMeter_02', 'tenant_waterMeter_03', 'tenant_waterMeter_04'];
                                $has_meter_sql = function ($cols) {
                                    return '(' . implode(' OR ', array_map(function ($c) { return "NULLIF(TRIM(`$c`), '') IS NOT NULL"; }, $cols)) . ')';
                                };
                                if ($searchMeters === 'Electrical') {
                                    $query .= " AND " . $has_meter_sql($elec_cols);   // Tenants with at least one electrical meter
                                } elseif ($searchMeters === 'Water') {
                                    $query .= " AND " . $has_meter_sql($water_cols);  // Tenants with at least one water meter
                                }

                                if (!empty($searchTerm)) {
                                    // Meter serials searched: only the selected kind of meter (or both)
                                    $search_meter_cols = ($searchMeters === 'Electrical') ? $elec_cols : (($searchMeters === 'Water') ? $water_cols : array_merge($elec_cols, $water_cols));
                                    $query .= " AND (
                                        tenant_name LIKE :search 
                                        OR tenant_code LIKE :search 
                                        OR tenant_shop LIKE :search
                                        OR " . implode(" LIKE :search OR ", $search_meter_cols) . " LIKE :search
                                    )";
                                    $params['search'] = "%$searchTerm%";
                                }

                                $query .= " ORDER BY tenant_id DESC";
                                $stmt = $tenant_db_conn->prepare($query);
                                $stmt->execute($params);

                                if ($stmt->rowCount() > 0) {
                                    $searchQueryParam = "";
                                    if (!empty($searchTerm) || !empty($searchProperty)) {
                                        $searchQueryParam .= "&return_search=" . urlencode($searchTerm);
                                    }

                                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                        echo "<tr>";
                                        $row_prop_known = in_array((string)($row['tenant_property'] ?? ''), $property_register, true);
                                        echo "<td><span class='text-white'>" . htmlspecialchars($row['tenant_property'] ?? '') . "</span>"
                                           . ($row_prop_known ? '' : "<div><span class='badge bg-warning text-dark' title='This property name is not in the property register (Configurations)'>Not in property register</span></div>")
                                           . "</td>";
                                        echo "<td><span class='text-white'>" . htmlspecialchars($row['tenant_name'] ?? '') . "</span></td>";
                                        echo "<td><span class='badge badge-type px-2 py-1'>" . htmlspecialchars($row['tenant_code'] ?? '') . "</span></td>";
                                        echo "<td>" . htmlspecialchars($row['tenant_shop'] ?? '') . "</td>";
                                        echo "<td>" . htmlspecialchars($row['tenant_shop_area'] ?? '0.00') . "</td>";
                                        
                                        $elec_meters_arr = array_filter(array_map('trim', [$row['tenant_electricalMeter_01'] ?? '', $row['tenant_electricalMeter_02'] ?? '', $row['tenant_electricalMeter_03'] ?? '']));
                                        $water_meters_arr = array_filter(array_map('trim', [$row['tenant_waterMeter_01'] ?? '', $row['tenant_waterMeter_02'] ?? '', $row['tenant_waterMeter_03'] ?? '', $row['tenant_waterMeter_04'] ?? '']));
                                        
                                        $elec_display = !empty($elec_meters_arr) ? implode('<br>', array_map('htmlspecialchars', $elec_meters_arr)) : "<span class='text-muted small'>None</span>";
                                        $water_display = !empty($water_meters_arr) ? implode('<br>', array_map('htmlspecialchars', $water_meters_arr)) : "<span class='text-muted small'>None</span>";
                                        
                                        if ($show_elec_col) echo "<td class='small'>" . $elec_display . "</td>";
                                        if ($show_water_col) echo "<td class='small'>" . $water_display . "</td>";
                                        
                                        $occ_start = $row['tenant_occupancy_start_date'] ?? null;
                                        $occ_end = $row['tenant_occupancy_end_date'] ?? null;
                                        $occ_display = "<span class='text-muted small'>Not Set</span>";
                                        
                                        if (!empty($occ_start) || !empty($occ_end)) {
                                            $s_str = !empty($occ_start) ? date('Y-m-d', strtotime($occ_start)) : 'N/A';
                                            $e_str = !empty($occ_end) ? date('Y-m-d', strtotime($occ_end)) : 'N/A';
                                            
                                            $end_class = "text-white";
                                            if (!empty($occ_end) && strtotime($occ_end) < time()) {
                                                $end_class = "text-danger fw-bold";
                                            }
                                            $occ_display = "<span class='text-white small d-block' style='line-height: 1.2;'>Start: $s_str<br>End: <span class='$end_class'>$e_str</span></span>";
                                        }
                                        
                                        echo "<td>" . $occ_display . "</td>";
                                        
                                        $rowId = htmlspecialchars($row['tenant_id'] ?? $row['id'] ?? ''); 
                                        
                                        echo "<td class='text-end text-nowrap'>
                                                <a href='Tenant Consumption Slips/view-consumption-slip.php?tenant_id=" . $rowId . $searchQueryParam . "' class='btn btn-sm btn-outline-success me-1' title='Consumption Slip'><i class='bi bi-receipt'></i></a>
                                                <a href='Tenant Update/tenant-update-form.php?tenant_id=" . $rowId . "' class='btn btn-sm btn-outline-secondary me-1' title='Edit Tenant'><i class='bi bi-pencil'></i></a>
                                                <a href='view-tenant.php?tenant_id=" . $rowId . $searchQueryParam . "' class='btn btn-sm btn-outline-info me-1' title='View Tenant Analysis'><i class='bi bi-eye'></i></a>";
                                                
                                        if ($isAdmin) {
                                            echo "<form method='POST' class='d-inline' onsubmit=\"return confirm('Are you sure you want to permanently delete this tenant? This action cannot be undone.');\">
                                                    <input type='hidden' name='csrf_token' value='" . $csrf_token . "'>
                                                    <input type='hidden' name='delete_tenant_id' value='" . $rowId . "'>
                                                    <button type='submit' class='btn btn-sm btn-outline-danger' title='Delete Tenant'><i class='bi bi-trash3-fill'></i></button>
                                                  </form>";
                                        }
                                        
                                        echo "</td>";
                                        echo "</tr>";
                                    }
                                } else {
                                    echo "<tr><td colspan='" . $table_cols . "' class='text-center py-4 text-muted'>No tenants found matching your criteria.</td></tr>";
                                }
                            } catch (PDOException $e) {
                                error_log('LUM tenant-overview query failed: ' . $e->getMessage());
                                echo "<tr><td colspan='" . $table_cols . "' class='text-center py-4 text-danger'>Unable to load tenants. Please contact the system administrator.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="text-center py-5 text-muted mt-5 border border-secondary" style="background-color: #1a1a1a; border-style: dashed !important;">
                <i class="bi bi-building fs-1 mb-3"></i>
                <h4 class="fw-normal">Select a property or use the search bar</h4>
                <p>The tenant table will generate once a filter is applied above.</p>
            </div>
        <?php endif; ?>
    </div>

    <?php include LUM_ROOT . '/Layout/foot-assets.php'; ?>

    <script>
        // Keep the scroll position across reloads and Back
        
        if ('scrollRestoration' in history) {
            history.scrollRestoration = 'manual';
        }

        document.addEventListener('DOMContentLoaded', function() {
            const tableContainer = document.getElementById('scrollableTableContainer');
            
            if (tableContainer) {
                const savedScrollPos = sessionStorage.getItem('lum_tenant_table_scroll');
                if (savedScrollPos) {
                    // Wait for the table to render
                    setTimeout(() => {
                        tableContainer.scrollTop = parseInt(savedScrollPos, 10);
                    }, 15);
                }
            }
        });

        window.addEventListener('beforeunload', function() {
            const tableContainer = document.getElementById('scrollableTableContainer');
            if (tableContainer) {
                sessionStorage.setItem('lum_tenant_table_scroll', tableContainer.scrollTop);
            }
        });
    </script>
</body>
</html>