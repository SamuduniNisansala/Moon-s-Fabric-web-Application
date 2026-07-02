<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/store.php';

function ensureAdminSessionHelpersLoaded(): void
{
    require_once __DIR__ . '/includes/session.php';
}

function fetchAdminByEmail(?PDO $pdo, string $email): ?array
{
    if (!$pdo instanceof PDO || trim($email) === '') {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT admin_id, name, email, password
         FROM admin
         WHERE LOWER(email) = LOWER(:email)
         LIMIT 1'
    );
    $statement->execute(['email' => trim($email)]);
    $admin = $statement->fetch();

    return $admin !== false ? $admin : null;
}

function verifyAdminLoginPassword(array $admin, string $password): bool
{
    $storedPassword = (string) ($admin['password'] ?? '');
    $passwordInfo = password_get_info($storedPassword);
    $isHashedPassword = ($passwordInfo['algoName'] ?? 'unknown') !== 'unknown';

    return $isHashedPassword
        ? password_verify($password, $storedPassword)
        : hash_equals($storedPassword, $password);
}

function rehashAdminPasswordIfNeeded(PDO $pdo, array $admin, string $password): void
{
    $storedPassword = (string) ($admin['password'] ?? '');
    $passwordInfo = password_get_info($storedPassword);
    $isHashedPassword = ($passwordInfo['algoName'] ?? 'unknown') !== 'unknown';

    if ($isHashedPassword) {
        return;
    }

    $statement = $pdo->prepare(
        'UPDATE admin
         SET password = :password
         WHERE admin_id = :admin_id'
    );
    $statement->execute([
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'admin_id' => (int) $admin['admin_id'],
    ]);
}

$pdo = getStorePdo();
$loginError = '';
$loginEmail = trim((string) ($_POST['email'] ?? ''));
$customer = fetchCurrentStoreCustomer($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) ($_POST['password'] ?? '');

    if (!isValidStoreCsrfToken($_POST['csrf_token'] ?? null)) {
        $loginError = 'Security validation failed. Please refresh the page and try again.';
    } elseif (!filter_var($loginEmail, FILTER_VALIDATE_EMAIL)) {
        $loginError = 'Please enter a valid email address.';
    } elseif ($password === '') {
        $loginError = 'Please enter your password.';
    } elseif (!$pdo instanceof PDO) {
        $loginError = 'The store database is unavailable right now.';
    } else {
        $admin = fetchAdminByEmail($pdo, $loginEmail);
        $customerRecord = fetchStoreCustomerByEmail($pdo, $loginEmail, true);

        if ($admin !== null && verifyAdminLoginPassword($admin, $password)) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            ensureAdminSessionHelpersLoaded();
            ensureAdminSessionStarted();
            clearLoginAttempts();
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $admin['admin_id'];
            $_SESSION['admin_name'] = (string) $admin['name'];
            $_SESSION['admin_email'] = (string) $admin['email'];
            $_SESSION['admin_last_login'] = date('Y-m-d H:i:s');

            rehashAdminPasswordIfNeeded($pdo, $admin, $password);

            header('Location: admin-dashboard.php');
            exit;
        }

        if ($customerRecord !== null && password_verify($password, (string) ($customerRecord['password'] ?? ''))) {
            ensureStoreSessionStarted();
            signInStoreCustomer($customerRecord);
            pushStoreFlashMessage('Welcome back to ' . STORE_SHOP_NAME . '.', 'success');
            header('Location: index.php');
            exit;
        }

        if ($admin !== null) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            ensureAdminSessionHelpersLoaded();
            ensureAdminSessionStarted();
            registerLoginAttempt();
            session_write_close();
            ensureStoreSessionStarted();
        }

        $loginError = 'Incorrect email or password. Please try again.';
    }
}

$storeCsrfToken = getStoreCsrfToken();
$customer = fetchCurrentStoreCustomer($pdo);

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

require_once __DIR__ . '/includes/admin-auth.php';

$adminFlashError = pullFlashMessage('auth_error');
$adminFlashSuccess = pullFlashMessage('logout_message');
$adminAuthenticated = isAdminAuthenticated();
$adminName = $adminAuthenticated ? (string) ($_SESSION['admin_name'] ?? 'Admin') : 'Admin';

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

ensureStoreSessionStarted();

renderStoreHeader('Login', $customer !== null ? 'account' : 'home');
?>
<main>
    <section class="site-shell page-banner compact">
        <span class="eyebrow">Shared Login</span>
    </section>

    <?php if ($loginError !== '' || $adminFlashError !== null || $adminFlashSuccess !== null): ?>
        <section class="site-shell flash-stack" style="padding-top: 0; margin-bottom: 1rem;">
            <?php if ($loginError !== ''): ?>
                <div class="flash flash-error"><?= storeEscape($loginError); ?></div>
            <?php endif; ?>
            <?php if ($adminFlashError !== null): ?>
                <div class="flash flash-error"><?= storeEscape($adminFlashError); ?></div>
            <?php endif; ?>
            <?php if ($adminFlashSuccess !== null): ?>
                <div class="flash flash-success"><?= storeEscape($adminFlashSuccess); ?></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="site-shell account-layout">
        <div class="account-visual">
            
        </div>

        <div class="account-stack">
            <article class="account-card">
                <h2>Login</h2>
                <p>Use your customer or admin account details here.</p>

                <?php if (!$customer && !$adminAuthenticated): ?>
                    <form method="post" action="login.php">
                        <input type="hidden" name="csrf_token" value="<?= storeEscape($storeCsrfToken); ?>">

                        <label class="field-label">
                            Email
                            <input type="email" name="email" required value="<?= storeEscape($loginEmail); ?>">
                        </label>

                        <label class="field-label">
                            Password
                            <input type="password" name="password" required>
                        </label>

                        <button class="btn btn-gradient" type="submit">Login</button>
                    </form>
                <?php else: ?>
                    <div class="split-actions" style="margin-top: 1rem;">
                        <?php if ($customer): ?>
                            <a class="btn btn-gradient" href="index.php">Go to Home</a>
                            <a class="btn btn-secondary" href="profile.php">Customer Profile</a>
                        <?php endif; ?>
                        <?php if ($adminAuthenticated): ?>
                            <a class="btn btn-gradient" href="admin-dashboard.php">Open Dashboard</a>
                            <a class="btn btn-secondary" href="admin-logout.php">Admin Logout</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            </article>

            <?php if ($customer): ?>
                <article class="account-card">
                    <h2>Customer session is active</h2>
                    <p>Signed in customer: <?= storeEscape((string) $customer['email']); ?></p>
                    <form method="post" action="store-action.php">
                        <input type="hidden" name="csrf_token" value="<?= storeEscape($storeCsrfToken); ?>">
                        <input type="hidden" name="action" value="logout">
                        <input type="hidden" name="redirect_to" value="index.php">
                        <button class="btn btn-secondary" type="submit">Logout Customer</button>
                    </form>
                </article>
            <?php endif; ?>

            <?php if ($adminAuthenticated): ?>
                <article class="account-card">
                    <h2>Admin session is active</h2>
                    <p>Signed in admin: <?= storeEscape($adminName); ?></p>
                    <div class="split-actions" style="margin-top: 1rem;">
                        <a class="btn btn-gradient" href="admin-dashboard.php">Dashboard</a>
                        <a class="btn btn-secondary" href="admin-logout.php">Logout Admin</a>
                    </div>
                </article>
            <?php endif; ?>

            <?php if (!$customer): ?>
                <article class="account-card">
                    <h2>Need a customer account?</h2>
                    <a class="btn btn-secondary" href="account.php?mode=register&redirect=index.php">Create Customer Account</a>
                </article>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php renderStoreFooter(); ?>
