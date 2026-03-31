<?php
/**
 * PsConnector - Danish Translations
 */
global $_MODULE;
$_MODULE = array();

$translations = array(
    'PrestaShift Connector' => 'PrestaShift Connector',
    'Secure API endpoint for PrestaShift migration tool' => 'Sikkert API-slutpunkt til PrestaShift-migreringsværktøj',
    'Secure API endpoint for PrestaShift migration tool.' => 'Sikkert API-slutpunkt til PrestaShift-migreringsværktøj.',
    'Are you sure you want to uninstall? This will break any active migrations.' => 'Er du sikker på, at du vil afinstallere? Dette vil afbryde alle aktive migreringer.',
    'Settings updated.' => 'Indstillinger opdateret.',
    'New token generated.' => 'Ny token genereret.',
    'API Endpoint URL: ' => 'API-slutpunkt URL: ',
    'Security Token' => 'Sikkerhedstoken',
    'Copy' => 'Kopier',
    'Copy this token to your PrestaShift module in the target shop to authorize the connection.' => 'Kopier denne token til dit PrestaShift-modul i mål-shoppen for at godkende forbindelsen.',
    'Token is saved automatically when generated.' => 'Token gemmes automatisk, når den genereres.',
    'Are you sure you want to generate a new token? This will break any existing connections.' => 'Er du sikker på, at du vil generere en ny token? Dette vil afbryde alle eksisterende forbindelser.',
    'Generate New Token' => 'Generer ny token',
    'Save Settings' => 'Gem indstillinger',
    'Powered by' => 'Drevet af',
    'Token copied to clipboard!' => 'Token kopieret til udklipsholder!',
);

foreach ($translations as $en => $da) {
    $md5 = md5($en);
    $_MODULE['<{psconnector}prestashop>psconnector_' . $md5] = $da;
    $_MODULE['<{psconnector}prestashop>configure_' . $md5] = $da;
}
return $_MODULE;
