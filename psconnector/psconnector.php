<?php
/**
 * PrestaShift Connector
 * 
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @version   1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PsConnector extends Module
{
    public function __construct()
    {
        $this->name = 'psconnector';
        $this->tab = 'administration';
        $this->version = '1.1.0';
        $this->author = 'marcingajewski.pl';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('PrestaShift Connector');
        $this->description = $this->l('Secure API endpoint for PrestaShift migration tool.');

        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => _PS_VERSION_);
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall? This will break any active migrations.');
    }

    public function install()
    {
        // Generate initial token
        $token = bin2hex(openssl_random_pseudo_bytes(32));
        Configuration::updateValue('PS_CONNECTOR_TOKEN', $token);

        return parent::install();
    }

    public function uninstall()
    {
        Configuration::deleteByName('PS_CONNECTOR_TOKEN');
        return parent::uninstall();
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitPsConnector')) {
            $token = Tools::getValue('PS_CONNECTOR_TOKEN');
            if ($token && Validate::isCleanHtml($token)) {
                Configuration::updateValue('PS_CONNECTOR_TOKEN', $token);
                $output .= $this->displayConfirmation($this->l('Settings updated.'));
            }
        }

        if (Tools::isSubmit('resetToken')) {
            $token = bin2hex(openssl_random_pseudo_bytes(32));
            Configuration::updateValue('PS_CONNECTOR_TOKEN', $token);
            $output .= $this->displayConfirmation($this->l('New token generated.'));
        }

        return $output . $this->renderModernUI();
    }

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);
        $this->context->controller->addCSS($this->_path . 'views/css/admin.css?v=' . time());
    }

    public function renderModernUI()
    {
        // Force load CSS here as setMedia might not be triggered on module config page in some PS versions
        $this->context->controller->addCSS($this->_path . 'views/css/admin.css?v=' . time());

        $baseUrl = (Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
        $apiUrl = $baseUrl . __PS_BASE_URI__ . 'modules/' . $this->name . '/api.php';

        $this->context->smarty->assign([
            'connector_token' => Configuration::get('PS_CONNECTOR_TOKEN'),
            'api_url' => $apiUrl,
            'currentIndex' => AdminController::$currentIndex . '&configure=' . $this->name,
            'token' => Tools::getAdminTokenLite('AdminModules'),
            'module_version' => $this->version,
        ]);

        return $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }
}
