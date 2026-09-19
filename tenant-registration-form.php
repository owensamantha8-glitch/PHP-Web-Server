<?php
require_once '/var/www/Lynx/bootstrap.php';
lum_page('tenants', 'edit');

// Meter lookup (AJAX): is this serial already linked to a tenant?
if (isset($_GET['check_meter']) && isset($_GET['serial'])) {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');
    
    if (empty($_SESSION['user_name'])) {
        echo json_encode(['error' => 'Security Error: You are not logged in.']);
        exit();
    }

    $serial = trim($_GET['serial']);
    if (empty($serial)) {
        echo json_encode(['exists' => false]);
        exit();
    }
    
    try {
        $tenant_db_conn = lum_db('tenants');
        if (!$tenant_db_conn) throw new PDOException('sys_db_tenants is not available');
        
        $query = "SELECT tenant_property, tenant_name, tenant_shop 
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

        if ($tenantData) {
            // Property access: only name the tenant if this user may see that property
            $lum_allowed = (!empty($_SESSION['assigned_properties']) ? array_map('trim', explode(',', $_SESSION['assigned_properties'])) : null);
            if ($lum_allowed !== null && !in_array($tenantData['tenant_property'], $lum_allowed, true)) {
                echo json_encode(['exists' => true, 'property' => 'another property', 'tenant' => 'another tenant', 'shop' => '-']);
                exit();
            }
            echo json_encode([
                'exists'   => true,
                'property' => $tenantData['tenant_property'],
                'tenant'   => $tenantData['tenant_name'],
                'shop'     => $tenantData['tenant_shop']
            ]);
        } else {
            echo json_encode(['exists' => false]);
        }
    } catch (Exception $e) {
        error_log('LUM tenant-registration-form lookup failed: ' . $e->getMessage()); echo json_encode(['error' => 'Database error. Please try again.']);
    }
    exit();
}

lum_use('tenant_forms');

$all_properties = [];
try {
    $core_db_conn = lum_db('properties');
    if (!$core_db_conn) throw new PDOException('sys_db_properties is not available');
    
    $db_props_stmt = $core_db_conn->query("SELECT Property FROM lum_properties ORDER BY Property ASC");
    $all_properties = $db_props_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch(PDOException $e) {
    error_log('LUM tenant-registration-form: Core Database Connection failed: ' . $e->getMessage()); die("<div style='color:white; padding:20px; font-family: sans-serif;'>Core Database Connection failed. Please contact the system administrator.</div>");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tenant Registration - Lynx Utility Management</title>
    
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>

    <style>
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
            border-radius: 0 !important; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important;
        }
        ::-webkit-scrollbar-track { background: #0a0a0a; border-radius: 0; }
        ::-webkit-scrollbar-thumb { background: #4a4a4a; border-radius: 0; }
        /* Remove up/down arrows from number inputs */
        input[type=number]::-webkit-inner-spin-button, 
        input[type=number]::-webkit-outer-spin-button { 
            -webkit-appearance: none; 
            margin: 0; 
        }
        body { background-color: #121212; color: #ffffff; padding-top: 0; overflow: hidden; }
        .action-bar { background-color: #0a0a0a; border-bottom: 1px solid #333333; padding: 10px 0; color: #ffffff; z-index: 1040; position: relative; }
        .btn-outline-lynx { color: #ffffff; border: 1px solid #555555; background-color: transparent; transition: all 0.3s ease; }
        .main-content {
            height: calc(100vh - 98px);
            overflow-y: auto;
            width: 100%;
        }
        .form-container { background: linear-gradient(135deg, #222222, #2d2d2d); border: 1px solid #333333; color: #ffffff; padding: 35px; box-shadow: 0 12px 30px rgba(0, 0, 0, 0.5); }
        .form-control, .form-select { background-color: #1a1a1a; border: 1px solid #444; color: #fff; padding: 12px; transition: all 0.3s ease; }
        .form-control:focus, .form-select:focus { background-color: #222222; border-color: #e3000f; color: #fff; box-shadow: 0 0 0 0.25rem rgba(227, 0, 15, 0.25); }
        .form-label { color: #ffffff !important; font-size: 0.9rem; font-weight: 500; margin-bottom: 0.4rem; }
        .btn-brand { background-color: #e3000f; color: #ffffff; border: none; padding: 12px 25px; font-weight: 600; letter-spacing: 0.5px; transition: 0.3s; }
        .btn-brand:hover { background-color: #bf000c; color: #ffffff; transform: translateY(-2px); }
        .section-title { border-bottom: 2px solid #e3000f; padding-bottom: 10px; margin-bottom: 25px; color: #ffffff; font-weight: bold; }
        .subsection-title { color: #ffffff; font-size: 1.1rem; font-weight: 600; margin-top: 30px; margin-bottom: 15px; border-bottom: 1px solid #444; padding-bottom: 5px; }
    </style>
</head>
<body>

<?php include LUM_ROOT . '/Layout/top-navbar.php'; ?>

<div class="action-bar mb-0">
    <div class="container-fluid px-4">
        <div class="d-flex align-items-center">
            <a href="../tenant-overview.php" class="btn btn-brand btn-sm px-2 shadow-sm me-3"><i class="bi bi-arrow-left me-1"></i> Back</a>
            <span class="fs-6 mb-0 text-white fw-normal">Tenant Registration</span>
        </div>
    </div>
</div>

<div class="main-content">
    <div class="container pb-5 pt-5">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="form-container shadow-lg">
                    <h3 class="section-title"><i class="bi bi-person-plus me-2"></i>New Tenant Registration</h3>
                    
                    <form action="tenant-registration-form-submit.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        
                        <h5 class="subsection-title">Property & Shop Allocation</h5>
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label for="f-tenant_property" class="form-label">Property</label>
                                <select class="form-select" id="f-tenant_property" name="f-tenant_property" required>
                                    <option value="" selected disabled></option>
                                    <?php 
                                    if (!empty($_SESSION['assigned_properties'])) {
                                        $allowed = explode(',', $_SESSION['assigned_properties']);
                                        $all_properties = array_intersect($all_properties, $allowed);
                                    }
                                    foreach($all_properties as $prop) {
                                        echo '<option value="' . htmlspecialchars($prop) . '"' . lumTenantFormPropertyAttrs($prop) . '>' . htmlspecialchars($prop) . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="f-tenant_name" class="form-label">Tenant Name / Business</label>
                                <input type="text" class="form-control" id="f-tenant_name" name="f-tenant_name">
                            </div>
                            <div class="col-md-6">
                                <label for="f-tenant_code" class="form-label">Tenant Code</label>
                                <input type="text" class="form-control" id="f-tenant_code" name="f-tenant_code">
                            </div>
                            <div class="col-md-6">
                                <label for="f-tenant_shop" class="form-label">Shop Number</label>
                                <input type="text" class="form-control" id="f-tenant_shop" name="f-tenant_shop">
                            </div>
                            <div class="col-md-4">
                                <label for="f-tenant_shop_area" class="form-label">Shop Area (m&sup2;)</label>
                                <input type="number" step="0.01" class="form-control" id="f-tenant_shop_area" name="f-tenant_shop_area">
                            </div>
                            <div class="col-md-4">
                                <label for="f-tenant_comm_area" class="form-label">Common Area Allocation (%)</label>
                                <input type="number" step="0.01" class="form-control" id="f-tenant_comm_area" name="f-tenant_comm_area">
                            </div>
                            <div class="col-md-4">
                                <label for="f-tenant_amps" class="form-label">Amps</label>
                                <input type="number" step="0.01" class="form-control" id="f-tenant_amps" name="f-tenant_amps">
                            </div>
                            
                            <div class="col-md-6">
                                <label for="f-tenant_pays_water_commArea" class="form-label text-white">Pays Water Common Area</label>
                                <select class="form-select" id="f-tenant_pays_water_commArea" name="f-tenant_pays_water_commArea" required>
                                    <option value="" selected disabled></option>
                                    <option value="Yes">Yes</option>
                                    <option value="No">No</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="f-tenant_pays_electrical_commArea" class="form-label text-white">Pays Elec. Common Area</label>
                                <select class="form-select" id="f-tenant_pays_electrical_commArea" name="f-tenant_pays_electrical_commArea" required>
                                    <option value="" selected disabled></option>
                                    <option value="Yes">Yes</option>
                                    <option value="No">No</option>
                                </select>
                            </div>
                        </div>

                        <h5 class="subsection-title">Tenant Contact & Occupancy Details</h5>
                        <div class="row g-4">
                            <div class="col-md-4">
                                <label for="f_tenant_onsite" class="form-label">Onsite Contact Name & Surname</label>
                                <input type="text" class="form-control" id="f_tenant_onsite" name="f_tenant_onsite">
                            </div>
                            <div class="col-md-4">
                                <label for="f_email" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="f_email" name="f_email">
                            </div>
                            <div class="col-md-4">
                                <label for="f_cell_number" class="form-label">Cell Number</label>
                                <input type="text" class="form-control" id="f_cell_number" name="f_cell_number">
                            </div>
                            <div class="col-md-6">
                                <label for="f_occupancy_start_date" class="form-label">Occupancy Start Date</label>
                                <input type="date" class="form-control" id="f_occupancy_start_date" name="f_occupancy_start_date">
                            </div>

                            <!-- Some tenants want water and electricity on separate slips: one

                                 tenant record, two documents, rather than registering them twice -->

                            <div class="col-md-4">

                                <label for="f_slip_layout" class="form-label">Consumption Slip</label>

                                <select class="form-select" id="f_slip_layout" name="f_slip_layout">

                                    <option value="combined" selected>One slip for everything</option>

                                    <option value="separate">Separate slips for electricity and water</option>

                                </select>

                            </div>

                            <div class="col-md-4">

                                <label for="f_water_slip_code" class="form-label">Water Slip Code <span class="text-secondary">(only if it differs)</span></label>

                                <input type="text" class="form-control" id="f_water_slip_code" name="f_water_slip_code" value="">

                            </div>

                            <div class="col-md-4">

                                <label for="f_water_slip_email" class="form-label">Water Slip Email <span class="text-secondary">(only if it differs)</span></label>

                                <input type="email" class="form-control" id="f_water_slip_email" name="f_water_slip_email" value="">

                            </div>
                            <div class="col-md-6">
                                <label for="f_occupancy_end_date" class="form-label">Occupancy End Date</label>
                                <input type="date" class="form-control" id="f_occupancy_end_date" name="f_occupancy_end_date">
                            </div>
                        </div>

                        <h5 class="subsection-title">Linked Meters (IDs)</h5>
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-white"><i class="bi bi-lightning-charge"></i> Electrical Meters & CT Ratios</label>
                                <div class="d-flex flex-column gap-2">
                                    <div id="wrapper_elec_01" class="d-flex flex-column gap-1">
                                        <div class="input-group">
                                            <input type="text" class="form-control meter-check-input" id="f-tenant_electricalMeter_01" name="f-tenant_electricalMeter_01">
                                            <input type="number" step="0.01" class="form-control" id="f-tenant_electricalMeter_01_ct_ratio" name="f-tenant_electricalMeter_01_ct_ratio" style="max-width: 140px;">
                                        </div>
                                        <small id="msg-f-tenant_electricalMeter_01" style="display:none;"></small>
                                    </div>
                                    <div id="wrapper_elec_02" class="d-flex flex-column gap-1" style="display: none !important;">
                                        <div class="input-group">
                                            <input type="text" class="form-control meter-check-input" id="f-tenant_electricalMeter_02" name="f-tenant_electricalMeter_02">
                                            <input type="number" step="0.01" class="form-control" id="f-tenant_electricalMeter_02_ct_ratio" name="f-tenant_electricalMeter_02_ct_ratio" style="max-width: 140px;">
                                        </div>
                                        <small id="msg-f-tenant_electricalMeter_02" style="display:none;"></small>
                                    </div>
                                    <div id="wrapper_elec_03" class="d-flex flex-column gap-1" style="display: none !important;">
                                        <div class="input-group">
                                            <input type="text" class="form-control meter-check-input" id="f-tenant_electricalMeter_03" name="f-tenant_electricalMeter_03">
                                            <input type="number" step="0.01" class="form-control" id="f-tenant_electricalMeter_03_ct_ratio" name="f-tenant_electricalMeter_03_ct_ratio" style="max-width: 140px;">
                                        </div>
                                        <small id="msg-f-tenant_electricalMeter_03" style="display:none;"></small>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-danger mt-2 fw-bold" id="add_elec_btn"><i class="bi bi-plus-circle me-1"></i> Add Electrical Meter</button>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold text-white"><i class="bi bi-droplet"></i> Water Meters</label>
                                <div class="d-flex flex-column gap-2">
                                    <div id="wrapper_water_01" class="d-flex flex-column gap-1">
                                        <input type="text" class="form-control meter-check-input" id="f-tenant_waterMeter_01" name="f-tenant_waterMeter_01">
                                        <small id="msg-f-tenant_waterMeter_01" style="display:none;"></small>
                                    </div>
                                    <div id="wrapper_water_02" class="d-flex flex-column gap-1" style="display: none !important;">
                                        <input type="text" class="form-control meter-check-input" id="f-tenant_waterMeter_02" name="f-tenant_waterMeter_02">
                                        <small id="msg-f-tenant_waterMeter_02" style="display:none;"></small>
                                    </div>
                                    <div id="wrapper_water_03" class="d-flex flex-column gap-1" style="display: none !important;">
                                        <input type="text" class="form-control meter-check-input" id="f-tenant_waterMeter_03" name="f-tenant_waterMeter_03">
                                        <small id="msg-f-tenant_waterMeter_03" style="display:none;"></small>
                                    </div>
                                    <div id="wrapper_water_04" class="d-flex flex-column gap-1" style="display: none !important;">
                                        <input type="text" class="form-control meter-check-input" id="f-tenant_waterMeter_04" name="f-tenant_waterMeter_04">
                                        <small id="msg-f-tenant_waterMeter_04" style="display:none;"></small>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-info mt-2 fw-bold" id="add_water_btn"><i class="bi bi-plus-circle me-1"></i> Add Water Meter</button>
                            </div>
                        </div>

                        <h5 class="subsection-title">Primary Tariffs & Charges</h5>
                        <div class="row g-4">
                            <div class="col-md-4">
                                <label for="f-tenant_electrical_tariff_charge" class="form-label">Electrical Tariff Charge</label>
                                <select class="form-select" id="f-tenant_electrical_tariff_charge" name="f-tenant_electrical_tariff_charge">
                                    <?php lumTenantFormTariffOptions('electricity', null); ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="f-tenant_water_tariff_charge" class="form-label">Water Tariff Charge</label>
                                <select class="form-select" id="f-tenant_water_tariff_charge" name="f-tenant_water_tariff_charge">
                                    <?php lumTenantFormTariffOptions('water', null); ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="f-tenant_water_sewer_tariff_charge" class="form-label">Water Sewer Tariff Charge</label>
                                <select class="form-select" id="f-tenant_water_sewer_tariff_charge" name="f-tenant_water_sewer_tariff_charge">
                                    <?php lumTenantFormTariffOptions('sewer', null); ?>
                                </select>
                            </div>
                        </div>

                        <h5 class="subsection-title">Basic Charge Removal</h5>
                        <div class="row g-4">
                            <div class="col-md-4">
                                <label for="f-tenant_pays_electrical_basic_charge" class="form-label text-white">Pays Elec. Basic Charge</label>
                                <select class="form-select" id="f-tenant_pays_electrical_basic_charge" name="f-tenant_pays_electrical_basic_charge" required>
                                    <option value="" selected disabled></option>
                                    <option value="Yes">Yes</option>
                                    <option value="No">No</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="f-tenant_pays_water_basic_charge" class="form-label text-white">Pays Water Basic Charge</label>
                                <select class="form-select" id="f-tenant_pays_water_basic_charge" name="f-tenant_pays_water_basic_charge" required>
                                    <option value="" selected disabled></option>
                                    <option value="Yes">Yes</option>
                                    <option value="No">No</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="f-tenant_pays_sewer_basic_charge" class="form-label text-white">Pays Sewer Basic Charge</label>
                                <select class="form-select" id="f-tenant_pays_sewer_basic_charge" name="f-tenant_pays_sewer_basic_charge" required>
                                    <option value="" selected disabled></option>
                                    <option value="Yes">Yes</option>
                                    <option value="No">No</option>
                                </select>
                            </div>
                        </div>

                        <h5 class="subsection-title">Electrical Units Tariff Removal</h5>
                        <div class="row g-4">
                            <div class="col-md-4">
                                <label for="f-tenant_pays_electrical_tariff_charge" class="form-label text-white">Pays Electrical Unit Tariff</label>
                                <select class="form-select" id="f-tenant_pays_electrical_tariff_charge" name="f-tenant_pays_electrical_tariff_charge" required>
                                    <option value="" selected disabled></option>
                                    <option value="Yes">Yes</option>
                                    <option value="No">No</option>
                                </select>
                            </div>
                            <div class="col-md-4" id="container_pays_demand_charge" style="display: none;">
                                <label for="f-tenant_pays_demand_tariff_charge" class="form-label text-white">Pays Demand Charges</label>
                                <select class="form-select" id="f-tenant_pays_demand_tariff_charge" name="f-tenant_pays_demand_tariff_charge" required>
                                    <option value="" selected disabled></option>
                                    <option value="Yes">Yes</option>
                                    <option value="No">No</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="f-tenant_pays_generator_tariff_charge" class="form-label text-white">Pays Generator Unit Tariff</label>
                                <select class="form-select" id="f-tenant_pays_generator_tariff_charge" name="f-tenant_pays_generator_tariff_charge" required>
                                    <option value="" selected disabled></option>
                                    <option value="Yes">Yes</option>
                                    <option value="No">No</option>
                                </select>
                            </div>
                        </div>

                        <h5 class="subsection-title">Generator & Additional Settings</h5>
                        <div class="row g-4">
                            <div class="col-md-4">
                                <label for="f-tenant_generator_tariff_charge" class="form-label">Generator Tariff Charge</label>
                                <select class="form-select" id="f-tenant_generator_tariff_charge" name="f-tenant_generator_tariff_charge">
                                    <?php lumTenantFormTariffOptions('generator', null); ?>
                                </select>
                            </div>
                            <div class="col-md-4" id="container_refuse_charge" style="display: none;">
                                <label for="f-tenant_council_refuse_charge" class="form-label text-white">Council Refuse Recovery Charge (R)</label>
                                <input type="number" step="0.01" class="form-control" id="f-tenant_council_refuse_charge" name="f-tenant_council_refuse_charge">
                            </div>
                            
                            <!-- Shared network access charge fields (shown when the property charges it) -->
                            <div class="col-md-4" id="container_shared_nac_contribute" style="display: none;">
                                <label for="f_shared_nac_contribute" class="form-label text-white">Contributes to Shared NAC</label>
                                <select class="form-select" id="f_shared_nac_contribute" name="f_shared_nac_contribute">
                                    <option value="" selected disabled></option>
                                    <option value="Yes">Yes</option>
                                    <option value="No">No</option>
                                </select>
                            </div>
                            <div class="col-md-4" id="container_pays_shared_nac" style="display: none;">
                                <label for="f_pays_shared_nac" class="form-label text-white">Pays % of Shared NAC</label>
                                <select class="form-select" id="f_pays_shared_nac" name="f_pays_shared_nac">
                                    <option value="" selected disabled></option>
                                    <option value="Yes">Yes</option>
                                    <option value="No">No</option>
                                </select>
                            </div>
                        </div>

                        <div id="common_area_section" style="display: none;">
                            <h5 class="subsection-title">Common Area Settings</h5>
                            <div class="row g-4">
                                <div class="col-md-6" id="container_elec_comm" style="display: none;">
                                    <label for="f-tenant_electrical_commArea_charge" class="form-label">Electrical Common Area Charge</label>
                                    <select class="form-select" id="f-tenant_electrical_commArea_charge" name="f-tenant_electrical_commArea_charge">
                                        <?php lumTenantFormTariffOptions('elec_common', null); ?>
                                    </select>
                                </div>
                                <div class="col-md-6" id="container_water_comm" style="display: none;">
                                    <label for="f-tenant_water_commArea_charge" class="form-label">Water Common Area Charge</label>
                                    <select class="form-select" id="f-tenant_water_commArea_charge" name="f-tenant_water_commArea_charge">
                                        <?php lumTenantFormTariffOptions('water_common', null); ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="mt-5 d-flex justify-content-end gap-3">
                            <button type="reset" class="btn btn-outline-light px-4">Clear Form</button>
                            <button type="submit" class="btn btn-brand" name="f-register-tenant" id="f-register-tenant"><i class="bi bi-save me-2"></i>Register Tenant</button>
                        </div>

                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

    <?php include LUM_ROOT . '/Layout/foot-assets.php'; ?>

<script>
    // Escape text before inserting it into the page
    function lumEsc(v) {
        return String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // Prevent accidental scroll wheel changes on specific number inputs
    const lockScrollInputs = ['f-tenant_shop_area', 'f-tenant_comm_area', 'f-tenant_amps'];
    lockScrollInputs.forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('wheel', function(e) {
                e.preventDefault();
            }, { passive: false });
        }
    });

    let elecMeters = 1;
    document.getElementById('add_elec_btn').addEventListener('click', function() {
        if (elecMeters === 1) {
            document.getElementById('wrapper_elec_02').style.setProperty('display', 'flex', 'important');
            elecMeters++;
        } else if (elecMeters === 2) {
            document.getElementById('wrapper_elec_03').style.setProperty('display', 'flex', 'important');
            elecMeters++;
            this.style.display = 'none'; 
        }
    });

    let waterMeters = 1;
    document.getElementById('add_water_btn').addEventListener('click', function() {
        if (waterMeters === 1) {
            document.getElementById('wrapper_water_02').style.setProperty('display', 'flex', 'important');
            waterMeters++;
        } else if (waterMeters === 2) {
            document.getElementById('wrapper_water_03').style.setProperty('display', 'flex', 'important');
            waterMeters++;
        } else if (waterMeters === 3) {
            document.getElementById('wrapper_water_04').style.setProperty('display', 'flex', 'important');
            waterMeters++;
            this.style.display = 'none';
        }
    });

    const meterInputs = document.querySelectorAll('.meter-check-input');
    meterInputs.forEach(input => {
        input.addEventListener('input', function() {
            const msgEl = document.getElementById('msg-' + this.id);
            if(msgEl) {
                msgEl.style.display = 'none';
                if (this.value.trim() === '') {
                    msgEl.innerHTML = '';
                }
            }
        });

        input.addEventListener('blur', function() {
            const serialValue = this.value.trim();
            const msgEl = document.getElementById('msg-' + this.id);
            if (!msgEl) return;
            
            if (serialValue === '') {
                msgEl.style.display = 'none';
                msgEl.innerHTML = '';
                return;
            }

            msgEl.innerHTML = '<i class="bi bi-hourglass-split"></i> Checking meter database...';
            msgEl.className = 'mt-1 d-block text-info small';
            msgEl.style.display = 'block';

            fetch(window.location.pathname + `?check_meter=1&serial=${encodeURIComponent(serialValue)}`, { headers: { 'Accept': 'application/json' } })
                .then(response => response.json())
                .then(data => {
                    // Ignore the answer if the input changed while the lookup was running
                    if (input.value.trim() !== serialValue || input.value.trim() === '') {
                        msgEl.style.display = 'none';
                        msgEl.innerHTML = '';
                        return;
                    }

                    if (data.error) {
                        msgEl.innerHTML = `<i class="bi bi-exclamation-triangle"></i> Error: ${lumEsc(data.error)}`;
                        msgEl.className = 'mt-1 d-block text-danger small';
                        msgEl.style.display = 'block';
                    } else if (data.exists) {
                        msgEl.innerHTML = `<i class="bi bi-exclamation-triangle-fill"></i> Warning: Meter already linked to <strong class="text-warning">${lumEsc(data.tenant)}</strong> at <strong class="text-warning">${lumEsc(data.property)}</strong> (Shop <strong class="text-warning">${lumEsc(data.shop)}</strong>)`;
                        msgEl.className = 'mt-1 d-block text-warning small';
                        msgEl.style.display = 'block';
                    } else {
                        msgEl.style.display = 'none';
                        msgEl.innerHTML = '';
                    }
                })
                .catch(err => {
                    if (input.value.trim() === serialValue) {
                        msgEl.style.display = 'none';
                    }
                });
        });
    });

    const resetBtn = document.querySelector('button[type="reset"]');
    if (resetBtn) {
        resetBtn.addEventListener('click', function() {
            document.querySelectorAll('small[id^="msg-f-tenant_"]').forEach(el => {
                el.style.display = 'none';
                el.innerHTML = '';
            });
        });
    }

    const propSelect = document.getElementById('f-tenant_property');
    const contRefuse = document.getElementById('container_refuse_charge');
    const refuseInput = document.getElementById('f-tenant_council_refuse_charge');
    
    const contSharedNacContrib = document.getElementById('container_shared_nac_contribute');
    const contPaysSharedNac = document.getElementById('container_pays_shared_nac');
    const sharedNacContribInput = document.getElementById('f_shared_nac_contribute');
    const paysSharedNacInput = document.getElementById('f_pays_shared_nac');

    function togglePropertySpecifics() {
        // Property Billing Settings: council refuse charge / shared network access charge
        const propOpt = propSelect ? propSelect.options[propSelect.selectedIndex] : null;
        const hasRefuse = !!(propOpt && propOpt.dataset.refuse === '1');
        const hasNac = !!(propOpt && propOpt.dataset.nac === '1');

        if (hasRefuse) {
            contRefuse.style.display = 'block';
        } else {
            contRefuse.style.display = 'none';
            if (refuseInput) refuseInput.value = ''; 
        }

        if (hasNac) {
            if (contSharedNacContrib) contSharedNacContrib.style.display = 'block';
            if (contPaysSharedNac) contPaysSharedNac.style.display = 'block';
        } else {
            if (contSharedNacContrib) contSharedNacContrib.style.display = 'none';
            if (contPaysSharedNac) contPaysSharedNac.style.display = 'none';
            if (sharedNacContribInput) sharedNacContribInput.value = '';
            if (paysSharedNacInput) paysSharedNacInput.value = '';
        }
    }

    if (propSelect) {
        propSelect.addEventListener('change', togglePropertySpecifics);
        togglePropertySpecifics();
    }

    const paysElec = document.getElementById('f-tenant_pays_electrical_commArea');
    const paysWater = document.getElementById('f-tenant_pays_water_commArea');
    const secCommArea = document.getElementById('common_area_section');
    const contElecComm = document.getElementById('container_elec_comm');
    const contWaterComm = document.getElementById('container_water_comm');
    
    const elecTariff = document.getElementById('f-tenant_electrical_tariff_charge');
    const waterTariff = document.getElementById('f-tenant_water_tariff_charge');
    const sewerTariff = document.getElementById('f-tenant_water_sewer_tariff_charge');
    const genTariff = document.getElementById('f-tenant_generator_tariff_charge');
    const elecCommTariff = document.getElementById('f-tenant_electrical_commArea_charge');
    const waterCommTariff = document.getElementById('f-tenant_water_commArea_charge');
    
    const demandChargeContainer = document.getElementById('container_pays_demand_charge');
    const demandChargeSelect = document.getElementById('f-tenant_pays_demand_tariff_charge');

    function toggleDemandCharge() {
        // Shown for electricity tariffs with a demand or network access (kVA) charge
        const elecOpt = elecTariff.options[elecTariff.selectedIndex];
        if (elecOpt && elecOpt.dataset.demand === '1') {
            demandChargeContainer.style.display = 'block';
            if (!demandChargeSelect.value) demandChargeSelect.value = 'Yes';
        } else {
            demandChargeContainer.style.display = 'none';
            demandChargeSelect.value = 'Yes';
        }
    }

    function toggleCommonAreas() {
        const eVal = paysElec.value;
        const wVal = paysWater.value;

        if (eVal === 'Yes') {
            contElecComm.style.display = 'block';
        } else {
            contElecComm.style.display = 'none';
            elecCommTariff.value = 'Not applicable';
        }

        if (wVal === 'Yes') {
            contWaterComm.style.display = 'block';
        } else {
            contWaterComm.style.display = 'none';
            waterCommTariff.value = 'Not applicable';
        }

        if (eVal === 'Yes' || wVal === 'Yes') {
            secCommArea.style.display = 'block';
        } else {
            secCommArea.style.display = 'none';
        }
    }

    // Default tariffs per municipality (the first tariff offered in each list, see tenant-form-options.php)
    const LUM_MUNI_DEFAULTS = <?php echo json_encode(lumTenantFormMunicipalityDefaults(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

    function syncMunicipalities() {
        const selected = elecTariff.value;
        if (!selected || selected === 'Not applicable') return;

        const elecOpt = elecTariff.options[elecTariff.selectedIndex];
        const d = (elecOpt && LUM_MUNI_DEFAULTS[elecOpt.dataset.muni]) || null;
        if (!d) return;
        const setIfEmpty = function (el, value) {
            if (el && value && el.value === 'Not applicable') el.value = value;
        };
        setIfEmpty(waterTariff, d.water);
        setIfEmpty(sewerTariff, d.sewer);
        setIfEmpty(document.getElementById('f-tenant_generator_tariff_charge'), d.generator);
        if (paysElec.value === 'Yes') setIfEmpty(elecCommTariff, d.elec_common);
        if (paysWater.value === 'Yes') setIfEmpty(waterCommTariff, d.water_common);
    }

    elecTariff.addEventListener('change', function() {
        syncMunicipalities();
        toggleDemandCharge();
    });
    
    paysElec.addEventListener('change', function() {
        toggleCommonAreas();
        syncMunicipalities();
    });
    
    paysWater.addEventListener('change', function() {
        toggleCommonAreas();
        syncMunicipalities(); 
    });
    
    toggleCommonAreas();
    toggleDemandCharge();
});
</script>
</body>
</html>