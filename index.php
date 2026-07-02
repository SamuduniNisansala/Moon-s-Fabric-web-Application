<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/store.php';

$pdo = getStorePdo();
$trendingProducts = fetchTrendingProducts($pdo, 6);
$categories = fetchStoreCategories($pdo);
$stats = fetchStoreStats($pdo);
$currentUrl = storeCurrentRelativeUrl();
$heroSlides = [
    [
        'image' => STORE_BANNER_PATH,
        'title' => 'MOON Signature Drop',
        'caption' => 'Fresh new looks, soft textures, and bold feminine styling for your next outfit pick.',
    ],
    [
        'image' => 'img/banner/2cf506aa-77a6-45b7-8f6c-5f42a35a16c4.png',
        'title' => 'Mirror Glow Edit',
        'caption' => 'Confident silhouettes and standout styling moments curated for the spotlight.',
    ],
    [
        'image' => 'img/banner/08082250-387b-464f-8313-ce67bb2a1798.png',
        'title' => 'Weekend Glam Mood',
        'caption' => 'Dreamy statement pieces that bring soft-glam energy into every outing.',
    ],
    [
        'image' => 'img/banner/30704642-c513-467c-9882-f8f92cf5a3e8.png',
        'title' => 'Soft Chic Layers',
        'caption' => 'Polished feminine details designed to feel effortless from day to night.',
    ],
];

renderStoreHeader('Home', 'home');
?>
<main>
    <section class="site-shell hero hero-single">
        <div class="hero-media hero-media-single" data-slider data-slider-autoplay="4500">
            <div class="split-actions">
                <a class="btn btn-gradient" href="products.php">Shop Now</a>
                <a class="btn btn-secondary" href="account.php">Create Account</a>
            </div>

            <div class="hero-slider-track" data-slider-track>
                <?php foreach ($heroSlides as $index => $slide): ?>
                    <article class="hero-slide" data-slide="<?= (int) $index; ?>">
                        <img src="<?= storeEscape((string) $slide['image']); ?>" alt="<?= storeEscape((string) $slide['title']); ?>">
                        <div class="hero-overlay">
                            <strong><?= storeEscape((string) $slide['title']); ?></strong>
                            <span><?= storeEscape((string) $slide['caption']); ?></span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="hero-slider-controls">
                <button class="hero-arrow" type="button" data-slider-prev aria-label="Previous slide">&#10094;</button>
                <div class="hero-slider-dots">
                    <?php foreach ($heroSlides as $index => $slide): ?>
                        <button
                            class="hero-dot <?= $index === 0 ? 'is-active' : ''; ?>"
                            type="button"
                            data-slider-dot="<?= (int) $index; ?>"
                            aria-label="Show slide <?= (int) $index + 1; ?>"
                            aria-current="<?= $index === 0 ? 'true' : 'false'; ?>"></button>
                    <?php endforeach; ?>
                </div>
                <button class="hero-arrow" type="button" data-slider-next aria-label="Next slide">&#10095;</button>
            </div>
        </div>
    </section>

    <section class="site-shell offer-grid">
        <article class="offer-card">
            <strong>Signature Style Picks</strong>
            <p>Handpicked statement outfits and polished looks chosen for your next standout moment.</p>
            <span>Shop edits</span>
        </article>
        <article class="offer-card alt">
            <strong>Bank Transfer Friendly</strong>
            <p>Simple checkout with COD and bank transfer options built right into the order flow.</p>
            <span>Easy payment</span>
        </article>
        <article class="offer-card dark">
            <strong>New Arrivals Energy</strong>
            <p>Fresh cuts, soft glam silhouettes, and elegant statement edits from MOON s Fabric.</p>
            <span>Fresh drop</span>
        </article>
    </section>





    <section class="site-shell">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Trending</span>
                <h2>Trending products</h2>
            </div>
            <a class="btn btn-secondary" href="products.php">View all products</a>
        </div>

        <?php if ($trendingProducts === []): ?>
            <?php renderStoreEmptyState(
                'Products will appear here soon',
                'Add items from the admin panel to instantly populate the storefront catalog.',
                'Open Shop',
                'products.php'
            ); ?>
        <?php else: ?>
            <div class="product-grid">
                <?php foreach ($trendingProducts as $product): ?>
                    <?php renderStoreProductCard($product, $currentUrl); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php renderStoreFooter(); ?>