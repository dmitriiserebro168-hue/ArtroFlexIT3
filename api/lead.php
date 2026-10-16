<?php
/*
 * Приём заявки и передача партнёру (сервер → сервер).
 * Успех ({"ok":true}) возвращается ТОЛЬКО после подтверждения приёма партнёром.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** Ответ лендингу. Если указан $log — сначала запись в журнал lead_log (как в it2). */
function out(int $code, array $body, ?string $log = null, array $logExtra = []): void
{
    if ($log !== null && isset($GLOBALS['cfg'])) lead_log($GLOBALS['cfg'], $log, $logExtra);
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_file(__DIR__ . '/config.php')) {
    out(500, ['ok' => false, 'error' => 'not_configured']);
}
$cfg = require __DIR__ . '/config.php';
date_default_timezone_set('Europe/Rome');
define('LP_DB', 1);
require __DIR__ . '/db.php';

const LOG_KEEP_DAYS   = 90;  // записи журнала старше — удаляются автоматически
const ALERT_EVERY_MIN = 30;  // письма об ошибках — не чаще

// Контекст текущей заявки для журнала и список попыток отправки
$LEAD = ['started' => microtime(true)];
$ATTEMPTS_LOG = [];

/** Запись о заявке в lead_log. Никогда не бросает исключений. */
function lead_log(array $cfg, string $status, array $extra = []): void
{
    global $LEAD, $ATTEMPTS_LOG;
    try {
        $pdo = lp_db($cfg);
        if ($pdo) {
            $st = $pdo->prepare('INSERT INTO lead_log
                (created_at, status, lead_id, guid, api_msg, name, phone, ip, utm, user_agent, referer, attempts, duration_ms)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $st->execute([
                gmdate('Y-m-d H:i:s'),
                $status,
                $LEAD['lead_id'] ?? null,
                isset($extra['guid']) ? substr((string)$extra['guid'], 0, 64) : null,
                isset($extra['api_msg']) ? mb_substr((string)$extra['api_msg'], 0, 255) : null,
                $LEAD['name'] ?? null,
                $LEAD['phone'] ?? null,
                $LEAD['ip'] ?? null,
                !empty($LEAD['utm']) ? json_encode($LEAD['utm'], JSON_UNESCAPED_UNICODE) : null,
                $LEAD['ua'] ?? null,
                $LEAD['ref'] ?? null,
                $ATTEMPTS_LOG ? json_encode($ATTEMPTS_LOG, JSON_UNESCAPED_UNICODE) : null,
                (int)round((microtime(true) - $LEAD['started']) * 1000),
            ]);
            if (mt_rand(1, 100) === 1) {
                $pdo->exec('DELETE FROM lead_log WHERE created_at < UTC_TIMESTAMP() - INTERVAL ' . LOG_KEEP_DAYS . ' DAY');
            }
        }
        if (in_array($status, ['api_error', 'network', 'config'], true)) {
            lead_alert($cfg, $status, (string)($extra['api_msg'] ?? ''));
        }
    } catch (Throwable $e) {
        error_log('lead.php: lead_log failed: ' . $e->getMessage());
    }
}

/** Письмо об ошибке отправки лида на 'alert_email', не чаще ALERT_EVERY_MIN минут. */
function lead_alert(array $cfg, string $status, string $apiMsg): void
{
    global $LEAD, $ATTEMPTS_LOG;
    if (empty($cfg['alert_email'])) return;
    $pdo = lp_db($cfg);
    if ($pdo) {
        $last = $pdo->query("SELECT v FROM lead_meta WHERE k = 'last_alert'")->fetchColumn();
        if ($last && time() - (int)$last < ALERT_EVERY_MIN * 60) return;
        $pdo->prepare("REPLACE INTO lead_meta (k, v) VALUES ('last_alert', ?)")->execute([(string)time()]);
    }
    $host  = preg_replace('/[^a-z0-9.\-]/i', '', $_SERVER['HTTP_HOST'] ?? 'site');
    $phone = isset($LEAD['phone']) ? substr($LEAD['phone'], 0, 6) . '…' . substr($LEAD['phone'], -2) : '';
    $lines = [
        'Время (UTC): ' . gmdate('Y-m-d H:i:s'),
        'Статус: ' . $status,
        'Ответ Webvork: ' . ($apiMsg !== '' ? $apiMsg : '—'),
        'Телефон: ' . $phone,
        'Попытки:',
    ];
    foreach ($ATTEMPTS_LOG as $a) {
        $lines[] = sprintf('  #%d %s HTTP %d, %d мс %s', $a['n'], $a['host'], $a['http'], $a['ms'], $a['error'] !== '' ? '(' . $a['error'] . ')' : '');
    }
    $lines[] = '';
    $lines[] = 'Подробности — https://' . $host . '/admin/stats.php или таблица lead_log в phpMyAdmin. Следующее письмо не раньше чем через ' . ALERT_EVERY_MIN . ' мин.';
    $subject = '=?UTF-8?B?' . base64_encode('[' . $host . '] Ошибка отправки лида: ' . $status) . '?=';
    @mail((string)$cfg['alert_email'], $subject, implode("\n", $lines),
        "From: noreply@{$host}\r\nContent-Type: text/plain; charset=UTF-8\r\n");
}

$dataDir = rtrim($cfg['data_dir'], '/\\');
foreach (['requests', 'logs'] as $sub) {
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

// Как в it2: оставить только цифры и «+», формат строго +39 и 10–11 цифр
function normalize_phone(string $raw): ?string
{
    $s = preg_replace('/[^\d+]/', '', $raw);
    return preg_match('/^\+39\d{10,11}$/', $s) ? $s : null;
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
        'curl'              => function_exists('curl_init'),
        'data_writable'     => is_writable($dataDir),
        'partner_reachable' => $reach,
        'db_configured'     => !empty($cfg['db']['name']),
        'db_connected'      => lp_db($cfg) !== null,
        'db_error'          => isset($GLOBALS['LP_DB_ERROR']) ? preg_replace('/\(using password.*$/', '', (string)$GLOBALS['LP_DB_ERROR']) : null,
        'alert_email_set'   => (string)($cfg['alert_email'] ?? '') !== '',
        'stats_password_set'=> (string)($cfg['stats_password'] ?? '') !== '',
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

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$now = time();

// Данные для журнала — как пришли, до проверки
$LEAD['name']  = mb_substr(trim((string)($in['name'] ?? '')), 0, 100);
$LEAD['phone'] = substr(preg_replace('/[^\d+]/', '', (string)($in['phone'] ?? '')), 0, 20);
$LEAD['ip']    = $ip;
$LEAD['ua']    = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
$LEAD['ref']   = mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 255);
$LEAD['utm']   = [];
foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'campaign_id', 'adset_id', 'ad_id', 'fbclid'] as $k) {
    $v = $in['params'][$k] ?? '';
    if (is_string($v) && $v !== '') $LEAD['utm'][$k] = mb_substr($v, 0, 200);
}

// Honeypot: боту отвечаем «успехом», ничего не отправляя
if (!empty($in['website'])) {
    log_event($dataDir, 'info', 'honeypot');
    out(200, ['ok' => true, 'lead_id' => uuid4()], 'bot');
}

// ---------- Валидация ----------
// Как в it2: имя — 2–60 символов после обрезки и схлопывания пробелов
$name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')));
$nameLen = mb_strlen($name, 'UTF-8');
if ($nameLen < 2 || $nameLen > 60) {
    out(422, ['ok' => false, 'error' => 'name', 'field' => 'name'], 'invalid_name');
}
$phone = normalize_phone((string)($in['phone'] ?? ''));
if ($phone === null) {
    out(422, ['ok' => false, 'error' => 'phone', 'field' => 'phone'], 'invalid_phone');
}
if (!empty($cfg['require_consent']) && ($in['consent'] ?? false) !== true) {
    out(422, ['ok' => false, 'error' => 'consent', 'field' => 'consent'], 'invalid_consent');
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
        $LEAD['lead_id'] = $prev['lead_id'];
        out(200, ['ok' => true, 'lead_id' => $prev['lead_id'], 'duplicate' => true], 'duplicate');
    }
}


// ---------- Формирование лида ----------
$params = is_array($in['params'] ?? null) ? $in['params'] : [];
$clean = function ($v) { return is_string($v) ? mb_substr(strip_tags($v), 0, 200) : null; };
$leadId = uuid4();
$LEAD['lead_id'] = $leadId;
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
    out(500, ['ok' => false, 'error' => 'not_configured'], 'config');
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
    $t0 = microtime(true);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, is_string($resp) ? $resp : '', $err, (int)round((microtime(true) - $t0) * 1000)];
}

$p = $cfg['partner'];
$accepted = false;
$attempts = 0;
$lastCode = 0;
$lastErr = '';
$urls = array_values(array_filter([$p['url'], $p['fallback_url'] ?? '']));
$resp = '';
$done = false;
$apiMsg = '';
$guid = '';
$apiRejected = false;
do {
    $attempts++;
    // Основной адрес, при сетевой/5xx ошибке — запасной (как в it2)
    foreach ($urls as $url) {
        [$lastCode, $resp, $lastErr, $ms] = partner_send($p, $url, $payload, $leadId);
        $ATTEMPTS_LOG[] = [
            'n' => $attempts, 'host' => parse_url($url, PHP_URL_HOST), 'http' => $lastCode,
            'ms' => $ms, 'error' => $lastErr, 'body' => mb_substr($resp, 0, 1000),
        ];
        $temporary = $lastCode === 0 || $lastCode === 429 || $lastCode >= 500;
        if ($lastCode >= 200 && $lastCode < 300) {
            $j = json_decode($resp, true);
            $accepted = true;
            if (!empty($p['success_check'])) {
                $accepted = is_array($j) && ($j[$p['success_check']['field']] ?? null) == $p['success_check']['value'];
            }
            if (is_array($j)) {
                $guid = (string)($j['guid'] ?? '');
                $apiMsg = (string)($j['msg'] ?? '');
            }
            $apiRejected = !$accepted; // Webvork ответил, но отклонил (дубль, неверный токен…)
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
    out(502, ['ok' => false, 'error' => 'partner'], $apiRejected ? 'api_error' : 'network', ['api_msg' => $apiMsg]);
}

// На диске — только хэш request_id и lead_id, без имени и телефона
@file_put_contents($reqFile, json_encode(['ok' => true, 'lead_id' => $leadId, 'at' => $now]), LOCK_EX);
log_event($dataDir, 'info', 'lead_accepted', ['lead_id' => $leadId, 'attempts' => $attempts, 'utm_campaign' => $lead['utm_campaign']]);

// Изредка чистим старые служебные файлы
if (random_int(1, 50) === 1) {
    $limit = $now - $cfg['retention_days'] * 86400;
    foreach (['requests'] as $sub) {
        foreach (glob("$dataDir/$sub/*") ?: [] as $f) {
            if (filemtime($f) < $limit) @unlink($f);
        }
    }
}

out(200, ['ok' => true, 'lead_id' => $leadId], 'ok', ['guid' => $guid]);
