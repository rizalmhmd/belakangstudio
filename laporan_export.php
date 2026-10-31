<?php
// laporan_export.php
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// pastikan admin login
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit;
}

// helper rupiah
if (!function_exists('rupiah')) {
    function rupiah($n) {
        return "Rp " . number_format($n, 0, ',', '.');
    }
}

// Samakan filter dengan halaman laporan agar unduhan berisi data yang sedang dilihat.
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'day', 'month', 'year', 'cash', 'qris'], true)) {
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

$res = $mysqli->query($sql);
if (!$res) {
    error_log('Gagal mengekspor laporan penjualan: ' . $mysqli->error);
    http_response_code(500);
    exit('Gagal mengekspor laporan penjualan.');
}
$rows = $res->fetch_all(MYSQLI_ASSOC);

$filterLabels = [
    'all' => 'semua',
    'day' => 'harian',
    'month' => 'bulanan',
    'year' => 'tahunan',
    'cash' => 'cash',
    'qris' => 'qris'
];
$filename = 'rekap_transaksi_' . $filterLabels[$filter] . '_' . date('Y-m-d_H-i-s') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF";
echo "<meta charset='UTF-8'>";
echo "<table border='1'>";
echo "<thead>
<tr style='background:#ddd;'>
    <th>ID</th>
    <th>Nama Pemesan</th>
    <th>Kategori</th>
    <th>Menu Dipesan</th>
    <th>Total</th>
    <th>Pembayaran</th>
    <th>Tanggal</th>
</tr>
</thead><tbody>";

if ($rows) {
    $totalOmzet = 0.0;
    foreach ($rows as $row) {
        $totalOmzet += (float)$row['total'];
        $paymentMethod = strtoupper(trim((string)($row['payment_method'] ?? 'cash')));
        echo "<tr>";
        echo "<td>" . (int)$row['id'] . "</td>";
        echo "<td>" . htmlspecialchars($row['customer_name'] ?? 'Tanpa Nama', ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars($row['categories'] ?: '-', ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars($row['menu_list'] ?: '-', ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . rupiah((float)$row['total']) . "</td>";
        echo "<td>" . htmlspecialchars($paymentMethod, ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars($row['created_at'], ENT_QUOTES, 'UTF-8') . "</td>";
        echo "</tr>";
    }

    echo "<tr style='font-weight:bold;background:#f3f3f3;'>";
    echo "<td colspan='4' align='right'>Total Omzet (Periode)</td>";
    echo "<td>".rupiah($totalOmzet)."</td>";
    echo "<td colspan='2'></td>";
    echo "</tr>";
    echo "<tr style='font-weight:bold;'>";
    echo "<td colspan='4' align='right'>Jumlah Transaksi</td>";
    echo "<td>" . count($rows) . "</td>";
    echo "<td colspan='2'></td>";
    echo "</tr>";
} else {
    echo "<tr><td colspan='7' align='center'>Tidak ada data</td></tr>";
}
echo "</tbody></table>";
exit;
