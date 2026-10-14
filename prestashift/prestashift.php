<?php
/**
 * PrestaShift Migration Module
 * 
 * @author    marcingajewski.pl <kontakt@marcingajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @attribution PrestaShift by Marcin Gajewski (marcingajewski.pl). Keep the Attribution Notice in prestashift.php — AFL-3.0 section 6.
 * @version   1.5.0
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
        $this->version = '1.5.0';
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
        if (!parent::install() || !$this->installTab() || !$this->registerHook('actionDispatcherBefore')) {
            return false;
        }
        \PrestaShift\Service\IdMapper::ensureTables();
        \PrestaShift\Service\UrlRedirects::ensureTable();

        return true;
    }

    /**
     * Fallback for the old addresses of the migrated shop: when neither the
     * copied URL patterns, nor .htaccess, nor the nginx rules took a visitor
     * from an old address to the new one, the module does. Runs before the
     * controller: an old address whose record changed id gives no 404, it
     * shows another record. Costs one query per page while the table of old
     * addresses is not empty; the module can be disabled once the server
     * redirects them itself.
     */
    public function hookActionDispatcherBefore($params)
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        if (!(int) Configuration::get(\PrestaShift\Service\UrlRedirects::COUNT_KEY)
            || !isset($params['controller_type']) || (int) $params['controller_type'] !== Dispatcher::FC_FRONT
            || !in_array($method, ['GET', 'HEAD'], true)
            || !isset($_SERVER['REQUEST_URI'])) {
            return;
        }

        try {
            $path = rawurldecode((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH));
            $idShop = (int) $this->context->shop->id;
            $row = \PrestaShift\Service\UrlRedirects::lookup($path, $idShop);
            if (!$row) {
                return;
            }
            // Built by Link from the record: the destination never comes from the request
            $url = \PrestaShift\Service\UrlRedirects::targetUrl($this->context->link, $row['entity'], $row['id_target'], $row['id_lang'], $idShop);
            if ($url === '' || rawurldecode((string) parse_url($url, PHP_URL_PATH)) === $path) {
                return;
            }
            if (!empty($_SERVER['QUERY_STRING'])) {
                $url .= (strpos($url, '?') === false ? '?' : '&') . $_SERVER['QUERY_STRING'];
            }
            $code = (int) Configuration::get(\PrestaShift\Service\UrlRedirects::CODE_KEY) === 302 ? 302 : 301;
        } catch (\Throwable $e) {
            return;
        }

        header(\PrestaShift\Service\UrlRedirects::FALLBACK_HEADER . ': fallback');
        header('Location: ' . $url, true, $code);
        exit;
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
