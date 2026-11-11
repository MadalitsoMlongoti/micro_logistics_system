
<?php
require 'config/database.php';
require 'includes/functions.php';

require_login();
require_permission('maintenance');

$page_title = 'Maintenance';
$currentPage = 'maintenance.php';

/* =========================================
   HELPER: VALIDATE DATE
========================================= */

function validMaintenanceDate($date)
{
    if (!$date) {
        return false;
    }

    $parsed = DateTime::createFromFormat('!Y-m-d', $date);

    return $parsed && $parsed->format('Y-m-d') === $date;
}

/* =========================================
   HANDLE MAINTENANCE ACTIONS
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['form_action'] ?? '';

    try {

        /* =====================================
           CREATE MAINTENANCE
        ===================================== */

        if ($action === 'create') {

            $vehicleId = filter_var(
                $_POST['vehicle_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $serviceDate = trim($_POST['service_date'] ?? '');
            $maintenanceType = trim($_POST['maintenance_type'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $cost = $_POST['cost'] ?? '';
            $odometer = $_POST['odometer_km'] ?? '';
            $nextServiceDate = trim($_POST['next_service_date'] ?? '');

            $allowedTypes = [
                'Service', 'Repair', 'Tyre', 'Inspection', 'Other'
            ];

            if (!$vehicleId || $vehicleId <= 0) {
                throw new Exception('Please select a valid vehicle.');
            }

            if (!validMaintenanceDate($serviceDate)) {
                throw new Exception('Please enter a valid service date.');
            }

            if ($serviceDate > date('Y-m-d')) {
                throw new Exception('Service date cannot be in the future.');
            }

            if (!in_array($maintenanceType, $allowedTypes, true)) {
                throw new Exception('Please select a valid maintenance type.');
            }

            if ($description === '') {
                throw new Exception('Please enter a maintenance description.');
            }

            if (!is_numeric($cost) || (float)$cost < 0) {
                throw new Exception('Please enter a valid maintenance cost.');
            }

            if (
                $odometer !== '' &&
                (!ctype_digit((string)$odometer) || (int)$odometer < 0)
            ) {
                throw new Exception('Odometer must be a valid non-negative number.');
            }

            if ($nextServiceDate !== '') {

                if (!validMaintenanceDate($nextServiceDate)) {
                    throw new Exception('Please enter a valid next service date.');
                }

                if ($nextServiceDate < $serviceDate) {
                    throw new Exception(
                        'Next service date cannot be before the service date.'
                    );
                }
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
                throw new Exception('Selected vehicle was not found.');
            }

            if ($vehicle['status'] !== 'available') {
                throw new Exception(
                    'Only available vehicles can enter maintenance.'
                );
            }

            $activeDeliveryStmt = $pdo->prepare("
                SELECT id
                FROM deliveries
                WHERE vehicle_id = ?
                  AND status IN ('assigned','picked_up','in_transit')
                LIMIT 1
            ");
            $activeDeliveryStmt->execute([$vehicleId]);

            if ($activeDeliveryStmt->fetch()) {
                throw new Exception(
                    'This vehicle has an active delivery. Complete or cancel it first.'
                );
            }

            $existingStmt = $pdo->prepare("
                SELECT id
                FROM maintenance_records
                WHERE vehicle_id = ?
                  AND status = 'in_progress'
                LIMIT 1
            ");
            $existingStmt->execute([$vehicleId]);

            if ($existingStmt->fetch()) {
                throw new Exception(
                    'This vehicle already has maintenance in progress.'
                );
            }

            $insert = $pdo->prepare("
                INSERT INTO maintenance_records (
                    vehicle_id,
                    service_date,
                    maintenance_type,
                    description,
                    cost,
                    odometer_km,
                    next_service_date,
                    status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'in_progress')
            ");

            $insert->execute([
                $vehicleId,
                $serviceDate,
                $maintenanceType,
                $description,
                (float)$cost,
                $odometer !== '' ? (int)$odometer : null,
                $nextServiceDate !== '' ? $nextServiceDate : null
            ]);

            $pdo->prepare("
                UPDATE vehicles
                SET status = 'maintenance'
                WHERE id = ?
            ")->execute([$vehicleId]);

            $pdo->commit();

            flash(
                'Maintenance started successfully. Vehicle ' .
                $vehicle['vehicle_code'] .
                ' is now unavailable for dispatch.',
                'success'
            );

        }

        /* =====================================
           UPDATE MAINTENANCE RECORD
        ===================================== */

        elseif ($action === 'update') {

            $maintenanceId = filter_var(
                $_POST['maintenance_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $serviceDate = trim($_POST['service_date'] ?? '');
            $maintenanceType = trim($_POST['maintenance_type'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $cost = $_POST['cost'] ?? '';
            $odometer = $_POST['odometer_km'] ?? '';
            $nextServiceDate = trim($_POST['next_service_date'] ?? '');

            $allowedTypes = [
                'Service', 'Repair', 'Tyre', 'Inspection', 'Other'
            ];

            if (!$maintenanceId || $maintenanceId <= 0) {
                throw new Exception('Invalid maintenance record.');
            }

            if (!validMaintenanceDate($serviceDate)) {
                throw new Exception('Please enter a valid service date.');
            }

            if ($serviceDate > date('Y-m-d')) {
                throw new Exception('Service date cannot be in the future.');
            }

            if (!in_array($maintenanceType, $allowedTypes, true)) {
                throw new Exception('Please select a valid maintenance type.');
            }

            if ($description === '') {
                throw new Exception('Description is required.');
            }

            if (!is_numeric($cost) || (float)$cost < 0) {
                throw new Exception('Please enter a valid maintenance cost.');
            }

            if (
                $odometer !== '' &&
                (!ctype_digit((string)$odometer) || (int)$odometer < 0)
            ) {
                throw new Exception('Odometer must be a valid non-negative number.');
            }

            if ($nextServiceDate !== '') {

                if (!validMaintenanceDate($nextServiceDate)) {
                    throw new Exception('Please enter a valid next service date.');
                }

                if ($nextServiceDate < $serviceDate) {
                    throw new Exception(
                        'Next service date cannot be before the service date.'
                    );
                }
            }

            $pdo->beginTransaction();

            $recordStmt = $pdo->prepare("
                SELECT id, vehicle_id, status
                FROM maintenance_records
                WHERE id = ?
                FOR UPDATE
            ");
            $recordStmt->execute([$maintenanceId]);

            $record = $recordStmt->fetch(PDO::FETCH_ASSOC);

            if (!$record) {
                throw new Exception('Maintenance record was not found.');
            }

            // Lock associated vehicle
            $vehicleStmt = $pdo->prepare("
                SELECT id, status
                FROM vehicles
                WHERE id = ?
                FOR UPDATE
            ");
            $vehicleStmt->execute([(int)$record['vehicle_id']]);

            if (!$vehicleStmt->fetch()) {
                throw new Exception('Associated vehicle was not found.');
            }

            $updateStmt = $pdo->prepare("
                UPDATE maintenance_records
                SET service_date = ?,
                    maintenance_type = ?,
                    description = ?,
                    cost = ?,
                    odometer_km = ?,
                    next_service_date = ?
                WHERE id = ?
            ");

            $updateStmt->execute([
                $serviceDate,
                $maintenanceType,
                $description,
                (float)$cost,
                $odometer !== '' ? (int)$odometer : null,
                $nextServiceDate !== '' ? $nextServiceDate : null,
                $maintenanceId
            ]);

            $pdo->commit();

            flash('Maintenance record updated successfully.', 'success');

        }

        /* =====================================
           COMPLETE MAINTENANCE
        ===================================== */

        elseif ($action === 'complete') {

            $maintenanceId = filter_var(
                $_POST['maintenance_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$maintenanceId || $maintenanceId <= 0) {
                throw new Exception('Invalid maintenance record.');
            }

            $pdo->beginTransaction();

            $recordStmt = $pdo->prepare("
                SELECT id, vehicle_id, status
                FROM maintenance_records
                WHERE id = ?
                FOR UPDATE
            ");
            $recordStmt->execute([$maintenanceId]);

            $record = $recordStmt->fetch(PDO::FETCH_ASSOC);

            if (!$record) {
                throw new Exception('Maintenance record was not found.');
            }

            if ($record['status'] !== 'in_progress') {
                throw new Exception('This maintenance record is already completed.');
            }

            $vehicleId = (int)$record['vehicle_id'];

            $vehicleStmt = $pdo->prepare("
                SELECT id, vehicle_code, status
                FROM vehicles
                WHERE id = ?
                FOR UPDATE
            ");
            $vehicleStmt->execute([$vehicleId]);

            $vehicle = $vehicleStmt->fetch(PDO::FETCH_ASSOC);

            if (!$vehicle) {
                throw new Exception('Associated vehicle was not found.');
            }

            $completeStmt = $pdo->prepare("
                UPDATE maintenance_records
                SET status = 'completed',
                    completed_at = NOW()
                WHERE id = ?
                  AND status = 'in_progress'
            ");
            $completeStmt->execute([$maintenanceId]);

            $otherMaintenanceStmt = $pdo->prepare("
                SELECT id
                FROM maintenance_records
                WHERE vehicle_id = ?
                  AND status = 'in_progress'
                  AND id <> ?
                LIMIT 1
            ");
            $otherMaintenanceStmt->execute([
                $vehicleId,
                $maintenanceId
            ]);

            $otherMaintenance = $otherMaintenanceStmt->fetch();

            $activeDeliveryStmt = $pdo->prepare("
                SELECT id
                FROM deliveries
                WHERE vehicle_id = ?
                  AND status IN ('assigned','picked_up','in_transit')
                LIMIT 1
            ");
            $activeDeliveryStmt->execute([$vehicleId]);

            $activeDelivery = $activeDeliveryStmt->fetch();

            if (!$otherMaintenance && !$activeDelivery) {

                $releaseVehicle = $pdo->prepare("
                    UPDATE vehicles
                    SET status = 'available'
                    WHERE id = ?
                      AND status = 'maintenance'
                ");
                $releaseVehicle->execute([$vehicleId]);

                $message = 'Maintenance completed. Vehicle ' .
                    $vehicle['vehicle_code'] .
                    ' is now available for dispatch.';

            } else {

                $message = 'Maintenance completed. The vehicle remains unavailable because another maintenance job or active delivery still exists.';

            }

            $pdo->commit();

            flash($message, 'success');

        }

        /* =====================================
           DELETE MAINTENANCE RECORD
        ===================================== */

        elseif ($action === 'delete') {

            $maintenanceId = filter_var(
                $_POST['maintenance_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$maintenanceId || $maintenanceId <= 0) {
                throw new Exception('Invalid maintenance record.');
            }

            $pdo->beginTransaction();

            $recordStmt = $pdo->prepare("
                SELECT id, vehicle_id, status
                FROM maintenance_records
                WHERE id = ?
                FOR UPDATE
            ");
            $recordStmt->execute([$maintenanceId]);

            $record = $recordStmt->fetch(PDO::FETCH_ASSOC);

            if (!$record) {
                throw new Exception('Maintenance record was not found.');
            }

            if ($record['status'] === 'in_progress') {
                throw new Exception(
                    'An active maintenance record cannot be deleted. Complete it first.'
                );
            }

            $vehicleStmt = $pdo->prepare("
                SELECT id, vehicle_code, status
                FROM vehicles
                WHERE id = ?
                FOR UPDATE
            ");
            $vehicleStmt->execute([(int)$record['vehicle_id']]);

            $vehicle = $vehicleStmt->fetch(PDO::FETCH_ASSOC);

            if (!$vehicle) {
                throw new Exception('Associated vehicle was not found.');
            }

            $deleteStmt = $pdo->prepare("
                DELETE FROM maintenance_records
                WHERE id = ?
                  AND status = 'completed'
            ");
            $deleteStmt->execute([$maintenanceId]);

            if ($deleteStmt->rowCount() !== 1) {
                throw new Exception('The maintenance record could not be deleted.');
            }

            // Do not change vehicle status when deleting historical records.

            $pdo->commit();

            flash('Maintenance record deleted successfully.', 'success');

        }

        else {
            throw new Exception('Invalid maintenance action.');
        }

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($e instanceof PDOException) {
            error_log($e->getMessage());
            flash(
                'A database error occurred while processing maintenance.',
                'danger'
            );
        } else {
            flash($e->getMessage(), 'danger');
        }
    }

    header('Location: maintenance.php');
    exit;
}

/* =========================================
   DASHBOARD STATISTICS
========================================= */

$statsQuery = $pdo->query("
    SELECT
        COUNT(*) AS total_records,
        COALESCE(SUM(status = 'in_progress'), 0) AS in_progress,
        COALESCE(SUM(status = 'completed'), 0) AS completed,
        COALESCE(SUM(cost), 0) AS total_cost,
        COALESCE(
            SUM(
                status = 'completed'
                AND next_service_date IS NOT NULL
                AND next_service_date < CURDATE()
            ), 0
        ) AS overdue
    FROM maintenance_records
");

$stats = $statsQuery->fetch(PDO::FETCH_ASSOC);

/* =========================================
   AVAILABLE VEHICLES
========================================= */

$vehiclesQuery = $pdo->query("
    SELECT id, vehicle_code, vehicle_type
    FROM vehicles
    WHERE status = 'available'
    ORDER BY vehicle_code ASC
");

$vehicles = $vehiclesQuery->fetchAll(PDO::FETCH_ASSOC);

/* =========================================
   MAINTENANCE HISTORY
========================================= */

$rowsQuery = $pdo->query("
    SELECT
        m.*,
        v.vehicle_code,
        v.vehicle_type
    FROM maintenance_records m
    INNER JOIN vehicles v ON v.id = m.vehicle_id
    ORDER BY
        CASE WHEN m.status = 'in_progress' THEN 0 ELSE 1 END,
        m.id DESC
");

$rows = $rowsQuery->fetchAll(PDO::FETCH_ASSOC);

/* =========================================
   SERVICE SCHEDULE
========================================= */

$serviceQuery = $pdo->query("
    SELECT
        m.id,
        m.vehicle_id,
        m.next_service_date,
        m.status,
        v.vehicle_code
    FROM maintenance_records m
    INNER JOIN vehicles v ON v.id = m.vehicle_id
    WHERE m.status = 'completed'
      AND m.next_service_date IS NOT NULL
    ORDER BY m.next_service_date ASC
");

$serviceDates = $serviceQuery->fetchAll(PDO::FETCH_ASSOC);

require 'includes/header.php';
?>

<div class="container-fluid">

    <!-- PAGE HEADER -->

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
        <div>
            <h3 class="fw-bold mb-1">Maintenance Management</h3>
            <p class="text-muted mb-0">
                Track vehicle servicing, repairs and maintenance costs.
            </p>
        </div>

        <button class="btn btn-primary"
                data-bs-toggle="modal"
                data-bs-target="#maintenanceModal"
                <?= empty($vehicles) ? 'disabled' : '' ?>>
            <i class="bi bi-plus-circle me-1"></i>
            Record Maintenance
        </button>
    </div>

    <?php show_flash(); ?>

    <!-- STATISTICS -->

    <div class="row g-3 mb-4">

        <div class="col-xl col-md-4 col-sm-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Total Records</div>
                    <h3 class="fw-bold mb-0"><?= (int)$stats['total_records'] ?></h3>
                    <i class="bi bi-clipboard-data fs-4 text-primary"></i>
                </div>
            </div>
        </div>

        <div class="col-xl col-md-4 col-sm-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">In Progress</div>
                    <h3 class="fw-bold text-warning mb-0"><?= (int)$stats['in_progress'] ?></h3>
                    <i class="bi bi-tools fs-4 text-warning"></i>
                </div>
            </div>
        </div>

        <div class="col-xl col-md-4 col-sm-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Completed</div>
                    <h3 class="fw-bold text-success mb-0"><?= (int)$stats['completed'] ?></h3>
                    <i class="bi bi-check-circle fs-4 text-success"></i>
                </div>
            </div>
        </div>

        <div class="col-xl col-md-4 col-sm-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Overdue Services</div>
                    <h3 class="fw-bold text-danger mb-0"><?= (int)$stats['overdue'] ?></h3>
                    <i class="bi bi-calendar-x fs-4 text-danger"></i>
                </div>
            </div>
        </div>

        <div class="col-xl col-md-4 col-sm-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Total Maintenance Cost</div>
                    <h3 class="fw-bold mb-0"><?= money($stats['total_cost']) ?></h3>
                    <i class="bi bi-cash-stack fs-4 text-primary"></i>
                </div>
            </div>
        </div>

    </div>

    <!-- AVAILABILITY NOTICE -->

    <?php if (empty($vehicles)): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-1"></i>
            No available vehicles are currently eligible for new maintenance.
        </div>
    <?php endif; ?>

    <!-- MAINTENANCE HISTORY -->

    <div class="card border-0 shadow-sm mb-4">

        <div class="card-header bg-white py-3">
            <h5 class="fw-bold mb-0">Maintenance History</h5>
        </div>

        <div class="card-body">

            <div class="table-responsive">

                <table class="table table-hover align-middle">

                    <thead class="table-light">
                        <tr>
                            <th>Vehicle</th>
                            <th>Service Date</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th>Cost</th>
                            <th>Next Service</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (empty($rows)): ?>

                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                No maintenance records available.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($rows as $r): ?>

                            <?php
                            $isOverdue =
                                $r['status'] === 'completed' &&
                                !empty($r['next_service_date']) &&
                                $r['next_service_date'] < date('Y-m-d');

                            $statusClass = $r['status'] === 'in_progress'
                                ? 'warning'
                                : 'success';

                            $recordJson = htmlspecialchars(
                                json_encode(
                                    $r,
                                    JSON_HEX_APOS | JSON_HEX_QUOT |
                                    JSON_HEX_TAG | JSON_HEX_AMP
                                ),
                                ENT_QUOTES,
                                'UTF-8'
                            );
                            ?>

                            <tr>

                                <td class="fw-semibold">
                                    <?= e($r['vehicle_code']) ?>
                                    <div class="small text-muted">
                                        <?= e(ucfirst($r['vehicle_type'])) ?>
                                    </div>
                                </td>

                                <td><?= e($r['service_date']) ?></td>

                                <td><?= e($r['maintenance_type']) ?></td>

                                <td><?= e($r['description']) ?></td>

                                <td><?= money($r['cost']) ?></td>

                                <td>
                                    <?php if (!empty($r['next_service_date'])): ?>

                                        <?= e($r['next_service_date']) ?>

                                        <?php if ($isOverdue): ?>
                                            <div>
                                                <span class="badge bg-danger">Overdue</span>
                                            </div>
                                        <?php endif; ?>

                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="badge bg-<?= $statusClass ?>">
                                        <?= e(ucfirst(str_replace('_', ' ', $r['status']))) ?>
                                    </span>

                                    <?php if (!empty($r['completed_at'])): ?>
                                        <div class="small text-muted">
                                            <?= e($r['completed_at']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="d-flex gap-2">

                                        <!-- EDIT BUTTON -->

                                        <button type="button"
                                                class="btn btn-sm btn-outline-primary"
                                                title="Edit maintenance"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editMaintenanceModal"
                                                data-record="<?= $recordJson ?>">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>

                                        <!-- COMPLETE BUTTON -->

                                        <?php if ($r['status'] === 'in_progress'): ?>

                                            <form method="POST"
                                                  action="maintenance.php"
                                                  onsubmit="return confirm('Confirm that this maintenance job is complete?');">

                                                <input type="hidden" name="csrf"
                                                       value="<?= e(csrf_token()) ?>">

                                                <input type="hidden"
                                                       name="form_action"
                                                       value="complete">

                                                <input type="hidden"
                                                       name="maintenance_id"
                                                       value="<?= (int)$r['id'] ?>">

                                                <button type="submit"
                                                        class="btn btn-sm btn-success"
                                                        title="Complete maintenance">
                                                    <i class="bi bi-check-circle"></i>
                                                </button>

                                            </form>

                                        <?php endif; ?>

                                        <!-- DELETE BUTTON -->

                                        <?php if ($r['status'] === 'completed'): ?>

                                            <button type="button"
                                                    class="btn btn-sm btn-outline-danger"
                                                    title="Delete maintenance"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#deleteMaintenanceModal"
                                                    data-id="<?= (int)$r['id'] ?>"
                                                    data-vehicle="<?= e($r['vehicle_code']) ?>"
                                                    data-date="<?= e($r['service_date']) ?>">
                                                <i class="bi bi-trash"></i>
                                            </button>

                                        <?php else: ?>

                                            <button type="button"
                                                    class="btn btn-sm btn-outline-secondary"
                                                    disabled
                                                    title="Complete maintenance before deleting">
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

    <!-- SERVICE SCHEDULE -->

    <div class="card border-0 shadow-sm">

        <div class="card-header bg-white py-3">
            <h5 class="fw-bold mb-0">Service Schedule</h5>
        </div>

        <div class="card-body">

            <div class="table-responsive">

                <table class="table table-hover align-middle">

                    <thead class="table-light">
                        <tr>
                            <th>Vehicle</th>
                            <th>Next Service Date</th>
                            <th>Service Status</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php
                    $scheduledServices = array_filter(
                        $serviceDates,
                        function ($service) {
                            return $service['next_service_date'] >= date('Y-m-d');
                        }
                    );
                    ?>

                    <?php if (empty($scheduledServices)): ?>

                        <tr>
                            <td colspan="3" class="text-center text-muted py-3">
                                No upcoming services scheduled.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($scheduledServices as $service): ?>

                            <?php
                            $daysRemaining = (int)date_diff(
                                new DateTime(date('Y-m-d')),
                                new DateTime($service['next_service_date'])
                            )->format('%r%a');
                            ?>

                            <tr>
                                <td class="fw-semibold">
                                    <?= e($service['vehicle_code']) ?>
                                </td>

                                <td><?= e($service['next_service_date']) ?></td>

                                <td>
                                    <?php if ($daysRemaining === 0): ?>
                                        <span class="badge bg-warning text-dark">
                                            Due Today
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-info">
                                            <?= $daysRemaining ?> days remaining
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
    </div>

</div>

<!-- =========================================
     CREATE MAINTENANCE MODAL
========================================= -->

<div class="modal fade" id="maintenanceModal" tabindex="-1"
     aria-labelledby="maintenanceModalLabel" aria-hidden="true">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content">

            <form method="POST" action="maintenance.php">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="maintenanceModalLabel">
                        Record New Maintenance
                    </h5>

                    <button type="button" class="btn-close"
                            data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" name="csrf"
                           value="<?= e(csrf_token()) ?>">

                    <input type="hidden" name="form_action" value="create">

                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label">Vehicle *</label>

                            <select name="vehicle_id" class="form-select" required>
                                <option value="">Select available vehicle</option>

                                <?php foreach ($vehicles as $v): ?>
                                    <option value="<?= (int)$v['id'] ?>">
                                        <?= e($v['vehicle_code']) ?>
                                        (<?= e(ucfirst($v['vehicle_type'])) ?>)
                                    </option>
                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Service Date *</label>

                            <input type="date"
                                   name="service_date"
                                   class="form-control"
                                   value="<?= date('Y-m-d') ?>"
                                   max="<?= date('Y-m-d') ?>"
                                   required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Maintenance Type *</label>

                            <select name="maintenance_type" class="form-select" required>
                                <option value="Service">Service</option>
                                <option value="Repair">Repair</option>
                                <option value="Tyre">Tyre</option>
                                <option value="Inspection">Inspection</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Maintenance Cost (K) *</label>

                            <input type="number"
                                   name="cost"
                                   class="form-control"
                                   min="0"
                                   step="0.01"
                                   required>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Description *</label>

                            <textarea name="description"
                                      class="form-control"
                                      rows="3"
                                      required></textarea>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Odometer (KM)</label>

                            <input type="number"
                                   name="odometer_km"
                                   class="form-control"
                                   min="0"
                                   step="1">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Next Service Date</label>

                            <input type="date"
                                   name="next_service_date"
                                   class="form-control"
                                   min="<?= date('Y-m-d') ?>">
                        </div>

                    </div>

                    <div class="alert alert-warning mt-3 mb-0">
                        Recording maintenance makes this vehicle unavailable
                        for dispatch until the maintenance job is completed.
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light"
                            data-bs-dismiss="modal">Cancel</button>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>
                        Start Maintenance
                    </button>
                </div>

            </form>

        </div>
    </div>
</div>

<!-- =========================================
     EDIT MAINTENANCE MODAL
========================================= -->

<div class="modal fade" id="editMaintenanceModal" tabindex="-1"
     aria-labelledby="editMaintenanceModalLabel" aria-hidden="true">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content">

            <form method="POST" action="maintenance.php">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="editMaintenanceModalLabel">
                        Edit Maintenance Record
                    </h5>

                    <button type="button" class="btn-close"
                            data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" name="csrf"
                           value="<?= e(csrf_token()) ?>">

                    <input type="hidden" name="form_action" value="update">

                    <input type="hidden"
                           name="maintenance_id"
                           id="edit_maintenance_id">

                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label">Vehicle</label>

                            <input type="text"
                                   id="edit_maintenance_vehicle"
                                   class="form-control"
                                   readonly>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Service Date *</label>

                            <input type="date"
                                   name="service_date"
                                   id="edit_service_date"
                                   class="form-control"
                                   max="<?= date('Y-m-d') ?>"
                                   required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Maintenance Type *</label>

                            <select name="maintenance_type"
                                    id="edit_maintenance_type"
                                    class="form-select"
                                    required>
                                <option value="Service">Service</option>
                                <option value="Repair">Repair</option>
                                <option value="Tyre">Tyre</option>
                                <option value="Inspection">Inspection</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Maintenance Cost (K) *</label>

                            <input type="number"
                                   name="cost"
                                   id="edit_maintenance_cost"
                                   class="form-control"
                                   min="0"
                                   step="0.01"
                                   required>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Description *</label>

                            <textarea name="description"
                                      id="edit_maintenance_description"
                                      class="form-control"
                                      rows="3"
                                      required></textarea>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Odometer (KM)</label>

                            <input type="number"
                                   name="odometer_km"
                                   id="edit_maintenance_odometer"
                                   class="form-control"
                                   min="0"
                                   step="1">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Next Service Date</label>

                            <input type="date"
                                   name="next_service_date"
                                   id="edit_next_service_date"
                                   class="form-control">
                        </div>

                    </div>

                    <div class="alert alert-info mt-3 mb-0">
                        Editing this record will not change the vehicle's
                        operational status or maintenance completion status.
                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button" class="btn btn-light"
                            data-bs-dismiss="modal">Cancel</button>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>
                        Save Changes
                    </button>

                </div>

            </form>

        </div>
    </div>
</div>

<!-- =========================================
     DELETE MAINTENANCE MODAL
========================================= -->

<div class="modal fade" id="deleteMaintenanceModal" tabindex="-1"
     aria-labelledby="deleteMaintenanceModalLabel" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <form method="POST" action="maintenance.php">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-danger"
                        id="deleteMaintenanceModalLabel">
                        Delete Maintenance Record
                    </h5>

                    <button type="button" class="btn-close"
                            data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" name="csrf"
                           value="<?= e(csrf_token()) ?>">

                    <input type="hidden" name="form_action" value="delete">

                    <input type="hidden"
                           name="maintenance_id"
                           id="delete_maintenance_id">

                    <div class="text-center">

                        <i class="bi bi-exclamation-triangle text-danger"
                           style="font-size:3rem;"></i>

                        <p class="mt-3 mb-1">
                            Are you sure you want to delete this maintenance record?
                        </p>

                        <h5 class="fw-bold" id="delete_maintenance_vehicle"></h5>

                        <p id="delete_maintenance_date" class="text-muted small"></p>

                        <p class="text-danger small mb-0">
                            This action cannot be undone.
                        </p>

                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button" class="btn btn-light"
                            data-bs-dismiss="modal">Cancel</button>

                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i>
                        Delete Record
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

    // EDIT MAINTENANCE
    const editModal = document.getElementById('editMaintenanceModal');

    if (editModal) {

        editModal.addEventListener('show.bs.modal', function (event) {

            const button = event.relatedTarget;
            const record = JSON.parse(button.getAttribute('data-record'));

            document.getElementById('edit_maintenance_id').value = record.id;
            document.getElementById('edit_maintenance_vehicle').value = record.vehicle_code;
            document.getElementById('edit_service_date').value = record.service_date;
            document.getElementById('edit_maintenance_type').value = record.maintenance_type;
            document.getElementById('edit_maintenance_cost').value = record.cost;
            document.getElementById('edit_maintenance_description').value = record.description;
            document.getElementById('edit_maintenance_odometer').value = record.odometer_km || '';
            document.getElementById('edit_next_service_date').value = record.next_service_date || '';

        });
    }

    // DELETE MAINTENANCE
    const deleteModal = document.getElementById('deleteMaintenanceModal');

    if (deleteModal) {

        deleteModal.addEventListener('show.bs.modal', function (event) {

            const button = event.relatedTarget;

            document.getElementById('delete_maintenance_id').value =
                button.getAttribute('data-id');

            document.getElementById('delete_maintenance_vehicle').textContent =
                button.getAttribute('data-vehicle');

            document.getElementById('delete_maintenance_date').textContent =
                'Service date: ' + button.getAttribute('data-date');

        });
    }

});
</script>

<?php require 'includes/footer.php'; ?>
