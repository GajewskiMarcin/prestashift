<?php
/**
 * PsConnector - Bulgarian Translations
 */
global $_MODULE;
$_MODULE = array();

$translations = array(
    'PrestaShift Connector' => 'PrestaShift Connector',
    'Secure API endpoint for PrestaShift migration tool' => 'Сигурна API точка за инструмента за миграция PrestaShift',
    'Secure API endpoint for PrestaShift migration tool.' => 'Сигурна API точка за инструмента за миграция PrestaShift.',
    'Are you sure you want to uninstall? This will break any active migrations.' => 'Сигурни ли сте, че искате да деинсталирате? Това ще прекъсне всички активни миграции.',
    'Settings updated.' => 'Настройките са обновени.',
    'New token generated.' => 'Генериран е нов токен.',
    'API Endpoint URL: ' => 'API Endpoint URL: ',
    'Security Token' => 'Токен за сигурност',
    'Copy' => 'Копирай',
    'Copy this token to your PrestaShift module in the target shop to authorize the connection.' => 'Копирайте този токен във вашия модул PrestaShift в целевия магазин, за да оторизирате връзката.',
    'Token is saved automatically when generated.' => 'Токенът се записва автоматично при генериране.',
    'Are you sure you want to generate a new token? This will break any existing connections.' => 'Сигурни ли сте, че искате да генерирате нов токен? Това ще прекъсне всички съществуващи връзки.',
    'Generate New Token' => 'Генериране на нов токен',
    'Save Settings' => 'Запазване на настройките',
    'Powered by' => 'С подкрепата на',
    'Token copied to clipboard!' => 'Токенът е копиран в клипборда!',
);

foreach ($translations as $en => $bg) {
    $md5 = md5($en);
    $_MODULE['<{psconnector}prestashop>psconnector_' . $md5] = $bg;
    $_MODULE['<{psconnector}prestashop>configure_' . $md5] = $bg;
}
return $_MODULE;
