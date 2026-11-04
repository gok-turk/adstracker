<?php
// Multi-Site AdsTracker Ingestion API

// Set CORS headers for cross-domain tracking
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

// Respond to preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(__DIR__) . '/inc/db.php';
require_once dirname(__DIR__) . '/inc/functions.php';

$db = getTrackerDB();
if (!$db) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Veritabanı bağlantı hatası.']);
    exit;
}

// Read payload from GET, POST, or raw JSON
$input = [];
$rawInput = file_get_contents('php://input');
if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

// Fallback / merge with $_REQUEST
$siteKey = $input['site_key'] ?? ($_REQUEST['site_key'] ?? '');
if (empty($siteKey)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Eksik parametre: site_key zorunludur.']);
    exit;
}

// Build hit data
$hitData = [
    'ip' => $input['ip'] ?? ($_REQUEST['ip'] ?? getClientIP()),
    'user_agent' => $input['user_agent'] ?? ($_REQUEST['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')),
    'page_url' => $input['page_url'] ?? ($_REQUEST['page_url'] ?? ($_SERVER['HTTP_REFERER'] ?? '')),
    'referrer' => $input['referrer'] ?? ($_REQUEST['referrer'] ?? ''),
    'screen_res' => $input['screen_res'] ?? ($_REQUEST['screen_res'] ?? ''),
    'gclid' => $input['gclid'] ?? ($_REQUEST['gclid'] ?? ''),
    'wbraid' => $input['wbraid'] ?? ($_REQUEST['wbraid'] ?? ''),
    'gbraid' => $input['gbraid'] ?? ($_REQUEST['gbraid'] ?? ''),
    'gad_source' => $input['gad_source'] ?? ($_REQUEST['gad_source'] ?? ''),
    'utm_source' => $input['utm_source'] ?? ($_REQUEST['utm_source'] ?? ''),
    'utm_campaign' => $input['utm_campaign'] ?? ($_REQUEST['utm_campaign'] ?? ''),
    'utm_medium' => $input['utm_medium'] ?? ($_REQUEST['utm_medium'] ?? '')
];

// If gclid not passed explicitly, extract from page_url
if (empty($hitData['gclid']) && !empty($hitData['page_url'])) {
    $parsedQuery = parse_url($hitData['page_url'], PHP_URL_QUERY);
    if ($parsedQuery) {
        parse_str($parsedQuery, $urlParams);
        if (!empty($urlParams['gclid'])) $hitData['gclid'] = $urlParams['gclid'];
        if (!empty($urlParams['wbraid'])) $hitData['wbraid'] = $urlParams['wbraid'];
        if (!empty($urlParams['gbraid'])) $hitData['gbraid'] = $urlParams['gbraid'];
        if (!empty($urlParams['gad_source'])) $hitData['gad_source'] = $urlParams['gad_source'];
        if (!empty($urlParams['utm_source'])) $hitData['utm_source'] = $urlParams['utm_source'];
        if (!empty($urlParams['utm_campaign'])) $hitData['utm_campaign'] = $urlParams['utm_campaign'];
        if (!empty($urlParams['utm_medium'])) $hitData['utm_medium'] = $urlParams['utm_medium'];
    }
}

// Process hit
$result = processSiteHit($db, $siteKey, $hitData);

if (!$result['success']) {
    http_response_code(404);
    echo json_encode($result);
    exit;
}

// Output response
echo json_encode([
    'success' => true,
    'site_name' => $result['site_name'],
    'ip' => $result['ip'],
    'is_google_ads' => $result['is_google_ads'],
    'is_suspicious' => $result['is_suspicious'],
    'blocked' => $result['blocked'],
    'ads_clicks_count' => $result['ads_clicks_count']
]);
