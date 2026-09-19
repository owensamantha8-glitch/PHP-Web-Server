<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once '/var/www/Lynx/bootstrap.php';
lum_page('configs', 'view');

// =========================================================================
// LYNX UTILITY MANAGEMENT - COMPANY DETAILS
// Location: /var/www/Lynx/Configs/company-details.php (next to view-configs.php)
// The company details printed on consumption slips and credit / debit notes.
// =========================================================================

lum_use('reporting'); // lumCompanySettings() and the document header
// Audit trail (switches itself off if the logger is missing)
lum_use('audit');

$can_edit = lum_can('configs', 'edit');
$user_name = substr((string)($_SESSION['user_name'] ?? 'system'), 0, 50);

// Field definitions: key => [label, max length, help]
$fields = [
    'company_name'         => ['Company name', 150, ''],
    'company_phone'        => ['Telephone', 50, ''],
    'company_email'        => ['Email address', 150, ''],
    'company_address'      => ['Address (optional)', 255, ''],
    'company_registration' => ['Company registration number (optional)', 60, ''],
    'vat_number'           => ['VAT number (optional)', 60, ''],
    'logo_url'             => ['Logo address', 255, ''],
    'slip_title'           => ['Consumption slip title', 60, ''],
    'contact_note'         => ['Contact note at the bottom', 1000, ''],
];

$db = null;
$db_error = '';
try {
    $db = lum_db('information'); // bootstrap.php
    if (!$db) throw new RuntimeException('sys_db_information is not available');
    $db->query("SELECT 1 FROM lum_company_settings LIMIT 1");
} catch (\Throwable $e) {
    error_log('LUM company details page: ' . $e->getMessage());
    $db = null;
    $db_error = 'The company details table is not installed (run company-details-setup.sql). The built-in details below are in use.';
}

$message = $_SESSION['company_message'] ?? null;
unset($_SESSION['company_message']);
$form = null;
$form_error = '';

// =========================================================================
// SAVE
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db) {
    lum_require_csrf();
    lum_require_access('configs', 'edit');

    $form = [];
    foreach ($fields as $key => $def) {
        $value = trim(str_replace("\r\n", "\n", (string)($_POST[$key] ?? '')));
        $form[$key] = $value;
        $len = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($len > $def[1]) $form_error = $def[0] . ' is too long (at most ' . $def[1] . ' characters).';
    }
    if ($form_error === '') {
        if ($form['company_name'] === '') $form_error = 'Please enter the company name.';
        elseif ($form['slip_title'] === '') $form_error = 'Please enter the consumption slip title.';
        elseif ($form['contact_note'] === '') $form_error = 'Please enter the contact note.';
        elseif ($form['company_email'] !== '' && !filter_var($form['company_email'], FILTER_VALIDATE_EMAIL)) $form_error = 'Please enter a valid email address.';
        elseif ($form['logo_url'] !== '' && !(
                    (stripos($form['logo_url'], 'https://') === 0 && filter_var($form['logo_url'], FILTER_VALIDATE_URL))
                    || preg_match('#^/[A-Za-z0-9/_\-. %]+$#', $form['logo_url']))) {
            $form_error = 'The logo address must start with https:// or be a path on this site starting with /.';
        }
    }

    if ($form_error === '') {
        try {
            $before = lumCompanySettings(true);
            $st = $db->prepare("INSERT INTO lum_company_settings (setting_key, setting_value, updated_by_name) VALUES (?, ?, ?)
                                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by_name = VALUES(updated_by_name)");
            foreach ($form as $key => $value) $st->execute([$key, $value, $user_name]);
            $after = lumCompanySettings(true);
            if ($before != $after && function_exists('lum_audit_log')) {
                $changed_before = array_diff_assoc($before, $after);
                $changed_after = array_intersect_key($after, $changed_before);
                lum_audit_log('UPDATE', 'company_settings', 1, 'Company details', null, $changed_before, $changed_after);
            }
            $_SESSION['company_message'] = ['type' => 'success', 'text' => 'The company details were saved. They are used on every slip and note from now on.'];
            header('Location: company-details.php');
            exit();
        } catch (\Throwable $e) {
            error_log('LUM company details save failed: ' . $e->getMessage());
            $form_error = 'The details could not be saved. Please try again or contact the system administrator.';
        }
    }
}

$current = lumCompanySettings(true);
$values = $form ?? $current;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Details - Lynx Utility Management</title>
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>
    <style>
        * { border-radius: 0 !important; }
        html { overflow-y: scroll; }
        body { background-color: #121212; color: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .action-bar { background-color: #0a0a0a; border-bottom: 1px solid #333; padding: 10px 0; }
        .panel { background: linear-gradient(135deg, #222, #2d2d2d); border: 1px solid #333; padding: 24px; }
        .form-control { background-color: #1a1a1a !important; border: 1px solid #444 !important; color: #fff !important; }
        .form-control:focus { border-color: #e3000f !important; box-shadow: 0 0 0 0.25rem rgba(227, 0, 15, 0.25) !important; }
        .form-control:disabled { color: #aaa !important; }
        .form-label { color: #ccc; font-size: 0.85rem; margin-bottom: 0.2rem; }
        .preview { background: #fff; color: #212529; border-top: 6px solid #000; padding: 24px; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .preview h2, .preview h4 { font-weight: normal; }
    </style>
</head>
<body>

<?php include LUM_ROOT . '/Layout/top-navbar.php'; ?>

<?php
$lum_sub_title = 'Company Details';
$lum_sub_back = 'view-configs.php';
include LUM_ROOT . '/Layout/sub-navbar.php';
?>
<div class="container-fluid px-4 py-4">
    <?php if ($db_error): ?><div class="alert alert-warning"><?php echo htmlspecialchars($db_error); ?></div><?php endif; ?>
    <?php if (!empty($message['text'])): ?><div class="alert alert-<?php echo htmlspecialchars($message['type']); ?>"><?php echo htmlspecialchars($message['text']); ?></div><?php endif; ?>
    <?php if ($form_error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($form_error); ?></div><?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-5">
            <div class="panel">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <?php $dis = ($can_edit && $db) ? '' : 'disabled'; ?>
                    <?php foreach ($fields as $key => $def): ?>
                        <div class="mb-3">
                            <label class="form-label" for="f_<?php echo $key; ?>"><?php echo htmlspecialchars($def[0]); ?></label>
                            <?php if ($key === 'contact_note' || $key === 'company_address'): ?>
                                <textarea class="form-control form-control-sm" id="f_<?php echo $key; ?>" name="<?php echo $key; ?>" rows="<?php echo $key === 'contact_note' ? 4 : 2; ?>" maxlength="<?php echo $def[1]; ?>" <?php echo $dis; ?>><?php echo htmlspecialchars((string)$values[$key]); ?></textarea>
                            <?php else: ?>
                                <input type="<?php echo $key === 'company_email' ? 'email' : 'text'; ?>" class="form-control form-control-sm" id="f_<?php echo $key; ?>" name="<?php echo $key; ?>" maxlength="<?php echo $def[1]; ?>"
                                       value="<?php echo htmlspecialchars((string)$values[$key]); ?>" <?php echo in_array($key, ['company_name', 'slip_title'], true) ? 'required' : ''; ?> <?php echo $dis; ?>>
                            <?php endif; ?>
                            <?php if ($def[2] !== ''): ?><div class="form-text text-secondary small"><?php echo htmlspecialchars($def[2]); ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($can_edit && $db): ?>
                        <div class="d-flex justify-content-end gap-2">
                            <a href="company-details.php" class="btn btn-outline-secondary btn-sm">Undo changes</a>
                            <button type="submit" class="btn btn-danger btn-sm px-3"><i class="bi bi-save me-1"></i>Save</button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="col-xl-7">
            <h6 class="text-secondary mb-2">Preview (saved details)</h6>
            <div class="preview mb-4">
                <?php echo lumCompanyHeaderHtml(null, $current); ?>
                <div class="text-muted small border p-3 mb-3">&hellip; consumption slip &hellip;</div>
                <p class="text-center text-muted small mb-0"><?php echo lumCompanyContactNoteHtml('your most recent consumption slip received', $current); ?></p>
            </div>
            <div class="preview">
                <?php echo lumCompanyHeaderHtml('Credit Note', $current); ?>
                <div class="text-muted small border p-3 mb-3">&hellip; credit note &hellip;</div>
                <p class="text-center text-muted small mb-0"><?php echo lumCompanyContactNoteHtml('this credit note', $current); ?></p>
            </div>
        </div>
    </div>
</div>

</body>
</html>