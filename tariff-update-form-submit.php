<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once __DIR__ . '/bootstrap.php';
lum_page('tariffs', 'edit');

lum_connect('tariffs');
require_once LUM_ROOT . '/Tarrifs/Tariff Control/tariff-conr.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // --- VALIDATE CSRF TOKEN ---
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        http_response_code(403);
        die("<div style='color:white; padding:20px; background:#121212; height:100vh;'>Security Error: Invalid CSRF Token. Request Blocked.</div>");
    }
    
    $conr = new tariff_conr($tariff_db_conn);
    $tariff_type = $_POST['tariff_type'] ?? '';
    if (!in_array($tariff_type, ['tshwane', 'ekurhuleni', 'rustenburg', 'mkhondo', 'plett', 'george'], true)) {
        die("<div style='color:white; padding:20px; background:#121212; height:100vh;'>Unknown tariff type.</div>");
    }

    $selected_period = $_POST['period_selector'] ?? '';
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$selected_period)) {
        die("<div style='color:white; padding:20px; background:#121212; height:100vh;'>Invalid tariff month.</div>");
    }
    $parts = explode('-', $selected_period);
    $period_year = (int)$parts[0];
    $period_month = (int)$parts[1];

    $demand_season = trim($_POST['demand_season'] ?? 'High');
    if (!in_array($demand_season, ['High', 'Low'], true)) {
        $demand_season = 'High';
    }

    if ($tariff_type === 'tshwane') {
        $res = $conr->save_tshwane_tariffs(
            $period_month, $period_year, $demand_season,
            floatval($_POST['tou_charge_peak'] ?? 0), floatval($_POST['tou_charge_offpeak'] ?? 0), floatval($_POST['tou_charge_standard'] ?? 0), floatval($_POST['tou_charge_kva'] ?? 0),
            floatval($_POST['tou_charge_basic'] ?? 0), 
            floatval($_POST['prepaid_tou_charge_peak'] ?? 0), floatval($_POST['prepaid_tou_charge_offpeak'] ?? 0), floatval($_POST['prepaid_tou_charge_standard'] ?? 0), 
            floatval($_POST['prepaid_tou_charge_kva'] ?? 0), floatval($_POST['prepaid_tou_charge_basic'] ?? 0),
            floatval($_POST['business_charge_basic'] ?? 0), floatval($_POST['business_charge_unit'] ?? 0),
            floatval($_POST['business_L_charge_basic'] ?? 0), floatval($_POST['business_L_charge_unit'] ?? 0),
            floatval($_POST['business_S_charge_basic'] ?? 0), floatval($_POST['business_S_charge_unit'] ?? 0),
            floatval($_POST['water_non_domestic'] ?? 0), floatval($_POST['water_sewer'] ?? 0), floatval($_POST['water_sewer_business'] ?? 0), floatval($_POST['generator'] ?? 0),
            floatval($_POST['comm_area_electrical'] ?? 0), floatval($_POST['comm_area_water'] ?? 0),
            floatval($_POST['non_domestic_three_phase'] ?? 0), floatval($_POST['non_domestic_three_phase_basic'] ?? 0),
            floatval($_POST['low_voltage_demand_charge_basic'] ?? 0),
            floatval($_POST['low_voltage_demand_charge_unit'] ?? 0),
            floatval($_POST['low_voltage_demand_charge_kva'] ?? 0),
            floatval($_POST['lvds_charge_basic'] ?? 0),
            floatval($_POST['lvds_charge_unit'] ?? 0),
            floatval($_POST['lvds_charge_kva'] ?? 0)
        );
    } 
    elseif ($tariff_type === 'ekurhuleni') {
        $res = $conr->save_ekurhuleni_tariffs(
            $period_month, $period_year, $demand_season,
            floatval($_POST['t_a_unit'] ?? 0), floatval($_POST['t_a_basic'] ?? 0), floatval($_POST['t_a_cap'] ?? 0),
            floatval($_POST['t_b_unit'] ?? 0), floatval($_POST['t_b_basic'] ?? 0), floatval($_POST['t_b_cap'] ?? 0),
            floatval($_POST['t_c_unit'] ?? 0), floatval($_POST['t_c_basic'] ?? 0), floatval($_POST['t_c_cap'] ?? 0),
            floatval($_POST['t_c_nac'] ?? 0), floatval($_POST['t_c_demand'] ?? 0), floatval($_POST['t_water'] ?? 0), floatval($_POST['t_sewer'] ?? 0),
            floatval($_POST['comn_elec'] ?? 0), floatval($_POST['comn_water'] ?? 0),
            floatval($_POST['tou_basic'] ?? 0), floatval($_POST['tou_peak'] ?? 0), floatval($_POST['tou_off'] ?? 0), floatval($_POST['tou_std'] ?? 0),
            floatval($_POST['tou_demand'] ?? 0), floatval($_POST['tou_demand_nac'] ?? 0),
            floatval($_POST['gen'] ?? 0)
        );
    }
    elseif ($tariff_type === 'rustenburg') {
        $res = $conr->save_rustenburg_tariffs(
            $period_month, $period_year, $demand_season,
            floatval($_POST['r_nd_conv_unit'] ?? 0), floatval($_POST['r_nd_conv_basic'] ?? 0),
            floatval($_POST['r_bulk_unit'] ?? 0), floatval($_POST['r_bulk_basic'] ?? 0),
            floatval($_POST['r_all_season_demand'] ?? 0), floatval($_POST['r_all_season_access'] ?? 0), floatval($_POST['r_shared_network'] ?? 0),
            floatval($_POST['r_comm_elec'] ?? 0), floatval($_POST['r_gen'] ?? 0),
            floatval($_POST['r_water_basic'] ?? 0), floatval($_POST['r_water_0_60'] ?? 0), floatval($_POST['r_water_61_100'] ?? 0), floatval($_POST['r_water_101_150'] ?? 0), floatval($_POST['r_water_151_plus'] ?? 0),
            floatval($_POST['r_sewer_basic'] ?? 0), floatval($_POST['r_comm_water'] ?? 0)
        );
    }
    elseif ($tariff_type === 'mkhondo') {
        $res = $conr->save_mkhondo_tariffs(
            $period_month, $period_year, $demand_season,
            floatval($_POST['m_elec_business_less_80_basic'] ?? 0),
            floatval($_POST['m_elec_business_less_80_kwh'] ?? 0),
            floatval($_POST['m_elec_business_more_80_basic'] ?? 0),
            floatval($_POST['m_elec_business_more_80_kwh'] ?? 0),
            floatval($_POST['m_elec_industrial_small_less_50_basic'] ?? 0),
            floatval($_POST['m_elec_industrial_small_less_50_kwh'] ?? 0),
            floatval($_POST['m_elec_industrial_small_less_50_kva'] ?? 0),
            floatval($_POST['m_elec_industrial_more_50_basic'] ?? 0),
            floatval($_POST['m_elec_industrial_more_50_kwh'] ?? 0),
            floatval($_POST['m_elec_industrial_more_50_kva'] ?? 0),
            floatval($_POST['m_elec_generator_rate'] ?? 0),
            floatval($_POST['m_water_business_basic'] ?? 0),
            floatval($_POST['m_water_tier_1'] ?? 0),
            floatval($_POST['m_water_tier_2'] ?? 0),
            floatval($_POST['m_water_tier_3'] ?? 0),
            floatval($_POST['m_water_tier_4'] ?? 0),
            floatval($_POST['m_water_tier_5'] ?? 0),
            floatval($_POST['m_sewer_basic_mkhondo'] ?? 0),
            floatval($_POST['m_sewer_basic_business_m'] ?? 0),
            floatval($_POST['m_sewer_basic_business_large'] ?? 0),
            floatval($_POST['m_comm_water'] ?? 0),
            floatval($_POST['m_comm_gen_elec'] ?? 0)
        );
    }
    elseif ($tariff_type === 'plett') {
        $res = $conr->save_plett_tariffs(
            $period_month, $period_year, $demand_season,
            floatval($_POST['p_1ph_15a_basic'] ?? 0), floatval($_POST['p_1ph_15a_kwh'] ?? 0),
            floatval($_POST['p_1ph_30a_basic'] ?? 0), floatval($_POST['p_1ph_30a_kwh'] ?? 0),
            floatval($_POST['p_1ph_40a_basic'] ?? 0), floatval($_POST['p_1ph_40a_kwh'] ?? 0),
            floatval($_POST['p_1ph_60a_basic'] ?? 0), floatval($_POST['p_1ph_60a_kwh'] ?? 0),
            floatval($_POST['p_3ph_60a_basic'] ?? 0), floatval($_POST['p_3ph_60a_kwh'] ?? 0),
            floatval($_POST['p_3ph_60a_63a_basic'] ?? 0), floatval($_POST['p_3ph_60a_63a_kwh'] ?? 0),
            floatval($_POST['p_3ph_100a_basic'] ?? 0), floatval($_POST['p_3ph_100a_kwh'] ?? 0),
            floatval($_POST['p_lv_basic'] ?? 0), floatval($_POST['p_lv_kwh'] ?? 0), floatval($_POST['p_lv_dem'] ?? 0), floatval($_POST['p_lv_acc'] ?? 0),
            floatval($_POST['b_tou_basic'] ?? 0), floatval($_POST['b_tou_peak'] ?? 0), floatval($_POST['b_tou_std'] ?? 0), floatval($_POST['b_tou_off'] ?? 0), floatval($_POST['b_tou_dem'] ?? 0), floatval($_POST['b_tou_acc'] ?? 0),
            floatval($_POST['p_comm_elec'] ?? 0), floatval($_POST['p_gen'] ?? 0),
            floatval($_POST['w_shops_basic'] ?? 0), floatval($_POST['w_shops_t1'] ?? 0), floatval($_POST['w_shops_t2'] ?? 0), floatval($_POST['w_shops_t3'] ?? 0), floatval($_POST['w_shops_t4'] ?? 0),
            floatval($_POST['w_buss_basic'] ?? 0), floatval($_POST['w_buss_t1'] ?? 0), floatval($_POST['w_buss_t2'] ?? 0), floatval($_POST['w_buss_t3'] ?? 0), floatval($_POST['w_buss_t4'] ?? 0),
            floatval($_POST['w_rest_basic'] ?? 0), floatval($_POST['w_rest_t1'] ?? 0), floatval($_POST['w_rest_t2'] ?? 0), floatval($_POST['w_rest_t3'] ?? 0), floatval($_POST['w_rest_t4'] ?? 0),
            floatval($_POST['s_bus_basic'] ?? 0), floatval($_POST['s_rest_basic'] ?? 0),
            floatval($_POST['p_comm_water'] ?? 0)
        );
    }
    elseif ($tariff_type === 'george') {
        $res = $conr->save_george_tariffs(
            $period_month, $period_year, $demand_season,
            floatval($_POST['george_elec_general_basic'] ?? 0),
            floatval($_POST['george_elec_general_kwh'] ?? 0),
            floatval($_POST['george_elec_general_amps'] ?? 0),
            floatval($_POST['george_elec_bulk_tou_basic'] ?? 0),
            floatval($_POST['george_elec_bulk_tou_peak'] ?? 0),
            floatval($_POST['george_elec_bulk_tou_standard'] ?? 0),
            floatval($_POST['george_elec_bulk_tou_offpeak'] ?? 0),
            floatval($_POST['george_elec_bulk_tou_demand_kva'] ?? 0),
            floatval($_POST['george_elec_bulk_tou_access_kva'] ?? 0),
            floatval($_POST['george_water_ind_basic'] ?? 0),
            floatval($_POST['george_water_ind_tier_1_0_6'] ?? 0),
            floatval($_POST['george_water_ind_tier_2_6_15'] ?? 0),
            floatval($_POST['george_water_ind_tier_3_15_20'] ?? 0),
            floatval($_POST['george_water_ind_tier_4_20_30'] ?? 0),
            floatval($_POST['george_water_ind_tier_5_30_50'] ?? 0),
            floatval($_POST['george_water_ind_tier_6_50_75'] ?? 0),
            floatval($_POST['george_water_ind_tier_7_above_75'] ?? 0),
            floatval($_POST['george_sewer_basic'] ?? 0),
            floatval($_POST['george_comm_area_electrical'] ?? 0),
            floatval($_POST['george_comm_area_water'] ?? 0),
            floatval($_POST['george_generator_rate'] ?? 0)
        );
    }

    if (isset($res) && $res) {
        $_SESSION['success_message'] = ucfirst($tariff_type) . " tariffs securely saved for " . str_pad($period_month, 2, '0', STR_PAD_LEFT) . "/" . $period_year;
    } else {
        $_SESSION['error_message'] = "Error updating tariffs. Please check database connection.";
    }

    // --- WATER TIER BANDS (tiered water tariffs only) ---
    $tier_municipalities = ['rustenburg' => ['Rustenburg', 4], 'mkhondo' => ['Mkhondo', 5], 'plett' => ['Plettenberg Bay', 4], 'george' => ['George', 7]];
    if (isset($res) && $res && isset($tier_municipalities[$tariff_type]) && isset($_POST['tier_up_to']) && is_array($_POST['tier_up_to'])) {
        lum_use('reporting');
        list($tier_muni, $tier_count) = $tier_municipalities[$tariff_type];
        $tier_limits = [];
        $tier_error = '';
        $prev = 0.0;
        for ($n = 1; $n < $tier_count; $n++) {
            $v = str_replace([' ', ','], ['', '.'], trim((string)($_POST['tier_up_to'][$n] ?? '')));
            if ($v === '' || !is_numeric($v) || (float)$v <= $prev || (float)$v > 100000) {
                $tier_error = 'Tier ' . $n . ' must end above ' . rtrim(rtrim(number_format($prev, 3, '.', ''), '0'), '.') . ' kL (each tier must end higher than the one before).';
                break;
            }
            $prev = round((float)$v, 3);
            $tier_limits[$n] = $prev;
        }
        $tier_limits[$tier_count] = null;

        if ($tier_error !== '') {
            $_SESSION['error_message'] = 'The rates were saved, but the water tier bands were not changed: ' . $tier_error;
        } else {
            // Only save when the bands differ from the ones in use for this tariff month
            $month_end = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $period_year, $period_month)));
            $current = lumWaterTierBandsFixed($tier_muni, $tier_count, $month_end);
            $changed = false;
            foreach ($current as $i => $band) {
                $a = $band['up_to'];
                $b = $tier_limits[$i + 1];
                if (($a === null) !== ($b === null) || ($a !== null && abs($a - $b) > 0.0005)) { $changed = true; break; }
            }
            if ($changed) {
                $apply_all = (($_POST['tier_apply'] ?? 'month') === 'all');
                if ($conr->save_water_tiers($tier_muni, sprintf('%04d-%02d-01', $period_year, $period_month), $tier_limits, $apply_all)) {
                    $_SESSION['success_message'] .= '. Water tier bands updated ' . ($apply_all ? 'for all months' : 'from ' . date('F Y', strtotime(sprintf('%04d-%02d-01', $period_year, $period_month))) . ' onwards') . '.';
                } else {
                    $_SESSION['error_message'] = 'The rates were saved, but the water tier bands could not be saved (is water-tiers-setup.sql installed?).';
                }
            }
        }
    }

    header('Location: ' . lum_app_url('/Tarrifs/view-tariffs.php?period=' . urlencode($selected_period) . '&tab=' . urlencode($tariff_type)));
    exit();

} else {
    header('Location: ' . lum_app_url('/Tarrifs/view-tariffs.php'));
    exit();
}
?>