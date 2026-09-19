<?php
/**
 * PrestaShift Migration Module
 * 
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @attribution PrestaShift by Marcin Gajewski (marcingajewski.pl). Keep the Attribution Notice in prestashift.php — AFL-3.0 section 6.
 * @version   1.4.1
 *
 * ATTRIBUTION NOTICE (Academic Free License 3.0, section 6)
 *
 * PrestaShift was created by Marcin Gajewski — https://marcingajewski.pl
 * Original project: https://github.com/GajewskiMarcin/prestashift
 * Support the work: https://buymeacoffee.com/marcingajewski
 *
 * Derivative works and forks must keep this notice, the author credit, and the
 * support link shown on the module's final screen. "PrestaShift" is the name of
 * the original module: if you publish a modified version, please give it your
 * own name.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// require_once __DIR__ . '/vendor/autoload.php';

spl_autoload_register(function ($class) {
    $prefix = 'PrestaShift\\';
    $base_dir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

class PrestaShift extends Module
{
    public function __construct()
    {
        $this->name = 'prestashift';
        $this->tab = 'administration';
        $this->version = '1.4.1';
        $this->author = 'marcingajewski.pl';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('PrestaShift Migration');
        $this->description = $this->l('Professional data migration tool for your PrestaShop. Migrate data with ease.');

        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => _PS_VERSION_];
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');
    }

    public function install()
    {
        if (!parent::install() || !$this->installTab()) {
            return false;
        }
        \PrestaShift\Service\IdMapper::ensureTables();

        return true;
    }

    // The id map tables are kept on uninstall on purpose: removing the module
    // to reinstall a newer version must not lose which source record became
    // which target record — later Delta runs would duplicate everything.

    public function uninstall()
    {
        return $this->uninstallTab() &&
            parent::uninstall();
    }

    public function installTab()
    {
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminPrestaShiftMigration';
        $tab->name = $this->getTabNames();
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminAdvancedParameters');
        $tab->module = $this->name;

        return $tab->add();
    }

    /**
     * Menu caption in every installed language. A hardcoded name showed the
     * Polish caption to every shop, whatever its language.
     */
    public function getTabNames()
    {
        $names = [];
        foreach (Language::getLanguages(true) as $lang) {
            $locale = isset($lang['locale']) ? $lang['locale'] : null;
            $names[(int) $lang['id_lang']] = $this->l('PrestaShift Migration', false, $locale);
        }

        return $names;
    }

    public function uninstallTab()
    {
        $id_tab = (int) Tab::getIdFromClassName('AdminPrestaShiftMigration');
        if ($id_tab) {
            $tab = new Tab($id_tab);
            return $tab->delete();
        }
        return true;
    }

    public function getContent()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminPrestaShiftMigration'));
    }
}
