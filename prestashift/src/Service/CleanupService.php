<?php
/**
 * PrestaShift Migration Module
 * 
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @version   1.0.0
 */
namespace PrestaShift\Service;

use Db;

class CleanupService
{
    /**
     * Truncate tables related to the migration scope to ensure ID preservation
     */
    public function cleanTargetShop($scope)
    {
        // Disable foreign key checks to allow truncation
        Db::getInstance()->execute('SET FOREIGN_KEY_CHECKS = 0;');

        $tablesToClean = [];

        if (!empty($scope['catalog'])) {
            $tablesToClean = array_merge($tablesToClean, [
                'product', 'product_lang', 'product_shop',
                'product_download', 'pack', 'product_supplier',
                'customization_field', 'customization_field_lang',
                // 'category' handled separately
                'category_product',
                'feature', 'feature_lang', 'feature_product', 'feature_value', 'feature_value_lang', 'feature_shop',
                'attribute', 'attribute_lang', 'attribute_group', 'attribute_group_lang', 'attribute_group_shop', 'attribute_shop',
                'product_attribute', 'product_attribute_combination', 'product_attribute_shop', 'product_attribute_image',
                'stock_available', 'image', 'image_lang', 'image_shop',
                'manufacturer', 'manufacturer_lang', 'manufacturer_shop',
                'supplier', 'supplier_lang', 'supplier_shop',
                'tag', 'product_tag',
                'attachment', 'attachment_lang', 'product_attachment'
            ]);
        }

        if (!empty($scope['tax_rules'])) {
            $tablesToClean = array_merge($tablesToClean, [
                'tax', 'tax_lang', 'tax_rule', 'tax_rules_group', 'tax_rules_group_shop'
            ]);
        }

        if (!empty($scope['specific_prices'])) {
            $tablesToClean = array_merge($tablesToClean, [
                'specific_price', 'catalog_price_rule', 'catalog_price_rule_shop'
            ]);
        }

        if (!empty($scope['cms'])) {
            $tablesToClean = array_merge($tablesToClean, [
                'cms', 'cms_lang', 'cms_shop',
                'meta', 'meta_lang'
            ]);
        }

        if (!empty($scope['customers'])) {
            $tablesToClean = array_merge($tablesToClean, [
                'customer', 'address', 'customer_group', 'group', 'group_lang'
            ]);
        }

        if (!empty($scope['orders'])) {
            $tablesToClean = array_merge($tablesToClean, [
                'orders', 'order_detail', 'order_history',
                'order_payment', 'order_invoice', 'order_invoice_payment', 'order_carrier',
                'order_slip', 'order_slip_detail',
                'cart', 'cart_product'
            ]);
        }

        if (!empty($scope['carriers'])) {
            $tablesToClean = array_merge($tablesToClean, [
                'carrier', 'carrier_lang', 'carrier_shop',
                'carrier_group', 'carrier_zone',
                'carrier_tax_rules_group_shop',
                'range_weight', 'range_price', 'delivery'
            ]);
        }

        if (!empty($scope['cart_rules'])) {
            $tablesToClean = array_merge($tablesToClean, [
                'cart_rule', 'cart_rule_lang', 'cart_rule_shop',
                'cart_rule_combination',
                'cart_rule_product_rule_group', 'cart_rule_product_rule', 'cart_rule_product_rule_value'
            ]);
        }

        if (!empty($scope['contacts'])) {
            $tablesToClean = array_merge($tablesToClean, [
                'contact', 'contact_lang', 'contact_shop',
                'store', 'store_lang', 'store_shop'
            ]);
        }

        foreach ($tablesToClean as $table) {
            $tableName = \_DB_PREFIX_ . $table;
            try {
                Db::getInstance()->execute("TRUNCATE TABLE `$tableName`");
            } catch (\Throwable $e) {
                // Table may not exist in this PS version — skip silently
            }
        }

        // SPECIAL CLEANUP FOR CATEGORIES (Preserve ID 1 & 2)
        if (!empty($scope['catalog'])) {
            $catTables = ['category', 'category_lang', 'category_shop', 'category_group'];
            foreach ($catTables as $table) {
                $tableName = \_DB_PREFIX_ . $table;
                try {
                    Db::getInstance()->execute("DELETE FROM `$tableName` WHERE id_category > 2");
                    if ($table === 'category') {
                         Db::getInstance()->execute("ALTER TABLE `$tableName` AUTO_INCREMENT = 3");
                    }
                } catch (\Throwable $e) {}
            }
        }

        // SPECIAL CLEANUP FOR CMS CATEGORIES (Preserve ID 1)
        if (!empty($scope['cms'])) {
            $cmsCatTables = ['cms_category', 'cms_category_lang', 'cms_category_shop'];
            foreach ($cmsCatTables as $table) {
                $tableName = \_DB_PREFIX_ . $table;
                try {
                    Db::getInstance()->execute("DELETE FROM `$tableName` WHERE id_cms_category > 1");
                } catch (\Throwable $e) {}
            }
        }

        Db::getInstance()->execute('SET FOREIGN_KEY_CHECKS = 1;');
        
        return true;
    }
}
