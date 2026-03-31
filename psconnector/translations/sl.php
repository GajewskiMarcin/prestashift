<?php
/**
 * PsConnector - Slovenian Translations
 */
global $_MODULE;
$_MODULE = array();

$translations = array(
    'PrestaShift Connector' => 'PrestaShift Connector',
    'Secure API endpoint for PrestaShift migration tool' => 'Varna API končna točka za orodje za migracijo PrestaShift',
    'Secure API endpoint for PrestaShift migration tool.' => 'Varna API končna točka za orodje za migracijo PrestaShift.',
    'Are you sure you want to uninstall? This will break any active migrations.' => 'Ali ste prepričani, da želite odstraniti? To bo prekinilo vse aktivne migracije.',
    'Settings updated.' => 'Nastavitve posodobljene.',
    'New token generated.' => 'Ustvarjen nov žeton.',
    'API Endpoint URL: ' => 'URL API končne točke: ',
    'Security Token' => 'Varnostni žeton',
    'Copy' => 'Kopiraj',
    'Copy this token to your PrestaShift module in the target shop to authorize the connection.' => 'Kopirajte ta žeton v svoj modul PrestaShift v ciljni trgovini za avtorizacijo povezave.',
    'Token is saved automatically when generated.' => 'Žeton se samodejno shrani ob ustvarjanju.',
    'Are you sure you want to generate a new token? This will break any existing connections.' => 'Ali ste prepričani, da želite ustvariti nov žeton? To bo prekinilo vse obstoječe povezave.',
    'Generate New Token' => 'Ustvari nov žeton',
    'Save Settings' => 'Shrani nastavitve',
    'Powered by' => 'Poganja',
    'Token copied to clipboard!' => 'Žeton kopiran v odložišče!',
);

foreach ($translations as $en => $sl) {
    $md5 = md5($en);
    $_MODULE['<{psconnector}prestashop>psconnector_' . $md5] = $sl;
    $_MODULE['<{psconnector}prestashop>configure_' . $md5] = $sl;
}
return $_MODULE;
