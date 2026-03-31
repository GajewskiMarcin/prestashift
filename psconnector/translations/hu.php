<?php
/**
 * PsConnector - Hungarian Translations
 */
global $_MODULE;
$_MODULE = array();

$translations = array(
    'PrestaShift Connector' => 'PrestaShift Connector',
    'Secure API endpoint for PrestaShift migration tool' => 'Biztonságos API végpont a PrestaShift migrációs eszközhöz',
    'Secure API endpoint for PrestaShift migration tool.' => 'Biztonságos API végpont a PrestaShift migrációs eszközhöz.',
    'Are you sure you want to uninstall? This will break any active migrations.' => 'Biztosan el akarja távolítani? Ez megszakítja az aktív migrációkat.',
    'Settings updated.' => 'Beállítások frissítve.',
    'New token generated.' => 'Új token generálva.',
    'API Endpoint URL: ' => 'API végpont URL: ',
    'Security Token' => 'Biztonsági Token',
    'Copy' => 'Másolás',
    'Copy this token to your PrestaShift module in the target shop to authorize the connection.' => 'Másolja ezt a tokent a cél áruház PrestaShift moduljába a kapcsolat engedélyezéséhez.',
    'Token is saved automatically when generated.' => 'A token generáláskor automatikusan mentésre kerül.',
    'Are you sure you want to generate a new token? This will break any existing connections.' => 'Biztosan új tokent akar generálni? Ez megszakítja a meglévő kapcsolatokat.',
    'Generate New Token' => 'Új Token Generálása',
    'Save Settings' => 'Beállítások mentése',
    'Powered by' => 'Támogatja:',
    'Token copied to clipboard!' => 'Token a vágólapra másolva!',
);

foreach ($translations as $en => $hu) {
    $md5 = md5($en);
    $_MODULE['<{psconnector}prestashop>psconnector_' . $md5] = $hu;
    $_MODULE['<{psconnector}prestashop>configure_' . $md5] = $hu;
}
return $_MODULE;
