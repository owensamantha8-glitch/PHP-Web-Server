<?php
    // Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
    require_once __DIR__ . '/bootstrap.php';
    lum_page('meters', 'edit');

    lum_connect('meters'); // $meter_db_conn, $meter_crud

    if(isset($_POST['f-update-meter'])){
        
        // --- VALIDATE CSRF TOKEN ---
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
            die("Security Error: Invalid CSRF Token.");
        }

        $id = (int)($_POST['f-id'] ?? 0);
        if ($id <= 0) {
            die("Invalid meter.");
        }

        // The meter being edited must also belong to a property this user may access
        if (!empty($_SESSION['assigned_properties'])) {
            $chk = $meter_db_conn->prepare("SELECT meter_property FROM lum_meters WHERE meter_id = :id");
            $chk->execute(['id' => $id]);
            $current_prop = $chk->fetchColumn();
            $allowed_now = array_map('trim', explode(',', $_SESSION['assigned_properties']));
            if ($current_prop === false || (!empty($current_prop) && !in_array($current_prop, $allowed_now, true))) {
                http_response_code(403);
                die("You do not have access to this meter.");
            }
        }
        $meter_serial = $_POST['f-meter_serial'] ?? null;
        $meter_type = isset($_POST['f-meter_type']) ? trim((string)$_POST['f-meter_type']) : null;

        // The type must be one of the Meter Configs types (or the meter's unchanged current type) and fit the database column
        $type_stmt = $meter_db_conn->prepare("SELECT meter_type FROM lum_meters WHERE meter_id = :id");
        $type_stmt->execute(['id' => $id]);
        $current_type = $type_stmt->fetchColumn();
        $type_error = $meter_crud->check_meter_type($meter_type, $current_type === false ? null : $current_type);
        if ($type_error !== '') {
            echo "<div style='background:#121212; color:white; padding: 30px; font-family: sans-serif; border: 1px solid red; border-radius: 10px; margin: 20px;'>";
            echo "<h2 style='color:#e3000f;'>&#9888; Update Failed</h2>";
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

        $result = $meter_crud->edit_meter($id, $meter_serial, $meter_type, $meter_rol, $meter_MDB, $meter_DB, $meter_property, $meter_location, $meter_tenant, $meter_shop, $meter_area, $meter_tenant_02, $meter_shop_02, $meter_area_02, $meter_tenant_03, $meter_shop_03, $meter_area_03, $meter_brand);

        // Redirect ONLY if successful
        // The CT ratio of the meter, and everything that reads it: the assignment in force
        // today and the tenant that carries this meter. Closed periods keep the ratio that
        // applied then - use Correct a past ratio for those.
        try {
            $ct = (float)str_replace(',', '.', (string)($_POST['f-meter_ct_ratio'] ?? '0'));
            if ($ct > 0) {
                $meters_pdo = lum_db('meters');
                $was = $meters_pdo->prepare("SELECT ct_ratio FROM lum_meters WHERE meter_id = :id");
                $was->execute(['id' => $id]);
                $old_ct = (float)$was->fetchColumn();

                $meters_pdo->prepare("UPDATE lum_meters SET ct_ratio = :ct WHERE meter_id = :id")
                           ->execute(['ct' => $ct, 'id' => $id]);

                if (abs($old_ct - $ct) >= 0.005) {
                    $tenants_pdo = lum_db('tenants');
                    $open = $tenants_pdo->prepare("UPDATE lum_tenant_meters
                                                      SET ct_ratio = :ct, updated_at = NOW()
                                                    WHERE meter_serial = :s AND meter_kind = 'electricity'
                                                      AND (valid_to IS NULL OR valid_to >= CURDATE())");
                    $open->execute(['ct' => $ct, 's' => $meter_serial]);

                    for ($slot = 1; $slot <= 3; $slot++) {
                        $col = 'tenant_electricalMeter_0' . $slot;
                        $tenants_pdo->prepare("UPDATE lum_tenants SET {$col}_ct_ratio = :ct
                                                WHERE CAST({$col} AS CHAR) = :s")
                                    ->execute(['ct' => $ct, 's' => $meter_serial]);
                    }

                    if (function_exists('lum_audit_log')) {
                        lum_audit_log('UPDATE', 'meter_ct_ratio', (int)$id, $meter_serial, $meter_property ?? null,
                                      ['ct_ratio' => $old_ct], ['ct_ratio' => $ct],
                                      'changed on the meter; tenants using it were brought in step');
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('LUM CT ratio not saved for meter ' . $id . ': ' . $e->getMessage());
        }

        if ($result) {
            header('Location: ' . lum_app_url('/Meter Management/meter-overview.php'));
            exit(); 
        } else {
            // Halting redirect so you can read the database error
            echo "<div style='background:#121212; color:white; padding: 30px; font-family: sans-serif; border: 1px solid red; border-radius: 10px; margin: 20px;'>";
            echo "<h2 style='color:#e3000f;'>&#9888; Update Failed</h2>";
            echo "<p>The database rejected the meter update. The error has been logged for the system administrator.</p>";
            echo "<a href='javascript:history.back()' style='color:#0dcaf0; text-decoration: none;'>&#8592; Click here to go back and try again</a>";
            echo "</div>";
            exit();
        }
    }
?>