<?php
// order_success.php
require_once 'db.php'; // rupiah() sudah ada di sini
if (session_status() === PHP_SESSION_NONE) session_start();

$id = intval($_GET['id'] ?? 0);
if (!$id) {
  echo "Order ID tidak valid.";
  exit;
}

// ambil order
$stmt = $mysqli->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();
$order = $res->fetch_assoc();
$stmt->close();

if (!$order) {
  echo "Order tidak ditemukan.";
  exit;
}

// ambil items
$stmt = $mysqli->prepare("SELECT * FROM order_items WHERE order_id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();
$items = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title>Struk Pesanan #<?= $order['id'] ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body {
      margin: 0;
      font-family: monospace, "Courier New", Arial, sans-serif;
      background: #fff;
      color: #000;
      padding: 10px;
    }

    .receipt {
      max-width: 320px;   /* lebih sempit */
      margin: 0 auto;
      border: 1px dashed #000;
      padding: 12px;
    }

    h1 {
      font-size: 16px;
      text-align: center;
      margin: 0 0 8px 0;
    }

    .meta {
      font-size: 12px;
      text-align: center;
      margin-bottom: 10px;
      line-height: 1.4;
    }

    .line {
      border-top: 1px dashed #000;
      margin: 6px 0;
    }

    ul.items {
      list-style: none;
      padding: 0;
      margin: 0;
    }

    ul.items li {
      font-size: 12px;
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 6px;
    }

    .variant,
    .note {
      display: block;
      font-size: 11px;
      color: #333;
      margin-left: 4px;
    }

    .total-row {
      font-weight: bold;
      font-size: 13px;
      display: flex;
      justify-content: space-between;
      margin-top: 6px;
    }

    .payment {
      text-align: center;
      font-size: 12px;
      margin-top: 10px;
    }

    .qris img {
      width: 100%;
      max-width: 220px;
      max-height: 320px;
      object-fit: contain;
      margin-top: 6px;
      border: 1px solid #000;
    }

    .footer {
      margin-top: 12px;
      font-size: 11px;
      text-align: center;
    }

    .actions {
      margin-top: 8px;
      text-align: center;
    }

    .btn {
      display: inline-block;
      margin: 3px;
      padding: 6px 10px;
      font-size: 11px;
      border: 1px solid #000;
      text-decoration: none;
      color: #000;
    }

    @media print {
      body { background: #fff; padding: 0; }
      .btn, .actions { display: none; }
      .receipt { border: none; }
    }
  </style>
</head>
<body>
  <div class="receipt">
    <h1>☕ Belakang Studio</h1>
    <div class="meta">
      ID: #<?= $order['id'] ?><br>
      Nama: <?= htmlspecialchars($order['customer_name']) ?><br>
      Meja: <?= htmlspecialchars($order['table_number']) ?><br>
      <?= date("d M Y H:i", strtotime($order['created_at'])) ?>
    </div>
    <div class="line"></div>

    <ul class="items">
      <?php foreach ($items as $it): ?>
        <li>
          <div>
            <?= htmlspecialchars($it['menu_name']) ?> x<?= intval($it['qty']) ?>
            <?php if (!empty($it['variant'])): ?>
              <span class="variant">(<?= htmlspecialchars($it['variant']) ?>)</span>
            <?php endif; ?>
            <?php if (!empty($it['note'])): ?>
              <span class="note">* <?= htmlspecialchars($it['note']) ?></span>
            <?php endif; ?>
          </div>
          <div><?= rupiah($it['subtotal']) ?></div>
        </li>
      <?php endforeach; ?>
    </ul>

    <div class="line"></div>

    <div class="total-row">
      <span>Total</span>
      <span><?= rupiah($order['total']) ?></span>
    </div>

    <div class="payment">
      <?php $paymentMethod = strtolower($order['payment_method'] ?? 'cash'); ?>
      Metode: <?= strtoupper($paymentMethod) ?><br>
      <?php if ($paymentMethod === 'qris'): ?>
        <div class="qris">
          <img src="<?= htmlspecialchars($order['bukti_qris'] ?: 'images/1.png?v=20261014') ?>" alt="<?= !empty($order['bukti_qris']) ? 'Bukti pembayaran QRIS' : 'Kode QRIS Belakang Studio' ?>">
        </div>
      <?php else: ?>
        <p>💵 Bayar tunai ke kasir</p>
      <?php endif; ?>
    </div>

    <div class="footer">
      Terima kasih 🙏<br>
      Simpan struk ini sebagai bukti.
    </div>

    <div class="actions">
      <a href="index.php" class="btn">← Menu</a>
      <a href="#" onclick="window.print()" class="btn">🖨️ Print</a>
    </div>
  </div>
</body>
</html>
