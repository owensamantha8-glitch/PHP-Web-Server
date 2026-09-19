<?php 
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
// Audit trail (switches itself off if the logger is missing; see /var/www/Lynx/bootstrap.php)
require_once '/var/www/Lynx/bootstrap.php';
lum_use('audit');

// Electrical or water, from the meter type: types are saved as "Electrical - <type>" / "Water - <type>"
// (unregistered meters are simply "Electrical" / "Water"). Returns 'Electrical', 'Water' or '' (unknown type).
if (!function_exists('lum_meter_category')) {
    function lum_meter_category($meter_type) {
        $t = ltrim((string)$meter_type);
        if (stripos($t, 'Electrical') === 0) return 'Electrical';
        if (stripos($t, 'Water') === 0) return 'Water';
        return '';
    }
}

// OBIS codes: name, unit, category and chart colour (Configurations -> OBIS Time Ranges;
// sys_db_information.lum_obis_time_ranges, obis-codes-setup.sql). Built-in values are used until the table has them.
if (!defined('LUM_OBIS_CODE_DEFAULTS')) {
    define('LUM_OBIS_CODE_DEFAULTS', [
        '1.1.1.8.0' => ['label' => 'Total Combined',   'unit' => 'kWh', 'category' => 'Electrical', 'color' => '#dc3545', 'sort' => 1],
        '1.1.1.8.1' => ['label' => 'Grid Supply',      'unit' => 'kWh', 'category' => 'Electrical', 'color' => '#198754', 'sort' => 2],
        '1.1.1.8.2' => ['label' => 'Generator Supply', 'unit' => 'kWh', 'category' => 'Electrical', 'color' => '#ffc107', 'sort' => 3],
        '8.1.1.0.0' => ['label' => 'Total Water Flow', 'unit' => 'kL',  'category' => 'Water',      'color' => '#0dcaf0', 'sort' => 4],
    ]);
}

// Every OBIS code in use: [code => ['label', 'unit', 'category', 'color', 'sort']], in display order.
// $pdo: any connection on the server (the table is read as sys_db_information.lum_obis_time_ranges).
if (!function_exists('lum_obis_codes')) {
    function lum_obis_codes($pdo = null) {
        static $codes = null;
        if ($codes !== null) return $codes;
        $codes = LUM_OBIS_CODE_DEFAULTS;
        if ($pdo) {
            try {
                $rows = $pdo->query("SELECT * FROM sys_db_information.lum_obis_time_ranges")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $code = trim((string)($r['obis_code'] ?? ''));
                    if (!preg_match('/^\d+(\.\d+){4}$/', $code)) continue;
                    $c = $codes[$code] ?? ['label' => $code, 'unit' => '', 'category' => '', 'color' => '#6c757d', 'sort' => 99];
                    if (trim((string)($r['obis_label'] ?? '')) !== '') $c['label'] = trim($r['obis_label']);
                    if (trim((string)($r['obis_unit'] ?? '')) !== '') $c['unit'] = trim($r['obis_unit']);
                    if (in_array($r['obis_category'] ?? '', ['Electrical', 'Water'], true)) $c['category'] = $r['obis_category'];
                    if (preg_match('/^#[0-9a-fA-F]{6}$/', (string)($r['chart_color'] ?? ''))) $c['color'] = $r['chart_color'];
                    if ((int)($r['sort_order'] ?? 0) > 0) $c['sort'] = (int)$r['sort_order'];
                    $codes[$code] = $c;
                }
            } catch (\Throwable $e) {
                // Table or columns not there yet: built-in values
            }
        }
        // Codes without a category are not used by the meter pages
        $codes = array_filter($codes, function ($c) { return $c['category'] !== ''; });
        uksort($codes, function ($a, $b) use ($codes) {
            return [$codes[$a]['sort'], $a] <=> [$codes[$b]['sort'], $b];
        });
        return $codes;
    }
}

// The OBIS codes of a category ('Electrical' / 'Water'), in display order
if (!function_exists('lum_obis_codes_for')) {
    function lum_obis_codes_for($category, $pdo = null) {
        return array_keys(array_filter(lum_obis_codes($pdo), function ($c) use ($category) { return $c['category'] === $category; }));
    }
}

// "Total Combined (1.1.1.8.0)"
if (!function_exists('lum_obis_label')) {
    function lum_obis_label($code, $pdo = null, $with_code = true) {
        $c = lum_obis_codes($pdo)[$code] ?? null;
        $label = $c ? $c['label'] : (string)$code;
        return $with_code ? $label . ' (' . $code . ')' : $label;
    }
}

// Years that have reading databases: db_obis_<code>_<year> for the given OBIS codes,
// and (with $include_manual) the manual extract databases db_manual_..._<year>. Sorted, oldest first.
if (!function_exists('lum_reading_years')) {
    function lum_reading_years($pdo, array $obis_codes = [], $include_manual = false) {
        $years = [];
        if (!$pdo) return [];
        try {
            foreach ($obis_codes as $code) {
                $clean = preg_replace('/[^0-9]/', '', (string)$code);
                if ($clean === '') continue;
                foreach ($pdo->query("SHOW DATABASES LIKE 'db\\_obis\\_{$clean}\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $db) {
                    if (preg_match('/^db_obis_' . $clean . '_(\d{4})$/', $db, $m)) $years[(int)$m[1]] = true;
                }
            }
            if ($include_manual) {
                foreach ($pdo->query("SHOW DATABASES LIKE 'db\\_manual\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $db) {
                    if (preg_match('/^db_manual_[a-z0-9_]+_(\d{4})$/i', $db, $m)) $years[(int)$m[1]] = true;
                }
            }
        } catch (\Throwable $e) {
            error_log('LUM reading years not read: ' . $e->getMessage());
        }
        $years = array_keys($years);
        sort($years);
        return $years;
    }
}

    class meter_conr{
        private $db;
        
        function __construct($meter_db_conn){
            $this->db = $meter_db_conn;
        }

        // Current database row of a meter (used for the audit trail)
        private function fetch_meter_row($meter_id) {
            try {
                $stmt = $this->db->prepare("SELECT * FROM lum_meters WHERE meter_id = :id");
                $stmt->execute([':id' => $meter_id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                return $row ?: null;
            } catch (PDOException $e) {
                error_log('LUM audit: could not read meter ' . $meter_id . ': ' . $e->getMessage());
                return null;
            }
        }

        // UNIVERSAL AUTO-SYNC ENGINE
        // Copies the shop area to every meter and tenant in the same shop, and records
        // each row whose area actually changes in the audit trail.
        private function sync_shop_area($meter_property, $meter_shop, $meter_area, $source_meter_id, $source_serial) {
            if (empty($meter_property) || empty($meter_shop) || $meter_area === null || trim((string)$meter_area) === '') return;

            $note = 'Shop area synced from meter ' . $source_serial;

            // Rows that will change (read before updating)
            $m_stmt = $this->db->prepare("SELECT * FROM lum_meters WHERE meter_property = :prop AND meter_shop = :shop AND meter_id <> :mid");
            $m_stmt->execute([':prop' => $meter_property, ':shop' => $meter_shop, ':mid' => (int)$source_meter_id]);
            $meters_before = $m_stmt->fetchAll(PDO::FETCH_ASSOC);

            $t_stmt = $this->db->prepare("SELECT * FROM sys_db_tenants.lum_tenants WHERE tenant_property = :prop AND tenant_shop = :shop");
            $t_stmt->execute([':prop' => $meter_property, ':shop' => $meter_shop]);
            $tenants_before = $t_stmt->fetchAll(PDO::FETCH_ASSOC);

            // 1. Cascade the area metric to all existing hardware in lum_meters linked to this shop
            $sync_m = $this->db->prepare("UPDATE lum_meters SET meter_area = :area WHERE meter_property = :prop AND meter_shop = :shop");
            $sync_m->execute([':area' => $meter_area, ':prop' => $meter_property, ':shop' => $meter_shop]);

            // 2. Cross-connect to sys_db_tenants to override the master tenant profile and prevent COALESCE view conflicts
            $sync_t = $this->db->prepare("UPDATE sys_db_tenants.lum_tenants SET tenant_shop_area = :area WHERE tenant_property = :prop AND tenant_shop = :shop");
            $sync_t->execute([':area' => $meter_area, ':prop' => $meter_property, ':shop' => $meter_shop]);

            // 3. Audit trail for the rows changed by the sync
            foreach ($meters_before as $mb) {
                lum_audit_log('UPDATE', 'meter', $mb['meter_id'], $mb['meter_serial'], $mb['meter_property'], ['meter_area' => $mb['meter_area']], ['meter_area' => $meter_area], $note);
            }
            foreach ($tenants_before as $tb) {
                lum_audit_log('UPDATE', 'tenant', $tb['tenant_id'], lum_audit_tenant_label($tb), $tb['tenant_property'], ['tenant_shop_area' => $tb['tenant_shop_area']], ['tenant_shop_area' => $meter_area], $note);
            }
        }

        // Meter types offered on the forms: "Electrical - <type>" / "Water - <type>" (Configurations -> Meter Configs)
        public function meter_type_options() {
            static $types = null;
            if ($types !== null) return $types;
            $types = [];
            try {
                $sql = "SELECT CONCAT('Electrical - ', type_name) FROM sys_db_information.lum_meter_type_electrical
                        UNION SELECT CONCAT('Water - ', type_name) FROM sys_db_information.lum_meter_type_water";
                $types = $this->db->query($sql)->fetchAll(PDO::FETCH_COLUMN);
            } catch (PDOException $e) {
                error_log('LUM meter types not read: ' . $e->getMessage());
            }
            return $types;
        }

        // Longest meter type the database can store (VARCHAR length of lum_meters.meter_type)
        public function meter_type_max_length() {
            try {
                $st = $this->db->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lum_meters' AND COLUMN_NAME = 'meter_type'");
                $len = (int)$st->fetchColumn();
                return $len > 0 ? $len : null;
            } catch (PDOException $e) {
                return null;
            }
        }

        // Check a meter type before saving: '' = fine, otherwise the reason it cannot be saved.
        // $current_type: the meter's saved type (an unchanged old type may be kept).
        public function check_meter_type($meter_type, $current_type = null) {
            $meter_type = (string)$meter_type;
            if (trim($meter_type) === '') return 'Please choose a meter type.';
            $offered = $this->meter_type_options();
            $unchanged = ($current_type !== null && $meter_type === (string)$current_type);
            if (!$unchanged && !empty($offered) && !in_array($meter_type, $offered, true)) {
                return 'The meter type "' . $meter_type . '" is not one of the types on Configurations -> Meter Configs.';
            }
            $max = $this->meter_type_max_length();
            $len = function_exists('mb_strlen') ? mb_strlen($meter_type, 'UTF-8') : strlen($meter_type);
            if (!$unchanged && $max !== null && $len > $max) {
                return 'The meter type "' . $meter_type . '" is longer than the ' . $max . ' characters the database can store. '
                     . 'Please ask the system administrator to run meter-type-length-fix.sql.';
            }
            return '';
        }

        // Register Meter
        public function meter_registration(
            $meter_serial, $meter_type, $meter_rol, $meter_MDB, $meter_DB, $meter_property, $meter_location, 
            $meter_tenant, $meter_shop, $meter_area=null, $meter_tenant_02=null, $meter_shop_02=null, 
            $meter_area_02=null, $meter_tenant_03=null, $meter_shop_03=null, $meter_area_03=null, $meter_brand=null
        ){
            try {
                $sql = "INSERT INTO lum_meters (
                            meter_serial, meter_type, meter_brand, meter_rol, meter_MDB, meter_DB, meter_property, 
                            meter_location, meter_tenant, meter_shop, meter_area, meter_tenant_02, meter_shop_02, 
                            meter_area_02, meter_tenant_03, meter_shop_03, meter_area_03
                        ) VALUES (
                            :meter_serial, :meter_type, :meter_brand, :meter_rol, :meter_MDB, :meter_DB, :meter_property, 
                            :meter_location, :meter_tenant, :meter_shop, :meter_area, :meter_tenant_02, :meter_shop_02, 
                            :meter_area_02, :meter_tenant_03, :meter_shop_03, :meter_area_03
                        )";

                $stmt = $this->db->prepare($sql);

                $stmt->bindparam(':meter_serial',$meter_serial);
                $stmt->bindparam(':meter_type',$meter_type);
                $stmt->bindparam(':meter_brand',$meter_brand);
                $stmt->bindparam(':meter_rol',$meter_rol);
                $stmt->bindparam(':meter_MDB',$meter_MDB);
                $stmt->bindparam(':meter_DB',$meter_DB);
                $stmt->bindparam(':meter_property',$meter_property);
                $stmt->bindparam(':meter_location',$meter_location);
                
                $stmt->bindparam(':meter_tenant',$meter_tenant);
                $stmt->bindparam(':meter_shop',$meter_shop);
                $stmt->bindparam(':meter_area',$meter_area);
                
                $stmt->bindparam(':meter_tenant_02',$meter_tenant_02);
                $stmt->bindparam(':meter_shop_02',$meter_shop_02);
                $stmt->bindparam(':meter_area_02',$meter_area_02);
                
                $stmt->bindparam(':meter_tenant_03',$meter_tenant_03);
                $stmt->bindparam(':meter_shop_03',$meter_shop_03);
                $stmt->bindparam(':meter_area_03',$meter_area_03);

                $stmt->execute();
                
                // Shop area sync + audit trail
                $new_meter_id = $this->db->lastInsertId();
                $this->sync_shop_area($meter_property, $meter_shop, $meter_area, $new_meter_id, $meter_serial);
                $after = $this->fetch_meter_row($new_meter_id);
                lum_audit_log('INSERT', 'meter', $new_meter_id, $after['meter_serial'] ?? $meter_serial, $after['meter_property'] ?? $meter_property, null, $after);
                
                return true;
        
            } catch (PDOException $e) {
                // Formatting the error to be highly visible if the insert fails
                error_log('LUM meter save failed: ' . $e->getMessage());
                return false;
            }
        }

        // Edit Meter
        public function edit_meter(
            $id, $meter_serial, $meter_type, $meter_rol, $meter_MDB, $meter_DB, $meter_property, $meter_location, 
            $meter_tenant, $meter_shop, $meter_area=null, $meter_tenant_02=null, $meter_shop_02=null, 
            $meter_area_02=null, $meter_tenant_03=null, $meter_shop_03=null, $meter_area_03=null, $meter_brand=null
        ){
            try {
                // Row as it was before this update (audit trail)
                $before = $this->fetch_meter_row($id);

                $sql = "UPDATE lum_meters 
                        SET meter_serial = :meter_serial, 
                            meter_type = :meter_type, 
                            meter_brand = :meter_brand,
                            meter_rol = :meter_rol, 
                            meter_MDB = :meter_MDB, 
                            meter_DB = :meter_DB, 
                            meter_property = :meter_property, 
                            meter_location = :meter_location, 
                            meter_tenant = :meter_tenant, 
                            meter_shop = :meter_shop,
                            meter_area = :meter_area,
                            meter_tenant_02 = :meter_tenant_02,
                            meter_shop_02 = :meter_shop_02,
                            meter_area_02 = :meter_area_02,
                            meter_tenant_03 = :meter_tenant_03,
                            meter_shop_03 = :meter_shop_03,
                            meter_area_03 = :meter_area_03
                        WHERE meter_id = :id";

                $stmt = $this->db->prepare($sql);

                $stmt->bindparam(':id', $id);
                $stmt->bindparam(':meter_serial', $meter_serial);
                $stmt->bindparam(':meter_type', $meter_type);
                $stmt->bindparam(':meter_brand', $meter_brand);
                $stmt->bindparam(':meter_rol', $meter_rol);
                $stmt->bindparam(':meter_MDB', $meter_MDB);
                $stmt->bindparam(':meter_DB', $meter_DB);
                $stmt->bindparam(':meter_property', $meter_property);
                $stmt->bindparam(':meter_location', $meter_location);
                $stmt->bindparam(':meter_tenant', $meter_tenant);
                $stmt->bindparam(':meter_shop', $meter_shop);
                $stmt->bindparam(':meter_area', $meter_area);
                $stmt->bindparam(':meter_tenant_02', $meter_tenant_02);
                $stmt->bindparam(':meter_shop_02', $meter_shop_02);
                $stmt->bindparam(':meter_area_02', $meter_area_02);
                $stmt->bindparam(':meter_tenant_03', $meter_tenant_03);
                $stmt->bindparam(':meter_shop_03', $meter_shop_03);
                $stmt->bindparam(':meter_area_03', $meter_area_03);

                $stmt->execute();
                
                // Shop area sync + audit trail
                $this->sync_shop_area($meter_property, $meter_shop, $meter_area, $id, $meter_serial);
                $after = $this->fetch_meter_row($id);
                lum_audit_log('UPDATE', 'meter', $id, $after['meter_serial'] ?? $meter_serial, $after['meter_property'] ?? $meter_property, $before, $after);

                return true;
        
            } catch (PDOException $e) {
                // Formatting the error to be highly visible
                error_log('LUM meter save failed: ' . $e->getMessage());
                return false;
            }
        }

        // Delete Meter (with audit trail)
        public function delete_meter($meter_id) {
            $before = $this->fetch_meter_row($meter_id);
            $stmt = $this->db->prepare("DELETE FROM lum_meters WHERE meter_id = :id");
            $stmt->execute([':id' => (int)$meter_id]);
            if ($before && $stmt->rowCount() > 0) {
                lum_audit_log('DELETE', 'meter', $before['meter_id'], $before['meter_serial'], $before['meter_property'], $before, null);
            }
            return true;
        }
    }
?>