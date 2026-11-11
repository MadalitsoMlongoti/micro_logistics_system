
<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

require_login();
require_permission('dispatch');

$page_title = 'Dispatch Management';
$currentPage = 'dispatch.php';

/*
|--------------------------------------------------------------------------
| HELPER: RECORD DELIVERY EVENTS
|--------------------------------------------------------------------------
*/

function recordDispatchEvent(
    PDO $pdo,
    int $deliveryId,
    string $eventType,
    string $description,
    ?int $userId
): void {

    $stmt = $pdo->prepare("
        INSERT INTO delivery_events
            (delivery_id, event_type, description, created_by)
        VALUES (?, ?, ?, ?)
    ");

    $stmt->execute([
        $deliveryId,
        $eventType,
        $description,
        $userId
    ]);
}

/*
|--------------------------------------------------------------------------
| HANDLE POST ACTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['form_action'] ?? '';

    /*
    |--------------------------------------------------------------------------
    | ASSIGN DELIVERY
    |--------------------------------------------------------------------------
    */

    if ($action === 'assign') {

        $deliveryId = filter_var(
            $_POST['delivery_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $driverId = filter_var(
            $_POST['driver_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $vehicleId = filter_var(
            $_POST['vehicle_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        try {

            if (
                $deliveryId === false ||
                $driverId === false ||
                $vehicleId === false ||
                $deliveryId <= 0 ||
                $driverId <= 0 ||
                $vehicleId <= 0
            ) {
                throw new InvalidArgumentException(
                    'Please select a valid delivery, driver and vehicle.'
                );
            }

            $pdo->beginTransaction();

            /*
            |--------------------------------------------------------------------------
            | LOCK DELIVERY
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT *
                FROM deliveries
                WHERE id = ?
                FOR UPDATE
            ");

            $stmt->execute([$deliveryId]);

            $delivery = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$delivery) {
                throw new InvalidArgumentException(
                    'Delivery not found.'
                );
            }

            if ($delivery['status'] !== 'pending') {
                throw new InvalidArgumentException(
                    'Only pending deliveries can be assigned.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | LOCK DRIVER
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT *
                FROM drivers
                WHERE id = ?
                FOR UPDATE
            ");

            $stmt->execute([$driverId]);

            $driver = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$driver || $driver['status'] !== 'active') {
                throw new InvalidArgumentException(
                    'The selected driver is unavailable.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | CHECK DRIVER WORKLOAD
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM deliveries
                WHERE driver_id = ?
                  AND status IN ('assigned', 'picked_up', 'in_transit')
            ");

            $stmt->execute([$driverId]);

            if ((int)$stmt->fetchColumn() > 0) {
                throw new InvalidArgumentException(
                    'This driver already has an active delivery.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | LOCK VEHICLE
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT *
                FROM vehicles
                WHERE id = ?
                FOR UPDATE
            ");

            $stmt->execute([$vehicleId]);

            $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$vehicle || $vehicle['status'] !== 'available') {
                throw new InvalidArgumentException(
                    'The selected vehicle is not available.'
                );
            }

            if ($vehicle['vehicle_type'] !== $delivery['vehicle_type']) {
                throw new InvalidArgumentException(
                    'Vehicle type does not match the delivery requirement.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | CHECK VEHICLE WORKLOAD
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM deliveries
                WHERE vehicle_id = ?
                  AND status IN ('assigned', 'picked_up', 'in_transit')
            ");

            $stmt->execute([$vehicleId]);

            if ((int)$stmt->fetchColumn() > 0) {
                throw new InvalidArgumentException(
                    'This vehicle already has an active delivery.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | ASSIGN DELIVERY
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                UPDATE deliveries
                SET
                    driver_id = ?,
                    vehicle_id = ?,
                    status = 'assigned',
                    assigned_at = NOW()
                WHERE id = ?
                  AND status = 'pending'
            ");

            $stmt->execute([
                $driverId,
                $vehicleId,
                $deliveryId
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'The delivery could not be assigned.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | UPDATE VEHICLE STATUS
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                UPDATE vehicles
                SET status = 'assigned'
                WHERE id = ?
                  AND status = 'available'
            ");

            $stmt->execute([$vehicleId]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'The vehicle is no longer available.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | RECORD EVENT
            |--------------------------------------------------------------------------
            */

            recordDispatchEvent(
                $pdo,
                (int)$deliveryId,
                'assigned',
                'Delivery assigned to ' .
                $driver['full_name'] .
                ' using vehicle ' .
                $vehicle['vehicle_code'],
                (int)$_SESSION['user_id']
            );

            $pdo->commit();

            flash(
                'Delivery successfully assigned to ' .
                $driver['full_name'] . '.',
                'success'
            );

        } catch (InvalidArgumentException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            flash($e->getMessage(), 'danger');

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'MLFMS Dispatch Assignment Error: ' .
                $e->getMessage()
            );

            flash(
                'Unable to assign the delivery. Please refresh and try again.',
                'danger'
            );
        }

        header('Location: dispatch.php');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE DELIVERY STATUS
    |--------------------------------------------------------------------------
    */

    if ($action === 'update_status') {

        $deliveryId = filter_var(
            $_POST['delivery_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $newStatus = $_POST['status'] ?? '';

        $allowedTransitions = [
            'assigned' => ['picked_up', 'cancelled'],
            'picked_up' => ['in_transit'],
            'in_transit' => ['delivered']
        ];

        try {

            if (
                $deliveryId === false ||
                $deliveryId <= 0
            ) {
                throw new InvalidArgumentException(
                    'Please select a valid delivery.'
                );
            }

            if (!is_string($newStatus)) {
                throw new InvalidArgumentException(
                    'Invalid delivery status.'
                );
            }

            $pdo->beginTransaction();

            /*
            |--------------------------------------------------------------------------
            | LOCK DELIVERY
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT *
                FROM deliveries
                WHERE id = ?
                FOR UPDATE
            ");

            $stmt->execute([$deliveryId]);

            $delivery = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$delivery) {
                throw new InvalidArgumentException(
                    'Delivery not found.'
                );
            }

            $currentStatus = $delivery['status'];

            /*
            |--------------------------------------------------------------------------
            | VALIDATE TRANSITION
            |--------------------------------------------------------------------------
            */

            if (
                !isset($allowedTransitions[$currentStatus]) ||
                !in_array(
                    $newStatus,
                    $allowedTransitions[$currentStatus],
                    true
                )
            ) {
                throw new InvalidArgumentException(
                    'Invalid delivery status transition.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | UPDATE DELIVERY
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                UPDATE deliveries
                SET
                    status = ?,
                    delivered_at = CASE
                        WHEN ? = 'delivered' THEN NOW()
                        ELSE delivered_at
                    END
                WHERE id = ?
            ");

            $stmt->execute([
                $newStatus,
                $newStatus,
                $deliveryId
            ]);

            /*
            |--------------------------------------------------------------------------
            | RELEASE VEHICLE
            |--------------------------------------------------------------------------
            */

            if (
                in_array(
                    $newStatus,
                    ['delivered', 'cancelled'],
                    true
                ) &&
                !empty($delivery['vehicle_id'])
            ) {

                $stmt = $pdo->prepare("
                    UPDATE vehicles
                    SET status = 'available'
                    WHERE id = ?
                      AND status = 'assigned'
                ");

                $stmt->execute([
                    $delivery['vehicle_id']
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | RECORD DELIVERY EVENT
            |--------------------------------------------------------------------------
            */

            recordDispatchEvent(
                $pdo,
                (int)$deliveryId,
                $newStatus,
                'Delivery status changed from ' .
                $currentStatus .
                ' to ' .
                $newStatus,
                (int)$_SESSION['user_id']
            );

            $pdo->commit();

            flash(
                'Delivery status updated successfully.',
                'success'
            );

        } catch (InvalidArgumentException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            flash($e->getMessage(), 'danger');

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'MLFMS Dispatch Status Error: ' .
                $e->getMessage()
            );

            flash(
                'Unable to update delivery status. Please try again.',
                'danger'
            );
        }

        header('Location: dispatch.php');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | INVALID ACTION
    |--------------------------------------------------------------------------
    */

    flash('Invalid dispatch action.', 'danger');

    header('Location: dispatch.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| DASHBOARD STATISTICS
|--------------------------------------------------------------------------
*/

$stats = $pdo->query("
    SELECT
        COUNT(*) AS total_deliveries,
        COALESCE(SUM(status = 'pending'), 0) AS pending_deliveries,
        COALESCE(SUM(status = 'assigned'), 0) AS assigned_deliveries,
        COALESCE(SUM(status = 'picked_up'), 0) AS picked_up_deliveries,
        COALESCE(SUM(status = 'in_transit'), 0) AS in_transit_deliveries,
        COALESCE(SUM(status = 'delivered'), 0) AS delivered_deliveries
    FROM deliveries
")->fetch(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| AVAILABLE DRIVERS
|--------------------------------------------------------------------------
*/

$drivers = $pdo->query("
    SELECT d.*
    FROM drivers d
    WHERE d.status = 'active'
      AND NOT EXISTS (
          SELECT 1
          FROM deliveries x
          WHERE x.driver_id = d.id
            AND x.status IN (
                'assigned',
                'picked_up',
                'in_transit'
            )
      )
    ORDER BY d.full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| AVAILABLE VEHICLES
|--------------------------------------------------------------------------
*/

$vehicles = $pdo->query("
    SELECT v.*
    FROM vehicles v
    WHERE v.status = 'available'
    ORDER BY v.vehicle_code ASC
")->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| PENDING DELIVERIES
|--------------------------------------------------------------------------
*/

$pendingDeliveries = $pdo->query("
    SELECT *
    FROM deliveries
    WHERE status = 'pending'
    ORDER BY created_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| DELIVERY MONITORING
|--------------------------------------------------------------------------
*/

$activeDeliveries = $pdo->query("
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

    WHERE d.status <> 'pending'

    ORDER BY
        CASE
            WHEN d.status IN (
                'assigned',
                'picked_up',
                'in_transit'
            ) THEN 0
            ELSE 1
        END,
        d.id DESC

    LIMIT 250
")->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| STATUS CONFIGURATION
|--------------------------------------------------------------------------
*/

$statusClasses = [
    'assigned' => 'primary',
    'picked_up' => 'info',
    'in_transit' => 'warning',
    'delivered' => 'success',
    'cancelled' => 'danger'
];

$nextActions = [
    'assigned' => [
        'picked_up' => 'Mark as Picked Up',
        'cancelled' => 'Cancel Delivery'
    ],
    'picked_up' => [
        'in_transit' => 'Mark In Transit'
    ],
    'in_transit' => [
        'delivered' => 'Mark as Delivered'
    ]
];

require_once __DIR__ . '/includes/header.php';

?>

<style>

/* DISPATCH DASHBOARD */

.dispatch-page .dispatch-heading {
    margin-bottom: 25px;
}

.dispatch-page .dispatch-heading h2 {
    font-weight: 700;
    letter-spacing: -.5px;
}

.dispatch-page .dispatch-stat {
    border: 0;
    border-radius: 12px;
    height: 100%;
    box-shadow: 0 2px 12px rgba(0,0,0,.045);
}

.dispatch-page .dispatch-stat-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 21px;
}

.dispatch-page .dispatch-stat-number {
    font-size: 27px;
    font-weight: 700;
}

.dispatch-page .dispatch-card {
    border: 0;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,.05);
}

.dispatch-page .dispatch-card .card-header {
    background: #fff;
    padding: 18px 20px;
    border-bottom: 1px solid #edf0f2;
}

.dispatch-page .dispatch-card .card-body {
    padding: 20px;
}

.dispatch-page .table thead th {
    background: #f8f9fa;
    color: #6c757d;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .35px;
    white-space: nowrap;
    padding: 13px 12px;
}

.dispatch-page .table tbody td {
    padding: 14px 12px;
    font-size: 13px;
}

.dispatch-page .dispatch-status {
    min-width: 90px;
    display: inline-block;
    text-align: center;
    padding: 7px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
}

.dispatch-page .assignment-box {
    background: #f8f9fa;
    border: 1px solid #edf0f2;
    border-radius: 10px;
    padding: 12px;
}

.dispatch-page .form-label {
    font-size: 12px;
    font-weight: 600;
    color: #495057;
}

.dispatch-page .form-select {
    min-height: 39px;
}

.dispatch-page .route-item {
    max-width: 240px;
    overflow-wrap: anywhere;
}

.dispatch-page .empty-state {
    padding: 45px 15px;
}

.dispatch-page .empty-state i {
    font-size: 40px;
    color: #adb5bd;
}

@media(max-width:767px) {

    .dispatch-page .dispatch-heading {
        align-items: flex-start !important;
        flex-direction: column;
    }

    .dispatch-page .dispatch-heading .btn {
        width: 100%;
    }

}

</style>

<div class="dispatch-page">

    <!-- PAGE HEADER -->

    <div class="dispatch-heading d-flex justify-content-between align-items-center flex-wrap gap-3">

        <div>

            <h2 class="mb-1">
                <i class="bi bi-truck text-primary me-2"></i>
                Dispatch Management
            </h2>

            <p class="text-muted mb-0">
                Assign drivers, allocate vehicles and monitor delivery progress.
            </p>

        </div>

        <div class="d-flex gap-2 flex-wrap">

            <a
                href="delivery_monitor.php"
                class="btn btn-outline-primary"
            >
                <i class="bi bi-geo-alt me-1"></i>
                Delivery Monitor
            </a>

        </div>

    </div>

    <?php show_flash(); ?>

    <!-- STATISTICS -->

    <div class="row g-3 mb-4">

        <?php

        $cards = [
            [
                'label' => 'Total Deliveries',
                'value' => $stats['total_deliveries'],
                'icon' => 'bi-box-seam',
                'color' => 'primary'
            ],
            [
                'label' => 'Pending',
                'value' => $stats['pending_deliveries'],
                'icon' => 'bi-clock-history',
                'color' => 'warning'
            ],
            [
                'label' => 'Assigned',
                'value' => $stats['assigned_deliveries'],
                'icon' => 'bi-person-check',
                'color' => 'primary'
            ],
            [
                'label' => 'Picked Up',
                'value' => $stats['picked_up_deliveries'],
                'icon' => 'bi-box-arrow-up',
                'color' => 'info'
            ],
            [
                'label' => 'In Transit',
                'value' => $stats['in_transit_deliveries'],
                'icon' => 'bi-truck',
                'color' => 'warning'
            ],
            [
                'label' => 'Delivered',
                'value' => $stats['delivered_deliveries'],
                'icon' => 'bi-check-circle',
                'color' => 'success'
            ]
        ];

        ?>

        <?php foreach ($cards as $card): ?>

            <div class="col-xl-2 col-md-4 col-6">

                <div class="card dispatch-stat">

                    <div class="card-body">

                        <div class="d-flex justify-content-between align-items-center">

                            <small class="text-muted">
                                <?= e($card['label']) ?>
                            </small>

                            <div class="dispatch-stat-icon bg-<?= e($card['color']) ?>-subtle text-<?= e($card['color']) ?>">
                                <i class="bi <?= e($card['icon']) ?>"></i>
                            </div>

                        </div>

                        <div class="dispatch-stat-number mt-2">
                            <?= number_format((int)$card['value']) ?>
                        </div>

                    </div>

                </div>

            </div>

        <?php endforeach; ?>

    </div>

    <!-- PENDING DELIVERY ASSIGNMENT -->

    <div class="card dispatch-card mb-4">

        <div class="card-header">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">

                <div>

                    <h5 class="mb-0 fw-bold">
                        <i class="bi bi-person-check text-primary me-2"></i>
                        Pending Delivery Assignment
                    </h5>

                    <small class="text-muted">
                        Assign an available driver and matching vehicle to each order.
                    </small>

                </div>

                <span class="badge bg-warning-subtle text-warning-emphasis rounded-pill px-3 py-2">
                    <?= count($pendingDeliveries) ?> Awaiting Assignment
                </span>

            </div>

        </div>

        <div class="card-body p-0">

            <div class="table-responsive">

                <table class="table table-hover align-middle mb-0">

                    <thead>

                        <tr>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Route</th>
                            <th>Requirement</th>
                            <th>Assignment</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php if (empty($pendingDeliveries)): ?>

                            <tr>

                                <td colspan="5">

                                    <div class="empty-state text-center">

                                        <i class="bi bi-check-circle d-block mb-3"></i>

                                        <h6 class="fw-bold">
                                            All deliveries assigned
                                        </h6>

                                        <p class="text-muted mb-0">
                                            There are currently no pending deliveries.
                                        </p>

                                    </div>

                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach ($pendingDeliveries as $delivery): ?>

                                <?php

                                $matchingVehicles = array_filter(
                                    $vehicles,
                                    function ($vehicle) use ($delivery) {
                                        return $vehicle['vehicle_type'] === $delivery['vehicle_type'];
                                    }
                                );

                                ?>

                                <tr>

                                    <!-- ORDER -->

                                    <td>

                                        <strong class="text-primary">
                                            <?= e($delivery['order_code']) ?>
                                        </strong>

                                        <div class="small text-muted mt-1">
                                            <?= e($delivery['created_at']) ?>
                                        </div>

                                    </td>

                                    <!-- CUSTOMER -->

                                    <td>

                                        <div class="fw-semibold">
                                            <?= e($delivery['customer_name']) ?>
                                        </div>

                                        <div class="small text-muted">
                                            <?= e($delivery['customer_phone']) ?>
                                        </div>

                                    </td>

                                    <!-- ROUTE -->

                                    <td>

                                        <div class="route-item">

                                            <div class="small mb-1">
                                                <i class="bi bi-circle-fill text-success me-1"></i>
                                                <?= e($delivery['pickup_address']) ?>
                                            </div>

                                            <div class="small">
                                                <i class="bi bi-geo-alt-fill text-danger me-1"></i>
                                                <?= e($delivery['dropoff_address']) ?>
                                            </div>

                                        </div>

                                    </td>

                                    <!-- REQUIREMENT -->

                                    <td>

                                        <span class="badge bg-primary-subtle text-primary-emphasis">
                                            <?= e(ucfirst($delivery['vehicle_type'])) ?>
                                        </span>

                                        <div class="small text-muted mt-1">
                                            <?= e((string)$delivery['distance_km']) ?> KM
                                        </div>

                                    </td>

                                    <!-- ASSIGNMENT FORM -->

                                    <td style="min-width:260px;">

                                        <?php if (empty($drivers)): ?>

                                            <div class="alert alert-warning py-2 mb-0 small">
                                                No available drivers.
                                            </div>

                                        <?php elseif (empty($matchingVehicles)): ?>

                                            <div class="alert alert-warning py-2 mb-0 small">
                                                No available matching vehicles.
                                            </div>

                                        <?php else: ?>

                                            <form method="POST" class="assignment-box">

                                                <input
                                                    type="hidden"
                                                    name="csrf"
                                                    value="<?= e(csrf_token()) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="form_action"
                                                    value="assign"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="delivery_id"
                                                    value="<?= (int)$delivery['id'] ?>"
                                                >

                                                <label class="form-label mb-1">
                                                    Driver
                                                </label>

                                                <select
                                                    name="driver_id"
                                                    class="form-select form-select-sm mb-2"
                                                    required
                                                >

                                                    <option value="">
                                                        Select Driver
                                                    </option>

                                                    <?php foreach ($drivers as $driver): ?>

                                                        <option value="<?= (int)$driver['id'] ?>">

                                                            <?= e($driver['full_name']) ?>

                                                        </option>

                                                    <?php endforeach; ?>

                                                </select>

                                                <label class="form-label mb-1">
                                                    Vehicle
                                                </label>

                                                <select
                                                    name="vehicle_id"
                                                    class="form-select form-select-sm mb-2"
                                                    required
                                                >

                                                    <option value="">
                                                        Select Vehicle
                                                    </option>

                                                    <?php foreach ($matchingVehicles as $vehicle): ?>

                                                        <option value="<?= (int)$vehicle['id'] ?>">

                                                            <?= e($vehicle['vehicle_code']) ?>

                                                        </option>

                                                    <?php endforeach; ?>

                                                </select>

                                                <button
                                                    type="submit"
                                                    class="btn btn-primary btn-sm w-100"
                                                >

                                                    <i class="bi bi-check-circle me-1"></i>
                                                    Assign Delivery

                                                </button>

                                            </form>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

    <!-- DELIVERY MONITORING -->

    <div class="card dispatch-card">

        <div class="card-header">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">

                <div>

                    <h5 class="mb-0 fw-bold">
                        <i class="bi bi-activity text-primary me-2"></i>
                        Delivery Monitoring
                    </h5>

                    <small class="text-muted">
                        Assigned, active, completed and cancelled deliveries.
                    </small>

                </div>

                <div class="d-flex align-items-center gap-2 flex-wrap">

                    <span class="badge bg-primary-subtle text-primary-emphasis rounded-pill px-3 py-2">
                        <?= count($activeDeliveries) ?> Monitored
                    </span>

                    <a
                        href="delivery_monitor.php"
                        class="btn btn-sm btn-outline-primary"
                    >

                        <i class="bi bi-geo-alt me-1"></i>
                        Open Delivery Monitor

                    </a>

                </div>

            </div>

        </div>

        <div class="card-body p-0">

            <div class="table-responsive">

                <table class="table table-hover align-middle mb-0">

                    <thead>

                        <tr>
                            <th>Order</th>
                            <th>Driver</th>
                            <th>Vehicle</th>
                            <th>Price</th>
                            <th>Assigned At</th>
                            <th>Status</th>
                            <th>Progress</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php if (empty($activeDeliveries)): ?>

                            <tr>

                                <td colspan="7">

                                    <div class="empty-state text-center">

                                        <i class="bi bi-truck d-block mb-3"></i>

                                        <h6 class="fw-bold">
                                            No monitored deliveries
                                        </h6>

                                        <p class="text-muted mb-0">
                                            Deliveries will appear here after assignment.
                                        </p>

                                    </div>

                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach ($activeDeliveries as $delivery): ?>

                                <?php

                                $status = $delivery['status'];

                                $badgeColor = $statusClasses[$status] ?? 'secondary';

                                ?>

                                <tr>

                                    <!-- ORDER -->

                                    <td>

                                        <strong class="text-primary">
                                            <?= e($delivery['order_code']) ?>
                                        </strong>

                                        <div class="small text-muted">
                                            <?= e($delivery['customer_name']) ?>
                                        </div>

                                    </td>

                                    <!-- DRIVER -->

                                    <td>

                                        <div class="fw-semibold">
                                            <?= e($delivery['driver_name'] ?? 'Not assigned') ?>
                                        </div>

                                        <div class="small text-muted">
                                            <?= e($delivery['driver_phone'] ?? '') ?>
                                        </div>

                                    </td>

                                    <!-- VEHICLE -->

                                    <td>

                                        <div class="fw-semibold">
                                            <?= e($delivery['vehicle_code'] ?? 'Not allocated') ?>
                                        </div>

                                        <div class="small text-muted">
                                            <?= e($delivery['assigned_vehicle_type'] ?? '') ?>
                                        </div>

                                    </td>

                                    <!-- PRICE -->

                                    <td>

                                        <strong>
                                            <?= money($delivery['total_price']) ?>
                                        </strong>

                                    </td>

                                    <!-- ASSIGNED DATE -->

                                    <td>

                                        <small>
                                            <?= e($delivery['assigned_at'] ?? 'Not recorded') ?>
                                        </small>

                                    </td>

                                    <!-- STATUS -->

                                    <td>

                                        <span class="dispatch-status bg-<?= e($badgeColor) ?>-subtle text-<?= e($badgeColor) ?>-emphasis">

                                            <?= e(
                                                ucwords(
                                                    str_replace('_', ' ', $status)
                                                )
                                            ) ?>

                                        </span>

                                    </td>

                                    <!-- PROGRESS -->

                                    <td style="min-width:220px;">

                                        <?php if (!empty($nextActions[$status])): ?>

                                            <form method="POST" class="d-flex gap-2">

                                                <input
                                                    type="hidden"
                                                    name="csrf"
                                                    value="<?= e(csrf_token()) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="form_action"
                                                    value="update_status"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="delivery_id"
                                                    value="<?= (int)$delivery['id'] ?>"
                                                >

                                                <select
                                                    name="status"
                                                    class="form-select form-select-sm"
                                                    required
                                                >

                                                    <?php foreach ($nextActions[$status] as $value => $label): ?>

                                                        <option value="<?= e($value) ?>">
                                                            <?= e($label) ?>
                                                        </option>

                                                    <?php endforeach; ?>

                                                </select>

                                                <button
                                                    type="submit"
                                                    class="btn btn-sm btn-primary"
                                                    title="Update delivery progress"
                                                >

                                                    <i class="bi bi-check-lg"></i>

                                                </button>

                                            </form>

                                        <?php else: ?>

                                            <span class="text-muted small">
                                                No further actions
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

        <div class="card-footer bg-white py-3">

            <small class="text-muted">

                <i class="bi bi-info-circle me-1"></i>

                For full delivery history, search and detailed event tracking,
                use Delivery Monitor.

            </small>

        </div>

    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
