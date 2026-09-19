<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once '/var/www/Lynx/bootstrap.php';
lum_page('configs', 'edit');

// Estimate time ranges (bootstrap.php)
$info_db_conn = lum_db('information', true);

$message = '';
$error = '';
$estimate_data = null;

// Audit trail (switches itself off if the logger is missing)
lum_use('audit');

// 1. Process Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_estimate'])) {
    
    // --- VALIDATE CSRF TOKEN ---
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        die("<div style='color:white; padding:20px; background:#121212; height:100vh;'>Security Error: Invalid CSRF Token. Request Blocked.</div>");
    }

    $id = $_POST['estimate_id'] ?? null;
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';

    if ($id && !empty($start_time) && !empty($end_time)) {
        try {
            // Convert HTML5 datetime-local format (YYYY-MM-DDTHH:MM) to strict MySQL format (YYYY-MM-DD HH:MM:SS)
            $parsed_start = strtotime($start_time);
            $parsed_end = strtotime($end_time);

            if ($parsed_start !== false && $parsed_end !== false) {
                $mysql_start_time = date('Y-m-d H:i:s', $parsed_start);
                $mysql_end_time = date('Y-m-d H:i:s', $parsed_end);

                $audit_before = lum_audit_fetch_row($info_db_conn, 'lum_estimate_time_ranges', 'id', $id);
                $update_stmt = $info_db_conn->prepare("UPDATE `lum_estimate_time_ranges` SET `start_time` = :st, `end_time` = :et WHERE `id` = :id");
                $update_stmt->execute([
                    ':st' => $mysql_start_time,
                    ':et' => $mysql_end_time,
                    ':id' => $id
                ]);
                
                $audit_after = lum_audit_fetch_row($info_db_conn, 'lum_estimate_time_ranges', 'id', $id);
                lum_audit_log('UPDATE', 'estimate_time_range', $id, $audit_after['obis_code'] ?? ($audit_before['obis_code'] ?? null), null, $audit_before, $audit_after);

                header("Location: view-configs.php?success=1");
                exit();
                
            } else {
                $error = "Invalid datetime structure supplied. Please use a valid calendar selection.";
            }
        } catch (Exception $e) {
            error_log('LUM edit-estimate-time.php: Failed to update Estimate configuration: ' . $e->getMessage());
            $error = "Failed to update Estimate configuration. Please try again or contact the system administrator.";
        }
    } else {
        $error = "Start and End Dates/Times cannot be left empty.";
    }
}

// 2. Fetch Existing Data for the Form
$edit_id = $_GET['id'] ?? ($_POST['estimate_id'] ?? null);

if ($edit_id) {
    try {
        $fetch_stmt = $info_db_conn->prepare("SELECT * FROM `lum_estimate_time_ranges` WHERE `id` = :id");
        $fetch_stmt->execute([':id' => $edit_id]);
        $estimate_data = $fetch_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$estimate_data) {
            $error = "Estimate configuration record not found.";
        }
    } catch (Exception $e) {
        error_log('LUM edit-estimate-time.php: Failed to fetch Estimate details: ' . $e->getMessage());
        $error = "Failed to fetch Estimate details. Please try again or contact the system administrator.";
    }
} else {
    $error = "No valid Estimate ID was provided. Cannot load configurations.";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Estimate Range - Lynx Utility Management</title>
    
    <!-- Bootstrap 5 CSS -->
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>
    <!-- Bootstrap Icons -->

    <style>
        body {
            background-color: #121212;
            color: #ffffff;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        /* --- Navbar Styles --- */
        .lynx-brand-hover:hover { color: #e3000f !important; }
        /* --- Form Specific Styles --- */
        .form-container {
            background-color: #1e1e1e;
            border: 1px solid #333333;
            border-radius: 12px;
            padding: 30px;
            max-width: 600px;
            margin: 40px auto;
            box-shadow: 0 8px 16px rgba(0,0,0,0.4);
        }
        .btn-brand {
            background-color: #0dcaf0;
            color: #000000;
            border: none;
            transition: 0.3s;
            font-weight: 600;
        }
        .btn-brand:hover {
            background-color: #31d2f2;
            color: #000000;
            transform: translateY(-1px);
        }
        /* Form Inputs */
        .form-label {
            color: #aaaaaa;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        /* Format adjustment to better fit standard datetime lengths */
        .form-control {
            background-color: #121212 !important;
            border: 1px solid #444 !important;
            color: #fff !important;
            padding: 10px 12px;
            border-radius: 8px;
            font-family: monospace;
            font-size: 1rem;
        }
        .form-control:focus {
            border-color: #0dcaf0 !important;
            box-shadow: 0 0 0 0.25rem rgba(13, 202, 240, 0.25) !important;
        }
        /* Override default calendar icon fill */
        ::-webkit-calendar-picker-indicator {
            filter: invert(1);
            cursor: pointer;
        }
    </style>
</head>
<body>

    <!-- Top Navbar -->
    <nav class="navbar navbar-dark py-1 border-bottom" style="background-color: #000000; border-color: #333333 !important;">
        <div class="container-fluid px-3">
            <a class="navbar-brand fs-6 mb-0 text-white lynx-brand-hover transition-colors" href="view-configs.php" style="transition: color 0.2s ease-in-out;">
                <i class="bi bi-arrow-left me-2"></i>Back to Configurations
            </a>
            <div class="d-flex align-items-center">
                <div class="text-secondary small d-flex align-items-center">
                    <i class="bi bi-person-fill text-white me-2"></i>
                    <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container-fluid px-4">
        
        <?php if ($error): ?>
            <div class="alert alert-danger mt-4 mx-auto" style="max-width: 600px;">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($estimate_data): ?>
        <div class="form-container">
            <h4 class="mb-4 text-white border-bottom border-secondary pb-3">
                <i class="bi bi-calendar-event me-2 text-info"></i>
                Edit Estimate Time Range Constraints
            </h4>

            <form action="edit-estimate-time.php" method="POST">
                
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="estimate_id" value="<?php echo htmlspecialchars($estimate_data['id']); ?>">

                <div class="mb-4">
                    <label class="form-label">Target OBIS Code</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($estimate_data['obis_code']); ?>" disabled>
                </div>

                <div class="row mb-5">
                    <div class="col-md-6">
                        <label class="form-label text-success">Start Datetime Boundary</label>
                        <?php 
                        // HTML5 datetime-local requires values strictly formatted as YYYY-MM-DDTHH:MM:SS
                        $fmt_start = date('Y-m-d\TH:i:s', strtotime($estimate_data['start_time'])); 
                        ?>
                        <input type="datetime-local" step="1" class="form-control border-success text-center" name="start_time" value="<?php echo htmlspecialchars($fmt_start); ?>" required>
                    </div>
                    <div class="col-md-6 mt-4 mt-md-0">
                        <label class="form-label text-danger">End Datetime Boundary</label>
                        <?php 
                        $fmt_end = date('Y-m-d\TH:i:s', strtotime($estimate_data['end_time'])); 
                        ?>
                        <input type="datetime-local" step="1" class="form-control border-danger text-center" name="end_time" value="<?php echo htmlspecialchars($fmt_end); ?>" required>
                    </div>
                </div>

                <div class="d-flex justify-content-end border-top border-secondary pt-4">
                    <a href="view-configs.php" class="btn btn-outline-secondary me-2 px-4 py-2">Cancel</a>
                    <button type="submit" name="update_estimate" class="btn btn-brand px-4 py-2">
                        <i class="bi bi-save me-2"></i>Save Restrictions
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>

    </div>

    <?php include LUM_ROOT . '/Layout/foot-assets.php'; ?>
</body>
</html>