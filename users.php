
<?php
require 'config/database.php';
require 'includes/functions.php';

require_login();
require_permission('users');

$page_title = 'User Management';
$currentPage = 'users.php';

/* =========================================
   HELPER: COUNT ACTIVE ADMINISTRATORS
========================================= */

$countActiveAdmins = function ($excludeId = 0) use ($pdo) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE r.name = 'administrator'
          AND u.status = 'active'
          AND u.id <> ?
    ");

    $stmt->execute([(int)$excludeId]);

    return (int)$stmt->fetchColumn();
};

/* =========================================
   HANDLE USER ACTIONS
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['form_action'] ?? '';

    try {

        /* =====================================
           CREATE USER
        ===================================== */

        if ($action === 'create') {

            $name = trim($_POST['name'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $password = $_POST['password'] ?? '';
            $roleId = filter_var(
                $_POST['role_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if ($name === '' || strlen($name) > 120) {
                throw new Exception('Please enter a valid name.');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address.');
            }

            if (!$roleId || $roleId <= 0) {
                throw new Exception('Please select a valid role.');
            }

            if (strlen($password) < 10) {
                throw new Exception('Password must contain at least 10 characters.');
            }

            $roleStmt = $pdo->prepare("
                SELECT id FROM roles WHERE id = ?
            ");
            $roleStmt->execute([$roleId]);

            if (!$roleStmt->fetch()) {
                throw new Exception('Selected role does not exist.');
            }

            $checkEmail = $pdo->prepare("
                SELECT id FROM users WHERE email = ? LIMIT 1
            ");
            $checkEmail->execute([$email]);

            if ($checkEmail->fetch()) {
                throw new Exception('This email address is already registered.');
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);

            $insert = $pdo->prepare("
                INSERT INTO users (
                    name,
                    email,
                    password_hash,
                    role_id,
                    status
                ) VALUES (?, ?, ?, ?, 'active')
            ");

            $insert->execute([
                $name,
                $email,
                $hash,
                $roleId
            ]);

            flash('User account created successfully.', 'success');
        }

        /* =====================================
           UPDATE USER
        ===================================== */

        elseif ($action === 'update') {

            $userId = filter_var(
                $_POST['user_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $name = trim($_POST['name'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));

            $roleId = filter_var(
                $_POST['role_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $status = $_POST['status'] ?? '';

            if (!$userId || $userId <= 0) {
                throw new Exception('Invalid user account.');
            }

            if ($name === '' || strlen($name) > 120) {
                throw new Exception('Please enter a valid name.');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address.');
            }

            if (!$roleId || $roleId <= 0) {
                throw new Exception('Please select a valid role.');
            }

            if (!in_array($status, ['active', 'inactive'], true)) {
                throw new Exception('Invalid account status.');
            }

            $pdo->beginTransaction();

            $userStmt = $pdo->prepare("
                SELECT u.id, u.role_id, u.status, r.name AS role
                FROM users u
                INNER JOIN roles r ON r.id = u.role_id
                WHERE u.id = ?
                FOR UPDATE
            ");

            $userStmt->execute([$userId]);

            $existingUser = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$existingUser) {
                throw new Exception('User account was not found.');
            }

            // Prevent changing your own role or account status.
            if ($userId === (int)$_SESSION['user_id']) {
                $roleId = (int)$existingUser['role_id'];
                $status = $existingUser['status'];
            }

            $roleStmt = $pdo->prepare("
                SELECT id, name FROM roles WHERE id = ?
            ");

            $roleStmt->execute([$roleId]);

            $newRole = $roleStmt->fetch(PDO::FETCH_ASSOC);

            if (!$newRole) {
                throw new Exception('Selected role does not exist.');
            }

            $emailStmt = $pdo->prepare("
                SELECT id
                FROM users
                WHERE email = ?
                  AND id <> ?
                LIMIT 1
            ");

            $emailStmt->execute([$email, $userId]);

            if ($emailStmt->fetch()) {
                throw new Exception('This email address belongs to another user.');
            }

            // Protect the last active administrator.
            $wasActiveAdmin =
                $existingUser['role'] === 'administrator' &&
                $existingUser['status'] === 'active';

            $willBeActiveAdmin =
                $newRole['name'] === 'administrator' &&
                $status === 'active';

            if ($wasActiveAdmin && !$willBeActiveAdmin) {

                if ($countActiveAdmins($userId) < 1) {
                    throw new Exception(
                        'You cannot remove or deactivate the last active administrator.'
                    );
                }
            }

            $update = $pdo->prepare("
                UPDATE users
                SET name = ?,
                    email = ?,
                    role_id = ?,
                    status = ?
                WHERE id = ?
            ");

            $update->execute([
                $name,
                $email,
                $roleId,
                $status,
                $userId
            ]);

            $pdo->commit();

            flash('User account updated successfully.', 'success');
        }

        /* =====================================
           RESET USER PASSWORD
        ===================================== */

        elseif ($action === 'reset_password') {

            $userId = filter_var(
                $_POST['user_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $password = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (!$userId || $userId <= 0) {
                throw new Exception('Invalid user account.');
            }

            if (strlen($password) < 10) {
                throw new Exception('Password must contain at least 10 characters.');
            }

            if ($password !== $confirmPassword) {
                throw new Exception('Passwords do not match.');
            }

            $check = $pdo->prepare("
                SELECT id FROM users WHERE id = ?
            ");

            $check->execute([$userId]);

            if (!$check->fetch()) {
                throw new Exception('User account was not found.');
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);

            $update = $pdo->prepare("
                UPDATE users
                SET password_hash = ?
                WHERE id = ?
            ");

            $update->execute([$hash, $userId]);

            flash('User password reset successfully.', 'success');
        }

        /* =====================================
           DELETE USER
        ===================================== */

        elseif ($action === 'delete') {

            $userId = filter_var(
                $_POST['user_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$userId || $userId <= 0) {
                throw new Exception('Invalid user account.');
            }

            if ($userId === (int)$_SESSION['user_id']) {
                throw new Exception('You cannot delete your own account.');
            }

            $pdo->beginTransaction();

            $userStmt = $pdo->prepare("
                SELECT u.id, u.status, r.name AS role
                FROM users u
                INNER JOIN roles r ON r.id = u.role_id
                WHERE u.id = ?
                FOR UPDATE
            ");

            $userStmt->execute([$userId]);

            $targetUser = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$targetUser) {
                throw new Exception('User account was not found.');
            }

            if (
                $targetUser['role'] === 'administrator' &&
                $targetUser['status'] === 'active' &&
                $countActiveAdmins($userId) < 1
            ) {
                throw new Exception(
                    'You cannot delete the last active administrator.'
                );
            }

            $delete = $pdo->prepare("
                DELETE FROM users WHERE id = ?
            ");

            $delete->execute([$userId]);

            $pdo->commit();

            flash('User account deleted successfully.', 'success');
        }

        else {
            throw new Exception('Invalid user management action.');
        }

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($e instanceof PDOException) {
            error_log($e->getMessage());

            flash(
                'A database error occurred. Please check the account details.',
                'danger'
            );
        } else {
            flash($e->getMessage(), 'danger');
        }
    }

    header('Location: users.php');
    exit;
}

/* =========================================
   LOAD ROLES AND USERS
========================================= */

$roles = $pdo->query("
    SELECT id, name
    FROM roles
    ORDER BY
        CASE name
            WHEN 'administrator' THEN 1
            WHEN 'dispatcher' THEN 2
            WHEN 'finance' THEN 3
            WHEN 'marketing' THEN 4
            WHEN 'fleet_manager' THEN 5
            ELSE 6
        END
")->fetchAll(PDO::FETCH_ASSOC);

$rows = $pdo->query("
    SELECT
        u.id,
        u.name,
        u.email,
        u.role_id,
        u.status,
        u.created_at,
        r.name AS role
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    ORDER BY u.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$totalUsers = count($rows);
$activeUsers = 0;
$inactiveUsers = 0;
$adminUsers = 0;

foreach ($rows as $user) {

    if ($user['status'] === 'active') {
        $activeUsers++;
    } else {
        $inactiveUsers++;
    }

    if ($user['role'] === 'administrator') {
        $adminUsers++;
    }
}

require 'includes/header.php';
?>

<div class="container-fluid">

    <!-- PAGE HEADER -->

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">

        <div>
            <h3 class="fw-bold mb-1">User Management</h3>
            <p class="text-muted mb-0">
                Manage system users, accounts and access permissions.
            </p>
        </div>

        <button class="btn btn-primary"
                data-bs-toggle="modal"
                data-bs-target="#createUserModal">

            <i class="bi bi-person-plus me-1"></i>
            Add New User

        </button>

    </div>

    <?php show_flash(); ?>

    <!-- STATISTICS -->

    <div class="row g-3 mb-4">

        <div class="col-xl-3 col-md-6">

            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">

                    <div class="text-muted small">Total Users</div>

                    <h3 class="fw-bold mb-0">
                        <?= $totalUsers ?>
                    </h3>

                    <i class="bi bi-people fs-4 text-primary"></i>

                </div>
            </div>

        </div>

        <div class="col-xl-3 col-md-6">

            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">

                    <div class="text-muted small">Active Users</div>

                    <h3 class="fw-bold text-success mb-0">
                        <?= $activeUsers ?>
                    </h3>

                    <i class="bi bi-person-check fs-4 text-success"></i>

                </div>
            </div>

        </div>

        <div class="col-xl-3 col-md-6">

            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">

                    <div class="text-muted small">Inactive Users</div>

                    <h3 class="fw-bold text-danger mb-0">
                        <?= $inactiveUsers ?>
                    </h3>

                    <i class="bi bi-person-x fs-4 text-danger"></i>

                </div>
            </div>

        </div>

        <div class="col-xl-3 col-md-6">

            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">

                    <div class="text-muted small">Administrators</div>

                    <h3 class="fw-bold mb-0">
                        <?= $adminUsers ?>
                    </h3>

                    <i class="bi bi-shield-lock fs-4 text-primary"></i>

                </div>
            </div>

        </div>

    </div>

    <!-- USERS TABLE -->

    <div class="card border-0 shadow-sm">

        <div class="card-header bg-white py-3">

            <h5 class="fw-bold mb-0">
                System Users
            </h5>

        </div>

        <div class="card-body">

            <div class="table-responsive">

                <table class="table table-hover align-middle">

                    <thead class="table-light">

                        <tr>
                            <th>#</th>
                            <th>User</th>
                            <th>Email Address</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Date Created</th>
                            <th>Actions</th>
                        </tr>

                    </thead>

                    <tbody>

                    <?php if (empty($rows)): ?>

                        <tr>
                            <td colspan="7"
                                class="text-center text-muted py-4">

                                No users have been registered.

                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($rows as $index => $r): ?>

                            <?php

                            $isCurrentUser =
                                (int)$r['id'] === (int)$_SESSION['user_id'];

                            $userJson = htmlspecialchars(
                                json_encode(
                                    $r,
                                    JSON_HEX_APOS |
                                    JSON_HEX_QUOT |
                                    JSON_HEX_TAG |
                                    JSON_HEX_AMP
                                ),
                                ENT_QUOTES,
                                'UTF-8'
                            );

                            ?>

                            <tr>

                                <td><?= $index + 1 ?></td>

                                <td>

                                    <div class="fw-semibold">

                                        <?= e($r['name']) ?>

                                        <?php if ($isCurrentUser): ?>

                                            <span class="badge bg-primary ms-1">
                                                You
                                            </span>

                                        <?php endif; ?>

                                    </div>

                                </td>

                                <td><?= e($r['email']) ?></td>

                                <td>

                                    <?php

                                    $roleLabels = [
                                        'administrator' => 'Administrator',
                                        'dispatcher' => 'Dispatcher',
                                        'finance' => 'Finance',
                                        'marketing' => 'Marketing',
                                        'fleet_manager' => 'Fleet Manager'
                                    ];

                                    ?>

                                    <span class="badge bg-light text-dark border">

                                        <?= e(
                                            $roleLabels[$r['role']] ??
                                            ucfirst($r['role'])
                                        ) ?>

                                    </span>

                                </td>

                                <td>

                                    <?php if ($r['status'] === 'active'): ?>

                                        <span class="badge bg-success">
                                            Active
                                        </span>

                                    <?php else: ?>

                                        <span class="badge bg-danger">
                                            Inactive
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td>
                                    <?= e($r['created_at']) ?>
                                </td>

                                <td>

                                    <div class="d-flex gap-1">

                                        <!-- EDIT -->

                                        <button type="button"
                                                class="btn btn-sm btn-outline-primary"
                                                title="Edit user"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editUserModal"
                                                data-user="<?= $userJson ?>">

                                            <i class="bi bi-pencil-square"></i>

                                        </button>

                                        <!-- RESET PASSWORD -->

                                        <button type="button"
                                                class="btn btn-sm btn-outline-warning"
                                                title="Reset password"
                                                data-bs-toggle="modal"
                                                data-bs-target="#resetPasswordModal"
                                                data-id="<?= (int)$r['id'] ?>"
                                                data-name="<?= e($r['name']) ?>">

                                            <i class="bi bi-key"></i>

                                        </button>

                                        <!-- DELETE -->

                                        <?php if (!$isCurrentUser): ?>

                                            <button type="button"
                                                    class="btn btn-sm btn-outline-danger"
                                                    title="Delete user"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#deleteUserModal"
                                                    data-id="<?= (int)$r['id'] ?>"
                                                    data-name="<?= e($r['name']) ?>">

                                                <i class="bi bi-trash"></i>

                                            </button>

                                        <?php else: ?>

                                            <button type="button"
                                                    class="btn btn-sm btn-outline-secondary"
                                                    disabled
                                                    title="You cannot delete your own account">

                                                <i class="bi bi-trash"></i>

                                            </button>

                                        <?php endif; ?>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

    <!-- ROLE INFORMATION -->

    <div class="card border-0 shadow-sm mt-4">

        <div class="card-header bg-white py-3">

            <h5 class="fw-bold mb-0">
                System Roles and Permissions
            </h5>

        </div>

        <div class="card-body">

            <div class="row g-3">

                <div class="col-md-6 col-lg-4">

                    <div class="border rounded p-3 h-100">

                        <h6 class="fw-bold">
                            <i class="bi bi-shield-lock text-primary me-1"></i>
                            Administrator
                        </h6>

                        <p class="text-muted small mb-0">
                            Full access to all system modules and user management.
                        </p>

                    </div>

                </div>

                <div class="col-md-6 col-lg-4">

                    <div class="border rounded p-3 h-100">

                        <h6 class="fw-bold">
                            <i class="bi bi-truck text-primary me-1"></i>
                            Dispatcher
                        </h6>

                        <p class="text-muted small mb-0">
                            Manages customers, deliveries, dispatch and drivers.
                        </p>

                    </div>

                </div>

                <div class="col-md-6 col-lg-4">

                    <div class="border rounded p-3 h-100">

                        <h6 class="fw-bold">
                            <i class="bi bi-cash-stack text-primary me-1"></i>
                            Finance
                        </h6>

                        <p class="text-muted small mb-0">
                            Manages payments and financial reports.
                        </p>

                    </div>

                </div>

                <div class="col-md-6 col-lg-4">

                    <div class="border rounded p-3 h-100">

                        <h6 class="fw-bold">
                            <i class="bi bi-megaphone text-primary me-1"></i>
                            Marketing
                        </h6>

                        <p class="text-muted small mb-0">
                            Manages customer information and customer records.
                        </p>

                    </div>

                </div>

                <div class="col-md-6 col-lg-4">

                    <div class="border rounded p-3 h-100">

                        <h6 class="fw-bold">
                            <i class="bi bi-wrench-adjustable text-primary me-1"></i>
                            Fleet Manager
                        </h6>

                        <p class="text-muted small mb-0">
                            Manages fleet vehicles, drivers and maintenance.
                        </p>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>

<!-- =========================================
     CREATE USER MODAL
========================================= -->

<div class="modal fade" id="createUserModal" tabindex="-1">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content">

            <form method="POST" action="users.php">

                <div class="modal-header">

                    <h5 class="modal-title fw-bold">
                        Create New User
                    </h5>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"></button>

                </div>

                <div class="modal-body">

                    <input type="hidden"
                           name="csrf"
                           value="<?= e(csrf_token()) ?>">

                    <input type="hidden"
                           name="form_action"
                           value="create">

                    <div class="row g-3">

                        <div class="col-md-6">

                            <label class="form-label">
                                Full Name *
                            </label>

                            <input type="text"
                                   name="name"
                                   class="form-control"
                                   maxlength="120"
                                   required>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label">
                                Email Address *
                            </label>

                            <input type="email"
                                   name="email"
                                   class="form-control"
                                   required>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label">
                                Password *
                            </label>

                            <input type="password"
                                   name="password"
                                   class="form-control"
                                   minlength="10"
                                   autocomplete="new-password"
                                   required>

                            <small class="text-muted">
                                Minimum 10 characters.
                            </small>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label">
                                Assign Role *
                            </label>

                            <select name="role_id"
                                    class="form-select"
                                    required>

                                <option value="">
                                    Select role
                                </option>

                                <?php foreach ($roles as $role): ?>

                                    <option value="<?= (int)$role['id'] ?>">

                                        <?= e(
                                            $roleLabels[$role['name']] ??
                                            ucfirst($role['name'])
                                        ) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                    </div>

                    <div class="alert alert-info mt-3 mb-0">

                        <i class="bi bi-info-circle me-1"></i>

                        New accounts are automatically activated.

                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button"
                            class="btn btn-light"
                            data-bs-dismiss="modal">

                        Cancel

                    </button>

                    <button type="submit"
                            class="btn btn-primary">

                        <i class="bi bi-person-plus me-1"></i>
                        Create Account

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<!-- =========================================
     EDIT USER MODAL
========================================= -->

<div class="modal fade" id="editUserModal" tabindex="-1">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content">

            <form method="POST" action="users.php">

                <div class="modal-header">

                    <h5 class="modal-title fw-bold">
                        Edit User Account
                    </h5>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"></button>

                </div>

                <div class="modal-body">

                    <input type="hidden"
                           name="csrf"
                           value="<?= e(csrf_token()) ?>">

                    <input type="hidden"
                           name="form_action"
                           value="update">

                    <input type="hidden"
                           name="user_id"
                           id="edit_user_id">

                    <div class="row g-3">

                        <div class="col-md-6">

                            <label class="form-label">
                                Full Name *
                            </label>

                            <input type="text"
                                   name="name"
                                   id="edit_user_name"
                                   class="form-control"
                                   maxlength="120"
                                   required>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label">
                                Email Address *
                            </label>

                            <input type="email"
                                   name="email"
                                   id="edit_user_email"
                                   class="form-control"
                                   required>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label">
                                Role *
                            </label>

                            <select name="role_id"
                                    id="edit_user_role"
                                    class="form-select"
                                    required>

                                <?php foreach ($roles as $role): ?>

                                    <option value="<?= (int)$role['id'] ?>">

                                        <?= e(
                                            $roleLabels[$role['name']] ??
                                            ucfirst($role['name'])
                                        ) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label">
                                Account Status *
                            </label>

                            <select name="status"
                                    id="edit_user_status"
                                    class="form-select"
                                    required>

                                <option value="active">
                                    Active
                                </option>

                                <option value="inactive">
                                    Inactive
                                </option>

                            </select>

                        </div>

                    </div>

                    <div id="selfAccountNotice"
                         class="alert alert-info mt-3 mb-0 d-none">

                        Your own role and account status cannot be changed here.

                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button"
                            class="btn btn-light"
                            data-bs-dismiss="modal">

                        Cancel

                    </button>

                    <button type="submit"
                            class="btn btn-primary">

                        <i class="bi bi-save me-1"></i>
                        Save Changes

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<!-- =========================================
     RESET PASSWORD MODAL
========================================= -->

<div class="modal fade" id="resetPasswordModal" tabindex="-1">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <form method="POST" action="users.php">

                <div class="modal-header">

                    <h5 class="modal-title fw-bold">
                        Reset User Password
                    </h5>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"></button>

                </div>

                <div class="modal-body">

                    <input type="hidden"
                           name="csrf"
                           value="<?= e(csrf_token()) ?>">

                    <input type="hidden"
                           name="form_action"
                           value="reset_password">

                    <input type="hidden"
                           name="user_id"
                           id="reset_user_id">

                    <p>
                        Resetting password for:

                        <strong id="reset_user_name"></strong>
                    </p>

                    <div class="mb-3">

                        <label class="form-label">
                            New Password *
                        </label>

                        <input type="password"
                               name="password"
                               class="form-control"
                               minlength="10"
                               autocomplete="new-password"
                               required>

                    </div>

                    <div>

                        <label class="form-label">
                            Confirm New Password *
                        </label>

                        <input type="password"
                               name="confirm_password"
                               class="form-control"
                               minlength="10"
                               autocomplete="new-password"
                               required>

                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button"
                            class="btn btn-light"
                            data-bs-dismiss="modal">

                        Cancel

                    </button>

                    <button type="submit"
                            class="btn btn-warning">

                        <i class="bi bi-key me-1"></i>
                        Reset Password

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<!-- =========================================
     DELETE USER MODAL
========================================= -->

<div class="modal fade" id="deleteUserModal" tabindex="-1">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <form method="POST" action="users.php">

                <div class="modal-header">

                    <h5 class="modal-title fw-bold text-danger">
                        Delete User Account
                    </h5>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"></button>

                </div>

                <div class="modal-body">

                    <input type="hidden"
                           name="csrf"
                           value="<?= e(csrf_token()) ?>">

                    <input type="hidden"
                           name="form_action"
                           value="delete">

                    <input type="hidden"
                           name="user_id"
                           id="delete_user_id">

                    <div class="text-center">

                        <i class="bi bi-exclamation-triangle text-danger"
                           style="font-size:3rem;"></i>

                        <p class="mt-3">
                            Are you sure you want to delete this user?
                        </p>

                        <h5 class="fw-bold"
                            id="delete_user_name"></h5>

                        <p class="text-danger small mb-0">
                            This action cannot be undone.
                        </p>

                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button"
                            class="btn btn-light"
                            data-bs-dismiss="modal">

                        Cancel

                    </button>

                    <button type="submit"
                            class="btn btn-danger">

                        <i class="bi bi-trash me-1"></i>
                        Delete Account

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<!-- =========================================
     MODAL JAVASCRIPT
========================================= -->

<script>
document.addEventListener('DOMContentLoaded', function () {

    const currentUserId = <?= (int)$_SESSION['user_id'] ?>;

    // EDIT USER
    const editModal = document.getElementById('editUserModal');

    editModal.addEventListener('show.bs.modal', function (event) {

        const button = event.relatedTarget;
        const user = JSON.parse(button.getAttribute('data-user'));

        document.getElementById('edit_user_id').value = user.id;
        document.getElementById('edit_user_name').value = user.name;
        document.getElementById('edit_user_email').value = user.email;
        document.getElementById('edit_user_role').value = user.role_id;
        document.getElementById('edit_user_status').value = user.status;

        const isSelf = parseInt(user.id) === currentUserId;

        document.getElementById('edit_user_role').disabled = isSelf;
        document.getElementById('edit_user_status').disabled = isSelf;

        document.getElementById('selfAccountNotice')
            .classList.toggle('d-none', !isSelf);

    });

    // RESET PASSWORD
    const resetModal = document.getElementById('resetPasswordModal');

    resetModal.addEventListener('show.bs.modal', function (event) {

        const button = event.relatedTarget;

        document.getElementById('reset_user_id').value =
            button.getAttribute('data-id');

        document.getElementById('reset_user_name').textContent =
            button.getAttribute('data-name');

        resetModal.querySelectorAll('input[type="password"]')
            .forEach(input => input.value = '');

    });

    // DELETE USER
    const deleteModal = document.getElementById('deleteUserModal');

    deleteModal.addEventListener('show.bs.modal', function (event) {

        const button = event.relatedTarget;

        document.getElementById('delete_user_id').value =
            button.getAttribute('data-id');

        document.getElementById('delete_user_name').textContent =
            button.getAttribute('data-name');

    });

});
</script>

<?php require 'includes/footer.php'; ?>
