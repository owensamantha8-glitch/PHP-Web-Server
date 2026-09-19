<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once '/var/www/Lynx/bootstrap.php';
lum_page('tariffs', 'edit');
// This page does not log deprecation notices and notices (the rest of the error settings come from bootstrap.php)
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

lum_connect('tariffs');
lum_use('reporting');

// STRICT EXACT MATCH FETCH ENGINE (No Auto-Loading Historical Data)
function fetchExactTariff($pdo, $table, $pm, $py) {
    try {
        $sql = "SELECT * FROM `$table` 
                WHERE period_year = :y AND period_month = :m 
                LIMIT 1";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['y' => $py, 'm' => $pm]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? $result : [];
    } catch (PDOException $e) {
        return [];
    }
}

// Use Month/Year Period
$selected_period = $_GET['period'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$selected_period)) {
    $selected_period = date('Y-m');
}
$parts = explode('-', $selected_period);
$py = (int)$parts[0];
$pm = (int)$parts[1];

$active_tab = $_GET['tab'] ?? 'tshwane';
if (!in_array($active_tab, ['tshwane', 'ekurhuleni', 'rustenburg', 'mkhondo', 'plett', 'george'], true)) {
    $active_tab = 'tshwane';
}

// Tariff Move Over Feature Injection
$copy_from = $_GET['copy_from'] ?? null;
if ($copy_from !== null && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$copy_from)) {
    $copy_from = null;
}
if ($copy_from) {
    $copy_parts = explode('-', $copy_from);
    $copy_py = (int)$copy_parts[0];
    $copy_pm = (int)$copy_parts[1];
    
    // Only load the EXACT copied data for the active tab being edited
    if ($active_tab === 'tshwane') $t_tshwane = fetchExactTariff($tariff_db_conn, 'lum_tariffs_city_of_tshwane', $copy_pm, $copy_py);
    if ($active_tab === 'ekurhuleni') $t_ekur = fetchExactTariff($tariff_db_conn, 'lum_tarrifs_ekhurhuleni', $copy_pm, $copy_py);
    if ($active_tab === 'rustenburg') $t_rust = fetchExactTariff($tariff_db_conn, 'lum_tariffs_rustenburg', $copy_pm, $copy_py);
    if ($active_tab === 'mkhondo') $t_mkhondo = fetchExactTariff($tariff_db_conn, 'lum_tariffs_mkhondo', $copy_pm, $copy_py);
    if ($active_tab === 'plett') $t_plett = fetchExactTariff($tariff_db_conn, 'lum_tariffs_plett', $copy_pm, $copy_py);
    if ($active_tab === 'george') $t_george = fetchExactTariff($tariff_db_conn, 'lum_tariffs_george', $copy_pm, $copy_py);
} else {
    // Normal Load - Strictly fetch EXACT month only
    $t_tshwane = fetchExactTariff($tariff_db_conn, 'lum_tariffs_city_of_tshwane', $pm, $py);
    $t_ekur    = fetchExactTariff($tariff_db_conn, 'lum_tarrifs_ekhurhuleni', $pm, $py);
    $t_rust    = fetchExactTariff($tariff_db_conn, 'lum_tariffs_rustenburg', $pm, $py);
    $t_mkhondo = fetchExactTariff($tariff_db_conn, 'lum_tariffs_mkhondo', $pm, $py);
    $t_plett   = fetchExactTariff($tariff_db_conn, 'lum_tariffs_plett', $pm, $py);
    $t_george  = fetchExactTariff($tariff_db_conn, 'lum_tariffs_george', $pm, $py);
}

// Return a completely blank value if the rate doesn't exist
function v($array, $key) { 
    return (isset($array[$key]) && $array[$key] !== '' && $array[$key] !== null) ? htmlspecialchars($array[$key]) : ''; 
}

// Dynamic rendering functions explicitly locked to fs-6 fw-normal
function renderCategory($title) {
    echo "<tr style='background-color: #1a1a1a;'><td colspan='2' class='text-danger fs-6 fw-normal border-bottom border-secondary pt-3 pb-2'><i class='bi bi-bookmark-check-fill me-2'></i>" . htmlspecialchars($title) . "</td></tr>";
}

// --- WATER TIER BANDS (sys_db_tariffs.lum_water_tiers): labels follow the bands of the tariff month ---
$tier_month_end = date('Y-m-t', strtotime($selected_period . '-01'));
$tier_municipalities = ['rustenburg' => ['Rustenburg', 4], 'mkhondo' => ['Mkhondo', 5], 'plett' => ['Plettenberg Bay', 4], 'george' => ['George', 7]];
$tier_bands = [];
foreach ($tier_municipalities as $tier_tab => $tier_def) {
    $tier_bands[$tier_tab] = lumWaterTierBandsFixed($tier_def[0], $tier_def[1], $tier_month_end);
}
function tierLabel($tab, $n, $prefix = '') {
    global $tier_bands;
    return $prefix . 'Tier ' . $n . ' (' . lumWaterTierRange($tier_bands[$tab], $n - 1) . ')';
}
// Water tier band editor (saved with the rates by tariff-update-form-submit.php)
function renderTierBandEditor($tab) {
    global $tier_bands, $tier_municipalities, $selected_period, $tariff_db_conn;
    list($muni, $count) = $tier_municipalities[$tab];
    $bands = $tier_bands[$tab];
    $conr_sets = null;
    try {
        $st = $tariff_db_conn->prepare("SELECT DISTINCT DATE_FORMAT(effective_from, '%Y-%m-%d') FROM lum_water_tiers WHERE municipality = ? ORDER BY effective_from");
        $st->execute([$muni]);
        $conr_sets = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $conr_sets = null;
    }
    $month_label = date('F Y', strtotime($selected_period . '-01'));
    $fmt = function ($v) { return ($v === null) ? '' : rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.'); };
    echo "<div class='mt-4 p-3 border border-secondary tier-editor' data-tab='" . htmlspecialchars($tab) . "' style='background-color: #0d0d0d;'>";
    echo "<div class='text-danger fs-6 fw-normal mb-1'><i class='bi bi-bar-chart-steps me-2'></i>Water Tier Bands &mdash; " . htmlspecialchars($muni) . "</div>";
    echo "<div class='text-secondary small mb-3'>How many kL fall in each tier. Each tier uses its own rate above. ";
    if ($conr_sets === null) {
        echo "<span class='text-warning'>The tier table is not installed yet (run water-tiers-setup.sql) &mdash; the built-in bands are shown and cannot be changed here.</span>";
    } else {
        echo "Band changes: " . htmlspecialchars($conr_sets ? implode(', ', array_map(function ($d) { return $d === '2000-01-01' ? 'all months' : 'from ' . date('M Y', strtotime($d)); }, $conr_sets)) : 'none saved (built-in bands)') . ".";
    }
    echo "</div>";
    echo "<table class='table table-dark table-sm align-middle mb-3' style='max-width: 520px;'><thead><tr><th>Tier</th><th>From (kL)</th><th>Up to (kL)</th></tr></thead><tbody>";
    for ($i = 0; $i < $count; $i++) {
        $last = ($i === $count - 1);
        echo "<tr><td>Tier " . ($i + 1) . "</td><td class='tier-from text-secondary'></td><td>";
        if ($last) {
            echo "<span class='text-secondary'>no upper limit</span>";
        } else {
            echo "<input type='number' step='0.001' min='0.001' class='form-control form-control-sm tier-up' style='max-width: 140px;' name='tier_up_to[" . ($i + 1) . "]' value='" . htmlspecialchars($fmt($bands[$i]['up_to'])) . "'" . ($conr_sets === null ? ' disabled' : ' required') . ">";
        }
        echo "</td></tr>";
    }
    echo "</tbody></table>";
    if ($conr_sets !== null) {
        echo "<div class='d-flex flex-wrap align-items-center gap-2 small'><span class='text-secondary'>If the bands are changed, apply them to</span>";
        echo "<select name='tier_apply' class='form-select form-select-sm bg-dark text-white border-secondary' style='width: auto;'>";
        echo "<option value='month'>water periods from " . htmlspecialchars($month_label) . " onwards (until the next change)</option>";
        echo "<option value='all'>all months (replaces every other band change)</option>";
        echo "</select></div>";
        echo "<div class='text-warning small mt-2'>Changing the bands changes the water charges of every slip, report and recalculation for those months.</div>";
    }
    echo "</div>";
}

function renderInput($label, $name, $value) {
    echo "<tr>
        <td class='ps-4 align-middle'><span class='text-white fs-6 fw-normal'>" . htmlspecialchars($label) . "</span></td>
        <td class='text-end align-middle'>
            <div class='d-inline-flex align-items-center bg-white text-dark text-start px-2 py-1' style='width: 130px;'>
                <span class='fs-6 fw-normal' style='margin-right: 4px;'>R</span>
                <input type='number' step='0.0001' name='" . htmlspecialchars($name) . "' class='border-0 bg-transparent text-dark fs-6 fw-normal p-0 w-100' style='outline: none;' value='" . htmlspecialchars($value) . "'>
            </div>
        </td>
    </tr>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Global Tariff Update - Lynx Utility Management</title>
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>
    <style>
        /* Strict global override to match Report Settings typography and force sharp corners */
        * {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important;
            font-weight: normal !important;
            text-transform: none !important;
            border-radius: 0 !important;
        }
        /* Global Custom Scrollbar from Dashboard */
        ::-webkit-scrollbar { width: 14px; height: 14px; }
        ::-webkit-scrollbar-track { background: #0a0a0a; border-radius: 0; }
        ::-webkit-scrollbar-thumb { background: #4a4a4a; border-radius: 0; }
        body { background-color: #121212; color: #fff; padding-bottom: 50px; }
        .action-bar { background-color: #0a0a0a; padding: 15px 20px; border-bottom: 1px solid #333; margin-bottom: 30px; }
        .table-container { background-color: #000000; border: 1px solid #333333; overflow: hidden; margin-top: 20px; margin-bottom: 20px; }
        .table { margin-bottom: 0; color: #e0e0e0; }
        .table thead th { background-color: #1a1a1a; color: #ffffff; border-bottom: 2px solid #333333; font-size: 1rem; padding: 12px 16px; }
        .table tbody td { border-bottom: 1px solid #222222; vertical-align: middle; background-color: transparent; color: #cccccc; }
        .table tbody tr:hover td { background-color: #111111; }
        /* Remove arrows from number inputs for a cleaner look */
        input[type=number]::-webkit-inner-spin-button, 
        input[type=number]::-webkit-outer-spin-button { 
            -webkit-appearance: none; 
            margin: 0; 
        }
        .btn-brand { background-color: #e3000f; color: #ffffff; border: none; transition: 0.3s; }
        .btn-brand:hover { background-color: #bf000c; color: #ffffff; transform: translateY(-1px); }
    </style>
</head>
<body>

<?php include LUM_ROOT . '/Layout/top-navbar.php'; ?>

<div class="action-bar d-flex justify-content-between align-items-center shadow-sm">
    <div class="d-flex align-items-center">
        <!-- FIXED ROUTING RELATIVE PATH -->
        <a href="../view-tariffs.php?period=<?php echo urlencode($selected_period); ?>&tab=<?php echo urlencode($active_tab); ?>" class="btn btn-sm btn-outline-light py-0 me-3 fs-6 fw-normal">Back</a>
        <span class="mb-0 fs-6 fw-normal text-white">Global Tariff Management</span>
    </div>
    <div class="d-flex align-items-center bg-dark p-2 border border-secondary">
        <span class="text-muted fs-6 fw-normal ms-2 me-2">Target Tariff Period:</span>
        <span class="text-white fs-6 fw-normal me-2"><?php echo date('F Y', strtotime($selected_period . '-01')); ?></span>
    </div>
</div>

<div class="container-fluid px-4 pb-5">

    <?php if ($active_tab === 'tshwane'): ?>
        <!-- TSHWANE TAB -->
        <form action="tariff-update-form-submit.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="tariff_type" value="tshwane">
            <input type="hidden" name="period_selector" value="<?php echo htmlspecialchars($selected_period); ?>">
            
            <div class="bg-dark p-3 border border-secondary mb-3 d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <span class="text-white fs-6 fw-normal"><i class="bi bi-clipboard-data text-warning me-2"></i>Tariff Move Over</span><br>
                    <span class="text-muted fw-normal" style="font-size: 0.85rem;">Copy historical or future rates into <?php echo date('F Y', strtotime($selected_period . '-01')); ?></span>
                </div>
                <div class="d-flex align-items-center mt-2 mt-md-0">
                    <span class="text-white-50 fs-6 fw-normal me-2">Copy From:</span>
                    <!-- Removed HTML5 Max Attribute for Bi-Directional Copying -->
                    <input type="month" id="copy_period_tshwane" class="form-control form-control-sm bg-dark text-white border-secondary fs-6 fw-normal me-3" style="width: 160px;">
                    <button type="button" class="btn btn-outline-warning btn-sm fs-6 fw-normal px-3" onclick="copyTariffs('tshwane')">Load Rates</button>
                </div>
            </div>

            <div class="table-container shadow">
                <div class="p-3 border-bottom border-secondary d-flex justify-content-between align-items-center bg-dark">
                    <span class="text-white fs-6 fw-normal mb-0">City of Tshwane Tariffs</span>
                    <?php $ds_tshwane = $t_tshwane['demand_season'] ?? 'High'; ?>
                    <select name="demand_season" class="form-select form-select-sm bg-dark text-white border-secondary w-auto">
                        <option value="High" <?php echo $ds_tshwane === 'High' ? 'selected' : ''; ?>>High Demand</option>
                        <option value="Low" <?php echo $ds_tshwane === 'Low' ? 'selected' : ''; ?>>Low Demand</option>
                    </select>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-borderless align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th style="width: 70%;">Tariff Description</th>
                                <th class="text-end" style="width: 30%;">Applied Rate (ZAR)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            renderCategory('Time of Use (TOU)');
                            renderInput('Basic Charge', 'tou_charge_basic', v($t_tshwane, 'tshwane_tou_charge_basic'));
                            renderInput('Peak Unit Rate', 'tou_charge_peak', v($t_tshwane, 'tshwane_tou_charge_peak'));
                            renderInput('Standard Unit Rate', 'tou_charge_standard', v($t_tshwane, 'tshwane_tou_charge_standard'));
                            renderInput('Off-Peak Unit Rate', 'tou_charge_offpeak', v($t_tshwane, 'tshwane_tou_charge_offpeak'));
                            renderInput('kVA Demand Charge', 'tou_charge_kva', v($t_tshwane, 'tshwane_tou_charge_kva'));
                            
                            renderCategory('Prepaid Time of Use (TOU)');
                            renderInput('Basic Charge', 'prepaid_tou_charge_basic', v($t_tshwane, 'tshwane_prepaid_tou_charge_basic'));
                            renderInput('Peak Unit Rate', 'prepaid_tou_charge_peak', v($t_tshwane, 'tshwane_prepaid_tou_charge_peak'));
                            renderInput('Standard Unit Rate', 'prepaid_tou_charge_standard', v($t_tshwane, 'tshwane_prepaid_tou_charge_standard'));
                            renderInput('Off-Peak Unit Rate', 'prepaid_tou_charge_offpeak', v($t_tshwane, 'tshwane_prepaid_tou_charge_offpeak'));
                            renderInput('kVA Demand Charge', 'prepaid_tou_charge_kva', v($t_tshwane, 'tshwane_prepaid_tou_charge_kva'));
                            
                            renderCategory('Business Standard');
                            renderInput('Basic Charge', 'business_charge_basic', v($t_tshwane, 'tshwane_business_charge_basic'));
                            renderInput('Unit Rate', 'business_charge_unit', v($t_tshwane, 'tshwane_business_charge_unit'));
                            
                            renderCategory('Business (S)');
                            renderInput('Basic Charge', 'business_S_charge_basic', v($t_tshwane, 'tshwane_business_S_charge_basic'));
                            renderInput('Unit Rate', 'business_S_charge_unit', v($t_tshwane, 'tshwane_business_S_charge_unit'));
                            
                            renderCategory('Business (L)');
                            renderInput('Basic Charge', 'business_L_charge_basic', v($t_tshwane, 'tshwane_business_L_charge_basic'));
                            renderInput('Unit Rate', 'business_L_charge_unit', v($t_tshwane, 'tshwane_business_L_charge_unit'));
                            
                            renderCategory('Non-Domestic Three Phase Conventional');
                            renderInput('Basic Charge', 'non_domestic_three_phase_basic', v($t_tshwane, 'tshwane_non_domestic_three_phase_basic'));
                            renderInput('Unit Rate', 'non_domestic_three_phase', v($t_tshwane, 'tshwane_non_domestic_three_phase'));
                            
                            renderCategory('Low Voltage Demand');
                            renderInput('Basic Charge', 'low_voltage_demand_charge_basic', v($t_tshwane, 'tshwane_low_voltage_demand_charge_basic'));
                            renderInput('Unit Rate (kWh)', 'low_voltage_demand_charge_unit', v($t_tshwane, 'tshwane_low_voltage_demand_charge_unit'));
                            renderInput('Demand Charge (kVA)', 'low_voltage_demand_charge_kva', v($t_tshwane, 'tshwane_low_voltage_demand_charge_kva'));
                            
                            renderCategory('Low Voltage Demand Scale');
                            renderInput('Basic Charge', 'lvds_charge_basic', v($t_tshwane, 'tshwane_lvds_charge_basic'));
                            renderInput('Unit Rate (kWh)', 'lvds_charge_unit', v($t_tshwane, 'tshwane_lvds_charge_unit'));
                            renderInput('Demand Charge (kVA)', 'lvds_charge_kva', v($t_tshwane, 'tshwane_lvds_charge_kva'));
                            
                            renderCategory('Water & Recoveries');
                            renderInput('Water (Non-Domestic)', 'water_non_domestic', v($t_tshwane, 'tshwane_water_non_domestic'));
                            renderInput('Sewer Recovery', 'water_sewer', v($t_tshwane, 'tshwane_water_sewer'));
                            renderInput('Sewer Recovery (Business)', 'water_sewer_business', v($t_tshwane, 'tshwane_water_sewer_business'));
                            renderInput('Generator Run Time Rate', 'generator', v($t_tshwane, 'tshwane_generator'));
                            
                            renderCategory('Common Area Recovery');
                            renderInput('Electrical Allocation Rate', 'comm_area_electrical', v($t_tshwane, 'tshwane_comm_area_electrical'));
                            renderInput('Water & Sewer Allocation Rate', 'comm_area_water', v($t_tshwane, 'tshwane_comm_area_water'));
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="d-flex justify-content-end mt-4 mb-2">
                <button type="submit" class="btn btn-brand px-4 py-2 shadow-sm fs-6 fw-normal">Save Tshwane Rates</button>
            </div>
        </form>

    <?php elseif ($active_tab === 'ekurhuleni'): ?>
        <!-- EKURHULENI TAB -->
        <form action="tariff-update-form-submit.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="tariff_type" value="ekurhuleni">
            <input type="hidden" name="period_selector" value="<?php echo htmlspecialchars($selected_period); ?>">
            
            <div class="bg-dark p-3 border border-secondary mb-3 d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <span class="text-white fs-6 fw-normal"><i class="bi bi-clipboard-data text-warning me-2"></i>Tariff Move Over</span><br>
                    <span class="text-muted fw-normal" style="font-size: 0.85rem;">Copy historical or future rates into <?php echo date('F Y', strtotime($selected_period . '-01')); ?></span>
                </div>
                <div class="d-flex align-items-center mt-2 mt-md-0">
                    <span class="text-white-50 fs-6 fw-normal me-2">Copy From:</span>
                    <!-- Removed HTML5 Max Attribute for Bi-Directional Copying -->
                    <input type="month" id="copy_period_ekurhuleni" class="form-control form-control-sm bg-dark text-white border-secondary fs-6 fw-normal me-3" style="width: 160px;">
                    <button type="button" class="btn btn-outline-warning btn-sm fs-6 fw-normal px-3" onclick="copyTariffs('ekurhuleni')">Load Rates</button>
                </div>
            </div>

            <div class="table-container shadow">
                <div class="p-3 border-bottom border-secondary d-flex justify-content-between align-items-center bg-dark">
                    <span class="text-white fs-6 fw-normal mb-0">Ekurhuleni Tariffs</span>
                    <?php $ds_ekur = $t_ekur['demand_season'] ?? 'High'; ?>
                    <select name="demand_season" class="form-select form-select-sm bg-dark text-white border-secondary w-auto">
                        <option value="High" <?php echo $ds_ekur === 'High' ? 'selected' : ''; ?>>High Demand</option>
                        <option value="Low" <?php echo $ds_ekur === 'Low' ? 'selected' : ''; ?>>Low Demand</option>
                    </select>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-borderless align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th style="width: 70%;">Tariff Description</th>
                                <th class="text-end" style="width: 30%;">Applied Rate (ZAR)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            renderCategory('Tariff A');
                            renderInput('Basic Charge', 't_a_basic', v($t_ekur, 'ekhurhuleni_charge_tariff_a_basic'));
                            renderInput('Unit Rate', 't_a_unit', v($t_ekur, 'ekhurhuleni_charge_tariff_a_unit'));
                            renderInput('Capacity Charge', 't_a_cap', v($t_ekur, 'ekhurhuleni_charge_tariff_a_capacity'));
                            
                            renderCategory('Tariff B');
                            renderInput('Basic Charge', 't_b_basic', v($t_ekur, 'ekhurhuleni_charge_tariff_b_basic'));
                            renderInput('Unit Rate', 't_b_unit', v($t_ekur, 'ekhurhuleni_charge_tariff_b_unit'));
                            renderInput('Capacity Charge', 't_b_cap', v($t_ekur, 'ekhurhuleni_charge_tariff_b_capacity'));
                            
                            renderCategory('Tariff C');
                            renderInput('Basic Charge', 't_c_basic', v($t_ekur, 'ekhurhuleni_charge_tariff_c_basic'));
                            renderInput('Unit Rate', 't_c_unit', v($t_ekur, 'ekhurhuleni_charge_tariff_c_unit'));
                            renderInput('Capacity Charge', 't_c_cap', v($t_ekur, 'ekhurhuleni_charge_tariff_c_capacity'));
                            renderInput('Demand Charge', 't_c_demand', v($t_ekur, 'ekhurhuleni_charge_tariff_c_demand'));
                            renderInput('NAC Charge', 't_c_nac', v($t_ekur, 'ekhurhuleni_charge_tariff_c_nac'));
                            
                            renderCategory('Time of Use (TOU)');
                            renderInput('Basic Charge', 'tou_basic', v($t_ekur, 'ekhurhuleni_charge_tariff_tou_basic'));
                            renderInput('Peak Unit Rate', 'tou_peak', v($t_ekur, 'ekhurhuleni_charge_tariff_tou_peak'));
                            renderInput('Standard Unit Rate', 'tou_std', v($t_ekur, 'ekhurhuleni_charge_tariff_tou_standard'));
                            renderInput('Off-Peak Unit Rate', 'tou_off', v($t_ekur, 'ekhurhuleni_charge_tariff_tou_offpeak'));
                            renderInput('Demand Charge', 'tou_demand', v($t_ekur, 'ekhurhuleni_charge_tariff_tou_demand'));
                            renderInput('Demand NAC Charge', 'tou_demand_nac', v($t_ekur, 'ekhurhuleni_charge_tariff_tou_demand_nac'));
                            
                            renderCategory('Water & Recoveries');
                            renderInput('Water Rate', 't_water', v($t_ekur, 'ekhurhuleni_charge_tariff_water'));
                            renderInput('Sewer Recovery', 't_sewer', v($t_ekur, 'ekhurhuleni_charge_tariff_water_sewer'));
                            renderInput('Generator Run Time Rate', 'gen', v($t_ekur, 'ekhurhuleni_charge_generator'));
                            
                            renderCategory('Common Area Recovery');
                            renderInput('Electrical Allocation Rate', 'comn_elec', v($t_ekur, 'ekhurhuleni_charge_comn_area_elec'));
                            renderInput('Water & Sewer Allocation Rate', 'comn_water', v($t_ekur, 'ekhurhuleni_charge_comn_area_water'));
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="d-flex justify-content-end mt-4 mb-2">
                <button type="submit" class="btn btn-brand px-4 py-2 shadow-sm fs-6 fw-normal">Save Ekurhuleni Rates</button>
            </div>
        </form>

    <?php elseif ($active_tab === 'rustenburg'): ?>
        <!-- RUSTENBURG TAB -->
        <form action="tariff-update-form-submit.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="tariff_type" value="rustenburg">
            <input type="hidden" name="period_selector" value="<?php echo htmlspecialchars($selected_period); ?>">
            
            <div class="bg-dark p-3 border border-secondary mb-3 d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <span class="text-white fs-6 fw-normal"><i class="bi bi-clipboard-data text-warning me-2"></i>Tariff Move Over</span><br>
                    <span class="text-muted fw-normal" style="font-size: 0.85rem;">Copy historical or future rates into <?php echo date('F Y', strtotime($selected_period . '-01')); ?></span>
                </div>
                <div class="d-flex align-items-center mt-2 mt-md-0">
                    <span class="text-white-50 fs-6 fw-normal me-2">Copy From:</span>
                    <!-- Removed HTML5 Max Attribute for Bi-Directional Copying -->
                    <input type="month" id="copy_period_rustenburg" class="form-control form-control-sm bg-dark text-white border-secondary fs-6 fw-normal me-3" style="width: 160px;">
                    <button type="button" class="btn btn-outline-warning btn-sm fs-6 fw-normal px-3" onclick="copyTariffs('rustenburg')">Load Rates</button>
                </div>
            </div>

            <div class="table-container shadow">
                <div class="p-3 border-bottom border-secondary d-flex justify-content-between align-items-center bg-dark">
                    <span class="text-white fs-6 fw-normal mb-0">Rustenburg Tariffs</span>
                    <?php $ds_rust = $t_rust['demand_season'] ?? 'High'; ?>
                    <select name="demand_season" class="form-select form-select-sm bg-dark text-white border-secondary w-auto">
                        <option value="High" <?php echo $ds_rust === 'High' ? 'selected' : ''; ?>>High Demand</option>
                        <option value="Low" <?php echo $ds_rust === 'Low' ? 'selected' : ''; ?>>Low Demand</option>
                    </select>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-borderless align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th style="width: 70%;">Tariff Description</th>
                                <th class="text-end" style="width: 30%;">Applied Rate (ZAR)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            renderCategory('Non-Domestic Conventional');
                            renderInput('Basic Charge', 'r_nd_conv_basic', v($t_rust, 'rustenburg_non_domestic_conventional_basic_charge'));
                            renderInput('Unit Rate', 'r_nd_conv_unit', v($t_rust, 'rustenburg_non_domestic_conventional_unit'));
                            
                            renderCategory('Network Access & Shared');
                            renderInput('All Season Demand (400V)', 'r_all_season_demand', v($t_rust, 'rustenburg_all_season_network_demand_charge_bulk_and_rural_400V'));
                            renderInput('All Season Access (400V)', 'r_all_season_access', v($t_rust, 'rustenburg_all_season_network_access_charge_bulk_and_rural_400V'));
                            renderInput('Shared Network Access', 'r_shared_network', v($t_rust, 'rustenburg_shared_network_access_charge'));
                            
                            renderCategory('Bulk Supply & Rural 400V');
                            renderInput('Basic Charge', 'r_bulk_basic', v($t_rust, 'rustenburg_bulk_supply_and_rural_400V_basic_charge'));
                            renderInput('Unit Rate', 'r_bulk_unit', v($t_rust, 'rustenburg_bulk_supply_and_rural_400V_unit'));
                            
                            renderCategory('Water (Commercial Tiered)');
                            renderInput('Water Basic Charge', 'r_water_basic', v($t_rust, 'rustenburg_water_commercial_tiered_basic_charge'));
                            renderInput(tierLabel('rustenburg', 1, 'Water '), 'r_water_0_60', v($t_rust, 'rustenburg_water_commercial_tiered_0_60_charge'));
                            renderInput(tierLabel('rustenburg', 2, 'Water '), 'r_water_61_100', v($t_rust, 'rustenburg_water_commercial_tiered_61_100_charge'));
                            renderInput(tierLabel('rustenburg', 3, 'Water '), 'r_water_101_150', v($t_rust, 'rustenburg_water_commercial_tiered_101_150_charge'));
                            renderInput(tierLabel('rustenburg', 4, 'Water '), 'r_water_151_plus', v($t_rust, 'rustenburg_water_commercial_tiered_151_plus_charge'));
                            
                            renderCategory('Sewer Recovery');
                            renderInput('Sewer Basic Charge', 'r_sewer_basic', v($t_rust, 'rustenburg_water_sewer_basic_charge'));
                            
                            renderCategory('Generator & Comm Area');
                            renderInput('Generator Run Time Rate', 'r_gen', v($t_rust, 'rustenburg_generator_charge'));
                            renderInput('Electrical Allocation Rate', 'r_comm_elec', v($t_rust, 'rustenburg_comm_area_electrical'));
                            renderInput('Water & Sewer Allocation Rate', 'r_comm_water', v($t_rust, 'rustenburg_comm_area_water'));
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php renderTierBandEditor('rustenburg'); ?>
            <div class="d-flex justify-content-end mt-4 mb-2">
                <button type="submit" class="btn btn-brand px-4 py-2 shadow-sm fs-6 fw-normal">Save Rustenburg Rates</button>
            </div>
        </form>

    <?php elseif ($active_tab === 'mkhondo'): ?>
        <!-- MKHONDO TAB -->
        <form action="tariff-update-form-submit.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="tariff_type" value="mkhondo">
            <input type="hidden" name="period_selector" value="<?php echo htmlspecialchars($selected_period); ?>">

            <div class="bg-dark p-3 border border-secondary mb-3 d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <span class="text-white fs-6 fw-normal"><i class="bi bi-clipboard-data text-warning me-2"></i>Tariff Move Over</span><br>
                    <span class="text-muted fw-normal" style="font-size: 0.85rem;">Copy historical or future rates into <?php echo date('F Y', strtotime($selected_period . '-01')); ?></span>
                </div>
                <div class="d-flex align-items-center mt-2 mt-md-0">
                    <span class="text-white-50 fs-6 fw-normal me-2">Copy From:</span>
                    <!-- Removed HTML5 Max Attribute for Bi-Directional Copying -->
                    <input type="month" id="copy_period_mkhondo" class="form-control form-control-sm bg-dark text-white border-secondary fs-6 fw-normal me-3" style="width: 160px;">
                    <button type="button" class="btn btn-outline-warning btn-sm fs-6 fw-normal px-3" onclick="copyTariffs('mkhondo')">Load Rates</button>
                </div>
            </div>

            <div class="table-container shadow">
                <div class="p-3 border-bottom border-secondary d-flex justify-content-between align-items-center bg-dark">
                    <span class="text-white fs-6 fw-normal mb-0">Mkhondo Tariffs</span>
                    <?php $ds_mkhondo = $t_mkhondo['demand_season'] ?? 'High'; ?>
                    <select name="demand_season" class="form-select form-select-sm bg-dark text-white border-secondary w-auto">
                        <option value="High" <?php echo $ds_mkhondo === 'High' ? 'selected' : ''; ?>>High Demand</option>
                        <option value="Low" <?php echo $ds_mkhondo === 'Low' ? 'selected' : ''; ?>>Low Demand</option>
                    </select>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-borderless align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th style="width: 70%;">Tariff Description</th>
                                <th class="text-end" style="width: 30%;">Applied Rate (ZAR)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            renderCategory('Business (Less than 80 kVA)');
                            renderInput('Basic Charge', 'm_elec_business_less_80_basic', v($t_mkhondo, 'mkhondo_elec_business_less_80_basic'));
                            renderInput('Unit Rate (kWh)', 'm_elec_business_less_80_kwh', v($t_mkhondo, 'mkhondo_elec_business_less_80_kwh'));
                            
                            renderCategory('Business (More than 80 kVA)');
                            renderInput('Basic Charge', 'm_elec_business_more_80_basic', v($t_mkhondo, 'mkhondo_elec_business_more_80_basic'));
                            renderInput('Unit Rate (kWh)', 'm_elec_business_more_80_kwh', v($t_mkhondo, 'mkhondo_elec_business_more_80_kwh'));
                            
                            renderCategory('Industrial Small (Less than 50 kVA)');
                            renderInput('Basic Charge', 'm_elec_industrial_small_less_50_basic', v($t_mkhondo, 'mkhondo_elec_industrial_small_less_50_basic'));
                            renderInput('Unit Rate (kWh)', 'm_elec_industrial_small_less_50_kwh', v($t_mkhondo, 'mkhondo_elec_industrial_small_less_50_kwh'));
                            renderInput('Demand Charge (kVA)', 'm_elec_industrial_small_less_50_kva', v($t_mkhondo, 'mkhondo_elec_industrial_small_less_50_kva'));
                            
                            renderCategory('Industrial (More than 50 kVA)');
                            renderInput('Basic Charge', 'm_elec_industrial_more_50_basic', v($t_mkhondo, 'mkhondo_elec_industrial_more_50_basic'));
                            renderInput('Unit Rate (kWh)', 'm_elec_industrial_more_50_kwh', v($t_mkhondo, 'mkhondo_elec_industrial_more_50_kwh'));
                            renderInput('Demand Charge (kVA)', 'm_elec_industrial_more_50_kva', v($t_mkhondo, 'mkhondo_elec_industrial_more_50_kva'));
                            
                            renderCategory('Water (Tiered)');
                            renderInput('Water Basic Charge', 'm_water_business_basic', v($t_mkhondo, 'mkhondo_water_business_basic'));
                            renderInput(tierLabel('mkhondo', 1, 'Water '), 'm_water_tier_1', v($t_mkhondo, 'mkhondo_water_tier_1_0_to_6'));
                            renderInput(tierLabel('mkhondo', 2, 'Water '), 'm_water_tier_2', v($t_mkhondo, 'mkhondo_water_tier_2_7_to_20'));
                            renderInput(tierLabel('mkhondo', 3, 'Water '), 'm_water_tier_3', v($t_mkhondo, 'mkhondo_water_tier_3_21_to_40'));
                            renderInput(tierLabel('mkhondo', 4, 'Water '), 'm_water_tier_4', v($t_mkhondo, 'mkhondo_water_tier_4_41_to_60'));
                            renderInput(tierLabel('mkhondo', 5, 'Water '), 'm_water_tier_5', v($t_mkhondo, 'mkhondo_water_tier_5_above_60'));
                            
                            renderCategory('Sewer Fixed Charges');
                            renderInput('Standard Sewer', 'm_sewer_basic_mkhondo', v($t_mkhondo, 'mkhondo_sewer_basic_mkhondo'));
                            renderInput('Business M Sewer', 'm_sewer_basic_business_m', v($t_mkhondo, 'mkhondo_sewer_basic_business_m'));
                            renderInput('Business Large Sewer', 'm_sewer_basic_business_large', v($t_mkhondo, 'mkhondo_sewer_basic_business_large'));
                            
                            renderCategory('Generator & Comm Area');
                            renderInput('Generator Run Time Rate', 'm_elec_generator_rate', v($t_mkhondo, 'mkhondo_elec_generator_rate'));
                            renderInput('Comm. Generator Recovery Rate', 'm_comm_gen_elec', v($t_mkhondo, 'mkhondo_comm_gen_electricity'));
                            renderInput('Comm. Water Contribution Rate', 'm_comm_water', v($t_mkhondo, 'mkhondo_comm_water_contribution'));
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php renderTierBandEditor('mkhondo'); ?>
            <div class="d-flex justify-content-end mt-4 mb-2">
                <button type="submit" class="btn btn-brand px-4 py-2 shadow-sm fs-6 fw-normal">Save Mkhondo Rates</button>
            </div>
        </form>

    <?php elseif ($active_tab === 'plett'): ?>
        <!-- PLETTENBERG BAY TAB -->
        <form action="tariff-update-form-submit.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="tariff_type" value="plett">
            <input type="hidden" name="period_selector" value="<?php echo htmlspecialchars($selected_period); ?>">

            <div class="bg-dark p-3 border border-secondary mb-3 d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <span class="text-white fs-6 fw-normal"><i class="bi bi-clipboard-data text-warning me-2"></i>Tariff Move Over</span><br>
                    <span class="text-muted fw-normal" style="font-size: 0.85rem;">Copy historical or future rates into <?php echo date('F Y', strtotime($selected_period . '-01')); ?></span>
                </div>
                <div class="d-flex align-items-center mt-2 mt-md-0">
                    <span class="text-white-50 fs-6 fw-normal me-2">Copy From:</span>
                    <!-- Removed HTML5 Max Attribute for Bi-Directional Copying -->
                    <input type="month" id="copy_period_plett" class="form-control form-control-sm bg-dark text-white border-secondary fs-6 fw-normal me-3" style="width: 160px;">
                    <button type="button" class="btn btn-outline-warning btn-sm fs-6 fw-normal px-3" onclick="copyTariffs('plett')">Load Rates</button>
                </div>
            </div>

            <div class="table-container shadow">
                <div class="p-3 border-bottom border-secondary d-flex justify-content-between align-items-center bg-dark">
                    <span class="text-white fs-6 fw-normal mb-0">Plettenberg Bay Tariffs</span>
                    <?php $ds_plett = $t_plett['demand_season'] ?? 'High'; ?>
                    <select name="demand_season" class="form-select form-select-sm bg-dark text-white border-secondary w-auto">
                        <option value="High" <?php echo $ds_plett === 'High' ? 'selected' : ''; ?>>High Demand</option>
                        <option value="Low" <?php echo $ds_plett === 'Low' ? 'selected' : ''; ?>>Low Demand</option>
                    </select>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-borderless align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th style="width: 70%;">Tariff Description</th>
                                <th class="text-end" style="width: 30%;">Applied Rate (ZAR)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            renderCategory('1-Phase Electricity');
                            renderInput('1PH 15A - Basic', 'p_1ph_15a_basic', v($t_plett, 'plett_1ph_15a_basic'));
                            renderInput('1PH 15A - kWh', 'p_1ph_15a_kwh', v($t_plett, 'plett_1ph_15a_kwh'));
                            renderInput('1PH 30A - Basic', 'p_1ph_30a_basic', v($t_plett, 'plett_1ph_30a_basic'));
                            renderInput('1PH 30A - kWh', 'p_1ph_30a_kwh', v($t_plett, 'plett_1ph_30a_kwh'));
                            renderInput('1PH 40A - Basic', 'p_1ph_40a_basic', v($t_plett, 'plett_1ph_40a_basic'));
                            renderInput('1PH 40A - kWh', 'p_1ph_40a_kwh', v($t_plett, 'plett_1ph_40a_kwh'));
                            renderInput('1PH 60A - Basic', 'p_1ph_60a_basic', v($t_plett, 'plett_1ph_60a_basic'));
                            renderInput('1PH 60A - kWh', 'p_1ph_60a_kwh', v($t_plett, 'plett_1ph_60a_kwh'));
                            
                            renderCategory('3-Phase Electricity');
                            renderInput('3PH 60A - Basic', 'p_3ph_60a_basic', v($t_plett, 'plett_3ph_60a_basic'));
                            renderInput('3PH 60A - kWh', 'p_3ph_60a_kwh', v($t_plett, 'plett_3ph_60a_kwh'));
                            renderInput('3PH 60A/63A - Basic', 'p_3ph_60a_63a_basic', v($t_plett, 'plett_3ph_60a_63a_basic'));
                            renderInput('3PH 60A/63A - kWh', 'p_3ph_60a_63a_kwh', v($t_plett, 'plett_3ph_60a_63a_kwh'));
                            renderInput('3PH 100A - Basic', 'p_3ph_100a_basic', v($t_plett, 'plett_3ph_100a_basic'));
                            renderInput('3PH 100A - kWh', 'p_3ph_100a_kwh', v($t_plett, 'plett_3ph_100a_kwh'));
                            
                            renderCategory('LV Electricity');
                            renderInput('Basic Charge', 'p_lv_basic', v($t_plett, 'plett_lv_basic'));
                            renderInput('Energy Charge (kWh)', 'p_lv_kwh', v($t_plett, 'plett_lv_kwh'));
                            renderInput('Demand Charge (kVA)', 'p_lv_dem', v($t_plett, 'plett_lv_demand_kva'));
                            renderInput('Access Charge (kVA)', 'p_lv_acc', v($t_plett, 'plett_lv_access_kva'));

                            renderCategory('Bitou Municipality TOU');
                            renderInput('Basic Charge', 'b_tou_basic', v($t_plett, 'plett_bitou_tou_basic'));
                            renderInput('Peak Unit Rate', 'b_tou_peak', v($t_plett, 'plett_bitou_tou_peak'));
                            renderInput('Standard Unit Rate', 'b_tou_std', v($t_plett, 'plett_bitou_tou_standard'));
                            renderInput('Off-Peak Unit Rate', 'b_tou_off', v($t_plett, 'plett_bitou_tou_offpeak'));
                            renderInput('Demand Charge (kVA)', 'b_tou_dem', v($t_plett, 'plett_bitou_tou_demand_kva'));
                            renderInput('Network Access / Capacity (kVA)', 'b_tou_acc', v($t_plett, 'plett_bitou_tou_access_kva'));
                            
                            renderCategory('Water (SHOPS)');
                            renderInput('Basic Charge', 'w_shops_basic', v($t_plett, 'bitou_water_shops_basic'));
                            renderInput(tierLabel('plett', 1, ''), 'w_shops_t1', v($t_plett, 'bitou_water_shops_tier_1_0_60'));
                            renderInput(tierLabel('plett', 2, ''), 'w_shops_t2', v($t_plett, 'bitou_water_shops_tier_2_60_100'));
                            renderInput(tierLabel('plett', 3, ''), 'w_shops_t3', v($t_plett, 'bitou_water_shops_tier_3_100_200'));
                            renderInput(tierLabel('plett', 4, ''), 'w_shops_t4', v($t_plett, 'bitou_water_shops_tier_4_above_200'));
                            
                            renderCategory('Water (BUSS)');
                            renderInput('Basic Charge', 'w_buss_basic', v($t_plett, 'bitou_water_buss_basic'));
                            renderInput(tierLabel('plett', 1, ''), 'w_buss_t1', v($t_plett, 'bitou_water_buss_tier_1_0_60'));
                            renderInput(tierLabel('plett', 2, ''), 'w_buss_t2', v($t_plett, 'bitou_water_buss_tier_2_60_100'));
                            renderInput(tierLabel('plett', 3, ''), 'w_buss_t3', v($t_plett, 'bitou_water_buss_tier_3_100_200'));
                            renderInput(tierLabel('plett', 4, ''), 'w_buss_t4', v($t_plett, 'bitou_water_buss_tier_4_above_200'));
                            
                            renderCategory('Water (REST)');
                            renderInput('Basic Charge', 'w_rest_basic', v($t_plett, 'bitou_water_rest_basic'));
                            renderInput(tierLabel('plett', 1, ''), 'w_rest_t1', v($t_plett, 'bitou_water_rest_tier_1_0_60'));
                            renderInput(tierLabel('plett', 2, ''), 'w_rest_t2', v($t_plett, 'bitou_water_rest_tier_2_60_100'));
                            renderInput(tierLabel('plett', 3, ''), 'w_rest_t3', v($t_plett, 'bitou_water_rest_tier_3_100_200'));
                            renderInput(tierLabel('plett', 4, ''), 'w_rest_t4', v($t_plett, 'bitou_water_rest_tier_4_above_200'));
                            
                            renderCategory('Sewer Basic Charges');
                            renderInput('BITOU MUNICIPALITY - SEWER (BUS)', 's_bus_basic', v($t_plett, 'bitou_sewer_bus_basic'));
                            renderInput('BITOU MUNICIPALITY - SEWER (REST)', 's_rest_basic', v($t_plett, 'bitou_sewer_rest_basic'));
                            
                            renderCategory('Generator & Recoveries');
                            renderInput('Generator Run Time Rate', 'p_gen', v($t_plett, 'plett_generator_rate'));
                            renderInput('Electrical Allocation Rate', 'p_comm_elec', v($t_plett, 'plett_comm_area_electrical'));
                            renderInput('Water Allocation Rate', 'p_comm_water', v($t_plett, 'plett_comm_area_water'));
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php renderTierBandEditor('plett'); ?>
            <div class="d-flex justify-content-end mt-4 mb-2">
                <button type="submit" class="btn btn-brand px-4 py-2 shadow-sm fs-6 fw-normal">Save Plettenberg Bay Rates</button>
            </div>
        </form>

    <?php elseif ($active_tab === 'george'): ?>
        <!-- GEORGE TAB -->
        <form action="tariff-update-form-submit.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="tariff_type" value="george">
            <input type="hidden" name="period_selector" value="<?php echo htmlspecialchars($selected_period); ?>">

            <div class="bg-dark p-3 border border-secondary mb-3 d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <span class="text-white fs-6 fw-normal"><i class="bi bi-clipboard-data text-warning me-2"></i>Tariff Move Over</span><br>
                    <span class="text-muted fw-normal" style="font-size: 0.85rem;">Copy historical or future rates into <?php echo date('F Y', strtotime($selected_period . '-01')); ?></span>
                </div>
                <div class="d-flex align-items-center mt-2 mt-md-0">
                    <span class="text-white-50 fs-6 fw-normal me-2">Copy From:</span>
                    <!-- Removed HTML5 Max Attribute for Bi-Directional Copying -->
                    <input type="month" id="copy_period_george" class="form-control form-control-sm bg-dark text-white border-secondary fs-6 fw-normal me-3" style="width: 160px;">
                    <button type="button" class="btn btn-outline-warning btn-sm fs-6 fw-normal px-3" onclick="copyTariffs('george')">Load Rates</button>
                </div>
            </div>

            <div class="table-container shadow">
                <div class="p-3 border-bottom border-secondary d-flex justify-content-between align-items-center bg-dark">
                    <span class="text-white fs-6 fw-normal mb-0">George Tariffs</span>
                    <?php $ds_george = $t_george['demand_season'] ?? 'High'; ?>
                    <select name="demand_season" class="form-select form-select-sm bg-dark text-white border-secondary w-auto">
                        <option value="High" <?php echo $ds_george === 'High' ? 'selected' : ''; ?>>High Demand</option>
                        <option value="Low" <?php echo $ds_george === 'Low' ? 'selected' : ''; ?>>Low Demand</option>
                    </select>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-borderless align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th style="width: 70%;">Tariff Description</th>
                                <th class="text-end" style="width: 30%;">Applied Rate (ZAR)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            renderCategory('General Consumers');
                            renderInput('Basic Charge', 'george_elec_general_basic', v($t_george, 'george_elec_general_basic'));
                            renderInput('Energy Charges (kWh)', 'george_elec_general_kwh', v($t_george, 'george_elec_general_kwh'));
                            renderInput('Capacity Charges (Amps)', 'george_elec_general_amps', v($t_george, 'george_elec_general_amps'));
                            
                            renderCategory('Bulk TOU (Medium Voltage)');
                            renderInput('Basic Charge', 'george_elec_bulk_tou_basic', v($t_george, 'george_elec_bulk_tou_basic'));
                            renderInput('Peak (High Demand)', 'george_elec_bulk_tou_peak', v($t_george, 'george_elec_bulk_tou_peak'));
                            renderInput('Standard (High Demand)', 'george_elec_bulk_tou_standard', v($t_george, 'george_elec_bulk_tou_standard'));
                            renderInput('Off Peak (High Demand)', 'george_elec_bulk_tou_offpeak', v($t_george, 'george_elec_bulk_tou_offpeak'));
                            renderInput('Demand Charge (block) TOU2 (kVA)', 'george_elec_bulk_tou_demand_kva', v($t_george, 'george_elec_bulk_tou_demand_kva'));
                            renderInput('Access Charge TOU2A (kVA)', 'george_elec_bulk_tou_access_kva', v($t_george, 'george_elec_bulk_tou_access_kva'));
                            
                            renderCategory('Water (Industries/Businesses)');
                            renderInput('Basic Charge', 'george_water_ind_basic', v($t_george, 'george_water_ind_basic'));
                            renderInput(tierLabel('george', 1, 'Water '), 'george_water_ind_tier_1_0_6', v($t_george, 'george_water_ind_tier_1_0_6'));
                            renderInput(tierLabel('george', 2, 'Water '), 'george_water_ind_tier_2_6_15', v($t_george, 'george_water_ind_tier_2_6_15'));
                            renderInput(tierLabel('george', 3, 'Water '), 'george_water_ind_tier_3_15_20', v($t_george, 'george_water_ind_tier_3_15_20'));
                            renderInput(tierLabel('george', 4, 'Water '), 'george_water_ind_tier_4_20_30', v($t_george, 'george_water_ind_tier_4_20_30'));
                            renderInput(tierLabel('george', 5, 'Water '), 'george_water_ind_tier_5_30_50', v($t_george, 'george_water_ind_tier_5_30_50'));
                            renderInput(tierLabel('george', 6, 'Water '), 'george_water_ind_tier_6_50_75', v($t_george, 'george_water_ind_tier_6_50_75'));
                            renderInput(tierLabel('george', 7, 'Water '), 'george_water_ind_tier_7_above_75', v($t_george, 'george_water_ind_tier_7_above_75'));
                            
                            renderCategory('Sewer Charges');
                            renderInput('Sewer Basic Fee', 'george_sewer_basic', v($t_george, 'george_sewer_basic'));
                            
                            renderCategory('Generator & Recovery');
                            renderInput('Generator Run Time Rate', 'george_generator_rate', v($t_george, 'george_generator_rate'));
                            renderInput('Electrical Allocation Rate', 'george_comm_area_electrical', v($t_george, 'george_comm_area_electrical'));
                            renderInput('Water Allocation Rate', 'george_comm_area_water', v($t_george, 'george_comm_area_water'));
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php renderTierBandEditor('george'); ?>
            <div class="d-flex justify-content-end mt-4 mb-2">
                <button type="submit" class="btn btn-brand px-4 py-2 shadow-sm fs-6 fw-normal">Save George Rates</button>
            </div>
        </form>
    <?php endif; ?>

</div>
    <?php include LUM_ROOT . '/Layout/foot-assets.php'; ?>
<script>
    function copyTariffs(tab) {
        const copyPeriod = document.getElementById('copy_period_' + tab).value;
        if (!copyPeriod) {
            alert("Please select a valid month to copy rates from.");
            return;
        }
        const currentPeriod = <?php echo json_encode($selected_period); ?>;
        window.location.href = `tariff-update-form.php?period=${currentPeriod}&tab=${tab}&copy_from=${copyPeriod}`;
    }
</script>
<script>
    // Water tier bands: show where each tier starts
    document.querySelectorAll('.tier-editor').forEach(function (box) {
        const inputs = Array.from(box.querySelectorAll('.tier-up'));
        const froms = Array.from(box.querySelectorAll('.tier-from'));
        function refresh() {
            let prev = 0;
            froms.forEach(function (cell, i) {
                const text = (i === 0) ? '0' : (Number.isInteger(prev) ? String(prev + 1) : String(prev));
                cell.textContent = (i === 0 || prev > 0) ? text : '-';
                const v = inputs[i] ? parseFloat(inputs[i].value) : NaN;
                if (!isNaN(v)) prev = v;
            });
        }
        inputs.forEach(function (inp) { inp.addEventListener('input', refresh); });
        refresh();
    });
</script>
</body>
</html>