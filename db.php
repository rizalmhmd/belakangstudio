<?php
// db.php
$DB_HOST = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? 'gateway01.ap-southeast-1.prod.aws.tidbcloud.com');
$DB_PORT = (int)(getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? 4000));
$DB_USER = getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? '25zLMrgWPwgqxo2.root');
$DB_PASS = getenv('DB_PASS') !== false ? getenv('DB_PASS') : ($_ENV['DB_PASS'] ?? 'J6F3LIi0cWqr30c9');
$DB_NAME = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'test');
$DB_SSL  = getenv('DB_SSL') ?: ($_ENV['DB_SSL'] ?? 'true');

// Setel zona waktu ke WIB (Asia/Jakarta)
date_default_timezone_set('Asia/Jakarta');

$mysqli = mysqli_init();

// Gunakan SSL otomatis jika terhubung ke TiDB Cloud atau jika DB_SSL=true
$useSsl = ($DB_SSL === 'true' || $DB_SSL === '1' || stripos($DB_HOST, 'tidbcloud.com') !== false || $DB_PORT === 4000);
if ($useSsl) {
    $mysqli->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
    mysqli_ssl_set($mysqli, null, null, null, null, null);
    $connected = @$mysqli->real_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT, null, MYSQLI_CLIENT_SSL | MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT);
} else {
    $connected = @$mysqli->real_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
}

if (!$connected || $mysqli->connect_errno) {
    die("Gagal koneksi MySQL: " . $mysqli->connect_error);
}

$mysqli->set_charset("utf8mb4");
@$mysqli->query("SET time_zone = '+07:00'");

// Database Session Handler untuk lingkungan Serverless (Vercel)
if (!class_exists('DbSessionHandler')) {
    class DbSessionHandler implements SessionHandlerInterface {
        private mysqli $db;

        public function __construct(mysqli $db) {
            $this->db = $db;
        }

        public function open(string $path, string $name): bool {
            return true;
        }

        public function close(): bool {
            return true;
        }

        public function read(string $id): string|false {
            $stmt = $this->db->prepare("SELECT data FROM app_sessions WHERE id = ? AND updated_at > ( UNIX_TIMESTAMP() - 86400 )");
            if (!$stmt) return '';
            $stmt->bind_param("s", $id);
            if ($stmt->execute()) {
                $res = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                return $res ? (string)$res['data'] : '';
            }
            $stmt->close();
            return '';
        }

        public function write(string $id, string $data): bool {
            $now = time();
            $stmt = $this->db->prepare("REPLACE INTO app_sessions (id, data, updated_at) VALUES (?, ?, ?)");
            if (!$stmt) return false;
            $stmt->bind_param("ssi", $id, $data, $now);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        }

        public function destroy(string $id): bool {
            $stmt = $this->db->prepare("DELETE FROM app_sessions WHERE id = ?");
            if (!$stmt) return false;
            $stmt->bind_param("s", $id);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        }

        public function gc(int $max_lifetime): int|false {
            $threshold = time() - $max_lifetime;
            $stmt = $this->db->prepare("DELETE FROM app_sessions WHERE updated_at < ?");
            if (!$stmt) return false;
            $stmt->bind_param("i", $threshold);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            return $affected;
        }
    }
}

// Gunakan database session di Vercel (atau jika DB_SESSION=true)
if (getenv('VERCEL') || getenv('DB_SESSION') === 'true' || $useSsl) {
    @$mysqli->query("CREATE TABLE IF NOT EXISTS app_sessions (
        id VARCHAR(128) NOT NULL PRIMARY KEY,
        data LONGTEXT NOT NULL,
        updated_at INT UNSIGNED NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$mysqli->query("CREATE TABLE IF NOT EXISTS menus (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category VARCHAR(100) NOT NULL DEFAULT 'Others',
        name VARCHAR(150) NOT NULL,
        description TEXT NULL,
        price_hot INT NOT NULL DEFAULT 0,
        price_ice INT NOT NULL DEFAULT 0,
        stock INT NOT NULL DEFAULT 0,
        image LONGTEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Auto-seed daftar menu awal jika tabel menus masih kosong
    $menuCheck = @$mysqli->query("SELECT COUNT(*) AS cnt FROM menus");
    if ($menuCheck && (int)($menuCheck->fetch_assoc()['cnt'] ?? 0) === 0) {
        $defaultMenus = [
            ['Food & Snack', 'Burger Melt Master', 'Burger daging sapi juicy dengan lelehan keju spesial', 25000, 32000, 20, 'uploads/Burger_Melt_Master.jpg'],
            ['Food & Snack', 'Burger Spice Blaster', 'Burger sapi dengan saus pedas gurih khas Studio', 25000, 32000, 20, 'uploads/Burger_Spice_Blaster.jpg'],
            ['Food & Snack', 'Cheese Cake', 'Potongan kue keju lembut dan creamy', 22000, 0, 15, 'uploads/Cheese_cake.jpg'],
            ['Food & Snack', 'Croissant Choco', 'Pastry renyah berlapis dengan isian cokelat lumer', 18000, 0, 15, 'uploads/Croissant_Choco.jpg'],
            ['Food & Snack', 'Croissant Plain', 'Croissant mentega klasik yang renyah dan harum', 15000, 0, 15, 'uploads/Croissant_Plain.jpg'],
            ['Food & Snack', 'Kentang Goreng', 'French fries renyah dengan taburan bumbu gurih', 15000, 0, 25, 'uploads/Kentang_Goreng.jpg'],
            ['Food & Snack', 'Keripik', 'Camilan keripik renyah teman ngopi', 12000, 0, 25, 'uploads/Keripik.jpg'],
            ['Food & Snack', 'Mac & Cheese', 'Makaroni panggang dengan saus keju melimpah', 22000, 0, 15, 'uploads/Mac_Cheese.jpg'],
            ['Food & Snack', 'Mac & Cheese Chicken Nashville', 'Mac & cheese creamy dengan topping ayam pedas Nashville', 28000, 0, 15, 'uploads/Mac_Cheese_Chicken_Nashville.jpg'],
            ['Food & Snack', 'Mix Platter', 'Paket camilan lengkap sosis, kentang, dan nugget', 25000, 0, 20, 'uploads/Mix_Platter.jpg'],
            ['Food & Snack', 'Ricebowl Nashville', 'Nasi hangat dengan ayam crispy bumbu Nashville pedas gurih', 25000, 30000, 20, 'uploads/Ricebowl_Nashville.jpg'],
            ['Food & Snack', 'Spaghetti Carbonara', 'Spaghetti creamy saus carbonara dengan smoked beef', 25000, 0, 15, 'uploads/Spaghetti_Carbonara.jpg'],
            ['Coffee (1)', 'Americano', 'Espresso dengan air mineral hangat atau dingin yang segar', 15000, 17000, 30, 'uploads/Americano.jpg'],
            ['Coffee (1)', 'Americano Breeze', 'Kesegaran Americano dingin dengan sentuhan manis segar', 0, 20000, 25, 'uploads/Americano_Breeze.jpg'],
            ['Coffee (1)', 'Americano On The Rock', 'Double shot espresso disajikan langsung di atas es batu', 0, 18000, 25, 'uploads/Americano_on_the_rock.jpg'],
            ['Coffee (1)', 'Cafe Latte', 'Perpaduan espresso lembut dengan steamed milk', 18000, 20000, 30, 'uploads/Cafe_Latte.jpg'],
            ['Coffee (1)', 'Dirty Latte', 'Susu dingin segar dengan tuangan espresso hangat di atasnya', 0, 22000, 25, 'uploads/Dirty_Latte.jpg'],
            ['Coffee (1)', 'Espresso', 'Ekstraksi kopi murni dengan crema tebal', 12000, 0, 30, 'uploads/Espresso.jpg'],
            ['Coffee (1)', 'Magic', 'Double ristretto dengan rasio susu microfoam yang seimbang', 20000, 0, 25, 'uploads/Magic.jpg'],
            ['Coffee (1)', 'Studio Latte', 'Signature kopi susu racikan khas Belakang Studio', 20000, 22000, 30, 'uploads/Studio_Latte.jpg'],
            ['Coffee (2)', 'Avocado Coffee', 'Paduan alpukat lembut dengan siraman shot espresso', 0, 24000, 20, 'uploads/Avocado_Coffee.jpg'],
            ['Coffee (2)', 'Es Kopi Susu Klasik', 'Es kopi susu gula aren klasik favorit', 0, 18000, 35, 'uploads/Es_Kopi_Susu_Klasik.jpg'],
            ['Coffee (2)', 'Es Kopi Susu Butterscotch', 'Es kopi susu lembut dengan aroma butterscotch manis gurih', 0, 22000, 30, 'uploads/Es_Kopi_Susu_Butterscotch.jpg'],
            ['Coffee (2)', 'Es Kopi Susu Regal', 'Es kopi susu creamy dengan topping biskuit Marie Regal', 0, 23000, 25, 'uploads/Es_Kopi_Susu_Regal.jpg'],
            ['Coffee (2)', 'Es Kopi Susu Salted Caramel', 'Es kopi susu dengan perpaduan saus karamel gurih', 0, 22000, 30, 'uploads/Es_Kopi_Susu_Salted_Caramel.jpg'],
            ['Coffee (2)', 'Es Kopi Susu Vanilla', 'Es kopi susu dengan sentuhan sirup vanilla harum', 0, 20000, 30, 'uploads/Es_Kopi_Susu_Vanilla.jpg'],
            ['Matcha', 'Matcha Latte Regular', 'Minuman teh hijau Jepang lembut dengan susu segar', 20000, 22000, 25, 'uploads/Matcha_Latte_Regular.jpg'],
            ['Matcha', 'Matcha Latte Strong', 'Matcha latte dengan takaran bubuk matcha lebih pekat', 23000, 25000, 25, 'uploads/Matcha_Latte_Strong.jpg'],
            ['Matcha', 'Matcha Peppermint Latte', 'Perpaduan matcha creamy dengan sensasi dingin peppermint', 22000, 24000, 20, 'uploads/Matcha_Peppermint_Latte.jpg'],
            ['Matcha', 'Matcha Regal Latte', 'Matcha latte dingin dengan taburan biskuit Regal', 0, 24000, 20, 'uploads/Matcha_Regal_Latte.jpg'],
            ['Matcha', 'Matcha Salted Caramel', 'Matcha latte dengan saus salted caramel yang legit', 0, 24000, 20, 'uploads/Matcha_Salted_Caramel.jpg'],
            ['Matcha', 'Matchapresso', 'Perpaduan unik antara teh hijau matcha, susu, dan shot espresso', 22000, 24000, 20, 'uploads/Matchapresso.jpg'],
            ['Matcha', 'Strawberry Matcha Latte', 'Gradasi selai stroberi manis segar, susu, dan matcha pilihan', 0, 24000, 25, 'uploads/Strawberry_Matcha_Latte.jpg'],
            ['Matcha', 'Unsweet Matcha Latte', 'Matcha latte murni tanpa tambahan gula', 20000, 22000, 20, 'uploads/Unsweet_Matcha_Latte.jpg'],
            ['Chocolatte', 'Chocolatte', 'Minuman cokelat klasik yang kaya rasa dan creamy', 18000, 20000, 30, 'uploads/Chocolatte.jpg'],
            ['Chocolatte', 'Chocolatte Butterscotch', 'Cokelat susu lembut dengan sirup butterscotch', 20000, 22000, 25, 'uploads/Chocolatte_Butterscotch.jpg'],
            ['Chocolatte', 'Chocolatte Peppermint', 'Cokelat creamy dengan kesegaran rasa mint', 20000, 22000, 25, 'uploads/Chocolatte_Peppermint.jpg'],
            ['Chocolatte', 'Chocolatte Regal', 'Es cokelat susu kental dengan topping biskuit Regal', 0, 23000, 25, 'uploads/Chocolatte_Regal.jpg'],
            ['Chocolatte', 'Chocolatte Strawberry', 'Paduan cokelat pekat dengan manis asam stroberi', 0, 23000, 25, 'uploads/Chocolatte_Strawberry.jpg'],
            ['Chocolatte', 'Choco Presso', 'Minuman cokelat berpadu dengan shot espresso kopi', 20000, 22000, 25, 'uploads/Choco_presso.jpg'],
            ['Chocolatte', 'Choco Salted Caramel', 'Minuman cokelat lembut dengan saus salted caramel', 20000, 22000, 25, 'uploads/Choco_Salted_Caramel.jpg'],
            ['Chocolatte', 'Choco Vanilla Latte', 'Minuman cokelat dengan aroma vanilla yang lembut', 20000, 22000, 25, 'uploads/Choco_Vanilla_Latte.jpg'],
            ['Other Milk Base', 'Avocado Latte', 'Minuman susu rasa alpukat yang lembut dan menyegarkan', 0, 20000, 25, 'uploads/Avocado_Latte.jpg'],
            ['Other Milk Base', 'Avocado Regal Latte', 'Susu alpukat dingin dengan topping biskuit Marie Regal', 0, 23000, 25, 'uploads/Avocado_Regal_Latte.jpg'],
            ['Other Milk Base', 'Korean Strawberry', 'Susu segar dingin dengan potongan buah dan selai stroberi ala Korea', 0, 22000, 25, 'uploads/Korean_Strawberry.jpg'],
            ['Other Milk Base', 'Red Velvet Latte', 'Minuman red velvet lembut dan manis dengan susu segar', 18000, 20000, 25, 'uploads/Red_Velvet_Latte.jpg'],
            ['Other Milk Base', 'Red Velvet Regal Latte', 'Es red velvet latte dengan topping biskuit Regal', 0, 23000, 25, 'uploads/Red_Velvet_Regal_Latte.jpg'],
            ['Other Milk Base', 'Redpresso', 'Perpaduan red velvet latte dengan tambahan shot espresso', 20000, 22000, 20, 'uploads/Redpresso.jpg'],
            ['Other Milk Base', 'Taro Latte', 'Minuman ubi ungu taro yang manis dan creamy', 18000, 20000, 25, 'uploads/Taro_Latte.jpg'],
            ['Other Milk Base', 'Vanilla Regal Latte', 'Susu vanilla creamy dengan taburan biskuit Marie Regal', 0, 22000, 25, 'uploads/Vanilla_Regal_Latte.jpg'],
            ['Others', 'Black Tea', 'Teh hitam pilihan disajikan hangat atau dingin', 10000, 12000, 35, 'uploads/Black_Tea.jpg'],
            ['Others', 'Lemon Tea', 'Teh segar dengan perasan jeruk lemon asli', 13000, 15000, 35, 'uploads/Lemon_Tea.jpg'],
            ['Others', 'Lychee Tea', 'Es teh manis segar dengan buah leci', 0, 18000, 30, 'uploads/Lychee_Tea.jpg'],
            ['Others', 'Mineral Water', 'Air mineral kemasan dingin atau suhu ruang', 8000, 0, 40, 'uploads/Mineral_Water.jpg'],
            ['Others', 'Extra Shot', 'Tambahan 1 shot espresso kopi', 5000, 0, 50, 'uploads/Extra_Shot.jpg'],
            ['Others', 'Extra Green Shot', 'Tambahan shot matcha pekat', 6000, 0, 50, 'uploads/Extra_Green_Shot.jpg'],
            ['Others', 'Extra Egg & Cheese', 'Tambahan topping telur dan keju', 8000, 0, 40, 'uploads/Extra_egg_Cheese.jpg'],
        ];
        $seedStmt = @$mysqli->prepare("INSERT INTO menus (category, name, description, price_hot, price_ice, stock, image, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        if ($seedStmt) {
            foreach ($defaultMenus as $dm) {
                $seedStmt->bind_param("sssiiis", $dm[0], $dm[1], $dm[2], $dm[3], $dm[4], $dm[5], $dm[6]);
                @$seedStmt->execute();
            }
            $seedStmt->close();
        }
    }

    @$mysqli->query("CREATE TABLE IF NOT EXISTS orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_name VARCHAR(150) NOT NULL,
        table_number VARCHAR(50) NOT NULL,
        total INT NOT NULL DEFAULT 0,
        payment_method VARCHAR(50) NOT NULL DEFAULT 'cash',
        bukti_qris LONGTEXT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'baru',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$mysqli->query("CREATE TABLE IF NOT EXISTS order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        menu_id INT NOT NULL,
        menu_name VARCHAR(200) NOT NULL,
        variant VARCHAR(50) NULL,
        qty INT NOT NULL DEFAULT 1,
        price INT NOT NULL DEFAULT 0,
        note VARCHAR(255) NULL,
        subtotal INT NOT NULL DEFAULT 0,
        INDEX idx_order_id (order_id),
        INDEX idx_menu_id (menu_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (session_status() === PHP_SESSION_NONE) {
        $handler = new DbSessionHandler($mysqli);
        session_set_save_handler($handler, true);
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Fungsi format Rupiah
if (!function_exists('rupiah')) {
    function rupiah($n) {
        return "Rp " . number_format((float)$n, 0, ',', '.');
    }
}

// Fungsi URL Gambar
if (!function_exists('imageUrl')) {
    function imageUrl($path) {
        if (empty($path)) return null;
        if (strpos($path, 'data:image/') === 0 || preg_match('#^https?://#i', $path)) {
            return $path;
        }
        return ltrim($path, '/');
    }
}
?>
