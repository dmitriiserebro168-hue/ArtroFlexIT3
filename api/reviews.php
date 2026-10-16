<?php
/*
 * Отзывы: публичное чтение + редактирование после входа по паролю.
 *   GET  ?scope=public           — опубликованные отзывы (для лендинга)
 *   GET  ?scope=session          — {logged, csrf}
 *   GET  ?scope=all              — все отзывы (нужен вход)
 *   POST {action:"login", password}
 *   POST {action:"logout"}                         + X-CSRF-Token
 *   POST {action:"save", reviews:[...]}            + X-CSRF-Token
 *   POST multipart action=upload, file=<image>     + X-CSRF-Token
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$cfg = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : null;
if (!$cfg) out(500, ['ok' => false, 'error' => 'not_configured']);

$dataDir = rtrim($cfg['data_dir'], '/\\');
$file = "$dataDir/reviews.json";
$uploadDir = __DIR__ . '/../assets/img/reviews';
$uploadUrl = 'assets/img/reviews';

function load_reviews(string $file): array
{
    if (!is_file($file)) return [];
    $j = json_decode((string)file_get_contents($file), true);
    return is_array($j) ? $j : [];
}

// ---------- Сессия ----------
session_name('lp_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();
$logged = !empty($_SESSION['admin']) && ($_SESSION['exp'] ?? 0) > time();
if ($logged) $_SESSION['exp'] = time() + 3600 * 4; // 4 часа бездействия
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

function require_auth(bool $logged): void
{
    if (!$logged) out(401, ['ok' => false, 'error' => 'auth']);
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf'], $token)) out(403, ['ok' => false, 'error' => 'csrf']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ---------- Чтение ----------
if ($method === 'GET') {
    $scope = $_GET['scope'] ?? 'public';
    if ($scope === 'session') out(200, ['ok' => true, 'logged' => $logged, 'csrf' => $logged ? $_SESSION['csrf'] : null]);
    $all = load_reviews($file);
    if ($scope === 'all') {
        if (!$logged) out(401, ['ok' => false, 'error' => 'auth']);
        out(200, ['ok' => true, 'reviews' => $all]);
    }
    $pub = array_values(array_filter($all, fn($r) => !empty($r['published']) && !empty($r['consent'])));
    $pub = array_map(fn($r) => [
        'name' => $r['name'], 'age' => $r['age'], 'city' => $r['city'], 'text' => $r['text'],
        'photo' => $r['photo'], 'video' => $r['video'], 'subtitles' => $r['subtitles'],
    ], $pub);
    header('Cache-Control: public, max-age=60');
    out(200, ['ok' => true, 'reviews' => $pub]);
}

if ($method !== 'POST') out(405, ['ok' => false, 'error' => 'method']);

// ---------- Загрузка фото ----------
if (($_POST['action'] ?? '') === 'upload') {
    require_auth($logged);
    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) out(400, ['ok' => false, 'error' => 'upload']);
    if ($f['size'] > 3 * 1024 * 1024) out(413, ['ok' => false, 'error' => 'too_big']);
    $info = @getimagesize($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if (!$ext) out(415, ['ok' => false, 'error' => 'type']);
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], "$uploadDir/$name")) out(500, ['ok' => false, 'error' => 'save']);
    out(200, ['ok' => true, 'url' => "$uploadUrl/$name"]);
}

$in = json_decode((string)file_get_contents('php://input', false, null, 0, 512 * 1024), true);
if (!is_array($in)) out(400, ['ok' => false, 'error' => 'bad_request']);
$action = $in['action'] ?? '';

// ---------- Вход ----------
if ($action === 'login') {
    $ip = hash('sha256', $cfg['hash_salt'] . ($_SERVER['REMOTE_ADDR'] ?? ''));
    $rf = "$dataDir/rate/login-$ip.json";
    $now = time();
    $hits = is_file($rf) ? (json_decode((string)file_get_contents($rf), true) ?: []) : [];
    $hits = array_values(array_filter($hits, fn($t) => $t > $now - 900));
    if (count($hits) >= 5) out(429, ['ok' => false, 'error' => 'rate_limited']); // 5 попыток за 15 минут
    $expected = (string)($cfg['admin_password'] ?? '');
    if ($expected === '' || !hash_equals($expected, (string)($in['password'] ?? ''))) {
        $hits[] = $now;
        @file_put_contents($rf, json_encode($hits), LOCK_EX);
        usleep(500000);
        out(401, ['ok' => false, 'error' => 'wrong_password']);
    }
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['exp'] = $now + 3600 * 4;
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    out(200, ['ok' => true, 'csrf' => $_SESSION['csrf']]);
}

if ($action === 'logout') {
    require_auth($logged);
    $_SESSION = [];
    session_destroy();
    out(200, ['ok' => true]);
}

// ---------- Сохранение ----------
if ($action === 'save') {
    require_auth($logged);
    $list = $in['reviews'] ?? null;
    if (!is_array($list) || count($list) > 100) out(400, ['ok' => false, 'error' => 'bad_list']);
    $str = fn($v, $n) => mb_substr(trim(strip_tags((string)($v ?? ''))), 0, $n);
    $url = function ($v) {
        $v = trim((string)($v ?? ''));
        if ($v === '') return '';
        // только относительные пути внутри сайта или https
        return (preg_match('#^(assets/[\w./-]+|https://[^\s"<>]+)$#', $v)) ? $v : '';
    };
    $clean = [];
    foreach ($list as $r) {
        if (!is_array($r)) continue;
        $item = [
            'id'        => preg_match('/^[\w-]{1,40}$/', (string)($r['id'] ?? '')) ? $r['id'] : bin2hex(random_bytes(6)),
            'name'      => $str($r['name'] ?? '', 60),
            'age'       => max(0, min(120, (int)($r['age'] ?? 0))),
            'city'      => $str($r['city'] ?? '', 60),
            'text'      => $str($r['text'] ?? '', 1500),
            'photo'     => $url($r['photo'] ?? ''),
            'video'     => $url($r['video'] ?? ''),
            'subtitles' => $url($r['subtitles'] ?? ''),
            'consent'   => !empty($r['consent']),
            'source'    => $str($r['source'] ?? '', 200),
            'published' => !empty($r['published']),
            'updated_at'=> date('c'),
        ];
        // Опубликовать можно только отзыв с подтверждённым согласием автора
        if (!$item['consent']) $item['published'] = false;
        $clean[] = $item;
    }
    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) === false
        || !@rename($tmp, $file)) {
        out(500, ['ok' => false, 'error' => 'save']);
    }
    out(200, ['ok' => true, 'reviews' => $clean]);
}

out(400, ['ok' => false, 'error' => 'action']);
