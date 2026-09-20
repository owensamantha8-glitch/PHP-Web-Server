<?php
require_once __DIR__ . '/bootstrap.php';
// TEMPORARY ERROR REPORTING - REMOVE IN PRODUCTION
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    ini_set('session.use_strict_mode', 1);
    session_start();
}

// Redirect if not logged in
if (!isset($_SESSION['user_name'])) {
    header('Location: ' . lum_app_url(LUM_LOGIN_PATH));
    exit();
}

// Include database connection files
include __DIR__ . '/db-conn-meters.php';
include __DIR__ . '/db-conn-obis.php';
include __DIR__ . '/db-conn-tenants.php'; // Needed for Orphan checking

// Core System Database Connection (For Properties Table)
$core_db_conn = lum_db('properties_core', true);

// ---------------------------------------------------------
// Helper: Calculate Time Elapsed (Fixed for PHP 8.2+)
// ---------------------------------------------------------
function time_elapsed_string($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    // Calculate weeks and remainder days mathematically to avoid mutating the DateInterval object
    $weeks = floor($diff->d / 7);
    $days = $diff->d - ($weeks * 7);

    $string = [];
    if ($diff->y) $string[] = $diff->y . ' year' . ($diff->y > 1 ? 's' : '');
    if ($diff->m) $string[] = $diff->m . ' month' . ($diff->m > 1 ? 's' : '');
    if ($weeks)   $string[] = $weeks . ' week' . ($weeks > 1 ? 's' : '');
    if ($days)    $string[] = $days . ' day' . ($days > 1 ? 's' : '');
    if ($diff->h) $string[] = $diff->h . ' hour' . ($diff->h > 1 ? 's' : '');
    if ($diff->i) $string[] = $diff->i . ' minute' . ($diff->i > 1 ? 's' : '');
    if ($diff->s) $string[] = $diff->s . ' second' . ($diff->s > 1 ? 's' : '');

    $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}

// ---------------------------------------------------------
// Helper: Get the Absolute Latest Reading (With OBIS Filter)
// ---------------------------------------------------------
function getLatestReading($pdo, $serial, $type, $obis_filter = '') {
    // The @ symbol perfectly suppresses the JIT PCRE Memory warning
    $clean_serial = @preg_replace('/[^a-zA-Z0-9]/', '', $serial); 
    $max_time = null;

    $tables_to_check = [];
    
    if (!empty($obis_filter)) {
        // Enforce specific OBIS table search
        $clean_obis = @preg_replace('/[^0-9]/', '', $obis_filter); 
        $tables_to_check[] = ['db' => 'db_obis_' . $clean_obis, 'tb' => 'obis_' . $clean_obis . '_tb_' . $clean_serial];
    } else {
        // Broad search across known tables based on hardware type
        if (stripos($type, 'Electrical') !== false || stripos($type, 'Solar') !== false) {
            $tables_to_check = [
                ['db' => 'db_obis_11180', 'tb' => 'obis_11180_tb_' . $clean_serial],
                ['db' => 'db_obis_11181', 'tb' => 'obis_11181_tb_' . $clean_serial],
                ['db' => 'db_obis_11182', 'tb' => 'obis_11182_tb_' . $clean_serial]
            ];
        } elseif (stripos($type, 'Water') !== false) {
            $tables_to_check = [
                ['db' => 'db_obis_81100', 'tb' => 'obis_81100_tb_' . $clean_serial]
            ];
        }
    }

    foreach ($tables_to_check as $t) {
        try {
            $stmt = $pdo->query("SELECT MAX(Time_stamp) FROM `{$t['db']}`.`{$t['tb']}`");
            $val = $stmt->fetchColumn();
            if ($val) {
                if (!$max_time || strtotime($val) > strtotime($max_time)) {
                    $max_time = $val;
                }
            }
        } catch (Exception $e) {
            // Table doesn't exist yet, silently ignore and check next
        }
    }
    return $max_time;
}

// ---------------------------------------------------------
// Filter Variables & Dynamic Property Logic
// ---------------------------------------------------------
$searchProperty = $_GET['property'] ?? '';
$searchObis = $_GET['obis_code'] ?? '';
$searchTerm = $_GET['search'] ?? '';

$is_elec_obis = stripos($searchObis, '1.') === 0;
$is_water_obis = stripos($searchObis, '8.') === 0;

// Generate Property List
$prop_list_stmt = $meter_db_conn->query("SELECT DISTINCT meter_property FROM lum_meters WHERE meter_property IS NOT NULL AND meter_property != '' ORDER BY meter_property ASC");
$meter_properties = $prop_list_stmt->fetchAll(PDO::FETCH_COLUMN);

$db_props_stmt = $core_db_conn->query("SELECT Property FROM lum_properties ORDER BY Property ASC");
$managed_properties = $db_props_stmt->fetchAll(PDO::FETCH_COLUMN);

$property_list = array_unique(array_merge($managed_properties, $meter_properties));
sort($property_list);

// Apply Security Constraints
if (!empty($_SESSION['assigned_properties'])) {
    $allowed_props = explode(',', $_SESSION['assigned_properties']);
    $property_list = array_intersect($property_list, $allowed_props);
}

// ---------------------------------------------------------
// Cross-Reference: Find Registered vs Unregistered Meters
// ---------------------------------------------------------
$registered_serials = [];
try {
    // 1. Fetch from sys_db_meters
    $stmt = $meter_db_conn->query("SELECT meter_serial FROM lum_meters");
    while($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($r['meter_serial'])) $registered_serials[] = trim($r['meter_serial']);
    }
    
    // 2. Fetch from sys_db_tenants
    $stmt = $tenant_db_conn->query("SELECT tenant_electricalMeter_01, tenant_electricalMeter_02, tenant_electricalMeter_03, tenant_waterMeter_01, tenant_waterMeter_02, tenant_waterMeter_03 FROM lum_tenants");
    while($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        foreach($r as $s) {
            if (!empty(trim((string)$s))) $registered_serials[] = trim((string)$s);
        }
    }
    $registered_serials = array_unique($registered_serials);
} catch(Exception $e) {}

// 3. Scan OBIS Databases for Unregistered (Transmitting data, but not registered)
$orphan_meters = [];
if ($searchProperty === 'Unassigned' || empty($searchProperty)) {
    try {
        $raw_tables_stmt = $obis_db_conn->query("SELECT TABLE_SCHEMA, TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA IN ('db_obis_11180', 'db_obis_11181', 'db_obis_11182', 'db_obis_81100')");
        while ($row = $raw_tables_stmt->fetch(PDO::FETCH_ASSOC)) {
            $db = $row['TABLE_SCHEMA'];
            $tb = $row['TABLE_NAME'];
            
            $serial = '';
            $type = 'Unknown';
            
            if ($db === 'db_obis_11180') { $serial = str_replace('obis_11180_tb_', '', $tb); $type = 'Electrical'; }
            elseif ($db === 'db_obis_11181') { $serial = str_replace('obis_11181_tb_', '', $tb); $type = 'Electrical'; }
            elseif ($db === 'db_obis_11182') { $serial = str_replace('obis_11182_tb_', '', $tb); $type = 'Electrical'; }
            elseif ($db === 'db_obis_81100') { $serial = str_replace('obis_81100_tb_', '', $tb); $type = 'Water'; }
            
            if (!empty($serial) && !in_array($serial, $registered_serials)) {
                $orphan_meters[$serial] = $type; 
            }
        }
    } catch(Exception $e) {}
}

// ---------------------------------------------------------
// Build Final Meter Status List
// ---------------------------------------------------------
$meter_status_list = [];
$counts = ['total' => 0, 'online' => 0, 'warning' => 0, 'offline' => 0, 'nodata' => 0];

try {
    $query = "SELECT * FROM lum_meters WHERE 1=1";
    $params = [];

    // Apply strict access control
    if (!empty($_SESSION['assigned_properties'])) {
        $allowed_props = explode(',', $_SESSION['assigned_properties']);
        
        if (!empty($searchProperty) && $searchProperty !== 'Unassigned' && !in_array($searchProperty, $allowed_props)) {
            $searchProperty = reset($allowed_props);
        }
        
        if (empty($searchProperty)) {
            $inClause = [];
            foreach ($allowed_props as $i => $ap) {
                $pName = "allowed_prop_$i";
                $inClause[] = ":$pName";
                $params[$pName] = $ap;
            }
            // Ensure they can still inherently see Unassigned meters
            $query .= " AND (meter_property IN (" . implode(',', $inClause) . ") OR (meter_property IS NULL OR meter_property = ''))";
        }
    }

    if ($searchProperty === 'Unassigned') {
        $query .= " AND (meter_property IS NULL OR meter_property = '')";
    } elseif (!empty($searchProperty)) {
        $query .= " AND meter_property = :prop";
        $params['prop'] = $searchProperty;
    }

    if (!empty($searchTerm)) {
        $query .= " AND (meter_serial LIKE :search OR meter_tenant LIKE :search OR meter_location LIKE :search OR meter_property LIKE :search)";
        $params['search'] = "%$searchTerm%";
    }

    $query .= " ORDER BY meter_id DESC";
    $stmt = $meter_db_conn->prepare($query);
    $stmt->execute($params);
    $all_meters = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Append Registered Meters
    foreach ($all_meters as $m) {
        $type = $m['meter_type'];
        
        // Skip based on explicit OBIS hardware filter
        if ($is_elec_obis && stripos($type, 'Water') !== false) continue;
        if ($is_water_obis && (stripos($type, 'Electrical') !== false || stripos($type, 'Solar') !== false)) continue;

        $counts['total']++;
        $last_reading = getLatestReading($obis_db_conn, $m['meter_serial'], $type, $searchObis);
        
        $status_label = 'No Data';
        $status_color = 'secondary';
        $timestamp = 0;
        
        if ($last_reading) {
            $timestamp = strtotime($last_reading);
            $diff = time() - $timestamp;
            
            if ($diff <= 86400) { $status_label = 'Online'; $status_color = 'success'; $counts['online']++; }
            elseif ($diff <= 604800) { $status_label = 'Warning'; $status_color = 'warning text-white'; $counts['warning']++; }
            else { $status_label = 'Offline'; $status_color = 'danger'; $counts['offline']++; }
        } else {
            $counts['nodata']++;
        }

        $meter_status_list[] = [
            'serial' => $m['meter_serial'],
            'type' => str_replace('_', ' ', $type),
            'property' => !empty($m['meter_property']) ? $m['meter_property'] : 'Unassigned',
            'tenant' => $m['meter_tenant'],
            'last_reading' => $last_reading,
            'timestamp' => $timestamp,
            'status_label' => $status_label,
            'status_color' => $status_color,
            'is_orphan' => false
        ];
    }
} catch (Exception $e) {
    die("Error fetching meters: " . $e->getMessage());
}

// Append Unregistered Meters
foreach ($orphan_meters as $serial => $type) {
    // Skip based on explicit OBIS hardware filter
    if ($is_elec_obis && stripos($type, 'Water') !== false) continue;
    if ($is_water_obis && stripos($type, 'Electrical') !== false) continue;
    // Apply search keyword filter to serials
    if (!empty($searchTerm) && stripos($serial, $searchTerm) === false) continue;

    $counts['total']++;
    $last_reading = getLatestReading($obis_db_conn, $serial, $type, $searchObis);
    
    $status_label = 'No Data';
    $status_color = 'secondary';
    $timestamp = 0;
    
    if ($last_reading) {
        $timestamp = strtotime($last_reading);
        $diff = time() - $timestamp;
        
        if ($diff <= 86400) { $status_label = 'Online'; $status_color = 'success'; $counts['online']++; }
        elseif ($diff <= 604800) { $status_label = 'Warning'; $status_color = 'warning text-white'; $counts['warning']++; }
        else { $status_label = 'Offline'; $status_color = 'danger'; $counts['offline']++; }
    } else {
        $counts['nodata']++;
    }

    $meter_status_list[] = [
        'serial' => $serial,
        'type' => $type,
        'property' => 'Unassigned',
        'tenant' => '',
        'last_reading' => $last_reading,
        'timestamp' => $timestamp,
        'status_label' => $status_label,
        'status_color' => $status_color,
        'is_orphan' => true
    ];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meter Health Status - Lynx Utility Management</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <!-- DataTables for instant sorting -->
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">

    <style>
        body { background-color: #121212; color: #ffffff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .lynx-brand-hover:hover { color: #e3000f !important; }
        .lynx-logout { color: #e3000f; }
        .lynx-logout:hover { color: #bf000c; }
        .action-bar { background-color: #0a0a0a; border-bottom: 1px solid #333333; padding: 15px 0; }

        .stat-card {
            background-color: #1e1e1e;
            border: 1px solid #333;
            border-radius: 8px;
            padding: 15px 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.2);
        }
        .stat-card h6 { color: #b0b0b0; font-weight: 700; font-size: 0.85rem; letter-spacing: 0.5px; }
        .stat-card h2 { color: #ffffff; font-weight: 800; font-size: 2rem; margin-bottom: 0; }
        
        .border-blue { border-left: 5px solid #0d6efd; }
        .border-green { border-left: 5px solid #198754; }
        .border-yellow { border-left: 5px solid #ffc107; }
        .border-red { border-left: 5px solid #dc3545; }
        .border-grey { border-left: 5px solid #6c757d; }

        /* Search Inputs */
        .search-input { background-color: #1e1e1e !important; border: 1px solid #444 !important; color: #fff !important; }
        .search-input:focus { border-color: #e3000f !important; box-shadow: 0 0 0 0.25rem rgba(227, 0, 15, 0.25) !important; }
        .search-btn { background-color: #1e1e1e; border: 1px solid #444; color: #b0b0b0; }
        .search-btn:hover { background-color: #333; color: #fff; }

        .table-container { background-color: #000000; border: 1px solid #333333; border-radius: 8px; overflow: hidden; padding: 20px;}
        .table { color: #e0e0e0; margin-bottom: 0;}
        .table thead th { background-color: #1a1a1a; color: #aaaaaa; border-bottom: 2px solid #333333; font-weight: 600; text-transform: uppercase; font-size: 0.85rem; letter-spacing: 0.5px; }
        .table tbody td { border-bottom: 1px solid #222222; vertical-align: middle; background-color: transparent; color: #cccccc; }
        .table tbody tr:hover td { background-color: #111111; }
        
        /* DataTables Dark Mode Overrides */
        div.dataTables_wrapper div.dataTables_length select, div.dataTables_wrapper div.dataTables_filter input { background-color: #1e1e1e; border: 1px solid #444; color: #fff; }
        div.dataTables_wrapper div.dataTables_info { color: #aaa; }
        .page-item.disabled .page-link { background-color: #1a1a1a; border-color: #333; color: #666; }
        .page-item .page-link { background-color: #1e1e1e; border-color: #333; color: #aaa; }
        .page-item.active .page-link { background-color: #e3000f; border-color: #e3000f; color: #fff; }
    </style>
</head>
<body>

    <nav class="navbar navbar-dark py-1 border-bottom" style="background-color: #000000; border-color: #333333 !important;">
        <div class="container-fluid px-3">
            <a class="navbar-brand fs-6 mb-0 text-white lynx-brand-hover transition-colors" href="<?php echo htmlspecialchars(lum_app_url('/index.php'), ENT_QUOTES); ?>">
                Lynx Utility Management
            </a>
            <div class="d-flex align-items-center">
                <div class="text-secondary small d-flex align-items-center">
                    <i class="bi bi-person-fill text-white me-2"></i>
                    <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?>
                </div>
                <a href="<?php echo htmlspecialchars(lum_app_url('/Sec/logout.php'), ENT_QUOTES); ?>" class="text-decoration-none small ms-3 ps-3 border-start lynx-logout transition-colors" style="border-color: #333333 !important;">
                    Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="action-bar mb-4">
        <div class="container-fluid px-4">
            <div class="row align-items-center">
                <!-- Left: Title & Back Button -->
                <div class="col-12 col-xl-2 mb-3 mb-xl-0">
                    <h4 class="mb-3 text-white"><i class="bi bi-activity text-info me-2"></i> Status</h4>
                    <a href="meter-overview.php" class="btn btn-outline-light btn-sm px-3 py-2 shadow-sm">
                        <i class="bi bi-arrow-left me-2"></i>Back to Overview
                    </a>
                </div>
                
                <!-- Right: Property, Type & Search Bar -->
                <div class="col-12 col-xl-10 d-flex justify-content-xl-end">
                    <form action="meter-status.php" method="GET" class="d-flex w-100 gap-2 justify-content-xl-end">
                        
                        <!-- Property Dropdown -->
                        <select name="property" class="form-select search-input" style="max-width: 220px;" onchange="this.form.submit()">
                            <option value="">All Properties...</option>
                            <option value="Unassigned" <?php echo $searchProperty === 'Unassigned' ? 'selected' : ''; ?>>[ Unassigned Meters ]</option>
                            <?php foreach($property_list as $prop): ?>
                                <option value="<?php echo htmlspecialchars($prop); ?>" <?php echo $searchProperty === $prop ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($prop); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <!-- OBIS Code Dropdown -->
                        <select name="obis_code" class="form-select search-input" style="max-width: 210px;" onchange="this.form.submit()">
                            <option value="">All OBIS Databases...</option>
                            <option value="1.1.1.8.0" <?php echo $searchObis === '1.1.1.8.0' ? 'selected' : ''; ?>>1.1.1.8.0 (Elec Total)</option>
                            <option value="1.1.1.8.1" <?php echo $searchObis === '1.1.1.8.1' ? 'selected' : ''; ?>>1.1.1.8.1 (Elec T1)</option>
                            <option value="1.1.1.8.2" <?php echo $searchObis === '1.1.1.8.2' ? 'selected' : ''; ?>>1.1.1.8.2 (Elec T2)</option>
                            <option value="8.1.1.0.0" <?php echo $searchObis === '8.1.1.0.0' ? 'selected' : ''; ?>>8.1.1.0.0 (Water)</option>
                        </select>

                        <!-- Keyword Search -->
                        <div class="input-group" style="max-width: 350px;">
                            <input type="text" name="search" class="form-control search-input" placeholder="Search serial, tenant, prop..." value="<?php echo htmlspecialchars($searchTerm); ?>">
                            
                            <?php if (!empty($searchTerm) || !empty($searchProperty) || !empty($searchObis)): ?>
                                <a href="meter-status.php" class="btn search-btn d-flex align-items-center" title="Clear Filters">
                                    <i class="bi bi-x-lg text-danger"></i>
                                </a>
                            <?php endif; ?>

                            <button class="btn search-btn" type="submit" id="button-addon2">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid px-4 pb-5">
        
        <!-- Status Counters -->
        <div class="row g-3 mb-4">
            <div class="col"><div class="stat-card border-blue"><h6>TOTAL METERS</h6><h2><?php echo $counts['total']; ?></h2></div></div>
            <div class="col"><div class="stat-card border-green"><h6>ONLINE (< 24h)</h6><h2 class="text-success"><?php echo $counts['online']; ?></h2></div></div>
            <div class="col"><div class="stat-card border-yellow"><h6>WARNING (1-7 days)</h6><h2 class="text-warning"><?php echo $counts['warning']; ?></h2></div></div>
            <div class="col"><div class="stat-card border-red"><h6>OFFLINE (> 7 days)</h6><h2 class="text-danger"><?php echo $counts['offline']; ?></h2></div></div>
            <div class="col"><div class="stat-card border-grey"><h6>NO DATA YET</h6><h2 class="text-secondary"><?php echo $counts['nodata']; ?></h2></div></div>
        </div>

        <div class="table-container shadow">
            <table id="statusTable" class="table table-hover table-borderless align-middle w-100">
                <thead>
                    <tr>
                        <th scope="col">Meter Serial</th>
                        <th scope="col">Type</th>
                        <th scope="col">Property</th>
                        <th scope="col">Tenant / Location</th>
                        <th scope="col">Latest Reading Recorded</th>
                        <th scope="col" class="text-center">Health Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($meter_status_list as $ms): ?>
                    <tr>
                        <td><strong class="text-white"><?php echo htmlspecialchars($ms['serial']); ?></strong></td>
                        <td><?php echo htmlspecialchars($ms['type']); ?></td>
                        <td>
                            <?php if($ms['property'] === 'Unassigned'): ?>
                                <span class="text-white fst-italic">Unassigned</span>
                            <?php else: ?>
                                <?php echo htmlspecialchars($ms['property']); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($ms['is_orphan']): ?>
                                <span class="text-danger fw-bold"><i class="bi bi-exclamation-triangle-fill me-1"></i> Not Registered</span>
                            <?php else: ?>
                                <?php echo htmlspecialchars($ms['tenant']); ?>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Data-sort attribute ensures DataTables sorts by the exact timestamp instead of alphabetizing the strings -->
                        <td data-sort="<?php echo $ms['timestamp']; ?>">
                            <?php if ($ms['last_reading']): ?>
                                <div class="text-white"><?php echo date('d M Y, H:i', $ms['timestamp']); ?></div>
                                <div class="small text-white"><i class="bi bi-clock-history me-1"></i><?php echo time_elapsed_string($ms['last_reading']); ?></div>
                            <?php else: ?>
                                <span class="text-white fst-italic">Never Logged</span>
                            <?php endif; ?>
                        </td>
                        
                        <td class="text-center">
                            <span class="badge bg-<?php echo $ms['status_color']; ?> px-3 py-2 border border-<?php echo str_replace(' text-white', '', $ms['status_color']); ?> bg-opacity-10 w-100" style="max-width: 120px;">
                                <?php echo $ms['status_label']; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
        $(document.ready(function() {
            $('#statusTable').DataTable({
                "pageLength": 50,
                "order": [[ 4, "asc" ]], // Sort by Last Reading ascending (so oldest/offline meters show first)
                "language": {
                    "search": "Instant List Filter:",
                    "lengthMenu": "Show _MENU_ meters per page",
                    "info": "Showing _START_ to _END_ of _TOTAL_ meters"
                }
            });
        });
    </script>
</body>
</html>