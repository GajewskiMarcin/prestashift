<?php
/**
 * PsConnector - Finnish Translations
 */
global $_MODULE;
$_MODULE = array();

$translations = array(
    'PrestaShift Connector' => 'PrestaShift Connector',
    'Secure API endpoint for PrestaShift migration tool' => 'Suojattu API-päätepiste PrestaShift-migraatiotyökalulle',
    'Secure API endpoint for PrestaShift migration tool.' => 'Suojattu API-päätepiste PrestaShift-migraatiotyökalulle.',
    'Are you sure you want to uninstall? This will break any active migrations.' => 'Oletko varma, että haluat poistaa asennuksen? Tämä keskeyttää kaikki aktiiviset migraatiot.',
    'Settings updated.' => 'Asetukset päivitetty.',
    'New token generated.' => 'Uusi tunniste (token) luotu.',
    'API Endpoint URL: ' => 'API-päätepisteen URL: ',
    'Security Token' => 'Suojaustunniste (Security Token)',
    'Copy' => 'Kopioi',
    'Copy this token to your PrestaShift module in the target shop to authorize the connection.' => 'Kopioi tämä tunniste kohdekaupan PrestaShift-moduuliin yhteyden valtuuttamiseksi.',
    'Token is saved automatically when generated.' => 'Tunniste tallennetaan automaattisesti luonnin yhteydessä.',
    'Are you sure you want to generate a new token? This will break any existing connections.' => 'Oletko varma, että haluat luoda uuden tunnisteen? Tämä katkaisee olemassa olevat yhteydet.',
    'Generate New Token' => 'Luo uusi tunniste',
    'Save Settings' => 'Tallenna asetukset',
    'Powered by' => 'Palvelun tarjoaa',
    'Token copied to clipboard!' => 'Tunniste kopioitu leikepöydälle!',
);

foreach ($translations as $en => $fi) {
    $md5 = md5($en);
    $_MODULE['<{psconnector}prestashop>psconnector_' . $md5] = $fi;
    $_MODULE['<{psconnector}prestashop>configure_' . $md5] = $fi;
}
return $_MODULE;
