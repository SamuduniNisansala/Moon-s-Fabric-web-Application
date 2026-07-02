<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin-auth.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/admin-payment-management.php';

requireAdminAccess();

const ADMIN_PAYMENTS_PER_PAGE = 10;

$stateSource = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$state = normalizeAdminPaymentsState(is_array($stateSource) ? $stateSource : []);
$pageError = pullFlashMessage('payments_error') ?? '';
$successMessage = pullFlashMessage('payments_success') ?? '';
$adminName = (string) ($_SESSION['admin_name'] ?? 'Admin');
$lastLogin = (string) ($_SESSION['admin_last_login'] ?? date('Y-m-d H:i:s'));

try {
    $pdo = getDatabaseConnection();
} catch (Throwable) {
    $pdo = null;
    $pageError = 'Unable to connect to the database right now.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
    if (!isValidCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        $pageError = 'Your session token has expired. Please refresh the page and try again.';
    } else {
        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action === 'manage_payment') {
            $paymentAction = trim((string) ($_POST['payment_action'] ?? 'save'));
            try {
                if ($paymentAction === 'verify') {
                    verifyAdminBankTransferPayment($pdo, (int) ($_POST['payment_id'] ?? 0));
                    setFlashMessage('payments_success', 'Bank transfer payment verified successfully.');
                } else {
                    updateAdminPaymentStatus(
                        $pdo,
                        (int) ($_POST['payment_id'] ?? 0),
                        trim((string) ($_POST['payment_status'] ?? ''))
                    );
                    setFlashMessage('payments_success', 'Payment status updated successfully.');
                }

                header('Location: ' . buildAdminPaymentsUrl($state));
                exit;
            } catch (Throwable $exception) {
                $pageError = $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'We could not update that payment right now. Please try again.';
            }
        }
    }
}

$summary = [
    'totalPayments' => 0,
    'paidPayments' => 0,
    'pendingPayments' => 0,
    'failedPayments' => 0,
    'totalRevenue' => 0.0,
];
$paymentsData = [
    'items' => [],
    'pagination' => [
        'currentPage' => 1,
        'perPage' => ADMIN_PAYMENTS_PER_PAGE,
        'totalItems' => 0,
        'totalPages' => 1,
    ],
];
$drawerPayload = [];
$paymentUpdatesEnabled = $pdo instanceof PDO ? adminPaymentStorageWritable() : false;

if ($pdo instanceof PDO) {
    try {
        $summary = fetchAdminPaymentSummary($pdo);
        $paymentsData = fetchPagedAdminPayments(
            $pdo,
            $state['search'],
            $state['method'],
            $state['status'],
            $state['page'],
            ADMIN_PAYMENTS_PER_PAGE
        );

        $drawerPayload = buildAdminPaymentDrawerPayload($paymentsData['items'], $paymentUpdatesEnabled);
        $state['page'] = (int) $paymentsData['pagination']['currentPage'];
    } catch (Throwable) {
        $pageError = $pageError !== ''
            ? $pageError
            : 'Unable to load payment management data from the database.';
    }
}

$pagination = $paymentsData['pagination'];
$pageStart = $pagination['totalItems'] > 0
    ? (($pagination['currentPage'] - 1) * $pagination['perPage']) + 1
    : 0;
$pageEnd = $pagination['totalItems'] > 0
    ? min($pagination['totalItems'], $pagination['currentPage'] * $pagination['perPage'])
    : 0;
$menuItems = buildAdminManagementMenu('payments');
$adminProductsStylesheetVersion = is_file(__DIR__ . '/assets/css/admin-products.css')
    ? (string) filemtime(__DIR__ . '/assets/css/admin-products.css')
    : '1';
$adminPaymentsStylesheetVersion = is_file(__DIR__ . '/assets/css/admin-payments.css')
    ? (string) filemtime(__DIR__ . '/assets/css/admin-payments.css')
    : '1';
$adminPaymentManagementScriptVersion = is_file(__DIR__ . '/assets/js/admin-payment-management.js')
    ? (string) filemtime(__DIR__ . '/assets/js/admin-payment-management.js')
    : '1';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Management | MOON s Fabric Shop</title>
    <link rel="stylesheet" href="assets/css/admin-products.css?v=<?= escape($adminProductsStylesheetVersion); ?>">
    <link rel="stylesheet" href="assets/css/admin-payments.css?v=<?= escape($adminPaymentsStylesheetVersion); ?>">
</head>

<body class="products-body admin-payments-body">
    <button class="sidebar-overlay" data-sidebar-overlay hidden aria-label="Close menu"></button>

    <div class="dashboard-app">
        <aside class="dashboard-sidebar" data-dashboard-sidebar>
            <div class="sidebar-brand">
                <div>
                    <span class="sidebar-eyebrow">MOON s Fabric Shop</span>
                    <h1>Payment Admin</h1>
                </div>
                <button type="button" class="sidebar-close" data-sidebar-close>Close</button>
            </div>

            <nav class="sidebar-nav">
                <?php foreach ($menuItems as $item): ?>
                    <a
                        href="<?= escape((string) $item['href']); ?>"
                        class="sidebar-link<?= !empty($item['active']) ? ' is-active' : ''; ?><?= !empty($item['logout']) ? ' is-logout' : ''; ?>"
                        data-sidebar-link>
                        <span class="sidebar-icon"><?= escape((string) $item['short']); ?></span>
                        <span><?= escape((string) $item['label']); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="sidebar-footer">
                <div class="sidebar-meta-label">Signed in as</div>
                <div class="sidebar-meta-value"><?= escape($adminName); ?></div>
            </div>
        </aside>

        <div class="dashboard-main">
            <header class="dashboard-topbar">
                <div class="topbar-left">
                    <button type="button" class="mobile-menu-button" data-sidebar-open aria-label="Open menu">
                        <span></span>
                        <span></span>
                        <span></span>
                    </button>
                    <div>
                        <span class="dashboard-label">Payment Management</span>
                        <h2>Manage COD and bank transfer payments from one place</h2>
                    </div>
                </div>

                <div class="topbar-right">
                    <span class="topbar-meta-label">Last login</span>
                    <span class="topbar-meta-value"><?= escape(formatAdminOrderDateTime($lastLogin)); ?></span>
                </div>
            </header>

            <main class="dashboard-content">
                <?php if ($pageError !== ''): ?>
                    <div class="status-message is-error">
                        <?= escape($pageError); ?>
                    </div>
                <?php endif; ?>

                <?php if ($successMessage !== ''): ?>
                    <div class="status-message is-success">
                        <?= escape($successMessage); ?>
                    </div>
                <?php endif; ?>

                <section class="products-card payment-hero-card">
                    <div class="section-head">
                        <div class="section-copy">
                            <span class="dashboard-label">Secure Payment Control</span>
                            <h3>Review proof, verify transfers, and keep revenue accurate</h3>
                        </div>
                        <div class="hero-tag-stack">
                            <span class="hero-pill">Cash on Delivery</span>
                            <span class="hero-pill">Bank Transfer</span>
                            <span class="hero-pill">Proof review</span>
                        </div>
                    </div>
                </section>

                <section class="metric-grid payment-metric-grid">
                    <article class="metric-card tone-blue">
                        <span class="metric-title">Total Payments</span>
                        <strong class="metric-value"><?= escape(number_format($summary['totalPayments'])); ?></strong>
                    </article>
                    <article class="metric-card tone-sky">
                        <span class="metric-title">Paid Payments</span>
                        <strong class="metric-value"><?= escape(number_format($summary['paidPayments'])); ?></strong>
                    </article>
                    <article class="metric-card tone-slate">
                        <span class="metric-title">Pending Payments</span>
                        <strong class="metric-value"><?= escape(number_format($summary['pendingPayments'])); ?></strong>
                    </article>
                    <article class="metric-card tone-amber">
                        <span class="metric-title">Failed Payments</span>
                        <strong class="metric-value"><?= escape(number_format($summary['failedPayments'])); ?></strong>
                    </article>
                    <article class="metric-card tone-rose">
                        <span class="metric-title">Total Revenue</span>
                        <strong class="metric-value"><?= escape(formatAdminOrderCurrency((float) $summary['totalRevenue'])); ?></strong>
                    </article>
                </section>

                <section class="products-card products-card-wide">
                    <div class="section-head">
                        <div class="section-copy">
                            <span class="dashboard-label">Payments Table</span>
                            <h3>Search, filter, and verify payment records</h3>
                        </div>
                        <div class="toolbar-actions">
                            <a class="secondary-button" href="admin-orders.php">Open orders</a>
                        </div>
                    </div>

                    <form method="get" class="filters-form">
                        <div class="filter-grid">
                            <label class="input-group">
                                <span>Search payments</span>
                                <input
                                    type="search"
                                    name="search"
                                    value="<?= escape($state['search']); ?>"
                                    placeholder="Payment ID, order ID, customer, amount, or proof">
                            </label>

                            <label class="input-group">
                                <span>Filter by method</span>
                                <select name="method">
                                    <option value="">All methods</option>
                                    <?php foreach (ADMIN_UI_PAYMENT_METHODS as $methodOption): ?>
                                        <option value="<?= escape($methodOption); ?>" <?= $state['method'] === $methodOption ? 'selected' : ''; ?>>
                                            <?= escape(adminPaymentMethodLabel($methodOption)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label class="input-group">
                                <span>Filter by status</span>
                                <select name="status">
                                    <option value="">All statuses</option>
                                    <?php foreach (ADMIN_UI_PAYMENT_STATUSES as $statusOption): ?>
                                        <option value="<?= escape($statusOption); ?>" <?= $state['status'] === $statusOption ? 'selected' : ''; ?>>
                                            <?= escape($statusOption); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <div class="filter-actions">
                                <button type="submit" class="toolbar-button">Apply Filters</button>
                                <a href="admin-payments.php" class="toolbar-link">Reset</a>
                            </div>
                        </div>
                    </form>

                    <div class="list-meta">
                        <div class="list-meta-copy">
                            Showing <?= escape((string) $pageStart); ?>-<?= escape((string) $pageEnd); ?>
                            of <?= escape((string) $pagination['totalItems']); ?> payments
                        </div>
                        <div class="table-chip-row">
                            <span class="summary-chip">Pending</span>
                            <span class="summary-chip">Paid</span>
                            <span class="summary-chip">Failed</span>
                            <span class="summary-chip">COD</span>
                            <span class="summary-chip">Bank Transfer</span>
                        </div>
                    </div>

                    <div class="table-shell">
                        <table class="products-table fashion-table payment-table">
                            <thead>
                                <tr>
                                    <th>Payment_ID</th>
                                    <th>Order_ID</th>
                                    <th>Customer name</th>
                                    <th>Payment method</th>
                                    <th>Amount</th>
                                    <th>Date</th>
                                    <th>Payment status</th>
                                    <th>Payment proof</th>
                                    <th class="align-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($paymentsData['items'] === []): ?>
                                    <tr>
                                        <td colspan="9" class="empty-row">
                                            No payments matched the current search and filter selection.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($paymentsData['items'] as $payment): ?>
                                        <tr>
                                            <td class="strong-cell">#<?= escape((string) $payment['paymentId']); ?></td>
                                            <td>#<?= escape((string) $payment['orderId']); ?></td>
                                            <td>
                                                <div class="table-primary"><?= escape((string) $payment['customerName']); ?></div>
                                                <div class="table-secondary"><?= escape((string) $payment['customerEmail']); ?></div>
                                            </td>
                                            <td>
                                                <span class="status-chip <?= escape((string) $payment['paymentMethodClassName']); ?>">
                                                    <?= escape((string) $payment['paymentMethodLabel']); ?>
                                                </span>
                                                <div class="table-secondary"><?= escape((string) $payment['paymentMethod']); ?></div>
                                            </td>
                                            <td class="strong-cell">
                                                <?= escape((string) $payment['amountFormatted']); ?>
                                            </td>
                                            <td>
                                                <div class="table-primary"><?= escape(formatAdminOrderDate((string) $payment['paymentDate'])); ?></div>
                                                <div class="table-secondary"><?= escape((string) $payment['paymentDateFormatted']); ?></div>
                                            </td>
                                            <td>
                                                <span class="status-chip <?= escape((string) $payment['paymentStatusClassName']); ?>">
                                                    <?= escape((string) $payment['paymentStatusLabel']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="payment-proof-chip <?= !empty($payment['paymentProofAvailable']) ? 'is-available' : 'is-missing'; ?>">
                                                    <?= !empty($payment['paymentProofAvailable']) ? 'Uploaded' : 'No proof uploaded'; ?>
                                                </span>
                                                <div class="table-secondary">
                                                    <?= !empty($payment['paymentProofAvailable']) ? escape((string) $payment['paymentProofLabel']) : 'Waiting for upload'; ?>
                                                </div>
                                            </td>
                                            <td class="align-right">
                                                <div class="action-row action-row-compact">
                                                    <button
                                                        type="button"
                                                        class="action-button secondary"
                                                        data-payment-detail-trigger
                                                        data-payment-id="<?= escape((string) $payment['paymentId']); ?>">
                                                        View Details
                                                    </button>
                                                    <?php if ($paymentUpdatesEnabled && (string) $payment['paymentMethod'] === 'Bank Transfer'): ?>
                                                        <?php if (!empty($payment['canVerifyBankTransfer'])): ?>
                                                            <button
                                                                type="button"
                                                                class="action-button"
                                                                data-payment-quick-verify-trigger
                                                                data-payment-id="<?= escape((string) $payment['paymentId']); ?>">
                                                                Verify Transfer
                                                            </button>
                                                        <?php else: ?>
                                                            <span class="status-chip status-paid">Verified</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="status-chip status-neutral">COD</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="products-mobile-list">
                        <?php if ($paymentsData['items'] === []): ?>
                            <article class="empty-card">
                                No payments matched the current search and filter selection.
                            </article>
                        <?php else: ?>
                            <?php foreach ($paymentsData['items'] as $payment): ?>
                                <article class="mobile-product-card payment-mobile-card">
                                    <div class="mobile-product-head">
                                        <div class="mobile-product-copy">
                                            <h4>Payment #<?= escape((string) $payment['paymentId']); ?></h4>
                                            <p><?= escape((string) $payment['customerName']); ?> - Order #<?= escape((string) $payment['orderId']); ?></p>
                                        </div>
                                        <span class="status-chip <?= escape((string) $payment['paymentStatusClassName']); ?>">
                                            <?= escape((string) $payment['paymentStatusLabel']); ?>
                                        </span>
                                    </div>

                                    <div class="mobile-product-grid">
                                        <div>
                                            <span>Method</span>
                                            <strong><?= escape((string) $payment['paymentMethodLabel']); ?></strong>
                                        </div>
                                        <div>
                                            <span>Amount</span>
                                            <strong><?= escape((string) $payment['amountFormatted']); ?></strong>
                                        </div>
                                        <div>
                                            <span>Date</span>
                                            <strong><?= escape((string) $payment['paymentDateFormatted']); ?></strong>
                                        </div>
                                        <div>
                                            <span>Proof</span>
                                            <strong><?= !empty($payment['paymentProofAvailable']) ? 'Available' : 'Missing'; ?></strong>
                                        </div>
                                    </div>

                                    <div class="mobile-actions">
                                        <button
                                            type="button"
                                            class="action-button secondary full-width"
                                            data-payment-detail-trigger
                                            data-payment-id="<?= escape((string) $payment['paymentId']); ?>">
                                            View Details
                                        </button>
                                        <?php if ($paymentUpdatesEnabled && (string) $payment['paymentMethod'] === 'Bank Transfer' && !empty($payment['canVerifyBankTransfer'])): ?>
                                            <button
                                                type="button"
                                                class="action-button full-width"
                                                data-payment-quick-verify-trigger
                                                data-payment-id="<?= escape((string) $payment['paymentId']); ?>">
                                                Verify Transfer
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <?php if ((int) $pagination['totalPages'] > 1): ?>
                        <nav class="pagination" aria-label="Payment pagination">
                            <?php
                            $previousPage = max(1, (int) $pagination['currentPage'] - 1);
                            $nextPage = min((int) $pagination['totalPages'], (int) $pagination['currentPage'] + 1);
                            ?>
                            <a
                                class="page-link<?= (int) $pagination['currentPage'] === 1 ? ' is-disabled' : ''; ?>"
                                href="<?= escape(buildAdminPaymentsUrl(array_merge($state, ['page' => $previousPage]))); ?>">
                                Prev
                            </a>

                            <?php for ($pageNumber = 1; $pageNumber <= (int) $pagination['totalPages']; $pageNumber++): ?>
                                <a
                                    class="page-link<?= $pageNumber === (int) $pagination['currentPage'] ? ' is-active' : ''; ?>"
                                    href="<?= escape(buildAdminPaymentsUrl(array_merge($state, ['page' => $pageNumber]))); ?>">
                                    <?= escape((string) $pageNumber); ?>
                                </a>
                            <?php endfor; ?>

                            <a
                                class="page-link<?= (int) $pagination['currentPage'] === (int) $pagination['totalPages'] ? ' is-disabled' : ''; ?>"
                                href="<?= escape(buildAdminPaymentsUrl(array_merge($state, ['page' => $nextPage]))); ?>">
                                Next
                            </a>
                        </nav>
                    <?php endif; ?>
                </section>
            </main>
        </div>
    </div>

    <div class="detail-backdrop" data-detail-overlay hidden></div>
    <aside class="detail-drawer" data-payment-detail-drawer aria-hidden="true">
        <div class="detail-drawer-head">
            <div>
                <span class="dashboard-label">Payment Detail</span>
                <h3 data-payment-detail-title>Choose a payment</h3>
            </div>
            <button type="button" class="modal-close" data-detail-close>Close</button>
        </div>

        <div class="detail-drawer-scroll">
            <div class="detail-body-shell" data-payment-detail-content>
                <div class="detail-empty-state">
                    Select a payment to review customer details, order context, and payment status.
                </div>
            </div>

            <form method="post" class="drawer-form" data-payment-update-form>
                <input type="hidden" name="csrf_token" value="<?= escape(getCsrfToken()); ?>">
                <input type="hidden" name="action" value="manage_payment">
                <input type="hidden" name="payment_id" value="" data-payment-id-input>
                <input type="hidden" name="search" value="<?= escape($state['search']); ?>">
                <input type="hidden" name="method" value="<?= escape($state['method']); ?>">
                <input type="hidden" name="status" value="<?= escape($state['status']); ?>">
                <input type="hidden" name="page" value="<?= escape((string) $state['page']); ?>">

                <div class="field-grid drawer-field-grid">
                    <label class="input-group">
                        <span>Payment status</span>
                        <select name="payment_status" data-payment-status-select<?= !$paymentUpdatesEnabled ? ' disabled' : ''; ?>>
                            <?php foreach (ADMIN_UI_PAYMENT_STATUSES as $statusOption): ?>
                                <option value="<?= escape($statusOption); ?>"><?= escape($statusOption); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="input-group">
                        <span>Payment method</span>
                        <input type="text" value="Select a payment" data-payment-method-display readonly>
                    </label>
                </div>

                <p class="drawer-note">
                    Use Save Status to update payment records. Bank transfer verification is available for bank transfers that have an uploaded proof.
                </p>

                <div class="modal-actions drawer-actions">
                    <button
                        type="submit"
                        class="toolbar-button"
                        name="payment_action"
                        value="save"
                        data-payment-save-button<?= !$paymentUpdatesEnabled ? ' disabled' : ''; ?>>
                        Save Status
                    </button>
                </div>
            </form>
        </div>
    </aside>

    <form method="post" class="is-hidden" data-payment-quick-verify-form>
        <input type="hidden" name="csrf_token" value="<?= escape(getCsrfToken()); ?>">
        <input type="hidden" name="action" value="manage_payment">
        <input type="hidden" name="payment_action" value="verify">
        <input type="hidden" name="payment_id" value="" data-payment-quick-verify-payment-id-input>
        <input type="hidden" name="search" value="<?= escape($state['search']); ?>">
        <input type="hidden" name="method" value="<?= escape($state['method']); ?>">
        <input type="hidden" name="status" value="<?= escape($state['status']); ?>">
        <input type="hidden" name="page" value="<?= escape((string) $state['page']); ?>">
    </form>

    <script type="application/json" id="payment-management-data">
        <?= encodeAdminManagementJson($drawerPayload); ?>
    </script>
    <script src="assets/js/admin-dashboard.js"></script>
    <script src="assets/js/admin-payment-management.js?v=<?= escape($adminPaymentManagementScriptVersion); ?>"></script>
</body>

</html>