<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once __DIR__ . '/bootstrap.php';
lum_page('configs', 'edit');

// OBIS time ranges (bootstrap.php)
$info_db_conn = lum_db('information', true);

$message = '';
$error = '';
$obis_data = null;

// Name, unit, category, colour and order of the code (added by obis-codes-setup.sql)
$has_name_cols = false;
try {
    $has_name_cols = (bool)$info_db_conn->query("SHOW COLUMNS FROM `lum_obis_time_ranges` LIKE 'obis_category'")->fetch();
} catch (Exception $e) {}

// Audit trail (switches itself off if the logger is missing)
lum_use('audit');

// 1. Process Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_obis'])) {
    
    // --- VALIDATE CSRF TOKEN ---
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        die("<div style='color:white; padding:20px; background:#121212; height:100vh;'>Security Error: Invalid CSRF Token. Request Blocked.</div>");
    }

    $id = $_POST['obis_id'] ?? null;
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';

    if ($id && !empty($start_time) && !empty($end_time)) {
        try {
            // Strict Regex to ensure time strings are always safe "HH:MM:SS" before entering database
            if (preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $start_time) && preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $end_time)) {
                
                $audit_before = lum_audit_fetch_row($info_db_conn, 'lum_obis_time_ranges', 'id', $id);
                if ($has_name_cols) {
                    $o_label = substr(trim((string)($_POST['obis_label'] ?? '')), 0, 60);
                    $o_unit = substr(trim((string)($_POST['obis_unit'] ?? '')), 0, 10);
                    $o_cat = in_array($_POST['obis_category'] ?? '', ['Electrical', 'Water'], true) ? $_POST['obis_category'] : null;
                    $o_color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_POST['chart_color'] ?? '')) ? $_POST['chart_color'] : null;
                    $o_sort = max(0, min(999, (int)($_POST['sort_order'] ?? 0)));
                    $update_stmt = $info_db_conn->prepare("UPDATE `lum_obis_time_ranges` SET `start_time` = :st, `end_time` = :et,
                                                           `obis_label` = :lb, `obis_unit` = :un, `obis_category` = :ct, `chart_color` = :cc, `sort_order` = :so
                                                           WHERE `id` = :id");
                    $update_stmt->execute([
                        ':st' => $start_time, ':et' => $end_time,
                        ':lb' => ($o_label !== '' ? $o_label : null), ':un' => ($o_unit !== '' ? $o_unit : null),
                        ':ct' => $o_cat, ':cc' => $o_color, ':so' => $o_sort,
                        ':id' => $id
                    ]);
                } else {
                    $update_stmt = $info_db_conn->prepare("UPDATE `lum_obis_time_ranges` SET `start_time` = :st, `end_time` = :et WHERE `id` = :id");
                    $update_stmt->execute([
                        ':st' => $start_time,
                        ':et' => $end_time,
                        ':id' => $id
                    ]);
                }
                
                $audit_after = lum_audit_fetch_row($info_db_conn, 'lum_obis_time_ranges', 'id', $id);
                lum_audit_log('UPDATE', 'obis_time_range', $id, $audit_after['obis_code'] ?? ($audit_before['obis_code'] ?? null), null, $audit_before, $audit_after);

                header("Location: view-configs.php?success=1");
                exit();
                
            } else {
                $error = "Invalid time string. Times must be supplied in strict HH:MM:SS format.";
            }
        } catch (Exception $e) {
            error_log('LUM edit-obis-time.php: Failed to update OBIS configuration: ' . $e->getMessage());
            $error = "Failed to update OBIS configuration. Please try again or contact the system administrator.";
        }
    } else {
        $error = "Start and End Times cannot be left empty.";
    }
}

// 2. Fetch Existing Data for the Form
$edit_id = $_GET['id'] ?? ($_POST['obis_id'] ?? null);

if ($edit_id) {
    try {
        $fetch_stmt = $info_db_conn->prepare("SELECT * FROM `lum_obis_time_ranges` WHERE `id` = :id");
        $fetch_stmt->execute([':id' => $edit_id]);
        $obis_data = $fetch_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$obis_data) {
            $error = "OBIS configuration record not found.";
        }
    } catch (Exception $e) {
        error_log('LUM edit-obis-time.php: Failed to fetch OBIS details: ' . $e->getMessage());
        $error = "Failed to fetch OBIS details. Please try again or contact the system administrator.";
    }
} else {
    $error = "No valid OBIS ID was provided. Cannot load configurations.";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit OBIS Range - Lynx Utility Management</title>
    
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
        .form-control {
            background-color: #121212 !important;
            border: 1px solid #444 !important;
            color: #fff !important;
            padding: 12px 15px;
            border-radius: 8px;
            font-family: monospace;
            font-size: 1.1rem;
        }
        .form-control:focus {
            border-color: #0dcaf0 !important;
            box-shadow: 0 0 0 0.25rem rgba(13, 202, 240, 0.25) !important;
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

        <?php if ($obis_data): ?>
        <div class="form-container">
            <h4 class="mb-4 text-white border-bottom border-secondary pb-3">
                <i class="bi bi-clock-history me-2 text-info"></i>
                Edit OBIS Range Constraints
            </h4>

            <form action="edit-obis-time.php" method="POST">
                
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="obis_id" value="<?php echo htmlspecialchars($obis_data['id']); ?>">

                <div class="mb-4">
                    <label class="form-label">Target OBIS Code</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($obis_data['obis_code']); ?>" disabled>
                </div>

                <?php if ($has_name_cols): ?>
                <!-- How the code is shown on Meter Status, View Meter and this screen -->
                <div class="row mb-4 g-3">
                    <div class="col-md-6">
                        <label class="form-label">Name</label>
                        <input type="text" class="form-control" name="obis_label" maxlength="60" value="<?php echo htmlspecialchars($obis_data['obis_label'] ?? ''); ?>" placeholder="e.g. Grid Supply">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Unit</label>
                        <input type="text" class="form-control" name="obis_unit" maxlength="10" value="<?php echo htmlspecialchars($obis_data['obis_unit'] ?? ''); ?>" placeholder="kWh / kL">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Category</label>
                        <select class="form-select" name="obis_category">
                            <option value="">Not used by the meter pages</option>
                            <?php foreach (['Electrical', 'Water'] as $oc): ?>
                                <option value="<?php echo $oc; ?>" <?php echo ($obis_data['obis_category'] ?? '') === $oc ? 'selected' : ''; ?>><?php echo $oc; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Chart colour</label>
                        <input type="color" class="form-control form-control-color w-100" name="chart_color" value="<?php echo htmlspecialchars(preg_match('/^#[0-9a-fA-F]{6}$/', (string)($obis_data['chart_color'] ?? '')) ? $obis_data['chart_color'] : '#6c757d'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Order</label>
                        <input type="number" class="form-control" name="sort_order" min="0" max="999" value="<?php echo (int)($obis_data['sort_order'] ?? 0); ?>">
                    </div>
                </div>
                <?php else: ?>
                <div class="alert alert-secondary small">Run <strong>obis-codes-setup.sql</strong> to give OBIS codes a name, unit, category and chart colour.</div>
                <?php endif; ?>

                <div class="row mb-5">
                    <div class="col-md-6">
                        <label class="form-label text-success">Start Time Boundary</label>
                        <input type="time" step="1" class="form-control border-success text-center" name="start_time" value="<?php echo htmlspecialchars($obis_data['start_time']); ?>" required>
                    </div>
                    <div class="col-md-6 mt-4 mt-md-0">
                        <label class="form-label text-danger">End Time Boundary</label>
                        <input type="time" step="1" class="form-control border-danger text-center" name="end_time" value="<?php echo htmlspecialchars($obis_data['end_time']); ?>" required>
                    </div>
                </div>

                <div class="d-flex justify-content-end border-top border-secondary pt-4">
                    <a href="view-configs.php" class="btn btn-outline-secondary me-2 px-4 py-2">Cancel</a>
                    <button type="submit" name="update_obis" class="btn btn-brand px-4 py-2">
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