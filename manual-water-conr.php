<?php
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
// Audit trail (switches itself off if the logger is missing; see /var/www/Lynx/bootstrap.php)
require_once __DIR__ . '/bootstrap.php';
lum_use('audit');

class manual_water_conr {

    private $db;
    
    public function __construct($manual_db_conn) {
        $this->db = $manual_db_conn;
    }

    // Insert new manual water reading
    public function add_reading($property, $tenant, $shop, $reading, $reading_date, $water_serial) {
        try {
            $sql = "INSERT INTO manual_readings_water (
                        property, tenant, shop, reading, reading_date, water_serial
                    ) VALUES (
                        :property, :tenant, :shop, :reading, :reading_date, :water_serial
                    )";

            $stmt = $this->db->prepare($sql);

            // Pass parameters directly into execute for maximum reliability
            $stmt->execute([
                ':property' => $property,
                ':tenant' => $tenant,
                ':shop' => $shop,
                ':reading' => $reading,
                ':reading_date' => $reading_date,
                ':water_serial' => $water_serial
            ]);

            // Audit trail
            $new_id = $this->db->lastInsertId();
            $row = lum_audit_fetch_row($this->db, 'manual_readings_water', 'id', $new_id);
            if (!$row) {
                $row = ['property' => $property, 'tenant' => $tenant, 'shop' => $shop, 'reading' => $reading, 'reading_date' => $reading_date, 'water_serial' => $water_serial];
            }
            lum_audit_log('INSERT', 'manual_water_reading', $new_id, 'Meter ' . $water_serial . ' (' . $reading_date . ')', $property, null, $row);
            
            return true;
    
        } catch (PDOException $e) {
            error_log("Error adding manual reading: " . $e->getMessage());
            return false;
        }
    }

    // Retrieve readings with optional filters for Property and Date
    public function get_readings($search_property = '', $search_date = '') {
        try {
            $sql = "SELECT * FROM manual_readings_water WHERE 1=1";
            $params = [];

            if (!empty($search_property)) {
                $sql .= " AND property LIKE :property";
                $params[':property'] = "%" . $search_property . "%";
            }

            if (!empty($search_date)) {
                $sql .= " AND reading_date = :reading_date";
                $params[':reading_date'] = $search_date;
            }

            $sql .= " ORDER BY reading_date DESC, property ASC, tenant ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            error_log("Error retrieving manual readings: " . $e->getMessage());
            return [];
        }
    }
}
?>