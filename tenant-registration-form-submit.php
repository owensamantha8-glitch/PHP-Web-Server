<?php
    require_once '/var/www/Lynx/bootstrap.php';
    lum_page('tenants', 'edit');

    lum_connect('tenants');

    if(isset($_POST['f-register-tenant'])){

        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
            die("<div style='color:white; background:red; padding:20px;'>Security Error: Invalid CSRF Token. Request Blocked.</div>");
        }

        $tenant_property = $_POST['f-tenant_property'] ?? '';
        $tenant_name = $_POST['f-tenant_name'] ?? '';
        $tenant_code = $_POST['f-tenant_code'] ?? '';
        $tenant_shop = $_POST['f-tenant_shop'] ?? '';

        // Property access: restricted users may only save tenants for their own properties
        $lum_allowed = (!empty($_SESSION['assigned_properties']) ? array_map('trim', explode(',', $_SESSION['assigned_properties'])) : null);
        if ($lum_allowed !== null && !in_array($tenant_property, $lum_allowed, true)) {
            http_response_code(403);
            die("<div style='color:white; background:red; padding:20px;'>You do not have access to this property.</div>");
        }
        
        $tenant_shop_area = !empty($_POST['f-tenant_shop_area']) ? $_POST['f-tenant_shop_area'] : 0;
        $tenant_comm_area = !empty($_POST['f-tenant_comm_area']) ? $_POST['f-tenant_comm_area'] : 0;
        $tenant_amps = !empty($_POST['f-tenant_amps']) ? $_POST['f-tenant_amps'] : 0;
        
        $tenant_pays_electrical_commArea = $_POST['f-tenant_pays_electrical_commArea'] ?? 'No';
        $tenant_pays_water_commArea = $_POST['f-tenant_pays_water_commArea'] ?? 'No';

        $tenant_electricalMeter_01 = (isset($_POST['f-tenant_electricalMeter_01']) && trim($_POST['f-tenant_electricalMeter_01']) !== '') ? trim($_POST['f-tenant_electricalMeter_01']) : null;
        $tenant_electricalMeter_02 = (isset($_POST['f-tenant_electricalMeter_02']) && trim($_POST['f-tenant_electricalMeter_02']) !== '') ? trim($_POST['f-tenant_electricalMeter_02']) : null;
        $tenant_electricalMeter_03 = (isset($_POST['f-tenant_electricalMeter_03']) && trim($_POST['f-tenant_electricalMeter_03']) !== '') ? trim($_POST['f-tenant_electricalMeter_03']) : null;
        
        $tenant_electricalMeter_01_ct_ratio = !empty($_POST['f-tenant_electricalMeter_01_ct_ratio']) ? floatval($_POST['f-tenant_electricalMeter_01_ct_ratio']) : 1.0;
        $tenant_electricalMeter_02_ct_ratio = !empty($_POST['f-tenant_electricalMeter_02_ct_ratio']) ? floatval($_POST['f-tenant_electricalMeter_02_ct_ratio']) : 1.0;
        $tenant_electricalMeter_03_ct_ratio = !empty($_POST['f-tenant_electricalMeter_03_ct_ratio']) ? floatval($_POST['f-tenant_electricalMeter_03_ct_ratio']) : 1.0;
        
        $tenant_waterMeter_01 = (isset($_POST['f-tenant_waterMeter_01']) && trim($_POST['f-tenant_waterMeter_01']) !== '') ? trim($_POST['f-tenant_waterMeter_01']) : null;
        $tenant_waterMeter_02 = (isset($_POST['f-tenant_waterMeter_02']) && trim($_POST['f-tenant_waterMeter_02']) !== '') ? trim($_POST['f-tenant_waterMeter_02']) : null;
        $tenant_waterMeter_03 = (isset($_POST['f-tenant_waterMeter_03']) && trim($_POST['f-tenant_waterMeter_03']) !== '') ? trim($_POST['f-tenant_waterMeter_03']) : null;
        $tenant_waterMeter_04 = (isset($_POST['f-tenant_waterMeter_04']) && trim($_POST['f-tenant_waterMeter_04']) !== '') ? trim($_POST['f-tenant_waterMeter_04']) : null;

        $tenant_electrical_tariff_charge = $_POST['f-tenant_electrical_tariff_charge'] ?? 'Not applicable';
        $tenant_electrical_commArea_charge = $_POST['f-tenant_electrical_commArea_charge'] ?? 'Not applicable';
        $tenant_water_tariff_charge = $_POST['f-tenant_water_tariff_charge'] ?? 'Not applicable';
        $tenant_water_sewer_tariff_charge = $_POST['f-tenant_water_sewer_tariff_charge'] ?? 'Not applicable';
        $tenant_water_commArea_charge = $_POST['f-tenant_water_commArea_charge'] ?? 'Not applicable';
        $tenant_generator_tariff_charge = $_POST['f-tenant_generator_tariff_charge'] ?? 'Not applicable';
        
        $tenant_council_refuse_charge = (isset($_POST['f-tenant_council_refuse_charge']) && trim($_POST['f-tenant_council_refuse_charge']) !== '') ? floatval($_POST['f-tenant_council_refuse_charge']) : null;

        $shared_nac_contribute = !empty($_POST['f_shared_nac_contribute']) ? $_POST['f_shared_nac_contribute'] : null;
        $pays_shared_nac = !empty($_POST['f_pays_shared_nac']) ? $_POST['f_pays_shared_nac'] : null;

        $occupancy_start_date = !empty($_POST['f_occupancy_start_date']) ? $_POST['f_occupancy_start_date'] : null;
        $occupancy_end_date = !empty($_POST['f_occupancy_end_date']) ? $_POST['f_occupancy_end_date'] : null;
        $tenant_onsite = !empty($_POST['f_tenant_onsite']) ? trim($_POST['f_tenant_onsite']) : null;
        $email = !empty($_POST['f_email']) ? trim($_POST['f_email']) : null;
        $cell_number = !empty($_POST['f_cell_number']) ? trim($_POST['f_cell_number']) : null;

        $tenant_pays_electrical_basic_charge = $_POST['f-tenant_pays_electrical_basic_charge'] ?? null;
        $tenant_pays_water_basic_charge = $_POST['f-tenant_pays_water_basic_charge'] ?? null;
        $tenant_pays_sewer_basic_charge = $_POST['f-tenant_pays_sewer_basic_charge'] ?? null;

        $tenant_pays_electrical_tariff_charge = $_POST['f-tenant_pays_electrical_tariff_charge'] ?? 'Yes';
        $tenant_pays_demand_tariff_charge = $_POST['f-tenant_pays_demand_tariff_charge'] ?? 'Yes';
        $tenant_pays_generator_tariff_charge = $_POST['f-tenant_pays_generator_tariff_charge'] ?? 'Yes';

        // --- Is this tenant, or one of these meters, already on the system? ---
        // Catches the same business registered twice (which bills it twice) and a meter
        // still linked to another tenant. Shown for confirmation, never blocked outright.
        if (!isset($_POST['confirm_duplicate'])) {
            $warnings = [];
            $pdo = lum_db('tenants');

            $stmt = $pdo->prepare(
                "SELECT tenant_id, tenant_name, tenant_code, tenant_shop, tenant_occupancy_end_date
                   FROM lum_tenants
                  WHERE tenant_property = :prop
                    AND ( (:code <> '' AND tenant_code = :code2)
                       OR (:shop <> '' AND tenant_shop = :shop2)
                       OR tenant_name = :name )
                  ORDER BY tenant_id");
            $stmt->execute([
                'prop' => $tenant_property,
                'code' => $tenant_code, 'code2' => $tenant_code,
                'shop' => $tenant_shop, 'shop2' => $tenant_shop,
                'name' => $tenant_name,
            ]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $why = [];
                if ($tenant_code !== '' && $m['tenant_code'] === $tenant_code) $why[] = 'same code';
                if ($tenant_shop !== '' && $m['tenant_shop'] === $tenant_shop) $why[] = 'same shop';
                if ($m['tenant_name'] === $tenant_name) $why[] = 'same name';
                $warnings[] = 'Tenant ' . $m['tenant_id'] . ' "' . $m['tenant_name'] . '" (shop ' . ($m['tenant_shop'] ?: '-')
                            . ', code ' . ($m['tenant_code'] ?: '-') . ') - ' . implode(', ', $why)
                            . ($m['tenant_occupancy_end_date'] ? '; left on ' . $m['tenant_occupancy_end_date'] : '; still active');
            }

            $meters = array_filter([
                $tenant_electricalMeter_01, $tenant_electricalMeter_02, $tenant_electricalMeter_03,
                $tenant_waterMeter_01, $tenant_waterMeter_02, $tenant_waterMeter_03, $tenant_waterMeter_04,
            ], static function ($v) { return $v !== null && $v !== ''; });
            foreach ($meters as $serial) {
                $ms = $pdo->prepare(
                    "SELECT tenant_id, tenant_name, tenant_property, tenant_shop FROM lum_tenants
                      WHERE :s IN (tenant_electricalMeter_01, tenant_electricalMeter_02, tenant_electricalMeter_03,
                                   tenant_waterMeter_01, tenant_waterMeter_02, tenant_waterMeter_03, tenant_waterMeter_04)
                        AND (tenant_occupancy_end_date IS NULL OR tenant_occupancy_end_date >= CURDATE())");
                $ms->execute(['s' => $serial]);
                foreach ($ms->fetchAll(PDO::FETCH_ASSOC) as $m) {
                    $warnings[] = 'Meter ' . $serial . ' is still on tenant ' . $m['tenant_id'] . ' "' . $m['tenant_name']
                                . '" (' . $m['tenant_property'] . ', shop ' . ($m['tenant_shop'] ?: '-') . ')';
                }
            }

            if (!empty($warnings)) {
                $back = 'https://lynx-um.co.za/Tenant Management/Tenant Registration/tenant-registration-form.php';
                echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Check before saving</title>';
                echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
                include LUM_ROOT . '/Layout/head-assets.php';
                echo '</head><body class="bg-dark text-white p-4"><div class="container" style="max-width: 820px;">';
                echo '<h4 class="mb-3">This tenant may already be on the system</h4>';
                echo '<p class="fs-6">Please check:</p>';
                echo '<ul class="fs-6">';
                foreach ($warnings as $w) { echo '<li>' . htmlspecialchars($w) . '</li>'; }
                echo '</ul>';

                echo '<form method="POST" action="">';
                foreach ($_POST as $k => $v) {
                    if (is_array($v)) { continue; }
                    echo '<input type="hidden" name="' . htmlspecialchars((string)$k) . '" value="' . htmlspecialchars((string)$v) . '">';
                }
                echo '<input type="hidden" name="confirm_duplicate" value="1">';
                echo '<button type="submit" class="btn btn-danger me-2">Save anyway</button>';
                echo '<a class="btn btn-outline-light" href="' . htmlspecialchars($back) . '">Go back and change it</a>';
                echo '</form></div></body></html>';
                exit();
            }
        }

        try {
            if (!isset($tenant_crud)) {
                throw new Exception("Error: \$tenant_crud object is not defined. Check your db-conn-tenants.php file.");
            }

            $result = $tenant_crud->tenant_registration(
                $tenant_property, $tenant_name, $tenant_code, $tenant_shop, $tenant_shop_area, 
                $tenant_amps, $tenant_comm_area, $tenant_electricalMeter_01, $tenant_electricalMeter_02, 
                $tenant_electricalMeter_03, $tenant_waterMeter_01, $tenant_waterMeter_02, $tenant_waterMeter_03, $tenant_waterMeter_04,
                $tenant_electrical_tariff_charge, $tenant_electrical_commArea_charge, $tenant_water_tariff_charge, 
                $tenant_water_sewer_tariff_charge, $tenant_water_commArea_charge, $tenant_generator_tariff_charge,
                $tenant_pays_electrical_commArea, $tenant_pays_water_commArea, $tenant_council_refuse_charge,
                $shared_nac_contribute, $pays_shared_nac,
                $occupancy_start_date, $occupancy_end_date, $tenant_onsite, $email, $cell_number,
                $tenant_electricalMeter_01_ct_ratio, $tenant_electricalMeter_02_ct_ratio, $tenant_electricalMeter_03_ct_ratio,
                $tenant_pays_electrical_basic_charge, $tenant_pays_water_basic_charge, $tenant_pays_sewer_basic_charge,
                $tenant_pays_electrical_tariff_charge, $tenant_pays_demand_tariff_charge, $tenant_pays_generator_tariff_charge
            );

            if ($result) {
                // The slip settings, now that the tenant has an id
                try {
                    $new_tenant_id = (int)$result;
                    if ($new_tenant_id > 0) {
                        $slip_layout = (($_POST['f_slip_layout'] ?? 'combined') === 'separate') ? 'separate' : 'combined';
                        $water_code = trim((string)($_POST['f_water_slip_code'] ?? ''));
                        $water_email = trim((string)($_POST['f_water_slip_email'] ?? ''));
                        $slip_stmt = lum_db('tenants')->prepare(
                            "UPDATE lum_tenants SET slip_layout = :layout, water_slip_code = :code, water_slip_email = :email
                              WHERE tenant_id = :id");
                        $slip_stmt->execute([
                            'layout' => $slip_layout,
                            'code'   => $water_code !== '' ? $water_code : null,
                            'email'  => $water_email !== '' ? $water_email : null,
                            'id'     => $new_tenant_id,
                        ]);
                    }
                } catch (Throwable $e) {
                    error_log('LUM slip settings not saved for the new tenant: ' . $e->getMessage());
                }

                header("Location: https://lynx-um.co.za/Tenant Management/tenant-overview.php");
                exit(); 
            } else {
                echo "<h2>Error</h2><p>The database registration failed.</p>";
            }

        } catch (Exception $e) {
            echo "<div style='color: white; background: red; padding: 20px; font-family: sans-serif;'>";
            error_log('LUM tenant save failed: ' . $e->getMessage());
            echo "<h3>The tenant could not be saved.</h3>The error has been logged for the system administrator.";
            echo "<br><br><a href='tenant-registration-form.php' style='color: white;'>Go Back to Registration Form</a>";
            echo "</div>";
            exit();
        }
    }
?>