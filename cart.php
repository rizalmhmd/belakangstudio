<?php
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['customer'])) {
  header("Location: index.php");
  exit;
}

$customer = $_SESSION['customer'] ?? [];

// fungsi rupiah
if (!function_exists('rupiah')) {
  function rupiah($angka) {
    return "Rp " . number_format($angka, 0, ',', '.');
  }
}

// Ambil stok semua menu dari database
function getMenuStocks($mysqli) {
  $stocks = [];
  $res = $mysqli->query("SELECT id, stock, name FROM menus");
  while ($row = $res->fetch_assoc()) {
    $stocks[$row['id']] = [
      'stock' => (int)$row['stock'],
      'name'  => $row['name'],
    ];
  }
  return $stocks;
}

$menuStocks = getMenuStocks($mysqli);

// Fungsi validasi stok gabungan
function validateCart(&$items, $menuStocks) {
  $grouped = [];
  foreach ($items as $key => &$it) {
    $mid = $it['menu_id'];
    $grouped[$mid][$key] = &$it;
  }

  foreach ($grouped as $mid => &$variants) {
    $max = $menuStocks[$mid]['stock'] ?? 0;
    $total = 0;
    foreach ($variants as &$v) {
      $total += $v['qty'];
    }

    if ($total > $max) {
      $factor = $max / $total;
      foreach ($variants as &$v) {
        $v['qty'] = floor($v['qty'] * $factor);
      }
    }
  }
}

// Tambah item baru dari menu.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['items']) && !isset($_POST['ajax_update']) && !isset($_POST['clear'])) {
  $menuNotes = $_POST['notes'] ?? [];

  foreach ($_POST['items'] as $key => $data) {
    $qty = intval($data['qty'] ?? 0);
    if ($qty > 0) {
      $menuId = intval($data['menu_id']);
      $note = trim($menuNotes[$menuId] ?? '');
      $_SESSION['cart'][$key] = [
        'menu_id' => $menuId,
        'name'    => $data['name'],
        'variant' => $data['variant'],
        'price'   => intval($data['price']),
        'qty'     => $qty,
        'note'    => $note,
      ];
    }
  }

  if (!empty($_SESSION['cart'])) {
    validateCart($_SESSION['cart'], $menuStocks);
  }

  header("Location: cart.php");
  exit;
}

// Update via AJAX (real-time)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_update'])) {
  foreach ($_POST['qty'] as $k => $v) {
    $v = intval($v);
    if ($v <= 0) {
      unset($_SESSION['cart'][$k]);
    } else {
      $_SESSION['cart'][$k]['qty'] = $v;
      if (isset($_POST['note'][$k])) {
        $_SESSION['cart'][$k]['note'] = trim($_POST['note'][$k]);
      }
    }
  }

  if (!empty($_SESSION['cart'])) {
    validateCart($_SESSION['cart'], $menuStocks);
  }

  $total = 0;
  foreach ($_SESSION['cart'] as $item) {
    $total += $item['price'] * $item['qty'];
  }

  echo json_encode([
    'success' => true,
    'total' => $total,
    'formattedTotal' => rupiah($total)
  ]);
  exit;
}

// Clear keranjang manual
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear'])) {
  $_SESSION['cart'] = [];
  $_SESSION['msg_success'] = "Keranjang dikosongkan.";
  header("Location: cart.php");
  exit;
}

$cart = $_SESSION['cart'] ?? [];
$total = 0;
foreach ($cart as $item) {
  $total += $item['price'] * $item['qty'];
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Keranjang - Belakang Studio</title>
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
      max-width: 800px;
      margin: 40px auto;
      padding: 0 20px;
    }

    .card {
      background: var(--white);
      border-radius: 20px;
      padding: 30px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.04);
      border: 1px solid var(--light-blue);
    }

    h2 {
      text-align: center;
      margin: 0;
      font-size: 24px;
      color: var(--primary-blue);
      font-weight: 700;
    }

    .customer-info {
      text-align: center;
      background: var(--soft-blue);
      padding: 10px;
      border-radius: 12px;
      margin: 15px 0 25px;
      font-size: 13px;
      color: var(--primary-blue);
      font-weight: 500;
    }

    /* Alert Style */
    .msg {
      padding: 12px;
      border-radius: 10px;
      font-size: 13px;
      margin-bottom: 20px;
      text-align: center;
    }
    .msg.error { background: #ffebeb; color: #d63031; }
    .msg.success { background: #e3faf3; color: #20bf6b; }

    /* Table Design */
    .table-responsive {
      overflow-x: auto;
      margin-bottom: 20px;
    }

    table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0 10px;
    }

    table th {
      text-align: left;
      padding: 12px;
      font-size: 12px;
      text-transform: uppercase;
      color: var(--text-muted);
      font-weight: 600;
      border-bottom: 1px solid var(--light-blue);
    }

    table td {
      padding: 15px 12px;
      background: var(--white);
      font-size: 14px;
      vertical-align: middle;
      border-bottom: 1px solid #f1f1f1;
    }

    .menu-name { font-weight: 600; color: var(--text-dark); }
    .menu-variant { font-size: 11px; color: var(--primary-blue); background: var(--soft-blue); padding: 2px 8px; border-radius: 20px; }

    /* Inputs in Table */
    input[type="number"] {
      width: 60px;
      padding: 8px;
      border: 1px solid var(--light-blue);
      border-radius: 8px;
      text-align: center;
      outline: none;
    }

    input[type="text"] {
      width: 100%;
      padding: 8px;
      border: 1px solid var(--light-blue);
      border-radius: 8px;
      font-size: 12px;
      outline: none;
    }

    /* Total Section */
    .total-box {
      background: var(--soft-blue);
      padding: 20px;
      border-radius: 15px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-top: 20px;
    }

    .total-label { font-weight: 600; color: var(--text-muted); }
    .total-amount { font-size: 20px; font-weight: 700; color: var(--primary-blue); }

    /* Buttons */
    .actions {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin-top: 25px;
    }

    .btn {
      display: inline-block;
      text-align: center;
      padding: 14px;
      border-radius: 12px;
      font-size: 14px;
      font-weight: 600;
      text-decoration: none;
      cursor: pointer;
      transition: 0.3s;
      border: none;
    }

    .btn-primary { background: var(--primary-blue); color: var(--white); grid-column: span 2; }
    .btn-outline { background: var(--white); color: var(--primary-blue); border: 1px solid var(--primary-blue); }
    .btn-danger { background: var(--white); color: var(--danger); border: 1px solid var(--danger); }

    .btn:hover { opacity: 0.8; transform: translateY(-2px); }

    .empty-state {
      text-align: center;
      padding: 40px 0;
    }

    .empty-state p { color: var(--text-muted); }

    @media(max-width: 600px) {
      .card { padding: 20px; }
      .actions { grid-template-columns: 1fr; }
      .btn-primary { grid-column: span 1; order: -1; }
      table th { display: none; }
      table td { display: block; text-align: right; padding: 10px 0; }
      table td::before { content: attr(data-label); float: left; font-weight: 600; color: var(--text-muted); }
    }
  </style>
</head>
<body>

  <div class="container">
    <div class="card">
      <h2>Keranjang Anda</h2>
      <div class="customer-info">
        <?= htmlspecialchars($customer['name'] ?? '-') ?> • Meja <?= htmlspecialchars($customer['table'] ?? '-') ?>
      </div>

      <?php if (!empty($_SESSION['msg_error'])): ?>
        <div class="msg error"><?= $_SESSION['msg_error']; unset($_SESSION['msg_error']); ?></div>
      <?php endif; ?>
      <?php if (!empty($_SESSION['msg_success'])): ?>
        <div class="msg success"><?= $_SESSION['msg_success']; unset($_SESSION['msg_success']); ?></div>
      <?php endif; ?>

      <?php if (empty($cart)): ?>
        <div class="empty-state">
          <p>Keranjang belanja Anda masih kosong.</p>
          <a href="menu.php" class="btn btn-primary" style="margin-top:10px;">Lihat Menu</a>
        </div>
      <?php else: ?>
        <form method="post" id="cart-form">
          <div class="table-responsive">
            <table>
              <thead>
                <tr>
                  <th>Menu</th>
                  <th>Harga</th>
                  <th>Jumlah</th>
                  <th>Catatan</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($cart as $k => $it): ?>
                  <tr>
                    <td data-label="Menu">
                      <div class="menu-name"><?= htmlspecialchars($it['name']) ?></div>
                      <span class="menu-variant"><?= htmlspecialchars(ucfirst($it['variant'])) ?></span>
                    </td>
                    <td data-label="Harga"><?= rupiah($it['price']) ?></td>
                    <td data-label="Jumlah">
                      <input type="number" name="qty[<?= $k ?>]" value="<?= intval($it['qty']) ?>" min="0">
                    </td>
                    <td data-label="Catatan">
                      <input type="text" name="note[<?= $k ?>]" value="<?= htmlspecialchars($it['note']) ?>" placeholder="Contoh: Kurangi gula">
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="total-box">
            <div class="total-label">Total Pembayaran</div>
            <div class="total-amount" id="cart-total"><?= rupiah($total) ?></div>
          </div>

          <div class="actions">
            <a href="checkout.php" class="btn btn-primary">Lanjutkan Checkout</a>
            <a href="menu.php" class="btn btn-outline">Tambah Menu</a>
            <button type="submit" name="clear" class="btn btn-danger" onclick="return confirm('Kosongkan keranjang?')">Kosongkan</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>

<script>
// Fungsi deteksi perubahan otomatis (AJAX)
document.querySelectorAll('input[name^="qty"], input[name^="note"]').forEach(el => {
  el.addEventListener('change', function() {
    let form = document.getElementById('cart-form');
    let formData = new FormData(form);
    formData.append('ajax_update', '1');

    fetch('cart.php', {
      method: 'POST',
      body: formData
    })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        document.getElementById('cart-total').textContent = data.formattedTotal;
        // Kita reload halaman untuk memastikan sinkronisasi stok dan tampilan varian jika qty jadi 0
        location.reload();
      }
    });
  });
});
</script>
</body>
</html>