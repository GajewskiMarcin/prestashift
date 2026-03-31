<?php
/**
 * PsConnector - German Translations
 */
global $_MODULE;
$_MODULE = array();

$translations = array(
    'PrestaShift Connector' => 'PrestaShift Connector',
    'Secure API endpoint for PrestaShift migration tool' => 'Sicherer API-Endpunkt für das PrestaShift-Migrations-Tool',
    'Secure API endpoint for PrestaShift migration tool.' => 'Sicherer API-Endpunkt für das PrestaShift-Migrations-Tool.',
    'Are you sure you want to uninstall? This will break any active migrations.' => 'Sind Sie sicher, dass Sie deinstallieren möchten? Dies wird alle aktiven Migrationen unterbrechen.',
    'Settings updated.' => 'Einstellungen aktualisiert.',
    'New token generated.' => 'Neues Token generiert.',
    'API Endpoint URL: ' => 'API-Endpunkt-URL: ',
    'Security Token' => 'Sicherheits-Token',
    'Copy' => 'Kopieren',
    'Copy this token to your PrestaShift module in the target shop to authorize the connection.' => 'Kopieren Sie dieses Token in Ihr PrestaShift-Modul im Ziel-Shop, um die Verbindung zu autorisieren.',
    'Token is saved automatically when generated.' => 'Token wird beim Generieren automatisch gespeichert.',
    'Are you sure you want to generate a new token? This will break any existing connections.' => 'Sind Sie sicher, dass Sie ein neues Token generieren möchten? Dies wird alle bestehenden Verbindungen unterbrechen.',
    'Generate New Token' => 'Neues Token generieren',
    'Save Settings' => 'Einstellungen speichern',
    'Powered by' => 'Unterstützt von',
    'Token copied to clipboard!' => 'Token in die Zwischenablage kopiert!',
);

foreach ($translations as $en => $de) {
    $md5 = md5($en);
    $_MODULE['<{psconnector}prestashop>psconnector_' . $md5] = $de;
    $_MODULE['<{psconnector}prestashop>configure_' . $md5] = $de;
}
return $_MODULE;
