<?php
// laporan.php
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Pastikan admin login
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit;
}

if (!function_exists('rupiah')) {
    function rupiah($angka) {
        return "Rp " . number_format($angka, 0, ',', '.');
    }
}

// Filter logic
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'day', 'month', 'year', 'cash', 'qris'], true)) {
    $filter = 'all';
}

$where = "WHERE o.status = 'selesai'";

if ($filter === 'day') {
    $where = "WHERE o.status = 'selesai' AND DATE(o.created_at) = CURDATE()";
} elseif ($filter === 'month') {
    $where = "WHERE o.status = 'selesai' AND MONTH(o.created_at) = MONTH(CURDATE()) AND YEAR(o.created_at) = YEAR(CURDATE())";
} elseif ($filter === 'year') {
    $where = "WHERE o.status = 'selesai' AND YEAR(o.created_at) = YEAR(CURDATE())";
} elseif ($filter === 'cash') {
    $where = "WHERE o.status = 'selesai' AND LOWER(TRIM(COALESCE(o.payment_method, 'cash'))) IN ('cash', 'tunai')";
} elseif ($filter === 'qris') {
    $where = "WHERE o.status = 'selesai' AND LOWER(TRIM(COALESCE(o.payment_method, 'cash'))) IN ('qris', 'qr')";
}

$sql = "
SELECT 
    o.id AS order_id, o.customer_name, o.total, o.created_at, o.payment_method, o.bukti_qris,
    oi.menu_name, oi.variant, oi.qty, m.category
FROM orders o
LEFT JOIN order_items oi ON o.id = oi.order_id
LEFT JOIN menus m ON oi.menu_id = m.id
$where
ORDER BY o.created_at DESC, o.id DESC
";

$result = $mysqli->query($sql);
if (!$result) {
    error_log('Gagal memuat laporan penjualan: ' . $mysqli->error);
    http_response_code(500);
    exit('Gagal memuat laporan penjualan.');
}
$rows = $result->fetch_all(MYSQLI_ASSOC);

$orders = [];
foreach ($rows as $r) {
    $id = (int)$r['order_id'];
    if (!isset($orders[$id])) {
        $orders[$id] = [
            'id' => $id,
            'customer_name' => $r['customer_name'] ?? 'Tanpa Nama',
            'total' => $r['total'] ?? 0,
            'created_at' => $r['created_at'],
            'payment_method' => $r['payment_method'],
            'bukti_qris' => $r['bukti_qris'],
            'items' => [],
            'categories' => []
        ];
    }
    if (!empty($r['menu_name'])) {
        $orders[$id]['items'][] = ['menu_name' => $r['menu_name'], 'variant' => $r['variant'], 'qty' => $r['qty']];
    }
    if (!empty($r['category']) && !in_array($r['category'], $orders[$id]['categories'])) {
        $orders[$id]['categories'][] = $r['category'];
    }
}

$totalCash = 0;
$totalQris = 0;
foreach ($orders as $o) {
    $method = strtolower(trim((string)($o['payment_method'] ?? 'cash')));
    $amount = (float)($o['total'] ?? 0);

    if (in_array($method, ['cash', 'tunai'], true)) {
        $totalCash += $amount;
    } elseif (in_array($method, ['qris', 'qr'], true)) {
        $totalQris += $amount;
    }
}

$totalOmzet = $totalCash + $totalQris;

$displayOrders = $orders;
if ($filter === 'cash') {
    $displayOrders = array_values(array_filter($orders, fn($o) => in_array(strtolower(trim((string)($o['payment_method'] ?? 'cash'))), ['cash', 'tunai'], true)));
} elseif ($filter === 'qris') {
    $displayOrders = array_values(array_filter($orders, fn($o) => in_array(strtolower(trim((string)($o['payment_method'] ?? 'cash'))), ['qris', 'qr'], true)));
}

$filterLabels = [
    'all' => 'Semua transaksi selesai',
    'day' => 'Transaksi selesai hari ini',
    'month' => 'Transaksi selesai bulan ini',
    'year' => 'Transaksi selesai tahun ini',
    'cash' => 'Transaksi selesai dengan Cash',
    'qris' => 'Transaksi selesai dengan QRIS'
];
?>

<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Transaksi - Belakang Studio</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
            --success: #1ca76d;
            --grey-panel: #f2f2f2;
            --grey-line: #dfe4ea;
            --table-head: #f1f2f7;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background: #ffffff; color: var(--text-dark); display: flex; }

        .sidebar { width: 220px; height: 100vh; min-height: 100vh; background: var(--sidebar-dark); padding: 18px 14px 14px; position: fixed; color: #fff; display: flex; flex-direction: column; }
        .sidebar-brand { font-size: 20px; font-weight: 700; margin-bottom: 22px; display: flex; align-items: center; gap: 10px; padding: 8px 12px; letter-spacing: 0.05em; }
        .brand-logo { width: 72px; height: 50px; flex: 0 0 72px; object-fit: contain; object-position: center; }
        .sidebar nav { display: flex; flex: 1; flex-direction: column; gap: 6px; }
        .sidebar nav a { display: flex; align-items: center; gap: 10px; min-height: 42px; padding: 9px 12px; color: #dfe7f1; text-decoration: none; border-radius: 9px; font-size: 13px; transition: background 0.2s ease, color 0.2s ease; }
        .sidebar nav a:hover, .sidebar nav a.active { color: #fff; background: rgba(255,255,255,0.08); }
        .sidebar nav a.active { background: #1d4e9a; box-shadow: inset 0 0 0 1px rgba(255,255,255,0.06); }

        .main { flex: 1; margin-left: 220px; padding: 24px 28px 30px; }

        .panel-header {
            background: rgba(255,255,255,0.35);
            padding: 18px 22px 16px;
            border-radius: 10px 10px 0 0;
            margin-bottom: 12px;
        }
        .panel-header h2 { font-size: 22px; color: var(--primary-blue); font-weight: 700; margin: 0 0 6px; }
        .panel-header p { color: var(--text-muted); font-size: 14px; }

        .card {
            background: #f3f3f3;
            padding: 18px 18px 12px;
            border-radius: 12px;
            border: 1px solid #dfe3ea;
            box-shadow: none;
        }

        .filters { display: flex; gap: 10px; margin: 0 0 18px; flex-wrap: wrap; }
        .filters a {
            padding: 10px 18px;
            background: #f7f7f7;
            border: 1px solid #dfe3ea;
            border-radius: 8px;
            text-decoration: none;
            color: var(--text-dark);
            font-size: 13px;
            font-weight: 500;
            transition: 0.3s;
        }
        .filters a.active {
            background: var(--primary-blue);
            color: #fff;
            border-color: var(--primary-blue);
            box-shadow: 0 2px 8px rgba(26, 76, 157, 0.2);
        }
        .filters a:hover:not(.active) { background: #eef3ff; }

        .btn-export { display: inline-flex; align-items: center; gap: 8px; background: var(--success); color: #fff; padding: 12px 18px; border-radius: 10px; text-decoration: none; font-size: 14px; font-weight: 600; margin: 0 0 18px; transition: 0.3s; box-shadow: 0 3px 10px rgba(28, 167, 109, 0.2); }
        .btn-export:hover { opacity: 0.96; transform: translateY(-1px); }
        .btn-export-pdf { background: #c0392b; margin-left: 8px; box-shadow: 0 3px 10px rgba(192, 57, 43, 0.2); }

        .table-container { width: 100%; overflow-x: auto; margin-top: 0; border-radius: 12px; border: 1px solid #dfe3ea; background: #f5f5f5; }
        table { width: 100%; border-collapse: collapse; min-width: 900px; }
        table thead th {
            background: var(--table-head);
            color: #3d4a5f;
            padding: 14px 12px;
            font-size: 12px;
            text-align: left;
            font-weight: 700;
            letter-spacing: 0.04em;
            border-bottom: 1px solid #e1e5eb;
        }
        table tbody td {
            padding: 14px 12px;
            border-bottom: 1px solid #e6e9ee;
            font-size: 13px;
            vertical-align: middle;
            background: rgba(255,255,255,0.25);
        }

        .badge { padding: 6px 10px; border-radius: 7px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .badge-qris { background: #dff7ee; color: #0f8a5f; }
        .badge-cash { background: #edf0f3; color: #4d5868; }

        .img-preview { width: 45px; height: 45px; border-radius: 8px; object-fit: cover; cursor: pointer; transition: 0.2s; border: 1px solid var(--light-blue); }
        .img-preview:hover { transform: scale(1.1); }

        .omzet-box {
            margin-top: 22px;
            padding: 12px 8px 0;
            background: transparent;
            border-radius: 0;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
        }
        .omzet-item {
            background: #f5f5f5;
            padding: 14px 16px;
            border-radius: 8px;
            border: 1px solid #dfe3ea;
        }
        .omzet-label { color: #48576a; font-weight: 600; font-size: 12px; text-transform: uppercase; }
        .omzet-val { font-size: 20px; font-weight: 700; color: var(--primary-blue); margin-top: 8px; }
        .report-context { margin: 0 0 12px; color: var(--text-muted); font-size: 13px; }

        ul.item-list { list-style: none; }
        ul.item-list li { font-size: 12px; color: var(--text-dark); margin-bottom: 2px; }

        body { background: #f4f7fb; }
        .main { background: #f4f7fb; min-width: 0; }
        .panel-header, .card, .omzet-item {
            background: #fff;
            border: 1px solid #dfe4ea;
            border-radius: 12px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
        }
        .panel-header { margin-bottom: 18px; }
        .nav-icon { width: 22px; flex: 0 0 22px; text-align: center; }
        .sidebar nav a .nav-label { white-space: nowrap; }
        .sidebar nav a.logout-link { margin-top: auto; color: #ff8a8a; }
        .sidebar nav a.logout-link:hover { background: rgba(255,138,138,0.12); color: #ffb0b0; }

        @media(max-width: 1024px) {
            .sidebar { width: 80px; padding: 30px 10px; }
            .sidebar-brand { justify-content: center; padding: 8px 0; }
            .brand-logo { width: 46px; height: 46px; flex-basis: 46px; }
            .brand-label, .sidebar nav .nav-label { display: none; }
            .sidebar nav a { justify-content: center; padding: 12px 8px; }
            .main { margin-left: 80px; }
        }
        @media(max-width: 600px) {
            .sidebar { width: 62px; padding: 16px 6px; }
            .main { margin-left: 62px; padding: 14px; }
            .omzet-box { grid-template-columns: 1fr; padding-left: 0; padding-right: 0; }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-brand"><img class="brand-logo" src="images/BsBiru2-cropped.png" alt="Logo Belakang Studio"><span class="brand-label">KASIR</span></div>
        <nav>
            <a href="admin_dashboard.php"><span class="nav-icon">🏠</span><span class="nav-label">Dashboard</span></a>
            <a href="laporan.php" class="active"><span class="nav-icon">📊</span><span class="nav-label">Rekap Transaksi</span></a>
            <a href="menu_manage.php"><span class="nav-icon">📋</span><span class="nav-label">Kelola Menu</span></a>
            <a href="admin_kelola_stok.php"><span class="nav-icon">📦</span><span class="nav-label">Kelola Stok</span></a>
            <a href="logout.php" class="logout-link"><span class="nav-icon">🚪</span><span class="nav-label">Logout</span></a>
        </nav>
    </div>

    <div class="main">
        <div class="panel-header">
            <h2>Rekap Transaksi</h2>
            <p>Ringkasan transaksi yang sudah selesai, sesuai filter yang dipilih.</p>
        </div>

        <div class="filters">
            <a href="laporan.php?filter=all" class="<?= $filter == 'all' ? 'active' : '' ?>">Semua</a>
            <a href="laporan.php?filter=cash" class="<?= $filter == 'cash' ? 'active' : '' ?>">Cash</a>
            <a href="laporan.php?filter=qris" class="<?= $filter == 'qris' ? 'active' : '' ?>">QRIS</a>
            <a href="laporan.php?filter=day" class="<?= $filter == 'day' ? 'active' : '' ?>">Hari Ini</a>
            <a href="laporan.php?filter=month" class="<?= $filter == 'month' ? 'active' : '' ?>">Bulan Ini</a>
            <a href="laporan.php?filter=year" class="<?= $filter == 'year' ? 'active' : '' ?>">Tahun Ini</a>
        </div>

        <a href="laporan_export.php?filter=<?= $filter ?>" class="btn-export">
            <span>📥</span> Export Laporan (Excel)
        </a>
        <a href="laporan_export_pdf.php?filter=<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>" class="btn-export btn-export-pdf">
            <span>📄</span> Export PDF
        </a>

        <p class="report-context">
            <?= htmlspecialchars($filterLabels[$filter], ENT_QUOTES, 'UTF-8') ?>:
            <strong><?= count($displayOrders) ?></strong> transaksi
        </p>

        <div class="card">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>PELANGGAN</th>
                            <th>KATEGORI</th>
                            <th>MENU PESANAN</th>
                            <th>TOTAL BAYAR</th>
                            <th>METODE</th>
                            <th>BUKTI</th>
                            <th>WAKTU</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($displayOrders as $o): ?>
                        <?php
                            $paymentMethod = strtolower(trim((string)($o['payment_method'] ?? 'cash')));
                            $isQrisPayment = in_array($paymentMethod, ['qris', 'qr'], true);
                            $isCashPayment = in_array($paymentMethod, ['cash', 'tunai'], true);
                        ?>
                        <tr>
                            <td style="font-weight: 600; color: var(--primary-blue);">#<?= $o['id'] ?></td>
                            <td style="font-weight: 500;"><?= htmlspecialchars($o['customer_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="color: var(--text-muted); font-size: 11px;">
                                <?= htmlspecialchars(implode(", ", $o['categories'] ?: ['-']), ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td>
                                <ul class="item-list">
                                    <?php foreach ($o['items'] as $it): ?>
                                    <li>
                                        <strong><?= (int)$it['qty'] ?>x</strong>
                                        <?= htmlspecialchars($it['menu_name'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php if (trim((string)($it['variant'] ?? '')) !== ''): ?>
                                            <span style="color:var(--text-muted);">(<?= htmlspecialchars($it['variant'], ENT_QUOTES, 'UTF-8') ?>)</span>
                                        <?php endif; ?>
                                    </li>
                                    <?php endforeach; ?>
                                    <?php if(empty($o['items'])) echo "<li>-</li>"; ?>
                                </ul>
                            </td>
                            <td style="font-weight: 600;"><?= rupiah($o['total']) ?>                            </td>
                            <td>
                                <span class="badge <?= $isQrisPayment ? 'badge-qris' : 'badge-cash' ?>">
                                    <?= $isQrisPayment ? 'QRIS' : ($isCashPayment ? 'CASH' : '-') ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($isQrisPayment && !empty($o['bukti_qris'])): ?>
                                    <a href="<?= htmlspecialchars($o['bukti_qris'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">
                                        <img src="<?= htmlspecialchars($o['bukti_qris'], ENT_QUOTES, 'UTF-8') ?>" class="img-preview" alt="Bukti QRIS">
                                    </a>
                                <?php elseif ($isCashPayment): ?>
                                    <span style="display:inline-block;background:#f1f5f9;color:#475569;padding:6px 10px;border-radius:8px;font-size:11px;font-weight:600;">
                                        Bayar di kasir
                                    </span>
                                <?php elseif ($isQrisPayment): ?>
                                    <span style="color:#94a3b8;font-size:11px;">Belum ada bukti</span>
                                <?php else: ?>
                                    <span style="color: #cbd5e1;">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="white-space: nowrap; font-size: 12px; color: var(--text-muted);">
                                <?= date('d M Y', strtotime($o['created_at'])) ?><br>
                                <small><?= date('H:i', strtotime($o['created_at'])) ?> WIB</small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($displayOrders)): ?>
                            <tr>
                                <td colspan="8" style="text-align:center; color:var(--text-muted); padding: 20px;">Belum ada data untuk filter ini.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="omzet-box">
                <div class="omzet-item">
                    <div class="omzet-label">Jumlah Transaksi</div>
                    <div class="omzet-val"><?= number_format(count($displayOrders), 0, ',', '.') ?></div>
                </div>
                <div class="omzet-item">
                    <div class="omzet-label">Total Bayar Cash</div>
                    <div class="omzet-val"><?= rupiah($totalCash) ?></div>
                </div>
                <div class="omzet-item">
                    <div class="omzet-label">Total Bayar QRIS</div>
                    <div class="omzet-val"><?= rupiah($totalQris) ?></div>
                </div>
                <div class="omzet-item">
                    <div class="omzet-label">Total Akumulasi Omzet</div>
                    <div class="omzet-val"><?= rupiah($totalOmzet) ?></div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>