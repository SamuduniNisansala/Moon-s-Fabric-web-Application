<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin-auth.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/admin-order-management.php';

requireAdminAccess();

const CATEGORY_STATUS_OPTIONS = ['Active', 'Inactive'];
const CATEGORIES_PER_PAGE = 8;

function buildCategoryManagementUrl(array $params = []): string
{
    $query = [];

    $search = trim((string) ($params['search'] ?? ''));
    if ($search !== '') {
        $query['search'] = $search;
    }

    $page = (int) ($params['page'] ?? 1);
    if ($page > 1) {
        $query['page'] = $page;
    }

    $modal = trim((string) ($params['modal'] ?? ''));
    if ($modal !== '') {
        $query['modal'] = $modal;
    }

    $categoryId = (int) ($params['category'] ?? 0);
    if ($categoryId > 0) {
        $query['category'] = $categoryId;
    }

    return 'admin-categories.php' . ($query !== [] ? '?' . http_build_query($query) : '');
}

function getCategoryStatusClassName(string $status): string
{
    return match ($status) {
        'Active' => 'status-active',
        'Inactive' => 'status-inactive',
        default => 'status-neutral',
    };
}

function formatCategoryDate(string $value): string
{
    $timestamp = strtotime($value);
    return $timestamp ? date('d M Y', $timestamp) : $value;
}

function defaultCategoryForm(): array
{
    return [
        'category_id' => 0,
        'name' => '',
        'description' => '',
        'status' => 'Active',
    ];
}

function fetchCategorySummary(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT
            COUNT(*) AS total_categories,
            COALESCE(SUM(status = "Active"), 0) AS active_categories,
            COALESCE(SUM(status = "Inactive"), 0) AS inactive_categories
         FROM category'
    );

    $summary = $statement->fetch() ?: [];
    $categoriesWithProducts = (int) $pdo->query(
        'SELECT COUNT(DISTINCT category_id) FROM product'
    )->fetchColumn();

    return [
        'totalCategories' => (int) ($summary['total_categories'] ?? 0),
        'activeCategories' => (int) ($summary['active_categories'] ?? 0),
        'inactiveCategories' => (int) ($summary['inactive_categories'] ?? 0),
        'categoriesWithProducts' => $categoriesWithProducts,
    ];
}

function fetchPagedCategories(PDO $pdo, string $search, int $page, int $perPage): array
{
    $page = max(1, $page);
    $perPage = max(1, min(12, $perPage));

    $whereSql = '';
    $parameters = [];

    if ($search !== '') {
        $whereSql = 'WHERE c.name LIKE :search_name OR COALESCE(c.description, "") LIKE :search_description';
        $searchTerm = '%' . $search . '%';
        $parameters['search_name'] = $searchTerm;
        $parameters['search_description'] = $searchTerm;
    }

    $countStatement = $pdo->prepare(
        "SELECT COUNT(*)
         FROM category c
         {$whereSql}"
    );

    foreach ($parameters as $name => $value) {
        $countStatement->bindValue(':' . $name, $value, PDO::PARAM_STR);
    }

    $countStatement->execute();
    $totalItems = (int) $countStatement->fetchColumn();
    $totalPages = max(1, (int) ceil($totalItems / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $categoriesStatement = $pdo->prepare(
        "SELECT
            c.category_id,
            c.name,
            COALESCE(c.description, '') AS description,
            c.status,
            COUNT(p.product_id) AS product_count
         FROM category c
         LEFT JOIN product p ON p.category_id = c.category_id
         {$whereSql}
         GROUP BY c.category_id, c.name, c.description, c.status
         ORDER BY c.name ASC, c.category_id ASC
         LIMIT :limit OFFSET :offset"
    );

    foreach ($parameters as $name => $value) {
        $categoriesStatement->bindValue(':' . $name, $value, PDO::PARAM_STR);
    }

    $categoriesStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $categoriesStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $categoriesStatement->execute();

    return [
        'items' => $categoriesStatement->fetchAll() ?: [],
        'pagination' => [
            'currentPage' => $page,
            'perPage' => $perPage,
            'totalItems' => $totalItems,
            'totalPages' => $totalPages,
        ],
    ];
}

function fetchCategoryById(PDO $pdo, int $categoryId): ?array
{
    $statement = $pdo->prepare(
        'SELECT
            c.category_id,
            c.name,
            COALESCE(c.description, "") AS description,
            c.status,
            COUNT(p.product_id) AS product_count
         FROM category c
         LEFT JOIN product p ON p.category_id = c.category_id
         WHERE c.category_id = :category_id
         GROUP BY c.category_id, c.name, c.description, c.status
         LIMIT 1'
    );

    $statement->execute(['category_id' => $categoryId]);
    $category = $statement->fetch();

    return $category ?: null;
}

function ensureCategoryExists(PDO $pdo, int $categoryId): void
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM category WHERE category_id = :category_id');
    $statement->execute(['category_id' => $categoryId]);

    if ((int) $statement->fetchColumn() === 0) {
        throw new RuntimeException('The selected category could not be found.');
    }
}

function ensureCategoryNameAvailable(PDO $pdo, string $name, int $ignoreCategoryId = 0): void
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM category
         WHERE LOWER(name) = LOWER(:name)
            AND category_id <> :category_id'
    );
    $statement->execute([
        'name' => $name,
        'category_id' => $ignoreCategoryId,
    ]);

    if ((int) $statement->fetchColumn() > 0) {
        throw new RuntimeException('A category with that name already exists.');
    }
}

function normalizeCategoryPayload(PDO $pdo, array $input, int $ignoreCategoryId = 0): array
{
    $name = trim((string) ($input['name'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $status = trim((string) ($input['status'] ?? 'Active'));

    if ($name === '') {
        throw new RuntimeException('Category name is required.');
    }

    if (!in_array($status, CATEGORY_STATUS_OPTIONS, true)) {
        throw new RuntimeException('Please choose a valid category status.');
    }

    ensureCategoryNameAvailable($pdo, $name, $ignoreCategoryId);

    return [
        'name' => $name,
        'description' => $description,
        'status' => $status,
    ];
}

function createCategoryRecord(PDO $pdo, array $payload): void
{
    $statement = $pdo->prepare(
        'INSERT INTO category (name, description, status)
         VALUES (:name, :description, :status)'
    );
    $statement->execute([
        'name' => $payload['name'],
        'description' => $payload['description'] !== '' ? $payload['description'] : null,
        'status' => $payload['status'],
    ]);
}

function updateCategoryRecord(PDO $pdo, int $categoryId, array $payload): void
{
    ensureCategoryExists($pdo, $categoryId);

    $statement = $pdo->prepare(
        'UPDATE category
         SET
            name = :name,
            description = :description,
            status = :status
         WHERE category_id = :category_id'
    );
    $statement->execute([
        'name' => $payload['name'],
        'description' => $payload['description'] !== '' ? $payload['description'] : null,
        'status' => $payload['status'],
        'category_id' => $categoryId,
    ]);
}

function toggleCategoryStatus(PDO $pdo, int $categoryId): string
{
    $category = fetchCategoryById($pdo, $categoryId);
    if ($category === null) {
        throw new RuntimeException('The selected category could not be found.');
    }

    $nextStatus = (string) $category['status'] === 'Active' ? 'Inactive' : 'Active';

    $statement = $pdo->prepare(
        'UPDATE category
         SET status = :status
         WHERE category_id = :category_id'
    );
    $statement->execute([
        'status' => $nextStatus,
        'category_id' => $categoryId,
    ]);

    return $nextStatus;
}

function deleteCategoryRecord(PDO $pdo, int $categoryId): void
{
    $category = fetchCategoryById($pdo, $categoryId);
    if ($category === null) {
        throw new RuntimeException('The selected category could not be found.');
    }

    if ((int) $category['product_count'] > 0) {
        throw new RuntimeException('This category is linked to products. Move or delete those products before removing the category.');
    }

    $statement = $pdo->prepare(
        'DELETE FROM category
         WHERE category_id = :category_id'
    );
    $statement->execute(['category_id' => $categoryId]);
}

$pdo = getDatabaseConnection();
$adminName = (string) ($_SESSION['admin_name'] ?? 'Admin');
$lastLogin = (string) ($_SESSION['admin_last_login'] ?? date('Y-m-d H:i:s'));
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestSource = $requestMethod === 'POST' ? $_POST : $_GET;

$search = trim((string) ($requestSource['return_search'] ?? $requestSource['search'] ?? ''));
$currentPage = max(1, (int) ($requestSource['return_page'] ?? $requestSource['page'] ?? 1));
$activeModal = trim((string) ($_GET['modal'] ?? ''));
$selectedCategoryId = max(0, (int) ($_GET['category'] ?? 0));
$summary = fetchCategorySummary($pdo);

$pageError = pullFlashMessage('category_error') ?? '';
$successMessage = pullFlashMessage('category_success');
$formError = '';
$formValues = defaultCategoryForm();
$deleteCategory = null;

if ($requestMethod === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $pageError = 'Your session has expired. Please refresh the page and try again.';
    } elseif ($action === 'toggle_status') {
        $categoryId = (int) ($_POST['category_id'] ?? 0);

        try {
            $newStatus = toggleCategoryStatus($pdo, $categoryId);
            setFlashMessage('category_success', 'Category status updated to ' . $newStatus . '.');
        } catch (Throwable $exception) {
            setFlashMessage('category_error', $exception->getMessage());
        }

        header('Location: ' . buildCategoryManagementUrl([
            'search' => $search,
            'page' => $currentPage,
        ]));
        exit;
    } elseif ($action === 'delete') {
        $categoryId = (int) ($_POST['category_id'] ?? 0);

        try {
            if ($categoryId <= 0) {
                throw new RuntimeException('Please choose a valid category to delete.');
            }

            deleteCategoryRecord($pdo, $categoryId);
            setFlashMessage('category_success', 'Category deleted successfully.');
        } catch (Throwable $exception) {
            setFlashMessage('category_error', $exception->getMessage());
        }

        header('Location: ' . buildCategoryManagementUrl([
            'search' => $search,
            'page' => $currentPage,
        ]));
        exit;
    } elseif ($action === 'save') {
        $formValues = [
            'category_id' => max(0, (int) ($_POST['category_id'] ?? 0)),
            'name' => trim((string) ($_POST['name'] ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'status' => trim((string) ($_POST['status'] ?? 'Active')),
        ];
        $activeModal = $formValues['category_id'] > 0 ? 'edit' : 'add';
        $selectedCategoryId = (int) $formValues['category_id'];

        try {
            $payload = normalizeCategoryPayload($pdo, $_POST, (int) $formValues['category_id']);

            if ((int) $formValues['category_id'] > 0) {
                updateCategoryRecord($pdo, (int) $formValues['category_id'], $payload);
                setFlashMessage('category_success', 'Category updated successfully.');
            } else {
                createCategoryRecord($pdo, $payload);
                setFlashMessage('category_success', 'Category created successfully.');
            }

            header('Location: ' . buildCategoryManagementUrl([
                'search' => $search,
                'page' => $currentPage,
            ]));
            exit;
        } catch (Throwable $exception) {
            $formError = $exception->getMessage();
        }
    }
}

if ($requestMethod === 'GET') {
    if ($activeModal === 'edit' && $selectedCategoryId > 0) {
        $editingCategory = fetchCategoryById($pdo, $selectedCategoryId);
        if ($editingCategory === null) {
            $pageError = 'The selected category could not be found.';
            $activeModal = '';
        } else {
            $formValues = [
                'category_id' => (int) $editingCategory['category_id'],
                'name' => (string) $editingCategory['name'],
                'description' => (string) $editingCategory['description'],
                'status' => (string) $editingCategory['status'],
            ];
        }
    }

    if ($activeModal === 'delete' && $selectedCategoryId > 0) {
        $deleteCategory = fetchCategoryById($pdo, $selectedCategoryId);
        if ($deleteCategory === null) {
            $pageError = 'The selected category could not be found.';
            $activeModal = '';
        }
    }
}

$categoriesData = fetchPagedCategories($pdo, $search, $currentPage, CATEGORIES_PER_PAGE);
$categories = $categoriesData['items'];
$pagination = $categoriesData['pagination'];
$totalPages = (int) $pagination['totalPages'];
$currentPage = (int) $pagination['currentPage'];
$totalItems = (int) $pagination['totalItems'];
$pageStart = $totalItems > 0 ? (($currentPage - 1) * CATEGORIES_PER_PAGE) + 1 : 0;
$pageEnd = min($totalItems, $currentPage * CATEGORIES_PER_PAGE);
$pageNumberStart = max(1, $currentPage - 2);
$pageNumberEnd = min($totalPages, $pageNumberStart + 4);

$menuItems = buildAdminManagementMenu('categories');
$adminProductsStylesheetVersion = (string) filemtime(__DIR__ . '/assets/css/admin-products.css');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Category Management | MOON s Fabric Shop</title>
    <link rel="stylesheet" href="assets/css/admin-products.css?v=<?= escape($adminProductsStylesheetVersion); ?>">
</head>

<body class="products-body">
    <button class="sidebar-overlay" data-sidebar-overlay hidden aria-label="Close menu"></button>

    <div class="dashboard-app">
        <aside class="dashboard-sidebar" data-dashboard-sidebar>
            <div class="sidebar-brand">
                <div>
                    <span class="sidebar-eyebrow">MOON s Fabric Shop</span>
                    <h1>Category Admin</h1>
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
                        <span class="dashboard-label">Category Management</span>
                        <h2>Manage shop categories</h2>
                    </div>
                </div>

                <div class="topbar-right">
                    <span class="topbar-meta-label">Last login</span>
                    <span class="topbar-meta-value"><?= escape(formatCategoryDate($lastLogin)); ?></span>
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

                <section class="metric-grid">
                    <article class="metric-card tone-blue">
                        <span class="metric-title">Total Categories</span>
                        <strong class="metric-value"><?= escape(number_format((int) $summary['totalCategories'])); ?></strong>
                    </article>
                    <article class="metric-card tone-sky">
                        <span class="metric-title">Active Categories</span>
                        <strong class="metric-value"><?= escape(number_format((int) $summary['activeCategories'])); ?></strong>
                    </article>
                    <article class="metric-card tone-slate">
                        <span class="metric-title">Inactive Categories</span>
                        <strong class="metric-value"><?= escape(number_format((int) $summary['inactiveCategories'])); ?></strong>
                    </article>
                    <article class="metric-card tone-amber">
                        <span class="metric-title">Linked Categories</span>
                        <strong class="metric-value"><?= escape(number_format((int) $summary['categoriesWithProducts'])); ?></strong>
                    </article>
                </section>

                <section class="panel-grid">
                    <article class="products-card products-card-wide">
                        <div class="section-head">
                            <div class="section-copy">
                                <span class="dashboard-label">Category Directory</span>
                                <h3>Search, review, and maintain categories</h3>
                            </div>
                            <div class="toolbar-actions">
                                <a
                                    class="toolbar-button"
                                    href="<?= escape(buildCategoryManagementUrl([
                                                'search' => $search,
                                                'page' => $currentPage,
                                                'modal' => 'add',
                                            ])); ?>#category-modal">
                                    Add Category
                                </a>
                            </div>
                        </div>

                        <form method="get" class="filters-form">
                            <div class="filter-grid filter-grid-two">
                                <label class="input-group">
                                    <span>Search categories</span>
                                    <input
                                        type="search"
                                        name="search"
                                        value="<?= escape($search); ?>"
                                        placeholder="Search by category name or description">
                                </label>

                                <div class="filter-actions">
                                    <button type="submit" class="toolbar-button">Apply Search</button>
                                    <a href="admin-categories.php" class="toolbar-link">Reset</a>
                                </div>
                            </div>
                        </form>

                        <div class="list-meta">
                            <div class="list-meta-copy">
                                Showing <?= escape((string) $pageStart); ?>-<?= escape((string) $pageEnd); ?>
                                of <?= escape((string) $totalItems); ?> categories
                            </div>
                        </div>

                        <div class="table-shell">
                            <table class="products-table">
                                <thead>
                                    <tr>
                                        <th>Category Name</th>
                                        <th>Description</th>
                                        <th>Status</th>
                                        <th>Products</th>
                                        <th class="align-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($categories === []): ?>
                                        <tr>
                                            <td colspan="5" class="empty-row">
                                                <?php if ((int) $summary['totalCategories'] === 0): ?>
                                                    No categories have been added yet.
                                                <?php else: ?>
                                                    No matching categories were found for the current search.
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($categories as $category): ?>
                                            <tr>
                                                <td>
                                                    <div class="table-primary"><?= escape((string) $category['name']); ?></div>
                                                </td>
                                                <td>
                                                    <div class="table-secondary">
                                                        <?= escape((string) ($category['description'] ?: 'No description added')); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="status-chip <?= escape(getCategoryStatusClassName((string) $category['status'])); ?>">
                                                        <?= escape((string) $category['status']); ?>
                                                    </span>
                                                </td>
                                                <td class="strong-cell"><?= escape((string) $category['product_count']); ?></td>
                                                <td class="align-right">
                                                    <div class="action-row">
                                                        <form method="post">
                                                            <input type="hidden" name="csrf_token" value="<?= escape(getCsrfToken()); ?>">
                                                            <input type="hidden" name="action" value="toggle_status">
                                                            <input type="hidden" name="category_id" value="<?= escape((string) $category['category_id']); ?>">
                                                            <input type="hidden" name="return_search" value="<?= escape($search); ?>">
                                                            <input type="hidden" name="return_page" value="<?= escape((string) $currentPage); ?>">
                                                            <button type="submit" class="action-button secondary">
                                                                <?= (string) $category['status'] === 'Active' ? 'Set Inactive' : 'Set Active'; ?>
                                                            </button>
                                                        </form>
                                                        <a
                                                            href="<?= escape(buildCategoryManagementUrl([
                                                                        'search' => $search,
                                                                        'page' => $currentPage,
                                                                        'modal' => 'edit',
                                                                        'category' => (int) $category['category_id'],
                                                                    ])); ?>#category-modal"
                                                            class="action-button secondary">
                                                            Edit
                                                        </a>
                                                        <a
                                                            href="<?= escape(buildCategoryManagementUrl([
                                                                        'search' => $search,
                                                                        'page' => $currentPage,
                                                                        'modal' => 'delete',
                                                                        'category' => (int) $category['category_id'],
                                                                    ])); ?>#category-modal"
                                                            class="action-button danger">
                                                            Delete
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="products-mobile-list">
                            <?php if ($categories === []): ?>
                                <div class="empty-card">
                                    <?php if ((int) $summary['totalCategories'] === 0): ?>
                                        No categories have been added yet.
                                    <?php else: ?>
                                        No matching categories were found for the current search.
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <?php foreach ($categories as $category): ?>
                                    <article class="mobile-product-card">
                                        <div class="mobile-product-head">
                                            <div class="mobile-product-copy">
                                                <h4><?= escape((string) $category['name']); ?></h4>
                                                <p><?= escape((string) ($category['description'] ?: 'No description added')); ?></p>
                                            </div>
                                            <span class="status-chip <?= escape(getCategoryStatusClassName((string) $category['status'])); ?>">
                                                <?= escape((string) $category['status']); ?>
                                            </span>
                                        </div>

                                        <div class="mobile-product-grid">
                                            <div>
                                                <span>Products</span>
                                                <strong><?= escape((string) $category['product_count']); ?></strong>
                                            </div>
                                            <div>
                                                <span>Status</span>
                                                <strong><?= escape((string) $category['status']); ?></strong>
                                            </div>
                                        </div>

                                        <div class="action-row mobile-actions">
                                            <form method="post" class="full-width">
                                                <input type="hidden" name="csrf_token" value="<?= escape(getCsrfToken()); ?>">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="category_id" value="<?= escape((string) $category['category_id']); ?>">
                                                <input type="hidden" name="return_search" value="<?= escape($search); ?>">
                                                <input type="hidden" name="return_page" value="<?= escape((string) $currentPage); ?>">
                                                <button type="submit" class="action-button secondary full-width">
                                                    <?= (string) $category['status'] === 'Active' ? 'Set Inactive' : 'Set Active'; ?>
                                                </button>
                                            </form>
                                            <a
                                                href="<?= escape(buildCategoryManagementUrl([
                                                            'search' => $search,
                                                            'page' => $currentPage,
                                                            'modal' => 'edit',
                                                            'category' => (int) $category['category_id'],
                                                        ])); ?>#category-modal"
                                                class="action-button secondary full-width">
                                                Edit
                                            </a>
                                            <a
                                                href="<?= escape(buildCategoryManagementUrl([
                                                            'search' => $search,
                                                            'page' => $currentPage,
                                                            'modal' => 'delete',
                                                            'category' => (int) $category['category_id'],
                                                        ])); ?>#category-modal"
                                                class="action-button danger full-width">
                                                Delete
                                            </a>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <?php if ($totalPages > 1): ?>
                            <nav class="pagination">
                                <a
                                    href="<?= escape(buildCategoryManagementUrl([
                                                'search' => $search,
                                                'page' => max(1, $currentPage - 1),
                                            ])); ?>"
                                    class="page-link<?= $currentPage <= 1 ? ' is-disabled' : ''; ?>">
                                    Previous
                                </a>

                                <?php for ($pageNumber = $pageNumberStart; $pageNumber <= $pageNumberEnd; $pageNumber++): ?>
                                    <a
                                        href="<?= escape(buildCategoryManagementUrl([
                                                    'search' => $search,
                                                    'page' => $pageNumber,
                                                ])); ?>"
                                        class="page-link<?= $pageNumber === $currentPage ? ' is-active' : ''; ?>">
                                        <?= escape((string) $pageNumber); ?>
                                    </a>
                                <?php endfor; ?>

                                <a
                                    href="<?= escape(buildCategoryManagementUrl([
                                                'search' => $search,
                                                'page' => min($totalPages, $currentPage + 1),
                                            ])); ?>"
                                    class="page-link<?= $currentPage >= $totalPages ? ' is-disabled' : ''; ?>">
                                    Next
                                </a>
                            </nav>
                        <?php endif; ?>
                    </article>
                </section>
            </main>
        </div>
    </div>

    <?php if (in_array($activeModal, ['add', 'edit'], true)): ?>
        <div class="modal-backdrop">
            <div class="modal-shell" id="category-modal">
                <div class="modal-head">
                    <div class="section-copy">
                        <span class="dashboard-label"><?= $activeModal === 'edit' ? 'Edit Category' : 'Add Category'; ?></span>
                        <h3><?= $activeModal === 'edit' ? 'Update category details' : 'Create a new category'; ?></h3>
                        <p><?= $activeModal === 'edit' ? 'Adjust the category name, description, or visibility status.' : 'Add a new category for products in the shop catalog.'; ?></p>
                    </div>
                    <a
                        href="<?= escape(buildCategoryManagementUrl([
                                    'search' => $search,
                                    'page' => $currentPage,
                                ])); ?>"
                        class="modal-close">
                        Close
                    </a>
                </div>

                <?php if ($formError !== ''): ?>
                    <div class="status-message is-error form-message">
                        <?= escape($formError); ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="product-form">
                    <input type="hidden" name="csrf_token" value="<?= escape(getCsrfToken()); ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="category_id" value="<?= escape((string) $formValues['category_id']); ?>">
                    <input type="hidden" name="return_search" value="<?= escape($search); ?>">
                    <input type="hidden" name="return_page" value="<?= escape((string) $currentPage); ?>">

                    <label class="input-group">
                        <span>Category name</span>
                        <input type="text" name="name" value="<?= escape((string) $formValues['name']); ?>" required>
                    </label>

                    <label class="input-group">
                        <span>Description</span>
                        <textarea name="description" rows="4" placeholder="Write a short category description"><?= escape((string) $formValues['description']); ?></textarea>
                    </label>

                    <label class="input-group">
                        <span>Status</span>
                        <select name="status" required>
                            <?php foreach (CATEGORY_STATUS_OPTIONS as $statusOption): ?>
                                <option value="<?= escape($statusOption); ?>" <?= (string) $formValues['status'] === $statusOption ? 'selected' : ''; ?>>
                                    <?= escape($statusOption); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <div class="modal-actions">
                        <a
                            href="<?= escape(buildCategoryManagementUrl([
                                        'search' => $search,
                                        'page' => $currentPage,
                                    ])); ?>"
                            class="toolbar-link">
                            Cancel
                        </a>
                        <button type="submit" class="toolbar-button">
                            <?= $activeModal === 'edit' ? 'Save Changes' : 'Create Category'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($activeModal === 'delete' && $deleteCategory !== null): ?>
        <div class="modal-backdrop">
            <div class="modal-shell modal-shell-small" id="category-modal">
                <div class="modal-head">
                    <div class="section-copy">
                        <span class="dashboard-label">Delete Category</span>
                        <h3>Confirm category removal</h3>
                        <p>Review the category details before removing it from the admin panel.</p>
                    </div>
                    <a
                        href="<?= escape(buildCategoryManagementUrl([
                                    'search' => $search,
                                    'page' => $currentPage,
                                ])); ?>"
                        class="modal-close">
                        Close
                    </a>
                </div>

                <div class="delete-summary">
                    <div class="summary-row">
                        <span>Category</span>
                        <strong><?= escape((string) $deleteCategory['name']); ?></strong>
                    </div>
                    <div class="summary-row">
                        <span>Status</span>
                        <strong><?= escape((string) $deleteCategory['status']); ?></strong>
                    </div>
                    <div class="summary-row">
                        <span>Linked products</span>
                        <strong><?= escape((string) $deleteCategory['product_count']); ?></strong>
                    </div>
                </div>

                <?php if ((int) $deleteCategory['product_count'] > 0): ?>
                    <div class="status-message is-warning form-message">
                        This category is linked to <?= escape((string) $deleteCategory['product_count']); ?> product(s). Move or delete those products first, then try again.
                    </div>
                    <div class="modal-actions">
                        <a
                            href="<?= escape(buildCategoryManagementUrl([
                                        'search' => $search,
                                        'page' => $currentPage,
                                    ])); ?>"
                            class="toolbar-button secondary-button">
                            Back to list
                        </a>
                    </div>
                <?php else: ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= escape(getCsrfToken()); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="category_id" value="<?= escape((string) $deleteCategory['category_id']); ?>">
                        <input type="hidden" name="return_search" value="<?= escape($search); ?>">
                        <input type="hidden" name="return_page" value="<?= escape((string) $currentPage); ?>">

                        <div class="modal-actions">
                            <a
                                href="<?= escape(buildCategoryManagementUrl([
                                            'search' => $search,
                                            'page' => $currentPage,
                                        ])); ?>"
                                class="toolbar-link">
                                Cancel
                            </a>
                            <button type="submit" class="action-button danger">
                                Delete Category
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <script src="assets/js/admin-dashboard.js"></script>
</body>

</html>