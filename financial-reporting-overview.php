<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once __DIR__ . '/bootstrap.php';
lum_page('financial_reports', 'view');

// Securely load the background calculations first
require_once 'financial-calculations.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Reporting Overview</title>
    
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
        /* Global Custom Scrollbar */
        ::-webkit-scrollbar { width: 14px; height: 14px; }
        ::-webkit-scrollbar-track { background: #0a0a0a; border-radius: 0; }
        ::-webkit-scrollbar-thumb { background: #4a4a4a; border-radius: 0; }
        body { 
            overflow: hidden; 
            background-color: #e2e8f0; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
        }
        .navbar { background-color: #000000; border-bottom: 1px solid #333333 !important; z-index: 1050; }
        .d-flex-wrapper { display: flex; width: 100%; overflow: hidden; }
        .sidebar { 
            width: 320px;
            min-width: 320px;
            height: calc(100vh - 46px); 
            background-color: #0a0a0a; 
            color: #fff; 
            padding: 1rem; 
            overflow-y: auto; 
            transition: margin-left 0.35s cubic-bezier(0.25, 0.8, 0.25, 1); 
            z-index: 1000;
        }
        .sidebar.collapsed { margin-left: -320px; }
        .sidebar-section { background-color: #1a1d20; border: 1px solid #2d3136; padding: 1rem; margin-bottom: 1rem; }
        .sidebar .form-label { color: #ccc; }
        .sidebar .form-control, .sidebar .form-select { background-color: #1e1e1e !important; border: 1px solid #444 !important; color: #fff !important; }
        .sidebar .form-control:focus, .sidebar .form-select:focus { border-color: #e3000f !important; box-shadow: 0 0 0 0.25rem rgba(227, 0, 15, 0.25) !important; }
        .btn-brand { background-color: #e3000f; color: #ffffff; border: none; }
        .btn-brand:hover { background-color: #bf000c; color: #ffffff; }
        .main-content { 
            flex-grow: 1;
            min-width: 0; 
            height: calc(100vh - 46px); 
            overflow-y: auto; 
            background-color: #e2e8f0;
            padding: 1.5rem;
            transition: all 0.35s cubic-bezier(0.25, 0.8, 0.25, 1); 
        }
        .report-container { background-color: #ffffff; border-top: 6px solid #e3000f; }
        .table-data th { background-color: #f8f9fa; font-weight: 600; width: 60%; }
        .table-data td { text-align: right; }
        @media print {
            .d-print-none, .sidebar, .navbar { display: none !important; }
        body { background-color: #ffffff; padding: 0; overflow: visible !important; }
        .d-flex-wrapper { display: block; overflow: visible !important; }
        .report-container { border: none !important; box-shadow: none !important; padding: 0 !important; max-width: 100%; margin: 0; }
        .main-content { padding: 0 !important; width: 100% !important; height: auto !important; overflow: visible !important; background-color: #ffffff !important;}
        /* Logic to force open checked collapses during PDF Print */
            .collapse-print-force {
                display: block !important;
                visibility: visible !important;
                height: auto !important;
            }
        .print-hide-toggle { display: none !important; }
        }
    </style>
</head>
<body>

    <?php
    $lum_nav_left = '<button class="btn btn-dark p-1 me-3" id="sidebarToggle" title="Toggle Sidebar" style="background-color: #000; border: 1px solid #333;"> <i class="bi bi-fullscreen text-danger fs-5 px-1"></i> </button>';
    include LUM_ROOT . '/Layout/top-navbar.php';
    ?>

    <div class="d-flex-wrapper">
        
        <?php require_once 'financial-sidebar.php'; ?>

        <div class="main-content" id="mainContent">
            <?php require_once 'financial-render.php'; ?>
        </div>

    </div>

    <?php include LUM_ROOT . '/Layout/foot-assets.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ" crossorigin="anonymous"></script>
    <script>
        // --- EXACT DATE BOUNDARY SPLITTING ---
        function applyBillingCycle(val) {
            if (!val) return;
            const parts = val.split('_TO_');
            if (parts.length !== 2) return;
            
            const startStr = parts[0]; 
            const endStr = parts[1];   
            
            const sParts = startStr.split('-');
            const sYear = parseInt(sParts[0], 10);
            const sMonth = parseInt(sParts[1], 10);
            
            const eParts = endStr.split('-');
            const eYear = parseInt(eParts[0], 10);
            const eMonth = parseInt(eParts[1], 10);
            
            function getSeason(m) {
                return [6, 7, 8].includes(m) ? 'High' : 'Low';
            }
            
            const setVal = (name, val) => { 
                const el = document.querySelector(`[name="${name}"]`) || document.getElementById(name); 
                if (el) el.value = val; 
            };
            
            if (sYear === eYear && sMonth === eMonth) {
                const ledgerMonth = sYear + '-' + String(sMonth).padStart(2, '0');
                const season = getSeason(sMonth);
                
                setVal('start_date', startStr);
                setVal('end_date', endStr);
                
                setVal('start_date_2', '');
                setVal('end_date_2', '');
                
                setVal('water_start_date', startStr);
                setVal('water_end_date', endStr);
                
                setVal('water_start_date_2', '');
                setVal('water_end_date_2', '');
                
            } else {
                const endOfStartMonth = new Date(sYear, sMonth, 0); 
                const p1EndStr = sYear + '-' + String(sMonth).padStart(2, '0') + '-' + String(endOfStartMonth.getDate()).padStart(2, '0');
                const p2StartStr = eYear + '-' + String(eMonth).padStart(2, '0') + '-01';
                
                const p1Ledger = sYear + '-' + String(sMonth).padStart(2, '0');
                const p2Ledger = eYear + '-' + String(eMonth).padStart(2, '0');

                setVal('start_date', startStr);
                setVal('end_date', p1EndStr);
                
                setVal('start_date_2', p2StartStr);
                setVal('end_date_2', endStr);
                
                setVal('water_start_date', startStr);
                setVal('water_end_date', p1EndStr);
                
                setVal('water_start_date_2', p2StartStr);
                setVal('water_end_date_2', endStr);
            }
            
            setVal('comm_start_date', startStr);
            setVal('comm_end_date', endStr);
            setVal('water_comm_start_date', startStr);
            setVal('water_comm_end_date', endStr);
            setVal('gen_start_date', startStr);
            setVal('gen_end_date', endStr);
            
            const form = document.querySelector('.sidebar form');
            const sidebarEl = document.querySelector('.sidebar');
            if (sidebarEl) sessionStorage.setItem('lynxSidebarScroll', sidebarEl.scrollTop);
            form.submit();
        }

        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('.sidebar form');
            const sidebarEl = document.querySelector('.sidebar');
            
            // Fullscreen Toggle Logic
            const sidebarToggleBtn = document.getElementById('sidebarToggle');
            if(sidebarToggleBtn) {
                sidebarToggleBtn.addEventListener('click', function() {
                    if (sidebarEl) {
                        sidebarEl.classList.toggle('collapsed');
                        setTimeout(() => window.dispatchEvent(new Event('resize')), 350);
                    }
                });
            }

            // Restore sidebar scroll position if we just reloaded
            if (sidebarEl) {
                const savedScroll = sessionStorage.getItem('lynxSidebarScroll');
                if (savedScroll) {
                    sidebarEl.scrollTop = parseInt(savedScroll, 10);
                    sessionStorage.removeItem('lynxSidebarScroll');
                }
            }
            
            let timeout;
            function autoSubmit() {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    if (sidebarEl) {
                        sessionStorage.setItem('lynxSidebarScroll', sidebarEl.scrollTop);
                    }
                    form.submit();
                }, 400);
            }

            // Attach auto-submit to all relevant form controls
            if (form) {
                const inputs = form.querySelectorAll('input:not([type="hidden"]), select:not(#billing_cycle_dropdown)');
                inputs.forEach(input => {
                    input.addEventListener('change', autoSubmit);
                });
            }

            // Collapse toggles for new charts
            ['tenantBar', 'elecPie', 'waterPie', 'dailyFin'].forEach(prefix => {
                const collapseEl = document.getElementById(prefix + 'Collapse');
                const toggleBtn = document.getElementById(prefix + 'ToggleBtn');
                if (collapseEl && toggleBtn) {
                    const icon = toggleBtn.querySelector('.toggle-icon');
                    collapseEl.addEventListener('show.bs.collapse', () => {
                        icon.classList.remove('bi-plus-circle');
                        icon.classList.add('bi-dash-circle');
                    });
                    collapseEl.addEventListener('hide.bs.collapse', () => {
                        icon.classList.remove('bi-dash-circle');
                        icon.classList.add('bi-plus-circle');
                    });
                }
            });

            // --- Advanced Chart Renderers ---
            
            // 1. Tenant Bar Chart
            const tBarEl = document.getElementById('tenantBarChart');
            if (tBarEl) {
                const tData = JSON.parse(tBarEl.getAttribute('data-chart'));
                new Chart(tBarEl.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: tData.labels,
                        datasets: [{
                            label: 'Total Billed (R)',
                            data: tData.values,
                            backgroundColor: '#0d6efd',
                            borderRadius: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: { y: { beginAtZero: true } }
                    }
                });
            }

            // 2. Electricity Pie Chart
            const ePieEl = document.getElementById('elecPieChart');
            if (ePieEl) {
                const eData = JSON.parse(ePieEl.getAttribute('data-chart'));
                new Chart(ePieEl.getContext('2d'), {
                    type: 'pie',
                    data: {
                        labels: eData.labels,
                        datasets: [{
                            data: eData.values,
                            backgroundColor: ['#0d6efd', '#fd7e14', '#6c757d', '#20c997', '#dc3545', '#ffc107', '#198754']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'right' } }
                    }
                });
            }

            // 3. Water Pie Chart
            const wPieEl = document.getElementById('waterPieChart');
            if (wPieEl) {
                const wData = JSON.parse(wPieEl.getAttribute('data-chart'));
                new Chart(wPieEl.getContext('2d'), {
                    type: 'pie',
                    data: {
                        labels: wData.labels,
                        datasets: [{
                            data: wData.values,
                            backgroundColor: ['#0dcaf0', '#0d6efd', '#6c757d', '#198754']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'right' } }
                    }
                });
            }

            // 4. Daily Financial Spend Chart
            const dfEl = document.getElementById('dailyFinChart');
            if (dfEl) {
                const dfData = JSON.parse(dfEl.getAttribute('data-chart'));
                new Chart(dfEl.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: dfData.labels,
                        datasets: [
                            { 
                                label: 'Combined Total (R)', 
                                data: dfData.total, 
                                type: 'line', 
                                borderColor: '#dc3545', 
                                backgroundColor: '#dc3545', 
                                borderWidth: 2, 
                                fill: false, 
                                tension: 0.3, 
                                pointRadius: 3,
                                order: 1 
                            },
                            { 
                                label: 'Water & Sewer (R)', 
                                data: dfData.water, 
                                backgroundColor: '#0dcaf0',
                                order: 2
                            },
                            { 
                                label: 'Electricity (R)', 
                                data: dfData.elec, 
                                backgroundColor: '#ffc107',
                                order: 3
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: { 
                            x: { stacked: true },
                            y: { stacked: true, beginAtZero: true }
                        },
                        plugins: { legend: { position: 'bottom' } }
                    }
                });
            }

            // Legacy Slip Graph Renders
            document.querySelectorAll('.elec-usage-chart').forEach(canvas => {
                const data = JSON.parse(canvas.getAttribute('data-chart'));
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
                    chartOptions = { responsive: true, maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } }, plugins: { legend: { position: 'bottom' } }, animation: false };
                } else {
                    chartData = {
                        labels: data.labels,
                        datasets: [{ label: 'Total Consumption (kWh)', data: data.total, backgroundColor: '#0d6efd' }]
                    };
                    chartOptions = { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } }, plugins: { legend: { position: 'bottom' } }, animation: false };
                }
                new Chart(ctx, { type: 'bar', data: chartData, options: chartOptions });
            });

            document.querySelectorAll('.water-usage-chart').forEach(canvas => {
                const data = JSON.parse(canvas.getAttribute('data-chart'));
                const ctx = canvas.getContext('2d');
                const chartData = {
                    labels: data.labels,
                    datasets: [{ label: 'Total Water Usage (kL)', data: data.total, backgroundColor: '#0dcaf0' }]
                };
                const chartOptions = { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } }, plugins: { legend: { position: 'bottom' } }, animation: false };
                new Chart(ctx, { type: 'bar', data: chartData, options: chartOptions });
            });
        });

        function bulkExportSlips() {
            const form = document.querySelector('form');
            const urlParams = new URLSearchParams(new FormData(form)).toString();
            window.open('bulk-consumption-slips.php?' + urlParams, '_blank');
        }

        // --- UPGRADED CANVAS-TO-EXCEL SNAPSHOT LOGIC ---
        function exportToExcel(divId, filename) {
            let originalReport = document.getElementById(divId);
            
            // Step 1: Create a perfect clone of the report so we don't break the live UI
            let reportClone = originalReport.cloneNode(true);
            
            // ---------------------------------------------------------------------------------
            // EXACT DATA REPLACEMENT LOGIC FOR EXCEL
            // ---------------------------------------------------------------------------------

            const showTenantBar = document.getElementById('show_tenant_bar').checked;
            const showElecPie = document.getElementById('show_elec_pie').checked;
            const showWaterPie = document.getElementById('show_water_pie').checked;
            const showDailyFin = document.getElementById('show_daily_fin').checked;
            const showGraph = document.getElementById('show_graph').checked;
            const showWaterGraph = document.getElementById('show_water_graph').checked;

            // 1. Tenant Bar Chart logic
            let tenantBarWrapper = reportClone.querySelector('#tenantBarCollapse');
            if (tenantBarWrapper) {
                if (!showTenantBar) {
                    tenantBarWrapper.parentNode.remove();
                } else {
                    let canvas = tenantBarWrapper.querySelector('#tenantBarChart');
                    if (canvas && canvas.hasAttribute('data-chart')) {
                        let data = JSON.parse(canvas.getAttribute('data-chart'));
                        let tableHtml = '<br><h3>Tenant Billing Breakdown Data (Insert Chart Here)</h3><table border="1"><thead><tr><th style="background-color:#f2f2f2;">Tenant & Shop</th><th style="background-color:#f2f2f2;text-align:right;">Total Billed (R)</th></tr></thead><tbody>';
                        for (let i = 0; i < data.labels.length; i++) {
                            tableHtml += `<tr><td>${data.labels[i]}</td><td style="text-align:right; mso-number-format:\'0.00\';">${data.values[i]}</td></tr>`;
                        }
                        tableHtml += '</tbody></table>';
                        canvas.parentNode.innerHTML = tableHtml;
                    }
                }
            }

            // 2. Electricity Pie Chart logic
            let elecPieWrapper = reportClone.querySelector('#elecPieCollapse');
            if (elecPieWrapper) {
                if (!showElecPie) {
                    elecPieWrapper.parentNode.remove();
                } else {
                    let canvas = elecPieWrapper.querySelector('#elecPieChart');
                    if (canvas && canvas.hasAttribute('data-chart')) {
                        let data = JSON.parse(canvas.getAttribute('data-chart'));
                        let tableHtml = '<br><h3>Electricity Recovery Split Data (Insert Chart Here)</h3><table border="1"><thead><tr><th style="background-color:#f2f2f2;">Category</th><th style="background-color:#f2f2f2;text-align:right;">Amount (R)</th></tr></thead><tbody>';
                        for (let i = 0; i < data.labels.length; i++) {
                            tableHtml += `<tr><td>${data.labels[i]}</td><td style="text-align:right; mso-number-format:\'0.00\';">${data.values[i]}</td></tr>`;
                        }
                        tableHtml += '</tbody></table>';
                        canvas.parentNode.innerHTML = tableHtml;
                    }
                }
            }

            // 3. Water Pie Chart logic
            let waterPieWrapper = reportClone.querySelector('#waterPieCollapse');
            if (waterPieWrapper) {
                if (!showWaterPie) {
                    waterPieWrapper.parentNode.remove();
                } else {
                    let canvas = waterPieWrapper.querySelector('#waterPieChart');
                    if (canvas && canvas.hasAttribute('data-chart')) {
                        let data = JSON.parse(canvas.getAttribute('data-chart'));
                        let tableHtml = '<br><h3>Water & Sewer Recovery Split Data (Insert Chart Here)</h3><table border="1"><thead><tr><th style="background-color:#f2f2f2;">Category</th><th style="background-color:#f2f2f2;text-align:right;">Amount (R)</th></tr></thead><tbody>';
                        for (let i = 0; i < data.labels.length; i++) {
                            tableHtml += `<tr><td>${data.labels[i]}</td><td style="text-align:right; mso-number-format:\'0.00\';">${data.values[i]}</td></tr>`;
                        }
                        tableHtml += '</tbody></table>';
                        canvas.parentNode.innerHTML = tableHtml;
                    }
                }
            }

            // 4. Daily Financial Spend Graph logic
            let dailyFinWrapper = reportClone.querySelector('#dailyFinCollapse');
            if (dailyFinWrapper) {
                if (!showDailyFin) {
                    dailyFinWrapper.parentNode.remove();
                } else {
                    let canvas = dailyFinWrapper.querySelector('#dailyFinChart');
                    if (canvas && canvas.hasAttribute('data-chart')) {
                        let data = JSON.parse(canvas.getAttribute('data-chart'));
                        let tableHtml = '<br><h3>Daily Financial Spend Data (Insert Chart Here)</h3><table border="1"><thead><tr><th style="background-color:#f2f2f2;">Date</th><th style="background-color:#f2f2f2;text-align:right;">Electricity (R)</th><th style="background-color:#f2f2f2;text-align:right;">Water & Sewer (R)</th><th style="background-color:#f2f2f2;text-align:right;">Combined Total (R)</th></tr></thead><tbody>';
                        for (let i = 0; i < data.labels.length; i++) {
                            tableHtml += `<tr><td>${data.labels[i]}</td><td style="text-align:right; mso-number-format:\'0.00\';">${data.elec[i]}</td><td style="text-align:right; mso-number-format:\'0.00\';">${data.water[i]}</td><td style="text-align:right; mso-number-format:\'0.00\';">${data.total[i]}</td></tr>`;
                        }
                        tableHtml += '</tbody></table>';
                        canvas.parentNode.innerHTML = tableHtml;
                    }
                }
            }

            // Legacy Slip Graphs
            let legacyElecContainer = reportClone.querySelector('#legacyElecContainer');
            if (legacyElecContainer) {
                if (!showGraph) {
                    legacyElecContainer.remove();
                } else {
                    let canvas = legacyElecContainer.querySelector('.elec-usage-chart');
                    if (canvas && canvas.hasAttribute('data-chart')) {
                        let data = JSON.parse(canvas.getAttribute('data-chart'));
                        let tableHtml = '<br><h3>Daily Electrical Consumption Data (Insert Chart Here)</h3><table border="1"><thead><tr><th style="background-color:#f2f2f2;">Date</th>';
                        
                        if (data.isTOU) {
                            tableHtml += '<th style="background-color:#f2f2f2;text-align:right;">Peak (kWh)</th><th style="background-color:#f2f2f2;text-align:right;">Standard (kWh)</th><th style="background-color:#f2f2f2;text-align:right;">Off-Peak (kWh)</th><th style="background-color:#f2f2f2;text-align:right;">Total (kWh)</th></tr></thead><tbody>';
                            for (let i = 0; i < data.labels.length; i++) {
                                tableHtml += `<tr><td>${data.labels[i]}</td><td style="text-align:right; mso-number-format:\'0.00\';">${data.peak[i]}</td><td style="text-align:right; mso-number-format:\'0.00\';">${data.std[i]}</td><td style="text-align:right; mso-number-format:\'0.00\';">${data.off[i]}</td><td style="text-align:right; mso-number-format:\'0.00\';">${data.total[i]}</td></tr>`;
                            }
                        } else {
                            tableHtml += '<th style="background-color:#f2f2f2;text-align:right;">Total (kWh)</th></tr></thead><tbody>';
                            for (let i = 0; i < data.labels.length; i++) {
                                tableHtml += `<tr><td>${data.labels[i]}</td><td style="text-align:right; mso-number-format:\'0.00\';">${data.total[i]}</td></tr>`;
                            }
                        }
                        tableHtml += '</tbody></table>';
                        canvas.parentNode.innerHTML = tableHtml;
                    }
                }
            }

            let legacyWaterContainer = reportClone.querySelector('#legacyWaterContainer');
            if (legacyWaterContainer) {
                if (!showWaterGraph) {
                    legacyWaterContainer.remove();
                } else {
                    let canvas = legacyWaterContainer.querySelector('.water-usage-chart');
                    if (canvas && canvas.hasAttribute('data-chart')) {
                        let data = JSON.parse(canvas.getAttribute('data-chart'));
                        let tableHtml = '<br><h3>Daily Water Consumption Data (Insert Chart Here)</h3><table border="1"><thead><tr><th style="background-color:#f2f2f2;">Date</th><th style="background-color:#f2f2f2;text-align:right;">Total (kL)</th></tr></thead><tbody>';
                        for (let i = 0; i < data.labels.length; i++) {
                            tableHtml += `<tr><td>${data.labels[i]}</td><td style="text-align:right; mso-number-format:\'0.00\';">${data.total[i]}</td></tr>`;
                        }
                        tableHtml += '</tbody></table>';
                        canvas.parentNode.innerHTML = tableHtml;
                    }
                }
            }

            // Step 2: Force border attribute on ALL tables existing in the clone to ensure Excel renders rigid black gridlines.
            let allTables = reportClone.querySelectorAll('table');
            allTables.forEach(t => {
                t.setAttribute('border', '1');
                t.style.border = '1px solid black';
                t.style.borderCollapse = 'collapse';
            });

            // Remove internal UI toggles from the final clean export sheet
            let toggleIcons = reportClone.querySelectorAll('.print-hide-toggle');
            toggleIcons.forEach(icon => icon.remove());
            
            // Step 3: Package the modified HTML into the Excel MIME standard WITH GRIDLINES
            let reportHtml = reportClone.innerHTML;
            let template = `
            <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
            <head>
                <meta charset="UTF-8">
                <!--[if gte mso 9]>
                <xml>
                    <x:ExcelWorkbook>
                        <x:ExcelWorksheets>
                            <x:ExcelWorksheet>
                                <x:Name>Financial Report</x:Name>
                                <x:WorksheetOptions>
                                    <x:DisplayGridlines/>
                                </x:WorksheetOptions>
                            </x:ExcelWorksheet>
                        </x:ExcelWorksheets>
                    </x:ExcelWorkbook>
                </xml>
                <![endif]-->
                <style>
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; font-family: sans-serif; }
        th, td { border: 1px solid #000000; padding: 6px; text-align: left; vertical-align: middle; }
        th { background-color: #f2f2f2; font-weight: bold; }
        .text-end { text-align: right; }
        .text-center { text-align: center; }
        h2, h3, h4, h5, h6 { font-family: sans-serif; margin-bottom: 10px; color: #000000; }
    </style>
            </head>
            <body>${reportHtml}</body>
            </html>`;
            
            let blob = new Blob([template], { type: 'application/vnd.ms-excel' });
            let url = URL.createObjectURL(blob);
            let a = document.createElement('a');
            a.href = url;
            a.download = filename + '.xls';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }

        function exportMRI() {
            const mriData = <?php echo json_encode($mri_data ?? ['EL00'=>[], 'GE00'=>[], 'WT00'=>[], 'SE00'=>[], 'RF00'=>[]]); ?>;
            const propertyName = "<?php echo preg_replace('/[^a-zA-Z0-9]/', '_', $selected_property); ?>";
            const reportMonth = "<?php echo date('M_Y', strtotime(empty($end_date_2) ? $end_date : $end_date_2)); ?>";

            let delay = 0;
            let filesGenerated = 0;
            
            for (const [service, rows] of Object.entries(mriData)) {
                if (rows.length === 0) continue;
                
                filesGenerated++;
                setTimeout(() => {
                    let csvContent = rows.join("\r\n");
                    let blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                    let url = URL.createObjectURL(blob);
                    let a = document.createElement('a');
                    a.href = url;
                    a.download = `MDA_${service}_${propertyName}_${reportMonth}.csv`;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                }, delay);
                
                delay += 500; 
            }
            
            if (filesGenerated === 0) {
                alert("No utility data available to export for this property.");
            }
        }
    </script>
</body>
</html>