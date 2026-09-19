<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once '/var/www/Lynx/bootstrap.php';
lum_page('configs', 'edit');

// Properties table (bootstrap.php)
$core_db_conn = lum_db('properties', true);

$message = '';
$error = '';
$property_data = null;

// Audit trail (switches itself off if the logger is missing)
lum_use('audit');

// 1. Process Form Submission
// Billing settings (property-settings-setup.sql): lists and defaults come from the shared slip engine
lum_use('slips');
$billing_cols_ok = false;
try {
    $billing_cols_ok = (bool)$core_db_conn->query("SHOW COLUMNS FROM `lum_properties` LIKE 'elec_common_area_method'")->fetch();
} catch (Exception $e) {
    $billing_cols_ok = false;
}
$billing_cols = ['elec_common_area_method', 'water_common_area_method', 'default_generator_method', 'generator_offline_meter',
                 'generator_tariff_label', 'tiered_water_label', 'forced_elec_tariff', 'charges_shared_nac', 'charges_refuse',
                 'bills_sewer_common_area', 'billing_cycle_match'];

// Billing settings from the form (blank text is saved as '' so the setup script never fills it again)
function lumPropertyBillingFromPost() {
    $text = function ($key, $max) { return substr(trim((string)($_POST[$key] ?? '')), 0, $max); };
    $elec = (string)($_POST['elec_common_area_method'] ?? '');
    $water = (string)($_POST['water_common_area_method'] ?? '');
    return [
        'elec_common_area_method'  => isset(LUM_ELEC_COMMON_AREA_METHODS[$elec]) ? $elec : 'manual',
        'water_common_area_method' => isset(LUM_WATER_COMMON_AREA_METHODS[$water]) ? $water : 'manual',
        'default_generator_method' => (($_POST['default_generator_method'] ?? '') === 'runtime') ? 'runtime' : '1.1.1.8.2',
        'generator_offline_meter'  => $text('generator_offline_meter', 30),
        'generator_tariff_label'   => $text('generator_tariff_label', 100),
        'tiered_water_label'       => $text('tiered_water_label', 150),
        'forced_elec_tariff'       => $text('forced_elec_tariff', 150),
        'charges_shared_nac'       => !empty($_POST['charges_shared_nac']) ? 1 : 0,
        'charges_refuse'           => !empty($_POST['charges_refuse']) ? 1 : 0,
        'bills_sewer_common_area'  => !empty($_POST['bills_sewer_common_area']) ? 1 : 0,
        'billing_cycle_match'      => $text('billing_cycle_match', 100),
    ];
}
function lumPropertyBillingSave($pdo, $id, array $values) {
    $sets = implode(', ', array_map(function ($c) { return "`$c` = :$c"; }, array_keys($values)));
    $st = $pdo->prepare("UPDATE `lum_properties` SET $sets WHERE `id` = :id");
    $params = [':id' => $id];
    foreach ($values as $c => $v) $params[":$c"] = $v;
    $st->execute($params);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_property'])) {
    
    // --- VALIDATE CSRF TOKEN ---
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        die("<div style='color:white; padding:20px; background:#121212; height:100vh;'>Security Error: Invalid CSRF Token. Request Blocked.</div>");
    }

    $id = $_POST['property_id'] ?? null;
    $property_name = $_POST['property_name'] ?? '';
    $location = $_POST['location'] ?? '';
    $account_number = $_POST['account_number'] ?? '';
    $power_factor = $_POST['power_factor'] ?? 0.85;
    $municipality = $_POST['municipality'] ?? '';
    $electrical_billing_type = $_POST['electrical_billing_type'] ?? '';
    $shared_nac_kva = $_POST['shared_nac_kva'] ?? '';
    $property_area = $_POST['property_area'] ?? '';
    $property_owner_organization = $_POST['property_owner_organization'] ?? '';
    $property_owner_portfolio = $_POST['property_owner_portfolio'] ?? '';

    if (!empty($property_name)) {
        try {
            if ($id) {
                $audit_before = lum_audit_fetch_row($core_db_conn, 'lum_properties', 'id', $id);
                // Update existing property record in the database
                $update_stmt = $core_db_conn->prepare("UPDATE `lum_properties` SET `Property` = :prop, `Location` = :loc, `account_number` = :acc, `power_factor` = :pf, `municipality` = :muni, `electrical_billing_type` = :ebt, `shared_nac_kva` = :snk, `property_area` = :pa, `property_owner_organization` = :poo, `property_owner_portfolio` = :pop WHERE `id` = :id");
                $update_stmt->execute([
                    ':prop' => $property_name,
                    ':loc'  => $location,
                    ':acc'  => $account_number,
                    ':pf'   => $power_factor,
                    ':muni' => empty($municipality) ? null : $municipality,
                    ':ebt'  => empty($electrical_billing_type) ? null : $electrical_billing_type,
                    ':snk'  => ($shared_nac_kva === '') ? null : $shared_nac_kva,
                    ':pa'   => ($property_area === '') ? null : $property_area,
                    ':poo'  => empty($property_owner_organization) ? null : $property_owner_organization,
                    ':pop'  => empty($property_owner_portfolio) ? null : $property_owner_portfolio,
                    ':id'   => $id
                ]);
                if ($billing_cols_ok) lumPropertyBillingSave($core_db_conn, $id, lumPropertyBillingFromPost());
                $audit_after = lum_audit_fetch_row($core_db_conn, 'lum_properties', 'id', $id);
                lum_audit_log('UPDATE', 'property', $id, $property_name, $property_name, $audit_before, $audit_after);
            } else {
                // Insert a brand new property record
                $insert_stmt = $core_db_conn->prepare("INSERT INTO `lum_properties` (`Property`, `Location`, `account_number`, `power_factor`, `municipality`, `electrical_billing_type`, `shared_nac_kva`, `property_area`, `property_owner_organization`, `property_owner_portfolio`) VALUES (:prop, :loc, :acc, :pf, :muni, :ebt, :snk, :pa, :poo, :pop)");
                $insert_stmt->execute([
                    ':prop' => $property_name,
                    ':loc'  => $location,
                    ':acc'  => $account_number,
                    ':pf'   => $power_factor,
                    ':muni' => empty($municipality) ? null : $municipality,
                    ':ebt'  => empty($electrical_billing_type) ? null : $electrical_billing_type,
                    ':snk'  => ($shared_nac_kva === '') ? null : $shared_nac_kva,
                    ':pa'   => ($property_area === '') ? null : $property_area,
                    ':poo'  => empty($property_owner_organization) ? null : $property_owner_organization,
                    ':pop'  => empty($property_owner_portfolio) ? null : $property_owner_portfolio
                ]);
                $new_id = $core_db_conn->lastInsertId();
                if ($billing_cols_ok) lumPropertyBillingSave($core_db_conn, $new_id, lumPropertyBillingFromPost());
                $audit_after = lum_audit_fetch_row($core_db_conn, 'lum_properties', 'id', $new_id);
                lum_audit_log('INSERT', 'property', $new_id, $property_name, $property_name, null, $audit_after);
            }
            
            // Redirect back to configs on success to prevent form resubmission
            header("Location: view-configs.php?success=1");
            exit();
        } catch (Exception $e) {
            error_log('LUM edit-property.php: Failed to save property: ' . $e->getMessage());
            $error = "Failed to save property. Please try again or contact the system administrator.";
        }
    } else {
        $error = "Property Name cannot be empty.";
    }
}

// 2. Fetch Existing Data for the Form
$edit_id = $_GET['id'] ?? ($_POST['property_id'] ?? null);

if ($edit_id) {
    try {
        $fetch_stmt = $core_db_conn->prepare("SELECT * FROM `lum_properties` WHERE `id` = :id");
        $fetch_stmt->execute([':id' => $edit_id]);
        $property_data = $fetch_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$property_data) {
            $error = "Property record not found.";
        }
    } catch (Exception $e) {
        error_log('LUM edit-property.php: Failed to fetch property details: ' . $e->getMessage());
        $error = "Failed to fetch property details. Please try again or contact the system administrator.";
    }
} else {
    // Defaults for Add New Property
    $property_data = [
        'id' => '',
        'Property' => '',
        'Location' => '',
        'account_number' => '',
        'power_factor' => '0.85',
        'municipality' => '',
        'electrical_billing_type' => '',
        'shared_nac_kva' => '',
        'property_area' => '',
        'property_owner_organization' => '',
        'property_owner_portfolio' => '',
        // Billing settings of a new property: no special rules
        'elec_common_area_method' => 'manual',
        'water_common_area_method' => 'manual',
        'default_generator_method' => '1.1.1.8.2',
        'bills_sewer_common_area' => 1,
    ];
}

// Tariff names for the "forced tariff" suggestions
$catalog_elec_tariffs = [];
if (function_exists('lumTariffCatalog')) {
    foreach ((lumTariffCatalog()['electricity'] ?? []) as $cat_row) {
        if (!empty($cat_row['active'])) $catalog_elec_tariffs[] = $cat_row['tariff_name'];
    }
    sort($catalog_elec_tariffs);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $edit_id ? 'Edit Property' : 'Add Property'; ?> - Lynx Utility Management</title>
    
    <!-- Bootstrap 5 CSS -->
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>
    <!-- Bootstrap Icons -->

    <style>
        body {
            background-color: #121212;
            color: #ffffff;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        /* --- Navbar Styles --- */
        .lynx-brand-hover:hover { color: #e3000f !important; }
        /* --- Form Specific Styles --- */
        .form-container {
            background-color: #1e1e1e;
            border: 1px solid #333333;
            border-radius: 12px;
            padding: 30px;
            max-width: 600px;
            margin: 40px auto;
            box-shadow: 0 8px 16px rgba(0,0,0,0.4);
        }
        /* Custom Lynx Button */
        .btn-brand {
            background-color: #e3000f;
            color: #ffffff;
            border: none;
            transition: 0.3s;
            font-weight: 500;
        }
        .btn-brand:hover {
            background-color: #bf000c;
            color: #ffffff;
            transform: translateY(-1px);
        }
        /* Form Inputs */
        .form-label {
            color: #aaaaaa;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .form-control, .form-select {
            background-color: #121212 !important;
            border: 1px solid #444 !important;
            color: #fff !important;
            padding: 12px 15px;
            border-radius: 8px;
        }
        .form-control:focus, .form-select:focus {
            border-color: #e3000f !important;
            box-shadow: 0 0 0 0.25rem rgba(227, 0, 15, 0.25) !important;
        }
        .input-group-text {
            background-color: #2a2a2a;
            border: 1px solid #444;
            color: #aaaaaa;
        }
    </style>
</head>
<body>

    <!-- Top Navbar -->
    <nav class="navbar navbar-dark py-1 border-bottom" style="background-color: #000000; border-color: #333333 !important;">
        <div class="container-fluid px-3">
            <a class="navbar-brand fs-6 mb-0 text-white lynx-brand-hover transition-colors" href="view-configs.php" style="transition: color 0.2s ease-in-out;">
                <i class="bi bi-arrow-left me-2"></i>Back to Configurations
            </a>
            <div class="d-flex align-items-center">
                <div class="text-secondary small d-flex align-items-center">
                    <i class="bi bi-person-fill text-white me-2"></i>
                    <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container-fluid px-4">
        
        <?php if ($error): ?>
            <div class="alert alert-danger mt-4 mx-auto" style="max-width: 600px;">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($property_data): ?>
        <div class="form-container">
            <h4 class="mb-4 text-white border-bottom border-secondary pb-3">
                <i class="bi bi-building me-2" style="color: #e3000f;"></i>
                <?php echo $edit_id ? 'Edit Property Details' : 'Add New Property'; ?>
            </h4>

            <form action="edit-property.php" method="POST">
                
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <!-- Hidden ID Field -->
                <?php if ($edit_id): ?>
                    <input type="hidden" name="property_id" value="<?php echo htmlspecialchars($property_data['id']); ?>">
                <?php endif; ?>

                <div class="mb-4">
                    <label class="form-label">Property Name</label>
                    <input type="text" class="form-control" name="property_name" value="<?php echo htmlspecialchars($property_data['Property'] ?? ''); ?>" required placeholder="e.g., Thatchfield Centre">
                </div>
                
                <div class="mb-4">
                    <label class="form-label">Account Number</label>
                    <input type="text" class="form-control border-info" name="account_number" value="<?php echo htmlspecialchars($property_data['account_number'] ?? ''); ?>" placeholder="e.g., CBC-001">
                </div>

                <div class="row mb-4">
                    <div class="col-md-6 mb-3 mb-md-0">
                        <label class="form-label text-success">Owner Organization</label>
                        <input type="text" class="form-control border-success" name="property_owner_organization" value="<?php echo htmlspecialchars($property_data['property_owner_organization'] ?? ''); ?>" placeholder="e.g., Lynx Real Estate">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-success">Owner Portfolio</label>
                        <input type="text" class="form-control border-success" name="property_owner_portfolio" value="<?php echo htmlspecialchars($property_data['property_owner_portfolio'] ?? ''); ?>" placeholder="e.g., Commercial Retail">
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-md-6 mb-3 mb-md-0">
                        <label class="form-label">Location / City</label>
                        <input type="text" class="form-control" name="location" value="<?php echo htmlspecialchars($property_data['Location'] ?? ''); ?>" placeholder="e.g., Centurion">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-warning">Municipality</label>
                        <input type="text" class="form-control border-warning" name="municipality" value="<?php echo htmlspecialchars($property_data['municipality'] ?? ''); ?>" placeholder="e.g., City of Tshwane">
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-md-6 mb-3 mb-md-0">
                        <label class="form-label text-warning">Electrical Billing Type</label>
                        <select class="form-select border-warning" name="electrical_billing_type">
                            <option value="" class="text-muted">Select Billing Type...</option>
                            <option value="Pre-paid" <?php echo (isset($property_data['electrical_billing_type']) && $property_data['electrical_billing_type'] === 'Pre-paid') ? 'selected' : ''; ?>>Pre-paid</option>
                            <option value="Supplied" <?php echo (isset($property_data['electrical_billing_type']) && $property_data['electrical_billing_type'] === 'Supplied') ? 'selected' : ''; ?>>Supplied</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-warning">Shared NAC (kVA)</label>
                        <input type="number" step="0.0001" class="form-control border-warning" name="shared_nac_kva" value="<?php echo htmlspecialchars($property_data['shared_nac_kva'] ?? ''); ?>" placeholder="0.0000">
                    </div>
                </div>

                <div class="row mb-5">
                    <div class="col-md-6 mb-3 mb-md-0">
                        <label class="form-label">Power Factor (For Demand)</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lightning-charge"></i></span>
                            <input type="number" step="0.01" min="0.1" max="1.0" class="form-control" name="power_factor" value="<?php echo htmlspecialchars($property_data['power_factor'] ?? '0.85'); ?>" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-info">Property Area (m&sup2;)</label>
                        <input type="number" step="0.01" class="form-control border-info" name="property_area" value="<?php echo htmlspecialchars($property_data['property_area'] ?? ''); ?>" placeholder="0.00">
                    </div>
                </div>

                <!-- ================= BILLING SETTINGS (used by the consumption slips and reports) ================= -->
                <h5 class="text-white fw-normal border-bottom border-secondary pb-2 mb-3 mt-2"><i class="bi bi-sliders me-2" style="color: #e3000f;"></i>Billing Settings</h5>
                <?php if (!$billing_cols_ok): ?>
                    <div class="alert alert-warning small">The billing settings are not installed yet (run <strong>property-settings-setup.sql</strong>). Until then the billing engine uses the old property-name rules.</div>
                <?php else: ?>
                <?php $bv = function ($k, $d = '') use ($property_data) { return (isset($property_data[$k]) && $property_data[$k] !== null) ? $property_data[$k] : $d; }; ?>

                <div class="row mb-3">
                    <div class="col-md-6 mb-3 mb-md-0">
                        <label class="form-label">Electrical Common Area Calculation</label>
                        <select class="form-select" name="elec_common_area_method">
                            <?php foreach (LUM_ELEC_COMMON_AREA_METHODS as $mk => $ml): ?>
                                <option value="<?php echo $mk; ?>" <?php echo $bv('elec_common_area_method', 'manual') === $mk ? 'selected' : ''; ?>><?php echo htmlspecialchars($ml); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Water Common Area Calculation</label>
                        <select class="form-select" name="water_common_area_method">
                            <?php foreach (LUM_WATER_COMMON_AREA_METHODS as $mk => $ml): ?>
                                <option value="<?php echo $mk; ?>" <?php echo $bv('water_common_area_method', 'manual') === $mk ? 'selected' : ''; ?>><?php echo htmlspecialchars($ml); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-4 mb-3 mb-md-0">
                        <label class="form-label">Default Generator Method</label>
                        <select class="form-select" name="default_generator_method">
                            <option value="1.1.1.8.2" <?php echo $bv('default_generator_method', '1.1.1.8.2') !== 'runtime' ? 'selected' : ''; ?>>OBIS 1.1.1.8.2 Reading</option>
                            <option value="runtime" <?php echo $bv('default_generator_method') === 'runtime' ? 'selected' : ''; ?>>Run Time Calculation</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3 mb-md-0">
                        <label class="form-label">Generator Offline-Hours Meter</label>
                        <input type="text" class="form-control" name="generator_offline_meter" maxlength="30" value="<?php echo htmlspecialchars((string)$bv('generator_offline_meter')); ?>" placeholder="Meter serial (optional)">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Generator Tariff Name on Slip</label>
                        <input type="text" class="form-control" name="generator_tariff_label" maxlength="100" value="<?php echo htmlspecialchars((string)$bv('generator_tariff_label')); ?>" placeholder="Blank = &lt;municipality&gt; - Generator">
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-6 mb-3 mb-md-0">
                        <label class="form-label">Electrical Tariff for All Tenants</label>
                        <input type="text" class="form-control" name="forced_elec_tariff" maxlength="150" list="catalogElecTariffs" value="<?php echo htmlspecialchars((string)$bv('forced_elec_tariff')); ?>" placeholder="Blank = each tenant's own tariff">
                        <datalist id="catalogElecTariffs">
                            <?php foreach ($catalog_elec_tariffs as $ct): ?><option value="<?php echo htmlspecialchars($ct); ?>"></option><?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Tiered Water Tariff Name on Slip</label>
                        <input type="text" class="form-control" name="tiered_water_label" maxlength="150" value="<?php echo htmlspecialchars((string)$bv('tiered_water_label')); ?>" placeholder="Blank = the tenant's water tariff name">
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-md-6 mb-3 mb-md-0">
                        <label class="form-label">Billing Cycle Name Pattern</label>
                        <input type="text" class="form-control" name="billing_cycle_match" maxlength="100" value="<?php echo htmlspecialchars((string)$bv('billing_cycle_match')); ?>" placeholder="Blank = exact property name, e.g. %Linton%">
                    </div>
                    <div class="col-md-6 d-flex flex-column justify-content-end gap-1">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="charges_shared_nac" value="1" id="bsNac" <?php echo !empty($bv('charges_shared_nac', 0)) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="bsNac">Shared network access charge (uses Shared NAC kVA)</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="charges_refuse" value="1" id="bsRefuse" <?php echo !empty($bv('charges_refuse', 0)) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="bsRefuse">Council refuse charge on the slips</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="bills_sewer_common_area" value="1" id="bsSewer" <?php echo !empty($bv('bills_sewer_common_area', 1)) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="bsSewer">Common area sewer contribution on the slips</label>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="d-flex justify-content-end">
                    <a href="view-configs.php" class="btn btn-outline-secondary me-2 px-4 py-2">Cancel</a>
                    <button type="submit" name="update_property" class="btn btn-brand px-4 py-2">
                        <i class="bi bi-save me-2"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>

    </div>

    <!-- Bootstrap JS Bundle -->
    <?php include LUM_ROOT . '/Layout/foot-assets.php'; ?>
</body>
</html>