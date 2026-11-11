
<?php

/*
|--------------------------------------------------------------------------
| SESSION SECURITY CONFIGURATION
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {

    // Prevent PHP from accepting session IDs through URLs.
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');

    // Session cookie security.
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => (
            !empty($_SERVER['HTTPS']) &&
            $_SERVER['HTTPS'] !== 'off'
        ),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();

    /*
    |--------------------------------------------------------------------------
    | SESSION TIMEOUT
    |--------------------------------------------------------------------------
    */

    $idleTimeout = 1800;       // 30 minutes
    $absoluteTimeout = 28800; // 8 hours

    $now = time();

    $lastActivity = $_SESSION['last_activity'] ?? $now;
    $loginTime = $_SESSION['login_time'] ?? $now;

    $sessionExpired =
        ($now - $lastActivity) > $idleTimeout ||
        ($now - $loginTime) > $absoluteTimeout;

    if ($sessionExpired && !empty($_SESSION['user_id'])) {

        $_SESSION = [];

        session_regenerate_id(true);

        $_SESSION['session_expired'] = true;

    } else {

        $_SESSION['last_activity'] = $now;
    }
}

/*
|--------------------------------------------------------------------------
| SESSION AUTHENTICATION HELPERS
|--------------------------------------------------------------------------
*/

/**
 * Securely initialize a user session after successful login.
 *
 * Call this only after verifying the user's password and account status.
 */
function secure_login_session(int $userId): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('Session is not active.');
    }

    // Generate a new session ID to prevent session fixation.
    session_regenerate_id(true);

    // Clear previous session data.
    $_SESSION = [];

    // Store authenticated user information.
    $_SESSION['user_id'] = $userId;
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();

    // Generate a fresh CSRF token.
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/**
 * Destroy the authenticated session.
 */
function secure_logout(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {

        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax'
            ]
        );
    }

    session_destroy();
}

/**
 * Invalidate an account's session.
 */
function invalidate_user_session(): void
{
    secure_logout();
}

/*
|--------------------------------------------------------------------------
| GENERAL HELPERS
|--------------------------------------------------------------------------
*/

/**
 * Safely escape output.
 */
function e($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/**
 * Require an authenticated user.
 */
function require_login(): void
{
    if (empty($_SESSION['user_id'])) {

        header('Location: login.php');
        exit;
    }

    // Confirm that the account still exists and is active.
    $user = current_user();

    if (!$user) {

        header('Location: login.php');
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| CSRF PROTECTION
|--------------------------------------------------------------------------
*/

/**
 * Generate or retrieve the current CSRF token.
 */
function csrf_token(): string
{
    if (
        empty($_SESSION['csrf']) ||
        !is_string($_SESSION['csrf'])
    ) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

/**
 * Verify a submitted CSRF token.
 */
function verify_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Method not allowed.');
    }

    $sessionToken = $_SESSION['csrf'] ?? '';
    $submittedToken = $_POST['csrf'] ?? '';

    if (
        !is_string($sessionToken) ||
        !is_string($submittedToken) ||
        $sessionToken === '' ||
        $submittedToken === '' ||
        !hash_equals($sessionToken, $submittedToken)
    ) {
        http_response_code(403);
        exit('Invalid request token. Refresh the page and try again.');
    }
}

/*
|--------------------------------------------------------------------------
| FLASH MESSAGES
|--------------------------------------------------------------------------
*/

function flash(string $message, string $type = 'success'): void
{
    $allowedTypes = [
        'success',
        'danger',
        'warning',
        'info',
        'primary',
        'secondary'
    ];

    if (!in_array($type, $allowedTypes, true)) {
        $type = 'info';
    }

    $_SESSION['flash'] = [
        'message' => $message,
        'type' => $type
    ];
}

function show_flash(): void
{
    if (!empty($_SESSION['flash'])) {

        $f = $_SESSION['flash'];

        unset($_SESSION['flash']);

        $allowedTypes = [
            'success',
            'danger',
            'warning',
            'info',
            'primary',
            'secondary'
        ];

        $type = in_array($f['type'] ?? '', $allowedTypes, true)
            ? $f['type']
            : 'info';

        echo '<div class="alert alert-' . e($type) . '">'
            . e($f['message'] ?? '')
            . '</div>';
    }
}

/*
|--------------------------------------------------------------------------
| FINANCIAL HELPER
|--------------------------------------------------------------------------
*/

function money($n): string
{
    return 'K' . number_format((float)$n, 2);
}

/*
|--------------------------------------------------------------------------
| DELIVERY PRICING
|--------------------------------------------------------------------------
*/

function price_delivery(
    string $vehicle,
    float $km,
    array $opts = []
): float {

    if (!is_finite($km) || $km <= 0) {
        throw new InvalidArgumentException(
            'Distance must be above zero.'
        );
    }

    if ($vehicle === 'bicycle') {

        if ($km > 6) {
            throw new InvalidArgumentException(
                'Bicycle delivery is limited to 6 km.'
            );
        }

        $price = $km <= 2 ? 15 : ($km <= 4 ? 22 : 30);

    } elseif ($vehicle === 'tricycle') {

        if ($km > 15) {
            throw new InvalidArgumentException(
                'Tricycle delivery is limited to 15 km.'
            );
        }

        $price = $km <= 3
            ? 50
            : ($km <= 6 ? 70 : ($km <= 10 ? 100 : 135));

    } else {

        throw new InvalidArgumentException(
            'Unsupported vehicle type.'
        );
    }

    $extraStops = max(
        0,
        (int)($opts['extra_stops'] ?? 0)
    );

    $waitMinutes = max(
        0,
        (int)($opts['wait_minutes'] ?? 0)
    );

    $price += $extraStops * 10;
    $price += $waitMinutes * 2;

    if (!empty($opts['rush'])) {
        $price *= 1.25;
    }

    if (!empty($opts['bulky'])) {
        $price += $vehicle === 'bicycle' ? 5 : 30;
    }

    if (!empty($opts['rain'])) {
        $price += $vehicle === 'bicycle' ? 5 : 10;
    }

    return round($price, 2);
}

/*
|--------------------------------------------------------------------------
| ROLE-BASED ACCESS CONTROL (RBAC)
|--------------------------------------------------------------------------
*/

/**
 * Get the currently logged-in user's details and role.
 */
function current_user(): ?array
{
    global $pdo;

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $user = false;

    if ($user !== false) {
        return $user;
    }

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException(
            'Database connection is unavailable.'
        );
    }

    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.name,
            u.email,
            u.status,
            r.name AS role
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE u.id = ?
        LIMIT 1
    ");

    $stmt->execute([
        (int)$_SESSION['user_id']
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // Invalidate deleted or inactive accounts immediately.
    if (!$user || $user['status'] !== 'active') {

        invalidate_user_session();

        $user = null;

        return null;
    }

    return $user;
}

/**
 * Check whether the logged-in user has a specified role.
 */
function has_role($roles): bool
{
    $user = current_user();

    if (!$user) {
        return false;
    }

    return in_array(
        $user['role'],
        (array)$roles,
        true
    );
}

/*
|--------------------------------------------------------------------------
| MODULE PERMISSIONS
|--------------------------------------------------------------------------
*/

function module_permissions(): array
{
    return [

        'dashboard' => [
            'administrator',
            'dispatcher',
            'finance',
            'marketing',
            'fleet_manager'
        ],

        'customers' => [
            'administrator',
            'dispatcher',
            'marketing'
        ],

        'deliveries' => [
            'administrator',
            'dispatcher'
        ],

        'dispatch' => [
            'administrator',
            'dispatcher'
        ],

        'delivery_monitor' => [
            'administrator',
            'dispatcher'
        ],

        'drivers' => [
            'administrator',
            'dispatcher',
            'fleet_manager'
        ],

        'fleet' => [
            'administrator',
            'fleet_manager'
        ],

        'maintenance' => [
            'administrator',
            'fleet_manager'
        ],

        'payments' => [
            'administrator',
            'finance'
        ],

        'finance' => [
            'administrator',
            'finance'
        ],

        'users' => [
            'administrator'
        ]
    ];
}

/**
 * Check whether a user can access a module.
 */
function can_access($module): bool
{
    $permissions = module_permissions();

    $user = current_user();

    if (!$user) {
        return false;
    }

    if (!isset($permissions[$module])) {
        return false;
    }

    return in_array(
        $user['role'],
        $permissions[$module],
        true
    );
}

/**
 * Require permission to access a module.
 */
function require_permission($module): void
{
    require_login();

    if (!can_access($module)) {

        http_response_code(403);

        exit(
            '403 Forbidden: You do not have permission to access this module.'
        );
    }
}

/**
 * Require a specific role or group of roles.
 */
function require_role($roles): void
{
    require_login();

    if (!has_role($roles)) {

        http_response_code(403);

        exit(
            '403 Forbidden: You do not have permission to access this page.'
        );
    }
}
