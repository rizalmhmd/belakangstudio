<?php
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'], $_POST['quantity'], $_POST['action'])) {
    $id = is_scalar($_POST['id']) ? filter_var($_POST['id'], FILTER_VALIDATE_INT) : false;
    $quantity = is_scalar($_POST['quantity']) ? filter_var($_POST['quantity'], FILTER_VALIDATE_INT) : false;
    $action = is_scalar($_POST['action']) ? (string)$_POST['action'] : '';
    $message = '';
    $messageType = 'error';

    if ($id === false || $id <= 0 || $quantity === false || $quantity <= 0 || !in_array($action, ['tambah', 'kurang'], true)) {
        $message = 'Masukkan jumlah perubahan stok yang valid.';
    } else {
        $sql = $action === 'tambah'
            ? "UPDATE menus SET stock = stock + ? WHERE id = ?"
            : "UPDATE menus SET stock = stock - ? WHERE id = ? AND stock >= ?";
        $stmt = $mysqli->prepare($sql);

        if (!$stmt) {
            error_log('Gagal menyiapkan perubahan stok: ' . $mysqli->error);
            $message = 'Stok gagal diperbarui. Silakan coba lagi.';
        } else {
            if ($action === 'tambah') {
                $stmt->bind_param("ii", $quantity, $id);
            } else {
                $stmt->bind_param("iii", $quantity, $id, $quantity);
            }

            if (!$stmt->execute()) {
                error_log('Gagal memperbarui stok: ' . $stmt->error);
                $message = 'Stok gagal diperbarui. Silakan coba lagi.';
            } elseif ($stmt->affected_rows === 0) {
                $message = $action === 'kurang'
                    ? 'Stok tidak cukup untuk dikurangi sebanyak itu.'
                    : 'Menu tidak ditemukan.';
            } else {
                $message = $action === 'tambah'
                    ? 'Stok berhasil ditambahkan.'
                    : 'Stok berhasil dikurangi.';
                $messageType = 'success';
            }
            $stmt->close();
        }
    }

    $_SESSION['stock_message'] = ['text' => $message, 'type' => $messageType];
    header("Location: admin_kelola_stok.php");
    exit;
}

$stockMessage = $_SESSION['stock_message'] ?? null;
unset($_SESSION['stock_message']);

$result = $mysqli->query("SELECT * FROM menus ORDER BY category ASC, name ASC");
$menu = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $menu[$row['category']][] = $row;
    }
}

$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Stok - Kasir</title>
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
            --panel-soft: #f5f5f5;
            --panel-line: #dfe4ea;
        }

        * { box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body {
            margin: 0;
            background: #ffffff;
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
            display: flex;
            flex-direction: column;
        }

        .sidebar-brand {
            font-size: 20px;
            font-weight: 700;
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
            background: rgba(255,255,255,0.08);
            color: #fff;
        }

        .sidebar nav a.active {
            background: var(--primary-blue);
            box-shadow: inset 0 0 0 1px rgba(255,255,255,0.06);
        }

        .main {
            flex: 1;
            margin-left: 220px;
            padding: 24px 28px 30px;
        }

        .panel-head {
            background: rgba(255,255,255,0.3);
            border: 1px solid var(--panel-line);
            border-radius: 10px 10px 0 0;
            padding: 18px 22px 16px;
            margin-bottom: 0;
        }

        .panel-head h1 {
            margin: 0;
            font-size: 22px;
            color: var(--primary-blue);
        }

        .panel-head p {
            margin: 5px 0 0;
            color: var(--text-muted);
            font-size: 13px;
        }

        .section-card {
            background: #fff;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid var(--light-blue);
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.03);
            margin-bottom: 22px;
        }

        .section-head {
            background: #f3f6fb;
            padding: 14px 18px;
            border-bottom: 1px solid #e6edf7;
            font-size: 18px;
            font-weight: 600;
            color: var(--text-dark);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid #edf2f7;
            font-size: 14px;
        }

        th {
            background: #f8fafc;
            color: var(--primary-blue);
            font-weight: 600;
        }

        .stock-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .stock-actions input {
            width: 76px;
            padding: 7px 8px;
            border: 1px solid #dfe7f5;
            border-radius: 8px;
            font-size: 13px;
            color: var(--text-dark);
        }

        .stock-actions button {
            border: none;
            color: #fff;
            border-radius: 8px;
            padding: 8px 10px;
            font-weight: 600;
            cursor: pointer;
        }

        .stock-add { background: #16a34a; }
        .stock-subtract { background: #dc2626; }
        .stock-current { font-weight: 600; }
        .stock-message { margin-bottom: 16px; padding: 12px 16px; border-radius: 8px; font-size: 13px; }
        .stock-message.success { background: #ecfdf5; color: #166534; border: 1px solid #bbf7d0; }
        .stock-message.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

        body { background: #f4f7fb; }
        .main { background: #f4f7fb; min-width: 0; }
        .panel-head, .section-card {
            background: #fff;
            border: 1px solid #dfe4ea;
            border-radius: 12px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
        }
        .panel-head { margin-bottom: 18px; }
        .nav-icon { width: 22px; flex: 0 0 22px; text-align: center; }
        .sidebar nav a .nav-label { white-space: nowrap; }
        .sidebar nav a.logout-link { margin-top: auto; color: #ff8a8a; }
        .sidebar nav a.logout-link:hover { background: rgba(255,138,138,0.12); color: #ffb0b0; }

        @media (max-width: 1024px) {
            .sidebar { width: 80px; padding: 20px 10px; }
            .sidebar-brand { justify-content: center; padding: 8px 0; }
            .brand-logo { width: 46px; height: 46px; flex-basis: 46px; }
            .brand-label, .sidebar nav .nav-label { display: none; }
            .sidebar nav a { justify-content: center; padding: 12px 8px; }
            .main { margin-left: 80px; padding: 20px; }
        }
        @media (max-width: 600px) {
            .sidebar { width: 62px; padding: 16px 6px; }
            .main { margin-left: 62px; padding: 14px; }
            th, td { padding: 10px 8px; }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-brand"><img class="brand-logo" src="images/BsBiru2-cropped.png" alt="Logo Belakang Studio"><span class="brand-label">KASIR</span></div>
        <nav>
            <a href="admin_dashboard.php"><span class="nav-icon">🏠</span><span class="nav-label">Dashboard</span></a>
            <a href="laporan.php"><span class="nav-icon">📊</span><span class="nav-label">Rekap Transaksi</span></a>
            <a href="menu_manage.php"><span class="nav-icon">📋</span><span class="nav-label">Kelola Menu</span></a>
            <a href="admin_kelola_stok.php" class="active"><span class="nav-icon">📦</span><span class="nav-label">Kelola Stok</span></a>
            <a href="logout.php" class="logout-link"><span class="nav-icon">🚪</span><span class="nav-label">Logout</span></a>
        </nav>
    </div>

    <div class="main">
        <div class="panel-head">
            <h1>📦 Kelola Stok Menu</h1>
            <p>Update stok menu dengan cepat dan mudah</p>
        </div>

        <?php if ($stockMessage): ?>
            <div class="stock-message <?= htmlspecialchars($stockMessage['type'], ENT_QUOTES, 'UTF-8') ?>" role="status">
                <?= htmlspecialchars($stockMessage['text'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($menu): ?>
            <?php foreach ($menu as $kategori => $items): ?>
                <div class="section-card">
                    <div class="section-head"><?= htmlspecialchars($kategori) ?></div>
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 45%;">Nama Menu</th>
                                <th style="width: 20%;">Stok Saat Ini</th>
                                <th style="width: 30%;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $m): ?>
                                <tr>
                                    <td><?= htmlspecialchars($m['name']) ?></td>
                                    <td>
                                        <span class="stock-current"><?= (int)$m['stock'] ?></span>
                                    </td>
                                    <td>
                                        <form method="post" class="stock-actions">
                                            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                            <input type="number" name="quantity" value="1" min="1" step="1" required aria-label="Jumlah perubahan stok untuk <?= htmlspecialchars($m['name'], ENT_QUOTES, 'UTF-8') ?>">
                                            <button type="submit" name="action" value="tambah" class="stock-add">+ Tambah</button>
                                            <button type="submit" name="action" value="kurang" class="stock-subtract">− Kurang</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="section-card" style="padding: 20px;">
                <p style="margin:0; color:var(--text-muted);">Belum ada menu.</p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
