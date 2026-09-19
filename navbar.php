<style>
    /* Custom hover effects for Lynx brand colors */
    .lynx-brand-hover:hover { color: #e3000f !important; }
    .lynx-logout { color: #e3000f; }
    .lynx-logout:hover { color: #bf000c; }
</style>

<!-- Bootstrap 5 Navbar -->
<nav class="navbar navbar-dark py-1 border-bottom" style="background-color: #000000; border-color: #333333 !important;">
    <div class="container-fluid px-3">
        
        <!-- Left Side: Company Name -->
        <a class="navbar-brand fs-6 mb-0 text-white lynx-brand-hover transition-colors" href="index.php" style="transition: color 0.2s ease-in-out;">
            Lynx Utility Management
        </a>

        <!-- Right Side: User & Logout -->
        <div class="d-flex align-items-center">
            
            <!-- Username -->
            <div class="text-secondary small d-flex align-items-center">
                <i class="bi bi-person-fill text-white me-2"></i>
                <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?>
            </div>
            
            <!-- Logout Link with Divider -->
            <a href="logout.php" class="text-decoration-none small ms-3 ps-3 border-start lynx-logout transition-colors" style="border-color: #333333 !important; transition: color 0.2s ease-in-out;">
                Logout
            </a>
            
        </div>
    </div>
</nav>