<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcingajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @attribution PrestaShift by Marcin Gajewski (marcingajewski.pl). Keep the Attribution Notice in prestashift.php — AFL-3.0 section 6.
 * @version   1.7.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.7.0
 *
 * This file also exists so that the version stored in the database actually
 * moves. PrestaShop decides there is nothing to upgrade when no upgrade-*.php
 * file is newer than the stored version (Module::initUpgradeModule() returns
 * Module::needUpgrade(), and ModuleManager::upgradeMigration() then reports
 * success without calling upgradeModuleVersion()). This module last shipped an
 * upgrade file for 1.3.1, so every release after that left shops reporting
 * 1.3.1 in the module manager, with an update button that did nothing visible.
 *
 * What it does:
 *
 *  - makes sure the id map tables exist;
 *  - arms the adoption of ids from migrations made before the id map existed.
 *    Those migrations copied the source ids as they were, and the map knows
 *    nothing about them, so without this the next run would number everything
 *    afresh and write a second copy of the whole catalogue instead of updating
 *    it. The adoption itself happens at the start of the next migration, where
 *    the source shop is known and the evidence can be checked.
 */
function upgrade_module_1_7_0($module)
{
    \PrestaShift\Service\IdMapper::ensureTables();

    \Configuration::updateValue('PRESTASHIFT_ADOPT_LEGACY_IDS', 1);

    return true;
}
