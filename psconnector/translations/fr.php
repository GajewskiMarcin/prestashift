<?php
/**
 * PsConnector - French Translations
 */
global $_MODULE;
$_MODULE = array();

$translations = array(
    'PrestaShift Connector' => 'PrestaShift Connector',
    'Secure API endpoint for PrestaShift migration tool' => 'Point de terminaison API sécurisé pour l\'outil de migration PrestaShift',
    'Secure API endpoint for PrestaShift migration tool.' => 'Point de terminaison API sécurisé pour l\'outil de migration PrestaShift.',
    'Are you sure you want to uninstall? This will break any active migrations.' => 'Êtes-vous sûr de vouloir désinstaller ? Cela interrompra toutes les migrations actives.',
    'Settings updated.' => 'Paramètres mis à jour.',
    'New token generated.' => 'Nouveau jeton généré.',
    'API Endpoint URL: ' => 'URL du point de terminaison API : ',
    'Security Token' => 'Jeton de sécurité',
    'Copy' => 'Copier',
    'Copy this token to your PrestaShift module in the target shop to authorize the connection.' => 'Copiez ce jeton dans votre module PrestaShift de la boutique cible pour autoriser la connexion.',
    'Token is saved automatically when generated.' => 'Le jeton est enregistré automatiquement lorsqu\'il est généré.',
    'Are you sure you want to generate a new token? This will break any existing connections.' => 'Êtes-vous sûr de vouloir générer un nouveau jeton ? Cela rompra toutes les connexions existantes.',
    'Generate New Token' => 'Générer un nouveau jeton',
    'Save Settings' => 'Enregistrer les paramètres',
    'Powered by' => 'Propulsé par',
    'Token copied to clipboard!' => 'Jeton copié dans le presse-papiers !',
);

foreach ($translations as $en => $fr) {
    $md5 = md5($en);
    $_MODULE['<{psconnector}prestashop>psconnector_' . $md5] = $fr;
    $_MODULE['<{psconnector}prestashop>configure_' . $md5] = $fr;
}
return $_MODULE;

