
<?php

require_once 'config/database.php';
require_once 'includes/functions.php';

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
| This assumes your login system stores the user ID in $_SESSION['user_id'].
| If your original login uses a different session key, retain its original
| authentication check here.
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Dashboard database functions
|--------------------------------------------------------------------------
*/

function dashboardCount(PDO $pdo, string $table): ?int
{
    $allowedTables = [
        'deliveries',
        'drivers',
        'vehicles'
    ];

    if (!in_array($table, $allowedTables, true)) {
        return null;
    }

    try {
        $query = $pdo->query("SELECT COUNT(*) FROM `$table`");

        return (int) $query->fetchColumn();

    } catch (PDOException $e) {
        error_log('Dashboard count error: ' . $e->getMessage());

        return null;
    }
}

function displayCount(?int $value): string
{
    return $value === null ? '—' : number_format($value);
}

/*
|--------------------------------------------------------------------------
| Retrieve dashboard information
|--------------------------------------------------------------------------
*/

$totalDeliveries = dashboardCount($pdo, 'deliveries');
$totalDrivers = dashboardCount($pdo, 'drivers');
$totalVehicles = dashboardCount($pdo, 'vehicles');

$totalRecords = null;

if (
    $totalDeliveries !== null &&
    $totalDrivers !== null &&
    $totalVehicles !== null
) {
    $totalRecords = $totalDeliveries + $totalDrivers + $totalVehicles;
}

$pageTitle = 'Dashboard';

require_once 'includes/header.php';

?>

<div class="dashboard-container">

    <!-- Welcome section -->

    <div class="welcome-section">
        <div>
            <h2>Welcome to MLFMS</h2>

            <p>
                Here's an overview of your logistics operations.
            </p>
        </div>

        <div class="dashboard-date">
            <i class="bi bi-calendar3"></i>
            <?= date('d F Y') ?>
        </div>
    </div>


    <!-- Dashboard statistics -->

    <div class="row g-3 dashboard-stats">

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="stat-card">

                <div class="stat-top">
                    <div class="stat-icon icon-blue">
                        <i class="bi bi-box-seam"></i>
                    </div>

                    <span class="stat-caption">Operations</span>
                </div>

                <div class="stat-value">
                    <?= displayCount($totalDeliveries) ?>
                </div>

                <div class="stat-label">
                    Total Deliveries
                </div>

            </div>

        </div>


        <div class="col-12 col-sm-6 col-xl-3">

            <div class="stat-card">

                <div class="stat-top">
                    <div class="stat-icon icon-green">
                        <i class="bi bi-people"></i>
                    </div>

                    <span class="stat-caption">Team</span>
                </div>

                <div class="stat-value">
                    <?= displayCount($totalDrivers) ?>
                </div>

                <div class="stat-label">
                    Registered Drivers
                </div>

            </div>

        </div>


        <div class="col-12 col-sm-6 col-xl-3">

            <div class="stat-card">

                <div class="stat-top">
                    <div class="stat-icon icon-orange">
                        <i class="bi bi-truck"></i>
                    </div>

                    <span class="stat-caption">Fleet</span>
                </div>

                <div class="stat-value">
                    <?= displayCount($totalVehicles) ?>
                </div>

                <div class="stat-label">
                    Registered Vehicles
                </div>

            </div>

        </div>


        <div class="col-12 col-sm-6 col-xl-3">

            <div class="stat-card">

                <div class="stat-top">
                    <div class="stat-icon icon-purple">
                        <i class="bi bi-database"></i>
                    </div>

                    <span class="stat-caption">System</span>
                </div>

                <div class="stat-value">
                    <?= displayCount($totalRecords) ?>
                </div>

                <div class="stat-label">
                    Total Operational Records
                </div>

            </div>

        </div>

    </div>


    <!-- Main dashboard section -->

    <div class="row g-3 dashboard-main">

        <!-- Left column -->

        <div class="col-12 col-xl-8">

            <!-- Records chart -->

            <div class="section-card">

                <div class="section-header">

                    <div>
                        <h5>Operational Overview</h5>

                        <p>
                            Current records across your system
                        </p>
                    </div>

                    <span class="overview-badge">
                        Live database
                    </span>

                </div>

                <div class="chart-container">

                    <canvas id="deliveryChart"></canvas>

                </div>

            </div>


            <!-- Operations overview -->

            <div class="section-card">

                <div class="section-header">

                    <div>
                        <h5>Operations Overview</h5>

                        <p>
                            Manage your logistics activities
                        </p>
                    </div>

                </div>


                <div class="operation-item">

                    <div class="operation-icon icon-blue">
                        <i class="bi bi-box-seam"></i>
                    </div>

                    <div class="operation-info">
                        <strong>Delivery Management</strong>

                        <span>
                            Create and manage delivery orders.
                        </span>
                    </div>

                    <a href="deliveries.php" class="operation-link">
                        Open <i class="bi bi-arrow-right"></i>
                    </a>

                </div>


                <div class="operation-item">

                    <div class="operation-icon icon-green">
                        <i class="bi bi-person-badge"></i>
                    </div>

                    <div class="operation-info">
                        <strong>Driver Management</strong>

                        <span>
                            Manage your delivery personnel.
                        </span>
                    </div>

                    <a href="drivers.php" class="operation-link">
                        Open <i class="bi bi-arrow-right"></i>
                    </a>

                </div>


                <div class="operation-item">

                    <div class="operation-icon icon-orange">
                        <i class="bi bi-truck"></i>
                    </div>

                    <div class="operation-info">
                        <strong>Fleet Management</strong>

                        <span>
                            Manage bicycles and motorised vehicles.
                        </span>
                    </div>

                    <a href="fleet.php" class="operation-link">
                        Open <i class="bi bi-arrow-right"></i>
                    </a>

                </div>


                <div class="operation-item">

                    <div class="operation-icon icon-purple">
                        <i class="bi bi-tools"></i>
                    </div>

                    <div class="operation-info">
                        <strong>Maintenance Management</strong>

                        <span>
                            Record and monitor vehicle maintenance.
                        </span>
                    </div>

                    <a href="maintenance.php" class="operation-link">
                        Open <i class="bi bi-arrow-right"></i>
                    </a>

                </div>

            </div>

        </div>


        <!-- Right column -->

        <div class="col-12 col-xl-4">

            <!-- Quick actions -->

            <div class="section-card">

                <div class="section-header">

                    <div>
                        <h5>Quick Actions</h5>

                        <p>
                            Frequently used shortcuts
                        </p>
                    </div>

                </div>


                <a href="deliveries.php" class="quick-action">

                    <div class="quick-icon icon-blue">
                        <i class="bi bi-plus-circle"></i>
                    </div>

                    <div>
                        <strong>New Delivery</strong>

                        <span>Create a delivery order</span>
                    </div>

                    <i class="bi bi-chevron-right ms-auto"></i>

                </a>


                <a href="drivers.php" class="quick-action">

                    <div class="quick-icon icon-green">
                        <i class="bi bi-person-plus"></i>
                    </div>

                    <div>
                        <strong>Register Driver</strong>

                        <span>Add a new driver</span>
                    </div>

                    <i class="bi bi-chevron-right ms-auto"></i>

                </a>


                <a href="fleet.php" class="quick-action">

                    <div class="quick-icon icon-orange">
                        <i class="bi bi-truck"></i>
                    </div>

                    <div>
                        <strong>Add Vehicle</strong>

                        <span>Register a fleet vehicle</span>
                    </div>

                    <i class="bi bi-chevron-right ms-auto"></i>

                </a>


                <a href="maintenance.php" class="quick-action">

                    <div class="quick-icon icon-purple">
                        <i class="bi bi-wrench"></i>
                    </div>

                    <div>
                        <strong>Record Maintenance</strong>

                        <span>Log a maintenance activity</span>
                    </div>

                    <i class="bi bi-chevron-right ms-auto"></i>

                </a>

            </div>


            <!-- System information -->

            <div class="section-card">

                <div class="section-header">

                    <div>
                        <h5>System Information</h5>

                        <p>
                            Application details
                        </p>
                    </div>

                    <i class="bi bi-info-circle text-primary"></i>

                </div>


                <div class="info-row">
                    <span>Application</span>
                    <strong>MLFMS</strong>
                </div>

                <div class="info-row">
                    <span>Currency</span>
                    <strong>ZMW (K)</strong>
                </div>

                <div class="info-row">
                    <span>Environment</span>
                    <strong>Local System</strong>
                </div>

                <div class="info-row">
                    <span>GPS Tracking</span>
                    <span class="info-pending">Planned</span>
                </div>

            </div>

        </div>

    </div>

</div>


<!-- Chart.js dashboard chart -->

<script>
document.addEventListener('DOMContentLoaded', function () {

    const chartElement = document.getElementById('deliveryChart');

    if (!chartElement) {
        return;
    }

    new Chart(chartElement, {

        type: 'bar',

        data: {

            labels: [
                'Deliveries',
                'Drivers',
                'Vehicles'
            ],

            datasets: [{

                label: 'Total Records',

                data: [
                    <?= $totalDeliveries ?? 0 ?>,
                    <?= $totalDrivers ?? 0 ?>,
                    <?= $totalVehicles ?? 0 ?>
                ],

                backgroundColor: [
                    '#2563eb',
                    '#16a34a',
                    '#f59e0b'
                ],

                borderRadius: 8,
                maxBarThickness: 65

            }]

        },

        options: {

            responsive: true,

            maintainAspectRatio: false,

            plugins: {

                legend: {
                    display: false
                }

            },

            scales: {

                y: {

                    beginAtZero: true,

                    ticks: {
                        precision: 0
                    }

                }

            }

        }

    });

});
</script>


<?php require_once 'includes/footer.php'; ?>
