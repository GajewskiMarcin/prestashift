<?php
/**
 * PsConnector - Spanish Translations
 */
global $_MODULE;
$_MODULE = array();

$translations = array(
    'PrestaShift Connector' => 'PrestaShift Connector',
    'Secure API endpoint for PrestaShift migration tool' => 'Punto final API seguro para la herramienta de migración PrestaShift',
    'Secure API endpoint for PrestaShift migration tool.' => 'Punto final API seguro para la herramienta de migración PrestaShift.',
    'Are you sure you want to uninstall? This will break any active migrations.' => '¿Está seguro de que desea desinstalar? Esto romperá cualquier migración activa.',
    'Settings updated.' => 'Configuración actualizada.',
    'New token generated.' => 'Nuevo token generado.',
    'API Endpoint URL: ' => 'URL de punto final API: ',
    'Security Token' => 'Token de seguridad',
    'Copy' => 'Copiar',
    'Copy this token to your PrestaShift module in the target shop to authorize the connection.' => 'Copie este token a su módulo PrestaShift en la tienda de destino para autorizar la conexión.',
    'Token is saved automatically when generated.' => 'El token se guarda automáticamente al generarse.',
    'Are you sure you want to generate a new token? This will break any existing connections.' => '¿Está seguro de que desea generar un nuevo token? Esto romperá cualquier conexión existente.',
    'Generate New Token' => 'Generar nuevo token',
    'Save Settings' => 'Guardar configuración',
    'Powered by' => 'Desarrollado por',
    'Token copied to clipboard!' => '¡Token copiado al portapapeles!',
);

foreach ($translations as $en => $es) {
    $md5 = md5($en);
    $_MODULE['<{psconnector}prestashop>psconnector_' . $md5] = $es;
    $_MODULE['<{psconnector}prestashop>configure_' . $md5] = $es;
}
return $_MODULE;
