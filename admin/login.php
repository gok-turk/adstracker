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
  <script>
    (function() {
      var savedTheme = localStorage.getItem('adstracker_theme');
      if (savedTheme === 'light') {
        document.documentElement.setAttribute('data-theme', 'light');
      } else {
        document.documentElement.setAttribute('data-theme', 'dark');
      }
    })();
  </script>
  <style>
    :root {
      --bg-dark: #090e1a;
      --bg-card: #131b2e;
      --border-color: #23304a;
      --text-main: #f8fafc;
      --text-muted: #94a3b8;
      --text-label: #cbd5e1;
      --input-bg: #090e1a;
      --input-border: #334155;
      --input-text: #ffffff;
      --accent-gold: #f59e0b;
      --accent-gold-hover: #d97706;
      --shadow: 0 20px 40px rgba(0,0,0,0.4);
      --footer-border: #1c273e;
      --btn-sec-bg: #1e293b;
      --btn-sec-color: #cbd5e1;
      --btn-sec-border: #334155;
      --btn-sec-hover: #334155;
    }
    [data-theme="light"] {
      --bg-dark: #f1f5f9;
      --bg-card: #ffffff;
      --border-color: #e2e8f0;
      --text-main: #0f172a;
      --text-muted: #64748b;
      --text-label: #334155;
      --input-bg: #ffffff;
      --input-border: #cbd5e1;
      --input-text: #0f172a;
      --shadow: 0 16px 40px rgba(15,23,42,0.08);
      --footer-border: #f1f5f9;
      --btn-sec-bg: #ffffff;
      --btn-sec-color: #334155;
      --btn-sec-border: #cbd5e1;
      --btn-sec-hover: #f1f5f9;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      background: var(--bg-dark);
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      color: var(--text-main);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
      position: relative;
      transition: background-color 0.2s ease, color 0.2s ease;
    }
    .theme-corner {
      position: fixed;
      top: 20px;
      right: 20px;
      z-index: 10;
    }
    .theme-toggle-btn {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      background: var(--btn-sec-bg);
      color: var(--btn-sec-color);
      border: 1px solid var(--btn-sec-border);
      padding: 7px 12px;
      border-radius: 6px;
      font-size: 0.84rem;
      font-weight: 700;
      cursor: pointer;
      box-shadow: 0 4px 12px rgba(0,0,0,0.1);
      transition: all 0.2s ease;
      user-select: none;
    }
    .theme-toggle-btn:hover {
      background: var(--btn-sec-hover);
      color: var(--text-main);
      border-color: var(--accent-gold);
    }
    .theme-toggle-btn .theme-icon {
      font-size: 1.05rem;
      line-height: 1;
      display: inline-block;
      transition: transform 0.25s ease;
    }
    .theme-toggle-btn:hover .theme-icon {
      transform: rotate(20deg) scale(1.15);
    }
    .login-card {
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      border-radius: 12px;
      padding: 40px;
      width: 100%;
      max-width: 440px;
      box-shadow: var(--shadow);
      transition: background-color 0.2s ease, border-color 0.2s ease;
    }
    .brand-header {
      text-align: center;
      margin-bottom: 28px;
    }
    .brand-header svg {
      color: var(--accent-gold);
      margin-bottom: 12px;
    }
    .brand-title {
      font-size: 1.45rem;
      font-weight: 800;
      color: var(--text-main);
    }
    .brand-title span { color: var(--accent-gold); }
    .brand-sub {
      font-size: 0.85rem;
      color: var(--text-muted);
      margin-top: 4px;
    }
    .form-group {
      margin-bottom: 18px;
    }
    label {
      display: block;
      font-size: 0.82rem;
      font-weight: 700;
      color: var(--text-label);
      margin-bottom: 6px;
    }
    input {
      width: 100%;
      padding: 12px 14px;
      background: var(--input-bg);
      border: 1px solid var(--input-border);
      border-radius: 8px;
      color: var(--input-text);
      font-size: 0.92rem;
      outline: none;
      transition: border 0.2s;
    }
    input:focus {
      border-color: var(--accent-gold);
    }
    .btn-submit {
      width: 100%;
      padding: 13px;
      background: var(--accent-gold);
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
      background: var(--accent-gold-hover);
    }
    .error-msg {
      background: rgba(239, 68, 68, 0.15);
      border: 1px solid rgba(239, 68, 68, 0.4);
      color: #ef4444;
      padding: 10px 14px;
      border-radius: 6px;
      font-size: 0.85rem;
      margin-bottom: 18px;
      text-align: center;
    }
    .login-footer {
      margin-top: 24px;
      padding-top: 18px;
      border-top: 1px solid var(--footer-border);
      text-align: center;
      font-size: 0.78rem;
      color: var(--text-muted);
    }
  </style>
</head>
<body>

  <div class="theme-corner">
    <button type="button" id="themeToggleBtn" class="theme-toggle-btn" onclick="toggleTheme()" title="Aydınlık / Karanlık Tema">
      <span class="theme-icon" id="themeToggleIcon">🌙</span>
      <span class="theme-label" id="themeToggleLabel">Koyu</span>
    </button>
  </div>

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

  <script>
    function applyTheme(theme) {
      document.documentElement.setAttribute('data-theme', theme);
      localStorage.setItem('adstracker_theme', theme);
      const icon = document.getElementById('themeToggleIcon');
      const label = document.getElementById('themeToggleLabel');
      const btn = document.getElementById('themeToggleBtn');
      if (theme === 'light') {
        if (icon) icon.textContent = '☀️';
        if (label) label.textContent = 'Açık';
        if (btn) btn.setAttribute('title', 'Koyu temaya geç');
      } else {
        if (icon) icon.textContent = '🌙';
        if (label) label.textContent = 'Koyu';
        if (btn) btn.setAttribute('title', 'Açık temaya geç');
      }
    }

    function toggleTheme() {
      const cur = document.documentElement.getAttribute('data-theme') || 'dark';
      const target = cur === 'dark' ? 'light' : 'dark';
      applyTheme(target);
    }

    document.addEventListener('DOMContentLoaded', function() {
      const saved = localStorage.getItem('adstracker_theme') || 'dark';
      applyTheme(saved);
    });
  </script>
</body>
</html>
