<?php
/**
 * PsConnector - Italian Translations
 */
global $_MODULE;
$_MODULE = array();

$translations = array(
    'PrestaShift Connector' => 'PrestaShift Connector',
    'Secure API endpoint for PrestaShift migration tool' => 'Endpoint API sicuro per lo strumento di migrazione PrestaShift',
    'Secure API endpoint for PrestaShift migration tool.' => 'Endpoint API sicuro per lo strumento di migrazione PrestaShift.',
    'Are you sure you want to uninstall? This will break any active migrations.' => 'Sei sicuro di voler disinstallare? Questo interromperà qualsiasi migrazione attiva.',
    'Settings updated.' => 'Impostazioni aggiornate.',
    'New token generated.' => 'Nuovo token generato.',
    'API Endpoint URL: ' => 'URL endpoint API: ',
    'Security Token' => 'Token di sicurezza',
    'Copy' => 'Copia',
    'Copy this token to your PrestaShift module in the target shop to authorize the connection.' => 'Copia questo token nel tuo modulo PrestaShift nel negozio di destinazione per autorizzare la connessione.',
    'Token is saved automatically when generated.' => 'Il token viene salvato automaticamente quando generato.',
    'Are you sure you want to generate a new token? This will break any existing connections.' => 'Sei sicuro di voler generare un nuovo token? Questo interromperà qualsiasi connessione esistente.',
    'Generate New Token' => 'Genera nuovo token',
    'Save Settings' => 'Salva impostazioni',
    'Powered by' => 'Powered by',
    'Token copied to clipboard!' => 'Token copiato negli appunti!',
);

foreach ($translations as $en => $it) {
    $md5 = md5($en);
    $_MODULE['<{psconnector}prestashop>psconnector_' . $md5] = $it;
    $_MODULE['<{psconnector}prestashop>configure_' . $md5] = $it;
}
return $_MODULE;
