<?php
/*
 * Настройки сервера. Файл хранится в git, секретов в нём нет.
 *
 * Секреты (токен Webvork, пароль редактора, соль) — в файле artroflex3-config.php
 * УРОВНЕМ ВЫШЕ папки сайта: /home/artrofle/artroflex3-config.php (как artroflex2-config.php у it2).
 * Он загружается один раз вручную (cPanel → File Manager), в git и в браузер не попадает.
 * Образец — config.example.php в корне репозитория. Для локальных тестов можно положить api/secrets.php.
 */
return (function (): array {
    $secret = [];
    foreach ([dirname(__DIR__, 2) . '/artroflex3-config.php', __DIR__ . '/secrets.php'] as $f) {
        if (!is_file($f)) continue;
        // Ошибка в файле секретов не должна ронять весь сайт: запоминаем её для ?health=config
        try {
            $c = include $f;
        } catch (Throwable $e) {
            $GLOBALS['LP_SECRETS_ERROR'] = basename($f) . ': ' . get_class($e) . ' — ' . $e->getMessage() . ' (строка ' . $e->getLine() . ')';
            error_log('config.php: ' . $GLOBALS['LP_SECRETS_ERROR']);
            continue;
        }
        if (is_array($c)) { $secret = $c; break; }
        $GLOBALS['LP_SECRETS_ERROR'] = basename($f) . ': файл должен заканчиваться «return array(…);»';
    }
    $s = function (string $key, string $default = '') use ($secret): string {
        $v = (string)($secret[$key] ?? '');
        return $v !== '' ? $v : $default;
    };

    return [
        // ---------- Акция (общая дата; при offer.personalHours > 0 в config.js таймер персональный) ----------
        'offer' => [
            'active'           => true,
            'ends_at'          => '2026-10-31 23:59:59', // время Europe/Rome
            'stock'            => 17,                    // остаток по акционной цене
            'stock_updated_at' => '2026-10-16',          // дата последней сверки остатка
        ],

        // ---------- Webvork (как в it2: form-urlencoded, токен в теле, запасной адрес) ----------
        'partner' => [
            'url'           => 'https://api.webvork.com/v1/new-lead',
            'fallback_url'  => 'https://api2.webvork.com/v1/new-lead',
            'method'        => 'POST',
            'format'        => 'form',
            'auth_header'   => '',                       // токен передаётся полем token
            'auth_value'    => '',
            'timeout'       => 8,
            'retries'       => 1,                        // 2 попытки × 2 адреса
            // наш ключ => поле Webvork (null — не передавать)
            'map' => [
                'name'         => 'name',
                'phone'        => 'phone',
                'ip'           => 'ip',
                'utm_source'   => 'utm_source',
                'utm_medium'   => 'utm_medium',
                'utm_campaign' => 'utm_campaign',
                'utm_content'  => 'utm_content',
                'utm_term'     => 'utm_term',
            ],
            // Постоянные поля: задаются на сервере, с клиента их подменить нельзя
            'static' => [
                'token'    => $s('token'),
                'offer_id' => $s('offer_id', '208'),     // как в it2; уточнить, если у it3 свой оффер
                'country'  => 'IT',
                'lang'     => 'it',
            ],
            // Ответ Webvork: {"status":"ok","guid":"…"}
            'success_check' => ['field' => 'status', 'value' => 'ok'],
        ],

        // ---------- Защита ----------
        'allowed_origins' => ['https://it3.artro-flex.com', 'https://www.it3.artro-flex.com'],
        'require_consent' => true,                       // должно совпадать с form.requireConsent в config.js

        // Каталог для служебных файлов
        'data_dir'        => __DIR__ . '/../_data',

        // MySQL для журнала заявок и аналитики (как в it2): ['host' => 'localhost', 'name' => …, 'user' => …, 'pass' => …]
        'db'              => is_array($secret['db'] ?? null) ? $secret['db'] : [],
        // Telegram-бот: сообщение о каждом принятом лиде ['token' => '…', 'chat_id' => '-100…']
        'telegram'        => is_array($secret['telegram'] ?? null) ? $secret['telegram'] : [],
        // LeadCenter — основной получатель заявок (без него — напрямую в Webvork) ['url' => 'https://leadcenter.artro-flex.com/api/lead', 'key' => '…']
        'leadcenter'      => is_array($secret['leadcenter'] ?? null) ? $secret['leadcenter'] : [],
        // Письма об ошибках отправки лидов (необязательно)
        'alert_email'     => $s('alert_email'),
        // Пароль дашборда /admin/stats.php (пусто — используется пароль редактора отзывов)
        'stats_password'  => $s('stats_password', $s('admin_password')),

        // Пароль редактора мнений специалистов (admin/experts.html). Пусто — используется пароль редактора отзывов.
        'experts_password'=> $s('experts_password'),

        // Пароль редактора отзывов (admin/). Пусто — вход закрыт.
        'admin_password'  => $s('admin_password'),

        // Срок хранения служебных записей (дней). Имена и телефоны на диске не хранятся.
        'retention_days'  => 30,
    ];
})();
