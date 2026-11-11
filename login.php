
<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

/*
|--------------------------------------------------------------------------
| REDIRECT ALREADY AUTHENTICATED USERS
|--------------------------------------------------------------------------
*/

if (!empty($_SESSION['user_id'])) {
    $existingUser = current_user();

    if ($existingUser && $existingUser['status'] === 'active') {
        header('Location: index.php');
        exit;
    }
}

$error = '';
$email = '';

/*
|--------------------------------------------------------------------------
| HANDLE LOGIN
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        strlen($email) > 190 ||
        !is_string($password) ||
        $password === ''
    ) {
        $error = 'Invalid email or password.';

    } else {

        try {

            $stmt = $pdo->prepare("
                SELECT id, name, email, password_hash, status
                FROM users
                WHERE email = ?
                LIMIT 1
            ");

            $stmt->execute([$email]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (
                $user &&
                $user['status'] === 'active' &&
                password_verify($password, $user['password_hash'])
            ) {

                /*
                |--------------------------------------------------------------------------
                | SECURE SESSION INITIALIZATION
                |--------------------------------------------------------------------------
                */

                secure_login_session((int)$user['id']);

                header('Location: index.php');
                exit;

            } else {

                $error = 'Invalid email or password.';

            }

        } catch (PDOException $e) {

            error_log('MLFMS login database error: ' . $e->getMessage());

            $error = 'Unable to process your login. Please try again later.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| SESSION EXPIRY MESSAGE
|--------------------------------------------------------------------------
*/

$sessionExpired = !empty($_SESSION['session_expired']);

if ($sessionExpired) {
    unset($_SESSION['session_expired']);
}

?>

<!doctype html>
<html lang="en">

<head>

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Sign In | MLFMS</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <style>

        :root {
            --primary: #1769e0;
            --primary-dark: #1053b7;
            --navy: #10243e;
            --muted: #718096;
            --background: #f3f6fb;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            background: var(--background);
            font-family: "Segoe UI", Arial, sans-serif;
            color: var(--navy);
        }

        .login-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 15px;
        }

        .login-container {
            width: 100%;
            max-width: 1050px;
            min-height: 610px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            background: #ffffff;
            border-radius: 22px;
            overflow: hidden;
            box-shadow: 0 18px 60px rgba(20, 42, 75, .12);
        }

        /* LEFT PANEL */

        .login-brand-panel {
            position: relative;
            padding: 55px 45px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            color: #ffffff;
            background:
                radial-gradient(
                    circle at 85% 15%,
                    rgba(255,255,255,.16),
                    transparent 35%
                ),
                linear-gradient(145deg, #1769e0, #103d83);
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 13px;
        }

        .brand-logo-icon {
            width: 52px;
            height: 52px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 15px;
            background: rgba(255,255,255,.17);
            font-size: 27px;
        }

        .brand-logo h4 {
            font-weight: 800;
            margin: 0;
            letter-spacing: 1px;
        }

        .brand-logo small {
            color: rgba(255,255,255,.75);
            font-size: 11px;
            letter-spacing: 1.5px;
        }

        .brand-content {
            margin-top: 45px;
            margin-bottom: 45px;
        }

        .brand-content h1 {
            font-size: clamp(30px, 4vw, 43px);
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 20px;
        }

        .brand-content p {
            color: rgba(255,255,255,.78);
            line-height: 1.8;
            font-size: 15px;
            max-width: 370px;
        }

        .brand-features {
            display: flex;
            flex-direction: column;
            gap: 17px;
            margin-top: 30px;
        }

        .brand-feature {
            display: flex;
            align-items: center;
            gap: 12px;
            color: rgba(255,255,255,.92);
            font-size: 14px;
        }

        .brand-feature i {
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,.15);
            border-radius: 8px;
        }

        .brand-footer {
            color: rgba(255,255,255,.65);
            font-size: 12px;
        }

        /* RIGHT PANEL */

        .login-form-panel {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 55px 50px;
        }

        .login-form-content {
            width: 100%;
            max-width: 370px;
        }

        .login-heading {
            margin-bottom: 34px;
        }

        .login-heading h2 {
            font-size: 29px;
            font-weight: 800;
            margin-bottom: 9px;
        }

        .login-heading p {
            color: var(--muted);
            font-size: 14px;
            margin-bottom: 0;
        }

        .form-label {
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 8px;
        }

        .input-group {
            border: 1px solid #dce3ed;
            border-radius: 10px;
            overflow: hidden;
            transition: .2s ease;
        }

        .input-group:focus-within {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(23,105,224,.10);
        }

        .input-group-text {
            background: #ffffff;
            border: 0;
            color: #8a98aa;
            padding-left: 14px;
        }

        .form-control {
            border: 0;
            box-shadow: none !important;
            padding: 13px 12px;
            font-size: 14px;
        }

        .password-toggle {
            border: 0;
            background: #ffffff;
            color: #718096;
            padding: 0 15px;
        }

        .password-toggle:hover {
            color: var(--primary);
        }

        .login-button {
            width: 100%;
            border: 0;
            border-radius: 10px;
            padding: 14px;
            background: var(--primary);
            color: #ffffff;
            font-weight: 700;
            font-size: 14px;
            transition: .2s ease;
            box-shadow: 0 5px 15px rgba(23,105,224,.2);
        }

        .login-button:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .login-button:disabled {
            opacity: .75;
            cursor: not-allowed;
            transform: none;
        }

        .security-note {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: var(--muted);
            font-size: 12px;
            margin-top: 25px;
        }

        .security-note i {
            color: #198754;
        }

        .login-bottom {
            text-align: center;
            color: #94a3b8;
            font-size: 12px;
            margin-top: 35px;
        }

        .alert {
            border-radius: 10px;
            font-size: 13px;
        }

        /* RESPONSIVE */

        @media (max-width: 768px) {

            .login-wrapper {
                padding: 15px;
            }

            .login-container {
                max-width: 480px;
                min-height: auto;
                grid-template-columns: 1fr;
            }

            .login-brand-panel {
                padding: 30px;
            }

            .brand-content {
                margin: 35px 0 10px;
            }

            .brand-content h1 {
                font-size: 27px;
            }

            .brand-content p {
                margin-bottom: 0;
            }

            .brand-features,
            .brand-footer {
                display: none;
            }

            .login-form-panel {
                padding: 40px 30px;
            }
        }

    </style>

</head>

<body>

<div class="login-wrapper">

    <div class="login-container">

        <!-- BRAND PANEL -->

        <div class="login-brand-panel">

            <div class="brand-logo">

                <div class="brand-logo-icon">
                    <i class="bi bi-truck"></i>
                </div>

                <div>
                    <h4>MLFMS</h4>
                    <small>MICRO LOGISTICS</small>
                </div>

            </div>

            <div class="brand-content">

                <h1>
                    Smarter Logistics.<br>
                    Better Deliveries.
                </h1>

                <p>
                    Manage your deliveries, fleet operations,
                    drivers and payments from one centralised
                    management platform.
                </p>

                <div class="brand-features">

                    <div class="brand-feature">
                        <i class="bi bi-truck"></i>
                        Fleet and vehicle management
                    </div>

                    <div class="brand-feature">
                        <i class="bi bi-box-seam"></i>
                        Delivery and dispatch tracking
                    </div>

                    <div class="brand-feature">
                        <i class="bi bi-cash-stack"></i>
                        Payments and financial reporting
                    </div>

                </div>

            </div>

            <div class="brand-footer">
                Micro-Logistics Fleet Management System
            </div>

        </div>

        <!-- LOGIN FORM PANEL -->

        <div class="login-form-panel">

            <div class="login-form-content">

                <div class="login-heading">

                    <div class="mb-3 text-primary fs-3">
                        <i class="bi bi-person-circle"></i>
                    </div>

                    <h2>Welcome Back</h2>

                    <p>
                        Sign in to access your management dashboard.
                    </p>

                </div>

                <?php if ($sessionExpired): ?>

                    <div class="alert alert-warning">
                        <i class="bi bi-clock-history me-2"></i>
                        Your session expired. Please sign in again.
                    </div>

                <?php endif; ?>

                <?php if (!empty($error)): ?>

                    <div class="alert alert-danger d-flex align-items-center">
                        <i class="bi bi-exclamation-circle-fill me-2"></i>
                        <?= e($error) ?>
                    </div>

                <?php endif; ?>

                <form method="POST" action="login.php" id="loginForm">

                    <input
                        type="hidden"
                        name="csrf"
                        value="<?= e(csrf_token()) ?>"
                    >

                    <!-- EMAIL -->

                    <div class="mb-4">

                        <label for="email" class="form-label">
                            Email Address
                        </label>

                        <div class="input-group">

                            <span class="input-group-text">
                                <i class="bi bi-envelope"></i>
                            </span>

                            <input
                                type="email"
                                class="form-control"
                                id="email"
                                name="email"
                                maxlength="190"
                                value="<?= e($email) ?>"
                                placeholder="Enter your email"
                                autocomplete="username"
                                required
                                autofocus
                            >

                        </div>

                    </div>

                    <!-- PASSWORD -->

                    <div class="mb-4">

                        <label for="password" class="form-label">
                            Password
                        </label>

                        <div class="input-group">

                            <span class="input-group-text">
                                <i class="bi bi-lock"></i>
                            </span>

                            <input
                                type="password"
                                class="form-control"
                                id="password"
                                name="password"
                                placeholder="Enter your password"
                                autocomplete="current-password"
                                required
                            >

                            <button
                                type="button"
                                class="password-toggle"
                                id="togglePassword"
                                aria-label="Show password"
                            >
                                <i class="bi bi-eye" id="passwordIcon"></i>
                            </button>

                        </div>

                    </div>

                    <!-- LOGIN BUTTON -->

                    <button
                        type="submit"
                        class="login-button"
                        id="loginButton"
                    >

                        <span id="buttonText">
                            Sign In
                            <i class="bi bi-arrow-right ms-2"></i>
                        </span>

                        <span id="buttonLoading" class="d-none">
                            <span class="spinner-border spinner-border-sm me-2"></span>
                            Signing in...
                        </span>

                    </button>

                </form>

                <div class="security-note">

                    <i class="bi bi-shield-lock-fill"></i>

                    Secure access to your management system

                </div>

                <div class="login-bottom">
                    &copy; <?= date('Y') ?> MLFMS. All rights reserved.
                </div>

            </div>

        </div>

    </div>

</div>

<script>

    /*
    |--------------------------------------------------------------------------
    | SHOW / HIDE PASSWORD
    |--------------------------------------------------------------------------
    */

    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    const passwordIcon = document.getElementById('passwordIcon');

    togglePassword.addEventListener('click', function () {

        const isPassword = passwordInput.type === 'password';

        passwordInput.type = isPassword ? 'text' : 'password';

        passwordIcon.className = isPassword
            ? 'bi bi-eye-slash'
            : 'bi bi-eye';

        togglePassword.setAttribute(
            'aria-label',
            isPassword ? 'Hide password' : 'Show password'
        );

    });

    /*
    |--------------------------------------------------------------------------
    | LOGIN LOADING STATE
    |--------------------------------------------------------------------------
    */

    const loginForm = document.getElementById('loginForm');
    const loginButton = document.getElementById('loginButton');
    const buttonText = document.getElementById('buttonText');
    const buttonLoading = document.getElementById('buttonLoading');

    loginForm.addEventListener('submit', function () {

        if (!loginForm.checkValidity()) {
            return;
        }

        loginButton.disabled = true;

        buttonText.classList.add('d-none');
        buttonLoading.classList.remove('d-none');

    });

</script>

</body>
</html>
