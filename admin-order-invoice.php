<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin-auth.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/admin-order-management.php';

requireAdminAccess();

$orderId = max(0, (int) ($_GET['order_id'] ?? 0));
$invoiceError = '';
$order = null;

if ($orderId <= 0) {
    $invoiceError = 'Please choose a valid order to print.';
} else {
    try {
        $pdo = getDatabaseConnection();
        $order = fetchAdminOrderDetail($pdo, $orderId);

        if ($order === null) {
            $invoiceError = 'The requested order could not be found.';
        }
    } catch (Throwable) {
        $invoiceError = 'Unable to load invoice data right now.';
    }
}

$stylesheetVersion = (string) filemtime(__DIR__ . '/assets/css/admin-products.css');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Invoice | MOON s Fabric Shop</title>
    <link rel="stylesheet" href="assets/css/admin-products.css?v=<?= escape($stylesheetVersion); ?>">
</head>

<body class="products-body invoice-body">
    <main class="invoice-shell">
        <?php if ($invoiceError !== ''): ?>
            <section class="products-card invoice-card">
                <div class="status-message is-error"><?= escape($invoiceError); ?></div>
                <div class="modal-actions invoice-toolbar">
                    <a class="secondary-button" href="admin-orders.php">Back to Orders</a>
                </div>
            </section>
        <?php else: ?>
            <?php
            $paymentStatus = adminOrderPaymentStatusMeta(
                (string) $order['paymentMethod'],
                (float) $order['paymentAmount'],
                (float) $order['totalAmount'],
                (string) $order['databaseStatus']
            );
            ?>
            <section class="products-card invoice-card">
                <div class="invoice-toolbar">
                    <a class="secondary-button" href="admin-orders.php?search=<?= escape((string) $order['orderId']); ?>">Back to Orders</a>
                    <button type="button" class="toolbar-button" data-print-invoice>Print Invoice</button>
                </div>

                <div class="invoice-header">
                    <div>
                        <span class="dashboard-label">MOON s Fabric Shop</span>
                        <h1>Order Invoice</h1>
                        <p>Premium fashion order summary for internal shop records.</p>
                    </div>
                    <div class="invoice-badge-stack">
                        <span class="status-chip <?= escape(adminOrderStatusClass((string) $order['status'])); ?>">
                            <?= escape((string) $order['status']); ?>
                        </span>
                        <span class="status-chip <?= escape((string) $paymentStatus['className']); ?>">
                            <?= escape((string) $paymentStatus['label']); ?>
                        </span>
                    </div>
                </div>

                <div class="invoice-meta-grid">
                    <article class="invoice-meta-card">
                        <span>Order ID</span>
                        <strong>#<?= escape((string) $order['orderId']); ?></strong>
                    </article>
                    <article class="invoice-meta-card">
                        <span>Customer ID</span>
                        <strong>#<?= escape((string) $order['customerId']); ?></strong>
                    </article>
                    <article class="invoice-meta-card">
                        <span>Order Date</span>
                        <strong><?= escape(formatAdminOrderDateTime((string) $order['orderDate'])); ?></strong>
                    </article>
                    <article class="invoice-meta-card">
                        <span>Total Amount</span>
                        <strong><?= escape(formatAdminOrderCurrency((float) $order['totalAmount'])); ?></strong>
                    </article>
                </div>

                <div class="invoice-content-grid">
                    <article class="invoice-panel">
                        <h2>Customer Details</h2>
                        <div class="invoice-data-list">
                            <div>
                                <span>Name</span>
                                <strong><?= escape((string) $order['customerName']); ?></strong>
                            </div>
                            <div>
                                <span>Email</span>
                                <strong><?= escape((string) $order['customerEmail']); ?></strong>
                            </div>
                            <div>
                                <span>Phone</span>
                                <strong><?= escape((string) $order['customerPhone']); ?></strong>
                            </div>
                        </div>
                    </article>

                    <article class="invoice-panel">
                        <h2>Payment & Delivery</h2>
                        <div class="invoice-data-list">
                            <div>
                                <span>Payment Method</span>
                                <strong><?= escape((string) $order['paymentMethod']); ?></strong>
                            </div>
                            <div>
                                <span>Payment Status</span>
                                <strong><?= escape((string) $paymentStatus['label']); ?></strong>
                            </div>
                            <div>
                                <span>Payment Date</span>
                                <strong>
                                    <?= $order['paymentDate'] !== '' ? escape(formatAdminOrderDateTime((string) $order['paymentDate'])) : 'Not recorded'; ?>
                                </strong>
                            </div>
                            <div>
                                <span>Delivery Status</span>
                                <strong><?= escape((string) $order['deliveryStatus']); ?></strong>
                            </div>
                        </div>
                    </article>
                </div>

                <article class="invoice-panel invoice-address-panel">
                    <h2>Shipping Address</h2>
                    <p><?= nl2br(escape((string) $order['shippingAddress'])); ?></p>
                </article>

                <article class="invoice-panel">
                    <h2>Order Items</h2>
                    <div class="table-shell invoice-table-shell">
                        <table class="products-table fashion-table invoice-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Product</th>
                                    <th>Quantity</th>
                                    <th>Unit Price</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($order['items'] as $item): ?>
                                    <tr>
                                        <td class="strong-cell">#<?= escape((string) $item['orderItemId']); ?></td>
                                        <td>
                                            <div class="table-primary"><?= escape((string) $item['productName']); ?></div>
                                            <div class="table-secondary"><?= escape((string) $item['brand']); ?> - Product #<?= escape((string) $item['productId']); ?></div>
                                        </td>
                                        <td><?= escape((string) $item['quantity']); ?></td>
                                        <td><?= escape(formatAdminOrderCurrency((float) $item['unitPrice'])); ?></td>
                                        <td class="strong-cell"><?= escape(formatAdminOrderCurrency((float) $item['totalPrice'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </article>

                <div class="invoice-total-row">
                    <span>Invoice Total</span>
                    <strong><?= escape(formatAdminOrderCurrency((float) $order['totalAmount'])); ?></strong>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <script>
        const printButton = document.querySelector('[data-print-invoice]');
        if (printButton) {
            printButton.addEventListener('click', () => window.print());
        }
    </script>
</body>

</html>