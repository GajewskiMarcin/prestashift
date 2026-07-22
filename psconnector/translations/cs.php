<?php
/**
 * PsConnector - Czech Translations
 */
global $_MODULE;
$_MODULE = array();

$translations = array(
    'PrestaShift Connector' => 'PrestaShift Connector',
    'Secure API endpoint for PrestaShift migration tool' => 'Zabezpečený koncový bod API pro migrační nástroj PrestaShift',
    'Secure API endpoint for PrestaShift migration tool.' => 'Zabezpečený koncový bod API pro migrační nástroj PrestaShift.',
    'Are you sure you want to uninstall? This will break any active migrations.' => 'Opravdu chcete odinstalovat? Tím se přeruší všechny aktivní migrace.',
    'Settings updated.' => 'Nastavení aktualizováno.',
    'New token generated.' => 'Byl vygenerován nový token.',
    'API Endpoint URL: ' => 'URL koncového bodu API: ',
    'Security Token' => 'Bezpečnostní token',
    'Copy' => 'Kopírovat',
    'Copy this token to your PrestaShift module in the target shop to authorize the connection.' => 'Zkopírujte tento token do vašeho modulu PrestaShift v cílovém obchodě pro autorizaci připojení.',
    'Token is saved automatically when generated.' => 'Token se při vygenerování automaticky uloží.',
    'Are you sure you want to generate a new token? This will break any existing connections.' => 'Opravdu chcete vygenerovat nový token? Tím se přeruší všechna stávající připojení.',
    'Generate New Token' => 'Vygenerovat nový token',
    'Save Settings' => 'Uložit nastavení',
    'Powered by' => 'Běží na',
    'Token copied to clipboard!' => 'Token zkopírován do schránky!',
);

foreach ($translations as $en => $cs) {
    $md5 = md5($en);
    $_MODULE['<{psconnector}prestashop>psconnector_' . $md5] = $cs;
    $_MODULE['<{psconnector}prestashop>configure_' . $md5] = $cs;
}
return $_MODULE;
