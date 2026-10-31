<?php
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['customer']) || empty($_SESSION['cart'])) {
    header("Location: menu.php");
    exit;
}

$customer = $_SESSION['customer'];
$cart     = $_SESSION['cart'];
$paymentMethod = strtolower($_SESSION['payment_method'] ?? 'cash');
if (!in_array($paymentMethod, ['cash', 'qris'], true)) {
    header("Location: checkout.php");
    exit;
}
$payment = $paymentMethod;
$bukti_qris = $_SESSION['bukti_qris'] ?? null;

$mysqli->begin_transaction();

try {
    // 1. Hitung total
    $total = 0;
    foreach ($cart as $it) {
        $total += $it['price'] * $it['qty'];
    }

    // 2. Simpan ke tabel orders
    $sqlOrder = "INSERT INTO orders (customer_name, table_number, total, payment_method, bukti_qris, status, created_at) VALUES (?, ?, ?, ?, ?, 'baru', NOW())";
    $stmt = $mysqli->prepare($sqlOrder);
    $stmt->bind_param("ssiss", $customer['name'], $customer['table'], $total, $payment, $bukti_qris);
    
    if (!$stmt->execute()) {
        throw new Exception("Gagal simpan order: " . $stmt->error);
    }
    $order_id = $stmt->insert_id;
    $stmt->close();

    // 3. Simpan ke order_items
    $stmtItem = $mysqli->prepare("INSERT INTO order_items (order_id, menu_id, menu_name, variant, qty, price, note, subtotal) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($cart as $it) {
        $subtotal = $it['price'] * $it['qty'];
        $menu_id  = intval($it['menu_id']);
        $note     = $it['note'] ?? '';
        
        $stmtItem->bind_param("iissiisi", $order_id, $menu_id, $it['name'], $it['variant'], $it['qty'], $it['price'], $note, $subtotal);
        
        if (!$stmtItem->execute()) {
            throw new Exception("Gagal simpan item: " . $stmtItem->error);
        }

        // 4. Update Stok
        $stmtUpdate = $mysqli->prepare("UPDATE menus SET stock = stock - ? WHERE id = ? AND stock >= ?");
        $stmtUpdate->bind_param("iii", $it['qty'], $menu_id, $it['qty']);
        if (!$stmtUpdate->execute() || $stmtUpdate->affected_rows !== 1) {
            throw new Exception("Stok " . $it['name'] . " tidak mencukupi. Silakan kembali ke menu dan perbarui pesanan.");
        }
        $stmtUpdate->close();
    }
    $stmtItem->close();

    $mysqli->commit();
    unset($_SESSION['cart'], $_SESSION['payment_method'], $_SESSION['bukti_qris']);
    
    header("Location: order_success.php?id=" . $order_id);
    exit;

} catch (Exception $e) {
    $mysqli->rollback();
    die("Transaksi Gagal: " . $e->getMessage());
}