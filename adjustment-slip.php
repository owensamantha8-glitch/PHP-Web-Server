<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once __DIR__ . '/bootstrap.php';
lum_page('back_billing', 'view');

// =========================================================================
// LYNX UTILITY MANAGEMENT - ADJUSTMENT SLIP (CREDIT / DEBIT NOTE)
// Location: /var/www/Lynx/Reporting/adjustment-slip.php
// Printable note for one back billing / refund adjustment, in the same
// layout as the consumption slip.
// =========================================================================

lum_use('back_billing', 'reporting'); // Reporting engine: company details on the note

$adj = lumAdjGet(lumJournalDb(), (int)($_GET['id'] ?? 0));
if (!$adj) {
    http_response_code(404);
    die("<div style='padding:20px; font-family:sans-serif;'>Adjustment not found. <a href='back-billing.php'>Back Billing</a></div>");
}
$a = $adj['adjustment'];
lum_require_property($a['property']);

// Property account number (same source as the consumption slip)
$account_number = '';
$pdo = lum_db('properties');
if ($pdo) {
    try {
        $st = $pdo->prepare("SELECT account_number FROM lum_properties WHERE Property = ? LIMIT 1");
        $st->execute([$a['property']]);
        $account_number = (string)($st->fetchColumn() ?: '');
    } catch (\Throwable $e) {
        error_log('LUM adjustment slip: property lookup failed: ' . $e->getMessage());
    }
}

$total = (float)$a['total'];
$is_credit = ($total < 0);
$title = $is_credit ? 'Credit Note' : 'Debit Note';
$watermark = in_array($a['status'], ['Draft', 'Voided'], true) ? strtoupper($a['status']) : '';
$note_date = $a['approved_at'] ?: $a['created_at'];
$money = function ($v) { return number_format((float)$v, 2, '.', ' '); };

// Lines per group
$groups = ['elec' => 'Electricity', 'water' => 'Water & Sewer', 'refuse' => 'Refuse'];
$by_group = [];
foreach ($adj['lines'] as $key => $l) {
    $g = LUM_ADJ_CHARGES[$key][1] ?? 'elec';
    $by_group[$g][$key] = $l;
}
$group_totals = ['elec' => (float)$a['total_elec'], 'water' => (float)$a['total_water'], 'refuse' => (float)$a['total_refuse']];
$filename = preg_replace('/[^A-Za-z0-9]+/', '_', $title . '_' . lumAdjNumber($a['adjustment_id']) . '_' . $a['tenant_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($filename); ?></title>
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>
    <style>
        * { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important; border-radius: 0 !important; }
        body { background-color: #e2e8f0; }
        .slip-container { max-width: 1000px; background: #fff; border-top: 6px solid #000; margin: 0 auto; box-shadow: 0 4px 6px rgba(0,0,0,0.1); position: relative; }
        .table-sm th, .table-sm td { padding: 0.4rem 0.6rem; vertical-align: middle; }
        .watermark { position: absolute; top: 40%; left: 0; right: 0; text-align: center; font-size: 110px; color: rgba(220, 53, 69, 0.12);
                     transform: rotate(-20deg); pointer-events: none; font-weight: 700 !important; letter-spacing: 12px; }
        @media print {
            @page { size: A4; margin: 8mm; }
        .d-print-none { display: none !important; }
        body { background: #fff; font-size: 12px !important; }
        .slip-container { box-shadow: none !important; border: none !important; padding: 0 !important; max-width: 100%; }
        .table-sm th, .table-sm td { padding: 0.15rem 0.25rem !important; font-size: 11px !important; }
        img[alt="Lynx Utility Management Logo"] { height: 60px !important; }
        }
    </style>
</head>
<body>

<div class="d-print-none bg-dark text-white p-2 px-3 d-flex justify-content-between align-items-center">
    <div><i class="bi bi-file-earmark-text me-2"></i><?php echo htmlspecialchars($title . ' ' . lumAdjNumber($a['adjustment_id'])); ?></div>
    <div class="d-flex gap-2">
        <a href="back-billing.php?id=<?php echo (int)$a['adjustment_id']; ?>" class="btn btn-outline-light btn-sm">Back to Adjustment</a>
        <button type="button" onclick="window.print()" class="btn btn-danger btn-sm"><i class="bi bi-printer me-1"></i>Print / PDF</button>
    </div>
</div>

<div class="p-4">
<div class="p-4 p-md-5 border slip-container">
    <?php if ($watermark !== ''): ?><div class="watermark"><?php echo $watermark; ?></div><?php endif; ?>

    <?php echo lumCompanyHeaderHtml($title); // Company details: Configurations -> Company Details ?>

    <!-- Info box -->
    <div class="border p-2 mb-3 bg-light">
        <div class="row">
            <div class="col-6">
                <table class="table table-borderless table-sm mb-0">
                    <tbody>
                        <tr><th class="text-muted text-nowrap fw-normal" style="width: 35%;">Premises:</th><td><?php echo htmlspecialchars($a['property']); ?></td></tr>
                        <tr><th class="text-muted text-nowrap fw-normal">Account No:</th><td><?php echo htmlspecialchars($account_number); ?></td></tr>
                        <tr><th class="text-muted text-nowrap fw-normal">Tenant:</th><td><?php echo htmlspecialchars((string)$a['tenant_name']); ?></td></tr>
                        <tr><th class="text-muted text-nowrap fw-normal">Shop No:</th><td><?php echo htmlspecialchars((string)$a['tenant_shop']); ?></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="col-6">
                <table class="table table-borderless table-sm mb-0">
                    <tbody>
                        <tr><th class="text-muted text-nowrap fw-normal" style="width: 40%;"><?php echo $title; ?> No:</th><td><?php echo lumAdjNumber($a['adjustment_id']); ?></td></tr>
                        <tr><th class="text-muted text-nowrap fw-normal">Date:</th><td><?php echo date('d/m/Y', strtotime($note_date)); ?></td></tr>
                        <tr><th class="text-muted text-nowrap fw-normal">Billing Period:</th><td><?php echo htmlspecialchars(lumAdjMonthLabel($a['posting_month'], $a['posting_year'])); ?></td></tr>
                        <tr><th class="text-muted text-nowrap fw-normal">Period Corrected:</th><td>
                            <?php echo htmlspecialchars(lumAdjMonthLabel($a['original_month'], $a['original_year'])); ?>
                            <?php if ($a['original_period_start'] && $a['original_period_end']): ?>
                                (<?php echo date('d/m/Y', strtotime($a['original_period_start'])); ?> - <?php echo date('d/m/Y', strtotime($a['original_period_end'])); ?>)
                            <?php endif; ?>
                        </td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Reason -->
    <div class="mb-3">
        <h4 class="border-bottom border-secondary pb-1 mb-2 text-dark text-uppercase fw-normal">Reason for this <?php echo strtolower($title); ?></h4>
        <p class="mb-1"><strong><?php echo htmlspecialchars(LUM_ADJ_REASONS[$a['reason']] ?? $a['reason']); ?></strong></p>
        <?php if (!empty($a['description'])): ?><p class="mb-0"><?php echo nl2br(htmlspecialchars($a['description'])); ?></p><?php endif; ?>
        <?php if ($a['kwh_original'] !== null && $a['kwh_corrected'] !== null && abs((float)$a['kwh_original'] - (float)$a['kwh_corrected']) >= 0.005): ?>
            <p class="mb-0 text-muted small">Electricity: <?php echo number_format((float)$a['kwh_original'], 2); ?> kWh billed, corrected to <?php echo number_format((float)$a['kwh_corrected'], 2); ?> kWh.</p>
        <?php endif; ?>
        <?php if ($a['kl_original'] !== null && $a['kl_corrected'] !== null && abs((float)$a['kl_original'] - (float)$a['kl_corrected']) >= 0.005): ?>
            <p class="mb-0 text-muted small">Water: <?php echo number_format((float)$a['kl_original'], 2); ?> kL billed, corrected to <?php echo number_format((float)$a['kl_corrected'], 2); ?> kL.</p>
        <?php endif; ?>
    </div>

    <!-- Charges -->
    <?php foreach ($groups as $gkey => $glabel): if (empty($by_group[$gkey])) continue; ?>
    <div class="table-responsive mb-3">
        <table class="table table-bordered table-sm align-middle">
            <thead class="table-dark">
                <tr><th colspan="4" class="text-uppercase fw-normal"><?php echo htmlspecialchars($glabel); ?> — Adjustment</th></tr>
            </thead>
            <tbody>
                <tr class="text-dark fw-normal" style="background-color: #f8f9fa;">
                    <td style="width: 40%;">Description</td>
                    <td class="text-end">Billed (R)</td>
                    <td class="text-end">Correct Amount (R)</td>
                    <td class="text-end">Difference (R)</td>
                </tr>
                <?php foreach ($by_group[$gkey] as $key => $l): ?>
                <tr>
                    <td><?php echo htmlspecialchars(LUM_ADJ_CHARGES[$key][0] ?? $key); ?></td>
                    <td class="text-end"><?php echo $l['original_amount'] !== null ? $money($l['original_amount']) : '-'; ?></td>
                    <td class="text-end"><?php echo $l['corrected_amount'] !== null ? $money($l['corrected_amount']) : '-'; ?></td>
                    <td class="text-end"><?php echo $money($l['difference']); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="table-light text-dark fw-normal">
                    <td colspan="3">Subtotal - <?php echo htmlspecialchars($glabel); ?></td>
                    <td class="text-end">R <?php echo number_format($group_totals[$gkey], 2); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>

    <!-- Total -->
    <div class="d-flex justify-content-between align-items-center bg-dark text-white p-3 px-4 mt-4 mb-2">
        <h4 class="mb-0 text-uppercase text-white fw-normal"><?php echo $is_credit ? 'Amount Credited to Your Account' : 'Additional Amount Payable'; ?></h4>
        <h3 class="mb-0 text-white fw-normal">R <?php echo number_format(abs($total), 2); ?></h3>
    </div>
    <p class="text-muted small mb-3">
        This <?php echo strtolower($title); ?> is included on your consumption slip for <?php echo htmlspecialchars(lumAdjMonthLabel($a['posting_month'], $a['posting_year'])); ?>
        <?php echo $is_credit ? 'and reduces the amount payable for that month.' : 'and is added to the amount payable for that month.'; ?>
    </p>

    <div class="text-center text-muted small mt-3 px-md-5">
        <p class="mb-0"><?php echo lumCompanyContactNoteHtml('this ' . strtolower($title)); ?></p>
    </div>
</div>
</div>

</body>
</html>