<?php
/*
 * Настройки сервера. Файл хранится в git, секретов в нём нет.
 * Секреты (пароли, ключи) берутся из api/secrets.php — его создаёт GitHub Actions
 * из секретов репозитория при каждой выкладке. Локально можно создать его вручную.
 */
return (function (): array {
$secret = is_file(__DIR__ . '/secrets.php') ? require __DIR__ . '/secrets.php' : [];
$env = function (string $key, string $default = '') use ($secret): string {
    $v = (string)($secret[$key] ?? '');
    if ($v !== '') return $v;
    $e = getenv($key);
    return $e !== false && $e !== '' ? $e : $default;
};

return [
    // ---------- Акция (общая дата; при offer.personalHours > 0 в config.js таймер персональный) ----------
    'offer' => [
        'active'          => true,
        'ends_at'         => '2026-10-31 23:59:59',   // время Europe/Rome
        'stock'           => 17,                      // остаток по акционной цене
        'stock_updated_at'=> '2026-10-16',            // дата последней сверки остатка
    ],

    // ---------- Партнёрская программа ----------
    'partner' => [
        'url'          => $env('PARTNER_URL', 'https://partner.example/api/leads'),
        'method'       => 'POST',
        'format'       => 'json',                     // 'json' или 'form'
        'auth_header'  => 'Authorization',            // заголовок авторизации
        'auth_value'   => 'Bearer ' . $env('PARTNER_API_KEY'),
        'timeout'      => 8,                          // секунд на попытку
        'retries'      => 2,                          // повторов при 5xx / таймауте
        // Как называются поля у партнёра: наш ключ => ключ партнёра.
        'map' => [
            'lead_id'         => 'lead_id',
            'name'            => 'name',
            'phone'           => 'phone',
            'created_at'      => 'created_at',
            'offer_id'        => 'offer_id',
            'landing_version' => 'landing_version',
            'campaign_id'     => 'campaign_id',
            'ad_id'           => 'ad_id',
            'utm_source'      => 'utm_source',
            'utm_medium'      => 'utm_medium',
            'utm_campaign'    => 'utm_campaign',
            'utm_content'     => 'utm_content',
            'utm_term'        => 'utm_term',
            'country'         => 'country',
            'consent'         => 'consent',
            'consent_at'      => 'consent_at',
            'privacy_version' => 'privacy_version',
            'offer_expires_at'=> 'offer_expires_at', // до какого момента действует персональная скидка
        ],
        // Постоянные поля, которых требует партнёр (stream id, flow, goods_id…)
        'static' => [
            // 'stream_id' => 'XXXX',
        ],
        // Признак успешного приёма в ответе партнёра: null — достаточно HTTP 2xx,
        // иначе ['field' => 'status', 'value' => 'ok'].
        'success_check' => null,
    ],

    // ---------- Защита ----------
    'allowed_origins' => ['https://it3.artro-flex.com', 'https://www.it3.artro-flex.com'],
    'require_consent' => true,                         // должно совпадать с form.requireConsent в config.js
    'rate_limit'      => ['max' => 5, 'window' => 600],// не более 5 заявок с IP за 10 минут
    'dedupe_hours'    => 24,                           // повтор того же телефона в течение N часов не отправляется партнёру
    'hash_salt'       => $env('LEAD_HASH_SALT', 'artroflex-it3-fallback-salt'),

    // Каталог для служебных файлов
    'data_dir'        => __DIR__ . '/../_data',

    // Пароль редактора отзывов (admin/). Пусто — вход закрыт.
    'admin_password'  => $env('ADMIN_PASSWORD'),

    // Срок хранения служебных записей (дней). Имена и телефоны на диске не хранятся.
    'retention_days'  => 30,
];
})();
