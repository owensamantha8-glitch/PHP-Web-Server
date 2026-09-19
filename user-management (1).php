<?php
session_start();
if (!isset($_SESSION['user_name']) || $_SESSION['role'] !== 'Admin') {
    // Restrict to 'Admin' role
    header("Location: https://lynx-um.co.za/index.php");
    exit();
}

require_once __DIR__ . '/db-conn-login.php';

$message = "";
$edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
$show_form = false;
$form_mode = '';

// Handle GET Messages for clean redirects
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'added') $message = "<div class='alert alert-success'>User registered successfully.</div>";
    if ($_GET['msg'] === 'updated') $message = "<div class='alert alert-success'>User profile updated successfully.</div>";
    if ($_GET['msg'] === 'deleted') $message = "<div class='alert alert-success'>User successfully deleted.</div>";
}

// Check URL triggers to display the right-side form
if (isset($_GET['action']) && $_GET['action'] === 'new') {
    $show_form = true;
    $form_mode = 'add';
}

if ($edit_id > 0) {
    $show_form = true;
    $form_mode = 'edit';
}

// ---------------------------------------------------------
// POST HANDLERS (Delete, Add, Edit)
// ---------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    
    // ACTION: DELETE USER
    if ($_POST['action'] === 'delete' && isset($_POST['delete_user_id'])) {
        $del_id = intval($_POST['delete_user_id']);
        if ($del_id === $_SESSION['user_id']) {
            $message = "<div class='alert alert-danger'>You cannot delete your own active account.</div>";
        } else {
            $stmt = $login_db_conn->prepare("DELETE FROM lum_sys_users WHERE user_id = ?");
            $stmt->bind_param("i", $del_id);
            if ($stmt->execute()) {
                header("Location: user-management.php?msg=deleted");
                exit();
            } else {
                $message = "<div class='alert alert-danger'>Error deleting user: " . $login_db_conn->error . "</div>";
            }
            $stmt->close();
        }
    }

    // ACTION: ADD USER
    if ($_POST['action'] === 'add') {
        $name = trim($_POST['user_name']);
        $email = trim($_POST['user_email']);
        $password = $_POST['user_password'];
        $role = $_POST['user_role'];
        $status = $_POST['user_status'] ?? 'Active';
        $assigned_properties = isset($_POST['assigned_properties']) ? implode(',', $_POST['assigned_properties']) : null;

        $stmt_check = $login_db_conn->prepare("SELECT user_id FROM lum_sys_users WHERE user_name = ? OR user_email = ?");
        $stmt_check->bind_param("ss", $name, $email);
        $stmt_check->execute();
        $stmt_check->store_result();

        if ($stmt_check->num_rows > 0) {
            $message = "<div class='alert alert-danger'>A user with that Username or Email already exists.</div>";
            $show_form = true;
            $form_mode = 'add';
        } elseif (strlen($password) < 8) {
            $message = "<div class='alert alert-warning'>Password must be at least 8 characters long.</div>";
            $show_form = true;
            $form_mode = 'add';
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $login_db_conn->prepare("INSERT INTO lum_sys_users (user_name, user_email, user_password, user_role, user_status, assigned_properties) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $name, $email, $hashed_password, $role, $status, $assigned_properties);
            
            if ($stmt->execute()) {
                header("Location: user-management.php?msg=added");
                exit();
            } else {
                $message = "<div class='alert alert-danger'>Database Error: " . $login_db_conn->error . "</div>";
                $show_form = true;
                $form_mode = 'add';
            }
            $stmt->close();
        }
        $stmt_check->close();
    }

    // ACTION: EDIT USER
    if ($_POST['action'] === 'edit' && isset($_POST['edit_id'])) {
        $edit_target = intval($_POST['edit_id']);
        $name = trim($_POST['user_name']);
        $email = trim($_POST['user_email']);
        $role = $_POST['user_role'];
        $status = $_POST['user_status'];
        $new_password = $_POST['user_password'];
        $assigned_properties = isset($_POST['assigned_properties']) ? implode(',', $_POST['assigned_properties']) : null;

        $stmt_check = $login_db_conn->prepare("SELECT user_id FROM lum_sys_users WHERE user_email = ? AND user_id != ?");
        $stmt_check->bind_param("si", $email, $edit_target);
        $stmt_check->execute();
        $stmt_check->store_result();

        if ($stmt_check->num_rows > 0) {
            $message = "<div class='alert alert-danger'>That Email Address is already registered to another user.</div>";
            $show_form = true;
            $form_mode = 'edit';
            $edit_id = $edit_target;
        } else {
            if (!empty($new_password)) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $login_db_conn->prepare("UPDATE lum_sys_users SET user_name=?, user_email=?, user_password=?, user_role=?, user_status=?, assigned_properties=? WHERE user_id=?");
                $stmt->bind_param("ssssssi", $name, $email, $hashed_password, $role, $status, $assigned_properties, $edit_target);
            } else {
                $stmt = $login_db_conn->prepare("UPDATE lum_sys_users SET user_name=?, user_email=?, user_role=?, user_status=?, assigned_properties=? WHERE user_id=?");
                $stmt->bind_param("sssssi", $name, $email, $role, $status, $assigned_properties, $edit_target);
            }
            
            if ($stmt->execute()) {
                header("Location: user-management.php?msg=updated");
                exit();
            } else {
                $message = "<div class='alert alert-danger'>Database Error: " . $login_db_conn->error . "</div>";
                $show_form = true;
                $form_mode = 'edit';
                $edit_id = $edit_target;
            }
            $stmt->close();
        }
        $stmt_check->close();
    }
}

// ---------------------------------------------------------
// FETCH DATA FOR UI
// ---------------------------------------------------------
$users = [];
$result = $login_db_conn->query("SELECT user_id, user_name, user_email, user_role, user_status, last_login FROM lum_sys_users ORDER BY user_name ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
}

// Fetch Target User if Edit Mode is Active
$target_user = null;
$user_props = [];
if ($show_form && $form_mode === 'edit' && $edit_id > 0) {
    $stmt = $login_db_conn->prepare("SELECT * FROM lum_sys_users WHERE user_id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 1) {
        $target_user = $res->fetch_assoc();
        $user_props = !empty($target_user['assigned_properties']) ? explode(',', $target_user['assigned_properties']) : [];
    } else {
        $show_form = false; 
    }
    $stmt->close();
}

$all_properties = ['DHL', 'DHL Hatfield', 'Greystone Crossing', 'Groenkloof Chambers', 'Lambton Gardens', "Linton's Corner", 'Lynnwood Lane', 'N2 Woodhill', 'Thatchfield Centre', 'Thatchfield Retail', 'The Marketsquare'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Lynx Utilities</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        html { scroll-behavior: smooth; }
        body { background-color: #121212; color: #ffffff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .lynx-brand-hover:hover { color: #e3000f !important; }
        .lynx-logout { color: #e3000f; }
        .lynx-logout:hover { color: #bf000c; }
        
        .admin-list-container { 
            background-color: #1e1e1e; 
            border-top: 6px solid #e3000f; 
            border-radius: 8px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.5); 
        }
        .admin-form-container { 
            background-color: #ffffff; 
            border-radius: 8px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.1); 
            color: #212529; 
        }
    </style>
</head>
<body>

    <nav class="navbar navbar-dark py-1 border-bottom d-print-none" style="background-color: #000000; border-color: #333333 !important;">
        <div class="container-fluid px-3">
            <a class="navbar-brand fs-6 mb-0 text-white lynx-brand-hover transition-colors" href="https://lynx-um.co.za/index.php">
                Lynx Utility Management
            </a>
            <div class="d-flex align-items-center">
                <div class="text-secondary small d-flex align-items-center">
                    <i class="bi bi-person-fill text-white me-2"></i>
                    <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?>
                </div>
                <a href="https://lynx-um.co.za/Sec/logout.php" class="text-decoration-none small ms-3 ps-3 border-start lynx-logout transition-colors" style="border-color: #333333 !important;">
                    Logout
                </a>
            </div>
        </div>
    </nav>

    <!-- Back Button -->
    <div class="container-fluid px-4 pt-4 pb-0">
        <a href="https://lynx-um.co.za/index.php" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-2"></i>Back to Dashboard</a>
    </div>

    <!-- Main Grid Layout -->
    <div class="container-fluid px-4 pt-3 pb-5">
        <div class="row g-4 justify-content-center">
            
            <!-- Left Column: User Table -->
            <div class="<?php echo $show_form ? 'col-xl-8 col-lg-7' : 'col-xl-10 col-lg-12'; ?>">
                <div class="admin-list-container p-4 p-md-5 h-100 m-0 w-100">
                    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-secondary pb-3">
                        <div>
                            <h2 class="fw-bold text-white mb-1">System Users</h2>
                            <p class="text-muted mb-0">Manage access and permissions to the portal.</p>
                        </div>
                        <div>
                            <a href="user-management.php?action=new" class="btn btn-danger fw-bold shadow-sm">
                                <i class="bi bi-person-plus-fill me-2"></i>Register New User
                            </a>
                        </div>
                    </div>

                    <?php echo $message; ?>

                    <div class="table-responsive">
                        <table class="table table-dark table-striped table-hover align-middle border-secondary mb-0">
                            <thead class="table-active">
                                <tr>
                                    <th># ID</th>
                                    <th>Username</th>
                                    <th>Email Address</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Last Login</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($users)): ?>
                                    <tr><td colspan="7" class="text-center text-muted py-4">No users found.</td></tr>
                                <?php else: foreach($users as $u): ?>
                                    <tr>
                                        <td class="fw-bold text-secondary"><?php echo $u['user_id']; ?></td>
                                        <td class="fw-bold text-white"><?php echo htmlspecialchars($u['user_name']); ?></td>
                                        <td><a href="mailto:<?php echo htmlspecialchars($u['user_email']); ?>" class="text-decoration-none text-info"><?php echo htmlspecialchars($u['user_email']); ?></a></td>
                                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($u['user_role']); ?></span></td>
                                        <td>
                                            <?php if(isset($u['user_status']) && $u['user_status'] === 'Disabled'): ?>
                                                <span class="badge bg-danger">Disabled</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($u['last_login'])): ?>
                                                <span class="text-white"><?php echo date('d M Y, H:i', strtotime($u['last_login'])); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted fst-italic">Never</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <a href="user-management.php?edit=<?php echo $u['user_id']; ?>" class="btn btn-sm btn-outline-primary me-2"><i class="bi bi-pencil-square"></i> Edit</a>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to permanently delete this user?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="delete_user_id" value="<?php echo $u['user_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" <?php echo ($u['user_id'] == $_SESSION['user_id']) ? 'disabled' : ''; ?>><i class="bi bi-trash3-fill"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Right Column: Dynamic Form (Only visible when triggered) -->
            <?php if ($show_form): ?>
            <div class="col-xl-4 col-lg-5">
                
                <?php if ($form_mode === 'edit' && $target_user): ?>
                    <!-- EDIT USER FORM -->
                    <div class="admin-form-container p-4 p-md-5 w-100 m-0" style="border-top: 6px solid #000000;">
                        <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-3">
                            <h4 class="fw-bold text-dark mb-0">Edit User Profile</h4>
                            <a href="user-management.php" class="btn btn-sm btn-outline-secondary">Close</a>
                        </div>

                        <form method="POST" action="user-management.php">
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="edit_id" value="<?php echo $edit_id; ?>">

                            <div class="mb-3">
                                <label class="form-label fw-semibold text-dark">Username</label>
                                <input type="text" class="form-control bg-light" name="user_name" required value="<?php echo htmlspecialchars($target_user['user_name']); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-dark">Email Address</label>
                                <input type="email" class="form-control bg-light" name="user_email" required value="<?php echo htmlspecialchars($target_user['user_email']); ?>">
                            </div>
                            <div class="mb-3 p-3 bg-light border rounded">
                                <label class="form-label fw-semibold text-danger"><i class="bi bi-key-fill"></i> Reset Password (Optional)</label>
                                <input type="password" class="form-control" name="user_password" minlength="8" placeholder="Leave blank to keep current password">
                                <small class="text-muted d-block mt-1">If entering a new password, it must be at least 8 characters.</small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-dark">System Role</label>
                                <select class="form-select" name="user_role" required>
                                    <option value="Standard" <?php echo ($target_user['user_role'] === 'Standard') ? 'selected' : ''; ?>>Standard User</option>
                                    <option value="Admin" <?php echo ($target_user['user_role'] === 'Admin') ? 'selected' : ''; ?>>Administrator</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-dark">Account Status</label>
                                <select class="form-select" name="user_status" required <?php echo ($target_user['user_id'] == $_SESSION['user_id']) ? 'disabled' : ''; ?>>
                                    <option value="Active" <?php echo (isset($target_user['user_status']) && $target_user['user_status'] === 'Active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="Disabled" <?php echo (isset($target_user['user_status']) && $target_user['user_status'] === 'Disabled') ? 'selected' : ''; ?>>Disabled</option>
                                </select>
                                <?php if ($target_user['user_id'] == $_SESSION['user_id']): ?>
                                    <input type="hidden" name="user_status" value="Active">
                                    <small class="text-muted">You cannot disable your own active session.</small>
                                <?php endif; ?>
                            </div>

                            <div class="mb-4 p-3 bg-light border rounded">
                                <label class="form-label fw-semibold text-primary"><i class="bi bi-building"></i> Property Access</label>
                                <p class="small text-muted mb-2">Select the specific properties this user is allowed to view. Leave all unchecked for global access.</p>
                                <div class="row">
                                    <?php
                                    foreach($all_properties as $prop) {
                                        $id = md5($prop);
                                        $checked = in_array($prop, $user_props) ? 'checked' : '';
                                        echo '<div class="col-sm-6 mb-1"><div class="form-check">';
                                        echo '<input class="form-check-input" type="checkbox" name="assigned_properties[]" value="'.htmlspecialchars($prop).'" id="edit_prop_'.$id.'" '.$checked.'>';
                                        echo '<label class="form-check-label small text-dark" for="edit_prop_'.$id.'">'.htmlspecialchars($prop).'</label>';
                                        echo '</div></div>';
                                    }
                                    ?>
                                </div>
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-dark btn-lg fw-bold shadow-sm">Update Account</button>
                            </div>
                        </form>
                    </div>

                <?php elseif ($form_mode === 'add'): ?>
                    <!-- REGISTER NEW USER FORM -->
                    <div class="admin-form-container p-4 p-md-5 w-100 m-0" style="border-top: 6px solid #e3000f;">
                        <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-3">
                            <h4 class="fw-bold text-dark mb-0">Register New User</h4>
                            <a href="user-management.php" class="btn btn-sm btn-outline-secondary">Close</a>
                        </div>

                        <form method="POST" action="user-management.php">
                            <input type="hidden" name="action" value="add">
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-dark">Username</label>
                                <input type="text" class="form-control bg-light" name="user_name" required placeholder="johndoe" value="<?php echo isset($_POST['user_name']) ? htmlspecialchars($_POST['user_name']) : ''; ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-dark">Email Address</label>
                                <input type="email" class="form-control bg-light" name="user_email" required placeholder="john@lynx-re.co.za" value="<?php echo isset($_POST['user_email']) ? htmlspecialchars($_POST['user_email']) : ''; ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-dark">Secure Password</label>
                                <input type="password" class="form-control bg-light" name="user_password" required minlength="8" placeholder="Must be at least 8 characters">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-dark">System Role</label>
                                <select class="form-select bg-light" name="user_role" required>
                                    <option value="Standard">Standard User</option>
                                    <option value="Admin">Administrator</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-dark">Account Status</label>
                                <select class="form-select bg-light" name="user_status" required>
                                    <option value="Active">Active (Can Login)</option>
                                    <option value="Disabled">Disabled (No Access)</option>
                                </select>
                            </div>

                            <div class="mb-4 p-3 bg-light border rounded">
                                <label class="form-label fw-semibold text-primary"><i class="bi bi-building"></i> Property Access</label>
                                <p class="small text-muted mb-2">Select the specific properties this user is allowed to view. Leave all unchecked for global access.</p>
                                <div class="row">
                                    <?php
                                    foreach($all_properties as $prop) {
                                        $id = md5($prop);
                                        echo '<div class="col-sm-6 mb-1"><div class="form-check">';
                                        echo '<input class="form-check-input" type="checkbox" name="assigned_properties[]" value="'.htmlspecialchars($prop).'" id="add_prop_'.$id.'">';
                                        echo '<label class="form-check-label small text-dark" for="add_prop_'.$id.'">'.htmlspecialchars($prop).'</label>';
                                        echo '</div></div>';
                                    }
                                    ?>
                                </div>
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-danger btn-lg fw-bold shadow-sm">Create Account</button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

            </div>
            <?php endif; ?>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>