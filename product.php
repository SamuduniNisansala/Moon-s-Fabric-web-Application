<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/store.php';

$pdo = getStorePdo();
$productId = max(0, (int) ($_GET['id'] ?? 0));
$product = fetchStoreProduct($pdo, $productId);
$currentUrl = storeCurrentRelativeUrl();
$relatedProducts = $product !== null
    ? fetchRelatedProducts($pdo, (int) $product['productId'], (int) $product['categoryId'], 4)
    : [];

renderStoreHeader($product !== null ? (string) $product['name'] : 'Product', 'shop');
?>
<main>
    <section class="site-shell page-banner compact">
        <span class="eyebrow">Product Details</span>
        <h1><?= storeEscape($product !== null ? (string) $product['name'] : 'MOON s Fabric Product'); ?></h1>
    </section>

    <section class="site-shell">
        <?php if ($product === null): ?>
            <?php renderStoreEmptyState(
                'Product not found',
                'The item you selected is not available anymore. Browse the full collection instead.',
                'Back to Shop',
                'products.php'
            ); ?>
        <?php else: ?>
            <div class="detail-layout">
                <div class="gallery-panel">
                    <div class="image-stage">
                        <img
                            id="product-main-image"
                            src="<?= storeEscape((string) $product['images'][0]); ?>"
                            alt="<?= storeEscape((string) $product['name']); ?>">
                    </div>
                    <div class="gallery-strip">
                        <?php foreach ($product['images'] as $index => $image): ?>
                            <button
                                class="thumb-button <?= $index === 0 ? 'is-active' : ''; ?>"
                                type="button"
                                data-gallery-thumb
                                data-gallery-target="#product-main-image"
                                data-image-src="<?= storeEscape((string) $image); ?>">
                                <img src="<?= storeEscape((string) $image); ?>" alt="<?= storeEscape((string) $product['name']); ?> thumbnail">
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="product-overview">
                    <div>
                        <div class="product-meta-line">
                            <span><?= storeEscape((string) $product['brand']); ?></span>
                            <span><?= storeEscape((string) $product['categoryName']); ?></span>
                        </div>
                        <h1><?= storeEscape((string) $product['name']); ?></h1>
                        <div class="price-display"><?= storeEscape(storeCurrency((float) $product['price'])); ?></div>
                    </div>

                    <div class="selection-group">
                        <div class="cart-item-head">
                            <strong>Stock status</strong>
                            <span class="stock-pill <?= (int) $product['stockQuantity'] > 0 ? 'is-in-stock' : 'is-out-of-stock'; ?>">
                                <?= (int) $product['stockQuantity'] > 0 ? 'Ready to order' : 'Currently sold out'; ?>
                            </span>
                        </div>
                        <p><?= storeEscape((string) $product['stockQuantity']); ?> unit(s) currently available.</p>
                    </div>

                    <div class="description-box">
                        <strong>Description</strong>
                        <p>
                            <?= storeEscape(
                                trim((string) $product['description']) !== ''
                                    ? (string) $product['description']
                                    : 'A polished MOON s Fabric piece designed for effortless confidence, comfort, and standout styling.'
                            ); ?>
                        </p>
                    </div>

                    <form method="post" action="store-action.php" class="selection-group">
                        <input type="hidden" name="csrf_token" value="<?= storeEscape(getStoreCsrfToken()); ?>">
                        <input type="hidden" name="action" value="add_to_cart">
                        <input type="hidden" name="product_id" value="<?= (int) $product['productId']; ?>">
                        <input type="hidden" name="redirect_to" value="<?= storeEscape($currentUrl); ?>">

                        <div>
                            <strong>Choose size</strong>
                            <div class="size-selector" style="margin-top: 0.85rem;">
                                <?php foreach (STORE_SIZE_OPTIONS as $index => $size): ?>
                                    <label class="size-option">
                                        <input type="radio" name="size" value="<?= storeEscape($size); ?>" <?= $index === 1 ? 'checked' : ''; ?>>
                                        <span><?= storeEscape($size); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div>
                            <strong>Quantity</strong>
                            <div class="quantity-stepper" style="margin-top: 0.85rem;">
                                <button type="button" data-qty-step="down" data-target="product-quantity">-</button>
                                <input
                                    id="product-quantity"
                                    type="number"
                                    name="quantity"
                                    min="1"
                                    max="<?= storeEscape((string) max(1, (int) $product['stockQuantity'])); ?>"
                                    value="1">
                                <button type="button" data-qty-step="up" data-target="product-quantity">+</button>
                            </div>
                        </div>

                        <div class="detail-actions">
                            <?php if ((int) $product['stockQuantity'] > 0): ?>
                                <button class="btn btn-gradient" type="submit">Add to Cart</button>
                            <?php else: ?>
                                <span class="btn btn-disabled">Out of Stock</span>
                            <?php endif; ?>
                        </div>
                    </form>

                    <form method="post" action="store-action.php">
                        <input type="hidden" name="csrf_token" value="<?= storeEscape(getStoreCsrfToken()); ?>">
                        <input type="hidden" name="action" value="toggle_wishlist">
                        <input type="hidden" name="product_id" value="<?= (int) $product['productId']; ?>">
                        <input type="hidden" name="redirect_to" value="<?= storeEscape($currentUrl); ?>">
                        <button class="btn btn-secondary" type="submit">
                            <?= isProductWishlisted((int) $product['productId']) ? 'Remove from Wishlist' : 'Add to Wishlist'; ?>
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($relatedProducts !== []): ?>
        <section class="site-shell" style="margin-top: 2.4rem;">
            <div class="section-heading">
                <div>
                    <span class="eyebrow">More to Love</span>
                    <h2>Related products</h2>
                    <p>Other live picks from the storefront that pair with this edit.</p>
                </div>
            </div>
            <div class="product-grid">
                <?php foreach ($relatedProducts as $relatedProduct): ?>
                    <?php renderStoreProductCard($relatedProduct, $currentUrl); ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</main>
<?php renderStoreFooter(); ?>