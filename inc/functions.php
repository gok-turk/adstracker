<?php
// Multi-Site AdsTracker Helper Functions

/**
 * Get accurate client IP address
 */
function getClientIP() {
    $headers = [
        'HTTP_CF_CONNECTING_IP', // Cloudflare
        'HTTP_TRUE_CLIENT_IP',   // Akamai / Cloudflare Enterprise
        'HTTP_X_REAL_IP',        // Nginx reverse proxy
        'HTTP_X_FORWARDED_FOR',  // Standard proxy / load balancer
        'REMOTE_ADDR'            // Direct connection
    ];

    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ipList = explode(',', $_SERVER[$header]);
            foreach ($ipList as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
            $first = trim($ipList[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * Parse User Agent for device, OS, and browser
 */
function parseUserAgent($ua = null) {
    if ($ua === null) {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    }

    $deviceType = 'desktop';
    $deviceName = 'Masaüstü PC';
    $os = 'Bilinmiyor';
    $browser = 'Bilinmiyor';

    // 1. Bot check
    if (preg_match('/(bot|crawler|spider|slurp|facebookexternalhit|whatsapp|telegrambot|curl|wget)/i', $ua)) {
        return [
            'device_type' => 'bot',
            'device_name' => 'Web Bot / Örümcek',
            'os' => 'Robot',
            'browser' => 'Bot'
        ];
    }

    // 2. OS detection
    if (preg_match('/iPhone/i', $ua)) {
        $deviceType = 'mobile';
        $deviceName = 'Apple iPhone';
        $os = 'iOS (iPhone)';
    } elseif (preg_match('/iPad/i', $ua)) {
        $deviceType = 'tablet';
        $deviceName = 'Apple iPad';
        $os = 'iPadOS';
    } elseif (preg_match('/Android/i', $ua)) {
        $os = 'Android';
        if (preg_match('/Mobile/i', $ua)) {
            $deviceType = 'mobile';
            $deviceName = 'Android Telefon';
        } else {
            $deviceType = 'tablet';
            $deviceName = 'Android Tablet';
        }
        if (preg_match('/SM-[A-Z0-9]+/i', $ua, $m)) {
            $deviceName = 'Samsung (' . $m[0] . ')';
        } elseif (preg_match('/Redmi[ a-zA-Z0-9]+/i', $ua, $m)) {
            $deviceName = 'Xiaomi ' . trim($m[0]);
        } elseif (preg_match('/POCO[ a-zA-Z0-9]+/i', $ua, $m)) {
            $deviceName = 'Xiaomi ' . trim($m[0]);
        }
    } elseif (preg_match('/Windows NT 10.0/i', $ua)) {
        $os = 'Windows 10/11';
        $deviceName = 'Windows PC';
    } elseif (preg_match('/Windows NT 6.3/i', $ua)) {
        $os = 'Windows 8.1';
        $deviceName = 'Windows PC';
    } elseif (preg_match('/Windows NT 6.1/i', $ua)) {
        $os = 'Windows 7';
        $deviceName = 'Windows PC';
    } elseif (preg_match('/Macintosh|Mac OS X/i', $ua)) {
        $os = 'macOS';
        $deviceName = 'Apple Mac';
    } elseif (preg_match('/Linux/i', $ua)) {
        $os = 'Linux';
        $deviceName = 'Linux PC';
    }

    // 3. Browser detection
    if (preg_match('/Edg\/([0-9.]+)/i', $ua)) {
        $browser = 'Microsoft Edge';
    } elseif (preg_match('/Chrome\/([0-9.]+)/i', $ua)) {
        $browser = 'Google Chrome';
    } elseif (preg_match('/Safari\/([0-9.]+)/i', $ua) && !preg_match('/Chrome/i', $ua)) {
        $browser = 'Apple Safari';
    } elseif (preg_match('/Firefox\/([0-9.]+)/i', $ua)) {
        $browser = 'Mozilla Firefox';
    } elseif (preg_match('/OPR\/([0-9.]+)/i', $ua)) {
        $browser = 'Opera';
    }

    return [
        'device_type' => $deviceType,
        'device_name' => $deviceName,
        'os' => $os,
        'browser' => $browser
    ];
}

/**
 * Determine if a visit is from Google Ads
 */
function isGoogleAdsVisit($data = []) {
    // 1. Explicit GCLID / Google Ads tracking tokens
    if (!empty($data['gclid']) || !empty($data['wbraid']) || !empty($data['gbraid']) || !empty($data['gad_source'])) {
        return true;
    }

    // 2. UTM campaign parameters
    $source = strtolower($data['utm_source'] ?? '');
    $medium = strtolower($data['utm_medium'] ?? '');
    if ($source === 'google' && in_array($medium, ['cpc', 'cpm', 'ppc', 'paid', 'ads', 'adwords'])) {
        return true;
    }

    // 3. URL string check
    $url = $data['page_url'] ?? '';
    if (preg_match('/[?&](gclid|wbraid|gbraid|gad_source)=/i', $url)) {
        return true;
    }

    // 4. Referrer check
    $ref = $data['referrer'] ?? '';
    if (preg_match('/googleadservices\.com|googlesyndication\.com/i', $ref)) {
        return true;
    }

    return false;
}

/**
 * Process and record incoming hit for a site
 */
function processSiteHit($db, $siteKey, $hit) {
    // 1. Verify Site Key
    $stmt = $db->prepare("SELECT * FROM sites WHERE site_key = :k AND is_active = 1 LIMIT 1");
    $stmt->execute([':k' => $siteKey]);
    $site = $stmt->fetch();

    if (!$site) {
        return ['success' => false, 'error' => 'Geçersiz veya pasif site anahtarı (site_key).', 'blocked' => false];
    }

    $siteId = (int)$site['id'];
    $threshold = (int)($site['click_threshold'] ?? 3);
    if ($threshold < 1) $threshold = 3;
    $autoBlock = (int)($site['auto_block'] ?? 0) === 1;

    // 2. Extract hit details
    $ip = $hit['ip'] ?? getClientIP();
    $ua = $hit['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $device = parseUserAgent($ua);

    $isAds = isGoogleAdsVisit($hit);
    $gclid = $hit['gclid'] ?? '';
    if (!$gclid && !empty($hit['page_url'])) {
        if (preg_match('/[?&]gclid=([^&#]+)/i', $hit['page_url'], $m)) {
            $gclid = urldecode($m[1]);
        }
    }

    $utmSource = $hit['utm_source'] ?? '';
    $utmCampaign = $hit['utm_campaign'] ?? '';
    $screenRes = $hit['screen_res'] ?? '';
    $pageUrl = $hit['page_url'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    $referrer = $hit['referrer'] ?? '';

    // 3. Check existing IP status for this site
    $chkStmt = $db->prepare("SELECT * FROM ip_stats WHERE site_id = :sid AND ip = :ip LIMIT 1");
    $chkStmt->execute([':sid' => $siteId, ':ip' => $ip]);
    $ipStat = $chkStmt->fetch();

    $isBlocked = $ipStat ? (int)$ipStat['is_blocked'] : 0;
    $currentAdsClicks = $ipStat ? (int)$ipStat['ads_clicks'] : 0;
    $newAdsClicks = $currentAdsClicks + ($isAds ? 1 : 0);
    $isSuspicious = ($newAdsClicks >= $threshold) ? 1 : ($ipStat ? (int)$ipStat['is_suspicious'] : 0);

    // Auto-block trigger if enabled on site
    if ($autoBlock && $isSuspicious && !$isBlocked) {
        $isBlocked = 1;
    }

    // 4. Insert into visits
    $insVisit = $db->prepare("INSERT INTO visits (
        site_id, ip, is_google_ads, gclid, utm_source, utm_campaign,
        device_type, device_name, os, browser, screen_res, page_url, referrer, user_agent, created_at
    ) VALUES (
        :sid, :ip, :is_ads, :gclid, :usrc, :ucamp,
        :dtype, :dname, :os, :browser, :sres, :purl, :ref, :ua, DATETIME('now')
    )");

    $insVisit->execute([
        ':sid' => $siteId,
        ':ip' => $ip,
        ':is_ads' => $isAds ? 1 : 0,
        ':gclid' => $gclid,
        ':usrc' => $utmSource,
        ':ucamp' => $utmCampaign,
        ':dtype' => $device['device_type'],
        ':dname' => $device['device_name'],
        ':os' => $device['os'],
        ':browser' => $device['browser'],
        ':sres' => $screenRes,
        ':purl' => $pageUrl,
        ':ref' => $referrer,
        ':ua' => $ua
    ]);

    // 5. Update or Insert ip_stats
    if ($ipStat) {
        $upd = $db->prepare("UPDATE ip_stats SET 
            total_visits = total_visits + 1,
            ads_clicks = ads_clicks + :is_ads,
            organic_visits = organic_visits + :is_org,
            device_type = :dtype,
            device_name = :dname,
            browser = :browser,
            os = :os,
            last_visit = DATETIME('now'),
            is_suspicious = :is_susp,
            is_blocked = :is_blk,
            updated_at = DATETIME('now')
            WHERE site_id = :sid AND ip = :ip
        ");
        $upd->execute([
            ':is_ads' => $isAds ? 1 : 0,
            ':is_org' => $isAds ? 0 : 1,
            ':dtype' => $device['device_type'],
            ':dname' => $device['device_name'],
            ':browser' => $device['browser'],
            ':os' => $device['os'],
            ':is_susp' => $isSuspicious,
            ':is_blk' => $isBlocked,
            ':sid' => $siteId,
            ':ip' => $ip
        ]);
    } else {
        $insStat = $db->prepare("INSERT INTO ip_stats (
            site_id, ip, total_visits, ads_clicks, organic_visits,
            device_type, device_name, browser, os, first_visit, last_visit,
            is_suspicious, is_blocked, updated_at
        ) VALUES (
            :sid, :ip, 1, :is_ads, :is_org,
            :dtype, :dname, :browser, :os, DATETIME('now'), DATETIME('now'),
            :is_susp, :is_blk, DATETIME('now')
        )");
        $insStat->execute([
            ':sid' => $siteId,
            ':ip' => $ip,
            ':is_ads' => $isAds ? 1 : 0,
            ':is_org' => $isAds ? 0 : 1,
            ':dtype' => $device['device_type'],
            ':dname' => $device['device_name'],
            ':browser' => $device['browser'],
            ':os' => $device['os'],
            ':is_susp' => $isSuspicious,
            ':is_blk' => $isBlocked
        ]);
    }

    return [
        'success' => true,
        'site_id' => $siteId,
        'site_name' => $site['name'],
        'ip' => $ip,
        'is_google_ads' => $isAds,
        'is_suspicious' => (bool)$isSuspicious,
        'blocked' => (bool)$isBlocked,
        'ads_clicks_count' => $newAdsClicks
    ];
}

/**
 * Rebuild IP statistics for a specific site or all sites
 */
function rebuildSiteStats($db, $siteId = null) {
    $where = $siteId ? "WHERE site_id = " . (int)$siteId : "";
    
    // Save blocked IPs
    $blockedQuery = "SELECT site_id, ip FROM ip_stats WHERE is_blocked = 1 " . ($siteId ? "AND site_id = " . (int)$siteId : "");
    $blockedList = $db->query($blockedQuery)->fetchAll() ?: [];

    // Delete existing
    $db->exec("DELETE FROM ip_stats " . $where . ";");

    // Re-aggregate from visits
    $sites = $siteId ? [$db->query("SELECT * FROM sites WHERE id = " . (int)$siteId)->fetch()] : $db->query("SELECT * FROM sites")->fetchAll();

    foreach ($sites as $st) {
        $sId = (int)$st['id'];
        $thresh = (int)($st['click_threshold'] ?? 3);
        if ($thresh < 1) $thresh = 3;

        $db->exec("INSERT INTO ip_stats (
            site_id, ip, total_visits, ads_clicks, organic_visits,
            device_type, device_name, browser, os, first_visit, last_visit,
            is_suspicious, is_blocked
        )
        SELECT 
            {$sId} as site_id,
            v.ip,
            COUNT(*) as total_visits,
            SUM(CASE WHEN v.is_google_ads = 1 THEN 1 ELSE 0 END) as ads_clicks,
            SUM(CASE WHEN v.is_google_ads = 0 THEN 1 ELSE 0 END) as organic_visits,
            (SELECT device_type FROM visits WHERE site_id = {$sId} AND ip = v.ip ORDER BY id DESC LIMIT 1) as device_type,
            (SELECT device_name FROM visits WHERE site_id = {$sId} AND ip = v.ip ORDER BY id DESC LIMIT 1) as device_name,
            (SELECT browser FROM visits WHERE site_id = {$sId} AND ip = v.ip ORDER BY id DESC LIMIT 1) as browser,
            (SELECT os FROM visits WHERE site_id = {$sId} AND ip = v.ip ORDER BY id DESC LIMIT 1) as os,
            MIN(v.created_at) as first_visit,
            MAX(v.created_at) as last_visit,
            CASE WHEN SUM(CASE WHEN v.is_google_ads = 1 THEN 1 ELSE 0 END) >= {$thresh} THEN 1 ELSE 0 END as is_suspicious,
            0 as is_blocked
        FROM visits v
        WHERE v.site_id = {$sId}
        GROUP BY v.ip;");
    }

    // Reapply blocked flags
    if (!empty($blockedList)) {
        $upd = $db->prepare("UPDATE ip_stats SET is_blocked = 1 WHERE site_id = :sid AND ip = :ip");
        foreach ($blockedList as $blk) {
            $upd->execute([':sid' => $blk['site_id'], ':ip' => $blk['ip']]);
        }
    }
}
