<?php
/**
 * PsConnector - Slovak Translations
 */
global $_MODULE;
$_MODULE = array();

$translations = array(
    'PrestaShift Connector' => 'PrestaShift Connector',
    'Secure API endpoint for PrestaShift migration tool' => 'Zabezpečený koncový bod API pre nástroj na migráciu PrestaShift',
    'Secure API endpoint for PrestaShift migration tool.' => 'Zabezpečený koncový bod API pre nástroj na migráciu PrestaShift.',
    'Are you sure you want to uninstall? This will break any active migrations.' => 'Naozaj chcete odinštalovať? Toto preruší všetky aktívne migrácie.',
    'Settings updated.' => 'Nastavenia aktualizované.',
    'New token generated.' => 'Vygenerovaný nový token.',
    'API Endpoint URL: ' => 'URL API koncového bodu: ',
    'Security Token' => 'Zabezpečovací token',
    'Copy' => 'Kopírovať',
    'Copy this token to your PrestaShift module in the target shop to authorize the connection.' => 'Skopírujte tento token do svojho modulu PrestaShift v cieľovom obchode na autorizáciu pripojenia.',
    'Token is saved automatically when generated.' => 'Token sa po vygenerovaní automaticky uloží.',
    'Are you sure you want to generate a new token? This will break any existing connections.' => 'Naozaj chcete vygenerovať nový token? Toto preruší všetky existujúce pripojenia.',
    'Generate New Token' => 'Vygenerovať nový token',
    'Save Settings' => 'Uložiť nastavenia',
    'Powered by' => 'Beží na',
    'Token copied to clipboard!' => 'Token skopírovaný do schránky!',
);

foreach ($translations as $en => $sk) {
    $md5 = md5($en);
    $_MODULE['<{psconnector}prestashop>psconnector_' . $md5] = $sk;
    $_MODULE['<{psconnector}prestashop>configure_' . $md5] = $sk;
}
return $_MODULE;
