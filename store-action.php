<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/store.php';

function redirectStoreRequest(string $fallback = 'index.php'): never
{
    $redirectTo = sanitizeStoreRedirect($_POST['redirect_to'] ?? $fallback, $fallback);
    header('Location: ' . $redirectTo);
    exit;
}

function redirectStoreTarget(string $target): never
{
    header('Location: ' . sanitizeStoreRedirect($target, 'index.php'));
    exit;
}

function resolveStoreAuthPage(string $fallback = 'account.php'): string
{
    $authPage = basename(trim((string) ($_POST['auth_page'] ?? $fallback)));
    return in_array($authPage, ['account.php', 'login.php'], true) ? $authPage : $fallback;
}

function buildStoreAuthTarget(string $mode = 'login', string $defaultRedirect = 'profile.php'): string
{
    $authPage = resolveStoreAuthPage();

    if ($authPage === 'login.php') {
        return 'login.php';
    }

    $query = [
        'redirect' => sanitizeStoreRedirect($_POST['redirect_to'] ?? $defaultRedirect, $defaultRedirect),
    ];

    if ($mode === 'register') {
        $query['mode'] = 'register';
    }

    return $authPage . '?' . http_build_query($query);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

if (!isValidStoreCsrfToken($_POST['csrf_token'] ?? null)) {
    pushStoreFlashMessage('Your session expired. Please try that again.', 'error');
    redirectStoreRequest();
}

$action = trim((string) ($_POST['action'] ?? ''));
$pdo = getStorePdo();

switch ($action) {
    case 'add_to_cart':
        $productId = (int) ($_POST['product_id'] ?? 0);
        $size = sanitizeStoreSize((string) ($_POST['size'] ?? 'M'));
        $quantity = max(1, min(20, (int) ($_POST['quantity'] ?? 1)));
        $product = fetchStoreProduct($pdo, $productId);

        if ($product === null) {
            pushStoreFlashMessage('That product is not available right now.', 'error');
            redirectStoreRequest();
        }

        if ((int) $product['stockQuantity'] <= 0) {
            pushStoreFlashMessage('This item is currently out of stock.', 'error');
            redirectStoreRequest();
        }

        addStoreCartItem($productId, $size, min($quantity, (int) $product['stockQuantity']));
        pushStoreFlashMessage($product['name'] . ' was added to your cart.', 'success');
        redirectStoreRequest();

    case 'update_cart_item':
        $cartKey = trim((string) ($_POST['cart_key'] ?? ''));
        $quantity = max(0, min(20, (int) ($_POST['quantity'] ?? 1)));

        if ($cartKey === '') {
            pushStoreFlashMessage('We could not update that cart item.', 'error');
            redirectStoreRequest('cart.php');
        }

        updateStoreCartItemQuantity($cartKey, $quantity);
        pushStoreFlashMessage('Your cart has been updated.', 'success');
        redirectStoreRequest('cart.php');

    case 'remove_cart_item':
        $cartKey = trim((string) ($_POST['cart_key'] ?? ''));

        if ($cartKey !== '') {
            removeStoreCartItem($cartKey);
            pushStoreFlashMessage('The item was removed from your cart.', 'success');
        }

        redirectStoreRequest('cart.php');

    case 'toggle_wishlist':
        $productId = (int) ($_POST['product_id'] ?? 0);
        $product = fetchStoreProduct($pdo, $productId);

        if ($product === null) {
            pushStoreFlashMessage('That product could not be found.', 'error');
            redirectStoreRequest();
        }

        $isSaved = toggleWishlistProduct($productId);
        pushStoreFlashMessage(
            $isSaved ? $product['name'] . ' was saved to your wishlist.' : $product['name'] . ' was removed from your wishlist.',
            'success'
        );
        redirectStoreRequest();

    case 'login':
        if (!$pdo instanceof PDO) {
            pushStoreFlashMessage('The store database is unavailable right now.', 'error');
            redirectStoreTarget(buildStoreAuthTarget('login', 'profile.php'));
        }

        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $customer = fetchStoreCustomerByEmail($pdo, $email, true);

        if ($customer === null || !password_verify($password, (string) ($customer['password'] ?? ''))) {
            pushStoreFlashMessage('Incorrect email or password. Please try again.', 'error');
            redirectStoreTarget(buildStoreAuthTarget('login', 'profile.php'));
        }

        signInStoreCustomer($customer);
        pushStoreFlashMessage('Welcome back to ' . STORE_SHOP_NAME . '.', 'success');
        redirectStoreRequest('profile.php');

    case 'register':
        if (!$pdo instanceof PDO) {
            pushStoreFlashMessage('The store database is unavailable right now.', 'error');
            redirectStoreTarget(buildStoreAuthTarget('register', 'profile.php'));
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($name === '' || $email === '' || $phone === '' || $password === '') {
            pushStoreFlashMessage('Please complete every registration field.', 'error');
            redirectStoreTarget(buildStoreAuthTarget('register', 'profile.php'));
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            pushStoreFlashMessage('Please enter a valid email address.', 'error');
            redirectStoreTarget(buildStoreAuthTarget('register', 'profile.php'));
        }

        if (mb_strlen($password) < 6) {
            pushStoreFlashMessage('Passwords should be at least 6 characters long.', 'error');
            redirectStoreTarget(buildStoreAuthTarget('register', 'profile.php'));
        }

        if (fetchStoreCustomerByEmail($pdo, $email) !== null) {
            pushStoreFlashMessage('That email address is already registered.', 'error');
            redirectStoreTarget(buildStoreAuthTarget('register', 'profile.php'));
        }

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $addressEnabled = storeCustomerHasAddressColumn($pdo);

        if ($addressEnabled) {
            $statement = $pdo->prepare(
                'INSERT INTO customer (name, email, phone, address, password, city)
                 VALUES (:name, :email, :phone, :address, :password, :city)'
            );
            $statement->execute([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'address' => '',
                'password' => $hashedPassword,
                'city' => '',
            ]);
        } else {
            $statement = $pdo->prepare(
                'INSERT INTO customer (name, email, phone, password, city)
                 VALUES (:name, :email, :phone, :password, :city)'
            );
            $statement->execute([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'password' => $hashedPassword,
                'city' => '',
            ]);
        }

        $customer = fetchStoreCustomerByEmail($pdo, $email);
        if ($customer !== null) {
            signInStoreCustomer($customer);
        }

        pushStoreFlashMessage('Your account is ready. Welcome to ' . STORE_SHOP_NAME . '.', 'success');
        redirectStoreRequest('profile.php');

    case 'update_profile':
        if (!$pdo instanceof PDO || !isCustomerAuthenticated()) {
            pushStoreFlashMessage('Please log in to update your profile.', 'error');
            redirectStoreRequest('login.php');
        }

        $customerId = getAuthenticatedCustomerId();
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $city = trim((string) ($_POST['city'] ?? ''));

        if ($name === '' || $email === '' || $phone === '' || $city === '') {
            pushStoreFlashMessage('Name, email, phone, and city are required.', 'error');
            redirectStoreRequest('profile.php');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            pushStoreFlashMessage('Please enter a valid email address.', 'error');
            redirectStoreRequest('profile.php');
        }

        $existing = fetchStoreCustomerByEmail($pdo, $email);
        if ($existing !== null && (int) $existing['cus_id'] !== $customerId) {
            pushStoreFlashMessage('Another account is already using that email.', 'error');
            redirectStoreRequest('profile.php');
        }

        if (storeCustomerHasAddressColumn($pdo)) {
            $statement = $pdo->prepare(
                'UPDATE customer
                 SET name = :name,
                     email = :email,
                     phone = :phone,
                     address = :address,
                     city = :city
                 WHERE cus_id = :customer_id'
            );
            $statement->execute([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'address' => $address,
                'city' => $city,
                'customer_id' => $customerId,
            ]);
        } else {
            $statement = $pdo->prepare(
                'UPDATE customer
                 SET name = :name,
                     email = :email,
                     phone = :phone,
                     city = :city
                 WHERE cus_id = :customer_id'
            );
            $statement->execute([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'city' => $city,
                'customer_id' => $customerId,
            ]);
        }

        $_SESSION['store_customer_name'] = $name;
        $_SESSION['store_customer_email'] = $email;

        pushStoreFlashMessage('Your profile was updated successfully.', 'success');
        redirectStoreRequest('profile.php');

    case 'logout':
        signOutStoreCustomer();
        pushStoreFlashMessage('You have been logged out.', 'success');
        redirectStoreRequest('index.php');

    case 'place_order':
        if (!$pdo instanceof PDO || !isCustomerAuthenticated()) {
            pushStoreFlashMessage('Please log in before placing your order.', 'error');
            redirectStoreRequest('login.php');
        }

        $cart = buildStoreCartDetails($pdo);
        if ($cart['items'] === []) {
            pushStoreFlashMessage('Your cart is empty.', 'error');
            redirectStoreRequest('cart.php');
        }

        $customerId = getAuthenticatedCustomerId();
        $name = trim((string) ($_POST['name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $city = trim((string) ($_POST['city'] ?? ''));
        $paymentMethod = trim((string) ($_POST['payment_method'] ?? 'COD'));

        if ($name === '' || $phone === '' || $address === '' || $city === '') {
            pushStoreFlashMessage('Please complete the delivery form before placing your order.', 'error');
            redirectStoreRequest('checkout.php');
        }

        if (!in_array($paymentMethod, ['COD', 'Bank Transfer'], true)) {
            pushStoreFlashMessage('Please choose a valid payment method.', 'error');
            redirectStoreRequest('checkout.php');
        }

        $shippingAddress = $name . "\n" . $phone . "\n" . $address . "\n" . $city;

        try {
            $pdo->beginTransaction();

            if (storeCustomerHasAddressColumn($pdo)) {
                $updateCustomer = $pdo->prepare(
                    'UPDATE customer
                     SET name = :name,
                         phone = :phone,
                         address = :address,
                         city = :city
                     WHERE cus_id = :customer_id'
                );
                $updateCustomer->execute([
                    'name' => $name,
                    'phone' => $phone,
                    'address' => $address,
                    'city' => $city,
                    'customer_id' => $customerId,
                ]);
            } else {
                $updateCustomer = $pdo->prepare(
                    'UPDATE customer
                     SET name = :name,
                         phone = :phone,
                         city = :city
                     WHERE cus_id = :customer_id'
                );
                $updateCustomer->execute([
                    'name' => $name,
                    'phone' => $phone,
                    'city' => $city,
                    'customer_id' => $customerId,
                ]);
            }

            $orderStatement = $pdo->prepare(
                'INSERT INTO orders (cus_id, total_amount, shopping_address, order_status)
                 VALUES (:customer_id, :total_amount, :shopping_address, "Pending")'
            );
            $orderStatement->execute([
                'customer_id' => $customerId,
                'total_amount' => $cart['subtotal'],
                'shopping_address' => $shippingAddress,
            ]);

            $orderId = (int) $pdo->lastInsertId();

            $itemStatement = $pdo->prepare(
                'INSERT INTO order_item (order_id, product_id, quantity, unit_price, total_price)
                 VALUES (:order_id, :product_id, :quantity, :unit_price, :total_price)'
            );

            foreach ($cart['items'] as $item) {
                $itemStatement->execute([
                    'order_id' => $orderId,
                    'product_id' => (int) $item['product']['productId'],
                    'quantity' => (int) $item['quantity'],
                    'unit_price' => (float) $item['product']['price'],
                    'total_price' => (float) $item['lineTotal'],
                ]);
            }

            $paymentStatement = $pdo->prepare(
                'INSERT INTO payment (order_id, payment_method, amount)
                 VALUES (:order_id, :payment_method, :amount)'
            );
            $paymentStatement->execute([
                'order_id' => $orderId,
                'payment_method' => $paymentMethod,
                'amount' => $cart['subtotal'],
            ]);

            $pdo->commit();
        } catch (Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            pushStoreFlashMessage('We could not place your order right now. Please try again.', 'error');
            redirectStoreRequest('checkout.php');
        }

        clearStoreCart();
        pushStoreFlashMessage('Order #' . $orderId . ' has been placed successfully.', 'success');
        redirectStoreRequest('orders.php');

    default:
        pushStoreFlashMessage('Unsupported storefront action.', 'error');
        redirectStoreRequest();
}
