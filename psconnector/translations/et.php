<?php
/**
 * PsConnector - Estonian Translations
 */
global $_MODULE;
$_MODULE = array();

$translations = array(
    'PrestaShift Connector' => 'PrestaShift Connector',
    'Secure API endpoint for PrestaShift migration tool' => 'Turvaline API lõpp-punkt PrestaShifti migreerimise tööriista jaoks',
    'Secure API endpoint for PrestaShift migration tool.' => 'Turvaline API lõpp-punkt PrestaShifti migreerimise tööriista jaoks.',
    'Are you sure you want to uninstall? This will break any active migrations.' => 'Kas olete kindel, et soovite desinstallida? See katkestab kõik aktiivsed migreerimised.',
    'Settings updated.' => 'Seaded uuendatud.',
    'New token generated.' => 'Genereeriti uus märgis.',
    'API Endpoint URL: ' => 'API lõpp-punkti URL: ',
    'Security Token' => 'Turvamärgis',
    'Copy' => 'Kopeeri',
    'Copy this token to your PrestaShift module in the target shop to authorize the connection.' => 'Kopeerige see märgis oma PrestaShifti moodulisse sihtkoha poes, et autoriseerida ühendus.',
    'Token is saved automatically when generated.' => 'Märgis salvestatakse genereerimisel automaatselt.',
    'Are you sure you want to generate a new token? This will break any existing connections.' => 'Kas olete kindel, et soovite genereerida uue märgise? See katkestab kõik olemasolevad ühendused.',
    'Generate New Token' => 'Genereeri uus märgis',
    'Save Settings' => 'Salvesta seaded',
    'Powered by' => 'Toetaja',
    'Token copied to clipboard!' => 'Märgis kopeeritud lõikelauale!',
);

foreach ($translations as $en => $et) {
    $md5 = md5($en);
    $_MODULE['<{psconnector}prestashop>psconnector_' . $md5] = $et;
    $_MODULE['<{psconnector}prestashop>configure_' . $md5] = $et;
}
return $_MODULE;
