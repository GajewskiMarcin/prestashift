<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcingajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @attribution PrestaShift by Marcin Gajewski (marcingajewski.pl). Keep the Attribution Notice in prestashift.php — AFL-3.0 section 6.
 * @version   1.3.1
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.3.1: the back-office menu entry was created with a hardcoded Polish
 * caption — rename it in every installed language.
 */
function upgrade_module_1_3_1($module)
{
    $idTab = (int) Tab::getIdFromClassName('AdminPrestaShiftMigration');
    if (!$idTab) {
        return true;
    }

    $tab = new Tab($idTab);
    $tab->name = $module->getTabNames();

    return (bool) $tab->save();
}
