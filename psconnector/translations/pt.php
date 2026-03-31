<?php
/**
 * PsConnector - Portuguese Translations
 */
global $_MODULE;
$_MODULE = array();

$translations = array(
    'PrestaShift Connector' => 'PrestaShift Connector',
    'Secure API endpoint for PrestaShift migration tool' => 'Endpoint de API seguro para a ferramenta de migração PrestaShift',
    'Secure API endpoint for PrestaShift migration tool.' => 'Endpoint de API seguro para a ferramenta de migração PrestaShift.',
    'Are you sure you want to uninstall? This will break any active migrations.' => 'Tem certeza de que deseja desinstalar? Isto irá interromper quaisquer migrações ativas.',
    'Settings updated.' => 'Definições atualizadas.',
    'New token generated.' => 'Novo token gerado.',
    'API Endpoint URL: ' => 'URL do Endpoint da API: ',
    'Security Token' => 'Token de Segurança',
    'Copy' => 'Copiar',
    'Copy this token to your PrestaShift module in the target shop to authorize the connection.' => 'Copie este token para o seu módulo PrestaShift na loja de destino para autorizar a conexão.',
    'Token is saved automatically when generated.' => 'O token é guardado automaticamente quando gerado.',
    'Are you sure you want to generate a new token? This will break any existing connections.' => 'Tem certeza de que deseja gerar um novo token? Isto irá interromper quaisquer conexões existentes.',
    'Generate New Token' => 'Gerar Novo Token',
    'Save Settings' => 'Guardar Definições',
    'Powered by' => 'Desenvolvido por',
    'Token copied to clipboard!' => 'Token copiado para a área de transferência!',
);

foreach ($translations as $en => $pt) {
    $md5 = md5($en);
    $_MODULE['<{psconnector}prestashop>psconnector_' . $md5] = $pt;
    $_MODULE['<{psconnector}prestashop>configure_' . $md5] = $pt;
}
return $_MODULE;
