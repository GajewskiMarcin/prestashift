<?php
/**
 * PrestaShift Connector - Polish Translations
 */
global $_MODULE;
$_MODULE = array();

$translations = array(
    'PrestaShift Connector' => 'PrestaShift Connector',
    'Secure API endpoint for PrestaShift migration tool' => 'Bezpieczny punkt końcowy API dla narzędzia migracji PrestaShift',
    'Secure API endpoint for PrestaShift migration tool.' => 'Bezpieczny punkt końcowy API dla narzędzia migracji PrestaShift.',
    'Are you sure you want to uninstall? This will break any active migrations.' => 'Czy na pewno chcesz odinstalować? Przerwie to wszystkie aktywne migracje.',
    'Settings updated.' => 'Ustawienia zaktualizowane.',
    'New token generated.' => 'Wygenerowano nowy token.',
    'API Endpoint URL: ' => 'URL punktu końcowego API: ',
    'Security Token' => 'Token bezpieczeństwa',
    'Copy' => 'Kopiuj',
    'Copy this token to your PrestaShift module in the target shop to authorize the connection.' => 'Skopiuj ten token do modułu PrestaShift w sklepie docelowym, aby autoryzować połączenie.',
    'Are you sure you want to generate a new token? This will break any existing connections.' => 'Czy na pewno chcesz wygenerować nowy token? Przerwie to wszystkie istniejące połączenia.',
    'Generate New Token' => 'Generuj nowy token',
    'Token is saved automatically when generated.' => 'Token jest zapisywany automatycznie po wygenerowaniu.',
    'Save Settings' => 'Zapisz ustawienia',
    'Powered by' => 'Wspierane przez',
    'Token copied to clipboard!' => 'Token skopiowany do schowka!',
);

foreach ($translations as $en => $pl) {
    $md5 = md5($en);
    // Main module scope
    $_MODULE['<{psconnector}prestashop>psconnector_' . $md5] = $pl;
    
    // Custom template scope
    $_MODULE['<{psconnector}prestashop>configure_' . $md5] = $pl;
}

return $_MODULE;
