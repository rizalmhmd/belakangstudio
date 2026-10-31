<?php
// index.php
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $table = trim($_POST['table'] ?? '');
    
    if ($name === '') {
        $error = "Mohon isi nama.";
    } elseif (!ctype_digit($table) || intval($table) <= 0) {
        $error = "Nomor meja harus berupa angka positif.";
    } else {
        $_SESSION['customer'] = ['name' => $name, 'table' => $table];
        $_SESSION['cart'] = [];
        unset($_SESSION['payment_method'], $_SESSION['bukti_qris']);
        header("Location: menu.php");
        exit;
    }
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Selamat Datang - Belakang Studio</title>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
:root {
  --primary-blue: #1E4DB7;
  --soft-blue: #f0f4ff;
  --light-blue: #e0e9ff;
  --text-dark: #2d3436;
  --text-muted: #636e72;
  --white: #ffffff;
  --bg-body: #f8fafd;
}

* { box-sizing: border-box; font-family: 'Poppins', sans-serif; }

body {
  margin: 0;
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  /* Gradasi biru cerah yang bersih */
  background: linear-gradient(135deg, #f0f4ff 0%, #d9e4ff 100%);
  padding: 20px;
  position: relative;
  overflow-x: hidden;
}

/* Dekorasi Lingkaran Halus di Background */
body::before {
  content: "";
  position: absolute;
  width: 300px;
  height: 300px;
  background: var(--primary-blue);
  filter: blur(100px);
  opacity: 0.1;
  top: -50px;
  right: -50px;
  z-index: -1;
}

.card {
  width: 100%;
  max-width: 400px;
  background: var(--white);
  padding: 40px 30px;
  border-radius: 24px;
  box-shadow: 0 20px 50px rgba(30, 77, 183, 0.1);
  border: 1px solid var(--light-blue);
  text-align: center;
  animation: slideUp 0.6s ease-out;
}

@keyframes slideUp {
  from { opacity: 0; transform: translateY(30px); }
  to { opacity: 1; transform: translateY(0); }
}

.logo {
  display: block;
  width: 230px;
  max-width: 85%;
  height: auto;
  margin: 0 auto 18px;
}

h4 {
  margin: 0 0 10px 0;
  font-size: 22px;
  font-weight: 700;
  color: var(--primary-blue);
}

p {
  font-size: 14px;
  color: var(--text-muted);
  margin-bottom: 30px;
  line-height: 1.5;
}

/* Style Input Modern */
.input-group {
  text-align: left;
  margin-bottom: 15px;
}

label {
  display: block;
  font-size: 12px;
  font-weight: 600;
  color: var(--primary-blue);
  margin-bottom: 5px;
  margin-left: 5px;
}

input {
  width: 100%;
  padding: 14px 18px;
  border-radius: 12px;
  border: 1px solid var(--light-blue);
  background: #fcfdfe;
  color: var(--text-dark);
  outline: none;
  font-size: 14px;
  transition: all 0.3s ease;
}

input:focus {
  border-color: var(--primary-blue);
  background: var(--white);
  box-shadow: 0 0 0 4px rgba(30, 77, 183, 0.08);
}

/* Button Modern */
button {
  width: 100%;
  padding: 15px;
  margin-top: 10px;
  border: none;
  border-radius: 12px;
  background: var(--primary-blue);
  color: var(--white);
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s ease;
}

button:hover {
  background: #1640a0;
  transform: translateY(-2px);
  box-shadow: 0 10px 20px rgba(30, 77, 183, 0.2);
}

button:active {
  transform: translateY(0);
}

/* Tautan Keranjang */
.cart-link {
  display: inline-block;
  margin-top: 20px;
  font-size: 13px;
  color: var(--primary-blue);
  text-decoration: none;
  font-weight: 500;
  padding: 8px 15px;
  border-radius: 20px;
  background: var(--soft-blue);
  transition: 0.3s;
}

.cart-link:hover {
  background: var(--light-blue);
}

/* Alert Error */
.alert {
  background: #fff5f5;
  border: 1px solid #ffe5e5;
  color: #eb4d4b;
  padding: 12px;
  border-radius: 12px;
  font-size: 13px;
  margin-bottom: 20px;
}

@media(max-width: 480px) {
  .card {
    padding: 30px 20px;
  }

  .logo {
    width: 210px;
    margin-bottom: 16px;
  }
}
</style>
</head>

<body>

<div class="card">
    <img src="images/BsBiru2-cropped.png" class="logo" alt="Logo Belakang Studio">

    <p>Silakan isi data diri untuk menikmati menu kopi terbaik kami.</p>

    <?php if (!empty($error)): ?>
        <div class="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <div class="input-group">
            <label>Nama Lengkap</label>
            <input type="text" name="name" placeholder="Siapa nama Anda?" required
                   value="<?= isset($_POST['name']) ? htmlspecialchars($_POST['name']) : '' ?>">
        </div>

        <div class="input-group">
            <label>Nomor Meja</label>
            <input type="number" name="table" placeholder="Contoh: 5" min="1" required
                   value="<?= isset($_POST['table']) ? htmlspecialchars($_POST['table']) : '' ?>">
        </div>

        <button type="submit">Mulai Pesan</button>
    </form>

    <?php if (isset($_SESSION['cart']) && count($_SESSION['cart']) > 0): ?>
        <a href="menu.php?resume=1" class="cart-link">
             Lanjutkan Keranjang (<?= count($_SESSION['cart']) ?>)
        </a>
    <?php endif; ?>
</div>

</body>
</html>