<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin-auth.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/admin-customer-management.php';

requireAdminAccess();

const ADMIN_CUSTOMERS_PER_PAGE = 10;

$stateSource = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$state = normalizeAdminCustomersState(is_array($stateSource) ? $stateSource : []);
$pageError = pullFlashMessage('customers_error') ?? '';
$successMessage = pullFlashMessage('customers_success') ?? '';
$adminName = (string) ($_SESSION['admin_name'] ?? 'Admin');
$lastLogin = (string) ($_SESSION['admin_last_login'] ?? date('Y-m-d H:i:s'));

try {
    $pdo = getDatabaseConnection();
} catch (Throwable) {
    $pdo = null;
    $pageError = 'Unable to connect to the database right now.';
}

$customerUpdatesEnabled = $pdo instanceof PDO && adminCustomerStorageWritable();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
    if (!isValidCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        $pageError = 'Your session token has expired. Please refresh the page and try again.';
    } else {
        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action === 'update_customer_status') {
            try {
                if (!$customerUpdatesEnabled) {
                    throw new RuntimeException('Customer status updates are unavailable because storage is not writable.');
                }

                updateAdminCustomerStatus(
                    $pdo,
                    (int) ($_POST['customer_id'] ?? 0),
                    (string) ($_POST['customer_status'] ?? '')
                );

                setFlashMessage('customers_success', 'Customer status updated successfully.');
                header('Location: ' . buildAdminCustomersUrl($state));
                exit;
            } catch (Throwable $exception) {
                $pageError = $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'We could not update that customer right now. Please try again.';
            }
        }
    }
}

$summary = [
    'totalCustomers' => 0,
    'activeCustomers' => 0,
    'newCustomersThisMonth' => 0,
    'returningCustomers' => 0,
];
$customersData = [
    'items' => [],
    'pagination' => [
        'currentPage' => 1,
        'perPage' => ADMIN_CUSTOMERS_PER_PAGE,
        'totalItems' => 0,
        'totalPages' => 1,
    ],
];
$profilePayload = [];

if ($pdo instanceof PDO) {
    try {
        $summary = fetchAdminCustomerSummary($pdo);
        $customersData = fetchPagedAdminCustomers(
            $pdo,
            $state['search'],
            $state['status'],
            $state['page'],
            ADMIN_CUSTOMERS_PER_PAGE
        );

        $currentCustomerIds = array_map(
            static fn(array $customer): int => (int) $customer['customerId'],
            $customersData['items']
        );
        $orderHistories = fetchAdminCustomerOrderHistories($pdo, $currentCustomerIds);
        $profilePayload = buildAdminCustomerProfilePayload($customersData['items'], $orderHistories, $customerUpdatesEnabled);
        $state['page'] = (int) $customersData['pagination']['currentPage'];
    } catch (Throwable) {
        $pageError = $pageError !== ''
            ? $pageError
            : 'Unable to load customer management data from the database.';
    }
}

$pagination = $customersData['pagination'];
$pageStart = $pagination['totalItems'] > 0
    ? (($pagination['currentPage'] - 1) * $pagination['perPage']) + 1
    : 0;
$pageEnd = $pagination['totalItems'] > 0
    ? min($pagination['totalItems'], $pagination['currentPage'] * $pagination['perPage'])
    : 0;
$menuItems = buildAdminManagementMenu('customers');
$adminProductsStylesheetVersion = is_file(__DIR__ . '/assets/css/admin-products.css')
    ? (string) filemtime(__DIR__ . '/assets/css/admin-products.css')
    : '1';
$adminCustomersStylesheetVersion = is_file(__DIR__ . '/assets/css/admin-customers.css')
    ? (string) filemtime(__DIR__ . '/assets/css/admin-customers.css')
    : '1';
$adminCustomerScriptVersion = is_file(__DIR__ . '/assets/js/admin-customer-management.js')
    ? (string) filemtime(__DIR__ . '/assets/js/admin-customer-management.js')
    : '1';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Management | MOON s Fabric Shop</title>
    <link rel="stylesheet" href="assets/css/admin-products.css?v=<?= escape($adminProductsStylesheetVersion); ?>">
    <link rel="stylesheet" href="assets/css/admin-customers.css?v=<?= escape($adminCustomersStylesheetVersion); ?>">
</head>

<body class="products-body admin-customers-body">
    <button class="sidebar-overlay" data-sidebar-overlay hidden aria-label="Close menu"></button>

    <div class="dashboard-app">
        <aside class="dashboard-sidebar" data-dashboard-sidebar>
            <div class="sidebar-brand">
                <div>
                    <span class="sidebar-eyebrow">MOON s Fabric Shop</span>
                    <h1>Customer CRM</h1>
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
                        <span class="dashboard-label">Customer Management</span>
                        <h2>Simple CRM view for customers, spending, and status</h2>
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

                <?php if ($pdo instanceof PDO && !$customerUpdatesEnabled): ?>
                    <div class="status-message is-warning">
                        Customer status updates are temporarily unavailable because the customer management storage file is not writable.
                    </div>
                <?php endif; ?>

                <section class="products-card customer-hero-card">
                    <div class="section-head">
                        <div class="section-copy">
                            <span class="dashboard-label">Fashion CRM</span>
                            <h3>Know every customer behind every order</h3>
                        </div>
                        <div class="hero-tag-stack">
                            <span class="hero-pill">Customer list</span>
                            <span class="hero-pill">Order links</span>
                            <span class="hero-pill">Status updates</span>
                        </div>
                    </div>
                </section>

                <section class="metric-grid customer-metric-grid">
                    <article class="metric-card tone-blue">
                        <span class="metric-title">Total Customers</span>
                        <strong class="metric-value"><?= escape(number_format($summary['totalCustomers'])); ?></strong>
                        <span class="metric-text">Registered customers</span>
                    </article>
                    <article class="metric-card tone-sky">
                        <span class="metric-title">Active Customers</span>
                        <strong class="metric-value"><?= escape(number_format($summary['activeCustomers'])); ?></strong>
                        <span class="metric-text">Customers currently marked active</span>
                    </article>
                    <article class="metric-card tone-rose">
                        <span class="metric-title">New This Month</span>
                        <strong class="metric-value"><?= escape(number_format($summary['newCustomersThisMonth'])); ?></strong>
                        <span class="metric-text">Customers with this month signup date</span>
                    </article>
                    
                </section>

                <section class="products-card products-card-wide">
                    <div class="section-head">
                        <div class="section-copy">
                            <span class="dashboard-label">Customer Table</span>
                            <h3>Search, filter, and manage customer accounts</h3>
                        </div>
                        <div class="toolbar-actions">
                            <a class="secondary-button" href="admin-orders.php">Open orders</a>
                        </div>
                    </div>

                    <form method="get" class="filters-form">
                        <div class="filter-grid">
                            <label class="input-group">
                                <span>Search customers</span>
                                <input
                                    type="search"
                                    name="search"
                                    value="<?= escape($state['search']); ?>"
                                    placeholder="Customer ID, name, email, phone, city, or address">
                            </label>

                            <label class="input-group">
                                <span>Filter customers</span>
                                <select name="status">
                                    <option value="">All statuses</option>
                                    <?php foreach (ADMIN_CUSTOMER_STATUSES as $statusOption): ?>
                                        <option value="<?= escape($statusOption); ?>" <?= $state['status'] === $statusOption ? 'selected' : ''; ?>>
                                            <?= escape($statusOption); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <div class="filter-actions">
                                <button type="submit" class="toolbar-button">Apply Filters</button>
                                <a href="admin-customers.php" class="toolbar-link">Reset</a>
                            </div>
                        </div>
                    </form>

                    <div class="list-meta">
                        <div class="list-meta-copy">
                            Showing <?= escape((string) $pageStart); ?>-<?= escape((string) $pageEnd); ?>
                            of <?= escape((string) $pagination['totalItems']); ?> customers
                        </div>
                        <div class="table-chip-row">
                            <?php foreach (ADMIN_CUSTOMER_STATUSES as $statusOption): ?>
                                <span class="summary-chip"><?= escape($statusOption); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="table-shell customer-table-shell">
                        <table class="products-table fashion-table customer-table">
                            <thead>
                                <tr>
                                    <th>Cus_ID</th>
                                    <th>Avatar</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>City</th>
                                    <th>Shopping address</th>
                                    <th>Total orders</th>
                                    <th>Total spending</th>
                                    <th>Account status</th>
                                    <th>Created_at</th>
                                    <th class="align-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($customersData['items'] === []): ?>
                                    <tr>
                                        <td colspan="12" class="empty-row">
                                            No customers matched the current search and filter selection.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($customersData['items'] as $customer): ?>
                                        <tr>
                                            <td class="strong-cell">#<?= escape((string) $customer['customerId']); ?></td>
                                            <td>
                                                <span class="customer-avatar <?= escape((string) $customer['avatarClassName']); ?>">
                                                    <?= escape((string) $customer['initials']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="table-primary"><?= escape((string) $customer['name']); ?></div>
                                                <div class="table-secondary"><?= !empty($customer['isReturningCustomer']) ? 'Returning customer' : 'New customer'; ?></div>
                                            </td>
                                            <td><?= escape((string) $customer['email']); ?></td>
                                            <td><?= escape((string) $customer['phone']); ?></td>
                                            <td><?= escape((string) ($customer['city'] !== '' ? $customer['city'] : 'Not set')); ?></td>
                                            <td>
                                                <div class="address-snippet"><?= escape((string) ($customer['shoppingAddress'] !== '' ? $customer['shoppingAddress'] : 'No address recorded')); ?></div>
                                            </td>
                                            <td class="strong-cell"><?= escape(number_format((int) $customer['totalOrders'])); ?></td>
                                            <td class="strong-cell"><?= escape((string) $customer['totalSpendingFormatted']); ?></td>
                                            <td>
                                                <span class="status-chip <?= escape((string) $customer['accountStatusClassName']); ?>">
                                                    <?= escape((string) $customer['accountStatus']); ?>
                                                </span>
                                            </td>
                                            <td><?= escape((string) $customer['createdAtFormatted']); ?></td>
                                            <td class="align-right">
                                                <div class="action-row action-row-compact customer-simple-actions">
                                                    <button
                                                        type="button"
                                                        class="action-button secondary"
                                                        data-customer-profile-trigger
                                                        data-customer-id="<?= escape((string) $customer['customerId']); ?>">
                                                        Account
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="action-button secondary"
                                                        data-customer-orders-trigger
                                                        data-customer-id="<?= escape((string) $customer['customerId']); ?>">
                                                        History
                                                    </button>
                                                    <form method="post" class="customer-status-inline-form">
                                                        <input type="hidden" name="csrf_token" value="<?= escape(getCsrfToken()); ?>">
                                                        <input type="hidden" name="action" value="update_customer_status">
                                                        <input type="hidden" name="customer_id" value="<?= escape((string) $customer['customerId']); ?>">
                                                        <input type="hidden" name="search" value="<?= escape($state['search']); ?>">
                                                        <input type="hidden" name="status" value="<?= escape($state['status']); ?>">
                                                        <input type="hidden" name="page" value="<?= escape((string) $state['page']); ?>">
                                                        <select name="customer_status" aria-label="Account status" <?= !$customerUpdatesEnabled ? 'disabled' : ''; ?>>
                                                            <?php foreach (ADMIN_CUSTOMER_STATUSES as $statusOption): ?>
                                                                <option value="<?= escape($statusOption); ?>" <?= $customer['accountStatus'] === $statusOption ? 'selected' : ''; ?>>
                                                                    <?= escape($statusOption); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <button type="submit" class="action-button" <?= !$customerUpdatesEnabled ? 'disabled' : ''; ?>>
                                                            Save
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="products-mobile-list">
                        <?php if ($customersData['items'] === []): ?>
                            <article class="empty-card">
                                No customers matched the current search and filter selection.
                            </article>
                        <?php else: ?>
                            <?php foreach ($customersData['items'] as $customer): ?>
                                <article class="mobile-product-card customer-mobile-card">
                                    <div class="mobile-product-head">
                                        <div class="customer-card-heading">
                                            <span class="customer-avatar <?= escape((string) $customer['avatarClassName']); ?>">
                                                <?= escape((string) $customer['initials']); ?>
                                            </span>
                                            <div class="mobile-product-copy">
                                                <h4><?= escape((string) $customer['name']); ?></h4>
                                                <p><?= escape((string) $customer['email']); ?></p>
                                            </div>
                                        </div>
                                        <span class="status-chip <?= escape((string) $customer['accountStatusClassName']); ?>">
                                            <?= escape((string) $customer['accountStatus']); ?>
                                        </span>
                                    </div>

                                    <div class="mobile-product-grid">
                                        <div>
                                            <span>Orders</span>
                                            <strong><?= escape(number_format((int) $customer['totalOrders'])); ?></strong>
                                        </div>
                                        <div>
                                            <span>Spending</span>
                                            <strong><?= escape((string) $customer['totalSpendingFormatted']); ?></strong>
                                        </div>
                                        <div>
                                            <span>City</span>
                                            <strong><?= escape((string) ($customer['city'] !== '' ? $customer['city'] : 'Not set')); ?></strong>
                                        </div>
                                        <div>
                                            <span>Created</span>
                                            <strong><?= escape((string) $customer['createdAtFormatted']); ?></strong>
                                        </div>
                                    </div>

                                    <div class="mobile-actions">
                                        <button
                                            type="button"
                                            class="action-button secondary full-width"
                                            data-customer-profile-trigger
                                            data-customer-id="<?= escape((string) $customer['customerId']); ?>">
                                            Account
                                        </button>
                                        <button
                                            type="button"
                                            class="action-button secondary full-width"
                                            data-customer-orders-trigger
                                            data-customer-id="<?= escape((string) $customer['customerId']); ?>">
                                            Order History
                                        </button>
                                        <form method="post" class="customer-status-inline-form customer-status-mobile-form">
                                            <input type="hidden" name="csrf_token" value="<?= escape(getCsrfToken()); ?>">
                                            <input type="hidden" name="action" value="update_customer_status">
                                            <input type="hidden" name="customer_id" value="<?= escape((string) $customer['customerId']); ?>">
                                            <input type="hidden" name="search" value="<?= escape($state['search']); ?>">
                                            <input type="hidden" name="status" value="<?= escape($state['status']); ?>">
                                            <input type="hidden" name="page" value="<?= escape((string) $state['page']); ?>">
                                            <select name="customer_status" aria-label="Account status" <?= !$customerUpdatesEnabled ? 'disabled' : ''; ?>>
                                                <?php foreach (ADMIN_CUSTOMER_STATUSES as $statusOption): ?>
                                                    <option value="<?= escape($statusOption); ?>" <?= $customer['accountStatus'] === $statusOption ? 'selected' : ''; ?>>
                                                        <?= escape($statusOption); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="action-button full-width" <?= !$customerUpdatesEnabled ? 'disabled' : ''; ?>>
                                                Save Status
                                            </button>
                                        </form>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <?php if ((int) $pagination['totalPages'] > 1): ?>
                        <nav class="pagination" aria-label="Customer pagination">
                            <?php
                            $previousPage = max(1, (int) $pagination['currentPage'] - 1);
                            $nextPage = min((int) $pagination['totalPages'], (int) $pagination['currentPage'] + 1);
                            ?>
                            <a
                                class="page-link<?= (int) $pagination['currentPage'] === 1 ? ' is-disabled' : ''; ?>"
                                href="<?= escape(buildAdminCustomersUrl(array_merge($state, ['page' => $previousPage]))); ?>">
                                Prev
                            </a>

                            <?php for ($pageNumber = 1; $pageNumber <= (int) $pagination['totalPages']; $pageNumber++): ?>
                                <a
                                    class="page-link<?= $pageNumber === (int) $pagination['currentPage'] ? ' is-active' : ''; ?>"
                                    href="<?= escape(buildAdminCustomersUrl(array_merge($state, ['page' => $pageNumber]))); ?>">
                                    <?= escape((string) $pageNumber); ?>
                                </a>
                            <?php endfor; ?>

                            <a
                                class="page-link<?= (int) $pagination['currentPage'] === (int) $pagination['totalPages'] ? ' is-disabled' : ''; ?>"
                                href="<?= escape(buildAdminCustomersUrl(array_merge($state, ['page' => $nextPage]))); ?>">
                                Next
                            </a>
                        </nav>
                    <?php endif; ?>
                </section>
            </main>
        </div>
    </div>

    <div class="customer-profile-backdrop" data-customer-profile-overlay hidden>
        <section class="customer-profile-modal" data-customer-profile-modal aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="customer-profile-title">
            <div class="detail-drawer-head">
                <div>
                    <span class="dashboard-label">Customer Account</span>
                    <h3 id="customer-profile-title" data-customer-modal-title>Choose a customer</h3>
                </div>
                <button type="button" class="modal-close" data-customer-modal-close>Close</button>
            </div>

            <div class="customer-profile-layout">
                <div class="customer-profile-content" data-customer-profile-content>
                    <div class="detail-empty-state">
                        Select a customer to review profile details, order history, courier names, and tracking IDs.
                    </div>
                </div>

                <aside class="customer-status-panel">
                    <div>
                        <span class="dashboard-label">Account Status</span>
                        <p>Update customer access without leaving the profile view.</p>
                    </div>
                    <form method="post" class="customer-status-inline-form customer-status-mobile-form">
                        <input type="hidden" name="csrf_token" value="<?= escape(getCsrfToken()); ?>">
                        <input type="hidden" name="action" value="update_customer_status">
                        <input type="hidden" name="customer_id" value="" data-customer-status-id-input>
                        <input type="hidden" name="search" value="<?= escape($state['search']); ?>">
                        <input type="hidden" name="status" value="<?= escape($state['status']); ?>">
                        <input type="hidden" name="page" value="<?= escape((string) $state['page']); ?>">
                        <select name="customer_status" aria-label="Account status" data-customer-status-select <?= !$customerUpdatesEnabled ? 'disabled' : ''; ?>>
                            <?php foreach (ADMIN_CUSTOMER_STATUSES as $statusOption): ?>
                                <option value="<?= escape($statusOption); ?>"><?= escape($statusOption); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="action-button full-width" data-customer-save-button <?= !$customerUpdatesEnabled ? 'disabled' : ''; ?>>
                            Save Status
                        </button>
                    </form>
                    <button type="button" class="secondary-button full-width" data-customer-modal-close>Close</button>
                </aside>
            </div>
        </section>
    </div>

    <script type="application/json" id="customer-management-data">
        <?= encodeAdminManagementJson($profilePayload); ?>
    </script>
    <script src="assets/js/admin-dashboard.js"></script>
    <script src="assets/js/admin-customer-management.js?v=<?= escape($adminCustomerScriptVersion); ?>"></script>
</body>

</html>
