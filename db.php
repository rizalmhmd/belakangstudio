<?php
// db.php
$DB_HOST = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? 'localhost');
$DB_PORT = (int)(getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? 3306));
$DB_USER = getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? 'root');
$DB_PASS = getenv('DB_PASS') !== false ? getenv('DB_PASS') : ($_ENV['DB_PASS'] ?? '');
$DB_NAME = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'belakangstudiocaffe');
$DB_SSL  = getenv('DB_SSL') ?: ($_ENV['DB_SSL'] ?? '');

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
