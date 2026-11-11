
<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

require_login();
require_permission('drivers');

$page_title = 'Driver Management';
$currentPage = 'drivers.php';

/*
|--------------------------------------------------------------------------
| DRIVER VALIDATION AND ACTIONS
|--------------------------------------------------------------------------
*/

$allowedEmploymentTypes = ['daily', 'commission', 'contract'];
$allowedStatuses = ['active', 'inactive', 'suspended'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['form_action'] ?? '';

    try {
        if ($action === 'create') {
            $fullName = trim($_POST['full_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $licenseNumber = trim($_POST['license_number'] ?? '');
            $employmentType = $_POST['employment_type'] ?? '';

            if ($fullName === '' || strlen($fullName) > 150) {
                throw new Exception('Enter a valid driver name.');
            }

            if ($phone === '' || strlen($phone) > 30) {
                throw new Exception('Enter a valid phone number.');
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Enter a valid email address.');
            }

            if (strlen($email) > 150 || strlen($licenseNumber) > 100) {
                throw new Exception('One or more fields exceed the allowed length.');
            }

            if (!in_array($employmentType, $allowedEmploymentTypes, true)) {
                throw new Exception('Select a valid employment type.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO drivers (
                    full_name,
                    phone,
                    email,
                    license_number,
                    employment_type,
                    status
                ) VALUES (?, ?, ?, ?, ?, 'active')
            ");

            $stmt->execute([
                $fullName,
                $phone,
                $email !== '' ? $email : null,
                $licenseNumber !== '' ? $licenseNumber : null,
                $employmentType
            ]);

            flash('Driver added successfully.');
        }

        elseif ($action === 'update_status') {
            $driverId = (int)($_POST['driver_id'] ?? 0);
            $newStatus = $_POST['new_status'] ?? '';

            if ($driverId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
                throw new Exception('Invalid driver status update.');
            }

            $stmt = $pdo->prepare("
                UPDATE drivers
                SET status = ?
                WHERE id = ?
            ");

            $stmt->execute([$newStatus, $driverId]);

            if ($stmt->rowCount() > 0) {
                flash('Driver status updated successfully.');
            } else {
                flash('No changes were made to the driver status.');
            }
        }

        else {
            throw new Exception('Invalid request.');
        }

    } catch (Exception $e) {
        flash('Error: ' . $e->getMessage());
    }

    header('Location: drivers.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| SEARCH AND FILTERS
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');
$employmentFilter = $_GET['employment_type'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(
        full_name LIKE ?
        OR phone LIKE ?
        OR email LIKE ?
        OR license_number LIKE ?
    )";

    $term = '%' . $search . '%';

    $params = array_merge($params, [
        $term,
        $term,
        $term,
        $term
    ]);
}

if (in_array($employmentFilter, $allowedEmploymentTypes, true)) {
    $where[] = "employment_type = ?";
    $params[] = $employmentFilter;
} else {
    $employmentFilter = '';
}

if (in_array($statusFilter, $allowedStatuses, true)) {
    $where[] = "status = ?";
    $params[] = $statusFilter;
} else {
    $statusFilter = '';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/*
|--------------------------------------------------------------------------
| DRIVER STATISTICS
|--------------------------------------------------------------------------
*/

$stats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'active') AS active,
        SUM(status = 'inactive') AS inactive,
        SUM(status = 'suspended') AS suspended
    FROM drivers
")->fetch(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| DRIVER DIRECTORY
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        d.*,
        (
            SELECT COUNT(*)
            FROM deliveries dl
            WHERE dl.driver_id = d.id
              AND dl.status IN ('assigned', 'picked_up', 'in_transit')
        ) AS active_deliveries
    FROM drivers d
    $whereSql
    ORDER BY d.id DESC
");

$stmt->execute($params);
$drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/includes/header.php';
?>

<style>
.driver-hero {
    background: linear-gradient(120deg, #123b70, #1769aa);
    color: white;
    border-radius: 16px;
    padding: 28px;
}

.driver-stat {
    border: 0;
    border-radius: 14px;
    box-shadow: 0 3px 14px rgba(0,0,0,.05);
    height: 100%;
}

.driver-stat .stat-icon {
    width: 45px;
    height: 45px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    font-size: 21px;
}

.driver-stat .stat-number {
    font-size: 27px;
    font-weight: 700;
    margin-bottom: 2px;
}

.driver-table th {
    white-space: nowrap;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .4px;
    color: #64748b;
    background: #f8fafc;
    padding: 15px 12px;
}

.driver-table td {
    padding: 14px 12px;
    vertical-align: middle;
}

.driver-avatar {
    width: 42px;
    height: 42px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #e7f0ff;
    color: #1759a6;
    font-weight: 700;
    margin-right: 10px;
}

.status-badge {
    padding: 6px 10px;
    border-radius: 30px;
    font-size: 11px;
    font-weight: 700;
    text-transform: capitalize;
}

.status-active {
    background: #dcfce7;
    color: #166534;
}

.status-inactive {
    background: #e2e8f0;
    color: #475569;
}

.status-suspended {
    background: #fee2e2;
    color: #991b1b;
}

.employment-badge {
    background: #eff6ff;
    color: #1d4ed8;
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
}

.driver-panel {
    border: 0;
    border-radius: 14px;
    box-shadow: 0 3px 14px rgba(0,0,0,.05);
}

.form-section-title {
    font-size: 13px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: .5px;
    margin-bottom: 14px;
}

@media(max-width:768px) {
    .driver-hero {
        padding: 20px;
    }

    .driver-hero .btn {
        margin-top: 15px;
    }
}
</style>

<!-- PAGE HEADER -->
<div class="driver-hero mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center">
        <div>
            <div class="small text-white-50 mb-2">
                FLEET MANAGEMENT / DRIVER DIRECTORY
            </div>

            <h2 class="fw-bold mb-2">
                <i class="bi bi-person-badge me-2"></i>
                Driver Management
            </h2>

            <p class="mb-0 text-white-50">
                Manage driver records, employment arrangements and availability.
            </p>
        </div>

        <button
            class="btn btn-light fw-semibold"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#addDriverForm"
        >
            <i class="bi bi-plus-circle me-1"></i>
            Add New Driver
        </button>
    </div>
</div>

<!-- STATISTICS -->
<div class="row g-3 mb-4">

    <div class="col-6 col-lg-3">
        <div class="card driver-stat">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Total Drivers</div>
                        <div class="stat-number">
                            <?= number_format((int)($stats['total'] ?? 0)) ?>
                        </div>
                    </div>
                    <div class="stat-icon bg-primary-subtle text-primary">
                        <i class="bi bi-people"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="card driver-stat">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Active Drivers</div>
                        <div class="stat-number text-success">
                            <?= number_format((int)($stats['active'] ?? 0)) ?>
                        </div>
                    </div>
                    <div class="stat-icon bg-success-subtle text-success">
                        <i class="bi bi-person-check"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="card driver-stat">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Inactive Drivers</div>
                        <div class="stat-number text-secondary">
                            <?= number_format((int)($stats['inactive'] ?? 0)) ?>
                        </div>
                    </div>
                    <div class="stat-icon bg-secondary-subtle text-secondary">
                        <i class="bi bi-person-dash"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="card driver-stat">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Suspended Drivers</div>
                        <div class="stat-number text-danger">
                            <?= number_format((int)($stats['suspended'] ?? 0)) ?>
                        </div>
                    </div>
                    <div class="stat-icon bg-danger-subtle text-danger">
                        <i class="bi bi-person-x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- ADD DRIVER FORM -->
<div class="collapse mb-4" id="addDriverForm">
    <div class="card driver-panel">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 fw-bold">
                <i class="bi bi-person-plus text-primary me-2"></i>
                Register New Driver
            </h5>
            <small class="text-muted">
                Enter the driver's personal and employment information.
            </small>
        </div>

        <div class="card-body p-4">

            <form method="POST">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="form_action" value="create">

                <div class="form-section-title">
                    <i class="bi bi-person me-1"></i>
                    Personal Information
                </div>

                <div class="row g-3 mb-4">

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Full Name <span class="text-danger">*</span>
                        </label>
                        <input
                            type="text"
                            name="full_name"
                            class="form-control"
                            maxlength="150"
                            placeholder="Enter driver's full name"
                            required
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Phone Number <span class="text-danger">*</span>
                        </label>
                        <input
                            type="tel"
                            name="phone"
                            class="form-control"
                            maxlength="30"
                            placeholder="e.g. 097XXXXXXXX"
                            required
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Email Address
                        </label>
                        <input
                            type="email"
                            name="email"
                            class="form-control"
                            maxlength="150"
                            placeholder="driver@example.com"
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Licence Number
                        </label>
                        <input
                            type="text"
                            name="license_number"
                            class="form-control"
                            maxlength="100"
                            placeholder="Enter driving licence number"
                        >
                    </div>

                </div>

                <div class="form-section-title">
                    <i class="bi bi-briefcase me-1"></i>
                    Employment Information
                </div>

                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Employment Type <span class="text-danger">*</span>
                        </label>

                        <select name="employment_type" class="form-select" required>
                            <option value="daily">Daily</option>
                            <option value="commission">Commission</option>
                            <option value="contract">Contract</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Initial Status
                        </label>
                        <input
                            type="text"
                            class="form-control"
                            value="Active"
                            disabled
                        >
                        <div class="form-text">
                            Newly registered drivers are active by default.
                        </div>
                    </div>

                </div>

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <button
                        type="button"
                        class="btn btn-light"
                        data-bs-toggle="collapse"
                        data-bs-target="#addDriverForm"
                    >
                        Cancel
                    </button>

                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-check-circle me-1"></i>
                        Save Driver
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>

<!-- DRIVER DIRECTORY -->
<div class="card driver-panel">

    <div class="card-header bg-white py-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h5 class="fw-bold mb-1">
                    <i class="bi bi-card-list text-primary me-2"></i>
                    Driver Directory
                </h5>
                <small class="text-muted">
                    Search and manage registered drivers.
                </small>
            </div>

            <span class="badge bg-primary-subtle text-primary px-3 py-2">
                <?= count($drivers) ?> drivers found
            </span>
        </div>
    </div>

    <div class="card-body">

        <!-- SEARCH AND FILTERS -->
        <form method="GET" class="row g-2 mb-4">

            <div class="col-lg-5">
                <div class="input-group">
                    <span class="input-group-text bg-white">
                        <i class="bi bi-search"></i>
                    </span>
                    <input
                        type="text"
                        name="search"
                        class="form-control"
                        placeholder="Search name, phone, email or licence..."
                        value="<?= e($search) ?>"
                    >
                </div>
            </div>

            <div class="col-lg-3">
                <select name="employment_type" class="form-select">
                    <option value="">All Employment Types</option>
                    <option value="daily" <?= $employmentFilter === 'daily' ? 'selected' : '' ?>>
                        Daily
                    </option>
                    <option value="commission" <?= $employmentFilter === 'commission' ? 'selected' : '' ?>>
                        Commission
                    </option>
                    <option value="contract" <?= $employmentFilter === 'contract' ? 'selected' : '' ?>>
                        Contract
                    </option>
                </select>
            </div>

            <div class="col-lg-2">
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>
                        Active
                    </option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>
                        Inactive
                    </option>
                    <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>
                        Suspended
                    </option>
                </select>
            </div>

            <div class="col-lg-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">
                    <i class="bi bi-funnel"></i> Filter
                </button>

                <a href="drivers.php" class="btn btn-light" title="Clear filters">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </a>
            </div>

        </form>

        <!-- TABLE -->
        <div class="table-responsive">
            <table class="table driver-table table-hover align-middle mb-0">

                <thead>
                    <tr>
                        <th>Driver</th>
                        <th>Contact</th>
                        <th>Licence Number</th>
                        <th>Employment</th>
                        <th>Active Deliveries</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!$drivers): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5">
                            <i class="bi bi-person-exclamation fs-1 text-muted"></i>
                            <h6 class="mt-3 fw-bold">No drivers found</h6>
                            <p class="text-muted mb-0">
                                Try changing your search or filter criteria.
                            </p>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($drivers as $driver): ?>

                    <?php
                        $initials = '';

                        $nameParts = preg_split('/\s+/', trim($driver['full_name']));

                        foreach (array_slice($nameParts, 0, 2) as $part) {
                            $initials .= strtoupper(substr($part, 0, 1));
                        }

                        $statusClass = 'status-' . $driver['status'];

                        $employmentLabels = [
                            'daily' => 'Daily',
                            'commission' => 'Commission',
                            'contract' => 'Contract'
                        ];

                        $nextStatus = [
                            'active' => 'inactive',
                            'inactive' => 'active',
                            'suspended' => 'active'
                        ];

                        $nextLabel = [
                            'active' => 'Deactivate',
                            'inactive' => 'Activate',
                            'suspended' => 'Reactivate'
                        ];

                        $nextIcon = [
                            'active' => 'bi-person-dash',
                            'inactive' => 'bi-person-check',
                            'suspended' => 'bi-person-check'
                        ];
                    ?>

                    <tr>

                        <td>
                            <div class="d-flex align-items-center">
                                <div class="driver-avatar">
                                    <?= e($initials) ?>
                                </div>

                                <div>
                                    <div class="fw-semibold">
                                        <?= e($driver['full_name']) ?>
                                    </div>
                                    <small class="text-muted">
                                        Driver #<?= (int)$driver['id'] ?>
                                    </small>
                                </div>
                            </div>
                        </td>

                        <td>
                            <div><?= e($driver['phone']) ?></div>

                            <?php if (!empty($driver['email'])): ?>
                                <small class="text-muted">
                                    <?= e($driver['email']) ?>
                                </small>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?= !empty($driver['license_number'])
                                ? e($driver['license_number'])
                                : '<span class="text-muted">Not provided</span>' ?>
                        </td>

                        <td>
                            <span class="employment-badge">
                                <?= e($employmentLabels[$driver['employment_type']] ?? ucfirst($driver['employment_type'])) ?>
                            </span>
                        </td>

                        <td>
                            <?php if ((int)$driver['active_deliveries'] > 0): ?>
                                <span class="badge bg-primary-subtle text-primary">
                                    <?= (int)$driver['active_deliveries'] ?> active
                                </span>
                            <?php else: ?>
                                <span class="text-muted small">No active deliveries</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="status-badge <?= e($statusClass) ?>">
                                <?= e(ucfirst($driver['status'])) ?>
                            </span>
                        </td>

                        <td class="text-end">
                            <div class="d-flex justify-content-end gap-1">

                                <?php if ($driver['status'] !== 'active'): ?>
                                    <form method="POST"
                                          onsubmit="return confirm('Are you sure you want to <?= strtolower($nextLabel[$driver['status']]) ?> this driver?');">
                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="form_action" value="update_status">
                                        <input type="hidden" name="driver_id" value="<?= (int)$driver['id'] ?>">
                                        <input type="hidden" name="new_status" value="<?= e($nextStatus[$driver['status']]) ?>">

                                        <button type="submit" class="btn btn-sm btn-outline-success" title="<?= e($nextLabel[$driver['status']]) ?>">
                                            <i class="bi <?= e($nextIcon[$driver['status']]) ?>"></i>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST"
                                          onsubmit="return confirm('Are you sure you want to deactivate this driver?');">
                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="form_action" value="update_status">
                                        <input type="hidden" name="driver_id" value="<?= (int)$driver['id'] ?>">
                                        <input type="hidden" name="new_status" value="inactive">

                                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Deactivate driver">
                                            <i class="bi bi-person-dash"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($driver['status'] !== 'suspended'): ?>
                                    <form method="POST"
                                          onsubmit="return confirm('Are you sure you want to suspend this driver?');">
                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="form_action" value="update_status">
                                        <input type="hidden" name="driver_id" value="<?= (int)$driver['id'] ?>">
                                        <input type="hidden" name="new_status" value="suspended">

                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Suspend driver">
                                            <i class="bi bi-person-x"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>

                            </div>
                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>
            </table>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
