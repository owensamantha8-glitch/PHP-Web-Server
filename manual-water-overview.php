<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once __DIR__ . '/bootstrap.php';
lum_page('manual_readings', 'view');

lum_connect('manual'); // $manual_db_conn (bootstrap.php)
require_once LUM_ROOT . "/Manual Readings/Manual Water Control/manual-water-conr.php";

$conr = new manual_water_conr($manual_db_conn);

$search_property = $_GET['search_property'] ?? '';
$search_date = $_GET['search_date'] ?? '';
if ($search_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $search_date)) { $search_date = ''; }

// --- PROPERTY ACCESS: users with assigned properties may only see those properties ---
$allowed_props = !empty($_SESSION['assigned_properties']) ? array_map('trim', explode(',', $_SESSION['assigned_properties'])) : null;
if ($allowed_props !== null && $search_property !== '' && !in_array($search_property, $allowed_props, true)) {
    $search_property = '';
}

// Fetch readings based on filters
$readings = $conr->get_readings($search_property, $search_date);
if (!is_array($readings)) { $readings = []; }

// Remove readings for properties this user may not see (also covers "All Properties")
if ($allowed_props !== null && is_array($readings)) {
    $readings = array_values(array_filter($readings, function ($r) use ($allowed_props) {
        return in_array($r['property'] ?? '', $allowed_props, true);
    }));
}

// Fetch properties for the dropdown
$all_properties = [];
try {
    $core_db_conn = lum_db('properties'); // bootstrap.php
    if ($core_db_conn) {
        
        $db_props_stmt = $core_db_conn->query("SELECT Property FROM lum_properties ORDER BY Property ASC");
        $all_properties = $db_props_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Filter by user assignments if they exist
        if (!empty($_SESSION['assigned_properties'])) {
            $allowed = explode(',', $_SESSION['assigned_properties']);
            $all_properties = array_intersect($all_properties, $allowed);
        }
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manual Water Readings Overview</title>
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>
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
        /* Specific exemption to slightly round the back button as requested */
        .btn-outline-light { border-radius: 4px !important; }
        /* Global Custom Scrollbar (Matched to index.php) */
        ::-webkit-scrollbar { width: 14px; height: 14px; }
        ::-webkit-scrollbar-track { background: #0a0a0a; border-radius: 0; }
        ::-webkit-scrollbar-thumb { background: #4a4a4a; border-radius: 0; }
        body { background-color: #121212; color: #ffffff; overflow: hidden; }
        .navbar { background-color: #000000; border-bottom: 1px solid #333333 !important; z-index: 1050; }
        /* Unified Portal Layout */
        .d-flex-wrapper { display: flex; width: 100%; overflow: hidden; height: calc(100vh - 46px); }
        .sidebar { width: 280px; min-width: 280px; background: linear-gradient(180deg, #000 0%, #0f0f0f 100%); padding: 25px 15px; border-right: 1px solid #333; overflow-y: auto; }
        /* Adjusted main-content padding to perfectly align headings horizontally */
        .main-content { flex-grow: 1; padding: 25px 30px; overflow-y: auto; background-color: #121212; }
        /* Sidebar Navigation */
        .nav-pills .nav-link { color: #b0b0b0; padding: 12px 20px; margin-bottom: 8px; transition: all 0.3s ease; border: 1px solid transparent; }
        .nav-pills .nav-link:hover { color: #fff; background-color: #1e1e1e; border-color: #333; }
        .nav-pills .nav-link.active { background-color: #e3000f; color: #fff; border-color: #e3000f; }
        /* Filter Controls */
        .form-control, .form-select { background-color: #1e1e1e !important; color: #ffffff !important; border: 1px solid #444 !important; padding: 10px; }
        .form-control::placeholder { color: #aaaaaa !important; opacity: 1; }
        .form-control:focus, .form-select:focus { background-color: #333333 !important; color: #ffffff !important; border-color: #e3000f !important; box-shadow: none !important; }
        ::-webkit-calendar-picker-indicator { filter: invert(1); opacity: 0.7; cursor: pointer; }
        /* Independent Scrolling Table */
        .table-container { background-color: #000000; border: 1px solid #333333; overflow: hidden; }
        .table-responsive { max-height: calc(100vh - 160px); overflow-y: auto; overflow-x: auto; }
        .table { margin-bottom: 0; color: #e0e0e0; font-size: 0.85rem; }
        .table thead th { background-color: #1a1a1a; color: #aaaaaa; border-bottom: 2px solid #333333; letter-spacing: 0.5px; padding: 10px 14px; position: sticky; top: 0; z-index: 10; box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.4); }
        .table tbody td { border-bottom: 1px solid #222222; padding: 10px 14px; vertical-align: middle; background-color: transparent; color: #cccccc; }
        .table tbody tr:hover td { background-color: #111111; }
    </style>
</head>
<body>

    <!-- Top Navbar -->
    <?php include LUM_ROOT . '/Layout/top-navbar.php'; ?>

<div class="d-flex-wrapper">
    <!-- Sidebar / Filters -->
    <div class="sidebar">
        
        <div class="mb-4 d-flex align-items-center">
            <a href="<?php echo htmlspecialchars(lum_app_url('/index.php'), ENT_QUOTES); ?>" class="btn btn-brand btn-sm px-2 shadow-sm me-3">Back</a>
            <span class="fs-6 text-white mb-0">Manual Readings</span>
        </div>
        
        <div class="nav flex-column nav-pills mb-5" role="tablist">
            <a href="manual-water-overview.php" class="nav-link active">
                <i class="bi bi-droplet me-2"></i> Water Meters
            </a>
            <a href="manual-genrun-overview.php" class="nav-link">
                <i class="bi bi-lightning-charge me-2"></i> Generator Runtime
            </a>
        </div>

        <form method="GET" action="manual-water-overview.php" class="bg-dark p-3 border border-secondary shadow-sm">
            <div class="mb-3">
                <label class="form-label small text-white mb-1">Property</label>
                <select name="search_property" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All Properties...</option>
                    <?php foreach ($all_properties as $prop): ?>
                        <option value="<?php echo htmlspecialchars($prop); ?>" <?php echo $search_property === $prop ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($prop); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-4">
                <label class="form-label small text-white mb-1">Date</label>
                <input type="date" name="search_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($search_date); ?>" onchange="this.form.submit()">
            </div>
            <div class="d-grid">
                <a href="manual-water-overview.php" class="btn btn-brand btn-sm px-2 shadow-sm">Clear Filters</a>
            </div>
        </form>
    </div>

    <!-- Main Content Area -->
    <div class="main-content">
        
        <div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom border-secondary" style="min-height: 31px;">
            <span class="fs-6 text-white mb-0">Water Readings Overview</span>
            <?php if (lum_can('manual_readings', 'edit')): ?>
            <a href="Manual Water Insert/manual-water-form.php" class="btn btn-danger btn-sm shadow-sm" style="background-color: #e3000f; border:none;">+ Add New Reading</a>
            <?php endif; ?>
        </div>

        <!-- Feedback Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show mb-3 border-0" style="background-color: #198754; color: #fff;" role="alert">
                <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-3 border-0" style="background-color: #dc3545; color: #fff;" role="alert">
                <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Data Table -->
        <div class="table-container shadow">
            <div class="table-responsive">
                <table class="table table-dark table-hover table-borderless align-middle">
                    <thead>
                        <tr>
                            <th scope="col" style="width: 15%;">Date</th>
                            <th scope="col" style="width: 25%;">Property</th>
                            <th scope="col" style="width: 20%;">Tenant</th>
                            <th scope="col" style="width: 10%;">Shop</th>
                            <th scope="col" style="width: 15%;">Meter Serial</th>
                            <th scope="col" class="text-end" style="width: 15%;">Reading (kL)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($readings) > 0): ?>
                            <?php foreach ($readings as $row): ?>
                                <tr>
                                    <td><?php echo date('d M Y', strtotime($row['reading_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($row['property']); ?></td>
                                    <td><?php echo htmlspecialchars($row['tenant']); ?></td>
                                    <td><?php echo htmlspecialchars($row['shop']); ?></td>
                                    <td><?php echo htmlspecialchars($row['water_serial'] ?? 'N/A'); ?></td>
                                    <td class="text-end text-info"><?php echo number_format($row['reading'], 4); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="bi bi-droplet-half fs-1 mb-3 d-block"></i>
                                    No manual water readings found matching your filters.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

    <?php include LUM_ROOT . '/Layout/foot-assets.php'; ?>
</body>
</html>