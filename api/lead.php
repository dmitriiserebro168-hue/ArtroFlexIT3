<?php
/*
 * Приём заявки и передача партнёру (сервер → сервер).
 * Успех ({"ok":true}) возвращается ТОЛЬКО после подтверждения приёма партнёром.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_file(__DIR__ . '/config.php')) {
    out(500, ['ok' => false, 'error' => 'not_configured']);
}
$cfg = require __DIR__ . '/config.php';
date_default_timezone_set('Europe/Rome');

$dataDir = rtrim($cfg['data_dir'], '/\\');
foreach (['requests', 'phones', 'rate', 'logs'] as $sub) {
    if (!is_dir("$dataDir/$sub")) @mkdir("$dataDir/$sub", 0750, true);
}

function log_event(string $dir, string $level, string $msg, array $ctx = []): void
{
    $line = date('c') . " [$level] $msg " . json_encode($ctx, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    @file_put_contents("$dir/logs/" . date('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
}

function mask_phone(string $p): string
{
    return substr($p, 0, 5) . str_repeat('*', max(0, strlen($p) - 7)) . substr($p, -2);
}

function normalize_phone(string $raw): ?string
{
    $s = preg_replace('/[\s.\-\/()]/', '', $raw);
    if (strpos($s, '+39') === 0) $s = substr($s, 3);
    elseif (strpos($s, '0039') === 0) $s = substr($s, 4);
    elseif (strpos($s, '+') === 0) return null;
    if (!preg_match('/^\d+$/', $s)) return null;
    if (preg_match('/^3\d{8,9}$/', $s) || preg_match('/^0\d{5,10}$/', $s)) return '+39' . $s;
    return null;
}

function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

// ---------- Базовые проверки запроса ----------
// lead.php?health=config — проверка настроек без отправки лида и без раскрытия секретов
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['health'] ?? '') === 'config') {
    $reach = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($cfg['partner']['url']);
        curl_setopt_array($ch, [CURLOPT_CONNECT_ONLY => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 8]);
        $reach = curl_exec($ch) !== false;
        curl_close($ch);
    }
    out(200, [
        'ok'                => true,
        'secrets_file'      => is_file(dirname(__DIR__, 2) . '/artroflex3-config.php'),
        'token_set'         => (string)($cfg['partner']['static']['token'] ?? '') !== '',
        'offer_id'          => (string)($cfg['partner']['static']['offer_id'] ?? ''),
        'admin_password_set'=> (string)($cfg['admin_password'] ?? '') !== '',
        'salt_custom'       => $cfg['hash_salt'] !== 'artroflex-it3-fallback-salt',
        'curl'              => function_exists('curl_init'),
        'data_writable'     => is_writable($dataDir),
        'partner_reachable' => $reach,
        'php'               => PHP_VERSION,
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    out(405, ['ok' => false, 'error' => 'method']);
}
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && !empty($cfg['allowed_origins']) && !in_array($origin, $cfg['allowed_origins'], true)) {
    out(403, ['ok' => false, 'error' => 'origin']);
}
$raw = file_get_contents('php://input', false, null, 0, 8192);
$in = json_decode((string)$raw, true);
if (!is_array($in)) {
    out(400, ['ok' => false, 'error' => 'bad_request']);
}

// Honeypot: боту отвечаем «успехом», ничего не отправляя
if (!empty($in['website'])) {
    log_event($dataDir, 'info', 'honeypot');
    out(200, ['ok' => true, 'lead_id' => uuid4()]);
}

// ---------- Ограничение частоты по IP ----------
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ipHash = hash('sha256', $cfg['hash_salt'] . $ip);
$rateFile = "$dataDir/rate/$ipHash.json";
$now = time();
$hits = is_file($rateFile) ? (json_decode((string)file_get_contents($rateFile), true) ?: []) : [];
$hits = array_values(array_filter($hits, fn($t) => $t > $now - $cfg['rate_limit']['window']));
if (count($hits) >= $cfg['rate_limit']['max']) {
    out(429, ['ok' => false, 'error' => 'rate_limited']);
}
$hits[] = $now;
@file_put_contents($rateFile, json_encode($hits), LOCK_EX);

// ---------- Валидация ----------
$name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')));
if (!preg_match("/^\p{L}[\p{L} '’.\-]{1,59}$/u", $name)) {
    out(422, ['ok' => false, 'error' => 'name', 'field' => 'name']);
}
$phone = normalize_phone((string)($in['phone'] ?? ''));
if ($phone === null) {
    out(422, ['ok' => false, 'error' => 'phone', 'field' => 'phone']);
}
if (!empty($cfg['require_consent']) && ($in['consent'] ?? false) !== true) {
    out(422, ['ok' => false, 'error' => 'consent', 'field' => 'consent']);
}
$requestId = (string)($in['request_id'] ?? '');
if (!preg_match('/^[0-9a-f-]{36}$/i', $requestId)) {
    out(400, ['ok' => false, 'error' => 'request_id']);
}

// ---------- Идемпотентность: тот же request_id → тот же ответ ----------
$reqFile = "$dataDir/requests/" . hash('sha256', $requestId) . '.json';
$lock = fopen($reqFile . '.lock', 'c');
flock($lock, LOCK_EX);
if (is_file($reqFile)) {
    $prev = json_decode((string)file_get_contents($reqFile), true);
    if (!empty($prev['ok'])) {
        out(200, ['ok' => true, 'lead_id' => $prev['lead_id'], 'duplicate' => true]);
    }
}

// ---------- Дубль по телефону ----------
$phoneHash = hash('sha256', $cfg['hash_salt'] . $phone);
$phoneFile = "$dataDir/phones/$phoneHash.json";
if (is_file($phoneFile)) {
    $seen = json_decode((string)file_get_contents($phoneFile), true);
    if ($seen && ($now - (int)$seen['at']) < $cfg['dedupe_hours'] * 3600) {
        log_event($dataDir, 'info', 'duplicate_phone', ['lead_id' => $seen['lead_id']]);
        out(200, ['ok' => true, 'lead_id' => $seen['lead_id'], 'duplicate' => true]);
    }
}

// ---------- Формирование лида ----------
$params = is_array($in['params'] ?? null) ? $in['params'] : [];
$clean = function ($v) { return is_string($v) ? mb_substr(strip_tags($v), 0, 200) : null; };
$leadId = uuid4();
$createdAt = date('c');
$lead = [
    'lead_id'         => $leadId,
    'name'            => $name,
    'phone'           => $phone,
    'created_at'      => $createdAt,
    'offer_id'        => $clean($in['offer_id'] ?? null),
    'landing_version' => $clean($in['landing_version'] ?? null),
    'campaign_id'     => $clean($params['campaign_id'] ?? null),
    'ad_id'           => $clean($params['ad_id'] ?? null),
    'utm_source'      => $clean($params['utm_source'] ?? null),
    'utm_medium'      => $clean($params['utm_medium'] ?? null),
    'utm_campaign'    => $clean($params['utm_campaign'] ?? null),
    'utm_content'     => $clean($params['utm_content'] ?? null),
    'utm_term'        => $clean($params['utm_term'] ?? null),
    'country'         => 'IT',
    'ip'              => $ip,
    'consent'         => ($in['consent'] ?? null) === true,
    'consent_at'      => $createdAt,
    'privacy_version' => $clean($in['privacy_version'] ?? null),
    'offer_expires_at'=> $clean($in['offer_expires_at'] ?? null),
];

$payload = $cfg['partner']['static'];
if (array_key_exists('token', $payload) && (string)$payload['token'] === '') {
    log_event($dataDir, 'error', 'no_partner_token');
    out(500, ['ok' => false, 'error' => 'not_configured']);
}
foreach ($cfg['partner']['map'] as $ours => $theirs) {
    if ($theirs !== null && $theirs !== '' && array_key_exists($ours, $lead)) $payload[$theirs] = $lead[$ours];
}

// ---------- Отправка партнёру с повтором при временной ошибке ----------
function partner_send(array $p, string $url, array $payload, string $leadId): array
{
    $isJson = $p['format'] === 'json';
    $body = $isJson ? json_encode($payload, JSON_UNESCAPED_UNICODE) : http_build_query($payload);
    $headers = [
        'Content-Type: ' . ($isJson ? 'application/json' : 'application/x-www-form-urlencoded'),
        'Accept: application/json',
        'Idempotency-Key: ' . $leadId,
    ];
    if (!empty($p['auth_header']) && trim((string)$p['auth_value']) !== 'Bearer') {
        $headers[] = $p['auth_header'] . ': ' . $p['auth_value'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $p['method'],
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => (int)$p['timeout'],
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, is_string($resp) ? $resp : '', $err];
}

$p = $cfg['partner'];
$accepted = false;
$attempts = 0;
$lastCode = 0;
$lastErr = '';
$urls = array_values(array_filter([$p['url'], $p['fallback_url'] ?? '']));
$resp = '';
$done = false;
do {
    $attempts++;
    // Основной адрес, при сетевой/5xx ошибке — запасной (как в it2)
    foreach ($urls as $url) {
        [$lastCode, $resp, $lastErr] = partner_send($p, $url, $payload, $leadId);
        $temporary = $lastCode === 0 || $lastCode === 429 || $lastCode >= 500;
        if ($lastCode >= 200 && $lastCode < 300) {
            $accepted = true;
            if (!empty($p['success_check'])) {
                $j = json_decode($resp, true);
                $accepted = is_array($j) && ($j[$p['success_check']['field']] ?? null) == $p['success_check']['value'];
            }
            $done = true;
            break;
        }
        if (!$temporary) { $done = true; break; }
    }
    if ($done) break;
    if ($attempts <= $p['retries']) sleep(2);
} while ($attempts <= $p['retries']);

if (!$accepted) {
    log_event($dataDir, 'error', 'partner_failed', [
        'lead_id' => $leadId, 'phone' => mask_phone($phone),
        'http' => $lastCode, 'curl' => $lastErr, 'attempts' => $attempts,
        'resp' => mb_substr((string)($resp ?? ''), 0, 300),
    ]);
    @file_put_contents($reqFile, json_encode(['ok' => false, 'at' => $now]), LOCK_EX);
    out(502, ['ok' => false, 'error' => 'partner']);
}

// На диске — только хэши и lead_id, без имени и телефона
@file_put_contents($reqFile, json_encode(['ok' => true, 'lead_id' => $leadId, 'at' => $now]), LOCK_EX);
@file_put_contents($phoneFile, json_encode(['lead_id' => $leadId, 'at' => $now]), LOCK_EX);
log_event($dataDir, 'info', 'lead_accepted', ['lead_id' => $leadId, 'attempts' => $attempts, 'utm_campaign' => $lead['utm_campaign']]);

// Изредка чистим старые служебные файлы
if (random_int(1, 50) === 1) {
    $limit = $now - $cfg['retention_days'] * 86400;
    foreach (['requests', 'phones', 'rate'] as $sub) {
        foreach (glob("$dataDir/$sub/*") ?: [] as $f) {
            if (filemtime($f) < $limit) @unlink($f);
        }
    }
}

out(200, ['ok' => true, 'lead_id' => $leadId]);
