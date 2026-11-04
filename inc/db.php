<?php
// Multi-Site AdsTracker Database & Initialization

function getTrackerDB() {
    static $db = null;
    if ($db !== null) {
        return $db;
    }

    $dbFile = dirname(__DIR__) . '/data/tracker.sqlite';
    $dbDir = dirname($dbFile);

    if (!is_dir($dbDir)) {
        @mkdir($dbDir, 0755, true);
    }

    $isNew = !file_exists($dbFile);

    try {
        $db = new PDO('sqlite:' . $dbFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // SQLite speed optimizations
        $db->exec('PRAGMA journal_mode = WAL;');
        $db->exec('PRAGMA synchronous = NORMAL;');

        if ($isNew || filesize($dbFile) === 0) {
            initTrackerDatabase($db);
        }
        return $db;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        return null;
    }
}

function initTrackerDatabase($db) {
    // 1. Sites Table
    $db->exec("CREATE TABLE IF NOT EXISTS sites (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site_key VARCHAR(64) UNIQUE NOT NULL,
        name VARCHAR(255) NOT NULL,
        domain VARCHAR(255) NOT NULL,
        click_threshold INTEGER DEFAULT 3,
        auto_block INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // 2. Visits Table
    $db->exec("CREATE TABLE IF NOT EXISTS visits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site_id INTEGER NOT NULL,
        ip VARCHAR(45) NOT NULL,
        is_google_ads INTEGER DEFAULT 0,
        gclid TEXT,
        utm_source VARCHAR(100),
        utm_campaign VARCHAR(100),
        device_type VARCHAR(20) DEFAULT 'desktop',
        device_name VARCHAR(100),
        os VARCHAR(50),
        browser VARCHAR(50),
        screen_res VARCHAR(30),
        page_url TEXT,
        referrer TEXT,
        user_agent TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
    );");

    // Indexes for fast querying
    $db->exec("CREATE INDEX IF NOT EXISTS idx_visits_site_id ON visits(site_id);");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_visits_ip ON visits(ip);");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_visits_created_at ON visits(created_at);");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_visits_ads ON visits(is_google_ads);");

    // 3. IP Aggregated Stats Table (Per Site)
    $db->exec("CREATE TABLE IF NOT EXISTS ip_stats (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site_id INTEGER NOT NULL,
        ip VARCHAR(45) NOT NULL,
        total_visits INTEGER DEFAULT 1,
        ads_clicks INTEGER DEFAULT 0,
        organic_visits INTEGER DEFAULT 0,
        device_type VARCHAR(20) DEFAULT 'desktop',
        device_name VARCHAR(100),
        browser VARCHAR(50),
        os VARCHAR(50),
        first_visit DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_visit DATETIME DEFAULT CURRENT_TIMESTAMP,
        is_suspicious INTEGER DEFAULT 0,
        is_blocked INTEGER DEFAULT 0,
        notes TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(site_id, ip),
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
    );");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_ip_stats_site_ip ON ip_stats(site_id, ip);");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ip_stats_suspicious ON ip_stats(is_suspicious);");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ip_stats_blocked ON ip_stats(is_blocked);");

    // 4. Admin Users Table
    $db->exec("CREATE TABLE IF NOT EXISTS admin_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username VARCHAR(64) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        email VARCHAR(120),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // Seed default admin: admin / admin
    $defaultUser = 'admin';
    $defaultPass = password_hash('admin', PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT OR IGNORE INTO admin_users (username, password_hash) VALUES (:u, :p)");
    $stmt->execute([':u' => $defaultUser, ':p' => $defaultPass]);

    // 5. Global Settings Table
    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(64) PRIMARY KEY,
        setting_value TEXT
    );");

    $db->exec("INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES 
        ('app_name', 'AdsTracker Pro'),
        ('global_threshold', '3'),
        ('auto_block_default', '0')
    ;");

    // Seed initial demo site
    $seedKey = 'cs_demo_' . bin2hex(random_bytes(8));
    $stmt = $db->prepare("INSERT OR IGNORE INTO sites (site_key, name, domain, click_threshold, auto_block) VALUES (:k, :n, :d, 3, 0)");
    $stmt->execute([
        ':k' => 'cs_site_bursahurda16',
        ':n' => 'Bursa Halis Hurdacılık',
        ':d' => 'bursa.hurdaci.name.tr'
    ]);
}
