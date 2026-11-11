
<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

require_login();
require_permission('dispatch');

$page_title = 'Delivery Monitoring';
$currentPage = 'delivery_monitor.php';

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$driverId = (int)($_GET['driver_id'] ?? 0);
$vehicleId = (int)($_GET['vehicle_id'] ?? 0);
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$allowedStatuses = [
    'pending',
    'assigned',
    'picked_up',
    'in_transit',
    'delivered',
    'cancelled'
];

if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

/*
|--------------------------------------------------------------------------
| VALIDATE DATES
|--------------------------------------------------------------------------
*/

if (
    $dateFrom !== '' &&
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)
) {
    $dateFrom = '';
}

if (
    $dateTo !== '' &&
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)
) {
    $dateTo = '';
}

if (
    $dateFrom !== '' &&
    !checkdate(
        (int)substr($dateFrom, 5, 2),
        (int)substr($dateFrom, 8, 2),
        (int)substr($dateFrom, 0, 4)
    )
) {
    $dateFrom = '';
}

if (
    $dateTo !== '' &&
    !checkdate(
        (int)substr($dateTo, 5, 2),
        (int)substr($dateTo, 8, 2),
        (int)substr($dateTo, 0, 4)
    )
) {
    $dateTo = '';
}

/*
|--------------------------------------------------------------------------
| LOAD FILTER DROPDOWNS
|--------------------------------------------------------------------------
*/

$drivers = $pdo->query("
    SELECT id, full_name
    FROM drivers
    ORDER BY full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$vehicles = $pdo->query("
    SELECT id, vehicle_code, vehicle_type
    FROM vehicles
    ORDER BY vehicle_code ASC
")->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| BUILD DELIVERY QUERY
|--------------------------------------------------------------------------
*/

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(
        d.order_code LIKE ?
        OR d.customer_name LIKE ?
        OR d.customer_phone LIKE ?
    )";

    $term = '%' . $search . '%';

    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if ($status !== '') {
    $where[] = "d.status = ?";
    $params[] = $status;
}

if ($driverId > 0) {
    $where[] = "d.driver_id = ?";
    $params[] = $driverId;
}

if ($vehicleId > 0) {
    $where[] = "d.vehicle_id = ?";
    $params[] = $vehicleId;
}

if ($dateFrom !== '') {
    $where[] = "DATE(d.created_at) >= ?";
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = "DATE(d.created_at) <= ?";
    $params[] = $dateTo;
}

$whereSql = '';

if (!empty($where)) {
    $whereSql = 'WHERE ' . implode(' AND ', $where);
}

/*
|--------------------------------------------------------------------------
| DELIVERY STATISTICS
|--------------------------------------------------------------------------
| Statistics reflect all deliveries, not only filtered results.
|--------------------------------------------------------------------------
*/

$stats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(status = 'pending'), 0) AS pending,
        COALESCE(SUM(status = 'assigned'), 0) AS assigned,
        COALESCE(SUM(status = 'picked_up'), 0) AS picked_up,
        COALESCE(SUM(status = 'in_transit'), 0) AS in_transit,
        COALESCE(SUM(status = 'delivered'), 0) AS delivered,
        COALESCE(SUM(status = 'cancelled'), 0) AS cancelled
    FROM deliveries
")->fetch(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| FETCH FILTERED DELIVERIES
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        d.*,
        dr.full_name AS driver_name,
        dr.phone AS driver_phone,
        v.vehicle_code,
        v.vehicle_type AS assigned_vehicle_type
    FROM deliveries d
    LEFT JOIN drivers dr
        ON d.driver_id = dr.id
    LEFT JOIN vehicles v
        ON d.vehicle_id = v.id
    $whereSql
    ORDER BY d.created_at DESC, d.id DESC
    LIMIT 300
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| FETCH DELIVERY EVENTS
|--------------------------------------------------------------------------
| All events are fetched in one query.
| User names are included where an event has a recorded user.
|--------------------------------------------------------------------------
*/

$eventsByDelivery = [];

$deliveryIds = array_column($deliveries, 'id');

if (!empty($deliveryIds)) {
    $placeholders = implode(
        ',',
        array_fill(0, count($deliveryIds), '?')
    );

    $eventSql = "
        SELECT
            de.delivery_id,
            de.event_type,
            de.description,
            de.created_at,
            de.created_by,
            u.name AS event_user_name
        FROM delivery_events de
        LEFT JOIN users u
            ON de.created_by = u.id
        WHERE de.delivery_id IN ($placeholders)
        ORDER BY de.created_at DESC, de.id DESC
    ";

    $eventStmt = $pdo->prepare($eventSql);
    $eventStmt->execute($deliveryIds);

    $events = $eventStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($events as $event) {
        $eventsByDelivery[$event['delivery_id']][] = $event;
    }
}

/*
|--------------------------------------------------------------------------
| STATUS DISPLAY
|--------------------------------------------------------------------------
*/

$statusLabels = [
    'pending' => 'Pending',
    'assigned' => 'Assigned',
    'picked_up' => 'Picked Up',
    'in_transit' => 'In Transit',
    'delivered' => 'Delivered',
    'cancelled' => 'Cancelled'
];

$statusClasses = [
    'pending' => 'bg-secondary',
    'assigned' => 'bg-primary',
    'picked_up' => 'bg-info text-dark',
    'in_transit' => 'bg-warning text-dark',
    'delivered' => 'bg-success',
    'cancelled' => 'bg-danger'
];

$statusIcons = [
    'pending' => 'bi-clock-history',
    'assigned' => 'bi-person-check',
    'picked_up' => 'bi-box-arrow-up',
    'in_transit' => 'bi-truck',
    'delivered' => 'bi-check-circle-fill',
    'cancelled' => 'bi-x-circle-fill'
];

/*
|--------------------------------------------------------------------------
| EVENT DISPLAY
|--------------------------------------------------------------------------
*/

$eventLabels = [
    'delivery_created' => 'Delivery Created',
    'assigned' => 'Driver and Vehicle Assigned',
    'picked_up' => 'Package Picked Up',
    'in_transit' => 'Delivery In Transit',
    'delivered' => 'Delivery Completed',
    'cancelled' => 'Delivery Cancelled',
    'payment_received' => 'Payment Received'
];

$eventStyles = [
    'delivery_created' => [
        'icon' => 'bi-plus-circle-fill',
        'color' => 'success'
    ],
    'assigned' => [
        'icon' => 'bi-person-check-fill',
        'color' => 'primary'
    ],
    'picked_up' => [
        'icon' => 'bi-box-arrow-up',
        'color' => 'info'
    ],
    'in_transit' => [
        'icon' => 'bi-truck',
        'color' => 'warning'
    ],
    'delivered' => [
        'icon' => 'bi-check-circle-fill',
        'color' => 'success'
    ],
    'cancelled' => [
        'icon' => 'bi-x-circle-fill',
        'color' => 'danger'
    ],
    'payment_received' => [
        'icon' => 'bi-cash-coin',
        'color' => 'success'
    ]
];

require_once __DIR__ . '/includes/header.php';
?>

<style>
/* =====================================================
   DELIVERY MONITORING UI
===================================================== */

.monitor-hero {
    background: linear-gradient(120deg, #172554, #1d4ed8);
    color: #fff;
    border-radius: 16px;
    padding: 26px;
    position: relative;
    overflow: hidden;
}

.monitor-hero::after {
    content: "";
    position: absolute;
    width: 190px;
    height: 190px;
    right: -55px;
    top: -65px;
    border: 25px solid rgba(255,255,255,.08);
    border-radius: 50%;
}

.monitor-hero-content {
    position: relative;
    z-index: 1;
}

.monitor-hero .hero-icon {
    width: 52px;
    height: 52px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 14px;
    background: rgba(255,255,255,.15);
    font-size: 25px;
}

.monitor-stat-card {
    border: 1px solid #e9edf3;
    border-radius: 13px;
    background: #fff;
    padding: 17px;
    height: 100%;
    transition: transform .2s ease, box-shadow .2s ease;
}

.monitor-stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 7px 20px rgba(15,23,42,.07);
}

.monitor-stat-icon {
    width: 39px;
    height: 39px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 11px;
    font-size: 18px;
}

.monitor-stat-value {
    font-size: 25px;
    font-weight: 700;
    margin-top: 13px;
    margin-bottom: 0;
    color: #172033;
}

.monitor-stat-label {
    color: #64748b;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .45px;
}

.monitor-section-card {
    border: 1px solid #e9edf3;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(15,23,42,.025);
}

.monitor-section-card > .card-header {
    background: #fff;
    border-bottom: 1px solid #edf0f4;
    padding: 17px 20px;
}

.monitor-section-card > .card-body {
    padding: 20px;
}

.monitor-table {
    margin-bottom: 0;
    min-width: 1100px;
}

.monitor-table thead th {
    background: #f8fafc;
    color: #64748b;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .5px;
    font-weight: 700;
    padding: 14px 12px;
    border-bottom: 1px solid #e5eaf0;
    white-space: nowrap;
}

.monitor-table tbody td {
    padding: 15px 12px;
    border-color: #edf0f4;
    font-size: 13px;
}

.monitor-table tbody tr.delivery-main-row:hover {
    background: #f8fbff;
}

.monitor-order-code {
    color: #1d4ed8;
    font-weight: 700;
    text-decoration: none;
}

.monitor-order-code:hover {
    text-decoration: underline;
}

.monitor-muted {
    color: #94a3b8;
    font-size: 11px;
}

.monitor-filter-label {
    font-size: 12px;
    font-weight: 700;
    color: #475569;
    margin-bottom: 7px;
}

.monitor-filter-control {
    min-height: 42px;
    border-color: #e2e8f0;
    font-size: 13px;
}

.monitor-filter-control:focus {
    border-color: #93c5fd;
    box-shadow: 0 0 0 3px rgba(59,130,246,.10);
}

.monitor-history-row td {
    background: #f8fafc;
}

.monitor-history-container {
    padding: 22px;
}

.monitor-history-heading {
    font-size: 14px;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 18px;
}

.monitor-timeline {
    position: relative;
    padding-left: 25px;
    margin-left: 8px;
    border-left: 2px solid #e2e8f0;
}

.monitor-timeline-item {
    position: relative;
    padding: 0 0 22px 20px;
}

.monitor-timeline-item:last-child {
    padding-bottom: 0;
}

.monitor-timeline-icon {
    position: absolute;
    left: -39px;
    top: 0;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 3px solid #f8fafc;
    font-size: 13px;
    background: #fff;
}

.monitor-event-card {
    background: #fff;
    border: 1px solid #e7ebf1;
    border-radius: 11px;
    padding: 14px 16px;
}

.monitor-event-title {
    font-weight: 700;
    color: #334155;
    font-size: 13px;
}

.monitor-event-description {
    color: #64748b;
    font-size: 12px;
    margin-top: 6px;
    margin-bottom: 0;
    line-height: 1.6;
}

.monitor-event-meta {
    color: #94a3b8;
    font-size: 11px;
}

.monitor-empty {
    padding: 35px 15px;
    text-align: center;
    color: #94a3b8;
}

.monitor-empty i {
    font-size: 34px;
    display: block;
    margin-bottom: 10px;
}

.monitor-view-button {
    white-space: nowrap;
}

.monitor-count-badge {
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #dbeafe;
}

.monitor-payment-badge {
    font-size: 10px;
}

.monitor-route {
    max-width: 230px;
    line-height: 1.6;
}

.monitor-date {
    white-space: nowrap;
}

@media (max-width: 767px) {
    .monitor-hero {
        padding: 20px;
    }

    .monitor-hero h2 {
        font-size: 21px;
    }

    .monitor-section-card > .card-body {
        padding: 14px;
    }

    .monitor-history-container {
        padding: 15px;
    }
}
</style>

<!-- =====================================================
     PAGE HEADER
===================================================== -->

<div class="monitor-hero mb-4">

    <div class="monitor-hero-content d-flex justify-content-between align-items-center flex-wrap gap-3">

        <div class="d-flex align-items-center gap-3">

            <div class="hero-icon">
                <i class="bi bi-geo-alt"></i>
            </div>

            <div>
                <h2 class="mb-1 fw-bold">Delivery Monitoring</h2>

                <p class="mb-0 text-white-50">
                    Monitor delivery progress, resources and complete activity history.
                </p>
            </div>

        </div>

        <a href="dispatch.php" class="btn btn-light btn-sm fw-semibold">
            <i class="bi bi-arrow-left me-1"></i>
            Back to Dispatch
        </a>

    </div>

</div>

<?php show_flash(); ?>

<!-- =====================================================
     STATISTICS
===================================================== -->

<?php
$cards = [
    [
        'label' => 'Total Deliveries',
        'value' => $stats['total'],
        'icon' => 'bi-box-seam',
        'color' => 'primary',
        'background' => '#eff6ff'
    ],
    [
        'label' => 'Pending',
        'value' => $stats['pending'],
        'icon' => 'bi-clock-history',
        'color' => 'secondary',
        'background' => '#f1f5f9'
    ],
    [
        'label' => 'Assigned',
        'value' => $stats['assigned'],
        'icon' => 'bi-person-check',
        'color' => 'primary',
        'background' => '#eff6ff'
    ],
    [
        'label' => 'Picked Up',
        'value' => $stats['picked_up'],
        'icon' => 'bi-box-arrow-up',
        'color' => 'info',
        'background' => '#ecfeff'
    ],
    [
        'label' => 'In Transit',
        'value' => $stats['in_transit'],
        'icon' => 'bi-truck',
        'color' => 'warning',
        'background' => '#fffbeb'
    ],
    [
        'label' => 'Delivered',
        'value' => $stats['delivered'],
        'icon' => 'bi-check-circle',
        'color' => 'success',
        'background' => '#f0fdf4'
    ],
    [
        'label' => 'Cancelled',
        'value' => $stats['cancelled'],
        'icon' => 'bi-x-circle',
        'color' => 'danger',
        'background' => '#fef2f2'
    ]
];
?>

<div class="row g-3 mb-4">

    <?php foreach ($cards as $card): ?>

        <div class="col-6 col-sm-4 col-lg-3 col-xl">

            <div class="monitor-stat-card">

                <div class="d-flex justify-content-between align-items-center">

                    <span class="monitor-stat-label">
                        <?= e($card['label']) ?>
                    </span>

                    <span
                        class="monitor-stat-icon text-<?= e($card['color']) ?>"
                        style="background: <?= e($card['background']) ?>;"
                    >
                        <i class="bi <?= e($card['icon']) ?>"></i>
                    </span>

                </div>

                <h3 class="monitor-stat-value">
                    <?= number_format((int)$card['value']) ?>
                </h3>

            </div>

        </div>

    <?php endforeach; ?>

</div>

<!-- =====================================================
     FILTERS
===================================================== -->

<div class="card monitor-section-card mb-4">

    <div class="card-header d-flex justify-content-between align-items-center">

        <div>
            <h5 class="mb-1 fw-bold">
                <i class="bi bi-funnel text-primary me-2"></i>
                Search and Filter
            </h5>

            <small class="text-muted">
                Find deliveries using order details, status, assigned resources or date.
            </small>
        </div>

    </div>

    <div class="card-body">

        <form method="GET">

            <div class="row g-3">

                <div class="col-md-6 col-lg-4">

                    <label class="monitor-filter-label">
                        Search Deliveries
                    </label>

                    <div class="input-group">

                        <span class="input-group-text bg-light">
                            <i class="bi bi-search text-muted"></i>
                        </span>

                        <input
                            type="text"
                            name="search"
                            class="form-control monitor-filter-control"
                            placeholder="Order number, customer or phone"
                            value="<?= e($search) ?>"
                        >

                    </div>

                </div>

                <div class="col-md-6 col-lg-2">

                    <label class="monitor-filter-label">
                        Delivery Status
                    </label>

                    <select
                        name="status"
                        class="form-select monitor-filter-control"
                    >

                        <option value="">All statuses</option>

                        <?php foreach ($statusLabels as $value => $label): ?>

                            <option
                                value="<?= e($value) ?>"
                                <?= $status === $value ? 'selected' : '' ?>
                            >
                                <?= e($label) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-md-6 col-lg-2">

                    <label class="monitor-filter-label">
                        Driver
                    </label>

                    <select
                        name="driver_id"
                        class="form-select monitor-filter-control"
                    >

                        <option value="0">All drivers</option>

                        <?php foreach ($drivers as $driver): ?>

                            <option
                                value="<?= (int)$driver['id'] ?>"
                                <?= $driverId === (int)$driver['id'] ? 'selected' : '' ?>
                            >
                                <?= e($driver['full_name']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-md-6 col-lg-2">

                    <label class="monitor-filter-label">
                        Vehicle
                    </label>

                    <select
                        name="vehicle_id"
                        class="form-select monitor-filter-control"
                    >

                        <option value="0">All vehicles</option>

                        <?php foreach ($vehicles as $vehicle): ?>

                            <option
                                value="<?= (int)$vehicle['id'] ?>"
                                <?= $vehicleId === (int)$vehicle['id'] ? 'selected' : '' ?>
                            >
                                <?= e($vehicle['vehicle_code']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-md-6 col-lg-2">

                    <label class="monitor-filter-label">
                        From Date
                    </label>

                    <input
                        type="date"
                        name="date_from"
                        class="form-control monitor-filter-control"
                        value="<?= e($dateFrom) ?>"
                    >

                </div>

                <div class="col-md-6 col-lg-2">

                    <label class="monitor-filter-label">
                        To Date
                    </label>

                    <input
                        type="date"
                        name="date_to"
                        class="form-control monitor-filter-control"
                        value="<?= e($dateTo) ?>"
                    >

                </div>

                <div class="col-12 d-flex flex-wrap gap-2 mt-4">

                    <button type="submit" class="btn btn-primary px-4">

                        <i class="bi bi-search me-1"></i>
                        Apply Filters

                    </button>

                    <a
                        href="delivery_monitor.php"
                        class="btn btn-outline-secondary"
                    >

                        <i class="bi bi-arrow-counterclockwise me-1"></i>
                        Clear Filters

                    </a>

                </div>

            </div>

        </form>

    </div>

</div>

<!-- =====================================================
     DELIVERY RECORDS
===================================================== -->

<div class="card monitor-section-card mb-4">

    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">

        <div>

            <h5 class="mb-1 fw-bold">
                <i class="bi bi-list-ul text-primary me-2"></i>
                Delivery Records
            </h5>

            <small class="text-muted">
                View delivery information and expand each record to see its complete history.
            </small>

        </div>

        <span class="badge monitor-count-badge rounded-pill px-3 py-2">
            <i class="bi bi-box-seam me-1"></i>
            <?= count($deliveries) ?> records found
        </span>

    </div>

    <div class="card-body p-0">

        <div class="table-responsive">

            <table class="table table-hover align-middle monitor-table">

                <thead>

                    <tr>
                        <th>Order Details</th>
                        <th>Customer</th>
                        <th>Delivery Route</th>
                        <th>Driver</th>
                        <th>Vehicle</th>
                        <th>Price / Payment</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>History</th>
                    </tr>

                </thead>

                <tbody>

                    <?php if (empty($deliveries)): ?>

                        <tr>

                            <td colspan="9">

                                <div class="monitor-empty">

                                    <i class="bi bi-inbox"></i>

                                    <strong class="d-block text-dark">
                                        No delivery records found
                                    </strong>

                                    <small>
                                        Try changing your search filters to find deliveries.
                                    </small>

                                </div>

                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($deliveries as $delivery): ?>

                            <?php
                            $deliveryStatus = $delivery['status'];

                            $badgeClass = $statusClasses[$deliveryStatus]
                                ?? 'bg-secondary';

                            $statusLabel = $statusLabels[$deliveryStatus]
                                ?? ucfirst($deliveryStatus);

                            $statusIcon = $statusIcons[$deliveryStatus]
                                ?? 'bi-question-circle';

                            $historyId = 'history_' . (int)$delivery['id'];

                            $deliveryEvents = $eventsByDelivery[$delivery['id']] ?? [];

                            $eventCount = count($deliveryEvents);

                            $paymentStatus = $delivery['payment_status'] ?? 'unpaid';

                            $paymentClasses = [
                                'paid' => 'bg-success-subtle text-success',
                                'partial' => 'bg-warning-subtle text-dark',
                                'unpaid' => 'bg-danger-subtle text-danger'
                            ];

                            $paymentClass = $paymentClasses[$paymentStatus]
                                ?? 'bg-secondary-subtle text-secondary';

                            $paymentLabel = ucfirst($paymentStatus);
                            ?>

                            <tr class="delivery-main-row">

                                <!-- ORDER -->
                                <td>

                                    <a
                                        href="#<?= e($historyId) ?>"
                                        class="monitor-order-code"
                                        data-bs-toggle="collapse"
                                        role="button"
                                    >
                                        <?= e($delivery['order_code']) ?>
                                    </a>

                                    <div class="monitor-muted mt-1">
                                        Delivery ID: #<?= (int)$delivery['id'] ?>
                                    </div>

                                </td>

                                <!-- CUSTOMER -->
                                <td>

                                    <strong class="text-dark">
                                        <?= e($delivery['customer_name']) ?>
                                    </strong>

                                    <div class="text-muted small mt-1">
                                        <i class="bi bi-telephone me-1"></i>
                                        <?= e($delivery['customer_phone']) ?>
                                    </div>

                                </td>

                                <!-- ROUTE -->
                                <td>

                                    <div class="monitor-route">

                                        <div class="mb-1">

                                            <i class="bi bi-geo-alt-fill text-success me-1"></i>

                                            <strong>Pickup:</strong>

                                            <?= e($delivery['pickup_address']) ?>

                                        </div>

                                        <div>

                                            <i class="bi bi-geo-alt-fill text-danger me-1"></i>

                                            <strong>Drop-off:</strong>

                                            <?= e($delivery['dropoff_address']) ?>

                                        </div>

                                        <div class="monitor-muted mt-1">
                                            <i class="bi bi-signpost-2 me-1"></i>
                                            <?= e((string)$delivery['distance_km']) ?> km
                                        </div>

                                    </div>

                                </td>

                                <!-- DRIVER -->
                                <td>

                                    <?php if (!empty($delivery['driver_name'])): ?>

                                        <strong>
                                            <?= e($delivery['driver_name']) ?>
                                        </strong>

                                        <?php if (!empty($delivery['driver_phone'])): ?>

                                            <div class="text-muted small mt-1">
                                                <i class="bi bi-telephone me-1"></i>
                                                <?= e($delivery['driver_phone']) ?>
                                            </div>

                                        <?php endif; ?>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            <i class="bi bi-person-dash me-1"></i>
                                            Not assigned
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <!-- VEHICLE -->
                                <td>

                                    <?php if (!empty($delivery['vehicle_code'])): ?>

                                        <strong>
                                            <?= e($delivery['vehicle_code']) ?>
                                        </strong>

                                        <?php if (!empty($delivery['assigned_vehicle_type'])): ?>

                                            <div class="text-muted small mt-1">
                                                <?= e(ucfirst($delivery['assigned_vehicle_type'])) ?>
                                            </div>

                                        <?php endif; ?>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            <i class="bi bi-truck me-1"></i>
                                            Not allocated
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <!-- PRICE AND PAYMENT -->
                                <td>

                                    <strong class="text-dark">
                                        <?= money($delivery['total_price']) ?>
                                    </strong>

                                    <div class="mt-1">
                                        <span class="badge monitor-payment-badge <?= e($paymentClass) ?>">
                                            <?= e($paymentLabel) ?>
                                        </span>
                                    </div>

                                </td>

                                <!-- STATUS -->
                                <td>

                                    <span class="badge <?= e($badgeClass) ?> px-2 py-2">

                                        <i class="bi <?= e($statusIcon) ?> me-1"></i>

                                        <?= e($statusLabel) ?>

                                    </span>

                                </td>

                                <!-- CREATED -->
                                <td class="monitor-date">

                                    <div class="text-dark small">
                                        <?= e($delivery['created_at']) ?>
                                    </div>

                                    <?php if (!empty($delivery['assigned_at'])): ?>

                                        <div class="text-muted mt-2" style="font-size: 10px;">
                                            <i class="bi bi-person-check me-1"></i>
                                            Assigned:
                                            <?= e($delivery['assigned_at']) ?>
                                        </div>

                                    <?php endif; ?>

                                    <?php if (!empty($delivery['delivered_at'])): ?>

                                        <div class="text-success mt-2" style="font-size: 10px;">
                                            <i class="bi bi-check-circle me-1"></i>
                                            Delivered:
                                            <?= e($delivery['delivered_at']) ?>
                                        </div>

                                    <?php endif; ?>

                                </td>

                                <!-- HISTORY -->
                                <td>

                                    <button
                                        class="btn btn-sm btn-outline-primary monitor-view-button"
                                        type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#<?= e($historyId) ?>"
                                        aria-expanded="false"
                                        aria-controls="<?= e($historyId) ?>"
                                    >

                                        <i class="bi bi-clock-history me-1"></i>
                                        View

                                        <span class="badge bg-primary ms-1">
                                            <?= $eventCount ?>
                                        </span>

                                    </button>

                                </td>

                            </tr>

                            <!-- =================================================
                                 DELIVERY EVENT HISTORY
                            ================================================== -->

                            <tr class="monitor-history-row">

                                <td colspan="9" class="p-0">

                                    <div
                                        class="collapse"
                                        id="<?= e($historyId) ?>"
                                    >

                                        <div class="monitor-history-container">

                                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">

                                                <div class="monitor-history-heading mb-0">

                                                    <i class="bi bi-clock-history text-primary me-2"></i>

                                                    Delivery Timeline:
                                                    <?= e($delivery['order_code']) ?>

                                                </div>

                                                <span class="badge bg-light text-dark border">
                                                    <?= $eventCount ?> recorded events
                                                </span>

                                            </div>

                                            <?php if (empty($deliveryEvents)): ?>

                                                <div class="monitor-empty bg-white rounded-3 border">

                                                    <i class="bi bi-clock-history"></i>

                                                    <strong class="d-block text-dark">
                                                        No history available
                                                    </strong>

                                                    <small>
                                                        No delivery events have been recorded for this order yet.
                                                    </small>

                                                </div>

                                            <?php else: ?>

                                                <div class="monitor-timeline">

                                                    <?php foreach ($deliveryEvents as $event): ?>

                                                        <?php
                                                        $eventType = $event['event_type'];

                                                        $eventLabel = $eventLabels[$eventType]
                                                            ?? ucwords(str_replace('_', ' ', $eventType));

                                                        $eventStyle = $eventStyles[$eventType] ?? [
                                                            'icon' => 'bi-info-circle-fill',
                                                            'color' => 'secondary'
                                                        ];
                                                        ?>

                                                        <div class="monitor-timeline-item">

                                                            <div class="monitor-timeline-icon text-<?= e($eventStyle['color']) ?>">

                                                                <i class="bi <?= e($eventStyle['icon']) ?>"></i>

                                                            </div>

                                                            <div class="monitor-event-card">

                                                                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">

                                                                    <div class="monitor-event-title">

                                                                        <?= e($eventLabel) ?>

                                                                    </div>

                                                                    <span class="monitor-event-meta">

                                                                        <i class="bi bi-calendar3 me-1"></i>

                                                                        <?= e($event['created_at']) ?>

                                                                    </span>

                                                                </div>

                                                                <?php if (!empty($event['description'])): ?>

                                                                    <p class="monitor-event-description">
                                                                        <?= e($event['description']) ?>
                                                                    </p>

                                                                <?php endif; ?>

                                                                <div class="monitor-event-meta mt-2">

                                                                    <i class="bi bi-person-circle me-1"></i>

                                                                    Recorded by:

                                                                    <strong class="text-secondary">

                                                                        <?= e($event['event_user_name'] ?? 'System / Unknown User') ?>

                                                                    </strong>

                                                                </div>

                                                            </div>

                                                        </div>

                                                    <?php endforeach; ?>

                                                </div>

                                            <?php endif; ?>

                                        </div>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

        <?php if (count($deliveries) === 300): ?>

            <div class="alert alert-info m-3 mb-0">

                <i class="bi bi-info-circle me-1"></i>

                Showing the latest 300 matching deliveries. Use the filters to narrow down your results.

            </div>

        <?php endif; ?>

    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
