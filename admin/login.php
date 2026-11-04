<?php
require_once __DIR__ . '/auth.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($username) && !empty($password)) {
        if (attemptLogin($username, $password)) {
            header('Location: index.php');
            exit;
        } else {
            $error = 'Kullanıcı adı veya şifre hatalı!';
        }
    } else {
        $error = 'Lütfen tüm alanları doldurun.';
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Giriş Yap | AdsTracker Pro Çoklu Site Güvenlik Paneli</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      background: #090e1a;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      color: #f8fafc;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    .login-card {
      background: #131b2e;
      border: 1px solid #23304a;
      border-radius: 12px;
      padding: 40px;
      width: 100%;
      max-width: 440px;
      box-shadow: 0 20px 40px rgba(0,0,0,0.4);
    }
    .brand-header {
      text-align: center;
      margin-bottom: 28px;
    }
    .brand-header svg {
      color: #f59e0b;
      margin-bottom: 12px;
    }
    .brand-title {
      font-size: 1.45rem;
      font-weight: 800;
      color: #ffffff;
    }
    .brand-title span { color: #f59e0b; }
    .brand-sub {
      font-size: 0.85rem;
      color: #94a3b8;
      margin-top: 4px;
    }
    .form-group {
      margin-bottom: 18px;
    }
    label {
      display: block;
      font-size: 0.82rem;
      font-weight: 700;
      color: #cbd5e1;
      margin-bottom: 6px;
    }
    input {
      width: 100%;
      padding: 12px 14px;
      background: #090e1a;
      border: 1px solid #334155;
      border-radius: 8px;
      color: #ffffff;
      font-size: 0.92rem;
      outline: none;
      transition: border 0.2s;
    }
    input:focus {
      border-color: #f59e0b;
    }
    .btn-submit {
      width: 100%;
      padding: 13px;
      background: #f59e0b;
      color: #0f172a;
      border: none;
      border-radius: 8px;
      font-size: 0.96rem;
      font-weight: 800;
      cursor: pointer;
      margin-top: 8px;
      transition: background 0.2s;
    }
    .btn-submit:hover {
      background: #d97706;
    }
    .error-msg {
      background: rgba(239, 68, 68, 0.15);
      border: 1px solid rgba(239, 68, 68, 0.4);
      color: #fca5a5;
      padding: 10px 14px;
      border-radius: 6px;
      font-size: 0.85rem;
      margin-bottom: 18px;
      text-align: center;
    }
    .login-footer {
      margin-top: 24px;
      padding-top: 18px;
      border-top: 1px solid #1c273e;
      text-align: center;
      font-size: 0.78rem;
      color: #64748b;
    }
  </style>
</head>
<body>

  <div class="login-card">
    <div class="brand-header">
      <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><circle cx="12" cy="11" r="3"/></svg>
      <div class="brand-title"><span>AdsTracker</span> Pro</div>
      <div class="brand-sub">Çoklu Domain Google Ads Sahte Tıklama &amp; IP Paneli</div>
    </div>

    <?php if ($error): ?>
      <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="form-group">
        <label>Kullanıcı Adı</label>
        <input type="text" name="username" value="admin" required autofocus>
      </div>

      <div class="form-group">
        <label>Yönetici Şifresi</label>
        <input type="password" name="password" placeholder="••••••••" required>
      </div>

      <button type="submit" class="btn-submit">Yönetim Paneline Giriş Yap</button>
    </form>

    <div class="login-footer">
      Varsayılan Giriş: <strong>admin</strong> / <strong>admin</strong>
    </div>
  </div>

</body>
</html>
