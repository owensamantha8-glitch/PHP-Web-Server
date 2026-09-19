<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once __DIR__ . '/bootstrap.php';
lum_page('users', 'view');

$login_db_conn = lum_db_mysqli_users(true);
// Audit trail (switches itself off if the logger is missing)
lum_use('audit');

$message = "";
$edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;

// ---------------------------------------------------------
// PROPERTIES: the property register (lum_properties)
// ---------------------------------------------------------
function lumUmPropertyList() {
    $list = [];
    // Properties come from the property register only (Configurations)
    $pdo = lum_db('properties');
    if ($pdo) {
        try {
            $list = $pdo->query("SELECT Property FROM lum_properties")->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            error_log('LUM user-management: properties not read: ' . $e->getMessage());
        }
    }
    $list = array_values(array_unique(array_filter(array_map('trim', $list), 'strlen')));
    sort($list, SORT_NATURAL | SORT_FLAG_CASE);
    return $list;
}
$all_properties = lumUmPropertyList();

// Submitted property access: only known properties (and those the user already had); null = all properties
function lumUmAssignedFromPost(array $known, array $keep = []) {
    if (!isset($_POST['assigned_properties']) || !is_array($_POST['assigned_properties'])) return null;
    $allowed = array_merge($known, $keep);
    $out = [];
    foreach ($_POST['assigned_properties'] as $p) {
        $p = trim((string)$p);
        if ($p !== '' && strpos($p, ',') === false && in_array($p, $allowed, true) && !in_array($p, $out, true)) $out[] = $p;
    }
    return $out ? implode(',', $out) : null;
}

// ---------------------------------------------------------
// ROLES & PAGE ACCESS DATA (tables from access-control-setup.sql)
// ---------------------------------------------------------
$access_db = lum_guard_db();          // PDO connection to sys_db_users (from auth-guard.php)
$access_ready = false;                // false when the access tables do not exist yet
$access_pages = [];                   // [ ['page_key','page_name','page_group','description'], ... ]
$role_rules = [];                     // role => page_key => row
$access_levels = ['view' => 'View', 'edit' => 'Edit', 'delete' => 'Delete'];

function lum_load_role_rules($pdo) {
    $rules = [];
    foreach ($pdo->query("SELECT role, page_key, can_view, can_edit, can_delete FROM lum_sys_role_access ORDER BY role, page_key") as $r) {
        $rules[$r['role']][$r['page_key']] = $r;
    }
    return $rules;
}

function lum_load_user_rules($pdo, $user_id) {
    $rules = [];
    $st = $pdo->prepare("SELECT page_key, can_view, can_edit, can_delete FROM lum_sys_users_access WHERE user_id = ?");
    $st->execute([(int)$user_id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rules[$r['page_key']] = $r;
    }
    return $rules;
}

// Flat form of a rule set for the audit trail: "tariffs.edit" => 1 / 0 / null (null = follow the role)
function lum_access_flatten($rules) {
    $out = [];
    foreach ($rules as $page_key => $r) {
        foreach (['view', 'edit', 'delete'] as $lvl) {
            $v = $r['can_' . $lvl] ?? null;
            $out[$page_key . '.' . $lvl] = ($v === null) ? null : (int)$v;
        }
    }
    ksort($out);
    return $out;
}

if ($access_db) {
    try {
        $access_pages = $access_db->query("SELECT page_key, page_name, page_group, description FROM lum_sys_pages ORDER BY page_group, page_name")->fetchAll(PDO::FETCH_ASSOC);
        $role_rules = lum_load_role_rules($access_db);
        $access_ready = true;
    } catch (\Throwable $e) {
        error_log('LUM user-management: access tables not available: ' . $e->getMessage());
    }
}

// Every role that exists: Admin, roles with rules, and roles still assigned to users
$user_roles_in_use = [];
$role_user_counts = [];
$res_roles = $login_db_conn->query("SELECT user_role, COUNT(*) AS n FROM lum_sys_users GROUP BY user_role");
if ($res_roles) {
    while ($r = $res_roles->fetch_assoc()) {
        $user_roles_in_use[] = $r['user_role'];
        $role_user_counts[$r['user_role']] = (int)$r['n'];
    }
}
$available_roles = array_values(array_unique(array_merge(['Standard'], array_keys($role_rules), $user_roles_in_use)));
$available_roles = array_values(array_diff($available_roles, ['Admin', '']));
sort($available_roles);
array_unshift($available_roles, 'Admin');

$active_tab = (lum_is_admin() && ($_GET['tab'] ?? '') === 'roles') ? 'roles' : 'users';
$selected_role = (string)($_GET['role'] ?? 'Standard');
if (!in_array($selected_role, $available_roles, true) || $selected_role === 'Admin') {
    $selected_role = 'Standard';
}

// ---------------------------------------------------------
// POST HANDLERS: ROLES & PAGE ACCESS (administrators only)
// ---------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['access_action'])) {
    lum_require_csrf();
    lum_require_admin(); // Changing permissions is never delegated: it would allow self-promotion

    $act = (string)$_POST['access_action'];
    $page_keys = array_column($access_pages, 'page_key');

    if (!$access_ready) {
        $message = "<div class='alert alert-danger'>The access tables were not found. Please run access-control-setup.sql first.</div>";
    }

    // --- Save the permissions of a role ---
    elseif ($act === 'save_role') {
        $role = (string)($_POST['role'] ?? '');
        if ($role === 'Admin' || !in_array($role, $available_roles, true)) {
            lum_deny('Unknown role.');
        }
        $before = lum_access_flatten($role_rules[$role] ?? []);
        try {
            $access_db->beginTransaction();
            $st = $access_db->prepare("INSERT INTO lum_sys_role_access (role, page_key, can_view, can_edit, can_delete) VALUES (?, ?, ?, ?, ?)
                                       ON DUPLICATE KEY UPDATE can_view = VALUES(can_view), can_edit = VALUES(can_edit), can_delete = VALUES(can_delete)");
            foreach ($page_keys as $pk) {
                $v = isset($_POST['perm'][$pk]['view']) ? 1 : 0;
                $e = isset($_POST['perm'][$pk]['edit']) ? 1 : 0;
                $d = isset($_POST['perm'][$pk]['delete']) ? 1 : 0;
                if ($e || $d) $v = 1; // Editing or deleting always includes viewing
                $st->execute([$role, $pk, $v, $e, $d]);
            }
            $access_db->commit();
            $after = lum_access_flatten(lum_load_role_rules($access_db)[$role] ?? []);
            lum_audit_log('UPDATE', 'role_access', 0, $role, null, $before, $after);
            header("Location: user-management.php?tab=roles&role=" . urlencode($role) . "&msg=role_saved");
            exit();
        } catch (\Throwable $e) {
            if ($access_db->inTransaction()) $access_db->rollBack();
            error_log('LUM role save failed: ' . $e->getMessage());
            $message = "<div class='alert alert-danger'>The role could not be saved. Please try again.</div>";
        }
    }

    // --- Create a role (optionally copying another role's permissions) ---
    elseif ($act === 'add_role') {
        $role = trim((string)($_POST['new_role'] ?? ''));
        $copy_from = (string)($_POST['copy_from'] ?? '');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 _-]{1,49}$/', $role)) {
            $message = "<div class='alert alert-warning'>Role names must be 2 to 50 characters: letters, numbers, spaces, - and _.</div>";
            $active_tab = 'roles';
        } elseif (in_array(strtolower($role), array_map('strtolower', $available_roles), true)) {
            $message = "<div class='alert alert-warning'>A role with that name already exists.</div>";
            $active_tab = 'roles';
        } else {
            $source = ($copy_from !== '' && isset($role_rules[$copy_from])) ? $role_rules[$copy_from] : [];
            try {
                $access_db->beginTransaction();
                $st = $access_db->prepare("INSERT INTO lum_sys_role_access (role, page_key, can_view, can_edit, can_delete) VALUES (?, ?, ?, ?, ?)");
                foreach ($page_keys as $pk) {
                    $src = $source[$pk] ?? null;
                    $st->execute([$role, $pk, (int)($src['can_view'] ?? 0), (int)($src['can_edit'] ?? 0), (int)($src['can_delete'] ?? 0)]);
                }
                $access_db->commit();
                $after = lum_access_flatten(lum_load_role_rules($access_db)[$role] ?? []);
                lum_audit_log('INSERT', 'role_access', 0, $role, null, null, $after, $copy_from !== '' ? 'Copied from ' . $copy_from : null);
                header("Location: user-management.php?tab=roles&role=" . urlencode($role) . "&msg=role_added");
                exit();
            } catch (\Throwable $e) {
                if ($access_db->inTransaction()) $access_db->rollBack();
                error_log('LUM role create failed: ' . $e->getMessage());
                $message = "<div class='alert alert-danger'>The role could not be created. Please try again.</div>";
                $active_tab = 'roles';
            }
        }
    }

    // --- Delete a role (only when no user has it; Standard is kept as the default role) ---
    elseif ($act === 'delete_role') {
        $role = (string)($_POST['role'] ?? '');
        if (in_array($role, ['Admin', 'Standard'], true) || !isset($role_rules[$role])) {
            lum_deny('This role cannot be deleted.');
        }
        if (($role_user_counts[$role] ?? 0) > 0) {
            $message = "<div class='alert alert-warning'>The role '" . htmlspecialchars($role) . "' is still assigned to " . (int)$role_user_counts[$role] . " user(s). Move them to another role first.</div>";
            $active_tab = 'roles';
            $selected_role = $role;
        } else {
            $before = lum_access_flatten($role_rules[$role]);
            $st = $access_db->prepare("DELETE FROM lum_sys_role_access WHERE role = ?");
            $st->execute([$role]);
            lum_audit_log('DELETE', 'role_access', 0, $role, null, $before, null);
            header("Location: user-management.php?tab=roles&msg=role_deleted");
            exit();
        }
    }

    // --- Save the page access exceptions of one user ---
    elseif ($act === 'save_user_access') {
        $uid = (int)($_POST['access_user_id'] ?? 0);
        $target = $uid > 0 ? lum_audit_fetch_user($uid) : null;
        if (!$target) {
            // lum_audit_fetch_user is unavailable when the audit logger is missing: read the role directly
            $st = $access_db->prepare("SELECT user_id, user_name, user_role FROM lum_sys_users WHERE user_id = ?");
            $st->execute([$uid]);
            $target = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$target) {
            lum_deny('Unknown user.');
        }
        if (($target['user_role'] ?? '') === 'Admin') {
            $message = "<div class='alert alert-info'>Administrators always have full access; exceptions do not apply to them.</div>";
        } else {
            $before = lum_access_flatten(lum_load_user_rules($access_db, $uid));
            $tri = function ($v) { return ($v === '1') ? 1 : (($v === '0') ? 0 : null); };
            try {
                $access_db->beginTransaction();
                $up = $access_db->prepare("INSERT INTO lum_sys_users_access (user_id, page_key, can_view, can_edit, can_delete) VALUES (?, ?, ?, ?, ?)
                                           ON DUPLICATE KEY UPDATE can_view = VALUES(can_view), can_edit = VALUES(can_edit), can_delete = VALUES(can_delete)");
                $del = $access_db->prepare("DELETE FROM lum_sys_users_access WHERE user_id = ? AND page_key = ?");
                foreach ($page_keys as $pk) {
                    $v = $tri((string)($_POST['ex'][$pk]['view'] ?? ''));
                    $e = $tri((string)($_POST['ex'][$pk]['edit'] ?? ''));
                    $d = $tri((string)($_POST['ex'][$pk]['delete'] ?? ''));
                    if ($v === null && $e === null && $d === null) {
                        $del->execute([$uid, $pk]);
                    } else {
                        $up->execute([$uid, $pk, $v, $e, $d]);
                    }
                }
                $access_db->commit();
                $after = lum_access_flatten(lum_load_user_rules($access_db, $uid));
                lum_audit_log('UPDATE', 'user_access', $uid, $target['user_name'] ?? null, null, $before, $after);
                header("Location: user-management.php?edit=" . $uid . "&msg=access_saved");
                exit();
            } catch (\Throwable $e) {
                if ($access_db->inTransaction()) $access_db->rollBack();
                error_log('LUM user access save failed: ' . $e->getMessage());
                $message = "<div class='alert alert-danger'>The access exceptions could not be saved. Please try again.</div>";
            }
        }
    }
}
$show_form = false;
$form_mode = '';

// Handle GET Messages for clean redirects
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'added') $message = "<div class='alert alert-success'>User registered successfully.</div>";
    if ($_GET['msg'] === 'updated') $message = "<div class='alert alert-success'>User profile updated successfully.</div>";
    if ($_GET['msg'] === 'deleted') $message = "<div class='alert alert-success'>User successfully deleted.</div>";
    if ($_GET['msg'] === 'role_saved') $message = "<div class='alert alert-success'>Role permissions saved. They apply from the user's next page load.</div>";
    if ($_GET['msg'] === 'role_added') $message = "<div class='alert alert-success'>Role created. Set its page access below.</div>";
    if ($_GET['msg'] === 'role_deleted') $message = "<div class='alert alert-success'>Role deleted.</div>";
    if ($_GET['msg'] === 'access_saved') $message = "<div class='alert alert-success'>Page access exceptions saved.</div>";
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
    
    // --- VALIDATE CSRF TOKEN ON ALL POST REQUESTS ---
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        die("<div class='alert alert-danger m-4 text-center'>Security Error: CSRF Token Validation Failed. Request Blocked.</div>");
    }
    // Adding/editing users needs edit rights, deleting needs delete rights
    lum_require_access('users', ($_POST['action'] ?? '') === 'delete' ? 'delete' : 'edit');
    // Only administrators may create, change or delete administrator accounts
    if (!lum_is_admin()) {
        $lum_target_id = (int)($_POST['delete_user_id'] ?? ($_POST['edit_id'] ?? 0));
        $lum_target_role = null;
        if ($lum_target_id > 0) {
            $lum_st = $login_db_conn->prepare("SELECT user_role FROM lum_sys_users WHERE user_id = ?");
            $lum_st->bind_param("i", $lum_target_id);
            $lum_st->execute();
            $lum_st->bind_result($lum_target_role);
            $lum_st->fetch();
            $lum_st->close();
        }
        if (($_POST['user_role'] ?? '') === 'Admin' || $lum_target_role === 'Admin') {
            lum_deny('Only administrators can create or change administrator accounts.');
        }
    }
    
    // ACTION: DELETE USER
    if ($_POST['action'] === 'delete' && isset($_POST['delete_user_id'])) {
        $del_id = intval($_POST['delete_user_id']);
        if ($del_id === (int)($_SESSION['user_id'] ?? 0)) {
            $message = "<div class='alert alert-danger'>You cannot delete your own active account.</div>";
        } else {
            $audit_before = lum_audit_fetch_user($del_id);
            $stmt = $login_db_conn->prepare("DELETE FROM lum_sys_users WHERE user_id = ?");
            $stmt->bind_param("i", $del_id);
            if ($stmt->execute()) {
                if ($audit_before && $stmt->affected_rows > 0) {
                    lum_audit_log('DELETE', 'user', $del_id, $audit_before['user_name'] ?? null, null, $audit_before, null);
                }
                header("Location: user-management.php?msg=deleted");
                exit();
            } else {
                error_log('LUM user delete failed: ' . $login_db_conn->error);
                $message = "<div class='alert alert-danger'>Error deleting user. Please try again or contact the system administrator.</div>";
            }
            $stmt->close();
        }
    }

    // ACTION: ADD USER
    if ($_POST['action'] === 'add') {
        $name = trim($_POST['user_name']);
        $email = trim($_POST['user_email']);
        $organization = trim($_POST['user_organization'] ?? '');
        $password = $_POST['user_password'];
        $role = in_array($_POST['user_role'] ?? '', $available_roles, true) ? $_POST['user_role'] : 'Standard';
        if (!lum_is_admin()) { $role = 'Standard'; } // Only administrators choose roles
        $status = in_array($_POST['user_status'] ?? '', ['Active', 'Disabled'], true) ? $_POST['user_status'] : 'Active';
        $assigned_properties = lumUmAssignedFromPost($all_properties);

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
            $stmt = $login_db_conn->prepare("INSERT INTO lum_sys_users (user_name, user_email, user_password, user_role, user_status, assigned_properties, user_organization) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssss", $name, $email, $hashed_password, $role, $status, $assigned_properties, $organization);
            
            if ($stmt->execute()) {
                $new_user_id = $stmt->insert_id;
                lum_audit_log('INSERT', 'user', $new_user_id, $name, null, null, lum_audit_fetch_user($new_user_id));
                header("Location: user-management.php?msg=added");
                exit();
            } else {
                error_log('LUM user save failed: ' . $login_db_conn->error);
                $message = "<div class='alert alert-danger'>The user could not be saved. Please try again or contact the system administrator.</div>";
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
        $organization = trim($_POST['user_organization'] ?? '');
        $role = in_array($_POST['user_role'] ?? '', $available_roles, true) ? $_POST['user_role'] : 'Standard';
        if (!lum_is_admin()) { $role = $lum_target_role ?? 'Standard'; } // Only administrators change roles (current role kept)
        $status = in_array($_POST['user_status'] ?? '', ['Active', 'Disabled'], true) ? $_POST['user_status'] : 'Active';
            if ($edit_target === (int)($_SESSION['user_id'] ?? 0)) { $status = 'Active'; } // Admins cannot disable themselves
        $new_password = $_POST['user_password'];
        // The user's current properties stay selectable (e.g. a property that was renamed since)
        $lum_keep_props = [];
        $st_kp = $login_db_conn->prepare("SELECT assigned_properties FROM lum_sys_users WHERE user_id = ?");
        $st_kp->bind_param("i", $edit_target);
        $st_kp->execute();
        $kp_row = $st_kp->get_result()->fetch_assoc();
        if ($kp_row && !empty($kp_row['assigned_properties'])) $lum_keep_props = array_map('trim', explode(',', $kp_row['assigned_properties']));
        $assigned_properties = lumUmAssignedFromPost($all_properties, $lum_keep_props);

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
            $audit_before = lum_audit_fetch_user($edit_target);
            if (!empty($new_password)) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $login_db_conn->prepare("UPDATE lum_sys_users SET user_name=?, user_email=?, user_password=?, user_role=?, user_status=?, assigned_properties=?, user_organization=? WHERE user_id=?");
                $stmt->bind_param("sssssssi", $name, $email, $hashed_password, $role, $status, $assigned_properties, $organization, $edit_target);
            } else {
                $stmt = $login_db_conn->prepare("UPDATE lum_sys_users SET user_name=?, user_email=?, user_role=?, user_status=?, assigned_properties=?, user_organization=? WHERE user_id=?");
                $stmt->bind_param("ssssssi", $name, $email, $role, $status, $assigned_properties, $organization, $edit_target);
            }
            
            if ($stmt->execute()) {
                $audit_after = lum_audit_fetch_user($edit_target);
                if (!empty($new_password) && is_array($audit_before) && is_array($audit_after)) {
                    $audit_before['password'] = null;
                    $audit_after['password'] = 'changed';
                }
                lum_audit_log('UPDATE', 'user', $edit_target, $name, null, $audit_before, $audit_after);
                header("Location: user-management.php?msg=updated");
                exit();
            } else {
                error_log('LUM user save failed: ' . $login_db_conn->error);
                $message = "<div class='alert alert-danger'>The user could not be saved. Please try again or contact the system administrator.</div>";
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
$result = $login_db_conn->query("SELECT user_id, user_name, user_email, user_role, user_status, last_login, user_organization FROM lum_sys_users ORDER BY user_name ASC");
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

// Number of page access exceptions per user (shown in the user list)
$user_exception_counts = [];
if ($access_ready) {
    try {
        foreach ($access_db->query("SELECT user_id, COUNT(*) AS n FROM lum_sys_users_access GROUP BY user_id") as $r) {
            $user_exception_counts[(int)$r['user_id']] = (int)$r['n'];
        }
    } catch (\Throwable $e) {}
}

// Exceptions of the user being edited
$target_user_rules = [];
if ($access_ready && $target_user) {
    try { $target_user_rules = lum_load_user_rules($access_db, (int)$target_user['user_id']); } catch (\Throwable $e) {}
}

// Areas grouped for display
$access_groups = [];
foreach ($access_pages as $p) {
    $access_groups[$p['page_group'] ?: 'General'][] = $p;
}

// Properties on the form: the list above plus any property the edited user still has (e.g. a renamed property)
$user_props = array_map('trim', $user_props);
$form_properties = array_values(array_unique(array_merge($all_properties, array_filter($user_props, 'strlen'))));
sort($form_properties, SORT_NATURAL | SORT_FLAG_CASE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Lynx Utilities</title>
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>
    <style>
        html { scroll-behavior: smooth; }
        body { background-color: #121212; color: #ffffff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
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
        .access-matrix th, .access-matrix td { vertical-align: middle; }
        .access-matrix .form-check-input { width: 1.2em; height: 1.2em; cursor: pointer; }
        .role-link { display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; border: 1px solid #333; border-radius: 6px; margin-bottom: 8px; color: #ddd; text-decoration: none; }
        .role-link:hover { background: #2a2a2a; color: #fff; }
        .role-link.active { border-color: #e3000f; background: #2a0003; color: #fff; }
        .nav-tabs .nav-link { color: #aaa; }
        .nav-tabs .nav-link.active { background: #1e1e1e; color: #fff; border-color: #333 #333 #1e1e1e; }
        .exception-select { min-width: 110px; }
    </style>
</head>
<body>

    <?php include LUM_ROOT . '/Layout/top-navbar.php'; ?>

    <?php
    $lum_sub_title = 'User Management';
    $lum_sub_back = 'https://lynx-um.co.za/Configs/view-configs.php';
    include LUM_ROOT . '/Layout/sub-navbar.php';
    ?>

    <?php if (lum_is_admin()): ?>
    <!-- Tabs -->
    <div class="container-fluid px-4 pt-3">
        <ul class="nav nav-tabs border-secondary">
            <li class="nav-item"><a class="nav-link <?php echo $active_tab === 'users' ? 'active' : ''; ?>" href="user-management.php"><i class="bi bi-people-fill me-2"></i>System Users</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $active_tab === 'roles' ? 'active' : ''; ?>" href="user-management.php?tab=roles"><i class="bi bi-shield-lock-fill me-2"></i>Roles &amp; Page Access</a></li>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($active_tab === 'users'): ?>
    <!-- Main Grid Layout -->
    <div class="container-fluid px-4 pt-3 pb-5">
        <div class="row g-4 justify-content-center">
            
            <!-- Left Column: User Table -->
            <div class="<?php echo $show_form ? 'col-xl-7 col-lg-6' : 'col-xl-10 col-lg-12'; ?>">
                <div class="admin-list-container p-4 p-md-5 h-100 m-0 w-100">
                    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-secondary pb-3">
                        <div>
                            <h2 class="fw-bold text-white mb-1">System Users</h2>
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
                                    <th>Organization</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Last Login</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($users)): ?>
                                    <tr><td colspan="8" class="text-center text-muted py-4">No users found.</td></tr>
                                <?php else: foreach($users as $u): ?>
                                    <tr>
                                        <td class="fw-bold text-secondary"><?php echo $u['user_id']; ?></td>
                                        <td class="fw-bold text-white"><?php echo htmlspecialchars($u['user_name']); ?></td>
                                        <td><a href="mailto:<?php echo htmlspecialchars($u['user_email']); ?>" class="text-decoration-none text-info"><?php echo htmlspecialchars($u['user_email']); ?></a></td>
                                        <td class="text-light"><?php echo htmlspecialchars($u['user_organization'] ?? 'N/A'); ?></td>
                                        <td>
                                            <span class="badge <?php echo ($u['user_role'] === 'Admin') ? 'bg-danger' : 'bg-secondary'; ?>"><?php echo htmlspecialchars($u['user_role']); ?></span>
                                            <?php if (!empty($user_exception_counts[(int)$u['user_id']]) && $u['user_role'] !== 'Admin'): ?>
                                                <span class="badge bg-warning text-dark" title="Page access exceptions"><i class="bi bi-sliders"></i> <?php echo (int)$user_exception_counts[(int)$u['user_id']]; ?></span>
                                            <?php endif; ?>
                                        </td>
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
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
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
            <div class="col-xl-5 col-lg-6">
                
                <?php if ($form_mode === 'edit' && $target_user): ?>
                    <!-- EDIT USER FORM -->
                    <div class="admin-form-container p-4 p-md-5 w-100 m-0" style="border-top: 6px solid #000000;">
                        <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-3">
                            <h4 class="fw-bold text-dark mb-0">Edit User Profile</h4>
                            <a href="user-management.php" class="btn btn-sm btn-outline-secondary">Close</a>
                        </div>

                        <form method="POST" action="user-management.php">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
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
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-dark">Organization</label>
                                <input type="text" class="form-control bg-light" name="user_organization" placeholder="e.g. Lynx Real Estate" value="<?php echo htmlspecialchars($target_user['user_organization'] ?? ''); ?>">
                            </div>
                            <div class="mb-3 p-3 bg-light border rounded">
                                <label class="form-label fw-semibold text-danger"><i class="bi bi-key-fill"></i> Reset Password (Optional)</label>
                                <input type="password" class="form-control" name="user_password" minlength="8" placeholder="Leave blank to keep current password">
                                <small class="text-muted d-block mt-1">If entering a new password, it must be at least 8 characters.</small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-dark">System Role</label>
                                <select class="form-select" name="user_role" required <?php echo lum_is_admin() ? '' : 'disabled'; ?>>
                                    <?php foreach ($available_roles as $r): if ($r === 'Admin' && !lum_is_admin()) continue; ?>
                                        <option value="<?php echo htmlspecialchars($r); ?>" <?php echo ($target_user['user_role'] === $r) ? 'selected' : ''; ?>><?php echo $r === 'Admin' ? 'Administrator' : htmlspecialchars($r); ?></option>
                                    <?php endforeach; ?>
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
                                    foreach($form_properties as $prop) {
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
                    <?php if (lum_is_admin() && $access_ready && $target_user['user_role'] !== 'Admin'): ?>
                    <!-- PAGE ACCESS EXCEPTIONS -->
                    <div class="admin-form-container p-4 w-100 mt-4" style="border-top: 6px solid #ffc107;">
                        <h5 class="fw-bold text-dark mb-1"><i class="bi bi-sliders me-2"></i>Page Access Exceptions</h5>
                        <p class="small text-muted mb-3">
                            Normally <strong><?php echo htmlspecialchars($target_user['user_name']); ?></strong> gets the access of the
                            <strong><?php echo htmlspecialchars($target_user['user_role']); ?></strong> role. Choose Allow or Deny only where this user must differ.
                        </p>
                        <form method="POST" action="user-management.php?edit=<?php echo (int)$target_user['user_id']; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="access_action" value="save_user_access">
                            <input type="hidden" name="access_user_id" value="<?php echo (int)$target_user['user_id']; ?>">
                            <div class="table-responsive" style="max-height: 420px;">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light" style="position: sticky; top: 0;">
                                        <tr><th>Area</th><?php foreach ($access_levels as $lvl => $lvl_label): ?><th class="text-center"><?php echo $lvl_label; ?></th><?php endforeach; ?></tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($access_pages as $p):
                                        $pk = $p['page_key'];
                                        $role_rule = $role_rules[$target_user['user_role']][$pk] ?? null;
                                        $user_rule = $target_user_rules[$pk] ?? null; ?>
                                        <tr>
                                            <td class="small fw-semibold"><?php echo htmlspecialchars($p['page_name']); ?></td>
                                            <?php foreach ($access_levels as $lvl => $lvl_label):
                                                $role_says = ($role_rule === null) ? 'default' : (!empty($role_rule['can_' . $lvl]) ? 'Yes' : 'No');
                                                $cur = ($user_rule === null || $user_rule['can_' . $lvl] === null) ? '' : (string)(int)$user_rule['can_' . $lvl]; ?>
                                                <td class="text-center">
                                                    <select class="form-select form-select-sm exception-select <?php echo $cur !== '' ? 'border-warning fw-semibold' : ''; ?>" name="ex[<?php echo htmlspecialchars($pk); ?>][<?php echo $lvl; ?>]">
                                                        <option value="" <?php echo $cur === '' ? 'selected' : ''; ?>>Role (<?php echo $role_says; ?>)</option>
                                                        <option value="1" <?php echo $cur === '1' ? 'selected' : ''; ?>>Allow</option>
                                                        <option value="0" <?php echo $cur === '0' ? 'selected' : ''; ?>>Deny</option>
                                                    </select>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="d-grid mt-3">
                                <button type="submit" class="btn btn-warning fw-bold">Save Exceptions</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>

                <?php elseif ($form_mode === 'add'): ?>
                    <!-- REGISTER NEW USER FORM -->
                    <div class="admin-form-container p-4 p-md-5 w-100 m-0" style="border-top: 6px solid #e3000f;">
                        <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-3">
                            <h4 class="fw-bold text-dark mb-0">Register New User</h4>
                            <a href="user-management.php" class="btn btn-sm btn-outline-secondary">Close</a>
                        </div>

                        <form method="POST" action="user-management.php">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
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
                                <label class="form-label fw-semibold text-dark">Organization</label>
                                <input type="text" class="form-control bg-light" name="user_organization" placeholder="e.g. Lynx Real Estate" value="<?php echo isset($_POST['user_organization']) ? htmlspecialchars($_POST['user_organization']) : ''; ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-dark">Secure Password</label>
                                <input type="password" class="form-control bg-light" name="user_password" required minlength="8" placeholder="Must be at least 8 characters">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-dark">System Role</label>
                                <select class="form-select bg-light" name="user_role" required <?php echo lum_is_admin() ? '' : 'disabled'; ?>>
                                    <?php foreach ($available_roles as $r): if ($r === 'Admin' && !lum_is_admin()) continue; ?>
                                        <option value="<?php echo htmlspecialchars($r); ?>" <?php echo ($r === 'Standard') ? 'selected' : ''; ?>><?php echo $r === 'Admin' ? 'Administrator' : htmlspecialchars($r); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (!lum_is_admin()): ?>
                                    <small class="text-muted">New users start with the Standard role. Only administrators can change roles.</small>
                                <?php endif; ?>
                                <?php if (lum_is_admin()): ?>
                                    <small class="text-muted">What each role may do is set under <a href="user-management.php?tab=roles">Roles &amp; Page Access</a>.</small>
                                <?php endif; ?>
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
                                    foreach($form_properties as $prop) {
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

    <?php else: /* ===================== ROLES & PAGE ACCESS TAB ===================== */ ?>
    <div class="container-fluid px-4 pt-3 pb-5">
        <?php echo $message; ?>

        <?php if (!$access_ready): ?>
            <div class="alert alert-warning">The access tables were not found. Please run <strong>access-control-setup.sql</strong> in phpMyAdmin first.</div>
        <?php else: ?>
        <div class="row g-4">

            <!-- Role list + create role -->
            <div class="col-xl-3 col-lg-4">
                <div class="admin-list-container p-4 h-100">
                    <h5 class="fw-bold text-white mb-3">Roles</h5>

                    <div class="role-link" style="cursor: default;">
                        <span><i class="bi bi-star-fill text-danger me-2"></i>Admin</span>
                        <span class="badge bg-secondary"><?php echo (int)($role_user_counts['Admin'] ?? 0); ?></span>
                    </div>

                    <?php foreach ($available_roles as $r): if ($r === 'Admin') continue; ?>
                        <a class="role-link <?php echo $r === $selected_role ? 'active' : ''; ?>" href="user-management.php?tab=roles&amp;role=<?php echo urlencode($r); ?>">
                            <span><i class="bi bi-person-badge me-2"></i><?php echo htmlspecialchars($r); ?>
                                <?php if (!isset($role_rules[$r])): ?><span class="badge bg-warning text-dark ms-1" title="No rules saved yet">new</span><?php endif; ?>
                            </span>
                            <span class="badge bg-secondary" title="Users with this role"><?php echo (int)($role_user_counts[$r] ?? 0); ?></span>
                        </a>
                    <?php endforeach; ?>

                    <hr class="border-secondary my-4">

                    <h6 class="fw-bold text-white mb-3">Create a Role</h6>
                    <form method="POST" action="user-management.php?tab=roles">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="access_action" value="add_role">
                        <div class="mb-2">
                            <input type="text" class="form-control" name="new_role" required maxlength="50" pattern="[A-Za-z0-9][A-Za-z0-9 _\-]{1,49}" placeholder="e.g. Viewer, Billing">
                        </div>
                        <div class="mb-3">
                            <select class="form-select" name="copy_from">
                                <option value="">Start with no access</option>
                                <?php foreach ($available_roles as $r): if ($r === 'Admin' || !isset($role_rules[$r])) continue; ?>
                                    <option value="<?php echo htmlspecialchars($r); ?>">Copy access from <?php echo htmlspecialchars($r); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-danger fw-bold"><i class="bi bi-plus-lg me-1"></i>Create Role</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Permission matrix for the selected role -->
            <div class="col-xl-9 col-lg-8">
                <div class="admin-list-container p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3 border-bottom border-secondary pb-3">
                        <div>
                            <h4 class="fw-bold text-white mb-1">Page Access: <?php echo htmlspecialchars($selected_role); ?></h4>
                        </div>
                        <?php if (!in_array($selected_role, ['Standard'], true) && isset($role_rules[$selected_role])): ?>
                        <form method="POST" action="user-management.php?tab=roles" onsubmit="return confirm('Delete the role <?php echo htmlspecialchars(addslashes($selected_role), ENT_QUOTES); ?>?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="access_action" value="delete_role">
                            <input type="hidden" name="role" value="<?php echo htmlspecialchars($selected_role); ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" <?php echo (($role_user_counts[$selected_role] ?? 0) > 0) ? 'disabled title="Still assigned to users"' : ''; ?>>
                                <i class="bi bi-trash3-fill me-1"></i>Delete Role
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>

                    <?php if (!isset($role_rules[$selected_role])): ?>
                        <div class="alert alert-warning py-2 small">This role has no saved rules yet. Until you save, its users follow the system default for areas without rules.</div>
                    <?php endif; ?>

                    <form method="POST" action="user-management.php?tab=roles&amp;role=<?php echo urlencode($selected_role); ?>" id="roleMatrixForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="access_action" value="save_role">
                        <input type="hidden" name="role" value="<?php echo htmlspecialchars($selected_role); ?>">

                        <div class="table-responsive">
                            <table class="table table-dark table-hover access-matrix mb-0">
                                <thead class="table-active">
                                    <tr>
                                        <th>Area</th>
                                        <?php foreach ($access_levels as $lvl => $lvl_label): ?>
                                            <th class="text-center" style="width: 90px;">
                                                <?php echo $lvl_label; ?><br>
                                                <a href="#" class="small text-info text-decoration-none" onclick="toggleColumn('<?php echo $lvl; ?>'); return false;">all</a>
                                            </th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($access_groups as $group => $pages): ?>
                                    <tr><td colspan="<?php echo 1 + count($access_levels); ?>" class="text-danger small fw-bold pt-3" style="background: #151515;"><?php echo htmlspecialchars($group); ?></td></tr>
                                    <?php foreach ($pages as $p):
                                        $pk = $p['page_key'];
                                        $rule = $role_rules[$selected_role][$pk] ?? null; ?>
                                    <tr>
                                        <td class="fw-semibold text-white"><?php echo htmlspecialchars($p['page_name']); ?></td>
                                        <?php foreach ($access_levels as $lvl => $lvl_label): ?>
                                            <td class="text-center">
                                                <input class="form-check-input perm-box" type="checkbox"
                                                       data-page="<?php echo htmlspecialchars($pk); ?>" data-level="<?php echo $lvl; ?>"
                                                       name="perm[<?php echo htmlspecialchars($pk); ?>][<?php echo $lvl; ?>]" value="1"
                                                       <?php echo (!empty($rule['can_' . $lvl])) ? 'checked' : ''; ?>>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-end mt-4">
                            <button type="submit" class="btn btn-danger fw-bold px-4"><i class="bi bi-save me-2"></i>Save <?php echo htmlspecialchars($selected_role); ?> Access</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Edit/Delete include View; removing View removes Edit/Delete
        document.querySelectorAll('.perm-box').forEach(function (box) {
            box.addEventListener('change', function () {
                const page = this.dataset.page;
                const row = document.querySelectorAll('.perm-box[data-page="' + CSS.escape(page) + '"]');
                if (this.dataset.level !== 'view' && this.checked) {
                    row.forEach(b => { if (b.dataset.level === 'view') b.checked = true; });
                }
                if (this.dataset.level === 'view' && !this.checked) {
                    row.forEach(b => { b.checked = false; });
                }
            });
        });
        function toggleColumn(level) {
            const boxes = Array.from(document.querySelectorAll('.perm-box[data-level="' + level + '"]'));
            const turnOn = boxes.some(b => !b.checked);
            boxes.forEach(b => { b.checked = turnOn; b.dispatchEvent(new Event('change')); });
        }
    </script>
    <?php endif; /* end of tabs */ ?>

    <?php include LUM_ROOT . '/Layout/foot-assets.php'; ?>
</body>
</html>