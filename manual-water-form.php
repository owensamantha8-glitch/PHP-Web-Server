<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once '/var/www/Lynx/bootstrap.php';
lum_page('manual_readings', 'edit');

lum_connect('tenants'); // $tenant_db_conn (bootstrap.php)

// Properties for the dropdown: the property register only (Configurations)
$properties = [];
try {
    $pr_db = lum_db('properties'); // bootstrap.php
    if (!$pr_db) throw new RuntimeException('sys_db_properties is not available');
    $properties = $pr_db->query("SELECT Property FROM lum_properties ORDER BY Property ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $e) {
    error_log('LUM manual-water-form: property register not read: ' . $e->getMessage());
}

// Filter by assigned properties if applicable
if (!empty($_SESSION['assigned_properties'])) {
    $allowed_props = explode(',', $_SESSION['assigned_properties']);
    $properties = array_intersect($properties, $allowed_props);
}

$selected_property = $_GET['property'] ?? '';
// Only properties in the (access-filtered) list may be opened
if ($selected_property !== '' && !in_array($selected_property, $properties, true)) {
    $selected_property = '';
}

// Fetch tenants and their water meters for the selected property
$tenant_meters = [];
if (!empty($selected_property)) {
    $stmt = $tenant_db_conn->prepare("SELECT tenant_name, tenant_shop, tenant_waterMeter_01, tenant_waterMeter_02, tenant_waterMeter_03, tenant_waterMeter_04 FROM lum_tenants WHERE tenant_property = :prop ORDER BY tenant_shop ASC");
    $stmt->execute(['prop' => $selected_property]);
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($tenants as $t) {
        $w_meters = array_filter([$t['tenant_waterMeter_01'], $t['tenant_waterMeter_02'], $t['tenant_waterMeter_03'], $t['tenant_waterMeter_04']]);
        foreach ($w_meters as $wm) {
            if (!empty(trim($wm))) {
                $tenant_meters[] = [
                    'tenant' => $t['tenant_name'],
                    'shop' => $t['tenant_shop'],
                    'serial' => trim($wm)
                ];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Insert Manual Water Readings</title>
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
        .form-container { max-width: 900px; margin: 30px auto; background-color: #1e1e1e; padding: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.5); border-top: 4px solid #d32f2f; }
        .form-control, .form-select { background-color: #1a1a1a !important; border: 1px solid #444 !important; color: #fff !important; padding: 10px; transition: all 0.3s ease; }
        .form-control:focus, .form-select:focus { background-color: #222222 !important; border-color: #e3000f !important; color: #fff !important; box-shadow: 0 0 0 0.25rem rgba(227, 0, 15, 0.25) !important; }
        ::-webkit-calendar-picker-indicator { filter: invert(1); opacity: 0.7; cursor: pointer; }
        /* Removed Spinner from Number Inputs */
        input[type=number]::-webkit-inner-spin-button, 
        input[type=number]::-webkit-outer-spin-button { 
            -webkit-appearance: none; 
            margin: 0; 
        }
        .table { margin-bottom: 0; color: #e0e0e0; font-size: 0.85rem; }
        .table thead th { background-color: #1a1a1a; color: #aaaaaa; border-bottom: 2px solid #333333; letter-spacing: 0.5px; padding: 10px 14px; position: sticky; top: 0; z-index: 10; box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.4); }
        .table tbody td { border-bottom: 1px solid #222222; padding: 10px 14px; vertical-align: middle; background-color: transparent; color: #cccccc; }
        .table tbody tr:hover td { background-color: #111111; }
    </style>
</head>
<body>

    <!-- Top Navbar -->
    <?php include LUM_ROOT . '/Layout/top-navbar.php'; ?>

<div class="page-wrapper">
    <div class="action-bar mb-4">
        <div class="container-fluid px-4">
            <div class="d-flex align-items-center flex-wrap gap-3">
                <!-- Back Button & Heading -->
                <div class="d-flex align-items-center pe-4 border-end border-secondary">
                    <a href="../manual-water-overview.php" class="btn btn-brand btn-sm px-2 shadow-sm me-3">
                        Back
                    </a>
                    <span class="fs-6 text-white mb-0">Insert Manual Water Readings</span>
                </div>
                
                <!-- Property Dropdown -->
                <form method="GET" action="manual-water-form.php" class="m-0" id="propertySelectForm">
                    <select class="form-select form-select-sm" id="property" name="property" onchange="this.form.submit()" required style="min-width: 250px;">
                        <option value="" selected disabled></option>
                        <?php foreach ($properties as $prop): ?>
                            <option value="<?php echo htmlspecialchars($prop); ?>" <?php echo ($selected_property === $prop) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($prop); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                
                <!-- Date Picker (appears when property is selected) -->
                <?php if (!empty($selected_property) && !empty($tenant_meters)): ?>
                    <input type="date" form="meterSubmitForm" class="form-control form-control-sm text-white" id="reading_date" name="reading_date" required value="<?php echo date('Y-m-d'); ?>" style="max-width: 150px;">
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="container pb-5">
        <?php if (empty($selected_property)): ?>
            <div class="text-center py-5 text-muted mt-5 border border-secondary" style="background-color: #1a1a1a; max-width: 900px; margin: 0 auto; border-style: dashed !important;">
                <i class="bi bi-building fs-1 mb-3"></i>
                <h4 class="fw-normal">Select a Property</h4>
                <p>Choose a property from the top navigation bar to load the tenant meter registry.</p>
            </div>
        <?php elseif (empty($tenant_meters)): ?>
            <div class="text-center py-5 text-warning mt-5 border border-warning" style="background-color: #1a1a1a; max-width: 900px; margin: 0 auto; border-style: dashed !important;">
                <i class="bi bi-exclamation-triangle fs-1 mb-3"></i>
                <h4 class="fw-normal">No Active Meters</h4>
                <p>No active water meters were found for tenants in <?php echo htmlspecialchars($selected_property); ?>.</p>
            </div>
        <?php else: ?>
            <div class="form-container">
                <!-- Bulk Reading Input Form -->
                <form id="meterSubmitForm" action="manual-water-form-submit.php" method="POST">
                    
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="property" value="<?php echo htmlspecialchars($selected_property); ?>">

                    <div class="table-responsive" style="max-height: 60vh;">
                        <table class="table table-dark table-hover table-borderless align-middle">
                            <thead>
                                <tr>
                                    <th>Tenant Name</th>
                                    <th>Shop No.</th>
                                    <th>Meter Serial No.</th>
                                    <th style="width: 160px;">Reading (kL)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tenant_meters as $m): ?>
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($m['tenant']); ?>
                                        <input type="hidden" name="tenants[]" value="<?php echo htmlspecialchars($m['tenant']); ?>">
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($m['shop']); ?>
                                        <input type="hidden" name="shops[]" value="<?php echo htmlspecialchars($m['shop']); ?>">
                                    </td>
                                    <td class="text-info">
                                        <?php echo htmlspecialchars($m['serial']); ?>
                                        <input type="hidden" name="serials[]" value="<?php echo htmlspecialchars($m['serial']); ?>">
                                    </td>
                                    <td>
                                        <input type="number" step="0.0001" class="form-control text-end" name="readings[]">
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-between mt-4 border-top border-secondary pt-4">
                        <a href="../manual-water-overview.php" class="btn btn-outline-light px-4">Cancel</a>
                        <div class="form-check text-white me-3 d-inline-block align-middle">
                            <input class="form-check-input" type="checkbox" value="1" id="allow_unusual" name="allow_unusual">
                            <label class="form-check-label fs-6 fw-normal" for="allow_unusual" title="Accept a reading that is lower than the previous one, or far higher than usual">Allow unusual readings</label>
                        </div>
                        <button type="submit" class="btn btn-danger px-4">Save Readings</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

    <?php include LUM_ROOT . '/Layout/foot-assets.php'; ?>
</body>
</html>