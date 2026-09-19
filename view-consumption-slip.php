<?php
require_once '/var/www/Lynx/bootstrap.php';
lum_page('consumption_slips', 'view');

require_once 'slip-calculations.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($tenant['tenant_name'] ?? 'Slip') . '_(' . htmlspecialchars($tenant['tenant_code'] ?? 'Tenant') . ')'; ?></title>
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>
    
    <style>
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
            border-radius: 0 !important; 
            font-weight: normal !important;
            text-transform: none !important;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important;
        }
        ::-webkit-scrollbar-track { background: #0a0a0a; border-radius: 0; }
        ::-webkit-scrollbar-thumb { background: #4a4a4a; border-radius: 0; }
        body { 
            overflow: hidden; 
            background-color: #e2e8f0; 
        }
        .navbar { background-color: #000000; border-bottom: 1px solid #333333 !important; z-index: 1050; position: sticky; top: 0; }
        .d-flex-wrapper { display: flex; width: 100%; height: calc(100vh - 46px); overflow: hidden; }
        .sidebar { width: 320px; min-width: 320px; background-color: #0a0a0a; color: #fff; padding: 1rem; overflow-y: auto; z-index: 1000; border-right: 1px solid #333; }
        .main-content { flex-grow: 1; overflow-y: auto; background-color: #e2e8f0; padding: 1.5rem; }
        .sidebar-section { background-color: #1a1d20; border: 1px solid #2d3136; border-radius: 0.5rem; padding: 1rem; margin-bottom: 1rem; }
        .sidebar .form-label { color: #ccc; }
        .sidebar .form-control, .sidebar .form-select { background-color: #1e1e1e !important; border: 1px solid #444 !important; color: #fff !important; }
        .sidebar .form-control:focus, .sidebar .form-select:focus { border-color: #e3000f !important; box-shadow: 0 0 0 0.25rem rgba(227, 0, 15, 0.25) !important; }
        .slip-container { max-width: 1000px; background-color: #ffffff; border-top: 6px solid var(--bs-black); margin: 0 auto; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .table-sm th, .table-sm td { padding: 0.4rem 0.6rem; vertical-align: middle; }
        @media print {
            @page { size: A4; margin: 8mm; }
        .d-print-none, .sidebar, .navbar { display: none !important; }
        body { background-color: #ffffff; padding: 0; font-size: 12px !important; overflow: visible !important; }
        .d-flex-wrapper { display: block; overflow: visible !important; height: auto !important; }
        .slip-container { border: none !important; box-shadow: none !important; padding: 0 !important; max-width: 100%; margin: 0; }
        .main-content { padding: 0 !important; width: 100% !important; overflow: visible !important; background-color: #ffffff !important; }
        .page-break-inside-avoid { page-break-inside: avoid; }
        .mb-5 { margin-bottom: 0.75rem !important; }
        .mb-4 { margin-bottom: 0.5rem !important; }
        .mb-3 { margin-bottom: 0.25rem !important; }
        .pb-3 { padding-bottom: 0.25rem !important; }
        .pb-2 { padding-bottom: 0.1rem !important; }
        .pt-3 { padding-top: 0.25rem !important; }
        .p-4, .p-md-5 { padding: 0 !important; }
        .p-3 { padding: 0.5rem !important; }
        h2 { font-size: 1.25rem !important; margin-bottom: 0.1rem !important; }
        h4 { font-size: 1rem !important; margin-bottom: 0.1rem !important; }
        h6 { font-size: 0.85rem !important; margin-bottom: 0.1rem !important; }
        p.text-muted { font-size: 10px !important; margin-bottom: 0 !important; }
        img[alt="Lynx Utility Management Logo"] { height: 60px !important; }
        .table-sm th, .table-sm td { padding: 0.15rem 0.25rem !important; font-size: 11px !important; }
        .table-borderless th, .table-borderless td { padding: 0.1rem 0.2rem !important; font-size: 11px !important; }
        #elecUsageChart { max-height: 140px !important; }
        #waterUsageChart { max-height: 140px !important; }
        }
    </style>
</head>
<body>

    <?php include LUM_ROOT . '/Layout/top-navbar.php'; ?>

    <div class="d-flex-wrapper">
        <?php require_once 'slip-sidebar.php'; ?>

        <div class="main-content p-4">
            
            <div class="p-4 p-md-5 border shadow-sm slip-container">
                <?php require_once 'slip-render.php'; ?>
            </div>
            
        </div>
    </div>
    
</body>
</html>