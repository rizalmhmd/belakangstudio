<?php
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['owner_logged_in']) || $_SESSION['owner_logged_in'] !== true) {
    header('Location: owner_login.php');
    exit;
}

$filter = $_GET['filter'] ?? 'all';
$allowedFilters = ['all', 'day', 'month', 'year'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

$where = "WHERE o.status = 'selesai'";
if ($filter === 'day') {
    $where = "WHERE o.status = 'selesai' AND DATE(o.created_at) = CURDATE()";
} elseif ($filter === 'month') {
    $where = "WHERE o.status = 'selesai' AND MONTH(o.created_at) = MONTH(CURDATE()) AND YEAR(o.created_at) = YEAR(CURDATE())";
} elseif ($filter === 'year') {
    $where = "WHERE o.status = 'selesai' AND YEAR(o.created_at) = YEAR(CURDATE())";
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
            ) SEPARATOR ', '
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
    error_log('Gagal memuat laporan penjualan owner: ' . $mysqli->error);
    http_response_code(500);
    exit('Gagal memuat laporan penjualan.');
}
$rows = $result->fetch_all(MYSQLI_ASSOC);

$cashTotal = 0;
$qrisTotal = 0;
foreach ($rows as $row) {
    $method = strtolower(trim((string)($row['payment_method'] ?? 'cash')));
    $amount = (float)($row['total'] ?? 0);

    if (in_array($method, ['cash', 'tunai'], true)) {
        $cashTotal += $amount;
    } elseif (in_array($method, ['qris', 'qr'], true)) {
        $qrisTotal += $amount;
    }
}

$totalOmzet = $cashTotal + $qrisTotal;

$tabLabels = [
    'all' => 'Semua',
    'day' => 'Harian',
    'month' => 'Bulanan',
    'year' => 'Tahunan'
];
$filterDescriptions = [
    'all' => 'Semua transaksi yang sudah selesai',
    'day' => 'Transaksi selesai hari ini',
    'month' => 'Transaksi selesai bulan ini',
    'year' => 'Transaksi selesai tahun ini'
];

if (($_GET['export'] ?? '') === 'excel') {
    $filename = 'laporan_penjualan_' . $filter . '_' . date('Y-m-d_H-i-s') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    echo "<meta charset='UTF-8'><table border='1'><thead><tr>";
    foreach (['ID', 'Nama Pemesan', 'Kategori', 'Menu Dipesan', 'Total', 'Pembayaran', 'Tanggal'] as $heading) {
        echo '<th>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $method = strtolower(trim((string)($row['payment_method'] ?? 'cash')));
        $paymentLabel = strtoupper(in_array($method, ['qris', 'qr'], true) ? 'QRIS' : 'Cash');
        $cells = [
            (string)(int)$row['id'],
            (string)($row['customer_name'] ?? 'Tanpa Nama'),
            (string)($row['categories'] ?: '-'),
            (string)($row['menu_list'] ?: '-'),
            rupiah((float)($row['total'] ?? 0)),
            $paymentLabel,
            (string)$row['created_at']
        ];

        echo '<tr>';
        foreach ($cells as $index => $cell) {
            $safeCell = htmlspecialchars($cell, ENT_QUOTES, 'UTF-8');
            if ($index !== 0 && preg_match('/^[=+\-@]/', $cell)) {
                $safeCell = "'" . $safeCell;
            }
            echo '<td>' . $safeCell . '</td>';
        }
        echo '</tr>';
    }

    echo '<tr><td colspan="4"><strong>Total Omzet</strong></td><td><strong>'
        . htmlspecialchars(rupiah($totalOmzet), ENT_QUOTES, 'UTF-8')
        . '</strong></td><td colspan="2"></td></tr>';
    echo '<tr><td colspan="4"><strong>Jumlah Transaksi</strong></td><td><strong>'
        . count($rows)
        . '</strong></td><td colspan="2"></td></tr>';
    echo '</tbody></table>';
    exit;
}

if (($_GET['export'] ?? '') === 'pdf') {
    $escape = static function ($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    };
    $ownerName = trim((string)($_SESSION['owner_name'] ?? 'Owner'));
    if ($ownerName === '') {
        $ownerName = 'Owner';
    }
    $indonesianMonths = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    $signatureDate = date('j') . ' ' . $indonesianMonths[(int)date('n')] . ' ' . date('Y');
    $html = '<h1>Laporan Penjualan</h1><p>Filter: ' . $escape($filterDescriptions[$filter])
        . ' | Dicetak: ' . date('d/m/Y H:i') . '</p><table><thead><tr>'
        . '<th>ID</th><th>Nama Pemesan</th><th>Kategori</th><th>Menu Dipesan</th>'
        . '<th>Total</th><th>Pembayaran</th><th>Tanggal</th></tr></thead><tbody>';

    foreach ($rows as $row) {
        $method = strtolower(trim((string)($row['payment_method'] ?? 'cash')));
        $paymentLabel = strtoupper(in_array($method, ['qris', 'qr'], true) ? 'QRIS' : 'Cash');
        $html .= '<tr>'
            . '<td>#' . (int)$row['id'] . '</td>'
            . '<td>' . $escape($row['customer_name'] ?? 'Tanpa Nama') . '</td>'
            . '<td>' . $escape($row['categories'] ?: '-') . '</td>'
            . '<td>' . $escape($row['menu_list'] ?: '-') . '</td>'
            . '<td>' . $escape(rupiah((float)($row['total'] ?? 0))) . '</td>'
            . '<td>' . $escape($paymentLabel) . '</td>'
            . '<td>' . $escape($row['created_at']) . '</td></tr>';
    }

    if ($rows) {
        $html .= '<tr><td colspan="4" class="total">Total Omzet</td><td class="total">'
            . $escape(rupiah($totalOmzet)) . '</td><td colspan="2"></td></tr>'
            . '<tr><td colspan="4" class="total">Jumlah Transaksi</td><td class="total">'
            . count($rows) . '</td><td colspan="2"></td></tr>';
    } else {
        $html .= '<tr><td colspan="7" class="empty">Tidak ada data untuk periode ini.</td></tr>';
    }
    $html .= '</tbody></table>';

    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <title>Laporan Owner</title>
        <style>
            @page { size: A4 landscape; margin: 12mm; }
            body { font: 10pt Arial, sans-serif; color: #1f2a37; }
            h1 { color: #1d4e9a; font-size: 18pt; margin: 0 0 4px; }
            p { color: #64748b; margin: 0 0 14px; }
            table { width: 100%; border-collapse: collapse; }
            th { background: #eef3ff; font-weight: bold; }
            th, td { border: 1px solid #dfe4ea; padding: 7px; text-align: left; vertical-align: top; }
            .total { text-align: right; font-weight: bold; }
            .empty { text-align: center; }
            .signature { width: 55mm; margin: 22mm 0 0 auto; text-align: center; page-break-inside: avoid; }
            .signature-space { height: 22mm; }
            .signature-name { display: inline-block; min-width: 45mm; padding-top: 2mm; border-top: 1px solid #1f2a37; font-weight: bold; }
            .print-actions { margin-bottom: 16px; }
            .print-actions button { padding: 9px 14px; border: 0; border-radius: 6px; background: #1d4e9a; color: white; }
            @media print { .print-actions { display: none; } }
        </style>
    </head>
    <body>
        <div class="print-actions">
            <button type="button" onclick="window.print()">Simpan sebagai PDF</button>
            <span>Pilih tujuan “Simpan sebagai PDF” pada dialog cetak.</span>
        </div>
        <?= $html ?>
        <div class="signature">
            <div>Tanggal: <?= $escape($signatureDate) ?></div>
            <div>Owner,</div>
            <div class="signature-space"></div>
            <div class="signature-name"><?= $escape($ownerName) ?></div>
        </div>
        <script>window.addEventListener('load', () => window.print());</script>
    </body>
    </html>
    <?php
    exit;
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Owner</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #0d1f2d;
            --navy-soft: #172d3f;
            --blue: #1d4e9a;
            --bg: #dfe7e3;
            --card: #f3f3f3;
            --line: #dfe4ea;
            --text: #1f2a37;
            --muted: #64748b;
            --white: #fff;
            --gray: #d9dde3;
            --dark-gray: #6f7d8c;
        }

        * { box-sizing: border-box; font-family: 'Poppins', sans-serif; }

        body {
            margin: 0;
            background: #ffffff;
            color: var(--text);
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 220px;
            background: var(--navy);
            height: 100vh;
            min-height: 100vh;
            padding: 18px 14px;
            color: var(--white);
            position: fixed;
            display: flex;
            flex-direction: column;
        }

        .brand {
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 0.05em;
            margin: 8px 8px 22px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .brand-logo {
            width: 72px;
            height: 50px;
            flex: 0 0 72px;
            object-fit: contain;
            object-position: center;
        }

        .nav {
            display: flex;
            flex: 1;
            flex-direction: column;
            gap: 6px;
        }

        .nav a {
            color: rgba(255,255,255,0.82);
            text-decoration: none;
            padding: 9px 12px;
            min-height: 42px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .nav a:hover {
            background: rgba(255,255,255,0.08);
            color: #fff;
        }

        .nav a.active {
            background: var(--blue);
            color: #fff;
            box-shadow: inset 0 0 0 1px rgba(255,255,255,0.06);
        }

        .nav-icon {
            width: 22px;
            flex: 0 0 22px;
            text-align: center;
        }

        .nav-label {
            white-space: nowrap;
        }

        .nav a.logout {
            margin-top: auto;
            color: #ff8a8a;
        }

        .nav a.logout:hover {
            background: rgba(255,138,138,0.12);
            color: #ffb0b0;
        }

        .content {
            flex: 1;
            min-width: 0;
            margin-left: 220px;
            padding: 26px 32px 30px;
            background: #f4f7fb;
        }

        .report-shell {
            max-width: 980px;
            margin: 0 auto;
            background: #ffffff;
            border: 1px solid var(--line);
            border-radius: 10px 10px 0 0;
            overflow: hidden;
        }

        .report-header {
            padding: 18px 20px 12px;
            background: #ffffff;
            border-bottom: 1px solid var(--line);
        }

        .title-wrap h1 {
            margin: 0;
            font-size: 24px;
            color: var(--blue);
            font-weight: 700;
        }

        .title-wrap p {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 14px;
        }

        .tabs {
            display: flex;
            gap: 8px;
            padding: 16px 20px 10px;
            background: #ffffff;
            border-bottom: 1px solid var(--line);
            flex-wrap: wrap;
        }

        .tab {
            display: inline-block;
            text-decoration: none;
            color: var(--text);
            background: rgba(255,255,255,0.28);
            padding: 8px 16px;
            border-radius: 6px;
            border: 1px solid var(--line);
            font-size: 13px;
            font-weight: 500;
        }

        .tab.active {
            background: #4d5d72;
            color: #fff;
            border-color: #4d5d72;
        }

        .tab.export {
            background: #1e9f6f;
            border-color: #1e9f6f;
            color: #fff;
            font-weight: 600;
        }
        .tab.export-pdf { background: #c0392b; border-color: #c0392b; color: #fff; }
        .report-count { padding: 14px 20px 0; color: var(--muted); font-size: 13px; }

        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 14px;
            padding: 18px 20px 0;
            background: rgba(255,255,255,0.08);
        }

        .summary-card {
            background: #ffffff;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 14px 16px;
        }

        .summary-card .label {
            font-size: 12px;
            color: var(--muted);
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .summary-card .value {
            margin-top: 8px;
            font-size: 20px;
            font-weight: 700;
            color: var(--blue);
        }

        .table-wrap {
            background: #ffffff;
            padding: 12px 0 6px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px 10px;
            border-bottom: 1px solid #e7ebf0;
            font-size: 13px;
            text-align: left;
            color: var(--text);
        }

        thead th {
            background: rgba(255,255,255,0.20);
            color: #4d596a;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        tbody tr:nth-child(odd) {
            background: rgba(255,255,255,0.06);
        }

        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            line-height: 1.2;
            text-transform: uppercase;
            color: #fff;
        }

        .badge.cash { background: #64748b; }
        .badge.qris { background: #1e9f6f; }

        .empty {
            text-align: center;
            padding: 18px;
            color: var(--muted);
        }

        @media (max-width: 860px) {
            .summary {
                grid-template-columns: 1fr;
            }
            .sidebar {
                width: 80px;
                padding: 20px 10px;
            }
            .brand {
                justify-content: center;
                margin-left: 0;
                margin-right: 0;
            }
            .brand-logo {
                width: 46px;
                height: 46px;
                flex-basis: 46px;
            }
            .brand-label,
            .nav-label {
                display: none;
            }
            .nav a {
                justify-content: center;
                padding: 9px 8px;
            }
            .content {
                margin-left: 80px;
                padding: 20px;
            }
        }

        @media (max-width: 600px) {
            .sidebar {
                width: 62px;
                padding: 16px 6px;
            }
            .content {
                margin-left: 62px;
                padding: 14px;
            }
        }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="brand"><img class="brand-logo" src="images/BsBiru2-cropped.png" alt="Logo Belakang Studio"><span class="brand-label">OWNER</span></div>
        <nav class="nav">
            <a href="owner_dashboard.php"><span class="nav-icon">🏠</span><span class="nav-label">Dashboard</span></a>
            <a href="owner_laporan.php" class="active"><span class="nav-icon">📊</span><span class="nav-label">Laporan</span></a>
            <a href="owner_logout.php" class="logout"><span class="nav-icon">🚪</span><span class="nav-label">Logout</span></a>
        </nav>
    </aside>

    <main class="content">
        <div class="report-shell">
            <div class="report-header">
                <div class="title-wrap">
                    <h1>Laporan Penjualan</h1>
                    <p><?= htmlspecialchars($filterDescriptions[$filter], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>

            <div class="tabs">
                <?php foreach ($tabLabels as $key => $label): ?>
                    <a href="?filter=<?= $key ?>" class="tab <?= $filter === $key ? 'active' : '' ?>"><?= $label ?></a>
                <?php endforeach; ?>
                <a href="?filter=<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>&amp;export=excel" class="tab export">Export Excel</a>
                <a href="?filter=<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>&amp;export=pdf" class="tab export export-pdf">Export PDF</a>
            </div>

            <div class="report-count">Menampilkan <strong><?= count($rows) ?></strong> transaksi selesai.</div>

            <div class="summary">
                <div class="summary-card">
                    <div class="label">Jumlah Transaksi</div>
                    <div class="value"><?= number_format(count($rows), 0, ',', '.') ?></div>
                </div>
                <div class="summary-card">
                    <div class="label">Total Cash</div>
                    <div class="value"><?= htmlspecialchars(rupiah($cashTotal), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="summary-card">
                    <div class="label">Total QRIS</div>
                    <div class="value"><?= htmlspecialchars(rupiah($qrisTotal), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="summary-card">
                    <div class="label">Total Omzet</div>
                    <div class="value"><?= htmlspecialchars(rupiah($totalOmzet), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>

            <div class="table-wrap">
                <?php if (empty($rows)): ?>
                    <div class="empty">Belum ada data untuk periode <?= htmlspecialchars($tabLabels[$filter], ENT_QUOTES, 'UTF-8') ?>.</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nama Pemesan</th>
                                <th>Kategori</th>
                                <th>Menu Dipesan</th>
                                <th>Total</th>
                                <th>Pembayaran</th>
                                <th>Tanggal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $method = strtolower(trim((string)($row['payment_method'] ?? 'cash')));
                                $isQris = in_array($method, ['qris', 'qr'], true);
                                $isCash = in_array($method, ['cash', 'tunai'], true);
                                $paymentLabel = $isQris ? 'QRIS' : ($isCash ? 'Cash' : '-');
                                $badgeClass = $isQris ? 'qris' : 'cash';
                                ?>
                                <tr>
                                    <td><?= (int)$row['id'] ?></td>
                                    <td><?= htmlspecialchars($row['customer_name'] ?? 'Tanpa Nama', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($row['categories'] ?: '-', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($row['menu_list'] ?: '-', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(rupiah((float)($row['total'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><span class="badge <?= $badgeClass ?>"><?= $paymentLabel ?></span></td>
                                    <td><?= htmlspecialchars(date('d-m-Y H:i:s', strtotime($row['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>
