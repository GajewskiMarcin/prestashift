<?php
/**
 * PsConnector - Latvian Translations
 */
global $_MODULE;
$_MODULE = array();

$translations = array(
    'PrestaShift Connector' => 'PrestaShift Connector',
    'Secure API endpoint for PrestaShift migration tool' => 'Drošs API galapunkts PrestaShift migrācijas rīkam',
    'Secure API endpoint for PrestaShift migration tool.' => 'Drošs API galapunkts PrestaShift migrācijas rīkam.',
    'Are you sure you want to uninstall? This will break any active migrations.' => 'Vai tiešām vēlaties atinstalēt? Tas pārtrauks visas aktīvās migrācijas.',
    'Settings updated.' => 'Iestatījumi atjaunināti.',
    'New token generated.' => 'Ģenerēta jauna pilnvara.',
    'API Endpoint URL: ' => 'API galapunkta URL: ',
    'Security Token' => 'Drošības pilnvara',
    'Copy' => 'Kopēt',
    'Copy this token to your PrestaShift module in the target shop to authorize the connection.' => 'Nokopējiet šo pilnvaru savā PrestaShift modulī mērķa veikalā, lai autorizētu savienojumu.',
    'Token is saved automatically when generated.' => 'Pilnvara tiek automātiski saglabāta, kad tā tiek ģenerēta.',
    'Are you sure you want to generate a new token? This will break any existing connections.' => 'Vai tiešām vēlaties ģenerēt jaunu pilnvaru? Tas pārtrauks visus esošos savienojumus.',
    'Generate New Token' => 'Ģenerēt jaunu pilnvaru',
    'Save Settings' => 'Saglabāt iestatījumus',
    'Powered by' => 'Nodrošina',
    'Token copied to clipboard!' => 'Pilnvara nokopēta starpliktuvē!',
);

foreach ($translations as $en => $lv) {
    $md5 = md5($en);
    $_MODULE['<{psconnector}prestashop>psconnector_' . $md5] = $lv;
    $_MODULE['<{psconnector}prestashop>configure_' . $md5] = $lv;
}
return $_MODULE;
