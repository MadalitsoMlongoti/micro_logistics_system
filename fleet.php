
<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

require_login();
require_permission('fleet');

$page_title = 'Fleet Management';
$currentPage = 'fleet.php';

$allowedTypes = ['bicycle', 'tricycle', 'motorbike'];
$allowedStatuses = ['available', 'assigned', 'maintenance', 'inactive'];

/*
|--------------------------------------------------------------------------
| POST ACTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['form_action'] ?? '';

    try {

        /*
        |--------------------------------------------------------------
        | REGISTER VEHICLE
        |--------------------------------------------------------------
        */

        if ($action === 'create') {

            $vehicleCode = strtoupper(trim($_POST['vehicle_code'] ?? ''));
            $vehicleType = $_POST['vehicle_type'] ?? '';
            $makeModel = trim($_POST['make_model'] ?? '');
            $registrationNumber = trim($_POST['registration_number'] ?? '');
            $payload = $_POST['payload_kg'] ?? '';
            $status = $_POST['status'] ?? 'available';
            $purchaseCost = $_POST['purchase_cost'] ?? '0';

            if ($vehicleCode === '' || strlen($vehicleCode) > 50) {
                throw new Exception('Enter a valid vehicle code.');
            }

            if (!in_array($vehicleType, $allowedTypes, true)) {
                throw new Exception('Please select a valid vehicle type.');
            }

            // New vehicles cannot be registered as assigned.
            if (!in_array($status, ['available', 'maintenance', 'inactive'], true)) {
                throw new Exception('Please select a valid initial vehicle status.');
            }

            if ($payload !== '' && (!is_numeric($payload) || (float)$payload < 0)) {
                throw new Exception('Payload must be a valid non-negative number.');
            }

            if (!is_numeric($purchaseCost) || (float)$purchaseCost < 0) {
                throw new Exception('Purchase cost must be a valid non-negative amount.');
            }

            if (strlen($makeModel) > 150 || strlen($registrationNumber) > 100) {
                throw new Exception('One or more vehicle fields are too long.');
            }

            $check = $pdo->prepare("
                SELECT id
                FROM vehicles
                WHERE vehicle_code = ?
                LIMIT 1
            ");
            $check->execute([$vehicleCode]);

            if ($check->fetch()) {
                throw new Exception("Vehicle code {$vehicleCode} already exists.");
            }

            $stmt = $pdo->prepare("
                INSERT INTO vehicles (
                    vehicle_code,
                    vehicle_type,
                    make_model,
                    registration_number,
                    payload_kg,
                    status,
                    purchase_cost
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $vehicleCode,
                $vehicleType,
                $makeModel !== '' ? $makeModel : null,
                $registrationNumber !== '' ? $registrationNumber : null,
                $payload !== '' ? (float)$payload : null,
                $status,
                (float)$purchaseCost
            ]);

            flash('Vehicle registered successfully.', 'success');
        }

        /*
        |--------------------------------------------------------------
        | UPDATE VEHICLE
        |--------------------------------------------------------------
        */

        elseif ($action === 'update') {

            $vehicleId = (int)($_POST['vehicle_id'] ?? 0);

            $vehicleCode = strtoupper(trim($_POST['vehicle_code'] ?? ''));
            $vehicleType = $_POST['vehicle_type'] ?? '';
            $makeModel = trim($_POST['make_model'] ?? '');
            $registrationNumber = trim($_POST['registration_number'] ?? '');
            $payload = $_POST['payload_kg'] ?? '';
            $status = $_POST['status'] ?? '';
            $purchaseCost = $_POST['purchase_cost'] ?? '';

            if ($vehicleId <= 0) {
                throw new Exception('Invalid vehicle selected.');
            }

            if ($vehicleCode === '' || strlen($vehicleCode) > 50) {
                throw new Exception('Enter a valid vehicle code.');
            }

            if (!in_array($vehicleType, $allowedTypes, true)) {
                throw new Exception('Please select a valid vehicle type.');
            }

            if (!in_array($status, ['available', 'maintenance', 'inactive'], true)) {
                throw new Exception('Please select a valid vehicle status.');
            }

            if ($payload !== '' && (!is_numeric($payload) || (float)$payload < 0)) {
                throw new Exception('Payload must be a valid non-negative number.');
            }

            if (!is_numeric($purchaseCost) || (float)$purchaseCost < 0) {
                throw new Exception('Purchase cost must be a valid non-negative amount.');
            }

            if (strlen($makeModel) > 150 || strlen($registrationNumber) > 100) {
                throw new Exception('One or more vehicle fields are too long.');
            }

            $pdo->beginTransaction();

            $vehicleStmt = $pdo->prepare("
                SELECT *
                FROM vehicles
                WHERE id = ?
                FOR UPDATE
            ");
            $vehicleStmt->execute([$vehicleId]);

            $existingVehicle = $vehicleStmt->fetch(PDO::FETCH_ASSOC);

            if (!$existingVehicle) {
                throw new Exception('Vehicle not found.');
            }

            $duplicateStmt = $pdo->prepare("
                SELECT id
                FROM vehicles
                WHERE vehicle_code = ?
                  AND id != ?
                LIMIT 1
            ");
            $duplicateStmt->execute([$vehicleCode, $vehicleId]);

            if ($duplicateStmt->fetch()) {
                throw new Exception("Vehicle code {$vehicleCode} is already used.");
            }

            $activeDeliveryStmt = $pdo->prepare("
                SELECT id
                FROM deliveries
                WHERE vehicle_id = ?
                  AND status IN ('assigned', 'picked_up', 'in_transit')
                LIMIT 1
            ");
            $activeDeliveryStmt->execute([$vehicleId]);

            $hasActiveDelivery = (bool)$activeDeliveryStmt->fetch();

            if (
                $hasActiveDelivery &&
                $vehicleType !== $existingVehicle['vehicle_type']
            ) {
                throw new Exception(
                    'Vehicle type cannot be changed while the vehicle is assigned to an active delivery.'
                );
            }

            $maintenanceStmt = $pdo->prepare("
                SELECT id
                FROM maintenance_records
                WHERE vehicle_id = ?
                  AND status = 'in_progress'
                LIMIT 1
            ");
            $maintenanceStmt->execute([$vehicleId]);

            $hasActiveMaintenance = (bool)$maintenanceStmt->fetch();

            if ($hasActiveMaintenance && $status !== 'maintenance') {
                throw new Exception(
                    'This vehicle has ongoing maintenance. Complete the maintenance record before changing its status.'
                );
            }

            if (
                $existingVehicle['status'] === 'assigned' &&
                !$hasActiveDelivery
            ) {
                throw new Exception(
                    'This vehicle is marked assigned but has no active delivery. Please resolve its status before editing.'
                );
            }

            // Dispatch controls assigned status.
            // Preserve assigned while an active delivery exists.
            $finalStatus = $hasActiveDelivery ? 'assigned' : $status;

            $updateStmt = $pdo->prepare("
                UPDATE vehicles
                SET
                    vehicle_code = ?,
                    vehicle_type = ?,
                    make_model = ?,
                    registration_number = ?,
                    payload_kg = ?,
                    status = ?,
                    purchase_cost = ?
                WHERE id = ?
            ");

            $updateStmt->execute([
                $vehicleCode,
                $vehicleType,
                $makeModel !== '' ? $makeModel : null,
                $registrationNumber !== '' ? $registrationNumber : null,
                $payload !== '' ? (float)$payload : null,
                $finalStatus,
                (float)$purchaseCost,
                $vehicleId
            ]);

            $pdo->commit();

            flash('Vehicle information updated successfully.', 'success');
        }

        /*
        |--------------------------------------------------------------
        | DELETE VEHICLE
        |--------------------------------------------------------------
        */

        elseif ($action === 'delete') {

            $vehicleId = (int)($_POST['vehicle_id'] ?? 0);

            if ($vehicleId <= 0) {
                throw new Exception('Invalid vehicle selected.');
            }

            $pdo->beginTransaction();

            $vehicleStmt = $pdo->prepare("
                SELECT id, vehicle_code, status
                FROM vehicles
                WHERE id = ?
                FOR UPDATE
            ");
            $vehicleStmt->execute([$vehicleId]);

            $vehicle = $vehicleStmt->fetch(PDO::FETCH_ASSOC);

            if (!$vehicle) {
                throw new Exception('Vehicle not found.');
            }

            if ($vehicle['status'] === 'assigned') {
                throw new Exception(
                    'This vehicle is currently assigned. Complete or cancel its delivery before deleting.'
                );
            }

            $activeDeliveryStmt = $pdo->prepare("
                SELECT id
                FROM deliveries
                WHERE vehicle_id = ?
                  AND status IN ('assigned', 'picked_up', 'in_transit')
                LIMIT 1
            ");
            $activeDeliveryStmt->execute([$vehicleId]);

            if ($activeDeliveryStmt->fetch()) {
                throw new Exception(
                    'This vehicle has an active delivery and cannot be deleted.'
                );
            }

            $deliveryStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM deliveries
                WHERE vehicle_id = ?
            ");
            $deliveryStmt->execute([$vehicleId]);

            if ((int)$deliveryStmt->fetchColumn() > 0) {
                throw new Exception(
                    'This vehicle has delivery history and cannot be deleted. Set it to inactive instead.'
                );
            }

            $maintenanceStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM maintenance_records
                WHERE vehicle_id = ?
            ");
            $maintenanceStmt->execute([$vehicleId]);

            if ((int)$maintenanceStmt->fetchColumn() > 0) {
                throw new Exception(
                    'This vehicle has maintenance history and cannot be deleted. Set it to inactive instead.'
                );
            }

            $deleteStmt = $pdo->prepare("
                DELETE FROM vehicles
                WHERE id = ?
            ");
            $deleteStmt->execute([$vehicleId]);

            $pdo->commit();

            flash('Vehicle deleted successfully.', 'success');
        }

        else {
            throw new Exception('Invalid action.');
        }

    } catch (PDOException $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
            flash('This vehicle code or registration number already exists.', 'warning');
        } else {
            error_log($e->getMessage());
            flash('A database error occurred while processing the vehicle.', 'danger');
        }

    } catch (Exception $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash($e->getMessage(), 'danger');
    }

    header('Location: fleet.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| FLEET STATISTICS
|--------------------------------------------------------------------------
*/

$statsQuery = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'available') AS available,
        SUM(status = 'assigned') AS assigned,
        SUM(status = 'maintenance') AS maintenance,
        SUM(status = 'inactive') AS inactive
    FROM vehicles
");

$statsResult = $statsQuery->fetch(PDO::FETCH_ASSOC);

$stats = [
    'total' => (int)($statsResult['total'] ?? 0),
    'available' => (int)($statsResult['available'] ?? 0),
    'assigned' => (int)($statsResult['assigned'] ?? 0),
    'maintenance' => (int)($statsResult['maintenance'] ?? 0),
    'inactive' => (int)($statsResult['inactive'] ?? 0)
];

$availabilityPercentage = $stats['total'] > 0
    ? round(($stats['available'] / $stats['total']) * 100)
    : 0;

/*
|--------------------------------------------------------------------------
| SEARCH AND FILTERS
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');
$typeFilter = $_GET['vehicle_type'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(
        v.vehicle_code LIKE ?
        OR v.make_model LIKE ?
        OR v.registration_number LIKE ?
    )";

    $term = '%' . $search . '%';

    $params = array_merge($params, [
        $term,
        $term,
        $term
    ]);
}

if (in_array($typeFilter, $allowedTypes, true)) {
    $where[] = "v.vehicle_type = ?";
    $params[] = $typeFilter;
} else {
    $typeFilter = '';
}

if (in_array($statusFilter, $allowedStatuses, true)) {
    $where[] = "v.status = ?";
    $params[] = $statusFilter;
} else {
    $statusFilter = '';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/*
|--------------------------------------------------------------------------
| VEHICLE DIRECTORY
|--------------------------------------------------------------------------
*/

$vehiclesQuery = $pdo->prepare("
    SELECT
        v.*,

        (
            SELECT COUNT(*)
            FROM maintenance_records m
            WHERE m.vehicle_id = v.id
        ) AS maintenance_count,

        (
            SELECT COUNT(*)
            FROM deliveries d
            WHERE d.vehicle_id = v.id
        ) AS delivery_count,

        (
            SELECT COUNT(*)
            FROM deliveries d
            WHERE d.vehicle_id = v.id
              AND d.status IN ('assigned', 'picked_up', 'in_transit')
        ) AS active_delivery_count,

        (
            SELECT COUNT(*)
            FROM maintenance_records m
            WHERE m.vehicle_id = v.id
              AND m.status = 'in_progress'
        ) AS active_maintenance_count

    FROM vehicles v
    $whereSql
    ORDER BY v.id DESC
");

$vehiclesQuery->execute($params);
$vehicles = $vehiclesQuery->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/includes/header.php';
?>

<style>
/* =========================================
   FLEET MANAGEMENT DESIGN
========================================= */

.fleet-hero {
    position: relative;
    overflow: hidden;
    border-radius: 18px;
    padding: 30px;
    color: #fff;
    background: linear-gradient(115deg, #102f59, #1769aa);
}

.fleet-hero::after {
    content: "";
    position: absolute;
    width: 230px;
    height: 230px;
    border: 35px solid rgba(255,255,255,.07);
    border-radius: 50%;
    right: -55px;
    top: -105px;
}

.fleet-hero-content {
    position: relative;
    z-index: 1;
}

.fleet-hero-icon {
    width: 55px;
    height: 55px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255,255,255,.15);
    border-radius: 15px;
    font-size: 27px;
}

.fleet-stat {
    border: 0;
    border-radius: 15px;
    height: 100%;
    box-shadow: 0 3px 15px rgba(15, 23, 42, .055);
    transition: transform .2s ease, box-shadow .2s ease;
}

.fleet-stat:hover {
    transform: translateY(-3px);
    box-shadow: 0 7px 20px rgba(15, 23, 42, .09);
}

.fleet-stat-icon {
    width: 46px;
    height: 46px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 13px;
    font-size: 21px;
}

.fleet-stat-number {
    font-size: 27px;
    font-weight: 750;
    line-height: 1.2;
}

.fleet-panel {
    border: 0;
    border-radius: 15px;
    box-shadow: 0 3px 15px rgba(15, 23, 42, .055);
    overflow: hidden;
}

.fleet-panel-header {
    padding: 19px 22px;
    background: #fff;
    border-bottom: 1px solid #edf0f4;
}

.fleet-filter {
    background: #f8fafc;
    border: 1px solid #e9eef4;
    border-radius: 12px;
    padding: 15px;
}

.fleet-table {
    margin-bottom: 0;
}

.fleet-table thead th {
    background: #f8fafc;
    color: #64748b;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    padding: 15px 13px;
    white-space: nowrap;
    border-bottom: 1px solid #e9eef4;
}

.fleet-table tbody td {
    padding: 15px 13px;
    vertical-align: middle;
    border-color: #f0f2f5;
}

.fleet-table tbody tr:hover {
    background: #f9fbff;
}

.vehicle-avatar {
    width: 43px;
    height: 43px;
    display: inline-flex;
    justify-content: center;
    align-items: center;
    border-radius: 12px;
    font-size: 20px;
    flex-shrink: 0;
}

.vehicle-code {
    font-weight: 700;
    color: #1e293b;
}

.vehicle-type-badge {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 7px;
    background: #eff6ff;
    color: #1d4ed8;
    font-size: 11px;
    font-weight: 700;
    text-transform: capitalize;
}

.vehicle-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    border-radius: 30px;
    font-size: 11px;
    font-weight: 700;
    text-transform: capitalize;
    white-space: nowrap;
}

.vehicle-status::before {
    content: "";
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
}

.vehicle-status.available {
    background: #dcfce7;
    color: #166534;
}

.vehicle-status.assigned {
    background: #dbeafe;
    color: #1d4ed8;
}

.vehicle-status.maintenance {
    background: #fef3c7;
    color: #92400e;
}

.vehicle-status.inactive {
    background: #fee2e2;
    color: #991b1b;
}

.vehicle-metric {
    font-size: 12px;
    color: #64748b;
}

.vehicle-metric strong {
    color: #334155;
}

.fleet-form-label {
    font-size: 12px;
    font-weight: 700;
    color: #475569;
    margin-bottom: 7px;
}

.fleet-section-title {
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .6px;
    color: #64748b;
    text-transform: uppercase;
    margin-bottom: 14px;
}

.fleet-empty {
    padding: 55px 15px;
    text-align: center;
}

.fleet-empty-icon {
    width: 65px;
    height: 65px;
    display: inline-flex;
    justify-content: center;
    align-items: center;
    border-radius: 20px;
    background: #eff6ff;
    color: #2563eb;
    font-size: 29px;
}

.fleet-progress {
    height: 7px;
    border-radius: 20px;
    background: #e9eef5;
}

.fleet-progress .progress-bar {
    border-radius: 20px;
}

.modal-content {
    border: 0;
    border-radius: 16px;
    overflow: hidden;
}

.modal-header,
.modal-footer {
    padding: 18px 22px;
}

.modal-body {
    padding: 24px;
}

@media(max-width:767px) {
    .fleet-hero {
        padding: 22px;
    }

    .fleet-hero-actions {
        margin-top: 20px;
    }

    .fleet-stat-number {
        font-size: 23px;
    }
}
</style>

<div class="container-fluid">

    <?php show_flash(); ?>

    <!-- =========================================
         PAGE HERO
    ========================================== -->

    <div class="fleet-hero mb-4">
        <div class="fleet-hero-content d-flex flex-wrap justify-content-between align-items-center">

            <div class="d-flex align-items-center gap-3">
                <div class="fleet-hero-icon">
                    <i class="bi bi-truck-front"></i>
                </div>

                <div>
                    <div class="small text-white-50 mb-1">
                        OPERATIONS / FLEET MANAGEMENT
                    </div>

                    <h2 class="fw-bold mb-1">Fleet Management</h2>

                    <p class="text-white-50 mb-0">
                        Monitor vehicles, manage fleet availability and maintain vehicle records.
                    </p>
                </div>
            </div>

            <div class="fleet-hero-actions">
                <button
                    class="btn btn-light fw-semibold px-4"
                    data-bs-toggle="modal"
                    data-bs-target="#addVehicleModal"
                >
                    <i class="bi bi-plus-circle me-1"></i>
                    Register Vehicle
                </button>
            </div>

        </div>
    </div>

    <!-- =========================================
         FLEET KPI CARDS
    ========================================== -->

    <div class="row g-3 mb-4">

        <div class="col-6 col-xl">
            <div class="card fleet-stat">
                <div class="card-body p-3 p-lg-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-muted small">Total Fleet</span>
                        <div class="fleet-stat-icon bg-primary-subtle text-primary">
                            <i class="bi bi-truck"></i>
                        </div>
                    </div>
                    <div class="fleet-stat-number">
                        <?= number_format($stats['total']) ?>
                    </div>
                    <small class="text-muted">Registered vehicles</small>
                </div>
            </div>
        </div>

        <div class="col-6 col-xl">
            <div class="card fleet-stat">
                <div class="card-body p-3 p-lg-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-muted small">Available</span>
                        <div class="fleet-stat-icon bg-success-subtle text-success">
                            <i class="bi bi-check-circle"></i>
                        </div>
                    </div>
                    <div class="fleet-stat-number text-success">
                        <?= number_format($stats['available']) ?>
                    </div>
                    <small class="text-muted">Ready for dispatch</small>
                </div>
            </div>
        </div>

        <div class="col-6 col-xl">
            <div class="card fleet-stat">
                <div class="card-body p-3 p-lg-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-muted small">Assigned</span>
                        <div class="fleet-stat-icon bg-info-subtle text-info">
                            <i class="bi bi-person-check"></i>
                        </div>
                    </div>
                    <div class="fleet-stat-number text-primary">
                        <?= number_format($stats['assigned']) ?>
                    </div>
                    <small class="text-muted">Currently on delivery</small>
                </div>
            </div>
        </div>

        <div class="col-6 col-xl">
            <div class="card fleet-stat">
                <div class="card-body p-3 p-lg-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-muted small">Maintenance</span>
                        <div class="fleet-stat-icon bg-warning-subtle text-warning">
                            <i class="bi bi-tools"></i>
                        </div>
                    </div>
                    <div class="fleet-stat-number text-warning">
                        <?= number_format($stats['maintenance']) ?>
                    </div>
                    <small class="text-muted">Under maintenance</small>
                </div>
            </div>
        </div>

        <div class="col-6 col-xl">
            <div class="card fleet-stat">
                <div class="card-body p-3 p-lg-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-muted small">Inactive</span>
                        <div class="fleet-stat-icon bg-danger-subtle text-danger">
                            <i class="bi bi-slash-circle"></i>
                        </div>
                    </div>
                    <div class="fleet-stat-number text-danger">
                        <?= number_format($stats['inactive']) ?>
                    </div>
                    <small class="text-muted">Not in operation</small>
                </div>
            </div>
        </div>

    </div>

    <!-- =========================================
         AVAILABILITY OVERVIEW
    ========================================== -->

    <div class="card fleet-panel mb-4">
        <div class="card-body p-3 p-lg-4">

            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                <div>
                    <h6 class="fw-bold mb-1">Fleet Availability</h6>
                    <small class="text-muted">
                        Percentage of registered vehicles currently ready for dispatch.
                    </small>
                </div>

                <div class="text-end">
                    <div class="fs-4 fw-bold text-success">
                        <?= $availabilityPercentage ?>%
                    </div>
                    <small class="text-muted">Available fleet</small>
                </div>
            </div>

            <div class="progress fleet-progress">
                <div
                    class="progress-bar bg-success"
                    role="progressbar"
                    style="width: <?= $availabilityPercentage ?>%"
                    aria-valuenow="<?= $availabilityPercentage ?>"
                    aria-valuemin="0"
                    aria-valuemax="100"
                ></div>
            </div>

            <div class="d-flex justify-content-between mt-2">
                <small class="text-muted">
                    <?= $stats['available'] ?> available vehicles
                </small>
                <small class="text-muted">
                    <?= $stats['total'] ?> total vehicles
                </small>
            </div>

        </div>
    </div>

    <!-- =========================================
         VEHICLE DIRECTORY
    ========================================== -->

    <div class="card fleet-panel">

        <div class="fleet-panel-header">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">

                <div>
                    <h5 class="fw-bold mb-1">
                        <i class="bi bi-grid-1x2 text-primary me-2"></i>
                        Vehicle Directory
                    </h5>

                    <small class="text-muted">
                        Search, filter and manage your registered fleet.
                    </small>
                </div>

                <span class="badge bg-primary-subtle text-primary px-3 py-2">
                    <?= count($vehicles) ?> vehicles found
                </span>

            </div>
        </div>

        <div class="card-body p-3 p-lg-4">

            <!-- SEARCH AND FILTERS -->

            <form method="GET" class="fleet-filter mb-4">

                <div class="row g-2 align-items-end">

                    <div class="col-lg-5">
                        <label class="fleet-form-label">Search Vehicles</label>

                        <div class="input-group">
                            <span class="input-group-text bg-white">
                                <i class="bi bi-search text-muted"></i>
                            </span>

                            <input
                                type="text"
                                name="search"
                                class="form-control"
                                placeholder="Vehicle code, model or registration..."
                                value="<?= e($search) ?>"
                            >
                        </div>
                    </div>

                    <div class="col-lg-2 col-md-4">
                        <label class="fleet-form-label">Vehicle Type</label>

                        <select name="vehicle_type" class="form-select">
                            <option value="">All Types</option>
                            <option value="bicycle" <?= $typeFilter === 'bicycle' ? 'selected' : '' ?>>Bicycle</option>
                            <option value="tricycle" <?= $typeFilter === 'tricycle' ? 'selected' : '' ?>>Tricycle</option>
                            <option value="motorbike" <?= $typeFilter === 'motorbike' ? 'selected' : '' ?>>Motorbike</option>
                        </select>
                    </div>

                    <div class="col-lg-2 col-md-4">
                        <label class="fleet-form-label">Status</label>

                        <select name="status" class="form-select">
                            <option value="">All Statuses</option>
                            <option value="available" <?= $statusFilter === 'available' ? 'selected' : '' ?>>Available</option>
                            <option value="assigned" <?= $statusFilter === 'assigned' ? 'selected' : '' ?>>Assigned</option>
                            <option value="maintenance" <?= $statusFilter === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                            <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="col-lg-3 col-md-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            <i class="bi bi-funnel me-1"></i>
                            Apply Filters
                        </button>

                        <a href="fleet.php" class="btn btn-outline-secondary" title="Clear filters">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </a>
                    </div>

                </div>
            </form>

            <!-- VEHICLE TABLE -->

            <div class="table-responsive">

                <table class="table fleet-table table-hover">

                    <thead>
                        <tr>
                            <th>Vehicle</th>
                            <th>Type</th>
                            <th>Registration</th>
                            <th>Capacity</th>
                            <th>Status</th>
                            <th>Activity</th>
                            <th>Purchase Cost</th>
                            <th>Date Added</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (empty($vehicles)): ?>

                        <tr>
                            <td colspan="9">
                                <div class="fleet-empty">
                                    <div class="fleet-empty-icon mb-3">
                                        <i class="bi bi-truck"></i>
                                    </div>

                                    <h6 class="fw-bold">No vehicles found</h6>

                                    <p class="text-muted small mb-3">
                                        No vehicles match your search or filter criteria.
                                    </p>

                                    <?php if ($search !== '' || $typeFilter !== '' || $statusFilter !== ''): ?>
                                        <a href="fleet.php" class="btn btn-sm btn-outline-primary">
                                            Clear Filters
                                        </a>
                                    <?php else: ?>
                                        <button
                                            class="btn btn-sm btn-primary"
                                            data-bs-toggle="modal"
                                            data-bs-target="#addVehicleModal"
                                        >
                                            <i class="bi bi-plus-circle me-1"></i>
                                            Register First Vehicle
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($vehicles as $vehicle): ?>

                            <?php
                            $typeIcons = [
                                'bicycle' => 'bi-bicycle',
                                'tricycle' => 'bi-truck',
                                'motorbike' => 'bi-scooter'
                            ];

                            $typeIcon = $typeIcons[$vehicle['vehicle_type']] ?? 'bi-truck';

                            $status = $vehicle['status'];

                            $vehicleJson = htmlspecialchars(
                                json_encode(
                                    $vehicle,
                                    JSON_HEX_APOS |
                                    JSON_HEX_QUOT |
                                    JSON_HEX_TAG |
                                    JSON_HEX_AMP
                                ),
                                ENT_QUOTES,
                                'UTF-8'
                            );

                            $canDelete = (
                                (int)$vehicle['delivery_count'] === 0 &&
                                (int)$vehicle['maintenance_count'] === 0 &&
                                (int)$vehicle['active_delivery_count'] === 0 &&
                                $status !== 'assigned'
                            );
                            ?>

                            <tr>

                                <!-- VEHICLE IDENTITY -->

                                <td>
                                    <div class="d-flex align-items-center gap-2">

                                        <div class="vehicle-avatar bg-primary-subtle text-primary">
                                            <i class="bi <?= e($typeIcon) ?>"></i>
                                        </div>

                                        <div>
                                            <div class="vehicle-code">
                                                <?= e($vehicle['vehicle_code']) ?>
                                            </div>

                                            <small class="text-muted">
                                                <?= e($vehicle['make_model'] ?: 'Model not specified') ?>
                                            </small>
                                        </div>

                                    </div>
                                </td>

                                <!-- TYPE -->

                                <td>
                                    <span class="vehicle-type-badge">
                                        <?= e(ucfirst($vehicle['vehicle_type'])) ?>
                                    </span>
                                </td>

                                <!-- REGISTRATION -->

                                <td>
                                    <span class="fw-semibold small">
                                        <?= e($vehicle['registration_number'] ?: 'Not registered') ?>
                                    </span>
                                </td>

                                <!-- CAPACITY -->

                                <td>
                                    <?php if ($vehicle['payload_kg'] !== null): ?>
                                        <span class="fw-semibold">
                                            <?= number_format((float)$vehicle['payload_kg'], 2) ?>
                                        </span>
                                        <small class="text-muted">kg</small>
                                    <?php else: ?>
                                        <span class="text-muted">Not set</span>
                                    <?php endif; ?>
                                </td>

                                <!-- STATUS -->

                                <td>
                                    <span class="vehicle-status <?= e($status) ?>">
                                        <?= e($status) ?>
                                    </span>

                                    <?php if ((int)$vehicle['active_maintenance_count'] > 0): ?>
                                        <div class="small text-warning mt-1">
                                            <i class="bi bi-tools"></i>
                                            Maintenance in progress
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- ACTIVITY -->

                                <td>
                                    <div class="vehicle-metric">
                                        <div>
                                            <strong><?= (int)$vehicle['delivery_count'] ?></strong>
                                            deliveries
                                        </div>

                                        <div>
                                            <strong><?= (int)$vehicle['maintenance_count'] ?></strong>
                                            maintenance records
                                        </div>

                                        <?php if ((int)$vehicle['active_delivery_count'] > 0): ?>
                                            <span class="text-primary fw-semibold">
                                                <?= (int)$vehicle['active_delivery_count'] ?> active
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- PURCHASE COST -->

                                <td>
                                    <span class="fw-semibold">
                                        <?= money($vehicle['purchase_cost'] ?? 0) ?>
                                    </span>
                                </td>

                                <!-- DATE -->

                                <td>
                                    <div class="small">
                                        <?= !empty($vehicle['created_at'])
                                            ? e(date('d M Y', strtotime($vehicle['created_at'])))
                                            : '-' ?>
                                    </div>

                                    <small class="text-muted">
                                        <?= !empty($vehicle['created_at'])
                                            ? e(date('H:i', strtotime($vehicle['created_at'])))
                                            : '' ?>
                                    </small>
                                </td>

                                <!-- ACTIONS -->

                                <td>
                                    <div class="d-flex justify-content-end gap-1">

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-primary"
                                            title="Edit vehicle"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editVehicleModal"
                                            data-vehicle="<?= $vehicleJson ?>"
                                        >
                                            <i class="bi bi-pencil-square"></i>
                                        </button>

                                        <?php if ($canDelete): ?>

                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-danger"
                                                title="Delete vehicle"
                                                data-bs-toggle="modal"
                                                data-bs-target="#deleteVehicleModal"
                                                data-id="<?= (int)$vehicle['id'] ?>"
                                                data-code="<?= e($vehicle['vehicle_code']) ?>"
                                            >
                                                <i class="bi bi-trash"></i>
                                            </button>

                                        <?php else: ?>

                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-secondary"
                                                disabled
                                                title="Vehicle has delivery or maintenance history"
                                            >
                                                <i class="bi bi-lock"></i>
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

            <div class="d-flex justify-content-between align-items-center mt-3">
                <small class="text-muted">
                    Showing <?= count($vehicles) ?> vehicle(s)
                </small>

                <small class="text-muted">
                    Fleet records
                </small>
            </div>

        </div>
    </div>

</div>

<!-- =========================================
     ADD VEHICLE MODAL
========================================= -->

<div class="modal fade" id="addVehicleModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content">

            <form method="POST" action="fleet.php">

                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold">
                            <i class="bi bi-truck text-primary me-2"></i>
                            Register New Vehicle
                        </h5>
                        <small class="text-muted">
                            Add a vehicle to your fleet records.
                        </small>
                    </div>

                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="form_action" value="create">

                    <div class="fleet-section-title">
                        <i class="bi bi-card-text me-1"></i>
                        Vehicle Identification
                    </div>

                    <div class="row g-3 mb-4">

                        <div class="col-md-6">
                            <label class="fleet-form-label">Vehicle Code *</label>
                            <input
                                type="text"
                                name="vehicle_code"
                                class="form-control text-uppercase"
                                placeholder="e.g. MTR-043"
                                maxlength="50"
                                required
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="fleet-form-label">Vehicle Type *</label>
                            <select name="vehicle_type" class="form-select" required>
                                <option value="">Select vehicle type</option>
                                <option value="bicycle">Bicycle</option>
                                <option value="tricycle">Tricycle</option>
                                <option value="motorbike">Motorbike</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="fleet-form-label">Make / Model</label>
                            <input
                                type="text"
                                name="make_model"
                                class="form-control"
                                maxlength="150"
                                placeholder="e.g. Honda Cargo"
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="fleet-form-label">Registration Number</label>
                            <input
                                type="text"
                                name="registration_number"
                                class="form-control"
                                maxlength="100"
                                placeholder="Enter registration number"
                            >
                        </div>

                    </div>

                    <div class="fleet-section-title">
                        <i class="bi bi-speedometer2 me-1"></i>
                        Vehicle Specifications
                    </div>

                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="fleet-form-label">Payload Capacity (KG)</label>
                            <input
                                type="number"
                                name="payload_kg"
                                class="form-control"
                                min="0"
                                step="0.01"
                                placeholder="e.g. 100"
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="fleet-form-label">Purchase Cost (K)</label>
                            <input
                                type="number"
                                name="purchase_cost"
                                class="form-control"
                                min="0"
                                step="0.01"
                                value="0"
                                required
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="fleet-form-label">Initial Status</label>
                            <select name="status" class="form-select">
                                <option value="available">Available</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>

                    </div>

                    <div class="alert alert-info border-0 mt-4 mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        Newly registered vehicles cannot be marked as assigned. Dispatch controls vehicle assignment.
                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-check-circle me-1"></i>
                        Register Vehicle
                    </button>

                </div>

            </form>

        </div>
    </div>
</div>

<!-- =========================================
     EDIT VEHICLE MODAL
========================================= -->

<div class="modal fade" id="editVehicleModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content">

            <form method="POST" action="fleet.php">

                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold">
                            <i class="bi bi-pencil-square text-primary me-2"></i>
                            Edit Vehicle
                        </h5>
                        <small class="text-muted">
                            Update the vehicle's information.
                        </small>
                    </div>

                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="form_action" value="update">
                    <input type="hidden" name="vehicle_id" id="edit_vehicle_id">

                    <div id="activeDeliveryNotice" class="alert alert-info d-none">
                        <i class="bi bi-info-circle me-2"></i>
                        This vehicle has an active delivery. Its assigned status and vehicle type are protected until the delivery is completed or cancelled.
                    </div>

                    <div id="activeMaintenanceNotice" class="alert alert-warning d-none">
                        <i class="bi bi-tools me-2"></i>
                        This vehicle has maintenance in progress. Its status must remain Maintenance until the work is completed.
                    </div>

                    <div class="fleet-section-title">
                        Vehicle Identification
                    </div>

                    <div class="row g-3 mb-4">

                        <div class="col-md-6">
                            <label class="fleet-form-label">Vehicle Code *</label>
                            <input
                                type="text"
                                name="vehicle_code"
                                id="edit_vehicle_code"
                                class="form-control text-uppercase"
                                maxlength="50"
                                required
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="fleet-form-label">Vehicle Type *</label>
                            <select
                                name="vehicle_type"
                                id="edit_vehicle_type"
                                class="form-select"
                                required
                            >
                                <option value="bicycle">Bicycle</option>
                                <option value="tricycle">Tricycle</option>
                                <option value="motorbike">Motorbike</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="fleet-form-label">Make / Model</label>
                            <input
                                type="text"
                                name="make_model"
                                id="edit_make_model"
                                class="form-control"
                                maxlength="150"
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="fleet-form-label">Registration Number</label>
                            <input
                                type="text"
                                name="registration_number"
                                id="edit_registration_number"
                                class="form-control"
                                maxlength="100"
                            >
                        </div>

                    </div>

                    <div class="fleet-section-title">
                        Vehicle Specifications
                    </div>

                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="fleet-form-label">Payload Capacity (KG)</label>
                            <input
                                type="number"
                                name="payload_kg"
                                id="edit_payload_kg"
                                class="form-control"
                                min="0"
                                step="0.01"
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="fleet-form-label">Purchase Cost (K)</label>
                            <input
                                type="number"
                                name="purchase_cost"
                                id="edit_purchase_cost"
                                class="form-control"
                                min="0"
                                step="0.01"
                                required
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="fleet-form-label">Vehicle Status</label>
                            <select
                                name="status"
                                id="edit_vehicle_status"
                                class="form-select"
                            >
                                <option value="available">Available</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="inactive">Inactive</option>
                            </select>

                            <small class="text-muted">
                                Assigned status is controlled by Dispatch.
                            </small>
                        </div>

                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-save me-1"></i>
                        Save Changes
                    </button>

                </div>

            </form>

        </div>
    </div>
</div>

<!-- =========================================
     DELETE VEHICLE MODAL
========================================= -->

<div class="modal fade" id="deleteVehicleModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <form method="POST" action="fleet.php">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-danger">
                        <i class="bi bi-trash me-2"></i>
                        Delete Vehicle
                    </h5>

                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body text-center">

                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="form_action" value="delete">
                    <input type="hidden" name="vehicle_id" id="delete_vehicle_id">

                    <div class="text-danger mb-3">
                        <i class="bi bi-exclamation-triangle" style="font-size: 55px;"></i>
                    </div>

                    <h6 class="fw-bold">Are you sure you want to delete this vehicle?</h6>

                    <div class="fs-5 fw-bold text-dark my-2" id="delete_vehicle_code"></div>

                    <p class="text-muted small mb-0">
                        This action cannot be undone. Vehicles with delivery or maintenance history cannot be deleted.
                    </p>

                </div>

                <div class="modal-footer justify-content-center">

                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button type="submit" class="btn btn-danger px-4">
                        <i class="bi bi-trash me-1"></i>
                        Delete Vehicle
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

    const editModal = document.getElementById('editVehicleModal');

    if (editModal) {

        editModal.addEventListener('show.bs.modal', function (event) {

            const button = event.relatedTarget;

            let vehicle;

            try {
                vehicle = JSON.parse(button.getAttribute('data-vehicle'));
            } catch (error) {
                alert('Unable to load vehicle information.');
                return;
            }

            document.getElementById('edit_vehicle_id').value = vehicle.id;
            document.getElementById('edit_vehicle_code').value = vehicle.vehicle_code || '';
            document.getElementById('edit_vehicle_type').value = vehicle.vehicle_type || 'bicycle';
            document.getElementById('edit_make_model').value = vehicle.make_model || '';
            document.getElementById('edit_registration_number').value = vehicle.registration_number || '';
            document.getElementById('edit_payload_kg').value = vehicle.payload_kg ?? '';
            document.getElementById('edit_purchase_cost').value = vehicle.purchase_cost ?? 0;

            const statusSelect = document.getElementById('edit_vehicle_status');
            const typeSelect = document.getElementById('edit_vehicle_type');

            const activeDelivery = Number(vehicle.active_delivery_count) > 0;
            const activeMaintenance = Number(vehicle.active_maintenance_count) > 0;

            const deliveryNotice = document.getElementById('activeDeliveryNotice');
            const maintenanceNotice = document.getElementById('activeMaintenanceNotice');

            deliveryNotice.classList.toggle('d-none', !activeDelivery);
            maintenanceNotice.classList.toggle('d-none', !activeMaintenance);

            // Vehicle type cannot change during an active delivery.
            typeSelect.disabled = activeDelivery;

            // The backend preserves assigned status when there is an active delivery.
            if (activeDelivery) {
                statusSelect.value = 'available';
                statusSelect.disabled = true;
            } else {
                statusSelect.disabled = false;
                statusSelect.value = vehicle.status === 'assigned'
                    ? 'available'
                    : vehicle.status;
            }

            // Maintenance status cannot be changed while maintenance is ongoing.
            if (activeMaintenance) {
                statusSelect.value = 'maintenance';
                statusSelect.disabled = true;
            }

        });

        editModal.addEventListener('hidden.bs.modal', function () {
            document.getElementById('edit_vehicle_status').disabled = false;
            document.getElementById('edit_vehicle_type').disabled = false;
        });
    }

    const deleteModal = document.getElementById('deleteVehicleModal');

    if (deleteModal) {

        deleteModal.addEventListener('show.bs.modal', function (event) {

            const button = event.relatedTarget;

            document.getElementById('delete_vehicle_id').value =
                button.getAttribute('data-id');

            document.getElementById('delete_vehicle_code').textContent =
                button.getAttribute('data-code');

        });
    }

});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
