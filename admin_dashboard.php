<?php
// admin_dashboard.php
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit;
}

function getOrderStatusData(mysqli $mysqli): ?array {
    $counts = ['baru' => 0, 'diproses' => 0, 'selesai' => 0];
    $statuses = [];
    $result = $mysqli->query("SELECT id, status FROM orders WHERE status IN ('baru','diproses','selesai')");
    if (!$result) {
        error_log('Gagal memeriksa jumlah pesanan: ' . $mysqli->error);
        return null;
    }

    while ($order = $result->fetch_assoc()) {
        $statuses[(string) $order['id']] = $order['status'];
        $counts[$order['status']]++;
    }

    return ['counts' => $counts, 'statuses' => $statuses];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'], $_POST['order_id'], $_POST['status'])) {
    $orderId = (int) $_POST['order_id'];
    $newStatus = is_string($_POST['status']) && in_array($_POST['status'], ['baru', 'diproses', 'selesai'], true)
        ? $_POST['status']
        : null;

    if ($orderId > 0 && $newStatus !== null) {
        $stmt = $mysqli->prepare("UPDATE orders SET status=? WHERE id=?");
        if ($stmt) {
            $stmt->bind_param("si", $newStatus, $orderId);
            $updated = $stmt->execute();
            $error = $stmt->error;
            $stmt->close();
        } else {
            $updated = false;
            $error = $mysqli->error;
        }
    } else {
        $updated = false;
        $error = 'ID pesanan atau status tidak valid.';
    }

    if (($_POST['ajax'] ?? '') === '1') {
        header('Content-Type: application/json; charset=utf-8');
        if (!$updated) {
            error_log('Gagal memperbarui status pesanan: ' . $error);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Status pesanan gagal diperbarui.']);
        } else {
            echo json_encode(['success' => true, 'status' => $newStatus]);
        }
        exit;
    }

    header('Location: admin_dashboard.php');
    exit;
}

if (($_GET['order_notifications'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $orderStatusData = getOrderStatusData($mysqli);
    if ($orderStatusData === null) {
        http_response_code(500);
        echo json_encode(['error' => 'Gagal memeriksa jumlah pesanan.']);
        exit;
    }

    echo json_encode($orderStatusData);
    exit;
}

$orderStatusData = getOrderStatusData($mysqli);
if ($orderStatusData === null) {
    http_response_code(500);
    exit('Gagal memeriksa jumlah pesanan.');
}
$orderCounts = $orderStatusData['counts'];

// ---------- Data ----------
$year = date('Y');

// summary
$totals = ['today' => 0, 'month' => 0, 'year'  => 0];
$res = $mysqli->query("SELECT COALESCE(SUM(total),0) AS s FROM orders WHERE DATE(created_at)=CURDATE() AND status='selesai'");
$totals['today'] = (float)$res->fetch_assoc()['s'];

$res = $mysqli->query("SELECT COALESCE(SUM(total),0) AS s FROM orders WHERE YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE()) AND status='selesai'");
$totals['month'] = (float)$res->fetch_assoc()['s'];

$res = $mysqli->query("SELECT COALESCE(SUM(total),0) AS s FROM orders WHERE YEAR(created_at)=YEAR(CURDATE()) AND status='selesai'");
$totals['year'] = (float)$res->fetch_assoc()['s'];

// Kategori Terlaris
$sql = "SELECT COALESCE(m.category,'Lainnya') AS category, SUM(oi.qty) AS total_qty
        FROM orders o
        INNER JOIN order_items oi ON oi.order_id = o.id
        LEFT JOIN menus m ON oi.menu_id = m.id
        WHERE o.status = 'selesai'
        GROUP BY category
        ORDER BY total_qty DESC";
$res = $mysqli->query($sql);
$cat_labels = $cat_values = [];
while ($res && $r=$res->fetch_assoc()) {
  $cat_labels[]=$r['category'];
  $cat_values[]=(int)$r['total_qty'];
}

// Grafik Bulanan
$sql = "SELECT MONTH(created_at) AS m, COALESCE(SUM(total),0) AS sum_total
        FROM orders
        WHERE YEAR(created_at)=$year AND status='selesai'
        GROUP BY MONTH(created_at)";
$res = $mysqli->query($sql);
$monthly = array_fill(1,12,0);
while ($res && $r=$res->fetch_assoc()) {
  $monthly[(int)$r['m']] = (float)$r['sum_total'];
}
$month_labels = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
$month_values = [];
for ($i=1;$i<=12;$i++) $month_values[]=$monthly[$i];

// Top 5 Menu
$sql="SELECT oi.menu_name, SUM(oi.qty) AS total_qty FROM orders o INNER JOIN order_items oi ON oi.order_id = o.id WHERE o.status='selesai' GROUP BY oi.menu_name ORDER BY total_qty DESC LIMIT 5";
$res=$mysqli->query($sql);
$top_menus = $res ? $res->fetch_all(MYSQLI_ASSOC):[];

$cat_labels_js=json_encode($cat_labels);
$cat_values_js=json_encode($cat_values);
$month_labels_js=json_encode($month_labels);
$month_values_js=json_encode($month_values);
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kasir Dashboard - Belakang Studio</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root {
  --primary-blue: #1a4c9d;
  --soft-blue: #eef3ff;
  --light-blue: #dfe8ff;
  --text-dark: #1f2a37;
  --text-muted: #5e6a7e;
  --white: #ffffff;
  --bg-body: #dfe7e3;
  --sidebar-dark: #0d1e2d;
  --sidebar-dark-2: #11273d;
  --panel-soft: #f4f4f4;
  --panel-line: #dfe4ea;
}

* { box-sizing: border-box; font-family: 'Poppins', sans-serif; }

body {
  margin: 0;
  background-color: #ffffff;
  color: var(--text-dark);
  display: flex;
}

.sidebar {
  width: 220px;
  background: var(--sidebar-dark);
  height: 100vh;
  min-height: 100vh;
  padding: 18px 14px 14px;
  position: fixed;
  color: #fff;
  transition: 0.3s;
  display: flex;
  flex-direction: column;
}

.sidebar-brand {
  font-size: 20px;
  font-weight: 700;
  color: #fff;
  margin-bottom: 22px;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 12px;
  letter-spacing: 0.05em;
}

.brand-logo {
  width: 72px;
  height: 50px;
  flex: 0 0 72px;
  object-fit: contain;
  object-position: center;
}

.sidebar nav {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 6px;
}

.sidebar nav a {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 42px;
  padding: 9px 12px;
  color: #dfe7f1;
  text-decoration: none;
  border-radius: 9px;
  font-size: 13px;
  transition: background 0.2s ease, color 0.2s ease;
}

.sidebar nav a:hover,
.sidebar nav a.active {
  background: rgba(255, 255, 255, 0.08);
  color: #fff;
}

.sidebar nav a.active {
  background: var(--primary-blue);
  box-shadow: inset 0 0 0 1px rgba(255,255,255,0.06);
}

.main-content {
  flex: 1;
  margin-left: 220px;
  padding: 24px 28px 30px;
  background: #ffffff;
}

.header-panel {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  background: rgba(255,255,255,0.3);
  border-radius: 10px 10px 0 0;
  padding: 18px 22px 16px;
}

.header-panel h1 { font-size: 22px; font-weight: 700; margin: 0; color: var(--primary-blue); }
.header-panel p { color: var(--text-muted); font-size: 13px; margin: 0; }

.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 18px;
  margin-bottom: 24px;
}

.stat-card {
  background: var(--panel-soft);
  padding: 18px 18px 14px;
  border-radius: 10px;
  border: 1px solid var(--panel-line);
  box-shadow: none;
}

.stat-card h3 { font-size: 12px; text-transform: uppercase; color: #4b5870; margin: 0 0 10px; letter-spacing: 0.05em; }
.stat-card .val { font-size: 24px; font-weight: 700; color: var(--primary-blue); }

.content-grid {
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: 20px;
}

.chart-card {
  background: var(--panel-soft);
  padding: 18px 18px 14px;
  border-radius: 10px;
  border: 1px solid var(--panel-line);
  box-shadow: none;
}

.chart-card h4 { margin: 0 0 18px; font-size: 16px; color: var(--text-dark); border-left: 4px solid var(--primary-blue); padding-left: 10px; }

.top-menu-list { list-style: none; padding: 0; margin: 0; }
.top-menu-item {
  display: flex;
  justify-content: space-between;
  padding: 12px 0;
  border-bottom: 1px solid #e5e7eb;
}
.top-menu-name { font-size: 14px; font-weight: 500; }
.top-menu-qty { font-size: 12px; background: var(--soft-blue); color: var(--primary-blue); padding: 2px 10px; border-radius: 20px; font-weight: 600; }

body { background: #f4f7fb; }
.main-content { background: #f4f7fb; }
.header-panel, .stat-card, .chart-card {
  background: #fff;
  border: 1px solid #dfe4ea;
  border-radius: 12px;
  box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
}
.header-panel { margin-bottom: 18px; }
.sidebar nav a { display: flex; align-items: center; gap: 10px; }
.nav-icon { width: 22px; flex: 0 0 22px; text-align: center; }
.sidebar nav a .nav-label { white-space: nowrap; }
.sidebar nav a.logout-link { margin-top: auto; color: #ff8a8a; }
.sidebar nav a.logout-link:hover { background: rgba(255, 138, 138, 0.12); color: #ffb0b0; }
.nav-order-count {
  display: none;
  min-width: 22px;
  margin-left: auto;
  padding: 2px 7px;
  border-radius: 999px;
  background: #ef4444;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  text-align: center;
}
.order-notification-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  padding: 10px 22px 14px;
  background: #fff;
  border: 1px solid #dfe4ea;
  border-top: 0;
  border-radius: 0 0 12px 12px;
  margin-bottom: 18px;
}
.order-notification-count { color: #475569; font-size: 13px; }
.order-notification-count strong { color: #dc2626; }
.order-notification-count { display: inline-flex; gap: 4px; align-items: center; }
.order-toast-container {
  position: fixed;
  top: 18px;
  right: 18px;
  z-index: 1000;
  display: grid;
  gap: 10px;
  width: min(360px, calc(100vw - 36px));
}
.order-toast {
  padding: 12px 16px;
  border-radius: 10px;
  background: #fff;
  border-left: 5px solid #2563eb;
  box-shadow: 0 8px 24px rgba(15, 23, 42, 0.18);
  color: #1f2a37;
  font-size: 13px;
  animation: toast-in 0.2s ease-out;
}
.order-toast[data-status="baru"] {
  border-left-color: #2563eb;
  background: #eff6ff;
}
.order-toast[data-status="diproses"] {
  border-left-color: #d97706;
  background: #fffbeb;
}
.order-toast[data-status="selesai"] {
  border-left-color: #16a34a;
  background: #f0fdf4;
}
.order-toast[data-status="error"] {
  border-left-color: #dc2626;
  background: #fef2f2;
}
@keyframes toast-in {
  from { opacity: 0; transform: translateY(-8px); }
  to { opacity: 1; transform: translateY(0); }
}

@media(max-width: 1024px) {
  .sidebar { width: 80px; padding: 30px 10px; }
  .sidebar-brand { justify-content: center; padding: 8px 0; }
  .brand-logo { width: 46px; height: 46px; flex-basis: 46px; }
  .brand-label, .sidebar nav .nav-label { display: none; }
  .sidebar nav a { justify-content: center; padding: 9px 8px; }
  .main-content { margin-left: 80px; }
  .content-grid { grid-template-columns: 1fr; }
}

@media(max-width: 600px) {
  .sidebar { width: 62px; padding: 16px 6px; }
  .main-content { margin-left: 62px; padding: 14px; }
}
</style>
</head>
<body>
<div class="order-toast-container" id="orderToastContainer" aria-live="polite" aria-atomic="false"></div>

<div class="sidebar">
  <div class="sidebar-brand"><img class="brand-logo" src="images/BsBiru2-cropped.png" alt="Logo Belakang Studio"><span class="brand-label">KASIR</span></div>

  <nav>
    <a href="admin_dashboard.php" class="active"><span class="nav-icon">🏠</span><span class="nav-label">Dashboard</span><span class="nav-order-count" id="newOrderNavBadge"></span></a>
    <a href="laporan.php"><span class="nav-icon">📊</span><span class="nav-label">Rekap Transaksi</span></a>
    <a href="menu_manage.php"><span class="nav-icon">📋</span><span class="nav-label">Kelola Menu</span></a>
    <a href="admin_kelola_stok.php"><span class="nav-icon">📦</span><span class="nav-label">Kelola Stok</span></a>
    <a href="logout.php" class="logout-link"><span class="nav-icon">🚪</span><span class="nav-label">Logout</span></a>
  </nav>
</div>

<div class="main-content">
  <div style="max-width:980px;margin:0 auto;">
    <div style="background:#fff;padding:18px 22px 16px;border-radius:10px 10px 0 0;margin-bottom:0;border:1px solid #dfe4ea;border-bottom:none;">
      <h1 style="margin:0;color:#1a4c9d;font-size:22px;">📋 Daftar Pesanan Masuk</h1>
      <p style="margin:5px 0 0;color:#5e6a7e;font-size:14px;">Semua pesanan terbaru Belakang Studio</p>
    </div>
    <div class="order-notification-bar">
      <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;" aria-live="polite">
        <span class="order-notification-count">Baru: <strong id="orderCountBaru"><?= $orderCounts['baru'] ?></strong></span>
        <span class="order-notification-count">Diproses: <strong id="orderCountDiproses"><?= $orderCounts['diproses'] ?></strong></span>
        <span class="order-notification-count">Selesai: <strong id="orderCountSelesai"><?= $orderCounts['selesai'] ?></strong></span>
      </div>
    </div>

    <?php
    $sqlOrders = "SELECT * FROM orders WHERE status IN ('baru','diproses','selesai') ORDER BY id DESC";
    $ordersResult = $mysqli->query($sqlOrders);
    $orders = $ordersResult ? $ordersResult->fetch_all(MYSQLI_ASSOC) : [];
    ?>

    <?php if (empty($orders)): ?>
      <div style="padding:20px;background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
        <p style="margin:0;color:#777;font-size:14px;">Belum ada pesanan.</p>
      </div>
    <?php else: ?>
      <?php foreach ($orders as $order): ?>
        <?php
        $paymentMethod = strtolower($order['payment_method'] ?? 'cash');
        $status = $order['status'] ?? 'baru';
        $statusColors = ['baru' => '#2563eb', 'diproses' => '#d97706', 'selesai' => '#16a34a'];
        $statusColor = $statusColors[$status] ?? '#2563eb';

        $stmtItems = $mysqli->prepare("SELECT * FROM order_items WHERE order_id=?");
        $items = [];
        if ($stmtItems) {
          $stmtItems->bind_param("i", $order['id']);
          $stmtItems->execute();
          $items = $stmtItems->get_result()->fetch_all(MYSQLI_ASSOC);
          $stmtItems->close();
        }
        ?>

        <div style="background:#fff;padding:18px 22px 12px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.08);margin-bottom:20px;border:1px solid #e8ebef;">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;padding-bottom:10px;border-bottom:1px solid #e5e7eb;flex-wrap:wrap;">
            <h2 style="margin:0;font-size:17px;color:#2F3640;font-weight:700;">
              <?= htmlspecialchars($order['id']) ?> - <?= htmlspecialchars($order['customer_name']) ?>
            </h2>
            <span style="font-size:12px;color:#6b7280;white-space:nowrap;">
              Waktu: <?= htmlspecialchars($order['created_at'] ?? '-') ?>
            </span>
          </div>

          <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:12px 0 8px;">
            <div style="display:flex;flex-wrap:wrap;gap:18px;align-items:center;font-size:14px;color:#444;">
              <span>Meja: <strong><?= htmlspecialchars($order['table_number']) ?></strong></span>
              <span>Total: <strong><?= rupiah($order['total']) ?></strong></span>
              <span>Metode:
                <?php if ($paymentMethod === 'qris'): ?>
                  <span style="display:inline-block;padding:4px 8px;border-radius:6px;font-size:11px;font-weight:700;line-height:1.4;text-transform:uppercase;letter-spacing:0.04em;background:#16a34a;color:#fff;">QRIS</span>
                <?php else: ?>
                  <span style="display:inline-block;padding:4px 8px;border-radius:6px;font-size:11px;font-weight:700;line-height:1.4;text-transform:uppercase;letter-spacing:0.04em;background:#374151;color:#fff;">Cash</span>
                <?php endif; ?>
              </span>
            </div>

            <form method="post" action="admin_dashboard.php" class="order-status-form" style="margin:0;">
              <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
              <input type="hidden" name="update_status" value="1">
              <select name="status" style="padding:6px 12px;border-radius:6px;border:none;font-size:13px;font-weight:600;color:#fff;cursor:pointer;min-width:125px;background:<?= $statusColor ?>;">
                <option value="baru" <?= $status === 'baru' ? 'selected' : '' ?> style="background:#2563eb;color:#fff;">Baru</option>
                <option value="diproses" <?= $status === 'diproses' ? 'selected' : '' ?> style="background:#d97706;color:#fff;">Diproses</option>
                <option value="selesai" <?= $status === 'selesai' ? 'selected' : '' ?> style="background:#16a34a;color:#fff;">Selesai</option>
              </select>
            </form>
          </div>

          <?php if ($paymentMethod === 'qris' && !empty($order['bukti_qris'])): ?>
            <div style="margin-top:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:13px;color:#4b5563;">
              <span>Bukti QRIS:</span>
              <img src="<?= htmlspecialchars($order['bukti_qris']) ?>" alt="Bukti QRIS" style="max-width:220px;max-height:280px;object-fit:contain;border-radius:8px;border:1px solid #ddd;display:block;">
            </div>
          <?php elseif ($paymentMethod === 'cash'): ?>
            <div style="margin-top:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:13px;color:#4b5563;">
              <span>Bukti Pembayaran:</span>
              <span style="display:inline-block;padding:6px 10px;border-radius:6px;background:#f1f5f9;color:#475569;font-size:12px;font-weight:600;">Bayar tunai di kasir</span>
            </div>
          <?php elseif ($paymentMethod === 'qris'): ?>
            <div style="margin-top:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:13px;color:#4b5563;">
              <span>Bukti Pembayaran:</span>
              <span style="display:inline-block;padding:6px 10px;border-radius:6px;background:#f1f5f9;color:#475569;font-size:12px;font-weight:600;">Belum ada bukti QRIS</span>
            </div>
          <?php endif; ?>

          <div style="overflow-x:auto;margin-top:10px;">
            <table style="width:100%;border-collapse:collapse;min-width:650px;">
              <thead>
                <tr>
                  <th style="background:#f8f8f8;padding:10px 8px;border-bottom:2px solid #ddd;text-align:left;color:#2f3640;font-size:13px;font-weight:600;">Menu</th>
                  <th style="background:#f8f8f8;padding:10px 8px;border-bottom:2px solid #ddd;text-align:left;color:#2f3640;font-size:13px;font-weight:600;">Varian</th>
                  <th style="background:#f8f8f8;padding:10px 8px;border-bottom:2px solid #ddd;text-align:center;color:#2f3640;font-size:13px;font-weight:600;">Qty</th>
                  <th style="background:#f8f8f8;padding:10px 8px;border-bottom:2px solid #ddd;text-align:right;color:#2f3640;font-size:13px;font-weight:600;">Harga</th>
                  <th style="background:#f8f8f8;padding:10px 8px;border-bottom:2px solid #ddd;text-align:right;color:#2f3640;font-size:13px;font-weight:600;">Subtotal</th>
                  <th style="background:#f8f8f8;padding:10px 8px;border-bottom:2px solid #ddd;text-align:left;color:#2f3640;font-size:13px;font-weight:600;">Catatan</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($items as $it): ?>
                  <tr>
                    <td style="padding:9px 8px;border-bottom:1px solid #eee;font-size:13px;vertical-align:top;"><?= htmlspecialchars($it['menu_name']) ?></td>
                    <td style="padding:9px 8px;border-bottom:1px solid #eee;font-size:13px;vertical-align:top;"><?= htmlspecialchars($it['variant']) ?></td>
                    <td style="padding:9px 8px;border-bottom:1px solid #eee;font-size:13px;vertical-align:top;text-align:center;"><?= (int) $it['qty'] ?></td>
                    <td style="padding:9px 8px;border-bottom:1px solid #eee;font-size:13px;vertical-align:top;text-align:right;"><?= rupiah($it['price']) ?></td>
                    <td style="padding:9px 8px;border-bottom:1px solid #eee;font-size:13px;vertical-align:top;text-align:right;color:#2F3640;font-weight:500;"><?= rupiah($it['subtotal']) ?></td>
                    <td style="padding:9px 8px;border-bottom:1px solid #eee;font-size:13px;vertical-align:top;"><?= htmlspecialchars($it['note']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<script>
(() => {
  const countElements = {
    baru: document.getElementById('orderCountBaru'),
    diproses: document.getElementById('orderCountDiproses'),
    selesai: document.getElementById('orderCountSelesai')
  };
  const navBadge = document.getElementById('newOrderNavBadge');
  const toastContainer = document.getElementById('orderToastContainer');
  const statusColors = { baru: '#2563eb', diproses: '#d97706', selesai: '#16a34a' };
  const notificationSounds = {
    baru: { notes: [523, 659, 784, 1047], type: 'sine', duration: 0.3, volume: 0.8 },
    diproses: { notes: [440, 554, 659], type: 'triangle', duration: 0.22, volume: 0.55 },
    selesai: { notes: [784, 1046, 1319], type: 'triangle', duration: 0.22, volume: 0.65 }
  };
  let orderCounts = <?= json_encode($orderCounts) ?>;
  let orderStatuses = <?= json_encode($orderStatusData['statuses']) ?>;
  const latestOrderIdOnLoad = Object.keys(orderStatuses).reduce((latest, orderId) => {
    const numericId = Number(orderId);
    return Number.isSafeInteger(numericId) ? Math.max(latest, numericId) : latest;
  }, 0);
  const lastSeenOrderStorageKey = 'belakangstudio-admin-last-order-id';
  let lastSeenOrderId = latestOrderIdOnLoad;
  try {
    const storedLastSeenOrderValue = window.localStorage.getItem(lastSeenOrderStorageKey);
    const storedLastSeenOrderId = Number(storedLastSeenOrderValue);
    if (storedLastSeenOrderValue !== null && Number.isSafeInteger(storedLastSeenOrderId) && storedLastSeenOrderId >= 0) {
      lastSeenOrderId = storedLastSeenOrderId;
    } else {
      window.localStorage.setItem(lastSeenOrderStorageKey, String(latestOrderIdOnLoad));
    }
  } catch (error) {
    console.error('Gagal membaca penanda pesanan terakhir:', error);
  }
  let soundEnabled = false;
  let audioContext = null;
  let soundActivation = null;
  const pendingNotificationSounds = [];
  let nextNotificationSoundTime = 0;
  let checkingOrders = false;

  function updateCounts(counts) {
    Object.keys(countElements).forEach((status) => {
      countElements[status].textContent = counts[status];
    });
    navBadge.textContent = counts.baru;
    navBadge.style.display = counts.baru > 0 ? 'inline-block' : 'none';
  }

  function drainPendingNotificationSounds() {
    if (!soundEnabled || !audioContext || audioContext.state !== 'running') {
      return;
    }

    while (pendingNotificationSounds.length > 0) {
      const status = pendingNotificationSounds.shift();
      const sound = notificationSounds[status];
      const startTime = Math.max(audioContext.currentTime, nextNotificationSoundTime);
      const noteSpacing = sound.duration * 0.67;

      try {
        sound.notes.forEach((frequency, index) => {
          const noteStart = startTime + index * noteSpacing;
          const oscillator = audioContext.createOscillator();
          const gain = audioContext.createGain();
          oscillator.type = sound.type;
          oscillator.frequency.value = frequency;
          gain.gain.setValueAtTime(0.0001, noteStart);
          gain.gain.exponentialRampToValueAtTime(sound.volume, noteStart + 0.025);
          gain.gain.exponentialRampToValueAtTime(0.0001, noteStart + sound.duration);
          oscillator.connect(gain);
          gain.connect(audioContext.destination);
          oscillator.start(noteStart);
          oscillator.stop(noteStart + sound.duration + 0.03);
        });
        nextNotificationSoundTime = startTime + (sound.notes.length - 1) * noteSpacing + sound.duration + 0.15;
      } catch (error) {
        console.error('Gagal memutar suara notifikasi:', error);
      }
    }
  }

  function queueNotificationSound(status) {
    pendingNotificationSounds.push(status);
    drainPendingNotificationSounds();
  }

  function showOrderNotification(orderId, status, playSound = false) {
    const notificationDetails = {
      baru: { title: 'Pesanan baru', message: `Pesanan #${orderId} baru masuk.` },
      diproses: { title: 'Pesanan diproses', message: `Pesanan #${orderId} sedang diproses.` },
      selesai: { title: 'Pesanan selesai', message: `Pesanan #${orderId} telah selesai.` }
    };
    const { title, message } = notificationDetails[status];
    const toast = document.createElement('div');
    toast.className = 'order-toast';
    toast.dataset.status = status;
    toast.textContent = message;
    toastContainer.appendChild(toast);
    window.setTimeout(() => toast.remove(), 15000);
    if ('Notification' in window && Notification.permission === 'granted') {
      try {
        new Notification(title, { body: message, tag: `order-${orderId}-${status}` });
      } catch (error) {
        console.error('Gagal menampilkan notifikasi browser:', error);
      }
    }
    if (playSound) {
      queueNotificationSound(status);
    }
  }

  function showNotificationError(message) {
    const toast = document.createElement('div');
    toast.className = 'order-toast';
    toast.dataset.status = 'error';
    toast.textContent = message;
    toastContainer.appendChild(toast);
    window.setTimeout(() => toast.remove(), 7000);
  }

  function enableSound() {
    if (soundEnabled && audioContext && audioContext.state === 'running') {
      drainPendingNotificationSounds();
      return Promise.resolve(true);
    }
    if (soundActivation) return soundActivation;

    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextClass) {
      console.error('Browser ini tidak mendukung suara notifikasi.');
      return Promise.resolve(false);
    }

    try {
      audioContext = audioContext || new AudioContextClass();
      soundActivation = audioContext.resume().then(() => {
        if (audioContext.state !== 'running') {
          throw new Error('AudioContext tidak berjalan setelah diaktifkan.');
        }

        soundEnabled = true;
        drainPendingNotificationSounds();
        return true;
      }).catch((error) => {
        console.error('Gagal mengaktifkan suara notifikasi:', error);
        soundEnabled = false;
        return false;
      }).finally(() => {
        soundActivation = null;
      });
      return soundActivation;
    } catch (error) {
      console.error('Gagal mengaktifkan suara notifikasi:', error);
      soundEnabled = false;
      soundActivation = null;
      return Promise.resolve(false);
    }
  }

  function enableSoundAfterInteraction() {
    if (!soundEnabled || !audioContext || audioContext.state !== 'running') {
      enableSound();
    }
  }

  window.addEventListener('pointerdown', enableSoundAfterInteraction);
  window.addEventListener('keydown', enableSoundAfterInteraction);

  document.querySelectorAll('.order-status-form').forEach((form) => {
    const statusSelect = form.querySelector('select[name="status"]');
    statusSelect.addEventListener('change', async () => {
      const previousStatus = statusSelect.dataset.previousStatus || statusSelect.value;
      const selectedStatus = statusSelect.value;
      const soundReady = ['diproses', 'selesai'].includes(selectedStatus)
        ? enableSound()
        : Promise.resolve(false);
      const formData = new FormData(form);
      formData.set('ajax', '1');
      statusSelect.disabled = true;

      try {
        const response = await fetch(form.action, {
          method: 'POST',
          body: formData,
          headers: { 'Accept': 'application/json' }
        });
        const result = await response.json();
        if (!response.ok || !result.success || result.status !== statusSelect.value) {
          throw new Error(result.message || `HTTP ${response.status}`);
        }

        const orderId = form.querySelector('input[name="order_id"]').value;
        const newStatus = result.status;
        statusSelect.dataset.previousStatus = newStatus;
        statusSelect.style.backgroundColor = statusColors[newStatus];
        orderStatuses[orderId] = newStatus;
        if (newStatus !== previousStatus) {
          const playStatusSound = ['diproses', 'selesai'].includes(newStatus) && await soundReady;
          showOrderNotification(orderId, newStatus, playStatusSound);
        }
        checkForNewOrders();
      } catch (error) {
        console.error('Gagal memperbarui status pesanan:', error);
        showNotificationError(error.message || 'Status pesanan gagal diperbarui.');
        statusSelect.value = previousStatus;
      } finally {
        statusSelect.disabled = false;
      }
    });
    statusSelect.dataset.previousStatus = statusSelect.value;
  });

  async function checkForNewOrders() {
    if (checkingOrders) return;
    checkingOrders = true;

    try {
      const response = await fetch('admin_dashboard.php?order_notifications=1', {
        cache: 'no-store',
        headers: { 'Accept': 'application/json' }
      });
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      const stats = await response.json();
      if (!stats.counts || !stats.statuses || !Object.keys(countElements).every((status) => Number.isInteger(stats.counts[status]))) {
        throw new Error('Invalid notification response');
      }

      let latestOrderId = lastSeenOrderId;
      Object.entries(stats.statuses).forEach(([orderId, status]) => {
        if (!Object.prototype.hasOwnProperty.call(orderCounts, status)) {
          throw new Error('Invalid order status in notification response');
        }
        const numericOrderId = Number(orderId);
        if (Number.isSafeInteger(numericOrderId)) {
          latestOrderId = Math.max(latestOrderId, numericOrderId);
        }

        const previousStatus = orderStatuses[orderId];
        if (Number.isSafeInteger(numericOrderId) && numericOrderId > lastSeenOrderId) {
          showOrderNotification(orderId, 'baru', true);
        } else if (status === 'baru' && previousStatus !== 'baru') {
          showOrderNotification(orderId, 'baru');
        } else if (status === 'diproses' && previousStatus !== 'diproses') {
          showOrderNotification(orderId, 'diproses', true);
        } else if (status === 'selesai' && previousStatus !== 'selesai') {
          showOrderNotification(orderId, 'selesai', true);
        }
      });

      updateCounts(stats.counts);
      orderCounts = stats.counts;
      orderStatuses = stats.statuses;
      if (latestOrderId > lastSeenOrderId) {
        lastSeenOrderId = latestOrderId;
        try {
          window.localStorage.setItem(lastSeenOrderStorageKey, String(lastSeenOrderId));
        } catch (error) {
          console.error('Gagal menyimpan penanda pesanan terakhir:', error);
        }
      }
    } catch (error) {
      console.error('Gagal memperbarui notifikasi pesanan:', error);
    } finally {
      checkingOrders = false;
    }
  }

  updateCounts(orderCounts);
  checkForNewOrders();
  window.setInterval(checkForNewOrders, 3000);
})();
</script>

</body>
</html>