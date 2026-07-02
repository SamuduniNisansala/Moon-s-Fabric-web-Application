<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin-auth.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/admin-order-management.php';

requireAdminAccess();

const PRODUCT_IMAGE_UPLOAD_DIRECTORY = 'uploads/products';
const MAX_PRODUCT_IMAGE_SIZE = 5242880;
const PRODUCT_IMAGES_PER_PAGE = 12;

function buildProductImageManagementUrl(array $params = []): string
{
    $query = [];

    $filterProductId = (int) ($params['filter_product_id'] ?? 0);
    if ($filterProductId > 0) {
        $query['filter_product_id'] = $filterProductId;
    }

    $page = (int) ($params['page'] ?? 1);
    if ($page > 1) {
        $query['page'] = $page;
    }

    $modal = trim((string) ($params['modal'] ?? ''));
    if ($modal !== '') {
        $query['modal'] = $modal;
    }

    $imageId = (int) ($params['image'] ?? 0);
    if ($imageId > 0) {
        $query['image'] = $imageId;
    }

    return 'admin-product-images.php' . ($query !== [] ? '?' . http_build_query($query) : '');
}

function formatProductImageDate(string $value): string
{
    $timestamp = strtotime($value);
    return $timestamp ? date('d M Y', $timestamp) : $value;
}

function fetchImageManagerProducts(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT
            p.product_id,
            p.name,
            c.name AS category_name,
            COUNT(pi.image_id) AS image_count
         FROM product p
         INNER JOIN category c ON c.category_id = p.category_id
         LEFT JOIN product_image pi ON pi.product_id = p.product_id
         GROUP BY p.product_id, p.name, c.name
         ORDER BY p.name ASC, p.product_id ASC'
    );

    return $statement->fetchAll() ?: [];
}

function fetchProductImageSummary(PDO $pdo): array
{
    $imageSummary = $pdo->query(
        'SELECT
            COUNT(*) AS total_images,
            COALESCE(SUM(is_main = 1), 0) AS main_images,
            COUNT(DISTINCT product_id) AS products_with_images
         FROM product_image'
    )->fetch() ?: [];

    $totalProducts = (int) $pdo->query('SELECT COUNT(*) FROM product')->fetchColumn();
    $productsWithImages = (int) ($imageSummary['products_with_images'] ?? 0);

    return [
        'totalImages' => (int) ($imageSummary['total_images'] ?? 0),
        'mainImages' => (int) ($imageSummary['main_images'] ?? 0),
        'productsWithImages' => $productsWithImages,
        'productsWithoutImages' => max(0, $totalProducts - $productsWithImages),
    ];
}

function fetchPagedProductImages(PDO $pdo, int $filterProductId, int $page, int $perPage): array
{
    $page = max(1, $page);
    $perPage = max(1, min(24, $perPage));

    $whereSql = '';
    $parameters = [];

    if ($filterProductId > 0) {
        $whereSql = 'WHERE pi.product_id = :product_id';
        $parameters['product_id'] = $filterProductId;
    }

    $countStatement = $pdo->prepare(
        "SELECT COUNT(*)
         FROM product_image pi
         {$whereSql}"
    );

    foreach ($parameters as $name => $value) {
        $countStatement->bindValue(':' . $name, $value, PDO::PARAM_INT);
    }

    $countStatement->execute();
    $totalItems = (int) $countStatement->fetchColumn();
    $totalPages = max(1, (int) ceil($totalItems / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $imagesStatement = $pdo->prepare(
        "SELECT
            pi.image_id,
            pi.product_id,
            pi.image_url,
            pi.is_main,
            pi.created_at,
            p.name AS product_name,
            c.name AS category_name
         FROM product_image pi
         INNER JOIN product p ON p.product_id = pi.product_id
         INNER JOIN category c ON c.category_id = p.category_id
         {$whereSql}
         ORDER BY pi.is_main DESC, pi.created_at DESC, pi.image_id DESC
         LIMIT :limit OFFSET :offset"
    );

    foreach ($parameters as $name => $value) {
        $imagesStatement->bindValue(':' . $name, $value, PDO::PARAM_INT);
    }

    $imagesStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $imagesStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $imagesStatement->execute();

    return [
        'items' => $imagesStatement->fetchAll() ?: [],
        'pagination' => [
            'currentPage' => $page,
            'perPage' => $perPage,
            'totalItems' => $totalItems,
            'totalPages' => $totalPages,
        ],
    ];
}

function fetchProductImageById(PDO $pdo, int $imageId): ?array
{
    $statement = $pdo->prepare(
        'SELECT
            pi.image_id,
            pi.product_id,
            pi.image_url,
            pi.is_main,
            pi.created_at,
            p.name AS product_name,
            c.name AS category_name
         FROM product_image pi
         INNER JOIN product p ON p.product_id = pi.product_id
         INNER JOIN category c ON c.category_id = p.category_id
         WHERE pi.image_id = :image_id
         LIMIT 1'
    );

    $statement->execute(['image_id' => $imageId]);
    $image = $statement->fetch();

    return $image ?: null;
}

function ensureProductExistsForImages(PDO $pdo, int $productId): void
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM product WHERE product_id = :product_id');
    $statement->execute(['product_id' => $productId]);

    if ((int) $statement->fetchColumn() === 0) {
        throw new RuntimeException('Please choose a valid product.');
    }
}

function normalizeUploadedFiles(array $fileBag): array
{
    if (!isset($fileBag['name'])) {
        return [];
    }

    if (!is_array($fileBag['name'])) {
        return [$fileBag];
    }

    $normalized = [];
    $count = count($fileBag['name']);

    for ($index = 0; $index < $count; $index++) {
        $error = (int) ($fileBag['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $normalized[] = [
            'tmp_name' => $fileBag['tmp_name'][$index] ?? '',
            'error' => $error,
            'size' => $fileBag['size'][$index] ?? 0,
        ];
    }

    return $normalized;
}

function ensureProductImageUploadDirectory(): string
{
    $directory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products';

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the product image upload directory.');
    }

    return $directory;
}

function saveUploadedProductImageFile(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('One of the selected images could not be uploaded.');
    }

    if ((int) ($file['size'] ?? 0) > MAX_PRODUCT_IMAGE_SIZE) {
        throw new RuntimeException('Each image must be 5MB or smaller.');
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
        throw new RuntimeException('Please upload JPG, PNG, WEBP, or GIF images only.');
    }

    $directory = ensureProductImageUploadDirectory();
    $fileName = 'product-image-' . bin2hex(random_bytes(10)) . '.' . $extensions[$mimeType];
    $destination = $directory . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('The uploaded image could not be saved.');
    }

    return PRODUCT_IMAGE_UPLOAD_DIRECTORY . '/' . $fileName;
}

function deleteLocalProductImageFiles(array $imagePaths): void
{
    foreach ($imagePaths as $imagePath) {
        $normalizedPath = str_replace('\\', '/', $imagePath);

        if (!str_starts_with($normalizedPath, PRODUCT_IMAGE_UPLOAD_DIRECTORY . '/')) {
            continue;
        }

        $absolutePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath);
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }
}

function saveUploadedProductImageBatch(array $fileBag): array
{
    $files = normalizeUploadedFiles($fileBag);
    if ($files === []) {
        throw new RuntimeException('Please choose at least one product image to upload.');
    }

    $savedPaths = [];

    try {
        foreach ($files as $file) {
            $savedPaths[] = saveUploadedProductImageFile($file);
        }
    } catch (Throwable $exception) {
        if ($savedPaths !== []) {
            deleteLocalProductImageFiles($savedPaths);
        }

        throw $exception;
    }

    return $savedPaths;
}

function productHasMainImage(PDO $pdo, int $productId): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM product_image
         WHERE product_id = :product_id
            AND is_main = 1'
    );
    $statement->execute(['product_id' => $productId]);

    return (int) $statement->fetchColumn() > 0;
}

function clearMainImageForProduct(PDO $pdo, int $productId): void
{
    $statement = $pdo->prepare(
        'UPDATE product_image
         SET is_main = 0
         WHERE product_id = :product_id'
    );
    $statement->execute(['product_id' => $productId]);
}

function promoteFallbackMainImage(PDO $pdo, int $productId): void
{
    if (productHasMainImage($pdo, $productId)) {
        return;
    }

    $imageId = $pdo->prepare(
        'SELECT image_id
         FROM product_image
         WHERE product_id = :product_id
         ORDER BY created_at DESC, image_id DESC
         LIMIT 1'
    );
    $imageId->execute(['product_id' => $productId]);
    $fallbackId = (int) $imageId->fetchColumn();

    if ($fallbackId <= 0) {
        return;
    }

    $statement = $pdo->prepare(
        'UPDATE product_image
         SET is_main = 1
         WHERE image_id = :image_id'
    );
    $statement->execute(['image_id' => $fallbackId]);
}

function uploadProductImages(PDO $pdo, int $productId, array $imagePaths, bool $makeFirstMain): void
{
    ensureProductExistsForImages($pdo, $productId);
    $shouldSetMain = $makeFirstMain || !productHasMainImage($pdo, $productId);

    try {
        $pdo->beginTransaction();

        if ($shouldSetMain) {
            clearMainImageForProduct($pdo, $productId);
        }

        $statement = $pdo->prepare(
            'INSERT INTO product_image (product_id, image_url, is_main)
             VALUES (:product_id, :image_url, :is_main)'
        );

        foreach ($imagePaths as $index => $imagePath) {
            $statement->execute([
                'product_id' => $productId,
                'image_url' => $imagePath,
                'is_main' => $shouldSetMain && $index === 0 ? 1 : 0,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        deleteLocalProductImageFiles($imagePaths);
        throw $exception;
    }
}

function setMainProductImage(PDO $pdo, int $imageId): void
{
    $image = fetchProductImageById($pdo, $imageId);
    if ($image === null) {
        throw new RuntimeException('The selected image could not be found.');
    }

    try {
        $pdo->beginTransaction();
        clearMainImageForProduct($pdo, (int) $image['product_id']);

        $statement = $pdo->prepare(
            'UPDATE product_image
             SET is_main = 1
             WHERE image_id = :image_id'
        );
        $statement->execute(['image_id' => $imageId]);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function updateProductImageRecord(PDO $pdo, int $imageId, int $productId, ?string $uploadedImagePath, bool $makeMain): void
{
    $image = fetchProductImageById($pdo, $imageId);
    if ($image === null) {
        if ($uploadedImagePath !== null) {
            deleteLocalProductImageFiles([$uploadedImagePath]);
        }

        throw new RuntimeException('The selected image could not be found.');
    }

    ensureProductExistsForImages($pdo, $productId);

    $oldProductId = (int) $image['product_id'];
    $oldImagePath = (string) $image['image_url'];
    $oldIsMain = (int) $image['is_main'] === 1;
    $newImagePath = $uploadedImagePath ?? $oldImagePath;
    $newIsMain = $oldIsMain && $productId === $oldProductId ? 1 : 0;

    if ($makeMain) {
        $newIsMain = 1;
    } elseif ($productId !== $oldProductId) {
        $newIsMain = productHasMainImage($pdo, $productId) ? 0 : 1;
    } elseif (!productHasMainImage($pdo, $productId)) {
        $newIsMain = 1;
    }

    try {
        $pdo->beginTransaction();

        if ($newIsMain === 1) {
            clearMainImageForProduct($pdo, $productId);
        }

        $statement = $pdo->prepare(
            'UPDATE product_image
             SET
                product_id = :product_id,
                image_url = :image_url,
                is_main = :is_main
             WHERE image_id = :image_id'
        );
        $statement->execute([
            'product_id' => $productId,
            'image_url' => $newImagePath,
            'is_main' => $newIsMain,
            'image_id' => $imageId,
        ]);

        if ($oldProductId !== $productId && $oldIsMain) {
            promoteFallbackMainImage($pdo, $oldProductId);
        }

        if ($productId !== $oldProductId && $newIsMain === 0) {
            promoteFallbackMainImage($pdo, $productId);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($uploadedImagePath !== null) {
            deleteLocalProductImageFiles([$uploadedImagePath]);
        }

        throw $exception;
    }

    if ($uploadedImagePath !== null && $uploadedImagePath !== $oldImagePath) {
        deleteLocalProductImageFiles([$oldImagePath]);
    }
}

function deleteProductImageRecord(PDO $pdo, int $imageId): void
{
    $image = fetchProductImageById($pdo, $imageId);
    if ($image === null) {
        throw new RuntimeException('The selected image could not be found.');
    }

    $oldProductId = (int) $image['product_id'];
    $oldIsMain = (int) $image['is_main'] === 1;
    $oldImagePath = (string) $image['image_url'];

    try {
        $pdo->beginTransaction();

        $statement = $pdo->prepare(
            'DELETE FROM product_image
             WHERE image_id = :image_id'
        );
        $statement->execute(['image_id' => $imageId]);

        if ($oldIsMain) {
            promoteFallbackMainImage($pdo, $oldProductId);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }

    deleteLocalProductImageFiles([$oldImagePath]);
}

$pdo = getDatabaseConnection();
$adminName = (string) ($_SESSION['admin_name'] ?? 'Admin');
$lastLogin = (string) ($_SESSION['admin_last_login'] ?? date('Y-m-d H:i:s'));
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestSource = $requestMethod === 'POST' ? $_POST : $_GET;

$filterProductId = max(0, (int) ($requestSource['return_filter_product_id'] ?? $requestSource['filter_product_id'] ?? 0));
$currentPage = max(1, (int) ($requestSource['return_page'] ?? $requestSource['page'] ?? 1));
$activeModal = trim((string) ($_GET['modal'] ?? ''));
$selectedImageId = max(0, (int) ($_GET['image'] ?? 0));

$products = fetchImageManagerProducts($pdo);
$summary = fetchProductImageSummary($pdo);
$hasProducts = $products !== [];
$pageError = pullFlashMessage('product_image_error') ?? '';
$successMessage = pullFlashMessage('product_image_success');
$uploadError = '';
$replaceError = '';
$uploadFormValues = [
    'product_id' => $products !== [] ? (string) $products[0]['product_id'] : '',
    'is_main' => false,
];
$replaceFormValues = [
    'image_id' => 0,
    'product_id' => '',
    'is_main' => false,
    'existing_image_url' => '',
    'created_at' => '',
];
$deleteImage = null;

if ($requestMethod === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $pageError = 'Your session has expired. Please refresh the page and try again.';
    } elseif ($action === 'upload_images') {
        $uploadFormValues = [
            'product_id' => trim((string) ($_POST['product_id'] ?? '')),
            'is_main' => isset($_POST['is_main']) && (string) $_POST['is_main'] === '1',
        ];

        try {
            $productId = (int) $uploadFormValues['product_id'];
            ensureProductExistsForImages($pdo, $productId);
            $savedPaths = saveUploadedProductImageBatch($_FILES['images'] ?? []);
            uploadProductImages($pdo, $productId, $savedPaths, $uploadFormValues['is_main']);

            setFlashMessage('product_image_success', count($savedPaths) . ' product image(s) uploaded successfully.');
            header('Location: ' . buildProductImageManagementUrl([
                'filter_product_id' => $filterProductId,
                'page' => $currentPage,
            ]));
            exit;
        } catch (Throwable $exception) {
            $uploadError = $exception->getMessage();
        }
    } elseif ($action === 'set_main') {
        $imageId = (int) ($_POST['image_id'] ?? 0);

        try {
            if ($imageId <= 0) {
                throw new RuntimeException('Please choose a valid image to mark as main.');
            }

            setMainProductImage($pdo, $imageId);
            setFlashMessage('product_image_success', 'Main product image updated successfully.');
        } catch (Throwable $exception) {
            setFlashMessage('product_image_error', $exception->getMessage());
        }

        header('Location: ' . buildProductImageManagementUrl([
            'filter_product_id' => $filterProductId,
            'page' => $currentPage,
        ]));
        exit;
    } elseif ($action === 'update_image') {
        $replaceFormValues = [
            'image_id' => max(0, (int) ($_POST['image_id'] ?? 0)),
            'product_id' => trim((string) ($_POST['product_id'] ?? '')),
            'is_main' => isset($_POST['is_main']) && (string) $_POST['is_main'] === '1',
            'existing_image_url' => trim((string) ($_POST['existing_image_url'] ?? '')),
            'created_at' => trim((string) ($_POST['created_at'] ?? '')),
        ];
        $activeModal = 'edit';
        $selectedImageId = (int) $replaceFormValues['image_id'];

        try {
            $imageId = (int) $replaceFormValues['image_id'];
            if ($imageId <= 0) {
                throw new RuntimeException('Please choose a valid image to update.');
            }

            $productId = (int) $replaceFormValues['product_id'];
            ensureProductExistsForImages($pdo, $productId);

            $uploadedImagePath = null;
            $files = normalizeUploadedFiles($_FILES['image'] ?? []);
            if ($files !== []) {
                $uploadedImagePath = saveUploadedProductImageFile($files[0]);
            }

            updateProductImageRecord($pdo, $imageId, $productId, $uploadedImagePath, $replaceFormValues['is_main']);
            setFlashMessage('product_image_success', 'Product image updated successfully.');

            header('Location: ' . buildProductImageManagementUrl([
                'filter_product_id' => $filterProductId,
                'page' => $currentPage,
            ]));
            exit;
        } catch (Throwable $exception) {
            $replaceError = $exception->getMessage();
        }
    } elseif ($action === 'delete_image') {
        $imageId = (int) ($_POST['image_id'] ?? 0);

        try {
            if ($imageId <= 0) {
                throw new RuntimeException('Please choose a valid image to delete.');
            }

            deleteProductImageRecord($pdo, $imageId);
            setFlashMessage('product_image_success', 'Product image deleted successfully.');
        } catch (Throwable $exception) {
            setFlashMessage('product_image_error', $exception->getMessage());
        }

        header('Location: ' . buildProductImageManagementUrl([
            'filter_product_id' => $filterProductId,
            'page' => $currentPage,
        ]));
        exit;
    }
}

if ($requestMethod === 'GET') {
    if ($activeModal === 'edit' && $selectedImageId > 0) {
        $editingImage = fetchProductImageById($pdo, $selectedImageId);
        if ($editingImage === null) {
            $pageError = 'The selected image could not be found.';
            $activeModal = '';
        } else {
            $replaceFormValues = [
                'image_id' => (int) $editingImage['image_id'],
                'product_id' => (string) $editingImage['product_id'],
                'is_main' => (int) $editingImage['is_main'] === 1,
                'existing_image_url' => (string) $editingImage['image_url'],
                'created_at' => (string) $editingImage['created_at'],
            ];
        }
    }

    if ($activeModal === 'delete' && $selectedImageId > 0) {
        $deleteImage = fetchProductImageById($pdo, $selectedImageId);
        if ($deleteImage === null) {
            $pageError = 'The selected image could not be found.';
            $activeModal = '';
        }
    }
}

$imagesData = fetchPagedProductImages($pdo, $filterProductId, $currentPage, PRODUCT_IMAGES_PER_PAGE);
$images = $imagesData['items'];
$pagination = $imagesData['pagination'];
$totalPages = (int) $pagination['totalPages'];
$currentPage = (int) $pagination['currentPage'];
$totalItems = (int) $pagination['totalItems'];
$pageStart = $totalItems > 0 ? (($currentPage - 1) * PRODUCT_IMAGES_PER_PAGE) + 1 : 0;
$pageEnd = min($totalItems, $currentPage * PRODUCT_IMAGES_PER_PAGE);
$pageNumberStart = max(1, $currentPage - 2);
$pageNumberEnd = min($totalPages, $pageNumberStart + 4);

$menuItems = buildAdminManagementMenu('images');
$adminProductsStylesheetVersion = (string) filemtime(__DIR__ . '/assets/css/admin-products.css');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Image Management | MOON s Fabric Shop</title>
    <link rel="stylesheet" href="assets/css/admin-products.css?v=<?= escape($adminProductsStylesheetVersion); ?>">
</head>

<body class="products-body">
    <button class="sidebar-overlay" data-sidebar-overlay hidden aria-label="Close menu"></button>

    <div class="dashboard-app">
        <aside class="dashboard-sidebar" data-dashboard-sidebar>
            <div class="sidebar-brand">
                <div>
                    <span class="sidebar-eyebrow">MOON s Fabric Shop</span>
                    <h1>Image Admin</h1>
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
                        <span class="dashboard-label">Product Image Management</span>
                        <h2>Manage catalog image gallery</h2>
                    </div>
                </div>

                <div class="topbar-right">
                    <span class="topbar-meta-label">Last login</span>
                    <span class="topbar-meta-value"><?= escape(formatProductImageDate($lastLogin)); ?></span>
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

                <?php if (!$hasProducts): ?>
                    <div class="status-message is-warning">
                        No products are available yet. Add products from
                        <a href="admin-products.php" class="toolbar-link">Product Management</a>
                        before uploading product images.
                    </div>
                <?php endif; ?>

                <section class="metric-grid">
                    <article class="metric-card tone-blue">
                        <span class="metric-title">Total Images</span>
                        <strong class="metric-value"><?= escape(number_format((int) $summary['totalImages'])); ?></strong>
                        <span class="metric-text">All uploaded product images</span>
                    </article>
                    <article class="metric-card tone-sky">
                        <span class="metric-title">Main Images</span>
                        <strong class="metric-value"><?= escape(number_format((int) $summary['mainImages'])); ?></strong>
                        <span class="metric-text">Images currently marked as main</span>
                    </article>
                    <article class="metric-card tone-slate">
                        <span class="metric-title">Products With Images</span>
                        <strong class="metric-value"><?= escape(number_format((int) $summary['productsWithImages'])); ?></strong>
                        <span class="metric-text">Products already showing image assets</span>
                    </article>
                    <article class="metric-card tone-amber">
                        <span class="metric-title">Products Without Images</span>
                        <strong class="metric-value"><?= escape(number_format((int) $summary['productsWithoutImages'])); ?></strong>
                        <span class="metric-text">Products still waiting for gallery images</span>
                    </article>
                </section>

                <section class="panel-grid">
                    <article class="products-card products-card-wide">
                        <div class="section-head">
                            <div class="section-copy">
                                <span class="dashboard-label">Gallery</span>
                                <h3>Browse and manage product image gallery</h3>
                                <p>Filter by product, set a main image, and keep the gallery organized across the catalog.</p>
                            </div>
                        </div>

                        <form method="get" class="filters-form">
                            <div class="filter-grid filter-grid-two">
                                <label class="input-group">
                                    <span>Filter by product</span>
                                    <select name="filter_product_id">
                                        <option value="0">All products</option>
                                        <?php foreach ($products as $product): ?>
                                            <option
                                                value="<?= escape((string) $product['product_id']); ?>"
                                                <?= $filterProductId === (int) $product['product_id'] ? 'selected' : ''; ?>>
                                                <?= escape((string) $product['name']); ?> (<?= escape((string) $product['category_name']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>

                                <div class="filter-actions">
                                    <button type="submit" class="toolbar-button">Apply Filter</button>
                                    <a href="admin-product-images.php" class="toolbar-link">Reset</a>
                                </div>
                            </div>
                        </form>

                        <div class="list-meta">
                            <div class="list-meta-copy">
                                Showing <?= escape((string) $pageStart); ?>-<?= escape((string) $pageEnd); ?>
                                of <?= escape((string) $totalItems); ?> images
                            </div>
                        </div>

                        <?php if ($images === []): ?>
                            <div class="empty-card">
                                <?php if ((int) $summary['totalImages'] === 0): ?>
                                    No product images have been uploaded yet.
                                <?php else: ?>
                                    No product images match the current filter.
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="image-gallery">
                                <?php foreach ($images as $image): ?>
                                    <article class="image-card">
                                        <div class="image-frame">
                                            <img
                                                src="<?= escape((string) $image['image_url']); ?>"
                                                alt="<?= escape((string) $image['product_name']); ?>"
                                                class="gallery-image">
                                            <?php if ((int) $image['is_main'] === 1): ?>
                                                <span class="main-image-badge">Main Image</span>
                                            <?php endif; ?>

                                            <div class="image-actions-overlay">
                                                <?php if ((int) $image['is_main'] !== 1): ?>
                                                    <form method="post">
                                                        <input type="hidden" name="csrf_token" value="<?= escape(getCsrfToken()); ?>">
                                                        <input type="hidden" name="action" value="set_main">
                                                        <input type="hidden" name="image_id" value="<?= escape((string) $image['image_id']); ?>">
                                                        <input type="hidden" name="return_filter_product_id" value="<?= escape((string) $filterProductId); ?>">
                                                        <input type="hidden" name="return_page" value="<?= escape((string) $currentPage); ?>">
                                                        <button type="submit" class="action-button secondary full-width">Set Main</button>
                                                    </form>
                                                <?php endif; ?>

                                                <a
                                                    href="<?= escape(buildProductImageManagementUrl([
                                                                'filter_product_id' => $filterProductId,
                                                                'page' => $currentPage,
                                                                'modal' => 'edit',
                                                                'image' => (int) $image['image_id'],
                                                            ])); ?>#image-modal"
                                                    class="action-button secondary full-width">
                                                    Update
                                                </a>

                                                <a
                                                    href="<?= escape(buildProductImageManagementUrl([
                                                                'filter_product_id' => $filterProductId,
                                                                'page' => $currentPage,
                                                                'modal' => 'delete',
                                                                'image' => (int) $image['image_id'],
                                                            ])); ?>#image-modal"
                                                    class="action-button danger full-width">
                                                    Delete
                                                </a>
                                            </div>
                                        </div>

                                        <div class="image-card-body">
                                            <h4><?= escape((string) $image['product_name']); ?></h4>
                                            <p><?= escape((string) $image['category_name']); ?></p>
                                            <div class="image-meta-row">
                                                <span>Created</span>
                                                <strong><?= escape(formatProductImageDate((string) $image['created_at'])); ?></strong>
                                            </div>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($totalPages > 1): ?>
                            <nav class="pagination">
                                <a
                                    href="<?= escape(buildProductImageManagementUrl([
                                                'filter_product_id' => $filterProductId,
                                                'page' => max(1, $currentPage - 1),
                                            ])); ?>"
                                    class="page-link<?= $currentPage <= 1 ? ' is-disabled' : ''; ?>">
                                    Previous
                                </a>

                                <?php for ($pageNumber = $pageNumberStart; $pageNumber <= $pageNumberEnd; $pageNumber++): ?>
                                    <a
                                        href="<?= escape(buildProductImageManagementUrl([
                                                    'filter_product_id' => $filterProductId,
                                                    'page' => $pageNumber,
                                                ])); ?>"
                                        class="page-link<?= $pageNumber === $currentPage ? ' is-active' : ''; ?>">
                                        <?= escape((string) $pageNumber); ?>
                                    </a>
                                <?php endfor; ?>

                                <a
                                    href="<?= escape(buildProductImageManagementUrl([
                                                'filter_product_id' => $filterProductId,
                                                'page' => min($totalPages, $currentPage + 1),
                                            ])); ?>"
                                    class="page-link<?= $currentPage >= $totalPages ? ' is-disabled' : ''; ?>">
                                    Next
                                </a>
                            </nav>
                        <?php endif; ?>
                    </article>

                    <aside class="products-card sticky-card">
                        <div class="section-head">
                            <div class="section-copy">
                                <span class="dashboard-label">Upload Images</span>
                                <h3>Upload product images</h3>
                            </div>
                        </div>

                        <?php if ($uploadError !== ''): ?>
                            <div class="status-message is-error form-message">
                                <?= escape($uploadError); ?>
                            </div>
                        <?php endif; ?>

                        <form method="post" enctype="multipart/form-data" class="product-form">
                            <input type="hidden" name="csrf_token" value="<?= escape(getCsrfToken()); ?>">
                            <input type="hidden" name="action" value="upload_images">
                            <input type="hidden" name="return_filter_product_id" value="<?= escape((string) $filterProductId); ?>">
                            <input type="hidden" name="return_page" value="<?= escape((string) $currentPage); ?>">

                            <label class="input-group">
                                <span>Product</span>
                                <select name="product_id" required <?= !$hasProducts ? 'disabled' : ''; ?>>
                                    <option value="">Select product</option>
                                    <?php foreach ($products as $product): ?>
                                        <option
                                            value="<?= escape((string) $product['product_id']); ?>"
                                            <?= (string) $uploadFormValues['product_id'] === (string) $product['product_id'] ? 'selected' : ''; ?>>
                                            <?= escape((string) $product['name']); ?> (<?= escape((string) $product['category_name']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <div class="input-group">
                                <span>Image upload</span>
                                <div class="dropzone" data-dropzone data-input-id="product-images-input" data-preview-target="upload-preview-list">
                                    <input
                                        id="product-images-input"
                                        type="file"
                                        name="images[]"
                                        accept="image/png,image/jpeg,image/webp,image/gif"
                                        multiple
                                        class="visually-hidden">
                                    <div class="dropzone-copy">
                                        <strong>Drag and drop images here</strong>
                                        <span>or click to browse multiple files</span>
                                    </div>
                                </div>
                                <small class="file-note">Accepted formats: JPG, PNG, WEBP, GIF. Maximum size 5MB each.</small>
                            </div>

                            <label class="checkbox-row">
                                <input type="checkbox" name="is_main" value="1" <?= $uploadFormValues['is_main'] ? 'checked' : ''; ?>>
                                <span>Set the first uploaded image as the main product image</span>
                            </label>

                            <div class="preview-list" id="upload-preview-list">
                                <div class="preview-placeholder">Selected image previews will appear here.</div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="toolbar-button" <?= !$hasProducts ? 'disabled' : ''; ?>>
                                    Upload Images
                                </button>
                            </div>
                        </form>
                    </aside>
                </section>
            </main>
        </div>
    </div>

    <?php if ($activeModal === 'edit' && $selectedImageId > 0): ?>
        <div class="modal-backdrop">
            <div class="modal-shell" id="image-modal">
                <div class="modal-head">
                    <div class="section-copy">
                        <span class="dashboard-label">Update Image</span>
                        <h3>Replace or reassign a product image</h3>
                        <p>Update the image file, move it to another product, or mark it as the main image.</p>
                    </div>
                    <a
                        href="<?= escape(buildProductImageManagementUrl([
                                    'filter_product_id' => $filterProductId,
                                    'page' => $currentPage,
                                ])); ?>"
                        class="modal-close">
                        Close
                    </a>
                </div>

                <?php if ($replaceError !== ''): ?>
                    <div class="status-message is-error form-message">
                        <?= escape($replaceError); ?>
                    </div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" class="product-form">
                    <input type="hidden" name="csrf_token" value="<?= escape(getCsrfToken()); ?>">
                    <input type="hidden" name="action" value="update_image">
                    <input type="hidden" name="image_id" value="<?= escape((string) $replaceFormValues['image_id']); ?>">
                    <input type="hidden" name="existing_image_url" value="<?= escape((string) $replaceFormValues['existing_image_url']); ?>">
                    <input type="hidden" name="created_at" value="<?= escape((string) $replaceFormValues['created_at']); ?>">
                    <input type="hidden" name="return_filter_product_id" value="<?= escape((string) $filterProductId); ?>">
                    <input type="hidden" name="return_page" value="<?= escape((string) $currentPage); ?>">

                    <div class="preview-card">
                        <div class="preview-copy">
                            <span>Current image</span>
                            <p>Created on <?= escape(formatProductImageDate((string) $replaceFormValues['created_at'])); ?></p>
                        </div>
                        <div class="preview-frame">
                            <?php if ((string) $replaceFormValues['existing_image_url'] !== ''): ?>
                                <img
                                    src="<?= escape((string) $replaceFormValues['existing_image_url']); ?>"
                                    alt="Current product image"
                                    class="preview-image">
                            <?php else: ?>
                                <span>No image available</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <label class="input-group">
                        <span>Product</span>
                        <select name="product_id" required>
                            <?php foreach ($products as $product): ?>
                                <option
                                    value="<?= escape((string) $product['product_id']); ?>"
                                    <?= (string) $replaceFormValues['product_id'] === (string) $product['product_id'] ? 'selected' : ''; ?>>
                                    <?= escape((string) $product['name']); ?> (<?= escape((string) $product['category_name']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <div class="input-group">
                        <span>Update image file</span>
                        <div class="dropzone" data-dropzone data-input-id="replace-image-input" data-preview-target="replace-preview-list">
                            <input
                                id="replace-image-input"
                                type="file"
                                name="image"
                                accept="image/png,image/jpeg,image/webp,image/gif"
                                class="visually-hidden">
                            <div class="dropzone-copy">
                                <strong>Drag and drop a replacement image</strong>
                                <span>or click to choose a single file</span>
                            </div>
                        </div>
                        <small class="file-note">Leave empty if you only want to change the product or main-image setting.</small>
                    </div>

                    <label class="checkbox-row">
                        <input type="checkbox" name="is_main" value="1" <?= $replaceFormValues['is_main'] ? 'checked' : ''; ?>>
                        <span>Mark this image as the main product image</span>
                    </label>

                    <div class="preview-list" id="replace-preview-list">
                        <div class="preview-placeholder">Replacement image preview will appear here.</div>
                    </div>

                    <div class="modal-actions">
                        <a
                            href="<?= escape(buildProductImageManagementUrl([
                                        'filter_product_id' => $filterProductId,
                                        'page' => $currentPage,
                                    ])); ?>"
                            class="toolbar-link">
                            Cancel
                        </a>
                        <button type="submit" class="toolbar-button">Save Image Changes</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($activeModal === 'delete' && $deleteImage !== null): ?>
        <div class="modal-backdrop">
            <div class="modal-shell modal-shell-small" id="image-modal">
                <div class="modal-head">
                    <div class="section-copy">
                        <span class="dashboard-label">Delete Image</span>
                        <h3>Confirm image deletion</h3>
                        <p>Removing this image will permanently delete the file from the product gallery.</p>
                    </div>
                    <a
                        href="<?= escape(buildProductImageManagementUrl([
                                    'filter_product_id' => $filterProductId,
                                    'page' => $currentPage,
                                ])); ?>"
                        class="modal-close">
                        Close
                    </a>
                </div>

                <div class="delete-summary">
                    <div class="summary-row">
                        <span>Product</span>
                        <strong><?= escape((string) $deleteImage['product_name']); ?></strong>
                    </div>
                    <div class="summary-row">
                        <span>Category</span>
                        <strong><?= escape((string) $deleteImage['category_name']); ?></strong>
                    </div>
                    <div class="summary-row">
                        <span>Created</span>
                        <strong><?= escape(formatProductImageDate((string) $deleteImage['created_at'])); ?></strong>
                    </div>
                    <div class="summary-row">
                        <span>Main image</span>
                        <strong><?= (int) $deleteImage['is_main'] === 1 ? 'Yes' : 'No'; ?></strong>
                    </div>
                </div>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= escape(getCsrfToken()); ?>">
                    <input type="hidden" name="action" value="delete_image">
                    <input type="hidden" name="image_id" value="<?= escape((string) $deleteImage['image_id']); ?>">
                    <input type="hidden" name="return_filter_product_id" value="<?= escape((string) $filterProductId); ?>">
                    <input type="hidden" name="return_page" value="<?= escape((string) $currentPage); ?>">

                    <div class="modal-actions">
                        <a
                            href="<?= escape(buildProductImageManagementUrl([
                                        'filter_product_id' => $filterProductId,
                                        'page' => $currentPage,
                                    ])); ?>"
                            class="toolbar-link">
                            Cancel
                        </a>
                        <button type="submit" class="action-button danger">Delete Image</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <script src="assets/js/admin-dashboard.js"></script>
    <script src="assets/js/admin-product-images.js"></script>
</body>

</html>