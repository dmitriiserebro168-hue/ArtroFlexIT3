<?php
// Образец. Скопируйте в artroflex3-config.php, заполните и загрузите
// на уровень выше папки сайта: /home/artrofle/artroflex3-config.php
// (cPanel → File Manager). В Git и в браузер этот файл не попадает.
return array(
    'token'          => 'ВАШ_ТОКЕН_WEBVORK',
    'offer_id'       => '208',                 // оффер Webvork; 208 — как у it2, уточните для it3
    'admin_password' => 'ПАРОЛЬ_РЕДАКТОРА_ОТЗЫВОВ',

    // Журнал заявок и статистика посещений в MySQL (как у it2). Базу и пользователя
    // создать в cPanel → MySQL Database Wizard и дать пользователю ALL PRIVILEGES на базу.
    'db' => array(
        'host' => 'localhost',
        'name' => 'artrofle_artroflex3',
        'user' => 'artrofle_artroflex3',
        'pass' => 'ПАРОЛЬ_ПОЛЬЗОВАТЕЛЯ_БАЗЫ',
    ),

    // Письма об ошибках отправки лидов (необязательно)
    'alert_email'    => 'you@example.com',
    // Пароль дашборда https://it3.artro-flex.com/admin/stats.php (пусто — пароль редактора отзывов)
    'stats_password' => 'ПРИДУМАЙТЕ_ПАРОЛЬ',
);
