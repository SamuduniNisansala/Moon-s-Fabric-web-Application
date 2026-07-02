<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin-auth.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/admin-order-management.php';

requireAdminAccess();

function buildMonthlySalesTemplate(int $months = 6): array
{
    $template = [];
    $currentMonth = new DateTimeImmutable('first day of this month');

    for ($offset = $months - 1; $offset >= 0; $offset--) {
        $month = $currentMonth->modify("-{$offset} months");
        $key = $month->format('Y-m');
        $template[$key] = [
            'month' => $month->format('M'),
            'sales' => 0,
        ];
    }

    return $template;
}

function formatDashboardCurrency(float $amount, string $currency = 'LKR'): string
{
    return $currency . ' ' . number_format($amount, 0);
}

function formatDashboardDate(string $value): string
{
    $timestamp = strtotime($value);
    return $timestamp ? date('d M Y', $timestamp) : $value;
}

function formatDashboardDateTime(string $value): string
{
    $timestamp = strtotime($value);
    return $timestamp ? date('d M Y, h:i A', $timestamp) : $value;
}

function getStatusClassName(string $status): string
{
    return match ($status) {
        'Pending' => 'status-pending',
        'Confirmed' => 'status-confirmed',
        'Shipped' => 'status-shipped',
        'Delivered' => 'status-delivered',
        'Cancelled' => 'status-cancelled',
        default => 'status-default',
    };
}

$adminName = (string) $_SESSION['admin_name'];
$lastLogin = (string) ($_SESSION['admin_last_login'] ?? date('Y-m-d H:i:s'));
$dashboardData = [
    'adminName' => $adminName,
    'lastLogin' => $lastLogin,
    'currency' => 'LKR',
    'metrics' => [
        'totalOrders' => 0,
        'totalSales' => 0,
        'totalCustomers' => 0,
        'totalProducts' => 0,
    ],
    'recentOrders' => [],
    'monthlySales' => array_values(buildMonthlySalesTemplate()),
    'availability' => [
        'productsWithImages' => 0,
        'productsWithoutImages' => 0,
        'categories' => 0,
        'catalogReadiness' => 0,
    ],
    'databaseError' => null,
];

try {
    $pdo = getDatabaseConnection();

    $dashboardData['metrics']['totalOrders'] = (int) $pdo->query(
        'SELECT COUNT(*) FROM orders'
    )->fetchColumn();

    $dashboardData['metrics']['totalSales'] = (float) $pdo->query(
        "SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE order_status <> 'Cancelled'"
    )->fetchColumn();

    $dashboardData['metrics']['totalCustomers'] = (int) $pdo->query(
        'SELECT COUNT(*) FROM customer'
    )->fetchColumn();

    $dashboardData['metrics']['totalProducts'] = (int) $pdo->query(
        'SELECT COUNT(*) FROM product'
    )->fetchColumn();

    $recentOrdersStatement = $pdo->query(
        'SELECT
            orders.order_id,
            orders.order_date,
            orders.total_amount,
            orders.order_status,
            customer.name AS customer_name,
            customer.email AS customer_email
        FROM orders
        LEFT JOIN customer ON customer.cus_id = orders.cus_id
        ORDER BY orders.order_date DESC, orders.order_id DESC
        LIMIT 6'
    );

    $dashboardData['recentOrders'] = $recentOrdersStatement->fetchAll() ?: [];

    $monthlySalesMap = buildMonthlySalesTemplate();
    $monthlySalesStatement = $pdo->query(
        "SELECT
            DATE_FORMAT(order_date, '%Y-%m') AS month_key,
            COALESCE(SUM(total_amount), 0) AS total_sales
        FROM orders
        WHERE order_status <> 'Cancelled'
            AND order_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
        GROUP BY month_key
        ORDER BY month_key ASC"
    );

    foreach ($monthlySalesStatement->fetchAll() ?: [] as $row) {
        $monthKey = (string) ($row['month_key'] ?? '');
        if (isset($monthlySalesMap[$monthKey])) {
            $monthlySalesMap[$monthKey]['sales'] = (float) ($row['total_sales'] ?? 0);
        }
    }
    $dashboardData['monthlySales'] = array_values($monthlySalesMap);

    $productsWithImages = (int) $pdo->query(
        'SELECT COUNT(DISTINCT product_id) FROM product_image'
    )->fetchColumn();

    $categories = (int) $pdo->query(
        'SELECT COUNT(*) FROM category'
    )->fetchColumn();

    $productsWithoutImages = max(
        0,
        $dashboardData['metrics']['totalProducts'] - $productsWithImages
    );

    $catalogReadiness = $dashboardData['metrics']['totalProducts'] > 0
        ? (int) round(
            ($productsWithImages / $dashboardData['metrics']['totalProducts']) * 100
        )
        : 0;

    $dashboardData['availability'] = [
        'productsWithImages' => $productsWithImages,
        'productsWithoutImages' => $productsWithoutImages,
        'categories' => $categories,
        'catalogReadiness' => $catalogReadiness,
    ];
} catch (Throwable $exception) {
    $dashboardData['databaseError'] = 'Unable to load dashboard data from the database.';
}

$menuItems = buildAdminManagementMenu('dashboard');

$maxSales = 0.0;
foreach ($dashboardData['monthlySales'] as $monthlySale) {
    $maxSales = max($maxSales, (float) $monthlySale['sales']);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | MOON s Fabric Shop</title>
    <link rel="stylesheet" href="assets/css/admin-panel.css">
</head>

<body class="admin-body dashboard-screen">
    <button class="sidebar-overlay" data-sidebar-overlay hidden aria-label="Close menu"></button>

    <div class="dashboard-app">
        <aside class="dashboard-sidebar" data-dashboard-sidebar>
            <div class="sidebar-brand">
                <div>
                    <span class="eyebrow sidebar-eyebrow">MOON s Fabric Shop</span>
                    <h1>Admin Panel</h1>
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
                        <span class="dashboard-label">Dashboard</span>
                        <h2>Welcome, <?= escape($dashboardData['adminName']); ?></h2>
                    </div>
                </div>

                <div class="topbar-right">
                    <span class="topbar-meta-label">Last login</span>
                    <span class="topbar-meta-value">
                        <?= escape(formatDashboardDateTime((string) $dashboardData['lastLogin'])); ?>
                    </span>
                </div>
            </header>

            <main class="dashboard-content">
                <?php if ($dashboardData['databaseError']): ?>
                    <div class="status-message is-error dashboard-message">
                        <?= escape((string) $dashboardData['databaseError']); ?>
                    </div>
                <?php endif; ?>

                <section class="metric-grid" id="dashboard-metrics">
                    <article class="metric-card tone-blue">
                        <span class="metric-title">Total Orders</span>
                        <strong class="metric-value">
                            <?= escape(number_format((int) $dashboardData['metrics']['totalOrders'])); ?>
                        </strong>
                        <span class="metric-text">Order records</span>
                    </article>

                    <article class="metric-card tone-emerald">
                        <span class="metric-title">Total Sales</span>
                        <strong class="metric-value">
                            <?= escape(formatDashboardCurrency((float) $dashboardData['metrics']['totalSales'], (string) $dashboardData['currency'])); ?>
                        </strong>
                        <span class="metric-text">Non-cancelled sales</span>
                    </article>

                    <article class="metric-card tone-slate">
                        <span class="metric-title">Total Customers</span>
                        <strong class="metric-value">
                            <?= escape(number_format((int) $dashboardData['metrics']['totalCustomers'])); ?>
                        </strong>
                        <span class="metric-text">Registered customers</span>
                    </article>

                    <article class="metric-card tone-blue">
                        <span class="metric-title">Total Products</span>
                        <strong class="metric-value">
                            <?= escape(number_format((int) $dashboardData['metrics']['totalProducts'])); ?>
                        </strong>
                        <span class="metric-text">Catalog products</span>
                    </article>
                </section>

                <section class="dashboard-panels">
                    <article class="dashboard-card sales-card" id="monthly-sales">
                        <div class="section-head">
                            <div>
                                <span class="dashboard-label">Sales</span>
                                <h3>Monthly Sales Chart</h3>
                            </div>
                            <span class="section-tag">Last 6 months</span>
                        </div>

                        <div class="sales-chart">
                            <div class="chart-scale">
                                <span><?= escape(formatDashboardCurrency($maxSales, (string) $dashboardData['currency'])); ?></span>
                                <span>
                                    <?= escape(formatDashboardCurrency($maxSales > 0 ? $maxSales / 2 : 0, (string) $dashboardData['currency'])); ?>
                                </span>
                                <span><?= escape(formatDashboardCurrency(0, (string) $dashboardData['currency'])); ?></span>
                            </div>

                            <div class="chart-bars">
                                <?php foreach ($dashboardData['monthlySales'] as $sale): ?>
                                    <?php
                                    $salesAmount = (float) $sale['sales'];
                                    $barHeight = $maxSales > 0
                                        ? max(10, ($salesAmount / $maxSales) * 100)
                                        : 10;
                                    ?>
                                    <div class="chart-bar-column">
                                        <span class="chart-value">
                                            <?= escape(formatDashboardCurrency($salesAmount, (string) $dashboardData['currency'])); ?>
                                        </span>
                                        <div class="chart-bar-track">
                                            <div
                                                class="chart-bar-fill"
                                                style="height: <?= escape((string) round($barHeight, 2)); ?>%;"></div>
                                        </div>
                                        <span class="chart-label"><?= escape((string) $sale['month']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </article>

                    <div class="side-panel-stack">
                        <article class="dashboard-card" id="availability-summary">
                            <div class="section-head">
                                <div>
                                    <span class="dashboard-label">Catalog</span>
                                    <h3>Product Availability Summary</h3>
                                </div>
                            </div>

                            <div class="readiness-card">
                                <div class="readiness-row">
                                    <span>Catalog readiness</span>
                                    <strong>
                                        <?= escape((string) $dashboardData['availability']['catalogReadiness']); ?>%
                                    </strong>
                                </div>
                                <div class="readiness-track">
                                    <div
                                        class="readiness-fill"
                                        style="width: <?= escape((string) min(100, max(0, (int) $dashboardData['availability']['catalogReadiness']))); ?>%;"></div>
                                </div>
                            </div>

                            <div class="summary-list">
                                <div class="summary-item">
                                    <span>Products with images</span>
                                    <strong><?= escape(number_format((int) $dashboardData['availability']['productsWithImages'])); ?></strong>
                                </div>
                                <div class="summary-item">
                                    <span>Products without images</span>
                                    <strong><?= escape(number_format((int) $dashboardData['availability']['productsWithoutImages'])); ?></strong>
                                </div>
                                <div class="summary-item">
                                    <span>Categories</span>
                                    <strong><?= escape(number_format((int) $dashboardData['availability']['categories'])); ?></strong>
                                </div>
                            </div>
                        </article>
                    </div>
                </section>

                <section class="dashboard-card recent-orders-card" id="recent-orders">
                    <div class="section-head section-head-with-tag">
                        <div>
                            <span class="dashboard-label">Orders</span>
                            <h3>Recent Orders</h3>
                        </div>
                        <span class="section-tag">
                            <?= escape((string) count($dashboardData['recentOrders'])); ?> rows
                        </span>
                    </div>

                    <div class="table-shell">
                        <table class="dashboard-table">
                            <thead>
                                <tr>
                                    <th>Order ID</th>
                                    <th>Customer</th>
                                    <th>Date</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$dashboardData['recentOrders']): ?>
                                    <tr>
                                        <td colspan="5" class="empty-row">No recent orders found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($dashboardData['recentOrders'] as $order): ?>
                                        <tr>
                                            <td class="strong-cell">#<?= escape((string) $order['order_id']); ?></td>
                                            <td>
                                                <div class="table-primary">
                                                    <?= escape((string) ($order['customer_name'] ?: 'Guest customer')); ?>
                                                </div>
                                                <div class="table-secondary">
                                                    <?= escape((string) ($order['customer_email'] ?: 'No email')); ?>
                                                </div>
                                            </td>
                                            <td><?= escape(formatDashboardDate((string) $order['order_date'])); ?></td>
                                            <td class="strong-cell">
                                                <?= escape(formatDashboardCurrency((float) $order['total_amount'], (string) $dashboardData['currency'])); ?>
                                            </td>
                                            <td>
                                                <span class="status-chip <?= escape(getStatusClassName((string) $order['order_status'])); ?>">
                                                    <?= escape((string) $order['order_status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </main>
        </div>
    </div>

    <script src="assets/js/admin-dashboard.js"></script>
</body>

</html>