<?php
    // Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
    require_once __DIR__ . '/bootstrap.php';
    lum_page('meters', 'edit');

    lum_connect('meters'); // $meter_db_conn, $meter_crud

    if(isset($_POST['register-meter'])){
        
        // --- VALIDATE CSRF TOKEN ---
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
            die("Security Error: Invalid CSRF Token.");
        }

        $meter_serial = $_POST['f-meter_serial'] ?? null;
        $meter_type = isset($_POST['f-meter_type']) ? trim((string)$_POST['f-meter_type']) : null;

        // The type must be one of the Meter Configs types and fit the database column (it is never cut off)
        $type_error = $meter_crud->check_meter_type($meter_type);
        if ($type_error !== '') {
            echo "<div style='background:#121212; color:white; padding: 30px; font-family: sans-serif; border: 1px solid red; border-radius: 10px; margin: 20px;'>";
            echo "<h2 style='color:#e3000f;'>&#9888; Registration Failed</h2>";
            echo "<p>" . htmlspecialchars($type_error) . "</p>";
            echo "<a href='javascript:history.back()' style='color:#0dcaf0; text-decoration: none;'>&#8592; Click here to go back and try again</a>";
            echo "</div>";
            exit();
        }

        $meter_brand = !empty($_POST['f-meter_brand']) ? trim($_POST['f-meter_brand']) : null;
        $meter_rol = $_POST['f-meter_rol'] ?? null;
        $meter_MDB = $_POST['f-meter_MDB'] ?? null;
        $meter_DB = $_POST['f-meter_DB'] ?? null;
        $meter_property = $_POST['f-meter_property'] ?? null;
        $meter_location = $_POST['f-meter_location'] ?? null;

        // Property access: restricted users may only save meters for their own properties
        $lum_allowed = (!empty($_SESSION['assigned_properties']) ? array_map('trim', explode(',', $_SESSION['assigned_properties'])) : null);
        if ($lum_allowed !== null && !empty($meter_property) && !in_array($meter_property, $lum_allowed, true)) {
            http_response_code(403);
            die("You do not have access to this property.");
        }
        
        $meter_tenant = $_POST['f-meter_tenant'] ?? null;
        $meter_shop = $_POST['f-meter_shop'] ?? null;
        $meter_area = !empty($_POST['f-meter_area']) ? $_POST['f-meter_area'] : null;
        
        $meter_tenant_02 = $_POST['f-meter_tenant_02'] ?? null;
        $meter_shop_02 = $_POST['f-meter_shop_02'] ?? null;
        $meter_area_02 = !empty($_POST['f-meter_area_02']) ? $_POST['f-meter_area_02'] : null;
        
        $meter_tenant_03 = $_POST['f-meter_tenant_03'] ?? null;
        $meter_shop_03 = $_POST['f-meter_shop_03'] ?? null;
        $meter_area_03 = !empty($_POST['f-meter_area_03']) ? $_POST['f-meter_area_03'] : null;

        // Execute Database Insert
        $result = $meter_crud->meter_registration($meter_serial, $meter_type, $meter_rol, $meter_MDB, $meter_DB, $meter_property, $meter_location, $meter_tenant, $meter_shop, $meter_area, $meter_tenant_02, $meter_shop_02, $meter_area_02, $meter_tenant_03, $meter_shop_03, $meter_area_03, $meter_brand);

        // Redirect ONLY if successful
        // The CT ratio belongs to the meter itself; the serial is unique in the register
        try {
            $ct = (float)str_replace(',', '.', (string)($_POST['f-meter_ct_ratio'] ?? '1'));
            if ($ct > 0) {
                $ct_stmt = lum_db('meters')->prepare("UPDATE lum_meters SET ct_ratio = :ct WHERE meter_serial = :s");
                $ct_stmt->execute(['ct' => $ct, 's' => $meter_serial]);
            }
        } catch (Throwable $e) {
            error_log('LUM CT ratio not saved for meter ' . $meter_serial . ': ' . $e->getMessage());
        }

        if ($result) {
            header("Location: https://lynx-um.co.za/Meter Management/meter-overview.php?msg=success");
            exit();
        } else {
            // Halting redirect so you can read the database error
            echo "<div style='background:#121212; color:white; padding: 30px; font-family: sans-serif; border: 1px solid red; border-radius: 10px; margin: 20px;'>";
            echo "<h2 style='color:#e3000f;'>&#9888; Registration Failed</h2>";
            echo "<p>The database rejected the new meter entry. The error has been logged for the system administrator.</p>";
            echo "<a href='meter-register-form.php' style='color:#0dcaf0; text-decoration: none;'>&#8592; Click here to go back and try again</a>";
            echo "</div>";
            exit();
        }
    }
?>