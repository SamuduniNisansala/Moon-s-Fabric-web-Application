<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/store.php';

if (!isCustomerAuthenticated()) {
    redirectStorePage('login.php', 'Please log in to view your orders.', 'error');
}

$pdo = getStorePdo();
$customer = fetchCurrentStoreCustomer($pdo);

if ($customer === null) {
    redirectStorePage('login.php', 'Your customer account could not be loaded right now.', 'error');
}

$orders = fetchCustomerOrderHistory($pdo, (int) $customer['cus_id']);
$deliverySteps = ['Pending', 'Shipped', 'Out for Delivery', 'Delivered'];

renderStoreHeader('Order History', 'account');
?>
<main>
    <section class="site-shell page-banner compact">
        <span class="eyebrow">Order History</span>
        <h1>Your past orders</h1>
        <p>Track your order status, courier assignment, tracking ID, delivery date, and purchased items in one place.</p>
    </section>

    <section class="site-shell">
        <?php if ($orders === []): ?>
            <?php renderStoreEmptyState(
                'No orders yet',
                'Once you place an order from checkout, it will appear here with status badges and item details.',
                'Start Shopping',
                'products.php'
            ); ?>
        <?php else: ?>
            <div class="orders-list">
                <?php foreach ($orders as $order): ?>
                    <?php
                    $deliveryStatus = (string) ($order['deliveryStatus'] ?? 'Pending');
                    $deliveryStatusClass = (string) ($order['deliveryStatusClassName'] ?? storeDeliveryStatusClass($deliveryStatus));
                    $deliveryStep = (int) ($order['deliveryStep'] ?? 1);
                    $isCancelledDelivery = $deliveryStatus === 'Cancelled';
                    ?>
                    <article class="order-card order-card-enhanced">
                        <div class="order-head order-head-enhanced">
                            <div>
                                <strong>Order #<?= storeEscape((string) $order['orderId']); ?></strong>
                                <p><?= storeEscape(storeFormatDateTime((string) $order['orderDate'])); ?></p>
                            </div>
                            <div class="order-badge-stack">
                                <span class="status-badge <?= storeEscape(storeOrderStatusClass((string) $order['status'])); ?>">
                                    Order <?= storeEscape((string) $order['status']); ?>
                                </span>
                                <span class="status-badge <?= storeEscape($deliveryStatusClass); ?>">
                                    Delivery <?= storeEscape($deliveryStatus); ?>
                                </span>
                            </div>
                        </div>

                        <div class="order-summary-grid">
                            <div class="order-summary-tile">
                                <span>Amount</span>
                                <strong><?= storeEscape(storeCurrency((float) $order['totalAmount'])); ?></strong>
                            </div>
                            <div class="order-summary-tile">
                                <span>Payment</span>
                                <strong><?= storeEscape((string) $order['paymentMethod']); ?></strong>
                            </div>
                            <div class="order-summary-tile">
                                <span>Courier</span>
                                <strong><?= storeEscape((string) ($order['courierName'] !== '' ? $order['courierName'] : 'Not assigned')); ?></strong>
                            </div>
                            <div class="order-summary-tile">
                                <span>Delivery date</span>
                                <strong><?= storeEscape((string) $order['deliveryDateFormatted']); ?></strong>
                            </div>
                        </div>

                        <section class="tracking-panel <?= !empty($order['hasTrackingDetails']) ? 'has-tracking' : 'is-pending'; ?>">
                            <div class="tracking-panel-head">
                                <div>
                                    <span>Tracking ID</span>
                                    <strong class="tracking-code"><?= storeEscape((string) $order['trackingBadgeLabel']); ?></strong>
                                </div>
                                <span class="status-badge <?= storeEscape($deliveryStatusClass); ?>">
                                    <?= storeEscape($deliveryStatus); ?>
                                </span>
                            </div>

                            <?php if ($isCancelledDelivery): ?>
                                <div class="delivery-stepper is-cancelled">
                                    <span class="delivery-step is-active is-cancelled">Cancelled</span>
                                </div>
                            <?php else: ?>
                                <div class="delivery-stepper">
                                    <?php foreach ($deliverySteps as $stepIndex => $stepLabel): ?>
                                        <span class="delivery-step <?= ($stepIndex + 1) <= $deliveryStep ? 'is-active' : ''; ?>">
                                            <?= storeEscape($stepLabel); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div class="tracking-grid">
                                <div>
                                    <span>Courier name</span>
                                    <strong><?= storeEscape((string) ($order['courierName'] !== '' ? $order['courierName'] : 'Waiting for courier')); ?></strong>
                                </div>
                                <div>
                                    <span>Tracking reference</span>
                                    <strong class="tracking-code"><?= storeEscape((string) ($order['trackingId'] !== '' ? $order['trackingId'] : 'Not available yet')); ?></strong>
                                </div>
                                <div>
                                    <span>Shipment status</span>
                                    <strong><?= storeEscape($deliveryStatus); ?></strong>
                                </div>
                            </div>

                            <?php if (empty($order['hasTrackingDetails'])): ?>
                                <p class="tracking-note">Courier and tracking details will appear here after the admin assigns shipment details.</p>
                            <?php endif; ?>
                        </section>

                        <section class="order-address-box">
                            <strong>Delivery address</strong>
                            <p><?= nl2br(storeEscape((string) $order['shippingAddress'])); ?></p>
                        </section>

                        <hr>

                        <div class="order-items order-items-enhanced">
                            <?php foreach ($order['items'] as $item): ?>
                                <div class="order-item">
                                    <img src="<?= storeEscape((string) $item['imageUrl']); ?>" alt="<?= storeEscape((string) $item['name']); ?>">
                                    <div>
                                        <strong><?= storeEscape((string) $item['name']); ?></strong>
                                        <p><?= storeEscape((string) $item['brand']); ?></p>
                                        <p>
                                            Qty <?= (int) $item['quantity']; ?>
                                            - <?= storeEscape(storeCurrency((float) $item['totalPrice'])); ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php renderStoreFooter(); ?>
