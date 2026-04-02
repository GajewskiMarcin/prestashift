<?php
/**
 * PrestaShift Migration Module
 * 
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @version   1.0.0
 */
namespace PrestaShift\Service;

use Exception;
use PrestaShift\Service\ConnectorClient;

class MigrationManager
{
    private $connectionService;
    private $db_connection;
    private $steps = [];

    /**
     * Ordered sequence of all migration tasks
     */
    private static $taskSequence = [
        'customers', 'addresses',
        'categories', 'tax_rules', 'localization', 'states',
        'attribute_groups', 'attributes',
        'products', 'product_download', 'pack', 'product_attributes',
        'customization_field',
        'specific_prices', 'catalog_price_rule',
        'manufacturers', 'suppliers', 'product_supplier',
        'features', 'feature_values', 'feature_products',
        'tag', 'attachments',
        'cms', 'meta',
        'images',
        'employees',
        'contacts', 'stores',
        'cart_rules', 'messages', 'carriers',
        'orders', 'order_payment', 'order_slip', 'cart',
        'wishlist', 'stock_mvt',
        'configuration'
    ];

    /**
     * Maps each task to its scope key from the UI checkboxes
     */
    private static $taskScopeMap = [
        'customers'          => 'customers',
        'addresses'          => 'customers',
        'categories'         => 'catalog',
        'tax_rules'          => 'tax_rules',
        'localization'       => 'localization',
        'states'             => 'localization',
        'attribute_groups'   => 'catalog',
        'attributes'         => 'catalog',
        'products'           => 'catalog',
        'product_download'   => 'catalog',
        'pack'               => 'catalog',
        'product_attributes' => 'catalog',
        'customization_field'=> 'catalog',
        'specific_prices'    => 'specific_prices',
        'catalog_price_rule' => 'specific_prices',
        'manufacturers'      => 'manufacturers',
        'suppliers'          => 'suppliers',
        'product_supplier'   => 'suppliers',
        'features'           => 'catalog',
        'feature_values'     => 'catalog',
        'feature_products'   => 'catalog',
        'tag'                => 'catalog',
        'attachments'        => 'attachments',
        'cms'                => 'cms',
        'meta'               => 'cms',
        'images'             => 'images',
        'employees'          => 'employees',
        'contacts'           => 'contacts',
        'stores'             => 'contacts',
        'cart_rules'         => 'cart_rules',
        'messages'           => 'messages',
        'carriers'           => 'carriers',
        'orders'             => 'orders',
        'order_payment'      => 'orders',
        'order_slip'         => 'orders',
        'cart'               => 'orders',
        'wishlist'           => 'customers',
        'stock_mvt'          => 'catalog',
        'configuration'      => 'configuration',
    ];

    public function __construct()
    {
    }

    private function l($string)
    {
        return \Translate::getModuleTranslation('prestashift', $string, 'MigrationManager');
    }

    /**
     * Get granular sync history from Configuration
     */
    public function getSyncHistory()
    {
        $data = \Configuration::get('PRESTASHIFT_SYNC_HISTORY');
        if (empty($data)) {
            return [];
        }
        $history = json_decode($data, true);
        return is_array($history) ? $history : [];
    }

    /**
     * Update sync history for a specific entity
     */
    public function updateSyncHistory($entity, $date)
    {
        $history = $this->getSyncHistory();
        $history[$entity] = $date;
        \Configuration::updateValue('PRESTASHIFT_SYNC_HISTORY', json_encode($history));
    }

    /**
     * Initialize the migration process
     */
    public function initSession($config)
    {
        $sessionData = [
            'config' => $config,
            'current_step_index' => 0,
            'processed_count' => 0,
            'total_count' => 0,
            'status' => 'initialized',
            'offset' => 0,
            'current_task' => $this->getFirstEnabledTask(isset($config['scope']) ? $config['scope'] : [])
        ];
        
        return $sessionData;
    }

    public function getConnection($config)
    {
        if (!$this->db_connection) {
            $method = isset($config['connection_method']) ? $config['connection_method'] : 'bridge';

            if ($method === 'direct') {
                $this->db_connection = new DirectDbClient(
                    $config['db_host'] ?? 'localhost',
                    $config['db_port'] ?? 3306,
                    $config['db_name'] ?? '',
                    $config['db_user'] ?? '',
                    $config['db_pass'] ?? '',
                    $config['db_prefix'] ?? 'ps_',
                    $config['source_url'] ?? ''
                );
            } else {
                $endpoint = $config['bridge_url'] ?? '';

                // Derive endpoint from source_url if not explicitly provided
                if (empty($endpoint) && !empty($config['source_url'])) {
                    $sourceUrl = rtrim($config['source_url'], '/');
                    $endpoint = $sourceUrl . '/modules/psconnector/api.php';
                }

                $this->db_connection = new ConnectorClient($endpoint, $config['bridge_token'] ?? '');
            }
        }
        return $this->db_connection;
    }

    /**
     * Check if a task is enabled based on selected scope
     */
    private function isTaskEnabled($task, $scope)
    {
        if (empty($scope)) {
            return true; // No scope = run everything (backward compat)
        }
        $requiredScope = isset(self::$taskScopeMap[$task]) ? self::$taskScopeMap[$task] : null;
        return $requiredScope && !empty($scope[$requiredScope]);
    }

    /**
     * Get the first enabled task based on scope selection
     */
    private function getFirstEnabledTask($scope)
    {
        foreach (self::$taskSequence as $task) {
            if ($this->isTaskEnabled($task, $scope)) {
                return $task;
            }
        }
        return 'finished';
    }

    /**
     * Main processing loop
     */
    public function processBatch($state)
    {
        $limit = isset($state['config']['options']['batch_size']) ? (int)$state['config']['options']['batch_size'] : (isset($state['config']['batch_size']) ? (int)$state['config']['batch_size'] : 200);
        
        // Environment Safety: Ensure critical directory constants are defined
        // Some PS environments (especially in AJAX/Bridge context) might miss these.
        if (!defined('_PS_PROD_IMG_DIR_')) {
            define('_PS_PROD_IMG_DIR_', _PS_IMG_DIR_ . 'p/');
        }
        if (!defined('_PS_MANU_IMG_DIR_')) {
            define('_PS_MANU_IMG_DIR_', _PS_IMG_DIR_ . 'm/');
        }
        if (!defined('_PS_DOWNLOAD_DIR_')) {
            define('_PS_DOWNLOAD_DIR_', _PS_ROOT_DIR_ . '/download/');
        }
        
        // Safety clamps
        if ($limit < 50) $limit = 50;
        if ($limit > 2000) $limit = 2000;

        $config = $state['config'];
        $conn = $this->getConnection($config);
        $prefix = $config['db_prefix'];

        // Set target shop ID
        $targetShopId = isset($config['options']['target_shop_id']) ? (int)$config['options']['target_shop_id'] : 1;
        SchemaHelper::setTargetShopId($targetShopId);

        // Init logger
        $log = LogService::getInstance();
        $debugMode = !empty($config['options']['debug_mode']);
        $log->setEnabled($debugMode);
        
        $incremental = !empty($config['options']['incremental']);
        $syncHistory = $this->getSyncHistory();
        $task = isset($state['current_task']) ? $state['current_task'] : 'customers';

        // Skip tasks that are not in the selected scope
        $scope = isset($config['scope']) ? $config['scope'] : [];
        if (!empty($scope)) {
            while ($task !== 'finished' && !$this->isTaskEnabled($task, $scope)) {
                $idx = array_search($task, self::$taskSequence);
                $task = ($idx !== false && $idx + 1 < count(self::$taskSequence))
                    ? self::$taskSequence[$idx + 1]
                    : 'finished';
            }
            $state['current_task'] = $task;
        }

        $lastDate = null;
        if ($incremental && isset($syncHistory[$task])) {
            $lastDate = $syncHistory[$task];
        }

        // Identify current task
        // MVP: Simple sequence: customers -> products -> orders
        // Improve with a proper task queue later
        
        // $task identified above for lastDate logic
        $offset = isset($state['offset']) ? (int)$state['offset'] : 0;
        
        $result = ['count' => 0, 'finished' => false];
        $message = '';

        switch ($task) {
            // 1. Customers
            case 'customers':
                $step = new Steps\CustomerMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating customers (Offset: %d)...'), $offset);
                if ($result['finished']) {
                    $this->updateSyncHistory('customers', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'addresses'; $state['offset'] = 0; $message = $this->l('Customers done. Starting addresses...');
                } else { $state['offset'] += $limit; }
                break;

            // 2. Addresses
            case 'addresses':
                $step = new Steps\AddressMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating addresses (Offset: %d)...'), $offset);
                if ($result['finished']) {
                    $this->updateSyncHistory('addresses', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'categories'; $state['offset'] = 0; $message = $this->l('Addresses done. Starting categories...');
                } else { $state['offset'] += $limit; }
                break;

            // 3. Categories
            case 'categories':
                $step = new Steps\CategoryMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating categories (Offset: %d)...'), $offset);
                if ($result['finished']) {
                    $this->updateSyncHistory('categories', date('Y-m-d H:i:s'));
                    // Restore Root/Home & Regen Tree
                    try {
                         $step->restoreRootCategories();
                         \Category::regenerateEntireNtree();
                    } catch (\Exception $e) {}

                    $state['current_task'] = 'tax_rules'; $state['offset'] = 0; $message = $this->l('Categories done. Starting tax rules...');
                } else { $state['offset'] += $limit; }
                break;
            
            // 4. Tax Rules
            case 'tax_rules':
                $step = new Steps\TaxRulesMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating tax rules (Offset: %d)...'), $offset);
                if ($result['finished']) {
                    $this->updateSyncHistory('tax_rules', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'localization'; $state['offset'] = 0; $message = $this->l('Tax rules done. Starting localization...');
                } else { $state['offset'] += $limit; }
                break;

            // 4a. Localization (Countries, Zones, Currencies)
            case 'localization':
                $step = new Steps\LocalizationMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = $this->l('Migrating localization data...');
                if ($result['finished']) {
                    $this->updateSyncHistory('localization', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'states'; $state['offset'] = 0; $message = $this->l('Localization done. Starting states...');
                }
                break;

            // 4b. States/Regions
            case 'states':
                $step = new Steps\StateMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = $this->l('Migrating states/regions...');
                if ($result['finished']) {
                    $this->updateSyncHistory('states', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'attribute_groups'; $state['offset'] = 0; $message = $this->l('States done. Starting attributes...');
                }
                break;

            // 5. Attribute Groups
            case 'attribute_groups':
                $step = new Steps\AttributeGroupMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating attribute groups (Offset: %d)...'), $offset);
                if ($result['finished']) {
                    $this->updateSyncHistory('attribute_groups', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'attributes'; $state['offset'] = 0; $message = $this->l('Groups done. Starting attribute values...');
                } else { $state['offset'] += $limit; }
                break;

            // 6. Attributes
            case 'attributes':
                $step = new Steps\AttributeMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating attributes (Offset: %d)...'), $offset);
                if ($result['finished']) {
                    $this->updateSyncHistory('attributes', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'products'; $state['offset'] = 0; $message = $this->l('Attributes done. Starting products...');
                } else { $state['offset'] += $limit; }
                break;

            // 7. Products
            case 'products':
                $step = new Steps\ProductMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating products (Offset: %d)...'), $offset);
                if ($result['finished']) {
                    $this->updateSyncHistory('products', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'product_download'; $state['offset'] = 0; $message = $this->l('Products done. Starting virtual products...');
                } else { $state['offset'] += $limit; }
                break;

            // 7b. Product Downloads (Virtual Products)
            case 'product_download':
                $source_url = isset($config['source_url']) ? $config['source_url'] : '';
                $skip_files = isset($config['options']['skip_files']) && $config['options']['skip_files'];
                $step = new Steps\ProductDownloadMigrationStep($conn, $prefix, $source_url, $skip_files);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating virtual products (Offset: %d)...'), $offset);
                if ($result['finished']) {
                    $this->updateSyncHistory('product_download', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'pack'; $state['offset'] = 0; $message = $this->l('Virtual products done. Starting packs...');
                } else { $state['offset'] += $limit; }
                break;

            // 7c. Pack Products
            case 'pack':
                $step = new Steps\PackMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating pack products (Offset: %d)...'), $offset);
                if ($result['finished']) {
                    $this->updateSyncHistory('pack', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'product_attributes'; $state['offset'] = 0; $message = $this->l('Packs done. Starting combinations...');
                } else { $state['offset'] += $limit; }
                break;

            // 8. Product Combinations
            case 'product_attributes':
                $step = new Steps\ProductAttributeMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating combinations (Offset: %d)...'), $offset);
                if ($result['finished']) {
                    $this->updateSyncHistory('product_attributes', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'customization_field'; $state['offset'] = 0; $message = $this->l('Combinations done. Starting customization fields...');
                } else { $state['offset'] += $limit; }
                break;

            // 8b. Customization Fields
            case 'customization_field':
                $step = new Steps\CustomizationFieldMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating customization fields (Offset: %d)...'), $offset);
                if ($result['finished']) {
                    $this->updateSyncHistory('customization_field', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'specific_prices'; $state['offset'] = 0; $message = $this->l('Customization fields done. Starting pricing rules...');
                } else { $state['offset'] += $limit; }
                break;

            // 8c. Specific Prices (Discounts)
            case 'specific_prices':
                $step = new Steps\SpecificPriceMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating specific prices (Offset: %d)...'), $offset);
                if ($result['finished']) {
                    $this->updateSyncHistory('specific_prices', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'catalog_price_rule'; $state['offset'] = 0; $message = $this->l('Specific prices done. Starting catalog price rules...');
                } else { $state['offset'] += $limit; }
                break;

            // 8d. Catalog Price Rules
            case 'catalog_price_rule':
                $step = new Steps\CatalogPriceRuleMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating catalog price rules (Offset: %d)...'), $offset);
                if ($result['finished']) {
                    $this->updateSyncHistory('catalog_price_rule', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'manufacturers'; $state['offset'] = 0; $message = $this->l('Price rules done. Starting manufacturers...');
                } else { $state['offset'] += $limit; }
                break;

            // 9. Manufacturers
            case 'manufacturers':
                $source_url = isset($config['source_url']) ? $config['source_url'] : '';
                $skip_files = isset($config['options']['skip_files']) && $config['options']['skip_files'];
                $step = new Steps\ManufacturerMigrationStep($conn, $prefix, $source_url, $skip_files);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating manufacturers (Offset: %d)...'), $offset);
                if ($result['finished']) {
                    $this->updateSyncHistory('manufacturers', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'suppliers'; $state['offset'] = 0; $message = $this->l('Manufacturers done. Starting suppliers...');
                } else { $state['offset'] += $limit; }
                break;

            // 9b. Suppliers
            case 'suppliers':
                $step = new Steps\SupplierMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating suppliers (Offset: %d)...'), $offset);
                if ($result['finished']) {
                    $this->updateSyncHistory('suppliers', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'product_supplier'; $state['offset'] = 0; $message = $this->l('Suppliers done. Starting product-supplier links...');
                } else { $state['offset'] += $limit; }
                break;

            // 9c. Product Suppliers
            case 'product_supplier':
                $step = new Steps\ProductSupplierMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating product-supplier links (Offset: %d)...'), $offset);
                if ($result['finished']) {
                    $this->updateSyncHistory('product_supplier', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'features'; $state['offset'] = 0; $message = $this->l('Product suppliers done. Starting features...');
                } else { $state['offset'] += $limit; }
                break;

            // 10. Features
            case 'features':
                $step = new Steps\FeatureMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating features (Offset: %d)...'), $offset);
                if ($result['finished']) {
                    $this->updateSyncHistory('features', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'feature_values'; $state['offset'] = 0; $message = $this->l('Features done. Starting feature values...');
                } else { $state['offset'] += $limit; }
                break;

            // 11. Feature Values
            case 'feature_values':
                $step = new Steps\FeatureValueMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating feature values (Offset: %d)...'), $offset);
                if ($result['finished']) {
                    $this->updateSyncHistory('feature_values', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'feature_products'; $state['offset'] = 0; $message = $this->l('Values done. Assigning features...');
                } else { $state['offset'] += $limit; }
                break;

            // 12. Feature Products
            case 'feature_products':
                // Higher batch or keep standard
                $step = new Steps\FeatureProductMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate); // Use standard batch
                $message = sprintf($this->l('Assigning features (Offset: %d)...'), $offset);
                if ($result['finished']) {
                    $this->updateSyncHistory('feature_products', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'tag'; $state['offset'] = 0; $message = $this->l('Features assigned. Starting tags...');
                } else { $state['offset'] += $limit; }
                break;

            // 12b. Tags
            case 'tag':
                $step = new Steps\TagMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating tags (Offset: %d)...'), $offset);
                if ($result['finished']) {
                    $this->updateSyncHistory('tag', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'attachments'; $state['offset'] = 0; $message = $this->l('Tags done. Starting attachments...');
                } else { $state['offset'] += $limit; }
                break;
            
            // 13. Attachments
            case 'attachments':
                $source_url = isset($config['source_url']) ? $config['source_url'] : '';
                $skip_files = isset($config['options']['skip_files']) && $config['options']['skip_files'];
                $step = new Steps\AttachmentMigrationStep($conn, $prefix, $source_url, $skip_files);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating attachments (Offset: %d)...'), $offset);
                if ($result['finished']) {
                     $this->updateSyncHistory('attachments', date('Y-m-d H:i:s'));
                     $state['current_task'] = 'cms'; $state['offset'] = 0; $message = $this->l('Attachments done. Starting CMS...');
                } else { $state['offset'] += $limit; }
                break;
            
            // 14. CMS
            case 'cms':
                $source_url = isset($config['source_url']) ? $config['source_url'] : '';
                $skip_files = isset($config['options']['skip_files']) && $config['options']['skip_files'];
                $step = new Steps\CmsMigrationStep($conn, $prefix, $source_url, $skip_files);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating CMS content (Offset: %d)...'), $offset);
                 if ($result['finished']) {
                     $this->updateSyncHistory('cms', date('Y-m-d H:i:s'));
                     $state['current_task'] = 'meta'; $state['offset'] = 0; $message = $this->l('CMS done. Starting meta/SEO...');
                 } else { $state['offset'] += $limit; }
                break;

            // 14b. Meta / SEO
            case 'meta':
                $step = new Steps\MetaMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = $this->l('Migrating meta/SEO data...');
                if ($result['finished']) {
                    $this->updateSyncHistory('meta', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'images'; $state['offset'] = 0; $message = $this->l('Meta done. Starting images...');
                }
                break;

            // 15. Images
            case 'images':
                // Special limit for images
                $limit = isset($config['options']['img_batch_size']) ? (int)$config['options']['img_batch_size'] : (isset($config['img_batch_size']) ? (int)$config['img_batch_size'] : 20);
                
                // Safety clamp for images (5-100)
                if ($limit < 5) $limit = 5;
                if ($limit > 100) $limit = 100;
                
                $source_url = isset($config['source_url']) ? $config['source_url'] : '';
                $skip_files = isset($config['options']['skip_files']) && $config['options']['skip_files'];
                
                $step = new Steps\ImageMigrationStep($conn, $prefix, $source_url, $skip_files);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating images (Offset: %d)...'), $offset);
                
                if ($result['count'] == 0 && $offset > 0) {
                     $result['finished'] = true;
                }

                if ($result['finished']) {
                    $this->updateSyncHistory('images', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'employees'; 
                    $state['offset'] = 0;
                    $message = $this->l('Images completed. Starting administration data...');
                } else {
                    $state['offset'] += $limit;
                }
                break;

            case 'employees':
                $step = new Steps\EmployeeMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating employees (Offset: %d)...'), $offset);
                if ($result['finished']) {
                    $this->updateSyncHistory('employees', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'contacts'; $state['offset'] = 0; $message = $this->l('Employees done. Starting contacts...');
                } else { $state['offset'] += $limit; }
                break;

            // 16. Contacts
            case 'contacts':
                $step = new Steps\ContactMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = $this->l('Migrating contacts...');
                if ($result['finished']) {
                    $this->updateSyncHistory('contacts', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'stores'; $state['offset'] = 0; $message = $this->l('Contacts done. Starting stores...');
                }
                break;

            // 16b. Physical Stores
            case 'stores':
                $step = new Steps\StoreMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating stores (Offset: %d)...'), $offset);
                if ($result['finished']) {
                    $this->updateSyncHistory('stores', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'cart_rules'; $state['offset'] = 0; $message = $this->l('Stores done. Starting cart rules...');
                } else { $state['offset'] += $limit; }
                break;

            // 17. Cart Rules (Vouchers)
            case 'cart_rules':
                $step = new Steps\CartRuleMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating cart rules (Offset: %d)...'), $offset);
                if ($result['finished']) {
                    $this->updateSyncHistory('cart_rules', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'messages'; $state['offset'] = 0; $message = $this->l('Vouchers done. Starting messages...');
                } else { $state['offset'] += $limit; }
                break;

            // 15b. Customer Messages
            case 'messages':
                $step = new Steps\MessageMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating customer messages (Offset: %d)...'), $offset);
                if ($result['finished']) {
                    $this->updateSyncHistory('messages', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'carriers'; $state['offset'] = 0; $message = $this->l('Messages done. Starting carriers...');
                } else { $state['offset'] += $limit; }
                break;

            // 15c. Carriers
            case 'carriers':
                $skip_files = isset($config['options']['skip_files']) && $config['options']['skip_files'];
                $step = new Steps\CarrierMigrationStep($conn, $prefix, $skip_files);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating carriers (Offset: %d)...'), $offset);
                if ($result['finished']) {
                    $this->updateSyncHistory('carriers', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'orders'; $state['offset'] = 0; $message = $this->l('Carriers done. Starting orders...');
                } else { $state['offset'] += $limit; }
                break;

            case 'orders':
                $status_map = isset($config['options']['status_map']) ? $config['options']['status_map'] : [];
                $step = new Steps\OrderMigrationStep($conn, $prefix, $status_map);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating orders (Offset: %d)...'), $offset);
                
                if ($result['count'] == 0 && $offset > 0) {
                     $result['finished'] = true;
                }

                if ($result['finished']) {
                    $this->updateSyncHistory('orders', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'order_payment';
                    $state['offset'] = 0;
                    $message = $this->l('Orders done. Starting order payments...');
                } else {
                    $state['offset'] += $limit;
                }
                break;

            // 20b. Order Payments & Invoices
            case 'order_payment':
                $step = new Steps\OrderPaymentMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating order payments (Offset: %d)...'), $offset);
                if ($result['count'] == 0 && $offset > 0) {
                    $result['finished'] = true;
                }
                if ($result['finished']) {
                    $this->updateSyncHistory('order_payment', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'order_slip'; $state['offset'] = 0; $message = $this->l('Payments done. Starting credit slips...');
                } else { $state['offset'] += $limit; }
                break;

            // 20c. Order Slips (Credit Notes)
            case 'order_slip':
                $step = new Steps\OrderSlipMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating credit slips (Offset: %d)...'), $offset);
                if ($result['finished']) {
                    $this->updateSyncHistory('order_slip', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'cart'; $state['offset'] = 0; $message = $this->l('Credit slips done. Starting carts...');
                } else { $state['offset'] += $limit; }
                break;

            // 20d. Carts
            case 'cart':
                $step = new Steps\CartMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating carts (Offset: %d)...'), $offset);
                if ($result['count'] == 0 && $offset > 0) {
                    $result['finished'] = true;
                }
                if ($result['finished']) {
                    $this->updateSyncHistory('cart', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'wishlist';
                    $state['offset'] = 0;
                    $message = $this->l('Carts done. Starting wishlists...');
                } else {
                    $state['offset'] += $limit;
                }
                break;

            // Wishlists
            case 'wishlist':
                $step = new Steps\WishlistMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating wishlists (Offset: %d)...'), $offset);
                if ($result['finished']) {
                    $this->updateSyncHistory('wishlist', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'stock_mvt'; $state['offset'] = 0; $message = $this->l('Wishlists done. Starting stock movements...');
                } else { $state['offset'] += $limit; }
                break;

            // Stock Movements
            case 'stock_mvt':
                $step = new Steps\StockMvtMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = sprintf($this->l('Migrating stock movements (Offset: %d)...'), $offset);
                if ($result['count'] == 0 && $offset > 0) {
                    $result['finished'] = true;
                }
                if ($result['finished']) {
                    $this->updateSyncHistory('stock_mvt', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'configuration'; $state['offset'] = 0; $message = $this->l('Stock movements done. Starting configuration...');
                } else { $state['offset'] += $limit; }
                break;

            // Configuration (selective)
            case 'configuration':
                $step = new Steps\ConfigurationMigrationStep($conn, $prefix);
                $result = $step->process($offset, $limit, $lastDate);
                $message = $this->l('Migrating shop configuration...');
                if ($result['finished']) {
                    $this->updateSyncHistory('configuration', date('Y-m-d H:i:s'));
                    $state['current_task'] = 'finished';
                    $state['offset'] = 0;
                    $message = $this->l('Migration fully completed!');
                }
                break;

            case 'finished':
                return [
                    'success' => true,
                    'message' => 'Migration fully completed!',
                    'next_batch' => false,
                    'progress' => 100,
                    'state' => $state
                ];
                
            default:
                $state['current_task'] = 'finished';
        }
        
        // Log batch result
        $batchCount = isset($result['count']) ? $result['count'] : 0;
        $log->info("Task: {$task} | Offset: {$offset} | Batch: {$batchCount} | " . $message);
        if ($state['current_task'] === 'finished') {
            $log->info('=== Migration fully completed ===');
        }

        // AUTO-SAVE STATE
        $this->saveState($state);

        // Calculate Weighted Progress
        $allSteps = [
            'customers' => 4,
            'addresses' => 4,
            'categories' => 4,
            'tax_rules' => 2,
            'localization' => 2,
            'states' => 1,
            'attribute_groups' => 2,
            'attributes' => 3,
            'products' => 20,
            'product_download' => 2,
            'pack' => 2,
            'product_attributes' => 10,
            'customization_field' => 1,
            'specific_prices' => 3,
            'catalog_price_rule' => 2,
            'manufacturers' => 4,
            'suppliers' => 2,
            'product_supplier' => 2,
            'features' => 4,
            'feature_values' => 4,
            'feature_products' => 8,
            'tag' => 2,
            'attachments' => 3,
            'cms' => 3,
            'meta' => 1,
            'images' => 20,
            'employees' => 2,
            'contacts' => 1,
            'stores' => 1,
            'cart_rules' => 3,
            'messages' => 2,
            'carriers' => 3,
            'orders' => 4,
            'order_payment' => 3,
            'order_slip' => 2,
            'cart' => 3,
            'wishlist' => 2,
            'stock_mvt' => 3,
            'configuration' => 1
        ];

        // Only count enabled steps for progress calculation
        $totalWeight = 0;
        foreach ($allSteps as $stepName => $weight) {
            if ($this->isTaskEnabled($stepName, $scope)) {
                $totalWeight += $weight;
            }
        }
        if ($totalWeight === 0) $totalWeight = 1;

        $currentWeight = 0;
        foreach ($allSteps as $stepName => $weight) {
            if ($stepName === $task) {
                break;
            }
            if ($this->isTaskEnabled($stepName, $scope)) {
                $currentWeight += $weight;
            }
        }

        // Add small increment for offset to show movement within step
        if ($offset > 0 && isset($allSteps[$task])) {
            $currentWeight += ($allSteps[$task] * 0.5); 
        }

        $progress = round(($currentWeight / $totalWeight) * 100);
        if ($progress > 99) $progress = 99; // Reserve 100 for 'finished'
        if ($state['current_task'] === 'finished') $progress = 100;

        return [
            'success' => true,
            'message' => $message,
            'next_batch' => ($state['current_task'] !== 'finished'),
            'progress' => $progress, 
            'batch_count' => $result['count'] ?? 0,
            'state' => $state
        ];
    }
    
    /**
     * Save migration state to Configuration
     */
    public function saveState($state)
    {
        if ($state['current_task'] === 'finished') {
            \Configuration::updateValue('PRESTASHIFT_MIGRATION_STATE', '');
            // Global date is now deprecated in favor of granular updateSyncHistory()
        } else {
            \Configuration::updateValue('PRESTASHIFT_MIGRATION_STATE', json_encode($state));
        }
    }

    /**
     * Get saved state from Configuration
     */
    public function getSavedState()
    {
        $data = \Configuration::get('PRESTASHIFT_MIGRATION_STATE');
        if (empty($data)) {
            return null;
        }
        
        $state = json_decode($data, true);
        return is_array($state) ? $state : null;
    }
}
