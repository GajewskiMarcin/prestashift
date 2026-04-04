<?php
/**
 * PrestaShift Migration Module
 * 
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @version   1.0.0
 */
namespace PrestaShift\Service\Steps;

use Db;
use PDO;

class ProductMigrationStep
{
    private $db_connection;
    private $prefix;

    public function __construct($db_connection, $prefix)
    {
        $this->db_connection = $db_connection;
        $this->prefix = $prefix;
    }

    public function process($offset, $limit, $dateFilter = null)
    {
        $products = $this->getProductsFromSource($offset, $limit, $dateFilter);

        if (empty($products)) {
            return ['count' => 0, 'finished' => true];
        }

        foreach ($products as $product) {
            $this->importProduct($product);
        }

        return ['count' => count($products), 'finished' => false];
    }

    private function getProductsFromSource($offset, $limit, $dateFilter = null)
    {
        $where = "";
        if ($dateFilter) {
            $where = " WHERE `date_add` > '{$dateFilter}' OR `date_upd` > '{$dateFilter}' ";
        }
        $sql = "SELECT * FROM `{$this->prefix}product` {$where} ORDER BY `id_product` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importProduct($data)
    {
        $id = (int)$data['id_product'];
        
        // Prepare data array with ALL possible columns from source (or defaults)
        // SchemaHelper will filter out what doesn't exist in target.
        
        $productData = [
            'id_product' => $id,
            'id_supplier' => $data['id_supplier'],
            'id_manufacturer' => $data['id_manufacturer'],
            'id_category_default' => $data['id_category_default'],
            'id_shop_default' => $data['id_shop_default'],
            'id_tax_rules_group' => $data['id_tax_rules_group'],
            'on_sale' => $data['on_sale'],
            'online_only' => $data['online_only'],
            'ean13' => $data['ean13'],
            'isbn' => isset($data['isbn']) ? $data['isbn'] : null,
            'upc' => $data['upc'],
            'mpn' => isset($data['mpn']) ? $data['mpn'] : null,
            'ecotax' => $data['ecotax'],
            'quantity' => isset($data['quantity']) ? $data['quantity'] : 0, // Deprecated in 9, will be stripped
            'minimal_quantity' => $data['minimal_quantity'],
            'low_stock_threshold' => isset($data['low_stock_threshold']) ? $data['low_stock_threshold'] : null,
            'low_stock_alert' => isset($data['low_stock_alert']) ? $data['low_stock_alert'] : 0,
            'price' => $data['price'],
            'wholesale_price' => $data['wholesale_price'],
            'unity' => $data['unity'],
            'unit_price_ratio' => $data['unit_price_ratio'],
            'additional_shipping_cost' => $data['additional_shipping_cost'],
            'reference' => $data['reference'],
            'supplier_reference' => $data['supplier_reference'],
            'location' => isset($data['location']) ? $data['location'] : '', // Deprecated
            'width' => $data['width'],
            'height' => $data['height'],
            'depth' => $data['depth'],
            'weight' => $data['weight'],
            'out_of_stock' => $data['out_of_stock'],
            'additional_delivery_times' => isset($data['additional_delivery_times']) ? $data['additional_delivery_times'] : 1,
            'quantity_discount' => isset($data['quantity_discount']) ? $data['quantity_discount'] : 0,
            'customizable' => isset($data['customizable']) ? $data['customizable'] : 0,
            'uploadable_files' => isset($data['uploadable_files']) ? $data['uploadable_files'] : 0,
            'text_fields' => isset($data['text_fields']) ? $data['text_fields'] : 0,
            'active' => $data['active'],
            'redirect_type' => $this->transformRedirectType($data['redirect_type']),
            'id_product_redirected' => isset($data['id_product_redirected']) ? $data['id_product_redirected'] : 0,
            'id_type_redirected' => isset($data['id_type_redirected']) ? $data['id_type_redirected'] : (isset($data['id_product_redirected']) ? $data['id_product_redirected'] : 0),
            'available_for_order' => $data['available_for_order'],
            'available_date' => $data['available_date'],
            'show_condition' => $data['show_condition'],
            'condition' => $data['condition'],
            'show_price' => $data['show_price'],
            'indexed' => 1,
            'visibility' => $data['visibility'],
            'is_virtual' => $data['is_virtual'],
            'cache_is_pack' => isset($data['cache_is_pack']) ? $data['cache_is_pack'] : 0,
            'cache_has_attachments' => isset($data['cache_has_attachments']) ? $data['cache_has_attachments'] : 0,
            'cache_default_attribute' => isset($data['cache_default_attribute']) ? $data['cache_default_attribute'] : 0,
            'date_add' => $data['date_add'],
            'date_upd' => $data['date_upd'],
            'advanced_stock_management' => isset($data['advanced_stock_management']) ? $data['advanced_stock_management'] : 0,
            'pack_stock_type' => isset($data['pack_stock_type']) ? $data['pack_stock_type'] : 3,
            'state' => isset($data['state']) ? $data['state'] : 1,
            'product_type' => isset($data['product_type']) ? $data['product_type'] : 'standard', // PS 1.7+
        ];

        // Build INSERT query
        // 3rd arg true = perform pSQL escaping on values.
        $sql = \PrestaShift\Service\SchemaHelper::buildInsertQuery('product', $productData, true);
        
        if ($sql) {
            // Append safe ON DUPLICATE KEY UPDATE for standard fields
            $sql .= " ON DUPLICATE KEY UPDATE date_upd = VALUES(date_upd), price = VALUES(price), id_tax_rules_group = VALUES(id_tax_rules_group)";
            try {
                 Db::getInstance()->execute($sql);
            } catch (\Exception $e) {
                // Log or ignore
            }
        }

        $this->importProductLang($id);
        $this->importProductShop($id); 
        $this->importStock($id); 
        $this->importCategoryLink($id, (int)$data['id_category_default']); // removed replace call, cast serves same purpose safely
    }

    private function importProductLang($id_product)
    {
        $sql = "SELECT * FROM `{$this->prefix}product_lang` WHERE id_product = $id_product";
        $stmt = $this->db_connection->query($sql);
        $langs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($langs as $lang) {
            $id_lang = (int)$lang['id_lang']; 
            
            Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "product_lang` WHERE id_product = $id_product AND id_lang = $id_lang");
            
            $langData = [
                'id_product' => $id_product,
                'id_shop' => \PrestaShift\Service\SchemaHelper::getTargetShopId(),
                'id_lang' => $id_lang,
                'description' => $lang['description'],
                'description_short' => $lang['description_short'],
                'link_rewrite' => $lang['link_rewrite'],
                'meta_description' => $lang['meta_description'],
                'meta_keywords' => isset($lang['meta_keywords']) ? $lang['meta_keywords'] : null,
                'meta_title' => $lang['meta_title'],
                'name' => $lang['name'],
                'available_now' => $lang['available_now'],
                'available_later' => $lang['available_later'],
                'delivery_in_stock' => isset($lang['delivery_in_stock']) ? $lang['delivery_in_stock'] : null,
                'delivery_out_stock' => isset($lang['delivery_out_stock']) ? $lang['delivery_out_stock'] : null,
            ];

            $sql = \PrestaShift\Service\SchemaHelper::buildInsertQuery('product_lang', $langData, true);
            if ($sql) {
                Db::getInstance()->execute($sql);
            }
        }
    }
    
    private function importProductShop($id_product) {
         // Fetch the product we just inserted to get the correct values
         // Or rely on the fact that we have $id_product and we know the values from previous step?
         // We don't have the $product data array passed here easily unless we change method signature.
         // Better: SELECT from ps_product local table to be 100% sure what we just saved.
         
         $localProduct = Db::getInstance()->getRow("SELECT * FROM `" . \_DB_PREFIX_ . "product` WHERE id_product = $id_product");
         
         if (!$localProduct) return;
         
         $shopData = [
             'id_product' => (int)$localProduct['id_product'],
             'id_shop' => \PrestaShift\Service\SchemaHelper::getTargetShopId(), // Default shop
             'id_category_default' => (int)$localProduct['id_category_default'],
             'id_tax_rules_group' => (int)$localProduct['id_tax_rules_group'],
             'on_sale' => (int)$localProduct['on_sale'],
             'online_only' => (int)$localProduct['online_only'],
             'ecotax' => (float)$localProduct['ecotax'],
             'minimal_quantity' => (int)$localProduct['minimal_quantity'],
             'low_stock_threshold' => $localProduct['low_stock_threshold'],
             'low_stock_alert' => $localProduct['low_stock_alert'],
             'price' => (float)$localProduct['price'],
             'wholesale_price' => (float)$localProduct['wholesale_price'],
             'unity' => $localProduct['unity'],
             'unit_price_ratio' => (float)$localProduct['unit_price_ratio'],
             'additional_shipping_cost' => (float)$localProduct['additional_shipping_cost'],
             'customizable' => (int)$localProduct['customizable'],
             'uploadable_files' => (int)$localProduct['uploadable_files'],
             'text_fields' => (int)$localProduct['text_fields'],
             'active' => (int)$localProduct['active'],
             'redirect_type' => $localProduct['redirect_type'],
             'id_type_redirected' => (int)$localProduct['id_type_redirected'],
             'available_for_order' => (int)$localProduct['available_for_order'],
             'available_date' => $localProduct['available_date'],
             'show_condition' => (int)$localProduct['show_condition'],
             'condition' => $localProduct['condition'],
             'show_price' => (int)$localProduct['show_price'],
             'indexed' => 1,
             'visibility' => $localProduct['visibility'],
             'cache_default_attribute' => (int)$localProduct['cache_default_attribute'],
             'advanced_stock_management' => (int)$localProduct['advanced_stock_management'],
             'date_add' => $localProduct['date_add'],
             'date_upd' => $localProduct['date_upd'],
             'pack_stock_type' => isset($localProduct['pack_stock_type']) ? $localProduct['pack_stock_type'] : 3,
         ];

         // Use SchemaHelper to safe insert into product_shop
         // This ensures we populate price, tax, etc.
         
         $sql = \PrestaShift\Service\SchemaHelper::buildInsertQuery('product_shop', $shopData, true);
         if ($sql) {
              $sql .= " ON DUPLICATE KEY UPDATE price = VALUES(price), active = VALUES(active), date_upd = VALUES(date_upd), id_tax_rules_group = VALUES(id_tax_rules_group)";
              Db::getInstance()->execute($sql);
         }
    }

    private function importStock($id_product) {
        // Source quantity and out_of_stock setting from ps_stock_available
        $sql = "SELECT quantity, out_of_stock FROM `{$this->prefix}stock_available` WHERE id_product = $id_product AND id_product_attribute = 0";
        $row = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $qty = isset($row[0]['quantity']) ? (int)$row[0]['quantity'] : 0;
        $outOfStock = isset($row[0]['out_of_stock']) ? (int)$row[0]['out_of_stock'] : 2;

        // Update quantity
        \StockAvailable::setQuantity($id_product, 0, $qty);

        // Update out_of_stock setting (0=deny, 1=allow, 2=use global)
        \StockAvailable::setProductOutOfStock($id_product, $outOfStock);
    }
    
    private function importCategoryLink($id_product, $id_cat_default) {
        // Fetch all categories for this product
        $sql = "SELECT id_category, position FROM `{$this->prefix}category_product` WHERE id_product = $id_product";
        $stmt = $this->db_connection->query($sql);
        $cats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "category_product` WHERE id_product = $id_product");
        
        foreach ($cats as $cat) {
            $id_category = (int)$cat['id_category'];
            $pos = (int)$cat['position'];
            Db::getInstance()->execute("INSERT INTO `" . \_DB_PREFIX_ . "category_product` (id_category, id_product, position) VALUES ($id_category, $id_product, $pos)");
        }
    }

    private function transformRedirectType($type)
    {
        $map = ['301' => '301-product', '302' => '302-product'];
        return isset($map[$type]) ? $map[$type] : $type;
    }
}
