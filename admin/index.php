<?php
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/inc/db.php';
require_once dirname(__DIR__) . '/inc/functions.php';

requireLogin();

$db = getTrackerDB();
if (!$db) {
    die("Veritabanı bağlantısı kurulamadı.");
}

// Active navigation view
$view = $_GET['view'] ?? 'dashboard';
if (!in_array($view, ['dashboard', 'sites', 'history', 'ips', 'settings'])) {
    $view = 'dashboard';
}

// Fetch all registered sites
$sitesList = $db->query("SELECT * FROM sites ORDER BY name ASC")->fetchAll();
$allSitesCount = count($sitesList);

// Selected Site Filter (0 = All Sites)
$selectedSiteId = (int)($_GET['site_id'] ?? 0);
$selectedSite = null;
if ($selectedSiteId > 0) {
    foreach ($sitesList as $s) {
        if ((int)$s['id'] === $selectedSiteId) {
            $selectedSite = $s;
            break;
        }
    }
    if (!$selectedSite) {
        $selectedSiteId = 0; // fallback to all
    }
}

// Site SQL conditions
$siteWhereVisits = $selectedSiteId > 0 ? " WHERE site_id = {$selectedSiteId}" : "";
$siteWhereVisitsAnd = $selectedSiteId > 0 ? " AND site_id = {$selectedSiteId}" : "";
$siteWhereStats = $selectedSiteId > 0 ? " WHERE site_id = {$selectedSiteId}" : "";
$siteWhereStatsAnd = $selectedSiteId > 0 ? " AND site_id = {$selectedSiteId}" : "";

// Table-prefixed conditions for JOIN queries
$vSiteAnd = $selectedSiteId > 0 ? " AND v.site_id = {$selectedSiteId}" : "";
$stSiteWhere = $selectedSiteId > 0 ? " WHERE st.site_id = {$selectedSiteId}" : "";
$stSiteAnd = $selectedSiteId > 0 ? " AND st.site_id = {$selectedSiteId}" : "";
$sSiteAnd = $selectedSiteId > 0 ? " AND s.site_id = {$selectedSiteId}" : "";

// Global KPIs (Filtered by selected site)
$totalVisits = $db->query("SELECT COUNT(*) FROM visits" . $siteWhereVisits)->fetchColumn() ?: 0;
$totalAdsClicks = $db->query("SELECT COUNT(*) FROM visits WHERE is_google_ads = 1" . $siteWhereVisitsAnd)->fetchColumn() ?: 0;
$todayAdsClicks = $db->query("SELECT COUNT(*) FROM visits WHERE is_google_ads = 1 AND DATE(created_at) = DATE('now')" . $siteWhereVisitsAnd)->fetchColumn() ?: 0;
$todayTotalVisits = $db->query("SELECT COUNT(*) FROM visits WHERE DATE(created_at) = DATE('now')" . $siteWhereVisitsAnd)->fetchColumn() ?: 0;
$suspiciousCount = $db->query("SELECT COUNT(DISTINCT ip) FROM ip_stats WHERE is_suspicious = 1" . $siteWhereStatsAnd)->fetchColumn() ?: 0;
$blockedCount = $db->query("SELECT COUNT(DISTINCT ip) FROM ip_stats WHERE is_blocked = 1" . $siteWhereStatsAnd)->fetchColumn() ?: 0;

// Google Ads Exclusion IPs for selected site
$exclusionStmt = $db->query("SELECT DISTINCT ip FROM ip_stats WHERE (is_suspicious = 1 OR is_blocked = 1)" . $siteWhereStatsAnd . " ORDER BY ads_clicks DESC");
$exclusionIPs = $exclusionStmt ? $exclusionStmt->fetchAll(PDO::FETCH_COLUMN) : [];
$exclusionText = implode("\n", $exclusionIPs);

// =========================================================================
// VIEW 1: DASHBOARD DATA
// =========================================================================
$dailyStats = [];
$deviceBreakdown = ['mobile' => 0, 'desktop' => 0, 'tablet' => 0, 'bot' => 0];
$topSuspiciousIPs = [];
$dailyIPs = [];
$dashDay = $_GET['day'] ?? 'today';
if ($dashDay === 'yesterday') {
    $dashDaySql = "DATE(v.created_at) = DATE('now', '-1 day')";
    $dashDayTitle = "Dünkü";
} elseif ($dashDay === 'last7') {
    $dashDaySql = "v.created_at >= DATETIME('now', '-7 days')";
    $dashDayTitle = "Son 7 Günlük";
} else {
    $dashDay = 'today';
    $dashDaySql = "DATE(v.created_at) = DATE('now')";
    $dashDayTitle = "Bugünkü";
}

if ($view === 'dashboard') {
    // 14-day history chart
    $dailyStmt = $db->query("SELECT 
        DATE(created_at) as visit_date,
        COUNT(*) as total_hits,
        SUM(CASE WHEN is_google_ads = 1 THEN 1 ELSE 0 END) as ads_hits,
        SUM(CASE WHEN is_google_ads = 0 THEN 1 ELSE 0 END) as organic_hits
        FROM visits
        {$siteWhereVisits}
        GROUP BY DATE(created_at)
        ORDER BY visit_date DESC
        LIMIT 14;");
    $dailyStats = $dailyStmt ? $dailyStmt->fetchAll() : [];

    // Device breakdown
    $devStmt = $db->query("SELECT device_type, COUNT(*) as count FROM visits {$siteWhereVisits} GROUP BY device_type");
    if ($devStmt) {
        while ($r = $devStmt->fetch()) {
            if (isset($deviceBreakdown[$r['device_type']])) {
                $deviceBreakdown[$r['device_type']] = (int)$r['count'];
            }
        }
    }

    // Top suspicious IPs
    $topSuspStmt = $db->query("SELECT s.*, st.name as site_name, st.domain 
        FROM ip_stats s 
        LEFT JOIN sites st ON s.site_id = st.id 
        WHERE (s.is_suspicious = 1 OR s.ads_clicks >= 2) {$sSiteAnd}
        ORDER BY s.ads_clicks DESC, s.total_visits DESC 
        LIMIT 6");
    $topSuspiciousIPs = $topSuspStmt ? $topSuspStmt->fetchAll() : [];

    // Daily IP List query
    $dailyIPsStmt = $db->query("SELECT 
        v.ip,
        s.name as site_name,
        s.domain as site_domain,
        COUNT(v.id) as period_hits,
        SUM(CASE WHEN v.is_google_ads = 1 THEN 1 ELSE 0 END) as period_ads_clicks,
        MIN(v.created_at) as first_hit,
        MAX(v.created_at) as last_hit,
        MAX(v.device_type) as device_type,
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
    WHERE {$dashDaySql} {$vSiteAnd}
    GROUP BY v.site_id, v.ip
    ORDER BY period_ads_clicks DESC, period_hits DESC, last_hit DESC");
    $dailyIPs = $dailyIPsStmt ? $dailyIPsStmt->fetchAll() : [];
}

// =========================================================================
// VIEW 2: SITES MANAGEMENT DATA
// =========================================================================
$sitesStats = [];
if ($view === 'sites') {
    $stStmt = $db->query("SELECT s.*,
        (SELECT COUNT(*) FROM visits WHERE site_id = s.id) as total_hits,
        (SELECT COUNT(*) FROM visits WHERE site_id = s.id AND is_google_ads = 1) as total_ads,
        (SELECT COUNT(*) FROM visits WHERE site_id = s.id AND is_google_ads = 1 AND DATE(created_at) = DATE('now')) as today_ads,
        (SELECT COUNT(*) FROM ip_stats WHERE site_id = s.id AND is_suspicious = 1) as susp_ips,
        (SELECT COUNT(*) FROM ip_stats WHERE site_id = s.id AND is_blocked = 1) as blocked_ips
        FROM sites s
        ORDER BY s.id DESC");
    $sitesStats = $stStmt ? $stStmt->fetchAll() : [];
}

// =========================================================================
// VIEW 3: DETAILED HIT HISTORY DATA
// =========================================================================
$historyRows = [];
$historyTotal = 0;
$filterSource = $_GET['source'] ?? 'all';
$filterDevice = $_GET['device'] ?? 'all';
$filterDate = $_GET['date_filter'] ?? 'all';
$filterDateStart = $_GET['date_start'] ?? '';
$filterDateEnd = $_GET['date_end'] ?? '';
$filterQ = trim($_GET['q'] ?? '');
$filterLimit = (int)($_GET['limit'] ?? 50);
if ($filterLimit < 10) $filterLimit = 50;
if ($filterLimit > 500) $filterLimit = 500;

if ($view === 'history') {
    $whereSql = " WHERE 1=1";
    $params = [];

    if ($selectedSiteId > 0) {
        $whereSql .= " AND v.site_id = :sid";
        $params[':sid'] = $selectedSiteId;
    }
    if ($filterSource === 'ads') {
        $whereSql .= " AND v.is_google_ads = 1";
    } elseif ($filterSource === 'organic') {
        $whereSql .= " AND v.is_google_ads = 0";
    }
    if ($filterDevice !== 'all' && in_array($filterDevice, ['mobile', 'desktop', 'tablet', 'bot'])) {
        $whereSql .= " AND v.device_type = :dev";
        $params[':dev'] = $filterDevice;
    }
    if ($filterDate === 'today') {
        $whereSql .= " AND DATE(v.created_at) = DATE('now')";
    } elseif ($filterDate === 'yesterday') {
        $whereSql .= " AND DATE(v.created_at) = DATE('now', '-1 day')";
    } elseif ($filterDate === 'last7') {
        $whereSql .= " AND v.created_at >= DATETIME('now', '-7 days')";
    } elseif ($filterDate === 'last30') {
        $whereSql .= " AND v.created_at >= DATETIME('now', '-30 days')";
    } elseif ($filterDate === 'custom') {
        if ($filterDateStart) {
            $whereSql .= " AND DATE(v.created_at) >= :dstart";
            $params[':dstart'] = $filterDateStart;
        }
        if ($filterDateEnd) {
            $whereSql .= " AND DATE(v.created_at) <= :dend";
            $params[':dend'] = $filterDateEnd;
        }
    }
    if ($filterQ !== '') {
        $whereSql .= " AND (v.ip LIKE :q OR v.gclid LIKE :q OR v.device_name LIKE :q OR v.page_url LIKE :q)";
        $params[':q'] = '%' . $filterQ . '%';
    }

    $cntStmt = $db->prepare("SELECT COUNT(*) FROM visits v" . $whereSql);
    $cntStmt->execute($params);
    $historyTotal = (int)$cntStmt->fetchColumn();

    $dataStmt = $db->prepare("SELECT v.*, s.name as site_name, s.domain 
        FROM visits v 
        LEFT JOIN sites s ON v.site_id = s.id 
        {$whereSql} 
        ORDER BY v.id DESC LIMIT " . $filterLimit);
    $dataStmt->execute($params);
    $historyRows = $dataStmt->fetchAll();
}

// =========================================================================
// VIEW 4: IP MANAGEMENT DATA
// =========================================================================
$ipList = [];
if ($view === 'ips') {
    $ipSql = "SELECT st.*, s.name as site_name, s.domain 
              FROM ip_stats st 
              LEFT JOIN sites s ON st.site_id = s.id 
              {$stSiteWhere} 
              ORDER BY st.is_blocked DESC, st.is_suspicious DESC, st.ads_clicks DESC, st.last_visit DESC";
    $ipList = $db->query($ipSql)->fetchAll() ?: [];
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AdsTracker Pro | Çoklu Domain Google Ads Güvenlik &amp; Sahte Tıklama Paneli</title>
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23f59e0b'><path d='M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z'/></svg>">
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
      --bg-card-alt: #1a243d;
      --header-bg: #0d1424;
      --border-color: #23304a;
      --border-subtle: #1a253a;
      --text-main: #f8fafc;
      --text-muted: #94a3b8;
      --text-label: #cbd5e1;
      --input-bg: #090e1a;
      --input-border: #334155;
      --input-text: #ffffff;
      --table-th-bg: #0d1424;
      --table-border: #1a253a;
      --table-hover: rgba(255, 255, 255, 0.02);
      --stat-bar-bg: #090e1a;
      --site-switcher-bg: #090e1a;
      --exclusion-bg: linear-gradient(135deg, #162035 0%, #0f172a 100%);
      --exclusion-border: rgba(245, 158, 11, 0.4);
      --exclusion-steps-bg: rgba(0,0,0,0.25);
      --exclusion-ip-color: #fbbf24;
      --modal-bg: #141c2e;
      --btn-sec-bg: #1e293b;
      --btn-sec-color: #cbd5e1;
      --btn-sec-border: #334155;
      --btn-sec-hover: #334155;
      --accent-gold: #f59e0b;
      --accent-gold-dark: #d97706;
      --danger-red: #ef4444;
      --warning-orange: #f97316;
      --success-green: #10b981;
      --ip-link: #60a5fa;
      --code-bg: #070c17;
      --code-border: #223049;
      --code-color: #38bdf8;
      --shadow: 0 8px 24px rgba(0,0,0,0.2);
      --radius: 10px;
    }

    [data-theme="light"] {
      --bg-dark: #f1f5f9;
      --bg-card: #ffffff;
      --bg-card-alt: #f8fafc;
      --header-bg: #ffffff;
      --border-color: #e2e8f0;
      --border-subtle: #edf2f7;
      --text-main: #0f172a;
      --text-muted: #64748b;
      --text-label: #334155;
      --input-bg: #ffffff;
      --input-border: #cbd5e1;
      --input-text: #0f172a;
      --table-th-bg: #f8fafc;
      --table-border: #f1f5f9;
      --table-hover: #f8fafc;
      --stat-bar-bg: #e2e8f0;
      --site-switcher-bg: #f8fafc;
      --exclusion-bg: linear-gradient(135deg, #fffbeb 0%, #ffffff 100%);
      --exclusion-border: #fde68a;
      --exclusion-steps-bg: #fef3c7;
      --exclusion-ip-color: #b45309;
      --modal-bg: #ffffff;
      --btn-sec-bg: #f1f5f9;
      --btn-sec-color: #334155;
      --btn-sec-border: #cbd5e1;
      --btn-sec-hover: #e2e8f0;
      --ip-link: #2563eb;
      --code-bg: #0f172a;
      --code-border: #1e293b;
      --code-color: #38bdf8;
      --shadow: 0 4px 16px rgba(15,23,42,0.06);
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background-color: var(--bg-dark);
      color: var(--text-main);
      line-height: 1.5;
      padding-bottom: 60px;
      transition: background-color 0.2s ease, color 0.2s ease;
    }

    a { text-decoration: none; color: inherit; }

    h1, h2, h3, h4, h5, h6 {
      color: var(--text-main) !important;
    }

    /* Header Nav */
    .header-nav {
      background: var(--header-bg);
      border-bottom: 1px solid var(--border-color);
      padding: 12px 24px;
      position: sticky;
      top: 0;
      z-index: 100;
      box-shadow: var(--shadow);
      transition: background-color 0.2s ease, border-color 0.2s ease;
    }

    .header-inner {
      max-width: 1400px;
      margin: 0 auto;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
    }

    .brand-title {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 1.18rem;
      font-weight: 800;
      color: var(--text-main);
    }
    .brand-title span { color: var(--accent-gold); }

    .brand-tag {
      font-size: 0.72rem;
      background: rgba(245, 158, 11, 0.15);
      border: 1px solid rgba(245, 158, 11, 0.3);
      color: #fbbf24;
      padding: 2px 8px;
      border-radius: 4px;
      font-weight: 700;
    }

    /* Site Selector Dropdown */
    .site-switcher {
      display: flex;
      align-items: center;
      gap: 8px;
      background: var(--site-switcher-bg);
      border: 1px solid var(--border-color);
      border-radius: 6px;
      padding: 4px 10px;
    }

    .site-switcher label {
      font-size: 0.76rem;
      font-weight: 700;
      color: var(--accent-gold);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .site-select {
      background: transparent;
      border: none;
      color: var(--text-main);
      font-size: 0.86rem;
      font-weight: 700;
      outline: none;
      cursor: pointer;
    }

    .site-select option {
      background: var(--bg-card);
      color: var(--text-main);
    }

    /* Tabs Navigation Bar */
    .tabs-bar {
      display: flex;
      align-items: center;
      gap: 6px;
      background: var(--site-switcher-bg);
      border: 1px solid var(--border-color);
      padding: 4px;
      border-radius: 8px;
      flex-wrap: wrap;
    }

    .tab-link {
      padding: 7px 14px;
      border-radius: 6px;
      font-size: 0.84rem;
      font-weight: 700;
      color: var(--text-muted);
      transition: all 0.2s;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .tab-link:hover { color: var(--text-main); background: rgba(148, 163, 184, 0.15); }

    .tab-link.active {
      background: var(--accent-gold);
      color: #0f172a;
    }

    /* Theme Toggle Button (Sun & Moon) */
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
    .theme-toggle-btn .theme-label {
      font-size: 0.78rem;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 14px;
      border-radius: 6px;
      font-size: 0.84rem;
      font-weight: 700;
      cursor: pointer;
      border: 1px solid transparent;
      transition: all 0.2s;
    }

    .btn-secondary { background: var(--btn-sec-bg); color: var(--btn-sec-color); border-color: var(--btn-sec-border); }
    .btn-secondary:hover { background: var(--btn-sec-hover); color: var(--text-main); }

    .btn-gold { background: var(--accent-gold); color: #0f172a; }
    .btn-gold:hover { background: #d97706; }

    .btn-danger { background: rgba(239, 68, 68, 0.15); color: #f87171; border-color: rgba(239, 68, 68, 0.3); }
    .btn-danger:hover { background: var(--danger-red); color: #ffffff; }

    .btn-success { background: #10b981; color: #ffffff; }
    .btn-success:hover { background: #059669; }

    .btn-sm {
      padding: 5px 9px;
      font-size: 0.76rem;
      border-radius: 4px;
      cursor: pointer;
      font-weight: 700;
      border: none;
      transition: all 0.15s;
    }

    .btn-inspect { background: #334155; color: #ffffff; }
    .btn-inspect:hover { background: #475569; }

    .btn-block-toggle { background: #b91c1c; color: #ffffff; }
    .btn-block-toggle.active { background: #166534; }

    .btn-code { background: #2563eb; color: #ffffff; }
    .btn-code:hover { background: #1d4ed8; }

    .btn-delete { background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); }
    .btn-delete:hover { background: #ef4444; color: #ffffff; }

    .container {
      max-width: 1400px;
      margin: 24px auto;
      padding: 0 20px;
    }

    /* KPI Cards */
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(1, 1fr);
      gap: 16px;
      margin-bottom: 24px;
    }
    @media (min-width: 600px) { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (min-width: 1024px) { .kpi-grid { grid-template-columns: repeat(5, 1fr); } }

    .kpi-card {
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      border-radius: var(--radius);
      padding: 18px 20px;
      display: flex;
      align-items: center;
      gap: 14px;
      transition: transform 0.2s, border-color 0.2s;
      box-shadow: var(--shadow);
    }
    .kpi-card:hover { transform: translateY(-2px); border-color: var(--accent-gold); }

    .kpi-icon {
      width: 48px;
      height: 48px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.4rem;
      flex-shrink: 0;
    }

    .icon-ads { background: rgba(59, 130, 246, 0.15); color: #60a5fa; }
    .icon-suspicious { background: rgba(249, 115, 22, 0.15); color: #fb923c; }
    .icon-blocked { background: rgba(239, 68, 68, 0.15); color: #f87171; }
    .icon-visits { background: rgba(16, 185, 129, 0.15); color: #34d399; }
    .icon-today { background: rgba(245, 158, 11, 0.15); color: #fbbf24; }

    .kpi-info .val { font-size: 1.6rem; font-weight: 900; line-height: 1.1; color: var(--text-main); }
    .kpi-info .label { font-size: 0.78rem; color: var(--text-muted); font-weight: 600; }

    /* Dashboard 2-column Layout */
    .dash-layout-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 24px;
      margin-bottom: 24px;
    }
    @media (min-width: 992px) { .dash-layout-grid { grid-template-columns: 1.4fr 1fr; } }

    .content-box {
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      border-radius: var(--radius);
      padding: 22px 24px;
      box-shadow: var(--shadow);
    }

    .content-box-title {
      font-size: 1.15rem;
      font-weight: 800;
      color: var(--text-main);
      margin-bottom: 14px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    /* Google Ads Exclusion Tool Box */
    .exclusion-box {
      background: var(--exclusion-bg);
      border: 1px solid var(--exclusion-border);
      border-radius: var(--radius);
      padding: 22px 24px;
      margin-bottom: 24px;
      display: grid;
      grid-template-columns: 1fr;
      gap: 18px;
      box-shadow: var(--shadow);
    }
    @media (min-width: 860px) { .exclusion-box { grid-template-columns: 1.5fr 1fr; } }

    .exclusion-left h3 {
      font-size: 1.15rem;
      font-weight: 800;
      color: var(--text-main);
      margin-bottom: 6px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .exclusion-left p {
      font-size: 0.86rem;
      color: var(--text-muted);
      line-height: 1.5;
      margin-bottom: 12px;
    }

    .exclusion-steps {
      font-size: 0.8rem;
      color: var(--text-main);
      background: var(--exclusion-steps-bg);
      border-radius: 6px;
      padding: 10px 14px;
      line-height: 1.5;
    }
    .exclusion-steps strong { color: var(--accent-gold); }

    .ip-textarea {
      width: 100%;
      height: 90px;
      background: var(--input-bg);
      border: 1px solid var(--input-border);
      border-radius: 6px;
      color: var(--exclusion-ip-color);
      font-family: monospace;
      font-size: 0.85rem;
      padding: 8px 10px;
      resize: none;
      outline: none;
      margin-bottom: 10px;
    }

    .btn-copy {
      background: #10b981;
      color: #ffffff;
      border: none;
      padding: 10px 14px;
      border-radius: 6px;
      font-weight: 800;
      font-size: 0.88rem;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      transition: all 0.2s;
    }
    .btn-copy:hover { background: #059669; }

    /* Tables */
    .data-table-wrapper { overflow-x: auto; }
    .data-table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
      font-size: 0.86rem;
    }
    .data-table th {
      background: var(--table-th-bg);
      color: var(--text-muted);
      font-weight: 700;
      padding: 12px 14px;
      border-bottom: 2px solid var(--border-color);
      white-space: nowrap;
    }
    .data-table td {
      padding: 12px 14px;
      border-bottom: 1px solid var(--table-border);
      vertical-align: middle;
      color: var(--text-main);
    }
    .data-table tr:hover td { background: var(--table-hover); }

    .daily-stats-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    .daily-stats-table th { color: var(--text-muted); font-weight: 700; padding: 8px 10px; border-bottom: 1px solid var(--border-color); text-align: left; }
    .daily-stats-table td { padding: 10px 10px; border-bottom: 1px solid var(--table-border); vertical-align: middle; color: var(--text-main); }

    .stat-bar-container {
      display: flex;
      height: 8px;
      background: var(--stat-bar-bg);
      border-radius: 4px;
      overflow: hidden;
      width: 100%;
      margin-top: 4px;
    }
    .stat-bar-ads { background: #3b82f6; }
    .stat-bar-organic { background: #64748b; }

    .ip-link { font-weight: 800; color: var(--ip-link); font-family: monospace; font-size: 0.92rem; }
    .ip-link:hover { text-decoration: underline; }

    .badge {
      display: inline-block;
      padding: 3px 8px;
      border-radius: 4px;
      font-size: 0.72rem;
      font-weight: 800;
      white-space: nowrap;
    }
    .badge-ads { background: rgba(59, 130, 246, 0.2); color: #93c5fd; border: 1px solid rgba(59, 130, 246, 0.4); }
    .badge-organic { background: rgba(148, 163, 184, 0.15); color: #cbd5e1; }
    .badge-suspicious { background: rgba(249, 115, 22, 0.2); color: #fdba74; border: 1px solid rgba(249, 115, 22, 0.4); }
    .badge-blocked { background: rgba(239, 68, 68, 0.2); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.4); }
    .badge-safe { background: rgba(16, 185, 129, 0.2); color: #86efac; }
    .badge-site { background: rgba(139, 92, 246, 0.2); color: #c4b5fd; border: 1px solid rgba(139, 92, 246, 0.4); }

    .click-counter {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 26px;
      height: 26px;
      border-radius: 50%;
      font-weight: 900;
      font-size: 0.82rem;
    }
    .counter-low { background: rgba(16, 185, 129, 0.2); color: #34d399; }
    .counter-med { background: rgba(249, 115, 22, 0.25); color: #fb923c; }
    .counter-high { background: rgba(239, 68, 68, 0.3); color: #f87171; border: 1px solid #ef4444; }

    .daily-filter-btn {
      padding: 6px 12px;
      font-size: 0.78rem;
      font-weight: 700;
      border-radius: 6px;
      cursor: pointer;
      border: 1px solid var(--btn-sec-border);
      background: var(--btn-sec-bg);
      color: var(--btn-sec-color);
      transition: all 0.2s;
    }
    .daily-filter-btn:hover { background: var(--btn-sec-hover); color: var(--text-main); }
    .daily-filter-btn.active { background: var(--accent-gold); color: #0f172a; border-color: var(--accent-gold); }

    .text-input, .select-input {
      width: 100%;
      padding: 8px 12px;
      background: var(--input-bg);
      border: 1px solid var(--input-border);
      border-radius: 6px;
      color: var(--input-text);
      font-size: 0.84rem;
      outline: none;
    }
    .text-input:focus, .select-input:focus { border-color: var(--accent-gold); }

    /* Modal Styles */
    .modal-overlay {
      display: none;
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(0,0,0,0.75);
      backdrop-filter: blur(4px);
      z-index: 1000;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    .modal-overlay.open { display: flex; }

    .modal-box {
      background: var(--modal-bg);
      border: 1px solid var(--border-color);
      border-radius: var(--radius);
      width: 100%;
      max-width: 720px;
      max-height: 90vh;
      overflow-y: auto;
      padding: 24px;
      box-shadow: 0 20px 50px rgba(0,0,0,0.6);
      color: var(--text-main);
    }

    .modal-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 18px;
      border-bottom: 1px solid var(--border-color);
      padding-bottom: 12px;
    }
    .modal-head h3 { font-size: 1.15rem; color: var(--text-main); font-weight: 800; }
    .close-modal { font-size: 1.4rem; background: none; border: none; color: var(--text-muted); cursor: pointer; }

    .code-box {
      background: var(--code-bg);
      border: 1px solid var(--code-border);
      border-radius: 8px;
      padding: 14px;
      font-family: monospace;
      font-size: 0.82rem;
      color: var(--code-color);
      overflow-x: auto;
      white-space: pre-wrap;
      word-break: break-all;
      margin-bottom: 12px;
    }

    /* Light Theme Specific Enhancements */
    [data-theme="light"] .modal-overlay {
      background: rgba(15, 23, 42, 0.45);
    }
    [data-theme="light"] .brand-tag {
      background: #fef3c7;
      border-color: #fde68a;
      color: #b45309;
    }
    [data-theme="light"] .badge-ads {
      background: #dbeafe;
      color: #1d4ed8;
      border: 1px solid #bfdbfe;
    }
    [data-theme="light"] .badge-organic {
      background: #f1f5f9;
      color: #475569;
      border: 1px solid #cbd5e1;
    }
    [data-theme="light"] .badge-suspicious {
      background: #ffedd5;
      color: #c2410c;
      border: 1px solid #fed7aa;
    }
    [data-theme="light"] .badge-blocked {
      background: #fee2e2;
      color: #b91c1c;
      border: 1px solid #fecaca;
    }
    [data-theme="light"] .badge-safe {
      background: #dcfce7;
      color: #15803d;
      border: 1px solid #bbf7d0;
    }
    [data-theme="light"] .badge-site {
      background: #ede9fe;
      color: #6d28d9;
      border: 1px solid #ddd6fe;
    }
    [data-theme="light"] .counter-low {
      background: #dcfce7;
      color: #15803d;
    }
    [data-theme="light"] .counter-med {
      background: #ffedd5;
      color: #c2410c;
    }
    [data-theme="light"] .counter-high {
      background: #fee2e2;
      color: #b91c1c;
      border: 1px solid #ef4444;
    }
    [data-theme="light"] .stat-bar-organic {
      background: #cbd5e1;
    }
    [data-theme="light"] .btn-inspect {
      background: #e2e8f0;
      color: #334155;
    }
    [data-theme="light"] .btn-inspect:hover {
      background: #cbd5e1;
    }
    [data-theme="light"] label {
      color: #334155 !important;
    }
  </style>
</head>
<body>

  <!-- 1. Header Navigation -->
  <header class="header-nav">
    <div class="header-inner">
      <div class="brand-title">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><circle cx="12" cy="11" r="3"/></svg>
        <span>AdsTracker</span> Pro
        <span class="brand-tag">ÇOKLU DOMAIN</span>
      </div>

      <!-- Active Site Switcher -->
      <div class="site-switcher">
        <label>Site Seçimi:</label>
        <select class="site-select" onchange="switchSite(this.value)">
          <option value="0" <?= $selectedSiteId === 0 ? 'selected' : '' ?>>🌐 Tüm Siteler (Konsolide Özet)</option>
          <?php foreach ($sitesList as $st): ?>
            <option value="<?= $st['id'] ?>" <?= $selectedSiteId === (int)$st['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($st['name']) ?> (<?= htmlspecialchars($st['domain']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Navigation Tabs -->
      <nav class="tabs-bar">
        <a href="index.php?view=dashboard&site_id=<?= $selectedSiteId ?>" class="tab-link <?= $view === 'dashboard' ? 'active' : '' ?>">
          📊 Genel Bakış
        </a>
        <a href="index.php?view=sites&site_id=<?= $selectedSiteId ?>" class="tab-link <?= $view === 'sites' ? 'active' : '' ?>">
          🌐 Sitelerim (<?= $allSitesCount ?>)
        </a>
        <a href="index.php?view=history&site_id=<?= $selectedSiteId ?>" class="tab-link <?= $view === 'history' ? 'active' : '' ?>">
          📋 Tıklama Geçmişi
        </a>
        <a href="index.php?view=ips&site_id=<?= $selectedSiteId ?>" class="tab-link <?= $view === 'ips' ? 'active' : '' ?>">
          🛡️ IP Yönetimi
        </a>
        <a href="index.php?view=settings&site_id=<?= $selectedSiteId ?>" class="tab-link <?= $view === 'settings' ? 'active' : '' ?>">
          ⚙️ Ayarlar
        </a>
      </nav>

      <div style="display: flex; align-items: center; gap: 8px;">
        <!-- Theme Toggle (Moon / Sun) -->
        <button type="button" id="themeToggleBtn" class="theme-toggle-btn" onclick="toggleTheme()" title="Aydınlık / Karanlık Tema">
          <span class="theme-icon" id="themeToggleIcon">🌙</span>
          <span class="theme-label" id="themeToggleLabel">Koyu</span>
        </button>

        <button type="button" class="btn btn-gold" onclick="openModal('addSiteModal')">
          ➕ Yeni Site Ekle
        </button>
        <a href="logout.php" class="btn btn-danger" style="padding: 7px 12px;">Çıkış</a>
      </div>
    </div>
  </header>

  <main class="container">

    <!-- =========================================================================
         VIEW 1: DASHBOARD
         ========================================================================= -->
    <?php if ($view === 'dashboard'): ?>

      <?php if ($selectedSite): ?>
        <div style="margin-bottom: 18px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
          <div>
            <h2 style="font-size: 1.35rem; font-weight: 800; color: #ffffff;">
              <?= htmlspecialchars($selectedSite['name']) ?>
              <span class="badge badge-site" style="font-size: 0.8rem; margin-left: 6px;"><?= htmlspecialchars($selectedSite['domain']) ?></span>
            </h2>
            <p style="font-size: 0.84rem; color: var(--text-muted);">Bu siteye ait özel Google Ads tıklama ve IP güvenlik verilerini inceliyorsunuz.</p>
          </div>
          <button type="button" class="btn btn-code" onclick="showIntegrationCode(<?= $selectedSite['id'] ?>)">
            ⚡ Entegrasyon Kodunu Al
          </button>
        </div>
      <?php endif; ?>

      <!-- KPI Row -->
      <div class="kpi-grid">
        <div class="kpi-card">
          <div class="kpi-icon icon-ads">🎯</div>
          <div class="kpi-info">
            <div class="val"><?= number_format($todayAdsClicks) ?></div>
            <div class="label">Bugünkü Ads Tıklaması</div>
          </div>
        </div>

        <div class="kpi-card">
          <div class="kpi-icon icon-today">👥</div>
          <div class="kpi-info">
            <div class="val"><?= number_format($todayTotalVisits) ?></div>
            <div class="label">Bugünkü Toplam Hit</div>
          </div>
        </div>

        <div class="kpi-card">
          <div class="kpi-icon icon-suspicious">⚠️</div>
          <div class="kpi-info">
            <div class="val"><?= number_format($suspiciousCount) ?></div>
            <div class="label">Şüpheli / Sahte IP</div>
          </div>
        </div>

        <div class="kpi-card">
          <div class="kpi-icon icon-blocked">🛑</div>
          <div class="kpi-info">
            <div class="val"><?= number_format($blockedCount) ?></div>
            <div class="label">Engellenen IP Sayısı</div>
          </div>
        </div>

        <div class="kpi-card">
          <div class="kpi-icon icon-visits">📈</div>
          <div class="kpi-info">
            <div class="val"><?= number_format($totalAdsClicks) ?></div>
            <div class="label">Toplam Ads Tıklaması</div>
          </div>
        </div>
      </div>

      <!-- Google Ads Exclusion Tool Box -->
      <div class="exclusion-box">
        <div class="exclusion-left">
          <h3>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            Google Ads Kampanyası İçin Dışlanacak IP Listesi <?= $selectedSite ? '(' . htmlspecialchars($selectedSite['name']) . ')' : '(Tüm Siteler)' ?>
          </h3>
          <p>
            Rakiplerinizi ve botları engellemek için, aşağıdaki şüpheli IP listesini kopyalayarak Google Ads kampanya ayarlarınıza yapıştırın. Google bu kullanıcılara reklamınızı bir daha asla göstermez!
          </p>
          <div class="exclusion-steps">
            📌 <strong>Nasıl Eklenir?</strong> Google Ads &gt; İlgili Kampanya &gt; <strong>Ayarlar</strong> &gt; <strong>Ek Ayarlar</strong> &gt; <strong>IP Adresi Dışlamaları</strong> alanına yapıştırıp kaydedin.
          </div>
        </div>

        <div class="exclusion-right">
          <textarea id="exclusionList" class="ip-textarea" readonly placeholder="Henüz şüpheli IP tespit edilmedi."><?= htmlspecialchars($exclusionText) ?></textarea>
          <button type="button" class="btn-copy" onclick="copyExclusionList()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
            <span id="copyBtnText">Tüm Şüpheli IP'leri Kopyala</span>
          </button>
        </div>
      </div>

      <!-- 2-Column Dashboard Breakdown -->
      <div class="dash-layout-grid">
        <!-- Left: Daily Traffic Breakdown -->
        <div class="content-box">
          <div class="content-box-title">
            <span>📅 Günlük Ziyaret &amp; Tıklama İstatistiği (Son 14 Gün)</span>
            <a href="index.php?view=history&site_id=<?= $selectedSiteId ?>" class="btn btn-secondary" style="font-size:0.75rem; padding:4px 10px;">Tüm Geçmişi İncele ➔</a>
          </div>

          <?php if (empty($dailyStats)): ?>
            <p style="color: #64748b; font-size: 0.88rem; padding: 20px; text-align: center;">Henüz ziyaret verisi bulunmuyor.</p>
          <?php else: ?>
            <table class="daily-stats-table">
              <thead>
                <tr>
                  <th>Tarih</th>
                  <th>Toplam Hit</th>
                  <th>Google Ads</th>
                  <th>Organik</th>
                  <th style="width: 35%;">Dağılım</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($dailyStats as $d): ?>
                  <?php
                    $tot = (int)$d['total_hits'];
                    $ads = (int)$d['ads_hits'];
                    $org = (int)$d['organic_hits'];
                    $adsPct = $tot > 0 ? round(($ads / $tot) * 100) : 0;
                    $orgPct = 100 - $adsPct;
                    $isToday = $d['visit_date'] === date('Y-m-d');
                  ?>
                  <tr>
                    <td>
                      <strong><?= date('d.m.Y', strtotime($d['visit_date'])) ?></strong>
                      <?php if ($isToday): ?>
                        <span class="badge" style="background:rgba(245, 158, 11, 0.2); color:#fbbf24; font-size:0.65rem; margin-left:4px;">BUGÜN</span>
                      <?php endif; ?>
                    </td>
                    <td><strong><?= $tot ?></strong></td>
                    <td style="color: #60a5fa; font-weight: 700;"><?= $ads ?></td>
                    <td style="color: #94a3b8;"><?= $org ?></td>
                    <td>
                      <div class="stat-bar-container" title="Ads: %<?= $adsPct ?> | Organik: %<?= $orgPct ?>">
                        <div class="stat-bar-ads" style="width: <?= $adsPct ?>%;"></div>
                        <div class="stat-bar-organic" style="width: <?= $orgPct ?>%;"></div>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <!-- Right: Device Distribution & Top Suspects -->
        <div style="display: flex; flex-direction: column; gap: 20px;">
          <!-- Device Breakdown -->
          <div class="content-box">
            <div class="content-box-title">
              <span>📱 Cihaz Dağılımı</span>
            </div>
            <?php
              $totDev = array_sum($deviceBreakdown);
              $mobPct = $totDev > 0 ? round(($deviceBreakdown['mobile'] / $totDev) * 100) : 0;
              $deskPct = $totDev > 0 ? round(($deviceBreakdown['desktop'] / $totDev) * 100) : 0;
              $tabPct = $totDev > 0 ? round(($deviceBreakdown['tablet'] / $totDev) * 100) : 0;
            ?>
            <div style="margin-bottom: 12px;">
              <div style="display: flex; justify-content: space-between; font-size: 0.84rem; margin-bottom: 4px;">
                <span>📱 Mobil Telefon:</span> <strong><?= $deviceBreakdown['mobile'] ?> (%<?= $mobPct ?>)</strong>
              </div>
              <div style="display: flex; justify-content: space-between; font-size: 0.84rem; margin-bottom: 4px;">
                <span>💻 Masaüstü PC:</span> <strong><?= $deviceBreakdown['desktop'] ?> (%<?= $deskPct ?>)</strong>
              </div>
              <div style="display: flex; justify-content: space-between; font-size: 0.84rem;">
                <span>📲 Tablet &amp; Bot:</span> <strong><?= $deviceBreakdown['tablet'] + $deviceBreakdown['bot'] ?></strong>
              </div>
            </div>
            <div class="stat-bar-container" style="height: 10px;">
              <div style="background: #10b981; width: <?= $mobPct ?>%;" title="Mobil"></div>
              <div style="background: #3b82f6; width: <?= $deskPct ?>%;" title="Masaüstü"></div>
              <div style="background: #f59e0b; width: <?= $tabPct ?>%;" title="Diğer"></div>
            </div>
          </div>

          <!-- Top Suspicious IPs -->
          <div class="content-box">
            <div class="content-box-title">
              <span>⚠️ En Çok Tıklayan Şüpheli IP'ler</span>
              <a href="index.php?view=ips&site_id=<?= $selectedSiteId ?>" class="btn btn-secondary" style="font-size:0.75rem; padding:4px 10px;">Tüm IP'ler ➔</a>
            </div>

            <?php if (empty($topSuspiciousIPs)): ?>
              <p style="color: #64748b; font-size: 0.84rem; padding: 14px 0;">Henüz şüpheli eşiğini aşan IP bulunmuyor.</p>
            <?php else: ?>
              <?php foreach ($topSuspiciousIPs as $s): ?>
                <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #1c273e; font-size: 0.84rem;">
                  <div>
                    <a href="https://ipinfo.io/<?= urlencode($s['ip']) ?>" target="_blank" class="ip-link"><?= htmlspecialchars($s['ip']) ?></a>
                    <small style="display: block; color: #64748b; font-size: 0.72rem;">
                      <?= htmlspecialchars($s['device_name']) ?> • <span style="color:#a78bfa;"><?= htmlspecialchars($s['site_name']) ?></span>
                    </small>
                  </div>
                  <div style="display: flex; align-items: center; gap: 8px;">
                    <span class="click-counter counter-high"><?= $s['ads_clicks'] ?> Ads</span>
                    <button type="button" class="btn-sm btn-block-toggle <?= $s['is_blocked'] ? 'active' : '' ?>" onclick="toggleBlock('<?= htmlspecialchars($s['ip']) ?>', <?= $s['site_id'] ?>)">
                      <?= $s['is_blocked'] ? 'Aç' : 'Engelle' ?>
                    </button>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- =========================================================================
           GÜNLÜK ZİYARETÇİ IP LİSTESİ TABLOSU (TAM GENİŞLİK)
           ========================================================================= -->
      <div class="content-box" style="margin-top: 24px;">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px; margin-bottom: 18px; padding-bottom: 14px; border-bottom: 1px solid var(--border-color);">
          <div>
            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
              <h3 style="font-size: 1.25rem; font-weight: 800; color: #ffffff; display: flex; align-items: center; gap: 8px;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                <?= $dashDayTitle ?> Ziyaretçi IP Listesi <?= $selectedSite ? '(' . htmlspecialchars($selectedSite['name']) . ')' : '' ?>
              </h3>
              <span class="badge" style="background: rgba(245, 158, 11, 0.2); color: #fbbf24; font-size: 0.82rem; padding: 4px 10px;">
                <?= count($dailyIPs) ?> Tekil IP
              </span>
              <?php
                $dayAdsCount = 0;
                $dayHitCount = 0;
                foreach ($dailyIPs as $dip) {
                  $dayAdsCount += (int)$dip['period_ads_clicks'];
                  $dayHitCount += (int)$dip['period_hits'];
                }
              ?>
              <span class="badge badge-ads" style="font-size: 0.82rem; padding: 4px 10px;">
                <?= $dayAdsCount ?> Ads Tıklaması
              </span>
              <span class="badge" style="background: rgba(148, 163, 184, 0.15); color: #cbd5e1; font-size: 0.82rem; padding: 4px 10px;">
                <?= $dayHitCount ?> Toplam Hit
              </span>
            </div>
            <p style="font-size: 0.84rem; color: var(--text-muted); margin-top: 4px;">
              <?= $dashDayTitle ?> sitelerinize bağlanan tüm IP adresleri, cihazlar, tıklama sayıları ve güvenlik aksiyonları.
            </p>
          </div>

          <!-- Date Quick Selector & Export Button -->
          <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <div style="display: flex; gap: 4px; background: var(--site-switcher-bg); padding: 4px; border-radius: 6px; border: 1px solid var(--border-color);">
              <a href="index.php?view=dashboard&site_id=<?= $selectedSiteId ?>&day=today" class="btn-sm <?= $dashDay === 'today' ? 'btn-gold' : 'btn-secondary' ?>">Bugün</a>
              <a href="index.php?view=dashboard&site_id=<?= $selectedSiteId ?>&day=yesterday" class="btn-sm <?= $dashDay === 'yesterday' ? 'btn-gold' : 'btn-secondary' ?>">Dün</a>
              <a href="index.php?view=dashboard&site_id=<?= $selectedSiteId ?>&day=last7" class="btn-sm <?= $dashDay === 'last7' ? 'btn-gold' : 'btn-secondary' ?>">Son 7 Gün</a>
            </div>

            <a href="api.php?action=export_daily_ips&site_id=<?= $selectedSiteId ?>&day=<?= urlencode($dashDay) ?>" class="btn btn-secondary" style="font-size: 0.8rem; padding: 6px 12px;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
              CSV İndir
            </a>
          </div>
        </div>

        <!-- Filter & Search Sub-bar -->
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 14px;">
          <div style="display: flex; gap: 6px; flex-wrap: wrap;">
            <button type="button" class="daily-filter-btn active" onclick="applyDailyFilter('all')">Tümü (<?= count($dailyIPs) ?>)</button>
            <button type="button" class="daily-filter-btn" onclick="applyDailyFilter('ads')">🎯 Yalnızca Ads (<?= $dayAdsCount ?>)</button>
            <button type="button" class="daily-filter-btn" onclick="applyDailyFilter('suspicious')">⚠️ Şüpheli / Sahte</button>
            <button type="button" class="daily-filter-btn" onclick="applyDailyFilter('blocked')">🛑 Engelliler</button>
          </div>

          <div style="min-width: 240px; flex: 1; max-width: 320px;">
            <input type="text" id="dailySearchInput" onkeyup="filterDailyRows()" class="text-input" placeholder="Bu tabloda IP veya cihaz ara..." style="padding: 6px 12px; font-size: 0.82rem;">
          </div>
        </div>

        <?php if (empty($dailyIPs)): ?>
          <div style="text-align: center; padding: 36px 20px; color: #64748b;">
            <div style="font-size: 2.2rem; margin-bottom: 8px;">🔍</div>
            <p style="font-size: 0.95rem; font-weight: 600;"><?= $dashDayTitle ?> herhangi bir ziyaretçi IP kaydı bulunamadı.</p>
          </div>
        <?php else: ?>
          <div class="data-table-wrapper">
            <table class="data-table">
              <thead>
                <tr>
                  <th>IP Adresi</th>
                  <?php if ($selectedSiteId === 0): ?><th>Hedef Site</th><?php endif; ?>
                  <th>Trafik Türü</th>
                  <th style="text-align: center;">Dönem Tıklaması</th>
                  <th>Cihaz &amp; Tarayıcı</th>
                  <th>İlk &amp; Son Görülme</th>
                  <th>Güvenlik Durumu</th>
                  <th style="text-align: right;">Hızlı Aksiyonlar</th>
                </tr>
              </thead>
              <tbody id="dailyTableBody">
                <?php foreach ($dailyIPs as $dRow): ?>
                  <?php
                    $isAds = (int)$dRow['period_ads_clicks'] > 0;
                    $isSusp = (int)$dRow['is_suspicious'] === 1;
                    $isBlk = (int)$dRow['is_blocked'] === 1;

                    $cntClass = 'counter-low';
                    if ((int)$dRow['period_ads_clicks'] >= 3) {
                      $cntClass = 'counter-high';
                    } elseif ((int)$dRow['period_ads_clicks'] >= 2) {
                      $cntClass = 'counter-med';
                    }

                    $devIcon = '💻';
                    if ($dRow['device_type'] === 'mobile') $devIcon = '📱';
                    elseif ($dRow['device_type'] === 'tablet') $devIcon = '📲';
                    elseif ($dRow['device_type'] === 'bot') $devIcon = '🤖';
                  ?>
                  <tr class="daily-ip-row"
                      data-ip="<?= htmlspecialchars($dRow['ip']) ?>"
                      data-device="<?= htmlspecialchars($dRow['device_name'] . ' ' . $dRow['browser'] . ' ' . $dRow['os']) ?>"
                      data-ads="<?= $isAds ? '1' : '0' ?>"
                      data-suspicious="<?= $isSusp ? '1' : '0' ?>"
                      data-blocked="<?= $isBlk ? '1' : '0' ?>">
                    <td>
                      <div style="display: flex; align-items: center; gap: 8px;">
                        <a href="https://ipinfo.io/<?= urlencode($dRow['ip']) ?>" target="_blank" class="ip-link" title="Whois / Lokasyon">
                          <?= htmlspecialchars($dRow['ip']) ?>
                        </a>
                        <button type="button" class="btn-sm btn-secondary" style="padding: 2px 6px; font-size: 0.72rem;" onclick="copySingleIP('<?= htmlspecialchars($dRow['ip']) ?>', this)" title="Kopyala">
                          📋
                        </button>
                      </div>
                      <small style="color: #64748b; font-size: 0.72rem; display: block; margin-top: 2px;">
                        Tüm zamanlar: <?= $dRow['all_time_visits'] ?> hit (<?= $dRow['all_time_ads'] ?> Ads)
                      </small>
                    </td>

                    <?php if ($selectedSiteId === 0): ?>
                      <td>
                        <span class="badge badge-site"><?= htmlspecialchars($dRow['site_name']) ?></span>
                        <small style="color: #64748b; display: block; font-size: 0.72rem;"><?= htmlspecialchars($dRow['site_domain']) ?></small>
                      </td>
                    <?php endif; ?>

                    <td>
                      <?php if ($dRow['period_ads_clicks'] > 0): ?>
                        <span class="badge badge-ads">🎯 Google Ads (<?= $dRow['period_ads_clicks'] ?>)</span>
                      <?php else: ?>
                        <span class="badge badge-organic">🌐 Organik Ziyaret</span>
                      <?php endif; ?>
                    </td>

                    <td style="text-align: center;">
                      <div style="display: flex; flex-direction: column; align-items: center; gap: 2px;">
                        <span class="click-counter <?= $cntClass ?>">
                          <?= $dRow['period_hits'] ?>
                        </span>
                        <span style="font-size: 0.68rem; color: #94a3b8;">hit</span>
                      </div>
                    </td>

                    <td>
                      <div style="font-size: 0.85rem; font-weight: 600; color: #ffffff;">
                        <?= $devIcon ?> <?= htmlspecialchars($dRow['device_name']) ?>
                      </div>
                      <div style="font-size: 0.74rem; color: #94a3b8;">
                        <?= htmlspecialchars($dRow['browser']) ?> • <?= htmlspecialchars($dRow['os']) ?>
                      </div>
                    </td>

                    <td>
                      <div style="font-size: 0.8rem; color: #cbd5e1;">
                        <strong style="color: #94a3b8;">Son:</strong> <?= date('H:i:s', strtotime($dRow['last_hit'])) ?>
                      </div>
                      <div style="font-size: 0.74rem; color: #64748b;">
                        İlk: <?= date('H:i:s', strtotime($dRow['first_hit'])) ?>
                      </div>
                    </td>

                    <td>
                      <?php if ($isBlk): ?>
                        <span class="badge badge-blocked">🛑 Engellendi</span>
                      <?php elseif ($isSusp): ?>
                        <span class="badge badge-suspicious">⚠️ Şüpheli / Sahte</span>
                      <?php else: ?>
                        <span class="badge badge-safe">✅ Normal Ziyaretçi</span>
                      <?php endif; ?>
                    </td>

                    <td style="text-align: right; white-space: nowrap;">
                      <button type="button" class="btn-sm btn-inspect" onclick="inspectIP('<?= htmlspecialchars($dRow['ip']) ?>')" title="Geçmişi İncele">
                        🔍 Geçmiş
                      </button>
                      <button type="button" class="btn-sm btn-block-toggle <?= $isBlk ? 'active' : '' ?>" onclick="toggleBlock('<?= htmlspecialchars($dRow['ip']) ?>', <?= $selectedSiteId ?>)" title="<?= $isBlk ? 'Engeli Kaldır' : 'Engelle' ?>">
                        <?= $isBlk ? 'Engeli Aç' : 'Engelle' ?>
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

    <!-- =========================================================================
         VIEW 2: SITES MANAGEMENT (SİTELERİM & ENTEGRASYON)
         ========================================================================= -->
    <?php elseif ($view === 'sites'): ?>

      <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px; margin-bottom: 20px;">
        <div>
          <h2 style="font-size: 1.45rem; font-weight: 800; color: #ffffff;">🌐 Sitelerim &amp; Takip Kodları</h2>
          <p style="font-size: 0.88rem; color: var(--text-muted);">
            Sisteme dilediğiniz kadar domain ekleyin, her birine özel üretilen JavaScript veya PHP kodunu sitenize yapıştırın.
          </p>
        </div>
        <button type="button" class="btn btn-gold" onclick="openModal('addSiteModal')">
          ➕ Yeni Site Ekle
        </button>
      </div>

      <div class="content-box">
        <?php if (empty($sitesStats)): ?>
          <div style="text-align:center; padding: 40px; color: #64748b;">
            <p>Henüz eklenmiş bir site bulunmuyor.</p>
          </div>
        <?php else: ?>
          <div class="data-table-wrapper">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Site Adı &amp; Domain</th>
                  <th>Site Anahtarı (site_key)</th>
                  <th style="text-align:center;">Bugünkü Ads</th>
                  <th style="text-align:center;">Toplam Hit</th>
                  <th style="text-align:center;">Şüpheli IP</th>
                  <th>Eşik &amp; Otomatik Blok</th>
                  <th>Durum</th>
                  <th style="text-align:right;">İşlemler</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($sitesStats as $st): ?>
                  <tr>
                    <td>
                      <div style="font-weight: 800; font-size: 0.95rem; color: #ffffff;">
                        <?= htmlspecialchars($st['name']) ?>
                      </div>
                      <a href="http://<?= htmlspecialchars($st['domain']) ?>" target="_blank" style="color: #60a5fa; font-size: 0.8rem;">
                        <?= htmlspecialchars($st['domain']) ?> ↗
                      </a>
                    </td>

                    <td>
                      <code style="background: var(--input-bg); border: 1px solid var(--border-color); padding: 4px 8px; border-radius: 4px; color: var(--accent-gold-dark); font-size: 0.78rem;">
                        <?= htmlspecialchars($st['site_key']) ?>
                      </code>
                    </td>

                    <td style="text-align:center;">
                      <span class="badge badge-ads"><?= $st['today_ads'] ?> Ads</span>
                    </td>

                    <td style="text-align:center;">
                      <strong><?= $st['total_hits'] ?></strong>
                    </td>

                    <td style="text-align:center;">
                      <?php if ($st['susp_ips'] > 0): ?>
                        <span class="badge badge-suspicious"><?= $st['susp_ips'] ?> Şüpheli</span>
                      <?php else: ?>
                        <span style="color: #64748b;">0</span>
                      <?php endif; ?>
                    </td>

                    <td>
                      <div style="font-size: 0.8rem; color: #cbd5e1;">
                        Eşik: <strong><?= $st['click_threshold'] ?> Tıklama</strong>
                      </div>
                      <div style="font-size: 0.74rem; color: <?= $st['auto_block'] ? '#34d399' : '#94a3b8' ?>;">
                        <?= $st['auto_block'] ? '🛡️ Otomatik Blok Açık' : '⚪ Manuel Blokaj' ?>
                      </div>
                    </td>

                    <td>
                      <span class="badge <?= $st['is_active'] ? 'badge-safe' : 'badge-blocked' ?>">
                        <?= $st['is_active'] ? 'Aktif' : 'Pasif' ?>
                      </span>
                    </td>

                    <td style="text-align:right; white-space: nowrap;">
                      <button type="button" class="btn-sm btn-code" onclick="showIntegrationCode(<?= $st['id'] ?>)">
                        ⚡ Kodları Al
                      </button>
                      <button type="button" class="btn-sm btn-inspect" onclick="editSite(<?= htmlspecialchars(json_encode($st)) ?>)">
                        ✏️ Düzenle
                      </button>
                      <button type="button" class="btn-sm btn-delete" onclick="deleteSite(<?= $st['id'] ?>, '<?= htmlspecialchars(addslashes($st['name'])) ?>')">
                        🗑️
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

    <!-- =========================================================================
         VIEW 3: DETAILED HIT HISTORY LOGS
         ========================================================================= -->
    <?php elseif ($view === 'history'): ?>

      <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px; margin-bottom: 20px;">
        <div>
          <h2 style="font-size: 1.45rem; font-weight: 800; color: #ffffff;">📋 Detaylı Ziyaret &amp; Tıklama Geçmişi</h2>
          <p style="font-size: 0.88rem; color: var(--text-muted);">Tüm sitelerinize gelen her tekil tıklamayı anlık filtreleyin ve dışa aktarın.</p>
        </div>

        <div>
          <a href="api.php?action=export_history_csv&site_id=<?= $selectedSiteId ?>&source=<?= urlencode($filterSource) ?>&device=<?= urlencode($filterDevice) ?>&date_filter=<?= urlencode($filterDate) ?>&q=<?= urlencode($filterQ) ?>" class="btn btn-gold">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
            Bu Filtreyle CSV İndir
          </a>
        </div>
      </div>

      <!-- Filter Panel -->
      <div class="content-box" style="margin-bottom: 20px; padding: 18px;">
        <form method="GET" action="index.php" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; align-items: flex-end;">
          <input type="hidden" name="view" value="history">

          <div>
            <label style="display:block; font-size:0.75rem; font-weight:700; color:#cbd5e1; margin-bottom:4px;">Site Filtresi</label>
            <select name="site_id" class="select-input">
              <option value="0">Tüm Siteler</option>
              <?php foreach ($sitesList as $st): ?>
                <option value="<?= $st['id'] ?>" <?= $selectedSiteId === (int)$st['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($st['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label style="display:block; font-size:0.75rem; font-weight:700; color:#cbd5e1; margin-bottom:4px;">Trafik Türü</label>
            <select name="source" class="select-input">
              <option value="all" <?= $filterSource === 'all' ? 'selected' : '' ?>>Tümü (Ads + Organik)</option>
              <option value="ads" <?= $filterSource === 'ads' ? 'selected' : '' ?>>Yalnızca Google Ads</option>
              <option value="organic" <?= $filterSource === 'organic' ? 'selected' : '' ?>>Yalnızca Organik</option>
            </select>
          </div>

          <div>
            <label style="display:block; font-size:0.75rem; font-weight:700; color:#cbd5e1; margin-bottom:4px;">Cihaz</label>
            <select name="device" class="select-input">
              <option value="all" <?= $filterDevice === 'all' ? 'selected' : '' ?>>Tüm Cihazlar</option>
              <option value="mobile" <?= $filterDevice === 'mobile' ? 'selected' : '' ?>>📱 Mobil</option>
              <option value="desktop" <?= $filterDevice === 'desktop' ? 'selected' : '' ?>>💻 Masaüstü</option>
              <option value="tablet" <?= $filterDevice === 'tablet' ? 'selected' : '' ?>>📲 Tablet</option>
              <option value="bot" <?= $filterDevice === 'bot' ? 'selected' : '' ?>>🤖 Botlar</option>
            </select>
          </div>

          <div>
            <label style="display:block; font-size:0.75rem; font-weight:700; color:#cbd5e1; margin-bottom:4px;">Tarih</label>
            <select name="date_filter" class="select-input">
              <option value="all" <?= $filterDate === 'all' ? 'selected' : '' ?>>Tüm Zamanlar</option>
              <option value="today" <?= $filterDate === 'today' ? 'selected' : '' ?>>Bugün</option>
              <option value="yesterday" <?= $filterDate === 'yesterday' ? 'selected' : '' ?>>Dün</option>
              <option value="last7" <?= $filterDate === 'last7' ? 'selected' : '' ?>>Son 7 Gün</option>
              <option value="last30" <?= $filterDate === 'last30' ? 'selected' : '' ?>>Son 30 Gün</option>
            </select>
          </div>

          <div>
            <label style="display:block; font-size:0.75rem; font-weight:700; color:#cbd5e1; margin-bottom:4px;">Arama (IP, gclid)</label>
            <input type="text" name="q" value="<?= htmlspecialchars($filterQ) ?>" class="text-input" placeholder="IP, gclid veya cihaz...">
          </div>

          <div style="display: flex; gap: 6px;">
            <button type="submit" class="btn btn-gold" style="width: 100%;">Filtrele</button>
            <a href="index.php?view=history" class="btn btn-secondary" title="Sıfırla">✕</a>
          </div>
        </form>
      </div>

      <!-- History Table -->
      <div class="content-box">
        <?php if (empty($historyRows)): ?>
          <p style="text-align:center; padding: 40px; color: #64748b;">Belirtilen kriterlere uygun tıklama kaydı bulunamadı.</p>
        <?php else: ?>
          <div class="data-table-wrapper">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Tarih &amp; Saat</th>
                  <th>Site</th>
                  <th>IP Adresi</th>
                  <th>Trafik Türü</th>
                  <th>Google GCLID</th>
                  <th>Cihaz &amp; Tarayıcı</th>
                  <th>Sayfa / Kaynak</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($historyRows as $h): ?>
                  <tr>
                    <td style="white-space: nowrap; color: #cbd5e1; font-size: 0.82rem;">
                      <?= date('d.m.Y H:i:s', strtotime($h['created_at'])) ?>
                    </td>
                    <td>
                      <span class="badge badge-site"><?= htmlspecialchars($h['site_name']) ?></span>
                    </td>
                    <td>
                      <a href="https://ipinfo.io/<?= urlencode($h['ip']) ?>" target="_blank" class="ip-link">
                        <?= htmlspecialchars($h['ip']) ?>
                      </a>
                    </td>
                    <td>
                      <?php if ($h['is_google_ads']): ?>
                        <span class="badge badge-ads">🎯 Google Ads</span>
                      <?php else: ?>
                        <span class="badge badge-organic">🌐 Organik</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($h['gclid']): ?>
                        <code style="color: #60a5fa; font-size: 0.72rem; word-break: break-all;">
                          <?= htmlspecialchars(substr($h['gclid'], 0, 22)) ?>...
                        </code>
                      <?php else: ?>
                        <span style="color: #64748b;">—</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div style="font-weight: 600; color: #ffffff; font-size: 0.82rem;">
                        <?= htmlspecialchars($h['device_name']) ?>
                      </div>
                      <div style="font-size: 0.74rem; color: #94a3b8;">
                        <?= htmlspecialchars($h['browser']) ?> • <?= htmlspecialchars($h['os']) ?>
                      </div>
                    </td>
                    <td style="max-width: 250px;">
                      <div style="font-size: 0.76rem; color: #cbd5e1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($h['page_url']) ?>">
                        <?= htmlspecialchars($h['page_url']) ?>
                      </div>
                      <?php if ($h['referrer']): ?>
                        <small style="color: #64748b; font-size: 0.7rem; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                          Ref: <?= htmlspecialchars($h['referrer']) ?>
                        </small>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

    <!-- =========================================================================
         VIEW 4: IP MANAGEMENT
         ========================================================================= -->
    <?php elseif ($view === 'ips'): ?>

      <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px; margin-bottom: 20px;">
        <div>
          <h2 style="font-size: 1.45rem; font-weight: 800; color: #ffffff;">🛡️ IP Yönetimi &amp; Dışlama Paneli</h2>
          <p style="font-size: 0.88rem; color: var(--text-muted);">
            Sitelerinizi ziyaret eden tüm tekil IP adresleri, sahte tıklama durumları ve anlık blokaj kontrolleri.
          </p>
        </div>

        <div style="display: flex; gap: 8px;">
          <a href="api.php?action=export_csv&site_id=<?= $selectedSiteId ?>" class="btn btn-secondary">
            📥 Tüm IP Listesini CSV İndir
          </a>
        </div>
      </div>

      <div class="content-box">
        <?php if (empty($ipList)): ?>
          <p style="text-align:center; padding: 40px; color: #64748b;">Henüz kayıtlı IP adresi bulunmuyor.</p>
        <?php else: ?>
          <div class="data-table-wrapper">
            <table class="data-table">
              <thead>
                <tr>
                  <th>IP Adresi</th>
                  <th>Site</th>
                  <th style="text-align: center;">Ads Tıklaması</th>
                  <th style="text-align: center;">Toplam Hit</th>
                  <th>Cihaz</th>
                  <th>İlk / Son Görülme</th>
                  <th>Güvenlik Durumu</th>
                  <th style="text-align: right;">Aksiyonlar</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($ipList as $ipRow): ?>
                  <tr>
                    <td>
                      <div style="display: flex; align-items: center; gap: 8px;">
                        <a href="https://ipinfo.io/<?= urlencode($ipRow['ip']) ?>" target="_blank" class="ip-link">
                          <?= htmlspecialchars($ipRow['ip']) ?>
                        </a>
                        <button type="button" class="btn-sm btn-secondary" style="padding: 2px 6px; font-size: 0.72rem;" onclick="copySingleIP('<?= htmlspecialchars($ipRow['ip']) ?>', this)">📋</button>
                      </div>
                    </td>

                    <td>
                      <span class="badge badge-site"><?= htmlspecialchars($ipRow['site_name']) ?></span>
                    </td>

                    <td style="text-align: center;">
                      <span class="click-counter <?= $ipRow['ads_clicks'] >= 3 ? 'counter-high' : ($ipRow['ads_clicks'] >= 2 ? 'counter-med' : 'counter-low') ?>">
                        <?= $ipRow['ads_clicks'] ?>
                      </span>
                    </td>

                    <td style="text-align: center;">
                      <strong><?= $ipRow['total_visits'] ?></strong>
                    </td>

                    <td>
                      <div style="font-size: 0.82rem; color: #ffffff;"><?= htmlspecialchars($ipRow['device_name']) ?></div>
                      <div style="font-size: 0.74rem; color: #94a3b8;"><?= htmlspecialchars($ipRow['browser']) ?> • <?= htmlspecialchars($ipRow['os']) ?></div>
                    </td>

                    <td>
                      <div style="font-size: 0.8rem; color: #cbd5e1;">Son: <?= date('d.m.Y H:i', strtotime($ipRow['last_visit'])) ?></div>
                      <div style="font-size: 0.74rem; color: #64748b;">İlk: <?= date('d.m.Y H:i', strtotime($ipRow['first_visit'])) ?></div>
                    </td>

                    <td>
                      <?php if ($ipRow['is_blocked']): ?>
                        <span class="badge badge-blocked">🛑 Engellendi</span>
                      <?php elseif ($ipRow['is_suspicious']): ?>
                        <span class="badge badge-suspicious">⚠️ Şüpheli / Sahte</span>
                      <?php else: ?>
                        <span class="badge badge-safe">✅ Normal</span>
                      <?php endif; ?>
                    </td>

                    <td style="text-align: right; white-space: nowrap;">
                      <button type="button" class="btn-sm btn-inspect" onclick="inspectIP('<?= htmlspecialchars($ipRow['ip']) ?>', <?= $ipRow['site_id'] ?>)">
                        🔍 Geçmiş
                      </button>
                      <button type="button" class="btn-sm btn-block-toggle <?= $ipRow['is_blocked'] ? 'active' : '' ?>" onclick="toggleBlock('<?= htmlspecialchars($ipRow['ip']) ?>', <?= $ipRow['site_id'] ?>)">
                        <?= $ipRow['is_blocked'] ? 'Engeli Aç' : 'Engelle' ?>
                      </button>
                      <button type="button" class="btn-sm btn-delete" onclick="deleteIP('<?= htmlspecialchars($ipRow['ip']) ?>', <?= $ipRow['site_id'] ?>)">
                        🗑️
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

    <!-- =========================================================================
         VIEW 5: SETTINGS & STATS RESET
         ========================================================================= -->
    <?php elseif ($view === 'settings'): ?>

      <h2 style="font-size: 1.45rem; font-weight: 800; color: #ffffff; margin-bottom: 20px;">⚙️ Sistem Ayarları &amp; İstatistik Sıfırlama</h2>

      <!-- Reset Cards Grid -->
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div class="content-box">
          <h4 style="color:#ffffff; margin-bottom: 6px;">🧹 Bugünkü İstatistikleri Sıfırla</h4>
          <p style="font-size:0.82rem; color:var(--text-muted); margin-bottom: 14px;">
            Yalnızca bugüne ait ziyaret ve tıklama loglarını siler. Geçmiş günlerin verileri ve engelli IP listeleri korunur.
          </p>
          <button type="button" class="btn btn-secondary" onclick="confirmReset('today')">Bugünü Temizle</button>
        </div>

        <div class="content-box">
          <h4 style="color:#ffffff; margin-bottom: 6px;">📦 30 Günden Eski Logları Arşivle / Sil</h4>
          <p style="font-size:0.82rem; color:var(--text-muted); margin-bottom: 14px;">
            Veritabanının şişmesini önlemek için 30 günden eski ziyaret geçmişi kayıtlarını temizler.
          </p>
          <button type="button" class="btn btn-secondary" onclick="confirmReset('older_30')">Eski Logları Temizle</button>
        </div>

        <div class="content-box">
          <h4 style="color:#ffffff; margin-bottom: 6px;">🎯 Organik / Doğrudan Ziyaretleri Temizle</h4>
          <p style="font-size:0.82rem; color:var(--text-muted); margin-bottom: 14px;">
            Google Ads tıklamalarını korur, yalnızca normal arama ve doğrudan siteye gelen organik hit kayıtlarını temizler.
          </p>
          <button type="button" class="btn btn-secondary" onclick="confirmReset('organic_only')">Organik Hitleri Sil</button>
        </div>

        <div class="content-box" style="border-color: rgba(239, 68, 68, 0.4);">
          <h4 style="color:#ef4444; margin-bottom: 6px;">⚠️ Tüm İstatistik ve Logları Sıfırla</h4>
          <p style="font-size:0.82rem; color:var(--text-muted); margin-bottom: 14px;">
            Sisteme kayıtlı tüm ziyaret loglarını ve IP sayaçlarını sıfırlar. Kayıtlı siteleriniz silinmez.
          </p>
          <button type="button" class="btn btn-danger" onclick="confirmReset('all')">Tüm Verileri Sıfırla</button>
        </div>
      </div>

      <!-- Account Profile (Username & Password) Change -->
      <div class="content-box" style="max-width: 520px;">
        <h4 style="color:#ffffff; margin-bottom: 14px;">🔐 Yönetici Kullanıcı Adı ve Şifre Ayarları</h4>
        <form id="profileForm">
          <div style="margin-bottom: 12px;">
            <label style="display:block; font-size:0.8rem; color:#cbd5e1; margin-bottom:4px; font-weight:700;">Kullanıcı Adı:</label>
            <input type="text" name="username" value="<?= htmlspecialchars($_SESSION['adstracker_username'] ?? 'admin') ?>" class="text-input" required>
            <small style="color:#64748b; font-size:0.72rem;">Panele giriş yaparken kullanacağınız kullanıcı adı.</small>
          </div>
          <div style="margin-bottom: 12px;">
            <label style="display:block; font-size:0.8rem; color:#cbd5e1; margin-bottom:4px; font-weight:700;">Mevcut Şifre (Doğrulama için):</label>
            <input type="password" name="old_password" class="text-input" placeholder="Mevcut şifreniz" required>
          </div>
          <div style="margin-bottom: 16px;">
            <label style="display:block; font-size:0.8rem; color:#cbd5e1; margin-bottom:4px; font-weight:700;">Yeni Şifre (Değiştirmek istemiyorsanız boş bırakın):</label>
            <input type="password" name="new_password" class="text-input" placeholder="Yeni şifreniz (En az 4 karakter)">
            <small style="color:#64748b; font-size:0.72rem;">Yalnızca şifreyi güncellemek istiyorsanız doldurun.</small>
          </div>
          <button type="submit" class="btn btn-gold">Bilgileri Güncelle</button>
        </form>
      </div>

    <?php endif; ?>

  </main>

  <!-- =========================================================================
       MODAL 1: ADD SITE MODAL
       ========================================================================= -->
  <div id="addSiteModal" class="modal-overlay">
    <div class="modal-box">
      <div class="modal-head">
        <h3>➕ Sisteme Yeni Site Ekle</h3>
        <button type="button" class="close-modal" onclick="closeModal('addSiteModal')">&times;</button>
      </div>
      <form id="addSiteForm">
        <div style="margin-bottom: 14px;">
          <label style="display:block; font-size:0.82rem; color:#cbd5e1; margin-bottom:4px; font-weight:700;">Site Adı:</label>
          <input type="text" name="name" class="text-input" placeholder="Örn: Halis Hurda Bursa" required>
        </div>
        <div style="margin-bottom: 14px;">
          <label style="display:block; font-size:0.82rem; color:#cbd5e1; margin-bottom:4px; font-weight:700;">Domain (Web Adresi):</label>
          <input type="text" name="domain" class="text-input" placeholder="Örn: bursahurda.com" required>
        </div>
        <div style="margin-bottom: 14px;">
          <label style="display:block; font-size:0.82rem; color:#cbd5e1; margin-bottom:4px; font-weight:700;">Şüpheli Tıklama Eşiği:</label>
          <input type="number" name="click_threshold" class="text-input" value="3" min="1" max="20" required>
          <small style="color: #64748b; font-size: 0.72rem;">Bir IP aynı gün kaç Google Ads tıklaması yaparsa sahte/şüpheli işaretlensin?</small>
        </div>
        <div style="margin-bottom: 20px;">
          <label style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: #cbd5e1; cursor: pointer;">
            <input type="checkbox" name="auto_block" value="1" style="width: auto;">
            <span>Eşiği aşan IP'leri otomatik engelle (Siteye girişte 403 erişim kısıtlaması göster)</span>
          </label>
        </div>
        <div style="display: flex; justify-content: flex-end; gap: 10px;">
          <button type="button" class="btn btn-secondary" onclick="closeModal('addSiteModal')">İptal</button>
          <button type="submit" class="btn btn-gold">Siteyi Kaydet &amp; Kod Üret</button>
        </div>
      </form>
    </div>
  </div>

  <!-- =========================================================================
       MODAL 2: INTEGRATION CODE MODAL
       ========================================================================= -->
  <div id="codeModal" class="modal-overlay">
    <div class="modal-box" style="max-width: 820px;">
      <div class="modal-head">
        <h3 id="codeModalTitle">⚡ Entegrasyon Kodları</h3>
        <button type="button" class="close-modal" onclick="closeModal('codeModal')">&times;</button>
      </div>

      <div style="display: flex; gap: 6px; margin-bottom: 18px; border-bottom: 1px solid #23304a; padding-bottom: 10px;">
        <button type="button" class="btn-sm daily-filter-btn active" id="tabBtnJs" onclick="switchCodeTab('js')">1. JavaScript Kodu (Kolay &amp; Tüm Siteler)</button>
        <button type="button" class="btn-sm daily-filter-btn" id="tabBtnPhp" onclick="switchCodeTab('php')">2. PHP Shield Dosyası (Sunucu Seviyesinde)</button>
        <button type="button" class="btn-sm daily-filter-btn" id="tabBtnGtm" onclick="switchCodeTab('gtm')">3. Google Tag Manager (GTM)</button>
      </div>

      <!-- Tab Content 1: JS -->
      <div id="codeTabJs">
        <p style="font-size: 0.86rem; color: #94a3b8; margin-bottom: 10px;">
          Aşağıdaki tek satır JavaScript kodunu hedef web sitenizin HTML şablonundaki <code>&lt;head&gt;</code> etiketleri arasına veya <code>&lt;/body&gt;</code> öncesine yapıştırın:
        </p>
        <div class="code-box" id="jsCodeBox"></div>
        <button type="button" class="btn btn-success" onclick="copyCode('jsCodeBox', this)">📋 Kodu Kopyala</button>
      </div>

      <!-- Tab Content 2: PHP -->
      <div id="codeTabPhp" style="display: none;">
        <p style="font-size: 0.86rem; color: #94a3b8; margin-bottom: 10px;">
          PHP tabanlı sitelerde (WordPress veya özel PHP yazılımlar) sahte tıklama yapanları siteniz hiç yüklenmeden sunucuda (403 Forbidden) durdurmak için:
        </p>
        <div style="margin-bottom: 14px;">
          <strong>Adım 1:</strong> Önceden yapılandırılmış <code>shield-client.php</code> dosyasını indirin ve sitenizin ana dizinine atın:
          <div style="margin-top: 6px;">
            <a id="downloadClientBtn" href="#" class="btn btn-gold">📥 shield-client.php Dosyasını İndir</a>
          </div>
        </div>
        <div>
          <strong>Adım 2:</strong> Sitenizin <code>index.php</code> veya <code>wp-config.php</code> dosyasının en başına şu satırı ekleyin:
          <div class="code-box" id="phpCodeBox" style="margin-top: 6px;"></div>
          <button type="button" class="btn btn-secondary" onclick="copyCode('phpCodeBox', this)">📋 Satırı Kopyala</button>
        </div>
      </div>

      <!-- Tab Content 3: GTM -->
      <div id="codeTabGtm" style="display: none;">
        <p style="font-size: 0.86rem; color: #94a3b8; margin-bottom: 10px;">
          Sitenizde Google Tag Manager yüklüyse kodu tek tıkla canlıya alabilirsiniz:
        </p>
        <ol style="font-size: 0.84rem; color: #cbd5e1; line-height: 1.8; margin-left: 20px; margin-bottom: 14px;">
          <li>Google Tag Manager hesabınıza giriş yapın.</li>
          <li><strong>Etiketler &gt; Yeni &gt; Özel HTML</strong> seçeneğini belirleyin.</li>
          <li>Yukarıdaki <strong>JavaScript Kodunu</strong> HTML alanına yapıştırın.</li>
          <li>Tetikleyici olarak <strong>Initialization - All Pages (Tüm Sayfalar)</strong> seçip kaydedin ve yayınlayın!</li>
        </ol>
      </div>
    </div>
  </div>

  <!-- =========================================================================
       MODAL 3: IP HISTORY MODAL
       ========================================================================= -->
  <div id="historyModal" class="modal-overlay">
    <div class="modal-box">
      <div class="modal-head">
        <h3 id="historyModalTitle">Tıklama Geçmişi</h3>
        <button type="button" class="close-modal" onclick="closeModal('historyModal')">&times;</button>
      </div>
      <div id="historyModalContent">Yükleniyor...</div>
    </div>
  </div>

  <script>
    function switchSite(siteId) {
      const url = new URL(window.location.href);
      url.searchParams.set('site_id', siteId);
      window.location.href = url.toString();
    }

    function openModal(id) { document.getElementById(id).classList.add('open'); }
    function closeModal(id) { document.getElementById(id).classList.remove('open'); }

    // Copy Exclusions
    function copyExclusionList() {
      const textarea = document.getElementById('exclusionList');
      if (!textarea || !textarea.value.trim()) {
        alert('Dışlanacak şüpheli IP listesi henüz boş.');
        return;
      }
      textarea.select();
      navigator.clipboard.writeText(textarea.value).then(() => {
        const btnText = document.getElementById('copyBtnText');
        const orig = btnText.textContent;
        btnText.textContent = '✔ Kopyalandı! (Google Ads\'e Yapıştırın)';
        setTimeout(() => { btnText.textContent = orig; }, 3000);
      });
    }

    function copySingleIP(ip, btn) {
      navigator.clipboard.writeText(ip).then(() => {
        const orig = btn.innerHTML;
        btn.innerHTML = '✔';
        setTimeout(() => { btn.innerHTML = orig; }, 2000);
      });
    }

    function copyCode(boxId, btn) {
      const text = document.getElementById(boxId).innerText;
      navigator.clipboard.writeText(text).then(() => {
        const orig = btn.innerHTML;
        btn.innerHTML = '✔ Kopyalandı!';
        setTimeout(() => { btn.innerHTML = orig; }, 2500);
      });
    }

    // Daily Table Filters
    let currentDailyFilter = 'all';
    function applyDailyFilter(filterType) {
      currentDailyFilter = filterType;
      document.querySelectorAll('.daily-filter-btn').forEach(b => b.classList.remove('active'));
      if (event && event.target) event.target.classList.add('active');
      filterDailyRows();
    }

    function filterDailyRows() {
      const searchInput = document.getElementById('dailySearchInput');
      const query = searchInput ? searchInput.value.toLowerCase().trim() : '';
      const rows = document.querySelectorAll('.daily-ip-row');

      rows.forEach(row => {
        const ip = (row.getAttribute('data-ip') || '').toLowerCase();
        const device = (row.getAttribute('data-device') || '').toLowerCase();
        const isAds = row.getAttribute('data-ads') === '1';
        const isSusp = row.getAttribute('data-suspicious') === '1';
        const isBlocked = row.getAttribute('data-blocked') === '1';

        let filterMatch = true;
        if (currentDailyFilter === 'ads') filterMatch = isAds;
        else if (currentDailyFilter === 'suspicious') filterMatch = isSusp;
        else if (currentDailyFilter === 'blocked') filterMatch = isBlocked;

        const searchMatch = !query || ip.includes(query) || device.includes(query);
        row.style.display = (filterMatch && searchMatch) ? '' : 'none';
      });
    }

    // Show Integration Code Modal
    function showIntegrationCode(siteId) {
      fetch('api.php?action=get_site_code&site_id=' + siteId)
        .then(r => r.json())
        .then(data => {
          if (!data.success) {
            alert(data.error || 'Hata oluştu.');
            return;
          }
          document.getElementById('codeModalTitle').textContent = data.site_name + ' - Takip & Koruma Kodları';
          document.getElementById('jsCodeBox').textContent = data.js_snippet.replace(/\\n/g, '\n');
          document.getElementById('phpCodeBox').textContent = data.php_include.replace(/\\n/g, '\n');
          document.getElementById('downloadClientBtn').href = data.download_url;
          switchCodeTab('js');
          openModal('codeModal');
        });
    }

    function switchCodeTab(tab) {
      document.getElementById('tabBtnJs').classList.remove('active');
      document.getElementById('tabBtnPhp').classList.remove('active');
      document.getElementById('tabBtnGtm').classList.remove('active');
      document.getElementById('codeTabJs').style.display = 'none';
      document.getElementById('codeTabPhp').style.display = 'none';
      document.getElementById('codeTabGtm').style.display = 'none';

      if (tab === 'js') {
        document.getElementById('tabBtnJs').classList.add('active');
        document.getElementById('codeTabJs').style.display = 'block';
      } else if (tab === 'php') {
        document.getElementById('tabBtnPhp').classList.add('active');
        document.getElementById('codeTabPhp').style.display = 'block';
      } else if (tab === 'gtm') {
        document.getElementById('tabBtnGtm').classList.add('active');
        document.getElementById('codeTabGtm').style.display = 'block';
      }
    }

    // Add Site Form
    document.getElementById('addSiteForm').addEventListener('submit', function(e) {
      e.preventDefault();
      const fd = new FormData(this);
      fetch('api.php?action=add_site', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            closeModal('addSiteModal');
            showIntegrationCode(data.site_id);
            setTimeout(() => { location.reload(); }, 6000);
          } else {
            alert(data.error || 'Site eklenemedi.');
          }
        });
    });

    // Toggle Block IP
    function toggleBlock(ip, siteId) {
      const fd = new FormData();
      fd.append('ip', ip);
      if (siteId) fd.append('site_id', siteId);

      fetch('api.php?action=toggle_block', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) location.reload();
          else alert(data.error || 'İşlem başarısız.');
        });
    }

    // Delete Site
    function deleteSite(siteId, siteName) {
      if (!confirm('"' + siteName + '" sitesini ve bu siteye ait tüm tıklama geçmişini kalıcı olarak silmek istediğinize emin misiniz?')) return;
      const fd = new FormData();
      fd.append('site_id', siteId);
      fetch('api.php?action=delete_site', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) location.reload();
          else alert(data.error || 'Silinemedi.');
        });
    }

    // Delete IP
    function deleteIP(ip, siteId) {
      if (!confirm(ip + ' kaydını silmek istediğinize emin misiniz?')) return;
      const fd = new FormData();
      fd.append('ip', ip);
      if (siteId) fd.append('site_id', siteId);
      fetch('api.php?action=delete_ip', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) location.reload();
          else alert(data.error || 'Silinemedi.');
        });
    }

    // Inspect IP
    function inspectIP(ip, siteId) {
      document.getElementById('historyModalTitle').textContent = ip + ' - Tıklama Detayları';
      document.getElementById('historyModalContent').innerHTML = '<div style="text-align:center; padding:20px;">Yükleniyor...</div>';
      openModal('historyModal');

      let url = 'api.php?action=get_ip_history&ip=' + encodeURIComponent(ip);
      if (siteId) url += '&site_id=' + siteId;

      fetch(url)
        .then(r => r.json())
        .then(data => {
          if (!data.success || !data.history || data.history.length === 0) {
            document.getElementById('historyModalContent').innerHTML = '<div style="text-align:center; color:#94a3b8; padding:20px;">Kayıt bulunamadı.</div>';
            return;
          }
          let html = '<div style="max-height: 440px; overflow-y: auto;">';
          data.history.forEach((h, idx) => {
            const isAds = parseInt(h.is_google_ads) === 1;
            html += '<div style="background:var(--bg-card-alt); border:1px solid var(--border-color); border-radius:6px; padding:10px 12px; margin-bottom:8px; font-size:0.82rem;">';
            html += '  <div style="display:flex; justify-content:space-between; margin-bottom:4px;">';
            html += '    <span style="color:#f59e0b; font-weight:700;">#' + (data.history.length - idx) + ' • ' + h.created_at + '</span>';
            html += '    <div><span class="badge badge-site" style="margin-right:4px;">' + escapeHtml(h.site_name) + '</span>' + (isAds ? '<span class="badge badge-ads">🎯 Google Ads</span>' : '<span class="badge badge-organic">🌐 Organik</span>') + '</div>';
            html += '  </div>';
            if (h.gclid) {
              html += '  <div style="color:#60a5fa; font-size:0.75rem; font-family:monospace;"><strong>gclid:</strong> ' + escapeHtml(h.gclid) + '</div>';
            }
            if (h.page_url) {
              html += '  <div style="color:#94a3b8; font-size:0.75rem; word-break:break-all;"><strong>URL:</strong> ' + escapeHtml(h.page_url) + '</div>';
            }
            html += '  <div style="font-size:0.74rem; color:#64748b; margin-top:2px;">' + escapeHtml(h.device_name + ' / ' + h.browser + ' / ' + h.os) + '</div>';
            html += '</div>';
          });
          html += '</div>';
          document.getElementById('historyModalContent').innerHTML = html;
        });
    }

    // Reset Confirm
    function confirmReset(mode) {
      if (!confirm('Bu sıfırlama işlemini gerçekleştirmek istediğinize emin misiniz?')) return;
      const fd = new FormData();
      fd.append('mode', mode);
      <?php if ($selectedSiteId > 0): ?>
      fd.append('site_id', <?= $selectedSiteId ?>);
      <?php endif; ?>

      fetch('api.php?action=reset_stats', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          alert(data.message || 'Tamamlandı.');
          if (data.success) location.reload();
        });
    }

    // Profile (Username & Password) Form
    const profForm = document.getElementById('profileForm') || document.getElementById('passwordForm');
    if (profForm) {
      profForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        fetch('api.php?action=update_profile', { method: 'POST', body: fd })
          .then(r => r.json())
          .then(data => {
            alert(data.message || data.error);
            if (data.success) {
              const oldPassInput = profForm.querySelector('input[name="old_password"]');
              const newPassInput = profForm.querySelector('input[name="new_password"]');
              if (oldPassInput) oldPassInput.value = '';
              if (newPassInput) newPassInput.value = '';
            }
          });
      });
    }

    // Theme Switcher (Aydınlık / Karanlık Tema)
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

    function escapeHtml(text) {
      if (!text) return '';
      return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
    }
  </script>
</body>
</html>
