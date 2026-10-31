<?php
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// fungsi rupiah
if (!function_exists('rupiah')) {
  function rupiah($angka) {
    return "Rp " . number_format($angka, 0, ',', '.');
  }
}

header('Content-Type: application/json');

// pastikan ada keranjang
if (!isset($_SESSION['cart'])) {
  echo json_encode(['success' => false, 'message' => 'Keranjang kosong.']);
  exit;
}

$key  = $_POST['key']  ?? null;
$qty  = isset($_POST['qty']) ? (int) $_POST['qty'] : 0;
$note = trim($_POST['note'] ?? '');

if ($key === null || !isset($_SESSION['cart'][$key])) {
  echo json_encode(['success' => false, 'message' => 'Item tidak ditemukan.']);
  exit;
}

if ($qty <= 0) {
  // hapus item jika qty <= 0
  unset($_SESSION['cart'][$key]);
} else {
  $_SESSION['cart'][$key]['qty']  = $qty;
  $_SESSION['cart'][$key]['note'] = $note;
}

// hitung ulang total
$total = 0;
foreach ($_SESSION['cart'] as $it) {
  $total += $it['price'] * $it['qty'];
}

$subtotal = 0;
if (isset($_SESSION['cart'][$key])) {
  $item = $_SESSION['cart'][$key];
  $subtotal = $item['price'] * $item['qty'];
}

echo json_encode([
  'success'  => true,
  'subtotal' => rupiah($subtotal),
  'total'    => rupiah($total),
]);
