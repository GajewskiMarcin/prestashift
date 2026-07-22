<?php
/**
 * PsConnector - Swedish Translations
 */
global $_MODULE;
$_MODULE = array();

$translations = array(
    'PrestaShift Connector' => 'PrestaShift Connector',
    'Secure API endpoint for PrestaShift migration tool' => 'Säker API-slutpunkt för migreringsverktyget PrestaShift',
    'Secure API endpoint for PrestaShift migration tool.' => 'Säker API-slutpunkt för migreringsverktyget PrestaShift.',
    'Are you sure you want to uninstall? This will break any active migrations.' => 'Är du säker på att du vill avinstallera? Detta kommer att avbryta alla aktiva migreringar.',
    'Settings updated.' => 'Inställningar uppdaterade.',
    'New token generated.' => 'Ny token genererad.',
    'API Endpoint URL: ' => 'URL för API-slutpunkt: ',
    'Security Token' => 'Säkerhetstoken',
    'Copy' => 'Kopiera',
    'Copy this token to your PrestaShift module in the target shop to authorize the connection.' => 'Kopiera denna token till din PrestaShift-modul i målbutikken för att godkänna anslutningen.',
    'Token is saved automatically when generated.' => 'Token sparas automatiskt när den genereras.',
    'Are you sure you want to generate a new token? This will break any existing connections.' => 'Är du säker på att du vill generera en ny token? Detta kommer att bryta alla befintliga anslutningar.',
    'Generate New Token' => 'Generera ny token',
    'Save Settings' => 'Spara inställningar',
    'Powered by' => 'Drivs av',
    'Token copied to clipboard!' => 'Token kopierad till urklipp!',
);

foreach ($translations as $en => $sv) {
    $md5 = md5($en);
    $_MODULE['<{psconnector}prestashop>psconnector_' . $md5] = $sv;
    $_MODULE['<{psconnector}prestashop>configure_' . $md5] = $sv;
}
return $_MODULE;
