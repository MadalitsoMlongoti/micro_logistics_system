
<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

require_login();
require_permission('customers');

$page_title = 'Customers';
$currentPage = 'customers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$message = '';
$messageType = 'success';

/*
|--------------------------------------------------------------------------
| CUSTOMER CODE GENERATOR
|--------------------------------------------------------------------------
*/

function generateCustomerCode(PDO $pdo): string
{
    do {
        $code = 'CUS-' . date('ym') . '-' . strtoupper(bin2hex(random_bytes(3)));

        $stmt = $pdo->prepare(
            "SELECT id FROM customers WHERE customer_code = ?"
        );

        $stmt->execute([$code]);

    } while ($stmt->fetch());

    return $code;
}

/*
|--------------------------------------------------------------------------
| HANDLE CUSTOMER FORMS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    /*
    |--------------------------------------------------------------------------
    | ADD CUSTOMER
    |--------------------------------------------------------------------------
    */

    if (isset($_POST['add_customer'])) {

        $customerType = $_POST['customer_type'] ?? 'Individual';
        $fullName = trim($_POST['full_name'] ?? '');
        $companyName = trim($_POST['company_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $payment = $_POST['preferred_payment'] ?? 'Cash';

        $allowedTypes = ['Individual', 'Business'];
        $allowedPayments = ['Cash', 'Mobile Money', 'Bank', 'Invoice'];

        try {

            if (
                !in_array($customerType, $allowedTypes, true) ||
                !in_array($payment, $allowedPayments, true)
            ) {
                throw new InvalidArgumentException(
                    'Invalid customer information.'
                );
            }

            if ($fullName === '' || $phone === '') {
                throw new InvalidArgumentException(
                    'Full name and phone number are required.'
                );
            }

            if (strlen($fullName) > 150 || strlen($companyName) > 150) {
                throw new InvalidArgumentException(
                    'Customer name exceeds the permitted length.'
                );
            }

            if (strlen($phone) > 30) {
                throw new InvalidArgumentException(
                    'Phone number is too long.'
                );
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException(
                    'Please enter a valid email address.'
                );
            }

            if (strlen($email) > 150 || strlen($address) > 1000) {
                throw new InvalidArgumentException(
                    'Email or address exceeds the permitted length.'
                );
            }

            if ($customerType === 'Individual') {
                $companyName = '';
            }

            $customerCode = generateCustomerCode($pdo);

            $stmt = $pdo->prepare("
                INSERT INTO customers (
                    customer_code,
                    customer_type,
                    full_name,
                    company_name,
                    phone,
                    email,
                    address,
                    preferred_payment
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $customerCode,
                $customerType,
                $fullName,
                $companyName ?: null,
                $phone,
                $email ?: null,
                $address ?: null,
                $payment
            ]);

            flash(
                'Customer registered successfully. Customer code: ' . $customerCode,
                'success'
            );

        } catch (InvalidArgumentException $e) {

            flash($e->getMessage(), 'danger');

        } catch (PDOException $e) {

            error_log('Customer Registration Error: ' . $e->getMessage());

            flash(
                'Unable to register customer. Check whether the phone or email already exists.',
                'danger'
            );
        }

        header('Location: customers.php');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | ACTIVATE / DEACTIVATE CUSTOMER
    |--------------------------------------------------------------------------
    */

    if (isset($_POST['toggle_status'])) {

        $customerId = (int)($_POST['customer_id'] ?? 0);

        if ($customerId <= 0) {

            flash('Invalid customer selected.', 'danger');

        } else {

            $stmt = $pdo->prepare("
                UPDATE customers
                SET status = IF(status = 'Active', 'Inactive', 'Active')
                WHERE id = ?
            ");

            $stmt->execute([$customerId]);

            flash(
                $stmt->rowCount() > 0
                    ? 'Customer status updated successfully.'
                    : 'Customer record was not found.',
                $stmt->rowCount() > 0 ? 'success' : 'warning'
            );
        }

        header('Location: customers.php');
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| FILTERS AND PAGINATION
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');
$typeFilter = $_GET['type'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$allowedTypes = ['', 'Individual', 'Business'];
$allowedStatuses = ['', 'Active', 'Inactive'];

if (!in_array($typeFilter, $allowedTypes, true)) {
    $typeFilter = '';
}

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

$where = [];
$params = [];

if ($search !== '') {

    $where[] = "(
        customer_code LIKE ?
        OR full_name LIKE ?
        OR company_name LIKE ?
        OR phone LIKE ?
        OR email LIKE ?
    )";

    $term = '%' . $search . '%';

    array_push($params, $term, $term, $term, $term, $term);
}

if ($typeFilter !== '') {
    $where[] = "customer_type = ?";
    $params[] = $typeFilter;
}

if ($statusFilter !== '') {
    $where[] = "status = ?";
    $params[] = $statusFilter;
}

$whereSql = $where
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

/*
|--------------------------------------------------------------------------
| TOTAL FILTERED CUSTOMERS
|--------------------------------------------------------------------------
*/

$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM customers
    $whereSql
");

$countStmt->execute($params);

$filteredCount = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($filteredCount / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| FETCH CUSTOMERS
|--------------------------------------------------------------------------
*/

$customerStmt = $pdo->prepare("
    SELECT *
    FROM customers
    $whereSql
    ORDER BY id DESC
    LIMIT $perPage OFFSET $offset
");

$customerStmt->execute($params);

$customers = $customerStmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| CUSTOMER STATISTICS
|--------------------------------------------------------------------------
*/

$totalCustomers = (int)$pdo->query("
    SELECT COUNT(*) FROM customers
")->fetchColumn();

$activeCustomers = (int)$pdo->query("
    SELECT COUNT(*) FROM customers WHERE status = 'Active'
")->fetchColumn();

$inactiveCustomers = (int)$pdo->query("
    SELECT COUNT(*) FROM customers WHERE status = 'Inactive'
")->fetchColumn();

$businessCustomers = (int)$pdo->query("
    SELECT COUNT(*) FROM customers WHERE customer_type = 'Business'
")->fetchColumn();

$individualCustomers = (int)$pdo->query("
    SELECT COUNT(*) FROM customers WHERE customer_type = 'Individual'
")->fetchColumn();

/*
|--------------------------------------------------------------------------
| FETCH DELIVERY HISTORY FOR CURRENT PAGE
|--------------------------------------------------------------------------
*/

$customerDeliveries = [];

if (!empty($customers)) {

    $customerIds = array_map(
        'intval',
        array_column($customers, 'id')
    );

    $placeholders = implode(
        ',',
        array_fill(0, count($customerIds), '?')
    );

    $deliveryStmt = $pdo->prepare("
        SELECT
            id,
            customer_id,
            order_code,
            pickup_address,
            dropoff_address,
            vehicle_type,
            distance_km,
            total_price,
            payment_method,
            payment_status,
            status,
            created_at
        FROM deliveries
        WHERE customer_id IN ($placeholders)
        ORDER BY created_at DESC
    ");

    $deliveryStmt->execute($customerIds);

    foreach ($deliveryStmt->fetchAll(PDO::FETCH_ASSOC) as $delivery) {
        $customerDeliveries[$delivery['customer_id']][] = $delivery;
    }
}

/*
|--------------------------------------------------------------------------
| PAGE URL HELPER
|--------------------------------------------------------------------------
*/

$queryBase = [
    'search' => $search,
    'type' => $typeFilter,
    'status' => $statusFilter
];

$paginationUrl = static function (int $targetPage) use ($queryBase): string {

    $query = array_filter(
        $queryBase,
        static fn($value) => $value !== ''
    );

    $query['page'] = $targetPage;

    return 'customers.php?' . http_build_query($query);
};

require_once __DIR__ . '/includes/header.php';
?>

<style>
.customer-hero {
    background: linear-gradient(120deg, #172554, #1d4ed8);
    color: #fff;
    padding: 27px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}

.customer-hero::after {
    content: "";
    position: absolute;
    width: 200px;
    height: 200px;
    right: -60px;
    top: -75px;
    border: 27px solid rgba(255,255,255,.08);
    border-radius: 50%;
}

.customer-hero-content {
    position: relative;
    z-index: 1;
}

.customer-hero-icon {
    width: 54px;
    height: 54px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 14px;
    background: rgba(255,255,255,.15);
    font-size: 25px;
}

.customer-stat-card {
    background: #fff;
    border: 1px solid #e9edf3;
    border-radius: 13px;
    padding: 19px;
    height: 100%;
    transition: .2s ease;
}

.customer-stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 7px 20px rgba(15,23,42,.07);
}

.customer-stat-icon {
    width: 42px;
    height: 42px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    font-size: 19px;
}

.customer-stat-label {
    color: #64748b;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
}

.customer-stat-value {
    font-size: 27px;
    font-weight: 750;
    color: #172033;
    margin-top: 13px;
    margin-bottom: 0;
}

.customer-section {
    border: 1px solid #e9edf3;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(15,23,42,.025);
}

.customer-section .card-header {
    background: #fff;
    border-bottom: 1px solid #edf0f4;
    padding: 17px 20px;
}

.customer-section .card-body {
    padding: 20px;
}

.customer-table {
    min-width: 1050px;
    margin-bottom: 0;
}

.customer-table thead th {
    background: #f8fafc;
    color: #64748b;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .5px;
    font-weight: 700;
    padding: 15px 13px;
    white-space: nowrap;
}

.customer-table tbody td {
    padding: 15px 13px;
    border-color: #edf0f4;
    font-size: 13px;
}

.customer-table tbody tr:hover {
    background: #f8fbff;
}

.customer-avatar {
    width: 39px;
    height: 39px;
    border-radius: 12px;
    background: #eff6ff;
    color: #1d4ed8;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 14px;
    flex-shrink: 0;
}

.customer-code {
    color: #1d4ed8;
    font-weight: 700;
    font-size: 12px;
}

.customer-contact {
    color: #64748b;
    font-size: 12px;
}

.customer-type-individual {
    background: #eff6ff;
    color: #1d4ed8;
}

.customer-type-business {
    background: #f3e8ff;
    color: #7e22ce;
}

.customer-status-active {
    background: #dcfce7;
    color: #15803d;
}

.customer-status-inactive {
    background: #f1f5f9;
    color: #64748b;
}

.customer-payment {
    background: #f1f5f9;
    color: #475569;
}

.customer-modal-section {
    background: #f8fafc;
    border: 1px solid #edf0f4;
    border-radius: 11px;
    padding: 16px;
}

.customer-modal-section-title {
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #475569;
    margin-bottom: 15px;
}

.customer-form-label {
    font-size: 12px;
    font-weight: 700;
    color: #475569;
    margin-bottom: 7px;
}

.customer-form-control {
    min-height: 42px;
    font-size: 13px;
    border-color: #e2e8f0;
}

.customer-form-control:focus {
    border-color: #93c5fd;
    box-shadow: 0 0 0 3px rgba(59,130,246,.10);
}

.customer-empty {
    text-align: center;
    padding: 40px 15px;
    color: #94a3b8;
}

.customer-empty i {
    display: block;
    font-size: 38px;
    margin-bottom: 12px;
}

.customer-action-btn {
    white-space: nowrap;
}

.customer-history-table {
    min-width: 850px;
}

.customer-history-table th {
    white-space: nowrap;
    font-size: 11px;
    text-transform: uppercase;
    color: #64748b;
    background: #f8fafc;
}

.customer-history-table td {
    font-size: 12px;
    vertical-align: middle;
}

.customer-pagination .page-link {
    color: #1d4ed8;
}

.customer-pagination .active .page-link {
    background: #1d4ed8;
    border-color: #1d4ed8;
}

@media(max-width:767px) {
    .customer-hero {
        padding: 20px;
    }

    .customer-hero h2 {
        font-size: 21px;
    }

    .customer-section .card-body {
        padding: 14px;
    }

    .customer-stat-card {
        padding: 15px;
    }
}
</style>

<div class="container-fluid py-3">

    <!-- PAGE HEADER -->

    <div class="customer-hero mb-4">

        <div class="customer-hero-content d-flex justify-content-between align-items-center flex-wrap gap-3">

            <div class="d-flex align-items-center gap-3">

                <div class="customer-hero-icon">
                    <i class="bi bi-people-fill"></i>
                </div>

                <div>
                    <h2 class="fw-bold mb-1">Customer Management</h2>
                    <p class="mb-0 text-white-50">
                        Manage customer accounts, profiles and delivery history.
                    </p>
                </div>

            </div>

            <button
                class="btn btn-light fw-semibold px-3"
                data-bs-toggle="modal"
                data-bs-target="#customerModal"
            >
                <i class="bi bi-person-plus-fill me-1"></i>
                Add Customer
            </button>

        </div>

    </div>

    <?php show_flash(); ?>

    <!-- STATISTICS -->

    <?php
    $statistics = [
        ['Total Customers', $totalCustomers, 'bi-people-fill', 'primary', '#eff6ff'],
        ['Active Customers', $activeCustomers, 'bi-person-check-fill', 'success', '#f0fdf4'],
        ['Business Customers', $businessCustomers, 'bi-building', 'info', '#ecfeff'],
        ['Individual Customers', $individualCustomers, 'bi-person', 'primary', '#eff6ff'],
        ['Inactive Customers', $inactiveCustomers, 'bi-person-dash-fill', 'secondary', '#f1f5f9']
    ];
    ?>

    <div class="row g-3 mb-4">

        <?php foreach ($statistics as $stat): ?>

            <div class="col-6 col-md-4 col-xl">

                <div class="customer-stat-card">

                    <div class="d-flex justify-content-between align-items-center">

                        <span class="customer-stat-label">
                            <?= e($stat[0]) ?>
                        </span>

                        <span
                            class="customer-stat-icon text-<?= e($stat[3]) ?>"
                            style="background:<?= e($stat[4]) ?>;"
                        >
                            <i class="bi <?= e($stat[2]) ?>"></i>
                        </span>

                    </div>

                    <h3 class="customer-stat-value">
                        <?= number_format((int)$stat[1]) ?>
                    </h3>

                </div>

            </div>

        <?php endforeach; ?>

    </div>

    <!-- SEARCH AND FILTERS -->

    <div class="card customer-section mb-4">

        <div class="card-body">

            <form method="GET">

                <div class="row g-3 align-items-end">

                    <div class="col-lg-5">

                        <label class="customer-form-label">
                            Search Customer Directory
                        </label>

                        <div class="input-group">

                            <span class="input-group-text bg-light">
                                <i class="bi bi-search text-muted"></i>
                            </span>

                            <input
                                type="text"
                                name="search"
                                class="form-control customer-form-control"
                                placeholder="Name, phone, company or customer code..."
                                value="<?= e($search) ?>"
                            >

                        </div>

                    </div>

                    <div class="col-md-4 col-lg-2">

                        <label class="customer-form-label">Customer Type</label>

                        <select name="type" class="form-select customer-form-control">

                            <option value="">All Types</option>
                            <option value="Individual" <?= $typeFilter === 'Individual' ? 'selected' : '' ?>>
                                Individual
                            </option>
                            <option value="Business" <?= $typeFilter === 'Business' ? 'selected' : '' ?>>
                                Business
                            </option>

                        </select>

                    </div>

                    <div class="col-md-4 col-lg-2">

                        <label class="customer-form-label">Account Status</label>

                        <select name="status" class="form-select customer-form-control">

                            <option value="">All Statuses</option>
                            <option value="Active" <?= $statusFilter === 'Active' ? 'selected' : '' ?>>
                                Active
                            </option>
                            <option value="Inactive" <?= $statusFilter === 'Inactive' ? 'selected' : '' ?>>
                                Inactive
                            </option>

                        </select>

                    </div>

                    <div class="col-md-4 col-lg-3">

                        <div class="d-flex gap-2">

                            <button class="btn btn-primary flex-grow-1">
                                <i class="bi bi-search me-1"></i>
                                Apply Filters
                            </button>

                            <a href="customers.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </a>

                        </div>

                    </div>

                </div>

            </form>

        </div>

    </div>

    <!-- CUSTOMER DIRECTORY -->

    <div class="card customer-section">

        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">

            <div>
                <h5 class="fw-bold mb-1">
                    <i class="bi bi-person-lines-fill text-primary me-2"></i>
                    Customer Directory
                </h5>

                <small class="text-muted">
                    View customer information, delivery records and account status.
                </small>
            </div>

            <span class="badge rounded-pill px-3 py-2"
                  style="background:#eff6ff;color:#1d4ed8;">

                <?= number_format($filteredCount) ?> customers found

            </span>

        </div>

        <div class="card-body p-0">

            <div class="table-responsive">

                <table class="table table-hover align-middle customer-table">

                    <thead>
                        <tr>
                            <th>Customer Code</th>
                            <th>Customer Information</th>
                            <th>Contact</th>
                            <th>Type</th>
                            <th>Payment</th>
                            <th>Status</th>
                            <th>Registered</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (empty($customers)): ?>

                        <tr>
                            <td colspan="8">
                                <div class="customer-empty">
                                    <i class="bi bi-people"></i>
                                    <strong class="d-block text-dark">No customers found</strong>
                                    <small>Try changing your filters or register a new customer.</small>
                                </div>
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($customers as $customer): ?>

                            <?php
                            $customerName = $customer['full_name'] ?? '';
                            $avatarSource = !empty($customer['company_name'])
                                ? $customer['company_name']
                                : $customerName;

                            $initials = strtoupper(substr($avatarSource, 0, 1));

                            $isActive = ($customer['status'] ?? '') === 'Active';

                            $typeClass = ($customer['customer_type'] ?? '') === 'Business'
                                ? 'customer-type-business'
                                : 'customer-type-individual';

                            $history = $customerDeliveries[$customer['id']] ?? [];
                            $historyCount = count($history);

                            $canCreateDelivery = can_access('deliveries');
                            ?>

                            <tr>

                                <td>
                                    <span class="customer-code">
                                        <?= e($customer['customer_code']) ?>
                                    </span>
                                </td>

                                <td>

                                    <div class="d-flex align-items-center gap-2">

                                        <div class="customer-avatar">
                                            <?= e($initials) ?>
                                        </div>

                                        <div>
                                            <div class="fw-bold text-dark">
                                                <?= e($customer['full_name']) ?>
                                            </div>

                                            <small class="text-muted">
                                                <?= !empty($customer['company_name'])
                                                    ? e($customer['company_name'])
                                                    : 'Individual account' ?>
                                            </small>
                                        </div>

                                    </div>

                                </td>

                                <td>

                                    <div class="customer-contact mb-1">
                                        <i class="bi bi-telephone me-1"></i>
                                        <?= e($customer['phone']) ?>
                                    </div>

                                    <?php if (!empty($customer['email'])): ?>
                                        <div class="customer-contact">
                                            <i class="bi bi-envelope me-1"></i>
                                            <?= e($customer['email']) ?>
                                        </div>
                                    <?php endif; ?>

                                </td>

                                <td>
                                    <span class="badge <?= $typeClass ?> rounded-pill px-3 py-2">
                                        <?= e($customer['customer_type']) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="badge customer-payment rounded-pill px-3 py-2">
                                        <?= e($customer['preferred_payment']) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="badge <?= $isActive ? 'customer-status-active' : 'customer-status-inactive' ?> rounded-pill px-3 py-2">
                                        <?= e($customer['status']) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="text-dark small">
                                        <?= date('d M Y', strtotime($customer['created_at'])) ?>
                                    </div>
                                    <small class="text-muted">
                                        <?= date('H:i', strtotime($customer['created_at'])) ?>
                                    </small>
                                </td>

                                <td>

                                    <div class="d-flex gap-2 flex-wrap">

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-primary customer-action-btn"
                                            data-bs-toggle="modal"
                                            data-bs-target="#customerDetails<?= (int)$customer['id'] ?>"
                                        >
                                            <i class="bi bi-eye me-1"></i>
                                            View
                                        </button>

                                        <form
                                            method="POST"
                                            onsubmit="return confirm('Change this customer account status?')"
                                        >

                                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

                                            <input
                                                type="hidden"
                                                name="customer_id"
                                                value="<?= (int)$customer['id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                name="toggle_status"
                                                class="btn btn-sm <?= $isActive ? 'btn-outline-danger' : 'btn-outline-success' ?> customer-action-btn"
                                            >
                                                <i class="bi <?= $isActive ? 'bi-person-dash' : 'bi-person-check' ?> me-1"></i>
                                                <?= $isActive ? 'Deactivate' : 'Activate' ?>
                                            </button>

                                        </form>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

        <!-- PAGINATION -->

        <div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap gap-3">

            <small class="text-muted">

                Showing
                <?= $filteredCount > 0 ? number_format($offset + 1) : 0 ?>
                to
                <?= number_format(min($offset + $perPage, $filteredCount)) ?>
                of
                <?= number_format($filteredCount) ?>
                customers

            </small>

            <?php if ($totalPages > 1): ?>

                <nav>
                    <ul class="pagination pagination-sm customer-pagination mb-0">

                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e($paginationUrl(max(1, $page - 1))) ?>">
                                Previous
                            </a>
                        </li>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        ?>

                        <?php for ($p = $startPage; $p <= $endPage; $p++): ?>

                            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                <a class="page-link" href="<?= e($paginationUrl($p)) ?>">
                                    <?= $p ?>
                                </a>
                            </li>

                        <?php endfor; ?>

                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e($paginationUrl(min($totalPages, $page + 1))) ?>">
                                Next
                            </a>
                        </li>

                    </ul>
                </nav>

            <?php endif; ?>

        </div>

    </div>

</div>

<!-- CUSTOMER PROFILE MODALS -->

<?php foreach ($customers as $customer): ?>

    <?php
    $customerId = (int)$customer['id'];
    $history = $customerDeliveries[$customerId] ?? [];

    $isActive = ($customer['status'] ?? '') === 'Active';

    $avatarSource = !empty($customer['company_name'])
        ? $customer['company_name']
        : $customer['full_name'];

    $initials = strtoupper(substr($avatarSource, 0, 1));

    $typeClass = ($customer['customer_type'] ?? '') === 'Business'
        ? 'customer-type-business'
        : 'customer-type-individual';
    ?>

    <div
        class="modal fade"
        id="customerDetails<?= $customerId ?>"
        tabindex="-1"
        aria-hidden="true"
    >

        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">

            <div class="modal-content border-0 shadow-lg">

                <div
                    class="modal-header text-white"
                    style="background:linear-gradient(120deg,#172554,#1d4ed8);"
                >

                    <div class="d-flex align-items-center gap-3">

                        <div class="customer-hero-icon"
                             style="width:45px;height:45px;font-size:20px;">
                            <i class="bi bi-person-vcard-fill"></i>
                        </div>

                        <div>
                            <h5 class="modal-title fw-bold mb-1">Customer Profile</h5>
                            <small class="text-white-50">
                                <?= e($customer['customer_code']) ?>
                            </small>
                        </div>

                    </div>

                    <button
                        type="button"
                        class="btn-close btn-close-white"
                        data-bs-dismiss="modal"
                    ></button>

                </div>

                <div class="modal-body p-4">

                    <!-- PROFILE SUMMARY -->

                    <div class="d-flex align-items-center flex-wrap gap-3 mb-4">

                        <div class="customer-avatar"
                             style="width:65px;height:65px;font-size:23px;">
                            <?= e($initials) ?>
                        </div>

                        <div class="flex-grow-1">

                            <h4 class="fw-bold text-dark mb-1">
                                <?= e($customer['full_name']) ?>
                            </h4>

                            <?php if (!empty($customer['company_name'])): ?>
                                <div class="text-muted small">
                                    <i class="bi bi-building me-1"></i>
                                    <?= e($customer['company_name']) ?>
                                </div>
                            <?php endif; ?>

                            <div class="mt-2">

                                <span class="badge <?= $typeClass ?> rounded-pill px-3 py-2">
                                    <?= e($customer['customer_type']) ?>
                                </span>

                                <span class="badge <?= $isActive ? 'customer-status-active' : 'customer-status-inactive' ?> rounded-pill px-3 py-2">
                                    <?= e($customer['status']) ?>
                                </span>

                            </div>

                        </div>

                        <?php if ($isActive && can_access('deliveries')): ?>

                            <a
                                href="deliveries.php?customer_id=<?= $customerId ?>#newDelivery"
                                class="btn btn-primary"
                            >
                                <i class="bi bi-plus-circle me-1"></i>
                                Create Delivery
                            </a>

                        <?php elseif (!$isActive): ?>

                            <span class="text-muted small">
                                Activate this customer before creating a delivery.
                            </span>

                        <?php endif; ?>

                    </div>

                    <!-- PROFILE DETAILS -->

                    <div class="row g-3 mb-4">

                        <div class="col-lg-6">

                            <div class="customer-modal-section h-100">

                                <div class="customer-modal-section-title">
                                    <i class="bi bi-person-lines-fill text-primary me-2"></i>
                                    Contact Information
                                </div>

                                <div class="mb-3">
                                    <small class="text-muted d-block">Phone Number</small>
                                    <strong><?= e($customer['phone']) ?></strong>
                                </div>

                                <div class="mb-3">
                                    <small class="text-muted d-block">Email Address</small>
                                    <strong><?= e($customer['email'] ?: 'Not provided') ?></strong>
                                </div>

                                <div>
                                    <small class="text-muted d-block">Address</small>
                                    <strong>
                                        <?= nl2br(e($customer['address'] ?: 'Not provided')) ?>
                                    </strong>
                                </div>

                            </div>

                        </div>

                        <div class="col-lg-6">

                            <div class="customer-modal-section h-100">

                                <div class="customer-modal-section-title">
                                    <i class="bi bi-wallet2 text-primary me-2"></i>
                                    Account Information
                                </div>

                                <div class="mb-3">
                                    <small class="text-muted d-block">Customer Code</small>
                                    <strong class="customer-code">
                                        <?= e($customer['customer_code']) ?>
                                    </strong>
                                </div>

                                <div class="mb-3">
                                    <small class="text-muted d-block">Preferred Payment</small>
                                    <strong><?= e($customer['preferred_payment']) ?></strong>
                                </div>

                                <div>
                                    <small class="text-muted d-block">Registration Date</small>
                                    <strong>
                                        <?= date('d M Y, H:i', strtotime($customer['created_at'])) ?>
                                    </strong>
                                </div>

                            </div>

                        </div>

                    </div>

                    <!-- DELIVERY SUMMARY -->

                    <?php
                    $deliveryCount = count($history);
                    $deliveredCount = 0;
                    $activeCount = 0;
                    $totalDeliveryValue = 0;

                    foreach ($history as $delivery) {

                        $totalDeliveryValue += (float)$delivery['total_price'];

                        if ($delivery['status'] === 'delivered') {
                            $deliveredCount++;
                        }

                        if (in_array(
                            $delivery['status'],
                            ['assigned', 'picked_up', 'in_transit'],
                            true
                        )) {
                            $activeCount++;
                        }
                    }
                    ?>

                    <div class="row g-3 mb-3">

                        <div class="col-6 col-lg-3">
                            <div class="customer-modal-section">
                                <small class="text-muted">Total Deliveries</small>
                                <h4 class="fw-bold mb-0"><?= number_format($deliveryCount) ?></h4>
                            </div>
                        </div>

                        <div class="col-6 col-lg-3">
                            <div class="customer-modal-section">
                                <small class="text-muted">Completed</small>
                                <h4 class="fw-bold text-success mb-0"><?= number_format($deliveredCount) ?></h4>
                            </div>
                        </div>

                        <div class="col-6 col-lg-3">
                            <div class="customer-modal-section">
                                <small class="text-muted">In Progress</small>
                                <h4 class="fw-bold text-primary mb-0"><?= number_format($activeCount) ?></h4>
                            </div>
                        </div>

                        <div class="col-6 col-lg-3">
                            <div class="customer-modal-section">
                                <small class="text-muted">Total Order Value</small>
                                <h4 class="fw-bold mb-0"><?= money($totalDeliveryValue) ?></h4>
                            </div>
                        </div>

                    </div>

                    <!-- DELIVERY HISTORY -->

                    <div class="card border-0"
                         style="background:#f8fafc;border-radius:12px;">

                        <div class="card-header bg-transparent border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">

                            <div>
                                <h6 class="fw-bold mb-1">
                                    <i class="bi bi-truck text-primary me-2"></i>
                                    Delivery History
                                </h6>

                                <small class="text-muted">
                                    Orders linked to this customer account.
                                </small>
                            </div>

                            <span class="badge rounded-pill px-3 py-2"
                                  style="background:#eff6ff;color:#1d4ed8;">
                                <?= number_format($deliveryCount) ?> Deliveries
                            </span>

                        </div>

                        <div class="card-body p-0">

                            <?php if (empty($history)): ?>

                                <div class="customer-empty">
                                    <i class="bi bi-box-seam"></i>
                                    <strong class="d-block text-dark">No delivery history</strong>
                                    <small>This customer has not placed any linked delivery orders yet.</small>
                                </div>

                            <?php else: ?>

                                <div class="table-responsive">

                                    <table class="table table-hover align-middle customer-history-table mb-0">

                                        <thead>
                                            <tr>
                                                <th>Order Code</th>
                                                <th>Route</th>
                                                <th>Vehicle</th>
                                                <th>Amount</th>
                                                <th>Payment</th>
                                                <th>Status</th>
                                                <th>Date</th>
                                            </tr>
                                        </thead>

                                        <tbody>

                                        <?php foreach ($history as $delivery): ?>

                                            <?php
                                            $deliveryStatus = strtolower($delivery['status']);

                                            $deliveryStatusClasses = [
                                                'pending' => 'bg-warning text-dark',
                                                'assigned' => 'bg-info text-dark',
                                                'picked_up' => 'bg-primary',
                                                'in_transit' => 'bg-primary',
                                                'delivered' => 'bg-success',
                                                'cancelled' => 'bg-danger'
                                            ];

                                            $deliveryStatusClass =
                                                $deliveryStatusClasses[$deliveryStatus]
                                                ?? 'bg-secondary';

                                            $paymentStatus = $delivery['payment_status'] ?? 'unpaid';

                                            $paymentClass = [
                                                'paid' => 'text-success',
                                                'partial' => 'text-warning',
                                                'unpaid' => 'text-danger'
                                            ][$paymentStatus] ?? 'text-secondary';
                                            ?>

                                            <tr>

                                                <td>
                                                    <strong class="customer-code">
                                                        <?= e($delivery['order_code']) ?>
                                                    </strong>
                                                </td>

                                                <td>
                                                    <div class="small">
                                                        <i class="bi bi-circle-fill text-success me-1"></i>
                                                        <?= e($delivery['pickup_address']) ?>
                                                    </div>
                                                    <div class="small mt-1">
                                                        <i class="bi bi-geo-alt-fill text-danger me-1"></i>
                                                        <?= e($delivery['dropoff_address']) ?>
                                                    </div>
                                                </td>

                                                <td>
                                                    <?= e(ucfirst($delivery['vehicle_type'])) ?>
                                                    <div class="text-muted small">
                                                        <?= e((string)$delivery['distance_km']) ?> KM
                                                    </div>
                                                </td>

                                                <td class="fw-semibold">
                                                    <?= money($delivery['total_price']) ?>
                                                </td>

                                                <td>
                                                    <span class="<?= e($paymentClass) ?>">
                                                        <?= e(ucfirst($paymentStatus)) ?>
                                                    </span>
                                                </td>

                                                <td>
                                                    <span class="badge <?= e($deliveryStatusClass) ?>">
                                                        <?= e(ucwords(str_replace('_', ' ', $delivery['status']))) ?>
                                                    </span>
                                                </td>

                                                <td class="small text-muted">
                                                    <?= date('d M Y', strtotime($delivery['created_at'])) ?>
                                                </td>

                                            </tr>

                                        <?php endforeach; ?>

                                        </tbody>

                                    </table>

                                </div>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

                <div class="modal-footer bg-light">

                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        data-bs-dismiss="modal"
                    >
                        <i class="bi bi-x-circle me-1"></i>
                        Close
                    </button>

                </div>

            </div>

        </div>

    </div>

<?php endforeach; ?>

<!-- ADD CUSTOMER MODAL -->

<div class="modal fade" id="customerModal" tabindex="-1">

    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">

        <div class="modal-content border-0 shadow-lg">

            <form method="POST">

                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

                <div class="modal-header text-white"
                     style="background:linear-gradient(120deg,#172554,#1d4ed8);">

                    <div>
                        <h5 class="modal-title fw-bold mb-1">
                            <i class="bi bi-person-plus-fill me-2"></i>
                            Register New Customer
                        </h5>

                        <small class="text-white-50">
                            Enter the customer's information below.
                        </small>
                    </div>

                    <button
                        type="button"
                        class="btn-close btn-close-white"
                        data-bs-dismiss="modal"
                    ></button>

                </div>

                <div class="modal-body p-4">

                    <div class="customer-modal-section mb-3">

                        <div class="customer-modal-section-title">
                            <i class="bi bi-person-badge me-2 text-primary"></i>
                            Customer Classification
                        </div>

                        <div class="row g-3">

                            <div class="col-md-6">

                                <label class="customer-form-label">Customer Type *</label>

                                <select
                                    name="customer_type"
                                    class="form-select customer-form-control"
                                    id="customerType"
                                    required
                                >
                                    <option value="Individual">Individual Customer</option>
                                    <option value="Business">Business Customer</option>
                                </select>

                            </div>

                            <div class="col-md-6">

                                <label class="customer-form-label">Full Name *</label>

                                <input
                                    type="text"
                                    name="full_name"
                                    class="form-control customer-form-control"
                                    maxlength="150"
                                    required
                                >

                            </div>

                            <div class="col-12" id="companyField" style="display:none;">

                                <label class="customer-form-label">Company Name</label>

                                <input
                                    type="text"
                                    name="company_name"
                                    id="companyName"
                                    class="form-control customer-form-control"
                                    maxlength="150"
                                >

                            </div>

                        </div>

                    </div>

                    <div class="customer-modal-section mb-3">

                        <div class="customer-modal-section-title">
                            <i class="bi bi-telephone me-2 text-primary"></i>
                            Contact Information
                        </div>

                        <div class="row g-3">

                            <div class="col-md-6">

                                <label class="customer-form-label">Phone Number *</label>

                                <input
                                    type="tel"
                                    name="phone"
                                    class="form-control customer-form-control"
                                    maxlength="30"
                                    placeholder="+260..."
                                    required
                                >

                            </div>

                            <div class="col-md-6">

                                <label class="customer-form-label">Email Address</label>

                                <input
                                    type="email"
                                    name="email"
                                    class="form-control customer-form-control"
                                    maxlength="150"
                                >

                            </div>

                            <div class="col-12">

                                <label class="customer-form-label">Customer Address</label>

                                <textarea
                                    name="address"
                                    class="form-control customer-form-control"
                                    rows="3"
                                    maxlength="1000"
                                ></textarea>

                            </div>

                        </div>

                    </div>

                    <div class="customer-modal-section">

                        <div class="customer-modal-section-title">
                            <i class="bi bi-wallet2 me-2 text-primary"></i>
                            Payment Preferences
                        </div>

                        <label class="customer-form-label">Preferred Payment Method</label>

                        <select
                            name="preferred_payment"
                            class="form-select customer-form-control"
                        >
                            <option value="Cash">Cash</option>
                            <option value="Mobile Money">Mobile Money</option>
                            <option value="Bank">Bank Transfer</option>
                            <option value="Invoice">Invoice</option>
                        </select>

                    </div>

                    <div class="alert alert-info mt-3 mb-0 small">
                        <i class="bi bi-info-circle me-1"></i>
                        A unique customer code will be generated automatically.
                    </div>

                </div>

                <div class="modal-footer bg-light">

                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        name="add_customer"
                        class="btn btn-primary px-4"
                    >
                        <i class="bi bi-check-circle me-1"></i>
                        Register Customer
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const customerType = document.getElementById('customerType');
    const companyField = document.getElementById('companyField');
    const companyName = document.getElementById('companyName');

    function updateCompanyField() {

        if (customerType.value === 'Business') {

            companyField.style.display = 'block';

        } else {

            companyField.style.display = 'none';
            companyName.value = '';
        }
    }

    customerType.addEventListener('change', updateCompanyField);

    updateCompanyField();

});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
