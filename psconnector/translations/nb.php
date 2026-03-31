<?php
/**
 * PsConnector - Norwegian Translations
 */
global $_MODULE;
$_MODULE = array();

$translations = array(
    'PrestaShift Connector' => 'PrestaShift Connector',
    'Secure API endpoint for PrestaShift migration tool' => 'Sikkert API-endepunkt for PrestaShift-migreringsverktøy',
    'Secure API endpoint for PrestaShift migration tool.' => 'Sikkert API-endepunkt for PrestaShift-migreringsverktøy.',
    'Are you sure you want to uninstall? This will break any active migrations.' => 'Er du sikker på at du vil avinstallere? Dette vil avbryte alle aktive migreringer.',
    'Settings updated.' => 'Innstillinger oppdatert.',
    'New token generated.' => 'Ny kode (token) generert.',
    'API Endpoint URL: ' => 'API-endepunkt URL: ',
    'Security Token' => 'Sikkerhetskode',
    'Copy' => 'Kopier',
    'Copy this token to your PrestaShift module in the target shop to authorize the connection.' => 'Kopier denne koden til din PrestaShift-modul i målbutikken for å autorisere tilkoblingen.',
    'Token is saved automatically when generated.' => 'Koden lagres automatisk når den genereres.',
    'Are you sure you want to generate a new token? This will break any existing connections.' => 'Er du sikker på at du vil generere en ny kode? Dette vil bryte alle eksisterende tilkoblinger.',
    'Generate New Token' => 'Generer ny kode',
    'Save Settings' => 'Lagre innstillinger',
    'Powered by' => 'Levert av',
    'Token copied to clipboard!' => 'Koden er kopiert til utklippstavlen!',
);

foreach ($translations as $en => $nb) {
    $md5 = md5($en);
    $_MODULE['<{psconnector}prestashop>psconnector_' . $md5] = $nb;
    $_MODULE['<{psconnector}prestashop>configure_' . $md5] = $nb;
}
return $_MODULE;
