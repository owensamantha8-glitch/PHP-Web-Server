<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once __DIR__ . '/bootstrap.php';
lum_page('manual_readings', 'edit');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // --- VALIDATE CSRF TOKEN ---
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        die("<div style='color:white; background:red; padding:20px; font-family:sans-serif;'>Security Error: Invalid CSRF Token. Request Blocked.</div>");
    }

    lum_connect('manual', 'tenants'); // $manual_db_conn, $tenant_db_conn (bootstrap.php)
    $manual_water_conr = lum_resolve_path('manual-water-conr.php');
    if ($manual_water_conr === false) lum_db_fail('Critical Error: manual water controller missing.');
    require_once $manual_water_conr;

    $manual_reading_crud = new manual_water_conr($manual_db_conn);

    $overview_url = lum_app_url('/Manual%20Readings/manual-water-overview.php');

    $property = trim($_POST['property'] ?? '');
    $reading_date = $_POST['reading_date'] ?? date('Y-m-d');

    // Validate the reading date
    $date_obj = DateTime::createFromFormat('Y-m-d', $reading_date);
    if (!$date_obj || $date_obj->format('Y-m-d') !== $reading_date) {
        $_SESSION['error_message'] = "Invalid reading date.";
        header("Location: " . $overview_url);
        exit();
    }

    // Property access: users with assigned properties may only submit for those properties
    if (!empty($_SESSION['assigned_properties'])) {
        $allowed_props = array_map('trim', explode(',', $_SESSION['assigned_properties']));
        if (!in_array($property, $allowed_props, true)) {
            $_SESSION['error_message'] = "You do not have access to this property.";
            header("Location: " . $overview_url);
            exit();
        }
    }

    // Only meters that belong to this property's tenants are accepted; tenant and shop come from the database
    $valid_meters = [];
    $stmt_m = $tenant_db_conn->prepare("SELECT tenant_name, tenant_shop, tenant_waterMeter_01, tenant_waterMeter_02, tenant_waterMeter_03, tenant_waterMeter_04 FROM lum_tenants WHERE tenant_property = :prop");
    $stmt_m->execute(['prop' => $property]);
    foreach ($stmt_m->fetchAll(PDO::FETCH_ASSOC) as $t) {
        foreach (['tenant_waterMeter_01', 'tenant_waterMeter_02', 'tenant_waterMeter_03', 'tenant_waterMeter_04'] as $col) {
            $serial = trim((string)($t[$col] ?? ''));
            if ($serial !== '' && !isset($valid_meters[$serial])) {
                $valid_meters[$serial] = ['tenant' => $t['tenant_name'], 'shop' => $t['tenant_shop']];
            }
        }
    }
    if (empty($valid_meters)) {
        $_SESSION['error_message'] = "No water meters were found for this property.";
        header("Location: " . $overview_url);
        exit();
    }
    
    // "Allow unusual readings" lets a reading through that is lower than the previous one,
    // or far higher than this meter normally moves. Duplicates are never allowed.
    $allow_unusual = isset($_POST['allow_unusual']);

    // Extract array data directly from the dynamic bulk-submission form
    $tenants = $_POST['tenants'] ?? [];
    $shops = $_POST['shops'] ?? [];
    $serials = $_POST['serials'] ?? [];
    $readings = $_POST['readings'] ?? [];

    $added_count = 0;
    $refused = [];   // readings the checks stopped, with the reason

    // The last reading before this date, and the first one after it, per meter
    $stmt_before = $manual_db_conn->prepare(
        "SELECT reading, reading_date FROM manual_readings_water
          WHERE water_serial = :serial AND reading_date <= :d
          ORDER BY reading_date DESC, id DESC LIMIT 1");
    $stmt_after = $manual_db_conn->prepare(
        "SELECT reading, reading_date FROM manual_readings_water
          WHERE water_serial = :serial AND reading_date > :d
          ORDER BY reading_date ASC, id ASC LIMIT 1");
    $stmt_same = $manual_db_conn->prepare(
        "SELECT reading FROM manual_readings_water
          WHERE water_serial = :serial AND reading_date = :d LIMIT 1");
    // How much this meter usually moves in a month, from its own history
    $stmt_pace = $manual_db_conn->prepare(
        "SELECT (MAX(reading) - MIN(reading)) / GREATEST(DATEDIFF(MAX(reading_date), MIN(reading_date)), 1) * 30 AS monthly
           FROM manual_readings_water WHERE water_serial = :serial");

    for ($i = 0; $i < count($serials); $i++) {
        $reading_val = trim($readings[$i] ?? '');
        
        // Skip entry entirely if the input is left blank (allowing single submissions from the list)
        if ($reading_val !== '') {
            $water_serial = trim($serials[$i] ?? '');
            if (!is_numeric($reading_val) || !isset($valid_meters[$water_serial])) {
                continue; // Not a number, or not a meter of this property
            }
            $tenant = $valid_meters[$water_serial]['tenant'];
            $shop = $valid_meters[$water_serial]['shop'];
            $reading = floatval($reading_val);
            
            if (!empty($water_serial)) {
                $label = $tenant . ' (' . $water_serial . ')';

                // A reading for this meter and date already exists: always refused, to avoid
                // two readings for the same day (edit the existing one instead).
                $stmt_same->execute(['serial' => $water_serial, 'd' => $reading_date]);
                $same = $stmt_same->fetch(PDO::FETCH_ASSOC);
                if ($same) {
                    $refused[] = $label . ': a reading of ' . rtrim(rtrim(number_format((float)$same['reading'], 4, '.', ''), '0'), '.')
                               . ' is already captured for ' . $reading_date . ' - edit that one instead';
                    continue;
                }

                $stmt_before->execute(['serial' => $water_serial, 'd' => $reading_date]);
                $before = $stmt_before->fetch(PDO::FETCH_ASSOC);
                $stmt_after->execute(['serial' => $water_serial, 'd' => $reading_date]);
                $after = $stmt_after->fetch(PDO::FETCH_ASSOC);

                if (!$allow_unusual && $before && $reading < (float)$before['reading']) {
                    $refused[] = $label . ': ' . $reading . ' is lower than the reading of '
                               . (float)$before['reading'] . ' on ' . $before['reading_date']
                               . ' - a meter only counts up';
                    continue;
                }
                if (!$allow_unusual && $after && $reading > (float)$after['reading']) {
                    $refused[] = $label . ': ' . $reading . ' is higher than the later reading of '
                               . (float)$after['reading'] . ' on ' . $after['reading_date'];
                    continue;
                }
                if (!$allow_unusual && $before) {
                    $used = $reading - (float)$before['reading'];
                    $days = max((int)((strtotime($reading_date) - strtotime($before['reading_date'])) / 86400), 1);
                    $stmt_pace->execute(['serial' => $water_serial]);
                    $pace = (float)($stmt_pace->fetchColumn() ?: 0);
                    $expected = $pace > 0 ? $pace * ($days / 30) : 0;
                    // Only question it when the jump is large in itself as well, so small meters
                    // with little history do not trip the check.
                    if ($expected > 0 && $used > $expected * 5 && $used > 20) {
                        $refused[] = $label . ': ' . round($used, 3) . ' kl over ' . $days . ' day(s) is far above the '
                                   . round($expected, 3) . ' kl this meter normally uses - tick "allow unusual readings" if it is correct';
                        continue;
                    }
                }

                if ($manual_reading_crud->add_reading($property, $tenant, $shop, $reading, $reading_date, $water_serial)) {
                    $added_count++;
                }
            }
        }
    }

    if ($added_count > 0) {
        $_SESSION['success_message'] = "{$added_count} manual water reading(s) successfully added."
            . (empty($refused) ? '' : ' ' . count($refused) . ' were not added, see below.');
    } elseif (empty($refused)) {
        $_SESSION['error_message'] = "No readings were added. Please ensure you entered at least one numerical reading.";
    }
    if (!empty($refused)) {
        $_SESSION['error_message'] = "These readings were not added: " . implode(' | ', $refused);
    }

    header('Location: ' . $overview_url);
    exit();
} else {
    header('Location: ' . lum_app_url('/Manual%20Readings/manual-water-overview.php'));
    exit();
}
?>