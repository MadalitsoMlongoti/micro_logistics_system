
<?php
require 'config/database.php';
require 'includes/functions.php';

require_login();
require_permission('finance');

$page_title = 'Financial Reports';
$currentPage = 'finance.php';

/*
|--------------------------------------------------------------------------
| DATE FILTERS
|--------------------------------------------------------------------------
*/

$today = date('Y-m-d');
$firstDayOfMonth = date('Y-m-01');

$startDate = $_GET['start_date'] ?? $firstDayOfMonth;
$endDate = $_GET['end_date'] ?? $today;

$datePattern = '/^\d{4}-\d{2}-\d{2}$/';

if (
    !preg_match($datePattern, $startDate) ||
    !preg_match($datePattern, $endDate) ||
    !strtotime($startDate) ||
    !strtotime($endDate) ||
    $startDate > $endDate
) {
    $startDate = $firstDayOfMonth;
    $endDate = $today;
}

$endExclusive = date(
    'Y-m-d',
    strtotime($endDate . ' +1 day')
);

/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function reportPercentage($value, $total): float
{
    if ((float)$total <= 0) {
        return 0;
    }

    return round(((float)$value / (float)$total) * 100, 1);
}

function reportMoney($amount): string
{
    return 'K' . number_format((float)$amount, 2);
}

/*
|--------------------------------------------------------------------------
| FINANCIAL SUMMARY
|--------------------------------------------------------------------------
*/

// Actual payments received during the selected period.
$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(amount), 0) AS total_collected,
        COUNT(*) AS transaction_count,
        COALESCE(AVG(amount), 0) AS average_payment
    FROM payments
    WHERE payment_date >= ?
      AND payment_date < ?
");

$stmt->execute([$startDate, $endExclusive]);
$paymentSummary = $stmt->fetch(PDO::FETCH_ASSOC);

$totalCollected = (float)$paymentSummary['total_collected'];
$transactionCount = (int)$paymentSummary['transaction_count'];
$averagePayment = (float)$paymentSummary['average_payment'];

// Delivery sales value for deliveries created during the period.
// Cancelled deliveries are excluded.
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_deliveries,
        COALESCE(SUM(total_price), 0) AS delivery_value,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) AS assigned,
        SUM(CASE WHEN status = 'picked_up' THEN 1 ELSE 0 END) AS picked_up,
        SUM(CASE WHEN status = 'in_transit' THEN 1 ELSE 0 END) AS in_transit,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled
    FROM deliveries
    WHERE created_at >= ?
      AND created_at < ?
      AND status != 'cancelled'
");

$stmt->execute([$startDate, $endExclusive]);
$deliverySummary = $stmt->fetch(PDO::FETCH_ASSOC);

$totalDeliveries = (int)$deliverySummary['total_deliveries'];
$deliveryValue = (float)$deliverySummary['delivery_value'];

// Retrieve all delivery statuses, including cancelled deliveries,
// for the operational status chart.
$stmt = $pdo->prepare("
    SELECT status, COUNT(*) AS total
    FROM deliveries
    WHERE created_at >= ?
      AND created_at < ?
    GROUP BY status
");

$stmt->execute([$startDate, $endExclusive]);
$statusRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$statusCounts = [
    'pending' => 0,
    'assigned' => 0,
    'picked_up' => 0,
    'in_transit' => 0,
    'delivered' => 0,
    'cancelled' => 0
];

foreach ($statusRows as $row) {
    if (array_key_exists($row['status'], $statusCounts)) {
        $statusCounts[$row['status']] = (int)$row['total'];
    }
}

$totalStatusDeliveries = array_sum($statusCounts);

$completedDeliveries = $statusCounts['delivered'];

$completionRate = reportPercentage(
    $completedDeliveries,
    $totalStatusDeliveries
);

$cancellationRate = reportPercentage(
    $statusCounts['cancelled'],
    $totalStatusDeliveries
);

/*
|--------------------------------------------------------------------------
| OUTSTANDING RECEIVABLES
|--------------------------------------------------------------------------
|
| This is the current outstanding balance across all non-cancelled
| deliveries, not just deliveries created in the selected period.
|
*/

$stmt = $pdo->query("
    SELECT
        COALESCE(
            SUM(
                GREATEST(
                    d.total_price - COALESCE(p.paid_amount, 0),
                    0
                )
            ),
            0
        ) AS outstanding
    FROM deliveries d
    LEFT JOIN (
        SELECT
            delivery_id,
            SUM(amount) AS paid_amount
        FROM payments
        GROUP BY delivery_id
    ) p ON p.delivery_id = d.id
    WHERE d.status != 'cancelled'
      AND d.total_price > COALESCE(p.paid_amount, 0)
");

$outstandingReceivables = (float)$stmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| MAINTENANCE EXPENDITURE
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(cost), 0) AS maintenance_cost,
        COUNT(*) AS maintenance_count
    FROM maintenance_records
    WHERE service_date >= ?
      AND service_date <= ?
");

$stmt->execute([$startDate, $endDate]);
$maintenanceSummary = $stmt->fetch(PDO::FETCH_ASSOC);

$maintenanceCost = (float)$maintenanceSummary['maintenance_cost'];
$maintenanceCount = (int)$maintenanceSummary['maintenance_count'];

// This is a cash-management indicator, NOT net profit.
$cashAfterMaintenance = $totalCollected - $maintenanceCost;

/*
|--------------------------------------------------------------------------
| PAYMENT METHOD BREAKDOWN
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        payment_method,
        COUNT(*) AS transaction_count,
        COALESCE(SUM(amount), 0) AS total_amount
    FROM payments
    WHERE payment_date >= ?
      AND payment_date < ?
    GROUP BY payment_method
    ORDER BY total_amount DESC
");

$stmt->execute([$startDate, $endExclusive]);
$paymentMethods = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| DAILY REVENUE
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        DATE(payment_date) AS payment_day,
        COALESCE(SUM(amount), 0) AS daily_total
    FROM payments
    WHERE payment_date >= ?
      AND payment_date < ?
    GROUP BY DATE(payment_date)
    ORDER BY payment_day ASC
");

$stmt->execute([$startDate, $endExclusive]);
$dailyRevenue = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| VEHICLE TYPE PERFORMANCE
|--------------------------------------------------------------------------
|
| This measures delivery sales value, not actual payments collected.
|
*/

$stmt = $pdo->prepare("
    SELECT
        vehicle_type,
        COUNT(*) AS delivery_count,
        COALESCE(SUM(total_price), 0) AS total_revenue,
        COALESCE(AVG(total_price), 0) AS average_value
    FROM deliveries
    WHERE created_at >= ?
      AND created_at < ?
      AND status != 'cancelled'
    GROUP BY vehicle_type
    ORDER BY total_revenue DESC
");

$stmt->execute([$startDate, $endExclusive]);
$vehiclePerformance = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| RECENT PAYMENTS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.payment_date,
        p.amount,
        p.payment_method,
        p.reference_number,
        d.order_code,
        d.customer_name
    FROM payments p
    INNER JOIN deliveries d ON d.id = p.delivery_id
    WHERE p.payment_date >= ?
      AND p.payment_date < ?
    ORDER BY p.payment_date DESC, p.id DESC
    LIMIT 50
");

$stmt->execute([$startDate, $endExclusive]);
$recentPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| CSV EXPORT
|--------------------------------------------------------------------------
|
| Export is processed before header.php to prevent output before
| HTTP headers are sent.
|
*/

if (isset($_GET['export']) && $_GET['export'] === 'csv') {

    $filename = 'financial_report_' . $startDate . '_to_' . $endDate . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    // UTF-8 BOM helps Excel display characters correctly.
    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, ['MICRO LOGISTICS FINANCIAL REPORT']);
    fputcsv($output, ['Reporting period', $startDate . ' to ' . $endDate]);
    fputcsv($output, []);

    fputcsv($output, ['FINANCIAL SUMMARY']);
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Revenue collected', $totalCollected]);
    fputcsv($output, ['Delivery sales value', $deliveryValue]);
    fputcsv($output, ['Outstanding receivables (current balance)', $outstandingReceivables]);
    fputcsv($output, ['Maintenance expenditure', $maintenanceCost]);
    fputcsv($output, ['Collected less maintenance (not profit)', $cashAfterMaintenance]);
    fputcsv($output, ['Payment transactions', $transactionCount]);
    fputcsv($output, ['Average payment', $averagePayment]);
    fputcsv($output, ['Deliveries created', $totalDeliveries]);
    fputcsv($output, ['Completed deliveries', $completedDeliveries]);
    fputcsv($output, ['Completion rate (%)', $completionRate]);
    fputcsv($output, ['Cancellation rate (%)', $cancellationRate]);
    fputcsv($output, []);

    fputcsv($output, ['DELIVERY STATUS']);
    fputcsv($output, ['Status', 'Deliveries']);

    foreach ($statusCounts as $status => $count) {
        fputcsv($output, [ucwords(str_replace('_', ' ', $status)), $count]);
    }

    fputcsv($output, []);

    fputcsv($output, ['PAYMENT METHODS']);
    fputcsv($output, ['Payment method', 'Transactions', 'Amount']);

    foreach ($paymentMethods as $method) {
        fputcsv($output, [
            ucwords(str_replace('_', ' ', $method['payment_method'])),
            $method['transaction_count'],
            $method['total_amount']
        ]);
    }

    fputcsv($output, []);

    fputcsv($output, ['DAILY REVENUE']);
    fputcsv($output, ['Date', 'Amount collected']);

    foreach ($dailyRevenue as $day) {
        fputcsv($output, [
            $day['payment_day'],
            $day['daily_total']
        ]);
    }

    fputcsv($output, []);

    fputcsv($output, ['VEHICLE PERFORMANCE']);
    fputcsv($output, ['Vehicle type', 'Deliveries', 'Delivery sales value', 'Average delivery value']);

    foreach ($vehiclePerformance as $vehicle) {
        fputcsv($output, [
            ucfirst($vehicle['vehicle_type']),
            $vehicle['delivery_count'],
            $vehicle['total_revenue'],
            $vehicle['average_value']
        ]);
    }

    fputcsv($output, []);

    fputcsv($output, ['RECENT PAYMENTS (LATEST 50)']);
    fputcsv($output, [
        'Payment date',
        'Order code',
        'Customer',
        'Payment method',
        'Reference',
        'Amount'
    ]);

    foreach ($recentPayments as $payment) {
        fputcsv($output, [
            $payment['payment_date'],
            $payment['order_code'],
            $payment['customer_name'],
            $payment['payment_method'],
            $payment['reference_number'],
            $payment['amount']
        ]);
    }

    fclose($output);
    exit;
}

/*
|--------------------------------------------------------------------------
| CHART DATA
|--------------------------------------------------------------------------
*/

$dailyLabels = [];
$dailyValues = [];

foreach ($dailyRevenue as $day) {
    $dailyLabels[] = date('d M', strtotime($day['payment_day']));
    $dailyValues[] = (float)$day['daily_total'];
}

$methodLabels = [];
$methodValues = [];

foreach ($paymentMethods as $method) {
    $methodLabels[] = ucwords(
        str_replace('_', ' ', $method['payment_method'])
    );

    $methodValues[] = (float)$method['total_amount'];
}

$statusLabels = [
    'Pending',
    'Assigned',
    'Picked Up',
    'In Transit',
    'Delivered',
    'Cancelled'
];

$statusValues = [
    $statusCounts['pending'],
    $statusCounts['assigned'],
    $statusCounts['picked_up'],
    $statusCounts['in_transit'],
    $statusCounts['delivered'],
    $statusCounts['cancelled']
];

$vehicleLabels = [];
$vehicleValues = [];

foreach ($vehiclePerformance as $vehicle) {
    $vehicleLabels[] = ucfirst($vehicle['vehicle_type']);
    $vehicleValues[] = (float)$vehicle['total_revenue'];
}

$chartOptions = JSON_HEX_TAG
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
    | JSON_HEX_AMP
    | JSON_INVALID_UTF8_SUBSTITUTE;

/*
|--------------------------------------------------------------------------
| PAGE HEADER
|--------------------------------------------------------------------------
*/

require 'includes/header.php';

$csvUrl = 'finance.php?' . http_build_query([
    'start_date' => $startDate,
    'end_date' => $endDate,
    'export' => 'csv'
]);

?>

<style>
/* =========================================================
   FINANCIAL REPORTS - PAGE STYLING
========================================================= */

.finance-page {
    padding-bottom: 35px;
}

.finance-hero {
    background: linear-gradient(120deg, #102c46, #176b87);
    color: #fff;
    border-radius: 16px;
    padding: 27px;
    margin-bottom: 24px;
    box-shadow: 0 8px 25px rgba(16, 44, 70, .12);
}

.finance-hero h2 {
    font-size: 25px;
    font-weight: 700;
    margin-bottom: 7px;
}

.finance-hero p {
    color: rgba(255,255,255,.78);
    margin-bottom: 0;
}

.finance-hero .btn {
    font-weight: 600;
}

.finance-filter,
.finance-card,
.chart-card,
.report-table-card {
    background: #fff;
    border: 1px solid #e9edf2;
    border-radius: 14px;
    box-shadow: 0 3px 14px rgba(0,0,0,.035);
}

.finance-filter {
    padding: 20px;
    margin-bottom: 24px;
}

.finance-filter label {
    font-weight: 600;
    font-size: 13px;
    margin-bottom: 7px;
}

.finance-filter .form-control {
    min-height: 43px;
}

.finance-card {
    padding: 19px;
    height: 100%;
    position: relative;
    overflow: hidden;
}

.finance-card .card-label {
    font-size: 13px;
    color: #718096;
    font-weight: 600;
    margin-bottom: 9px;
}

.finance-card .card-value {
    font-size: 25px;
    font-weight: 750;
    color: #172b4d;
    margin-bottom: 7px;
    overflow-wrap: anywhere;
}

.finance-card .card-description {
    font-size: 12px;
    color: #8994a5;
    margin: 0;
}

.finance-card .card-icon {
    width: 43px;
    height: 43px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 19px;
    margin-bottom: 15px;
}

.icon-green {
    background: #e4f7ed;
    color: #159957;
}

.icon-blue {
    background: #e6f0ff;
    color: #2874c7;
}

.icon-orange {
    background: #fff1df;
    color: #d98216;
}

.icon-red {
    background: #ffe8e8;
    color: #d64545;
}

.icon-purple {
    background: #f0eaff;
    color: #7652c8;
}

.icon-teal {
    background: #ddf7f5;
    color: #128b83;
}

.section-heading {
    font-size: 18px;
    font-weight: 700;
    color: #23354d;
    margin: 28px 0 15px;
}

.chart-card {
    padding: 20px;
    height: 100%;
    min-height: 350px;
}

.chart-title {
    font-size: 15px;
    font-weight: 700;
    color: #23354d;
    margin-bottom: 5px;
}

.chart-subtitle {
    font-size: 12px;
    color: #8792a2;
    margin-bottom: 17px;
}

.chart-container {
    height: 265px;
    width: 100%;
    position: relative;
}

.chart-container canvas {
    max-width: 100%;
}

.chart-empty {
    color: #8994a5;
    text-align: center;
    padding: 75px 10px;
    font-size: 13px;
}

.report-table-card {
    overflow: hidden;
    margin-bottom: 20px;
}

.report-table-header {
    padding: 18px 20px;
    border-bottom: 1px solid #edf0f4;
}

.report-table-header h5 {
    font-size: 15px;
    font-weight: 700;
    margin: 0;
    color: #23354d;
}

.report-table-header p {
    color: #8994a5;
    font-size: 12px;
    margin: 5px 0 0;
}

.report-table-card .table {
    margin-bottom: 0;
}

.report-table-card .table th {
    font-size: 12px;
    color: #718096;
    font-weight: 700;
    background: #f8fafc;
    white-space: nowrap;
    padding: 13px 16px;
}

.report-table-card .table td {
    font-size: 13px;
    padding: 13px 16px;
    vertical-align: middle;
    color: #34445b;
}

.status-label {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    background: #edf2f7;
    color: #475569;
}

.status-delivered {
    background: #dcfce7;
    color: #15803d;
}

.status-cancelled {
    background: #fee2e2;
    color: #b91c1c;
}

.status-pending {
    background: #fef3c7;
    color: #92400e;
}

.status-assigned {
    background: #dbeafe;
    color: #1d4ed8;
}

.status-picked_up,
.status-in_transit {
    background: #e0e7ff;
    color: #4338ca;
}

.method-bar {
    height: 8px;
    background: #edf1f5;
    border-radius: 10px;
    overflow: hidden;
    margin-top: 8px;
}

.method-bar-fill {
    height: 100%;
    border-radius: 10px;
    background: #2874c7;
}

.report-note {
    background: #f1f7ff;
    border-left: 4px solid #2874c7;
    padding: 13px 16px;
    border-radius: 7px;
    font-size: 12px;
    color: #52647c;
    margin-top: 15px;
}

.finance-footer-note {
    font-size: 12px;
    color: #8994a5;
    text-align: center;
    padding: 15px;
}

@media(max-width: 768px) {
    .finance-hero {
        padding: 20px;
    }

    .finance-hero h2 {
        font-size: 21px;
    }

    .finance-card .card-value {
        font-size: 21px;
    }

    .chart-card {
        min-height: 320px;
    }

    .chart-container {
        height: 235px;
    }
}

/* =========================================================
   PRINT / SAVE AS PDF
========================================================= */

@media print {

    @page {
        size: A4 landscape;
        margin: 12mm;
    }

    body {
        background: #fff !important;
        font-size: 10px;
    }

    .sidebar,
    .topbar,
    .navbar,
    .finance-filter,
    .no-print,
    button,
    .btn,
    footer {
        display: none !important;
    }

    .main-content,
    .content,
    .container-fluid {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
    }

    .finance-hero {
        background: #eaf1f8 !important;
        color: #172b4d !important;
        box-shadow: none !important;
        border: 1px solid #dce4ee;
        padding: 15px;
    }

    .finance-hero p {
        color: #475569 !important;
    }

    .finance-card,
    .chart-card,
    .report-table-card {
        box-shadow: none !important;
        border: 1px solid #ddd !important;
        break-inside: avoid;
    }

    .finance-card {
        padding: 12px;
    }

    .finance-card .card-value {
        font-size: 18px;
    }

    .chart-card {
        min-height: 280px;
        padding: 12px;
    }

    .chart-container {
        height: 210px;
    }

    .report-table-card {
        margin-bottom: 12px;
    }

    .table {
        font-size: 9px !important;
    }

    .section-heading {
        margin-top: 18px;
        font-size: 15px;
    }

    canvas {
        max-width: 100% !important;
    }

    .finance-footer-note {
        display: block !important;
    }
}
</style>

<div class="finance-page">

    <!-- =====================================================
         HERO SECTION
    ====================================================== -->

    <div class="finance-hero">

        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">

            <div>
                <h2>
                    <i class="bi bi-graph-up-arrow me-2"></i>
                    Financial Reports
                </h2>

                <p>
                    Monitor revenue, collections, outstanding balances,
                    delivery performance and operational expenditure.
                </p>

                <div class="mt-2 small">
                    <i class="bi bi-calendar3 me-1"></i>
                    Reporting period:
                    <strong>
                        <?= e(date('d M Y', strtotime($startDate))) ?>
                        -
                        <?= e(date('d M Y', strtotime($endDate))) ?>
                    </strong>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2 no-print">

                <a href="<?= e($csvUrl) ?>"
                   class="btn btn-light">
                    <i class="bi bi-file-earmark-spreadsheet me-1"></i>
                    Export CSV
                </a>

                <button type="button"
                        onclick="window.print()"
                        class="btn btn-outline-light">
                    <i class="bi bi-printer me-1"></i>
                    Print / Save PDF
                </button>

            </div>

        </div>

    </div>


    <!-- =====================================================
         DATE FILTERS
    ====================================================== -->

    <div class="finance-filter no-print">

        <form method="GET" action="finance.php">

            <div class="row g-3 align-items-end">

                <div class="col-md-4">

                    <label for="start_date">
                        Start Date
                    </label>

                    <input type="date"
                           name="start_date"
                           id="start_date"
                           class="form-control"
                           value="<?= e($startDate) ?>"
                           required>

                </div>

                <div class="col-md-4">

                    <label for="end_date">
                        End Date
                    </label>

                    <input type="date"
                           name="end_date"
                           id="end_date"
                           class="form-control"
                           value="<?= e($endDate) ?>"
                           required>

                </div>

                <div class="col-md-4 d-flex gap-2">

                    <button type="submit"
                            class="btn btn-primary flex-grow-1">
                        <i class="bi bi-filter me-1"></i>
                        Generate Report
                    </button>

                    <a href="finance.php"
                       class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </a>

                </div>

            </div>

        </form>

        <div class="d-flex flex-wrap gap-2 mt-3">

            <span class="small text-muted me-1 align-self-center">
                Quick filters:
            </span>

            <?php
            $quickPeriods = [
                'Today' => [$today, $today],
                'This Week' => [
                    date('Y-m-d', strtotime('monday this week')),
                    $today
                ],
                'This Month' => [$firstDayOfMonth, $today],
                'Last Month' => [
                    date('Y-m-01', strtotime('first day of last month')),
                    date('Y-m-t', strtotime('last month'))
                ]
            ];
            ?>

            <?php foreach ($quickPeriods as $label => $dates): ?>

                <a class="btn btn-sm btn-outline-primary"
                   href="finance.php?start_date=<?= e($dates[0]) ?>&end_date=<?= e($dates[1]) ?>">
                    <?= e($label) ?>
                </a>

            <?php endforeach; ?>

        </div>

    </div>


    <!-- =====================================================
         FINANCIAL KPI CARDS
    ====================================================== -->

    <h5 class="section-heading mt-0">
        <i class="bi bi-speedometer2 me-2"></i>
        Financial Overview
    </h5>

    <div class="row g-3">

        <div class="col-xl-3 col-md-6">

            <div class="finance-card">

                <div class="card-icon icon-green">
                    <i class="bi bi-cash-stack"></i>
                </div>

                <div class="card-label">
                    Revenue Collected
                </div>

                <div class="card-value">
                    <?= reportMoney($totalCollected) ?>
                </div>

                <p class="card-description">
                    Actual payments received during this period
                </p>

            </div>

        </div>


        <div class="col-xl-3 col-md-6">

            <div class="finance-card">

                <div class="card-icon icon-blue">
                    <i class="bi bi-truck"></i>
                </div>

                <div class="card-label">
                    Delivery Sales Value
                </div>

                <div class="card-value">
                    <?= reportMoney($deliveryValue) ?>
                </div>

                <p class="card-description">
                    Non-cancelled deliveries created during this period
                </p>

            </div>

        </div>


        <div class="col-xl-3 col-md-6">

            <div class="finance-card">

                <div class="card-icon icon-orange">
                    <i class="bi bi-wallet2"></i>
                </div>

                <div class="card-label">
                    Outstanding Receivables
                </div>

                <div class="card-value">
                    <?= reportMoney($outstandingReceivables) ?>
                </div>

                <p class="card-description">
                    Current unpaid balance across all deliveries
                </p>

            </div>

        </div>


        <div class="col-xl-3 col-md-6">

            <div class="finance-card">

                <div class="card-icon icon-red">
                    <i class="bi bi-tools"></i>
                </div>

                <div class="card-label">
                    Maintenance Expenditure
                </div>

                <div class="card-value">
                    <?= reportMoney($maintenanceCost) ?>
                </div>

                <p class="card-description">
                    Maintenance recorded during this period
                </p>

            </div>

        </div>


        <div class="col-xl-3 col-md-6">

            <div class="finance-card">

                <div class="card-icon icon-purple">
                    <i class="bi bi-receipt"></i>
                </div>

                <div class="card-label">
                    Payment Transactions
                </div>

                <div class="card-value">
                    <?= number_format($transactionCount) ?>
                </div>

                <p class="card-description">
                    Total payment records received
                </p>

            </div>

        </div>


        <div class="col-xl-3 col-md-6">

            <div class="finance-card">

                <div class="card-icon icon-teal">
                    <i class="bi bi-calculator"></i>
                </div>

                <div class="card-label">
                    Average Payment
                </div>

                <div class="card-value">
                    <?= reportMoney($averagePayment) ?>
                </div>

                <p class="card-description">
                    Average value per payment transaction
                </p>

            </div>

        </div>


        <div class="col-xl-3 col-md-6">

            <div class="finance-card">

                <div class="card-icon icon-blue">
                    <i class="bi bi-box-seam"></i>
                </div>

                <div class="card-label">
                    Deliveries Created
                </div>

                <div class="card-value">
                    <?= number_format($totalDeliveries) ?>
                </div>

                <p class="card-description">
                    Non-cancelled deliveries in this period
                </p>

            </div>

        </div>


        <div class="col-xl-3 col-md-6">

            <div class="finance-card">

                <div class="card-icon icon-green">
                    <i class="bi bi-check-circle"></i>
                </div>

                <div class="card-label">
                    Delivery Completion Rate
                </div>

                <div class="card-value">
                    <?= number_format($completionRate, 1) ?>%
                </div>

                <p class="card-description">
                    <?= number_format($completedDeliveries) ?>
                    completed out of
                    <?= number_format($totalStatusDeliveries) ?>
                    total deliveries
                </p>

            </div>

        </div>

    </div>


    <div class="report-note">

        <i class="bi bi-info-circle me-1"></i>

        <strong>Reporting note:</strong>
        Revenue collected represents actual payments received.
        Delivery sales value represents delivery charges created.
        Outstanding receivables are the current unpaid balance across
        all deliveries. Maintenance expenditure is based on service dates.
        Collected less maintenance is only a cash-management indicator,
        not net profit.

    </div>


    <!-- =====================================================
         CHARTS
    ====================================================== -->

    <h5 class="section-heading">
        <i class="bi bi-bar-chart-line me-2"></i>
        Financial Analytics
    </h5>


    <div class="row g-3">

        <!-- DAILY REVENUE CHART -->

        <div class="col-xl-8 col-lg-7">

            <div class="chart-card">

                <div class="chart-title">
                    Daily Revenue Trend
                </div>

                <div class="chart-subtitle">
                    Actual payments collected per day
                </div>

                <?php if (!empty($dailyRevenue)): ?>

                    <div class="chart-container">
                        <canvas id="dailyRevenueChart"></canvas>
                    </div>

                <?php else: ?>

                    <div class="chart-empty">
                        <i class="bi bi-bar-chart fs-2 d-block mb-2"></i>
                        No payment data available for this period.
                    </div>

                <?php endif; ?>

            </div>

        </div>


        <!-- PAYMENT METHODS CHART -->

        <div class="col-xl-4 col-lg-5">

            <div class="chart-card">

                <div class="chart-title">
                    Payment Methods
                </div>

                <div class="chart-subtitle">
                    Distribution of actual collections
                </div>

                <?php if (!empty($paymentMethods)): ?>

                    <div class="chart-container">
                        <canvas id="paymentMethodsChart"></canvas>
                    </div>

                <?php else: ?>

                    <div class="chart-empty">
                        <i class="bi bi-pie-chart fs-2 d-block mb-2"></i>
                        No payment data available.
                    </div>

                <?php endif; ?>

            </div>

        </div>


        <!-- DELIVERY STATUS CHART -->

        <div class="col-xl-5 col-lg-5">

            <div class="chart-card">

                <div class="chart-title">
                    Delivery Status Distribution
                </div>

                <div class="chart-subtitle">
                    All deliveries created during the selected period
                </div>

                <?php if ($totalStatusDeliveries > 0): ?>

                    <div class="chart-container">
                        <canvas id="deliveryStatusChart"></canvas>
                    </div>

                <?php else: ?>

                    <div class="chart-empty">
                        <i class="bi bi-pie-chart fs-2 d-block mb-2"></i>
                        No delivery data available.
                    </div>

                <?php endif; ?>

            </div>

        </div>


        <!-- VEHICLE REVENUE CHART -->

        <div class="col-xl-7 col-lg-7">

            <div class="chart-card">

                <div class="chart-title">
                    Revenue by Vehicle Type
                </div>

                <div class="chart-subtitle">
                    Delivery sales value generated by each vehicle category
                </div>

                <?php if (!empty($vehiclePerformance)): ?>

                    <div class="chart-container">
                        <canvas id="vehicleRevenueChart"></canvas>
                    </div>

                <?php else: ?>

                    <div class="chart-empty">
                        <i class="bi bi-truck fs-2 d-block mb-2"></i>
                        No vehicle performance data available.
                    </div>

                <?php endif; ?>

            </div>

        </div>

    </div>


    <!-- =====================================================
         PAYMENT METHOD BREAKDOWN TABLE
    ====================================================== -->

    <h5 class="section-heading">
        <i class="bi bi-credit-card me-2"></i>
        Payment Method Breakdown
    </h5>

    <div class="report-table-card">

        <div class="report-table-header">
            <h5>Collections by Payment Method</h5>
            <p>Actual payments received during the selected period</p>
        </div>

        <div class="table-responsive">

            <table class="table table-hover">

                <thead>
                    <tr>
                        <th>Payment Method</th>
                        <th>Transactions</th>
                        <th>Total Collected</th>
                        <th>Share of Collections</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (!empty($paymentMethods)): ?>

                        <?php foreach ($paymentMethods as $method): ?>

                            <?php
                            $percentage = reportPercentage(
                                $method['total_amount'],
                                $totalCollected
                            );
                            ?>

                            <tr>

                                <td class="fw-semibold">
                                    <?= e(ucwords(str_replace('_', ' ', $method['payment_method']))) ?>
                                </td>

                                <td>
                                    <?= number_format((int)$method['transaction_count']) ?>
                                </td>

                                <td class="fw-semibold">
                                    <?= reportMoney($method['total_amount']) ?>
                                </td>

                                <td style="min-width:180px;">

                                    <div class="d-flex justify-content-between">
                                        <span><?= $percentage ?>%</span>
                                    </div>

                                    <div class="method-bar">

                                        <div class="method-bar-fill"
                                             style="width: <?= min(100, max(0, $percentage)) ?>%;">
                                        </div>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">
                                No payment records found.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

                <tfoot class="table-light">

                    <tr>
                        <th>Total</th>
                        <th><?= number_format($transactionCount) ?></th>
                        <th><?= reportMoney($totalCollected) ?></th>
                        <th>100%</th>
                    </tr>

                </tfoot>

            </table>

        </div>

    </div>


    <!-- =====================================================
         DELIVERY PERFORMANCE
    ====================================================== -->

    <h5 class="section-heading">
        <i class="bi bi-truck me-2"></i>
        Vehicle Performance
    </h5>

    <div class="report-table-card">

        <div class="report-table-header">
            <h5>Delivery Performance by Vehicle Type</h5>
            <p>Delivery sales value generated during the selected period</p>
        </div>

        <div class="table-responsive">

            <table class="table table-hover">

                <thead>
                    <tr>
                        <th>Vehicle Type</th>
                        <th>Deliveries</th>
                        <th>Delivery Sales Value</th>
                        <th>Average Delivery Value</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (!empty($vehiclePerformance)): ?>

                        <?php foreach ($vehiclePerformance as $vehicle): ?>

                            <tr>

                                <td class="fw-semibold">
                                    <i class="bi bi-truck me-2 text-primary"></i>
                                    <?= e(ucfirst($vehicle['vehicle_type'])) ?>
                                </td>

                                <td>
                                    <?= number_format((int)$vehicle['delivery_count']) ?>
                                </td>

                                <td class="fw-semibold">
                                    <?= reportMoney($vehicle['total_revenue']) ?>
                                </td>

                                <td>
                                    <?= reportMoney($vehicle['average_value']) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">
                                No vehicle performance data found.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>


    <!-- =====================================================
         DELIVERY STATUS SUMMARY
    ====================================================== -->

    <h5 class="section-heading">
        <i class="bi bi-clipboard-data me-2"></i>
        Delivery Status Summary
    </h5>

    <div class="report-table-card">

        <div class="report-table-header">
            <h5>Delivery Status Breakdown</h5>
            <p>All delivery statuses, including cancelled deliveries</p>
        </div>

        <div class="table-responsive">

            <table class="table table-hover">

                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Number of Deliveries</th>
                        <th>Percentage</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($statusCounts as $status => $count): ?>

                        <?php
                        $statusPercentage = reportPercentage(
                            $count,
                            $totalStatusDeliveries
                        );
                        ?>

                        <tr>

                            <td>
                                <span class="status-label status-<?= e($status) ?>">
                                    <?= e(ucwords(str_replace('_', ' ', $status))) ?>
                                </span>
                            </td>

                            <td>
                                <?= number_format($count) ?>
                            </td>

                            <td>
                                <?= number_format($statusPercentage, 1) ?>%
                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    </div>


    <!-- =====================================================
         RECENT PAYMENTS
    ====================================================== -->

    <h5 class="section-heading">
        <i class="bi bi-clock-history me-2"></i>
        Recent Payment Transactions
    </h5>

    <div class="report-table-card">

        <div class="report-table-header">
            <h5>Latest Payments</h5>
            <p>Showing up to 50 payment transactions within the selected period</p>
        </div>

        <div class="table-responsive">

            <table class="table table-hover">

                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Order Code</th>
                        <th>Customer</th>
                        <th>Payment Method</th>
                        <th>Reference</th>
                        <th class="text-end">Amount</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (!empty($recentPayments)): ?>

                        <?php foreach ($recentPayments as $payment): ?>

                            <tr>

                                <td>
                                    <?= e(date('d M Y H:i', strtotime($payment['payment_date']))) ?>
                                </td>

                                <td class="fw-semibold">
                                    <?= e($payment['order_code']) ?>
                                </td>

                                <td>
                                    <?= e($payment['customer_name']) ?>
                                </td>

                                <td>
                                    <?= e(ucwords(str_replace('_', ' ', $payment['payment_method']))) ?>
                                </td>

                                <td>
                                    <?= e($payment['reference_number'] ?: '—') ?>
                                </td>

                                <td class="text-end fw-semibold">
                                    <?= reportMoney($payment['amount']) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                No payment transactions found for this period.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>


    <!-- =====================================================
         REPORT FOOTER
    ====================================================== -->

    <div class="finance-footer-note">

        <p class="mb-1">
            <strong>Micro Logistics Financial Reports</strong>
        </p>

        <p class="mb-0">
            Generated on <?= e(date('d M Y H:i:s')) ?>.
            All amounts are displayed in Zambian Kwacha (ZMW).
        </p>

    </div>

</div>


<!-- =========================================================
     CHART.JS
========================================================== -->

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    if (typeof Chart === 'undefined') {
        console.error('Chart.js could not be loaded.');
        return;
    }

    Chart.defaults.font.family =
        "'Segoe UI', Arial, sans-serif";

    Chart.defaults.color = '#718096';

    Chart.defaults.plugins.legend.labels.usePointStyle = true;


    /*
    |--------------------------------------------------------------------------
    | DAILY REVENUE CHART
    |--------------------------------------------------------------------------
    */

    const dailyCanvas = document.getElementById('dailyRevenueChart');

    if (dailyCanvas) {

        const dailyLabels = <?= json_encode($dailyLabels, $chartOptions) ?>;
        const dailyValues = <?= json_encode($dailyValues, $chartOptions) ?>;

        new Chart(dailyCanvas, {

            type: 'bar',

            data: {

                labels: dailyLabels,

                datasets: [{

                    label: 'Revenue Collected',

                    data: dailyValues,

                    backgroundColor: 'rgba(40, 116, 199, 0.78)',

                    borderColor: '#2874c7',

                    borderWidth: 1,

                    borderRadius: 6,

                    maxBarThickness: 45

                }]

            },

            options: {

                responsive: true,

                maintainAspectRatio: false,

                interaction: {
                    intersect: false,
                    mode: 'index'
                },

                plugins: {

                    legend: {
                        display: false
                    },

                    tooltip: {

                        callbacks: {

                            label: function(context) {

                                return 'Collected: K' +
                                    Number(context.raw).toLocaleString(
                                        'en-US',
                                        {
                                            minimumFractionDigits: 2,
                                            maximumFractionDigits: 2
                                        }
                                    );

                            }

                        }

                    }

                },

                scales: {

                    y: {

                        beginAtZero: true,

                        ticks: {

                            callback: function(value) {
                                return 'K' + Number(value).toLocaleString();
                            }

                        },

                        grid: {
                            color: '#edf1f5'
                        }

                    },

                    x: {

                        grid: {
                            display: false
                        }

                    }

                }

            }

        });

    }


    /*
    |--------------------------------------------------------------------------
    | PAYMENT METHODS DOUGHNUT CHART
    |--------------------------------------------------------------------------
    */

    const paymentCanvas = document.getElementById('paymentMethodsChart');

    if (paymentCanvas) {

        const methodLabels = <?= json_encode($methodLabels, $chartOptions) ?>;
        const methodValues = <?= json_encode($methodValues, $chartOptions) ?>;

        new Chart(paymentCanvas, {

            type: 'doughnut',

            data: {

                labels: methodLabels,

                datasets: [{

                    data: methodValues,

                    backgroundColor: [
                        '#2874c7',
                        '#16a085',
                        '#f39c12',
                        '#8e63ce',
                        '#e74c3c'
                    ],

                    borderWidth: 2,

                    borderColor: '#ffffff',

                    hoverOffset: 7

                }]

            },

            options: {

                responsive: true,

                maintainAspectRatio: false,

                cutout: '65%',

                plugins: {

                    legend: {

                        position: 'bottom',

                        labels: {
                            padding: 15,
                            boxWidth: 10
                        }

                    },

                    tooltip: {

                        callbacks: {

                            label: function(context) {

                                return context.label + ': K' +
                                    Number(context.raw).toLocaleString(
                                        'en-US',
                                        {
                                            minimumFractionDigits: 2,
                                            maximumFractionDigits: 2
                                        }
                                    );

                            }

                        }

                    }

                }

            }

        });

    }


    /*
    |--------------------------------------------------------------------------
    | DELIVERY STATUS DOUGHNUT CHART
    |--------------------------------------------------------------------------
    */

    const statusCanvas = document.getElementById('deliveryStatusChart');

    if (statusCanvas) {

        const statusLabels = <?= json_encode($statusLabels, $chartOptions) ?>;
        const statusValues = <?= json_encode($statusValues, $chartOptions) ?>;

        new Chart(statusCanvas, {

            type: 'doughnut',

            data: {

                labels: statusLabels,

                datasets: [{

                    data: statusValues,

                    backgroundColor: [
                        '#f0ad4e',
                        '#2874c7',
                        '#8e63ce',
                        '#5b73d6',
                        '#16a085',
                        '#e74c3c'
                    ],

                    borderWidth: 2,

                    borderColor: '#ffffff',

                    hoverOffset: 7

                }]

            },

            options: {

                responsive: true,

                maintainAspectRatio: false,

                cutout: '60%',

                plugins: {

                    legend: {

                        position: 'bottom',

                        labels: {
                            padding: 12,
                            boxWidth: 10
                        }

                    }

                }

            }

        });

    }


    /*
    |--------------------------------------------------------------------------
    | VEHICLE REVENUE BAR CHART
    |--------------------------------------------------------------------------
    */

    const vehicleCanvas = document.getElementById('vehicleRevenueChart');

    if (vehicleCanvas) {

        const vehicleLabels = <?= json_encode($vehicleLabels, $chartOptions) ?>;
        const vehicleValues = <?= json_encode($vehicleValues, $chartOptions) ?>;

        new Chart(vehicleCanvas, {

            type: 'bar',

            data: {

                labels: vehicleLabels,

                datasets: [{

                    label: 'Delivery Sales Value',

                    data: vehicleValues,

                    backgroundColor: [
                        'rgba(22, 160, 133, 0.78)',
                        'rgba(40, 116, 199, 0.78)',
                        'rgba(142, 99, 206, 0.78)'
                    ],

                    borderRadius: 7,

                    maxBarThickness: 65

                }]

            },

            options: {

                responsive: true,

                maintainAspectRatio: false,

                indexAxis: 'y',

                plugins: {

                    legend: {
                        display: false
                    },

                    tooltip: {

                        callbacks: {

                            label: function(context) {

                                return 'Delivery value: K' +
                                    Number(context.raw).toLocaleString(
                                        'en-US',
                                        {
                                            minimumFractionDigits: 2,
                                            maximumFractionDigits: 2
                                        }
                                    );

                            }

                        }

                    }

                },

                scales: {

                    x: {

                        beginAtZero: true,

                        ticks: {

                            callback: function(value) {
                                return 'K' + Number(value).toLocaleString();
                            }

                        },

                        grid: {
                            color: '#edf1f5'
                        }

                    },

                    y: {

                        grid: {
                            display: false
                        }

                    }

                }

            }

        });

    }

});
</script>

<?php require 'includes/footer.php'; ?>
