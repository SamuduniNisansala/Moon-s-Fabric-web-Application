<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/store.php';

if (!isCustomerAuthenticated()) {
    redirectStorePage('login.php', 'Please log in to view your profile.', 'error');
}

$pdo = getStorePdo();
$customer = fetchCurrentStoreCustomer($pdo);

if ($customer === null) {
    redirectStorePage('login.php', 'Your account could not be loaded right now.', 'error');
}

$orderHistory = fetchCustomerOrderHistory($pdo, (int) $customer['cus_id']);
$wishlistProducts = fetchStoreProductsByIds($pdo, getStoreWishlistProductIds());
$latestOrder = $orderHistory[0] ?? null;
$activeDeliveryCount = count(array_filter(
    $orderHistory,
    static fn(array $order): bool => !in_array((string) ($order['deliveryStatus'] ?? 'Pending'), ['Delivered', 'Cancelled'], true)
));

renderStoreHeader('Profile', 'account');
?>
<main>
    <section class="site-shell page-banner compact">
        <span class="eyebrow">Profile</span>
        <h1>Your customer account</h1>
    </section>

    <section class="site-shell profile-top-grid" style="margin-bottom: 1.3rem;">
        <article class="profile-quick-card">
            <strong><?= storeEscape((string) count($orderHistory)); ?></strong>
            <p>Total orders placed</p>
        </article>
        <article class="profile-quick-card">
            <strong><?= storeEscape((string) $activeDeliveryCount); ?></strong>
            <p>Active deliveries</p>
        </article>
        <article class="profile-quick-card">
            <strong><?= storeEscape((string) count($wishlistProducts)); ?></strong>
            <p>Wishlist items saved</p>
        </article>
        <article class="profile-quick-card">
            <strong><?= storeEscape($latestOrder !== null ? (string) ($latestOrder['trackingBadgeLabel'] ?? 'Pending') : 'No orders'); ?></strong>
            <p>Latest tracking ID</p>
        </article>
    </section>

    <section class="site-shell profile-layout">
        <div class="panel">
            <h2 style="margin-bottom: 10px ;text-align: center;" class="edit-pro">Edit your profile</h2>
            <form method="post" action="store-action.php">
                <input type="hidden" name="csrf_token" value="<?= storeEscape(getStoreCsrfToken()); ?>">
                <input type="hidden" name="action" value="update_profile">
                <input type="hidden" name="redirect_to" value="profile.php">

                <label class="field-label">
                    Name
                    <input type="text" name="name" required value="<?= storeEscape((string) $customer['name']); ?>">
                </label>

                <label class="field-label">
                    Email
                    <input type="email" name="email" required value="<?= storeEscape((string) $customer['email']); ?>">
                </label>

                <label class="field-label">
                    Phone
                    <input type="tel" name="phone" required value="<?= storeEscape((string) $customer['phone']); ?>">
                </label>

                <label class="field-label">
                    Address
                    <textarea name="address"><?= storeEscape((string) ($customer['address'] ?? '')); ?></textarea>
                </label>

                <label class="field-label">
                    City
                    <input type="text" name="city" required value="<?= storeEscape((string) ($customer['city'] ?? '')); ?>">
                </label>

                <button class="btn btn-gradient" type="submit">Save Profile</button>
            </form>
        </div>

        <aside class="account-stack">
            <article class="profile-quick-card profile-shortcuts-card">
                <h2>Account shortcuts</h2>
                <div class="profile-quick-list">
                    <span>Signed in as <?= storeEscape((string) $customer['email']); ?></span>
                    <a class="profile-shortcut-link" href="orders.php">
                        <span>View order history</span>
                    </a>
                    <a class="profile-shortcut-link is-primary" href="products.php">
                        <span>Continue shopping</span>
                    </a>
                </div>
            </article>

            <?php if ($latestOrder !== null): ?>
                <article class="profile-quick-card latest-delivery-card">
                    <h2>Latest delivery</h2>
                    <div class="profile-delivery-snapshot">
                        <div>
                            <span>Order</span>
                            <strong>#<?= storeEscape((string) $latestOrder['orderId']); ?></strong>
                        </div>
                        <div>
                            <span>Status</span>
                            <strong><?= storeEscape((string) ($latestOrder['deliveryStatus'] ?? 'Pending')); ?></strong>
                        </div>
                        <div>
                            <span>Courier</span>
                            <strong><?= storeEscape((string) (($latestOrder['courierName'] ?? '') !== '' ? $latestOrder['courierName'] : 'Not assigned')); ?></strong>
                        </div>
                        <div>
                            <span>Tracking</span>
                            <strong><?= storeEscape((string) ($latestOrder['trackingBadgeLabel'] ?? 'Tracking pending')); ?></strong>
                        </div>
                    </div>
                    <a class="btn btn-secondary" href="orders.php">Track Orders</a>
                </article>
            <?php endif; ?>

            <article class="profile-quick-card">
                <h2>Saved picks</h2>
                <?php if ($wishlistProducts === []): ?>
                    <p>No wishlist items yet. Save products from the shop or detail pages.</p>
                <?php else: ?>
                    <div class="saved-grid">
                        <?php foreach (array_slice(array_values($wishlistProducts), 0, 3) as $wishlistProduct): ?>
                            <a class="saved-card" href="product.php?id=<?= (int) $wishlistProduct['productId']; ?>">
                                <img src="<?= storeEscape((string) $wishlistProduct['imageUrl']); ?>" alt="<?= storeEscape((string) $wishlistProduct['name']); ?>">
                                <div>
                                    <strong><?= storeEscape((string) $wishlistProduct['name']); ?></strong>
                                    <p><?= storeEscape(storeCurrency((float) $wishlistProduct['price'])); ?></p>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>

            <article class="profile-quick-card">
                <h2 style="text-align: center;">Logout</h2>
                <form style="text-align: center;" method="post" action="store-action.php">
                    <input type="hidden" name="csrf_token" value="<?= storeEscape(getStoreCsrfToken()); ?>">
                    <input type="hidden" name="action" value="logout">
                    <input type="hidden" name="redirect_to" value="index.php">
                    <button class="btn btn-secondary" type="submit">Logout</button>
                </form>
            </article>
        </aside>
    </section>
</main>
<?php renderStoreFooter(); ?>
