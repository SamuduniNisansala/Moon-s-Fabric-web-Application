<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/store.php';

$pdo = getStorePdo();
$cart = buildStoreCartDetails($pdo);
$customer = fetchCurrentStoreCustomer($pdo);

renderStoreHeader('Checkout', 'cart');
?>
<main>
    <section class="site-shell page-banner compact">
        <span class="eyebrow">Checkout</span>
        <h1>Complete your order</h1>
        <p>Delivery details, payment method, and your full order summary are all in one place.</p>
    </section>

    <section class="site-shell">
        <?php if ($cart['items'] === []): ?>
            <?php renderStoreEmptyState(
                'Checkout is waiting for items',
                'Add a product to your cart first, then come back to place the order.',
                'Browse Products',
                'products.php'
            ); ?>
        <?php elseif ($customer === null): ?>
            <div class="checkout-layout">
                <div class="panel">
                    <h2>Login required before checkout</h2>
                    <p>Create an account or log in so we can save your order history and delivery details properly.</p>
                    <div class="split-actions" style="margin-top: 1rem;">
                        <a class="btn btn-gradient" href="login.php">Login</a>
                        <a class="btn btn-secondary" href="account.php?mode=register&redirect=checkout.php">Register</a>
                        <a class="btn btn-secondary" href="cart.php">Back to Cart</a>
                    </div>
                </div>

                <aside class="summary-card">
                    <h2>Order summary</h2>
                    <div class="checkout-summary-list">
                        <?php foreach ($cart['items'] as $item): ?>
                            <div class="summary-row" style="width: 100%;">
                                <span><?= storeEscape((string) $item['product']['name']); ?> x <?= (int) $item['quantity']; ?></span>
                                <strong><?= storeEscape(storeCurrency((float) $item['lineTotal'])); ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <hr>
                    <div class="summary-total">
                        <span>Total</span>
                        <strong><?= storeEscape(storeCurrency((float) $cart['subtotal'])); ?></strong>
                    </div>
                </aside>
            </div>
        <?php else: ?>
            <div class="checkout-layout">
                <div class="panel">
                    <h2>Delivery form</h2>
                    <p>Fill in your contact and address details, then choose the payment method you prefer.</p>
                    <form method="post" action="store-action.php">
                        <input type="hidden" name="csrf_token" value="<?= storeEscape(getStoreCsrfToken()); ?>">
                        <input type="hidden" name="action" value="place_order">

                        <label class="field-label">
                            Name
                            <input type="text" name="name" required value="<?= storeEscape((string) ($customer['name'] ?? '')); ?>">
                        </label>

                        <label class="field-label">
                            Phone
                            <input type="tel" name="phone" required value="<?= storeEscape((string) ($customer['phone'] ?? '')); ?>">
                        </label>

                        <label class="field-label">
                            Address
                            <textarea name="address" required><?= storeEscape((string) ($customer['address'] ?? '')); ?></textarea>
                        </label>

                        <label class="field-label">
                            City
                            <input type="text" name="city" required value="<?= storeEscape((string) ($customer['city'] ?? '')); ?>">
                        </label>

                        <div class="field-label">
                            Payment method
                            <div class="payment-selector">
                                <label class="payment-option">
                                    <input type="radio" name="payment_method" value="COD" checked>
                                    <span>Cash on Delivery (COD)</span>
                                </label>
                                <label class="payment-option">
                                    <input type="radio" name="payment_method" value="Bank Transfer">
                                    <span>Bank Transfer</span>
                                </label>
                            </div>
                        </div>

                        <button class="btn btn-gradient" type="submit">Place Order</button>
                    </form>
                </div>

                <aside class="summary-card">
                    <h2>Order summary</h2>
                    <p><?= storeEscape((string) $cart['quantityCount']); ?> total quantity selected.</p>
                    <div class="checkout-summary-list" style="margin-top: 1rem;">
                        <?php foreach ($cart['items'] as $item): ?>
                            <div class="summary-row" style="width: 100%;">
                                <span>
                                    <?= storeEscape((string) $item['product']['name']); ?>
                                    <small>Size <?= storeEscape((string) $item['size']); ?></small>
                                </span>
                                <strong><?= storeEscape(storeCurrency((float) $item['lineTotal'])); ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <hr>
                    <div class="summary-total">
                        <span>Total price</span>
                        <strong><?= storeEscape(storeCurrency((float) $cart['subtotal'])); ?></strong>
                    </div>
                </aside>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php renderStoreFooter(); ?>
