<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once '/var/www/Lynx/bootstrap.php';
lum_page('meters', 'edit');

// =========================================================================
// EMBEDDED AJAX ENDPOINT (Bypasses external file permission blocks)
// =========================================================================

// 1. Check if Meter is already registered (excluding the current meter being edited)
if (isset($_GET['check_exists']) && isset($_GET['serial']) && isset($_GET['current_id'])) {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');
    
    if (empty($_SESSION['user_name'])) {
        echo json_encode(['error' => 'Security Error: You are not logged in.']);
        exit();
    }

    $serial = trim($_GET['serial']);
    $current_id = trim($_GET['current_id']);
    
    try {
        $meter_db_conn = lum_db('meters'); // bootstrap.php
        if (!$meter_db_conn) {
            echo json_encode(['error' => 'Database error. Please try again.']);
            exit();
        }
        
        $stmt = $meter_db_conn->prepare("SELECT meter_id FROM lum_meters WHERE meter_serial = :s AND meter_id != :id LIMIT 1");
        $stmt->execute(['s' => $serial, 'id' => $current_id]);
        $exists = $stmt->fetch() ? true : false;
        
        echo json_encode(['exists' => $exists]);
    } catch (Exception $e) {
        error_log('LUM meter-update-form.php lookup failed: ' . $e->getMessage()); echo json_encode(['error' => 'Database error. Please try again.']);
    }
    exit();
}

// 2. Fetch Tenant Data for Auto-fill
if (isset($_GET['ajax_lookup']) && isset($_GET['serial'])) {
    
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');
    
    if (empty($_SESSION['user_name'])) {
        echo json_encode(['error' => 'Security Error: You are not logged in.']);
        exit();
    }

    $serial = trim($_GET['serial']);

    try {
        $tenant_db_conn = lum_db('tenants'); // bootstrap.php
        if (!$tenant_db_conn) {
            echo json_encode(['error' => 'Database error. Please try again.']);
            exit();
        }

        $query = "SELECT tenant_property, tenant_name, tenant_shop, tenant_shop_area,
                         CASE 
                            WHEN tenant_electricalMeter_01 = :s1 OR tenant_electricalMeter_02 = :s2 OR tenant_electricalMeter_03 = :s3 THEN 'electrical'
                            WHEN tenant_waterMeter_01 = :s4 OR tenant_waterMeter_02 = :s5 OR tenant_waterMeter_03 = :s6 OR tenant_waterMeter_04 = :s7 THEN 'water'
                            ELSE 'unknown'
                         END as matched_type
                  FROM lum_tenants 
                  WHERE tenant_electricalMeter_01 = :s1 
                     OR tenant_electricalMeter_02 = :s2 
                     OR tenant_electricalMeter_03 = :s3 
                     OR tenant_waterMeter_01 = :s4 
                     OR tenant_waterMeter_02 = :s5 
                     OR tenant_waterMeter_03 = :s6 
                     OR tenant_waterMeter_04 = :s7
                  LIMIT 1";

        $stmt = $tenant_db_conn->prepare($query);
        $stmt->execute([
            ':s1' => $serial, ':s2' => $serial, ':s3' => $serial,
            ':s4' => $serial, ':s5' => $serial, ':s6' => $serial, ':s7' => $serial
        ]);
        
        $tenantData = $stmt->fetch(PDO::FETCH_ASSOC);

        // Property access: do not reveal tenants of properties this user may not see
        $lum_allowed = (!empty($_SESSION['assigned_properties']) ? array_map('trim', explode(',', $_SESSION['assigned_properties'])) : null);
        if ($tenantData && $lum_allowed !== null && !in_array($tenantData['tenant_property'], $lum_allowed, true)) {
            $tenantData = false;
        }

        if ($tenantData) {
            echo json_encode([
                'success'      => true,
                'property'     => $tenantData['tenant_property'],
                'tenant'       => $tenantData['tenant_name'],
                'shop'         => $tenantData['tenant_shop'],
                'area'         => $tenantData['tenant_shop_area'],
                'matched_type' => $tenantData['matched_type']
            ], JSON_INVALID_UTF8_SUBSTITUTE);
        } else {
            echo json_encode(['success' => false, 'message' => 'No matching tenant found for this serial number.']);
        }
    } catch (Exception $e) {
        error_log('LUM meter-update-form.php lookup failed: ' . $e->getMessage()); echo json_encode(['error' => 'Database error. Please try again.']);
    }
    
    exit(); 
}
// =========================================================================

lum_connect('meters'); // $meter_db_conn, $meter_crud

$all_properties = [];
$electrical_types = [];
$water_types = [];
$electrical_brands = [];
$water_brands = [];
$tenant_data_map = [];

try {
    $core_db_conn = lum_db('properties'); // bootstrap.php
    if (!$core_db_conn) throw new PDOException('sys_db_properties is not available');
    $db_props_stmt = $core_db_conn->query("SELECT Property FROM lum_properties ORDER BY Property ASC");
    $all_properties = $db_props_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $info_db_conn = lum_db('information'); // bootstrap.php
    if (!$info_db_conn) throw new PDOException('sys_db_information is not available');
    $elec_stmt = $info_db_conn->query("SELECT type_name FROM lum_meter_type_electrical ORDER BY type_name ASC");
    $electrical_types = $elec_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $water_stmt = $info_db_conn->query("SELECT type_name FROM lum_meter_type_water ORDER BY type_name ASC");
    $water_types = $water_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $brands_stmt = $info_db_conn->query("SELECT brand_name FROM lum_meters_electrical_brands ORDER BY brand_name ASC");
    $electrical_brands = $brands_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $w_brands_stmt = $info_db_conn->query("SELECT brand_name FROM lum_meters_water_brands ORDER BY brand_name ASC");
    $water_brands = $w_brands_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $tenant_db_conn = lum_db('tenants'); // bootstrap.php
    if (!$tenant_db_conn) throw new PDOException('sys_db_tenants is not available');
    $tenant_stmt = $tenant_db_conn->query("SELECT tenant_property, tenant_name, tenant_shop, tenant_shop_area FROM lum_tenants WHERE tenant_shop_area IS NOT NULL");
    $tenant_data_map = $tenant_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    error_log('LUM meter-update-form.php: ' . $e->getMessage()); die("<div style='color:white; padding:20px; font-family:sans-serif;'>Database Connection error. Please contact the system administrator.</div>");
}

$meter_id = $_GET['meter_id'] ?? $_GET['id'] ?? null;
$meter = [];

if ($meter_id) {
    try {
        $stmt = $meter_db_conn->prepare("SELECT * FROM lum_meters WHERE meter_id = :meter_id");
        $stmt->execute(['meter_id' => $meter_id]);
        $meter = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$meter) {
            die("<div style='color:white; padding:20px; font-family:sans-serif;'>Meter not found in the database.</div>");
        }

        // Property access: restricted users may only edit meters of their own properties (or unassigned meters)
        $lum_allowed = (!empty($_SESSION['assigned_properties']) ? array_map('trim', explode(',', $_SESSION['assigned_properties'])) : null);
        if ($lum_allowed !== null && !empty($meter['meter_property']) && !in_array($meter['meter_property'], $lum_allowed, true)) {
            http_response_code(403);
            die("<div style='color:white; padding:20px; font-family:sans-serif;'>You do not have access to this meter.</div>");
        }

        if (empty((float)$meter['meter_area']) && !empty($meter['meter_tenant'])) {
            $ts = $tenant_db_conn->prepare("SELECT tenant_shop_area FROM lum_tenants WHERE tenant_property = ? AND (tenant_name = ? OR tenant_shop = ?) LIMIT 1");
            $ts->execute([$meter['meter_property'], $meter['meter_tenant'], $meter['meter_shop']]);
            $area = $ts->fetchColumn();
            if ($area) $meter['meter_area'] = $area;
        }

        if (empty((float)$meter['meter_area_02']) && !empty($meter['meter_tenant_02'])) {
            $ts = $tenant_db_conn->prepare("SELECT tenant_shop_area FROM lum_tenants WHERE tenant_property = ? AND (tenant_name = ? OR tenant_shop = ?) LIMIT 1");
            $ts->execute([$meter['meter_property'], $meter['meter_tenant_02'], $meter['meter_shop_02']]);
            $area = $ts->fetchColumn();
            if ($area) $meter['meter_area_02'] = $area;
        }

        if (empty((float)$meter['meter_area_03']) && !empty($meter['meter_tenant_03'])) {
            $ts = $tenant_db_conn->prepare("SELECT tenant_shop_area FROM lum_tenants WHERE tenant_property = ? AND (tenant_name = ? OR tenant_shop = ?) LIMIT 1");
            $ts->execute([$meter['meter_property'], $meter['meter_tenant_03'], $meter['meter_shop_03']]);
            $area = $ts->fetchColumn();
            if ($area) $meter['meter_area_03'] = $area;
        }
        
    } catch (PDOException $e) {
        error_log('LUM meter-update-form.php: ' . $e->getMessage()); die("<div style='color:white; padding:20px; font-family:sans-serif;'>Database Error. Please contact the system administrator.</div>");
    }
} else {
    die("<div style='color:white; padding:20px; font-family:sans-serif;'>No Meter ID provided.</div>");
}

$init_tenants = 1;
if (!empty($meter['meter_tenant_03'])) { $init_tenants = 3; } 
elseif (!empty($meter['meter_tenant_02'])) { $init_tenants = 2; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Meter - Lynx Utility Management</title>
    
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
        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 14px; height: 14px; }
        ::-webkit-scrollbar-track { background: #0a0a0a; border-radius: 0; }
        ::-webkit-scrollbar-thumb { background: #4a4a4a; border-radius: 0; }
        body { background-color: #121212; color: #ffffff; padding-top: 0; overflow: hidden; /* Lock master scroll */ }
        .navbar { background-color: #000000; border-bottom: 1px solid #333333 !important; z-index: 1050; }
        .action-bar { background-color: #0a0a0a; border-bottom: 1px solid #333333; padding: 15px 0; color: #ffffff; }
        .form-container { background: linear-gradient(135deg, #222222, #2d2d2d); border: 1px solid #333333; color: #ffffff; padding: 35px; box-shadow: 0 12px 30px rgba(0, 0, 0, 0.5); position: relative;}
        .form-control, .form-select { background-color: #1a1a1a; border: 1px solid #444; color: #fff; padding: 12px; transition: all 0.3s ease; }
        .form-control:focus, .form-select:focus { background-color: #222222; border-color: #e3000f; color: #fff; box-shadow: 0 0 0 0.25rem rgba(227, 0, 15, 0.25); }
        /* Force label to white as requested */
        .form-label { color: #ffffff; font-size: 0.9rem; margin-bottom: 0.4rem; font-weight: 500 !important; }
        .btn-brand { background-color: #e3000f; color: #ffffff; border: none; padding: 12px 25px; letter-spacing: 0.5px; transition: 0.3s; }
        .btn-brand:hover { background-color: #bf000c; color: #ffffff; transform: translateY(-2px); }
        .section-title { border-bottom: 2px solid #e3000f; padding-bottom: 10px; margin-bottom: 25px; color: #fff; font-weight: bold; }
        /* Removed Spinner from Number Inputs */
        input[type=number]::-webkit-inner-spin-button, 
        input[type=number]::-webkit-outer-spin-button { 
            -webkit-appearance: none; 
            margin: 0; 
        }
        .autofill-highlight { animation: highlightPulse 2s ease-out forwards; }
        @keyframes highlightPulse {
            0% { box-shadow: 0 0 0 0 rgba(13, 202, 240, 0.7); border-color: #0dcaf0; }
        70% { box-shadow: 0 0 0 10px rgba(13, 202, 240, 0); border-color: #0dcaf0; }
        100% { box-shadow: 0 0 0 0 rgba(13, 202, 240, 0); border-color: #444; }
        }
        #lookup-spinner { display: none; position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: #0dcaf0; }
        .input-wrapper { position: relative; }
    </style>
</head>
<body>

<?php include LUM_ROOT . '/Layout/top-navbar.php'; ?>

<div class="page-wrapper">
    <div class="action-bar mb-5">
        <div class="container-fluid px-4">
            <div class="d-flex align-items-center">
                <a href="https://lynx-um.co.za/Meter Management/meter-overview.php" class="btn btn-brand btn-sm px-2 shadow-sm me-3">
                    Back
                </a>
                <span class="fs-6 text-white mb-0">Edit Meter Details</span>
            </div>
        </div>
    </div>

    <div class="container pb-5">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                
                <div class="form-container shadow-lg">
                    <h3 class="section-title"><i class="bi bi-pencil-square me-2"></i>Update Meter Info</h3>
                    
                    <form action="meter-update-form-submit.php" method="POST" id="meterUpdateForm">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="f-id" value="<?php echo htmlspecialchars($meter['meter_id'] ?? ''); ?>">

                        <?php 
                            $current_type = $meter['meter_type'] ?? ''; 
                            $is_water = (stripos($current_type, 'Water') !== false || in_array($current_type, $water_types));
                            $cat_value = $is_water ? 'water' : 'electrical';
                            $curr_brand = $meter['meter_brand'] ?? '';
                        ?>

                        <div class="row g-4 mb-4 pb-4 border-bottom border-secondary">
                            <div class="col-md-6">
                                <label class="form-label">Meter Category</label>
                                <select class="form-select" id="utility_category" name="utility_category" onchange="toggleUtilityFields()">
                                    <option value="electrical" <?php echo $cat_value === 'electrical' ? 'selected' : ''; ?>>Electrical Meter</option>
                                    <option value="water" <?php echo $cat_value === 'water' ? 'selected' : ''; ?>>Water Meter</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="meter_property" class="form-label">Property</label>
                                <?php $current_prop = $meter['meter_property'] ?? ''; ?>
                                <select class="form-select" id="f-meter_property" name="f-meter_property" required>
                                    <option value="" disabled <?php echo empty($current_prop) ? 'selected' : ''; ?>></option>
                                    <?php 
                                    if (!empty($_SESSION['assigned_properties'])) {
                                        $allowed = explode(',', $_SESSION['assigned_properties']);
                                        $all_properties = array_intersect($all_properties, $allowed);
                                    }
                                    foreach($all_properties as $prop) {
                                        $sel = ($current_prop === $prop) ? 'selected' : '';
                                        echo '<option value="' . htmlspecialchars($prop) . '" ' . $sel . '>' . htmlspecialchars($prop) . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <div id="dynamic_form_content" style="display: none;">
                            
                            <div class="row g-4 mb-4 pb-4 border-bottom border-secondary">
                                
                                <div class="col-md-6 transition-all" id="meter_serial_container">
                                    <label for="meter_serial" class="form-label">Meter Serial Number</label>
                                    <div class="input-wrapper">
                                        <input type="text" class="form-control" id="f-meter_serial" name="f-meter_serial" value="<?php echo htmlspecialchars($meter['meter_serial'] ?? ''); ?>" required>
                                        <div id="lookup-spinner" class="spinner-border spinner-border-sm" role="status">
                                            <span class="visually-hidden">Loading...</span>
                                        </div>
                                    </div>
                                    <small id="lookup-message" class="text-info mt-1 d-block" style="display:none;"></small>
                                </div>

                                <div class="col-md-6 transition-all" id="meter_ct_ratio_container">
                                    <label for="f-meter_ct_ratio" class="form-label">CT Ratio</label>
                                    <input type="number" step="0.01" min="0.01" class="form-control" id="f-meter_ct_ratio" name="f-meter_ct_ratio" value="<?php echo htmlspecialchars(number_format((float)($meter['ct_ratio'] ?? 1), 2, '.', '')); ?>">
                                    <a class="small text-info" href="https://lynx-um.co.za/Tenant Management/correct-ct-ratio.php">Correct a past ratio</a>
                                </div>

                                <div class="col-md-6 transition-all" id="meter_type_container">
                                    <label for="meter_type" class="form-label">Specific Meter Type</label>
                                    <select class="form-select" id="f-meter_type" name="f-meter_type" required>
                                    </select>
                                </div>

                                <div class="col-md-4 transition-all" id="meter_brand_container" style="display: none;">
                                    <label for="f-meter_brand" class="form-label" id="brand_label_icon">Meter Brand</label>
                                    <select class="form-select" id="f-meter_brand" name="f-meter_brand">
                                        <option value="" disabled <?php echo empty($curr_brand) ? 'selected' : ''; ?>></option>
                                    </select>
                                </div>
                            </div>

                            <div class="row g-4 mb-4 pb-4 border-bottom border-secondary">

                                <div class="col-md-12 transition-all" id="meter_desc_container">
                                    <label for="meter_rol" class="form-label">Meter Description</label>
                                    <input type="text" class="form-control" id="f-meter_rol" name="f-meter_rol" value="<?php echo htmlspecialchars($meter['meter_rol'] ?? ''); ?>">
                                </div>

                                <div class="col-md-4" id="electrical_mdb_container" style="display: none;">
                                    <label for="meter_MDB" class="form-label">Main Distribution Board (MDB)</label>
                                    <input type="text" class="form-control" id="f-meter_MDB" name="f-meter_MDB" value="<?php echo htmlspecialchars($meter['meter_MDB'] ?? ''); ?>">
                                </div>

                                <div class="col-md-4" id="electrical_db_container" style="display: none;">
                                    <label for="meter_DB" class="form-label">Sub Distribution Board (DB)</label>
                                    <input type="text" class="form-control" id="f-meter_DB" name="f-meter_DB" value="<?php echo htmlspecialchars($meter['meter_DB'] ?? ''); ?>">
                                </div>

                                <div class="col-md-12">
                                    <label for="meter_location" class="form-label">Specific Location</label>
                                    <input type="text" class="form-control" id="f-meter_location" name="f-meter_location" value="<?php echo htmlspecialchars($meter['meter_location'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="row g-4">
                                <div class="col-md-4">
                                    <label for="f-meter_tenant" class="form-label">Tenant Name (Primary)</label>
                                    <input type="text" class="form-control" id="f-meter_tenant" name="f-meter_tenant" value="<?php echo htmlspecialchars($meter['meter_tenant'] ?? ''); ?>">
                                </div>

                                <div class="col-md-4">
                                    <label for="f-meter_shop" class="form-label">Shop / Unit Number</label>
                                    <input type="text" class="form-control" id="f-meter_shop" name="f-meter_shop" value="<?php echo htmlspecialchars($meter['meter_shop'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-md-4">
                                    <label for="f-meter_area" class="form-label">Shop Area (m²)</label>
                                    <input type="number" step="0.01" class="form-control" id="f-meter_area" name="f-meter_area" value="<?php echo htmlspecialchars($meter['meter_area'] ?? ''); ?>">
                                </div>

                                <div class="col-12 mt-1">
                                    <button type="button" class="btn btn-sm btn-outline-info" id="add_tenant_btn" style="display: <?php echo $init_tenants == 3 ? 'none' : 'inline-block'; ?>;">
                                        <i class="bi bi-person-plus-fill me-1"></i> Add Additional Tenant
                                    </button>
                                </div>

                                <div class="col-md-4 tenant-2" style="display: <?php echo $init_tenants >= 2 ? 'block' : 'none'; ?>;">
                                    <label for="f-meter_tenant_02" class="form-label">Tenant Name (2)</label>
                                    <input type="text" class="form-control" id="f-meter_tenant_02" name="f-meter_tenant_02" value="<?php echo htmlspecialchars($meter['meter_tenant_02'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4 tenant-2" style="display: <?php echo $init_tenants >= 2 ? 'block' : 'none'; ?>;">
                                    <label for="f-meter_shop_02" class="form-label">Shop / Unit Number (2)</label>
                                    <input type="text" class="form-control" id="f-meter_shop_02" name="f-meter_shop_02" value="<?php echo htmlspecialchars($meter['meter_shop_02'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4 tenant-2" style="display: <?php echo $init_tenants >= 2 ? 'block' : 'none'; ?>;">
                                    <label for="f-meter_area_02" class="form-label">Shop Area (m²) (2)</label>
                                    <input type="number" step="0.01" class="form-control" id="f-meter_area_02" name="f-meter_area_02" value="<?php echo htmlspecialchars($meter['meter_area_02'] ?? ''); ?>">
                                </div>

                                <div class="col-md-4 tenant-3" style="display: <?php echo $init_tenants == 3 ? 'block' : 'none'; ?>;">
                                    <label for="f-meter_tenant_03" class="form-label">Tenant Name (3)</label>
                                    <input type="text" class="form-control" id="f-meter_tenant_03" name="f-meter_tenant_03" value="<?php echo htmlspecialchars($meter['meter_tenant_03'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4 tenant-3" style="display: <?php echo $init_tenants == 3 ? 'block' : 'none'; ?>;">
                                    <label for="f-meter_shop_03" class="form-label">Shop / Unit Number (3)</label>
                                    <input type="text" class="form-control" id="f-meter_shop_03" name="f-meter_shop_03" value="<?php echo htmlspecialchars($meter['meter_shop_03'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4 tenant-3" style="display: <?php echo $init_tenants == 3 ? 'block' : 'none'; ?>;">
                                    <label for="f-meter_area_03" class="form-label">Shop Area (m²) (3)</label>
                                    <input type="number" step="0.01" class="form-control" id="f-meter_area_03" name="f-meter_area_03" value="<?php echo htmlspecialchars($meter['meter_area_03'] ?? ''); ?>">
                                </div>
                                
                            </div>

                            <div class="mt-5 d-flex justify-content-end gap-3">
                                <a href="https://lynx-um.co.za/Meter Management/meter-overview.php" class="btn btn-outline-light px-4">Cancel</a>
                                <button type="submit" class="btn btn-brand" name="f-update-meter" id="f-update-meter"><i class="bi bi-check-circle me-2"></i>Update Meter</button>
                            </div>
                        </div>

                    </form>
                </div>
                
            </div>
        </div>
    </div>
</div>

    <?php include LUM_ROOT . '/Layout/foot-assets.php'; ?>
<script>
const electricalTypes = <?php echo json_encode($electrical_types); ?>;
const waterTypes = <?php echo json_encode($water_types); ?>;
const electricalBrands = <?php echo json_encode($electrical_brands); ?>;
const waterBrands = <?php echo json_encode($water_brands); ?>;
const tenantDataMap = <?php echo json_encode($tenant_data_map); ?>;

function autoFillArea(tenantInputId, shopInputId, areaInputId) {
    const tName = document.getElementById(tenantInputId).value.trim().toLowerCase();
    const tShop = document.getElementById(shopInputId).value.trim().toLowerCase();
    const tPropSelect = document.getElementById('f-meter_property');
    const tProp = tPropSelect.options[tPropSelect.selectedIndex].text;
    
    if ((!tName && !tShop) || tProp === "") return;
    
    const match = tenantDataMap.find(t => 
        t.tenant_property === tProp && 
        ( (tName && t.tenant_name.toLowerCase() === tName) || (tShop && t.tenant_shop.toLowerCase() === tShop) )
    );
    
    if (match && match.tenant_shop_area && parseFloat(match.tenant_shop_area) > 0) {
        document.getElementById(areaInputId).value = parseFloat(match.tenant_shop_area).toFixed(4);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    
    toggleUtilityFields();
    
    // Select the saved meter type (a type that is no longer on Meter Configs is still shown, so it can be kept or changed)
    const savedMeterType = <?php echo json_encode((string)$current_type, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

    if (savedMeterType) {
        const typeSelect = document.getElementById('f-meter_type');
        let optionExists = Array.from(typeSelect.options).some(opt => opt.value === savedMeterType);
        
        if (!optionExists) {
            typeSelect.add(new Option(savedMeterType, savedMeterType));
        }
        typeSelect.value = savedMeterType;
    }

    const savedMeterBrand = "<?php echo htmlspecialchars($curr_brand); ?>";
    if (savedMeterBrand) {
        const brandSelect = document.getElementById('f-meter_brand');
        let brandExists = Array.from(brandSelect.options).some(opt => opt.value === savedMeterBrand);
        
        if (brandExists) {
            brandSelect.value = savedMeterBrand;
        } else {
            brandSelect.innerHTML += `<option value="${savedMeterBrand}">${savedMeterBrand}</option>`;
            brandSelect.value = savedMeterBrand;
        }
    }

    let tenantCount = <?php echo $init_tenants; ?>;
    const addTenantBtn = document.getElementById('add_tenant_btn');
    if(addTenantBtn) {
        addTenantBtn.addEventListener('click', function() {
            if (tenantCount === 1) {
                document.querySelectorAll('.tenant-2').forEach(el => el.style.display = 'block');
                tenantCount++;
            } else if (tenantCount === 2) {
                document.querySelectorAll('.tenant-3').forEach(el => el.style.display = 'block');
                tenantCount++;
                this.style.display = 'none'; 
            }
        });
    }

    ['', '_02', '_03'].forEach(suffix => {
        const tInput = document.getElementById('f-meter_tenant' + suffix);
        const sInput = document.getElementById('f-meter_shop' + suffix);
        const aInput = document.getElementById('f-meter_area' + suffix);
        if (tInput && sInput && aInput) {
            tInput.addEventListener('input', () => autoFillArea(tInput.id, sInput.id, aInput.id));
            sInput.addEventListener('input', () => autoFillArea(tInput.id, sInput.id, aInput.id));
        }
    });

    // ==========================================
    // AJAX BACKEND SERIAL LOOKUP INTEGRATION
    // ==========================================
    const serialInput = document.getElementById('f-meter_serial');
    const spinner = document.getElementById('lookup-spinner');
    const messageDisplay = document.getElementById('lookup-message');
    const submitBtn = document.getElementById('f-update-meter');
    const currentId = document.querySelector('input[name="f-id"]').value;

    if (serialInput) {
        
        // --- Real-time clearing of error states when the user modifies the input ---
        serialInput.addEventListener('input', function() {
            messageDisplay.style.display = 'none';
            submitBtn.disabled = false;
        });

        serialInput.addEventListener('blur', function() {
            const serialValue = this.value.trim();
            
            if (serialValue === '') {
                messageDisplay.style.display = 'none';
                submitBtn.disabled = false;
                return;
            }

            spinner.style.display = 'inline-block';
            messageDisplay.style.display = 'none';
            messageDisplay.classList.remove('text-success', 'text-warning', 'text-danger');
            submitBtn.disabled = true; // Lock down submission

            // STEP 1: Strict Check for existing registration in lum_meters (Excluding current meter ID)
            fetch(window.location.pathname + `?check_exists=1&serial=${encodeURIComponent(serialValue)}&current_id=${encodeURIComponent(currentId)}`, { headers: { 'Accept': 'application/json' } })
                .then(response => response.json())
                .then(data => {
                    if (data.error) throw new Error(data.error);
                    
                    if (data.exists) {
                        spinner.style.display = 'none';
                        messageDisplay.innerHTML = '<i class="bi bi-x-circle-fill"></i> <strong>Meter Registered</strong> - This serial number already exists in the system.';
                        messageDisplay.classList.add('text-danger');
                        messageDisplay.style.display = 'block';
                        // Keep submitBtn disabled to protect data integrity
                    } else {
                        // STEP 2: Safe to proceed. Fetch Tenant Data for Auto-fill
                        fetchTenantData(serialValue);
                    }
                })
                .catch(error => {
                    spinner.style.display = 'none';
                    submitBtn.disabled = false; // Re-enable so they can try again or manually bypass a network error
                    messageDisplay.innerHTML = `<i class="bi bi-exclamation-triangle"></i> <strong>ERROR:</strong> ${error.message}`;
                    messageDisplay.classList.add('text-danger');
                    messageDisplay.style.display = 'block';
                });
        });
    }

    function fetchTenantData(serialValue) {
        fetch(window.location.pathname + `?ajax_lookup=1&serial=${encodeURIComponent(serialValue)}`, { headers: { 'Accept': 'application/json' } })
            .then(response => response.text())
            .then(text => {
                spinner.style.display = 'none';
                submitBtn.disabled = false; // Fully safe to submit
                
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    throw new Error(`Code Error: ${text}`);
                }

                if (data.error) {
                    throw new Error(data.error);
                }

                if (data.success) {
                    messageDisplay.innerHTML = '<i class="bi bi-check-circle-fill"></i> Tenant match found! Auto-filling data.';
                    messageDisplay.classList.add('text-success');
                    messageDisplay.style.display = 'block';

                    const targets = [];
                    
                    // SMART AUTO-FILL: Switch Category automatically based on the database response
                    if (data.matched_type && data.matched_type !== 'unknown') {
                        const catSelect = document.getElementById('utility_category');
                        if (catSelect && catSelect.value !== data.matched_type) {
                            catSelect.value = data.matched_type;
                            toggleUtilityFields(); 
                            targets.push(catSelect);
                        }
                    }

                    const propertySelect = document.getElementById('f-meter_property');
                    if (propertySelect && data.property) {
                        for (let i = 0; i < propertySelect.options.length; i++) {
                            if (propertySelect.options[i].text === data.property) {
                                propertySelect.selectedIndex = i;
                                targets.push(propertySelect);
                                break;
                            }
                        }
                    }

                    const tenantInput = document.getElementById('f-meter_tenant');
                    if (tenantInput && data.tenant) {
                        tenantInput.value = data.tenant;
                        targets.push(tenantInput);
                    }

                    const shopInput = document.getElementById('f-meter_shop');
                    if (shopInput && data.shop) {
                        shopInput.value = data.shop;
                        targets.push(shopInput);
                    }

                    const areaInput = document.getElementById('f-meter_area');
                    if (areaInput && data.area) {
                        areaInput.value = parseFloat(data.area).toFixed(4);
                        targets.push(areaInput);
                    }

                    targets.forEach(el => {
                        el.classList.remove('autofill-highlight');
                        void el.offsetWidth;
                        el.classList.add('autofill-highlight');
                    });

                }
            })
            .catch(error => {
                spinner.style.display = 'none';
                submitBtn.disabled = false; // Safe to submit if tenant lookup just fails natively
                messageDisplay.innerHTML = `<i class="bi bi-exclamation-triangle"></i> <strong>ERROR:</strong> ${error.message}`;
                messageDisplay.classList.add('text-danger');
                messageDisplay.style.display = 'block';
            });
    }
});

function toggleUtilityFields() {
    const category = document.getElementById('utility_category').value;
    const dynamicContent = document.getElementById('dynamic_form_content');
    
    const serialContainer = document.getElementById('meter_serial_container');
    const typeContainer = document.getElementById('meter_type_container');
    
    const brandContainer = document.getElementById('meter_brand_container');
    const brandLabelIcon = document.getElementById('brand_label_icon');
    
    const meterTypeSelect = document.getElementById('f-meter_type');
    const meterBrandSelect = document.getElementById('f-meter_brand');
    
    const descContainer = document.getElementById('meter_desc_container');
    const mdbContainer = document.getElementById('electrical_mdb_container');
    const dbContainer = document.getElementById('electrical_db_container');
    const mdbInput = document.getElementById('f-meter_MDB');
    const dbInput = document.getElementById('f-meter_DB');

    if (category !== "") {
        dynamicContent.style.display = 'block';
    }

    meterTypeSelect.innerHTML = '<option value="" disabled></option>';
    meterBrandSelect.innerHTML = '<option value="" disabled></option>';

    if (category === 'electrical') {
        serialContainer.classList.replace('col-md-6', 'col-md-4');
        typeContainer.classList.replace('col-md-6', 'col-md-4');
        
        brandContainer.style.display = 'block';
        brandLabelIcon.className = 'form-label';
        brandLabelIcon.innerHTML = 'Meter Brand';
        meterBrandSelect.className = 'form-select';
        
        descContainer.classList.replace('col-md-12', 'col-md-4');
        mdbContainer.style.display = 'block';
        dbContainer.style.display = 'block';
        
        electricalTypes.forEach(function(type) {
            meterTypeSelect.innerHTML += `<option value="Electrical - ${type}">Electrical - ${type}</option>`;
        });
        
        electricalBrands.forEach(function(brand) {
            meterBrandSelect.innerHTML += `<option value="${brand}">${brand}</option>`;
        });
    } 
    else if (category === 'water') {
        serialContainer.classList.replace('col-md-6', 'col-md-4');
        typeContainer.classList.replace('col-md-6', 'col-md-4');
        
        brandContainer.style.display = 'block';
        brandLabelIcon.className = 'form-label';
        brandLabelIcon.innerHTML = 'Meter Brand';
        meterBrandSelect.className = 'form-select';
        
        descContainer.classList.replace('col-md-4', 'col-md-12');
        mdbContainer.style.display = 'none';
        dbContainer.style.display = 'none';
        mdbInput.value = '';
        dbInput.value = '';
        
        waterTypes.forEach(function(type) {
            meterTypeSelect.innerHTML += `<option value="Water - ${type}">Water - ${type}</option>`;
        });
        
        waterBrands.forEach(function(brand) {
            meterBrandSelect.innerHTML += `<option value="${brand}">${brand}</option>`;
        });
    }
}
</script>
</body>
</html>