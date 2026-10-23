<?php
// Образец. Скопируйте в artroflex3-config.php, заполните и загрузите
// на уровень выше папки сайта: /home/artrofle/artroflex3-config.php
// (cPanel → File Manager). В Git и в браузер этот файл не попадает.
return array(
    'token'          => 'ВАШ_ТОКЕН_WEBVORK',
    'offer_id'       => '208',                 // оффер Webvork; 208 — как у it2, уточните для it3
    'admin_password' => 'ПАРОЛЬ_РЕДАКТОРА_ОТЗЫВОВ',
    'experts_password' => 'ПАРОЛЬ_РЕДАКТОРА_СПЕЦИАЛИСТОВ',   // /admin/experts.html; пусто — пароль редактора отзывов

    // Журнал заявок и статистика посещений в MySQL (как у it2). Базу и пользователя
    // создать в cPanel → MySQL Database Wizard и дать пользователю ALL PRIVILEGES на базу.
    'db' => array(
        'host' => 'localhost',
        'name' => 'artrofle_artroflex3',
        'user' => 'artrofle_artroflex3',
        'pass' => 'ПАРОЛЬ_ПОЛЬЗОВАТЕЛЯ_БАЗЫ',
    ),

    // Telegram: сообщение о каждом лиде, принятом Webvork (имя, телефон, ответ Webvork, кампания)
    'telegram' => array(
        'token'   => '1234567890:AAH…',
        'chat_id' => '-1001234567890',
    ),

    // Письма об ошибках отправки лидов (необязательно)
    'alert_email'    => 'you@example.com',
    // Пароль дашборда https://it3.artro-flex.com/admin/stats.php (пусто — пароль редактора отзывов)
    'stats_password' => 'ПРИДУМАЙТЕ_ПАРОЛЬ',

    // Копия каждой заявки в LeadCenter (необязательно). key — API-ключ источника из LeadCenter → Источники.
    'leadcenter' => array(
        'url' => 'https://leadcenter.artro-flex.com/api/lead',
        'key' => '',
    ),
);
