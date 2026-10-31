<?php
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (isset($_SESSION['owner_logged_in']) && $_SESSION['owner_logged_in'] === true) {
    header('Location: owner_dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === 'owner' && $password === '12345') {
        $_SESSION['owner_logged_in'] = true;
        $_SESSION['owner_name'] = $username;
        header('Location: owner_dashboard.php');
        exit;
    }

    $error = 'Nama owner atau password salah.';
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Owner Login - Belakang Studio</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-blue: #1E4DB7;
            --soft-blue: #f0f4ff;
            --light-blue: #e0e9ff;
            --text-dark: #2d3436;
            --text-muted: #636e72;
            --white: #ffffff;
            --bg-body: #edf3ff;
            --danger: #ff7675;
        }

        * { box-sizing: border-box; font-family: 'Poppins', sans-serif; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #eef4ff 0%, #dfeaff 100%);
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            content: "";
            position: absolute;
            width: 320px;
            height: 320px;
            background: var(--primary-blue);
            filter: blur(110px);
            opacity: 0.09;
            top: -60px;
            right: -40px;
            z-index: -1;
        }

        .card {
            width: 100%;
            max-width: 420px;
            background: var(--white);
            border: 1px solid var(--light-blue);
            box-shadow: 0 20px 50px rgba(30, 77, 183, 0.10);
            border-radius: 24px;
            padding: 36px 28px 26px;
            text-align: center;
        }

        .logo {
            width: 150px;
            margin-bottom: 18px;
            filter: drop-shadow(0 5px 10px rgba(0,0,0,0.08));
        }

        h2 {
            margin: 0 0 8px;
            color: var(--primary-blue);
            font-size: 26px;
            font-weight: 700;
        }

        .subtitle {
            font-size: 14px;
            color: var(--text-muted);
            margin: 0 0 26px;
            line-height: 1.5;
        }

        .input-group {
            text-align: left;
            margin-bottom: 16px;
        }

        label {
            display: block;
            margin: 0 0 7px 5px;
            font-size: 12px;
            font-weight: 600;
            color: var(--primary-blue);
        }

        input {
            width: 100%;
            padding: 14px 16px;
            border-radius: 12px;
            border: 1px solid var(--light-blue);
            background: #fcfdfe;
            font-size: 14px;
            color: var(--text-dark);
            outline: none;
            transition: 0.3s;
        }

        input:focus {
            border-color: var(--primary-blue);
            background: var(--white);
            box-shadow: 0 0 0 4px rgba(30, 77, 183, 0.08);
        }

        button {
            width: 100%;
            border: none;
            border-radius: 12px;
            background: var(--primary-blue);
            color: var(--white);
            padding: 15px 16px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.3s;
            margin-top: 10px;
        }

        button:hover {
            background: #1640a0;
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(30, 77, 183, 0.18);
        }

        .alert {
            margin-bottom: 18px;
            padding: 12px 14px;
            border-radius: 12px;
            background: #fff5f5;
            border: 1px solid #ffe5e5;
            color: var(--danger);
            font-size: 13px;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="card">
        <img src="images/BsBiru2-cropped.png" class="logo" alt="Belakang Studio Logo">

        <h2>Belakang Studio</h2>
        <p class="subtitle">Silakan masuk sebagai owner untuk mengelola data restoran.</p>

        <?php if (!empty($error)): ?>
            <div class="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="input-group">
                <label for="username">Nama Owner</label>
                <input id="username" type="text" name="username" value="owner" placeholder="Masukkan username owner" required>
            </div>

            <div class="input-group">
                <label for="password">Password</label>
                <input id="password" type="password" name="password" placeholder="Masukkan password" required>
            </div>

            <button type="submit">Masuk ke Dashboard</button>
        </form>
    </div>
</body>
</html>
