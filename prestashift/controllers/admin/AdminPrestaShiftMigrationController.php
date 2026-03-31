<?php
/**
 * PrestaShift Migration Module
 * 
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @version   1.0.0
 */

class AdminPrestaShiftMigrationController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent()
    {
        parent::initContent();

        $this->context->smarty->assign([
            'ps_version' => _PS_VERSION_,
            'module_dir' => $this->module->getPathUri(),
            'controller_url' => $this->context->link->getAdminLink('AdminPrestaShiftMigration'),
            'ps_translations' => [
                'loading' => $this->module->l('Loading...'),
                'error' => $this->module->l('Error'),
                'success' => $this->module->l('Success'),
                'connection_failed' => $this->module->l('Connection failed:'),
                'communication_error' => $this->module->l('Communication error:'),
                'migration_completed' => $this->module->l('Migration fully completed!'),
                'done' => $this->module->l('Done!'),
                'waiting' => $this->module->l('Waiting'),
                'resuming' => $this->module->l('Resuming migration from task:'),
                'detected_interrupted' => $this->module->l('Interrupted migration detected. Task:'),
                'offset' => $this->module->l(', Offset:'),
                'resume_button' => $this->module->l('Resume work from this point'),
                'reset_session' => $this->module->l('Reset / Start New'),
                'st_old_status' => $this->module->l('Old Status (Source)'),
                'st_new_status' => $this->module->l('New Status (Target)'),
                'st_error_fetch' => $this->module->l('Error while fetching statuses from source.'),
                'loading_statuses' => $this->module->l('Loading source statuses...'),
                'none_selected' => $this->module->l('None selected'),
                'yes_clean' => $this->module->l('Yes (Clean Install)'),
                'no' => $this->module->l('No'),
                'batch' => $this->module->l('Batch:'),
                'delay' => $this->module->l('Delay:'),
                'none' => $this->module->l('None'),
                'images' => $this->module->l('Images:'),
                'unknown' => $this->module->l('Unknown'),
                // Task identifiers for JS
                'customers' => $this->module->l('customers'),
                'addresses' => $this->module->l('addresses'),
                'categories' => $this->module->l('categories'),
                'tax_rules' => $this->module->l('tax rules'),
                'localization' => $this->module->l('localization'),
                'attribute_groups' => $this->module->l('attribute groups'),
                'attributes' => $this->module->l('attributes'),
                'products' => $this->module->l('products'),
                'product_attributes' => $this->module->l('combinations'),
                'specific_prices' => $this->module->l('discounts'),
                'manufacturers' => $this->module->l('manufacturers'),
                'suppliers' => $this->module->l('suppliers'),
                'features' => $this->module->l('features'),
                'feature_values' => $this->module->l('feature values'),
                'feature_products' => $this->module->l('feature assignment'),
                'attachments' => $this->module->l('attachments'),
                'cms' => $this->module->l('cms content'),
                'images' => $this->module->l('images'),
                'employees' => $this->module->l('employees'),
                'cart_rules' => $this->module->l('vouchers'),
                'messages' => $this->module->l('customer messages'),
                'carriers' => $this->module->l('carriers'),
                'orders' => $this->module->l('orders'),
            ]
        ]);

        $this->setTemplate('configure.tpl');
    }

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);
        $time = time(); // Force cache bust
        $this->addCSS($this->module->getPathUri() . 'views/css/admin.css?v=' . $time);
        $this->addJS($this->module->getPathUri() . 'views/js/admin.js?v=' . $time);
    }

    public function ajaxProcessrenderStep()
    {
        ob_start(); // Start buffering to capture any stray output
        
        $step = (int) Tools::getValue('step');
        $content = '';
        
        // Simplified debugging (kept for safety)

        try {
            if (!defined('_PS_MODULE_DIR_')) {
                throw new Exception('_PS_MODULE_DIR_ is not defined');
            }
            
            $moduleDir = _PS_MODULE_DIR_;
            $basePath = $moduleDir . 'prestashift/views/templates/admin/_steps/';
            
            $debug_info = [
                'step' => $step,
                'module_dir_const' => $moduleDir,
                'basePath' => $basePath
            ];
            
            // Log path info
            PrestaShopLogger::addLog("[PrestaShift] BasePath: $basePath", 1, null, 'PrestaShift', 1, true);

            switch ($step) {
                case 1: $tpl = $basePath . 'connection.tpl'; break;
                case 2: $tpl = $basePath . 'scope.tpl'; break;
                case 3: $tpl = $basePath . 'options.tpl'; break;
                case 4: $tpl = $basePath . 'migration.tpl'; break;
                default: $tpl = null;
            }
            
            $debug_info['tpl_path'] = $tpl;
            $debug_info['exists'] = ($tpl && file_exists($tpl));

            if ($tpl && file_exists($tpl)) {
                try {
                     $this->context->smarty->assign([
                         'controller_url' => $this->context->link->getAdminLink('AdminPrestaShiftMigration'),
                         'module_dir' => $this->module->getPathUri(),
                     ]);
                     $content = $this->context->smarty->fetch($tpl);
                } catch (Throwable $e) {
                     $content = "<!-- LOAD FAILED: " . $e->getMessage() . " -->"; 
                     $content .= @file_get_contents($tpl);
                     $debug_info['smarty_error'] = $e->getMessage();
                }
            } else {
                $content = '<div class="alert alert-danger">Template not found at: ' . $tpl . '</div>';
            }
            
        } catch (Throwable $e) {
            $debug_info['error'] = $e->getMessage();
            $content = '<div class="alert alert-danger">Fatal Error: ' . $e->getMessage() . '</div>';
            PrestaShopLogger::addLog("[PrestaShift] Fatal: " . $e->getMessage(), 3, null, 'PrestaShift', 1, true);
        }

        $response = [
            'content' => $content,
            'debug' => $debug_info, 
            'version' => 'FINAL_CHECK_V2'
        ];
        
        $json = json_encode($response);
        if ($json === false) {
             $json = json_encode(['content' => 'JSON Encode Error: ' . json_last_error_msg()]);
        }
        
        $json = json_encode($response);
        if ($json === false) {
             $json = json_encode(['content' => 'JSON Encode Error: ' . json_last_error_msg()]);
        }
        
        PrestaShopLogger::addLog("[PrestaShift] Response: $json", 1, null, 'PrestaShift', 1, true);
        
        ob_end_clean(); // Discard any warnings/text caught in buffer
        header('Content-Type: application/json');
        die($json);
    }

    public function ajaxProcesscheckConnection()
    {
        ob_start();
        $method = Tools::getValue('connection_method', 'bridge');

        try {
            if ($method === 'direct') {
                $config = [
                    'connection_method' => 'direct',
                    'db_host' => Tools::getValue('db_host', 'localhost'),
                    'db_port' => (int)Tools::getValue('db_port', 3306),
                    'db_name' => Tools::getValue('db_name'),
                    'db_user' => Tools::getValue('db_user'),
                    'db_pass' => Tools::getValue('db_pass', ''),
                    'db_prefix' => Tools::getValue('db_prefix', 'ps_'),
                    'source_url' => rtrim(Tools::getValue('source_url', ''), '/'),
                ];
            } else {
                $sourceUrl = rtrim(Tools::getValue('source_url'), '/');
                $config = [
                    'connection_method' => 'bridge',
                    'use_bridge' => 1,
                    'bridge_url' => $sourceUrl . '/modules/psconnector/api.php',
                    'bridge_token' => Tools::getValue('bridge_token'),
                    'db_prefix' => Tools::getValue('db_prefix', 'ps_'),
                    'source_url' => $sourceUrl,
                ];
            }

            $manager = new \PrestaShift\Service\MigrationManager();
            $conn = $manager->getConnection($config);

            $test = $conn->test();
            if (!$test['success']) {
                throw new \Exception("Connection test failed: " . ($test['error'] ?? 'Unknown error'));
            }
            $prefix = $test['prefix'] ?? $config['db_prefix'];
            $sourceVersion = $test['ps_version'] ?? 'unknown';
            $targetVersion = _PS_VERSION_;

            $warnings = $this->getVersionWarnings($sourceVersion, $targetVersion);

            $methodLabel = ($method === 'direct') ? 'Direct DB' : 'Bridge';
            $response = [
                'success' => true,
                'message' => $methodLabel . ' ' . $this->module->l('connected! Prefix detected:') . ' ' . $prefix,
                'source_version' => $sourceVersion,
                'target_version' => $targetVersion,
                'warnings' => $warnings,
            ];

        } catch (\Throwable $e) {
            $response = ['success' => false, 'message' => $this->module->l('Connection failed:') . ' ' . $e->getMessage()];
        }

        $json = json_encode($response);
        ob_end_clean();
        header('Content-Type: application/json');
        die($json);
    }
    
    /**
     * Generate warnings based on source/target version difference
     */
    private function getVersionWarnings($sourceVersion, $targetVersion)
    {
        $warnings = [];
        $srcMajor = (int)$sourceVersion;
        $tgtMajor = (int)$targetVersion;

        // PS 1.7 → 8 or 9
        if ($srcMajor === 1 && $tgtMajor >= 8) {
            $warnings[] = $this->module->l('redirect_type values will be auto-converted (301→301-product, 302→302-product).');
            $warnings[] = $this->module->l('Column id_product_redirected renamed to id_type_redirected — handled automatically.');
        }

        // PS 1.7 or 8 → 9
        if ($tgtMajor >= 9) {
            $warnings[] = $this->module->l('PrestaShop 9 uses Symfony 6.4 — some hooks were removed. Module configurations may need manual adjustment.');
        }

        // Same major version
        if ($srcMajor === $tgtMajor) {
            $warnings[] = $this->module->l('Same major version detected — minimal compatibility issues expected.');
        }

        // Downgrade warning
        if (version_compare($sourceVersion, $targetVersion, '>')) {
            $warnings[] = $this->module->l('WARNING: Source version is newer than target. Downgrade migration may cause data loss.');
        }

        return $warnings;
    }

    private function logProbe($msg) {
        $timestamp = date('H:i:s');
        error_log("[PrestaShift Probe $timestamp] $msg");
        // Optional: PrestaShopLogger::addLog("[PrestaShift Probe] $msg", 1);
    }

    /**
     * Pre-flight check before migration start
     */
    public function ajaxProcesspreFlight()
    {
        ob_start();
        $checks = [];

        // 1. PHP memory_limit
        $memoryLimit = ini_get('memory_limit');
        $memoryBytes = $this->convertToBytes($memoryLimit);
        $checks[] = [
            'label' => 'PHP memory_limit',
            'value' => $memoryLimit,
            'ok' => $memoryBytes >= 128 * 1024 * 1024,
            'hint' => $memoryBytes < 128 * 1024 * 1024 ? $this->module->l('Recommended: 256M or higher') : '',
        ];

        // 2. max_execution_time
        $maxExec = (int)ini_get('max_execution_time');
        $checks[] = [
            'label' => 'max_execution_time',
            'value' => $maxExec . 's',
            'ok' => $maxExec === 0 || $maxExec >= 30,
            'hint' => ($maxExec > 0 && $maxExec < 30) ? $this->module->l('Recommended: 60s or higher') : '',
        ];

        // 3. Disk free space
        $freeSpace = @disk_free_space(_PS_ROOT_DIR_);
        $checks[] = [
            'label' => $this->module->l('Free disk space'),
            'value' => $freeSpace ? round($freeSpace / 1024 / 1024) . ' MB' : 'unknown',
            'ok' => $freeSpace === false || $freeSpace > 500 * 1024 * 1024,
            'hint' => ($freeSpace && $freeSpace <= 500 * 1024 * 1024) ? $this->module->l('Low disk space — image transfer may fail') : '',
        ];

        // 4. cURL available
        $checks[] = [
            'label' => 'cURL',
            'value' => function_exists('curl_init') ? 'OK' : 'Missing',
            'ok' => function_exists('curl_init'),
            'hint' => !function_exists('curl_init') ? $this->module->l('cURL is required for bridge connection') : '',
        ];

        // 5. Target product count (is shop clean?)
        $productCount = (int)Db::getInstance()->getValue("SELECT COUNT(*) FROM `" . _DB_PREFIX_ . "product`");
        $checks[] = [
            'label' => $this->module->l('Existing products in target'),
            'value' => $productCount,
            'ok' => true,
            'hint' => $productCount > 0 ? $this->module->l('Consider enabling "Clean Target Data" to avoid ID conflicts') : '',
        ];

        $allOk = true;
        foreach ($checks as $c) {
            if (!$c['ok']) { $allOk = false; break; }
        }

        $response = ['success' => true, 'checks' => $checks, 'all_ok' => $allOk];

        $json = json_encode($response);
        ob_end_clean();
        header('Content-Type: application/json');
        die($json);
    }

    /**
     * Dry-run preview — count records from source
     */
    public function ajaxProcesspreview()
    {
        ob_start();
        try {
            $method = Tools::getValue('connection_method', 'bridge');
            $config = [
                'connection_method' => $method,
                'db_prefix' => Tools::getValue('db_prefix', 'ps_'),
                'source_url' => rtrim(Tools::getValue('source_url', ''), '/'),
            ];
            if ($method === 'direct') {
                $config['db_host'] = Tools::getValue('db_host', 'localhost');
                $config['db_port'] = (int)Tools::getValue('db_port', 3306);
                $config['db_name'] = Tools::getValue('db_name');
                $config['db_user'] = Tools::getValue('db_user');
                $config['db_pass'] = Tools::getValue('db_pass', '');
            } else {
                $config['bridge_token'] = Tools::getValue('bridge_token');
            }

            $manager = new \PrestaShift\Service\MigrationManager();
            $conn = $manager->getConnection($config);
            $prefix = $config['db_prefix'];

            $counts = [];
            $queries = [
                'products'      => "SELECT COUNT(*) as c FROM `{$prefix}product`",
                'categories'    => "SELECT COUNT(*) as c FROM `{$prefix}category` WHERE id_category > 2",
                'customers'     => "SELECT COUNT(*) as c FROM `{$prefix}customer`",
                'orders'        => "SELECT COUNT(*) as c FROM `{$prefix}orders`",
                'manufacturers' => "SELECT COUNT(*) as c FROM `{$prefix}manufacturer`",
                'carriers'      => "SELECT COUNT(*) as c FROM `{$prefix}carrier`",
                'cms'           => "SELECT COUNT(*) as c FROM `{$prefix}cms`",
                'images'        => "SELECT COUNT(*) as c FROM `{$prefix}image`",
                'cart_rules'    => "SELECT COUNT(*) as c FROM `{$prefix}cart_rule`",
            ];

            foreach ($queries as $key => $sql) {
                try {
                    $row = $conn->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
                    $counts[$key] = (int)($row[0]['c'] ?? 0);
                } catch (\Throwable $e) {
                    $counts[$key] = 0;
                }
            }

            $response = ['success' => true, 'counts' => $counts];
        } catch (\Throwable $e) {
            $response = ['success' => false, 'message' => $e->getMessage()];
        }

        $json = json_encode($response);
        ob_end_clean();
        header('Content-Type: application/json');
        die($json);
    }

    private function convertToBytes($val)
    {
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        $num = (int)$val;
        switch ($last) {
            case 'g': $num *= 1024;
            case 'm': $num *= 1024;
            case 'k': $num *= 1024;
        }
        return $num;
    }

    public function ajaxProcessstartMigration()
    {
        // NUCLEAR DEBUGGING for Batch
        register_shutdown_function(function() {
            $error = error_get_last();
            if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR)) {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'FATAL BATCH ERROR: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']]);
                die();
            }
        });

        ob_start();

        $method = Tools::getValue('connection_method', 'bridge');
        $config = [
            'connection_method' => $method,
            'db_prefix' => Tools::getValue('db_prefix', 'ps_'),
            'source_url' => rtrim(Tools::getValue('source_url', ''), '/'),
            'scope' => Tools::getValue('scope', []),
            'options' => Tools::getValue('options', [])
        ];

        // Add method-specific config
        if ($method === 'direct') {
            $config['db_host'] = Tools::getValue('db_host', 'localhost');
            $config['db_port'] = (int)Tools::getValue('db_port', 3306);
            $config['db_name'] = Tools::getValue('db_name');
            $config['db_user'] = Tools::getValue('db_user');
            $config['db_pass'] = Tools::getValue('db_pass', '');
        } else {
            $config['use_bridge'] = 1;
            $config['bridge_token'] = Tools::getValue('bridge_token');
        }

        try {
            // 2. Cleanup if requested
            if (!empty($config['options']['clean_target'])) {
                // Warning: CleanupService might use DB service? checking...
                $cleanup = new \PrestaShift\Service\CleanupService();
                $cleanup->cleanTargetShop($config['scope']);
            }

            // 3. Initialize Manager
            $manager = new \PrestaShift\Service\MigrationManager();
            
            // CLEAR PREVIOUS SAVED STATE if this is a fresh start
            \Configuration::updateValue('PRESTASHIFT_MIGRATION_STATE', '');

            // Save config for post-migration tasks (redirect map needs connection info)
            \Configuration::updateValue('PRESTASHIFT_LAST_CONFIG', json_encode($config));

            $state = $manager->initSession($config);

            $response = [
                'success' => true,
                'message' => $this->module->l('Initialization complete. Target wiped (if selected). Starting batch...'),
                'next_batch' => true,
                'state' => $state
            ];

        } catch (Throwable $e) {
            $response = [
                'success' => false,
                'message' => $this->module->l('Error during initialization:') . ' ' . $e->getMessage()
            ];
        }
        
        $json = json_encode($response);
        ob_end_clean();
        header('Content-Type: application/json');
        die($json);
    }

    /**
     * Post-migration cleanup tasks
     */
    public function ajaxProcesspostMigration()
    {
        ob_start();
        $tasks = [];

        // 1. Regenerate category tree
        try {
            \Category::regenerateEntireNtree();
            $tasks[] = ['label' => $this->module->l('Category tree regenerated'), 'ok' => true];
        } catch (\Throwable $e) {
            $tasks[] = ['label' => $this->module->l('Category tree regeneration failed'), 'ok' => false, 'error' => $e->getMessage()];
        }

        // 2. Rebuild search index
        try {
            if (class_exists('Search')) {
                \Search::indexation(true);
                $tasks[] = ['label' => $this->module->l('Search index rebuilt'), 'ok' => true];
            }
        } catch (\Throwable $e) {
            $tasks[] = ['label' => $this->module->l('Search index rebuild failed'), 'ok' => false, 'error' => $e->getMessage()];
        }

        // 3. Clear Smarty cache
        try {
            \Tools::clearSmartyCache();
            $tasks[] = ['label' => $this->module->l('Smarty cache cleared'), 'ok' => true];
        } catch (\Throwable $e) {
            $tasks[] = ['label' => $this->module->l('Smarty cache clear failed'), 'ok' => false, 'error' => $e->getMessage()];
        }

        // 4. Clear Symfony cache (PS 1.7+)
        try {
            $cacheDir = _PS_ROOT_DIR_ . '/var/cache/';
            if (is_dir($cacheDir . 'prod')) {
                \Tools::deleteDirectory($cacheDir . 'prod', false);
            }
            if (is_dir($cacheDir . 'dev')) {
                \Tools::deleteDirectory($cacheDir . 'dev', false);
            }
            $tasks[] = ['label' => $this->module->l('Symfony cache cleared'), 'ok' => true];
        } catch (\Throwable $e) {
            $tasks[] = ['label' => $this->module->l('Symfony cache clear failed'), 'ok' => false, 'error' => $e->getMessage()];
        }

        // 5. Refresh product indexing flags
        try {
            \Db::getInstance()->execute("UPDATE `" . _DB_PREFIX_ . "product` SET `indexed` = 1");
            $tasks[] = ['label' => $this->module->l('Product index flags updated'), 'ok' => true];
        } catch (\Throwable $e) {
            $tasks[] = ['label' => $this->module->l('Product index update failed'), 'ok' => false, 'error' => $e->getMessage()];
        }

        // 6. Collect final report stats from target DB
        $report = [];
        $reportQueries = [
            'products'     => "SELECT COUNT(*) as c FROM `" . _DB_PREFIX_ . "product`",
            'categories'   => "SELECT COUNT(*) as c FROM `" . _DB_PREFIX_ . "category` WHERE id_category > 2",
            'customers'    => "SELECT COUNT(*) as c FROM `" . _DB_PREFIX_ . "customer`",
            'orders'       => "SELECT COUNT(*) as c FROM `" . _DB_PREFIX_ . "orders`",
            'images'       => "SELECT COUNT(*) as c FROM `" . _DB_PREFIX_ . "image`",
            'manufacturers'=> "SELECT COUNT(*) as c FROM `" . _DB_PREFIX_ . "manufacturer`",
            'cms_pages'    => "SELECT COUNT(*) as c FROM `" . _DB_PREFIX_ . "cms`",
            'carriers'     => "SELECT COUNT(*) as c FROM `" . _DB_PREFIX_ . "carrier` WHERE deleted = 0",
        ];
        foreach ($reportQueries as $key => $sql) {
            try {
                $report[$key] = (int)\Db::getInstance()->getValue($sql);
            } catch (\Throwable $e) {
                $report[$key] = 0;
            }
        }

        // 7. Generate redirect map
        $redirectFile = null;
        try {
            $savedState = (new \PrestaShift\Service\MigrationManager())->getSavedState();
            if (!$savedState) {
                $savedState = json_decode(\Configuration::get('PRESTASHIFT_LAST_CONFIG'), true);
            }
            if ($savedState && isset($savedState['config'])) {
                $cfg = $savedState['config'];
                $manager = new \PrestaShift\Service\MigrationManager();
                $conn = $manager->getConnection($cfg);
                $redirectFile = \PrestaShift\Service\RedirectMapService::generate(
                    $conn, $cfg['db_prefix'], $cfg['source_url'] ?? ''
                );
                if ($redirectFile && file_exists($redirectFile)) {
                    $tasks[] = ['label' => $this->module->l('Redirect map generated:') . ' ' . basename($redirectFile), 'ok' => true];
                }
            }
        } catch (\Throwable $e) {
            $tasks[] = ['label' => $this->module->l('Redirect map generation failed'), 'ok' => false, 'error' => $e->getMessage()];
        }

        $response = ['success' => true, 'tasks' => $tasks, 'report' => $report, 'redirect_file' => $redirectFile];
        $json = json_encode($response);
        ob_end_clean();
        header('Content-Type: application/json');
        die($json);
    }

    public function ajaxProcessrunBatch()
    {
        // NUCLEAR DEBUGGING for Batch
        register_shutdown_function(function() {
            $error = error_get_last();
            if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR)) {
                http_response_code(500); 
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'FATAL BATCH ERROR: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']]);
                die();
            }
        });

        ob_start();
        $stateRaw = Tools::getValue('state');
        
        // Decode JSON state if string (sent via JSON.stringify in JS)
        if (is_string($stateRaw)) {
            $state = json_decode($stateRaw, true);
        } else {
            $state = $stateRaw;
        }
        
        try {
            $manager = new \PrestaShift\Service\MigrationManager();
            if (!is_array($state) || (!$state['config']['use_bridge'] && empty($state['config']['db_host']))) {
                 // Fallback info for debugging
                 $type = gettype($stateRaw);
                 throw new Exception("Invalid Migration State received. Config missing. Type: $type. Raw: " . substr(print_r($stateRaw, true), 0, 100));
            }
            $response = $manager->processBatch($state);
            
        } catch (Throwable $e) {
            // TELEMETRY: If user agreed, send the error report
            $config = $this->getMigrationConfig($state);
            $telemetry = new \PrestaShift\Service\TelemetryService();
            $telemetry->sendErrorReport([
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ], $config);

            $response = [
                'success' => false,
                'next_batch' => false,
                'message' => $this->module->l('Batch Error:') . ' ' . $e->getMessage()
            ];
        }
        
        $json = json_encode($response);
        ob_end_clean();
        header('Content-Type: application/json');
        die($json);
    }

    private function getMigrationConfig($state = null)
    {
        if ($state && isset($state['config'])) {
            return $state['config'];
        }
        
        $savedState = (new \PrestaShift\Service\MigrationManager())->getSavedState();
        if ($savedState && isset($savedState['config'])) {
            return $savedState['config'];
        }
        
        return [
            'source_url' => Tools::getValue('source_url'),
            'options' => Tools::getValue('options', [])
        ];
    }

    public function ajaxProcessping()
    {
        ob_end_clean(); // Clean any previous buffers
        header('Content-Type: application/json');
        die(json_encode(['success' => true, 'message' => 'Pong!', 'class' => __CLASS__]));
    }

    public function ajaxProcessgetSavedState()
    {
        ob_start();
        $manager = new \PrestaShift\Service\MigrationManager();
        $state = $manager->getSavedState();
        
        $response = [
            'success' => true,
            'has_state' => !empty($state),
            'state' => $state
        ];
        
        $json = json_encode($response);
        ob_end_clean();
        header('Content-Type: application/json');
        die($json);
    }

    public function ajaxProcessclearSavedState()
    {
        \Configuration::updateValue('PRESTASHIFT_MIGRATION_STATE', '');
        header('Content-Type: application/json');
        die(json_encode(['success' => true]));
    }

    public function ajaxProcessgetSourceStatuses()
    {
        ob_start();
        try {
            $config = [
                'bridge_token' => Tools::getValue('bridge_token'),
                'source_url' => Tools::getValue('source_url'),
                'db_prefix' => Tools::getValue('db_prefix', 'ps_')
            ];

            $manager = new \PrestaShift\Service\MigrationManager();
            $conn = $manager->getConnection($config);
            $prefix = $config['db_prefix'];

            // 1. Get ALL languages from source to find a valid one for names
            $langSql = "SELECT id_lang FROM `{$prefix}lang` WHERE active = 1 LIMIT 1";
            $langStmt = $conn->query($langSql);
            $sourceLangId = $langStmt->fetchColumn() ?: 1;

            // 2. Get Source Statuses
            $sql = "SELECT os.id_order_state, osl.name 
                    FROM `{$prefix}order_state` os 
                    LEFT JOIN `{$prefix}order_state_lang` osl ON (os.id_order_state = osl.id_order_state AND osl.id_lang = $sourceLangId)
                    ORDER BY os.id_order_state ASC";
            $stmt = $conn->query($sql);
            $sourceStatuses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 3. Get Local Statuses (Destination)
            $localLangId = (int)$this->context->language->id;
            $localStatuses = \Db::getInstance()->executeS("
                SELECT os.id_order_state, osl.name 
                FROM `"._DB_PREFIX_."order_state` os
                LEFT JOIN `"._DB_PREFIX_."order_state_lang` osl ON (os.id_order_state = osl.id_order_state AND osl.id_lang = $localLangId)
                ORDER BY os.id_order_state ASC
            ");

            $response = [
                'success' => true,
                'source_statuses' => $sourceStatuses,
                'local_statuses' => $localStatuses
            ];

        } catch (\Throwable $e) {
            $response = [
                'success' => false,
                'message' => $this->module->l('Error fetching statuses:') . ' ' . $e->getMessage()
            ];
        }

        $json = json_encode($response);
        ob_end_clean();
        header('Content-Type: application/json');
        die($json);
    }
}
