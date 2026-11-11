
<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

require_login();
require_permission('payments');

$page_title = 'Payments';
$currentPage = 'payments.php';

$today = date('Y-m-d');
$errors = [];

/* =========================================================
   RECORD PAYMENT
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (($_POST['form_action'] ?? '') !== 'record_payment') {
        flash('Invalid payment request.', 'danger');
        header('Location: payments.php');
        exit;
    }

    $deliveryId = filter_var($_POST['delivery_id'] ?? '', FILTER_VALIDATE_INT);
    $amountInput = trim($_POST['amount'] ?? '');
    $method = $_POST['payment_method'] ?? '';
    $reference = trim($_POST['reference_number'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    $allowedMethods = ['cash', 'mobile_money', 'bank'];

    if (!$deliveryId || $deliveryId <= 0) {
        flash('Invalid delivery selected.', 'danger');
        header('Location: payments.php');
        exit;
    }

    if (!is_numeric($amountInput) || !is_finite((float)$amountInput) || (float)$amountInput <= 0) {
        flash('Enter a valid payment amount greater than zero.', 'danger');
        header('Location: payments.php');
        exit;
    }

    $amount = round((float)$amountInput, 2);

    if (!in_array($method, $allowedMethods, true)) {
        flash('Invalid payment method selected.', 'danger');
        header('Location: payments.php');
        exit;
    }

    if (strlen($reference) > 100) {
        flash('Reference number cannot exceed 100 characters.', 'danger');
        header('Location: payments.php');
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT id, order_code, total_price, status
            FROM deliveries
            WHERE id = ?
            FOR UPDATE
        ");
        $stmt->execute([$deliveryId]);
        $delivery = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$delivery) {
            throw new RuntimeException('Delivery not found.');
        }

        if ($delivery['status'] === 'cancelled') {
            throw new RuntimeException('Payments cannot be recorded for cancelled deliveries.');
        }

        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM payments
            WHERE delivery_id = ?
        ");
        $stmt->execute([$deliveryId]);

        $alreadyPaid = (float)$stmt->fetchColumn();
        $totalPrice = (float)$delivery['total_price'];
        $balance = max(0, round($totalPrice - $alreadyPaid, 2));

        if ($balance <= 0) {
            throw new RuntimeException('This delivery has already been fully paid.');
        }

        if ($amount > $balance + 0.001) {
            throw new RuntimeException(
                'Payment exceeds the outstanding balance of ' . money($balance)
            );
        }

        $stmt = $pdo->prepare("
            INSERT INTO payments (
                delivery_id,
                amount,
                payment_method,
                reference_number,
                notes,
                received_by
            )
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $deliveryId,
            $amount,
            $method,
            $reference !== '' ? $reference : null,
            $notes !== '' ? $notes : null,
            $_SESSION['user_id'] ?? null
        ]);

        $newPaid = round($alreadyPaid + $amount, 2);

        $paymentStatus = $newPaid >= $totalPrice - 0.001
            ? 'paid'
            : 'partial';

        $stmt = $pdo->prepare("
            UPDATE deliveries
            SET payment_status = ?
            WHERE id = ?
        ");
        $stmt->execute([$paymentStatus, $deliveryId]);

        $stmt = $pdo->prepare("
            INSERT INTO delivery_events (
                delivery_id,
                event_type,
                description,
                created_by
            )
            VALUES (?, ?, ?, ?)
        ");

        $stmt->execute([
            $deliveryId,
            'payment_received',
            'Payment of ' . money($amount) .
            ' received via ' . ucfirst(str_replace('_', ' ', $method)),
            $_SESSION['user_id'] ?? null
        ]);

        $pdo->commit();

        flash('Payment successfully recorded.', 'success');
        header('Location: payments.php');
        exit;

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash($e instanceof RuntimeException
            ? $e->getMessage()
            : 'Unable to record payment. Please try again.', 'danger');

        header('Location: payments.php');
        exit;
    }
}

/* =========================================================
   DASHBOARD STATISTICS
========================================================= */

$totalCollected = (float)$pdo->query("
    SELECT COALESCE(SUM(amount), 0)
    FROM payments
")->fetchColumn();

$todayCollected = (float)$pdo->query("
    SELECT COALESCE(SUM(amount), 0)
    FROM payments
    WHERE DATE(payment_date) = CURDATE()
")->fetchColumn();

$totalOutstanding = (float)$pdo->query("
    SELECT COALESCE(SUM(
        d.total_price - COALESCE(p.paid, 0)
    ), 0)
    FROM deliveries d
    LEFT JOIN (
        SELECT delivery_id, SUM(amount) AS paid
        FROM payments
        GROUP BY delivery_id
    ) p ON p.delivery_id = d.id
    WHERE d.status <> 'cancelled'
")->fetchColumn();

$unpaidCount = (int)$pdo->query("
    SELECT COUNT(*)
    FROM deliveries
    WHERE payment_status = 'unpaid'
      AND status <> 'cancelled'
")->fetchColumn();

$partialCount = (int)$pdo->query("
    SELECT COUNT(*)
    FROM deliveries
    WHERE payment_status = 'partial'
      AND status <> 'cancelled'
")->fetchColumn();

$paidCount = (int)$pdo->query("
    SELECT COUNT(*)
    FROM deliveries
    WHERE payment_status = 'paid'
      AND status <> 'cancelled'
")->fetchColumn();

/* =========================================================
   CHART 1: PAYMENT METHODS
========================================================= */

$methodRows = $pdo->query("
    SELECT payment_method, SUM(amount) AS total
    FROM payments
    GROUP BY payment_method
")->fetchAll(PDO::FETCH_ASSOC);

$methodLabels = [];
$methodAmounts = [];

foreach ($methodRows as $row) {
    $methodLabels[] = ucfirst(str_replace('_', ' ', $row['payment_method']));
    $methodAmounts[] = (float)$row['total'];
}

/* =========================================================
   CHART 2: DAILY COLLECTIONS - LAST 7 DAYS
========================================================= */

$dailyRows = $pdo->query("
    SELECT DATE(payment_date) AS payment_day,
           SUM(amount) AS total
    FROM payments
    WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
      AND payment_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
    GROUP BY DATE(payment_date)
    ORDER BY payment_day ASC
")->fetchAll(PDO::FETCH_ASSOC);

$dailyMap = [];

foreach ($dailyRows as $row) {
    $dailyMap[$row['payment_day']] = (float)$row['total'];
}

$dailyLabels = [];
$dailyAmounts = [];

for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));

    $dailyLabels[] = date('D, d M', strtotime($date));
    $dailyAmounts[] = $dailyMap[$date] ?? 0;
}

/* =========================================================
   CHART 3: PAYMENT STATUS
========================================================= */

$statusLabels = ['Paid', 'Partial', 'Unpaid'];
$statusAmounts = [$paidCount, $partialCount, $unpaidCount];

/* =========================================================
   OUTSTANDING PAYMENTS
========================================================= */

$outstandingStmt = $pdo->query("
    SELECT
        d.id,
        d.order_code,
        d.customer_name,
        d.customer_phone,
        d.total_price,
        d.payment_status,
        d.status AS delivery_status,
        COALESCE(p.paid, 0) AS paid,
        d.total_price - COALESCE(p.paid, 0) AS balance
    FROM deliveries d
    LEFT JOIN (
        SELECT delivery_id, SUM(amount) AS paid
        FROM payments
        GROUP BY delivery_id
    ) p ON p.delivery_id = d.id
    WHERE d.status <> 'cancelled'
      AND d.total_price - COALESCE(p.paid, 0) > 0.001
    ORDER BY d.created_at DESC
");

$outstandingPayments = $outstandingStmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   RECENT PAYMENT HISTORY
========================================================= */

$historyStmt = $pdo->query("
    SELECT
        p.id,
        p.delivery_id,
        p.amount,
        p.payment_method,
        p.reference_number,
        p.notes,
        p.payment_date,
        d.order_code,
        d.customer_name,
        u.name AS received_by
    FROM payments p
    LEFT JOIN deliveries d ON d.id = p.delivery_id
    LEFT JOIN users u ON u.id = p.received_by
    ORDER BY p.payment_date DESC, p.id DESC
    LIMIT 100
");

$paymentHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/includes/header.php';
?>

<style>
.payment-stat-card {
    border: 0;
    border-radius: 14px;
    box-shadow: 0 4px 16px rgba(0,0,0,.045);
    transition: transform .2s ease, box-shadow .2s ease;
    height: 100%;
}

.payment-stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 22px rgba(0,0,0,.08);
}

.payment-stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
}

.payment-chart-card {
    border: 0;
    border-radius: 14px;
    box-shadow: 0 4px 16px rgba(0,0,0,.045);
    height: 100%;
}

.payment-chart-container {
    position: relative;
    height: 290px;
    width: 100%;
}

.payment-chart-container-small {
    position: relative;
    height: 260px;
    width: 100%;
}

.payment-section-card {
    border: 0;
    border-radius: 14px;
    box-shadow: 0 4px 16px rgba(0,0,0,.045);
}

.payment-table th {
    white-space: nowrap;
    font-size: .82rem;
    text-transform: uppercase;
    letter-spacing: .035em;
    color: #6c757d;
}

.payment-table td {
    vertical-align: middle;
}

.payment-search {
    max-width: 320px;
}

.payment-order-code {
    font-weight: 600;
    color: #0d6efd;
}

.payment-amount {
    font-weight: 700;
    white-space: nowrap;
}

.payment-status-badge {
    font-size: .75rem;
    padding: 6px 9px;
}

.payment-modal-icon {
    width: 45px;
    height: 45px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #e7f1ff;
    color: #0d6efd;
    font-size: 22px;
}
</style>

<!-- PAGE HEADER -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <h3 class="fw-bold mb-1">
            <i class="bi bi-wallet2 me-2 text-primary"></i>
            Payments & Collections
        </h3>
        <p class="text-muted mb-0">
            Manage customer payments, outstanding balances and collection performance.
        </p>
    </div>

    <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary"
                onclick="window.location.reload()">
            <i class="bi bi-arrow-clockwise me-1"></i> Refresh
        </button>
    </div>
</div>

<!-- KPI CARDS -->
<div class="row g-3 mb-4">

    <div class="col-xl-3 col-md-6">
        <div class="card payment-stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-2">Total Collected</div>
                        <h4 class="fw-bold mb-0"><?= money($totalCollected) ?></h4>
                        <small class="text-success">
                            <i class="bi bi-check-circle me-1"></i>All payments
                        </small>
                    </div>
                    <div class="payment-stat-icon bg-success-subtle text-success">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="card payment-stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-2">Collected Today</div>
                        <h4 class="fw-bold mb-0"><?= money($todayCollected) ?></h4>
                        <small class="text-primary">
                            <i class="bi bi-calendar-day me-1"></i><?= date('d M Y') ?>
                        </small>
                    </div>
                    <div class="payment-stat-icon bg-primary-subtle text-primary">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="card payment-stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-2">Outstanding</div>
                        <h4 class="fw-bold mb-0"><?= money($totalOutstanding) ?></h4>
                        <small class="text-danger">
                            <i class="bi bi-exclamation-circle me-1"></i>Receivables
                        </small>
                    </div>
                    <div class="payment-stat-icon bg-danger-subtle text-danger">
                        <i class="bi bi-hourglass-split"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="card payment-stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-2">Unpaid Deliveries</div>
                        <h4 class="fw-bold mb-0"><?= number_format($unpaidCount) ?></h4>
                        <small class="text-warning">
                            <i class="bi bi-receipt me-1"></i>
                            <?= number_format($partialCount) ?> partial
                        </small>
                    </div>
                    <div class="payment-stat-icon bg-warning-subtle text-warning">
                        <i class="bi bi-file-earmark-text"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- CHARTS -->
<div class="row g-3 mb-4">

    <!-- DAILY COLLECTIONS -->
    <div class="col-xl-8">
        <div class="card payment-chart-card">
            <div class="card-header bg-white border-0 pt-3 px-3">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h6 class="fw-bold mb-1">
                            <i class="bi bi-bar-chart-line me-2 text-primary"></i>
                            Daily Collections
                        </h6>
                        <small class="text-muted">Payment collections over the last 7 days</small>
                    </div>
                    <span class="badge bg-primary-subtle text-primary">
                        Last 7 days
                    </span>
                </div>
            </div>
            <div class="card-body">
                <div class="payment-chart-container">
                    <canvas id="dailyCollectionsChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- PAYMENT METHODS -->
    <div class="col-xl-4">
        <div class="card payment-chart-card">
            <div class="card-header bg-white border-0 pt-3 px-3">
                <h6 class="fw-bold mb-1">
                    <i class="bi bi-pie-chart me-2 text-success"></i>
                    Payment Methods
                </h6>
                <small class="text-muted">Collections by payment channel</small>
            </div>
            <div class="card-body">
                <div class="payment-chart-container-small">
                    <canvas id="paymentMethodsChart"></canvas>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- PAYMENT STATUS CHART -->
<div class="row g-3 mb-4">
    <div class="col-xl-5">
        <div class="card payment-chart-card">
            <div class="card-header bg-white border-0 pt-3 px-3">
                <h6 class="fw-bold mb-1">
                    <i class="bi bi-pie-chart-fill me-2 text-warning"></i>
                    Payment Status Overview
                </h6>
                <small class="text-muted">Paid, partial and unpaid deliveries</small>
            </div>
            <div class="card-body">
                <div class="payment-chart-container-small">
                    <canvas id="paymentStatusChart"></canvas>
                </div>
        </div>
        </div>
    </div>

    <div class="col-xl-7">
        <div class="card payment-chart-card">
            <div class="card-body">
                <h6 class="fw-bold mb-3">
                    <i class="bi bi-info-circle me-2 text-primary"></i>
                    Collection Summary
                </h6>

                <div class="d-flex justify-content-between align-items-center py-3 border-bottom">
                    <span class="text-muted">
                        <i class="bi bi-check-circle text-success me-2"></i>
                        Fully paid deliveries
                    </span>
                    <span class="badge bg-success-subtle text-success fs-6">
                        <?= number_format($paidCount) ?>
                    </span>
                </div>

                <div class="d-flex justify-content-between align-items-center py-3 border-bottom">
                    <span class="text-muted">
                        <i class="bi bi-clock text-warning me-2"></i>
                        Partially paid deliveries
                    </span>
                    <span class="badge bg-warning-subtle text-warning fs-6">
                        <?= number_format($partialCount) ?>
                    </span>
                </div>

                <div class="d-flex justify-content-between align-items-center py-3 border-bottom">
                    <span class="text-muted">
                        <i class="bi bi-exclamation-circle text-danger me-2"></i>
                        Unpaid deliveries
                    </span>
                    <span class="badge bg-danger-subtle text-danger fs-6">
                        <?= number_format($unpaidCount) ?>
                    </span>
                </div>

                <div class="mt-4 p-3 rounded bg-light">
                    <div class="small text-muted mb-1">Total Outstanding Balance</div>
                    <h3 class="fw-bold text-danger mb-0">
                        <?= money($totalOutstanding) ?>
                    </h3>
                    <small class="text-muted">Across all non-cancelled deliveries</small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- OUTSTANDING PAYMENTS -->
<div class="card payment-section-card mb-4">

    <div class="card-header bg-white border-0 pt-3 px-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="fw-bold mb-1">
                    <i class="bi bi-receipt me-2 text-danger"></i>
                    Outstanding Payments
                </h5>
                <small class="text-muted">
                    Deliveries with unpaid or partially paid balances
                </small>
            </div>

            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-danger-subtle text-danger">
                    <?= count($outstandingPayments) ?> outstanding
                </span>
                <input type="text"
                       id="outstandingSearch"
                       class="form-control form-control-sm payment-search"
                       placeholder="Search order or customer...">
            </div>
        </div>
    </div>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover payment-table mb-0" id="outstandingTable">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Order</th>
                        <th>Customer</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th>Delivery</th>
                        <th class="text-end pe-3">Action</th>
                    </tr>
                </thead>
                <tbody>

                <?php if (!$outstandingPayments): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5">
                            <i class="bi bi-check-circle display-5 text-success"></i>
                            <h6 class="mt-3 fw-bold">All payments settled</h6>
                            <p class="text-muted mb-0">There are no outstanding payments.</p>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($outstandingPayments as $row): ?>

                    <tr>
                        <td class="ps-3">
                            <span class="payment-order-code">
                                <?= e($row['order_code']) ?>
                            </span>
                        </td>

                        <td>
                            <div class="fw-semibold"><?= e($row['customer_name']) ?></div>
                            <small class="text-muted"><?= e($row['customer_phone']) ?></small>
                        </td>

                        <td><?= money($row['total_price']) ?></td>

                        <td class="text-success fw-semibold">
                            <?= money($row['paid']) ?>
                        </td>

                        <td class="payment-amount text-danger">
                            <?= money($row['balance']) ?>
                        </td>

                        <td>
                            <?php if ($row['payment_status'] === 'partial'): ?>
                                <span class="badge bg-warning-subtle text-warning payment-status-badge">
                                    Partial
                                </span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger payment-status-badge">
                                    Unpaid
                                </span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="badge bg-secondary-subtle text-secondary">
                                <?= e(ucfirst(str_replace('_', ' ', $row['delivery_status']))) ?>
                            </span>
                        </td>

                        <td class="text-end pe-3">
                            <button class="btn btn-sm btn-primary"
                                    data-bs-toggle="modal"
                                    data-bs-target="#paymentModal<?= (int)$row['id'] ?>">
                                <i class="bi bi-plus-circle me-1"></i>
                                Record
                            </button>
                        </td>
                    </tr>

                <?php endforeach; ?>

                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- PAYMENT HISTORY -->
<div class="card payment-section-card mb-4">

    <div class="card-header bg-white border-0 pt-3 px-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="fw-bold mb-1">
                    <i class="bi bi-clock-history me-2 text-success"></i>
                    Recent Payment History
                </h5>
                <small class="text-muted">
                    Latest 100 recorded transactions
                </small>
            </div>

            <input type="text"
                   id="historySearch"
                   class="form-control form-control-sm payment-search"
                   placeholder="Search payment history...">
        </div>
    </div>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover payment-table mb-0" id="historyTable">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Date</th>
                        <th>Order</th>
                        <th>Customer</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th>Received By</th>
                        <th class="text-end pe-3">Amount</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!$paymentHistory): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="bi bi-wallet2 display-5"></i>
                            <p class="mt-3 mb-0">No payments recorded yet.</p>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($paymentHistory as $payment): ?>

                    <tr>
                        <td class="ps-3">
                            <div><?= date('d M Y', strtotime($payment['payment_date'])) ?></div>
                            <small class="text-muted">
                                <?= date('H:i', strtotime($payment['payment_date'])) ?>
                            </small>
                        </td>

                        <td>
                            <span class="payment-order-code">
                                <?= e($payment['order_code'] ?? 'N/A') ?>
                            </span>
                        </td>

                        <td><?= e($payment['customer_name'] ?? 'Unknown') ?></td>

                        <td>
                            <?php
                            $method = $payment['payment_method'];
                            $methodIcon = match ($method) {
                                'cash' => 'bi-cash',
                                'mobile_money' => 'bi-phone',
                                'bank' => 'bi-bank',
                                default => 'bi-wallet2'
                            };
                            ?>
                            <span class="badge bg-light text-dark">
                                <i class="bi <?= $methodIcon ?> me-1"></i>
                                <?= e(ucfirst(str_replace('_', ' ', $method))) ?>
                            </span>
                        </td>

                        <td>
                            <?= e($payment['reference_number'] ?: '—') ?>
                        </td>

                        <td>
                            <?= e($payment['received_by'] ?: 'System') ?>
                        </td>

                        <td class="text-end pe-3 payment-amount text-success">
                            +<?= money($payment['amount']) ?>
                        </td>
                    </tr>

                <?php endforeach; ?>

                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- PAYMENT MODALS -->
<?php foreach ($outstandingPayments as $row): ?>

<div class="modal fade"
     id="paymentModal<?= (int)$row['id'] ?>"
     tabindex="-1"
     aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">

            <form method="POST">

                <div class="modal-header">
                    <div class="d-flex align-items-center gap-3">
                        <div class="payment-modal-icon">
                            <i class="bi bi-wallet2"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold">Record Payment</h5>
                            <small class="text-muted">
                                <?= e($row['order_code']) ?>
                            </small>
                        </div>
                    </div>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="form_action" value="record_payment">
                    <input type="hidden" name="delivery_id" value="<?= (int)$row['id'] ?>">

                    <div class="bg-light rounded p-3 mb-3">

                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Customer</span>
                            <span class="fw-semibold"><?= e($row['customer_name']) ?></span>
                        </div>

                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Delivery Total</span>
                            <span><?= money($row['total_price']) ?></span>
                        </div>

                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Already Paid</span>
                            <span class="text-success"><?= money($row['paid']) ?></span>
                        </div>

                        <div class="d-flex justify-content-between pt-2 border-top">
                            <span class="fw-semibold">Outstanding Balance</span>
                            <span class="fw-bold text-danger fs-5">
                                <?= money($row['balance']) ?>
                            </span>
                        </div>

                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Payment Amount (K)</label>

                        <input type="number"
                               name="amount"
                               class="form-control form-control-lg"
                               min="0.01"
                               max="<?= e(number_format((float)$row['balance'], 2, '.', '')) ?>"
                               step="0.01"
                               required
                               placeholder="Enter amount">

                        <div class="form-text">
                            Maximum payment:
                            <?= money($row['balance']) ?>
                        </div>

                        <button type="button"
                                class="btn btn-sm btn-outline-success mt-2"
                                onclick="setFullBalance(this)">
                            <i class="bi bi-check2-circle me-1"></i>
                            Pay Full Balance
                        </button>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Payment Method</label>
                        <select name="payment_method" class="form-select" required>
                            <option value="">Select payment method</option>
                            <option value="cash">Cash</option>
                            <option value="mobile_money">Mobile Money</option>
                            <option value="bank">Bank Transfer</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            Reference Number
                        </label>
                        <input type="text"
                               name="reference_number"
                               maxlength="100"
                               class="form-control"
                               placeholder="Transaction ID (optional)">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notes</label>
                        <textarea name="notes"
                                  class="form-control"
                                  rows="2"
                                  placeholder="Additional payment details (optional)"></textarea>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button"
                            class="btn btn-light"
                            data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-1"></i>
                        Confirm Payment
                    </button>
                </div>

            </form>

        </div>
    </div>
</div>

<?php endforeach; ?>

<!-- CHART.JS -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    /* =====================================================
       CHART DATA FROM PHP
    ===================================================== */

    const dailyLabels = <?= json_encode($dailyLabels) ?>;
    const dailyAmounts = <?= json_encode($dailyAmounts) ?>;

    const methodLabels = <?= json_encode($methodLabels) ?>;
    const methodAmounts = <?= json_encode($methodAmounts) ?>;

    const statusLabels = <?= json_encode($statusLabels) ?>;
    const statusAmounts = <?= json_encode($statusAmounts) ?>;

    /* =====================================================
       DAILY COLLECTIONS BAR CHART
    ===================================================== */

    const dailyCanvas = document.getElementById('dailyCollectionsChart');

    if (dailyCanvas) {

        new Chart(dailyCanvas, {
            type: 'bar',

            data: {
                labels: dailyLabels,

                datasets: [{
                    label: 'Collections (K)',
                    data: dailyAmounts,

                    backgroundColor: 'rgba(13, 110, 253, 0.75)',
                    borderColor: '#0d6efd',
                    borderWidth: 1,
                    borderRadius: 7,
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
                                return ' K' + Number(context.raw).toLocaleString(
                                    'en-ZM',
                                    {minimumFractionDigits: 2, maximumFractionDigits: 2}
                                );
                            }
                        }
                    }
                },

                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },

                    y: {
                        beginAtZero: true,

                        ticks: {
                            callback: function(value) {
                                return 'K' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
    }

    /* =====================================================
       PAYMENT METHODS DOUGHNUT CHART
    ===================================================== */

    const methodCanvas = document.getElementById('paymentMethodsChart');

    if (methodCanvas) {

        new Chart(methodCanvas, {
            type: 'doughnut',

            data: {
                labels: methodLabels,

                datasets: [{
                    data: methodAmounts,

                    backgroundColor: [
                        '#198754',
                        '#0d6efd',
                        '#fd7e14',
                        '#6f42c1'
                    ],

                    borderWidth: 3,
                    borderColor: '#ffffff',
                    hoverOffset: 8
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
                            padding: 18,
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }
                    },

                    tooltip: {
                        callbacks: {
                            label: function(context) {

                                const value = Number(context.raw);

                                return context.label + ': K' +
                                    value.toLocaleString(
                                        'en-ZM',
                                        {minimumFractionDigits: 2, maximumFractionDigits: 2}
                                    );
                            }
                        }
                    }
                }
            }
        });
    }

    /* =====================================================
       PAYMENT STATUS DOUGHNUT CHART
    ===================================================== */

    const statusCanvas = document.getElementById('paymentStatusChart');

    if (statusCanvas) {

        new Chart(statusCanvas, {
            type: 'doughnut',

            data: {
                labels: statusLabels,

                datasets: [{
                    data: statusAmounts,

                    backgroundColor: [
                        '#198754',
                        '#ffc107',
                        '#dc3545'
                    ],

                    borderWidth: 3,
                    borderColor: '#ffffff',
                    hoverOffset: 8
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
                            padding: 18,
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }
                    }
                }
            }
        });
    }

    /* =====================================================
       OUTSTANDING PAYMENT SEARCH
    ===================================================== */

    const outstandingSearch = document.getElementById('outstandingSearch');
    const outstandingTable = document.getElementById('outstandingTable');

    if (outstandingSearch && outstandingTable) {

        outstandingSearch.addEventListener('input', function() {

            const search = this.value.toLowerCase();

            outstandingTable.querySelectorAll('tbody tr').forEach(function(row) {

                row.style.display = row.innerText.toLowerCase().includes(search)
                    ? ''
                    : 'none';
            });
        });
    }

    /* =====================================================
       PAYMENT HISTORY SEARCH
    ===================================================== */

    const historySearch = document.getElementById('historySearch');
    const historyTable = document.getElementById('historyTable');

    if (historySearch && historyTable) {

        historySearch.addEventListener('input', function() {

            const search = this.value.toLowerCase();

            historyTable.querySelectorAll('tbody tr').forEach(function(row) {

                row.style.display = row.innerText.toLowerCase().includes(search)
                    ? ''
                    : 'none';
            });
        });
    }

});

/* =========================================================
   PAY FULL BALANCE BUTTON
========================================================= */

function setFullBalance(button) {

    const modal = button.closest('.modal');

    if (!modal) return;

    const amountInput = modal.querySelector('input[name="amount"]');

    if (!amountInput) return;

    amountInput.value = amountInput.max;
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
