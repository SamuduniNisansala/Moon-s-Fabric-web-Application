<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin-auth.php';
require_once __DIR__ . '/includes/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isAdminAuthenticated()) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'message' => 'Unauthorized admin session.',
    ]);
    exit;
}

const PRODUCT_STATUS_OPTIONS = ['Active', 'Draft', 'Inactive'];
const PRODUCT_UPLOAD_DIRECTORY = 'uploads/products';
const MAX_PRODUCT_IMAGE_SIZE = 5_242_880;

function jsonResponse(int $statusCode, array $payload): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function fetchCategories(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT category_id, name, status
         FROM category
         ORDER BY name ASC'
    );

    return array_map(
        static fn(array $row): array => [
            'categoryId' => (int) $row['category_id'],
            'name' => (string) $row['name'],
            'status' => (string) $row['status'],
        ],
        $statement->fetchAll() ?: []
    );
}

function fetchSummary(PDO $pdo): array
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

function fetchProducts(PDO $pdo): array
{
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = max(1, min(12, (int) ($_GET['per_page'] ?? 8)));
    $search = trim((string) ($_GET['search'] ?? ''));
    $categoryId = max(0, (int) ($_GET['category_id'] ?? 0));

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
        $countStatement->bindValue(':' . $name, $value, $name === 'category_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
    }

    $countStatement->execute();
    $totalItems = (int) $countStatement->fetchColumn();
    $totalPages = max(1, (int) ceil($totalItems / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $productsSql = sprintf(
        'SELECT
            p.product_id,
            p.name,
            COALESCE(p.brand, "") AS brand,
            COALESCE(p.description, "") AS description,
            p.base_price,
            p.stock_quantity,
            p.product_status,
            p.category_id,
            c.name AS category_name,
            COALESCE(product_image_latest.image_url, "") AS image_url,
            DATE_FORMAT(p.created_at, "%%Y-%%m-%%d %%H:%%i:%%s") AS created_at
         FROM product p
         INNER JOIN category c ON c.category_id = p.category_id
         %s
         %s
         ORDER BY p.created_at DESC, p.product_id DESC
         LIMIT :limit OFFSET :offset',
        latestProductImageJoinSql(),
        $whereSql
    );

    $productsStatement = $pdo->prepare($productsSql);

    foreach ($parameters as $name => $value) {
        $productsStatement->bindValue(':' . $name, $value, $name === 'category_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
    }

    $productsStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $productsStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $productsStatement->execute();

    $products = array_map(
        static fn(array $row): array => [
            'productId' => (int) $row['product_id'],
            'name' => (string) $row['name'],
            'brand' => (string) $row['brand'],
            'description' => (string) $row['description'],
            'price' => (float) $row['base_price'],
            'stockQuantity' => (int) $row['stock_quantity'],
            'status' => (string) $row['product_status'],
            'categoryId' => (int) $row['category_id'],
            'categoryName' => (string) $row['category_name'],
            'imageUrl' => (string) $row['image_url'],
            'createdAt' => (string) $row['created_at'],
        ],
        $productsStatement->fetchAll() ?: []
    );

    return [
        'items' => $products,
        'pagination' => [
            'currentPage' => $page,
            'perPage' => $perPage,
            'totalItems' => $totalItems,
            'totalPages' => $totalPages,
        ],
        'filters' => [
            'search' => $search,
            'categoryId' => $categoryId,
        ],
    ];
}

function requireMutationCsrfToken(): void
{
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        jsonResponse(419, [
            'ok' => false,
            'message' => 'CSRF validation failed. Please refresh the page and try again.',
        ]);
    }
}

function assertCategoryExists(PDO $pdo, int $categoryId): void
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM category WHERE category_id = :category_id');
    $statement->execute(['category_id' => $categoryId]);

    if ((int) $statement->fetchColumn() === 0) {
        jsonResponse(422, [
            'ok' => false,
            'message' => 'Please select a valid product category.',
        ]);
    }
}

function normalizeProductPayload(PDO $pdo): array
{
    $name = trim((string) ($_POST['name'] ?? ''));
    $brand = trim((string) ($_POST['brand'] ?? ''));
    $priceRaw = trim((string) ($_POST['price'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $status = trim((string) ($_POST['status'] ?? 'Active'));
    $stockRaw = trim((string) ($_POST['stock_quantity'] ?? '0'));
    $removeImage = isset($_POST['remove_image']) && (string) $_POST['remove_image'] === '1';

    if ($name === '') {
        jsonResponse(422, [
            'ok' => false,
            'message' => 'Product name is required.',
        ]);
    }

    if (!is_numeric($priceRaw)) {
        jsonResponse(422, [
            'ok' => false,
            'message' => 'Price must be a valid number.',
        ]);
    }

    $price = round((float) $priceRaw, 2);
    if ($price < 0) {
        jsonResponse(422, [
            'ok' => false,
            'message' => 'Price cannot be negative.',
        ]);
    }

    if (filter_var($stockRaw, FILTER_VALIDATE_INT) === false || (int) $stockRaw < 0) {
        jsonResponse(422, [
            'ok' => false,
            'message' => 'Stock quantity must be a whole number zero or above.',
        ]);
    }

    if (!in_array($status, PRODUCT_STATUS_OPTIONS, true)) {
        jsonResponse(422, [
            'ok' => false,
            'message' => 'Please choose a valid product status.',
        ]);
    }

    if ($categoryId <= 0) {
        jsonResponse(422, [
            'ok' => false,
            'message' => 'A category is required before saving a product.',
        ]);
    }

    assertCategoryExists($pdo, $categoryId);

    return [
        'name' => $name,
        'brand' => $brand !== '' ? $brand : null,
        'price' => $price,
        'description' => $description !== '' ? $description : null,
        'categoryId' => $categoryId,
        'status' => $status,
        'stockQuantity' => (int) $stockRaw,
        'removeImage' => $removeImage,
    ];
}

function ensureUploadDirectory(): string
{
    $directory = __DIR__ . DIRECTORY_SEPARATOR . PRODUCT_UPLOAD_DIRECTORY;

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the product upload directory.');
    }

    return $directory;
}

function saveUploadedProductImage(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        jsonResponse(422, [
            'ok' => false,
            'message' => 'Product image upload failed. Please try another file.',
        ]);
    }

    if ((int) ($file['size'] ?? 0) > MAX_PRODUCT_IMAGE_SIZE) {
        jsonResponse(422, [
            'ok' => false,
            'message' => 'Product image must be 5MB or smaller.',
        ]);
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
        jsonResponse(422, [
            'ok' => false,
            'message' => 'Only JPG, PNG, WEBP, and GIF product images are allowed.',
        ]);
    }

    $directory = ensureUploadDirectory();
    $fileName = 'product-' . bin2hex(random_bytes(10)) . '.' . $extensions[$mimeType];
    $destination = $directory . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('Unable to store the uploaded product image.');
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

function deleteLocalImageFiles(array $imagePaths): void
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

function requireExistingProduct(PDO $pdo, int $productId): void
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM product WHERE product_id = :product_id');
    $statement->execute(['product_id' => $productId]);

    if ((int) $statement->fetchColumn() === 0) {
        jsonResponse(404, [
            'ok' => false,
            'message' => 'The selected product could not be found.',
        ]);
    }
}

function createProduct(PDO $pdo): void
{
    requireMutationCsrfToken();
    $payload = normalizeProductPayload($pdo);
    $uploadedImagePath = isset($_FILES['image']) ? saveUploadedProductImage($_FILES['image']) : null;

    try {
        $pdo->beginTransaction();

        $insertStatement = $pdo->prepare(
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
        $insertStatement->execute([
            'category_id' => $payload['categoryId'],
            'name' => $payload['name'],
            'brand' => $payload['brand'],
            'description' => $payload['description'],
            'base_price' => $payload['price'],
            'stock_quantity' => $payload['stockQuantity'],
            'product_status' => $payload['status'],
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
            deleteLocalImageFiles([$uploadedImagePath]);
        }

        throw $exception;
    }

    jsonResponse(201, [
        'ok' => true,
        'message' => 'Product created successfully.',
    ]);
}

function updateProduct(PDO $pdo): void
{
    requireMutationCsrfToken();
    $productId = (int) ($_POST['product_id'] ?? 0);

    if ($productId <= 0) {
        jsonResponse(422, [
            'ok' => false,
            'message' => 'A valid product is required for editing.',
        ]);
    }

    requireExistingProduct($pdo, $productId);

    $payload = normalizeProductPayload($pdo);
    $uploadedImagePath = isset($_FILES['image']) ? saveUploadedProductImage($_FILES['image']) : null;
    $imagePathsToDelete = [];

    try {
        $pdo->beginTransaction();

        $updateStatement = $pdo->prepare(
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
        $updateStatement->execute([
            'category_id' => $payload['categoryId'],
            'name' => $payload['name'],
            'brand' => $payload['brand'],
            'description' => $payload['description'],
            'base_price' => $payload['price'],
            'stock_quantity' => $payload['stockQuantity'],
            'product_status' => $payload['status'],
            'product_id' => $productId,
        ]);

        if ($payload['removeImage'] || $uploadedImagePath !== null) {
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
            deleteLocalImageFiles([$uploadedImagePath]);
        }

        throw $exception;
    }

    if ($imagePathsToDelete !== []) {
        deleteLocalImageFiles($imagePathsToDelete);
    }

    jsonResponse(200, [
        'ok' => true,
        'message' => 'Product updated successfully.',
    ]);
}

function deleteProduct(PDO $pdo): void
{
    requireMutationCsrfToken();
    $productId = (int) ($_POST['product_id'] ?? 0);

    if ($productId <= 0) {
        jsonResponse(422, [
            'ok' => false,
            'message' => 'A valid product is required for deletion.',
        ]);
    }

    requireExistingProduct($pdo, $productId);

    $imagePathsToDelete = [];

    try {
        $pdo->beginTransaction();

        $imagePathsToDelete = deleteProductImageRows($pdo, $productId);

        $deleteStatement = $pdo->prepare(
            'DELETE FROM product
             WHERE product_id = :product_id'
        );
        $deleteStatement->execute(['product_id' => $productId]);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }

    if ($imagePathsToDelete !== []) {
        deleteLocalImageFiles($imagePathsToDelete);
    }

    jsonResponse(200, [
        'ok' => true,
        'message' => 'Product deleted successfully.',
    ]);
}

try {
    $pdo = getDatabaseConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $productData = fetchProducts($pdo);

        jsonResponse(200, [
            'ok' => true,
            'products' => $productData['items'],
            'pagination' => $productData['pagination'],
            'filters' => $productData['filters'],
            'categories' => fetchCategories($pdo),
            'summary' => fetchSummary($pdo),
            'statusOptions' => PRODUCT_STATUS_OPTIONS,
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(405, [
            'ok' => false,
            'message' => 'Method not allowed.',
        ]);
    }

    $action = trim((string) ($_POST['_action'] ?? ''));

    if ($action === 'create') {
        createProduct($pdo);
    }

    if ($action === 'update') {
        updateProduct($pdo);
    }

    if ($action === 'delete') {
        deleteProduct($pdo);
    }

    jsonResponse(422, [
        'ok' => false,
        'message' => 'Unsupported product action.',
    ]);
} catch (Throwable $exception) {
    jsonResponse(500, [
        'ok' => false,
        'message' => 'Product management request failed.',
        'details' => $exception->getMessage(),
    ]);
}
