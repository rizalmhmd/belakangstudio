<?php
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['customer']) || empty($_SESSION['cart'])) {
  header("Location: menu.php");
  exit;
}

$customer = $_SESSION['customer'];
$cart = $_SESSION['cart'];

$total = 0;
foreach ($cart as $it) {
  $total += $it['price'] * $it['qty'];
}

$uploadDir = __DIR__ . "/uploads/";
if (!is_dir($uploadDir) && !getenv('VERCEL')) {
  @mkdir($uploadDir, 0777, true);
}

$error = "";
$isAjax = ($_POST['ajax'] ?? '') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax && isset($_POST['quantities'])) {
  $updatedCart = $_SESSION['cart'];
  $quantitiesByMenu = [];

  if (!is_array($_POST['quantities'])) {
    $error = "Jumlah menu tidak valid. Silakan muat ulang halaman.";
  } else {
    foreach ($updatedCart as $key => $item) {
      if (!array_key_exists($key, $_POST['quantities'])) {
        $error = "Ringkasan menu berubah. Silakan muat ulang halaman.";
        break;
      }
    }

    foreach ($_POST['quantities'] as $key => $rawQuantity) {
      if ($error !== '') {
        break;
      }
      if (!isset($updatedCart[$key]) || !is_scalar($rawQuantity)) {
        $error = "Jumlah menu tidak valid. Silakan muat ulang halaman.";
        break;
      }

      $quantity = filter_var($rawQuantity, FILTER_VALIDATE_INT);
      if ($quantity === false || $quantity < 0) {
        $error = "Jumlah menu tidak valid.";
        break;
      }

      $menuId = (int)$updatedCart[$key]['menu_id'];
      $updatedCart[$key]['qty'] = $quantity;
      $quantitiesByMenu[$menuId] = ($quantitiesByMenu[$menuId] ?? 0) + $quantity;
    }
  }

  if ($error === '') {
    $stockStatement = $mysqli->prepare("SELECT stock FROM menus WHERE id = ?");
    if (!$stockStatement) {
      error_log('Gagal menyiapkan pemeriksaan stok checkout: ' . $mysqli->error);
      $error = "Gagal memeriksa stok menu. Silakan coba kembali.";
    } else {
      foreach ($quantitiesByMenu as $menuId => $quantity) {
        $stockStatement->bind_param("i", $menuId);
        if (!$stockStatement->execute()) {
          error_log('Gagal memeriksa stok checkout: ' . $stockStatement->error);
          $error = "Gagal memeriksa stok menu. Silakan coba kembali.";
          break;
        }

        $menuStock = $stockStatement->get_result()->fetch_assoc();
        if (!$menuStock || $quantity > (int)$menuStock['stock']) {
          $error = "Jumlah pesanan melebihi stok yang tersedia. Silakan sesuaikan jumlah menu.";
          break;
        }
      }
      $stockStatement->close();
    }
  }

  if ($error === '' && !array_filter($updatedCart, static function ($item) {
    return (int)$item['qty'] > 0;
  })) {
    $error = "Pilih minimal satu menu terlebih dahulu.";
  }

  if ($error !== '') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $error]);
    exit;
  }

  $_SESSION['cart'] = $updatedCart;
  $cart = $updatedCart;
  $total = 0;
  foreach ($cart as $item) {
    $total += $item['price'] * $item['qty'];
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $method = strtolower($_POST['payment'] ?? '');
  $validMethods = ['cash', 'qris'];
  $buktiPath = $_SESSION['bukti_qris'] ?? null;

  if (!in_array($method, $validMethods, true)) {
    $error = "Silakan pilih metode pembayaran yang tersedia.";
  } elseif ($method === 'qris') {
    if (!isset($_FILES['bukti']) || $_FILES['bukti']['error'] !== UPLOAD_ERR_OK) {
      $error = "Silakan upload bukti pembayaran QRIS.";
    } else {
      $file = $_FILES['bukti'];
      $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
      $allowed = ['jpg', 'jpeg', 'png'];
      $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);

      if (!in_array($ext, $allowed, true) || !in_array($mime, ['image/jpeg', 'image/png'], true)) {
        $error = "Format file harus JPG atau PNG.";
      } elseif ($file['size'] > 2 * 1024 * 1024) {
        $error = "Ukuran file maksimal 2MB.";
      } else {
        $savedToDisk = false;
        if (!getenv('VERCEL') && (is_dir($uploadDir) || @mkdir($uploadDir, 0775, true))) {
          $fname = "qris_" . bin2hex(random_bytes(8)) . "." . $ext;
          $target = $uploadDir . $fname;
          if (@move_uploaded_file($file['tmp_name'], $target)) {
            $buktiPath = "uploads/" . $fname;
            $savedToDisk = true;
          }
        }
        if (!$savedToDisk) {
          $rawBytes = @file_get_contents($file['tmp_name']);
          if ($rawBytes !== false) {
            @$mysqli->query("ALTER TABLE orders MODIFY COLUMN bukti_qris LONGTEXT NULL");
            $buktiPath = "data:" . $mime . ";base64," . base64_encode($rawBytes);
          } else {
            $error = "Gagal upload bukti pembayaran.";
          }
        }
      }
    }
  }

  if ($error === '') {
    foreach (($_POST['notes'] ?? []) as $key => $note) {
      if (isset($_SESSION['cart'][$key])) {
        $_SESSION['cart'][$key]['note'] = trim((string)$note);
      }
    }
    foreach ($_SESSION['cart'] as $key => $item) {
      if ((int)$item['qty'] <= 0) {
        unset($_SESSION['cart'][$key]);
      }
    }
    $_SESSION['payment_method'] = $method;
    if ($method === 'qris') {
      $_SESSION['bukti_qris'] = $buktiPath;
    } else {
      unset($_SESSION['bukti_qris']);
    }

    if ($isAjax) {
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['success' => true, 'redirect' => 'order_submit.php']);
      exit;
    }
    header("Location: order_submit.php");
    exit;
  }

  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $error]);
    exit;
  }
}

if (!function_exists('rupiah')) {
  function rupiah($n) {
    return "Rp " . number_format($n, 0, ',', '.');
  }
}
?>

<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Checkout - Belakang Studio</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
:root {
  --primary-blue: #1E4DB7;
  --soft-blue: #f0f4ff;
  --light-blue: #e0e9ff;
  --text-dark: #2d3436;
  --text-muted: #636e72;
  --white: #ffffff;
  --bg-body: #f8fafd;
  --danger: #ff7675;
}

* { box-sizing: border-box; font-family: 'Poppins', sans-serif; }

body {
  margin: 0;
  background-color: var(--bg-body);
  color: var(--text-dark);
  min-height: 100vh;
}

.container {
  max-width: 480px;
  margin: 30px auto;
  padding: 0 15px;
}

.card {
  background: var(--white);
  border-radius: 24px;
  padding: 25px;
  box-shadow: 0 10px 40px rgba(0,0,0,0.04);
  border: 1px solid var(--light-blue);
}

.header { text-align: center; margin-bottom: 25px; }
.header img { display: block; width: 180px; max-width: 70%; height: auto; margin: 0 auto 14px; }
.header h2 { margin: 0; font-size: 20px; color: var(--primary-blue); font-weight: 700; }
.header p { margin: 5px 0 0; font-size: 13px; color: var(--text-muted); }

/* Error Message */
.error {
  background: #fff5f5;
  color: var(--danger);
  padding: 10px;
  border-radius: 10px;
  font-size: 12px;
  text-align: center;
  margin-bottom: 15px;
  border: 1px solid #ffe5e5;
}

/* Order List */
.order-list { margin: 0; padding: 0; list-style: none; }
.order-item {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  padding: 12px 0;
  border-bottom: 1px solid var(--soft-blue);
}
.item-info { flex: 1; }
.item-name { font-size: 14px; font-weight: 600; color: var(--text-dark); }
.item-variant { font-size: 11px; color: var(--primary-blue); background: var(--soft-blue); padding: 1px 6px; border-radius: 10px; }
.item-note { font-size: 11px; color: var(--text-muted); font-style: italic; margin-top: 4px; }
.item-price { text-align: right; font-size: 14px; font-weight: 600; color: var(--text-dark); }
.item-qty { font-size: 11px; color: var(--text-muted); }

/* Total Section */
.summary-total {
  margin-top: 15px;
  padding: 15px;
  background: var(--soft-blue);
  border-radius: 15px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.total-label { font-weight: 600; color: var(--text-muted); font-size: 14px; }
.total-val { font-size: 18px; font-weight: 700; color: var(--primary-blue); }

/* Payment Section */
.section-title { font-size: 13px; font-weight: 600; margin: 20px 0 10px; color: var(--text-dark); }
select {
  width: 100%;
  padding: 12px;
  border-radius: 12px;
  border: 1px solid var(--light-blue);
  background-color: var(--white);
  font-size: 14px;
  color: var(--text-dark);
  cursor: pointer;
}

/* QRIS Box */
#qris-box {
  display: none;
  margin-top: 15px;
  padding: 20px;
  border-radius: 15px;
  border: 2px dashed var(--light-blue);
  background: #fff;
  text-align: center;
}
#qris-box p { font-size: 12px; color: var(--text-muted); margin-bottom: 10px; }
#qris-box img.qr-code { display: block; width: 100%; max-width: 260px; max-height: 420px; object-fit: contain; margin: 0 auto 15px; border: 1px solid #eee; border-radius: 10px; padding: 6px; background: #fff; }
input[type="file"] { font-size: 12px; color: var(--text-muted); width: 100%; }

/* Buttons */
.btn {
  display: block;
  width: 100%;
  padding: 15px;
  border-radius: 12px;
  font-size: 14px;
  font-weight: 600;
  text-align: center;
  border: none;
  cursor: pointer;
  transition: 0.3s;
  text-decoration: none;
  margin-top: 10px;
}
.btn-finish { background: var(--primary-blue); color: #fff; margin-top: 25px; }
.btn-back { background: var(--soft-blue); color: var(--primary-blue); }
.btn-reset { background: transparent; color: var(--danger); font-size: 12px; }

.footer { text-align: center; font-size: 11px; color: #ccc; margin-top: 20px; letter-spacing: 2px; }

@media (max-width: 400px) {
  .card { padding: 15px; }
}
</style>
</head>

<body>

<div class="container">
  <div class="card">
    
    <div class="header">
      <img src="images/BsBiru2-cropped.png" alt="Logo Belakang Studio">
      <h2>Ringkasan Pesanan</h2>
      <p><?= htmlspecialchars($customer['name']) ?> • Meja <?= htmlspecialchars($customer['table']) ?></p>
    </div>

    <?php if ($error): ?>
      <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
      
      <ul class="order-list">
        <?php foreach ($cart as $it): ?>
        <li class="order-item">
          <div class="item-info">
            <div class="item-name"><?= htmlspecialchars($it['name']) ?></div>
            <?php if (!empty($it['variant'])): ?>
              <span class="item-variant"><?= ucfirst($it['variant']) ?></span>
            <?php endif; ?>
            <?php if (!empty($it['note'])): ?>
              <div class="item-note">"<?= htmlspecialchars($it['note']) ?>"</div>
            <?php endif; ?>
          </div>
          <div class="item-price">
            <div class="item-qty">x<?= $it['qty'] ?></div>
            <?= rupiah($it['price'] * $it['qty']) ?>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>

      <div class="summary-total">
        <span class="total-label">Total Bayar</span>
        <span class="total-val"><?= rupiah($total) ?></span>
      </div>

      <div class="section-title">Metode Pembayaran</div>
      <select name="payment" id="payment" required>
        <option value="">-- Pilih Pembayaran --</option>
        <option value="cash">Tunai (Bayar di Kasir)</option>
        <option value="qris">QRIS (Bayar Sekarang)</option>
      </select>

      <div id="qris-box">
        <p>Silakan scan kode QRIS di bawah ini:</p>
        <img class="qr-code" src="images/1.png?v=20261014" alt="QRIS Belakang Studio">
        <p style="font-weight: 600; color: var(--text-dark);">Upload Bukti Pembayaran:</p>
        <input type="file" name="bukti" id="bukti" accept="image/*">
        <div id="preview"></div>
      </div>

      <button type="submit" class="btn btn-finish">Selesaikan Pesanan</button>
    </form>

    <a href="menu.php" class="btn btn-back">Kembali ke Menu</a>
    <a href="cart.php" class="btn btn-reset" onclick="return confirm('Batalkan pesanan ini?')">Batalkan / Reset Pesanan</a>

    <div class="footer">BELAKANG STUDIO</div>

  </div>
</div>

<script>
const paymentSelect = document.getElementById('payment');
const qrisBox = document.getElementById('qris-box');
const buktiInput = document.getElementById('bukti');
const previewDiv = document.getElementById('preview');

paymentSelect.addEventListener('change', () => {
  qrisBox.style.display = paymentSelect.value === 'qris' ? 'block' : 'none';
});

buktiInput?.addEventListener('change', function() {
  const file = this.files[0];
  if (!file) return;

  const reader = new FileReader();
  reader.onload = e => {
    previewDiv.innerHTML = `<img src="${e.target.result}" style="margin-top:15px; width:100%; max-width:150px; border-radius:12px; border: 2px solid var(--soft-blue);">`;
  };
  reader.readAsDataURL(file);
});
</script>

</body>
</html>