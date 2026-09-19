<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once __DIR__ . '/bootstrap.php';
lum_page('tariffs', 'view');

lum_connect('tariffs');
lum_use('reporting');

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

// STRICT EXACT MATCH FETCH ENGINE (Prioritizes the absolute newest ID for the month)
function fetchExactTariff($pdo, $table, $pm, $py) {
    try {
        $sql = "SELECT * FROM `$table` 
                WHERE period_year = :y AND period_month = :m 
                ORDER BY id DESC LIMIT 1";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['y' => $py, 'm' => $pm]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? $result : [];
    } catch (PDOException $e) {
        return [];
    }
}

$t_tshwane = fetchExactTariff($tariff_db_conn, 'lum_tariffs_city_of_tshwane', $pm, $py);
$t_ekur    = fetchExactTariff($tariff_db_conn, 'lum_tarrifs_ekhurhuleni', $pm, $py);
$t_rust    = fetchExactTariff($tariff_db_conn, 'lum_tariffs_rustenburg', $pm, $py);
$t_mkhondo = fetchExactTariff($tariff_db_conn, 'lum_tariffs_mkhondo', $pm, $py);
$t_plett   = fetchExactTariff($tariff_db_conn, 'lum_tariffs_plett', $pm, $py);
$t_george  = fetchExactTariff($tariff_db_conn, 'lum_tariffs_george', $pm, $py);

// Return a dash if the data doesn't exist for the specific month
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

function d($array, $key) { 
    return (isset($array[$key]) && $array[$key] !== '' && $array[$key] !== null) ? number_format((float)$array[$key], 4) : '-'; 
}

// Dynamic rendering functions explicitly locked to fs-6 fw-normal and 2 columns
function renderCategory($title) {
    echo "<tr style='background-color: #1a1a1a;'><td colspan='2' class='text-danger fs-6 fw-normal border-bottom border-secondary pt-3 pb-2'><i class='bi bi-bookmark-check-fill me-2'></i>" . htmlspecialchars($title) . "</td></tr>";
}

function renderRow($label, $value, $period) {
    $displayValue = ($value === '-') ? '-' : 'R ' . $value;
    echo "<tr>
        <td class='ps-4 align-middle'><span class='text-white fs-6 fw-normal'>" . htmlspecialchars($label) . "</span></td>
        <td class='text-end align-middle'>
            <div class='px-2 py-1 bg-white text-dark fs-6 fw-normal d-inline-block text-start' style='width: 130px;'>" . $displayValue . "</div>
        </td>
    </tr>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Tariff Overview - Lynx Utility Management</title>
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>
    <style>
        /* Strict global override to match Report Settings typography and force sharp corners */
        * {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important;
            font-weight: normal !important;
            text-transform: none !important;
            border-radius: 0 !important;
        }
        body { background-color: #121212; color: #fff; padding-bottom: 50px; }
        .action-bar { background-color: #0a0a0a; padding: 15px 20px; border-bottom: 1px solid #333; margin-bottom: 30px; }
        .nav-tabs .nav-link { color: #aaa; border: none; padding: 12px 25px; }
        .nav-tabs .nav-link.active { background-color: #1e1e1e; color: #d32f2f; border-bottom: 3px solid #d32f2f; }
        .table-container { background-color: #000000; border: 1px solid #333333; overflow: hidden; }
        .table { margin-bottom: 0; color: #e0e0e0; }
        .table thead th { background-color: #1a1a1a; color: #ffffff; border-bottom: 2px solid #333333; font-size: 1rem; padding: 12px 16px; }
        .table tbody td { border-bottom: 1px solid #222222; padding: 10px 16px; vertical-align: middle; background-color: transparent; color: #cccccc; }
        .table tbody tr:hover td { background-color: #111111; }
        .btn-brand { background-color: #e3000f; color: #ffffff; border: none; transition: 0.3s; }
        .btn-brand:hover { background-color: #bf000c; color: #ffffff; transform: translateY(-1px); }
    </style>
</head>
<body>

<?php include LUM_ROOT . '/Layout/top-navbar.php'; ?>

<div class="action-bar d-flex justify-content-between align-items-center shadow-sm">
    <div class="d-flex align-items-center">
        <a href="../Configs/view-configs.php" class="btn btn-brand btn-sm px-2 shadow-sm me-3">Back</a>
        <span class="mb-0 fs-6 fw-normal text-white">System Tariffs Overview</span>
    </div>
    <div class="d-flex align-items-center">
        <!-- FIXED JUMPING TAB ON DATE CHANGE -->
        <form method="GET" class="d-flex me-3 align-items-center bg-dark p-1 border border-secondary">
            <input type="hidden" name="tab" id="hiddenTabInput" value="<?php echo htmlspecialchars($active_tab); ?>">
            <span class="text-white fs-6 fw-normal ms-2 me-2">Check Historical Rates:</span>
            <input type="month" name="period" value="<?php echo htmlspecialchars($selected_period); ?>" class="form-control form-control-sm bg-dark text-white border-0 fs-6 fw-normal" onchange="
                const activeLink = document.querySelector('.nav-tabs .nav-link.active');
                if (activeLink) {
                    document.getElementById('hiddenTabInput').value = activeLink.id.replace('-tab', '');
                }
                this.form.submit();
            " style="width: 150px;">
        </form>
        <?php if (lum_can('tariffs', 'edit')): // The setup screens below are only for users who may change tariffs ?>
        <a href="tariff-rate-map.php" class="btn btn-brand btn-sm px-2 shadow-sm me-2"><i class="bi bi-diagram-2 me-2"></i>Rate Map</a>
        <a href="tou-periods.php" class="btn btn-brand btn-sm px-2 shadow-sm me-2"><i class="bi bi-clock-history me-2"></i>TOU Periods</a>
        <a href="tariff-catalog.php" class="btn btn-brand btn-sm px-2 shadow-sm me-2"><i class="bi bi-journal-text me-2"></i>Tariff Catalog</a>
        <a href="Tariff Update/tariff-update-form.php?period=<?php echo urlencode($selected_period); ?>" id="editTariffsBtn" class="btn btn-brand btn-sm px-3 py-2 shadow-sm fs-6 fw-normal"><i class="bi bi-pencil-square me-2"></i>Edit / Add Tariffs</a>
        <?php endif; ?>
    </div>
</div>

<div class="container-fluid px-4 pb-5">
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <span class="fs-6 fw-normal text-dark"><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></span>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <ul class="nav nav-tabs mb-4" id="tariffTabs" role="tablist">
        <li class="nav-item" role="presentation"><button class="nav-link fs-6 fw-normal <?php echo $active_tab === 'tshwane' ? 'active' : ''; ?>" id="tshwane-tab" data-bs-toggle="tab" data-bs-target="#tshwane" type="button" role="tab">City of Tshwane</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link fs-6 fw-normal <?php echo $active_tab === 'ekurhuleni' ? 'active' : ''; ?>" id="ekurhuleni-tab" data-bs-toggle="tab" data-bs-target="#ekurhuleni" type="button" role="tab">Ekurhuleni</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link fs-6 fw-normal <?php echo $active_tab === 'rustenburg' ? 'active' : ''; ?>" id="rustenburg-tab" data-bs-toggle="tab" data-bs-target="#rustenburg" type="button" role="tab">Rustenburg</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link fs-6 fw-normal <?php echo $active_tab === 'mkhondo' ? 'active' : ''; ?>" id="mkhondo-tab" data-bs-toggle="tab" data-bs-target="#mkhondo" type="button" role="tab">Mkhondo</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link fs-6 fw-normal <?php echo $active_tab === 'plett' ? 'active' : ''; ?>" id="plett-tab" data-bs-toggle="tab" data-bs-target="#plett" type="button" role="tab">Plettenberg Bay</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link fs-6 fw-normal <?php echo $active_tab === 'george' ? 'active' : ''; ?>" id="george-tab" data-bs-toggle="tab" data-bs-target="#george" type="button" role="tab">George</button></li>
    </ul>

    <div class="tab-content" id="tariffTabsContent">
        <!-- TSHWANE TAB -->
        <div class="tab-pane fade <?php echo $active_tab === 'tshwane' ? 'show active' : ''; ?>" id="tshwane" role="tabpanel">
            <div class="table-container shadow mb-5">
                <div class="p-3 border-bottom border-secondary d-flex justify-content-between align-items-center bg-dark">
                    <span class="text-white fs-6 fw-normal mb-0">City of Tshwane Tariffs</span>
                    <?php $ds_tshwane = $t_tshwane['demand_season'] ?? 'High'; ?>
                    <select class="form-select form-select-sm bg-dark text-white border-secondary w-auto" disabled>
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
                            renderRow('Basic Charge', d($t_tshwane, 'tshwane_tou_charge_basic'), $selected_period);
                            renderRow('Peak Unit Rate', d($t_tshwane, 'tshwane_tou_charge_peak'), $selected_period);
                            renderRow('Standard Unit Rate', d($t_tshwane, 'tshwane_tou_charge_standard'), $selected_period);
                            renderRow('Off-Peak Unit Rate', d($t_tshwane, 'tshwane_tou_charge_offpeak'), $selected_period);
                            renderRow('kVA Demand Charge', d($t_tshwane, 'tshwane_tou_charge_kva'), $selected_period);
                            
                            renderCategory('Prepaid Time of Use (TOU)');
                            renderRow('Basic Charge', d($t_tshwane, 'tshwane_prepaid_tou_charge_basic'), $selected_period);
                            renderRow('Peak Unit Rate', d($t_tshwane, 'tshwane_prepaid_tou_charge_peak'), $selected_period);
                            renderRow('Standard Unit Rate', d($t_tshwane, 'tshwane_prepaid_tou_charge_standard'), $selected_period);
                            renderRow('Off-Peak Unit Rate', d($t_tshwane, 'tshwane_prepaid_tou_charge_offpeak'), $selected_period);
                            renderRow('kVA Demand Charge', d($t_tshwane, 'tshwane_prepaid_tou_charge_kva'), $selected_period);
                            
                            renderCategory('Business Standard');
                            renderRow('Basic Charge', d($t_tshwane, 'tshwane_business_charge_basic'), $selected_period);
                            renderRow('Unit Rate', d($t_tshwane, 'tshwane_business_charge_unit'), $selected_period);
                            
                            renderCategory('Business (S)');
                            renderRow('Basic Charge', d($t_tshwane, 'tshwane_business_S_charge_basic'), $selected_period);
                            renderRow('Unit Rate', d($t_tshwane, 'tshwane_business_S_charge_unit'), $selected_period);
                            
                            renderCategory('Business (L)');
                            renderRow('Basic Charge', d($t_tshwane, 'tshwane_business_L_charge_basic'), $selected_period);
                            renderRow('Unit Rate', d($t_tshwane, 'tshwane_business_L_charge_unit'), $selected_period);
                            
                            renderCategory('Non-Domestic Three Phase Conventional');
                            renderRow('Basic Charge', d($t_tshwane, 'tshwane_non_domestic_three_phase_basic'), $selected_period);
                            renderRow('Unit Rate', d($t_tshwane, 'tshwane_non_domestic_three_phase'), $selected_period);
                            
                            renderCategory('Low Voltage Demand');
                            renderRow('Basic Charge', d($t_tshwane, 'tshwane_low_voltage_demand_charge_basic'), $selected_period);
                            renderRow('Unit Rate (kWh)', d($t_tshwane, 'tshwane_low_voltage_demand_charge_unit'), $selected_period);
                            renderRow('Demand Charge (kVA)', d($t_tshwane, 'tshwane_low_voltage_demand_charge_kva'), $selected_period);
                            
                            renderCategory('Low Voltage Demand Scale');
                            renderRow('Basic Charge', d($t_tshwane, 'lvds_charge_basic'), $selected_period);
                            renderRow('Unit Rate (kWh)', d($t_tshwane, 'lvds_charge_unit'), $selected_period);
                            renderRow('Demand Charge (kVA)', d($t_tshwane, 'lvds_charge_kva'), $selected_period);
                            
                            renderCategory('Water & Recoveries');
                            renderRow('Water (Non-Domestic)', d($t_tshwane, 'tshwane_water_non_domestic'), $selected_period);
                            renderRow('Sewer Recovery', d($t_tshwane, 'tshwane_water_sewer'), $selected_period);
                            renderRow('Sewer Recovery (Business)', d($t_tshwane, 'tshwane_water_sewer_business'), $selected_period);
                            renderRow('Generator Run Time Rate', d($t_tshwane, 'tshwane_generator'), $selected_period);
                            
                            renderCategory('Common Area Recovery');
                            renderRow('Electrical Allocation Rate', d($t_tshwane, 'tshwane_comm_area_electrical'), $selected_period);
                            renderRow('Water & Sewer Allocation Rate', d($t_tshwane, 'tshwane_comm_area_water'), $selected_period);
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- EKURHULENI TAB -->
        <div class="tab-pane fade <?php echo $active_tab === 'ekurhuleni' ? 'show active' : ''; ?>" id="ekurhuleni" role="tabpanel">
            <div class="table-container shadow mb-5">
                <div class="p-3 border-bottom border-secondary d-flex justify-content-between align-items-center bg-dark">
                    <span class="text-white fs-6 fw-normal mb-0">Ekurhuleni Tariffs</span>
                    <?php $ds_ekur = $t_ekur['demand_season'] ?? 'High'; ?>
                    <select class="form-select form-select-sm bg-dark text-white border-secondary w-auto" disabled>
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
                            renderRow('Basic Charge', d($t_ekur, 'ekhurhuleni_charge_tariff_a_basic'), $selected_period);
                            renderRow('Unit Rate', d($t_ekur, 'ekhurhuleni_charge_tariff_a_unit'), $selected_period);
                            renderRow('Capacity Charge', d($t_ekur, 'ekhurhuleni_charge_tariff_a_capacity'), $selected_period);
                            
                            renderCategory('Tariff B');
                            renderRow('Basic Charge', d($t_ekur, 'ekhurhuleni_charge_tariff_b_basic'), $selected_period);
                            renderRow('Unit Rate', d($t_ekur, 'ekhurhuleni_charge_tariff_b_unit'), $selected_period);
                            renderRow('Capacity Charge', d($t_ekur, 'ekhurhuleni_charge_tariff_b_capacity'), $selected_period);
                            
                            renderCategory('Tariff C');
                            renderRow('Basic Charge', d($t_ekur, 'ekhurhuleni_charge_tariff_c_basic'), $selected_period);
                            renderRow('Unit Rate', d($t_ekur, 'ekhurhuleni_charge_tariff_c_unit'), $selected_period);
                            renderRow('Capacity Charge', d($t_ekur, 'ekhurhuleni_charge_tariff_c_capacity'), $selected_period);
                            renderRow('Demand Charge', d($t_ekur, 'ekhurhuleni_charge_tariff_c_demand'), $selected_period);
                            renderRow('NAC Charge', d($t_ekur, 'ekhurhuleni_charge_tariff_c_nac'), $selected_period);
                            
                            renderCategory('Time of Use (TOU)');
                            renderRow('Basic Charge', d($t_ekur, 'ekhurhuleni_charge_tariff_tou_basic'), $selected_period);
                            renderRow('Peak Unit Rate', d($t_ekur, 'ekhurhuleni_charge_tariff_tou_peak'), $selected_period);
                            renderRow('Standard Unit Rate', d($t_ekur, 'ekhurhuleni_charge_tariff_tou_standard'), $selected_period);
                            renderRow('Off-Peak Unit Rate', d($t_ekur, 'ekhurhuleni_charge_tariff_tou_offpeak'), $selected_period);
                            renderRow('Demand Charge', d($t_ekur, 'ekhurhuleni_charge_tariff_tou_demand'), $selected_period);
                            renderRow('Demand NAC Charge', d($t_ekur, 'ekhurhuleni_charge_tariff_tou_demand_nac'), $selected_period);
                            
                            renderCategory('Water & Recoveries');
                            renderRow('Water Rate', d($t_ekur, 'ekhurhuleni_charge_tariff_water'), $selected_period);
                            renderRow('Sewer Recovery', d($t_ekur, 'ekhurhuleni_charge_tariff_water_sewer'), $selected_period);
                            renderRow('Generator Run Time Rate', d($t_ekur, 'ekhurhuleni_charge_generator'), $selected_period);
                            
                            renderCategory('Common Area Recovery');
                            renderRow('Electrical Allocation Rate', d($t_ekur, 'ekhurhuleni_charge_comn_area_elec'), $selected_period);
                            renderRow('Water & Sewer Allocation Rate', d($t_ekur, 'ekhurhuleni_charge_comn_area_water'), $selected_period);
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- RUSTENBURG TAB -->
        <div class="tab-pane fade <?php echo $active_tab === 'rustenburg' ? 'show active' : ''; ?>" id="rustenburg" role="tabpanel">
            <div class="table-container shadow mb-5">
                <div class="p-3 border-bottom border-secondary d-flex justify-content-between align-items-center bg-dark">
                    <span class="text-white fs-6 fw-normal mb-0">Rustenburg Tariffs</span>
                    <?php $ds_rust = $t_rust['demand_season'] ?? 'High'; ?>
                    <select class="form-select form-select-sm bg-dark text-white border-secondary w-auto" disabled>
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
                            renderRow('Basic Charge', d($t_rust, 'rustenburg_non_domestic_conventional_basic_charge'), $selected_period);
                            renderRow('Unit Rate', d($t_rust, 'rustenburg_non_domestic_conventional_unit'), $selected_period);
                            
                            renderCategory('Network Access & Shared');
                            renderRow('All Season Demand (400V)', d($t_rust, 'rustenburg_all_season_network_demand_charge_bulk_and_rural_400V'), $selected_period);
                            renderRow('All Season Access (400V)', d($t_rust, 'rustenburg_all_season_network_access_charge_bulk_and_rural_400V'), $selected_period);
                            renderRow('Shared Network Access', d($t_rust, 'rustenburg_shared_network_access_charge'), $selected_period);
                            
                            renderCategory('Bulk Supply & Rural 400V');
                            renderRow('Basic Charge', d($t_rust, 'rustenburg_bulk_supply_and_rural_400V_basic_charge'), $selected_period);
                            renderRow('Unit Rate', d($t_rust, 'rustenburg_bulk_supply_and_rural_400V_unit'), $selected_period);
                            
                            renderCategory('Water (Commercial Tiered)');
                            renderRow('Water Basic Charge', d($t_rust, 'rustenburg_water_commercial_tiered_basic_charge'), $selected_period);
                            renderRow(tierLabel('rustenburg', 1, 'Water '), d($t_rust, 'rustenburg_water_commercial_tiered_0_60_charge'), $selected_period);
                            renderRow(tierLabel('rustenburg', 2, 'Water '), d($t_rust, 'rustenburg_water_commercial_tiered_61_100_charge'), $selected_period);
                            renderRow(tierLabel('rustenburg', 3, 'Water '), d($t_rust, 'rustenburg_water_commercial_tiered_101_150_charge'), $selected_period);
                            renderRow(tierLabel('rustenburg', 4, 'Water '), d($t_rust, 'rustenburg_water_commercial_tiered_151_plus_charge'), $selected_period);
                            
                            renderCategory('Sewer Recovery');
                            renderRow('Sewer Basic Charge', d($t_rust, 'rustenburg_water_sewer_basic_charge'), $selected_period);
                            
                            renderCategory('Generator & Comm Area');
                            renderRow('Generator Run Time Rate', d($t_rust, 'rustenburg_generator_charge'), $selected_period);
                            renderRow('Electrical Allocation Rate', d($t_rust, 'rustenburg_comm_area_electrical'), $selected_period);
                            renderRow('Water & Sewer Allocation Rate', d($t_rust, 'rustenburg_comm_area_water'), $selected_period);
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- MKHONDO TAB -->
        <div class="tab-pane fade <?php echo $active_tab === 'mkhondo' ? 'show active' : ''; ?>" id="mkhondo" role="tabpanel">
            <div class="table-container shadow mb-5">
                <div class="p-3 border-bottom border-secondary d-flex justify-content-between align-items-center bg-dark">
                    <span class="text-white fs-6 fw-normal mb-0">Mkhondo Tariffs</span>
                    <?php $ds_mkhondo = $t_mkhondo['demand_season'] ?? 'High'; ?>
                    <select class="form-select form-select-sm bg-dark text-white border-secondary w-auto" disabled>
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
                            renderRow('Basic Charge', d($t_mkhondo, 'mkhondo_elec_business_less_80_basic'), $selected_period);
                            renderRow('Unit Rate (kWh)', d($t_mkhondo, 'mkhondo_elec_business_less_80_kwh'), $selected_period);
                            
                            renderCategory('Business (More than 80 kVA)');
                            renderRow('Basic Charge', d($t_mkhondo, 'mkhondo_elec_business_more_80_basic'), $selected_period);
                            renderRow('Unit Rate (kWh)', d($t_mkhondo, 'mkhondo_elec_business_more_80_kwh'), $selected_period);
                            
                            renderCategory('Industrial Small (Less than 50 kVA)');
                            renderRow('Basic Charge', d($t_mkhondo, 'mkhondo_elec_industrial_small_less_50_basic'), $selected_period);
                            renderRow('Unit Rate (kWh)', d($t_mkhondo, 'mkhondo_elec_industrial_small_less_50_kwh'), $selected_period);
                            renderRow('Demand Charge (kVA)', d($t_mkhondo, 'mkhondo_elec_industrial_small_less_50_kva'), $selected_period);
                            
                            renderCategory('Industrial (More than 50 kVA)');
                            renderRow('Basic Charge', d($t_mkhondo, 'mkhondo_elec_industrial_more_50_basic'), $selected_period);
                            renderRow('Unit Rate (kWh)', d($t_mkhondo, 'mkhondo_elec_industrial_more_50_kwh'), $selected_period);
                            renderRow('Demand Charge (kVA)', d($t_mkhondo, 'mkhondo_elec_industrial_more_50_kva'), $selected_period);
                            
                            renderCategory('Water (Tiered)');
                            renderRow('Water Basic Charge', d($t_mkhondo, 'mkhondo_water_business_basic'), $selected_period);
                            renderRow(tierLabel('mkhondo', 1, 'Water '), d($t_mkhondo, 'mkhondo_water_tier_1_0_to_6'), $selected_period);
                            renderRow(tierLabel('mkhondo', 2, 'Water '), d($t_mkhondo, 'mkhondo_water_tier_2_7_to_20'), $selected_period);
                            renderRow(tierLabel('mkhondo', 3, 'Water '), d($t_mkhondo, 'mkhondo_water_tier_3_21_to_40'), $selected_period);
                            renderRow(tierLabel('mkhondo', 4, 'Water '), d($t_mkhondo, 'mkhondo_water_tier_4_41_to_60'), $selected_period);
                            renderRow(tierLabel('mkhondo', 5, 'Water '), d($t_mkhondo, 'mkhondo_water_tier_5_above_60'), $selected_period);
                            
                            renderCategory('Sewer Fixed Charges');
                            renderRow('Standard Sewer', d($t_mkhondo, 'mkhondo_sewer_basic_mkhondo'), $selected_period);
                            renderRow('Business M Sewer', d($t_mkhondo, 'mkhondo_sewer_basic_business_m'), $selected_period);
                            renderRow('Business Large Sewer', d($t_mkhondo, 'mkhondo_sewer_basic_business_large'), $selected_period);
                            
                            renderCategory('Generator & Comm Area');
                            renderRow('Generator Run Time Rate', d($t_mkhondo, 'mkhondo_elec_generator_rate'), $selected_period);
                            renderRow('Comm. Generator Recovery Rate', d($t_mkhondo, 'mkhondo_comm_gen_electricity'), $selected_period);
                            renderRow('Comm. Water Contribution Rate', d($t_mkhondo, 'mkhondo_comm_water_contribution'), $selected_period);
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- PLETT TAB -->
        <div class="tab-pane fade <?php echo $active_tab === 'plett' ? 'show active' : ''; ?>" id="plett" role="tabpanel">
            <div class="table-container shadow mb-5">
                <div class="p-3 border-bottom border-secondary d-flex justify-content-between align-items-center bg-dark">
                    <span class="text-white fs-6 fw-normal mb-0">Plettenberg Bay Tariffs</span>
                    <?php $ds_plett = $t_plett['demand_season'] ?? 'High'; ?>
                    <select class="form-select form-select-sm bg-dark text-white border-secondary w-auto" disabled>
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
                            renderRow('1PH 15A - Basic', d($t_plett, 'plett_1ph_15a_basic'), $selected_period);
                            renderRow('1PH 15A - kWh', d($t_plett, 'plett_1ph_15a_kwh'), $selected_period);
                            renderRow('1PH 30A - Basic', d($t_plett, 'plett_1ph_30a_basic'), $selected_period);
                            renderRow('1PH 30A - kWh', d($t_plett, 'plett_1ph_30a_kwh'), $selected_period);
                            renderRow('1PH 40A - Basic', d($t_plett, 'plett_1ph_40a_basic'), $selected_period);
                            renderRow('1PH 40A - kWh', d($t_plett, 'plett_1ph_40a_kwh'), $selected_period);
                            renderRow('1PH 60A - Basic', d($t_plett, 'plett_1ph_60a_basic'), $selected_period);
                            renderRow('1PH 60A - kWh', d($t_plett, 'plett_1ph_60a_kwh'), $selected_period);
                            
                            renderCategory('3-Phase Electricity');
                            renderRow('3PH 60A - Basic', d($t_plett, 'plett_3ph_60a_basic'), $selected_period);
                            renderRow('3PH 60A - kWh', d($t_plett, 'plett_3ph_60a_kwh'), $selected_period);
                            renderRow('3PH 60A/63A - Basic', d($t_plett, 'plett_3ph_60a_63a_basic'), $selected_period);
                            renderRow('3PH 60A/63A - kWh', d($t_plett, 'plett_3ph_60a_63a_kwh'), $selected_period);
                            renderRow('3PH 100A - Basic', d($t_plett, 'plett_3ph_100a_basic'), $selected_period);
                            renderRow('3PH 100A - kWh', d($t_plett, 'plett_3ph_100a_kwh'), $selected_period);
                            
                            renderCategory('LV Electricity');
                            renderRow('Basic Charge', d($t_plett, 'plett_lv_basic'), $selected_period);
                            renderRow('Energy Charge (kWh)', d($t_plett, 'plett_lv_kwh'), $selected_period);
                            renderRow('Demand Charge (kVA)', d($t_plett, 'plett_lv_demand_kva'), $selected_period);
                            renderRow('Access Charge (kVA)', d($t_plett, 'plett_lv_access_kva'), $selected_period);
                            
                            renderCategory('Bitou Municipality TOU');
                            renderRow('Basic Charge', d($t_plett, 'plett_bitou_tou_basic'), $selected_period);
                            renderRow('Peak Unit Rate', d($t_plett, 'plett_bitou_tou_peak'), $selected_period);
                            renderRow('Standard Unit Rate', d($t_plett, 'plett_bitou_tou_standard'), $selected_period);
                            renderRow('Off-Peak Unit Rate', d($t_plett, 'plett_bitou_tou_offpeak'), $selected_period);
                            renderRow('Demand Charge (kVA)', d($t_plett, 'plett_bitou_tou_demand_kva'), $selected_period);
                            renderRow('Network Access / Capacity (kVA)', d($t_plett, 'plett_bitou_tou_access_kva'), $selected_period);
                            
                            renderCategory('Water (SHOPS)');
                            renderRow('Basic Charge', d($t_plett, 'bitou_water_shops_basic'), $selected_period);
                            renderRow(tierLabel('plett', 1, ''), d($t_plett, 'bitou_water_shops_tier_1_0_60'), $selected_period);
                            renderRow(tierLabel('plett', 2, ''), d($t_plett, 'bitou_water_shops_tier_2_60_100'), $selected_period);
                            renderRow(tierLabel('plett', 3, ''), d($t_plett, 'bitou_water_shops_tier_3_100_200'), $selected_period);
                            renderRow(tierLabel('plett', 4, ''), d($t_plett, 'bitou_water_shops_tier_4_above_200'), $selected_period);
                            
                            renderCategory('Water (BUSS)');
                            renderRow('Basic Charge', d($t_plett, 'bitou_water_buss_basic'), $selected_period);
                            renderRow(tierLabel('plett', 1, ''), d($t_plett, 'bitou_water_buss_tier_1_0_60'), $selected_period);
                            renderRow(tierLabel('plett', 2, ''), d($t_plett, 'bitou_water_buss_tier_2_60_100'), $selected_period);
                            renderRow(tierLabel('plett', 3, ''), d($t_plett, 'bitou_water_buss_tier_3_100_200'), $selected_period);
                            renderRow(tierLabel('plett', 4, ''), d($t_plett, 'bitou_water_buss_tier_4_above_200'), $selected_period);
                            
                            renderCategory('Water (REST)');
                            renderRow('Basic Charge', d($t_plett, 'bitou_water_rest_basic'), $selected_period);
                            renderRow(tierLabel('plett', 1, ''), d($t_plett, 'bitou_water_rest_tier_1_0_60'), $selected_period);
                            renderRow(tierLabel('plett', 2, ''), d($t_plett, 'bitou_water_rest_tier_2_60_100'), $selected_period);
                            renderRow(tierLabel('plett', 3, ''), d($t_plett, 'bitou_water_rest_tier_3_100_200'), $selected_period);
                            renderRow(tierLabel('plett', 4, ''), d($t_plett, 'bitou_water_rest_tier_4_above_200'), $selected_period);
                            
                            renderCategory('Sewer Basic Charges');
                            renderRow('BITOU MUNICIPALITY - SEWER (BUS)', d($t_plett, 'bitou_sewer_bus_basic'), $selected_period);
                            renderRow('BITOU MUNICIPALITY - SEWER (REST)', d($t_plett, 'bitou_sewer_rest_basic'), $selected_period);
                            
                            renderCategory('Generator & Recoveries');
                            renderRow('Generator Run Time Rate', d($t_plett, 'plett_generator_rate'), $selected_period);
                            renderRow('Electrical Allocation Rate', d($t_plett, 'plett_comm_area_electrical'), $selected_period);
                            renderRow('Water Allocation Rate', d($t_plett, 'plett_comm_area_water'), $selected_period);
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- GEORGE TAB -->
        <div class="tab-pane fade <?php echo $active_tab === 'george' ? 'show active' : ''; ?>" id="george" role="tabpanel">
            <div class="table-container shadow mb-5">
                <div class="p-3 border-bottom border-secondary d-flex justify-content-between align-items-center bg-dark">
                    <span class="text-white fs-6 fw-normal mb-0">George Tariffs</span>
                    <?php $ds_george = $t_george['demand_season'] ?? 'High'; ?>
                    <select class="form-select form-select-sm bg-dark text-white border-secondary w-auto" disabled>
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
                            renderRow('Basic Charge', d($t_george, 'george_elec_general_basic'), $selected_period);
                            renderRow('Energy Charges (kWh)', d($t_george, 'george_elec_general_kwh'), $selected_period);
                            renderRow('Capacity Charges (Amps)', d($t_george, 'george_elec_general_amps'), $selected_period);
                            
                            renderCategory('Bulk TOU (Medium Voltage)');
                            renderRow('Basic Charge', d($t_george, 'george_elec_bulk_tou_basic'), $selected_period);
                            renderRow('Peak (High Demand)', d($t_george, 'george_elec_bulk_tou_peak'), $selected_period);
                            renderRow('Standard (High Demand)', d($t_george, 'george_elec_bulk_tou_standard'), $selected_period);
                            renderRow('Off Peak (High Demand)', d($t_george, 'george_elec_bulk_tou_offpeak'), $selected_period);
                            renderRow('Demand Charge (block) TOU2 (kVA)', d($t_george, 'george_elec_bulk_tou_demand_kva'), $selected_period);
                            renderRow('Access Charge TOU2A (kVA)', d($t_george, 'george_elec_bulk_tou_access_kva'), $selected_period);
                            
                            renderCategory('Water (Industries/Businesses)');
                            renderRow('Basic Charge', d($t_george, 'george_water_ind_basic'), $selected_period);
                            renderRow(tierLabel('george', 1, 'Water '), d($t_george, 'george_water_ind_tier_1_0_6'), $selected_period);
                            renderRow(tierLabel('george', 2, 'Water '), d($t_george, 'george_water_ind_tier_2_6_15'), $selected_period);
                            renderRow(tierLabel('george', 3, 'Water '), d($t_george, 'george_water_ind_tier_3_15_20'), $selected_period);
                            renderRow(tierLabel('george', 4, 'Water '), d($t_george, 'george_water_ind_tier_4_20_30'), $selected_period);
                            renderRow(tierLabel('george', 5, 'Water '), d($t_george, 'george_water_ind_tier_5_30_50'), $selected_period);
                            renderRow(tierLabel('george', 6, 'Water '), d($t_george, 'george_water_ind_tier_6_50_75'), $selected_period);
                            renderRow(tierLabel('george', 7, 'Water '), d($t_george, 'george_water_ind_tier_7_above_75'), $selected_period);
                            
                            renderCategory('Sewer Charges');
                            renderRow('Sewer Basic Fee', d($t_george, 'george_sewer_basic'), $selected_period);
                            
                            renderCategory('Generator & Recovery');
                            renderRow('Generator Run Time Rate', d($t_george, 'george_generator_rate'), $selected_period);
                            renderRow('Electrical Allocation Rate', d($t_george, 'george_comm_area_electrical'), $selected_period);
                            renderRow('Water Allocation Rate', d($t_george, 'george_comm_area_water'), $selected_period);
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

    <?php include LUM_ROOT . '/Layout/foot-assets.php'; ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Update edit button link dynamically based on active tab
        const editBtn = document.getElementById('editTariffsBtn');
        if(editBtn) {
            editBtn.addEventListener('click', function(e) {
                e.preventDefault();
                const activeTab = document.querySelector('.nav-tabs .nav-link.active');
                let tabId = 'tshwane';
                if (activeTab) {
                    tabId = activeTab.id.replace('-tab', '');
                }
                window.location.href = this.getAttribute('href') + '&tab=' + tabId;
            });
        }

        // --- FIX: Update the hidden 'tab' input when the user switches tabs ---
        const tabButtons = document.querySelectorAll('button[data-bs-toggle="tab"]');
        const hiddenTabInput = document.querySelector('input[name="tab"]');
        
        tabButtons.forEach(btn => {
            btn.addEventListener('shown.bs.tab', function (event) {
                if (hiddenTabInput) {
                    hiddenTabInput.value = event.target.id.replace('-tab', '');
                }
            });
        });
    });
</script>
</body>
</html>