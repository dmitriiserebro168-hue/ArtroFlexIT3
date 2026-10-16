<?php
/* Серверное время и параметры акции для таймера и остатка (часовой пояс Europe/Rome). */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$cfg = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : ['offer' => ['active' => false]];
$tz = new DateTimeZone('Europe/Rome');
$now = new DateTimeImmutable('now', $tz);
$o = $cfg['offer'];

$endsAt = !empty($o['ends_at']) ? new DateTimeImmutable($o['ends_at'], $tz) : null;
$stock = isset($o['stock']) ? max(0, (int)$o['stock']) : null;
$active = !empty($o['active']) && $endsAt !== null && $endsAt > $now && $stock !== 0;

echo json_encode([
    'now'     => $now->format(DATE_ATOM),
    'ends_at' => $endsAt ? $endsAt->format(DATE_ATOM) : null,
    'stock'   => $stock,
    'active'  => $active,
]);
