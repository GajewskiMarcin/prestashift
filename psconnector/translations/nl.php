<?php
/**
 * PsConnector - Dutch Translations
 */
global $_MODULE;
$_MODULE = array();

$translations = array(
    'PrestaShift Connector' => 'PrestaShift Connector',
    'Secure API endpoint for PrestaShift migration tool' => 'Beveiligd API-eindpunt voor de PrestaShift migratietool',
    'Secure API endpoint for PrestaShift migration tool.' => 'Beveiligd API-eindpunt voor de PrestaShift migratietool.',
    'Are you sure you want to uninstall? This will break any active migrations.' => 'Weet u zeker dat u de installatie ongedaan wilt maken? Dit verbreekt alle actieve migraties.',
    'Settings updated.' => 'Instellingen bijgewerkt.',
    'New token generated.' => 'Nieuw token gegenereerd.',
    'API Endpoint URL: ' => 'API-eindpunt URL: ',
    'Security Token' => 'Beveiligingstoken',
    'Copy' => 'Kopiëren',
    'Copy this token to your PrestaShift module in the target shop to authorize the connection.' => 'Kopieer dit token naar uw PrestaShift-module in de doelwinkel om de verbinding te autoriseren.',
    'Token is saved automatically when generated.' => 'Token wordt automatisch opgeslagen wanneer het wordt gegenereerd.',
    'Are you sure you want to generate a new token? This will break any existing connections.' => 'Weet u zeker dat u een nieuw token wilt genereren? Dit verbreekt alle bestaande verbindingen.',
    'Generate New Token' => 'Nieuw token genereren',
    'Save Settings' => 'Instellingen opslaan',
    'Powered by' => 'Mogelijk gemaakt door',
    'Token copied to clipboard!' => 'Token gekopieerd naar klembord!',
);

foreach ($translations as $en => $nl) {
    $md5 = md5($en);
    $_MODULE['<{psconnector}prestashop>psconnector_' . $md5] = $nl;
    $_MODULE['<{psconnector}prestashop>configure_' . $md5] = $nl;
}
return $_MODULE;
