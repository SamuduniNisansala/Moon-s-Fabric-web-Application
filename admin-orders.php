<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin-auth.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/admin-order-management.php';

requireAdminAccess();

const ADMIN_ORDERS_PER_PAGE = 10;

function buildAdminOrdersUrl(array $params = []): string
{
    $query = [];

    $search = trim((string) ($params['search'] ?? ''));
    if ($search !== '') {
        $query['search'] = $search;
    }

    $status = trim((string) ($params['status'] ?? ''));
    if ($status !== '' && in_array($status, ADMIN_UI_ORDER_STATUSES, true)) {
        $query['status'] = $status;
    }

    $dateFrom = trim((string) ($params['date_from'] ?? ''));
    if ($dateFrom !== '') {
        $query['date_from'] = $dateFrom;
    }

    $dateTo = trim((string) ($params['date_to'] ?? ''));
    if ($dateTo !== '') {
        $query['date_to'] = $dateTo;
    }

    $page = max(1, (int) ($params['page'] ?? 1));
    if ($page > 1) {
        $query['page'] = $page;
    }

    return 'admin-orders.php' . ($query !== [] ? '?' . http_build_query($query) : '');
}

function normalizeAdminOrdersState(array $source): array
{
    $status = trim((string) ($source['status'] ?? ''));
    if (!in_array($status, ADMIN_UI_ORDER_STATUSES, true)) {
        $status = '';
    }

    $dateFrom = trim((string) ($source['date_from'] ?? ''));
    if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $dateFrom = '';
    }

    $dateTo = trim((string) ($source['date_to'] ?? ''));
    if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $dateTo = '';
    }

    return [
        'search' => trim((string) ($source['search'] ?? '')),
        'status' => $status,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'page' => max(1, (int) ($source['page'] ?? 1)),
    ];
}

function summarizeOrderAddress(string $address, int $limit = 78): string
{
    $normalized = preg_replace('/\s+/', ' ', trim($address));
    if (!is_string($normalized) || $normalized === '') {
        return 'No address available';
    }

    if (mb_strlen($normalized) <= $limit) {
        return $normalized;
    }

    return rtrim(mb_substr($normalized, 0, max(0, $limit - 3))) . '...';
}

function fetchAdminOrderSummary(PDO $pdo): array
{
    $summary = $pdo->query(
        "SELECT
            COUNT(*) AS total_orders,
            COALESCE(SUM(order_status = 'Pending'), 0) AS pending_orders,
            COALESCE(SUM(order_status = 'Confirmed'), 0) AS confirmed_orders,
            COALESCE(SUM(order_status = 'Delivered'), 0) AS delivered_orders,
            COALESCE(SUM(order_status = 'Cancelled'), 0) AS cancelled_orders
        FROM orders"
    )->fetch() ?: [];

    return [
        'totalOrders' => (int) ($summary['total_orders'] ?? 0),
        'pendingOrders' => (int) ($summary['pending_orders'] ?? 0),
        'confirmedOrders' => (int) ($summary['confirmed_orders'] ?? 0),
        'deliveredOrders' => (int) ($summary['delivered_orders'] ?? 0),
        'cancelledOrders' => (int) ($summary['cancelled_orders'] ?? 0),
    ];
}

function fetchPagedAdminOrders(
    PDO $pdo,
    string $search,
    string $status,
    string $dateFrom,
    string $dateTo,
    int $page,
    int $perPage
): array {
    $page = max(1, $page);
    $perPage = max(1, min(20, $perPage));

    $conditions = [];
    $parameters = [];

    if ($search !== '') {
        $conditions[] = '(CAST(o.order_id AS CHAR) LIKE :search_order_id
            OR CAST(o.cus_id AS CHAR) LIKE :search_customer_id
            OR COALESCE(c.name, "") LIKE :search_customer_name
            OR COALESCE(c.email, "") LIKE :search_customer_email
            OR COALESCE(c.phone, "") LIKE :search_customer_phone
            OR o.shopping_address LIKE :search_address)';
        $searchTerm = '%' . $search . '%';
        $parameters['search_order_id'] = $searchTerm;
        $parameters['search_customer_id'] = $searchTerm;
        $parameters['search_customer_name'] = $searchTerm;
        $parameters['search_customer_email'] = $searchTerm;
        $parameters['search_customer_phone'] = $searchTerm;
        $parameters['search_address'] = $searchTerm;
    }

    if ($status !== '') {
        $conditions[] = 'o.order_status = :order_status';
        $parameters['order_status'] = adminOrderDatabaseStatusFromUi($status);
    }

    if ($dateFrom !== '') {
        $conditions[] = 'DATE(o.order_date) >= :date_from';
        $parameters['date_from'] = $dateFrom;
    }

    if ($dateTo !== '') {
        $conditions[] = 'DATE(o.order_date) <= :date_to';
        $parameters['date_to'] = $dateTo;
    }

    $whereSql = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $countStatement = $pdo->prepare(
        "SELECT COUNT(*)
         FROM orders o
         LEFT JOIN customer c
            ON c.cus_id = o.cus_id
         {$whereSql}"
    );

    foreach ($parameters as $name => $value) {
        $countStatement->bindValue(':' . $name, $value, PDO::PARAM_STR);
    }

    $countStatement->execute();
    $totalItems = (int) $countStatement->fetchColumn();
    $totalPages = max(1, (int) ceil($totalItems / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $statement = $pdo->prepare(
        "SELECT
            o.order_id,
            o.cus_id,
            o.order_date,
            o.total_amount,
            o.shopping_address,
            o.order_status,
            COALESCE(c.name, 'Guest customer') AS customer_name,
            COALESCE(c.email, '') AS customer_email,
            COALESCE(c.phone, '') AS customer_phone,
            COALESCE(pay.payment_method, 'COD') AS payment_method,
            COALESCE(pay.amount, 0) AS payment_amount,
            pay.payment_date,
            del.delivery_status,
            del.delivery_date
        FROM orders o
        LEFT JOIN customer c
            ON c.cus_id = o.cus_id
        LEFT JOIN payment pay
            ON pay.payment_id = (
                SELECT p2.payment_id
                FROM payment p2
                WHERE p2.order_id = o.order_id
                ORDER BY p2.payment_date DESC, p2.payment_id DESC
                LIMIT 1
            )
        LEFT JOIN delivery del
            ON del.delivery_id = (
                SELECT d2.delivery_id
                FROM delivery d2
                WHERE d2.order_id = o.order_id
                ORDER BY COALESCE(d2.delivery_date, '0001-01-01') DESC, d2.delivery_id DESC
                LIMIT 1
            )
        {$whereSql}
        ORDER BY o.order_date DESC, o.order_id DESC
        LIMIT :limit OFFSET :offset"
    );

    foreach ($parameters as $name => $value) {
        $statement->bindValue(':' . $name, $value, PDO::PARAM_STR);
    }

    $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();

    $items = [];
    foreach ($statement->fetchAll() ?: [] as $row) {
        $uiStatus = adminOrderUiStatusFromDatabase((string) ($row['order_status'] ?? 'Pending'));
        $paymentStatus = adminOrderPaymentStatusMeta(
            (string) ($row['payment_method'] ?? 'COD'),
            (float) ($row['payment_amount'] ?? 0),
            (float) ($row['total_amount'] ?? 0),
            (string) ($row['order_status'] ?? 'Pending')
        );
        $deliveryStatus = adminDeliveryUiStatusFromDatabase(
            (string) ($row['delivery_status'] ?? ''),
            (string) ($row['order_status'] ?? 'Pending')
        );

        $items[] = [
            'orderId' => (int) ($row['order_id'] ?? 0),
            'customerId' => (int) ($row['cus_id'] ?? 0),
            'customerName' => (string) ($row['customer_name'] ?? 'Guest customer'),
            'customerEmail' => (string) ($row['customer_email'] ?? ''),
            'customerPhone' => (string) ($row['customer_phone'] ?? ''),
            'orderDate' => (string) ($row['order_date'] ?? ''),
            'totalAmount' => (float) ($row['total_amount'] ?? 0),
            'shippingAddress' => (string) ($row['shopping_address'] ?? ''),
            'status' => $uiStatus,
            'statusClassName' => adminOrderStatusClass($uiStatus),
            'paymentMethod' => (string) ($row['payment_method'] ?? 'COD'),
            'paymentStatusLabel' => $paymentStatus['label'],
            'paymentStatusClassName' => $paymentStatus['className'],
            'deliveryStatus' => $deliveryStatus,
            'deliveryStatusClassName' => adminOrderStatusClass($deliveryStatus),
        ];
    }

    return [
        'items' => $items,
        'pagination' => [
            'currentPage' => $page,
            'perPage' => $perPage,
            'totalItems' => $totalItems,
            'totalPages' => $totalPages,
        ],
    ];
}

function updateAdminOrderStatus(PDO $pdo, int $orderId, string $orderStatus, string $deliveryStatus): void
{
    if ($orderId <= 0) {
        throw new RuntimeException('Please choose a valid order.');
    }

    if (!in_array($orderStatus, ADMIN_UI_ORDER_STATUSES, true)) {
        throw new RuntimeException('Please choose a valid order status.');
    }

    if (!in_array($deliveryStatus, ADMIN_UI_DELIVERY_STATUSES, true)) {
        throw new RuntimeException('Please choose a valid delivery status.');
    }

    $orderStatusDb = adminOrderDatabaseStatusFromUi($orderStatus);
    $deliveryStatusDb = adminDeliveryDatabaseStatusFromUi($deliveryStatus);

    $pdo->beginTransaction();

    try {
        $existsStatement = $pdo->prepare(
            'SELECT order_id
             FROM orders
             WHERE order_id = :order_id
             FOR UPDATE'
        );
        $existsStatement->execute(['order_id' => $orderId]);

        if (!$existsStatement->fetch()) {
            throw new RuntimeException('The selected order no longer exists.');
        }

        $orderStatement = $pdo->prepare(
            'UPDATE orders
             SET order_status = :order_status
             WHERE order_id = :order_id'
        );
        $orderStatement->execute([
            'order_status' => $orderStatusDb,
            'order_id' => $orderId,
        ]);

        $deliveryStatement = $pdo->prepare(
            'SELECT delivery_id
             FROM delivery
             WHERE order_id = :order_id
             ORDER BY delivery_id DESC
             LIMIT 1
             FOR UPDATE'
        );
        $deliveryStatement->execute(['order_id' => $orderId]);
        $existingDelivery = $deliveryStatement->fetch();

        $deliveryDate = $deliveryStatusDb === 'Delivered' ? date('Y-m-d') : null;

        if ($existingDelivery) {
            $updateDelivery = $pdo->prepare(
                'UPDATE delivery
                 SET delivery_status = :delivery_status,
                     delivery_date = CASE
                        WHEN :delivery_status = "Delivered" THEN COALESCE(delivery_date, :delivery_date)
                        ELSE delivery_date
                     END
                 WHERE delivery_id = :delivery_id'
            );
            $updateDelivery->execute([
                'delivery_status' => $deliveryStatusDb,
                'delivery_date' => $deliveryDate,
                'delivery_id' => (int) $existingDelivery['delivery_id'],
            ]);
        } else {
            $insertDelivery = $pdo->prepare(
                'INSERT INTO delivery (order_id, delivery_status, delivery_date)
                 VALUES (:order_id, :delivery_status, :delivery_date)'
            );
            $insertDelivery->execute([
                'order_id' => $orderId,
                'delivery_status' => $deliveryStatusDb,
                'delivery_date' => $deliveryDate,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function buildOrderDrawerPayload(array $orderDetails): array
{
    $payload = [];

    foreach ($orderDetails as $orderId => $detail) {
        $items = [];
        foreach ($detail['items'] as $item) {
            $items[] = [
                'orderItemId' => (int) $item['orderItemId'],
                'productId' => (int) $item['productId'],
                'productName' => (string) $item['productName'],
                'brand' => (string) $item['brand'],
                'imageUrl' => (string) $item['imageUrl'],
                'quantity' => (int) $item['quantity'],
                'unitPriceFormatted' => formatAdminOrderCurrency((float) $item['unitPrice']),
                'totalPriceFormatted' => formatAdminOrderCurrency((float) $item['totalPrice']),
            ];
        }

        $payload[(string) $orderId] = [
            'orderId' => (int) $detail['orderId'],
            'customerId' => (int) $detail['customerId'],
            'customerName' => (string) $detail['customerName'],
            'customerEmail' => (string) $detail['customerEmail'],
            'customerPhone' => (string) $detail['customerPhone'],
            'orderDateFormatted' => formatAdminOrderDateTime((string) $detail['orderDate']),
            'shippingAddress' => (string) $detail['shippingAddress'],
            'totalAmountFormatted' => formatAdminOrderCurrency((float) $detail['totalAmount']),
            'status' => (string) $detail['status'],
            'statusClassName' => (string) $detail['statusClassName'],
            'paymentMethod' => (string) $detail['paymentMethod'],
            'paymentAmountFormatted' => formatAdminOrderCurrency((float) $detail['paymentAmount']),
            'paymentDateFormatted' => $detail['paymentDate'] !== ''
                ? formatAdminOrderDateTime((string) $detail['paymentDate'])
                : 'Not recorded',
            'paymentStatusLabel' => (string) $detail['paymentStatusLabel'],
            'paymentStatusClassName' => (string) $detail['paymentStatusClassName'],
            'deliveryStatus' => (string) $detail['deliveryStatus'],
            'deliveryStatusInput' => (string) $detail['deliveryStatusInput'],
            'deliveryStatusClassName' => (string) $detail['deliveryStatusClassName'],
            'deliveryDateFormatted' => $detail['deliveryDate'] !== ''
                ? formatAdminOrderDate((string) $detail['deliveryDate'])
                : 'Not scheduled',
            'courierName' => (string) $detail['courierName'],
            'trackingId' => (string) $detail['trackingId'],
            'invoiceUrl' => 'admin-order-invoice.php?order_id=' . urlencode((string) $detail['orderId']),
            'items' => $items,
        ];
    }

    return $payload;
}

$stateSource = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$state = normalizeAdminOrdersState(is_array($stateSource) ? $stateSource : []);
$pageError = pullFlashMessage('orders_error') ?? '';
$successMessage = pullFlashMessage('orders_success') ?? '';
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

        if ($action === 'update_order_status') {
            try {
                updateAdminOrderStatus(
                    $pdo,
                    (int) ($_POST['order_id'] ?? 0),
                    trim((string) ($_POST['order_status'] ?? '')),
                    trim((string) ($_POST['delivery_status'] ?? ''))
                );

                setFlashMessage('orders_success', 'Order status updated successfully.');
                header('Location: ' . buildAdminOrdersUrl($state));
                exit;
            } catch (Throwable $exception) {
                $pageError = $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'We could not update that order right now. Please try again.';
            }
        }
    }
}

$summary = [
    'totalOrders' => 0,
    'pendingOrders' => 0,
    'confirmedOrders' => 0,
    'deliveredOrders' => 0,
    'cancelledOrders' => 0,
];
$ordersData = [
    'items' => [],
    'pagination' => [
        'currentPage' => 1,
        'perPage' => ADMIN_ORDERS_PER_PAGE,
        'totalItems' => 0,
        'totalPages' => 1,
    ],
];
$drawerPayload = [];

if ($pdo instanceof PDO) {
    try {
        $summary = fetchAdminOrderSummary($pdo);
        $ordersData = fetchPagedAdminOrders(
            $pdo,
            $state['search'],
            $state['status'],
            $state['date_from'],
            $state['date_to'],
            $state['page'],
            ADMIN_ORDERS_PER_PAGE
        );

        $currentOrderIds = array_map(
            static fn(array $order): int => (int) $order['orderId'],
            $ordersData['items']
        );
        $drawerPayload = buildOrderDrawerPayload(fetchAdminOrderDetailsByIds($pdo, $currentOrderIds));
        $state['page'] = (int) $ordersData['pagination']['currentPage'];
    } catch (Throwable) {
        $pageError = $pageError !== ''
            ? $pageError
            : 'Unable to load order management data from the database.';
    }
}

$pagination = $ordersData['pagination'];
$pageStart = $pagination['totalItems'] > 0
    ? (($pagination['currentPage'] - 1) * $pagination['perPage']) + 1
    : 0;
$pageEnd = $pagination['totalItems'] > 0
    ? min($pagination['totalItems'], $pagination['currentPage'] * $pagination['perPage'])
    : 0;
$menuItems = buildAdminManagementMenu('orders');
$adminProductsStylesheetVersion = (string) filemtime(__DIR__ . '/assets/css/admin-products.css');
$adminOrderManagementScriptVersion = (string) filemtime(__DIR__ . '/assets/js/admin-order-management.js');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Management | MOON s Fabric Shop</title>
    <link rel="stylesheet" href="assets/css/admin-products.css?v=<?= escape($adminProductsStylesheetVersion); ?>">
</head>

<body class="products-body admin-orders-body">
    <button class="sidebar-overlay" data-sidebar-overlay hidden aria-label="Close menu"></button>

    <div class="dashboard-app">
        <aside class="dashboard-sidebar" data-dashboard-sidebar>
            <div class="sidebar-brand">
                <div>
                    <span class="sidebar-eyebrow">MOON s Fabric Shop</span>
                    <h1>Order Admin</h1>
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
                        <span class="dashboard-label">Order Management</span>
                        <h2>Manage every customer order from one place</h2>
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

                <section class="products-card order-hero-card">
                    <div class="section-head">
                        <div class="section-copy">
                            <span class="dashboard-label">Owner Workspace</span>
                            <h3>Premium order flow control</h3>
                        </div>
                        <div class="hero-tag-stack">
                            <span class="hero-pill">Live order tracking</span>
                            <span class="hero-pill">Pink fashion admin UI</span>
                        </div>
                    </div>
                </section>

                <section class="metric-grid order-metric-grid">
                    <article class="metric-card tone-blue">
                        <span class="metric-title">Total Orders</span>
                        <strong class="metric-value"><?= escape(number_format($summary['totalOrders'])); ?></strong>
                    </article>
                    <article class="metric-card tone-sky">
                        <span class="metric-title">Pending Orders</span>
                        <strong class="metric-value"><?= escape(number_format($summary['pendingOrders'])); ?></strong>
                    </article>
                    <article class="metric-card tone-slate">
                        <span class="metric-title">Confirmed Orders</span>
                        <strong class="metric-value"><?= escape(number_format($summary['confirmedOrders'])); ?></strong>
                    </article>
                    <article class="metric-card tone-amber">
                        <span class="metric-title">Delivered Orders</span>
                        <strong class="metric-value"><?= escape(number_format($summary['deliveredOrders'])); ?></strong>
                    </article>
                    <article class="metric-card tone-rose">
                        <span class="metric-title">Cancelled Orders</span>
                        <strong class="metric-value"><?= escape(number_format($summary['cancelledOrders'])); ?></strong>
                    </article>
                </section>

                <section class="products-card products-card-wide">
                    <div class="section-head">
                        <div class="section-copy">
                            <span class="dashboard-label">Orders Table</span>
                            <h3>Search, filter, and review active customer orders</h3>
                        </div>
                        <div class="toolbar-actions">
                            <a class="secondary-button" href="admin-order-items.php">Open order items</a>
                        </div>
                    </div>

                    <form method="get" class="filters-form">
                        <div class="filter-grid filter-grid-orders">
                            <label class="input-group">
                                <span>Search orders</span>
                                <input
                                    type="search"
                                    name="search"
                                    value="<?= escape($state['search']); ?>"
                                    placeholder="Order ID, customer name, email, phone, or address">
                            </label>

                            <label class="input-group">
                                <span>Filter by status</span>
                                <select name="status">
                                    <option value="">All statuses</option>
                                    <?php foreach (ADMIN_UI_ORDER_STATUSES as $statusOption): ?>
                                        <option value="<?= escape($statusOption); ?>" <?= $state['status'] === $statusOption ? 'selected' : ''; ?>>
                                            <?= escape($statusOption); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label class="input-group">
                                <span>From date</span>
                                <input type="date" name="date_from" value="<?= escape($state['date_from']); ?>">
                            </label>

                            <label class="input-group">
                                <span>To date</span>
                                <input type="date" name="date_to" value="<?= escape($state['date_to']); ?>">
                            </label>

                            <div class="filter-actions">
                                <button type="submit" class="toolbar-button">Apply Filters</button>
                                <a href="admin-orders.php" class="toolbar-link">Reset</a>
                            </div>
                        </div>
                    </form>

                    <div class="list-meta">
                        <div class="list-meta-copy">
                            Showing <?= escape((string) $pageStart); ?>-<?= escape((string) $pageEnd); ?>
                            of <?= escape((string) $pagination['totalItems']); ?> orders
                        </div>
                        <div class="table-chip-row">
                            <span class="summary-chip">Pending</span>
                            <span class="summary-chip">Confirmed</span>
                            <span class="summary-chip">Processing</span>
                            <span class="summary-chip">Delivered</span>
                            <span class="summary-chip">Cancelled</span>
                        </div>
                    </div>

                    <div class="table-shell order-table-shell">
                        <table class="products-table fashion-table orders-table">
                            <thead>
                                <tr>
                                    <th>Order ID</th>
                                    <th>Customer Name</th>
                                    <th>Cus ID</th>
                                    <th>Order Date</th>
                                    <th>Shopping Address</th>
                                    <th>Total Amount</th>
                                    <th>Order Status</th>
                                    <th>Payment Status</th>
                                    <th>Delivery Status</th>
                                    <th class="align-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($ordersData['items'] === []): ?>
                                    <tr>
                                        <td colspan="10" class="empty-row">
                                            No orders matched the current search and filter selection.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($ordersData['items'] as $order): ?>
                                        <tr>
                                            <td class="strong-cell">#<?= escape((string) $order['orderId']); ?></td>
                                            <td>
                                                <div class="table-primary"><?= escape((string) $order['customerName']); ?></div>
                                                <div class="table-secondary"><?= escape((string) $order['customerEmail']); ?></div>
                                            </td>
                                            <td>#<?= escape((string) $order['customerId']); ?></td>
                                            <td>
                                                <div class="table-primary"><?= escape(formatAdminOrderDate((string) $order['orderDate'])); ?></div>
                                                <div class="table-secondary"><?= escape(formatAdminOrderDateTime((string) $order['orderDate'])); ?></div>
                                            </td>
                                            <td>
                                                <div class="table-primary order-address-preview">
                                                    <?= escape(summarizeOrderAddress((string) $order['shippingAddress'])); ?>
                                                </div>
                                                <div class="table-secondary"><?= escape((string) $order['customerPhone']); ?></div>
                                            </td>
                                            <td class="strong-cell">
                                                <?= escape(formatAdminOrderCurrency((float) $order['totalAmount'])); ?>
                                            </td>
                                            <td>
                                                <span class="status-chip <?= escape((string) $order['statusClassName']); ?>">
                                                    <?= escape((string) $order['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-chip <?= escape((string) $order['paymentStatusClassName']); ?>">
                                                    <?= escape((string) $order['paymentStatusLabel']); ?>
                                                </span>
                                                <div class="table-secondary"><?= escape((string) $order['paymentMethod']); ?></div>
                                            </td>
                                            <td>
                                                <span class="status-chip <?= escape((string) $order['deliveryStatusClassName']); ?>">
                                                    <?= escape((string) $order['deliveryStatus']); ?>
                                                </span>
                                            </td>
                                            <td class="align-right">
                                                <div class="action-row action-row-compact">
                                                    <button
                                                        type="button"
                                                        class="action-button secondary"
                                                        data-order-detail-trigger
                                                        data-order-id="<?= escape((string) $order['orderId']); ?>">
                                                        View Details
                                                    </button>
                                                    <a
                                                        class="action-button"
                                                        href="admin-order-invoice.php?order_id=<?= escape((string) $order['orderId']); ?>"
                                                        target="_blank"
                                                        rel="noopener">
                                                        Print Invoice
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="products-mobile-list order-mobile-list">
                        <?php if ($ordersData['items'] === []): ?>
                            <article class="empty-card">
                                No orders matched the current search and filter selection.
                            </article>
                        <?php else: ?>
                            <?php foreach ($ordersData['items'] as $order): ?>
                                <article class="mobile-product-card order-mobile-card">
                                    <div class="mobile-product-head">
                                        <div class="mobile-product-copy">
                                            <h4>Order #<?= escape((string) $order['orderId']); ?></h4>
                                            <p><?= escape((string) $order['customerName']); ?> - #<?= escape((string) $order['customerId']); ?></p>
                                        </div>
                                        <span class="status-chip <?= escape((string) $order['statusClassName']); ?>">
                                            <?= escape((string) $order['status']); ?>
                                        </span>
                                    </div>

                                    <div class="mobile-product-grid">
                                        <div>
                                            <span>Order date</span>
                                            <strong><?= escape(formatAdminOrderDate((string) $order['orderDate'])); ?></strong>
                                        </div>
                                        <div>
                                            <span>Total</span>
                                            <strong><?= escape(formatAdminOrderCurrency((float) $order['totalAmount'])); ?></strong>
                                        </div>
                                        <div>
                                            <span>Payment</span>
                                            <strong><?= escape((string) $order['paymentStatusLabel']); ?></strong>
                                        </div>
                                        <div>
                                            <span>Delivery</span>
                                            <strong><?= escape((string) $order['deliveryStatus']); ?></strong>
                                        </div>
                                    </div>

                                    <p class="mobile-order-address"><?= escape(summarizeOrderAddress((string) $order['shippingAddress'], 120)); ?></p>

                                    <div class="mobile-actions">
                                        <button
                                            type="button"
                                            class="action-button secondary full-width"
                                            data-order-detail-trigger
                                            data-order-id="<?= escape((string) $order['orderId']); ?>">
                                            View Details
                                        </button>
                                        <a
                                            class="action-button full-width"
                                            href="admin-order-invoice.php?order_id=<?= escape((string) $order['orderId']); ?>"
                                            target="_blank"
                                            rel="noopener">
                                            Print Invoice
                                        </a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <?php if ((int) $pagination['totalPages'] > 1): ?>
                        <nav class="pagination" aria-label="Order pagination">
                            <?php
                            $previousPage = max(1, (int) $pagination['currentPage'] - 1);
                            $nextPage = min((int) $pagination['totalPages'], (int) $pagination['currentPage'] + 1);
                            ?>
                            <a
                                class="page-link<?= (int) $pagination['currentPage'] === 1 ? ' is-disabled' : ''; ?>"
                                href="<?= escape(buildAdminOrdersUrl(array_merge($state, ['page' => $previousPage]))); ?>">
                                Prev
                            </a>

                            <?php for ($pageNumber = 1; $pageNumber <= (int) $pagination['totalPages']; $pageNumber++): ?>
                                <a
                                    class="page-link<?= $pageNumber === (int) $pagination['currentPage'] ? ' is-active' : ''; ?>"
                                    href="<?= escape(buildAdminOrdersUrl(array_merge($state, ['page' => $pageNumber]))); ?>">
                                    <?= escape((string) $pageNumber); ?>
                                </a>
                            <?php endfor; ?>

                            <a
                                class="page-link<?= (int) $pagination['currentPage'] === (int) $pagination['totalPages'] ? ' is-disabled' : ''; ?>"
                                href="<?= escape(buildAdminOrdersUrl(array_merge($state, ['page' => $nextPage]))); ?>">
                                Next
                            </a>
                        </nav>
                    <?php endif; ?>
                </section>
            </main>
        </div>
    </div>

    <div class="detail-backdrop" data-detail-overlay hidden></div>
    <aside class="detail-drawer" data-order-detail-drawer aria-hidden="true">
        <div class="detail-drawer-head">
            <div>
                <span class="dashboard-label">Order Detail</span>
                <h3 data-order-detail-title>Choose an order</h3>
            </div>
            <button type="button" class="modal-close" data-detail-close>Close</button>
        </div>

        <div class="detail-drawer-scroll">
            <div class="detail-body-shell" data-order-detail-content>
                <div class="detail-empty-state">
                    Select an order to review customer details, order items, payment status, and delivery progress.
                </div>
            </div>

            <form method="post" class="drawer-form">
                <input type="hidden" name="csrf_token" value="<?= escape(getCsrfToken()); ?>">
                <input type="hidden" name="action" value="update_order_status">
                <input type="hidden" name="order_id" value="" data-order-id-input>
                <input type="hidden" name="search" value="<?= escape($state['search']); ?>">
                <input type="hidden" name="status" value="<?= escape($state['status']); ?>">
                <input type="hidden" name="date_from" value="<?= escape($state['date_from']); ?>">
                <input type="hidden" name="date_to" value="<?= escape($state['date_to']); ?>">
                <input type="hidden" name="page" value="<?= escape((string) $state['page']); ?>">

                <div class="field-grid drawer-field-grid">
                    <label class="input-group">
                        <span>Order status</span>
                        <select name="order_status" data-order-status-select disabled>
                            <?php foreach (ADMIN_UI_ORDER_STATUSES as $statusOption): ?>
                                <option value="<?= escape($statusOption); ?>"><?= escape($statusOption); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="input-group">
                        <span>Delivery status</span>
                        <select name="delivery_status" data-delivery-status-select disabled>
                            <?php foreach (ADMIN_UI_DELIVERY_STATUSES as $statusOption): ?>
                                <option value="<?= escape($statusOption); ?>"><?= escape($statusOption); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <p class="drawer-note">
                    Payment status is calculated from the current payment records. Save the form to update the order and delivery progress.
                </p>

                <div class="modal-actions drawer-actions">
                    <a href="admin-orders.php" class="secondary-button" data-order-print-link target="_blank" rel="noopener">
                        Print Invoice
                    </a>
                    <button type="submit" class="toolbar-button" data-order-save-button disabled>
                        Save Status
                    </button>
                </div>
            </form>
        </div>
    </aside>

    <script type="application/json" id="order-management-data">
        <?= encodeAdminManagementJson($drawerPayload); ?>
    </script>
    <script src="assets/js/admin-dashboard.js"></script>
    <script src="assets/js/admin-order-management.js?v=<?= escape($adminOrderManagementScriptVersion); ?>"></script>
</body>

</html>