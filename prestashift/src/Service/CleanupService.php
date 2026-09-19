<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @attribution PrestaShift by Marcin Gajewski (marcingajewski.pl). Keep the Attribution Notice in prestashift.php — AFL-3.0 section 6.
 * @version   1.3.0
 */
namespace PrestaShift\Service;

use Db;

class CleanupService
{
    /**
     * Tables emptied for each selected scope, and the id-map entities whose
     * mappings become invalid with them. A scope only ever cleans its own data:
     * brands, suppliers and attachments have their own checkboxes, so cleaning
     * the catalog leaves them alone. Data that only makes sense attached to a
     * product (specific prices, reviews, accessories…) goes with the catalog,
     * otherwise it would stick to the new products that reuse the same ids.
     */
    private static $plan = [
        'catalog' => [
            'tables' => [
                'product', 'product_lang', 'product_shop',
                'product_download', 'pack', 'accessory', 'product_carrier', 'product_supplier',
                'customization_field', 'customization_field_lang',
                'category_product',
                'feature', 'feature_lang', 'feature_shop', 'feature_product', 'feature_value', 'feature_value_lang',
                'attribute', 'attribute_lang', 'attribute_shop',
                'attribute_group', 'attribute_group_lang', 'attribute_group_shop',
                'product_attribute', 'product_attribute_combination', 'product_attribute_shop', 'product_attribute_image',
                'stock_available', 'stock_mvt',
                'image', 'image_lang', 'image_shop',
                'tag', 'product_tag',
                'product_attachment',
                'specific_price', 'specific_price_priority',
                'product_comment', 'product_comment_grade', 'product_comment_usefulness', 'product_comment_report',
                'product_comment_criterion_product',
            ],
            'entities' => [
                'product', 'product_attribute', 'product_download', 'product_supplier', 'customization_field',
                'category', 'feature', 'feature_value', 'attribute', 'attribute_group', 'image', 'tag',
                'specific_price', 'stock_mvt', 'product_comment',
            ],
        ],
        'images' => [
            'tables' => ['image', 'image_lang', 'image_shop', 'product_attribute_image'],
            'entities' => ['image'],
        ],
        'manufacturers' => [
            'tables' => ['manufacturer', 'manufacturer_lang', 'manufacturer_shop'],
            'entities' => ['manufacturer'],
        ],
        'suppliers' => [
            'tables' => ['supplier', 'supplier_lang', 'supplier_shop', 'product_supplier'],
            'entities' => ['supplier', 'product_supplier'],
        ],
        'attachments' => [
            'tables' => ['attachment', 'attachment_lang', 'attachment_shop', 'product_attachment'],
            'entities' => ['attachment'],
        ],
        'tax_rules' => [
            'tables' => ['tax', 'tax_lang', 'tax_rule', 'tax_rules_group', 'tax_rules_group_shop'],
            'entities' => ['tax', 'tax_rule', 'tax_rules_group'],
        ],
        'specific_prices' => [
            'tables' => [
                'specific_price', 'specific_price_priority',
                'specific_price_rule', 'specific_price_rule_condition_group', 'specific_price_rule_condition',
            ],
            'entities' => [
                'specific_price', 'specific_price_rule', 'specific_price_rule_condition_group', 'specific_price_rule_condition',
            ],
        ],
        'cms' => [
            // Meta pages are not emptied: the target needs a row for each of its
            // own pages. Migrated meta is matched by page name instead.
            'tables' => ['cms', 'cms_lang', 'cms_shop'],
            'entities' => ['cms', 'cms_category'],
        ],
        'customers' => [
            'tables' => ['customer', 'address', 'customer_group', 'group', 'group_lang', 'group_shop'],
            'entities' => ['customer', 'address', 'group'],
        ],
        'orders' => [
            'tables' => [
                'orders', 'order_detail', 'order_detail_tax', 'order_history',
                'order_payment', 'order_invoice', 'order_invoice_payment', 'order_invoice_tax', 'order_carrier',
                'order_cart_rule', 'order_slip', 'order_slip_detail', 'order_return', 'order_return_detail',
                'message', 'cart', 'cart_product', 'cart_cart_rule', 'customization', 'customized_data',
            ],
            'entities' => [
                'order', 'order_detail', 'order_history', 'order_payment', 'order_invoice', 'order_carrier',
                'order_cart_rule', 'order_slip', 'order_return', 'message', 'cart', 'customization',
            ],
        ],
        'messages' => [
            'tables' => ['customer_thread', 'customer_message'],
            'entities' => ['customer_thread', 'customer_message'],
        ],
        'cart_rules' => [
            'tables' => [
                'cart_rule', 'cart_rule_lang', 'cart_rule_shop', 'cart_rule_country', 'cart_rule_group', 'cart_rule_carrier',
                'cart_rule_combination', 'cart_rule_product_rule_group', 'cart_rule_product_rule', 'cart_rule_product_rule_value',
            ],
            'entities' => ['cart_rule', 'cart_rule_product_rule_group', 'cart_rule_product_rule'],
        ],
        'contacts' => [
            'tables' => ['contact', 'contact_lang', 'contact_shop', 'store', 'store_lang', 'store_shop'],
            'entities' => ['contact', 'store'],
        ],
        'reviews' => [
            'tables' => [
                'product_comment', 'product_comment_grade', 'product_comment_criterion', 'product_comment_criterion_lang',
                'product_comment_criterion_product', 'product_comment_criterion_category',
                'product_comment_usefulness', 'product_comment_report',
            ],
            'entities' => ['product_comment', 'product_comment_criterion'],
        ],
    ];

    /**
     * Empties the target data of the selected scopes.
     */
    public function cleanTargetShop($scope)
    {
        Db::getInstance()->execute('SET FOREIGN_KEY_CHECKS = 0;');

        $tables = [];
        $entities = [];
        foreach (self::$plan as $scopeKey => $plan) {
            if (!empty($scope[$scopeKey])) {
                $tables = array_merge($tables, $plan['tables']);
                $entities = array_merge($entities, $plan['entities']);
            }
        }

        foreach (array_unique($tables) as $table) {
            if (!SchemaHelper::hasTable($table)) {
                continue; // absent in this PrestaShop version / module not installed
            }
            try {
                Db::getInstance()->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . bqSQL($table) . '`');
            } catch (\Throwable $e) {
                LogService::getInstance()->warning('Cleanup: could not empty ' . $table . ': ' . $e->getMessage());
            }
        }

        // Categories: keep the target's Root and Home
        if (!empty($scope['catalog'])) {
            $keep = array_values(array_unique(array_filter([
                (int) \Configuration::get('PS_ROOT_CATEGORY'), (int) \Configuration::get('PS_HOME_CATEGORY'), 1, 2,
            ])));
            $keepList = implode(',', $keep);
            foreach (['category_lang', 'category_shop', 'category_group', 'category'] as $table) {
                try {
                    Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . $table . "` WHERE id_category NOT IN ($keepList)");
                } catch (\Throwable $e) {
                }
            }
            try {
                Db::getInstance()->execute('ALTER TABLE `' . _DB_PREFIX_ . 'category` AUTO_INCREMENT = ' . (max($keep) + 1));
            } catch (\Throwable $e) {
            }
        }

        // CMS categories: keep the root
        if (!empty($scope['cms'])) {
            foreach (['cms_category_lang', 'cms_category_shop', 'cms_category'] as $table) {
                try {
                    Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . $table . '` WHERE id_cms_category > 1');
                } catch (\Throwable $e) {
                }
            }
        }

        // Carriers: remove only carriers not owned by a module. A carrier
        // created by a shipping module installed in this shop (InPost, DPD…)
        // belongs to that module — deleting it breaks the module.
        if (!empty($scope['carriers'])) {
            $this->cleanCarriers();
            $entities = array_merge($entities, ['carrier', 'range_weight', 'range_price', 'delivery']);
        }

        Db::getInstance()->execute('SET FOREIGN_KEY_CHECKS = 1;');

        IdMapper::forget(array_values(array_unique($entities)));

        return true;
    }

    private function cleanCarriers()
    {
        $ids = [];
        $rows = Db::getInstance()->executeS('SELECT `id_carrier` FROM `' . _DB_PREFIX_ . 'carrier`
            WHERE `is_module` = 0 AND (`external_module_name` IS NULL OR `external_module_name` = \'\')');
        foreach ((array) $rows as $row) {
            $ids[] = (int) $row['id_carrier'];
        }
        if (empty($ids)) {
            return;
        }
        $in = implode(',', $ids);

        foreach (['carrier_lang', 'carrier_shop', 'carrier_group', 'carrier_zone', 'carrier_tax_rules_group_shop',
            'range_weight', 'range_price', 'delivery', 'cart_rule_carrier'] as $table) {
            if (!SchemaHelper::hasTable($table)) {
                continue;
            }
            try {
                Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . $table . "` WHERE `id_carrier` IN ($in)");
            } catch (\Throwable $e) {
            }
        }
        if (SchemaHelper::hasTable('product_carrier')) {
            Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . "product_carrier` WHERE `id_carrier_reference` IN ($in)");
        }
        Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . "carrier` WHERE `id_carrier` IN ($in)");
    }
}
