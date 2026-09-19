<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once '/var/www/Lynx/bootstrap.php';
lum_page('back_billing', 'view');

// =========================================================================
// LYNX UTILITY MANAGEMENT - BACK BILLING (STEP 1)
// Location: /var/www/Lynx/Reporting/back-billing.php
// List, create, edit, approve and void back billing / refund adjustments.
// =========================================================================

lum_connect('tenants');
lum_use('back_billing');

// The billing engine's database connections are only needed for a recalculation
$bb_needs_engine = (($_GET['action'] ?? '') === 'recalc')
    || (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (($_POST['action'] ?? '') === 'recalc_preview' || ($_POST['adjustment_type'] ?? '') === 'recalculated'));
if ($bb_needs_engine) {
    lum_connect('obis', 'manual', 'tariffs');
}
lum_use('audit');

$bdb = lumJournalDb();
$allowed_props = lum_allowed_properties(); // null = all
$can_edit = lum_can('back_billing', 'edit');
$can_void = lum_can('back_billing', 'delete');
$can_approve = lum_can('back_billing_approval', 'edit');
$message = $_SESSION['bb_message'] ?? null;
unset($_SESSION['bb_message']);
$form_error = '';

$flash = function ($type, $text) { $_SESSION['bb_message'] = ['type' => $type, 'text' => $text]; };
$money = function ($v) { return number_format((float)$v, 2); };
$signed = function ($v) {
    $v = (float)$v;
    return ($v < 0 ? '- R ' : 'R ') . number_format(abs($v), 2);
};
$kind = function ($total) {
    return ((float)$total < 0) ? ['Credit (refund)', 'success'] : ['Debit (back billing)', 'danger'];
};
$status_badge = function ($status) {
    $cls = ['Draft' => 'bg-secondary', 'Approved' => 'bg-primary', 'Posted' => 'bg-success', 'Voided' => 'bg-danger'][$status] ?? 'bg-secondary';
    return "<span class='badge {$cls}'>" . htmlspecialchars($status) . "</span>";
};

// ---------------- Helpers ----------------
function lumBbTenant($pdo, $tenant_id) {
    try {
        $st = $pdo->prepare("SELECT tenant_id, tenant_name, tenant_code, tenant_shop, tenant_property FROM lum_tenants WHERE tenant_id = ?");
        $st->execute([(int)$tenant_id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

// Full tenant row (for the recalculation)
function lumBbTenantFull($pdo, $tenant_id) {
    try {
        $st = $pdo->prepare("SELECT * FROM lum_tenants WHERE tenant_id = ?");
        $st->execute([(int)$tenant_id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

// Charge lines: journaled amount vs recalculated amount
function lumBbRecalcLines(array $line, array $R) {
    $lines = [];
    foreach (array_keys(LUM_ADJ_CHARGES) as $k) {
        $o = round((float)($line[$k] ?? 0), 2);
        $c = round((float)($R['totals'][$k] ?? 0), 2);
        if (abs($o) < 0.005 && abs($c) < 0.005) continue;
        $lines[$k] = ['original' => $o, 'corrected' => $c, 'difference' => round($c - $o, 2)];
    }
    return $lines;
}

// Generator source(s) of a billing summary
function lumBbGenMethod($billing) {
    $m = array_values(array_unique(array_filter(array_column((array)($billing['meters'] ?? []), 'gen_source'))));
    return $m ? implode(' / ', $m) : '-';
}

// What is stored with a recalculated adjustment
function lumBbRecalcSettings(array $line, array $R, array $RC) {
    return [
        'corrections' => $RC,
        'journaled' => [
            'elec_tariff' => $line['elec_tariff'], 'water_tariff' => $line['water_tariff'], 'sewer_tariff' => $line['sewer_tariff'],
            'elec_obis' => $line['elec_obis'], 'gen_method' => $line['gen_method'], 'period' => lumAdjLinePeriod($line),
            'kwh' => (float)$line['kwh'], 'kl' => (float)$line['water_kl'],
        ],
        'recalculated' => [
            'elec_tariff' => $R['tariffs']['elec'], 'water_tariff' => $R['tariffs']['water'], 'sewer_tariff' => $R['tariffs']['sewer'],
            'elec_obis' => $R['obis'], 'gen_method' => lumBbGenMethod($R['billing']), 'period' => $R['period'],
            'seasons' => $R['seasons'], 'kwh' => round((float)$R['totals']['kwh'], 3), 'kl' => round((float)$R['totals']['water_kl'], 3),
            'calculated_at' => date('Y-m-d H:i:s'),
        ],
    ];
}

// Audit snapshot of an adjustment (header and charge differences)
function lumBbSnapshot($adj) {
    if (!$adj) return null;
    $a = $adj['adjustment'];
    $snap = [
        'status' => $a['status'], 'reason' => $a['reason'], 'description' => $a['description'],
        'original_month' => sprintf('%02d/%04d', $a['original_month'], $a['original_year']),
        'original_journal_id' => $a['original_journal_id'],
        'posting_month' => sprintf('%02d/%04d', $a['posting_month'], $a['posting_year']),
        'posting_option' => $a['posting_option'], 'total' => $a['total'],
    ];
    foreach ($adj['lines'] as $k => $l) $snap['charge_' . $k] = $l['difference'];
    return $snap;
}

function lumBbAudit($action, $adj_after, $adj_before, $note = null) {
    if (!function_exists('lum_audit_log')) return;
    $ref = $adj_after ?: $adj_before;
    if (!$ref) return;
    $a = $ref['adjustment'];
    lum_audit_log($action, 'back_billing', $a['adjustment_id'],
        lumAdjNumber($a['adjustment_id']) . ' ' . $a['tenant_name'] . ($a['tenant_code'] ? ' (' . $a['tenant_code'] . ')' : ''),
        $a['property'], lumBbSnapshot($adj_before), lumBbSnapshot($adj_after), $note);
}

// Properties the user may use (from the tenant register)
$property_options = [];
try {
    // Properties come from the property register only (Configurations)
    $prop_register_db = lum_db('properties');
    if ($prop_register_db) {
        $property_options = $prop_register_db->query("SELECT Property FROM lum_properties ORDER BY Property")->fetchAll(PDO::FETCH_COLUMN);
    }
    if ($allowed_props !== null) $property_options = array_values(array_intersect($property_options, $allowed_props));
} catch (\Throwable $e) {}

// =========================================================================
// POST: SAVE (create / update draft)
// =========================================================================
$F = null; // Form state (also used to show the form again after a validation error)
$R = null;   // Recalculation result
$RC = null;  // Corrections used
$RL = null;  // Journal line being recalculated
$bb_post_action = (string)($_POST['action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($bb_post_action, ['save', 'recalc_preview'], true)) {
    lum_require_csrf();
    lum_require_access('back_billing', 'edit');

    $F = [
        'adjustment_id' => (int)($_POST['adjustment_id'] ?? 0),
        'property' => (string)($_POST['property'] ?? ''),
        'tenant_id' => (int)($_POST['tenant_id'] ?? 0),
        'line_id' => (int)($_POST['line_id'] ?? 0),
        'original_period' => (string)($_POST['original_period'] ?? ''),
        'period_start' => (string)($_POST['period_start'] ?? ''),
        'period_end' => (string)($_POST['period_end'] ?? ''),
        'reason' => (string)($_POST['reason'] ?? ''),
        'description' => trim((string)($_POST['description'] ?? '')),
        'posting_option' => (($_POST['posting_option'] ?? 'next') === 'same') ? 'same' : 'next',
        'kwh_original' => (string)($_POST['kwh_original'] ?? ''), 'kwh_corrected' => (string)($_POST['kwh_corrected'] ?? ''),
        'kl_original' => (string)($_POST['kl_original'] ?? ''), 'kl_corrected' => (string)($_POST['kl_corrected'] ?? ''),
        'reverses_adjustment_id' => (int)($_POST['reverses_adjustment_id'] ?? 0),
        'charge' => is_array($_POST['charge'] ?? null) ? $_POST['charge'] : [],
        'adjustment_type' => (($_POST['adjustment_type'] ?? '') === 'recalculated' || $bb_post_action === 'recalc_preview') ? 'recalculated' : 'manual',
        'corr' => is_array($_POST['corr'] ?? null) ? $_POST['corr'] : [],
    ];

    $existing = null;
    if ($F['adjustment_id'] > 0) {
        $existing = lumAdjGet($bdb, $F['adjustment_id']);
        if (!$existing || $existing['adjustment']['status'] !== 'Draft') {
            $flash('warning', 'Only draft adjustments can be changed.');
            header('Location: back-billing.php?id=' . $F['adjustment_id']);
            exit();
        }
        lum_require_property($existing['adjustment']['property']);
        // Property, tenant and original journal line stay as created
        $F['property'] = $existing['adjustment']['property'];
        $F['tenant_id'] = (int)$existing['adjustment']['tenant_id'];
        $F['line_id'] = (int)$existing['adjustment']['original_line_id'];
        $F['adjustment_type'] = ($existing['adjustment']['adjustment_type'] === 'recalculated') ? 'recalculated' : 'manual';
    }

    lum_require_property($F['property']);
    $tenant = lumBbTenant($tenant_db_conn, $F['tenant_id']);
    $line = $F['line_id'] > 0 ? lumAdjJournalLine($bdb, $F['line_id']) : null;
    $lines = lumAdjCleanLines($F['charge']);
    $valid_date = function ($d) { return $d === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); };

    // Recalculated adjustments: the amounts always come from the billing engine, never from the form
    $recalc_error = '';
    if ($F['adjustment_type'] === 'recalculated') {
        $lines = [];
        $RL = $line;
        if (!$line || !$tenant || (int)$line['tenant_id'] !== (int)$tenant['tenant_id']) {
            $recalc_error = 'A recalculated adjustment must be linked to a journaled month of this tenant.';
        } else {
            $RC = lumAdjCleanCorrections($F['corr'], lumAdjTariffOptions($tenant_db_conn));
            $recalc_error = lumAdjCorrectionsError($RC);
            if ($recalc_error === '') {
                try {
                    $R = lumAdjRecalculate($line, lumBbTenantFull($tenant_db_conn, $tenant['tenant_id']), $RC);
                    $lines = lumBbRecalcLines($line, $R);
                } catch (\Throwable $e) {
                    error_log('LUM back billing recalculation failed: ' . $e->getMessage());
                    $recalc_error = 'The recalculation failed. Please check the corrections or contact the system administrator.';
                    $R = null;
                }
            }
        }
    }

    if ($bb_post_action === 'recalc_preview') {
        // Calculate only: show the result, nothing is saved
        if (!$bdb) {
            $form_error = 'The journal database is not available. Please run the setup scripts.';
        } elseif (!$tenant || $tenant['tenant_property'] !== $F['property']) {
            $form_error = 'Please choose a tenant of the selected property.';
        } else {
            $form_error = $recalc_error;
        }
    } elseif (!$bdb) {
        $form_error = 'The journal database is not available. Please run the setup scripts.';
    } elseif (!$tenant || $tenant['tenant_property'] !== $F['property']) {
        $form_error = 'Please choose a tenant of the selected property.';
    } elseif ($F['line_id'] > 0 && (!$line || (int)$line['tenant_id'] !== $F['tenant_id'] || $line['property'] !== $F['property'])) {
        $form_error = 'The selected journal does not belong to this tenant.';
    } elseif ($recalc_error !== '') {
        $form_error = $recalc_error;
    } elseif (!$line && !preg_match('/^(\d{4})-(\d{2})$/', $F['original_period'])) {
        $form_error = 'Please choose the billing month being corrected.';
    } elseif (!$valid_date($F['period_start']) || !$valid_date($F['period_end'])) {
        $form_error = 'Please enter valid period dates.';
    } elseif (!isset(LUM_ADJ_REASONS[$F['reason']])) {
        $form_error = 'Please choose a reason.';
    } elseif ($F['description'] === '') {
        $form_error = 'Please describe what is being corrected.';
    } elseif ($F['posting_option'] === 'same' && !$can_approve) {
        $form_error = 'Only users who may approve adjustments can bill an adjustment in the original (journaled) month.';
    } elseif ($F['reverses_adjustment_id'] > 0 && (!($rev = lumAdjGet($bdb, $F['reverses_adjustment_id']))
              || $rev['adjustment']['status'] !== 'Posted' || (int)$rev['adjustment']['tenant_id'] !== $F['tenant_id']
              || $rev['adjustment']['property'] !== $F['property'])) {
        $form_error = 'Only a posted adjustment of this tenant can be reversed.';
    } elseif (empty($lines) || abs(lumAdjTotals($lines)['total']) < 0.005) {
        $form_error = 'Enter at least one charge that changes (the adjustment total cannot be R 0.00).';
    } else {
        if ($line) {
            $orig_month = (int)$line['billing_month'];
            $orig_year = (int)$line['billing_year'];
            $p_start = $F['period_start'] !== '' ? $F['period_start'] : ($line['period_start'] ?? $line['j_period_start']);
            $p_end = $F['period_end'] !== '' ? $F['period_end'] : ($line['period_end'] ?? (!empty($line['j_period_2_end']) ? $line['j_period_2_end'] : $line['j_period_end']));
        } else {
            list($oy, $om) = array_map('intval', explode('-', $F['original_period']));
            $orig_month = $om;
            $orig_year = $oy;
            $p_start = $F['period_start'];
            $p_end = $F['period_end'];
        }
        list($pm, $py) = lumAdjPostingMonth($bdb, $F['posting_option'], $F['property'], $orig_month, $orig_year);

        try {
            $id = lumAdjSave($bdb, [
                'property' => $F['property'], 'tenant_id' => $tenant['tenant_id'],
                'tenant_name' => $tenant['tenant_name'], 'tenant_code' => $tenant['tenant_code'], 'tenant_shop' => $tenant['tenant_shop'],
                'adjustment_type' => $F['adjustment_type'], 'reason' => $F['reason'], 'description' => $F['description'],
                'original_journal_id' => $line ? (int)$line['journal_id'] : null, 'original_line_id' => $line ? (int)$line['line_id'] : null,
                'original_month' => $orig_month, 'original_year' => $orig_year,
                'original_period_start' => $p_start ?: null, 'original_period_end' => $p_end ?: null,
                'posting_option' => $F['posting_option'], 'posting_month' => $pm, 'posting_year' => $py,
                'kwh_original' => $R ? $line['kwh'] : $F['kwh_original'],
                'kwh_corrected' => $R ? $R['totals']['kwh'] : $F['kwh_corrected'],
                'kl_original' => $R ? $line['water_kl'] : $F['kl_original'],
                'kl_corrected' => $R ? $R['totals']['water_kl'] : $F['kl_corrected'],
                'reverses_adjustment_id' => $F['reverses_adjustment_id'] ?: null,
                'recalc_settings' => $R ? lumBbRecalcSettings($line, $R, $RC) : null,
            ], $lines, $existing ? $F['adjustment_id'] : null);

            lumBbAudit($existing ? 'UPDATE' : 'INSERT', lumAdjGet($bdb, $id), $existing, $existing ? 'Draft adjustment edited' : 'Adjustment created');
            $flash('success', lumAdjNumber($id) . ($existing ? ' was saved.' : ' was created as a draft.'));
            header('Location: back-billing.php?id=' . $id);
            exit();
        } catch (\Throwable $e) {
            error_log('LUM back billing save failed: ' . $e->getMessage());
            $form_error = 'The adjustment could not be saved. Please try again or contact the system administrator.';
        }
    }
}

// =========================================================================
// POST: APPROVE / VOID
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['approve', 'void'], true)) {
    lum_require_csrf();
    $act = $_POST['action'];
    $aid = (int)($_POST['adjustment_id'] ?? 0);
    $before = lumAdjGet($bdb, $aid);
    if (!$before) {
        $flash('warning', 'Adjustment not found.');
        header('Location: back-billing.php');
        exit();
    }
    lum_require_property($before['adjustment']['property']);

    if ($act === 'approve') {
        lum_require_access('back_billing_approval', 'edit');
        list($ok, $msg) = lumAdjApprove($bdb, $aid);
        if ($ok) lumBbAudit('UPDATE', lumAdjGet($bdb, $aid), $before, 'Adjustment approved');
        $flash($ok ? 'success' : 'warning', lumAdjNumber($aid) . ': ' . $msg);
    } else {
        lum_require_access('back_billing', 'delete');
        $reason = trim((string)($_POST['void_reason'] ?? ''));
        if ($reason === '') {
            $flash('warning', 'Please give a reason for voiding the adjustment.');
        } elseif (lumAdjVoid($bdb, $aid, $reason)) {
            lumBbAudit('UPDATE', lumAdjGet($bdb, $aid), $before, 'Adjustment voided: ' . $reason);
            $flash('success', lumAdjNumber($aid) . ' was voided.');
        } else {
            $flash('warning', 'Only draft or approved adjustments can be voided. Posted adjustments are corrected with a reversing adjustment.');
        }
    }
    header('Location: back-billing.php?id=' . $aid);
    exit();
}

// =========================================================================
// WHICH VIEW
// =========================================================================
$action = (string)($_GET['action'] ?? '');
$view_id = (int)($_GET['id'] ?? 0);
$mode = 'list';
if ($F !== null) {
    $mode = ($F['adjustment_type'] === 'recalculated') ? 'recalc' : 'form';
} elseif ($action === 'recalc') {
    lum_require_access('back_billing', 'edit');
    $mode = 'recalc';
} elseif (in_array($action, ['new', 'edit', 'reverse'], true)) {
    lum_require_access('back_billing', 'edit');
    $mode = 'form';
} elseif ($view_id > 0) {
    $mode = 'detail';
}

// ---------------- Form state for new / edit / reverse ----------------
$form_existing = null;
if ($mode === 'form' && $F === null) {
    $F = [
        'adjustment_id' => 0, 'property' => (string)($_GET['property'] ?? ''), 'tenant_id' => (int)($_GET['tenant_id'] ?? 0),
        'line_id' => (int)($_GET['line_id'] ?? 0), 'original_period' => '', 'period_start' => '', 'period_end' => '',
        'reason' => '', 'description' => '', 'posting_option' => 'next',
        'kwh_original' => '', 'kwh_corrected' => '', 'kl_original' => '', 'kl_corrected' => '',
        'reverses_adjustment_id' => 0, 'charge' => [],
    ];

    if ($action === 'edit' || $action === 'reverse') {
        $form_existing = lumAdjGet($bdb, (int)($_GET['id'] ?? 0));
        if (!$form_existing) {
            $flash('warning', 'Adjustment not found.');
            header('Location: back-billing.php');
            exit();
        }
        $a = $form_existing['adjustment'];
        lum_require_property($a['property']);
        if ($action === 'edit' && $a['adjustment_type'] === 'recalculated') {
            header('Location: back-billing.php?action=recalc&id=' . (int)$a['adjustment_id']);
            exit();
        }
        if ($action === 'edit' && $a['status'] !== 'Draft') {
            $flash('warning', 'Only draft adjustments can be changed.');
            header('Location: back-billing.php?id=' . $a['adjustment_id']);
            exit();
        }
        if ($action === 'reverse' && $a['status'] !== 'Posted') {
            $flash('warning', 'Only posted adjustments are reversed. Draft and approved adjustments can simply be voided.');
            header('Location: back-billing.php?id=' . $a['adjustment_id']);
            exit();
        }
        $reverse = ($action === 'reverse');
        $F = array_merge($F, [
            'adjustment_id' => $reverse ? 0 : (int)$a['adjustment_id'],
            'property' => $a['property'], 'tenant_id' => (int)$a['tenant_id'], 'line_id' => (int)$a['original_line_id'],
            'original_period' => sprintf('%04d-%02d', $a['original_year'], $a['original_month']),
            'period_start' => (string)$a['original_period_start'], 'period_end' => (string)$a['original_period_end'],
            'reason' => $reverse ? 'other' : $a['reason'],
            'description' => $reverse ? 'Reversal of ' . lumAdjNumber($a['adjustment_id']) : (string)$a['description'],
            'posting_option' => $reverse ? 'next' : $a['posting_option'],
            'kwh_original' => (string)$a['kwh_original'], 'kwh_corrected' => (string)$a['kwh_corrected'],
            'kl_original' => (string)$a['kl_original'], 'kl_corrected' => (string)$a['kl_corrected'],
            'reverses_adjustment_id' => $reverse ? (int)$a['adjustment_id'] : (int)$a['reverses_adjustment_id'],
        ]);
        foreach ($form_existing['lines'] as $k => $l) {
            $F['charge'][$k] = $reverse
                ? ['original' => '', 'corrected' => '', 'difference' => number_format(-(float)$l['difference'], 2, '.', '')]
                : ['original' => $l['original_amount'], 'corrected' => $l['corrected_amount'], 'difference' => $l['difference']];
        }
    } elseif ($F['line_id'] > 0) {
        // Started from a journal line: fill in the journaled amounts
        $jl = lumAdjJournalLine($bdb, $F['line_id']);
        if ($jl) {
            lum_require_property($jl['property']);
            $F['property'] = $jl['property'];
            $F['tenant_id'] = (int)$jl['tenant_id'];
            foreach (array_keys(LUM_ADJ_CHARGES) as $k) {
                $v = number_format((float)($jl[$k] ?? 0), 2, '.', '');
                $F['charge'][$k] = ['original' => $v, 'corrected' => $v, 'difference' => '0.00'];
            }
            $F['kwh_original'] = $F['kwh_corrected'] = (string)$jl['kwh'];
            $F['kl_original'] = $F['kl_corrected'] = (string)$jl['water_kl'];
        } else {
            $F['line_id'] = 0;
        }
    }
    if ($F['property'] !== '' && !lum_can_access_property($F['property'])) $F['property'] = '';
}

// ---------------- Recalculation screen state (GET) ----------------
$bb_tariff_options = ['elec' => [], 'water' => [], 'sewer' => []];
if ($mode === 'recalc') {
    $bb_tariff_options = lumAdjTariffOptions($tenant_db_conn);
    if ($F === null) {
        $F = [
            'adjustment_id' => 0, 'property' => '', 'tenant_id' => 0, 'line_id' => (int)($_GET['line_id'] ?? 0),
            'original_period' => '', 'period_start' => '', 'period_end' => '', 'reason' => '', 'description' => '',
            'posting_option' => 'next', 'reverses_adjustment_id' => 0, 'charge' => [], 'adjustment_type' => 'recalculated', 'corr' => [],
            'kwh_original' => '', 'kwh_corrected' => '', 'kl_original' => '', 'kl_corrected' => '',
        ];
        if (!empty($_GET['id'])) {
            // Edit a recalculated draft
            $ex = lumAdjGet($bdb, (int)$_GET['id']);
            if (!$ex || $ex['adjustment']['status'] !== 'Draft' || $ex['adjustment']['adjustment_type'] !== 'recalculated') {
                $flash('warning', 'Only draft recalculated adjustments can be changed here.');
                header('Location: back-billing.php' . ($ex ? '?id=' . (int)$ex['adjustment']['adjustment_id'] : ''));
                exit();
            }
            $xa = $ex['adjustment'];
            lum_require_property($xa['property']);
            $xs = json_decode((string)$xa['recalc_settings'], true) ?: [];
            $F = array_merge($F, [
                'adjustment_id' => (int)$xa['adjustment_id'], 'property' => $xa['property'], 'tenant_id' => (int)$xa['tenant_id'],
                'line_id' => (int)$xa['original_line_id'], 'reason' => $xa['reason'], 'description' => (string)$xa['description'],
                'posting_option' => $xa['posting_option'], 'corr' => (array)($xs['corrections'] ?? []),
            ]);
        }
    }
    $RL = $RL ?? ($F['line_id'] > 0 ? lumAdjJournalLine($bdb, $F['line_id']) : null);
    if (!$RL) {
        $flash('warning', 'Choose a journaled month to recalculate (use the back billing button on a tenant line in the Journal History).');
        header('Location: back-billing.php');
        exit();
    }
    lum_require_property($RL['property']);
    $F['property'] = $RL['property'];
    $F['tenant_id'] = (int)$RL['tenant_id'];
    if ($RC === null) {
        // Start from the period the tenant was billed on
        $RC = array_merge(['elec_tariff' => '', 'water_tariff' => '', 'sewer_tariff' => '', 'billing_obis' => '', 't2_method' => '',
                           'estimated' => '', 'kwh' => '', 'kl' => ''], lumAdjLinePeriod($RL));
        if (!empty($F['corr'])) $RC = lumAdjCleanCorrections($F['corr'], $bb_tariff_options);
    }
    $bb_tenant_now = lumBbTenantFull($tenant_db_conn, $F['tenant_id']);
    $preview_next = lumAdjNextOpenMonth($bdb, $F['property'], $RL['billing_month'], $RL['billing_year']);
    $preview_same = [(int)$RL['billing_month'], (int)$RL['billing_year']];
}

// Data for the form
$form_tenants = [];
$form_journals = [];
$form_line = null;
if ($mode === 'form') {
    if ($F['property'] !== '') {
        try {
            $st = $tenant_db_conn->prepare("SELECT tenant_id, tenant_name, tenant_code, tenant_shop FROM lum_tenants WHERE tenant_property = ? ORDER BY tenant_shop, tenant_name");
            $st->execute([$F['property']]);
            $form_tenants = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {}
    }
    if ($F['property'] !== '' && $F['tenant_id'] > 0) {
        $form_journals = lumAdjTenantJournals($bdb, $F['property'], $F['tenant_id']);
    }
    if ($F['line_id'] > 0) {
        $form_line = lumAdjJournalLine($bdb, $F['line_id']);
    }
    // Where the adjustment would be billed (preview)
    $preview_month = null;
    if ($form_line) {
        $preview_month = lumAdjPostingMonth($bdb, $F['posting_option'], $F['property'], $form_line['billing_month'], $form_line['billing_year']);
        $preview_next = lumAdjNextOpenMonth($bdb, $F['property'], $form_line['billing_month'], $form_line['billing_year']);
        $preview_same = [(int)$form_line['billing_month'], (int)$form_line['billing_year']];
    }
}

// Data for the detail view
$view = null;
$view_reversed_by = [];
if ($mode === 'detail') {
    $view = lumAdjGet($bdb, $view_id);
    if ($view) {
        lum_require_property($view['adjustment']['property']);
        try {
            $st = $bdb->prepare("SELECT adjustment_id, status FROM lum_adjustments WHERE reverses_adjustment_id = ?");
            $st->execute([$view_id]);
            $view_reversed_by = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {}
    }
}

// Data for the list
$f_property = (string)($_GET['property'] ?? '');
$f_status = (string)($_GET['status'] ?? '');
$f_posting = (string)($_GET['posting'] ?? '');
$f_search = trim((string)($_GET['search'] ?? ''));
if ($f_property !== '' && !lum_can_access_property($f_property)) $f_property = '';
if (!in_array($f_status, array_merge([''], LUM_ADJ_STATUSES), true)) $f_status = '';
if ($f_posting !== '' && !preg_match('/^\d{4}-\d{2}$/', $f_posting)) $f_posting = '';
$list = ($mode === 'list') ? lumAdjList($bdb, [
    'property' => $f_property, 'status' => $f_status, 'posting' => $f_posting, 'search' => $f_search,
    'allowed_properties' => $allowed_props,
]) : [];

$report_back_url = 'financial-reporting-overview.php' . (!empty($_SESSION['lum_last_financial_report']) ? '?' . $_SESSION['lum_last_financial_report'] : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Back Billing - Lynx Utility Management</title>
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>
    <style>
        * { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background-color: #e2e8f0; }
        .navbar { background-color: #000000; border-bottom: 1px solid #333333; }
        .action-bar { background-color: #0a0a0a; border-bottom: 1px solid #333; padding: 12px 0; }
        .bb-card { background: #fff; border: 1px solid #d0d7de; }
        .table-sm th, .table-sm td { padding: 0.35rem 0.5rem; vertical-align: middle; }
        .table thead th { white-space: nowrap; }
        .amt-credit { color: #198754; font-weight: 600; }
        .amt-debit { color: #dc3545; font-weight: 600; }
        .charge-input { max-width: 140px; text-align: right; }
        @media print {
            .d-print-none, .navbar, .action-bar { display: none !important; }
        body { background: #fff; }
        .bb-card { border: none; }
        }
    </style>
</head>
<body>

<?php include LUM_ROOT . '/Layout/top-navbar.php'; ?>

<div class="action-bar d-print-none">
    <div class="container-fluid px-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="mb-0 text-white fw-normal"><i class="bi bi-arrow-left-right me-2"></i>Back Billing &amp; Refunds</h5>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($mode !== 'list'): ?>
                <a href="back-billing.php" class="btn btn-brand btn-sm px-2 shadow-sm"><i class="bi bi-list-ul me-1"></i>All Adjustments</a>
            <?php endif; ?>
            <?php if ($can_edit && $mode === 'list'): ?>
                <a href="back-billing.php?action=new<?php echo $f_property !== '' ? '&amp;property=' . urlencode($f_property) : ''; ?>" class="btn btn-danger btn-sm"><i class="bi bi-plus-lg me-1"></i>New Adjustment</a>
            <?php endif; ?>
            <a href="financial-journal.php" class="btn btn-brand btn-sm px-2 shadow-sm"><i class="bi bi-journal-bookmark me-1"></i>Journal History</a>
            <a href="<?php echo htmlspecialchars($report_back_url); ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Financial Report</a>
        </div>
    </div>
</div>

<div class="container-fluid px-4 py-4">

    <?php if (!$bdb): ?>
        <div class="alert alert-danger">The journal database is not available. Please run <strong>financial-journal-setup.sql</strong> and <strong>back-billing-setup.sql</strong>.</div>
    <?php endif; ?>
    <?php if (!empty($message['text'])): ?>
        <div class="alert alert-<?php echo htmlspecialchars($message['type'] ?? 'info'); ?>"><?php echo htmlspecialchars($message['text']); ?></div>
    <?php endif; ?>

<?php if ($mode === 'form'): ?>
    <!-- =============================== FORM =============================== -->
    <?php if ($form_error !== ''): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($form_error); ?></div>
    <?php endif; ?>

    <div class="bb-card p-4 mb-4">
        <h4 class="fw-bold mb-1">
            <?php echo $F['adjustment_id'] ? 'Edit ' . lumAdjNumber($F['adjustment_id']) : (!empty($F['reverses_adjustment_id']) ? 'Reverse ' . lumAdjNumber($F['reverses_adjustment_id']) : 'New Adjustment'); ?>
        </h4>

        <?php if (!$F['adjustment_id'] && empty($F['reverses_adjustment_id'])): ?>
        <!-- 1. Property, tenant and the journaled month being corrected (reloads the form) -->
        <form method="GET" class="row g-3 mb-4 border-bottom pb-4">
            <input type="hidden" name="action" value="new">
            <div class="col-md-4">
                <label class="form-label small fw-semibold">1. Property</label>
                <select name="property" class="form-select" onchange="this.form.tenant_id.value=''; this.form.line_id.value=''; this.form.submit();">
                    <option value="">Choose a property...</option>
                    <?php foreach ($property_options as $p): ?>
                        <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $F['property'] === $p ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold">2. Tenant</label>
                <select name="tenant_id" class="form-select" onchange="this.form.line_id.value=''; this.form.submit();" <?php echo $F['property'] === '' ? 'disabled' : ''; ?>>
                    <option value="">Choose a tenant...</option>
                    <?php foreach ($form_tenants as $t): ?>
                        <option value="<?php echo (int)$t['tenant_id']; ?>" <?php echo (int)$F['tenant_id'] === (int)$t['tenant_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(trim(($t['tenant_shop'] ? $t['tenant_shop'] . ' - ' : '') . $t['tenant_name'] . ($t['tenant_code'] ? ' (' . $t['tenant_code'] . ')' : ''))); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold">3. Journaled month being corrected</label>
                <select name="line_id" class="form-select" onchange="this.form.submit();" <?php echo $F['tenant_id'] ? '' : 'disabled'; ?>>
                    <option value="">Not journaled / enter manually</option>
                    <?php foreach ($form_journals as $jj): ?>
                        <option value="<?php echo (int)$jj['line_id']; ?>" <?php echo (int)$F['line_id'] === (int)$jj['line_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(lumAdjMonthLabel($jj['billing_month'], $jj['billing_year']) . ' - Journal #' . $jj['journal_id'] . ($jj['status'] !== 'Active' ? ' (' . $jj['status'] . ')' : '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($F['line_id'] > 0): ?>
                <div class="form-text">
                    <a href="back-billing.php?action=recalc&amp;line_id=<?php echo (int)$F['line_id']; ?>"><i class="bi bi-calculator me-1"></i>Recalculate this month with corrections instead</a>
                </div>
                <?php endif; ?>
            </div>
        </form>
        <?php endif; ?>

        <?php if ($F['property'] !== '' && $F['tenant_id'] > 0): ?>
        <!-- 2. The adjustment itself -->
        <form method="POST" action="back-billing.php" id="adjForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="adjustment_id" value="<?php echo (int)$F['adjustment_id']; ?>">
            <input type="hidden" name="property" value="<?php echo htmlspecialchars($F['property']); ?>">
            <input type="hidden" name="tenant_id" value="<?php echo (int)$F['tenant_id']; ?>">
            <input type="hidden" name="line_id" value="<?php echo (int)$F['line_id']; ?>">
            <input type="hidden" name="reverses_adjustment_id" value="<?php echo (int)$F['reverses_adjustment_id']; ?>">

            <?php if ($F['adjustment_id'] || !empty($F['reverses_adjustment_id'])): ?>
                <div class="alert alert-light border small">
                    <strong><?php echo htmlspecialchars($F['property']); ?></strong> &middot;
                    <?php
                    $ft = lumBbTenant($tenant_db_conn, $F['tenant_id']);
                    echo htmlspecialchars($ft ? $ft['tenant_name'] . ($ft['tenant_code'] ? ' (' . $ft['tenant_code'] . ')' : '') : 'Tenant #' . $F['tenant_id']);
                    ?>
                    <?php if ($form_line): ?> &middot; Journal #<?php echo (int)$form_line['journal_id']; ?> (<?php echo htmlspecialchars(lumAdjMonthLabel($form_line['billing_month'], $form_line['billing_year'])); ?>)<?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="row g-3 mb-4">
                <?php if (!$form_line): ?>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Billing month being corrected</label>
                    <input type="month" name="original_period" class="form-control" value="<?php echo htmlspecialchars($F['original_period']); ?>" required>
                </div>
                <?php endif; ?>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Period start (optional)</label>
                    <input type="date" name="period_start" class="form-control" value="<?php echo htmlspecialchars($F['period_start'] !== '' ? $F['period_start'] : (string)($form_line['period_start'] ?? ($form_line['j_period_start'] ?? ''))); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Period end (optional)</label>
                    <input type="date" name="period_end" class="form-control" value="<?php echo htmlspecialchars($F['period_end'] !== '' ? $F['period_end'] : (string)($form_line['period_end'] ?? ($form_line ? (!empty($form_line['j_period_2_end']) ? $form_line['j_period_2_end'] : $form_line['j_period_end']) : ''))); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Reason</label>
                    <select name="reason" class="form-select" required>
                        <option value="">Choose...</option>
                        <?php foreach (LUM_ADJ_REASONS as $rk => $rl): ?>
                            <option value="<?php echo $rk; ?>" <?php echo $F['reason'] === $rk ? 'selected' : ''; ?>><?php echo htmlspecialchars($rl); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold">Description</label>
                    <textarea name="description" class="form-control" rows="2" maxlength="500" required placeholder="What went wrong and how it was corrected (shown on the adjustment)"><?php echo htmlspecialchars($F['description']); ?></textarea>
                </div>
            </div>

            <!-- Where it is billed -->
            <div class="border rounded p-3 mb-4 bg-light">
                <div class="fw-semibold mb-2">Bill this adjustment in</div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="posting_option" id="po_next" value="next" <?php echo $F['posting_option'] !== 'same' ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="po_next">
                        The next open billing month
                        <?php if (!empty($preview_next)): ?><strong>(<?php echo htmlspecialchars(lumAdjMonthLabel($preview_next[0], $preview_next[1])); ?>)</strong><?php endif; ?>
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="posting_option" id="po_same" value="same" <?php echo $F['posting_option'] === 'same' ? 'checked' : ''; ?> <?php echo $can_approve ? '' : 'disabled'; ?>>
                    <label class="form-check-label" for="po_same">
                        The original billing month
                        <?php if (!empty($preview_same)): ?><strong>(<?php echo htmlspecialchars(lumAdjMonthLabel($preview_same[0], $preview_same[1])); ?>)</strong><?php endif; ?>
                        <?php if (!$can_approve): ?><span class="text-danger small">(only users who may approve adjustments)</span><?php endif; ?>
                    </label>
                </div>
            </div>

            <!-- Charges -->
            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered align-middle mb-0" id="chargeTable">
                    <thead class="table-dark">
                        <tr>
                            <th>Charge</th>
                            <th class="text-end">Billed (R)</th>
                            <th class="text-end">Should have been (R)</th>
                            <th class="text-end">Difference (R)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $prev_group = null; foreach (LUM_ADJ_CHARGES as $ck => $cdef):
                        $row = $F['charge'][$ck] ?? [];
                        if ($cdef[1] !== $prev_group): $prev_group = $cdef[1]; ?>
                        <tr class="table-light"><td colspan="4" class="small fw-bold text-uppercase"><?php echo ['elec' => 'Electricity', 'water' => 'Water & Sewer', 'refuse' => 'Refuse'][$cdef[1]]; ?></td></tr>
                    <?php endif; ?>
                        <tr data-group="<?php echo $cdef[1]; ?>">
                            <td><?php echo htmlspecialchars($cdef[0]); ?></td>
                            <td><input type="number" step="0.01" class="form-control form-control-sm charge-input ms-auto js-orig" name="charge[<?php echo $ck; ?>][original]" value="<?php echo htmlspecialchars((string)($row['original'] ?? '')); ?>"></td>
                            <td><input type="number" step="0.01" class="form-control form-control-sm charge-input ms-auto js-corr" name="charge[<?php echo $ck; ?>][corrected]" value="<?php echo htmlspecialchars((string)($row['corrected'] ?? '')); ?>"></td>
                            <td><input type="number" step="0.01" class="form-control form-control-sm charge-input ms-auto js-diff" name="charge[<?php echo $ck; ?>][difference]" value="<?php echo htmlspecialchars((string)($row['difference'] ?? '')); ?>" title="Worked out from the two amounts; type it in only when you do not have them"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><th colspan="3" class="text-end">Electricity</th><th class="text-end" id="sumElec">0.00</th></tr>
                        <tr><th colspan="3" class="text-end">Water &amp; Sewer</th><th class="text-end" id="sumWater">0.00</th></tr>
                        <tr><th colspan="3" class="text-end">Refuse</th><th class="text-end" id="sumRefuse">0.00</th></tr>
                        <tr class="table-dark"><th colspan="3" class="text-end">Adjustment Total</th><th class="text-end" id="sumTotal">0.00</th></tr>
                    </tfoot>
                </table>
            </div>

            <!-- Units (for the record) -->
            <div class="row g-3 mb-4">
                <div class="col-md-3"><label class="form-label small">kWh billed</label><input type="number" step="0.001" name="kwh_original" class="form-control form-control-sm" value="<?php echo htmlspecialchars($F['kwh_original']); ?>"></div>
                <div class="col-md-3"><label class="form-label small">kWh that should have been billed</label><input type="number" step="0.001" name="kwh_corrected" class="form-control form-control-sm" value="<?php echo htmlspecialchars($F['kwh_corrected']); ?>"></div>
                <div class="col-md-3"><label class="form-label small">kL billed</label><input type="number" step="0.001" name="kl_original" class="form-control form-control-sm" value="<?php echo htmlspecialchars($F['kl_original']); ?>"></div>
                <div class="col-md-3"><label class="form-label small">kL that should have been billed</label><input type="number" step="0.001" name="kl_corrected" class="form-control form-control-sm" value="<?php echo htmlspecialchars($F['kl_corrected']); ?>"></div>
            </div>

            <div class="d-flex justify-content-end gap-2">
                <a href="<?php echo $F['adjustment_id'] ? 'back-billing.php?id=' . (int)$F['adjustment_id'] : 'back-billing.php'; ?>" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-dark"><i class="bi bi-save me-1"></i>Save as Draft</button>
            </div>
        </form>
        <?php elseif ($F['property'] !== ''): ?>
            <div class="text-muted">Choose the tenant to continue.</div>
        <?php else: ?>
            <div class="text-muted">Choose the property to continue.</div>
        <?php endif; ?>
    </div>

    <script>
        // Difference = should have been - billed; totals per group
        (function () {
            const table = document.getElementById('chargeTable');
            if (!table) return;
            const num = v => { const n = parseFloat(v); return isNaN(n) ? null : n; };
            const fmt = v => (v < 0 ? '- ' : '') + Math.abs(v).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            function recalc(row) {
                const o = num(row.querySelector('.js-orig').value);
                const c = num(row.querySelector('.js-corr').value);
                if (o !== null && c !== null) row.querySelector('.js-diff').value = (c - o).toFixed(2);
            }
            function totals() {
                const sums = { elec: 0, water: 0, refuse: 0 };
                table.querySelectorAll('tbody tr[data-group]').forEach(r => {
                    const d = num(r.querySelector('.js-diff').value);
                    if (d !== null) sums[r.dataset.group] += d;
                });
                const total = sums.elec + sums.water + sums.refuse;
                document.getElementById('sumElec').textContent = fmt(sums.elec);
                document.getElementById('sumWater').textContent = fmt(sums.water);
                document.getElementById('sumRefuse').textContent = fmt(sums.refuse);
                const t = document.getElementById('sumTotal');
                t.textContent = fmt(total) + (Math.abs(total) < 0.005 ? '' : (total < 0 ? '  (refund to tenant)' : '  (tenant pays)'));
            }
            table.querySelectorAll('tbody tr[data-group]').forEach(r => {
                r.querySelectorAll('.js-orig, .js-corr').forEach(i => i.addEventListener('input', () => { recalc(r); totals(); }));
                r.querySelector('.js-diff').addEventListener('input', totals);
                recalc(r);
            });
            totals();
        })();
    </script>

<?php elseif ($mode === 'recalc'):
    $jp = lumAdjLinePeriod($RL);
    $fmt_period = function ($p, $a, $b, $a2, $b2) {
        if (($p[$a] ?? '') === '') return '-';
        $t = date('d M Y', strtotime($p[$a])) . ' - ' . date('d M Y', strtotime($p[$b]));
        if (($p[$a2] ?? '') !== '') $t .= ' & ' . date('d M Y', strtotime($p[$a2])) . ' - ' . date('d M Y', strtotime($p[$b2]));
        return $t;
    };
    $opt_keep = function ($label) { return '<option value="">' . htmlspecialchars($label) . '</option>'; };
?>
    <!-- =============================== RECALCULATE =============================== -->
    <?php if ($form_error !== ''): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($form_error); ?></div>
    <?php endif; ?>

    <div class="bb-card p-4 mb-4">
        <h4 class="fw-bold mb-1">
            <?php echo $F['adjustment_id'] ? 'Edit ' . lumAdjNumber($F['adjustment_id']) . ' - ' : ''; ?>Recalculate
            <?php echo htmlspecialchars((string)$RL['tenant_name']); ?><?php echo $RL['tenant_code'] ? ' (' . htmlspecialchars($RL['tenant_code']) . ')' : ''; ?>
            &mdash; <?php echo htmlspecialchars(lumAdjMonthLabel($RL['billing_month'], $RL['billing_year'])); ?>
        </h4>
        <p class="text-muted small mb-3">
            The tenant's bill for this month is calculated again with the billing engine, using the report settings and the tenant's own settings
            stored in <a href="financial-journal.php?id=<?php echo (int)$RL['journal_id']; ?>">Journal #<?php echo (int)$RL['journal_id']; ?></a>, plus the corrections below.
            Readings imported after the journal are included, so a month with missing readings can be recalculated without other corrections.
            <?php if (!$F['adjustment_id']): ?>
                <a href="back-billing.php?action=new&amp;line_id=<?php echo (int)$RL['line_id']; ?>">Enter the amounts manually instead</a>.
            <?php endif; ?>
        </p>

        <div class="row g-2 small mb-4">
            <div class="col-md-3"><div class="border rounded p-2 h-100"><div class="text-muted">Journaled electrical tariff</div><?php echo htmlspecialchars((string)$RL['elec_tariff'] ?: '-'); ?>
                <?php if ($bb_tenant_now && (string)$bb_tenant_now['tenant_electrical_tariff_charge'] !== ''): ?><div class="text-muted">Tenant's tariff now: <?php echo htmlspecialchars($bb_tenant_now['tenant_electrical_tariff_charge']); ?></div><?php endif; ?>
            </div></div>
            <div class="col-md-3"><div class="border rounded p-2 h-100"><div class="text-muted">Journaled water / sewer tariff</div><?php echo htmlspecialchars(((string)$RL['water_tariff'] ?: '-') . ' / ' . ((string)$RL['sewer_tariff'] ?: '-')); ?></div></div>
            <div class="col-md-3"><div class="border rounded p-2 h-100"><div class="text-muted">Journaled OBIS &middot; generator</div><?php echo htmlspecialchars(((string)$RL['elec_obis'] ?: '-') . ' · ' . ((string)$RL['gen_method'] ?: '-')); ?></div></div>
            <div class="col-md-3"><div class="border rounded p-2 h-100"><div class="text-muted">Journaled units &middot; grand total</div><?php echo number_format((float)$RL['kwh'], 2); ?> kWh &middot; <?php echo number_format((float)$RL['water_kl'], 2); ?> kL &middot; <strong>R <?php echo $money($RL['total']); ?></strong></div></div>
        </div>

        <form method="POST" action="back-billing.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="adjustment_id" value="<?php echo (int)$F['adjustment_id']; ?>">
            <input type="hidden" name="property" value="<?php echo htmlspecialchars($F['property']); ?>">
            <input type="hidden" name="tenant_id" value="<?php echo (int)$F['tenant_id']; ?>">
            <input type="hidden" name="line_id" value="<?php echo (int)$RL['line_id']; ?>">
            <input type="hidden" name="adjustment_type" value="recalculated">

            <h6 class="fw-bold border-bottom pb-1">Corrections</h6>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Electrical tariff</label>
                    <select name="corr[elec_tariff]" class="form-select form-select-sm">
                        <?php echo $opt_keep("Keep the tenant's tariff"); ?>
                        <?php foreach ($bb_tariff_options['elec'] as $o): ?><option value="<?php echo htmlspecialchars($o); ?>" <?php echo $RC['elec_tariff'] === $o ? 'selected' : ''; ?>><?php echo htmlspecialchars($o); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Water tariff</label>
                    <select name="corr[water_tariff]" class="form-select form-select-sm">
                        <?php echo $opt_keep("Keep the tenant's tariff"); ?>
                        <?php foreach ($bb_tariff_options['water'] as $o): ?><option value="<?php echo htmlspecialchars($o); ?>" <?php echo $RC['water_tariff'] === $o ? 'selected' : ''; ?>><?php echo htmlspecialchars($o); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Sewer tariff</label>
                    <select name="corr[sewer_tariff]" class="form-select form-select-sm">
                        <?php echo $opt_keep("Keep the tenant's tariff"); ?>
                        <?php foreach ($bb_tariff_options['sewer'] as $o): ?><option value="<?php echo htmlspecialchars($o); ?>" <?php echo $RC['sewer_tariff'] === $o ? 'selected' : ''; ?>><?php echo htmlspecialchars($o); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Billing OBIS</label>
                    <select name="corr[billing_obis]" class="form-select form-select-sm">
                        <?php echo $opt_keep('Keep as journaled (' . ((string)$RL['elec_obis'] ?: 'report default') . ')'); ?>
                        <?php foreach (['1.1.1.8.0', '1.1.1.8.1'] as $o): ?><option value="<?php echo $o; ?>" <?php echo $RC['billing_obis'] === $o ? 'selected' : ''; ?>><?php echo $o; ?></option><?php endforeach; ?>
                    </select>
                    <?php if (!empty($RL['is_tou'])): ?><div class="form-text">Time Of Use tenants are always billed on 1.1.1.8.0.</div><?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Generator method</label>
                    <select name="corr[t2_method]" class="form-select form-select-sm">
                        <?php echo $opt_keep('Keep as journaled'); ?>
                        <option value="1.1.1.8.2" <?php echo $RC['t2_method'] === '1.1.1.8.2' ? 'selected' : ''; ?>>OBIS 1.1.1.8.2 Reading</option>
                        <option value="runtime" <?php echo $RC['t2_method'] === 'runtime' ? 'selected' : ''; ?>>Run Time Calculation</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Estimated readings</label>
                    <select name="corr[estimated]" class="form-select form-select-sm">
                        <?php echo $opt_keep('Keep as journaled (' . (!empty($RL['estimated_readings']) ? 'on' : 'off') . ')'); ?>
                        <option value="on" <?php echo $RC['estimated'] === 'on' ? 'selected' : ''; ?>>On</option>
                        <option value="off" <?php echo $RC['estimated'] === 'off' ? 'selected' : ''; ?>>Off</option>
                    </select>
                </div>

                <div class="col-md-3"><label class="form-label small fw-semibold">Period start</label><input type="date" name="corr[start_date]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($RC['start_date']); ?>" required></div>
                <div class="col-md-3"><label class="form-label small fw-semibold">Period end</label><input type="date" name="corr[end_date]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($RC['end_date']); ?>" required></div>
                <div class="col-md-3"><label class="form-label small">Period 2 start (optional)</label><input type="date" name="corr[start_date_2]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($RC['start_date_2']); ?>"></div>
                <div class="col-md-3"><label class="form-label small">Period 2 end (optional)</label><input type="date" name="corr[end_date_2]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($RC['end_date_2']); ?>"></div>
                <div class="col-md-3"><label class="form-label small">Water period start</label><input type="date" name="corr[water_start_date]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($RC['water_start_date']); ?>"></div>
                <div class="col-md-3"><label class="form-label small">Water period end</label><input type="date" name="corr[water_end_date]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($RC['water_end_date']); ?>"></div>
                <div class="col-md-3"><label class="form-label small">Water period 2 start</label><input type="date" name="corr[water_start_date_2]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($RC['water_start_date_2']); ?>"></div>
                <div class="col-md-3"><label class="form-label small">Water period 2 end</label><input type="date" name="corr[water_end_date_2]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($RC['water_end_date_2']); ?>"></div>

                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Corrected kWh</label>
                    <input type="number" step="0.001" min="0" name="corr[kwh]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($RC['kwh']); ?>" placeholder="Blank = meter readings">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Corrected kL</label>
                    <input type="number" step="0.001" min="0" name="corr[kl]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($RC['kl']); ?>" placeholder="Blank = meter readings">
                </div>
                <div class="col-md-6 small text-muted d-flex align-items-end">
                    Corrected units replace the metered units for the whole period (split over the time-of-use bands and periods in the metered proportions; without readings the units are billed in the standard band).
                </div>
            </div>

            <h6 class="fw-bold border-bottom pb-1">Adjustment</h6>
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Reason</label>
                    <select name="reason" class="form-select form-select-sm" required>
                        <option value="">Choose...</option>
                        <?php foreach (LUM_ADJ_REASONS as $rk => $rlab): ?><option value="<?php echo $rk; ?>" <?php echo $F['reason'] === $rk ? 'selected' : ''; ?>><?php echo htmlspecialchars($rlab); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-9">
                    <label class="form-label small fw-semibold">Description</label>
                    <input type="text" name="description" class="form-control form-control-sm" maxlength="500" required value="<?php echo htmlspecialchars($F['description']); ?>" placeholder="What went wrong and how it was corrected">
                </div>
                <div class="col-12">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="posting_option" id="rp_next" value="next" <?php echo $F['posting_option'] !== 'same' ? 'checked' : ''; ?>>
                        <label class="form-check-label small" for="rp_next">Bill in the next open month (<strong><?php echo htmlspecialchars(lumAdjMonthLabel($preview_next[0], $preview_next[1])); ?></strong>)</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="posting_option" id="rp_same" value="same" <?php echo $F['posting_option'] === 'same' ? 'checked' : ''; ?> <?php echo $can_approve ? '' : 'disabled'; ?>>
                        <label class="form-check-label small" for="rp_same">Bill in the original month (<strong><?php echo htmlspecialchars(lumAdjMonthLabel($preview_same[0], $preview_same[1])); ?></strong>, journal again)<?php echo $can_approve ? '' : ' <span class="text-danger">(approvers only)</span>'; ?></label>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mb-2">
                <a href="<?php echo $F['adjustment_id'] ? 'back-billing.php?id=' . (int)$F['adjustment_id'] : 'financial-journal.php?id=' . (int)$RL['journal_id']; ?>" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" name="action" value="recalc_preview" class="btn btn-outline-primary" formnovalidate><i class="bi bi-calculator me-1"></i>Calculate</button>
                <button type="submit" name="action" value="save" class="btn btn-dark"><i class="bi bi-save me-1"></i>Save as Draft</button>
            </div>
            <div class="small text-muted text-end">Saving always recalculates, so the saved amounts match the corrections.</div>
        </form>

        <?php if ($R):
            $cmp_lines = lumBbRecalcLines($RL, $R);
            $cmp_tot = lumAdjTotals($cmp_lines);
            $rp = $R['period'];
            $facts = [
                ['Electrical tariff', (string)$RL['elec_tariff'], (string)$R['tariffs']['elec']],
                ['Water tariff', (string)$RL['water_tariff'], (string)$R['tariffs']['water']],
                ['Sewer tariff', (string)$RL['sewer_tariff'], (string)$R['tariffs']['sewer']],
                ['Billing OBIS', (string)$RL['elec_obis'], (string)$R['obis']],
                ['Generator', (string)$RL['gen_method'], lumBbGenMethod($R['billing'])],
                ['Billing period', $fmt_period($jp, 'start_date', 'end_date', 'start_date_2', 'end_date_2'), $fmt_period($rp, 'start_date', 'end_date', 'start_date_2', 'end_date_2')],
                ['Water period', $fmt_period($jp, 'water_start_date', 'water_end_date', 'water_start_date_2', 'water_end_date_2'), $fmt_period($rp, 'water_start_date', 'water_end_date', 'water_start_date_2', 'water_end_date_2')],
                ['kWh', number_format((float)$RL['kwh'], 2), number_format((float)$R['totals']['kwh'], 2)],
                ['kL', number_format((float)$RL['water_kl'], 2), number_format((float)$R['totals']['water_kl'], 2)],
            ];
        ?>
        <hr>
        <h5 class="fw-bold">Result</h5>
        <div class="row g-4">
            <div class="col-lg-5">
                <table class="table table-sm table-bordered small">
                    <thead class="table-dark"><tr><th></th><th>As journaled</th><th>Recalculated</th></tr></thead>
                    <tbody>
                    <?php foreach ($facts as $fct): $changed = (trim($fct[1]) !== trim($fct[2])); ?>
                        <tr class="<?php echo $changed ? 'table-warning' : ''; ?>"><td class="fw-semibold"><?php echo $fct[0]; ?></td><td><?php echo htmlspecialchars($fct[1] !== '' ? $fct[1] : '-'); ?></td><td><?php echo htmlspecialchars($fct[2] !== '' ? $fct[2] : '-'); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="col-lg-7">
                <table class="table table-sm table-bordered">
                    <thead class="table-dark"><tr><th>Charge</th><th class="text-end">Journaled (R)</th><th class="text-end">Recalculated (R)</th><th class="text-end">Difference (R)</th></tr></thead>
                    <tbody>
                    <?php if (empty($cmp_lines)): ?><tr><td colspan="4" class="text-muted text-center">No charges.</td></tr><?php endif; ?>
                    <?php foreach ($cmp_lines as $ck => $cl): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(LUM_ADJ_CHARGES[$ck][0]); ?></td>
                            <td class="text-end"><?php echo $money($cl['original']); ?></td>
                            <td class="text-end"><?php echo $money($cl['corrected']); ?></td>
                            <td class="text-end <?php echo $cl['difference'] < 0 ? 'amt-credit' : ($cl['difference'] > 0 ? 'amt-debit' : ''); ?>"><?php echo $signed($cl['difference']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><th colspan="3" class="text-end">Electricity</th><th class="text-end"><?php echo $signed($cmp_tot['elec']); ?></th></tr>
                        <tr><th colspan="3" class="text-end">Water &amp; Sewer</th><th class="text-end"><?php echo $signed($cmp_tot['water']); ?></th></tr>
                        <tr><th colspan="3" class="text-end">Refuse</th><th class="text-end"><?php echo $signed($cmp_tot['refuse']); ?></th></tr>
                        <tr class="table-dark"><th colspan="3" class="text-end">Adjustment Total</th><th class="text-end"><?php echo $signed($cmp_tot['total']); ?><?php echo abs($cmp_tot['total']) < 0.005 ? '' : ($cmp_tot['total'] < 0 ? ' (refund)' : ' (tenant pays)'); ?></th></tr>
                    </tfoot>
                </table>
                <?php if (abs($cmp_tot['total']) < 0.005): ?><div class="small text-muted">The recalculation gives the same amounts as the journal, so there is nothing to adjust.</div><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

<?php elseif ($mode === 'detail'): ?>
    <!-- =============================== DETAIL =============================== -->
    <?php if (!$view): ?>
        <div class="alert alert-warning">Adjustment not found.</div>
    <?php else:
        $a = $view['adjustment'];
        list($kind_label, $kind_cls) = $kind($a['total']);
    ?>
    <div class="bb-card p-4 mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 border-bottom pb-3 mb-3">
            <div>
                <h3 class="fw-bold mb-1"><?php echo lumAdjNumber($a['adjustment_id']); ?> <?php echo $status_badge($a['status']); ?>
                    <span class="badge bg-<?php echo $kind_cls; ?>"><?php echo $kind_label; ?></span>
                </h3>
                <h5 class="text-muted mb-1"><?php echo htmlspecialchars($a['tenant_name']); ?><?php echo $a['tenant_code'] ? ' (' . htmlspecialchars($a['tenant_code']) . ')' : ''; ?><?php echo $a['tenant_shop'] ? ' &middot; Shop ' . htmlspecialchars($a['tenant_shop']) : ''; ?></h5>
                <div class="small text-muted"><?php echo htmlspecialchars($a['property']); ?></div>
            </div>
            <div class="text-end">
                <div class="small text-muted">Adjustment total</div>
                <div class="fs-3 <?php echo (float)$a['total'] < 0 ? 'amt-credit' : 'amt-debit'; ?>"><?php echo $signed($a['total']); ?></div>
                <div class="small text-muted"><?php echo (float)$a['total'] < 0 ? 'Paid back to the tenant' : 'Billed to the tenant'; ?></div>
            </div>
        </div>

        <div class="row g-3 mb-4 small">
            <div class="col-md-4">
                <div class="fw-semibold">Corrects</div>
                <div><?php echo htmlspecialchars(lumAdjMonthLabel($a['original_month'], $a['original_year'])); ?>
                    <?php if ($a['original_journal_id']): ?> &middot; <a href="financial-journal.php?id=<?php echo (int)$a['original_journal_id']; ?>">Journal #<?php echo (int)$a['original_journal_id']; ?></a><?php else: ?> &middot; <span class="text-muted">not linked to a journal</span><?php endif; ?>
                </div>
                <?php if ($a['original_period_start']): ?><div class="text-muted">Period <?php echo date('d M Y', strtotime($a['original_period_start'])); ?> &ndash; <?php echo $a['original_period_end'] ? date('d M Y', strtotime($a['original_period_end'])) : '?'; ?></div><?php endif; ?>
            </div>
            <div class="col-md-4">
                <div class="fw-semibold">Billed in</div>
                <div><?php echo htmlspecialchars(lumAdjMonthLabel($a['posting_month'], $a['posting_year'])); ?>
                    <span class="badge bg-light text-dark border"><?php echo $a['posting_option'] === 'same' ? 'Original month (journal again)' : 'Next open month'; ?></span>
                </div>
                <?php if ($a['posted_journal_id']): ?><div>Posted in <a href="financial-journal.php?id=<?php echo (int)$a['posted_journal_id']; ?>">Journal #<?php echo (int)$a['posted_journal_id']; ?></a></div><?php endif; ?>
            </div>
            <div class="col-md-4">
                <div class="fw-semibold">Reason</div>
                <div><?php echo htmlspecialchars(LUM_ADJ_REASONS[$a['reason']] ?? $a['reason']); ?> <span class="text-muted">(<?php echo htmlspecialchars($a['adjustment_type']); ?>)</span></div>
                <?php if ($a['reverses_adjustment_id']): ?><div>Reverses <a href="back-billing.php?id=<?php echo (int)$a['reverses_adjustment_id']; ?>"><?php echo lumAdjNumber($a['reverses_adjustment_id']); ?></a></div><?php endif; ?>
                <?php foreach ($view_reversed_by as $rb): ?><div>Reversed by <a href="back-billing.php?id=<?php echo (int)$rb['adjustment_id']; ?>"><?php echo lumAdjNumber($rb['adjustment_id']); ?></a> (<?php echo htmlspecialchars($rb['status']); ?>)</div><?php endforeach; ?>
            </div>
            <div class="col-12">
                <div class="fw-semibold">Description</div>
                <div><?php echo nl2br(htmlspecialchars((string)$a['description'])); ?></div>
            </div>
            <?php $rs = ($a['adjustment_type'] === 'recalculated') ? (json_decode((string)$a['recalc_settings'], true) ?: []) : []; ?>
            <?php if ($rs): $rj = $rs['journaled'] ?? []; $rr = $rs['recalculated'] ?? []; ?>
            <div class="col-12">
                <div class="fw-semibold mb-1">Recalculation<?php echo !empty($rr['calculated_at']) ? ' <span class="text-muted fw-normal">(' . htmlspecialchars(date('d M Y H:i', strtotime($rr['calculated_at']))) . ')</span>' : ''; ?></div>
                <table class="table table-sm table-bordered mb-0" style="max-width: 900px;">
                    <thead class="table-light"><tr><th></th><th>As journaled</th><th>Recalculated</th></tr></thead>
                    <tbody>
                    <?php foreach ([['Electrical tariff', 'elec_tariff'], ['Water tariff', 'water_tariff'], ['Sewer tariff', 'sewer_tariff'], ['Billing OBIS', 'elec_obis'], ['Generator', 'gen_method']] as $fr):
                        $v1 = (string)($rj[$fr[1]] ?? ''); $v2 = (string)($rr[$fr[1]] ?? ''); ?>
                        <tr class="<?php echo trim($v1) !== trim($v2) ? 'table-warning' : ''; ?>"><td><?php echo $fr[0]; ?></td><td><?php echo htmlspecialchars($v1 ?: '-'); ?></td><td><?php echo htmlspecialchars($v2 ?: '-'); ?></td></tr>
                    <?php endforeach; ?>
                    <?php
                    $pp = function ($p) {
                        if (empty($p['start_date'])) return '-';
                        $t = date('d M Y', strtotime($p['start_date'])) . ' - ' . date('d M Y', strtotime($p['end_date']));
                        if (!empty($p['start_date_2'])) $t .= ' & ' . date('d M Y', strtotime($p['start_date_2'])) . ' - ' . date('d M Y', strtotime($p['end_date_2']));
                        return $t;
                    };
                    $p1 = $pp($rj['period'] ?? []); $p2 = $pp($rr['period'] ?? []);
                    ?>
                    <tr class="<?php echo $p1 !== $p2 ? 'table-warning' : ''; ?>"><td>Billing period</td><td><?php echo htmlspecialchars($p1); ?></td><td><?php echo htmlspecialchars($p2); ?></td></tr>
                    <tr><td>Units</td><td><?php echo number_format((float)($rj['kwh'] ?? 0), 2); ?> kWh &middot; <?php echo number_format((float)($rj['kl'] ?? 0), 2); ?> kL</td><td><?php echo number_format((float)($rr['kwh'] ?? 0), 2); ?> kWh &middot; <?php echo number_format((float)($rr['kl'] ?? 0), 2); ?> kL</td></tr>
                    </tbody>
                </table>
                <?php $rc = $rs['corrections'] ?? []; if (($rc['kwh'] ?? '') !== '' || ($rc['kl'] ?? '') !== ''): ?>
                    <div class="small text-muted mt-1">Corrected units entered: <?php echo ($rc['kwh'] ?? '') !== '' ? htmlspecialchars($rc['kwh']) . ' kWh ' : ''; ?><?php echo ($rc['kl'] ?? '') !== '' ? htmlspecialchars($rc['kl']) . ' kL' : ''; ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="table-responsive mb-3">
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-dark">
                    <tr><th>Charge</th><th class="text-end">Billed (R)</th><th class="text-end">Should have been (R)</th><th class="text-end">Difference (R)</th></tr>
                </thead>
                <tbody>
                <?php foreach ($view['lines'] as $ck => $l): ?>
                    <tr>
                        <td><?php echo htmlspecialchars(LUM_ADJ_CHARGES[$ck][0] ?? $ck); ?></td>
                        <td class="text-end"><?php echo $l['original_amount'] !== null ? $money($l['original_amount']) : '-'; ?></td>
                        <td class="text-end"><?php echo $l['corrected_amount'] !== null ? $money($l['corrected_amount']) : '-'; ?></td>
                        <td class="text-end <?php echo (float)$l['difference'] < 0 ? 'amt-credit' : ((float)$l['difference'] > 0 ? 'amt-debit' : ''); ?>"><?php echo $signed($l['difference']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr><th colspan="3" class="text-end">Electricity</th><th class="text-end"><?php echo $signed($a['total_elec']); ?></th></tr>
                    <tr><th colspan="3" class="text-end">Water &amp; Sewer</th><th class="text-end"><?php echo $signed($a['total_water']); ?></th></tr>
                    <tr><th colspan="3" class="text-end">Refuse</th><th class="text-end"><?php echo $signed($a['total_refuse']); ?></th></tr>
                    <tr class="table-dark"><th colspan="3" class="text-end">Adjustment Total</th><th class="text-end"><?php echo $signed($a['total']); ?></th></tr>
                </tfoot>
            </table>
        </div>

        <?php if ($a['kwh_original'] !== null || $a['kwh_corrected'] !== null || $a['kl_original'] !== null || $a['kl_corrected'] !== null): ?>
        <div class="small mb-3">
            <?php if ($a['kwh_original'] !== null || $a['kwh_corrected'] !== null): ?>
                Electricity: <?php echo number_format((float)$a['kwh_original'], 2); ?> kWh billed, <?php echo number_format((float)$a['kwh_corrected'], 2); ?> kWh should have been billed.
            <?php endif; ?>
            <?php if ($a['kl_original'] !== null || $a['kl_corrected'] !== null): ?>
                Water: <?php echo number_format((float)$a['kl_original'], 2); ?> kL billed, <?php echo number_format((float)$a['kl_corrected'], 2); ?> kL should have been billed.
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Workflow -->
        <div class="small text-muted border-top pt-3 mb-3">
            Created <?php echo htmlspecialchars(date('d M Y H:i', strtotime($a['created_at']))); ?> by <?php echo htmlspecialchars($a['created_by_name']); ?>
            <?php if ($a['updated_at']): ?> &middot; Edited <?php echo htmlspecialchars(date('d M Y H:i', strtotime($a['updated_at']))); ?> by <?php echo htmlspecialchars((string)$a['updated_by_name']); ?><?php endif; ?>
            <?php if ($a['approved_at']): ?> &middot; Approved <?php echo htmlspecialchars(date('d M Y H:i', strtotime($a['approved_at']))); ?> by <?php echo htmlspecialchars((string)$a['approved_by_name']); ?><?php endif; ?>
            <?php if ($a['posted_at']): ?> &middot; Posted <?php echo htmlspecialchars(date('d M Y H:i', strtotime($a['posted_at']))); ?><?php endif; ?>
            <?php if ($a['voided_at']): ?> &middot; <span class="text-danger">Voided <?php echo htmlspecialchars(date('d M Y H:i', strtotime($a['voided_at']))); ?> by <?php echo htmlspecialchars((string)$a['voided_by_name']); ?>: <?php echo htmlspecialchars((string)$a['void_reason']); ?></span><?php endif; ?>
        </div>

        <!-- Actions -->
        <div class="d-flex flex-wrap gap-2 d-print-none">
            <?php if ($a['status'] === 'Draft' && $can_edit): ?>
                <a href="back-billing.php?action=<?php echo $a['adjustment_type'] === 'recalculated' ? 'recalc' : 'edit'; ?>&amp;id=<?php echo (int)$a['adjustment_id']; ?>" class="btn btn-outline-dark btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>
            <?php endif; ?>
            <?php if ($a['status'] === 'Draft' && $can_approve): ?>
                <form method="POST" class="m-0" onsubmit="return confirm('Approve <?php echo lumAdjNumber($a['adjustment_id']); ?>? It will then be billed in <?php echo htmlspecialchars(addslashes(lumAdjMonthLabel($a['posting_month'], $a['posting_year'])), ENT_QUOTES); ?> (or the next open month).');">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="adjustment_id" value="<?php echo (int)$a['adjustment_id']; ?>">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check2-circle me-1"></i>Approve</button>
                </form>
            <?php endif; ?>
            <?php if (in_array($a['status'], ['Draft', 'Approved'], true) && $can_void): ?>
                <form method="POST" class="d-flex gap-1 m-0" onsubmit="return confirm('Void <?php echo lumAdjNumber($a['adjustment_id']); ?>?');">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="void">
                    <input type="hidden" name="adjustment_id" value="<?php echo (int)$a['adjustment_id']; ?>">
                    <input type="text" name="void_reason" class="form-control form-control-sm" placeholder="Reason" maxlength="255" required style="width: 200px;">
                    <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-x-octagon me-1"></i>Void</button>
                </form>
            <?php endif; ?>
            <?php if ($a['status'] === 'Posted' && $can_edit && empty($view_reversed_by)): ?>
                <a href="back-billing.php?action=reverse&amp;id=<?php echo (int)$a['adjustment_id']; ?>" class="btn btn-outline-warning btn-sm"><i class="bi bi-arrow-counterclockwise me-1"></i>Create Reversing Adjustment</a>
            <?php endif; ?>
            <a href="adjustment-slip.php?id=<?php echo (int)$a['adjustment_id']; ?>" target="_blank" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-text me-1"></i>Print <?php echo (float)$a['total'] < 0 ? 'Credit' : 'Debit'; ?> Note</a>
            <button type="button" onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer me-1"></i>Print Page</button>
        </div>
        <?php if ($a['status'] === 'Approved'): ?>
            <div class="small text-muted mt-2">Approved adjustments are billed on the <?php echo htmlspecialchars(lumAdjMonthLabel($a['posting_month'], $a['posting_year'])); ?> slips and report, and become <em>Posted</em> when that month is journaled.</div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

<?php else: ?>
    <!-- =============================== LIST =============================== -->
    <div class="bb-card p-3 mb-3 d-print-none">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1">Property</label>
                <select name="property" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All properties</option>
                    <?php foreach ($property_options as $p): ?>
                        <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $f_property === $p ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All</option>
                    <?php foreach (LUM_ADJ_STATUSES as $s): ?>
                        <option value="<?php echo $s; ?>" <?php echo $f_status === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Billed in (month)</label>
                <input type="month" name="posting" class="form-control form-control-sm" value="<?php echo htmlspecialchars($f_posting); ?>" onchange="this.form.submit()">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Search</label>
                <input type="text" name="search" class="form-control form-control-sm" value="<?php echo htmlspecialchars($f_search); ?>" placeholder="Tenant, code, shop or description">
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-dark btn-sm"><i class="bi bi-search"></i></button>
                <a href="back-billing.php" class="btn btn-outline-secondary btn-sm">Clear</a>
            </div>
        </form>
    </div>

    <?php
    $sum_debit = 0; $sum_credit = 0;
    foreach ($list as $r) {
        if ($r['status'] === 'Voided') continue;
        if ((float)$r['total'] < 0) $sum_credit += (float)$r['total']; else $sum_debit += (float)$r['total'];
    }
    ?>
    <div class="row g-3 mb-3">
        <div class="col-md-4"><div class="bb-card p-3"><div class="small text-muted">Adjustments shown</div><div class="fs-4 fw-bold"><?php echo count($list); ?></div></div></div>
        <div class="col-md-4"><div class="bb-card p-3"><div class="small text-muted">Back billing (excl. voided)</div><div class="fs-4 amt-debit"><?php echo $signed($sum_debit); ?></div></div></div>
        <div class="col-md-4"><div class="bb-card p-3"><div class="small text-muted">Refunds (excl. voided)</div><div class="fs-4 amt-credit"><?php echo $signed($sum_credit); ?></div></div></div>
    </div>

    <div class="bb-card">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle" style="font-size: 0.85rem;">
                <thead class="table-dark">
                    <tr><th>No.</th><th>Tenant</th><th>Property</th><th>Reason</th><th>Corrects</th><th>Billed in</th><th class="text-end">Amount</th><th>Status</th><th>Created</th><th></th></tr>
                </thead>
                <tbody>
                <?php if (empty($list)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">No adjustments found.</td></tr>
                <?php endif; ?>
                <?php foreach ($list as $r): ?>
                    <tr class="<?php echo $r['status'] === 'Voided' ? 'text-muted' : ''; ?>">
                        <td class="text-nowrap"><?php echo lumAdjNumber($r['adjustment_id']); ?></td>
                        <td><?php echo htmlspecialchars((string)$r['tenant_name']); ?><div class="small text-muted"><?php echo htmlspecialchars(trim((string)$r['tenant_code'] . ' ' . ($r['tenant_shop'] ? '· Shop ' . $r['tenant_shop'] : ''))); ?></div></td>
                        <td><?php echo htmlspecialchars($r['property']); ?></td>
                        <td class="small"><?php echo htmlspecialchars(LUM_ADJ_REASONS[$r['reason']] ?? $r['reason']); ?></td>
                        <td class="small text-nowrap"><?php echo htmlspecialchars(date('M Y', mktime(0, 0, 0, $r['original_month'], 1, $r['original_year']))); ?><?php echo $r['original_journal_id'] ? ' <span class="text-muted">#' . (int)$r['original_journal_id'] . '</span>' : ''; ?></td>
                        <td class="small text-nowrap"><?php echo htmlspecialchars(date('M Y', mktime(0, 0, 0, $r['posting_month'], 1, $r['posting_year']))); ?><?php echo $r['posting_option'] === 'same' ? ' <span class="badge bg-warning text-dark" title="Billed in the original month">same</span>' : ''; ?></td>
                        <td class="text-end text-nowrap <?php echo (float)$r['total'] < 0 ? 'amt-credit' : 'amt-debit'; ?>"><?php echo $signed($r['total']); ?></td>
                        <td><?php echo $status_badge($r['status']); ?></td>
                        <td class="small"><?php echo htmlspecialchars(date('d M Y', strtotime($r['created_at']))); ?><br><span class="text-muted"><?php echo htmlspecialchars($r['created_by_name']); ?></span></td>
                        <td class="text-end"><a href="back-billing.php?id=<?php echo (int)$r['adjustment_id']; ?>" class="btn btn-sm btn-outline-primary" title="View"><i class="bi bi-eye"></i></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
</div>

    <?php include LUM_ROOT . '/Layout/foot-assets.php'; ?>
</body>
</html>