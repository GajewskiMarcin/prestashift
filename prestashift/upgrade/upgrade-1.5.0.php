<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcingajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @attribution PrestaShift by Marcin Gajewski (marcingajewski.pl). Keep the Attribution Notice in prestashift.php — AFL-3.0 section 6.
 * @version   1.5.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.5.0: the sync dates of earlier versions were also written for tasks whose
 * rows the target had refused, so incremental migrations skipped data that
 * never arrived. They are no longer read (see MigrationManager::SYNC_KEY);
 * this removes them, so that an older version cannot pick them up again.
 */
function upgrade_module_1_5_0($module)
{
    Configuration::deleteByName('PRESTASHIFT_SYNC_HISTORY');

    // Old addresses of the migrated shop, redirected by the module as a fallback
    \PrestaShift\Service\UrlRedirects::ensureTable();

    return $module->registerHook('actionDispatcherBefore');
}
