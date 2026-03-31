<?php
/**
 * PsConnector - Lithuanian Translations
 */
global $_MODULE;
$_MODULE = array();

$translations = array(
    'PrestaShift Connector' => 'PrestaShift Connector',
    'Secure API endpoint for PrestaShift migration tool' => 'Saugus API pabaigos taškas „PrestaShift“ migravimo įrankiui',
    'Secure API endpoint for PrestaShift migration tool.' => 'Saugus API pabaigos taškas „PrestaShift“ migravimo įrankiui.',
    'Are you sure you want to uninstall? This will break any active migrations.' => 'Ar tikrai norite pašalinti? Tai nutrauks visus aktyvius migravimus.',
    'Settings updated.' => 'Nustatymai atnaujinti.',
    'New token generated.' => 'Sugeneruotas naujas žetonas.',
    'API Endpoint URL: ' => 'API pabaigos taško URL: ',
    'Security Token' => 'Saugumo žetonas',
    'Copy' => 'Kopijuoti',
    'Copy this token to your PrestaShift module in the target shop to authorize the connection.' => 'Nukopijuokite šį žetoną į savo „PrestaShift“ modulį tikslinėje parduotuvėje, kad autorizuotumėte ryšį.',
    'Token is saved automatically when generated.' => 'Žetonas automatiškai išsaugomas jį sugeneravus.',
    'Are you sure you want to generate a new token? This will break any existing connections.' => 'Ar tikrai norite sugeneruoti naują žetoną? Tai nutrauks visus esamus ryšius.',
    'Generate New Token' => 'Generuoti naują žetoną',
    'Save Settings' => 'Išsaugoti nustatymus',
    'Powered by' => 'Sukurta su',
    'Token copied to clipboard!' => 'Žetonas nukopijuotas į iškarpinę!',
);

foreach ($translations as $en => $lt) {
    $md5 = md5($en);
    $_MODULE['<{psconnector}prestashop>psconnector_' . $md5] = $lt;
    $_MODULE['<{psconnector}prestashop>configure_' . $md5] = $lt;
}
return $_MODULE;
