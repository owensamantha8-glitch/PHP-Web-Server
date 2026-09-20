<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once __DIR__ . '/bootstrap.php';
lum_page('imports', 'view');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>
    <?php include LUM_ROOT . '/Layout/foot-assets.php'; ?>
    <title>Import CSV Files - Lynx Utility Management</title>
    <style>
        /* Matches your structural layout rules from tenant overview files */
        .lynx-brand-hover:hover { color: #e3000f !important; }
        .action-bar { background-color: #0a0a0a; border-bottom: 1px solid #333333; padding: 15px 0; }
    </style>
</head>

<body style="background-color: #A9A9A9; width: 100%;">

    <!-- Top Universal System Navbar Context -->
    <?php
    $lum_nav_left = '<button class="btn btn-link text-white p-0 me-3 lynx-brand-hover" id="sidebarToggle" title="Toggle Sidebar"> <i class="bi bi-list fs-4"></i> </button>';
    include LUM_ROOT . '/Layout/top-navbar.php';
    ?>

    <!-- Sub-Category Action Header -->
    <div class="action-bar mb-4">
        <div class="container-fluid px-4 d-flex justify-content-between align-items-center">
            <h4 class="mb-0 text-white">Import</h4>
            <a class="btn btn-brand btn-sm px-2 shadow-sm" href="../index.php">
                <i class="bi bi-arrow-left me-2"></i>Back Dashboard
            </a>
        </div>
    </div>

    <!-- Inject the Single Universal Component Here -->
    <div class="container mt-2">
        <?php include LUM_ROOT . "/Import/universal-import.php"; ?>
    </div>

</body>
</html>