<?php
/**
 * AdsTracker Pro - Target Website PHP Shield Client
 * 
 * Sunucu Seviyesinde Google Ads Sahte Tıklama & IP Engelleme Dosyası
 * 
 * Kurulum:
 * 1. Bu dosyayı hedef sitenizin ana dizinine (public_html veya root) atın.
 * 2. Aşağıdaki $ADSTRACKER_API ve $ADSTRACKER_KEY değerlerini kontrol edin.
 * 3. Hedef sitenizin index.php veya wp-config.php dosyasının EN BAŞINA şu satırı ekleyin:
 *    require_once __DIR__ . '/shield-client.php';
 */

// ==========================================
// SİTE YAPILANDIRMASI
// ==========================================
$ADSTRACKER_API = "http://localhost/adstracker/api/collect.php";
$ADSTRACKER_KEY = "BURAYA_SITE_ANAHTARINI_YAZIN";

// ==========================================
// ENGINE (DOKUNMAYIN)
// ==========================================
(function($apiUrl, $siteKey) {
    if (empty($siteKey) || $siteKey === 'BURAYA_SITE_ANAHTARINI_YAZIN') {
        return;
    }

    // Gerçek İstemci IP Adresini Belirle
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'];
    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            $parts = explode(',', $_SERVER[$h]);
            $candidate = trim($parts[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                $ip = $candidate;
                break;
            }
        }
    }

    // Google Ads Parametrelerini Al
    $gclid = $_GET['gclid'] ?? '';
    $wbraid = $_GET['wbraid'] ?? '';
    $gbraid = $_GET['gbraid'] ?? '';
    $gadSource = $_GET['gad_source'] ?? '';
    $utmSource = $_GET['utm_source'] ?? '';
    $utmCampaign = $_GET['utm_campaign'] ?? '';

    $fullUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

    $payload = json_encode([
        'site_key' => $siteKey,
        'ip' => $ip,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'page_url' => $fullUrl,
        'referrer' => $_SERVER['HTTP_REFERER'] ?? '',
        'gclid' => $gclid,
        'wbraid' => $wbraid,
        'gbraid' => $gbraid,
        'gad_source' => $gadSource,
        'utm_source' => $utmSource,
        'utm_campaign' => $utmCampaign
    ]);

    // Tracker sunucusuna hızlı istek gönder
    $response = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Content-Length: ' . strlen($payload)]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2); // 2 saniye maksimum bekleme
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        $response = curl_exec($ch);
        curl_close($ch);
    } else {
        $opts = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n",
                'content' => $payload,
                'timeout' => 2
            ]
        ];
        $context = stream_context_create($opts);
        $response = @file_get_contents($apiUrl, false, $context);
    }

    if ($response) {
        $data = json_decode($response, true);
        if ($data && !empty($data['blocked'])) {
            http_response_code(403);
            header('HTTP/1.1 403 Forbidden');
            die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>403 Kısıtlandı</title><style>body{background:#0b1120;color:#f8fafc;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;}div{background:#1e293b;padding:40px;border-radius:12px;text-align:center;max-width:500px;box-shadow:0 10px 30px rgba(0,0,0,0.5);border:1px solid #334155;}h1{color:#ef4444;font-size:1.6rem;margin-top:0;}p{color:#94a3b8;line-height:1.6;font-size:0.95rem;}</style></head><body><div><h1>🛑 403 - Erişim Kısıtlandı</h1><p>Güvenlik ve reklam doğrulama protokolü gereğince, IP adresinizden gelen şüpheli veya geçersiz tıklamalar nedeniyle bu web sitesine erişiminiz sınırlandırılmıştır.</p><small style="color:#64748b;">AdsTracker Security System - Blocked IP: ' . htmlspecialchars($ip) . '</small></div></body></html>');
        }
    }
})($ADSTRACKER_API, $ADSTRACKER_KEY);
