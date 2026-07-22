<?php
/**
 * PsConnector - Croatian Translations
 */
global $_MODULE;
$_MODULE = array();

$translations = array(
    'PrestaShift Connector' => 'PrestaShift Connector',
    'Secure API endpoint for PrestaShift migration tool' => 'Sigurna API krajnja točka za alat za migraciju PrestaShift',
    'Secure API endpoint for PrestaShift migration tool.' => 'Sigurna API krajnja točka za alat za migraciju PrestaShift.',
    'Are you sure you want to uninstall? This will break any active migrations.' => 'Jeste li sigurni da želite deinstalirati? To će prekinuti sve aktivne migracije.',
    'Settings updated.' => 'Postavke ažurirane.',
    'New token generated.' => 'Novi token generiran.',
    'API Endpoint URL: ' => 'URL API krajnje točke: ',
    'Security Token' => 'Sigurnosni token',
    'Copy' => 'Kopiraj',
    'Copy this token to your PrestaShift module in the target shop to authorize the connection.' => 'Kopirajte ovaj token u svoj PrestaShift modul u ciljnoj trgovini radi autorizacije veze.',
    'Token is saved automatically when generated.' => 'Token se sprema automatski prilikom generiranja.',
    'Are you sure you want to generate a new token? This will break any existing connections.' => 'Jeste li sigurni da želite generirati novi token? To će prekinuti sve postojeće veze.',
    'Generate New Token' => 'Generiraj novi token',
    'Save Settings' => 'Spremi postavke',
    'Powered by' => 'Pokreće',
    'Token copied to clipboard!' => 'Token kopiran u međuspremnik!',
);

foreach ($translations as $en => $hr) {
    $md5 = md5($en);
    $_MODULE['<{psconnector}prestashop>psconnector_' . $md5] = $hr;
    $_MODULE['<{psconnector}prestashop>configure_' . $md5] = $hr;
}
return $_MODULE;
