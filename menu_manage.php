<?php
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit;
}

$menuCategories = ['Food & Snack', 'Coffee (1)', 'Coffee (2)', 'Matcha', 'Chocolatte', 'Other Milk Base', 'Others'];

$uploadDir = __DIR__ . "/uploads/";
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$id = intval($_POST['id'] ?? $_GET['id'] ?? 0);
$actionMessage = '';
$actionMessageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = intval($_POST['id'] ?? 0);

    if ($action === 'delete') {
        if ($id <= 0) {
            $actionMessage = 'Menu tidak ditemukan.';
            $actionMessageType = 'error';
        } else {
            try {
                $stmt = $mysqli->prepare("SELECT image FROM menus WHERE id=?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $menuToDelete = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$menuToDelete) {
                    $actionMessage = 'Menu tidak ditemukan atau sudah dihapus.';
                    $actionMessageType = 'error';
                } else {
                    $stmt = $mysqli->prepare("SELECT COUNT(*) AS total FROM order_items WHERE menu_id=?");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $orderReferences = (int)$stmt->get_result()->fetch_assoc()['total'];
                    $stmt->close();

                    if ($orderReferences > 0) {
                        $actionMessage = 'Menu tidak dapat dihapus karena sudah tercatat dalam pesanan. Riwayat transaksi tetap dipertahankan.';
                        $actionMessageType = 'error';
                    } else {
                        $stmt = $mysqli->prepare("DELETE FROM menus WHERE id=?");
                        $stmt->bind_param("i", $id);
                        $stmt->execute();
                        $deleted = $stmt->affected_rows === 1;
                        $stmt->close();

                        if (!$deleted) {
                            $actionMessage = 'Menu tidak berhasil dihapus. Silakan muat ulang halaman dan coba lagi.';
                            $actionMessageType = 'error';
                        } else {
                            $imagePath = (string)($menuToDelete['image'] ?? '');
                            $uploadsPath = realpath($uploadDir);
                            $filePath = $imagePath !== '' ? realpath(__DIR__ . DIRECTORY_SEPARATOR . $imagePath) : false;

                            if ($uploadsPath && $filePath && strpos($filePath, $uploadsPath . DIRECTORY_SEPARATOR) === 0 && is_file($filePath) && !unlink($filePath)) {
                                error_log('Gagal menghapus file gambar menu: ' . $filePath);
                                $actionMessage = 'Menu berhasil dihapus, tetapi file gambarnya gagal dibersihkan.';
                                $actionMessageType = 'error';
                            } else {
                                header("Location: menu_manage.php?msg=deleted");
                                exit;
                            }
                        }
                    }
                }
            } catch (mysqli_sql_exception $exception) {
                error_log('Gagal menghapus menu ' . $id . ': ' . $exception->getMessage());
                $actionMessage = 'Menu gagal dihapus karena masih digunakan oleh data lain.';
                $actionMessageType = 'error';
            }
        }
    } else {
        $category = trim((string)($_POST['category'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));

        $price_hot = $_POST['price_hot'] ?? 0;
        $price_ice = $_POST['price_ice'] ?? 0;
        if ($price_hot === '' || $price_hot === null) $price_hot = 0;
        if ($price_ice === '' || $price_ice === null) $price_ice = 0;
        $price_hot = (int)$price_hot;
        $price_ice = (int)$price_ice;

        $stock = (int)($_POST['stock'] ?? 0);
        if ($stock < 0) $stock = 0;

        $image = '';
        if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            if (in_array($ext, $allowed, true)) {
                $savedToDisk = false;
                if (!getenv('VERCEL') && (is_dir($uploadDir) || @mkdir($uploadDir, 0777, true))) {
                    $filename = uniqid('menu_') . '.' . $ext;
                    $target = $uploadDir . $filename;
                    if (@move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
                        $image = 'uploads/' . $filename;
                        $savedToDisk = true;
                    }
                }
                if (!$savedToDisk) {
                    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['image']['tmp_name']) ?: 'image/jpeg';
                    $rawBytes = @file_get_contents($_FILES['image']['tmp_name']);
                    if ($rawBytes !== false) {
                        @$mysqli->query("ALTER TABLE menus MODIFY COLUMN image LONGTEXT NULL");
                        $image = 'data:' . $mime . ';base64,' . base64_encode($rawBytes);
                    }
                }
            }
        }

        if ($action === 'add') {
            $stmt = $mysqli->prepare("INSERT INTO menus (category, name, description, price_hot, price_ice, stock, image, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("sssiiis", $category, $name, $description, $price_hot, $price_ice, $stock, $image);
            $stmt->execute();
            header("Location: menu_manage.php?msg=added");
            exit;
        } elseif ($action === 'edit' && $id) {
            if ($image !== '') {
                $stmt = $mysqli->prepare("UPDATE menus SET category=?, name=?, description=?, price_hot=?, price_ice=?, stock=?, image=? WHERE id=?");
                $stmt->bind_param("sssiiisi", $category, $name, $description, $price_hot, $price_ice, $stock, $image, $id);
            } else {
                $stmt = $mysqli->prepare("UPDATE menus SET category=?, name=?, description=?, price_hot=?, price_ice=?, stock=? WHERE id=?");
                $stmt->bind_param("sssiiis", $category, $name, $description, $price_hot, $price_ice, $stock, $id);
            }
            $stmt->execute();
            header("Location: menu_manage.php?msg=updated");
            exit;
        }
    }
}

$res = $mysqli->query("SELECT * FROM menus ORDER BY category ASC, name ASC");
$menus = $res->fetch_all(MYSQLI_ASSOC);

$editData = null;
if (($action === 'edit') && $id) {
    $stmt = $mysqli->prepare("SELECT * FROM menus WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $editData = $stmt->get_result()->fetch_assoc();
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Kelola Menu - Kasir Studio</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary-blue: #1a4c9d; --soft-blue: #eef3ff; --light-blue: #dfe8ff; --text-dark: #1f2a37; --text-muted: #5e6a7e; --white: #ffffff; --bg-body: #dfe7e3; --sidebar-dark: #0d1e2d; --danger: #ff7675; --panel-soft: #f5f5f5; --panel-line: #dfe4ea; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background: #ffffff; display: flex; }
        .sidebar { width: 220px; height: 100vh; min-height: 100vh; background: var(--sidebar-dark); padding: 18px 14px 14px; position: fixed; color: #fff; display: flex; flex-direction: column; }
        .sidebar-brand { font-size: 20px; font-weight: 700; margin-bottom: 22px; padding: 8px 12px; display: flex; align-items: center; gap: 10px; letter-spacing: 0.05em; }
        .brand-logo { width: 72px; height: 50px; flex: 0 0 72px; object-fit: contain; object-position: center; }
        .sidebar nav { display: flex; flex: 1; flex-direction: column; gap: 6px; }
        .sidebar nav a { display: flex; align-items: center; gap: 10px; min-height: 42px; padding: 9px 12px; color: #dfe7f1; text-decoration: none; border-radius: 9px; font-size: 13px; transition: background 0.2s ease, color 0.2s ease; }
        .sidebar nav a.active { background: var(--primary-blue); color: #fff; box-shadow: inset 0 0 0 1px rgba(255,255,255,0.06); }
        .main { flex: 1; margin-left: 220px; padding: 24px 28px 30px; }
        .topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; padding: 18px 22px 16px; background: rgba(255,255,255,0.3); border: 1px solid var(--panel-line); border-bottom: none; border-radius: 10px 10px 0 0; }
        .topbar h2 { font-size: 22px; color: var(--primary-blue); font-weight: 700; }
        .page-shell { width: 100%; max-width: none; margin: 0 auto; }
        .grid { display: block; width: 100%; }
        .left-stack { display: none; }
        .card { background: var(--panel-soft); padding: 18px; border-radius: 10px; border: 1px solid var(--panel-line); box-shadow: none; }
        .form-panel { width: 100%; max-width: none; }
        .form-panel h3 { margin-bottom: 12px; font-size: 16px; color: var(--text-dark); }
        .add-panel { border: 1px solid #dbeafe; background: #f5f6f8; }
        .edit-panel { border: 1px solid #cfe3ff; background: #f8fbff; }
        .form-group { margin-bottom: 10px; }
        label { display: block; font-size: 11px; font-weight: 600; color: var(--text-muted); margin-bottom: 5px; }
        input, textarea, select { width: 100%; padding: 9px 10px; border-radius: 10px; border: 1px solid #dfe7f5; background: #fff; font-size: 13px; color: var(--text-dark); }
        input:focus, textarea:focus, select:focus { outline: 2px solid rgba(30, 77, 183, 0.15); border-color: var(--primary-blue); }
        textarea { min-height: 68px; resize: vertical; }
        .compact-form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
        .span-2 { grid-column: 1 / -1; }
        .btn { width: 100%; padding: 10px 12px; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; background: var(--primary-blue); color: #fff; transition: 0.2s ease; }
        .btn:hover { opacity: 0.96; transform: translateY(-1px); }
        .btn-inline { display: inline-flex; align-items: center; justify-content: center; padding: 9px 14px; border: none; border-radius: 10px; background: var(--primary-blue); color: #fff; text-decoration: none; font-size: 12px; font-weight: 600; cursor: pointer; }
        .btn-mini { display: inline-block; padding: 7px 12px; border-radius: 8px; text-decoration: none; font-size: 12px; font-weight: 600; border: none; cursor: pointer; }
        .btn-mini.primary { background: #e0e9ff; color: var(--primary-blue); }
        .btn-mini.danger { background: #fee2e2; color: #b91c1c; }
        .alert.error { background: #fff5f5; color: #b91c1c; border: 1px solid #fecaca; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px 14px; background: #f8fafc; font-size: 12px; color: var(--primary-blue); border-bottom: 1px solid #e5ecf7; }
        td { padding: 12px 14px; border-bottom: 1px solid #eef2f7; font-size: 13px; }
        .table-card { margin-top: 0; width: 100%; max-width: none; min-width: 0; }
        .table-wrap { overflow-x: auto; }
        .menu-img { width: 52px; height: 52px; border-radius: 10px; object-fit: cover; border: 1px solid #e5e7eb; }
        .menu-name { font-weight: 700; }
        .meta { color: var(--text-muted); font-size: 11px; margin-top: 2px; }
        .alert { padding: 10px; background: #d1fae5; color: #065f46; border-radius: 10px; margin-bottom: 20px; font-size: 13px; }
        .action-cell { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .inline-form { display: inline; }
        .edit-card {
            position: fixed;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
            z-index: 50;
            background: #f8fbff;
            border: 1px solid #dfeafc;
            border-radius: 14px;
            padding: 12px 14px;
            max-width: 760px;
            width: min(92vw, 760px);
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.18);
        }
        .edit-card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        .edit-card-header strong { color: var(--primary-blue); font-size: 11px; }
        .edit-form-grid { display: grid; grid-template-columns: repeat(5, minmax(110px, 1fr)); gap: 8px 10px; }
        .edit-form-grid .form-group { margin-bottom: 0; }
        .edit-form-grid label { font-size: 9px; margin-bottom: 3px; }
        .edit-form-grid input, .edit-form-grid textarea, .edit-form-grid select { padding: 6px 7px; font-size: 11px; border-radius: 8px; }
        .edit-form-grid textarea { min-height: 28px; }
        .form-actions { margin-top: 8px; display: flex; gap: 8px; }
        body { background: #f4f7fb; }
        .main { background: #f4f7fb; min-width: 0; }
        .sidebar nav a:hover { background: rgba(255,255,255,0.08); color: #fff; }
        .nav-icon { width: 22px; flex: 0 0 22px; text-align: center; }
        .sidebar nav a .nav-label { white-space: nowrap; }
        .sidebar nav a.logout-link { margin-top: auto; color: #ff8a8a; }
        .sidebar nav a.logout-link:hover { background: rgba(255,138,138,0.12); color: #ffb0b0; }
        .topbar, .card, .table-card {
            background: #fff;
            border: 1px solid #dfe4ea;
            border-radius: 12px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
        }
        .topbar { margin-bottom: 18px; }
        @media (max-width: 1024px) {
            .sidebar { width: 80px; padding: 30px 10px; }
            .sidebar-brand { justify-content: center; padding: 8px 0; }
            .brand-logo { width: 46px; height: 46px; flex-basis: 46px; }
            .brand-label, .sidebar nav .nav-label { display: none; }
            .sidebar nav a { justify-content: center; padding: 12px 8px; }
            .main { margin-left: 80px; }
        }
        @media (max-width: 600px) {
            .sidebar { width: 62px; padding: 16px 6px; }
            .main { margin-left: 62px; padding: 14px; }
            .topbar { align-items: flex-start; gap: 12px; flex-wrap: wrap; }
            .edit-form-grid { grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-brand"><img class="brand-logo" src="images/BsBiru2-cropped.png" alt="Logo Belakang Studio"><span class="brand-label">KASIR</span></div>
        <nav>
            <a href="admin_dashboard.php"><span class="nav-icon">🏠</span><span class="nav-label">Dashboard</span></a>
            <a href="laporan.php"><span class="nav-icon">📊</span><span class="nav-label">Rekap Transaksi</span></a>
            <a href="menu_manage.php" class="active"><span class="nav-icon">📋</span><span class="nav-label">Kelola Menu</span></a>
            <a href="admin_kelola_stok.php"><span class="nav-icon">📦</span><span class="nav-label">Kelola Stok</span></a>
            <a href="logout.php" class="logout-link"><span class="nav-icon">🚪</span><span class="nav-label">Logout</span></a>
        </nav>
    </div>
    <div class="main">
        <div class="page-shell">
            <div class="topbar">
                <h2>Manajemen Menu</h2>
                <a href="menu_manage.php?action=add" class="btn-inline">+ Tambah Menu</a>
            </div>

            <?php if ($actionMessage !== ''): ?>
                <div class="alert <?= $actionMessageType === 'error' ? 'error' : '' ?>" role="status"><?= htmlspecialchars($actionMessage, ENT_QUOTES, 'UTF-8') ?></div>
            <?php elseif (isset($_GET['msg'])): ?>
                <div class="alert">
                    <?= $_GET['msg'] === 'deleted' ? 'Menu berhasil dihapus.' : ($_GET['msg'] === 'added' ? 'Menu berhasil ditambahkan.' : ($_GET['msg'] === 'updated' ? 'Menu berhasil diperbarui.' : 'Aksi berhasil diperbarui!')) ?>
                </div>
            <?php endif; ?>

            <div class="grid">
                <?php if (($action === 'add') || (isset($_GET['action']) && $_GET['action'] === 'add')): ?>
                    <div class="card form-panel add-panel" style="margin-bottom: 18px;">
                        <h3>➕ Tambah Menu</h3>
                        <form method="post" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="add">
                            <div class="compact-form-grid">
                                <div class="form-group">
                                    <label>Kategori</label>
                                    <select name="category" required>
                                        <?php foreach ($menuCategories as $category): ?>
                                            <option value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Nama Menu</label>
                                    <input type="text" name="name" placeholder="Masukkan nama menu" required>
                                </div>
                                <div class="form-group">
                                    <label>Harga Panas (Rp)</label>
                                    <input type="number" name="price_hot" placeholder="0">
                                </div>
                                <div class="form-group">
                                    <label>Harga Dingin (Rp)</label>
                                    <input type="number" name="price_ice" placeholder="0">
                                </div>
                                <div class="form-group">
                                    <label>Stok</label>
                                    <input type="number" name="stock" value="0" required>
                                </div>
                                <div class="form-group">
                                    <label>Gambar</label>
                                    <input type="file" name="image" accept="image/*">
                                </div>
                                <div class="form-group span-2">
                                    <label>Deskripsi</label>
                                    <textarea name="description" placeholder="Masukkan deskripsi menu"></textarea>
                                </div>
                                <div class="form-group span-2" style="display:flex; gap:10px; align-items:center; margin-bottom:0;">
                                    <button type="submit" class="btn-inline" style="width:auto;">Simpan Menu</button>
                                    <a href="menu_manage.php" style="font-size:12px; color:var(--text-muted); text-decoration:none;">Batal</a>
                                </div>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <?php if ($editData): ?>
                    <div class="edit-card" id="edit-menu-panel">
                        <div class="edit-card-header">
                            <strong>Edit Menu</strong>
                            <a href="menu_manage.php" style="font-size:12px; color:var(--text-muted); text-decoration:none;">Batal</a>
                        </div>
                        <form method="post" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="id" value="<?= (int)$editData['id'] ?>">
                            <div class="edit-form-grid">
                                <div class="form-group">
                                    <label>Kategori</label>
                                    <select name="category" required>
                                        <?php if (!in_array($editData['category'], $menuCategories, true)): ?>
                                            <option value="<?= htmlspecialchars($editData['category'], ENT_QUOTES, 'UTF-8') ?>" selected><?= htmlspecialchars($editData['category'], ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endif; ?>
                                        <?php foreach ($menuCategories as $category): ?>
                                            <option value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>" <?= $editData['category'] === $category ? 'selected' : '' ?>><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Nama</label>
                                    <input type="text" name="name" value="<?= htmlspecialchars($editData['name'] ?? '') ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Harga Panas</label>
                                    <input type="number" name="price_hot" value="<?= htmlspecialchars((string)($editData['price_hot'] ?? '')) ?>">
                                </div>
                                <div class="form-group">
                                    <label>Harga Dingin</label>
                                    <input type="number" name="price_ice" value="<?= htmlspecialchars((string)($editData['price_ice'] ?? '')) ?>">
                                </div>
                                <div class="form-group" style="grid-column: 1 / -1;">
                                    <label>Gambar Menu</label>
                                    <?php if (!empty($editData['image'])): ?>
                                        <div style="margin-bottom:8px;">
                                            <img src="<?= htmlspecialchars($editData['image']) ?>" alt="Preview menu" style="width:80px;height:80px;object-fit:cover;border-radius:12px;border:1px solid #dfeafc;display:block;">
                                        </div>
                                    <?php endif; ?>
                                    <input type="file" name="image" accept="image/*">
                                </div>
                                <div class="form-group" style="grid-column: 1 / -1;">
                                    <label>Deskripsi</label>
                                    <textarea name="description" style="min-height:28px;"><?= htmlspecialchars($editData['description'] ?? '') ?></textarea>
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn-inline" style="width:auto; padding:6px 10px; font-size:11px;">Simpan</button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <div class="card table-card" style="margin-top: 0;">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>IMG</th>
                                <th>MENU</th>
                                <th>HARGA</th>
                                <th>STOK</th>
                                <th>AKSI</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($menus as $m): ?>
                            <tr>
                                <td><img src="<?= $m['image'] ? htmlspecialchars($m['image']) : '' ?>" class="menu-img" alt=""></td>
                                <td>
                                    <div class="menu-name"><?= htmlspecialchars($m['name']) ?></div>
                                    <div class="meta"><?= htmlspecialchars($m['category']) ?></div>
                                </td>
                                <td>
                                    <div>H: <?= $m['price_hot'] ? rupiah($m['price_hot']) : '-' ?></div>
                                    <div>I: <?= $m['price_ice'] ? rupiah($m['price_ice']) : '-' ?></div>
                                </td>
                                <td><b><?= $m['stock'] ?></b></td>
                                <td>
                                    <div class="action-cell">
                                        <a href="menu_manage.php?action=edit&id=<?= (int)$m['id'] ?>" class="btn-mini primary">Edit</a>
                                        <form method="post" class="inline-form" onsubmit="return confirm('Hapus menu ini?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                            <button type="submit" class="btn-mini danger">Hapus</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const panel = document.getElementById('edit-menu-panel');
            if (panel) {
                panel.style.display = 'block';
                panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
    </script>
</body>
</html>