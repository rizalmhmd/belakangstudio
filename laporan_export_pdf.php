<?php
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit;
}

if (!function_exists('rupiah')) {
    function rupiah($amount) {
        return "Rp " . number_format((float)$amount, 0, ',', '.');
    }
}

$filter = $_GET['filter'] ?? 'all';
if (!is_string($filter) || !in_array($filter, ['all', 'day', 'month', 'year', 'cash', 'qris'], true)) {
    $filter = 'all';
}

$where = "WHERE o.status = 'selesai'";
if ($filter === 'day') {
    $where .= " AND DATE(o.created_at) = CURDATE()";
} elseif ($filter === 'month') {
    $where .= " AND MONTH(o.created_at) = MONTH(CURDATE()) AND YEAR(o.created_at) = YEAR(CURDATE())";
} elseif ($filter === 'year') {
    $where .= " AND YEAR(o.created_at) = YEAR(CURDATE())";
} elseif ($filter === 'cash') {
    $where .= " AND LOWER(TRIM(COALESCE(o.payment_method, 'cash'))) IN ('cash', 'tunai')";
} elseif ($filter === 'qris') {
    $where .= " AND LOWER(TRIM(COALESCE(o.payment_method, 'cash'))) IN ('qris', 'qr')";
}

$sql = "
    SELECT
        o.id,
        o.customer_name,
        o.total,
        o.created_at,
        o.payment_method,
        GROUP_CONCAT(DISTINCT m.category SEPARATOR ', ') AS categories,
        GROUP_CONCAT(
            CONCAT(
                oi.qty,
                'x ',
                oi.menu_name,
                IF(TRIM(COALESCE(oi.variant, '')) <> '', CONCAT(' (', TRIM(oi.variant), ')'), '')
            ) SEPARATOR '; '
        ) AS menu_list
    FROM orders o
    LEFT JOIN order_items oi ON o.id = oi.order_id
    LEFT JOIN menus m ON oi.menu_id = m.id
    $where
    GROUP BY o.id, o.customer_name, o.total, o.created_at, o.payment_method
    ORDER BY o.created_at DESC, o.id DESC
";

$result = $mysqli->query($sql);
if (!$result) {
    error_log('Gagal mengekspor rekap transaksi PDF: ' . $mysqli->error);
    http_response_code(500);
    exit('Gagal mengekspor rekap transaksi PDF.');
}

$rows = $result->fetch_all(MYSQLI_ASSOC);
$filterLabels = [
    'all' => 'Semua',
    'day' => 'Hari Ini',
    'month' => 'Bulan Ini',
    'year' => 'Tahun Ini',
    'cash' => 'Cash',
    'qris' => 'QRIS'
];
$escape = static function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};
$cashierName = trim((string)($_SESSION['admin_username'] ?? 'Kasir'));
if ($cashierName === '') {
    $cashierName = 'Kasir';
}
$indonesianMonths = [
    1 => 'Januari',
    2 => 'Februari',
    3 => 'Maret',
    4 => 'April',
    5 => 'Mei',
    6 => 'Juni',
    7 => 'Juli',
    8 => 'Agustus',
    9 => 'September',
    10 => 'Oktober',
    11 => 'November',
    12 => 'Desember'
];
$signatureDate = date('j') . ' ' . $indonesianMonths[(int)date('n')] . ' ' . date('Y');

$totalOmzet = 0.0;
$html = '
<style>
body { font-family: sans-serif; color: #1f2a37; font-size: 9pt; }
h1 { color: #1a4c9d; font-size: 18pt; margin-bottom: 4px; }
.subtitle { color: #5e6a7e; margin-bottom: 14px; }
table { width: 100%; border-collapse: collapse; }
th { background: #eef3ff; color: #1f2a37; font-weight: bold; }
th, td { border: 1px solid #dfe4ea; padding: 7px; vertical-align: top; }
.total { text-align: right; font-weight: bold; }
</style>
<h1>Rekap Transaksi</h1>
<div class="subtitle">Filter: ' . $escape($filterLabels[$filter]) . ' | Dicetak: ' . date('d/m/Y H:i') . '</div>
<table>
<thead><tr>
<th>ID</th><th>Pelanggan</th><th>Kategori</th><th>Menu Pesanan</th>
<th>Total Bayar</th><th>Metode</th><th>Waktu</th>
</tr></thead><tbody>';

if ($rows) {
    foreach ($rows as $row) {
        $totalOmzet += (float)$row['total'];
        $paymentMethod = strtoupper(trim((string)($row['payment_method'] ?? 'cash')));
        $html .= '<tr>'
            . '<td>#' . (int)$row['id'] . '</td>'
            . '<td>' . $escape($row['customer_name'] ?? 'Tanpa Nama') . '</td>'
            . '<td>' . $escape($row['categories'] ?: '-') . '</td>'
            . '<td>' . $escape($row['menu_list'] ?: '-') . '</td>'
            . '<td>' . $escape(rupiah($row['total'])) . '</td>'
            . '<td>' . $escape($paymentMethod) . '</td>'
            . '<td>' . $escape($row['created_at']) . '</td>'
            . '</tr>';
    }
    $html .= '<tr><td colspan="4" class="total">Total Omzet</td><td class="total">'
        . $escape(rupiah($totalOmzet)) . '</td><td colspan="2"></td></tr>';
    $html .= '<tr><td colspan="4" class="total">Jumlah Transaksi</td><td class="total">'
        . count($rows) . '</td><td colspan="2"></td></tr>';
} else {
    $html .= '<tr><td colspan="7" style="text-align:center">Tidak ada data untuk filter ini.</td></tr>';
}

$html .= '</tbody></table>';

$filterFileLabels = [
    'all' => 'semua',
    'day' => 'harian',
    'month' => 'bulanan',
    'year' => 'tahunan',
    'cash' => 'cash',
    'qris' => 'qris'
];
$filename = 'rekap_transaksi_' . $filterFileLabels[$filter] . '_' . date('Y-m-d_H-i-s') . '.pdf';

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape(pathinfo($filename, PATHINFO_FILENAME)) ?></title>
    <style>
        @page { size: A4 landscape; margin: 12mm; }
        body { font-family: Arial, sans-serif; color: #1f2a37; font-size: 10pt; }
        h1 { color: #1a4c9d; font-size: 18pt; margin: 0 0 4px; }
        .subtitle { color: #5e6a7e; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #eef3ff; color: #1f2a37; font-weight: bold; }
        th, td { border: 1px solid #dfe4ea; padding: 7px; vertical-align: top; }
        .total { text-align: right; font-weight: bold; }
        .signature { width: 55mm; margin: 22mm 0 0 auto; text-align: center; page-break-inside: avoid; }
        .signature-space { height: 22mm; }
        .signature-name { display: inline-block; min-width: 45mm; padding-top: 2mm; border-top: 1px solid #1f2a37; font-weight: bold; }
        .print-actions { margin-bottom: 16px; }
        .print-actions button { padding: 9px 14px; border: 0; border-radius: 6px; background: #1a4c9d; color: #fff; cursor: pointer; }
        @media print {
            .print-actions { display: none; }
        }
    </style>
</head>
<body>
    <div class="print-actions">
        <button type="button" onclick="window.print()">Simpan sebagai PDF</button>
        <span>Di jendela cetak, pilih tujuan “Save as PDF” / “Simpan sebagai PDF”.</span>
    </div>
    <?= $html ?>
    <div class="signature">
        <div>Tanggal: <?= $escape($signatureDate) ?></div>
        <div>Kasir,</div>
        <div class="signature-space"></div>
        <div class="signature-name"><?= $escape($cashierName) ?></div>
    </div>
    <script>
      window.addEventListener('load', () => window.print());
    </script>
</body>
</html>
