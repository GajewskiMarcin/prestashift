<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcingajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @attribution PrestaShift by Marcin Gajewski (marcingajewski.pl). Keep the Attribution Notice in prestashift.php — AFL-3.0 section 6.
 * @version   1.3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.3.0: persistent source → target id map (migrations into a shop that
 * already has data, Delta runs, migrations in parts).
 */
function upgrade_module_1_3_0($module)
{
    \PrestaShift\Service\IdMapper::ensureTables();

    return true;
}
