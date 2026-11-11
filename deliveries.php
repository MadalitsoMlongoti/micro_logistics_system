
<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

require_login();
require_permission('deliveries');

$page_title = 'Deliveries';
$currentPage = 'deliveries.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| CONFIGURATION
|--------------------------------------------------------------------------
*/

$allowedVehicles = ['bicycle', 'tricycle'];

$allowedPayments = [
    'cash',
    'mobile_money',
    'bank',
    'invoice'
];

$maxNameLength = 150;
$maxPhoneLength = 30;
$maxAddressLength = 500;
$maxNotesLength = 2000;

$selectedCustomer = null;
$selectedCustomerId = (int)($_GET['customer_id'] ?? 0);

/*
|--------------------------------------------------------------------------
| LOAD CUSTOMER FROM PROFILE SHORTCUT
|--------------------------------------------------------------------------
*/

if ($selectedCustomerId > 0) {

    $customerStmt = $pdo->prepare("
        SELECT
            id,
            customer_code,
            full_name,
            phone,
            status
        FROM customers
        WHERE id = ?
        LIMIT 1
    ");

    $customerStmt->execute([$selectedCustomerId]);

    $selectedCustomer = $customerStmt->fetch(PDO::FETCH_ASSOC);

    if (!$selectedCustomer) {

        flash('The selected customer could not be found.', 'danger');

        header('Location: deliveries.php');
        exit;
    }

    if ($selectedCustomer['status'] !== 'Active') {

        flash(
            'This customer account is inactive. Activate the customer before creating a delivery.',
            'danger'
        );

        header('Location: customers.php');
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| HELPER: VALIDATE TEXT INPUT
|--------------------------------------------------------------------------
*/

$readText = static function (
    string $field,
    int $maxLength,
    bool $required = false
): string {

    $value = $_POST[$field] ?? '';

    if (!is_string($value)) {
        throw new InvalidArgumentException(
            'Invalid input received for ' . str_replace('_', ' ', $field) . '.'
        );
    }

    $value = trim($value);

    if ($required && $value === '') {
        throw new InvalidArgumentException(
            'Please complete all required fields.'
        );
    }

    if (strlen($value) > $maxLength) {
        throw new InvalidArgumentException(
            ucfirst(str_replace('_', ' ', $field)) .
            ' exceeds the permitted length.'
        );
    }

    return $value;
};

/*
|--------------------------------------------------------------------------
| CREATE DELIVERY
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['form_action'] ?? '';

    if ($action !== 'create') {

        flash('Invalid delivery request.', 'danger');

        header('Location: deliveries.php');
        exit;
    }

    $postedCustomerId = (int)($_POST['customer_id'] ?? 0);

    try {

        /*
        |--------------------------------------------------------------------------
        | CUSTOMER DETAILS
        |--------------------------------------------------------------------------
        | If a registered customer is selected, use the saved customer
        | record rather than trusting the submitted name and phone.
        |--------------------------------------------------------------------------
        */

        if ($postedCustomerId > 0) {

            $customerStmt = $pdo->prepare("
                SELECT
                    id,
                    full_name,
                    phone,
                    status
                FROM customers
                WHERE id = ?
                LIMIT 1
            ");

            $customerStmt->execute([$postedCustomerId]);

            $linkedCustomer = $customerStmt->fetch(PDO::FETCH_ASSOC);

            if (!$linkedCustomer) {
                throw new InvalidArgumentException(
                    'The selected customer no longer exists.'
                );
            }

            if ($linkedCustomer['status'] !== 'Active') {
                throw new InvalidArgumentException(
                    'This customer account is inactive.'
                );
            }

            $customerId = (int)$linkedCustomer['id'];
            $customerName = $linkedCustomer['full_name'];
            $customerPhone = $linkedCustomer['phone'];

        } else {

            $customerId = null;

            $customerName = $readText(
                'customer_name',
                $maxNameLength,
                true
            );

            $customerPhone = $readText(
                'customer_phone',
                $maxPhoneLength,
                true
            );
        }

        if (!preg_match('/^[0-9+\s().-]{7,30}$/', $customerPhone)) {
            throw new InvalidArgumentException(
                'Please enter a valid customer phone number.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | ADDRESS DETAILS
        |--------------------------------------------------------------------------
        */

        $pickupAddress = $readText(
            'pickup_address',
            $maxAddressLength,
            true
        );

        $dropoffAddress = $readText(
            'dropoff_address',
            $maxAddressLength,
            true
        );

        if ($pickupAddress === $dropoffAddress) {
            throw new InvalidArgumentException(
                'Pickup and drop-off addresses cannot be identical.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | VEHICLE VALIDATION
        |--------------------------------------------------------------------------
        */

        $vehicleType = $_POST['vehicle_type'] ?? '';

        if (
            !is_string($vehicleType) ||
            !in_array($vehicleType, $allowedVehicles, true)
        ) {
            throw new InvalidArgumentException(
                'Please select a valid vehicle type.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | DISTANCE VALIDATION
        |--------------------------------------------------------------------------
        */

        $distanceInput = $_POST['distance_km'] ?? '';

        if (
            !is_string($distanceInput) ||
            $distanceInput === '' ||
            !is_numeric($distanceInput)
        ) {
            throw new InvalidArgumentException(
                'Please enter a valid delivery distance.'
            );
        }

        $distance = (float)$distanceInput;

        if (!is_finite($distance) || $distance <= 0) {
            throw new InvalidArgumentException(
                'Delivery distance must be greater than zero.'
            );
        }

        if (
            ($vehicleType === 'bicycle' && $distance > 6) ||
            ($vehicleType === 'tricycle' && $distance > 15)
        ) {
            throw new InvalidArgumentException(
                'The selected vehicle cannot handle this delivery distance.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | EXTRA STOPS
        |--------------------------------------------------------------------------
        */

        $extraStopsInput = $_POST['extra_stops'] ?? '0';

        if (
            !is_string($extraStopsInput) ||
            filter_var($extraStopsInput, FILTER_VALIDATE_INT) === false
        ) {
            throw new InvalidArgumentException(
                'Extra stops must be a valid whole number.'
            );
        }

        $extraStops = (int)$extraStopsInput;

        if ($extraStops < 0 || $extraStops > 50) {
            throw new InvalidArgumentException(
                'Extra stops must be between 0 and 50.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | WAITING TIME
        |--------------------------------------------------------------------------
        */

        $waitInput = $_POST['wait_minutes'] ?? '0';

        if (
            !is_string($waitInput) ||
            filter_var($waitInput, FILTER_VALIDATE_INT) === false
        ) {
            throw new InvalidArgumentException(
                'Waiting time must be a valid whole number.'
            );
        }

        $waitMinutes = (int)$waitInput;

        if ($waitMinutes < 0 || $waitMinutes > 1440) {
            throw new InvalidArgumentException(
                'Waiting time must be between 0 and 1440 minutes.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | PAYMENT METHOD
        |--------------------------------------------------------------------------
        */

        $paymentMethod = $_POST['payment_method'] ?? 'cash';

        if (
            !is_string($paymentMethod) ||
            !in_array($paymentMethod, $allowedPayments, true)
        ) {
            throw new InvalidArgumentException(
                'Please select a valid payment method.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | ADDITIONAL SERVICES
        |--------------------------------------------------------------------------
        */

        foreach (['rush', 'bulky', 'rain'] as $checkbox) {

            if (
                isset($_POST[$checkbox]) &&
                $_POST[$checkbox] !== '1'
            ) {
                throw new InvalidArgumentException(
                    'Invalid additional service selection.'
                );
            }
        }

        $options = [
            'extra_stops' => $extraStops,
            'wait_minutes' => $waitMinutes,
            'rush' => isset($_POST['rush']),
            'bulky' => isset($_POST['bulky']),
            'rain' => isset($_POST['rain'])
        ];

        /*
        |--------------------------------------------------------------------------
        | NOTES
        |--------------------------------------------------------------------------
        */

        $notes = $readText(
            'notes',
            $maxNotesLength
        );

        /*
        |--------------------------------------------------------------------------
        | SERVER-SIDE PRICE CALCULATION
        |--------------------------------------------------------------------------
        */

        $price = price_delivery(
            $vehicleType,
            $distance,
            $options
        );

        if (!is_finite($price) || $price <= 0) {
            throw new InvalidArgumentException(
                'Unable to calculate the delivery price.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | GENERATE ORDER REFERENCE
        |--------------------------------------------------------------------------
        */

        $orderCode = 'ML' . date('ymd') . '-' .
            strtoupper(bin2hex(random_bytes(4)));

        /*
        |--------------------------------------------------------------------------
        | SAVE DELIVERY AND INITIAL EVENT
        |--------------------------------------------------------------------------
        */

        $pdo->beginTransaction();

        /*
        |--------------------------------------------------------------------------
        | INSERT DELIVERY
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            INSERT INTO deliveries (
                order_code,
                customer_id,
                customer_name,
                customer_phone,
                pickup_address,
                dropoff_address,
                vehicle_type,
                distance_km,
                extra_stops,
                wait_minutes,
                rush,
                bulky,
                rain,
                total_price,
                payment_method,
                notes,
                status,
                created_by
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                'pending', ?
            )
        ");

        $stmt->execute([
            $orderCode,
            $customerId,
            $customerName,
            $customerPhone,
            $pickupAddress,
            $dropoffAddress,
            $vehicleType,
            $distance,
            $extraStops,
            $options['rush'] ? 1 : 0,
            $options['bulky'] ? 1 : 0,
            $options['rain'] ? 1 : 0,
            $price,
            $paymentMethod,
            $notes,
            (int)$_SESSION['user_id']
        ]);

        /*
        |--------------------------------------------------------------------------
        | GET NEW DELIVERY ID
        |--------------------------------------------------------------------------
        */

        $deliveryId = (int)$pdo->lastInsertId();

        if ($deliveryId <= 0) {
            throw new RuntimeException(
                'Unable to retrieve the newly created delivery ID.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | RECORD INITIAL DELIVERY EVENT
        |--------------------------------------------------------------------------
        */

        $eventStmt = $pdo->prepare("
            INSERT INTO delivery_events (
                delivery_id,
                event_type,
                description,
                created_by
            ) VALUES (?, ?, ?, ?)
        ");

        $eventDescription = 'Delivery order ' . $orderCode .
            ' was created with pending status.';

        if ($customerId !== null) {
            $eventDescription .= ' Linked customer ID: ' . $customerId . '.';
        }

        $eventStmt->execute([
            $deliveryId,
            'delivery_created',
            $eventDescription,
            (int)$_SESSION['user_id']
        ]);

        /*
        |--------------------------------------------------------------------------
        | COMMIT TRANSACTION
        |--------------------------------------------------------------------------
        */

        $pdo->commit();

        flash(
            'Delivery created successfully. Order reference: ' . $orderCode,
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
            'MLFMS Delivery Creation Error: ' . $e->getMessage()
        );

        flash(
            'Unable to create the delivery. Please check your information and try again.',
            'danger'
        );
    }

    header('Location: deliveries.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| RETRIEVE DELIVERY RECORDS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        d.*,
        v.vehicle_code,
        dr.full_name AS driver_name

    FROM deliveries d

    LEFT JOIN vehicles v
        ON d.vehicle_id = v.id

    LEFT JOIN drivers dr
        ON d.driver_id = dr.id

    ORDER BY d.id DESC

    LIMIT 250
");

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| DASHBOARD STATISTICS
|--------------------------------------------------------------------------
*/

$totalDeliveries = count($rows);
$pendingDeliveries = 0;
$activeDeliveries = 0;
$completedDeliveries = 0;

foreach ($rows as $delivery) {

    if ($delivery['status'] === 'pending') {
        $pendingDeliveries++;
    }

    if (
        in_array(
            $delivery['status'],
            ['assigned', 'picked_up', 'in_transit'],
            true
        )
    ) {
        $activeDeliveries++;
    }

    if ($delivery['status'] === 'delivered') {
        $completedDeliveries++;
    }
}

require_once __DIR__ . '/includes/header.php';

?>

<style>
.delivery-page .page-heading {
    margin-bottom: 25px;
}

.delivery-page .page-heading h2 {
    font-weight: 700;
    letter-spacing: -.5px;
}

.delivery-page .stat-card {
    border: 0;
    border-radius: 12px;
    height: 100%;
    box-shadow: 0 2px 10px rgba(0,0,0,.045);
}

.delivery-page .stat-icon {
    width: 45px;
    height: 45px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 21px;
}

.delivery-page .stat-number {
    font-size: 25px;
    font-weight: 700;
}

.delivery-page .section-card {
    border: 0;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,.05);
}

.delivery-page .section-title {
    font-weight: 650;
}

.delivery-page .form-label {
    font-weight: 600;
    font-size: 13px;
    color: #495057;
}

.delivery-page .form-control,
.delivery-page .form-select {
    min-height: 42px;
}

.delivery-page textarea.form-control {
    min-height: 85px;
}

.delivery-page .table thead th {
    white-space: nowrap;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .35px;
    color: #6c757d;
    background: #f8f9fa;
    padding: 13px 12px;
}

.delivery-page .table tbody td {
    padding: 13px 12px;
    font-size: 13px;
}

.delivery-page .route-text {
    max-width: 240px;
    overflow-wrap: anywhere;
}

.delivery-page .status-badge {
    min-width: 85px;
    display: inline-block;
    text-align: center;
    padding: 7px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
}

.delivery-page .form-section {
    border: 1px solid #e9ecef;
    border-radius: 10px;
    padding: 18px;
    height: 100%;
}

.delivery-page .form-section-title {
    font-size: 14px;
    font-weight: 700;
    margin-bottom: 17px;
}

.delivery-page .service-option {
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 10px 13px;
    min-width: 145px;
}

.delivery-page .empty-state {
    padding: 45px 15px;
}

.delivery-page .empty-state i {
    font-size: 38px;
    color: #adb5bd;
}

.delivery-page .selected-customer {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 10px;
    padding: 13px 15px;
}

.delivery-page .price-preview {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 10px;
    padding: 13px 15px;
}

@media(max-width:767px) {
    .delivery-page .page-heading {
        align-items: flex-start !important;
        flex-direction: column;
    }

    .delivery-page .page-heading .btn {
        width: 100%;
    }
}
</style>

<div class="delivery-page">

    <!-- PAGE HEADER -->

    <div class="page-heading d-flex justify-content-between align-items-center flex-wrap gap-3">

        <div>
            <h2 class="mb-1">
                <i class="bi bi-box-seam text-primary me-2"></i>
                Deliveries
            </h2>

            <p class="text-muted mb-0">
                Create delivery orders and manage delivery records.
            </p>
        </div>

        <button
            class="btn btn-primary px-4"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#newDelivery"
            aria-expanded="<?= $selectedCustomer ? 'true' : 'false' ?>"
        >
            <i class="bi bi-plus-circle me-1"></i>
            Create New Delivery
        </button>

    </div>

    <?php show_flash(); ?>

    <!-- STATISTICS -->

    <div class="row g-3 mb-4">

        <div class="col-xl-3 col-md-6">

            <div class="card stat-card">
                <div class="card-body d-flex align-items-center justify-content-between">

                    <div>
                        <small class="text-muted">Total Records</small>
                        <div class="stat-number"><?= number_format($totalDeliveries) ?></div>
                        <small class="text-muted">Latest 250 deliveries</small>
                    </div>

                    <div class="stat-icon bg-primary-subtle text-primary">
                        <i class="bi bi-box-seam"></i>
                    </div>

                </div>
            </div>

        </div>

        <div class="col-xl-3 col-md-6">

            <div class="card stat-card">
                <div class="card-body d-flex align-items-center justify-content-between">

                    <div>
                        <small class="text-muted">Pending</small>
                        <div class="stat-number text-warning"><?= number_format($pendingDeliveries) ?></div>
                        <small class="text-muted">Awaiting dispatch</small>
                    </div>

                    <div class="stat-icon bg-warning-subtle text-warning">
                        <i class="bi bi-clock-history"></i>
                    </div>

                </div>
            </div>

        </div>

        <div class="col-xl-3 col-md-6">

            <div class="card stat-card">
                <div class="card-body d-flex align-items-center justify-content-between">

                    <div>
                        <small class="text-muted">Active Deliveries</small>
                        <div class="stat-number text-primary"><?= number_format($activeDeliveries) ?></div>
                        <small class="text-muted">Currently in progress</small>
                    </div>

                    <div class="stat-icon bg-info-subtle text-info">
                        <i class="bi bi-truck"></i>
                    </div>

                </div>
            </div>

        </div>

        <div class="col-xl-3 col-md-6">

            <div class="card stat-card">
                <div class="card-body d-flex align-items-center justify-content-between">

                    <div>
                        <small class="text-muted">Completed</small>
                        <div class="stat-number text-success"><?= number_format($completedDeliveries) ?></div>
                        <small class="text-muted">Successfully delivered</small>
                    </div>

                    <div class="stat-icon bg-success-subtle text-success">
                        <i class="bi bi-check-circle"></i>
                    </div>

                </div>
            </div>

        </div>

    </div>

    <!-- CREATE DELIVERY FORM -->

    <div class="collapse <?= $selectedCustomer ? 'show' : '' ?> mb-4" id="newDelivery">

        <div class="card section-card">

            <div class="card-header bg-white py-3">

                <div class="d-flex align-items-center gap-2">

                    <div class="stat-icon bg-primary-subtle text-primary">
                        <i class="bi bi-plus-circle"></i>
                    </div>

                    <div>
                        <h5 class="mb-0 section-title">Create New Delivery</h5>
                        <small class="text-muted">
                            Enter the customer, route and delivery information.
                        </small>
                    </div>

                </div>

            </div>

            <div class="card-body p-4">

                <?php if ($selectedCustomer): ?>

                    <div class="selected-customer mb-4">

                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">

                            <div>

                                <div class="small text-primary fw-bold mb-1">
                                    <i class="bi bi-person-check-fill me-1"></i>
                                    REGISTERED CUSTOMER SELECTED
                                </div>

                                <strong class="text-dark">
                                    <?= e($selectedCustomer['full_name']) ?>
                                </strong>

                                <div class="small text-muted">
                                    <?= e($selectedCustomer['phone']) ?>
                                    <span class="mx-1">|</span>
                                    <?= e($selectedCustomer['customer_code']) ?>
                                </div>

                            </div>

                            <a href="deliveries.php#newDelivery"
                               class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-x-circle me-1"></i>
                                Remove Customer
                            </a>

                        </div>

                    </div>

                <?php endif; ?>

                <form method="POST" autocomplete="off">

                    <input
                        type="hidden"
                        name="csrf"
                        value="<?= e(csrf_token()) ?>"
                    >

                    <input
                        type="hidden"
                        name="form_action"
                        value="create"
                    >

                    <input
                        type="hidden"
                        name="customer_id"
                        value="<?= $selectedCustomer ? (int)$selectedCustomer['id'] : 0 ?>"
                    >

                    <div class="row g-3">

                        <!-- CUSTOMER DETAILS -->

                        <div class="col-lg-6">

                            <div class="form-section">

                                <div class="form-section-title">
                                    <i class="bi bi-person text-primary me-2"></i>
                                    Customer Information
                                </div>

                                <div class="mb-3">

                                    <label class="form-label">Customer Name *</label>

                                    <input
                                        type="text"
                                        name="customer_name"
                                        class="form-control"
                                        maxlength="150"
                                        placeholder="Enter customer name"
                                        value="<?= $selectedCustomer ? e($selectedCustomer['full_name']) : '' ?>"
                                        <?= $selectedCustomer ? 'readonly' : 'required' ?>
                                    >

                                    <?php if ($selectedCustomer): ?>
                                        <small class="text-muted">
                                            This information is taken from the registered customer account.
                                        </small>
                                    <?php endif; ?>

                                </div>

                                <div>

                                    <label class="form-label">Customer Phone *</label>

                                    <input
                                        type="tel"
                                        name="customer_phone"
                                        class="form-control"
                                        maxlength="30"
                                        placeholder="e.g. 097XXXXXXX"
                                        value="<?= $selectedCustomer ? e($selectedCustomer['phone']) : '' ?>"
                                        <?= $selectedCustomer ? 'readonly' : 'required' ?>
                                    >

                                </div>

                            </div>

                        </div>

                        <!-- DELIVERY DETAILS -->

                        <div class="col-lg-6">

                            <div class="form-section">

                                <div class="form-section-title">
                                    <i class="bi bi-truck text-primary me-2"></i>
                                    Delivery Requirements
                                </div>

                                <div class="mb-3">

                                    <label class="form-label">Vehicle Required *</label>

                                    <select name="vehicle_type" class="form-select" required>
                                        <option value="bicycle">Bicycle</option>
                                        <option value="tricycle">Motorized Tricycle</option>
                                    </select>

                                </div>

                                <div>

                                    <label class="form-label">Distance (KM) *</label>

                                    <input
                                        type="number"
                                        name="distance_km"
                                        class="form-control"
                                        step="0.1"
                                        min="0.1"
                                        max="15"
                                        placeholder="Enter distance in kilometres"
                                        required
                                    >

                                    <small class="text-muted">
                                        Bicycle: maximum 6 KM. Tricycle: maximum 15 KM.
                                    </small>

                                </div>

                            </div>

                        </div>

                        <!-- ROUTE DETAILS -->

                        <div class="col-12">

                            <div class="form-section">

                                <div class="form-section-title">
                                    <i class="bi bi-geo-alt text-primary me-2"></i>
                                    Pickup and Drop-off Information
                                </div>

                                <div class="row g-3">

                                    <div class="col-md-6">

                                        <label class="form-label">Pickup Address *</label>

                                        <input
                                            type="text"
                                            name="pickup_address"
                                            class="form-control"
                                            maxlength="500"
                                            placeholder="Where should the parcel be collected?"
                                            required
                                        >

                                    </div>

                                    <div class="col-md-6">

                                        <label class="form-label">Drop-off Address *</label>

                                        <input
                                            type="text"
                                            name="dropoff_address"
                                            class="form-control"
                                            maxlength="500"
                                            placeholder="Where should the parcel be delivered?"
                                            required
                                        >

                                    </div>

                                </div>

                            </div>

                        </div>

                        <!-- CHARGES -->

                        <div class="col-lg-6">

                            <div class="form-section">

                                <div class="form-section-title">
                                    <i class="bi bi-cash-stack text-primary me-2"></i>
                                    Additional Charges
                                </div>

                                <div class="row g-3">

                                    <div class="col-md-6">

                                        <label class="form-label">Extra Stops</label>

                                        <input
                                            type="number"
                                            name="extra_stops"
                                            class="form-control"
                                            min="0"
                                            max="50"
                                            step="1"
                                            value="0"
                                        >

                                    </div>

                                    <div class="col-md-6">

                                        <label class="form-label">Waiting Time (Minutes)</label>

                                        <input
                                            type="number"
                                            name="wait_minutes"
                                            class="form-control"
                                            min="0"
                                            max="1440"
                                            step="1"
                                            value="0"
                                        >

                                    </div>

                                    <div class="col-12">

                                        <label class="form-label">Additional Services</label>

                                        <div class="d-flex flex-wrap gap-2">

                                            <label class="service-option">
                                                <input type="checkbox" name="rush" value="1"
                                                       class="form-check-input me-2">
                                                <i class="bi bi-lightning-charge text-warning me-1"></i>
                                                Rush
                                            </label>

                                            <label class="service-option">
                                                <input type="checkbox" name="bulky" value="1"
                                                       class="form-check-input me-2">
                                                <i class="bi bi-box-seam text-primary me-1"></i>
                                                Bulky
                                            </label>

                                            <label class="service-option">
                                                <input type="checkbox" name="rain" value="1"
                                                       class="form-check-input me-2">
                                                <i class="bi bi-cloud-rain text-info me-1"></i>
                                                Rain
                                            </label>

                                        </div>

                                    </div>

                                </div>

                            </div>

                        </div>

                        <!-- PAYMENT AND NOTES -->

                        <div class="col-lg-6">

                            <div class="form-section">

                                <div class="form-section-title">
                                    <i class="bi bi-wallet2 text-primary me-2"></i>
                                    Payment and Parcel Details
                                </div>

                                <div class="mb-3">

                                    <label class="form-label">Payment Method</label>

                                    <select name="payment_method" class="form-select">
                                        <option value="cash">Cash</option>
                                        <option value="mobile_money">Mobile Money</option>
                                        <option value="bank">Bank</option>
                                        <option value="invoice">Invoice</option>
                                    </select>

                                </div>

                                <div>

                                    <label class="form-label">Notes / Parcel Details</label>

                                    <textarea
                                        name="notes"
                                        class="form-control"
                                        rows="2"
                                        maxlength="2000"
                                        placeholder="Describe the parcel or provide additional instructions..."
                                    ></textarea>

                                </div>

                            </div>

                        </div>

                        <!-- PRICE PREVIEW -->

                        <div class="col-12">

                            <div class="price-preview">

                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">

                                    <div>
                                        <div class="fw-bold text-success">
                                            <i class="bi bi-calculator me-1"></i>
                                            Delivery Price
                                        </div>

                                        <small class="text-muted">
                                            Final price is calculated securely by the server when submitted.
                                        </small>
                                    </div>

                                    <div class="text-end">
                                        <div class="small text-muted">Estimated total</div>
                                        <h4 class="fw-bold text-success mb-0" id="pricePreview">
                                            Enter delivery details
                                        </h4>
                                    </div>

                                </div>

                            </div>

                        </div>

                        <!-- FORM ACTIONS -->

                        <div class="col-12">

                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">

                                <small class="text-muted">
                                    <i class="bi bi-shield-check text-success me-1"></i>
                                    Delivery pricing is calculated automatically by the system.
                                </small>

                                <div class="d-flex gap-2">

                                    <button
                                        type="button"
                                        class="btn btn-light border"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#newDelivery"
                                    >
                                        Cancel
                                    </button>

                                    <button type="submit" class="btn btn-success px-4">
                                        <i class="bi bi-check-circle me-1"></i>
                                        Create Delivery
                                    </button>

                                </div>

                            </div>

                        </div>

                    </div>

                </form>

            </div>

        </div>

    </div>

    <!-- DELIVERY RECORDS -->

    <div class="card section-card">

        <div class="card-header bg-white py-3">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">

                <div>
                    <h5 class="mb-0 section-title">
                        <i class="bi bi-list-ul text-primary me-2"></i>
                        Delivery Records
                    </h5>

                    <small class="text-muted">
                        Review delivery orders and their current progress.
                    </small>
                </div>

                <span class="badge bg-primary rounded-pill px-3 py-2">
                    <?= number_format($totalDeliveries) ?> Records
                </span>

            </div>

        </div>

        <div class="card-body p-0">

            <div class="table-responsive">

                <table class="table table-hover align-middle mb-0">

                    <thead>
                        <tr>
                            <th>Order Details</th>
                            <th>Customer</th>
                            <th>Delivery Route</th>
                            <th>Assignment</th>
                            <th>Price</th>
                            <th>Payment</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (empty($rows)): ?>

                        <tr>
                            <td colspan="7">

                                <div class="empty-state text-center">

                                    <i class="bi bi-inbox d-block mb-3"></i>

                                    <h6 class="fw-bold">No deliveries found</h6>

                                    <p class="text-muted mb-3">
                                        Start by creating your first delivery order.
                                    </p>

                                    <button
                                        class="btn btn-sm btn-primary"
                                        type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#newDelivery"
                                    >
                                        <i class="bi bi-plus-circle me-1"></i>
                                        Create Delivery
                                    </button>

                                </div>

                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($rows as $r): ?>

                            <?php
                            $statusClasses = [
                                'pending' => 'bg-warning-subtle text-warning-emphasis',
                                'assigned' => 'bg-primary-subtle text-primary-emphasis',
                                'picked_up' => 'bg-info-subtle text-info-emphasis',
                                'in_transit' => 'bg-warning-subtle text-warning-emphasis',
                                'delivered' => 'bg-success-subtle text-success-emphasis',
                                'cancelled' => 'bg-danger-subtle text-danger-emphasis'
                            ];

                            $status = $r['status'] ?? '';

                            $statusClass = $statusClasses[$status]
                                ?? 'bg-secondary-subtle text-secondary-emphasis';

                            $paymentStatus = $r['payment_status'] ?? 'unpaid';

                            $paymentClasses = [
                                'paid' => 'text-success',
                                'partial' => 'text-warning',
                                'unpaid' => 'text-danger'
                            ];

                            $paymentClass = $paymentClasses[$paymentStatus]
                                ?? 'text-secondary';
                            ?>

                            <tr>

                                <td>
                                    <strong class="text-primary">
                                        <?= e($r['order_code']) ?>
                                    </strong>

                                    <div class="small text-muted mt-1">
                                        <i class="bi bi-calendar3 me-1"></i>
                                        <?= e($r['created_at']) ?>
                                    </div>
                                </td>

                                <td>

                                    <div class="fw-semibold">
                                        <?= e($r['customer_name']) ?>
                                    </div>

                                    <div class="small text-muted">
                                        <i class="bi bi-telephone me-1"></i>
                                        <?= e($r['customer_phone']) ?>
                                    </div>

                                    <?php if (!empty($r['customer_id'])): ?>
                                        <span class="badge bg-primary-subtle text-primary-emphasis mt-1">
                                            <i class="bi bi-link-45deg me-1"></i>
                                            Registered customer
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted mt-1">
                                            Manual customer
                                        </span>
                                    <?php endif; ?>

                                </td>

                                <td>

                                    <div class="route-text">

                                        <div class="small mb-1">
                                            <i class="bi bi-circle-fill text-success me-1"></i>
                                            <?= e($r['pickup_address']) ?>
                                        </div>

                                        <div class="small">
                                            <i class="bi bi-geo-alt-fill text-danger me-1"></i>
                                            <?= e($r['dropoff_address']) ?>
                                        </div>

                                    </div>

                                    <div class="small text-muted mt-1">
                                        <?= e((string)$r['distance_km']) ?> KM
                                    </div>

                                </td>

                                <td>

                                    <div class="fw-semibold">
                                        <?= e($r['vehicle_code'] ?? 'Not allocated') ?>
                                    </div>

                                    <div class="small text-muted">
                                        <?= e($r['driver_name'] ?? 'Not assigned') ?>
                                    </div>

                                </td>

                                <td>
                                    <strong><?= money($r['total_price']) ?></strong>
                                </td>

                                <td>

                                    <div class="small">
                                        <?= e(
                                            ucwords(
                                                str_replace('_', ' ', $r['payment_method'])
                                            )
                                        ) ?>
                                    </div>

                                    <div class="small fw-semibold <?= e($paymentClass) ?>">
                                        <?= e(ucfirst($paymentStatus)) ?>
                                    </div>

                                </td>

                                <td>
                                    <span class="status-badge <?= e($statusClass) ?>">
                                        <?= e(
                                            ucwords(
                                                str_replace('_', ' ', $status)
                                            )
                                        ) ?>
                                    </span>
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
                Delivery assignments and status changes are managed through the Dispatch module.
            </small>

        </div>

    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    /*
    |--------------------------------------------------------------------------
    | AUTOMATICALLY OPEN DELIVERY FORM WHEN CUSTOMER IS SELECTED
    |--------------------------------------------------------------------------
    */

    const deliveryForm = document.getElementById('newDelivery');

    if (deliveryForm && window.location.hash === '#newDelivery') {

        const collapse = bootstrap.Collapse.getOrCreateInstance(
            deliveryForm,
            { toggle: false }
        );

        collapse.show();

        setTimeout(function () {
            deliveryForm.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }, 200);
    }

    /*
    |--------------------------------------------------------------------------
    | CLIENT-SIDE PRICE PREVIEW
    |--------------------------------------------------------------------------
    | This is an estimate only. The server remains authoritative.
    |--------------------------------------------------------------------------
    */

    const form = document.querySelector('#newDelivery form');

    if (!form) {
        return;
    }

    const vehicle = form.querySelector('[name="vehicle_type"]');
    const distance = form.querySelector('[name="distance_km"]');
    const stops = form.querySelector('[name="extra_stops"]');
    const waiting = form.querySelector('[name="wait_minutes"]');
    const rush = form.querySelector('[name="rush"]');
    const bulky = form.querySelector('[name="bulky"]');
    const rain = form.querySelector('[name="rain"]');
    const preview = document.getElementById('pricePreview');

    function calculatePreview() {

        const type = vehicle.value;
        const km = parseFloat(distance.value) || 0;
        const extraStops = parseInt(stops.value, 10) || 0;
        const waitMinutes = parseInt(waiting.value, 10) || 0;

        if (km <= 0) {
            preview.textContent = 'Enter delivery distance';
            return;
        }

        let base = 0;

        if (type === 'bicycle') {

            if (km <= 2) base = 15;
            else if (km <= 4) base = 22;
            else if (km <= 6) base = 30;
            else {
                preview.textContent = 'Distance exceeds bicycle limit';
                return;
            }

        } else if (type === 'tricycle') {

            if (km <= 3) base = 50;
            else if (km <= 6) base = 70;
            else if (km <= 10) base = 100;
            else if (km <= 15) base = 135;
            else {
                preview.textContent = 'Distance exceeds tricycle limit';
                return;
            }
        }

        let total = base;

        total += extraStops * 10;

        if (waitMinutes > 10) {
            total += (waitMinutes - 10) * 2;
        }

        if (bulky.checked) {
            total += type === 'bicycle' ? 5 : 30;
        }

        if (rain.checked) {
            total += type === 'bicycle' ? 5 : 10;
        }

        if (rush.checked) {
            total *= 1.25;
        }

        preview.textContent = 'K' + total.toFixed(2);
    }

    [
        vehicle,
        distance,
        stops,
        waiting,
        rush,
        bulky,
        rain
    ].forEach(function (element) {

        element.addEventListener('input', calculatePreview);
        element.addEventListener('change', calculatePreview);

    });

    calculatePreview();

});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
