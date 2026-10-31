<?php
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['customer'])) {
    header("Location: index.php");
    exit;
}

$isAjax = ($_POST['ajax'] ?? '') === '1';
$postItems = $_POST['items'] ?? [];
$existingCart = $_SESSION['cart'] ?? [];
$newCart = [];
$quantitiesByMenu = [];

foreach ($postItems as $key => $data) {
    $qty = filter_var($data['qty'] ?? 0, FILTER_VALIDATE_INT);
    if ($qty === false || $qty < 0 || !preg_match('/^(\d+)_(hot|ice)$/', (string)$key, $match)) {
        continue;
    }
    if ($qty === 0) {
        continue;
    }

    $menuId = (int)$match[1];
    $variant = $match[2];
    $quantitiesByMenu[$menuId] = ($quantitiesByMenu[$menuId] ?? 0) + $qty;
    $newCart[$key] = [
        'menu_id' => $menuId,
        'variant_key' => $variant,
        'qty' => $qty,
    ];
}

if (empty($newCart)) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Pilih minimal satu menu terlebih dahulu.']);
        exit;
    }
    header("Location: menu.php");
    exit;
}

$stmt = $mysqli->prepare("SELECT id, name, category, stock, price_hot, price_ice FROM menus WHERE id = ?");
foreach ($newCart as $key => &$item) {
    $menuId = $item['menu_id'];
    $stmt->bind_param("i", $menuId);
    if (!$stmt->execute()) {
        http_response_code(500);
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Gagal memeriksa data menu.']);
        } else {
            echo "Gagal memeriksa data menu.";
        }
        exit;
    }

    $menu = $stmt->get_result()->fetch_assoc();
    if (!$menu) {
        http_response_code(422);
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Salah satu menu tidak ditemukan. Muat ulang halaman dan coba kembali.']);
        } else {
            echo "Salah satu menu tidak ditemukan. Muat ulang halaman dan coba kembali.";
        }
        exit;
    }

    $priceColumn = $item['variant_key'] === 'hot' ? 'price_hot' : 'price_ice';
    $price = (int)$menu[$priceColumn];
    if ($price <= 0 || $quantitiesByMenu[$menuId] > (int)$menu['stock']) {
        http_response_code(422);
        $message = $price <= 0
            ? 'Varian menu yang dipilih tidak tersedia.'
            : 'Jumlah pesanan melebihi stok ' . $menu['name'] . '.';
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $message]);
        } else {
            echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        }
        exit;
    }

    $isFood = in_array(strtolower($menu['category']), ['food & snack', 'foods', 'snack'], true);
    $label = $item['variant_key'] === 'hot'
        ? ($isFood ? 'Reguler' : 'Hot / Default')
        : ($isFood ? 'Paket / Ekstra' : 'Ice');
    $item['name'] = $menu['name'] . ' (' . $label . ')';
    $item['variant'] = ucfirst($item['variant_key']);
    $item['price'] = $price;
    $item['stock'] = (int)$menu['stock'];
    $item['note'] = (string)($existingCart[$key]['note'] ?? '');
    $item['subtotal'] = $price * $item['qty'];
}
unset($item);
$stmt->close();

$_SESSION['cart'] = $newCart;

if ($isAjax) {
    $responseItems = [];
    $total = 0;
    foreach ($newCart as $key => $item) {
        $total += $item['subtotal'];
        $responseItems[] = [
            'key' => $key,
            'menu_id' => $item['menu_id'],
            'name' => $item['name'],
            'qty' => $item['qty'],
            'price' => $item['price'],
            'stock' => $item['stock'],
            'subtotal' => $item['subtotal'],
            'note' => $item['note'],
        ];
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'items' => $responseItems,
        'total' => $total,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

header("Location: cart.php");
exit;
