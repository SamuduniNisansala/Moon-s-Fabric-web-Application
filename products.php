<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/store.php';

$pdo = getStorePdo();
$filters = buildStoreCatalogFilters($pdo);
$catalog = fetchStoreProducts($pdo, $filters, 9);
$categories = fetchStoreCategories($pdo);
$brands = fetchStoreBrands($pdo);
$currentUrl = storeCurrentRelativeUrl();

$queryForPagination = [
    'search' => $filters['search'],
    'category' => $filters['categoryId'] > 0 ? $filters['categoryId'] : null,
    'brand' => $filters['brand'] !== '' ? $filters['brand'] : null,
    'sort' => $filters['sort'],
    'min_price' => $filters['selectedMinPrice'],
    'max_price' => $filters['selectedMaxPrice'],
];

renderStoreHeader('Shop', 'shop');
?>
<main>
    <section class="site-shell page-banner">
        <span class="eyebrow">Product Listing</span>
        <h1>Shop the MOON s Fabric collection.</h1>
    </section>

    <section class="site-shell filters-layout">
        <aside class="filters-card">
            <h2>Filter the catalog</h2>
            <form method="get" action="products.php">
                <label class="field-label">
                    Search
                    <input type="text" name="search" value="<?= storeEscape((string) $filters['search']); ?>" placeholder="Search products or brands">
                </label>

                <label class="field-label">
                    Category
                    <select name="category">
                        <option value="0">All categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option
                                value="<?= (int) $category['categoryId']; ?>"
                                <?= (int) $filters['categoryId'] === (int) $category['categoryId'] ? 'selected' : ''; ?>>
                                <?= storeEscape((string) $category['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="field-label">
                    Brand
                    <select name="brand">
                        <option value="">All brands</option>
                        <?php foreach ($brands as $brand): ?>
                            <option value="<?= storeEscape($brand); ?>" <?= $filters['brand'] === $brand ? 'selected' : ''; ?>>
                                <?= storeEscape($brand); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="filter-range">
                    <span class="field-label">Price range</span>
                    <div class="filter-range-values">
                        <span>Min: <?= storeEscape(storeCurrency((float) $filters['selectedMinPrice'])); ?></span>
                        <span data-price-min-output><?= storeEscape((string) (int) $filters['selectedMinPrice']); ?></span>
                    </div>
                    <input
                        type="range"
                        name="min_price"
                        min="<?= storeEscape((string) (int) $filters['priceBounds']['min']); ?>"
                        max="<?= storeEscape((string) (int) $filters['priceBounds']['max']); ?>"
                        value="<?= storeEscape((string) (int) $filters['selectedMinPrice']); ?>"
                        data-price-min>
                    <div class="filter-range-values">
                        <span>Max: <?= storeEscape(storeCurrency((float) $filters['selectedMaxPrice'])); ?></span>
                        <span data-price-max-output><?= storeEscape((string) (int) $filters['selectedMaxPrice']); ?></span>
                    </div>
                    <input
                        type="range"
                        name="max_price"
                        min="<?= storeEscape((string) (int) $filters['priceBounds']['min']); ?>"
                        max="<?= storeEscape((string) (int) $filters['priceBounds']['max']); ?>"
                        value="<?= storeEscape((string) (int) $filters['selectedMaxPrice']); ?>"
                        data-price-max>
                </div>

                <label class="field-label">
                    Sort by
                    <select name="sort">
                        <?php foreach (STORE_SORT_OPTIONS as $sortValue => $sortLabel): ?>
                            <option value="<?= storeEscape($sortValue); ?>" <?= $filters['sort'] === $sortValue ? 'selected' : ''; ?>>
                                <?= storeEscape($sortLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="split-actions">
                    <button class="btn btn-gradient" type="submit">Apply filters</button>
                    <a class="btn btn-secondary" href="products.php">Reset</a>
                </div>
            </form>
        </aside>

        <div>
            <div class="toolbar">
                <strong><?= storeEscape((string) $catalog['pagination']['totalItems']); ?> item(s) found</strong>
                <span>Sort: <?= storeEscape(STORE_SORT_OPTIONS[$filters['sort']] ?? 'Newest'); ?></span>
            </div>

            <?php if ($catalog['items'] === []): ?>
                <?php renderStoreEmptyState(
                    'No matching products found',
                    'Try widening the price range or clearing a filter to see more products.',
                    'Clear Filters',
                    'products.php'
                ); ?>
            <?php else: ?>
                <div class="product-grid catalog-grid">
                    <?php foreach ($catalog['items'] as $product): ?>
                        <?php renderStoreProductCard($product, $currentUrl); ?>
                    <?php endforeach; ?>
                </div>
                <?php renderStorePagination($catalog['pagination'], 'products.php', $queryForPagination); ?>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php renderStoreFooter(); ?>