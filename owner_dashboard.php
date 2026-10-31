<?php
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['owner_logged_in']) || $_SESSION['owner_logged_in'] !== true) {
    header('Location: owner_login.php');
    exit;
}

$salesResult = $mysqli->query("
    SELECT
        COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN total ELSE 0 END), 0) AS today,
        COALESCE(SUM(CASE
            WHEN YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())
            THEN total ELSE 0 END), 0) AS month,
        COALESCE(SUM(CASE WHEN YEAR(created_at) = YEAR(CURDATE()) THEN total ELSE 0 END), 0) AS year
    FROM orders
    WHERE status = 'selesai'
");
if (!$salesResult) {
    error_log('Gagal memuat ringkasan penjualan owner: ' . $mysqli->error);
    http_response_code(500);
    exit('Gagal memuat ringkasan penjualan.');
}
$salesTotals = $salesResult->fetch_assoc();

$categoryResult = $mysqli->query("
    SELECT
        COALESCE(NULLIF(TRIM(m.category), ''), 'Lainnya') AS category,
        SUM(oi.qty) AS total_qty
    FROM orders o
    INNER JOIN order_items oi ON oi.order_id = o.id
    LEFT JOIN menus m ON m.id = oi.menu_id
    WHERE o.status = 'selesai'
    GROUP BY COALESCE(NULLIF(TRIM(m.category), ''), 'Lainnya')
    ORDER BY total_qty DESC, category ASC
");
if (!$categoryResult) {
    error_log('Gagal memuat kategori penjualan owner: ' . $mysqli->error);
    http_response_code(500);
    exit('Gagal memuat kategori penjualan.');
}
$categorySales = $categoryResult->fetch_all(MYSQLI_ASSOC);
$categoryTotal = array_sum(array_map(static function ($category) {
    return (int)$category['total_qty'];
}, $categorySales));

$categoryColors = ['#2b67c9', '#7da9ea', '#b7ccef', '#d97706', '#16a34a', '#8b5cf6', '#e879a9'];
$categoryGradient = [];
$categoryLegend = [];
$categoryPosition = 0.0;
foreach ($categorySales as $index => $category) {
    $quantity = (int)$category['total_qty'];
    $percentage = $categoryTotal > 0 ? ($quantity / $categoryTotal) * 100 : 0;
    $nextPosition = $categoryPosition + $percentage;
    $color = $categoryColors[$index % count($categoryColors)];
    $categoryGradient[] = $color . ' ' . $categoryPosition . '% ' . $nextPosition . '%';
    $categoryLegend[] = [
        'name' => $category['category'],
        'quantity' => $quantity,
        'percentage' => $categoryTotal > 0 ? (int)round($percentage) : 0,
        'color' => $color
    ];
    $categoryPosition = $nextPosition;
}
$donutBackground = $categoryGradient
    ? 'conic-gradient(' . implode(', ', $categoryGradient) . ')'
    : 'conic-gradient(#e5e7eb 0% 100%)';

$topMenuResult = $mysqli->query("
    SELECT oi.menu_name, SUM(oi.qty) AS total_qty
    FROM orders o
    INNER JOIN order_items oi ON oi.order_id = o.id
    WHERE o.status = 'selesai'
    GROUP BY oi.menu_name
    ORDER BY total_qty DESC, oi.menu_name ASC
    LIMIT 5
");
if (!$topMenuResult) {
    error_log('Gagal memuat menu terlaris owner: ' . $mysqli->error);
    http_response_code(500);
    exit('Gagal memuat menu terlaris.');
}
$topMenus = $topMenuResult->fetch_all(MYSQLI_ASSOC);
$topMenuMaxQuantity = $topMenus ? max(array_map(static function ($menu) {
    return (int)$menu['total_qty'];
}, $topMenus)) : 0;
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Owner</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #0d1f2d;
            --navy-soft: #172d3f;
            --blue: #1d4e9a;
            --bg: #eaf0f7;
            --card: #f3f3f3;
            --line: #dfe4ea;
            --text: #1f2a37;
            --muted: #64748b;
            --white: #fff;
            --silver: #b9bec8;
            --gray: #d9dde3;
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
            padding: 28px 30px 26px;
            background: #f4f7fb;
        }

        .panel {
            max-width: 920px;
            margin: 0 auto;
            background: #ffffff;
            border: 1px solid var(--line);
            border-radius: 10px 10px 0 0;
            padding: 18px 20px 18px;
        }

        .panel h1 {
            margin: 0;
            font-size: 24px;
            color: var(--blue);
            font-weight: 700;
        }

        .panel p {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 14px;
        }

        .cards {
            max-width: 920px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(3, minmax(180px, 1fr));
            gap: 14px;
            margin-top: 12px;
        }

        .metric {
            background: #ffffff;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 16px 18px;
            min-height: 110px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .metric .label {
            font-size: 12px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 8px;
        }

        .metric .value {
            font-size: 20px;
            font-weight: 700;
            color: var(--blue);
        }

        .board {
            max-width: 920px;
            margin: 14px auto 0;
            background: #ffffff;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 18px;
            display: grid;
            grid-template-columns: 1.1fr 1.2fr;
            gap: 18px;
        }

        .chart-box {
            background: #ffffff;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 16px;
            min-width: 0;
        }

        .chart-title {
            margin: 0;
            color: var(--blue);
            font-size: 16px;
            font-weight: 700;
        }

        .donut-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 240px;
        }

        .donut {
            width: 180px;
            height: 180px;
            border-radius: 50%;
            background: conic-gradient(
                #2b67c9 0 38%,
                #7da9ea 38% 55%,
                #b7ccef 55% 70%,
                #dfe9f8 70% 100%
            );
            position: relative;
            margin: 12px auto;
            box-shadow: inset 0 0 0 1px rgba(0,0,0,0.04);
        }

        .donut::after {
            content: "";
            position: absolute;
            inset: 28px;
            background: rgba(255,255,255,0.86);
            border-radius: 50%;
        }

        .legend {
            list-style: none;
            padding: 0;
            margin: 10px 0 0;
            display: grid;
            gap: 8px;
            width: 100%;
        }

        .legend li {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 13px;
            color: var(--text);
            gap: 8px;
        }

        .legend span {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .swatch {
            width: 12px;
            height: 12px;
            border-radius: 3px;
            display: inline-block;
        }

        .bars {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 8px;
        }

        .bar-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            font-size: 13px;
            color: var(--text);
        }

        .bar-track {
            flex: 1;
            height: 9px;
            background: #e7e9ed;
            border-radius: 999px;
            overflow: hidden;
        }

        .bar-fill {
            height: 100%;
            border-radius: 999px;
            background: #7da9ea;
        }

        .bar-row strong {
            min-width: 34px;
            text-align: right;
            color: var(--muted);
        }

        .empty-chart {
            color: var(--muted);
            font-size: 13px;
            text-align: center;
            padding: 18px 8px;
        }

        @media (max-width: 860px) {
            .board {
                grid-template-columns: 1fr;
            }
            .cards {
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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
            <a href="owner_dashboard.php" class="active"><span class="nav-icon">🏠</span><span class="nav-label">Dashboard</span></a>
            <a href="owner_laporan.php"><span class="nav-icon">📊</span><span class="nav-label">Laporan</span></a>
            <a href="owner_logout.php" class="logout"><span class="nav-icon">🚪</span><span class="nav-label">Logout</span></a>
        </nav>
    </aside>

    <main class="content">
        <div class="panel">
            <h1>Dashboard Owner</h1>
            <p>Ringkasan transaksi berstatus selesai di Belakang Studio.</p>
        </div>

        <section class="cards">
            <div class="metric">
                <div class="label">Penjualan Hari Ini</div>
                <div class="value"><?= htmlspecialchars(rupiah($salesTotals['today']), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="metric">
                <div class="label">Penjualan Bulan Ini</div>
                <div class="value"><?= htmlspecialchars(rupiah($salesTotals['month']), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="metric">
                <div class="label">Penjualan Tahun Ini</div>
                <div class="value"><?= htmlspecialchars(rupiah($salesTotals['year']), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </section>

        <section class="board">
            <div class="chart-box">
                <h2 class="chart-title">Penjualan per Kategori</h2>
                <div class="donut-wrap">
                    <div class="donut" style="background:<?= htmlspecialchars($donutBackground, ENT_QUOTES, 'UTF-8') ?>;" role="img" aria-label="Grafik penjualan berdasarkan kategori"></div>
                </div>
                <ul class="legend">
                    <?php if ($categoryLegend): ?>
                        <?php foreach ($categoryLegend as $category): ?>
                            <li>
                                <span>
                                    <i class="swatch" style="background:<?= htmlspecialchars($category['color'], ENT_QUOTES, 'UTF-8') ?>"></i>
                                    <?= htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <strong><?= $category['percentage'] ?>% (<?= number_format($category['quantity'], 0, ',', '.') ?>)</strong>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="empty-chart">Belum ada transaksi selesai.</li>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="chart-box">
                <h2 class="chart-title">5 Menu Terlaris</h2>
                <?php if ($topMenus): ?>
                    <div class="bars">
                        <?php foreach ($topMenus as $menu): ?>
                            <?php $barWidth = $topMenuMaxQuantity > 0 ? ((int)$menu['total_qty'] / $topMenuMaxQuantity) * 100 : 0; ?>
                            <div class="bar-row">
                                <span><?= htmlspecialchars($menu['menu_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                <div class="bar-track" role="img" aria-label="<?= (int)$menu['total_qty'] ?> terjual">
                                    <div class="bar-fill" style="width:<?= $barWidth ?>%;"></div>
                                </div>
                                <strong><?= number_format((int)$menu['total_qty'], 0, ',', '.') ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="empty-chart">Belum ada menu terjual dari transaksi selesai.</p>
                <?php endif; ?>
            </div>
        </section>
    </main>
</body>
</html>
