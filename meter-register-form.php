<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once __DIR__ . '/bootstrap.php';
lum_page('meters', 'edit');

// =========================================================================
// EMBEDDED AJAX ENDPOINT (Bypasses external file permission blocks)
// =========================================================================

// 1. Check if Meter is already registered
if (isset($_GET['check_exists']) && isset($_GET['serial'])) {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');
    
    if (empty($_SESSION['user_name'])) {
        echo json_encode(['error' => 'Security Error: You are not logged in.']);
        exit();
    }

    $serial = trim($_GET['serial']);
    
    try {
        $meter_db_conn = lum_db('meters'); // bootstrap.php
        if (!$meter_db_conn) {
            echo json_encode(['error' => 'Database error. Please try again.']);
            exit();
        }
        
        $stmt = $meter_db_conn->prepare("SELECT meter_id FROM lum_meters WHERE meter_serial = :s LIMIT 1");
        $stmt->execute(['s' => $serial]);
        $exists = $stmt->fetch() ? true : false;
        
        echo json_encode(['exists' => $exists]);
    } catch (Exception $e) {
        error_log('LUM meter-register-form.php lookup failed: ' . $e->getMessage()); echo json_encode(['error' => 'Database error. Please try again.']);
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

        // UPDATED: Now checks waterMeter_04 and dynamically returns the matched category
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
        error_log('LUM meter-register-form.php lookup failed: ' . $e->getMessage()); echo json_encode(['error' => 'Database error. Please try again.']);
    }
    exit(); 
}
// =========================================================================

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
    error_log('LUM meter-register-form.php: ' . $e->getMessage()); die("<div style='color:white; padding:20px; font-family:sans-serif;'>Database Connection error. Please contact the system administrator.</div>");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meter Registration - Lynx Utility Management</title>
    
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
                <span class="fs-6 text-white mb-0">Meter Registration</span>
            </div>
        </div>
    </div>

    <div class="container pb-5">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                
                <div class="form-container shadow-lg">
                    <h3 class="section-title"><i class="bi bi-speedometer2 me-2"></i>New Meter Registration</h3>
                    
                    <form action="meter-register-form-submit.php" method="POST" id="meterRegistrationForm">
                        
                        <div class="row g-4 mb-4 pb-4 border-bottom border-secondary">
                            <div class="col-md-6">
                                <label class="form-label">Meter Category</label>
                                <select class="form-select" id="utility_category" name="utility_category" onchange="toggleUtilityFields()">
                                    <option value="" selected disabled></option>
                                    <option value="electrical">Electrical</option>
                                    <option value="water">Water</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="meter_property" class="form-label">Property</label>
                                <select class="form-select" id="f-meter_property" name="f-meter_property" required>
                                    <option value="" selected disabled></option>
                                    <?php 
                                    if (!empty($_SESSION['assigned_properties'])) {
                                        $allowed = explode(',', $_SESSION['assigned_properties']);
                                        $all_properties = array_intersect($all_properties, $allowed);
                                    }
                                    foreach($all_properties as $prop) {
                                        echo '<option value="' . htmlspecialchars($prop) . '">' . htmlspecialchars($prop) . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <div id="dynamic_form_content" style="display: none;">

                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            
                            <div class="row g-4 mb-4 pb-4 border-bottom border-secondary">
                                
                                <div class="col-md-6 transition-all" id="meter_serial_container">
                                    <label for="meter_serial" class="form-label">Meter Serial Number</label>
                                    <div class="input-wrapper">
                                        <input type="text" class="form-control" id="f-meter_serial" name="f-meter_serial" required>
                                        <div id="lookup-spinner" class="spinner-border spinner-border-sm" role="status">
                                            <span class="visually-hidden">Loading...</span>
                                        </div>
                                    </div>
                                    <small id="lookup-message" class="text-info mt-1 d-block" style="display:none;"></small>
                                </div>

                                <div class="col-md-6 transition-all" id="meter_ct_ratio_container">
                                    <label for="f-meter_ct_ratio" class="form-label">CT Ratio</label>
                                    <input type="number" step="0.01" min="0.01" class="form-control" id="f-meter_ct_ratio" name="f-meter_ct_ratio" value="1.00">
                                </div>

                                <div class="col-md-6 transition-all" id="meter_type_container">
                                    <label for="meter_type" class="form-label">Specific Meter Type</label>
                                    <select class="form-select" id="f-meter_type" name="f-meter_type" required>
                                    </select>
                                </div>

                                <div class="col-md-4 transition-all" id="meter_brand_container" style="display: none;">
                                    <label for="f-meter_brand" class="form-label" id="brand_label_icon">Meter Brand</label>
                                    <select class="form-select" id="f-meter_brand" name="f-meter_brand">
                                        <option value="" selected disabled></option>
                                    </select>
                                </div>
                            </div>

                            <div class="row g-4 mb-4 pb-4 border-bottom border-secondary">
                                <div class="col-md-12 transition-all" id="meter_desc_container">
                                    <label for="meter_rol" class="form-label">Meter Description</label>
                                    <input type="text" class="form-control" id="f-meter_rol" name="f-meter_rol">
                                </div>

                                <div class="col-md-4" id="electrical_mdb_container" style="display: none;">
                                    <label for="meter_MDB" class="form-label">Main Distribution Board (MDB)</label>
                                    <input type="text" class="form-control" id="f-meter_MDB" name="f-meter_MDB">
                                </div>

                                <div class="col-md-4" id="electrical_db_container" style="display: none;">
                                    <label for="meter_DB" class="form-label">Sub Distribution Board (DB)</label>
                                    <input type="text" class="form-control" id="f-meter_DB" name="f-meter_DB">
                                </div>

                                <div class="col-md-12">
                                    <label for="meter_location" class="form-label">Specific Location</label>
                                    <input type="text" class="form-control" id="f-meter_location" name="f-meter_location">
                                </div>
                            </div>

                            <div class="row g-4">
                                <div class="col-md-4">
                                    <label for="f-meter_tenant" class="form-label">Tenant Name (Primary)</label>
                                    <input type="text" class="form-control" id="f-meter_tenant" name="f-meter_tenant">
                                </div>

                                <div class="col-md-4">
                                    <label for="f-meter_shop" class="form-label">Shop / Unit Number</label>
                                    <input type="text" class="form-control" id="f-meter_shop" name="f-meter_shop">
                                </div>
                                
                                <div class="col-md-4">
                                    <label for="f-meter_area" class="form-label">Shop Area (m²)</label>
                                    <input type="number" step="0.01" class="form-control" id="f-meter_area" name="f-meter_area">
                                </div>

                                <div class="col-12 mt-1">
                                    <button type="button" class="btn btn-sm btn-outline-info" id="add_tenant_btn"><i class="bi bi-person-plus-fill me-1"></i> Add Additional Tenant</button>
                                </div>

                                <div class="col-md-4 tenant-2" style="display: none;">
                                    <label for="f-meter_tenant_02" class="form-label">Tenant Name (2)</label>
                                    <input type="text" class="form-control" id="f-meter_tenant_02" name="f-meter_tenant_02">
                                </div>
                                <div class="col-md-4 tenant-2" style="display: none;">
                                    <label for="f-meter_shop_02" class="form-label">Shop / Unit Number (2)</label>
                                    <input type="text" class="form-control" id="f-meter_shop_02" name="f-meter_shop_02">
                                </div>
                                <div class="col-md-4 tenant-2" style="display: none;">
                                    <label for="f-meter_area_02" class="form-label">Shop Area (m²) (2)</label>
                                    <input type="number" step="0.01" class="form-control" id="f-meter_area_02" name="f-meter_area_02">
                                </div>

                                <div class="col-md-4 tenant-3" style="display: none;">
                                    <label for="f-meter_tenant_03" class="form-label">Tenant Name (3)</label>
                                    <input type="text" class="form-control" id="f-meter_tenant_03" name="f-meter_tenant_03">
                                </div>
                                <div class="col-md-4 tenant-3" style="display: none;">
                                    <label for="f-meter_shop_03" class="form-label">Shop / Unit Number (3)</label>
                                    <input type="text" class="form-control" id="f-meter_shop_03" name="f-meter_shop_03">
                                </div>
                                <div class="col-md-4 tenant-3" style="display: none;">
                                    <label for="f-meter_area_03" class="form-label">Shop Area (m²) (3)</label>
                                    <input type="number" step="0.01" class="form-control" id="f-meter_area_03" name="f-meter_area_03">
                                </div>

                            </div>

                            <div class="mt-5 d-flex justify-content-end gap-3">
                                <button type="reset" class="btn btn-outline-light px-4" onclick="clearVisuals()">Clear Form</button>
                                <button type="submit" class="btn btn-brand" name="register-meter" id="register-meter"><i class="bi bi-save me-2"></i>Register Meter</button>
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

function clearVisuals() {
    document.getElementById('dynamic_form_content').style.display='none';
    document.getElementById('lookup-message').innerText = '';
    document.getElementById('register-meter').disabled = false;
    
    const fieldsToReset = ['f-meter_property', 'f-meter_tenant', 'f-meter_shop', 'f-meter_area', 'utility_category'];
    fieldsToReset.forEach(id => {
        let el = document.getElementById(id);
        if(el) { el.classList.remove('autofill-highlight'); }
    });
}

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

    meterTypeSelect.innerHTML = '<option value="" selected disabled></option>';
    meterBrandSelect.innerHTML = '<option value="" selected disabled></option>';

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

document.addEventListener('DOMContentLoaded', function() {
    let tenantCount = 1;
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

    const serialInput = document.getElementById('f-meter_serial');
    const spinner = document.getElementById('lookup-spinner');
    const messageDisplay = document.getElementById('lookup-message');
    const submitBtn = document.getElementById('register-meter');

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

            // STEP 1: Strict Check for existing registration in lum_meters
            fetch(window.location.pathname + `?check_exists=1&serial=${encodeURIComponent(serialValue)}`, { headers: { 'Accept': 'application/json' } })
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
</script>
</body>
</html>