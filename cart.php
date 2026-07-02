<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/store.php';

$pdo = getStorePdo();
$cart = buildStoreCartDetails($pdo);

renderStoreHeader('Cart', 'cart');
?>
<main>
    <section class="site-shell page-banner compact">
        <span class="eyebrow">Shopping Cart</span>
        <h1>Your selected pieces</h1>
    </section>

    <section class="site-shell">
        <?php if ($cart['items'] === []): ?>
            <?php renderStoreEmptyState(
                'Your cart is still empty',
                'Start exploring the catalog and add your favorite MOON s Fabric pieces here.',
                'Go to Shop',
                'products.php'
            ); ?>
        <?php else: ?>
            <div class="cart-layout">
                <div class="cart-items-list">
                    <?php foreach ($cart['items'] as $index => $item): ?>
                        <?php $quantityInputId = 'cart-quantity-' . $index; ?>
                        <article class="cart-item">
                            <img class="cart-item-image" src="<?= storeEscape((string) $item['product']['imageUrl']); ?>" alt="<?= storeEscape((string) $item['product']['name']); ?>">
                            <div class="cart-item-info">
                                <div class="cart-item-head">
                                    <div class="cart-item-summary">
                                        <strong class="cart-item-title"><?= storeEscape((string) $item['product']['name']); ?></strong>
                                        <p class="cart-item-meta">
                                            <?= storeEscape((string) $item['product']['brand']); ?>
                                            <span class="cart-separator">&bull;</span>
                                            Size <?= storeEscape((string) $item['size']); ?>
                                        </p>
                                    </div>
                                    <strong class="cart-item-price"><?= storeEscape(storeCurrency((float) $item['lineTotal'])); ?></strong>
                                </div>

                                <p class="cart-item-note">
                                    Unit price: <?= storeEscape(storeCurrency((float) $item['product']['price'])); ?>
                                    <?php if (!$item['isAvailable']): ?>
                                        <span class="cart-separator">&bull;</span>
                                        This item is currently unavailable
                                    <?php endif; ?>
                                </p>

                                <div class="cart-item-foot">
                                    <form class="inline-form" method="post" action="store-action.php">
                                        <input type="hidden" name="csrf_token" value="<?= storeEscape(getStoreCsrfToken()); ?>">
                                        <input type="hidden" name="action" value="update_cart_item">
                                        <input type="hidden" name="cart_key" value="<?= storeEscape((string) $item['cartKey']); ?>">
                                        <input type="hidden" name="redirect_to" value="cart.php">
                                        <div class="quantity-stepper">
                                            <button type="button" data-qty-step="down" data-target="<?= storeEscape($quantityInputId); ?>">-</button>
                                            <input id="<?= storeEscape($quantityInputId); ?>" type="number" name="quantity" min="1" max="20" value="<?= (int) $item['quantity']; ?>">
                                            <button type="button" data-qty-step="up" data-target="<?= storeEscape($quantityInputId); ?>">+</button>
                                        </div>
                                        <button class="btn btn-secondary" type="submit">Update</button>
                                    </form>

                                    <form class="cart-remove-form" method="post" action="store-action.php">
                                        <input type="hidden" name="csrf_token" value="<?= storeEscape(getStoreCsrfToken()); ?>">
                                        <input type="hidden" name="action" value="remove_cart_item">
                                        <input type="hidden" name="cart_key" value="<?= storeEscape((string) $item['cartKey']); ?>">
                                        <input type="hidden" name="redirect_to" value="cart.php">
                                        <button class="btn btn-secondary" type="submit">Remove</button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <aside class="summary-card">
                    <h2>Order summary</h2>
                    <p><?= storeEscape((string) $cart['quantityCount']); ?> item quantity selected across your cart.</p>
                    <hr>
                    <div class="summary-row">
                        <span>Subtotal</span>
                        <strong><?= storeEscape(storeCurrency((float) $cart['subtotal'])); ?></strong>
                    </div>
                    <div class="summary-row">
                        <span>Delivery</span>
                        <strong>Calculated at checkout</strong>
                    </div>
                    <hr>
                    <div class="summary-total">
                        <span>Total</span>
                        <strong><?= storeEscape(storeCurrency((float) $cart['subtotal'])); ?></strong>
                    </div>
                    <div class="split-actions" style="margin-top: 1.2rem;">
                        <a class="btn btn-gradient" href="checkout.php">Checkout</a>
                        <a class="btn btn-secondary" href="products.php">Continue shopping</a>
                    </div>
                </aside>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php renderStoreFooter(); ?>