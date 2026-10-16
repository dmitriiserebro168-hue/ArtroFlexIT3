<?php
/*
 * Скопировать в api/config.php и заполнить. config.php не публиковать в репозиторий.
 * Секреты лучше задавать переменными окружения (SetEnv в .htaccess / панель хостинга).
 */
return [
    // ---------- Акция (единственный рабочий источник для таймера и остатка) ----------
    'offer' => [
        'active'          => true,
        'ends_at'         => '2026-10-31 23:59:59',   // время Europe/Rome
        'stock'           => 17,                      // актуальный остаток по акционной цене
        'stock_updated_at'=> '2026-10-16',            // дата последней сверки остатка с партнёром
    ],

    // ---------- Партнёрская программа ----------
    'partner' => [
        'url'          => getenv('PARTNER_URL') ?: 'https://partner.example/api/leads',
        'method'       => 'POST',
        'format'       => 'json',                     // 'json' или 'form'
        'auth_header'  => 'Authorization',            // заголовок авторизации
        'auth_value'   => 'Bearer ' . (getenv('PARTNER_API_KEY') ?: ''),
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
    'allowed_origins' => ['https://example.it'],       // домен лендинга; пусто — не проверять
    'require_consent' => true,                         // должно совпадать с form.requireConsent в config.js
    'rate_limit'      => ['max' => 5, 'window' => 600],// не более 5 заявок с IP за 10 минут
    'dedupe_hours'    => 24,                           // повтор того же телефона в течение N часов не отправляется партнёру
    'hash_salt'       => getenv('LEAD_HASH_SALT') ?: 'заменить-на-случайную-строку',

    // Каталог для служебных файлов. Лучше вне корня сайта.
    'data_dir'        => __DIR__ . '/../_data',

    // Пароль страницы редактирования отзывов (admin/). Лучше задать переменной окружения ADMIN_PASSWORD.
    'admin_password'  => getenv('ADMIN_PASSWORD') ?: '', // задать в api/config.php или ADMIN_PASSWORD; пусто — вход закрыт

    // Срок хранения служебных записей (дней). Имена и телефоны на диске не хранятся.
    'retention_days'  => 30,
];
