<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin-auth.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/admin-order-management.php';

requireAdminAccess();

const ADMIN_ORDER_ITEMS_PER_PAGE = 12;

function buildAdminOrderItemsUrl(array $params = []): string
{
    $query = [];

    $search = trim((string) ($params['search'] ?? ''));
    if ($search !== '') {
        $query['search'] = $search;
    }

    $orderId = max(0, (int) ($params['order_id'] ?? 0));
    if ($orderId > 0) {
        $query['order_id'] = $orderId;
    }

    $productId = max(0, (int) ($params['product_id'] ?? 0));
    if ($productId > 0) {
        $query['product_id'] = $productId;
    }

    $page = max(1, (int) ($params['page'] ?? 1));
    if ($page > 1) {
        $query['page'] = $page;
    }

    return 'admin-order-items.php' . ($query !== [] ? '?' . http_build_query($query) : '');
}

function normalizeAdminOrderItemsState(array $source): array
{
    return [
        'search' => trim((string) ($source['search'] ?? '')),
        'order_id' => max(0, (int) ($source['order_id'] ?? 0)),
        'product_id' => max(0, (int) ($source['product_id'] ?? 0)),
        'page' => max(1, (int) ($source['page'] ?? 1)),
    ];
}

function fetchAdminOrderItemsSummary(PDO $pdo): array
{
    $summary = $pdo->query(
        "SELECT
            COUNT(*) AS total_order_items,
            COALESCE(SUM(quantity), 0) AS total_quantity_sold
        FROM order_item"
    )->fetch() ?: [];

    $mostOrderedProductStatement = $pdo->query(
        "SELECT
            p.product_id,
            p.name,
            COALESCE(SUM(oi.quantity), 0) AS total_quantity
        FROM order_item oi
        INNER JOIN product p
            ON p.product_id = oi.product_id
        GROUP BY p.product_id, p.name
        ORDER BY total_quantity DESC, p.name ASC
        LIMIT 1"
    );
    $mostOrderedProduct = $mostOrderedProductStatement->fetch() ?: [];

    return [
        'totalOrderItems' => (int) ($summary['total_order_items'] ?? 0),
        'totalQuantitySold' => (int) ($summary['total_quantity_sold'] ?? 0),
        'mostOrderedProduct' => (string) ($mostOrderedProduct['name'] ?? 'No orders yet'),
        'mostOrderedProductQuantity' => (int) ($mostOrderedProduct['total_quantity'] ?? 0),
    ];
}

function fetchAdminOrderItemProductOptions(PDO $pdo): array
{
    $statement = $pdo->query(
        "SELECT DISTINCT
            p.product_id,
            p.name
        FROM order_item oi
        INNER JOIN product p
            ON p.product_id = oi.product_id
        ORDER BY p.name ASC"
    );

    return $statement->fetchAll() ?: [];
}

function fetchPagedAdminOrderItems(
    PDO $pdo,
    string $search,
    int $orderId,
    int $productId,
    int $page,
    int $perPage
): array {
    $page = max(1, $page);
    $perPage = max(1, min(24, $perPage));

    $conditions = [];
    $parameters = [];

    if ($search !== '') {
        $conditions[] = '(CAST(oi.order_item_id AS CHAR) LIKE :search_order_item_id
            OR CAST(oi.order_id AS CHAR) LIKE :search_order_id
            OR CAST(oi.product_id AS CHAR) LIKE :search_product_id
            OR p.name LIKE :search_product_name
            OR COALESCE(p.brand, "") LIKE :search_product_brand
            OR COALESCE(c.name, "") LIKE :search_customer_name
            OR COALESCE(c.email, "") LIKE :search_customer_email)';
        $searchTerm = '%' . $search . '%';
        $parameters['search_order_item_id'] = $searchTerm;
        $parameters['search_order_id'] = $searchTerm;
        $parameters['search_product_id'] = $searchTerm;
        $parameters['search_product_name'] = $searchTerm;
        $parameters['search_product_brand'] = $searchTerm;
        $parameters['search_customer_name'] = $searchTerm;
        $parameters['search_customer_email'] = $searchTerm;
    }

    if ($orderId > 0) {
        $conditions[] = 'oi.order_id = :order_id';
        $parameters['order_id'] = $orderId;
    }

    if ($productId > 0) {
        $conditions[] = 'oi.product_id = :product_id';
        $parameters['product_id'] = $productId;
    }

    $whereSql = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $countStatement = $pdo->prepare(
        "SELECT COUNT(*)
         FROM order_item oi
         INNER JOIN orders o
            ON o.order_id = oi.order_id
         INNER JOIN product p
            ON p.product_id = oi.product_id
         LEFT JOIN customer c
            ON c.cus_id = o.cus_id
         {$whereSql}"
    );

    foreach ($parameters as $name => $value) {
        $countStatement->bindValue(
            ':' . $name,
            $value,
            is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
        );
    }

    $countStatement->execute();
    $totalItems = (int) $countStatement->fetchColumn();
    $totalPages = max(1, (int) ceil($totalItems / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $statement = $pdo->prepare(
        "SELECT
            oi.order_item_id,
            oi.order_id,
            oi.product_id,
            oi.quantity,
            oi.unit_price,
            oi.total_price,
            p.name AS product_name,
            COALESCE(p.brand, '') AS brand,
            COALESCE(product_image_latest.image_url, '') AS image_url,
            o.order_date,
            o.order_status,
            COALESCE(c.name, 'Guest customer') AS customer_name
        FROM order_item oi
        INNER JOIN orders o
            ON o.order_id = oi.order_id
        INNER JOIN product p
            ON p.product_id = oi.product_id
        LEFT JOIN customer c
            ON c.cus_id = o.cus_id
        " . adminLatestProductImageJoinSql('p') . "
        {$whereSql}
        ORDER BY o.order_date DESC, oi.order_item_id DESC
        LIMIT :limit OFFSET :offset"
    );

    foreach ($parameters as $name => $value) {
        $statement->bindValue(
            ':' . $name,
            $value,
            is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
        );
    }

    $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();

    $items = [];
    foreach ($statement->fetchAll() ?: [] as $row) {
        $uiStatus = adminOrderUiStatusFromDatabase((string) ($row['order_status'] ?? 'Pending'));

        $items[] = [
            'orderItemId' => (int) ($row['order_item_id'] ?? 0),
            'orderId' => (int) ($row['order_id'] ?? 0),
            'productId' => (int) ($row['product_id'] ?? 0),
            'productName' => (string) ($row['product_name'] ?? ''),
            'brand' => (string) ($row['brand'] ?? ''),
            'imageUrl' => (string) ($row['image_url'] ?? ''),
            'quantity' => (int) ($row['quantity'] ?? 0),
            'unitPrice' => (float) ($row['unit_price'] ?? 0),
            'totalPrice' => (float) ($row['total_price'] ?? 0),
            'orderDate' => (string) ($row['order_date'] ?? ''),
            'customerName' => (string) ($row['customer_name'] ?? 'Guest customer'),
            'orderStatus' => $uiStatus,
            'orderStatusClassName' => adminOrderStatusClass($uiStatus),
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

function buildOrderItemDrawerPayload(array $itemDetails): array
{
    $payload = [];

    foreach ($itemDetails as $itemId => $detail) {
        $payload[(string) $itemId] = [
            'orderItemId' => (int) $detail['orderItemId'],
            'orderId' => (int) $detail['orderId'],
            'productId' => (int) $detail['productId'],
            'productName' => (string) $detail['productName'],
            'brand' => (string) $detail['brand'],
            'productDescription' => (string) $detail['productDescription'],
            'imageUrl' => (string) $detail['imageUrl'],
            'quantity' => (int) $detail['quantity'],
            'unitPriceFormatted' => formatAdminOrderCurrency((float) $detail['unitPrice']),
            'totalPriceFormatted' => formatAdminOrderCurrency((float) $detail['totalPrice']),
            'stockQuantity' => (int) $detail['stockQuantity'],
            'customerId' => (int) $detail['customerId'],
            'customerName' => (string) $detail['customerName'],
            'customerEmail' => (string) $detail['customerEmail'],
            'customerPhone' => (string) $detail['customerPhone'],
            'orderDateFormatted' => formatAdminOrderDateTime((string) $detail['orderDate']),
            'orderTotalAmountFormatted' => formatAdminOrderCurrency((float) $detail['orderTotalAmount']),
            'shippingAddress' => (string) $detail['shippingAddress'],
            'status' => (string) $detail['status'],
            'statusClassName' => (string) $detail['statusClassName'],
            'paymentMethod' => (string) $detail['paymentMethod'],
            'paymentDateFormatted' => $detail['paymentDate'] !== ''
                ? formatAdminOrderDateTime((string) $detail['paymentDate'])
                : 'Not recorded',
            'paymentStatusLabel' => (string) $detail['paymentStatusLabel'],
            'paymentStatusClassName' => (string) $detail['paymentStatusClassName'],
            'deliveryStatus' => (string) $detail['deliveryStatus'],
            'deliveryStatusClassName' => (string) $detail['deliveryStatusClassName'],
            'deliveryDateFormatted' => $detail['deliveryDate'] !== ''
                ? formatAdminOrderDate((string) $detail['deliveryDate'])
                : 'Not scheduled',
            'orderUrl' => 'admin-orders.php?search=' . urlencode((string) $detail['orderId']),
            'invoiceUrl' => 'admin-order-invoice.php?order_id=' . urlencode((string) $detail['orderId']),
        ];
    }

    return $payload;
}

$state = normalizeAdminOrderItemsState($_GET);
$pageError = '';
$adminName = (string) ($_SESSION['admin_name'] ?? 'Admin');
$lastLogin = (string) ($_SESSION['admin_last_login'] ?? date('Y-m-d H:i:s'));
$summary = [
    'totalOrderItems' => 0,
    'totalQuantitySold' => 0,
    'mostOrderedProduct' => 'No orders yet',
    'mostOrderedProductQuantity' => 0,
];
$orderItemsData = [
    'items' => [],
    'pagination' => [
        'currentPage' => 1,
        'perPage' => ADMIN_ORDER_ITEMS_PER_PAGE,
        'totalItems' => 0,
        'totalPages' => 1,
    ],
];
$productOptions = [];
$drawerPayload = [];

try {
    $pdo = getDatabaseConnection();
    $summary = fetchAdminOrderItemsSummary($pdo);
    $productOptions = fetchAdminOrderItemProductOptions($pdo);
    $orderItemsData = fetchPagedAdminOrderItems(
        $pdo,
        $state['search'],
        $state['order_id'],
        $state['product_id'],
        $state['page'],
        ADMIN_ORDER_ITEMS_PER_PAGE
    );

    $currentItemIds = array_map(
        static fn(array $item): int => (int) $item['orderItemId'],
        $orderItemsData['items']
    );
    $drawerPayload = buildOrderItemDrawerPayload(fetchAdminOrderItemDetailsByIds($pdo, $currentItemIds));
    $state['page'] = (int) $orderItemsData['pagination']['currentPage'];
} catch (Throwable) {
    $pageError = 'Unable to load order item management data from the database.';
}

$pagination = $orderItemsData['pagination'];
$pageStart = $pagination['totalItems'] > 0
    ? (($pagination['currentPage'] - 1) * $pagination['perPage']) + 1
    : 0;
$pageEnd = $pagination['totalItems'] > 0
    ? min($pagination['totalItems'], $pagination['currentPage'] * $pagination['perPage'])
    : 0;
$menuItems = buildAdminManagementMenu('order_items');
$adminProductsStylesheetVersion = (string) filemtime(__DIR__ . '/assets/css/admin-products.css');
$adminOrderManagementScriptVersion = (string) filemtime(__DIR__ . '/assets/js/admin-order-management.js');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Item Management | MOON s Fabric Shop</title>
    <link rel="stylesheet" href="assets/css/admin-products.css?v=<?= escape($adminProductsStylesheetVersion); ?>">
</head>

<body class="products-body admin-orders-body">
    <button class="sidebar-overlay" data-sidebar-overlay hidden aria-label="Close menu"></button>

    <div class="dashboard-app">
        <aside class="dashboard-sidebar" data-dashboard-sidebar>
            <div class="sidebar-brand">
                <div>
                    <span class="sidebar-eyebrow">MOON s Fabric Shop</span>
                    <h1>Order Items</h1>
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
                        <span class="dashboard-label">Order Item Management</span>
                        <h2>Review every product sold through customer orders</h2>
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

                <section class="products-card order-hero-card">
                    <div class="section-head">
                        <div class="section-copy">
                            <span class="dashboard-label">Product Order Flow</span>
                            <h3>Monitor the exact products included in every order</h3>
                        </div>
                        <div class="hero-tag-stack">
                            <span class="hero-pill">Fashion product thumbnails</span>
                            <span class="hero-pill">Elegant item detail views</span>
                        </div>
                    </div>
                </section>

                <section class="metric-grid order-metric-grid order-item-metric-grid">
                    <article class="metric-card tone-blue">
                        <span class="metric-title">Total Order Items</span>
                        <strong class="metric-value"><?= escape(number_format($summary['totalOrderItems'])); ?></strong>
                    </article>
                    <article class="metric-card tone-sky">
                        <span class="metric-title">Most Ordered Product</span>
                        <strong class="metric-value metric-value-compact"><?= escape($summary['mostOrderedProduct']); ?></strong>
                        
                    </article>
                    <article class="metric-card tone-amber">
                        <span class="metric-title">Total Quantity Sold</span>
                        <strong class="metric-value"><?= escape(number_format($summary['totalQuantitySold'])); ?></strong>
                    </article>
                </section>

                <section class="products-card products-card-wide">
                    <div class="section-head">
                        <div class="section-copy">
                            <span class="dashboard-label">Order Item Table</span>
                            <h3>Search, filter, and review sold products</h3>
                        </div>
                        <div class="toolbar-actions">
                            <a class="secondary-button" href="admin-orders.php">Open orders</a>
                        </div>
                    </div>

                    <form method="get" class="filters-form">
                        <div class="filter-grid filter-grid-order-items">
                            <label class="input-group">
                                <span>Search order items</span>
                                <input
                                    type="search"
                                    name="search"
                                    value="<?= escape($state['search']); ?>"
                                    placeholder="Order item, order, product, brand, or customer">
                            </label>

                            <label class="input-group">
                                <span>Filter by order</span>
                                <input type="number" min="1" name="order_id" value="<?= $state['order_id'] > 0 ? escape((string) $state['order_id']) : ''; ?>" placeholder="Enter order ID">
                            </label>

                            <label class="input-group">
                                <span>Filter by product</span>
                                <select name="product_id">
                                    <option value="0">All products</option>
                                    <?php foreach ($productOptions as $productOption): ?>
                                        <?php $currentProductId = (int) ($productOption['product_id'] ?? 0); ?>
                                        <option value="<?= escape((string) $currentProductId); ?>" <?= $state['product_id'] === $currentProductId ? 'selected' : ''; ?>>
                                            <?= escape((string) ($productOption['name'] ?? 'Product')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <div class="filter-actions">
                                <button type="submit" class="toolbar-button">Apply Filters</button>
                                <a href="admin-order-items.php" class="toolbar-link">Reset</a>
                            </div>
                        </div>
                    </form>

                    <div class="list-meta">
                        <div class="list-meta-copy">
                            Showing <?= escape((string) $pageStart); ?>-<?= escape((string) $pageEnd); ?>
                            of <?= escape((string) $pagination['totalItems']); ?> order items
                        </div>
                    </div>

                    <div class="table-shell order-table-shell">
                        <table class="products-table fashion-table order-items-table">
                            <thead>
                                <tr>
                                    <th>Order Item ID</th>
                                    <th>Order ID</th>
                                    <th>Product ID</th>
                                    <th>Product Image</th>
                                    <th>Product Name</th>
                                    <th>Quantity</th>
                                    <th>Unit Price</th>
                                    <th>Total Price</th>
                                    <th class="align-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($orderItemsData['items'] === []): ?>
                                    <tr>
                                        <td colspan="9" class="empty-row">
                                            No order items matched the current search and filter selection.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($orderItemsData['items'] as $item): ?>
                                        <tr>
                                            <td class="strong-cell">#<?= escape((string) $item['orderItemId']); ?></td>
                                            <td>#<?= escape((string) $item['orderId']); ?></td>
                                            <td>#<?= escape((string) $item['productId']); ?></td>
                                            <td>
                                                <?php if ($item['imageUrl'] !== ''): ?>
                                                    <img class="product-thumbnail" src="<?= escape((string) $item['imageUrl']); ?>" alt="<?= escape((string) $item['productName']); ?>">
                                                <?php else: ?>
                                                    <span class="product-placeholder">No Img</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="table-primary"><?= escape((string) $item['productName']); ?></div>
                                                <div class="table-secondary">
                                                    <?= escape((string) $item['brand']); ?> - Order #<?= escape((string) $item['orderId']); ?> - <?= escape((string) $item['customerName']); ?>
                                                </div>
                                            </td>
                                            <td class="strong-cell"><?= escape((string) $item['quantity']); ?></td>
                                            <td><?= escape(formatAdminOrderCurrency((float) $item['unitPrice'])); ?></td>
                                            <td>
                                                <div class="table-primary"><?= escape(formatAdminOrderCurrency((float) $item['totalPrice'])); ?></div>
                                                <div class="table-secondary">
                                                    <?= escape((string) $item['orderStatus']); ?> - <?= escape(formatAdminOrderDate((string) $item['orderDate'])); ?>
                                                </div>
                                            </td>
                                            <td class="align-right">
                                                <div class="action-row action-row-compact">
                                                    <button
                                                        type="button"
                                                        class="action-button secondary"
                                                        data-order-item-detail-trigger
                                                        data-order-item-id="<?= escape((string) $item['orderItemId']); ?>">
                                                        View Details
                                                    </button>
                                                    <a class="action-button" href="admin-orders.php?search=<?= escape((string) $item['orderId']); ?>">
                                                        Open Order
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
                        <?php if ($orderItemsData['items'] === []): ?>
                            <article class="empty-card">
                                No order items matched the current search and filter selection.
                            </article>
                        <?php else: ?>
                            <?php foreach ($orderItemsData['items'] as $item): ?>
                                <article class="mobile-product-card order-mobile-card">
                                    <div class="mobile-product-head">
                                        <div class="mobile-product-copy">
                                            <h4><?= escape((string) $item['productName']); ?></h4>
                                            <p>Item #<?= escape((string) $item['orderItemId']); ?> - Order #<?= escape((string) $item['orderId']); ?></p>
                                        </div>
                                        <span class="status-chip <?= escape((string) $item['orderStatusClassName']); ?>">
                                            <?= escape((string) $item['orderStatus']); ?>
                                        </span>
                                    </div>

                                    <div class="mobile-product-grid">
                                        <div>
                                            <span>Product ID</span>
                                            <strong>#<?= escape((string) $item['productId']); ?></strong>
                                        </div>
                                        <div>
                                            <span>Quantity</span>
                                            <strong><?= escape((string) $item['quantity']); ?></strong>
                                        </div>
                                        <div>
                                            <span>Unit Price</span>
                                            <strong><?= escape(formatAdminOrderCurrency((float) $item['unitPrice'])); ?></strong>
                                        </div>
                                        <div>
                                            <span>Total Price</span>
                                            <strong><?= escape(formatAdminOrderCurrency((float) $item['totalPrice'])); ?></strong>
                                        </div>
                                    </div>

                                    <div class="mobile-actions">
                                        <button
                                            type="button"
                                            class="action-button secondary full-width"
                                            data-order-item-detail-trigger
                                            data-order-item-id="<?= escape((string) $item['orderItemId']); ?>">
                                            View Details
                                        </button>
                                        <a class="action-button full-width" href="admin-orders.php?search=<?= escape((string) $item['orderId']); ?>">
                                            Open Order
                                        </a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <?php if ((int) $pagination['totalPages'] > 1): ?>
                        <nav class="pagination" aria-label="Order item pagination">
                            <?php
                            $previousPage = max(1, (int) $pagination['currentPage'] - 1);
                            $nextPage = min((int) $pagination['totalPages'], (int) $pagination['currentPage'] + 1);
                            ?>
                            <a
                                class="page-link<?= (int) $pagination['currentPage'] === 1 ? ' is-disabled' : ''; ?>"
                                href="<?= escape(buildAdminOrderItemsUrl(array_merge($state, ['page' => $previousPage]))); ?>">
                                Prev
                            </a>

                            <?php for ($pageNumber = 1; $pageNumber <= (int) $pagination['totalPages']; $pageNumber++): ?>
                                <a
                                    class="page-link<?= $pageNumber === (int) $pagination['currentPage'] ? ' is-active' : ''; ?>"
                                    href="<?= escape(buildAdminOrderItemsUrl(array_merge($state, ['page' => $pageNumber]))); ?>">
                                    <?= escape((string) $pageNumber); ?>
                                </a>
                            <?php endfor; ?>

                            <a
                                class="page-link<?= (int) $pagination['currentPage'] === (int) $pagination['totalPages'] ? ' is-disabled' : ''; ?>"
                                href="<?= escape(buildAdminOrderItemsUrl(array_merge($state, ['page' => $nextPage]))); ?>">
                                Next
                            </a>
                        </nav>
                    <?php endif; ?>
                </section>
            </main>
        </div>
    </div>

    <div class="detail-backdrop" data-detail-overlay hidden></div>
    <aside class="detail-drawer" data-order-item-detail-drawer aria-hidden="true">
        <div class="detail-drawer-head">
            <div>
                <span class="dashboard-label">Order Item Detail</span>
                <h3 data-order-item-detail-title>Choose an order item</h3>
            </div>
            <button type="button" class="modal-close" data-detail-close>Close</button>
        </div>

        <div class="detail-drawer-scroll">
            <div class="detail-body-shell" data-order-item-detail-content>
                <div class="detail-empty-state">
                    Select an order item to inspect product, customer, payment, and related order details.
                </div>
            </div>

            <div class="modal-actions drawer-actions">
                <a href="admin-order-items.php" class="secondary-button" data-order-item-order-link>
                    Open Related Order
                </a>
                <a href="admin-order-items.php" class="toolbar-button" data-order-item-invoice-link target="_blank" rel="noopener">
                    Print Invoice
                </a>
            </div>
        </div>
    </aside>

    <script type="application/json" id="order-item-management-data">
        <?= encodeAdminManagementJson($drawerPayload); ?>
    </script>
    <script src="assets/js/admin-dashboard.js"></script>
    <script src="assets/js/admin-order-management.js?v=<?= escape($adminOrderManagementScriptVersion); ?>"></script>
</body>

</html>