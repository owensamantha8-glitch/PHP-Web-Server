<?php 
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
// Audit trail (switches itself off if the logger is missing; see /var/www/Lynx/bootstrap.php)
require_once __DIR__ . '/bootstrap.php';
lum_use('audit');

class tariff_conr {
    private $db;
    
    function __construct($tariff_db_conn) {
        $this->db = $tariff_db_conn;
    }

    // Tariff row currently stored for a month (read before it is replaced)
    private function audit_fetch_period($table, $period_month, $period_year) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM `$table` WHERE period_year = ? AND period_month = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$period_year, $period_month]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            error_log("LUM audit: could not read $table for $period_month/$period_year: " . $e->getMessage());
            return null;
        }
    }

    // Record a saved tariff month in the audit trail (INSERT for a new month, UPDATE with the changed rates otherwise)
    private function audit_period_saved($table, $municipality, $period_month, $period_year, $before) {
        $after = $this->audit_fetch_period($table, $period_month, $period_year);
        // The row is replaced on every save, so its id (and any timestamp) always changes: leave those out
        foreach (['id', 'created_at', 'updated_at', 'date_created', 'last_updated'] as $skip) {
            if (is_array($before)) unset($before[$skip]);
            if (is_array($after)) unset($after[$skip]);
        }
        $label = $municipality . ' tariffs ' . str_pad($period_month, 2, '0', STR_PAD_LEFT) . '/' . $period_year;
        lum_audit_log($before ? 'UPDATE' : 'INSERT', 'tariff', (int)$period_year * 100 + (int)$period_month, $label, null, $before, $after);
    }

    // =====================================================================
    // WATER TIER BANDS (lum_water_tiers in this database)
    // =====================================================================
    // All band sets of a municipality: ['Y-m-d' => [tier_no => up_to_kl|null]], or null when the table is missing
    public function get_water_tier_sets($municipality) {
        try {
            $st = $this->db->prepare("SELECT DATE_FORMAT(effective_from, '%Y-%m-%d') AS effective_from, tier_no, up_to_kl
                                      FROM lum_water_tiers WHERE municipality = ? ORDER BY effective_from, tier_no");
            $st->execute([$municipality]);
            $sets = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $sets[$r['effective_from']][(int)$r['tier_no']] = ($r['up_to_kl'] === null) ? null : (float)$r['up_to_kl'];
            }
            return $sets;
        } catch (PDOException $e) {
            error_log('LUM water tiers: could not read bands: ' . $e->getMessage());
            return null;
        }
    }

    // Save a band set. $limits = [1 => 6, 2 => 20, ..., n => null].
    // $replace_all = true: the set applies to every month (all other sets of the municipality are removed).
    public function save_water_tiers($municipality, $effective_from, array $limits, $replace_all = false) {
        lum_use('reporting'); // lumWaterTierRange()
        $before = $this->get_water_tier_sets($municipality);
        if ($before === null) return false;
        if ($replace_all) $effective_from = '2000-01-01';

        ksort($limits);
        $bands = [];
        foreach ($limits as $n => $up) $bands[] = ['up_to' => $up];

        try {
            $this->db->beginTransaction();
            if ($replace_all) {
                $this->db->prepare("DELETE FROM lum_water_tiers WHERE municipality = ?")->execute([$municipality]);
            } else {
                $this->db->prepare("DELETE FROM lum_water_tiers WHERE municipality = ? AND effective_from = ?")->execute([$municipality, $effective_from]);
            }
            $ins = $this->db->prepare("INSERT INTO lum_water_tiers (municipality, effective_from, tier_no, up_to_kl, rate_key, label) VALUES (?, ?, ?, ?, ?, ?)");
            $i = 0;
            foreach ($limits as $n => $up) {
                $ins->execute([$municipality, $effective_from, (int)$n, $up, 'water_tier_' . (int)$n,
                               'Water Charge Tier ' . (int)$n . ' (' . lumWaterTierRange($bands, $i) . ')']);
                $i++;
            }
            $this->db->commit();
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('LUM water tiers: could not save bands: ' . $e->getMessage());
            return false;
        }

        $flat = function ($sets) {
            $out = [];
            foreach ((array)$sets as $from => $set) {
                $out['from ' . $from] = implode(' / ', array_map(function ($v) { return $v === null ? 'no limit' : rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.'); }, $set)) . ' kL';
            }
            return $out;
        };
        lum_audit_log('UPDATE', 'water_tiers', 0, $municipality . ' water tier bands', null, $flat($before), $flat($this->get_water_tier_sets($municipality)),
                      $replace_all ? 'Applied to all months' : 'Applied from ' . $effective_from);
        return true;
    }

    public function save_tshwane_tariffs(
        $period_month, $period_year, $demand_season,
        $tou_charge_peak, $tou_charge_offpeak, $tou_charge_standard, $tou_charge_kva,
        $tou_charge_basic, 
        $prepaid_tou_charge_peak, $prepaid_tou_charge_offpeak, $prepaid_tou_charge_standard, 
        $prepaid_tou_charge_kva, $prepaid_tou_charge_basic,
        $business_charge_basic, $business_charge_unit,
        $business_L_charge_basic, $business_L_charge_unit,
        $business_S_charge_basic, $business_S_charge_unit,
        $water_non_domestic, $water_sewer, $water_sewer_business, $generator,
        $comm_area_electrical, $comm_area_water,
        $non_domestic_three_phase, $non_domestic_three_phase_basic = 0,
        $low_voltage_demand_charge_basic = 0,
        $low_voltage_demand_charge_unit = 0,
        $low_voltage_demand_charge_kva = 0,
        $lvds_charge_basic = 0,
        $lvds_charge_unit = 0,
        $lvds_charge_kva = 0
    ) {
        try {
            // Actively sweep existing records for the period before saving new data
            $audit_before = $this->audit_fetch_period('lum_tariffs_city_of_tshwane', $period_month, $period_year);

            $del = $this->db->prepare("DELETE FROM lum_tariffs_city_of_tshwane WHERE period_year = ? AND period_month = ?");
            $del->execute([$period_year, $period_month]);

            $sql = "INSERT INTO lum_tariffs_city_of_tshwane (
                        period_month, period_year, demand_season,
                        tshwane_tou_charge_peak, tshwane_tou_charge_offpeak, tshwane_tou_charge_standard, tshwane_tou_charge_kva,
                        tshwane_tou_charge_basic, 
                        tshwane_prepaid_tou_charge_peak, tshwane_prepaid_tou_charge_offpeak, tshwane_prepaid_tou_charge_standard, 
                        tshwane_prepaid_tou_charge_kva, tshwane_prepaid_tou_charge_basic,
                        tshwane_business_charge_basic, tshwane_business_charge_unit,
                        tshwane_business_L_charge_basic, tshwane_business_L_charge_unit,
                        tshwane_business_S_charge_basic, tshwane_business_S_charge_unit,
                        tshwane_water_non_domestic, tshwane_water_sewer, tshwane_water_sewer_business, tshwane_generator,
                        tshwane_comm_area_electrical, tshwane_comm_area_water,
                        tshwane_non_domestic_three_phase, tshwane_non_domestic_three_phase_basic,
                        tshwane_low_voltage_demand_charge_basic, tshwane_low_voltage_demand_charge_unit, tshwane_low_voltage_demand_charge_kva,
                        tshwane_lvds_charge_basic, tshwane_lvds_charge_unit, tshwane_lvds_charge_kva
                    ) VALUES (
                        :pm, :py, :demand_season,
                        :tou_charge_peak, :tou_charge_offpeak, :tou_charge_standard, :tou_charge_kva,
                        :tou_charge_basic, 
                        :prepaid_tou_charge_peak, :prepaid_tou_charge_offpeak, :prepaid_tou_charge_standard, 
                        :prepaid_tou_charge_kva, :prepaid_tou_charge_basic,
                        :business_charge_basic, :business_charge_unit,
                        :business_L_charge_basic, :business_L_charge_unit,
                        :business_S_charge_basic, :business_S_charge_unit,
                        :water_non_domestic, :water_sewer, :water_sewer_business, :generator,
                        :comm_area_electrical, :comm_area_water,
                        :non_domestic_three_phase, :non_domestic_three_phase_basic,
                        :lv_basic, :lv_unit, :lv_kva,
                        :lvds_basic, :lvds_unit, :lvds_kva
                    )";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'pm' => $period_month, 'py' => $period_year, 'demand_season' => $demand_season,
                'tou_charge_peak' => $tou_charge_peak, 'tou_charge_offpeak' => $tou_charge_offpeak, 'tou_charge_standard' => $tou_charge_standard, 'tou_charge_kva' => $tou_charge_kva,
                'tou_charge_basic' => $tou_charge_basic,
                'prepaid_tou_charge_peak' => $prepaid_tou_charge_peak, 'prepaid_tou_charge_offpeak' => $prepaid_tou_charge_offpeak, 'prepaid_tou_charge_standard' => $prepaid_tou_charge_standard, 
                'prepaid_tou_charge_kva' => $prepaid_tou_charge_kva, 'prepaid_tou_charge_basic' => $prepaid_tou_charge_basic,
                'business_charge_basic' => $business_charge_basic, 'business_charge_unit' => $business_charge_unit,
                'business_L_charge_basic' => $business_L_charge_basic, 'business_L_charge_unit' => $business_L_charge_unit,
                'business_S_charge_basic' => $business_S_charge_basic, 'business_S_charge_unit' => $business_S_charge_unit,
                'water_non_domestic' => $water_non_domestic, 'water_sewer' => $water_sewer, 'water_sewer_business' => $water_sewer_business, 'generator' => $generator,
                'comm_area_electrical' => $comm_area_electrical, 'comm_area_water' => $comm_area_water,
                'non_domestic_three_phase' => $non_domestic_three_phase,
                'non_domestic_three_phase_basic' => $non_domestic_three_phase_basic,
                'lv_basic' => $low_voltage_demand_charge_basic,
                'lv_unit' => $low_voltage_demand_charge_unit,
                'lv_kva' => $low_voltage_demand_charge_kva,
                'lvds_basic' => $lvds_charge_basic,
                'lvds_unit' => $lvds_charge_unit,
                'lvds_kva' => $lvds_charge_kva
            ]);
            $this->audit_period_saved('lum_tariffs_city_of_tshwane', 'Tshwane', $period_month, $period_year, $audit_before);
            return true;
        } catch (PDOException $e) {
            error_log("Error updating Tshwane tariff: " . $e->getMessage());
            return false;
        }
    }

    public function save_ekurhuleni_tariffs(
        $period_month, $period_year, $demand_season,
        $t_a_unit, $t_a_basic, $t_a_cap,
        $t_b_unit, $t_b_basic, $t_b_cap,
        $t_c_unit, $t_c_basic, $t_c_cap,
        $t_c_nac, $t_c_demand, $t_water, $t_sewer,
        $comn_elec, $comn_water,
        $tou_basic, $tou_peak, $tou_off, $tou_std,
        $tou_demand, $tou_demand_nac,
        $gen
    ) {
        try {
            // Actively sweep existing records for the period before saving new data
            $audit_before = $this->audit_fetch_period('lum_tarrifs_ekhurhuleni', $period_month, $period_year);

            $del = $this->db->prepare("DELETE FROM lum_tarrifs_ekhurhuleni WHERE period_year = ? AND period_month = ?");
            $del->execute([$period_year, $period_month]);

            $sql = "INSERT INTO lum_tarrifs_ekhurhuleni (
                        period_month, period_year, demand_season,
                        ekhurhuleni_charge_tariff_a_unit, ekhurhuleni_charge_tariff_a_basic, ekhurhuleni_charge_tariff_a_capacity,
                        ekhurhuleni_charge_tariff_b_unit, ekhurhuleni_charge_tariff_b_basic, ekhurhuleni_charge_tariff_b_capacity,
                        ekhurhuleni_charge_tariff_c_unit, ekhurhuleni_charge_tariff_c_basic, ekhurhuleni_charge_tariff_c_capacity,
                        ekhurhuleni_charge_tariff_c_nac, ekhurhuleni_charge_tariff_c_demand, ekhurhuleni_charge_tariff_water, ekhurhuleni_charge_tariff_water_sewer,
                        ekhurhuleni_charge_comn_area_elec, ekhurhuleni_charge_comn_area_water,
                        ekhurhuleni_charge_tariff_tou_basic, ekhurhuleni_charge_tariff_tou_peak, ekhurhuleni_charge_tariff_tou_offpeak, ekhurhuleni_charge_tariff_tou_standard,
                        ekhurhuleni_charge_tariff_tou_demand, ekhurhuleni_charge_tariff_tou_demand_nac,
                        ekhurhuleni_charge_generator
                    ) VALUES (
                        :pm, :py, :demand_season,
                        :t_a_unit, :t_a_basic, :t_a_cap,
                        :t_b_unit, :t_b_basic, :t_b_cap,
                        :t_c_unit, :t_c_basic, :t_c_cap,
                        :t_c_nac, :t_c_demand, :t_water, :t_sewer,
                        :comn_elec, :comn_water,
                        :tou_basic, :tou_peak, :tou_off, :tou_std,
                        :tou_demand, :tou_demand_nac,
                        :gen
                    )";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'pm' => $period_month, 'py' => $period_year, 'demand_season' => $demand_season,
                't_a_unit' => $t_a_unit, 't_a_basic' => $t_a_basic, 't_a_cap' => $t_a_cap,
                't_b_unit' => $t_b_unit, 't_b_basic' => $t_b_basic, 't_b_cap' => $t_b_cap,
                't_c_unit' => $t_c_unit, 't_c_basic' => $t_c_basic, 't_c_cap' => $t_c_cap,
                't_c_nac' => $t_c_nac, 't_c_demand' => $t_c_demand, 't_water' => $t_water, 't_sewer' => $t_sewer,
                'comn_elec' => $comn_elec, 'comn_water' => $comn_water,
                'tou_basic' => $tou_basic, 'tou_peak' => $tou_peak, 'tou_off' => $tou_off, 'tou_std' => $tou_std,
                'tou_demand' => $tou_demand, 'tou_demand_nac' => $tou_demand_nac,
                'gen' => $gen
            ]);
            $this->audit_period_saved('lum_tarrifs_ekhurhuleni', 'Ekurhuleni', $period_month, $period_year, $audit_before);
            return true;
        } catch (PDOException $e) {
            error_log("Error updating Ekurhuleni tariff: " . $e->getMessage());
            return false;
        }
    }

    public function save_rustenburg_tariffs(
        $period_month, $period_year, $demand_season,
        $r_nd_conv_unit, $r_nd_conv_basic,
        $r_bulk_unit, $r_bulk_basic,
        $r_all_season_demand, $r_all_season_access, $r_shared_network,
        $r_comm_elec, $r_gen,
        $r_water_basic, $r_water_0_60, $r_water_61_100, $r_water_101_150, $r_water_151_plus,
        $r_sewer_basic, $r_comm_water
    ) {
        try {
            // Actively sweep existing records for the period before saving new data
            $audit_before = $this->audit_fetch_period('lum_tariffs_rustenburg', $period_month, $period_year);

            $del = $this->db->prepare("DELETE FROM lum_tariffs_rustenburg WHERE period_year = ? AND period_month = ?");
            $del->execute([$period_year, $period_month]);

            $sql = "INSERT INTO lum_tariffs_rustenburg (
                        period_month, period_year, demand_season,
                        rustenburg_non_domestic_conventional_unit, rustenburg_non_domestic_conventional_basic_charge,
                        rustenburg_bulk_supply_and_rural_400V_unit, rustenburg_bulk_supply_and_rural_400V_basic_charge,
                        rustenburg_all_season_network_demand_charge_bulk_and_rural_400V, rustenburg_all_season_network_access_charge_bulk_and_rural_400V, rustenburg_shared_network_access_charge,
                        rustenburg_comm_area_electrical, rustenburg_generator_charge,
                        rustenburg_water_commercial_tiered_basic_charge, rustenburg_water_commercial_tiered_0_60_charge, rustenburg_water_commercial_tiered_61_100_charge,
                        rustenburg_water_commercial_tiered_101_150_charge, rustenburg_water_commercial_tiered_151_plus_charge, rustenburg_water_sewer_basic_charge, rustenburg_comm_area_water
                    ) VALUES (
                        :pm, :py, :demand_season,
                        :r_nd_conv_unit, :r_nd_conv_basic,
                        :r_bulk_unit, :r_bulk_basic,
                        :r_all_season_demand, :r_all_season_access, :r_shared_network,
                        :r_comm_elec, :r_gen,
                        :r_water_basic, :r_water_0_60, :r_water_61_100, :r_water_101_150, :r_water_151_plus,
                        :r_sewer_basic, :r_comm_water
                    )";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'pm' => $period_month, 'py' => $period_year, 'demand_season' => $demand_season,
                'r_nd_conv_unit' => $r_nd_conv_unit, 'r_nd_conv_basic' => $r_nd_conv_basic,
                'r_bulk_unit' => $r_bulk_unit, 'r_bulk_basic' => $r_bulk_basic,
                'r_all_season_demand' => $r_all_season_demand, 'r_all_season_access' => $r_all_season_access, 'r_shared_network' => $r_shared_network,
                'r_comm_elec' => $r_comm_elec, 'r_gen' => $r_gen,
                'r_water_basic' => $r_water_basic, 'r_water_0_60' => $r_water_0_60, 'r_water_61_100' => $r_water_61_100, 'r_water_101_150' => $r_water_101_150, 'r_water_151_plus' => $r_water_151_plus,
                'r_sewer_basic' => $r_sewer_basic, 'r_comm_water' => $r_comm_water
            ]);
            $this->audit_period_saved('lum_tariffs_rustenburg', 'Rustenburg', $period_month, $period_year, $audit_before);
            return true;
        } catch (PDOException $e) {
            error_log("Error updating Rustenburg tariff: " . $e->getMessage());
            return false;
        }
    }

    public function save_mkhondo_tariffs(
        $period_month, $period_year, $demand_season,
        $elec_business_less_80_basic,
        $elec_business_less_80_kwh,
        $elec_business_more_80_basic,
        $elec_business_more_80_kwh,
        $elec_industrial_small_less_50_basic,
        $elec_industrial_small_less_50_kwh,
        $elec_industrial_small_less_50_kva,
        $elec_industrial_more_50_basic,
        $elec_industrial_more_50_kwh,
        $elec_industrial_more_50_kva,
        $elec_generator_rate,
        $water_business_basic,
        $water_tier_1_0_to_6,
        $water_tier_2_7_to_20,
        $water_tier_3_21_to_40,
        $water_tier_4_41_to_60,
        $water_tier_5_above_60,
        $sewer_basic_mkhondo,
        $sewer_basic_business_m,
        $sewer_basic_business_large,
        $comm_water_contribution,
        $comm_gen_electricity
    ) {
        try {
            // Actively sweep existing records for the period before saving new data
            $audit_before = $this->audit_fetch_period('lum_tariffs_mkhondo', $period_month, $period_year);

            $del = $this->db->prepare("DELETE FROM lum_tariffs_mkhondo WHERE period_year = ? AND period_month = ?");
            $del->execute([$period_year, $period_month]);

            $sql = "INSERT INTO lum_tariffs_mkhondo (
                        period_month, period_year, demand_season,
                        mkhondo_elec_business_less_80_basic, mkhondo_elec_business_less_80_kwh,
                        mkhondo_elec_business_more_80_basic, mkhondo_elec_business_more_80_kwh,
                        mkhondo_elec_industrial_small_less_50_basic, mkhondo_elec_industrial_small_less_50_kwh, mkhondo_elec_industrial_small_less_50_kva,
                        mkhondo_elec_industrial_more_50_basic, mkhondo_elec_industrial_more_50_kwh, mkhondo_elec_industrial_more_50_kva,
                        mkhondo_elec_generator_rate,
                        mkhondo_water_business_basic, mkhondo_water_tier_1_0_to_6, mkhondo_water_tier_2_7_to_20, mkhondo_water_tier_3_21_to_40, mkhondo_water_tier_4_41_to_60, mkhondo_water_tier_5_above_60,
                        mkhondo_sewer_basic_mkhondo, mkhondo_sewer_basic_business_m, mkhondo_sewer_basic_business_large,
                        mkhondo_comm_water_contribution, mkhondo_comm_gen_electricity
                    ) VALUES (
                        :pm, :py, :demand_season,
                        :b_l80_basic, :b_l80_kwh,
                        :b_m80_basic, :b_m80_kwh,
                        :i_l50_basic, :i_l50_kwh, :i_l50_kva,
                        :i_m50_basic, :i_m50_kwh, :i_m50_kva,
                        :gen_rate,
                        :w_basic, :w_t1, :w_t2, :w_t3, :w_t4, :w_t5,
                        :s_basic, :s_basic_m, :s_basic_l,
                        :c_water, :c_gen
                    )";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'pm' => $period_month, 'py' => $period_year, 'demand_season' => $demand_season,
                'b_l80_basic' => $elec_business_less_80_basic, 'b_l80_kwh' => $elec_business_less_80_kwh,
                'b_m80_basic' => $elec_business_more_80_basic, 'b_m80_kwh' => $elec_business_more_80_kwh,
                'i_l50_basic' => $elec_industrial_small_less_50_basic, 'i_l50_kwh' => $elec_industrial_small_less_50_kwh, 'i_l50_kva' => $elec_industrial_small_less_50_kva,
                'i_m50_basic' => $elec_industrial_more_50_basic, 'i_m50_kwh' => $elec_industrial_more_50_kwh, 'i_m50_kva' => $elec_industrial_more_50_kva,
                'gen_rate' => $elec_generator_rate,
                'w_basic' => $water_business_basic, 'w_t1' => $water_tier_1_0_to_6, 'w_t2' => $water_tier_2_7_to_20, 'w_t3' => $water_tier_3_21_to_40, 'w_t4' => $water_tier_4_41_to_60, 'w_t5' => $water_tier_5_above_60,
                's_basic' => $sewer_basic_mkhondo, 's_basic_m' => $sewer_basic_business_m, 's_basic_l' => $sewer_basic_business_large,
                'c_water' => $comm_water_contribution, 'c_gen' => $comm_gen_electricity
            ]);
            $this->audit_period_saved('lum_tariffs_mkhondo', 'Mkhondo', $period_month, $period_year, $audit_before);
            return true;
        } catch (PDOException $e) {
            error_log("Error updating Mkhondo tariff: " . $e->getMessage());
            return false;
        }
    }

    public function save_plett_tariffs(
        $period_month, $period_year, $demand_season,
        $p_1ph_15a_basic, $p_1ph_15a_kwh,
        $p_1ph_30a_basic, $p_1ph_30a_kwh,
        $p_1ph_40a_basic, $p_1ph_40a_kwh,
        $p_1ph_60a_basic, $p_1ph_60a_kwh,
        $p_3ph_60a_basic, $p_3ph_60a_kwh,
        $p_3ph_60a_63a_basic, $p_3ph_60a_63a_kwh,
        $p_3ph_100a_basic, $p_3ph_100a_kwh,
        $p_lv_basic, $p_lv_kwh, $p_lv_dem, $p_lv_acc,
        $b_tou_basic, $b_tou_peak, $b_tou_std, $b_tou_off, $b_tou_dem, $b_tou_acc,
        $p_comm_elec, $p_gen,
        $w_shops_basic, $w_shops_t1, $w_shops_t2, $w_shops_t3, $w_shops_t4,
        $w_buss_basic, $w_buss_t1, $w_buss_t2, $w_buss_t3, $w_buss_t4,
        $w_rest_basic, $w_rest_t1, $w_rest_t2, $w_rest_t3, $w_rest_t4,
        $s_bus_basic, $s_rest_basic,
        $p_comm_water
    ) {
        try {
            // Actively sweep existing records for the period before saving new data
            $audit_before = $this->audit_fetch_period('lum_tariffs_plett', $period_month, $period_year);

            $del = $this->db->prepare("DELETE FROM lum_tariffs_plett WHERE period_year = ? AND period_month = ?");
            $del->execute([$period_year, $period_month]);

            $sql = "INSERT INTO lum_tariffs_plett (
                        period_month, period_year, demand_season,
                        plett_1ph_15a_basic, plett_1ph_15a_kwh,
                        plett_1ph_30a_basic, plett_1ph_30a_kwh,
                        plett_1ph_40a_basic, plett_1ph_40a_kwh,
                        plett_1ph_60a_basic, plett_1ph_60a_kwh,
                        plett_3ph_60a_basic, plett_3ph_60a_kwh,
                        plett_3ph_60a_63a_basic, plett_3ph_60a_63a_kwh,
                        plett_3ph_100a_basic, plett_3ph_100a_kwh,
                        plett_lv_basic, plett_lv_kwh, plett_lv_demand_kva, plett_lv_access_kva,
                        plett_bitou_tou_basic, plett_bitou_tou_peak, plett_bitou_tou_standard, plett_bitou_tou_offpeak, plett_bitou_tou_demand_kva, plett_bitou_tou_access_kva,
                        plett_comm_area_electrical, plett_generator_rate,
                        bitou_water_shops_basic, bitou_water_shops_tier_1_0_60, bitou_water_shops_tier_2_60_100, bitou_water_shops_tier_3_100_200, bitou_water_shops_tier_4_above_200,
                        bitou_water_buss_basic, bitou_water_buss_tier_1_0_60, bitou_water_buss_tier_2_60_100, bitou_water_buss_tier_3_100_200, bitou_water_buss_tier_4_above_200,
                        bitou_water_rest_basic, bitou_water_rest_tier_1_0_60, bitou_water_rest_tier_2_60_100, bitou_water_rest_tier_3_100_200, bitou_water_rest_tier_4_above_200,
                        bitou_sewer_bus_basic, bitou_sewer_rest_basic,
                        plett_comm_area_water
                    ) VALUES (
                        :pm, :py, :demand_season,
                        :p_1ph_15a_b, :p_1ph_15a_k,
                        :p_1ph_30a_b, :p_1ph_30a_k,
                        :p_1ph_40a_b, :p_1ph_40a_k,
                        :p_1ph_60a_b, :p_1ph_60a_k,
                        :p_3ph_60a_b, :p_3ph_60a_k,
                        :p_3ph_60a_63a_b, :p_3ph_60a_63a_k,
                        :p_3ph_100a_b, :p_3ph_100a_k,
                        :p_lv_b, :p_lv_k, :p_lv_d, :p_lv_a,
                        :b_tou_b, :b_tou_p, :b_tou_s, :b_tou_o, :b_tou_d, :b_tou_a,
                        :p_ce, :p_gen,
                        :w_s_b, :w_s_t1, :w_s_t2, :w_s_t3, :w_s_t4,
                        :w_b_b, :w_b_t1, :w_b_t2, :w_b_t3, :w_b_t4,
                        :w_r_b, :w_r_t1, :w_r_t2, :w_r_t3, :w_r_t4,
                        :s_b_b, :s_r_b,
                        :p_cw
                    )";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'pm' => $period_month, 'py' => $period_year, 'demand_season' => $demand_season,
                'p_1ph_15a_b' => $p_1ph_15a_basic, 'p_1ph_15a_k' => $p_1ph_15a_kwh,
                'p_1ph_30a_b' => $p_1ph_30a_basic, 'p_1ph_30a_k' => $p_1ph_30a_kwh,
                'p_1ph_40a_b' => $p_1ph_40a_basic, 'p_1ph_40a_k' => $p_1ph_40a_kwh,
                'p_1ph_60a_b' => $p_1ph_60a_basic, 'p_1ph_60a_k' => $p_1ph_60a_kwh,
                'p_3ph_60a_b' => $p_3ph_60a_basic, 'p_3ph_60a_k' => $p_3ph_60a_kwh,
                'p_3ph_60a_63a_b' => $p_3ph_60a_63a_basic, 'p_3ph_60a_63a_k' => $p_3ph_60a_63a_kwh,
                'p_3ph_100a_b' => $p_3ph_100a_basic, 'p_3ph_100a_k' => $p_3ph_100a_kwh,
                'p_lv_b' => $p_lv_basic, 'p_lv_k' => $p_lv_kwh, 'p_lv_d' => $p_lv_dem, 'p_lv_a' => $p_lv_acc,
                'b_tou_b' => $b_tou_basic, 'b_tou_p' => $b_tou_peak, 'b_tou_s' => $b_tou_std, 'b_tou_o' => $b_tou_off, 'b_tou_d' => $b_tou_dem, 'b_tou_a' => $b_tou_acc,
                'p_ce' => $p_comm_elec, 'p_gen' => $p_gen,
                'w_s_b' => $w_shops_basic, 'w_s_t1' => $w_shops_t1, 'w_s_t2' => $w_shops_t2, 'w_s_t3' => $w_shops_t3, 'w_s_t4' => $w_shops_t4,
                'w_b_b' => $w_buss_basic, 'w_b_t1' => $w_buss_t1, 'w_b_t2' => $w_buss_t2, 'w_b_t3' => $w_buss_t3, 'w_b_t4' => $w_buss_t4,
                'w_r_b' => $w_rest_basic, 'w_r_t1' => $w_rest_t1, 'w_r_t2' => $w_rest_t2, 'w_r_t3' => $w_rest_t3, 'w_r_t4' => $w_rest_t4,
                's_b_b' => $s_bus_basic, 's_r_b' => $s_rest_basic,
                'p_cw' => $p_comm_water
            ]);
            $this->audit_period_saved('lum_tariffs_plett', 'Plettenberg Bay', $period_month, $period_year, $audit_before);
            return true;
        } catch (PDOException $e) {
            error_log("Error updating Plettenberg Bay tariff: " . $e->getMessage());
            return false;
        }
    }

    public function save_george_tariffs(
        $period_month, $period_year, $demand_season,
        $g_elec_general_basic, $g_elec_general_kwh, $g_elec_general_amps,
        $g_elec_bulk_tou_basic, $g_elec_bulk_tou_peak, $g_elec_bulk_tou_standard, $g_elec_bulk_tou_offpeak,
        $g_elec_bulk_tou_demand_kva, $g_elec_bulk_tou_access_kva,
        $g_water_ind_basic,
        $g_water_ind_tier_1_0_6, $g_water_ind_tier_2_6_15, $g_water_ind_tier_3_15_20,
        $g_water_ind_tier_4_20_30, $g_water_ind_tier_5_30_50, $g_water_ind_tier_6_50_75, $g_water_ind_tier_7_above_75,
        $g_sewer_basic,
        $g_comm_area_electrical, $g_comm_area_water, $g_generator_rate
    ) {
        try {
            // Actively sweep existing records for the period before saving new data
            $audit_before = $this->audit_fetch_period('lum_tariffs_george', $period_month, $period_year);

            $del = $this->db->prepare("DELETE FROM lum_tariffs_george WHERE period_year = ? AND period_month = ?");
            $del->execute([$period_year, $period_month]);

            $sql = "INSERT INTO lum_tariffs_george (
                        period_month, period_year, demand_season,
                        george_elec_general_basic, george_elec_general_kwh, george_elec_general_amps,
                        george_elec_bulk_tou_basic, george_elec_bulk_tou_peak, george_elec_bulk_tou_standard, george_elec_bulk_tou_offpeak,
                        george_elec_bulk_tou_demand_kva, george_elec_bulk_tou_access_kva,
                        george_water_ind_basic,
                        george_water_ind_tier_1_0_6, george_water_ind_tier_2_6_15, george_water_ind_tier_3_15_20,
                        george_water_ind_tier_4_20_30, george_water_ind_tier_5_30_50, george_water_ind_tier_6_50_75, george_water_ind_tier_7_above_75,
                        george_sewer_basic,
                        george_comm_area_electrical, george_comm_area_water, george_generator_rate
                    ) VALUES (
                        :pm, :py, :demand_season,
                        :g_gen_b, :g_gen_k, :g_gen_a,
                        :g_tou_b, :g_tou_p, :g_tou_s, :g_tou_o,
                        :g_tou_d, :g_tou_a,
                        :g_w_b,
                        :g_w_t1, :g_w_t2, :g_w_t3,
                        :g_w_t4, :g_w_t5, :g_w_t6, :g_w_t7,
                        :g_s_b,
                        :g_ce, :g_cw, :g_gen
                    )";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'pm' => $period_month, 'py' => $period_year, 'demand_season' => $demand_season,
                'g_gen_b' => $g_elec_general_basic, 'g_gen_k' => $g_elec_general_kwh, 'g_gen_a' => $g_elec_general_amps,
                'g_tou_b' => $g_elec_bulk_tou_basic, 'g_tou_p' => $g_elec_bulk_tou_peak, 'g_tou_s' => $g_elec_bulk_tou_standard, 'g_tou_o' => $g_elec_bulk_tou_offpeak,
                'g_tou_d' => $g_elec_bulk_tou_demand_kva, 'g_tou_a' => $g_elec_bulk_tou_access_kva,
                'g_w_b' => $g_water_ind_basic,
                'g_w_t1' => $g_water_ind_tier_1_0_6, 'g_w_t2' => $g_water_ind_tier_2_6_15, 'g_w_t3' => $g_water_ind_tier_3_15_20,
                'g_w_t4' => $g_water_ind_tier_4_20_30, 'g_w_t5' => $g_water_ind_tier_5_30_50, 'g_w_t6' => $g_water_ind_tier_6_50_75, 'g_w_t7' => $g_water_ind_tier_7_above_75,
                'g_s_b' => $g_sewer_basic,
                'g_ce' => $g_comm_area_electrical, 'g_cw' => $g_comm_area_water, 'g_gen' => $g_generator_rate
            ]);
            $this->audit_period_saved('lum_tariffs_george', 'George', $period_month, $period_year, $audit_before);
            return true;
        } catch (PDOException $e) {
            error_log("Error updating George tariff: " . $e->getMessage());
            return false;
        }
    }
}
?>