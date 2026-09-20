<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once __DIR__ . '/bootstrap.php';
lum_page('manual_readings', 'edit');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // --- VALIDATE CSRF TOKEN ---
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        die("<div style='color:white; background:red; padding:20px; font-family:sans-serif;'>Security Error: Invalid CSRF Token. Request Blocked.</div>");
    }

    lum_connect('manual'); // $manual_db_conn (bootstrap.php)
    $manual_genrun_conr = lum_resolve_path('manual-genrun-conr.php');
    if ($manual_genrun_conr === false) lum_db_fail('Critical Error: manual genrun controller missing.');
    require_once $manual_genrun_conr;

    $conr = new manual_genrun_conr($manual_db_conn);

    $property = trim($_POST['property'] ?? '');
    $reading_date = $_POST['reading_date'] ?? date('Y-m-d');
    $reading_hours = trim($_POST['reading_hours'] ?? '');
    $overview_url = lum_app_url('/Manual%20Readings/manual-genrun-overview.php');

    // Validate the reading date
    $date_obj = DateTime::createFromFormat('Y-m-d', $reading_date);
    if (!$date_obj || $date_obj->format('Y-m-d') !== $reading_date) {
        $_SESSION['error_message'] = "Invalid reading date.";
        header("Location: " . $overview_url);
        exit();
    }

    // Property access: restricted users may only submit for their own properties; the property must exist
    $lum_allowed = (!empty($_SESSION['assigned_properties']) ? array_map('trim', explode(',', $_SESSION['assigned_properties'])) : null);
    $prop_ok = false;
    try {
        $core_db_conn = lum_db('properties'); // bootstrap.php
        if ($core_db_conn) {
            $chk = $core_db_conn->prepare("SELECT 1 FROM lum_properties WHERE Property = :p LIMIT 1");
            $chk->execute(['p' => $property]);
            $prop_ok = (bool)$chk->fetchColumn();
        }
    } catch (Exception $e) {
        error_log('LUM genrun submit property check failed: ' . $e->getMessage());
    }
    if (!$prop_ok || ($lum_allowed !== null && !in_array($property, $lum_allowed, true))) {
        $_SESSION['error_message'] = "You do not have access to this property.";
        header("Location: " . $overview_url);
        exit();
    }

    // Format handling: if user entered simple numbers like "2" or "2.5", format to HH:MM:SS
    if (is_numeric($reading_hours)) {
        $hours = (float)$reading_hours;
        $h = floor($hours);
        $m = round(($hours - $h) * 60);
        $reading_hours = sprintf("%02d:%02d:00", $h, $m);
    } elseif (preg_match('/^\d+:[0-5]\d$/', $reading_hours)) {
        $reading_hours .= ":00"; // HHHH:MM -> HHHH:MM:00
    }

    // Runtime must be a plain hour-meter value: HHHH:MM:SS
    if (!preg_match('/^\d{1,7}:[0-5]\d:[0-5]\d$/', $reading_hours)) {
        $_SESSION['error_message'] = "Runtime Hours must be in the format HH:MM:SS (e.g. 3071:14:53).";
        header("Location: " . $overview_url);
        exit();
    }

    if (!empty($property) && !empty($reading_date) && !empty($reading_hours)) {
        if ($conr->add_reading($property, $reading_date, $reading_hours)) {
            $_SESSION['success_message'] = "Generator runtime reading successfully added.";
        } else {
            $_SESSION['error_message'] = "Failed to save the generator runtime reading in the database.";
        }
    } else {
        $_SESSION['error_message'] = "Please fill in all required fields.";
    }

    header('Location: ' . $overview_url);
    exit();
} else {
    header('Location: ' . lum_app_url('/Manual%20Readings/manual-genrun-overview.php'));
    exit();
}
?>