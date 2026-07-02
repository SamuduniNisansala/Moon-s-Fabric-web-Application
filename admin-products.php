<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin-auth.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/admin-order-management.php';

requireAdminAccess();

const PRODUCT_STATUS_OPTIONS = ['Active', 'Draft', 'Inactive'];
const PRODUCT_UPLOAD_DIRECTORY = 'uploads/products';
const MAX_PRODUCT_IMAGE_SIZE = 5242880;
const PRODUCTS_PER_PAGE = 8;

function buildProductManagementUrl(array $params = []): string
{
    $query = [];

    $search = trim((string) ($params['search'] ?? ''));
    if ($search !== '') {
        $query['search'] = $search;
    }

    $categoryId = (int) ($params['category_id'] ?? 0);
    if ($categoryId > 0) {
        $query['category_id'] = $categoryId;
    }

    $page = (int) ($params['page'] ?? 1);
    if ($page > 1) {
        $query['page'] = $page;
    }

    $edit = (int) ($params['edit'] ?? 0);
    if ($edit > 0) {
        $query['edit'] = $edit;
    }

    return 'admin-products.php' . ($query !== [] ? '?' . http_build_query($query) : '');
}

function formatProductCurrency(float $amount, string $currency = 'LKR'): string
{
    return $currency . ' ' . number_format($amount, 2);
}

function formatProductDate(string $value): string
{
    $timestamp = strtotime($value);
    return $timestamp ? date('d M Y', $timestamp) : $value;
}

function getProductStatusClassName(string $status): string
{
    return match ($status) {
        'Active' => 'status-active',
        'Draft' => 'status-draft',
        'Inactive' => 'status-inactive',
        default => 'status-neutral',
    };
}

function defaultProductForm(array $categories): array
{
    return [
        'product_id' => 0,
        'name' => '',
        'brand' => '',
        'price' => '',
        'description' => '',
        'category_id' => $categories !== [] ? (string) $categories[0]['category_id'] : '',
        'product_status' => 'Active',
        'stock_quantity' => '0',
        'remove_image' => false,
        'existing_image_url' => '',
    ];
}

function fetchProductCategories(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT category_id, name, status
         FROM category
         ORDER BY name ASC'
    );

    return $statement->fetchAll() ?: [];
}

function fetchProductSummary(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT
            COUNT(*) AS total_products,
            COALESCE(SUM(product_status = "Active"), 0) AS active_products,
            COALESCE(SUM(product_status = "Draft"), 0) AS draft_products,
            COALESCE(SUM(stock_quantity <= 5), 0) AS low_stock_products
         FROM product'
    );

    $summary = $statement->fetch() ?: [];

    return [
        'totalProducts' => (int) ($summary['total_products'] ?? 0),
        'activeProducts' => (int) ($summary['active_products'] ?? 0),
        'draftProducts' => (int) ($summary['draft_products'] ?? 0),
        'lowStockProducts' => (int) ($summary['low_stock_products'] ?? 0),
    ];
}

function latestProductImageJoinSql(): string
{
    return 'LEFT JOIN product_image product_image_latest
                ON product_image_latest.image_id = (
                    SELECT pi2.image_id
                    FROM product_image pi2
                    WHERE pi2.product_id = p.product_id
                    ORDER BY pi2.is_main DESC, pi2.image_id DESC
                    LIMIT 1
                )';
}

function fetchPagedProducts(PDO $pdo, string $search, int $categoryId, int $page, int $perPage): array
{
    $page = max(1, $page);
    $perPage = max(1, min(12, $perPage));

    $where = [];
    $parameters = [];

    if ($search !== '') {
        $where[] = '(p.name LIKE :search_name OR COALESCE(p.brand, "") LIKE :search_brand OR c.name LIKE :search_category)';
        $searchTerm = '%' . $search . '%';
        $parameters['search_name'] = $searchTerm;
        $parameters['search_brand'] = $searchTerm;
        $parameters['search_category'] = $searchTerm;
    }

    if ($categoryId > 0) {
        $where[] = 'p.category_id = :category_id';
        $parameters['category_id'] = $categoryId;
    }

    $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStatement = $pdo->prepare(
        "SELECT COUNT(*)
         FROM product p
         INNER JOIN category c ON c.category_id = p.category_id
         {$whereSql}"
    );

    foreach ($parameters as $name => $value) {
        $countStatement->bindValue(
            ':' . $name,
            $value,
            $name === 'category_id' ? PDO::PARAM_INT : PDO::PARAM_STR
        );
    }

    $countStatement->execute();
    $totalItems = (int) $countStatement->fetchColumn();
    $totalPages = max(1, (int) ceil($totalItems / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $productsStatement = $pdo->prepare(
        "SELECT
            p.product_id,
            p.category_id,
            p.name,
            COALESCE(p.brand, '') AS brand,
            COALESCE(p.description, '') AS description,
            p.base_price,
            p.stock_quantity,
            p.product_status,
            p.created_at,
            c.name AS category_name,
            COALESCE(product_image_latest.image_url, '') AS image_url
         FROM product p
         INNER JOIN category c ON c.category_id = p.category_id
         " . latestProductImageJoinSql() . "
         {$whereSql}
         ORDER BY p.created_at DESC, p.product_id DESC
         LIMIT :limit OFFSET :offset"
    );

    foreach ($parameters as $name => $value) {
        $productsStatement->bindValue(
            ':' . $name,
            $value,
            $name === 'category_id' ? PDO::PARAM_INT : PDO::PARAM_STR
        );
    }

    $productsStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $productsStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $productsStatement->execute();

    return [
        'items' => $productsStatement->fetchAll() ?: [],
        'pagination' => [
            'currentPage' => $page,
            'perPage' => $perPage,
            'totalItems' => $totalItems,
            'totalPages' => $totalPages,
        ],
    ];
}

function fetchProductById(PDO $pdo, int $productId): ?array
{
    $statement = $pdo->prepare(
        "SELECT
            p.product_id,
            p.category_id,
            p.name,
            COALESCE(p.brand, '') AS brand,
            COALESCE(p.description, '') AS description,
            p.base_price,
            p.stock_quantity,
            p.product_status,
            p.created_at,
            c.name AS category_name,
            COALESCE(product_image_latest.image_url, '') AS image_url
         FROM product p
         INNER JOIN category c ON c.category_id = p.category_id
         " . latestProductImageJoinSql() . "
         WHERE p.product_id = :product_id
         LIMIT 1"
    );

    $statement->execute(['product_id' => $productId]);
    $product = $statement->fetch();

    return $product ?: null;
}

function ensureProductExists(PDO $pdo, int $productId): void
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM product WHERE product_id = :product_id');
    $statement->execute(['product_id' => $productId]);

    if ((int) $statement->fetchColumn() === 0) {
        throw new RuntimeException('The selected product could not be found.');
    }
}

function ensureProductCategoryExists(PDO $pdo, int $categoryId): void
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM category
         WHERE category_id = :category_id'
    );
    $statement->execute(['category_id' => $categoryId]);

    if ((int) $statement->fetchColumn() === 0) {
        throw new RuntimeException('Please choose a valid category.');
    }
}

function normalizeProductPayload(PDO $pdo, array $input): array
{
    $name = trim((string) ($input['name'] ?? ''));
    $brand = trim((string) ($input['brand'] ?? ''));
    $priceRaw = trim((string) ($input['price'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $categoryId = (int) ($input['category_id'] ?? 0);
    $status = trim((string) ($input['product_status'] ?? 'Active'));
    $stockRaw = trim((string) ($input['stock_quantity'] ?? '0'));
    $removeImage = isset($input['remove_image']) && (string) $input['remove_image'] === '1';

    if ($name === '') {
        throw new RuntimeException('Product name is required.');
    }

    if ($priceRaw === '' || !is_numeric($priceRaw)) {
        throw new RuntimeException('Please enter a valid product price.');
    }

    $price = round((float) $priceRaw, 2);
    if ($price < 0) {
        throw new RuntimeException('Product price cannot be negative.');
    }

    if (filter_var($stockRaw, FILTER_VALIDATE_INT) === false || (int) $stockRaw < 0) {
        throw new RuntimeException('Stock quantity must be a whole number of zero or more.');
    }

    if (!in_array($status, PRODUCT_STATUS_OPTIONS, true)) {
        throw new RuntimeException('Please choose a valid product status.');
    }

    if ($categoryId <= 0) {
        throw new RuntimeException('Please choose a category before saving the product.');
    }

    ensureProductCategoryExists($pdo, $categoryId);

    return [
        'name' => $name,
        'brand' => $brand,
        'price' => $price,
        'description' => $description,
        'category_id' => $categoryId,
        'product_status' => $status,
        'stock_quantity' => (int) $stockRaw,
        'remove_image' => $removeImage,
    ];
}

function ensureProductUploadDirectory(): string
{
    $directory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products';

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the product image upload directory.');
    }

    return $directory;
}

function saveUploadedProductImage(?array $file): ?string
{
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The product image could not be uploaded.');
    }

    if ((int) ($file['size'] ?? 0) > MAX_PRODUCT_IMAGE_SIZE) {
        throw new RuntimeException('Product images must be 5MB or smaller.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($tmpName) ?: '';

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (!isset($extensions[$mimeType])) {
        throw new RuntimeException('Please upload a JPG, PNG, WEBP, or GIF image.');
    }

    $directory = ensureProductUploadDirectory();
    $fileName = 'product-' . bin2hex(random_bytes(10)) . '.' . $extensions[$mimeType];
    $destination = $directory . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('The uploaded product image could not be saved.');
    }

    return PRODUCT_UPLOAD_DIRECTORY . '/' . $fileName;
}

function fetchProductImagePaths(PDO $pdo, int $productId): array
{
    $statement = $pdo->prepare(
        'SELECT image_url
         FROM product_image
         WHERE product_id = :product_id'
    );
    $statement->execute(['product_id' => $productId]);

    return array_map(
        static fn(array $row): string => (string) $row['image_url'],
        $statement->fetchAll() ?: []
    );
}

function deleteProductImageRows(PDO $pdo, int $productId): array
{
    $imagePaths = fetchProductImagePaths($pdo, $productId);

    $deleteStatement = $pdo->prepare(
        'DELETE FROM product_image
         WHERE product_id = :product_id'
    );
    $deleteStatement->execute(['product_id' => $productId]);

    return $imagePaths;
}

function deleteLocalProductFiles(array $imagePaths): void
{
    foreach ($imagePaths as $imagePath) {
        $normalizedPath = str_replace('\\', '/', $imagePath);

        if (!str_starts_with($normalizedPath, PRODUCT_UPLOAD_DIRECTORY . '/')) {
            continue;
        }

        $absolutePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath);
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }
}

function createProductRecord(PDO $pdo, array $payload, ?string $uploadedImagePath): void
{
    try {
        $pdo->beginTransaction();

        $statement = $pdo->prepare(
            'INSERT INTO product (
                category_id,
                name,
                brand,
                description,
                base_price,
                stock_quantity,
                product_status
             ) VALUES (
                :category_id,
                :name,
                :brand,
                :description,
                :base_price,
                :stock_quantity,
                :product_status
             )'
        );

        $statement->execute([
            'category_id' => $payload['category_id'],
            'name' => $payload['name'],
            'brand' => $payload['brand'] !== '' ? $payload['brand'] : null,
            'description' => $payload['description'] !== '' ? $payload['description'] : null,
            'base_price' => $payload['price'],
            'stock_quantity' => $payload['stock_quantity'],
            'product_status' => $payload['product_status'],
        ]);

        $productId = (int) $pdo->lastInsertId();

        if ($uploadedImagePath !== null) {
            $imageStatement = $pdo->prepare(
                'INSERT INTO product_image (product_id, image_url, is_main)
                 VALUES (:product_id, :image_url, 1)'
            );
            $imageStatement->execute([
                'product_id' => $productId,
                'image_url' => $uploadedImagePath,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($uploadedImagePath !== null) {
            deleteLocalProductFiles([$uploadedImagePath]);
        }

        throw $exception;
    }
}

function updateProductRecord(PDO $pdo, int $productId, array $payload, ?string $uploadedImagePath): void
{
    ensureProductExists($pdo, $productId);
    $imagePathsToDelete = [];

    try {
        $pdo->beginTransaction();

        $statement = $pdo->prepare(
            'UPDATE product
             SET
                category_id = :category_id,
                name = :name,
                brand = :brand,
                description = :description,
                base_price = :base_price,
                stock_quantity = :stock_quantity,
                product_status = :product_status
             WHERE product_id = :product_id'
        );

        $statement->execute([
            'category_id' => $payload['category_id'],
            'name' => $payload['name'],
            'brand' => $payload['brand'] !== '' ? $payload['brand'] : null,
            'description' => $payload['description'] !== '' ? $payload['description'] : null,
            'base_price' => $payload['price'],
            'stock_quantity' => $payload['stock_quantity'],
            'product_status' => $payload['product_status'],
            'product_id' => $productId,
        ]);

        if ($payload['remove_image'] || $uploadedImagePath !== null) {
            $imagePathsToDelete = deleteProductImageRows($pdo, $productId);
        }

        if ($uploadedImagePath !== null) {
            $imageStatement = $pdo->prepare(
                'INSERT INTO product_image (product_id, image_url, is_main)
                 VALUES (:product_id, :image_url, 1)'
            );
            $imageStatement->execute([
                'product_id' => $productId,
                'image_url' => $uploadedImagePath,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($uploadedImagePath !== null) {
            deleteLocalProductFiles([$uploadedImagePath]);
        }

        throw $exception;
    }

    if ($imagePathsToDelete !== []) {
        deleteLocalProductFiles($imagePathsToDelete);
    }
}

function deleteProductRecord(PDO $pdo, int $productId): void
{
    ensureProductExists($pdo, $productId);
    $imagePathsToDelete = [];

    try {
        $pdo->beginTransaction();

        $imagePathsToDelete = deleteProductImageRows($pdo, $productId);

        $statement = $pdo->prepare(
            'DELETE FROM product
             WHERE product_id = :product_id'
        );
        $statement->execute(['product_id' => $productId]);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }

    if ($imagePathsToDelete !== []) {
        deleteLocalProductFiles($imagePathsToDelete);
    }
}

$pdo = getDatabaseConnection();
$adminName = (string) ($_SESSION['admin_name'] ?? 'Admin');
$lastLogin = (string) ($_SESSION['admin_last_login'] ?? date('Y-m-d H:i:s'));

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestSource = $requestMethod === 'POST' ? $_POST : $_GET;
$search = trim((string) ($requestSource['return_search'] ?? $requestSource['search'] ?? ''));
$categoryFilter = max(0, (int) ($requestSource['return_category_id'] ?? $requestSource['category_id'] ?? 0));
$currentPage = max(1, (int) ($requestSource['return_page'] ?? $requestSource['page'] ?? 1));

$categories = fetchProductCategories($pdo);
$summary = fetchProductSummary($pdo);
$formMode = 'create';
$formError = '';
$pageError = '';
$successMessage = pullFlashMessage('product_success');
$pageError = pullFlashMessage('product_error') ?? '';
$formValues = defaultProductForm($categories);

if ($requestMethod === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $formError = 'Your session has expired. Please refresh the page and try again.';
    } elseif ($action === 'delete') {
        $deleteProductId = (int) ($_POST['product_id'] ?? 0);

        try {
            if ($deleteProductId <= 0) {
                throw new RuntimeException('Please choose a valid product to delete.');
            }

            deleteProductRecord($pdo, $deleteProductId);
            setFlashMessage('product_success', 'Product deleted successfully.');
        } catch (Throwable $exception) {
            setFlashMessage('product_error', $exception->getMessage());
        }

        header('Location: ' . buildProductManagementUrl([
            'search' => $search,
            'category_id' => $categoryFilter,
            'page' => $currentPage,
        ]));
        exit;
    } elseif ($action === 'save') {
        $formValues = [
            'product_id' => max(0, (int) ($_POST['product_id'] ?? 0)),
            'name' => trim((string) ($_POST['name'] ?? '')),
            'brand' => trim((string) ($_POST['brand'] ?? '')),
            'price' => trim((string) ($_POST['price'] ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'category_id' => trim((string) ($_POST['category_id'] ?? '')),
            'product_status' => trim((string) ($_POST['product_status'] ?? 'Active')),
            'stock_quantity' => trim((string) ($_POST['stock_quantity'] ?? '0')),
            'remove_image' => isset($_POST['remove_image']) && (string) $_POST['remove_image'] === '1',
            'existing_image_url' => trim((string) ($_POST['existing_image_url'] ?? '')),
        ];
        $formMode = $formValues['product_id'] > 0 ? 'edit' : 'create';

        try {
            $payload = normalizeProductPayload($pdo, $_POST);
            $uploadedImagePath = saveUploadedProductImage($_FILES['image'] ?? null);

            if ($formValues['product_id'] > 0) {
                updateProductRecord($pdo, (int) $formValues['product_id'], $payload, $uploadedImagePath);
                setFlashMessage('product_success', 'Product updated successfully.');
            } else {
                createProductRecord($pdo, $payload, $uploadedImagePath);
                setFlashMessage('product_success', 'Product created successfully.');
            }

            header('Location: ' . buildProductManagementUrl([
                'search' => $search,
                'category_id' => $categoryFilter,
                'page' => $currentPage,
            ]));
            exit;
        } catch (Throwable $exception) {
            $formError = $exception->getMessage();
        }
    }
} else {
    $editProductId = max(0, (int) ($_GET['edit'] ?? 0));

    if ($editProductId > 0) {
        $editingProduct = fetchProductById($pdo, $editProductId);

        if ($editingProduct === null) {
            $pageError = 'The selected product could not be found.';
        } else {
            $formMode = 'edit';
            $formValues = [
                'product_id' => (int) $editingProduct['product_id'],
                'name' => (string) $editingProduct['name'],
                'brand' => (string) $editingProduct['brand'],
                'price' => number_format((float) $editingProduct['base_price'], 2, '.', ''),
                'description' => (string) $editingProduct['description'],
                'category_id' => (string) $editingProduct['category_id'],
                'product_status' => (string) $editingProduct['product_status'],
                'stock_quantity' => (string) $editingProduct['stock_quantity'],
                'remove_image' => false,
                'existing_image_url' => (string) $editingProduct['image_url'],
            ];
        }
    }
}

$productsData = fetchPagedProducts($pdo, $search, $categoryFilter, $currentPage, PRODUCTS_PER_PAGE);
$products = $productsData['items'];
$pagination = $productsData['pagination'];
$noCategories = $categories === [];
$totalPages = (int) $pagination['totalPages'];
$currentPage = (int) $pagination['currentPage'];
$totalItems = (int) $pagination['totalItems'];
$pageStart = $totalItems > 0 ? (($currentPage - 1) * PRODUCTS_PER_PAGE) + 1 : 0;
$pageEnd = min($totalItems, $currentPage * PRODUCTS_PER_PAGE);
$pageNumberStart = max(1, $currentPage - 2);
$pageNumberEnd = min($totalPages, $pageNumberStart + 4);
$menuItems = buildAdminManagementMenu('products');
$adminProductsStylesheetVersion = (string) filemtime(__DIR__ . '/assets/css/admin-products.css');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Management | MOON s Fabric Shop</title>
    <link rel="stylesheet" href="assets/css/admin-products.css?v=<?= escape($adminProductsStylesheetVersion); ?>">
</head>

<body class="products-body">
    <button class="sidebar-overlay" data-sidebar-overlay hidden aria-label="Close menu"></button>

    <div class="dashboard-app">
        <aside class="dashboard-sidebar" data-dashboard-sidebar>
            <div class="sidebar-brand">
                <div>
                    <span class="sidebar-eyebrow">MOON s Fabric Shop</span>
                    <h1>Product Admin</h1>
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
                        <span class="dashboard-label">Product Management</span>
                        <h2>Manage fashion catalog products</h2>
                    </div>
                </div>

                <div class="topbar-right">
                    <span class="topbar-meta-label">Last login</span>
                    <span class="topbar-meta-value"><?= escape(formatProductDate($lastLogin)); ?></span>
                </div>
            </header>

            <main class="dashboard-content">
                <?php if ($pageError !== ''): ?>
                    <div class="status-message is-error">
                        <?= escape($pageError); ?>
                    </div>
                <?php endif; ?>

                <?php if ($successMessage): ?>
                    <div class="status-message is-success">
                        <?= escape($successMessage); ?>
                    </div>
                <?php endif; ?>

                <?php if ($noCategories): ?>
                    <div class="status-message is-warning">
                        No categories are available yet. Add at least one category from
                        <a href="admin-categories.php" class="toolbar-link">Category Management</a>,
                        then products can be created under that category.
                    </div>
                <?php endif; ?>

                <section class="metric-grid">
                    <article class="metric-card tone-blue">
                        <span class="metric-title">Total Products</span>
                        <strong class="metric-value"><?= escape(number_format((int) $summary['totalProducts'])); ?></strong>
                        <span class="metric-text">All catalog products</span>
                    </article>
                    <article class="metric-card tone-sky">
                        <span class="metric-title">Active Products</span>
                        <strong class="metric-value"><?= escape(number_format((int) $summary['activeProducts'])); ?></strong>
                        <span class="metric-text">Products ready for selling</span>
                    </article>
                    <article class="metric-card tone-slate">
                        <span class="metric-title">Draft Products</span>
                        <strong class="metric-value"><?= escape(number_format((int) $summary['draftProducts'])); ?></strong>
                        <span class="metric-text">Products waiting for review</span>
                    </article>
                    <article class="metric-card tone-amber">
                        <span class="metric-title">Low Stock Alert</span>
                        <strong class="metric-value"><?= escape(number_format((int) $summary['lowStockProducts'])); ?></strong>
                        <span class="metric-text">Items with stock of 5 or less</span>
                    </article>
                </section>

                <section class="panel-grid">
                    <article class="products-card products-card-wide">
                        <div class="section-head">
                            <div class="section-copy">
                                <span class="dashboard-label">Catalog Controls</span>
                                <h3>Search, filter, and review products</h3>
                            </div>
                            <div class="toolbar-actions">
                                <a class="toolbar-button" href="<?= escape(buildProductManagementUrl()); ?>#product-form">
                                    Add Product
                                </a>
                            </div>
                        </div>

                        <form method="get" class="filters-form">
                            <div class="filter-grid">
                                <label class="input-group">
                                    <span>Search products</span>
                                    <input
                                        type="search"
                                        name="search"
                                        value="<?= escape($search); ?>"
                                        placeholder="Search by name, brand, or category">
                                </label>

                                <label class="input-group">
                                    <span>Filter by category</span>
                                    <select name="category_id">
                                        <option value="0">All categories</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option
                                                value="<?= escape((string) $category['category_id']); ?>"
                                                <?= $categoryFilter === (int) $category['category_id'] ? 'selected' : ''; ?>>
                                                <?= escape((string) $category['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>

                                <div class="filter-actions">
                                    <button type="submit" class="toolbar-button">Apply Filters</button>
                                    <a href="admin-products.php" class="toolbar-link">Reset</a>
                                </div>
                            </div>
                        </form>

                        <div class="list-meta">
                            <div class="list-meta-copy">
                                Showing <?= escape((string) $pageStart); ?>-<?= escape((string) $pageEnd); ?>
                                of <?= escape((string) $totalItems); ?> products
                            </div>
                            <?php if ($formMode === 'edit'): ?>
                                <a class="toolbar-link" href="<?= escape(buildProductManagementUrl([
                                                                    'search' => $search,
                                                                    'category_id' => $categoryFilter,
                                                                    'page' => $currentPage,
                                                                ])); ?>">
                                    Cancel edit
                                </a>
                            <?php endif; ?>
                        </div>

                        <div class="table-shell">
                            <table class="products-table">
                                <thead>
                                    <tr>
                                        <th>Product Image</th>
                                        <th>Product Name</th>
                                        <th>Brand</th>
                                        <th>Category</th>
                                        <th>Price</th>
                                        <th>Stock</th>
                                        <th>Status</th>
                                        <th class="align-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($products === []): ?>
                                        <tr>
                                            <td colspan="8" class="empty-row">
                                                <?php if ((int) $summary['totalProducts'] === 0): ?>
                                                    No products have been added yet.
                                                <?php else: ?>
                                                    No matching products were found for the current filters.
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($products as $product): ?>
                                            <tr>
                                                <td>
                                                    <?php if ((string) $product['image_url'] !== ''): ?>
                                                        <img
                                                            src="<?= escape((string) $product['image_url']); ?>"
                                                            alt="<?= escape((string) $product['name']); ?>"
                                                            class="product-thumbnail">
                                                    <?php else: ?>
                                                        <div class="product-placeholder">No Img</div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="table-primary"><?= escape((string) $product['name']); ?></div>
                                                    <div class="table-secondary">
                                                        <?= escape((string) ($product['description'] ?: 'No description added')); ?>
                                                    </div>
                                                </td>
                                                <td><?= escape((string) ($product['brand'] ?: 'N/A')); ?></td>
                                                <td><?= escape((string) $product['category_name']); ?></td>
                                                <td class="strong-cell">
                                                    <?= escape(formatProductCurrency((float) $product['base_price'])); ?>
                                                </td>
                                                <td>
                                                    <span class="stock-chip<?= (int) $product['stock_quantity'] <= 5 ? ' is-low' : ''; ?>">
                                                        <?= escape((string) $product['stock_quantity']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="status-chip <?= escape(getProductStatusClassName((string) $product['product_status'])); ?>">
                                                        <?= escape((string) $product['product_status']); ?>
                                                    </span>
                                                </td>
                                                <td class="align-right">
                                                    <div class="action-row">
                                                        <a
                                                            href="<?= escape(buildProductManagementUrl([
                                                                        'search' => $search,
                                                                        'category_id' => $categoryFilter,
                                                                        'page' => $currentPage,
                                                                        'edit' => (int) $product['product_id'],
                                                                    ])); ?>#product-form"
                                                            class="action-button secondary">
                                                            Edit
                                                        </a>
                                                        <form method="post" onsubmit="return confirm('Delete this product from the catalog?');">
                                                            <input type="hidden" name="csrf_token" value="<?= escape(getCsrfToken()); ?>">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="product_id" value="<?= escape((string) $product['product_id']); ?>">
                                                            <input type="hidden" name="return_search" value="<?= escape($search); ?>">
                                                            <input type="hidden" name="return_category_id" value="<?= escape((string) $categoryFilter); ?>">
                                                            <input type="hidden" name="return_page" value="<?= escape((string) $currentPage); ?>">
                                                            <button type="submit" class="action-button danger">Delete</button>
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
                            <?php if ($products === []): ?>
                                <div class="empty-card">
                                    <?php if ((int) $summary['totalProducts'] === 0): ?>
                                        No products have been added yet.
                                    <?php else: ?>
                                        No matching products were found for the current filters.
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <?php foreach ($products as $product): ?>
                                    <article class="mobile-product-card">
                                        <div class="mobile-product-head">
                                            <?php if ((string) $product['image_url'] !== ''): ?>
                                                <img
                                                    src="<?= escape((string) $product['image_url']); ?>"
                                                    alt="<?= escape((string) $product['name']); ?>"
                                                    class="product-thumbnail large">
                                            <?php else: ?>
                                                <div class="product-placeholder large">No Img</div>
                                            <?php endif; ?>

                                            <div class="mobile-product-copy">
                                                <h4><?= escape((string) $product['name']); ?></h4>
                                                <p><?= escape((string) ($product['brand'] ?: 'No brand')); ?> | <?= escape((string) $product['category_name']); ?></p>
                                            </div>
                                        </div>

                                        <div class="mobile-product-grid">
                                            <div>
                                                <span>Price</span>
                                                <strong><?= escape(formatProductCurrency((float) $product['base_price'])); ?></strong>
                                            </div>
                                            <div>
                                                <span>Stock</span>
                                                <strong><?= escape((string) $product['stock_quantity']); ?></strong>
                                            </div>
                                            <div>
                                                <span>Status</span>
                                                <strong><?= escape((string) $product['product_status']); ?></strong>
                                            </div>
                                            <div>
                                                <span>Added</span>
                                                <strong><?= escape(formatProductDate((string) $product['created_at'])); ?></strong>
                                            </div>
                                        </div>

                                        <div class="action-row mobile-actions">
                                            <a
                                                href="<?= escape(buildProductManagementUrl([
                                                            'search' => $search,
                                                            'category_id' => $categoryFilter,
                                                            'page' => $currentPage,
                                                            'edit' => (int) $product['product_id'],
                                                        ])); ?>#product-form"
                                                class="action-button secondary">
                                                Edit
                                            </a>
                                            <form method="post" class="full-width" onsubmit="return confirm('Delete this product from the catalog?');">
                                                <input type="hidden" name="csrf_token" value="<?= escape(getCsrfToken()); ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="product_id" value="<?= escape((string) $product['product_id']); ?>">
                                                <input type="hidden" name="return_search" value="<?= escape($search); ?>">
                                                <input type="hidden" name="return_category_id" value="<?= escape((string) $categoryFilter); ?>">
                                                <input type="hidden" name="return_page" value="<?= escape((string) $currentPage); ?>">
                                                <button type="submit" class="action-button danger full-width">Delete</button>
                                            </form>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <?php if ($totalPages > 1): ?>
                            <nav class="pagination">
                                <a
                                    href="<?= escape(buildProductManagementUrl([
                                                'search' => $search,
                                                'category_id' => $categoryFilter,
                                                'page' => max(1, $currentPage - 1),
                                                'edit' => $formMode === 'edit' ? (int) $formValues['product_id'] : 0,
                                            ])); ?>"
                                    class="page-link<?= $currentPage <= 1 ? ' is-disabled' : ''; ?>">
                                    Previous
                                </a>

                                <?php for ($pageNumber = $pageNumberStart; $pageNumber <= $pageNumberEnd; $pageNumber++): ?>
                                    <a
                                        href="<?= escape(buildProductManagementUrl([
                                                    'search' => $search,
                                                    'category_id' => $categoryFilter,
                                                    'page' => $pageNumber,
                                                    'edit' => $formMode === 'edit' ? (int) $formValues['product_id'] : 0,
                                                ])); ?>"
                                        class="page-link<?= $pageNumber === $currentPage ? ' is-active' : ''; ?>">
                                        <?= escape((string) $pageNumber); ?>
                                    </a>
                                <?php endfor; ?>

                                <a
                                    href="<?= escape(buildProductManagementUrl([
                                                'search' => $search,
                                                'category_id' => $categoryFilter,
                                                'page' => min($totalPages, $currentPage + 1),
                                                'edit' => $formMode === 'edit' ? (int) $formValues['product_id'] : 0,
                                            ])); ?>"
                                    class="page-link<?= $currentPage >= $totalPages ? ' is-disabled' : ''; ?>">
                                    Next
                                </a>
                            </nav>
                        <?php endif; ?>
                    </article>

                    <aside class="products-card sticky-card" id="product-form">
                        <div class="section-head">
                            <div class="section-copy">
                                <span class="dashboard-label"><?= $formMode === 'edit' ? 'Edit Product' : 'Add Product'; ?></span>
                                <h3><?= $formMode === 'edit' ? 'Update product details' : 'Create a new catalog product'; ?></h3>
                                <p><?= $formMode === 'edit' ? 'Adjust product fields, image, price, and stock.' : 'Add a new product to the MOON s Fabric Shop catalog.'; ?></p>
                            </div>
                        </div>

                        <?php if ($formError !== ''): ?>
                            <div class="status-message is-error form-message">
                                <?= escape($formError); ?>
                            </div>
                        <?php endif; ?>

                        <form method="post" enctype="multipart/form-data" class="product-form">
                            <input type="hidden" name="csrf_token" value="<?= escape(getCsrfToken()); ?>">
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="product_id" value="<?= escape((string) $formValues['product_id']); ?>">
                            <input type="hidden" name="existing_image_url" value="<?= escape((string) $formValues['existing_image_url']); ?>">
                            <input type="hidden" name="return_search" value="<?= escape($search); ?>">
                            <input type="hidden" name="return_category_id" value="<?= escape((string) $categoryFilter); ?>">
                            <input type="hidden" name="return_page" value="<?= escape((string) $currentPage); ?>">

                            <div class="field-grid">
                                <label class="input-group">
                                    <span>Product name</span>
                                    <input type="text" name="name" value="<?= escape((string) $formValues['name']); ?>" required>
                                </label>

                                <label class="input-group">
                                    <span>Brand</span>
                                    <input type="text" name="brand" value="<?= escape((string) $formValues['brand']); ?>" placeholder="Optional brand name">
                                </label>

                                <label class="input-group">
                                    <span>Price</span>
                                    <input type="number" min="0" step="0.01" name="price" value="<?= escape((string) $formValues['price']); ?>" required>
                                </label>

                                <label class="input-group">
                                    <span>Stock quantity</span>
                                    <input type="number" min="0" step="1" name="stock_quantity" value="<?= escape((string) $formValues['stock_quantity']); ?>" required>
                                </label>

                                <label class="input-group">
                                    <span>Category</span>
                                    <select name="category_id" required <?= $noCategories ? 'disabled' : ''; ?>>
                                        <option value="">Select category</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option
                                                value="<?= escape((string) $category['category_id']); ?>"
                                                <?= (string) $formValues['category_id'] === (string) $category['category_id'] ? 'selected' : ''; ?>>
                                                <?= escape((string) $category['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>

                                <label class="input-group">
                                    <span>Product status</span>
                                    <select name="product_status" required>
                                        <?php foreach (PRODUCT_STATUS_OPTIONS as $statusOption): ?>
                                            <option value="<?= escape($statusOption); ?>" <?= (string) $formValues['product_status'] === $statusOption ? 'selected' : ''; ?>>
                                                <?= escape($statusOption); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </div>

                            <label class="input-group">
                                <span>Description</span>
                                <textarea name="description" rows="4" placeholder="Write a short product description"><?= escape((string) $formValues['description']); ?></textarea>
                            </label>

                            <div class="preview-card">
                                <div class="preview-copy">
                                    <span>Current image</span>
                                    <p>Upload a new product image to replace the current one.</p>
                                </div>
                                <div class="preview-frame">
                                    <?php if ((string) $formValues['existing_image_url'] !== '' && !$formValues['remove_image']): ?>
                                        <img
                                            src="<?= escape((string) $formValues['existing_image_url']); ?>"
                                            alt="Current product image"
                                            class="preview-image">
                                    <?php else: ?>
                                        <span>No image selected</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <label class="input-group">
                                <span>Product image upload</span>
                                <input type="file" name="image" accept="image/png,image/jpeg,image/webp,image/gif">
                                <small class="file-note">Accepted formats: JPG, PNG, WEBP, GIF. Maximum size 5MB.</small>
                            </label>

                            <?php if ($formMode === 'edit' && (string) $formValues['existing_image_url'] !== ''): ?>
                                <label class="checkbox-row">
                                    <input type="checkbox" name="remove_image" value="1" <?= $formValues['remove_image'] ? 'checked' : ''; ?>>
                                    <span>Remove current product image</span>
                                </label>
                            <?php endif; ?>

                            <div class="form-actions">
                                <?php if ($formMode === 'edit'): ?>
                                    <a
                                        href="<?= escape(buildProductManagementUrl([
                                                    'search' => $search,
                                                    'category_id' => $categoryFilter,
                                                    'page' => $currentPage,
                                                ])); ?>"
                                        class="toolbar-link">
                                        Cancel
                                    </a>
                                <?php endif; ?>

                                <button type="submit" class="toolbar-button" <?= $noCategories ? 'disabled' : ''; ?>>
                                    <?= $formMode === 'edit' ? 'Save Changes' : 'Create Product'; ?>
                                </button>
                            </div>
                        </form>
                    </aside>
                </section>
            </main>
        </div>
    </div>

    <script src="assets/js/admin-dashboard.js"></script>
</body>

</html>