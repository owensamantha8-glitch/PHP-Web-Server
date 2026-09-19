<?php
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
?>
<div id="loadingOverlay">
    <div class="spinner-border text-light mb-3" style="width: 3rem; height: 3rem;" role="status"></div>
    <h4 id="loadingText" class="fw-semibold">Generating PDFs... 0 / <?php echo count($tenants); ?></h4>
</div>

<!-- Top Action Bar -->
<div class="d-print-none bg-dark text-white p-3 d-flex justify-content-between align-items-center mb-4 shadow">
    <div>
        <h5 class="mb-0"><i class="bi bi-files me-2"></i>Bulk Print Slips: <?php echo htmlspecialchars($selected_property); ?></h5>
        <small class="text-secondary"><?php echo count($tenants); ?> tenant slips ready.</small>
    </div>
    <div>
        <button type="button" onclick="window.close()" class="btn btn-outline-light btn-sm me-2">Close</button>
        <button type="button" onclick="window.print()" class="btn btn-primary btn-sm fw-bold me-2"><i class="bi bi-printer me-1"></i> Print All Slips</button>
        <button type="button" onclick="generateZip()" class="btn btn-success btn-sm fw-bold"><i class="bi bi-folder-symlink me-1"></i> Export PDF Folder</button>
    </div>
</div>