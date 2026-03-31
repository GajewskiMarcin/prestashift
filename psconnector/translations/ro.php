<?php
/**
 * PsConnector - Romanian Translations
 */
global $_MODULE;
$_MODULE = array();

$translations = array(
    'PrestaShift Connector' => 'PrestaShift Connector',
    'Secure API endpoint for PrestaShift migration tool' => 'Endpoint API securizat pentru instrumentul de migrare PrestaShift',
    'Secure API endpoint for PrestaShift migration tool.' => 'Endpoint API securizat pentru instrumentul de migrare PrestaShift.',
    'Are you sure you want to uninstall? This will break any active migrations.' => 'Ești sigur că vrei să dezinstalezi? Acest lucru va întrerupe orice migrare activă.',
    'Settings updated.' => 'Setări actualizate.',
    'New token generated.' => 'Token nou generat.',
    'API Endpoint URL: ' => 'URL Endpoint API: ',
    'Security Token' => 'Token de Securitate',
    'Copy' => 'Copiază',
    'Copy this token to your PrestaShift module in the target shop to authorize the connection.' => 'Copiați acest token în modulul PrestaShift din magazinul țintă pentru a autoriza conexiunea.',
    'Token is saved automatically when generated.' => 'Tokenul este salvat automat când este generat.',
    'Are you sure you want to generate a new token? This will break any existing connections.' => 'Ești sigur că vrei să generezi un token nou? Acest lucru va întrerupe conexiunile existente.',
    'Generate New Token' => 'Generează Token Nou',
    'Save Settings' => 'Salvează Setările',
    'Powered by' => 'Susținut de',
    'Token copied to clipboard!' => 'Token copiat în clipboard!',
);

foreach ($translations as $en => $ro) {
    $md5 = md5($en);
    $_MODULE['<{psconnector}prestashop>psconnector_' . $md5] = $ro;
    $_MODULE['<{psconnector}prestashop>configure_' . $md5] = $ro;
}
return $_MODULE;
