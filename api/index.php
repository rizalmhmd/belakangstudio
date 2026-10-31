<?php
$allowedPages = [
    'index.php',
    'menu.php',
    'add_to_cart.php',
    'cart.php',
    'cart_update.php',
    'checkout.php',
    'order_submit.php',
    'order_success.php',
    'admin_login.php',
    'admin_dashboard.php',
    'admin_kelola_stok.php',
    'menu_manage.php',
    'laporan.php',
    'laporan_export.php',
    'laporan_export_pdf.php',
    'logout.php',
    'owner_login.php',
    'owner_dashboard.php',
    'owner_laporan.php',
    'owner_logout.php',
    'customer_logout.php',
    'db.php',
];

$requested = $_GET['__page'] ?? '';
if ($requested === '') {
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $requested = ltrim($uriPath, '/');
}

if ($requested === '' || $requested === '/') {
    $requested = 'index.php';
}

$requested = basename($requested);
if (!in_array($requested, $allowedPages, true)) {
    http_response_code(404);
    echo "404 Halaman Tidak Ditemukan";
    exit;
}

$rootDir = dirname(__DIR__);
chdir($rootDir);
$_SERVER['PHP_SELF'] = '/' . $requested;
$_SERVER['SCRIPT_NAME'] = '/' . $requested;
$_SERVER['SCRIPT_FILENAME'] = $rootDir . '/' . $requested;

require $rootDir . '/' . $requested;

