<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once __DIR__ . '/bootstrap.php';
lum_page('manual_readings', 'edit');

// Fetch properties from Core Database
$all_properties = [];
try {
    $core_db_conn = lum_db('properties'); // bootstrap.php
    if ($core_db_conn) {
        
        $db_props_stmt = $core_db_conn->query("SELECT Property FROM lum_properties ORDER BY Property ASC");
        $all_properties = $db_props_stmt->fetchAll(PDO::FETCH_COLUMN);
        
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
    <title>Insert Generator Runtime Reading</title>
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
        /* Specific exemption to slightly round buttons as requested */
        .btn { border-radius: 4px !important; }
        /* Global Custom Scrollbar */
        ::-webkit-scrollbar { width: 14px; height: 14px; }
        ::-webkit-scrollbar-track { background: #0a0a0a; border-radius: 0; }
        ::-webkit-scrollbar-thumb { background: #4a4a4a; border-radius: 0; }
        body { background-color: #121212; color: #ffffff; padding-top: 0; overflow: hidden; }
        .navbar { background-color: #000000; border-bottom: 1px solid #333333 !important; z-index: 1050; }
        .action-bar { background-color: #0a0a0a; border-bottom: 1px solid #333333; padding: 10px 0; color: #ffffff; }
        .form-container { max-width: 600px; margin: 30px auto; background-color: #1e1e1e; padding: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.5); border-top: 4px solid #d32f2f; }
        .form-control, .form-select { background-color: #1a1a1a !important; border: 1px solid #444 !important; color: #fff !important; padding: 10px; transition: all 0.3s ease; }
        .form-control:focus, .form-select:focus { background-color: #222222 !important; border-color: #e3000f !important; color: #fff !important; box-shadow: 0 0 0 0.25rem rgba(227, 0, 15, 0.25) !important; }
        ::-webkit-calendar-picker-indicator { filter: invert(1); opacity: 0.7; cursor: pointer; }
        .form-label { color: #ffffff; font-size: 0.9rem; margin-bottom: 0.4rem; font-weight: 500 !important; }
    </style>
</head>
<body>

    <?php include LUM_ROOT . '/Layout/top-navbar.php'; ?>

<div class="page-wrapper">
    <div class="action-bar mb-4">
        <div class="container-fluid px-4">
            <div class="d-flex align-items-center flex-wrap gap-3">
                <!-- Back Button & Heading -->
                <div class="d-flex align-items-center pe-4 border-end border-secondary">
                    <a href="../manual-genrun-overview.php" class="btn btn-brand btn-sm px-2 shadow-sm me-3">
                        Back
                    </a>
                    <span class="fs-6 text-white mb-0">Insert Generator Runtime Reading</span>
                </div>
                
                <!-- Property Dropdown (Width override to prevent Bootstrap stacking) -->
                <select form="genrunSubmitForm" class="form-select form-select-sm m-0" id="property" name="property" required style="min-width: 250px; width: auto;">
                    <option value="" selected disabled>Select Property...</option>
                    <?php foreach ($all_properties as $prop): ?>
                        <option value="<?php echo htmlspecialchars($prop); ?>"><?php echo htmlspecialchars($prop); ?></option>
                    <?php endforeach; ?>
                </select>
                
                <!-- Date Picker (Width override to prevent Bootstrap stacking) -->
                <input type="date" form="genrunSubmitForm" class="form-control form-control-sm text-white m-0" id="reading_date" name="reading_date" required value="<?php echo date('Y-m-d'); ?>" style="max-width: 150px; width: auto;">
            </div>
        </div>
    </div>

    <div class="container pb-5">
        <div class="form-container">
            <form id="genrunSubmitForm" action="manual-genrun-form-submit.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                <div class="mb-4">
                    <label for="reading_hours" class="form-label">Runtime Hours</label>
                    <input type="text" class="form-control" id="reading_hours" name="reading_hours" required pattern="^([0-9]{2,}:)?[0-5][0-9]:[0-5][0-9]$|^[0-9]+:[0-5][0-9]$|^[0-9]+$">
                    <div class="form-text text-white-50 small mt-1">Format: HH:MM:SS (e.g. 3071:14:53)</div>
                </div>

                <div class="d-flex justify-content-between mt-4 border-top border-secondary pt-4">
                    <a href="../manual-genrun-overview.php" class="btn btn-brand btn-sm px-2 shadow-sm">Cancel</a>
                    <button type="submit" class="btn btn-danger px-4">Save Reading</button>
                </div>
            </form>
        </div>
    </div>
</div>

    <?php include LUM_ROOT . '/Layout/foot-assets.php'; ?>
</body>
</html>