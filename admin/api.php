<?php
// Multi-Site AdsTracker Admin API Handler

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/inc/db.php';
require_once dirname(__DIR__) . '/inc/functions.php';

requireLogin();

$db = getTrackerDB();
if (!$db) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Veritabanı bağlantı hatası.']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

// =========================================================================
// 1. ADD NEW SITE
// =========================================================================
if ($action === 'add_site') {
    header('Content-Type: application/json; charset=utf-8');
    $name = trim($_POST['name'] ?? '');
    $domain = trim($_POST['domain'] ?? '');
    $threshold = (int)($_POST['click_threshold'] ?? 3);
    $autoBlock = !empty($_POST['auto_block']) ? 1 : 0;

    if (empty($name) || empty($domain)) {
        echo json_encode(['success' => false, 'error' => 'Lütfen site adı ve domain alanlarını doldurun.']);
        exit;
    }

    // Clean domain
    $domain = preg_replace('#^https?://#i', '', $domain);
    $domain = rtrim($domain, '/');

    // Generate unique site key
    $cleanPrefix = preg_replace('/[^a-z0-9]/i', '', strtolower($name));
    if (strlen($cleanPrefix) > 10) $cleanPrefix = substr($cleanPrefix, 0, 10);
    if (empty($cleanPrefix)) $cleanPrefix = 'site';
    $siteKey = 'cs_' . $cleanPrefix . '_' . bin2hex(random_bytes(6));

    if ($threshold < 1) $threshold = 3;

    try {
        $stmt = $db->prepare("INSERT INTO sites (site_key, name, domain, click_threshold, auto_block, is_active, created_at) VALUES (:k, :n, :d, :ct, :ab, 1, DATETIME('now'))");
        $stmt->execute([
            ':k' => $siteKey,
            ':n' => $name,
            ':d' => $domain,
            ':ct' => $threshold,
            ':ab' => $autoBlock
        ]);

        $newId = $db->lastInsertId();
        echo json_encode([
            'success' => true,
            'message' => 'Site başarıyla eklendi!',
            'site_id' => $newId,
            'site_key' => $siteKey
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Site eklenirken hata: ' . $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// 2. UPDATE SITE
// =========================================================================
if ($action === 'update_site') {
    header('Content-Type: application/json; charset=utf-8');
    $siteId = (int)($_POST['site_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $domain = trim($_POST['domain'] ?? '');
    $threshold = (int)($_POST['click_threshold'] ?? 3);
    $autoBlock = !empty($_POST['auto_block']) ? 1 : 0;
    $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

    if ($siteId <= 0 || empty($name) || empty($domain)) {
        echo json_encode(['success' => false, 'error' => 'Geçersiz parametreler.']);
        exit;
    }

    $domain = preg_replace('#^https?://#i', '', $domain);
    $domain = rtrim($domain, '/');
    if ($threshold < 1) $threshold = 3;

    try {
        $stmt = $db->prepare("UPDATE sites SET name = :n, domain = :d, click_threshold = :ct, auto_block = :ab, is_active = :act WHERE id = :id");
        $stmt->execute([
            ':n' => $name,
            ':d' => $domain,
            ':ct' => $threshold,
            ':ab' => $autoBlock,
            ':act' => $isActive,
            ':id' => $siteId
        ]);
        echo json_encode(['success' => true, 'message' => 'Site ayarları güncellendi.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// 3. DELETE SITE
// =========================================================================
if ($action === 'delete_site') {
    header('Content-Type: application/json; charset=utf-8');
    $siteId = (int)($_POST['site_id'] ?? 0);
    if ($siteId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Geçersiz site ID.']);
        exit;
    }

    try {
        $db->prepare("DELETE FROM visits WHERE site_id = :id")->execute([':id' => $siteId]);
        $db->prepare("DELETE FROM ip_stats WHERE site_id = :id")->execute([':id' => $siteId]);
        $db->prepare("DELETE FROM sites WHERE id = :id")->execute([':id' => $siteId]);
        echo json_encode(['success' => true, 'message' => 'Site ve tüm verileri silindi.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Silme hatası: ' . $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// 4. GET SITE INTEGRATION CODE
// =========================================================================
if ($action === 'get_site_code') {
    header('Content-Type: application/json; charset=utf-8');
    $siteId = (int)($_GET['site_id'] ?? 0);
    $stmt = $db->prepare("SELECT * FROM sites WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $siteId]);
    $site = $stmt->fetch();

    if (!$site) {
        echo json_encode(['success' => false, 'error' => 'Site bulunamadı.']);
        exit;
    }

    // Determine current host URL for integration
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $baseUrl = $scheme . '://' . $host . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');

    $jsUrl = $baseUrl . '/api/tracker.js';
    $apiUrl = $baseUrl . '/api/collect.php';
    $siteKey = $site['site_key'];

    // 1. JavaScript Snippet
    $jsSnippet = '<!-- AdsTracker Pro Koruma Kodu -->\n' .
                 '<script src="' . $jsUrl . '" data-site-key="' . $siteKey . '" async></script>';

    // 2. PHP Shield Client
    $phpCode = '<?php\n' .
               '// Hedef sitenizin index.php veya wp-config.php dosyasının EN BAŞINA ekleyin:\n' .
               'require_once __DIR__ . \'/shield-client.php\';\n';

    // 3. Pre-configured shield-client.php content
    $clientFileContent = file_get_contents(dirname(__DIR__) . '/api/shield-client.php');
    $clientFileContent = str_replace(
        ['http://localhost/adstracker/api/collect.php', 'BURAYA_SITE_ANAHTARINI_YAZIN'],
        [$apiUrl, $siteKey],
        $clientFileContent
    );

    echo json_encode([
        'success' => true,
        'site_name' => $site['name'],
        'domain' => $site['domain'],
        'site_key' => $siteKey,
        'js_snippet' => $jsSnippet,
        'php_include' => $phpCode,
        'client_file_content' => $clientFileContent,
        'download_url' => 'api.php?action=download_client&site_id=' . $siteId
    ]);
    exit;
}

// =========================================================================
// 5. DOWNLOAD CONFIGURED SHIELD CLIENT FILE
// =========================================================================
if ($action === 'download_client') {
    $siteId = (int)($_GET['site_id'] ?? 0);
    $stmt = $db->prepare("SELECT * FROM sites WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $siteId]);
    $site = $stmt->fetch();

    if (!$site) die('Site bulunamadı.');

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $baseUrl = $scheme . '://' . $host . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
    $apiUrl = $baseUrl . '/api/collect.php';
    $siteKey = $site['site_key'];

    $content = file_get_contents(dirname(__DIR__) . '/api/shield-client.php');
    $content = str_replace(
        ['http://localhost/adstracker/api/collect.php', 'BURAYA_SITE_ANAHTARINI_YAZIN'],
        [$apiUrl, $siteKey],
        $content
    );

    header('Content-Type: application/x-httpd-php');
    header('Content-Disposition: attachment; filename=shield-client.php');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}

// =========================================================================
// 6. TOGGLE IP BLOCK
// =========================================================================
if ($action === 'toggle_block') {
    header('Content-Type: application/json; charset=utf-8');
    $ip = trim($_POST['ip'] ?? '');
    $siteId = (int)($_POST['site_id'] ?? 0);

    if (empty($ip)) {
        echo json_encode(['success' => false, 'error' => 'Geçersiz IP.']);
        exit;
    }

    if ($siteId > 0) {
        $stmt = $db->prepare("SELECT is_blocked FROM ip_stats WHERE site_id = :sid AND ip = :ip LIMIT 1");
        $stmt->execute([':sid' => $siteId, ':ip' => $ip]);
        $curr = $stmt->fetchColumn();
        $new = ($curr == 1) ? 0 : 1;

        $up = $db->prepare("UPDATE ip_stats SET is_blocked = :b WHERE site_id = :sid AND ip = :ip");
        $up->execute([':b' => $new, ':sid' => $siteId, ':ip' => $ip]);
    } else {
        // Toggle on all sites
        $stmt = $db->prepare("SELECT MAX(is_blocked) FROM ip_stats WHERE ip = :ip");
        $stmt->execute([':ip' => $ip]);
        $curr = $stmt->fetchColumn();
        $new = ($curr == 1) ? 0 : 1;

        $up = $db->prepare("UPDATE ip_stats SET is_blocked = :b WHERE ip = :ip");
        $up->execute([':b' => $new, ':ip' => $ip]);
    }

    echo json_encode(['success' => true, 'is_blocked' => $new]);
    exit;
}

// =========================================================================
// 7. GET IP DETAILED HIT HISTORY
// =========================================================================
if ($action === 'get_ip_history') {
    header('Content-Type: application/json; charset=utf-8');
    $ip = trim($_GET['ip'] ?? '');
    $siteId = (int)($_GET['site_id'] ?? 0);

    if (empty($ip)) {
        echo json_encode(['success' => false, 'error' => 'Geçersiz IP.']);
        exit;
    }

    $where = " WHERE v.ip = :ip";
    $params = [':ip' => $ip];
    if ($siteId > 0) {
        $where .= " AND v.site_id = :sid";
        $params[':sid'] = $siteId;
    }

    $sql = "SELECT v.*, s.name as site_name, s.domain 
            FROM visits v 
            LEFT JOIN sites s ON v.site_id = s.id 
            {$where} 
            ORDER BY v.id DESC LIMIT 100";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $history = $stmt->fetchAll();

    echo json_encode(['success' => true, 'ip' => $ip, 'history' => $history]);
    exit;
}

// =========================================================================
// 8. DELETE IP RECORDS
// =========================================================================
if ($action === 'delete_ip') {
    header('Content-Type: application/json; charset=utf-8');
    $ip = trim($_POST['ip'] ?? '');
    $siteId = (int)($_POST['site_id'] ?? 0);

    if (empty($ip)) {
        echo json_encode(['success' => false, 'error' => 'Geçersiz IP.']);
        exit;
    }

    if ($siteId > 0) {
        $db->prepare("DELETE FROM visits WHERE site_id = :sid AND ip = :ip")->execute([':sid' => $siteId, ':ip' => $ip]);
        $db->prepare("DELETE FROM ip_stats WHERE site_id = :sid AND ip = :ip")->execute([':sid' => $siteId, ':ip' => $ip]);
    } else {
        $db->prepare("DELETE FROM visits WHERE ip = :ip")->execute([':ip' => $ip]);
        $db->prepare("DELETE FROM ip_stats WHERE ip = :ip")->execute([':ip' => $ip]);
    }

    echo json_encode(['success' => true, 'message' => $ip . ' kaydı silindi.']);
    exit;
}

// =========================================================================
// 9. EXPORT DAILY IP SUMMARY CSV
// =========================================================================
if ($action === 'export_daily_ips') {
    $siteId = (int)($_GET['site_id'] ?? 0);
    $day = $_GET['day'] ?? 'today';

    if ($day === 'yesterday') {
        $daySql = "DATE(v.created_at) = DATE('now', '-1 day')";
        $filenameDate = date('Y-m-d', strtotime('-1 day'));
    } elseif ($day === 'last7') {
        $daySql = "v.created_at >= DATETIME('now', '-7 days')";
        $filenameDate = 'son7gun_' . date('Y-m-d');
    } else {
        $day = 'today';
        $daySql = "DATE(v.created_at) = DATE('now')";
        $filenameDate = date('Y-m-d');
    }

    $siteWhere = $siteId > 0 ? " AND v.site_id = " . $siteId : "";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=gunluk_ip_listesi_' . $filenameDate . '.csv');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

    fputcsv($output, ['Site Adı', 'Domain', 'IP Adresi', 'Dönem Hit', 'Google Ads Tıklaması', 'İlk Ziyaret', 'Son Ziyaret', 'Cihaz', 'Tarayıcı', 'İşletim Sistemi', 'Durum', 'Tüm Zamanlar Toplam Hit', 'Tüm Zamanlar Ads']);

    $sql = "SELECT 
        s.name as site_name, s.domain,
        v.ip,
        COUNT(v.id) as period_hits,
        SUM(CASE WHEN v.is_google_ads = 1 THEN 1 ELSE 0 END) as period_ads_clicks,
        MIN(v.created_at) as first_hit,
        MAX(v.created_at) as last_hit,
        MAX(v.device_name) as device_name,
        MAX(v.browser) as browser,
        MAX(v.os) as os,
        COALESCE(st.is_suspicious, 0) as is_suspicious,
        COALESCE(st.is_blocked, 0) as is_blocked,
        COALESCE(st.total_visits, COUNT(v.id)) as all_time_visits,
        COALESCE(st.ads_clicks, SUM(CASE WHEN v.is_google_ads = 1 THEN 1 ELSE 0 END)) as all_time_ads
    FROM visits v
    LEFT JOIN sites s ON v.site_id = s.id
    LEFT JOIN ip_stats st ON (v.site_id = st.site_id AND v.ip = st.ip)
    WHERE {$daySql} {$siteWhere}
    GROUP BY v.site_id, v.ip
    ORDER BY period_ads_clicks DESC, period_hits DESC, last_hit DESC";

    $stmt = $db->query($sql);
    while ($row = $stmt->fetch()) {
        $status = 'Normal';
        if ($row['is_blocked']) $status = 'Engellendi';
        elseif ($row['is_suspicious']) $status = 'Şüpheli / Sahte';

        fputcsv($output, [
            $row['site_name'],
            $row['domain'],
            $row['ip'],
            $row['period_hits'],
            $row['period_ads_clicks'],
            $row['first_hit'],
            $row['last_hit'],
            $row['device_name'],
            $row['browser'],
            $row['os'],
            $status,
            $row['all_time_visits'],
            $row['all_time_ads']
        ]);
    }
    fclose($output);
    exit;
}

// =========================================================================
// 10. EXPORT DETAILED HIT HISTORY CSV
// =========================================================================
if ($action === 'export_history_csv') {
    $siteId = (int)($_GET['site_id'] ?? 0);
    $source = $_GET['source'] ?? 'all';
    $device = $_GET['device'] ?? 'all';
    $dateFilter = $_GET['date_filter'] ?? 'all';
    $q = trim($_GET['q'] ?? '');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=tiklama_gecmisi_' . date('Y-m-d_H-i') . '.csv');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, ['ID', 'Site Adı', 'Domain', 'Tarih & Saat', 'IP Adresi', 'Trafik Türü', 'Google gclid', 'Cihaz', 'Tarayıcı', 'İşletim Sistemi', 'Referer', 'Sayfa URL']);

    $where = " WHERE 1=1";
    $params = [];

    if ($siteId > 0) {
        $where .= " AND v.site_id = :sid";
        $params[':sid'] = $siteId;
    }
    if ($source === 'ads') {
        $where .= " AND v.is_google_ads = 1";
    } elseif ($source === 'organic') {
        $where .= " AND v.is_google_ads = 0";
    }
    if ($device !== 'all' && in_array($device, ['mobile', 'desktop', 'tablet', 'bot'])) {
        $where .= " AND v.device_type = :dev";
        $params[':dev'] = $device;
    }
    if ($dateFilter === 'today') {
        $where .= " AND DATE(v.created_at) = DATE('now')";
    } elseif ($dateFilter === 'yesterday') {
        $where .= " AND DATE(v.created_at) = DATE('now', '-1 day')";
    } elseif ($dateFilter === 'last7') {
        $where .= " AND v.created_at >= DATETIME('now', '-7 days')";
    } elseif ($dateFilter === 'last30') {
        $where .= " AND v.created_at >= DATETIME('now', '-30 days')";
    }
    if ($q !== '') {
        $where .= " AND (v.ip LIKE :q OR v.gclid LIKE :q OR v.device_name LIKE :q OR v.page_url LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }

    $sql = "SELECT v.*, s.name as site_name, s.domain 
            FROM visits v 
            LEFT JOIN sites s ON v.site_id = s.id 
            {$where} 
            ORDER BY v.id DESC LIMIT 5000";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    while ($r = $stmt->fetch()) {
        fputcsv($output, [
            $r['id'],
            $r['site_name'],
            $r['domain'],
            $r['created_at'],
            $r['ip'],
            $r['is_google_ads'] ? 'Google Ads' : 'Organik',
            $r['gclid'],
            $r['device_name'],
            $r['browser'],
            $r['os'],
            $r['referrer'],
            $r['page_url']
        ]);
    }
    fclose($output);
    exit;
}

// =========================================================================
// 11. RESET STATISTICS
// =========================================================================
if ($action === 'reset_stats') {
    header('Content-Type: application/json; charset=utf-8');
    $mode = $_POST['mode'] ?? 'all';
    $siteId = (int)($_POST['site_id'] ?? 0);

    $siteWhere = $siteId > 0 ? "WHERE site_id = " . $siteId : "";

    if ($mode === 'all') {
        if ($siteId > 0) {
            $db->exec("DELETE FROM visits WHERE site_id = {$siteId};");
            $db->exec("DELETE FROM ip_stats WHERE site_id = {$siteId};");
        } else {
            $db->exec("DELETE FROM visits;");
            $db->exec("DELETE FROM ip_stats;");
        }
        $db->exec("VACUUM;");
        echo json_encode(['success' => true, 'message' => 'Seçilen istatistikler ve loglar tamamen sıfırlandı.']);
        exit;
    }

    if ($mode === 'today') {
        $w = $siteId > 0 ? " AND site_id = {$siteId}" : "";
        $db->exec("DELETE FROM visits WHERE DATE(created_at) = DATE('now') {$w};");
        rebuildSiteStats($db, $siteId ?: null);
        echo json_encode(['success' => true, 'message' => 'Bugünkü loglar silindi ve istatistikler güncellendi.']);
        exit;
    }

    if ($mode === 'older_30') {
        $w = $siteId > 0 ? " AND site_id = {$siteId}" : "";
        $db->exec("DELETE FROM visits WHERE created_at < DATETIME('now', '-30 days') {$w};");
        rebuildSiteStats($db, $siteId ?: null);
        echo json_encode(['success' => true, 'message' => '30 günden eski loglar temizlendi.']);
        exit;
    }

    if ($mode === 'organic_only') {
        $w = $siteId > 0 ? " AND site_id = {$siteId}" : "";
        $db->exec("DELETE FROM visits WHERE is_google_ads = 0 {$w};");
        rebuildSiteStats($db, $siteId ?: null);
        echo json_encode(['success' => true, 'message' => 'Yalnızca organik hitler silindi, Google Ads verileri korundu.']);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Geçersiz sıfırlama modu.']);
    exit;
}

// =========================================================================
// 12. UPDATE PROFILE (USERNAME & PASSWORD)
// =========================================================================
if ($action === 'change_password' || $action === 'update_profile') {
    header('Content-Type: application/json; charset=utf-8');
    $userId = $_SESSION['adstracker_user_id'] ?? 1;
    $newUsername = trim($_POST['username'] ?? '');
    $oldPass = $_POST['old_password'] ?? '';
    $newPass = $_POST['new_password'] ?? '';

    // Verify current password
    $stmt = $db->prepare("SELECT * FROM admin_users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $userId]);
    $currUser = $stmt->fetch();

    if (!$currUser || !password_verify($oldPass, $currUser['password_hash'])) {
        echo json_encode(['success' => false, 'error' => 'Mevcut şifrenizi yanlış girdiniz.']);
        exit;
    }

    $updates = [];
    $params = [':id' => $userId];

    // Username update
    if (!empty($newUsername) && $newUsername !== $currUser['username']) {
        if (strlen($newUsername) < 3) {
            echo json_encode(['success' => false, 'error' => 'Kullanıcı adı en az 3 karakter olmalıdır.']);
            exit;
        }
        $chk = $db->prepare("SELECT COUNT(*) FROM admin_users WHERE username = :u AND id != :id");
        $chk->execute([':u' => $newUsername, ':id' => $userId]);
        if ($chk->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'error' => 'Bu kullanıcı adı zaten başka bir hesap tarafından kullanılıyor.']);
            exit;
        }
        $updates[] = "username = :uname";
        $params[':uname'] = $newUsername;
        $_SESSION['adstracker_username'] = $newUsername;
    }

    // Password update (if entered)
    if (!empty($newPass)) {
        if (strlen($newPass) < 4) {
            echo json_encode(['success' => false, 'error' => 'Yeni şifre en az 4 karakter olmalıdır.']);
            exit;
        }
        $newHash = password_hash($newPass, PASSWORD_DEFAULT);
        $updates[] = "password_hash = :phash";
        $params[':phash'] = $newHash;
    }

    if (empty($updates)) {
        echo json_encode(['success' => false, 'error' => 'Herhangi bir değişiklik yapmadınız.']);
        exit;
    }

    $sql = "UPDATE admin_users SET " . implode(', ', $updates) . " WHERE id = :id";
    $up = $db->prepare($sql);
    $up->execute($params);

    echo json_encode(['success' => true, 'message' => 'Yönetici kullanıcı adı ve şifreniz başarıyla güncellendi!']);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Geçersiz işlem isteği.']);
