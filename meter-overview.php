<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once '/var/www/Lynx/bootstrap.php';
lum_page('meters', 'view');

// Meters and tenants ($meter_db_conn, $meter_crud, $tenant_db_conn, $tenant_crud)
lum_connect('meters', 'tenants');

// Core System Database Connections
try {
    $core_db_conn = lum_db('properties'); // bootstrap.php
    if (!$core_db_conn) throw new PDOException('sys_db_properties is not available');
    
    // Info database for meter types
    $info_db_conn = lum_db('information'); // bootstrap.php
    if (!$info_db_conn) throw new PDOException('sys_db_information is not available');
    
} catch(PDOException $e) {
    error_log('LUM meter-overview DB connection failed: ' . $e->getMessage());
    die("Database connection failed. Please contact the system administrator.");
}

// Handle Deletion (Admin Only) with CSRF Protection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_meter_id'])) {
    
    // --- VALIDATE CSRF TOKEN ---
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        die("Security Error: Invalid CSRF Token.");
    }
    
    if (lum_can('meters', 'delete')) {
        $del_id = (int)$_POST['delete_meter_id'];
        try {
            // Deletes the meter and records it in the audit trail
            $meter_crud->delete_meter($del_id);
            $_SESSION['success_message'] = "Meter successfully deleted.";
        } catch (PDOException $e) {
            error_log('LUM meter delete failed: ' . $e->getMessage());
            $_SESSION['error_message'] = "Error deleting meter. Please try again or contact the system administrator.";
        }
    } else {
        $_SESSION['error_message'] = "Unauthorized: You do not have permission to delete meters.";
    }
    header("Location: meter-overview.php");
    exit();
}

// ---------------------------------------------------------
// Handle Search & Filter State Persistence
// ---------------------------------------------------------
// The filter fields and where each one is remembered
$lum_filter_fields = [
    'search'   => 'meter_overview_search',
    'property' => 'meter_overview_property',
    'category' => 'meter_overview_category',   // Electrical / Water
    'type'     => 'meter_overview_type',
];

// Clear everything (search text and all dropdowns)
if (isset($_GET['clear']) && $_GET['clear'] == '1') {
    foreach ($lum_filter_fields as $lum_key) {
        unset($_SESSION[$lum_key]);
    }
    header("Location: meter-overview.php");
    exit();
}

// Clear one field only; the other filters stay as they are
if (isset($_GET['clear_field']) && isset($lum_filter_fields[$_GET['clear_field']])) {
    unset($_SESSION[$lum_filter_fields[$_GET['clear_field']]]);
    header("Location: meter-overview.php");
    exit();
}

// Update session state if a new search or filter is submitted
if (isset($_GET['search']) || isset($_GET['property']) || isset($_GET['category']) || isset($_GET['type'])) {
    foreach ($lum_filter_fields as $lum_field => $lum_key) {
        $_SESSION[$lum_key] = trim((string)($_GET[$lum_field] ?? ''));
    }
}

$searchTerm = $_SESSION['meter_overview_search'] ?? '';
$searchProperty = $_SESSION['meter_overview_property'] ?? '';
$searchCategory = $_SESSION['meter_overview_category'] ?? '';
$searchType = $_SESSION['meter_overview_type'] ?? '';

// Older saved filters used "Electrical" / "Water" as a meter type: they are now the Electrical / Water filter
if ($searchType === 'Electrical' || $searchType === 'Water') {
    $searchCategory = $searchType;
    $searchType = '';
}
if (!in_array($searchCategory, ['', 'Electrical', 'Water'], true)) {
    $searchCategory = '';
}
// A meter type must belong to the selected Electrical / Water filter
if ($searchCategory !== '' && $searchType !== '' && stripos($searchType, $searchCategory . ' - ') !== 0) {
    $searchType = '';
}
$_SESSION['meter_overview_category'] = $searchCategory;
$_SESSION['meter_overview_type'] = $searchType;
$isAdmin = lum_can('meters', 'delete'); // Shows the delete buttons (Admins, or roles/users granted delete)

$showTable = (!empty($searchTerm) || !empty($searchProperty) || !empty($searchCategory) || !empty($searchType));

// ---------------------------------------------------------
// Build a Tenant Map for Cross-Referencing
// ---------------------------------------------------------
$tenant_map = [];
try {
    $t_stmt = $tenant_db_conn->query("SELECT tenant_name, tenant_code, tenant_shop, tenant_property, tenant_electricalMeter_01, tenant_electricalMeter_02, tenant_electricalMeter_03, tenant_waterMeter_01, tenant_waterMeter_02, tenant_waterMeter_03 FROM lum_tenants");
    
    while ($t = $t_stmt->fetch(PDO::FETCH_ASSOC)) {
        $meters = array_filter([
            $t['tenant_electricalMeter_01'], $t['tenant_electricalMeter_02'], $t['tenant_electricalMeter_03'],
            $t['tenant_waterMeter_01'], $t['tenant_waterMeter_02'], $t['tenant_waterMeter_03']
        ]);
        
        foreach ($meters as $m) {
            $m = trim($m);
            if (!empty($m)) {
                $tenant_map[$m] = [
                    'name' => $t['tenant_name'],
                    'code' => $t['tenant_code'],
                    'shop' => $t['tenant_shop'],
                    'property' => $t['tenant_property']
                ];
            }
        }
    }
} catch (Exception $e) {}

$matching_serials = [];
if (!empty($searchTerm)) {
    foreach ($tenant_map as $serial => $data) {
        if (stripos($data['name'], $searchTerm) !== false || 
            stripos($data['code'], $searchTerm) !== false || 
            stripos($data['property'], $searchTerm) !== false ||
            stripos($data['shop'], $searchTerm) !== false) {
            $matching_serials[] = $serial;
        }
    }
}

// ---------------------------------------------------------
// UNREGISTERED METERS
// Serials that have readings in the OBIS tables (all years) but are not in lum_meters.
// Electrical meters are found in 1.1.1.8.0, water meters in 8.1.1.0.0.
// Returns "kind|serial" => ['serial', 'kind', 'last_reading'].
// ---------------------------------------------------------
function lumFindUnregisteredMeters($obis_pdo, $meter_pdo, $term, $kinds, $limit = 25) {
    $sources = ['electrical' => '11180', 'water' => '81100'];
    $found = [];
    foreach ($kinds as $kind) {
        if (!isset($sources[$kind])) continue;
        $code = $sources[$kind];
        try {
            $dbs = $obis_pdo->query("SHOW DATABASES LIKE 'db\\_obis\\_{$code}\\_%'")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            continue;
        }
        rsort($dbs); // Newest year first, so the first hit carries the latest reading
        foreach ($dbs as $db) {
            if (!preg_match('/^db_obis_' . $code . '_(\d{4})$/', $db, $m)) continue;
            $tb = 'tb_obis_' . $code . '_' . $m[1];
            try {
                // Prefix search on the (meter_serial, Time_stamp) key: fast even on large tables
                $st = $obis_pdo->prepare("SELECT meter_serial, MAX(Time_stamp) AS last_ts FROM `$db`.`$tb` WHERE meter_serial LIKE ? GROUP BY meter_serial LIMIT " . (int)$limit);
                $st->execute([$term . '%']);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $serial = trim((string)$r['meter_serial']);
                    $key = $kind . '|' . $serial;
                    if ($serial !== '' && !isset($found[$key])) {
                        $found[$key] = ['serial' => $serial, 'kind' => $kind, 'last_reading' => $r['last_ts']];
                    }
                }
            } catch (Exception $e) {}
            if (count($found) >= $limit) break;
        }
    }
    if (empty($found)) return [];

    // Leave out every serial that is registered (whatever the current filters are)
    $serials = array_values(array_unique(array_column($found, 'serial')));
    try {
        $in = implode(',', array_fill(0, count($serials), '?'));
        $st = $meter_pdo->prepare("SELECT TRIM(meter_serial) FROM lum_meters WHERE TRIM(meter_serial) IN ($in)");
        $st->execute($serials);
        $registered = array_flip(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)));
    } catch (Exception $e) {
        return []; // Without the registration check nothing is shown as unregistered
    }
    foreach ($found as $key => $u) {
        if (isset($registered[$u['serial']])) unset($found[$key]);
    }
    return $found;
}

$unregistered_meters = [];
$lum_serial_term = preg_replace('/[^a-zA-Z0-9]/', '', (string)$searchTerm);
$lum_unreg_kinds = [];
if ($searchType === '') {
    // A specific meter type is unknown for unregistered meters, so they only show without one
    if ($searchCategory === 'Electrical') {
        $lum_unreg_kinds = ['electrical'];
    } elseif ($searchCategory === 'Water') {
        $lum_unreg_kinds = ['water'];
    } else {
        $lum_unreg_kinds = ['electrical', 'water'];
    }
}
// Only for searches that look like a serial number (letters/digits only, at least 3 characters)
if (strlen($lum_serial_term) >= 3 && $lum_serial_term === trim((string)$searchTerm) && !empty($lum_unreg_kinds)) {
    lum_connect('obis');
    foreach (lumFindUnregisteredMeters($obis_db_conn, $meter_db_conn, $lum_serial_term, $lum_unreg_kinds) as $key => $u) {
        $link = $tenant_map[$u['serial']] ?? null;
        $prop = (string)($link['property'] ?? '');
        // Property filter: an unregistered meter only has a property through its tenant
        if ($searchProperty !== '' && $prop !== $searchProperty) continue;
        // Restricted users: hide meters linked to tenants of other properties
        if ($prop !== '' && !lum_can_access_property($prop)) continue;
        $u['tenant'] = $link;
        $unregistered_meters[$key] = $u;
    }
}

// --- DYNAMIC PROPERTY & TYPE LIST GENERATION ---
// Properties come from the property register only (Configurations)
$db_props_stmt = $core_db_conn->query("SELECT Property FROM lum_properties ORDER BY Property ASC");
$property_list = $db_props_stmt->fetchAll(PDO::FETCH_COLUMN);
sort($property_list);

if (!empty($_SESSION['assigned_properties'])) {
    $allowed_props = explode(',', $_SESSION['assigned_properties']);
    $property_list = array_intersect($property_list, $allowed_props);
}

// Fetch configured Types from the Information Database
$elec_config_types = [];
$water_config_types = [];
try {
    $e_stmt = $info_db_conn->query("SELECT type_name FROM lum_meter_type_electrical ORDER BY type_name ASC");
    $elec_config_types = $e_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $w_stmt = $info_db_conn->query("SELECT type_name FROM lum_meter_type_water ORDER BY type_name ASC");
    $water_config_types = $w_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meter Overview - Lynx Utility Management</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">

    <style>
        /* Strict global override to match financial reporting overview typography */
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
            border-radius: 0 !important; 
            font-weight: normal !important;
            text-transform: none !important;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important;
        }

        /* Specific exemption to slightly round buttons as requested */
        .btn { border-radius: 4px !important; }

        body { background-color: #121212; color: #ffffff; overflow: hidden; /* Prevent body double scroll */ }
        .lynx-brand-hover:hover { color: #e3000f !important; }
        .lynx-logout { color: #e3000f; }
        .lynx-logout:hover { color: #bf000c; }
        .navbar { background-color: #000000; border-bottom: 1px solid #333333 !important; z-index: 1050; }
        
        /* Adjusted Action Bar for Compactness */
        .action-bar { background-color: #0a0a0a; border-bottom: 1px solid #333333; padding: 10px 0; }
        .btn-brand { background-color: #e3000f; color: #ffffff; border: none; transition: 0.3s; }
        .btn-brand:hover { background-color: #bf000c; color: #ffffff; }
        .search-input { background-color: #1e1e1e !important; border: 1px solid #444 !important; color: #fff !important; }
        .search-input:focus { border-color: #e3000f !important; box-shadow: 0 0 0 0.25rem rgba(227, 0, 15, 0.25) !important; }
        .search-btn { background-color: #1e1e1e; border: 1px solid #444; color: #b0b0b0; }
        .search-btn:hover { background-color: #333; color: #fff; }
        /* Filter bar: the three filters and the search box on one line on large screens */
        .meter-filter-form { flex-wrap: wrap; }
        .meter-filter-form .input-group { flex-wrap: nowrap; }
        .meter-filter-form .mf-property { flex: 0 1 200px; min-width: 140px; }
        .meter-filter-form .mf-category { flex: 0 1 165px; min-width: 125px; }
        .meter-filter-form .mf-type     { flex: 0 1 210px; min-width: 140px; }
        .meter-filter-form .mf-search   { flex: 0 1 260px; min-width: 200px; }
        .meter-filter-form .mf-search .form-control { min-width: 70px; }
        @media (min-width: 1200px) {
            .meter-filter-form { flex-wrap: nowrap; }
        }
        
        .table-container { background-color: #000000; border: 1px solid #333333; overflow: hidden; }
        
        /* INDEPENDENT TABLE SCROLLING - Expanded Max Height */
        .table-responsive { max-height: calc(100vh - 125px); overflow-y: auto; overflow-x: auto; }
        
        /* CUSTOM SCROLLBAR FOR TABLE */
        ::-webkit-scrollbar { width: 14px; height: 14px; }
        ::-webkit-scrollbar-track { background: #0a0a0a; }
        ::-webkit-scrollbar-thumb { background: #4a4a4a; }
        ::-webkit-scrollbar-thumb:hover { background: #e3000f; }

        .table { margin-bottom: 0; color: #e0e0e0; }
        
        /* STICKY HEADERS */
        .table thead th { 
            background-color: #1a1a1a; 
            color: #aaaaaa; 
            border-bottom: 2px solid #333333; 
            font-size: 0.85rem; 
            letter-spacing: 0.5px; 
            padding: 10px 14px; 
            position: sticky; 
            top: 0; 
            z-index: 10; 
            box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.4);
        }
        
        .table tbody td { border-bottom: 1px solid #222222; padding: 10px 14px; vertical-align: middle; background-color: transparent; color: #cccccc; }
        .table tbody tr:hover td { background-color: #111111; }
        
        /* Fixed-width badge rendering */
        .badge-type { 
            display: inline-block;
            width: 160px; /* Forces uniform size across all badges */
            text-align: center;
            letter-spacing: 0.5px; 
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            vertical-align: middle;
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
                    <h5 class="mb-0 text-white me-3 fs-6">Meter Overview</h5>
                    <div class="d-flex gap-2 border-start border-secondary ps-3">
                        <a href="https://lynx-um.co.za/Meter Management/Meter Registration/meter-register-form.php" class="btn btn-brand btn-sm px-2 shadow-sm">
                            <i class="bi bi-plus-lg me-1"></i>Register New
                        </a>
                        <a href="meter-health.php" class="btn btn-brand btn-sm px-2 shadow-sm">
                            Meter Status
                        </a>
                        <a href="usage-comparison.php" class="btn btn-brand btn-sm px-2 shadow-sm">
                            Usage Comparison
                        </a>
                    </div>
                </div>
                
                <div class="col-12 col-xl-7 d-flex justify-content-xl-end">
                    <?php
                    $lum_any_filter = ($searchTerm !== '' || $searchProperty !== '' || $searchCategory !== '' || $searchType !== '');
                    // Small "x" that clears one field only
                    $lum_clear_one = function ($field, $title) {
                        return "<a href='meter-overview.php?clear_field=" . $field . "' class='btn search-btn d-flex align-items-center px-2' title='" . htmlspecialchars($title, ENT_QUOTES) . "'><i class='bi bi-x text-danger'></i></a>";
                    };
                    ?>
                    <form action="meter-overview.php" method="GET" class="d-flex meter-filter-form w-100 gap-2 justify-content-xl-end mb-0" id="meterFilterForm">

                        <!-- Property -->
                        <div class="input-group input-group-sm mf-property">
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

                        <!-- Electrical / Water -->
                        <div class="input-group input-group-sm mf-category">
                            <select name="category" class="form-select form-select-sm search-input" onchange="this.form.submit()">
                                <option value="">Electrical &amp; Water...</option>
                                <option value="Electrical" <?php echo $searchCategory === 'Electrical' ? 'selected' : ''; ?>>Electrical</option>
                                <option value="Water" <?php echo $searchCategory === 'Water' ? 'selected' : ''; ?>>Water</option>
                            </select>
                            <?php if ($searchCategory !== '') echo $lum_clear_one('category', 'Clear Electrical / Water'); ?>
                        </div>

                        <!-- Meter type: only the types of the selected Electrical / Water filter -->
                        <div class="input-group input-group-sm mf-type">
                            <select name="type" class="form-select form-select-sm search-input" onchange="this.form.submit()">
                                <option value=""><?php echo $searchCategory === 'Electrical' ? 'All Electrical Types...' : ($searchCategory === 'Water' ? 'All Water Types...' : 'All Types...'); ?></option>

                                <?php if ($searchCategory !== 'Water'): ?>
                                <optgroup label="Electrical">
                                    <?php foreach($elec_config_types as $m_type):
                                        $val = "Electrical - " . $m_type; ?>
                                        <option value="<?php echo htmlspecialchars($val); ?>" <?php echo $searchType === $val ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($val); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endif; ?>

                                <?php if ($searchCategory !== 'Electrical'): ?>
                                <optgroup label="Water">
                                    <?php foreach($water_config_types as $m_type):
                                        $val = "Water - " . $m_type; ?>
                                        <option value="<?php echo htmlspecialchars($val); ?>" <?php echo $searchType === $val ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($val); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endif; ?>
                            </select>
                            <?php if ($searchType !== '') echo $lum_clear_one('type', 'Clear meter type'); ?>
                        </div>

                        <!-- Serial / tenant search (same line as the filters) -->
                        <div class="input-group input-group-sm mf-search">
                            <input type="text" name="search" class="form-control search-input" placeholder="Serial, tenant, loc..." value="<?php echo htmlspecialchars($searchTerm); ?>">
                            <?php if ($searchTerm !== '') echo $lum_clear_one('search', 'Clear search text only'); ?>

                            <button class="btn search-btn" type="submit" id="button-addon2" title="Search">
                                <i class="bi bi-search"></i>
                            </button>

                            <?php if ($lum_any_filter): ?>
                                <a href="meter-overview.php?clear=1" class="btn search-btn d-flex align-items-center" title="Clear the search text and all filters">
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
                                <th scope="col">Meter Serial</th>
                                <th scope="col" style="min-width: 175px;">Type</th>
                                <th scope="col">Location Specs</th>
                                <th scope="col">Tenant Assignment</th>
                                <th scope="col">Shop</th>
                                <th scope="col" class="text-center">Status</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            try {
                                $query = "SELECT * FROM lum_meters WHERE 1=1";
                                $params = [];

                                if (!empty($_SESSION['assigned_properties'])) {
                                    $allowed_props = explode(',', $_SESSION['assigned_properties']);
                                    
                                    if (!empty($searchProperty) && !in_array($searchProperty, $allowed_props)) {
                                        $searchProperty = reset($allowed_props);
                                    }
                                    
                                    if (empty($searchProperty)) {
                                        $inClause = [];
                                        foreach ($allowed_props as $i => $ap) {
                                            $pName = "allowed_prop_$i";
                                            $inClause[] = ":$pName";
                                            $params[$pName] = $ap;
                                        }
                                        $query .= " AND meter_property IN (" . implode(',', $inClause) . ")";
                                    }
                                }

                                if (!empty($searchProperty)) {
                                    $query .= " AND meter_property = :prop";
                                    $params['prop'] = $searchProperty;
                                }

                                // --- ELECTRICAL / WATER FILTER ---
                                if ($searchCategory === 'Electrical') {
                                    $query .= " AND meter_type LIKE 'Electrical%'";
                                } elseif ($searchCategory === 'Water') {
                                    $query .= " AND meter_type LIKE 'Water%'";
                                }

                                // --- SMART FILTERING ENGINE ---
                                if (!empty($searchType)) {
                                    if ($searchType === 'Electrical') {
                                        // Master Catch-all for Electrical
                                        $query .= " AND meter_type LIKE 'Electrical%'";
                                    } elseif ($searchType === 'Water') {
                                        // Master Catch-all for Water
                                        $query .= " AND meter_type LIKE 'Water%'";
                                    } else {
                                        // Standard exact match
                                        $query .= " AND meter_type = :mtype";
                                        $params['mtype'] = $searchType;
                                    }
                                }

                                if (!empty($searchTerm)) {
                                    $searchSql = "(meter_serial LIKE :search OR meter_tenant LIKE :search OR meter_tenant_02 LIKE :search OR meter_tenant_03 LIKE :search OR meter_location LIKE :search OR meter_MDB LIKE :search OR meter_DB LIKE :search";
                                    $params['search'] = "%$searchTerm%";
                                    
                                    if (!empty($matching_serials)) {
                                        $inClause = [];
                                        foreach ($matching_serials as $index => $serialVal) {
                                            $pName = "s_match_$index";
                                            $inClause[] = ":$pName";
                                            $params[$pName] = $serialVal;
                                        }
                                        $searchSql .= " OR meter_serial IN (" . implode(',', $inClause) . ")";
                                    }
                                    $searchSql .= ")";
                                    $query .= " AND " . $searchSql;
                                }

                                $query .= " ORDER BY meter_id DESC";
                                $stmt = $meter_db_conn->prepare($query);
                                $stmt->execute($params);

                                $lum_registered_count = $stmt->rowCount();
                                if ($lum_registered_count > 0) {
                                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                        $serial = trim($row['meter_serial'] ?? '');
                                        $is_linked = isset($tenant_map[$serial]);

                                        // The type as saved (old type names were renamed by meter-type-names-cleanup.sql)
                                        $display_type = ($row['meter_type'] ?? '') !== '' ? $row['meter_type'] : 'Unknown';

                                        // Badge colour: red = electrical, blue = water, grey = unknown type (solar meters keep their yellow badge)
                                        $row_category = lum_meter_category($row['meter_type'] ?? '');
                                        $type_badge = 'bg-secondary';
                                        if ($row_category === 'Electrical') {
                                            $type_badge = (stripos($display_type, 'Solar') !== false) ? 'bg-warning text-dark' : 'bg-danger text-white';
                                        } elseif ($row_category === 'Water') {
                                            $type_badge = 'bg-info text-dark';
                                        }

                                        echo "<tr>";
                                        echo "<td><span class='text-white fs-6'>" . htmlspecialchars($serial) . "</span></td>";
                                        
                                        echo "<td>
                                                <span class='badge {$type_badge} badge-type px-2 py-1 mb-1'>" . htmlspecialchars($display_type) . "</span>
                                                " . (!empty($row['meter_rol']) ? "<div class='small text-light mt-1'>ROL: " . htmlspecialchars($row['meter_rol']) . "</div>" : "") . "
                                              </td>";

                                        $display_prop = $is_linked ? $tenant_map[$serial]['property'] : ($row['meter_property'] ?? '');
                                        echo "<td>
                                                <div class='text-white mb-1'>" . htmlspecialchars($display_prop) . "</div>
                                                <div class='small text-light'>";
                                        if (!empty($row['meter_location'])) echo "<i class='bi bi-geo-alt me-1'></i>" . htmlspecialchars($row['meter_location']) . "<br>";
                                        
                                        $boards = [];
                                        if (!empty($row['meter_MDB'])) $boards[] = "MDB: " . htmlspecialchars($row['meter_MDB']);
                                        if (!empty($row['meter_DB'])) $boards[] = "DB: " . htmlspecialchars($row['meter_DB']);
                                        if (!empty($boards)) echo implode(" | ", $boards);
                                        
                                        echo "  </div>
                                              </td>";

                                        echo "<td>";
                                        $tenants_arr = []; 
                                        if ($is_linked) {
                                            $display_tenant = $tenant_map[$serial]['name'];
                                            $display_code = $tenant_map[$serial]['code'];
                                            echo "<div class='text-white mb-1'>" . htmlspecialchars($display_tenant) . "</div>";
                                            if (!empty($display_code)) {
                                                echo "<div class='small text-light'>Code: " . htmlspecialchars($display_code) . "</div>";
                                            }
                                        } else {
                                            if (!empty($row['meter_tenant']) || !empty($row['meter_shop'])) $tenants_arr[] = ['t' => $row['meter_tenant'], 's' => $row['meter_shop'] ?? ''];
                                            if (!empty($row['meter_tenant_02']) || !empty($row['meter_shop_02'])) $tenants_arr[] = ['t' => $row['meter_tenant_02'], 's' => $row['meter_shop_02'] ?? ''];
                                            if (!empty($row['meter_tenant_03']) || !empty($row['meter_shop_03'])) $tenants_arr[] = ['t' => $row['meter_tenant_03'], 's' => $row['meter_shop_03'] ?? ''];
                                            
                                            if (!empty($tenants_arr)) {
                                                foreach ($tenants_arr as $t_obj) {
                                                    $t_name = !empty($t_obj['t']) ? $t_obj['t'] : 'Unknown Tenant';
                                                    echo "<div class='text-white mb-1'>" . htmlspecialchars($t_name) . "</div>";
                                                }
                                            } else {
                                                echo "<span class='text-light'>Unassigned</span>";
                                            }
                                        }
                                        echo "</td>";

                                        echo "<td>";
                                        if ($is_linked) {
                                            $display_shop = $tenant_map[$serial]['shop'] ?? '';
                                            if (!empty($display_shop)) {
                                                echo "<div class='text-info'>" . htmlspecialchars($display_shop) . "</div>";
                                            } else {
                                                echo "<div class='text-muted'>-</div>";
                                            }
                                        } else {
                                            if (!empty($tenants_arr)) {
                                                foreach ($tenants_arr as $t_obj) {
                                                    if (!empty($t_obj['s'])) {
                                                        echo "<div class='text-info mb-1'>" . htmlspecialchars($t_obj['s']) . "</div>";
                                                    } else {
                                                        echo "<div class='text-muted mb-1'>-</div>";
                                                    }
                                                }
                                            } else {
                                                echo "<div class='text-muted'>-</div>";
                                            }
                                        }
                                        echo "</td>";

                                        echo "<td class='text-center'>";
                                        if ($is_linked) {
                                            echo "<span class='badge bg-success bg-opacity-10 text-success border border-success px-2 py-1'>Linked</span>";
                                        } else {
                                            echo "<span class='badge bg-secondary bg-opacity-10 text-secondary border border-secondary px-2 py-1'>Standalone</span>";
                                        }
                                        echo "</td>";
                                        
                                        $rowId = htmlspecialchars($row['meter_id']); 
                                        echo "<td class='text-end text-nowrap'>
                                                <a href='Meter Update/meter-update-form.php?meter_id=" . $rowId . "' class='btn btn-sm btn-outline-secondary me-1' title='Edit Meter'><i class='bi bi-pencil'></i></a>";
                                        
                                        if ($row_category !== '') {
                                            echo "<a href='view-meter.php?meter_id=" . $rowId . "' class='btn btn-sm btn-outline-info me-1' title='View Meter Analysis'><i class='bi bi-eye'></i></a>";
                                        } else {
                                            echo "<button class='btn btn-sm btn-outline-secondary me-1 disabled' title='Analysis unavailable for this meter type'><i class='bi bi-eye-slash'></i></button>";
                                        }
                                                
                                        if ($isAdmin) {
                                            // --- CSRF PROTECTED DELETION FORM ---
                                            echo "<form method='POST' class='d-inline' onsubmit=\"return confirm('Are you sure you want to permanently delete this meter? This action cannot be undone.');\">
                                                    <input type='hidden' name='csrf_token' value='" . $csrf_token . "'>
                                                    <input type='hidden' name='delete_meter_id' value='" . $rowId . "'>
                                                    <button type='submit' class='btn btn-sm btn-outline-danger' title='Delete Meter'><i class='bi bi-trash3-fill'></i></button>
                                                  </form>";
                                        }

                                        echo "</td>";
                                        echo "</tr>";
                                    }
                                }

                                // --- METERS FOUND IN THE OBIS DATA BUT NOT REGISTERED ---
                                if (!empty($unregistered_meters)) {
                                    echo "<tr><td colspan='7' class='py-2' style='background-color:#1a1a1a;'>
                                            <span class='text-warning small'><i class='bi bi-exclamation-triangle me-1'></i>
                                            Found in the meter data but not registered in Meter Management (" . count($unregistered_meters) . ")</span>
                                          </td></tr>";
                                    foreach ($unregistered_meters as $u) {
                                        $u_serial = $u['serial'];
                                        $u_is_elec = ($u['kind'] === 'electrical');
                                        $u_link = $u['tenant'];

                                        echo "<tr>";
                                        echo "<td><span class='text-white fs-6'>" . htmlspecialchars($u_serial) . "</span></td>";

                                        echo "<td>
                                                <span class='badge " . ($u_is_elec ? 'bg-danger text-white' : 'bg-info text-dark') . " badge-type px-2 py-1 mb-1'>" . ($u_is_elec ? 'Electrical' : 'Water') . "</span>
                                                <div class='small text-light mt-1'>From " . ($u_is_elec ? 'OBIS 1.1.1.8.0' : 'OBIS 8.1.1.0.0') . " data</div>
                                              </td>";

                                        echo "<td>
                                                <div class='text-white mb-1'>" . htmlspecialchars($u_link['property'] ?? '') . "</div>
                                                <div class='small text-light'>" . (!empty($u['last_reading'])
                                                    ? "<i class='bi bi-clock-history me-1'></i>Last reading: " . htmlspecialchars(date('d M Y H:i', strtotime($u['last_reading'])))
                                                    : "") . "</div>
                                              </td>";

                                        echo "<td>";
                                        if ($u_link) {
                                            echo "<div class='text-white mb-1'>" . htmlspecialchars($u_link['name']) . "</div>";
                                            if (!empty($u_link['code'])) echo "<div class='small text-light'>Code: " . htmlspecialchars($u_link['code']) . "</div>";
                                        } else {
                                            echo "<span class='text-light'>Unassigned</span>";
                                        }
                                        echo "</td>";

                                        echo "<td>" . (!empty($u_link['shop']) ? "<div class='text-info'>" . htmlspecialchars($u_link['shop']) . "</div>" : "<div class='text-muted'>-</div>") . "</td>";

                                        echo "<td class='text-center'><span class='badge bg-warning bg-opacity-10 text-warning border border-warning px-2 py-1'>Unregistered</span></td>";

                                        $u_view = 'view-meter.php?' . http_build_query(['serial' => $u_serial, 'kind' => $u['kind']]);
                                        echo "<td class='text-end text-nowrap'>";
                                        if (lum_can('meters', 'edit')) {
                                            echo "<a href='https://lynx-um.co.za/Meter Management/Meter Registration/meter-register-form.php' class='btn btn-sm btn-outline-warning me-1' title='Register this meter'><i class='bi bi-plus-lg'></i></a>";
                                        }
                                        echo "<a href='" . htmlspecialchars($u_view, ENT_QUOTES) . "' class='btn btn-sm btn-outline-info me-1' title='View Meter Analysis'><i class='bi bi-eye'></i></a>";
                                        echo "</td>";
                                        echo "</tr>";
                                    }
                                }

                                if ($lum_registered_count === 0 && empty($unregistered_meters)) {
                                    echo "<tr><td colspan='7' class='text-center py-5 text-muted'>No meters found matching your filters.</td></tr>";
                                }
                            } catch (PDOException $e) {
                                error_log('LUM meter-overview query failed: ' . $e->getMessage());
                                echo "<tr><td colspan='7' class='text-center py-4 text-danger'>Unable to load meters. Please contact the system administrator.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="text-center py-5 text-white mt-5 border border-secondary" style="background-color: #1a1a1a; border-style: dashed !important;">
                <i class="bi bi-speedometer2 fs-1 mb-3 text-white"></i>
                <h4 class="fw-normal text-white">Select a filter or use the search bar</h4>
                <p class="text-white">The meter registry will generate once a filter is applied above.</p>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    
    <script>
        // ---------------------------------------------------------
        // Seamless Memory State & Scroll Retention Engine
        // ---------------------------------------------------------
        
        // Override native browser scroll-jumping behavior
        if ('scrollRestoration' in history) {
            history.scrollRestoration = 'manual';
        }

        document.addEventListener('DOMContentLoaded', function() {
            const tableContainer = document.getElementById('scrollableTableContainer');
            
            if (tableContainer) {
                // Restore scroll position
                const savedScrollPos = sessionStorage.getItem('lum_meter_table_scroll');
                if (savedScrollPos) {
                    // Small timeout ensures table rendering is complete before scrolling
                    setTimeout(() => {
                        tableContainer.scrollTop = parseInt(savedScrollPos, 10);
                    }, 15);
                }
            }
        });

        // Capture exact scroll depth the moment before the browser executes a reload/navigation request
        window.addEventListener('beforeunload', function() {
            const tableContainer = document.getElementById('scrollableTableContainer');
            if (tableContainer) {
                sessionStorage.setItem('lum_meter_table_scroll', tableContainer.scrollTop);
            }
        });
    </script>
</body>
</html>