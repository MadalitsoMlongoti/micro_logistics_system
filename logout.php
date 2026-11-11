
<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

/*
|--------------------------------------------------------------------------
| LOGOUT
|--------------------------------------------------------------------------
| Destroy the authenticated session, clear the session cookie,
| and prevent the browser from caching the previous page.
|--------------------------------------------------------------------------
*/

secure_logout();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

header('Location: login.php');
exit;
