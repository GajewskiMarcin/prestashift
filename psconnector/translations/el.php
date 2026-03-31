<?php
/**
 * PsConnector - Greek Translations
 */
global $_MODULE;
$_MODULE = array();

$translations = array(
    'PrestaShift Connector' => 'PrestaShift Connector',
    'Secure API endpoint for PrestaShift migration tool' => 'Ασφαλές σημείο λήξης API για το εργαλείο μεταφοράς PrestaShift',
    'Secure API endpoint for PrestaShift migration tool.' => 'Ασφαλές σημείο λήξης API για το εργαλείο μεταφοράς PrestaShift.',
    'Are you sure you want to uninstall? This will break any active migrations.' => 'Είστε βέβαιοι ότι θέλετε να απεγκαταστήσετε; Αυτό θα διακόψει τυχόν ενεργές μεταφορές.',
    'Settings updated.' => 'Οι ρυθμίσεις ενημερώθηκαν.',
    'New token generated.' => 'Παρήχθη νέο διακριτικό.',
    'API Endpoint URL: ' => 'URL τελικού σημείου API: ',
    'Security Token' => 'Διακριτικό ασφαλείας',
    'Copy' => 'Αντιγραφή',
    'Copy this token to your PrestaShift module in the target shop to authorize the connection.' => 'Αντιγράψτε αυτό το διακριτικό στη μονάδα PrestaShift στο κατάστημα-στόχο για να επιτρέψετε τη σύνδεση.',
    'Token is saved automatically when generated.' => 'Το διακριτικό αποθηκεύεται αυτόματα κατά την παραγωγή του.',
    'Are you sure you want to generate a new token? This will break any existing connections.' => 'Είστε βέβαιοι ότι θέλετε να δημιουργήσετε νέο διακριτικό; Αυτό θα διακόψει τυχόν υπάρχουσες συνδέσεις.',
    'Generate New Token' => 'Παραγωγή νέου διακριτικού',
    'Save Settings' => 'Αποθήκευση ρυθμίσεων',
    'Powered by' => 'Με την υποστήριξη του',
    'Token copied to clipboard!' => 'Το διακριτικό αντιγράφηκε στο πρόχειρο!',
);

foreach ($translations as $en => $el) {
    $md5 = md5($en);
    $_MODULE['<{psconnector}prestashop>psconnector_' . $md5] = $el;
    $_MODULE['<{psconnector}prestashop>configure_' . $md5] = $el;
}
return $_MODULE;
