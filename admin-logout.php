<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';

$_SESSION = [];
clearLoginAttempts();
session_regenerate_id(true);
setFlashMessage('logout_message', 'You have been logged out securely.');

header('Location: login.php');
exit;
