<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin-auth.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/admin-delivery-management.php';

requireAdminAccess();

const ADMIN_DELIVERIES_PER_PAGE = 10;

$stateSource = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$state = normalizeAdminDeliveriesState(is_array($stateSource) ? $stateSource : []);
$pageError = pullFlashMessage('deliveries_error') ?? '';
$successMessage = pullFlashMessage('deliveries_success') ?? '';
$adminName = (string) ($_SESSION['admin_name'] ?? 'Admin');
$lastLogin = (string) ($_SESSION['admin_last_login'] ?? date('Y-m-d H:i:s'));

try {
    $pdo = getDatabaseConnection();
} catch (Throwable) {
    $pdo = null;
    $pageError = 'Unable to connect to the database right now.';
}

$deliveryUpdatesEnabled = $pdo instanceof PDO && adminDeliveryStorageWritable();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
    if (!isValidCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        $pageError = 'Your session token has expired. Please refresh the page and try again.';
    } else {
        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action === 'manage_delivery') {
            try {
                if (!$deliveryUpdatesEnabled) {
                    throw new RuntimeException('Delivery updates are unavailable because storage is not writable.');
                }

                updateAdminDeliveryRecord(
                    $pdo,
                    (int) ($_POST['order_id'] ?? 0),
                    (string) ($_POST['courier_name'] ?? ''),
                    (string) ($_POST['tracking_id'] ?? ''),
                    (string) ($_POST['delivery_date'] ?? ''),
                    (string) ($_POST['delivery_status'] ?? '')
                );

                setFlashMessage('deliveries_success', 'Delivery details updated successfully.');
                header('Location: ' . buildAdminDeliveriesUrl($state));
                exit;
            } catch (Throwable $exception) {
                $pageError = $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'We could not update that delivery right now. Please try again.';
            }
        }
    }
}

$summary = [
    'totalDeliveries' => 0,
    'pendingDeliveries' => 0,
    'shippedOrders' => 0,
    'deliveredOrders' => 0,
    'cancelledDeliveries' => 0,
];
$deliveriesData = [
    'items' => [],
    'pagination' => [
        'currentPage' => 1,
        'perPage' => ADMIN_DELIVERIES_PER_PAGE,
        'totalItems' => 0,
        'totalPages' => 1,
    ],
];
$drawerPayload = [];

if ($pdo instanceof PDO) {
    try {
        $summary = fetchAdminDeliverySummary($pdo);
        $deliveriesData = fetchPagedAdminDeliveries(
            $pdo,
            $state['search'],
            $state['status'],
            $state['page'],
            ADMIN_DELIVERIES_PER_PAGE
        );

        $drawerPayload = buildAdminDeliveryDrawerPayload($deliveriesData['items'], $deliveryUpdatesEnabled);
        $state['page'] = (int) $deliveriesData['pagination']['currentPage'];
    } catch (Throwable) {
        $pageError = $pageError !== ''
            ? $pageError
            : 'Unable to load delivery management data from the database.';
    }
}

$pagination = $deliveriesData['pagination'];
$pageStart = $pagination['totalItems'] > 0
    ? (($pagination['currentPage'] - 1) * $pagination['perPage']) + 1
    : 0;
$pageEnd = $pagination['totalItems'] > 0
    ? min($pagination['totalItems'], $pagination['currentPage'] * $pagination['perPage'])
    : 0;
$menuItems = buildAdminManagementMenu('deliveries');
$adminProductsStylesheetVersion = is_file(__DIR__ . '/assets/css/admin-products.css')
    ? (string) filemtime(__DIR__ . '/assets/css/admin-products.css')
    : '1';
$adminDeliveriesStylesheetVersion = is_file(__DIR__ . '/assets/css/admin-deliveries.css')
    ? (string) filemtime(__DIR__ . '/assets/css/admin-deliveries.css')
    : '1';
$adminDeliveryScriptVersion = is_file(__DIR__ . '/assets/js/admin-delivery-management.js')
    ? (string) filemtime(__DIR__ . '/assets/js/admin-delivery-management.js')
    : '1';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Management | MOON s Fabric Shop</title>
    <link rel="stylesheet" href="assets/css/admin-products.css?v=<?= escape($adminProductsStylesheetVersion); ?>">
    <link rel="stylesheet" href="assets/css/admin-deliveries.css?v=<?= escape($adminDeliveriesStylesheetVersion); ?>">
</head>

<body class="products-body admin-deliveries-body">
    <button class="sidebar-overlay" data-sidebar-overlay hidden aria-label="Close menu"></button>

    <div class="dashboard-app">
        <aside class="dashboard-sidebar" data-dashboard-sidebar>
            <div class="sidebar-brand">
                <div>
                    <span class="sidebar-eyebrow">MOON s Fabric Shop</span>
                    <h1>Delivery Admin</h1>
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
                        <span class="dashboard-label">Delivery Management</span>
                        <h2>Manage courier details, tracking IDs, and delivery progress</h2>
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

                <section class="products-card delivery-hero-card">
                    <div class="section-head">
                        <div class="section-copy">
                            <span class="dashboard-label">Logistics Control</span>
                            <h3>Track every order from packing desk to doorstep</h3>
                        </div>
                        <div class="hero-tag-stack">
                            <span class="hero-pill">Courier details</span>
                            <span class="hero-pill">Tracking badges</span>
                            <span class="hero-pill">Status timeline</span>
                        </div>
                    </div>
                </section>

                <section class="metric-grid delivery-metric-grid">
                    <article class="metric-card tone-blue">
                        <span class="metric-title">Total Deliveries</span>
                        <strong class="metric-value"><?= escape(number_format($summary['totalDeliveries'])); ?></strong>
                    </article>
                    <article class="metric-card tone-slate">
                        <span class="metric-title">Pending Deliveries</span>
                        <strong class="metric-value"><?= escape(number_format($summary['pendingDeliveries'])); ?></strong>
                    </article>
                    <article class="metric-card tone-sky">
                        <span class="metric-title">Shipped Orders</span>
                        <strong class="metric-value"><?= escape(number_format($summary['shippedOrders'])); ?></strong>
                    </article>
                    <article class="metric-card tone-rose">
                        <span class="metric-title">Delivered Orders</span>
                        <strong class="metric-value"><?= escape(number_format($summary['deliveredOrders'])); ?></strong>
                    </article>
                    <article class="metric-card tone-amber">
                        <span class="metric-title">Cancelled Deliveries</span>
                        <strong class="metric-value"><?= escape(number_format($summary['cancelledDeliveries'])); ?></strong>
                    </article>
                </section>

                <section class="products-card products-card-wide">
                    <div class="section-head">
                        <div class="section-copy">
                            <span class="dashboard-label">Delivery Table</span>
                            <h3>Search, filter, and update delivery records</h3>
                        </div>
                        <div class="toolbar-actions">
                            <a class="secondary-button" href="admin-orders.php">Open orders</a>
                        </div>
                    </div>

                    <form method="get" class="filters-form">
                        <div class="filter-grid">
                            <label class="input-group">
                                <span>Search deliveries</span>
                                <input
                                    type="search"
                                    name="search"
                                    value="<?= escape($state['search']); ?>"
                                    placeholder="Delivery ID, order ID, customer, courier, or tracking ID">
                            </label>

                            <label class="input-group">
                                <span>Filter by status</span>
                                <select name="status">
                                    <option value="">All statuses</option>
                                    <?php foreach (ADMIN_DELIVERY_MANAGEMENT_STATUSES as $statusOption): ?>
                                        <option value="<?= escape($statusOption); ?>" <?= $state['status'] === $statusOption ? 'selected' : ''; ?>>
                                            <?= escape($statusOption); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <div class="filter-actions">
                                <button type="submit" class="toolbar-button">Apply Filters</button>
                                <a href="admin-deliveries.php" class="toolbar-link">Reset</a>
                            </div>
                        </div>
                    </form>

                    <div class="list-meta">
                        <div class="list-meta-copy">
                            Showing <?= escape((string) $pageStart); ?>-<?= escape((string) $pageEnd); ?>
                            of <?= escape((string) $pagination['totalItems']); ?> deliveries
                        </div>
                        <div class="table-chip-row">
                            <?php foreach (ADMIN_DELIVERY_MANAGEMENT_STATUSES as $statusOption): ?>
                                <span class="summary-chip"><?= escape($statusOption); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="table-shell">
                        <table class="products-table fashion-table delivery-table">
                            <thead>
                                <tr>
                                    <th>Delivery_ID</th>
                                    <th>Order_ID</th>
                                    <th>Customer name</th>
                                    <th>Courier name</th>
                                    <th>Tracking_ID</th>
                                    <th>Delivery date</th>
                                    <th>Delivery status</th>
                                    <th class="align-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($deliveriesData['items'] === []): ?>
                                    <tr>
                                        <td colspan="8" class="empty-row">
                                            No deliveries matched the current search and filter selection.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($deliveriesData['items'] as $delivery): ?>
                                        <tr>
                                            <td class="strong-cell">#<?= escape((string) $delivery['deliveryIdDisplay']); ?></td>
                                            <td>#<?= escape((string) $delivery['orderId']); ?></td>
                                            <td>
                                                <div class="table-primary"><?= escape((string) $delivery['customerName']); ?></div>
                                                <div class="table-secondary"><?= escape((string) $delivery['customerEmail']); ?></div>
                                            </td>
                                            <td>
                                                <div class="table-primary"><?= escape((string) ($delivery['courierName'] !== '' ? $delivery['courierName'] : 'Not assigned')); ?></div>
                                                <div class="table-secondary"><?= escape((string) $delivery['customerPhone']); ?></div>
                                            </td>
                                            <td>
                                                <span class="tracking-badge <?= $delivery['trackingId'] !== '' ? 'is-active' : 'is-empty'; ?>">
                                                    <?= escape((string) $delivery['trackingBadgeLabel']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="table-primary"><?= escape((string) $delivery['deliveryDateFormatted']); ?></div>
                                                <div class="table-secondary"><?= escape((string) $delivery['orderDateFormatted']); ?></div>
                                            </td>
                                            <td>
                                                <div class="timeline-inline">
                                                    <span class="timeline-dot <?= escape((string) $delivery['deliveryStatusClassName']); ?>"></span>
                                                    <span class="status-chip <?= escape((string) $delivery['deliveryStatusClassName']); ?>">
                                                        <?= escape((string) $delivery['deliveryStatusLabel']); ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="align-right">
                                                <div class="action-row action-row-compact">
                                                    <button
                                                        type="button"
                                                        class="action-button secondary"
                                                        data-delivery-detail-trigger
                                                        data-order-id="<?= escape((string) $delivery['orderId']); ?>">
                                                        View Details
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="action-button"
                                                        data-delivery-edit-trigger
                                                        data-order-id="<?= escape((string) $delivery['orderId']); ?>">
                                                        Update
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="products-mobile-list">
                        <?php if ($deliveriesData['items'] === []): ?>
                            <article class="empty-card">
                                No deliveries matched the current search and filter selection.
                            </article>
                        <?php else: ?>
                            <?php foreach ($deliveriesData['items'] as $delivery): ?>
                                <article class="mobile-product-card delivery-mobile-card">
                                    <div class="mobile-product-head">
                                        <div class="mobile-product-copy">
                                            <h4>Delivery #<?= escape((string) $delivery['deliveryIdDisplay']); ?></h4>
                                            <p><?= escape((string) $delivery['customerName']); ?> - Order #<?= escape((string) $delivery['orderId']); ?></p>
                                        </div>
                                        <span class="status-chip <?= escape((string) $delivery['deliveryStatusClassName']); ?>">
                                            <?= escape((string) $delivery['deliveryStatusLabel']); ?>
                                        </span>
                                    </div>

                                    <div class="mobile-product-grid">
                                        <div>
                                            <span>Courier</span>
                                            <strong><?= escape((string) ($delivery['courierName'] !== '' ? $delivery['courierName'] : 'Not assigned')); ?></strong>
                                        </div>
                                        <div>
                                            <span>Tracking</span>
                                            <strong><?= escape((string) $delivery['trackingBadgeLabel']); ?></strong>
                                        </div>
                                        <div>
                                            <span>Date</span>
                                            <strong><?= escape((string) $delivery['deliveryDateFormatted']); ?></strong>
                                        </div>
                                        <div>
                                            <span>Order</span>
                                            <strong>#<?= escape((string) $delivery['orderId']); ?></strong>
                                        </div>
                                    </div>

                                    <div class="mobile-actions">
                                        <button
                                            type="button"
                                            class="action-button secondary full-width"
                                            data-delivery-detail-trigger
                                            data-order-id="<?= escape((string) $delivery['orderId']); ?>">
                                            View Details
                                        </button>
                                        <button
                                            type="button"
                                            class="action-button full-width"
                                            data-delivery-edit-trigger
                                            data-order-id="<?= escape((string) $delivery['orderId']); ?>">
                                            Update Delivery
                                        </button>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <?php if ((int) $pagination['totalPages'] > 1): ?>
                        <nav class="pagination" aria-label="Delivery pagination">
                            <?php
                            $previousPage = max(1, (int) $pagination['currentPage'] - 1);
                            $nextPage = min((int) $pagination['totalPages'], (int) $pagination['currentPage'] + 1);
                            ?>
                            <a
                                class="page-link<?= (int) $pagination['currentPage'] === 1 ? ' is-disabled' : ''; ?>"
                                href="<?= escape(buildAdminDeliveriesUrl(array_merge($state, ['page' => $previousPage]))); ?>">
                                Prev
                            </a>

                            <?php for ($pageNumber = 1; $pageNumber <= (int) $pagination['totalPages']; $pageNumber++): ?>
                                <a
                                    class="page-link<?= $pageNumber === (int) $pagination['currentPage'] ? ' is-active' : ''; ?>"
                                    href="<?= escape(buildAdminDeliveriesUrl(array_merge($state, ['page' => $pageNumber]))); ?>">
                                    <?= escape((string) $pageNumber); ?>
                                </a>
                            <?php endfor; ?>

                            <a
                                class="page-link<?= (int) $pagination['currentPage'] === (int) $pagination['totalPages'] ? ' is-disabled' : ''; ?>"
                                href="<?= escape(buildAdminDeliveriesUrl(array_merge($state, ['page' => $nextPage]))); ?>">
                                Next
                            </a>
                        </nav>
                    <?php endif; ?>
                </section>
            </main>
        </div>
    </div>

    <div class="detail-backdrop" data-detail-overlay hidden></div>
    <aside class="detail-drawer" data-delivery-detail-drawer aria-hidden="true">
        <div class="detail-drawer-head">
            <div>
                <span class="dashboard-label">Delivery Detail</span>
                <h3 data-delivery-detail-title>Choose a delivery</h3>
            </div>
            <button type="button" class="modal-close" data-detail-close>Close</button>
        </div>

        <div class="detail-drawer-scroll">
            <div class="detail-body-shell" data-delivery-detail-content>
                <div class="detail-empty-state">
                    Select a delivery to review courier, tracking, customer, and timeline details.
                </div>
            </div>

            <form method="post" class="drawer-form" data-delivery-update-form>
                <input type="hidden" name="csrf_token" value="<?= escape(getCsrfToken()); ?>">
                <input type="hidden" name="action" value="manage_delivery">
                <input type="hidden" name="order_id" value="" data-delivery-order-id-input>
                <input type="hidden" name="search" value="<?= escape($state['search']); ?>">
                <input type="hidden" name="status" value="<?= escape($state['status']); ?>">
                <input type="hidden" name="page" value="<?= escape((string) $state['page']); ?>">

                <div class="field-grid drawer-field-grid">
                    <label class="input-group">
                        <span>Courier name</span>
                        <input type="text" name="courier_name" value="" data-delivery-courier-input placeholder="Example: Prompt Xpress" <?= !$deliveryUpdatesEnabled ? 'disabled' : ''; ?>>
                    </label>

                    <label class="input-group">
                        <span>Tracking ID</span>
                        <input type="text" name="tracking_id" value="" data-delivery-tracking-input placeholder="Tracking reference" <?= !$deliveryUpdatesEnabled ? 'disabled' : ''; ?>>
                    </label>

                    <label class="input-group">
                        <span>Delivery date</span>
                        <input type="date" name="delivery_date" value="" data-delivery-date-input <?= !$deliveryUpdatesEnabled ? 'disabled' : ''; ?>>
                    </label>

                    <label class="input-group">
                        <span>Delivery status</span>
                        <select name="delivery_status" data-delivery-status-select<?= !$deliveryUpdatesEnabled ? ' disabled' : ''; ?>>
                            <?php foreach (ADMIN_DELIVERY_MANAGEMENT_STATUSES as $statusOption): ?>
                                <option value="<?= escape($statusOption); ?>"><?= escape($statusOption); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <p class="drawer-note">
                    Add courier details, tracking ID, delivery date, and update the shipment status from this panel.
                </p>

                <div class="modal-actions drawer-actions">
                    <button
                        type="submit"
                        class="toolbar-button"
                        data-delivery-save-button<?= !$deliveryUpdatesEnabled ? ' disabled' : ''; ?>>
                        Save Delivery
                    </button>
                </div>
            </form>
        </div>
    </aside>

    <script type="application/json" id="delivery-management-data">
        <?= encodeAdminManagementJson($drawerPayload); ?>
    </script>
    <script src="assets/js/admin-dashboard.js"></script>
    <script src="assets/js/admin-delivery-management.js?v=<?= escape($adminDeliveryScriptVersion); ?>"></script>
</body>

</html>