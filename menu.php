<?php
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['customer'])) {
    header("Location: index.php");
    exit;
}

$customer = $_SESSION['customer'];
$res = $mysqli->query("SELECT * FROM menus ORDER BY id ASC");
$menus = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// Kategori utama ditampilkan dahulu; kategori lama di database tetap ditampilkan di bawah.
$kategori_urutan = [
    'Food & Snack', 'Coffee (1)', 'Coffee (2)', 'Matcha', 'Chocolatte', 'Other Milk Base', 'Others'
];

$kategori_terpakai = [];
foreach ($menus as &$menuItem) {
    $category = trim((string)($menuItem['category'] ?? ''));
    if ($category === '') {
        $category = 'Others';
        $menuItem['category'] = $category;
    }

    $categoryKey = strtolower($category);
    $kategori_terpakai[$categoryKey] = $category;
}
unset($menuItem);

foreach ($kategori_terpakai as $categoryKey => $category) {
    $isPrimaryCategory = false;
    foreach ($kategori_urutan as $primaryCategory) {
        if (strtolower($primaryCategory) === $categoryKey) {
            $isPrimaryCategory = true;
            break;
        }
    }
    if (!$isPrimaryCategory) {
        $kategori_urutan[] = $category;
    }
}

$grouped = [];
foreach ($menus as $m) {
    foreach ($kategori_urutan as $k) {
        if (strcasecmp($m['category'], $k) == 0) {
            $grouped[$k][] = $m;
        }
    }
}

$cart = $_SESSION['cart'] ?? [];
$menuStocksById = [];
foreach ($menus as $menu) {
    $menuStocksById[(int)$menu['id']] = (int)$menu['stock'];
}
$resumeCheckout = ['items' => [], 'total' => 0];
foreach ($cart as $key => $item) {
    $qty = (int)($item['qty'] ?? 0);
    $price = (int)($item['price'] ?? 0);
    if ($qty <= 0) {
        continue;
    }
    $subtotal = $price * $qty;
    $resumeCheckout['items'][] = [
        'key' => (string)$key,
        'menu_id' => (int)($item['menu_id'] ?? 0),
        'name' => (string)($item['name'] ?? 'Menu'),
        'qty' => $qty,
        'price' => $price,
        'stock' => $menuStocksById[(int)($item['menu_id'] ?? 0)] ?? 0,
        'subtotal' => $subtotal,
        'note' => (string)($item['note'] ?? ''),
    ];
    $resumeCheckout['total'] += $subtotal;
}
$shouldResumeCheckout = isset($_GET['resume']) && $_GET['resume'] === '1' && !empty($resumeCheckout['items']);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Menu - Belakang Studio</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --primary-blue: #1E4DB7; --soft-blue: #f0f4ff; --light-blue: #e0e9ff; --text-dark: #2d3436; --text-muted: #636e72; --white: #ffffff; --bg-body: #f8fafd; }
        * { box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { margin: 0; background-color: var(--bg-body); color: var(--text-dark); padding-bottom: 120px; }
        .page-header { position: sticky; top: 0; z-index: 100; background: rgba(255,255,255,0.96); backdrop-filter: blur(10px); }
        .topbar { background: var(--white); padding: 12px 20px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--light-blue); }
        .topbar .info span { color: var(--primary-blue); font-weight: 700; }
        .topbar a { color: var(--text-muted); text-decoration: none; font-size: 13px; padding: 6px 12px; border-radius: 8px; background: var(--soft-blue); }
        .sticky-header { background: transparent; border-bottom: 1px solid var(--light-blue); }
        .search-container { padding: 12px 15px 5px; max-width: 600px; margin: 0 auto; }
        .search-box { width: 100%; padding: 10px 15px; border-radius: 25px; border: 1px solid var(--light-blue); outline: none; }
        .filter-btns { display: flex; gap: 10px; padding: 10px 12px 15px; overflow-x: auto; justify-content: flex-start; scrollbar-width: none; }
        .filter-btns::-webkit-scrollbar { display: none; }
        .filter-btns button { background: var(--white); border: 1px solid var(--light-blue); color: var(--primary-blue); padding: 8px 18px; border-radius: 20px; font-size: 13px; font-weight: 500; white-space: nowrap; cursor: pointer; transition: all 0.2s; }
        .filter-btns button.active { background: var(--primary-blue); color: var(--white); border-color: var(--primary-blue); }
        h2 { text-align: center; font-size: 1.1rem; color: var(--primary-blue); margin: 30px 0 15px; text-transform: uppercase; letter-spacing: 1px; scroll-margin-top: 170px; }
        /* auto-fill di bawah ini yang mencegah Mineral Water menjadi raksasa */
        .menu-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 15px; padding: 0 15px; max-width: 1100px; margin: 0 auto; }
        .menu-card { height: 100%; background: var(--white); border-radius: 15px; padding: 12px; border: 1px solid var(--light-blue); display: flex; flex-direction: column; }
        /* Gambar dikembalikan ke object-fit: cover seperti semula */
        .menu-card img { width: 100%; height: 130px; border-radius: 10px; object-fit: cover; margin-bottom: 10px; background: #eee; }
        .no-image { height: 130px; background: #eee; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #999; margin-bottom: 10px; }
        .menu-card h3 { height: 40px; margin: 0 0 5px; font-size: 14px; overflow: hidden; }
        .menu-desc { font-size: 11px; color: var(--text-muted); margin-bottom: 8px; line-height: 1.4; height: 32px; overflow: hidden; font-style: italic; }
        .stok { min-height: 16px; font-size: 11px; font-weight: 600; color: #ff7675; margin-bottom: 10px; }
        .variant-block { min-height: 70px; background: var(--soft-blue); padding: 8px; border-radius: 10px; margin-bottom: 8px; }
        .variant-block label { display: block; min-height: 26px; font-size: 10px; font-weight: 600; color: var(--primary-blue); margin-bottom: 4px; }
        .qty-control { display: flex; align-items: center; justify-content: space-between; }
        .qty-btn { background: var(--white); border: 1px solid var(--light-blue); width: 28px; height: 28px; border-radius: 8px; cursor: pointer; color: var(--primary-blue); font-weight: bold; }
        .qty-input { width: 54px; height: 30px; padding: 2px 4px; border: 1px solid var(--light-blue); border-radius: 7px; color: var(--primary-blue); text-align: center; font-size: 13px; font-weight: 600; appearance: textfield; -moz-appearance: textfield; }
        .qty-input::-webkit-inner-spin-button, .qty-input::-webkit-outer-spin-button { margin: 0; -webkit-appearance: none; }
        .summary-qty-control { display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
        .summary-qty-control .qty-input { width: 48px; }
        .cart-bar { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); width: 90%; max-width: 500px; background: var(--primary-blue); padding: 15px 25px; border-radius: 50px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 10px 25px rgba(30, 77, 183, 0.3); z-index: 1000; transition: transform 0.3s ease; }
        .cart-info { color: var(--white); }
        .btn-checkout { background: var(--white); color: var(--primary-blue); border: none; padding: 8px 20px; border-radius: 25px; font-weight: 700; cursor: pointer; }
        #checkoutModal .modal-content { border: 0; border-radius: 16px; overflow: hidden; }
        #checkoutModal .modal-header, #checkoutModal .modal-footer { padding: 16px 20px; }
        #checkoutModal .modal-body { max-height: 65vh; overflow-y: auto; padding: 16px 20px; }
        #checkoutModal .table { min-width: 560px; margin-bottom: 12px; }
        #checkoutModal .table th { color: var(--text-muted); font-size: 12px; font-weight: 600; }
        #checkoutModal .table td { font-size: 13px; }
        #checkoutModal .form-control, #checkoutModal .form-select { font-size: 13px; }
        #checkoutModal .order-total { color: var(--primary-blue); font-weight: 700; }
        #checkoutModal .qris-panel { display: grid; grid-template-columns: minmax(230px, 0.9fr) minmax(260px, 1.1fr); gap: 20px; align-items: center; padding: 18px; border: 1px solid var(--light-blue); border-radius: 12px; background: var(--soft-blue); }
        #checkoutModal .qris-instructions { margin: 0 0 10px; color: var(--text-muted); font-size: 12px; }
        #checkoutModal .qris-code { display: block; width: 100%; max-width: 280px; max-height: 440px; object-fit: contain; margin: 0 auto; padding: 6px; border: 1px solid var(--light-blue); border-radius: 10px; background: #fff; }
        #checkoutModal .qris-upload { min-width: 0; }
        #checkoutModal .qris-preview-link { display: block; width: fit-content; max-width: 100%; margin: 12px auto 0; cursor: zoom-in; }
        #checkoutModal #qrisPreview { display: block; max-width: 100%; max-height: min(52vh, 440px); object-fit: contain; border: 1px solid var(--light-blue); border-radius: 10px; background: #fff; }
        #checkoutModal #qrisPreview[hidden] { display: none; }
        #checkoutModal .qris-preview-caption { text-align: center; }
        @media (max-width: 480px) {
            .menu-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .topbar { padding: 10px 12px; }
            #checkoutModal .modal-header, #checkoutModal .modal-footer { padding: 12px 14px; }
            #checkoutModal .modal-body { padding: 12px 14px; }
            #checkoutModal .modal-footer .btn { font-size: 12px; }
            #checkoutModal .qris-panel { grid-template-columns: 1fr; gap: 16px; padding: 12px; }
            #checkoutModal .qris-code { width: 100%; max-width: 240px; max-height: 360px; }
        }
    </style>
</head>
<body>

    <div class="page-header">
        <div class="topbar">
            <div class="info">Halo, <span><?= htmlspecialchars($customer['name']) ?></span></div>
            <a href="customer_logout.php">Meja <?= htmlspecialchars($customer['table']) ?> • Keluar</a>
        </div>

        <div class="sticky-header">
            <div class="search-container">
                <input type="text" id="menuSearch" class="search-box" placeholder="Cari menu..." onkeyup="searchMenu()">
            </div>
            <div class="filter-btns">
                <!-- Tombol Filter Sekarang Otomatis Dibuat dari Array -->
                <?php foreach ($kategori_urutan as $index => $kat): 
                    $kat_id = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $kat), '_'));
                ?>
                    <button type="button" class="<?= $index === 0 ? 'active' : '' ?>" onclick="scrollToCat('<?= htmlspecialchars($kat_id, ENT_QUOTES, 'UTF-8') ?>', this)"><?= htmlspecialchars($kat) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <form method="post" action="add_to_cart.php"> 
        <?php foreach ($kategori_urutan as $kat): ?>
            <?php if(isset($grouped[$kat])): ?>
                <?php $kat_id = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $kat), '_')); ?>
                <div class="category-section" id="section_<?= htmlspecialchars($kat_id, ENT_QUOTES, 'UTF-8') ?>">
                    <h2 id="<?= htmlspecialchars($kat_id, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($kat) ?></h2>
                    <div class="menu-grid">
                        <?php foreach ($grouped[$kat] as $m): 
                            $id = $m['id'];
                            
                            // Logika Label Pintar (Agar Makanan tidak dilabeli HOT/ICE)
                            $is_food = in_array(strtolower($m['category']), ['food & snack', 'foods', 'snack'], true);
                            $label_1 = $is_food ? 'Reguler' : 'Hot / Default';
                            $label_2 = $is_food ? 'Paket / Ekstra' : 'Ice';
                        ?>
                        <div class="menu-card" data-name="<?= strtolower(htmlspecialchars($m['name'])) ?>">
                            
                            <?php if (!empty($m['image']) && $m['image'] !== 'uploads/default.jpg' && (strpos($m['image'], 'data:image/') === 0 || preg_match('#^https?://#i', $m['image']) || file_exists(__DIR__ . '/' . ltrim($m['image'], '/')))): ?>
                                <!-- Memanggil foto dari folder uploads atau data URI -->
                                <img src="<?= htmlspecialchars($m['image']) ?>" alt="<?= htmlspecialchars($m['name']) ?>">
                            <?php else: ?>
                                <div class="no-image">No Photo</div>
                            <?php endif; ?>

                            <h3><?= htmlspecialchars($m['name']) ?></h3>
                            <div class="menu-desc"><?= htmlspecialchars(trim($m['description'] ?? '')) ?></div>
                            <div class="stok">Stok: <?= $m['stock'] ?></div>

                            <!-- Pilihan Harga 1 (Hot/Reguler) -->
                            <?php if ($m['price_hot']): ?>
                                <div class="variant-block">
                                    <label><?= $label_1 ?> - <?= rupiah($m['price_hot']) ?></label>
                                    <div class="qty-control">
                                        <button type="button" class="qty-btn" aria-label="Kurangi <?= htmlspecialchars($m['name'], ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($label_1, ENT_QUOTES, 'UTF-8') ?>" onclick="updateQty('<?= $id ?>_hot', -1, <?= (int)$m['stock'] ?>, <?= (int)$id ?>)">-</button>
                                        <input type="number" class="qty-input" id="input_<?= $id ?>_hot" name="items[<?= $id ?>_hot][qty]" value="<?= (int)($cart[$id . '_hot']['qty'] ?? 0) ?>" min="0" max="<?= (int)$m['stock'] ?>" data-menu-id="<?= (int)$id ?>" aria-label="Jumlah <?= htmlspecialchars($m['name'], ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($label_1, ENT_QUOTES, 'UTF-8') ?>" oninput="updateSelectedItemCount()" onchange="updateQty('<?= $id ?>_hot', 0, <?= (int)$m['stock'] ?>, <?= (int)$id ?>)">
                                        <button type="button" class="qty-btn" aria-label="Tambah <?= htmlspecialchars($m['name'], ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($label_1, ENT_QUOTES, 'UTF-8') ?>" onclick="updateQty('<?= $id ?>_hot', 1, <?= (int)$m['stock'] ?>, <?= (int)$id ?>)">+</button>
                                    </div>
                                    <input type="hidden" name="items[<?= $id ?>_hot][name]" value="<?= htmlspecialchars($m['name']) ?> (<?= $label_1 ?>)">
                                    <input type="hidden" name="items[<?= $id ?>_hot][price]" value="<?= $m['price_hot'] ?>">
                                </div>
                            <?php endif; ?>

                            <!-- Pilihan Harga 2 (Ice/Paket) -->
                            <?php if ($m['price_ice']): ?>
                                <div class="variant-block">
                                    <label><?= $label_2 ?> - <?= rupiah($m['price_ice']) ?></label>
                                    <div class="qty-control">
                                        <button type="button" class="qty-btn" aria-label="Kurangi <?= htmlspecialchars($m['name'], ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($label_2, ENT_QUOTES, 'UTF-8') ?>" onclick="updateQty('<?= $id ?>_ice', -1, <?= (int)$m['stock'] ?>, <?= (int)$id ?>)">-</button>
                                        <input type="number" class="qty-input" id="input_<?= $id ?>_ice" name="items[<?= $id ?>_ice][qty]" value="<?= (int)($cart[$id . '_ice']['qty'] ?? 0) ?>" min="0" max="<?= (int)$m['stock'] ?>" data-menu-id="<?= (int)$id ?>" aria-label="Jumlah <?= htmlspecialchars($m['name'], ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($label_2, ENT_QUOTES, 'UTF-8') ?>" oninput="updateSelectedItemCount()" onchange="updateQty('<?= $id ?>_ice', 0, <?= (int)$m['stock'] ?>, <?= (int)$id ?>)">
                                        <button type="button" class="qty-btn" aria-label="Tambah <?= htmlspecialchars($m['name'], ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($label_2, ENT_QUOTES, 'UTF-8') ?>" onclick="updateQty('<?= $id ?>_ice', 1, <?= (int)$m['stock'] ?>, <?= (int)$id ?>)">+</button>
                                    </div>
                                    <input type="hidden" name="items[<?= $id ?>_ice][name]" value="<?= htmlspecialchars($m['name']) ?> (<?= $label_2 ?>)">
                                    <input type="hidden" name="items[<?= $id ?>_ice][price]" value="<?= $m['price_ice'] ?>">
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>

        <div class="cart-bar" id="cartBar">
            <div class="cart-info"><b>Menu dipilih: <span id="selectedItemCount">0</span></b></div>
            <button type="submit" class="btn-checkout">Ke Keranjang</button>
        </div>
    </form>

    <div class="modal fade" id="checkoutModal" tabindex="-1" aria-labelledby="checkoutModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="checkoutModalLabel">Ringkasan Pesanan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <form id="checkoutForm" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>Menu</th>
                                        <th class="text-center">Jumlah</th>
                                        <th>Catatan</th>
                                        <th class="text-end">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody id="checkoutItems"></tbody>
                            </table>
                        </div>
                        <div class="d-flex justify-content-between border-top pt-3 mb-4">
                            <span>Total</span>
                            <span class="order-total" id="checkoutTotal"></span>
                        </div>

                        <div class="mb-3">
                            <label for="checkoutPayment" class="form-label fw-semibold">Metode Pembayaran</label>
                            <select class="form-select" id="checkoutPayment" name="payment" required>
                                <option value="">Pilih metode pembayaran</option>
                                <option value="cash">Tunai / Bayar di Kasir</option>
                                <option value="qris">QRIS</option>
                            </select>
                        </div>
                        <div class="mb-3 qris-panel" id="qrisPaymentDetails" hidden>
                            <div class="text-center">
                                <p class="mb-2 fw-semibold">Scan QRIS</p>
                                <img src="images/1.png?v=20261014" alt="Kode QRIS Belakang Studio" class="qris-code">
                            </div>
                            <div class="qris-upload">
                                <label for="qrisProof" class="form-label fw-semibold mb-1">Unggah bukti pembayaran</label>
                                <p class="qris-instructions">Pilih bukti pembayaran asli (JPG/PNG, maksimal 2 MB). File tidak dikompres.</p>
                                <input class="form-control" type="file" id="qrisProof" name="bukti" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
                                <a id="qrisPreviewLink" class="qris-preview-link" target="_blank" rel="noopener" hidden>
                                    <img id="qrisPreview" alt="Pratinjau bukti pembayaran QRIS" hidden>
                                </a>
                                <div class="form-text qris-preview-caption" id="qrisPreviewCaption" hidden>Klik gambar untuk melihat ukuran penuh.</div>
                            </div>
                        </div>
                        <div class="alert alert-danger py-2 mb-0" id="checkoutError" role="alert" hidden></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Tambah Menu</button>
                        <button type="submit" class="btn btn-primary" id="submitOrderButton">Selesaikan Pesanan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function updateQty(id, delta, stock, menuId) {
        const input = document.getElementById('input_' + id);
        const previousValue = parseInt(input.value, 10) || 0;
        let value = delta === 0 ? previousValue : Math.max(0, previousValue + delta);
        const otherVariantQuantity = Array.from(document.querySelectorAll('.qty-input[data-menu-id="' + menuId + '"]'))
            .filter(item => item !== input)
            .reduce((total, item) => total + (parseInt(item.value, 10) || 0), 0);
        const maxForVariant = Math.max(0, stock - otherVariantQuantity);
        value = Math.min(value, maxForVariant);
        input.value = value;
        input.max = maxForVariant;
        updateSelectedItemCount();
    }

    function updateSelectedItemCount() {
        const selectedCount = Array.from(document.querySelectorAll('form[action="add_to_cart.php"] .qty-input'))
            .reduce((total, input) => total + (parseInt(input.value, 10) || 0), 0);
        document.getElementById('selectedItemCount').textContent = selectedCount;
    }
    updateSelectedItemCount();

    const menuForm = document.querySelector('form[action="add_to_cart.php"]');
    const checkoutForm = document.getElementById('checkoutForm');
    const checkoutItems = document.getElementById('checkoutItems');
    const checkoutModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('checkoutModal'));
    const checkoutPayment = document.getElementById('checkoutPayment');
    const qrisPaymentDetails = document.getElementById('qrisPaymentDetails');
    const qrisProof = document.getElementById('qrisProof');
    const qrisPreview = document.getElementById('qrisPreview');
    const qrisPreviewLink = document.getElementById('qrisPreviewLink');
    const qrisPreviewCaption = document.getElementById('qrisPreviewCaption');
    let qrisPreviewUrl = null;
    const checkoutError = document.getElementById('checkoutError');
    const submitOrderButton = document.getElementById('submitOrderButton');

    function showCheckoutError(message) {
        checkoutError.textContent = message;
        checkoutError.hidden = false;
    }

    function formatRupiah(amount) {
        return 'Rp ' + Number(amount).toLocaleString('id-ID');
    }

    function clearQrisPreview() {
        if (qrisPreviewUrl) {
            URL.revokeObjectURL(qrisPreviewUrl);
            qrisPreviewUrl = null;
        }
        qrisPreviewLink.removeAttribute('href');
        qrisPreviewLink.hidden = true;
        qrisPreview.removeAttribute('src');
        qrisPreview.hidden = true;
        qrisPreviewCaption.hidden = true;
    }

    function renderCheckout(data) {
        checkoutForm.reset();
        checkoutItems.replaceChildren();
        data.items.forEach(item => {
            const row = document.createElement('tr');

            const menuCell = document.createElement('td');
            menuCell.textContent = item.name;
            row.dataset.menuId = item.menu_id;
            const quantityCell = document.createElement('td');
            quantityCell.className = 'text-center text-nowrap';
            const quantityControl = document.createElement('div');
            quantityControl.className = 'summary-qty-control';
            const decreaseButton = document.createElement('button');
            decreaseButton.type = 'button';
            decreaseButton.className = 'qty-btn';
            decreaseButton.textContent = '-';
            decreaseButton.setAttribute('aria-label', `Kurangi ${item.name}`);
            const quantityInput = document.createElement('input');
            quantityInput.type = 'number';
            quantityInput.className = 'qty-input';
            quantityInput.name = `quantities[${item.key}]`;
            quantityInput.min = '0';
            quantityInput.max = String(item.stock);
            quantityInput.value = item.qty;
            quantityInput.dataset.menuId = item.menu_id;
            quantityInput.dataset.menuKey = item.key;
            quantityInput.dataset.price = item.price;
            quantityInput.setAttribute('aria-label', `Jumlah ${item.name}`);
            const increaseButton = document.createElement('button');
            increaseButton.type = 'button';
            increaseButton.className = 'qty-btn';
            increaseButton.textContent = '+';
            increaseButton.setAttribute('aria-label', `Tambah ${item.name}`);
            decreaseButton.addEventListener('click', () => updateSummaryQuantity(quantityInput, -1));
            increaseButton.addEventListener('click', () => updateSummaryQuantity(quantityInput, 1));
            quantityInput.addEventListener('change', () => updateSummaryQuantity(quantityInput, 0));
            quantityControl.append(decreaseButton, quantityInput, increaseButton);
            quantityCell.appendChild(quantityControl);
            const noteCell = document.createElement('td');
            const noteInput = document.createElement('input');
            noteInput.className = 'form-control';
            noteInput.type = 'text';
            noteInput.name = `notes[${item.key}]`;
            noteInput.maxLength = 255;
            noteInput.placeholder = 'Contoh: kurang gula';
            noteInput.value = item.note || '';
            noteCell.appendChild(noteInput);
            const subtotalCell = document.createElement('td');
            subtotalCell.className = 'text-end text-nowrap';
            subtotalCell.dataset.subtotal = '';
            subtotalCell.textContent = formatRupiah(item.subtotal);

            row.append(menuCell, quantityCell, noteCell, subtotalCell);
            checkoutItems.appendChild(row);
        });

        document.getElementById('checkoutTotal').textContent = formatRupiah(data.total);
        checkoutError.hidden = true;
        qrisPaymentDetails.hidden = true;
        qrisProof.required = false;
        clearQrisPreview();
        checkoutModal.show();
    }

    function updateSummaryQuantity(input, delta) {
        const menuId = input.dataset.menuId;
        const previousQuantity = parseInt(input.value, 10) || 0;
        const otherQuantity = Array.from(checkoutItems.querySelectorAll(`.qty-input[data-menu-id="${menuId}"]`))
            .filter(quantityInput => quantityInput !== input)
            .reduce((total, quantityInput) => total + (parseInt(quantityInput.value, 10) || 0), 0);
        const availableStock = Math.max(0, Number(input.max) - otherQuantity);
        let quantity = delta === 0 ? previousQuantity : Math.max(0, previousQuantity + delta);
        quantity = Math.min(quantity, availableStock);
        input.value = quantity;

        const menuQuantityInput = document.getElementById(`input_${input.dataset.menuKey}`);
        if (menuQuantityInput) {
            menuQuantityInput.value = quantity;
        }
        updateSelectedItemCount();

        let total = 0;
        checkoutItems.querySelectorAll('tr').forEach(row => {
            const rowQuantityInput = row.querySelector('.qty-input');
            const subtotal = (parseInt(rowQuantityInput.value, 10) || 0) * Number(rowQuantityInput.dataset.price);
            row.querySelector('[data-subtotal]').textContent = formatRupiah(subtotal);
            total += subtotal;
        });
        document.getElementById('checkoutTotal').textContent = formatRupiah(total);
    }

    const resumeCheckoutData = <?= json_encode($resumeCheckout, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    if (<?= $shouldResumeCheckout ? 'true' : 'false' ?>) {
        renderCheckout(resumeCheckoutData);
    }

    menuForm.addEventListener('submit', async event => {
        event.preventDefault();
        const button = menuForm.querySelector('.btn-checkout');
        const formData = new FormData(menuForm);
        formData.append('ajax', '1');
        button.disabled = true;

        try {
            const response = await fetch('add_to_cart.php', { method: 'POST', body: formData });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Gagal memuat ringkasan pesanan.');
            }
            renderCheckout(data);
        } catch (error) {
            alert(error.message || 'Tidak dapat menghubungi server. Silakan coba lagi.');
        } finally {
            button.disabled = false;
        }
    });

    checkoutPayment.addEventListener('change', () => {
        const isQris = checkoutPayment.value === 'qris';
        qrisPaymentDetails.hidden = !isQris;
        qrisProof.required = isQris;
    });

    qrisProof.addEventListener('change', () => {
        const file = qrisProof.files[0];
        clearQrisPreview();

        if (!file) return;
        if (!['image/jpeg', 'image/png'].includes(file.type) || file.size > 2 * 1024 * 1024) {
            qrisProof.value = '';
            showCheckoutError('Pilih bukti QRIS berupa JPG atau PNG dengan ukuran maksimal 2 MB.');
            return;
        }

        checkoutError.hidden = true;
        qrisPreviewUrl = URL.createObjectURL(file);
        qrisPreviewLink.href = qrisPreviewUrl;
        qrisPreviewLink.hidden = false;
        qrisPreview.src = qrisPreviewUrl;
        qrisPreview.hidden = false;
        qrisPreviewCaption.hidden = false;
    });

    checkoutForm.addEventListener('submit', async event => {
        event.preventDefault();
        checkoutError.hidden = true;
        submitOrderButton.disabled = true;

        const formData = new FormData(checkoutForm);
        formData.append('ajax', '1');

        try {
            const response = await fetch('checkout.php', { method: 'POST', body: formData });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Pesanan tidak dapat diproses.');
            }
            window.location.href = data.redirect;
        } catch (error) {
            showCheckoutError(error.message || 'Tidak dapat menghubungi server. Silakan coba lagi.');
            submitOrderButton.disabled = false;
        }
    });
    
    function scrollToCat(id, btn) {
        document.querySelectorAll('.filter-btns button').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        let el = document.getElementById('section_' + id);
        if (el) {
            const headerHeight = document.querySelector('.page-header').offsetHeight;
            window.scrollTo({ top: el.offsetTop - headerHeight - 12, behavior: 'smooth' });
        }
    }
    
    // Fitur pencarian baru yang otomatis menyembunyikan judul kategori yang kosong
    function searchMenu() {
        let s = document.getElementById('menuSearch').value.toLowerCase();
        
        document.querySelectorAll('.category-section').forEach(section => {
            let hasVisibleItem = false;
            
            section.querySelectorAll('.menu-card').forEach(card => {
                if (card.getAttribute('data-name').includes(s)) {
                    card.style.display = 'flex';
                    hasVisibleItem = true; 
                } else {
                    card.style.display = 'none';
                }
            });
            
            section.style.display = hasVisibleItem ? 'block' : 'none';
        });
    }
    </script>
</body>
</html>