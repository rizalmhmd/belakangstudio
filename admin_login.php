<?php
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// kalau sudah login, langsung ke dashboard admin
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header("Location: admin_dashboard.php");
    exit;
}

$error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // username & password default kasir
    $valid_user = "kasir";
    $valid_pass = "12345";

    if ($username === $valid_user && $password === $valid_pass) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        header("Location: admin_dashboard.php");
        exit;
    } else {
        $error = "Username atau password salah!";
    }
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kasir Login - Belakang Studio</title>
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
  --danger: #ff7675;
}

* { box-sizing: border-box; font-family: 'Poppins', sans-serif; }

body {
  margin: 0;
  height: 100vh;
  display: flex;
  justify-content: center;
  align-items: center;
  background: linear-gradient(135deg, #f0f4ff 0%, #d9e4ff 100%);
  overflow: hidden;
}

/* Dekorasi background agar terasa eksklusif admin */
body::before {
  content: "";
  position: absolute;
  width: 400px;
  height: 400px;
  background: var(--primary-blue);
  filter: blur(130px);
  opacity: 0.08;
  bottom: -100px;
  left: -100px;
  z-index: -1;
}

.card {
  width: 100%;
  max-width: 380px;
  padding: 40px 30px;
  border-radius: 24px;
  background: var(--white);
  border: 1px solid var(--light-blue);
  box-shadow: 0 20px 60px rgba(30, 77, 183, 0.12);
  text-align: center;
  animation: fadeIn 0.6s ease;
}

@keyframes fadeIn {
  from { transform: translateY(20px); opacity: 0; }
  to { transform: translateY(0); opacity: 1; }
}

.icon-box {
  width: 70px;
  height: 70px;
  background: var(--soft-blue);
  color: var(--primary-blue);
  border-radius: 20px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 20px;
  font-size: 30px;
}

h2 {
  margin: 0;
  font-size: 22px;
  color: var(--primary-blue);
  font-weight: 700;
}

p {
  font-size: 13px;
  color: var(--text-muted);
  margin: 5px 0 25px;
}

.form-group {
  text-align: left;
  margin-bottom: 15px;
}

label {
  font-size: 12px;
  font-weight: 600;
  color: var(--primary-blue);
  margin-left: 5px;
  display: block;
  margin-bottom: 5px;
}

input {
  width: 100%;
  padding: 13px 18px;
  border-radius: 12px;
  border: 1px solid var(--light-blue);
  background: #fcfdfe;
  color: var(--text-dark);
  outline: none;
  font-size: 14px;
  transition: 0.3s;
}

input:focus {
  border-color: var(--primary-blue);
  background: var(--white);
  box-shadow: 0 0 0 4px rgba(30, 77, 183, 0.08);
}

button {
  width: 100%;
  padding: 14px;
  margin-top: 15px;
  border: none;
  border-radius: 12px;
  background: var(--primary-blue);
  color: var(--white);
  font-weight: 600;
  font-size: 14px;
  cursor: pointer;
  transition: 0.3s;
}

button:hover {
  background: #1640a0;
  transform: translateY(-2px);
  box-shadow: 0 10px 20px rgba(30, 77, 183, 0.2);
}

.error {
  background: #fff5f5;
  border: 1px solid #ffe5e5;
  color: var(--danger);
  padding: 12px;
  border-radius: 12px;
  font-size: 13px;
  margin-bottom: 20px;
  font-weight: 500;
}

.footer-text {
  margin-top: 25px;
  font-size: 11px;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 1px;
}
</style>
</head>

<body>

<div class="card">
  <div class="icon-box">
    <!-- Simbol Kunci atau User -->
    👤
  </div>
  
  <h2>Belakang Studio</h2>
  <p>Kasir Belakang Studio</p>

  <?php if (!empty($error)): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post">
    <div class="form-group">
      <label>Username</label>
      <input type="text" name="username" value="kasir" placeholder="Masukkan username" autocomplete="username" required>
    </div>
    
    <div class="form-group">
      <label>Password</label>
      <input type="password" name="password" placeholder="Masukkan password" required>
    </div>

    <button type="submit">MASUK KE DASHBOARD</button>
  </form>

  <div class="footer-text">
    Secure Access Only
  </div>
</div>

</body>
</html>