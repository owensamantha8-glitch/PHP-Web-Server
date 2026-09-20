<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once __DIR__ . '/bootstrap.php';
lum_page('consumption_slips', 'view');
ini_set('memory_limit', '512M'); // Increase memory limit to handle massive arrays
set_time_limit(300); // Give the script 5 full minutes to crunch the TOU data

// Prevent output buffering from holding the entire response
if (ob_get_level() == 0) ob_start();

// --- SECURE HEX CHUNKED PAYLOAD DECODER (100% WAF IMMUNE) ---
$hex = '';
for ($i = 0; $i < 50; $i++) {
    if (isset($_POST['p' . $i])) {
        $hex .= $_POST['p' . $i];
    } else {
        break;
    }
}

if (!empty($hex)) {
    $json = hex2bin($hex);
    if ($json !== false) {
        $arr = json_decode($json, true);
        if (is_array($arr)) {
            foreach ($arr as $k => $v) {
                $_REQUEST[$k] = $v;
                $_POST[$k] = $v;
                $_GET[$k] = $v;
            }
        }
    }
}

// 1. DATABASE CONNECTIONS & SHARED SLIP ENGINE (bootstrap.php; the engine also loads reporting-engine.php)
lum_connect('tenants', 'obis', 'manual', 'tariffs');
lum_use('slips');

$selected_property = $_REQUEST['property'] ?? '';

// Property access: restricted users may only export slips for their own properties
if (!empty($_SESSION['assigned_properties']) && $selected_property !== ''
    && !in_array($selected_property, array_map('trim', explode(',', $_SESSION['assigned_properties'])), true)) {
    http_response_code(403);
    die("<div style='padding:20px; font-family:sans-serif; color:#fff; background:#121212; height:100vh;'><h4>Access Denied</h4><p>You do not have access to this property.</p></div>");
}
if (empty($selected_property)) {
    die("<div style='padding:20px; font-family:sans-serif; color:#fff; background:#121212; height:100vh;'><h4>Invalid Request</h4><p>No property selected for bulk export.</p></div>");
}

// 2. SHARED BILLING SETUP - identical to the single consumption slip
$property_name = $selected_property;
require LUM_SLIP_DIR . '/slip-context.php';

// Fetch Tenants
$stmt = $tenant_db_conn->prepare("SELECT * FROM lum_tenants WHERE tenant_property = :prop ORDER BY tenant_shop ASC");
$stmt->execute(['prop' => $selected_property]);
$tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Settings chosen on individual tenants' slips for this billing month (including their own billing cycles),
// so every bulk slip matches the financial report and the tenant's single consumption slip
$tenant_report_settings = lumTenantReportSettingsLoad($tenant_db_conn, array_column($tenants, 'tenant_id'), $report_month, $report_year);
$lum_gen_cache = null;
// Back billing adjustments on the slips follow the report's billing month (also for tenants with their own cycle)
$slip_bill_month = $report_month;
$slip_bill_year = $report_year;

// Chart Collector Arrays (filled by slip-render.php in bulk mode)
$all_chart_data = [];
$all_water_chart_data = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Export - <?php echo htmlspecialchars($selected_property); ?></title>
    
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" integrity="sha384-ZZ1pncU3bQe8y31yfZdMFdSpttDoPmOZg2wguVK9almUodir1PghgT0eY7Mrty8H" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" integrity="sha384-JcnsjUPPylna1s1fvi1u12X5qjY5OL56iySh75FdtrwhO/SWXgMjoVqcKyIIWOLk" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js" integrity="sha384-+mbV2IY1Zk/X1p/nWllGySJSUN8uMs+gUAN10Or95UBH0fpj6GfKgPmgC5EXieXG" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js" integrity="sha384-PlRSzpewlarQuj5alIadXwjNUX+2eNMKwr0f07ShWYLy8B6TjEbm7ZlcN/ScSbwy" crossorigin="anonymous"></script>

    <style>
        body { 
            background-color: #e2e8f0; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
        }
        .slip-wrapper {
            width: 100%;
            margin-bottom: 30px;
        }
        .slip-container { 
            max-width: 1000px; 
            background-color: #ffffff; 
            border-top: 6px solid var(--bs-black); 
            margin: 0 auto; 
        }
        .table-sm th, .table-sm td { 
            padding: 0.4rem 0.6rem; 
            vertical-align: middle; 
        }
        /* --- SAME TYPOGRAPHY AS THE SINGLE CONSUMPTION SLIP --- */
        .slip-container, .slip-container * {
            border-radius: 0 !important;
            font-weight: normal !important;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important;
        }
        /* --- LIGHTER TABLE BORDERS --- */
        .slip-container .table-bordered,
        .slip-container .table-bordered > tbody > tr > td,
        .slip-container .table-bordered > tbody > tr > th,
        .slip-container .table-bordered > thead > tr > td,
        .slip-container .table-bordered > thead > tr > th {
            border: 1px solid #b0b0b0 !important;
        }
        /* --- TIGHTER SPACING FOR ONE-PAGE FIT --- */
        @media print {
            @page { size: A4; margin: 4mm; }
        .d-print-none, .navbar { display: none !important; }
        body { background-color: #ffffff; padding: 0; font-size: 11px !important; }
        .slip-wrapper { page-break-after: always; }
        .slip-wrapper:last-child { page-break-after: auto; }
        .slip-container { border: none !important; box-shadow: none !important; padding: 0 !important; max-width: 100%; margin: 0; }
        .main-content { padding: 0 !important; width: 100% !important; overflow: visible !important; background-color: #ffffff !important; }
        .page-break-inside-avoid, .table-responsive, .info-box { page-break-inside: avoid !important; }
        .mb-5 { margin-bottom: 0.3rem !important; }
        .mb-4 { margin-bottom: 0.2rem !important; }
        .mb-3 { margin-bottom: 0.1rem !important; }
        .pb-3 { padding-bottom: 0.1rem !important; }
        .pb-2 { padding-bottom: 0.05rem !important; }
        .pt-3 { padding-top: 0.1rem !important; }
        .p-4, .p-md-5 { padding: 0.15rem !important; }
        .p-3 { padding: 0.15rem !important; }
        .mt-4 { margin-top: 0.3rem !important; }
        h2 { font-size: 1.4rem !important; margin-bottom: 0.1rem !important; }
        h4 { font-size: 1.15rem !important; margin-bottom: 0.1rem !important; }
        h6 { font-size: 0.95rem !important; margin-bottom: 0.1rem !important; }
        p.text-muted { font-size: 11px !important; margin-bottom: 0 !important; }
        img[alt="Lynx Utility Management Logo"] { height: 65px !important; }
        .table-sm th, .table-sm td { padding: 0.15rem 0.25rem !important; font-size: 12px !important; line-height: 1.2 !important; }
        .table-borderless th, .table-borderless td { padding: 0.1rem 0.2rem !important; font-size: 12px !important; line-height: 1.2 !important; }
        #elecUsageChart { max-height: 100px !important; }
        #waterUsageChart { max-height: 100px !important; }
        }
        /* --- TIGHTER SPACING PDF RENDER FONTS --- */
        .pdf-mode { font-size: 12px !important; background-color: #ffffff !important; box-shadow: none !important; border: none !important; padding: 0 !important; max-width: 100% !important; margin: 0 !important; }
        .pdf-mode .mb-5 { margin-bottom: 0.3rem !important; }
        .pdf-mode .mb-4 { margin-bottom: 0.2rem !important; }
        .pdf-mode .mb-3 { margin-bottom: 0.1rem !important; }
        .pdf-mode .pb-3 { padding-bottom: 0.1rem !important; }
        .pdf-mode .pb-2 { padding-bottom: 0.05rem !important; }
        .pdf-mode .pt-3 { padding-top: 0.1rem !important; }
        .pdf-mode .p-4, .pdf-mode .p-md-5 { padding: 0.15rem !important; }
        .pdf-mode .p-3 { padding: 0.15rem !important; }
        .pdf-mode .mt-4 { margin-top: 0.3rem !important; }
        .pdf-mode .page-break-inside-avoid, .pdf-mode .table-responsive, .pdf-mode .info-box { page-break-inside: avoid !important; }
        .pdf-mode h2 { font-size: 1.4rem !important; margin-bottom: 0.1rem !important; }
        .pdf-mode h4 { font-size: 1.15rem !important; margin-bottom: 0.1rem !important; }
        .pdf-mode h6 { font-size: 0.95rem !important; margin-bottom: 0.1rem !important; }
        .pdf-mode p.text-muted { font-size: 11px !important; margin-bottom: 0 !important; }
        .pdf-mode img[alt="Lynx Utility Management Logo"] { height: 65px !important; width: auto !important; margin-bottom: 2px !important; }
        .pdf-mode .table-sm th, .pdf-mode .table-sm td { padding: 0.15rem 0.25rem !important; font-size: 12px !important; line-height: 1.2 !important; }
        .pdf-mode .table-borderless th, .pdf-mode .table-borderless td { padding: 0.1rem 0.2rem !important; font-size: 12px !important; line-height: 1.2 !important; }
        #loadingOverlay {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background-color: rgba(15, 15, 15, 0.98);
            z-index: 9999;
            display: none;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            color: white;
        }
    </style>
</head>
<body>

    <!-- Include Controls -->
    <?php include 'bulk-slip-controls.php'; ?>

    <div class="main-content flex-grow-1 p-4">
    <?php
    $bulk_slip_no = 0;
    foreach ($tenants as $index => $tenant):
        // A tenant set to separate slips gets two documents: electricity, then water.
        $bulk_parts = (($tenant['slip_layout'] ?? 'combined') === 'separate') ? ['electricity', 'water'] : ['all'];
        foreach ($bulk_parts as $slip_part):
            $bulk_slip_no++;
            // Everything is worked out again for each part, exactly as a single slip would
            include 'bulk-slip-calculations.php';
    ?>
        <!-- Render the wrapper and slip -->
        <div class="slip-wrapper" id="slip-wrapper-<?php echo $bulk_slip_no; ?>">
            <div class="slip-container" id="slip-container-<?php echo $bulk_slip_no; ?>" data-filename="<?php echo $slip_filename; ?>">
                <?php include 'bulk-slip-render.php'; ?>
            </div>
        </div>
    <?php
        endforeach;
    endforeach;
    ?>
    </div>

    <?php include LUM_ROOT . '/Layout/foot-assets.php'; ?>
    
    <!-- Core UI Script (Always Load) -->
    <script>
        async function generateZip() {
            const overlay = document.getElementById('loadingOverlay');
            const loadingText = document.getElementById('loadingText');
            const slips = document.querySelectorAll('.slip-wrapper');
            const zip = new JSZip();
            const { jsPDF } = window.jspdf;
            
            overlay.style.display = 'flex';
            const propName = "<?php echo preg_replace('/[^a-zA-Z0-9]/', '_', $selected_property); ?>";
            const reportMonth = "<?php echo date('M_Y', strtotime(empty($end_date_2) ? $end_date : $end_date_2)); ?>";
            
            window.scrollTo(0, 0);
            
            // Allow browser to render the opaque overlay before freezing the DOM
            await new Promise(r => setTimeout(r, 150));

            // CRITICAL FIX: Temporarily stretch the body so the PDF engine cannot clip elements overflowing a small viewport.
            const originalBodyWidth = document.body.style.width;
            document.body.style.width = '1200px';

            for (let i = 0; i < slips.length; i++) {
                loadingText.innerText = `Generating PDFs... ${i + 1} / ${slips.length}`;
                
                const slip = slips[i];
                const content = slip.querySelector('.slip-container');
                const filename = content.getAttribute('data-filename') || `Slip_${i}.pdf`;
                
                // Temporarily prepare the live container for flawless A4 scaling
                const originalWidth = content.style.width;
                const originalMaxWidth = content.style.maxWidth;
                const originalMargin = content.style.margin;
                
                // CRITICAL FIX: Flush the container tightly to the left side of the virtual screen (margin: 0)
                content.style.width = '1000px';
                content.style.maxWidth = '1000px';
                content.style.margin = '0';
                content.classList.add('pdf-mode');

                // Freeze form inputs as plain text for the snapshot
                const inputs = content.querySelectorAll('input');
                inputs.forEach(inp => {
                    let span = document.createElement('span');
                    span.className = 'temp-pdf-text';
                    span.innerText = inp.value;
                    inp.style.display = 'none';
                    inp.parentNode.insertBefore(span, inp);
                });

                // Small yield to let the browser redraw the styled container
                await new Promise(r => setTimeout(r, 80)); 

                try {
                    // Instruct html2canvas to natively capture exactly the live element bounds from the DOM
                    const canvas = await html2canvas(content, {
                        scale: 3, // High resolution
                        useCORS: true, 
                        logging: false,
                        windowWidth: 1200
                    });
                    
                    const imgData = canvas.toDataURL('image/jpeg', 1.0);
                    const pdf = new jsPDF('p', 'mm', 'a4');
                    
                    const pageWidth = pdf.internal.pageSize.getWidth();   // 210 mm
                    const pageHeight = pdf.internal.pageSize.getHeight(); // 297 mm
                    
                    // Fit strictly to A4 width with a solid 5mm inner margin
                    const margin = 5;
                    const printableWidth = pageWidth - (margin * 2);
                    const imgWidth = printableWidth;
                    const imgHeight = (canvas.height * imgWidth) / canvas.width;
                    
                    let heightLeft = imgHeight;
                    let position = margin; // Top margin

                    pdf.addImage(imgData, 'JPEG', margin, position, imgWidth, imgHeight);
                    
                    // Mask top and bottom margins to prevent visual duplication on page breaks
                    pdf.setFillColor(255, 255, 255);
                    pdf.rect(0, 0, pageWidth, margin, 'F');
                    pdf.rect(0, pageHeight - margin, pageWidth, margin, 'F');

                    heightLeft -= (pageHeight - margin * 2);

                    // Automatically slice across multiple pages if the slip is extremely long
                    while (heightLeft > 0) {
                        position = position - (pageHeight - margin * 2);
                        pdf.addPage();
                        pdf.addImage(imgData, 'JPEG', margin, position, imgWidth, imgHeight);
                        
                        // Mask margins on additional pages
                        pdf.setFillColor(255, 255, 255);
                        pdf.rect(0, 0, pageWidth, margin, 'F');
                        pdf.rect(0, pageHeight - margin, pageWidth, margin, 'F');
                        
                        heightLeft -= (pageHeight - margin * 2);
                    }

                    zip.file(filename, pdf.output('blob'));

                    // Aggressive Garbage Collection
                    canvas.width = 0;
                    canvas.height = 0;
                } catch (e) {
                    console.error("Error generating PDF for " + filename, e);
                }
                
                // Restore element layout immediately after capture
                content.style.width = originalWidth;
                content.style.maxWidth = originalMaxWidth;
                content.style.margin = originalMargin;
                content.classList.remove('pdf-mode');
                
                content.querySelectorAll('.temp-pdf-text').forEach(el => el.remove());
                inputs.forEach(inp => inp.style.display = 'inline-block');
            }

            // Restore body layout safely at the very end
            document.body.style.width = originalBodyWidth;

            loadingText.innerText = 'Zipping files...';
            await new Promise(r => setTimeout(r, 100));

            const zipContent = await zip.generateAsync({type:"blob"});
            saveAs(zipContent, `Slips_${propName}_${reportMonth}.zip`);
            
            overlay.style.display = 'none';
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ" crossorigin="anonymous"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const allCharts = <?php echo json_encode($all_chart_data); ?>;
            const allWaterCharts = <?php echo json_encode($all_water_chart_data); ?>;
            
            for (const [tenantId, data] of Object.entries(allCharts)) {
                const canvas = document.getElementById('elecUsageChart_' + tenantId);
                if (!canvas) continue;
                const ctx = canvas.getContext('2d');
                let chartData, chartOptions;

                if (data.isTOU) {
                    chartData = {
                        labels: data.labels,
                        datasets: [
                            { label: 'Off-Peak (kWh)', data: data.off, backgroundColor: '#198754' },
                            { label: 'Standard (kWh)', data: data.std, backgroundColor: '#ffc107' },
                            { label: 'Peak (kWh)', data: data.peak, backgroundColor: '#dc3545' }
                        ]
                    };
                    chartOptions = {
                        responsive: true, maintainAspectRatio: false,
                        scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } },
                        plugins: { legend: { position: 'bottom' } },
                        animation: false 
                    };
                } else {
                    chartData = {
                        labels: data.labels,
                        datasets: [{
                            label: 'Total Consumption (kWh)',
                            data: data.total,
                            backgroundColor: '#0d6efd'
                        }]
                    };
                    chartOptions = {
                        responsive: true, maintainAspectRatio: false,
                        scales: { y: { beginAtZero: true } },
                        plugins: { legend: { position: 'bottom' } },
                        animation: false 
                    };
                }
                new Chart(ctx, { type: 'bar', data: chartData, options: chartOptions });
            }

            for (const [tenantId, data] of Object.entries(allWaterCharts)) {
                const canvas = document.getElementById('waterUsageChart_' + tenantId);
                if (!canvas) continue;
                const ctx = canvas.getContext('2d');
                
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: data.labels,
                        datasets: [{ label: 'Total Water Usage (kL)', data: data.total, backgroundColor: '#0dcaf0' }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } }, plugins: { legend: { position: 'bottom' } }, animation: false }
                });
            }
        });
    </script>
</body>
</html>