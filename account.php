<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/store.php';

$pdo = getStorePdo();
$customer = fetchCurrentStoreCustomer($pdo);
$mode = trim((string) ($_GET['mode'] ?? 'login'));
$mode = $mode === 'register' ? 'register' : 'login';
$redirectTarget = sanitizeStoreRedirect($_GET['redirect'] ?? 'profile.php', 'profile.php');
$isCheckoutIntent = $redirectTarget === 'checkout.php';

if ($customer === null && $mode === 'login') {
    redirectStorePage('login.php', null, 'info');
}

renderStoreHeader('Account', 'account');
?>
<main>
    <section class="site-shell page-banner compact">
        <span class="eyebrow">Customer Account</span>
        <h1>Create your customer profile.</h1>
        <p>Use this page for registration. Customer and admin sign-in now happen through the shared login page.</p>
    </section>

    <section class="site-shell account-layout">
        <div class="account-visual">
            <div class="account-visual-copy">
            </div>
        </div>

        <div class="account-stack">
            <?php if ($customer !== null): ?>
                <article class="account-card">
                    <h2>You are already signed in</h2>
                    <p>Jump into your profile, review past orders, or log out when you are done.</p>
                    <div class="split-actions" style="margin-top: 1rem;">
                        <a class="btn btn-gradient" href="profile.php">Profile</a>
                        <a class="btn btn-secondary" href="orders.php">Order History</a>
                    </div>
                    <form method="post" action="store-action.php" style="margin-top: 1rem;">
                        <input type="hidden" name="csrf_token" value="<?= storeEscape(getStoreCsrfToken()); ?>">
                        <input type="hidden" name="action" value="logout">
                        <input type="hidden" name="redirect_to" value="index.php">
                        <button class="btn btn-secondary" type="submit">Logout</button>
                    </form>
                </article>
            <?php else: ?>
                <article class="account-card">
                    <h2>Register</h2>
                    <p>Create your customer profile with the details needed for future orders.</p>
                    <form method="post" action="store-action.php">
                        <input type="hidden" name="csrf_token" value="<?= storeEscape(getStoreCsrfToken()); ?>">
                        <input type="hidden" name="action" value="register">
                        <input type="hidden" name="redirect_to" value="<?= storeEscape($redirectTarget); ?>">
                        <input type="hidden" name="auth_page" value="account.php">

                        <label class="field-label">
                            Name
                            <input type="text" name="name" required>
                        </label>

                        <label class="field-label">
                            Email
                            <input type="email" name="email" required>
                        </label>

                        <label class="field-label">
                            Phone
                            <input type="tel" name="phone" required>
                        </label>

                        <label class="field-label">
                            Password
                            <input type="password" name="password" required minlength="6">
                        </label>

                        <button class="btn btn-gradient" type="submit">Register</button>
                    </form>
                </article>

                <article class="account-card">
                    <h2>Already have an account?</h2>
                    <a class="btn btn-secondary" href="login.php">Open shared login</a>
                </article>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php renderStoreFooter(); ?>
