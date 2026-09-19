<?php 
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }

// Audit trail (does nothing when the logger is missing)
require_once '/var/www/Lynx/bootstrap.php';
lum_use('audit');

if (!class_exists('tenant_conr')) {
    class tenant_conr {

        private $db;
        
        function __construct($tenant_db_conn) {
            $this->db = $tenant_db_conn;
        }

        // Current database row of a tenant (used for the audit trail)
        // -------------------------------------------------------------------
        // Keep lum_tenant_meters in step with the meter fields on the tenant.
        // A meter that disappears is closed off on $change_date; one that appears
        // opens on it. Nothing is ever deleted, so the history stays readable.
        // Called after a save; a failure is logged and never stops the save.
        // -------------------------------------------------------------------
        private function sync_meter_assignments($tenant_id, $row, $change_date = null) {
            try {
                if (!$row) return false;
                // The assignment table only exists once the meter link has been set up
                $has = $this->db->query("SELECT COUNT(*) FROM information_schema.TABLES
                                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lum_tenant_meters'")->fetchColumn();
                if (!$has) return false;

                $ref = trim((string)($row['tenant_ref'] ?? ''));
                if ($ref === '') {
                    $ref = 'T' . str_pad((string)$tenant_id, 5, '0', STR_PAD_LEFT);
                    $u = $this->db->prepare("UPDATE lum_tenants SET tenant_ref = :r WHERE tenant_id = :id AND (tenant_ref IS NULL OR tenant_ref = '')");
                    $u->execute(['r' => $ref, 'id' => $tenant_id]);
                }

                $change_date = $change_date ?: date('Y-m-d');
                $property = (string)($row['tenant_property'] ?? '');

                // What the tenant form now says
                $wanted = [];
                for ($i = 1; $i <= 3; $i++) {
                    $serial = trim((string)($row['tenant_electricalMeter_0' . $i] ?? ''));
                    if ($serial !== '' && $serial !== '0') {
                        $wanted[$serial] = ['kind' => 'electricity', 'slot' => $i,
                                            'ct' => (float)($row['tenant_electricalMeter_0' . $i . '_ct_ratio'] ?? 1)];
                    }
                }
                for ($i = 1; $i <= 4; $i++) {
                    $serial = trim((string)($row['tenant_waterMeter_0' . $i] ?? ''));
                    if ($serial !== '' && $serial !== '0') {
                        $wanted[$serial] = ['kind' => 'water', 'slot' => $i, 'ct' => 1.0];
                    }
                }

                // What the assignment table currently has open for this business
                $open = [];
                // Open means not ended yet: an assignment carrying a future date
                // (a lease end, say) is still the meter in use today.
                $stmt = $this->db->prepare("SELECT link_id, meter_serial, meter_kind, slot, ct_ratio, valid_from
                                              FROM lum_tenant_meters
                                             WHERE tenant_ref = :ref AND (valid_to IS NULL OR valid_to >= CURDATE())");
                $stmt->execute(['ref' => $ref]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) { $open[trim((string)$a['meter_serial'])] = $a; }

                // Gone from the form: close it on the day of the change
                $close = $this->db->prepare("UPDATE lum_tenant_meters
                                                SET valid_to = :d, updated_at = NOW()
                                              WHERE link_id = :id");
                foreach ($open as $serial => $a) {
                    if (!isset($wanted[$serial])) {
                        // A meter removed on the first day it applied would leave an empty period
                        $end = ($a['valid_from'] && $a['valid_from'] > $change_date) ? $a['valid_from'] : $change_date;
                        $close->execute(['d' => $end, 'id' => $a['link_id']]);
                    }
                }

                // New on the form: open it. The first assignments of a new tenant start
                // at their occupancy date; later changes start on the day of the change.
                $start = $change_date;
                if (empty($open) && !empty($row['tenant_occupancy_start_date'])) {
                    $start = $row['tenant_occupancy_start_date'];
                }
                $add = $this->db->prepare("INSERT IGNORE INTO lum_tenant_meters
                        (tenant_ref, tenant_id, property, meter_serial, meter_kind, slot, ct_ratio, valid_from, valid_to, source, confidence, created_by, notes)
                        VALUES (:ref, :tid, :prop, :serial, :kind, :slot, :ct, :from, :to, 'current', 'high', :who, NULL)");
                foreach ($wanted as $serial => $w) {
                    if (isset($open[$serial])) {
                        // Still there: only the CT ratio or the slot can have changed
                        if ((float)$open[$serial]['ct_ratio'] !== (float)$w['ct'] || (int)$open[$serial]['slot'] !== (int)$w['slot']) {
                            $upd = $this->db->prepare("UPDATE lum_tenant_meters SET ct_ratio = :ct, slot = :slot, updated_at = NOW() WHERE link_id = :id");
                            $upd->execute(['ct' => $w['ct'], 'slot' => $w['slot'], 'id' => $open[$serial]['link_id']]);
                        }
                        continue;
                    }
                    $add->execute([
                        'ref' => $ref, 'tid' => $tenant_id, 'prop' => $property, 'serial' => $serial,
                        'kind' => $w['kind'], 'slot' => $w['slot'], 'ct' => $w['ct'],
                        // No end date: an assignment ends when the meter stops serving the
                        // tenant, not when the lease ends. The occupancy dates govern billing.
                        'from' => $start, 'to' => null,
                        'who' => ($_SESSION['user_name'] ?? null),
                    ]);
                }
                return true;
            } catch (Throwable $e) {
                error_log('LUM meter assignments not updated for tenant ' . $tenant_id . ': ' . $e->getMessage());
                return false;
            }
        }

        private function fetch_tenant_row($tenant_id) {
            try {
                $stmt = $this->db->prepare("SELECT * FROM lum_tenants WHERE tenant_id = :id");
                $stmt->execute([':id' => $tenant_id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                return $row ?: null;
            } catch (PDOException $e) {
                error_log('LUM audit: could not read tenant ' . $tenant_id . ': ' . $e->getMessage());
                return null;
            }
        }

        public function tenant_registration(
            $tenant_property, $tenant_name, $tenant_code, $tenant_shop, $tenant_shop_area, 
            $tenant_amps, $tenant_comm_area, $tenant_electricalMeter_01, $tenant_electricalMeter_02, 
            $tenant_electricalMeter_03, $tenant_waterMeter_01, $tenant_waterMeter_02, $tenant_waterMeter_03, $tenant_waterMeter_04,
            $tenant_electrical_tariff_charge, $tenant_electrical_commArea_charge, $tenant_water_tariff_charge, 
            $tenant_water_sewer_tariff_charge, $tenant_water_commArea_charge, $tenant_generator_tariff_charge,
            $tenant_pays_electrical_commArea, $tenant_pays_water_commArea, $tenant_council_refuse_charge,
            $shared_nac_contribute, $pays_shared_nac,
            $occupancy_start_date, $occupancy_end_date, $tenant_onsite, $email, $cell_number,
            $tenant_electricalMeter_01_ct_ratio = 1.0, $tenant_electricalMeter_02_ct_ratio = 1.0, $tenant_electricalMeter_03_ct_ratio = 1.0,
            $tenant_pays_electrical_basic_charge = 'Yes', $tenant_pays_water_basic_charge = 'Yes', $tenant_pays_sewer_basic_charge = 'Yes',
            $tenant_pays_electrical_tariff_charge = 'Yes', $tenant_pays_demand_tariff_charge = 'Yes', $tenant_pays_generator_tariff_charge = 'Yes'
        ) {
            try {
                $sql = "INSERT INTO lum_tenants (
                            tenant_property, tenant_name, tenant_code, tenant_shop, tenant_shop_area, 
                            tenant_amps, tenant_comm_area, tenant_electricalMeter_01, tenant_electricalMeter_02, 
                            tenant_electricalMeter_03, tenant_waterMeter_01, tenant_waterMeter_02, tenant_waterMeter_03, tenant_waterMeter_04,
                            tenant_electrical_tariff_charge, tenant_electrical_commArea_charge, tenant_water_tariff_charge, 
                            tenant_water_sewer_tariff_charge, tenant_water_commArea_charge, tenant_generator_tariff_charge,
                            tenant_pays_electrical_commArea, tenant_pays_water_commArea, tenant_council_refuse_charge,
                            shared_nac_contribute, pays_shared_nac,
                            tenant_occupancy_start_date, tenant_occupancy_end_date, tenant_onsite, tenant_email, tenant_cell_number,
                            tenant_electricalMeter_01_ct_ratio, tenant_electricalMeter_02_ct_ratio, tenant_electricalMeter_03_ct_ratio,
                            tenant_pays_electrical_basic_charge, tenant_pays_water_basic_charge, tenant_pays_sewer_basic_charge,
                            tenant_pays_electrical_tariff_charge, tenant_pays_demand_tariff_charge, tenant_pays_generator_tariff_charge
                        ) VALUES (
                            :tenant_property, :tenant_name, :tenant_code, :tenant_shop, :tenant_shop_area, 
                            :tenant_amps, :tenant_comm_area, :tenant_electricalMeter_01, :tenant_electricalMeter_02, 
                            :tenant_electricalMeter_03, :tenant_waterMeter_01, :tenant_waterMeter_02, :tenant_waterMeter_03, :tenant_waterMeter_04,
                            :tenant_electrical_tariff_charge, :tenant_electrical_commArea_charge, :tenant_water_tariff_charge, 
                            :tenant_water_sewer_tariff_charge, :tenant_water_commArea_charge, :tenant_generator_tariff_charge,
                            :tenant_pays_electrical_commArea, :tenant_pays_water_commArea, :tenant_council_refuse_charge,
                            :shared_nac_contribute, :pays_shared_nac,
                            :occupancy_start_date, :occupancy_end_date, :tenant_onsite, :email, :cell_number,
                            :tenant_electricalMeter_01_ct_ratio, :tenant_electricalMeter_02_ct_ratio, :tenant_electricalMeter_03_ct_ratio,
                            :tenant_pays_electrical_basic_charge, :tenant_pays_water_basic_charge, :tenant_pays_sewer_basic_charge,
                            :tenant_pays_electrical_tariff_charge, :tenant_pays_demand_tariff_charge, :tenant_pays_generator_tariff_charge
                        )";

                $stmt = $this->db->prepare($sql);

                $stmt->bindparam(':tenant_property', $tenant_property);
                $stmt->bindparam(':tenant_name', $tenant_name);
                $stmt->bindparam(':tenant_code', $tenant_code);
                $stmt->bindparam(':tenant_shop', $tenant_shop);
                $stmt->bindparam(':tenant_shop_area', $tenant_shop_area);
                $stmt->bindparam(':tenant_amps', $tenant_amps);
                $stmt->bindparam(':tenant_comm_area', $tenant_comm_area);
                $stmt->bindparam(':tenant_electricalMeter_01', $tenant_electricalMeter_01);
                $stmt->bindparam(':tenant_electricalMeter_02', $tenant_electricalMeter_02);
                $stmt->bindparam(':tenant_electricalMeter_03', $tenant_electricalMeter_03);
                $stmt->bindparam(':tenant_waterMeter_01', $tenant_waterMeter_01);
                $stmt->bindparam(':tenant_waterMeter_02', $tenant_waterMeter_02);
                $stmt->bindparam(':tenant_waterMeter_03', $tenant_waterMeter_03);
                $stmt->bindparam(':tenant_waterMeter_04', $tenant_waterMeter_04);
                $stmt->bindparam(':tenant_electrical_tariff_charge', $tenant_electrical_tariff_charge);
                $stmt->bindparam(':tenant_electrical_commArea_charge', $tenant_electrical_commArea_charge);
                $stmt->bindparam(':tenant_water_tariff_charge', $tenant_water_tariff_charge);
                $stmt->bindparam(':tenant_water_sewer_tariff_charge', $tenant_water_sewer_tariff_charge);
                $stmt->bindparam(':tenant_water_commArea_charge', $tenant_water_commArea_charge);
                $stmt->bindparam(':tenant_generator_tariff_charge', $tenant_generator_tariff_charge);
                $stmt->bindparam(':tenant_pays_electrical_commArea', $tenant_pays_electrical_commArea);
                $stmt->bindparam(':tenant_pays_water_commArea', $tenant_pays_water_commArea);
                $stmt->bindparam(':tenant_council_refuse_charge', $tenant_council_refuse_charge);
                $stmt->bindparam(':shared_nac_contribute', $shared_nac_contribute);
                $stmt->bindparam(':pays_shared_nac', $pays_shared_nac);
                $stmt->bindparam(':occupancy_start_date', $occupancy_start_date);
                $stmt->bindparam(':occupancy_end_date', $occupancy_end_date);
                $stmt->bindparam(':tenant_onsite', $tenant_onsite);
                $stmt->bindparam(':email', $email);
                $stmt->bindparam(':cell_number', $cell_number);
                $stmt->bindparam(':tenant_electricalMeter_01_ct_ratio', $tenant_electricalMeter_01_ct_ratio);
                $stmt->bindparam(':tenant_electricalMeter_02_ct_ratio', $tenant_electricalMeter_02_ct_ratio);
                $stmt->bindparam(':tenant_electricalMeter_03_ct_ratio', $tenant_electricalMeter_03_ct_ratio);
                $stmt->bindparam(':tenant_pays_electrical_basic_charge', $tenant_pays_electrical_basic_charge);
                $stmt->bindparam(':tenant_pays_water_basic_charge', $tenant_pays_water_basic_charge);
                $stmt->bindparam(':tenant_pays_sewer_basic_charge', $tenant_pays_sewer_basic_charge);
                $stmt->bindparam(':tenant_pays_electrical_tariff_charge', $tenant_pays_electrical_tariff_charge);
                $stmt->bindparam(':tenant_pays_demand_tariff_charge', $tenant_pays_demand_tariff_charge);
                $stmt->bindparam(':tenant_pays_generator_tariff_charge', $tenant_pays_generator_tariff_charge);

                $stmt->execute();
                
                $tenant_id = $this->db->lastInsertId();
                
                // Every change is also recorded in lum_tenants_legacy
                $legacy_sql = "INSERT INTO lum_tenants_legacy (
                            action_type, tenant_id, tenant_property, tenant_name, tenant_code, tenant_shop, tenant_shop_area, 
                            tenant_amps, tenant_comm_area, tenant_electricalMeter_01, tenant_electricalMeter_01_ct_ratio, 
                            tenant_electricalMeter_02, tenant_electricalMeter_02_ct_ratio, tenant_electricalMeter_03, 
                            tenant_electricalMeter_03_ct_ratio, tenant_waterMeter_01, tenant_waterMeter_02, 
                            tenant_waterMeter_03, tenant_waterMeter_04, tenant_electrical_tariff_charge, 
                            tenant_electrical_commArea_charge, tenant_water_tariff_charge, tenant_water_sewer_tariff_charge, 
                            tenant_water_commArea_charge, tenant_generator_tariff_charge, tenant_pays_electrical_commArea, 
                            tenant_pays_water_commArea, tenant_council_refuse_charge, shared_nac_contribute, pays_shared_nac,
                            tenant_occupancy_start_date, tenant_occupancy_end_date, tenant_email, tenant_cell_number, tenant_onsite,
                            tenant_pays_electrical_basic_charge, tenant_pays_water_basic_charge, tenant_pays_sewer_basic_charge,
                            tenant_pays_electrical_tariff_charge, tenant_pays_generator_tariff_charge, tenant_pays_demand_tariff_charge
                        ) VALUES (
                            'INSERT', :tenant_id, :tenant_property, :tenant_name, :tenant_code, :tenant_shop, :tenant_shop_area, 
                            :tenant_amps, :tenant_comm_area, :tenant_electricalMeter_01, :tenant_electricalMeter_01_ct_ratio, 
                            :tenant_electricalMeter_02, :tenant_electricalMeter_02_ct_ratio, :tenant_electricalMeter_03, 
                            :tenant_electricalMeter_03_ct_ratio, :tenant_waterMeter_01, :tenant_waterMeter_02, 
                            :tenant_waterMeter_03, :tenant_waterMeter_04, :tenant_electrical_tariff_charge, 
                            :tenant_electrical_commArea_charge, :tenant_water_tariff_charge, :tenant_water_sewer_tariff_charge, 
                            :tenant_water_commArea_charge, :tenant_generator_tariff_charge, :tenant_pays_electrical_commArea, 
                            :tenant_pays_water_commArea, :tenant_council_refuse_charge, :shared_nac_contribute, :pays_shared_nac,
                            :occupancy_start_date, :occupancy_end_date, :email, :cell_number, :tenant_onsite,
                            :tenant_pays_electrical_basic_charge, :tenant_pays_water_basic_charge, :tenant_pays_sewer_basic_charge,
                            :tenant_pays_electrical_tariff_charge, :tenant_pays_generator_tariff_charge, :tenant_pays_demand_tariff_charge
                        )";
                        
                $legacy_stmt = $this->db->prepare($legacy_sql);
                $legacy_stmt->bindparam(':tenant_id', $tenant_id);
                $legacy_stmt->bindparam(':tenant_property', $tenant_property);
                $legacy_stmt->bindparam(':tenant_name', $tenant_name);
                $legacy_stmt->bindparam(':tenant_code', $tenant_code);
                $legacy_stmt->bindparam(':tenant_shop', $tenant_shop);
                $legacy_stmt->bindparam(':tenant_shop_area', $tenant_shop_area);
                $legacy_stmt->bindparam(':tenant_amps', $tenant_amps);
                $legacy_stmt->bindparam(':tenant_comm_area', $tenant_comm_area);
                $legacy_stmt->bindparam(':tenant_electricalMeter_01', $tenant_electricalMeter_01);
                $legacy_stmt->bindparam(':tenant_electricalMeter_01_ct_ratio', $tenant_electricalMeter_01_ct_ratio);
                $legacy_stmt->bindparam(':tenant_electricalMeter_02', $tenant_electricalMeter_02);
                $legacy_stmt->bindparam(':tenant_electricalMeter_02_ct_ratio', $tenant_electricalMeter_02_ct_ratio);
                $legacy_stmt->bindparam(':tenant_electricalMeter_03', $tenant_electricalMeter_03);
                $legacy_stmt->bindparam(':tenant_electricalMeter_03_ct_ratio', $tenant_electricalMeter_03_ct_ratio);
                $legacy_stmt->bindparam(':tenant_waterMeter_01', $tenant_waterMeter_01);
                $legacy_stmt->bindparam(':tenant_waterMeter_02', $tenant_waterMeter_02);
                $legacy_stmt->bindparam(':tenant_waterMeter_03', $tenant_waterMeter_03);
                $legacy_stmt->bindparam(':tenant_waterMeter_04', $tenant_waterMeter_04);
                $legacy_stmt->bindparam(':tenant_electrical_tariff_charge', $tenant_electrical_tariff_charge);
                $legacy_stmt->bindparam(':tenant_electrical_commArea_charge', $tenant_electrical_commArea_charge);
                $legacy_stmt->bindparam(':tenant_water_tariff_charge', $tenant_water_tariff_charge);
                $legacy_stmt->bindparam(':tenant_water_sewer_tariff_charge', $tenant_water_sewer_tariff_charge);
                $legacy_stmt->bindparam(':tenant_water_commArea_charge', $tenant_water_commArea_charge);
                $legacy_stmt->bindparam(':tenant_generator_tariff_charge', $tenant_generator_tariff_charge);
                $legacy_stmt->bindparam(':tenant_pays_electrical_commArea', $tenant_pays_electrical_commArea);
                $legacy_stmt->bindparam(':tenant_pays_water_commArea', $tenant_pays_water_commArea);
                $legacy_stmt->bindparam(':tenant_council_refuse_charge', $tenant_council_refuse_charge);
                $legacy_stmt->bindparam(':shared_nac_contribute', $shared_nac_contribute);
                $legacy_stmt->bindparam(':pays_shared_nac', $pays_shared_nac);
                $legacy_stmt->bindparam(':occupancy_start_date', $occupancy_start_date);
                $legacy_stmt->bindparam(':occupancy_end_date', $occupancy_end_date);
                $legacy_stmt->bindparam(':email', $email);
                $legacy_stmt->bindparam(':cell_number', $cell_number);
                $legacy_stmt->bindparam(':tenant_onsite', $tenant_onsite);
                $legacy_stmt->bindparam(':tenant_pays_electrical_basic_charge', $tenant_pays_electrical_basic_charge);
                $legacy_stmt->bindparam(':tenant_pays_water_basic_charge', $tenant_pays_water_basic_charge);
                $legacy_stmt->bindparam(':tenant_pays_sewer_basic_charge', $tenant_pays_sewer_basic_charge);
                $legacy_stmt->bindparam(':tenant_pays_electrical_tariff_charge', $tenant_pays_electrical_tariff_charge);
                $legacy_stmt->bindparam(':tenant_pays_demand_tariff_charge', $tenant_pays_demand_tariff_charge);
                $legacy_stmt->bindparam(':tenant_pays_generator_tariff_charge', $tenant_pays_generator_tariff_charge);
                
                $legacy_stmt->execute();

                lum_audit_stamp_tenant_legacy($this->db, $this->db->lastInsertId());
                $after = $this->fetch_tenant_row($tenant_id);
                $this->sync_meter_assignments($tenant_id, $after);
                lum_audit_log('INSERT', 'tenant', $tenant_id, lum_audit_tenant_label($after), $after['tenant_property'] ?? $tenant_property, null, $after);

                // The new tenant's id, so the caller can finish setting it up.
                // Still truthy, so "if ($result)" keeps working as before.
                return (int)$tenant_id;
        
            } catch (PDOException $e) {
                error_log("Tenant Registration Error: " . $e->getMessage());
                throw $e;
            }
        }

        public function edit_tenant(
            $tenant_id, $tenant_property, $tenant_name, $tenant_code, $tenant_shop, $tenant_shop_area, 
            $tenant_amps, $tenant_comm_area, $tenant_electricalMeter_01, $tenant_electricalMeter_02, 
            $tenant_electricalMeter_03, $tenant_waterMeter_01, $tenant_waterMeter_02, $tenant_waterMeter_03, $tenant_waterMeter_04,
            $tenant_electrical_tariff_charge, $tenant_electrical_commArea_charge, $tenant_water_tariff_charge, 
            $tenant_water_sewer_tariff_charge, $tenant_water_commArea_charge, $tenant_generator_tariff_charge,
            $tenant_pays_electrical_commArea, $tenant_pays_water_commArea, $tenant_council_refuse_charge,
            $shared_nac_contribute, $pays_shared_nac,
            $occupancy_start_date, $occupancy_end_date, $tenant_onsite, $email, $cell_number,
            $tenant_electricalMeter_01_ct_ratio = 1.0, $tenant_electricalMeter_02_ct_ratio = 1.0, $tenant_electricalMeter_03_ct_ratio = 1.0,
            $tenant_pays_electrical_basic_charge = 'Yes', $tenant_pays_water_basic_charge = 'Yes', $tenant_pays_sewer_basic_charge = 'Yes',
            $tenant_pays_electrical_tariff_charge = 'Yes', $tenant_pays_demand_tariff_charge = 'Yes', $tenant_pays_generator_tariff_charge = 'Yes'
        ) {
            try {
                // Row as it was before this update (audit trail)
                $before = $this->fetch_tenant_row($tenant_id);

                $sql = "UPDATE lum_tenants 
                        SET tenant_property = :tenant_property, 
                            tenant_name = :tenant_name, 
                            tenant_code = :tenant_code, 
                            tenant_shop = :tenant_shop, 
                            tenant_shop_area = :tenant_shop_area, 
                            tenant_amps = :tenant_amps, 
                            tenant_comm_area = :tenant_comm_area, 
                            tenant_electricalMeter_01 = :tenant_electricalMeter_01, 
                            tenant_electricalMeter_02 = :tenant_electricalMeter_02, 
                            tenant_electricalMeter_03 = :tenant_electricalMeter_03, 
                            tenant_waterMeter_01 = :tenant_waterMeter_01, 
                            tenant_waterMeter_02 = :tenant_waterMeter_02, 
                            tenant_waterMeter_03 = :tenant_waterMeter_03, 
                            tenant_waterMeter_04 = :tenant_waterMeter_04, 
                            tenant_electrical_tariff_charge = :tenant_electrical_tariff_charge, 
                            tenant_electrical_commArea_charge = :tenant_electrical_commArea_charge, 
                            tenant_water_tariff_charge = :tenant_water_tariff_charge, 
                            tenant_water_sewer_tariff_charge = :tenant_water_sewer_tariff_charge, 
                            tenant_water_commArea_charge = :tenant_water_commArea_charge, 
                            tenant_generator_tariff_charge = :tenant_generator_tariff_charge,
                            tenant_pays_electrical_commArea = :tenant_pays_electrical_commArea,
                            tenant_pays_water_commArea = :tenant_pays_water_commArea,
                            tenant_council_refuse_charge = :tenant_council_refuse_charge,
                            shared_nac_contribute = :shared_nac_contribute,
                            pays_shared_nac = :pays_shared_nac,
                            tenant_occupancy_start_date = :occupancy_start_date,
                            tenant_occupancy_end_date = :occupancy_end_date,
                            tenant_onsite = :tenant_onsite,
                            tenant_email = :email,
                            tenant_cell_number = :cell_number,
                            tenant_electricalMeter_01_ct_ratio = :tenant_electricalMeter_01_ct_ratio,
                            tenant_electricalMeter_02_ct_ratio = :tenant_electricalMeter_02_ct_ratio,
                            tenant_electricalMeter_03_ct_ratio = :tenant_electricalMeter_03_ct_ratio,
                            tenant_pays_electrical_basic_charge = :tenant_pays_electrical_basic_charge,
                            tenant_pays_water_basic_charge = :tenant_pays_water_basic_charge,
                            tenant_pays_sewer_basic_charge = :tenant_pays_sewer_basic_charge,
                            tenant_pays_electrical_tariff_charge = :tenant_pays_electrical_tariff_charge,
                            tenant_pays_demand_tariff_charge = :tenant_pays_demand_tariff_charge,
                            tenant_pays_generator_tariff_charge = :tenant_pays_generator_tariff_charge
                        WHERE tenant_id = :tenant_id";

                $stmt = $this->db->prepare($sql);

                $stmt->bindparam(':tenant_id', $tenant_id);
                $stmt->bindparam(':tenant_property', $tenant_property);
                $stmt->bindparam(':tenant_name', $tenant_name);
                $stmt->bindparam(':tenant_code', $tenant_code);
                $stmt->bindparam(':tenant_shop', $tenant_shop);
                $stmt->bindparam(':tenant_shop_area', $tenant_shop_area);
                $stmt->bindparam(':tenant_amps', $tenant_amps);
                $stmt->bindparam(':tenant_comm_area', $tenant_comm_area);
                $stmt->bindparam(':tenant_electricalMeter_01', $tenant_electricalMeter_01);
                $stmt->bindparam(':tenant_electricalMeter_02', $tenant_electricalMeter_02);
                $stmt->bindparam(':tenant_electricalMeter_03', $tenant_electricalMeter_03);
                $stmt->bindparam(':tenant_waterMeter_01', $tenant_waterMeter_01);
                $stmt->bindparam(':tenant_waterMeter_02', $tenant_waterMeter_02);
                $stmt->bindparam(':tenant_waterMeter_03', $tenant_waterMeter_03);
                $stmt->bindparam(':tenant_waterMeter_04', $tenant_waterMeter_04);
                $stmt->bindparam(':tenant_electrical_tariff_charge', $tenant_electrical_tariff_charge);
                $stmt->bindparam(':tenant_electrical_commArea_charge', $tenant_electrical_commArea_charge);
                $stmt->bindparam(':tenant_water_tariff_charge', $tenant_water_tariff_charge);
                $stmt->bindparam(':tenant_water_sewer_tariff_charge', $tenant_water_sewer_tariff_charge);
                $stmt->bindparam(':tenant_water_commArea_charge', $tenant_water_commArea_charge);
                $stmt->bindparam(':tenant_generator_tariff_charge', $tenant_generator_tariff_charge);
                $stmt->bindparam(':tenant_pays_electrical_commArea', $tenant_pays_electrical_commArea);
                $stmt->bindparam(':tenant_pays_water_commArea', $tenant_pays_water_commArea);
                $stmt->bindparam(':tenant_council_refuse_charge', $tenant_council_refuse_charge);
                $stmt->bindparam(':shared_nac_contribute', $shared_nac_contribute);
                $stmt->bindparam(':pays_shared_nac', $pays_shared_nac);
                $stmt->bindparam(':occupancy_start_date', $occupancy_start_date);
                $stmt->bindparam(':occupancy_end_date', $occupancy_end_date);
                $stmt->bindparam(':tenant_onsite', $tenant_onsite);
                $stmt->bindparam(':email', $email);
                $stmt->bindparam(':cell_number', $cell_number);
                $stmt->bindparam(':tenant_electricalMeter_01_ct_ratio', $tenant_electricalMeter_01_ct_ratio);
                $stmt->bindparam(':tenant_electricalMeter_02_ct_ratio', $tenant_electricalMeter_02_ct_ratio);
                $stmt->bindparam(':tenant_electricalMeter_03_ct_ratio', $tenant_electricalMeter_03_ct_ratio);
                $stmt->bindparam(':tenant_pays_electrical_basic_charge', $tenant_pays_electrical_basic_charge);
                $stmt->bindparam(':tenant_pays_water_basic_charge', $tenant_pays_water_basic_charge);
                $stmt->bindparam(':tenant_pays_sewer_basic_charge', $tenant_pays_sewer_basic_charge);
                $stmt->bindparam(':tenant_pays_electrical_tariff_charge', $tenant_pays_electrical_tariff_charge);
                $stmt->bindparam(':tenant_pays_demand_tariff_charge', $tenant_pays_demand_tariff_charge);
                $stmt->bindparam(':tenant_pays_generator_tariff_charge', $tenant_pays_generator_tariff_charge);

                $stmt->execute();
                
                // Every change is also recorded in lum_tenants_legacy
                $legacy_sql = "INSERT INTO lum_tenants_legacy (
                            action_type, tenant_id, tenant_property, tenant_name, tenant_code, tenant_shop, tenant_shop_area, 
                            tenant_amps, tenant_comm_area, tenant_electricalMeter_01, tenant_electricalMeter_01_ct_ratio, 
                            tenant_electricalMeter_02, tenant_electricalMeter_02_ct_ratio, tenant_electricalMeter_03, 
                            tenant_electricalMeter_03_ct_ratio, tenant_waterMeter_01, tenant_waterMeter_02, 
                            tenant_waterMeter_03, tenant_waterMeter_04, tenant_electrical_tariff_charge, 
                            tenant_electrical_commArea_charge, tenant_water_tariff_charge, tenant_water_sewer_tariff_charge, 
                            tenant_water_commArea_charge, tenant_generator_tariff_charge, tenant_pays_electrical_commArea, 
                            tenant_pays_water_commArea, tenant_council_refuse_charge, shared_nac_contribute, pays_shared_nac,
                            tenant_occupancy_start_date, tenant_occupancy_end_date, tenant_email, tenant_cell_number, tenant_onsite,
                            tenant_pays_electrical_basic_charge, tenant_pays_water_basic_charge, tenant_pays_sewer_basic_charge,
                            tenant_pays_electrical_tariff_charge, tenant_pays_generator_tariff_charge, tenant_pays_demand_tariff_charge
                        ) VALUES (
                            'UPDATE', :tenant_id, :tenant_property, :tenant_name, :tenant_code, :tenant_shop, :tenant_shop_area, 
                            :tenant_amps, :tenant_comm_area, :tenant_electricalMeter_01, :tenant_electricalMeter_01_ct_ratio, 
                            :tenant_electricalMeter_02, :tenant_electricalMeter_02_ct_ratio, :tenant_electricalMeter_03, 
                            :tenant_electricalMeter_03_ct_ratio, :tenant_waterMeter_01, :tenant_waterMeter_02, 
                            :tenant_waterMeter_03, :tenant_waterMeter_04, :tenant_electrical_tariff_charge, 
                            :tenant_electrical_commArea_charge, :tenant_water_tariff_charge, :tenant_water_sewer_tariff_charge, 
                            :tenant_water_commArea_charge, :tenant_generator_tariff_charge, :tenant_pays_electrical_commArea, 
                            :tenant_pays_water_commArea, :tenant_council_refuse_charge, :shared_nac_contribute, :pays_shared_nac,
                            :occupancy_start_date, :occupancy_end_date, :email, :cell_number, :tenant_onsite,
                            :tenant_pays_electrical_basic_charge, :tenant_pays_water_basic_charge, :tenant_pays_sewer_basic_charge,
                            :tenant_pays_electrical_tariff_charge, :tenant_pays_generator_tariff_charge, :tenant_pays_demand_tariff_charge
                        )";
                        
                $legacy_stmt = $this->db->prepare($legacy_sql);
                $legacy_stmt->bindparam(':tenant_id', $tenant_id);
                $legacy_stmt->bindparam(':tenant_property', $tenant_property);
                $legacy_stmt->bindparam(':tenant_name', $tenant_name);
                $legacy_stmt->bindparam(':tenant_code', $tenant_code);
                $legacy_stmt->bindparam(':tenant_shop', $tenant_shop);
                $legacy_stmt->bindparam(':tenant_shop_area', $tenant_shop_area);
                $legacy_stmt->bindparam(':tenant_amps', $tenant_amps);
                $legacy_stmt->bindparam(':tenant_comm_area', $tenant_comm_area);
                $legacy_stmt->bindparam(':tenant_electricalMeter_01', $tenant_electricalMeter_01);
                $legacy_stmt->bindparam(':tenant_electricalMeter_01_ct_ratio', $tenant_electricalMeter_01_ct_ratio);
                $legacy_stmt->bindparam(':tenant_electricalMeter_02', $tenant_electricalMeter_02);
                $legacy_stmt->bindparam(':tenant_electricalMeter_02_ct_ratio', $tenant_electricalMeter_02_ct_ratio);
                $legacy_stmt->bindparam(':tenant_electricalMeter_03', $tenant_electricalMeter_03);
                $legacy_stmt->bindparam(':tenant_electricalMeter_03_ct_ratio', $tenant_electricalMeter_03_ct_ratio);
                $legacy_stmt->bindparam(':tenant_waterMeter_01', $tenant_waterMeter_01);
                $legacy_stmt->bindparam(':tenant_waterMeter_02', $tenant_waterMeter_02);
                $legacy_stmt->bindparam(':tenant_waterMeter_03', $tenant_waterMeter_03);
                $legacy_stmt->bindparam(':tenant_waterMeter_04', $tenant_waterMeter_04);
                $legacy_stmt->bindparam(':tenant_electrical_tariff_charge', $tenant_electrical_tariff_charge);
                $legacy_stmt->bindparam(':tenant_electrical_commArea_charge', $tenant_electrical_commArea_charge);
                $legacy_stmt->bindparam(':tenant_water_tariff_charge', $tenant_water_tariff_charge);
                $legacy_stmt->bindparam(':tenant_water_sewer_tariff_charge', $tenant_water_sewer_tariff_charge);
                $legacy_stmt->bindparam(':tenant_water_commArea_charge', $tenant_water_commArea_charge);
                $legacy_stmt->bindparam(':tenant_generator_tariff_charge', $tenant_generator_tariff_charge);
                $legacy_stmt->bindparam(':tenant_pays_electrical_commArea', $tenant_pays_electrical_commArea);
                $legacy_stmt->bindparam(':tenant_pays_water_commArea', $tenant_pays_water_commArea);
                $legacy_stmt->bindparam(':tenant_council_refuse_charge', $tenant_council_refuse_charge);
                $legacy_stmt->bindparam(':shared_nac_contribute', $shared_nac_contribute);
                $legacy_stmt->bindparam(':pays_shared_nac', $pays_shared_nac);
                $legacy_stmt->bindparam(':occupancy_start_date', $occupancy_start_date);
                $legacy_stmt->bindparam(':occupancy_end_date', $occupancy_end_date);
                $legacy_stmt->bindparam(':email', $email);
                $legacy_stmt->bindparam(':cell_number', $cell_number);
                $legacy_stmt->bindparam(':tenant_onsite', $tenant_onsite);
                $legacy_stmt->bindparam(':tenant_pays_electrical_basic_charge', $tenant_pays_electrical_basic_charge);
                $legacy_stmt->bindparam(':tenant_pays_water_basic_charge', $tenant_pays_water_basic_charge);
                $legacy_stmt->bindparam(':tenant_pays_sewer_basic_charge', $tenant_pays_sewer_basic_charge);
                $legacy_stmt->bindparam(':tenant_pays_electrical_tariff_charge', $tenant_pays_electrical_tariff_charge);
                $legacy_stmt->bindparam(':tenant_pays_demand_tariff_charge', $tenant_pays_demand_tariff_charge);
                $legacy_stmt->bindparam(':tenant_pays_generator_tariff_charge', $tenant_pays_generator_tariff_charge);
                
                $legacy_stmt->execute();

                lum_audit_stamp_tenant_legacy($this->db, $this->db->lastInsertId());
                $after = $this->fetch_tenant_row($tenant_id);
                $this->sync_meter_assignments($tenant_id, $after);
                lum_audit_log('UPDATE', 'tenant', $tenant_id, lum_audit_tenant_label($after ?: $before), $after['tenant_property'] ?? $tenant_property, $before, $after);
                
                return true;
        
            } catch (PDOException $e) {
                error_log("Tenant Update Error: " . $e->getMessage());
                throw $e;
            }
        }
        
        public function delete_tenant($tenant_id) {
            try {
                // The row is recorded in lum_tenants_legacy and the audit trail before it is deleted
                $fetch_stmt = $this->db->prepare("SELECT * FROM lum_tenants WHERE tenant_id = :tenant_id");
                $fetch_stmt->execute([':tenant_id' => $tenant_id]);
                $t = $fetch_stmt->fetch(PDO::FETCH_ASSOC);

                if ($t) {
                    $legacy_sql = "INSERT INTO lum_tenants_legacy (
                        action_type, tenant_id, tenant_property, tenant_name, tenant_code, tenant_shop, tenant_shop_area, 
                        tenant_amps, tenant_comm_area, tenant_electricalMeter_01, tenant_electricalMeter_01_ct_ratio, 
                        tenant_electricalMeter_02, tenant_electricalMeter_02_ct_ratio, tenant_electricalMeter_03, 
                        tenant_electricalMeter_03_ct_ratio, tenant_waterMeter_01, tenant_waterMeter_02, 
                        tenant_waterMeter_03, tenant_waterMeter_04, tenant_electrical_tariff_charge, 
                        tenant_electrical_commArea_charge, tenant_water_tariff_charge, tenant_water_sewer_tariff_charge, 
                        tenant_water_commArea_charge, tenant_generator_tariff_charge, tenant_pays_electrical_commArea, 
                        tenant_pays_water_commArea, tenant_council_refuse_charge, shared_nac_contribute, pays_shared_nac,
                        tenant_occupancy_start_date, tenant_occupancy_end_date, tenant_email, tenant_cell_number, tenant_onsite,
                        tenant_pays_electrical_basic_charge, tenant_pays_water_basic_charge, tenant_pays_sewer_basic_charge,
                        tenant_pays_electrical_tariff_charge, tenant_pays_generator_tariff_charge, tenant_pays_demand_tariff_charge
                    ) VALUES (
                        'DELETE', :tenant_id, :tenant_property, :tenant_name, :tenant_code, :tenant_shop, :tenant_shop_area, 
                        :tenant_amps, :tenant_comm_area, :tenant_electricalMeter_01, :tenant_electricalMeter_01_ct_ratio, 
                        :tenant_electricalMeter_02, :tenant_electricalMeter_02_ct_ratio, :tenant_electricalMeter_03, 
                        :tenant_electricalMeter_03_ct_ratio, :tenant_waterMeter_01, :tenant_waterMeter_02, 
                        :tenant_waterMeter_03, :tenant_waterMeter_04, :tenant_electrical_tariff_charge, 
                        :tenant_electrical_commArea_charge, :tenant_water_tariff_charge, :tenant_water_sewer_tariff_charge, 
                        :tenant_water_commArea_charge, :tenant_generator_tariff_charge, :tenant_pays_electrical_commArea, 
                        :tenant_pays_water_commArea, :tenant_council_refuse_charge, :shared_nac_contribute, :pays_shared_nac,
                        :tenant_occupancy_start_date, :tenant_occupancy_end_date, :tenant_email, :tenant_cell_number, :tenant_onsite,
                        :tenant_pays_electrical_basic_charge, :tenant_pays_water_basic_charge, :tenant_pays_sewer_basic_charge,
                        :tenant_pays_electrical_tariff_charge, :tenant_pays_generator_tariff_charge, :tenant_pays_demand_tariff_charge
                    )";
                    
                    $legacy_stmt = $this->db->prepare($legacy_sql);
                    
                    $legacy_stmt->execute([
                        ':tenant_id' => $t['tenant_id'],
                        ':tenant_property' => $t['tenant_property'],
                        ':tenant_name' => $t['tenant_name'],
                        ':tenant_code' => $t['tenant_code'],
                        ':tenant_shop' => $t['tenant_shop'],
                        ':tenant_shop_area' => $t['tenant_shop_area'],
                        ':tenant_amps' => $t['tenant_amps'],
                        ':tenant_comm_area' => $t['tenant_comm_area'],
                        ':tenant_electricalMeter_01' => $t['tenant_electricalMeter_01'],
                        ':tenant_electricalMeter_01_ct_ratio' => $t['tenant_electricalMeter_01_ct_ratio'],
                        ':tenant_electricalMeter_02' => $t['tenant_electricalMeter_02'],
                        ':tenant_electricalMeter_02_ct_ratio' => $t['tenant_electricalMeter_02_ct_ratio'],
                        ':tenant_electricalMeter_03' => $t['tenant_electricalMeter_03'],
                        ':tenant_electricalMeter_03_ct_ratio' => $t['tenant_electricalMeter_03_ct_ratio'],
                        ':tenant_waterMeter_01' => $t['tenant_waterMeter_01'],
                        ':tenant_waterMeter_02' => $t['tenant_waterMeter_02'],
                        ':tenant_waterMeter_03' => $t['tenant_waterMeter_03'],
                        ':tenant_waterMeter_04' => $t['tenant_waterMeter_04'],
                        ':tenant_electrical_tariff_charge' => $t['tenant_electrical_tariff_charge'],
                        ':tenant_electrical_commArea_charge' => $t['tenant_electrical_commArea_charge'],
                        ':tenant_water_tariff_charge' => $t['tenant_water_tariff_charge'],
                        ':tenant_water_sewer_tariff_charge' => $t['tenant_water_sewer_tariff_charge'],
                        ':tenant_water_commArea_charge' => $t['tenant_water_commArea_charge'],
                        ':tenant_generator_tariff_charge' => $t['tenant_generator_tariff_charge'],
                        ':tenant_pays_electrical_commArea' => $t['tenant_pays_electrical_commArea'],
                        ':tenant_pays_water_commArea' => $t['tenant_pays_water_commArea'],
                        ':tenant_council_refuse_charge' => $t['tenant_council_refuse_charge'],
                        ':shared_nac_contribute' => $t['shared_nac_contribute'],
                        ':pays_shared_nac' => $t['pays_shared_nac'],
                        ':tenant_occupancy_start_date' => $t['tenant_occupancy_start_date'],
                        ':tenant_occupancy_end_date' => $t['tenant_occupancy_end_date'],
                        ':tenant_email' => $t['tenant_email'],
                        ':tenant_cell_number' => $t['tenant_cell_number'],
                        ':tenant_onsite' => $t['tenant_onsite'],
                        ':tenant_pays_electrical_basic_charge' => $t['tenant_pays_electrical_basic_charge'],
                        ':tenant_pays_water_basic_charge' => $t['tenant_pays_water_basic_charge'],
                        ':tenant_pays_sewer_basic_charge' => $t['tenant_pays_sewer_basic_charge'],
                        ':tenant_pays_electrical_tariff_charge' => $t['tenant_pays_electrical_tariff_charge'],
                        ':tenant_pays_generator_tariff_charge' => $t['tenant_pays_generator_tariff_charge'],
                        ':tenant_pays_demand_tariff_charge' => $t['tenant_pays_demand_tariff_charge']
                    ]);
                    lum_audit_stamp_tenant_legacy($this->db, $this->db->lastInsertId());
                }
            
                $sql = "DELETE FROM lum_tenants WHERE tenant_id = :tenant_id";
                $stmt = $this->db->prepare($sql);
                $stmt->bindparam(':tenant_id', $tenant_id);
                $stmt->execute();

                if ($t) {
                    // The assignments stay, closed off today, so the meter history of the
                    // business survives the tenant record being removed.
                    try {
                        $ref = trim((string)($t['tenant_ref'] ?? ''));
                        if ($ref === '') $ref = 'T' . str_pad((string)$t['tenant_id'], 5, '0', STR_PAD_LEFT);
                        $close = $this->db->prepare("UPDATE lum_tenant_meters
                                                       SET valid_to = COALESCE(:end, CURDATE()), updated_at = NOW(),
                                                           notes = CONCAT(COALESCE(notes, ''), ' [tenant record removed]')
                                                     WHERE tenant_ref = :ref AND (valid_to IS NULL OR valid_to >= CURDATE())");
                        $close->execute(['end' => ($t['tenant_occupancy_end_date'] ?: null), 'ref' => $ref]);
                    } catch (Throwable $e) {
                        error_log('LUM meter assignments not closed for tenant ' . $tenant_id . ': ' . $e->getMessage());
                    }
                    lum_audit_log('DELETE', 'tenant', $t['tenant_id'], lum_audit_tenant_label($t), $t['tenant_property'], $t, null);
                }
                return true;
            } catch (PDOException $e) {
                error_log("Tenant Deletion Error: " . $e->getMessage());
                throw $e;
            }
        }
    }
}
?>