<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @version   1.3.0
 */
namespace PrestaShift\Service;

use Db;
use PDO;

/**
 * Source → target ID translation, persisted across migration runs.
 *
 * Every record written to the target gets its id from here, and every
 * reference to another record is translated through here. Nothing writes a
 * source id into the target directly.
 *
 * Numbering: the first time an entity is migrated from a given source, its
 * offset is fixed at the target's current MAX id and stored. A new record gets
 * target = source + offset; when that id is already used, the first free id
 * above everything known is taken instead. After "Clean target data" the tables
 * are empty, the offset is 0 and ids come out 1:1 — the same code path serves
 * both modes.
 *
 * Every assignment is stored (prestashift_id_map), so a later run — Delta, a
 * migration done in parts, a repeated run — finds the same target id instead of
 * computing a new one. Ids are reserved on first reference, so the order of the
 * migration steps does not matter (a product may be written before its brand).
 *
 * Records that already exist in the target are matched instead of copied where
 * a natural key exists (customer e-mail, employee e-mail, ISO codes, group and
 * category roots, meta page, tax names). Such "linked" records are never
 * overwritten.
 */
class IdMapper
{
    const MAP_TABLE = 'prestashift_id_map';
    const OFFSET_TABLE = 'prestashift_id_offset';

    /**
     * Entities that own a numeric primary key. entity => [table, pk column].
     * Default: table = entity, pk = id_<entity>.
     */
    private static $entities = [
        'category' => [], 'manufacturer' => [], 'supplier' => [],
        'attribute_group' => [], 'attribute' => [], 'feature' => [], 'feature_value' => [],
        'product' => [], 'product_attribute' => [], 'image' => [], 'tag' => [],
        'product_supplier' => [], 'product_download' => [], 'attachment' => [],
        'customization_field' => [], 'customization' => [],
        'specific_price' => [], 'specific_price_rule' => [],
        'specific_price_rule_condition_group' => [], 'specific_price_rule_condition' => [],
        'tax' => [], 'tax_rules_group' => [], 'tax_rule' => [],
        'country' => [], 'state' => [], 'currency' => [],
        'group' => [], 'customer' => [], 'address' => [],
        'order' => ['orders', 'id_order'],
        'order_detail' => [], 'order_history' => [], 'order_carrier' => [],
        'order_invoice' => [], 'order_payment' => [], 'order_slip' => [],
        'order_cart_rule' => [], 'order_return' => [],
        'cart' => [], 'message' => [],
        'cart_rule' => [],
        'cart_rule_product_rule_group' => ['cart_rule_product_rule_group', 'id_product_rule_group'],
        'cart_rule_product_rule' => ['cart_rule_product_rule', 'id_product_rule'],
        'carrier' => [], 'range_weight' => [], 'range_price' => [], 'delivery' => [],
        'cms' => [], 'cms_category' => [], 'meta' => [],
        'contact' => [], 'store' => [],
        'customer_thread' => [], 'customer_message' => [],
        'employee' => [], 'profile' => [],
        'product_comment' => [], 'product_comment_criterion' => [],
        'stock_mvt' => [], 'wishlist' => [],
    ];

    /**
     * Entities written by the tasks of each UI scope. A reference to an entity
     * of a scope that is not being migrated is only resolved when it was mapped
     * by an earlier run; otherwise it falls back (0 or the target default)
     * instead of pointing at an id nothing will ever fill.
     */
    private static $scopeEntities = [
        'catalog' => ['category', 'attribute_group', 'attribute', 'feature', 'feature_value', 'product',
            'product_attribute', 'tag', 'customization_field', 'product_download', 'stock_mvt'],
        'images' => ['image'],
        'manufacturers' => ['manufacturer'],
        'suppliers' => ['supplier', 'product_supplier'],
        'attachments' => ['attachment'],
        'specific_prices' => ['specific_price', 'specific_price_rule', 'specific_price_rule_condition_group',
            'specific_price_rule_condition'],
        'tax_rules' => ['tax', 'tax_rules_group', 'tax_rule'],
        'localization' => ['country', 'state', 'currency'],
        'customers' => ['group', 'customer', 'address', 'wishlist'],
        'orders' => ['order', 'order_detail', 'order_history', 'order_carrier', 'order_invoice', 'order_payment',
            'order_slip', 'order_cart_rule', 'order_return', 'cart', 'customization', 'message'],
        'messages' => ['customer_thread', 'customer_message'],
        'cart_rules' => ['cart_rule', 'cart_rule_product_rule_group', 'cart_rule_product_rule'],
        'carriers' => ['carrier', 'range_weight', 'range_price', 'delivery'],
        'cms' => ['cms', 'cms_category', 'meta'],
        'contacts' => ['contact', 'store'],
        'employees' => ['employee', 'profile'],
        'reviews' => ['product_comment', 'product_comment_criterion'],
    ];

    /**
     * Id columns of each target table: column => entity. The table's own
     * primary key is listed too. Columns resolved by dictionaries use the
     * pseudo-entities lang, shop, shop_group, gender, risk, zone, order_state.
     */
    private static $columns = [
        'category' => ['id_category' => 'category', 'id_parent' => 'category', 'id_shop_default' => 'shop'],
        'category_lang' => ['id_category' => 'category', 'id_shop' => 'shop', 'id_lang' => 'lang'],
        'category_shop' => ['id_category' => 'category', 'id_shop' => 'shop'],
        'category_group' => ['id_category' => 'category', 'id_group' => 'group'],
        'category_product' => ['id_category' => 'category', 'id_product' => 'product'],

        'manufacturer' => ['id_manufacturer' => 'manufacturer'],
        'manufacturer_lang' => ['id_manufacturer' => 'manufacturer', 'id_lang' => 'lang'],
        'supplier' => ['id_supplier' => 'supplier'],
        'supplier_lang' => ['id_supplier' => 'supplier', 'id_lang' => 'lang'],

        'attribute_group' => ['id_attribute_group' => 'attribute_group'],
        'attribute_group_lang' => ['id_attribute_group' => 'attribute_group', 'id_lang' => 'lang'],
        'attribute' => ['id_attribute' => 'attribute', 'id_attribute_group' => 'attribute_group'],
        'attribute_lang' => ['id_attribute' => 'attribute', 'id_lang' => 'lang'],
        'feature' => ['id_feature' => 'feature'],
        'feature_lang' => ['id_feature' => 'feature', 'id_lang' => 'lang'],
        'feature_value' => ['id_feature_value' => 'feature_value', 'id_feature' => 'feature'],
        'feature_value_lang' => ['id_feature_value' => 'feature_value', 'id_lang' => 'lang'],
        'feature_product' => ['id_feature' => 'feature', 'id_product' => 'product', 'id_feature_value' => 'feature_value'],

        'product' => ['id_product' => 'product', 'id_supplier' => 'supplier', 'id_manufacturer' => 'manufacturer',
            'id_category_default' => 'category', 'id_shop_default' => 'shop', 'id_tax_rules_group' => 'tax_rules_group',
            'cache_default_attribute' => 'product_attribute'],
        'product_shop' => ['id_product' => 'product', 'id_shop' => 'shop', 'id_category_default' => 'category',
            'id_tax_rules_group' => 'tax_rules_group', 'cache_default_attribute' => 'product_attribute'],
        'product_lang' => ['id_product' => 'product', 'id_shop' => 'shop', 'id_lang' => 'lang'],
        'product_supplier' => ['id_product_supplier' => 'product_supplier', 'id_product' => 'product',
            'id_product_attribute' => 'product_attribute', 'id_supplier' => 'supplier', 'id_currency' => 'currency'],
        'product_download' => ['id_product_download' => 'product_download', 'id_product' => 'product'],
        'product_attachment' => ['id_product' => 'product', 'id_attachment' => 'attachment'],
        'attachment' => ['id_attachment' => 'attachment'],
        'attachment_lang' => ['id_attachment' => 'attachment', 'id_lang' => 'lang'],
        'accessory' => ['id_product_1' => 'product', 'id_product_2' => 'product'],
        'product_carrier' => ['id_product' => 'product', 'id_carrier_reference' => 'carrier', 'id_shop' => 'shop'],
        'tag' => ['id_tag' => 'tag', 'id_lang' => 'lang'],
        'product_tag' => ['id_product' => 'product', 'id_tag' => 'tag', 'id_lang' => 'lang'],

        'product_attribute' => ['id_product_attribute' => 'product_attribute', 'id_product' => 'product'],
        'product_attribute_shop' => ['id_product_attribute' => 'product_attribute', 'id_product' => 'product', 'id_shop' => 'shop'],
        'product_attribute_combination' => ['id_product_attribute' => 'product_attribute', 'id_attribute' => 'attribute'],
        'product_attribute_image' => ['id_product_attribute' => 'product_attribute', 'id_image' => 'image'],

        'image' => ['id_image' => 'image', 'id_product' => 'product'],
        'image_lang' => ['id_image' => 'image', 'id_lang' => 'lang'],
        'image_shop' => ['id_image' => 'image', 'id_product' => 'product', 'id_shop' => 'shop'],

        'pack' => ['id_product_pack' => 'product', 'id_product_item' => 'product', 'id_product_attribute_item' => 'product_attribute'],
        'customization_field' => ['id_customization_field' => 'customization_field', 'id_product' => 'product'],
        'customization_field_lang' => ['id_customization_field' => 'customization_field', 'id_lang' => 'lang', 'id_shop' => 'shop'],
        'customization' => ['id_customization' => 'customization', 'id_product_attribute' => 'product_attribute',
            'id_address_delivery' => 'address', 'id_cart' => 'cart', 'id_product' => 'product'],
        'customized_data' => ['id_customization' => 'customization'],

        'specific_price' => ['id_specific_price' => 'specific_price', 'id_specific_price_rule' => 'specific_price_rule',
            'id_cart' => 'cart', 'id_product' => 'product', 'id_shop' => 'shop', 'id_shop_group' => 'shop_group',
            'id_currency' => 'currency', 'id_country' => 'country', 'id_group' => 'group', 'id_customer' => 'customer',
            'id_product_attribute' => 'product_attribute'],
        'specific_price_priority' => ['id_shop' => 'shop', 'id_product' => 'product'],
        'specific_price_rule' => ['id_specific_price_rule' => 'specific_price_rule', 'id_shop' => 'shop',
            'id_currency' => 'currency', 'id_country' => 'country', 'id_group' => 'group'],
        'specific_price_rule_condition_group' => ['id_specific_price_rule_condition_group' => 'specific_price_rule_condition_group',
            'id_specific_price_rule' => 'specific_price_rule'],
        'specific_price_rule_condition' => ['id_specific_price_rule_condition' => 'specific_price_rule_condition',
            'id_specific_price_rule_condition_group' => 'specific_price_rule_condition_group'],

        'tax' => ['id_tax' => 'tax'],
        'tax_lang' => ['id_tax' => 'tax', 'id_lang' => 'lang'],
        'tax_rules_group' => ['id_tax_rules_group' => 'tax_rules_group'],
        'tax_rules_group_shop' => ['id_tax_rules_group' => 'tax_rules_group', 'id_shop' => 'shop'],
        'tax_rule' => ['id_tax_rule' => 'tax_rule', 'id_tax_rules_group' => 'tax_rules_group', 'id_country' => 'country',
            'id_state' => 'state', 'id_tax' => 'tax'],

        'country' => ['id_country' => 'country', 'id_zone' => 'zone', 'id_currency' => 'currency'],
        'country_lang' => ['id_country' => 'country', 'id_lang' => 'lang'],
        'state' => ['id_state' => 'state', 'id_country' => 'country', 'id_zone' => 'zone'],
        'currency' => ['id_currency' => 'currency'],
        'currency_lang' => ['id_currency' => 'currency', 'id_lang' => 'lang'],

        'group' => ['id_group' => 'group'],
        'group_lang' => ['id_group' => 'group', 'id_lang' => 'lang'],
        'customer' => ['id_customer' => 'customer', 'id_shop_group' => 'shop_group', 'id_shop' => 'shop',
            'id_gender' => 'gender', 'id_default_group' => 'group', 'id_lang' => 'lang', 'id_risk' => 'risk'],
        'customer_group' => ['id_customer' => 'customer', 'id_group' => 'group'],
        'address' => ['id_address' => 'address', 'id_country' => 'country', 'id_state' => 'state',
            'id_customer' => 'customer', 'id_manufacturer' => 'manufacturer', 'id_supplier' => 'supplier',
            'id_warehouse' => 'none'],

        'orders' => ['id_order' => 'order', 'id_shop_group' => 'shop_group', 'id_shop' => 'shop', 'id_carrier' => 'carrier',
            'id_lang' => 'lang', 'id_customer' => 'customer', 'id_cart' => 'cart', 'id_currency' => 'currency',
            'id_address_delivery' => 'address', 'id_address_invoice' => 'address', 'current_state' => 'order_state'],
        'order_detail' => ['id_order_detail' => 'order_detail', 'id_order' => 'order', 'id_order_invoice' => 'order_invoice',
            'id_warehouse' => 'none', 'id_shop' => 'shop', 'product_id' => 'product', 'product_attribute_id' => 'product_attribute',
            'id_customization' => 'customization', 'id_tax_rules_group' => 'tax_rules_group'],
        'order_detail_tax' => ['id_order_detail' => 'order_detail', 'id_tax' => 'tax'],
        'order_history' => ['id_order_history' => 'order_history', 'id_employee' => 'employee', 'id_order' => 'order',
            'id_order_state' => 'order_state'],
        'order_carrier' => ['id_order_carrier' => 'order_carrier', 'id_order' => 'order', 'id_carrier' => 'carrier',
            'id_order_invoice' => 'order_invoice'],
        'order_invoice' => ['id_order_invoice' => 'order_invoice', 'id_order' => 'order'],
        'order_invoice_tax' => ['id_order_invoice' => 'order_invoice', 'id_tax' => 'tax'],
        'order_payment' => ['id_order_payment' => 'order_payment', 'id_currency' => 'currency'],
        'order_invoice_payment' => ['id_order_invoice' => 'order_invoice', 'id_order_payment' => 'order_payment', 'id_order' => 'order'],
        'order_slip' => ['id_order_slip' => 'order_slip', 'id_customer' => 'customer', 'id_order' => 'order'],
        'order_slip_detail' => ['id_order_slip' => 'order_slip', 'id_order_detail' => 'order_detail'],
        'order_cart_rule' => ['id_order_cart_rule' => 'order_cart_rule', 'id_order' => 'order', 'id_cart_rule' => 'cart_rule',
            'id_order_invoice' => 'order_invoice'],
        'order_return' => ['id_order_return' => 'order_return', 'id_customer' => 'customer', 'id_order' => 'order'],
        'order_return_detail' => ['id_order_return' => 'order_return', 'id_order_detail' => 'order_detail',
            'id_customization' => 'customization'],
        'message' => ['id_message' => 'message', 'id_cart' => 'cart', 'id_customer' => 'customer',
            'id_employee' => 'employee', 'id_order' => 'order'],

        'cart' => ['id_cart' => 'cart', 'id_shop_group' => 'shop_group', 'id_shop' => 'shop', 'id_carrier' => 'carrier',
            'id_lang' => 'lang', 'id_address_delivery' => 'address', 'id_address_invoice' => 'address',
            'id_currency' => 'currency', 'id_customer' => 'customer', 'id_guest' => 'none'],
        'cart_product' => ['id_cart' => 'cart', 'id_product' => 'product', 'id_address_delivery' => 'address',
            'id_shop' => 'shop', 'id_product_attribute' => 'product_attribute', 'id_customization' => 'customization'],
        'cart_cart_rule' => ['id_cart' => 'cart', 'id_cart_rule' => 'cart_rule'],

        'cart_rule' => ['id_cart_rule' => 'cart_rule', 'id_customer' => 'customer', 'reduction_currency' => 'currency',
            'minimum_amount_currency' => 'currency', 'reduction_product' => 'product', 'gift_product' => 'product',
            'gift_product_attribute' => 'product_attribute'],
        'cart_rule_lang' => ['id_cart_rule' => 'cart_rule', 'id_lang' => 'lang'],
        'cart_rule_shop' => ['id_cart_rule' => 'cart_rule', 'id_shop' => 'shop'],
        'cart_rule_country' => ['id_cart_rule' => 'cart_rule', 'id_country' => 'country'],
        'cart_rule_group' => ['id_cart_rule' => 'cart_rule', 'id_group' => 'group'],
        'cart_rule_carrier' => ['id_cart_rule' => 'cart_rule', 'id_carrier' => 'carrier'],
        'cart_rule_combination' => ['id_cart_rule_1' => 'cart_rule', 'id_cart_rule_2' => 'cart_rule'],
        'cart_rule_product_rule_group' => ['id_product_rule_group' => 'cart_rule_product_rule_group', 'id_cart_rule' => 'cart_rule'],
        'cart_rule_product_rule' => ['id_product_rule' => 'cart_rule_product_rule', 'id_product_rule_group' => 'cart_rule_product_rule_group'],
        'cart_rule_product_rule_value' => ['id_product_rule' => 'cart_rule_product_rule'],

        'carrier' => ['id_carrier' => 'carrier', 'id_reference' => 'carrier', 'id_tax_rules_group' => 'tax_rules_group'],
        'carrier_lang' => ['id_carrier' => 'carrier', 'id_shop' => 'shop', 'id_lang' => 'lang'],
        'carrier_shop' => ['id_carrier' => 'carrier', 'id_shop' => 'shop'],
        'carrier_group' => ['id_carrier' => 'carrier', 'id_group' => 'group'],
        'carrier_zone' => ['id_carrier' => 'carrier', 'id_zone' => 'zone'],
        'carrier_tax_rules_group_shop' => ['id_carrier' => 'carrier', 'id_tax_rules_group' => 'tax_rules_group', 'id_shop' => 'shop'],
        'range_weight' => ['id_range_weight' => 'range_weight', 'id_carrier' => 'carrier'],
        'range_price' => ['id_range_price' => 'range_price', 'id_carrier' => 'carrier'],
        'delivery' => ['id_delivery' => 'delivery', 'id_shop' => 'shop', 'id_shop_group' => 'shop_group', 'id_carrier' => 'carrier',
            'id_range_price' => 'range_price', 'id_range_weight' => 'range_weight', 'id_zone' => 'zone'],

        'cms' => ['id_cms' => 'cms', 'id_cms_category' => 'cms_category'],
        'cms_lang' => ['id_cms' => 'cms', 'id_lang' => 'lang', 'id_shop' => 'shop'],
        'cms_shop' => ['id_cms' => 'cms', 'id_shop' => 'shop'],
        'cms_category' => ['id_cms_category' => 'cms_category', 'id_parent' => 'cms_category'],
        'cms_category_lang' => ['id_cms_category' => 'cms_category', 'id_lang' => 'lang', 'id_shop' => 'shop'],
        'cms_category_shop' => ['id_cms_category' => 'cms_category', 'id_shop' => 'shop'],
        'meta' => ['id_meta' => 'meta'],
        'meta_lang' => ['id_meta' => 'meta', 'id_shop' => 'shop', 'id_lang' => 'lang'],

        'contact' => ['id_contact' => 'contact'],
        'contact_lang' => ['id_contact' => 'contact', 'id_lang' => 'lang'],
        'contact_shop' => ['id_contact' => 'contact', 'id_shop' => 'shop'],
        'store' => ['id_store' => 'store', 'id_country' => 'country', 'id_state' => 'state'],
        'store_lang' => ['id_store' => 'store', 'id_lang' => 'lang'],
        'store_shop' => ['id_store' => 'store', 'id_shop' => 'shop'],

        'customer_thread' => ['id_customer_thread' => 'customer_thread', 'id_shop' => 'shop', 'id_lang' => 'lang',
            'id_contact' => 'contact', 'id_customer' => 'customer', 'id_order' => 'order', 'id_product' => 'product'],
        'customer_message' => ['id_customer_message' => 'customer_message', 'id_customer_thread' => 'customer_thread',
            'id_employee' => 'employee'],

        'employee' => ['id_employee' => 'employee', 'id_profile' => 'profile', 'id_lang' => 'lang'],
        'profile' => ['id_profile' => 'profile'],
        'profile_lang' => ['id_profile' => 'profile', 'id_lang' => 'lang'],

        'product_comment' => ['id_product_comment' => 'product_comment', 'id_product' => 'product',
            'id_customer' => 'customer', 'id_guest' => 'none'],
        'product_comment_grade' => ['id_product_comment' => 'product_comment', 'id_product_comment_criterion' => 'product_comment_criterion'],
        'product_comment_criterion' => ['id_product_comment_criterion' => 'product_comment_criterion'],
        'product_comment_criterion_lang' => ['id_product_comment_criterion' => 'product_comment_criterion', 'id_lang' => 'lang'],
        'product_comment_criterion_product' => ['id_product' => 'product', 'id_product_comment_criterion' => 'product_comment_criterion'],
        'product_comment_criterion_category' => ['id_product_comment_criterion' => 'product_comment_criterion', 'id_category' => 'category'],
        'product_comment_usefulness' => ['id_product_comment' => 'product_comment', 'id_customer' => 'customer'],
        'product_comment_report' => ['id_product_comment' => 'product_comment', 'id_customer' => 'customer'],

        'stock_mvt' => ['id_stock_mvt' => 'stock_mvt', 'id_order' => 'order', 'id_employee' => 'employee'],
        'wishlist' => ['id_wishlist' => 'wishlist', 'id_customer' => 'customer', 'id_shop' => 'shop', 'id_shop_group' => 'shop_group'],
        'wishlist_product' => ['id_wishlist' => 'wishlist', 'id_product' => 'product', 'id_product_attribute' => 'product_attribute'],
    ];

    /** Entities resolved by matching, never stored in the map. */
    private static $dictionaries = ['lang', 'shop', 'shop_group', 'gender', 'risk', 'zone', 'order_state', 'none'];

    // ---- per-request state (static state does not survive a request) ----

    /** @var string */
    private static $source = '';
    private static $conn;
    private static $prefix = 'ps_';
    private static $scope = [];
    private static $options = [];

    /** @var array entity => [source_id => target_id] */
    private static $cache = [];
    /** @var array entity => [source_id => true] */
    private static $linked = [];
    /** @var array entity => [source_id => fallback], references that resolve to nothing */
    private static $missing = [];
    /** @var array entity => offset */
    private static $offsets = [];
    /** @var array entity => highest target id allocated in this request */
    private static $allocated = [];
    /** @var array dictionary caches */
    private static $dict = [];
    /** @var array warnings already logged in this request */
    private static $warned = [];

    // ------------------------------------------------------------------
    // Setup
    // ------------------------------------------------------------------

    public static function ensureTables()
    {
        Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::MAP_TABLE . '` (
            `source` CHAR(32) NOT NULL,
            `entity` VARCHAR(48) NOT NULL,
            `source_id` INT UNSIGNED NOT NULL,
            `target_id` INT UNSIGNED NOT NULL,
            `linked` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`source`, `entity`, `source_id`),
            KEY `target` (`entity`, `target_id`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');

        Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::OFFSET_TABLE . '` (
            `source` CHAR(32) NOT NULL,
            `entity` VARCHAR(48) NOT NULL,
            `offset_value` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`source`, `entity`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
    }

    /**
     * Stable identity of the source shop: its install timestamp plus table
     * prefix. Survives a domain change (http→https, new host), differs between
     * two different shops, so mappings of several sources never mix.
     */
    public static function fingerprint($conn, $prefix, $sourceUrl)
    {
        $installed = '';
        try {
            $installed = (string) $conn->query(
                "SELECT MIN(`date_add`) FROM `{$prefix}configuration` WHERE `date_add` > '1971-01-01'"
            )->fetchColumn();
        } catch (\Exception $e) {
            $installed = '';
        }

        if ($installed === '') {
            $host = parse_url((string) $sourceUrl, PHP_URL_HOST);
            $installed = 'host:' . strtolower((string) $host);
        }

        return md5($prefix . '|' . $installed);
    }

    /**
     * Called at the start of every batch (static state does not survive a
     * request).
     */
    public static function begin($conn, $prefix, array $config)
    {
        self::$conn = $conn;
        self::$prefix = $prefix;
        self::$source = isset($config['id_source']) ? (string) $config['id_source'] : md5((string) $prefix);
        self::$scope = isset($config['scope']) && is_array($config['scope']) ? $config['scope'] : [];
        self::$options = isset($config['options']) && is_array($config['options']) ? $config['options'] : [];
        self::$cache = [];
        self::$linked = [];
        self::$missing = [];
        self::$offsets = [];
        self::$allocated = [];
        self::$dict = [];
        self::$warned = [];
    }

    /**
     * Forget mappings and offsets of entities whose target tables were just
     * emptied by "Clean target data" — for every source, since the rows they
     * pointed at are gone. The next allocation starts again from offset 0,
     * which yields 1:1 ids.
     */
    public static function forget(array $entities)
    {
        if (empty($entities)) {
            return;
        }
        $in = "'" . implode("','", array_map('pSQL', $entities)) . "'";
        Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . self::MAP_TABLE . '` WHERE `entity` IN (' . $in . ')');
        Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . self::OFFSET_TABLE . '` WHERE `entity` IN (' . $in . ')');
    }

    /**
     * Raises AUTO_INCREMENT of every table that received reservations in this
     * request above the reserved range, so records the shop creates itself can
     * never take a reserved id.
     */
    public static function finishBatch()
    {
        foreach (self::$allocated as $entity => $max) {
            list($table) = self::tableOf($entity);
            $needed = (int) $max + 1;
            try {
                // executeS, not getRow: getRow appends LIMIT 1, invalid for SHOW
                $rows = Db::getInstance()->executeS("SHOW TABLE STATUS LIKE '" . pSQL(_DB_PREFIX_ . $table) . "'", true, false);
                $current = isset($rows[0]['Auto_increment']) ? (int) $rows[0]['Auto_increment'] : 0;
                if ($current < $needed) {
                    Db::getInstance()->execute('ALTER TABLE `' . _DB_PREFIX_ . bqSQL($table) . '` AUTO_INCREMENT = ' . $needed);
                }
            } catch (\Throwable $e) {
                self::warnOnce('ai_' . $entity, 'Could not raise AUTO_INCREMENT of ' . $table . ': ' . $e->getMessage());
            }
        }
        self::$allocated = [];
    }

    // ------------------------------------------------------------------
    // Public resolution API
    // ------------------------------------------------------------------

    /**
     * Target id for a record the current step is about to write. Reserves a
     * new id when the record was never migrated.
     *
     * @param array $hints natural-key data of the source row (e.g. email) —
     *                     saves a source query when matching
     */
    public static function own($entity, $sourceId, array $hints = [])
    {
        return self::resolve($entity, $sourceId, true, $hints);
    }

    /**
     * Target id for a reference to another record. Reserves an id only when
     * that entity is part of this migration; otherwise returns the earlier
     * mapping or a safe fallback (0 / target default).
     */
    public static function ref($entity, $sourceId)
    {
        return self::resolve($entity, $sourceId, false, []);
    }

    /**
     * Mapped id or 0 — never reserves, never falls back.
     */
    public static function find($entity, $sourceId)
    {
        $sourceId = (int) $sourceId;
        if ($sourceId <= 0) {
            return 0;
        }
        if (in_array($entity, self::$dictionaries, true)) {
            return self::dictionary($entity, $sourceId);
        }
        $mapped = self::lookup($entity, $sourceId);

        return $mapped === null ? 0 : $mapped;
    }

    /**
     * True when the source record was matched to a record that already existed
     * in the target. Steps must not overwrite such records.
     */
    public static function isLinked($entity, $sourceId)
    {
        $sourceId = (int) $sourceId;
        if ($sourceId <= 0) {
            return false;
        }
        self::lookup($entity, $sourceId);

        return isset(self::$linked[$entity][$sourceId]);
    }

    /**
     * Records that a source record corresponds to an existing target record.
     */
    public static function link($entity, $sourceId, $targetId)
    {
        self::store($entity, (int) $sourceId, (int) $targetId, true);
    }

    /**
     * Loads the mappings of many source ids with one query and reserves ids for
     * the ones never migrated (for entities without natural-key matching).
     * Purely an optimisation of own()/ref() for a batch.
     */
    public static function prepare($entity, array $sourceIds)
    {
        if (in_array($entity, self::$dictionaries, true)) {
            return;
        }
        $ids = [];
        foreach ($sourceIds as $id) {
            $id = (int) $id;
            if ($id > 0 && !isset(self::$cache[$entity][$id])) {
                $ids[$id] = $id;
            }
        }
        if (empty($ids)) {
            return;
        }

        foreach (array_chunk(array_values($ids), 1000) as $chunk) {
            $rows = Db::getInstance()->executeS('SELECT `source_id`, `target_id`, `linked` FROM `' . _DB_PREFIX_ . self::MAP_TABLE . "`
                WHERE `source` = '" . pSQL(self::$source) . "' AND `entity` = '" . pSQL($entity) . "'
                AND `source_id` IN (" . implode(',', $chunk) . ')');
            foreach ((array) $rows as $row) {
                $sid = (int) $row['source_id'];
                self::$cache[$entity][$sid] = (int) $row['target_id'];
                if ((int) $row['linked']) {
                    self::$linked[$entity][$sid] = true;
                }
                unset($ids[$sid]);
            }
        }

        if (empty($ids) || self::hasMatcher($entity) || !isset(self::$entities[$entity]) || !self::inScope($entity)) {
            return;
        }

        // Bulk reservation: candidate = source + offset, keep the free ones.
        $offset = self::offset($entity);
        list($table, $pk) = self::tableOf($entity);
        $candidates = [];
        foreach ($ids as $sid) {
            $candidates[$sid + $offset] = $sid;
        }

        $taken = [];
        foreach (array_chunk(array_keys($candidates), 1000) as $chunk) {
            $rows = Db::getInstance()->executeS('SELECT `' . bqSQL($pk) . '` AS id FROM `' . _DB_PREFIX_ . bqSQL($table) . '`
                WHERE `' . bqSQL($pk) . '` IN (' . implode(',', $chunk) . ')');
            foreach ((array) $rows as $row) {
                $taken[(int) $row['id']] = true;
            }
            $rows = Db::getInstance()->executeS('SELECT `target_id` AS id FROM `' . _DB_PREFIX_ . self::MAP_TABLE . "`
                WHERE `entity` = '" . pSQL($entity) . "' AND `target_id` IN (" . implode(',', $chunk) . ')');
            foreach ((array) $rows as $row) {
                $taken[(int) $row['id']] = true;
            }
        }

        $values = [];
        foreach ($candidates as $tid => $sid) {
            if (isset($taken[$tid])) {
                continue; // allocated one by one below
            }
            $values[] = "('" . pSQL(self::$source) . "', '" . pSQL($entity) . "', $sid, $tid, 0)";
            self::$cache[$entity][$sid] = $tid;
            self::noteAllocated($entity, $tid);
            unset($ids[$sid]);
        }
        foreach (array_chunk($values, 500) as $chunk) {
            Db::getInstance()->execute('INSERT IGNORE INTO `' . _DB_PREFIX_ . self::MAP_TABLE . '`
                (`source`, `entity`, `source_id`, `target_id`, `linked`) VALUES ' . implode(',', $chunk));
        }

        foreach ($ids as $sid) {
            self::resolve($entity, $sid, false, []);
        }
    }

    /**
     * Translates every known id column of a row (own primary key via own(),
     * everything else via ref()). Language columns are left alone — *_lang rows
     * go through LanguageMapper::expand(). Shop columns are forced to the target
     * shop.
     */
    public static function row($table, array $row)
    {
        if (!isset(self::$columns[$table])) {
            return $row;
        }

        // *_lang rows already carry target language ids (LanguageMapper::expand)
        $langDone = substr($table, -5) === '_lang';

        foreach (self::$columns[$table] as $col => $entity) {
            if (!array_key_exists($col, $row) || $row[$col] === null) {
                continue;
            }
            if ($entity === 'lang' && $langDone) {
                continue;
            }
            if (self::isOwnKey($table, $col, $entity)) {
                $row[$col] = self::own($entity, $row[$col]);
            } else {
                $row[$col] = self::ref($entity, $row[$col]);
            }
        }

        return $row;
    }

    /** True when $col is the table's own primary key (not a reference). */
    private static function isOwnKey($table, $col, $entity)
    {
        if (!isset(self::$entities[$entity])) {
            return false;
        }
        list($entityTable, $pk) = self::tableOf($entity);

        return $entityTable === $table && $pk === $col;
    }

    /**
     * Human-readable map summary for the log.
     */
    public static function describe()
    {
        $rows = Db::getInstance()->executeS('SELECT `entity`, COUNT(*) AS n, SUM(`linked`) AS l FROM `'
            . _DB_PREFIX_ . self::MAP_TABLE . "` WHERE `source` = '" . pSQL(self::$source) . "' GROUP BY `entity`");
        $parts = [];
        foreach ((array) $rows as $r) {
            $parts[] = $r['entity'] . '=' . $r['n'] . ((int) $r['l'] ? ' (linked ' . (int) $r['l'] . ')' : '');
        }

        return 'id map: ' . implode(', ', $parts);
    }

    public static function inScope($entity)
    {
        if (empty(self::$scope)) {
            return true;
        }
        foreach (self::$scopeEntities as $scopeKey => $entities) {
            if (!empty(self::$scope[$scopeKey]) && in_array($entity, $entities, true)) {
                return true;
            }
        }

        return false;
    }

    public static function tableOf($entity)
    {
        $def = isset(self::$entities[$entity]) ? self::$entities[$entity] : [];
        $table = isset($def[0]) ? $def[0] : $entity;
        $pk = isset($def[1]) ? $def[1] : 'id_' . $entity;

        return [$table, $pk];
    }

    public static function sourceKey()
    {
        return self::$source;
    }

    /** Migration option (options[...] of the run) */
    public static function option($name)
    {
        return isset(self::$options[$name]) ? self::$options[$name] : null;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private static function resolve($entity, $sourceId, $own, array $hints)
    {
        $sourceId = (int) $sourceId;
        if ($sourceId <= 0) {
            return $sourceId; // 0 = none; negatives are special values (e.g. cart rule -1/-2)
        }

        if (in_array($entity, self::$dictionaries, true)) {
            return self::dictionary($entity, $sourceId);
        }

        if (!isset(self::$entities[$entity])) {
            return $sourceId; // unknown entity — should never happen; keep behaviour visible
        }

        if (!$own && isset(self::$missing[$entity][$sourceId])) {
            return self::$missing[$entity][$sourceId];
        }

        $mapped = self::lookup($entity, $sourceId);
        if ($mapped !== null) {
            return $mapped;
        }

        $matched = self::matchExisting($entity, $sourceId, $hints);
        if ($matched > 0) {
            self::store($entity, $sourceId, $matched, true);

            return $matched;
        }

        if ($own || self::inScope($entity)) {
            return self::allocate($entity, $sourceId);
        }

        // Remember the miss: loops over thousands of rows would otherwise query
        // the map and the source again for every row
        return self::$missing[$entity][$sourceId] = self::fallback($entity);
    }

    private static function lookup($entity, $sourceId)
    {
        if (isset(self::$cache[$entity]) && array_key_exists($sourceId, self::$cache[$entity])) {
            return self::$cache[$entity][$sourceId];
        }

        $row = Db::getInstance()->getRow('SELECT `target_id`, `linked` FROM `' . _DB_PREFIX_ . self::MAP_TABLE . "`
            WHERE `source` = '" . pSQL(self::$source) . "' AND `entity` = '" . pSQL($entity) . "' AND `source_id` = " . (int) $sourceId, false);

        if (!$row) {
            return null;
        }

        self::$cache[$entity][$sourceId] = (int) $row['target_id'];
        if ((int) $row['linked']) {
            self::$linked[$entity][$sourceId] = true;
        }

        return (int) $row['target_id'];
    }

    private static function store($entity, $sourceId, $targetId, $linked)
    {
        Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . self::MAP_TABLE . "`
            (`source`, `entity`, `source_id`, `target_id`, `linked`)
            VALUES ('" . pSQL(self::$source) . "', '" . pSQL($entity) . "', " . (int) $sourceId . ', ' . (int) $targetId . ', ' . ($linked ? 1 : 0) . ')
            ON DUPLICATE KEY UPDATE `target_id` = VALUES(`target_id`), `linked` = VALUES(`linked`)');

        self::$cache[$entity][$sourceId] = (int) $targetId;
        if ($linked) {
            self::$linked[$entity][$sourceId] = true;
        } else {
            unset(self::$linked[$entity][$sourceId]);
        }
    }

    private static function allocate($entity, $sourceId)
    {
        $candidate = $sourceId + self::offset($entity);
        if (self::isTaken($entity, $candidate)) {
            $candidate = self::nextFree($entity);
        }

        self::store($entity, $sourceId, $candidate, false);
        self::noteAllocated($entity, $candidate);

        return $candidate;
    }

    private static function noteAllocated($entity, $targetId)
    {
        if (!isset(self::$allocated[$entity]) || self::$allocated[$entity] < $targetId) {
            self::$allocated[$entity] = $targetId;
        }
    }

    private static function isTaken($entity, $targetId)
    {
        list($table, $pk) = self::tableOf($entity);

        $inTable = Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . bqSQL($table) . '`
            WHERE `' . bqSQL($pk) . '` = ' . (int) $targetId, false);
        if ($inTable) {
            return true;
        }

        return (bool) Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . self::MAP_TABLE . "`
            WHERE `entity` = '" . pSQL($entity) . "' AND `target_id` = " . (int) $targetId, false);
    }

    private static function nextFree($entity)
    {
        list($table, $pk) = self::tableOf($entity);

        $max = (int) Db::getInstance()->getValue('SELECT MAX(`' . bqSQL($pk) . '`) FROM `' . _DB_PREFIX_ . bqSQL($table) . '`', false);
        $maxMap = (int) Db::getInstance()->getValue('SELECT MAX(`target_id`) FROM `' . _DB_PREFIX_ . self::MAP_TABLE . "`
            WHERE `entity` = '" . pSQL($entity) . "'", false);

        $candidate = max($max, $maxMap, isset(self::$allocated[$entity]) ? self::$allocated[$entity] : 0) + 1;
        while (self::isTaken($entity, $candidate)) {
            ++$candidate;
        }

        return $candidate;
    }

    /**
     * Offset of an entity for the current source — fixed at the first
     * migration of that entity, then reused forever (until "Clean target
     * data" forgets it).
     */
    private static function offset($entity)
    {
        if (isset(self::$offsets[$entity])) {
            return self::$offsets[$entity];
        }

        $stored = Db::getInstance()->getValue('SELECT `offset_value` FROM `' . _DB_PREFIX_ . self::OFFSET_TABLE . "`
            WHERE `source` = '" . pSQL(self::$source) . "' AND `entity` = '" . pSQL($entity) . "'", false);

        if ($stored !== false && $stored !== null) {
            return self::$offsets[$entity] = (int) $stored;
        }

        list($table, $pk) = self::tableOf($entity);
        $exclude = self::systemTargetIds($entity);
        $where = empty($exclude) ? '' : ' WHERE `' . bqSQL($pk) . '` NOT IN (' . implode(',', $exclude) . ')';

        $offset = 0;
        try {
            $offset = (int) Db::getInstance()->getValue('SELECT MAX(`' . bqSQL($pk) . '`) FROM `' . _DB_PREFIX_ . bqSQL($table) . '`' . $where, false);
        } catch (\Throwable $e) {
            $offset = 0;
        }

        Db::getInstance()->execute('INSERT IGNORE INTO `' . _DB_PREFIX_ . self::OFFSET_TABLE . "` (`source`, `entity`, `offset_value`)
            VALUES ('" . pSQL(self::$source) . "', '" . pSQL($entity) . "', " . max(0, $offset) . ')');

        return self::$offsets[$entity] = max(0, $offset);
    }

    /**
     * Target records that always exist and are matched rather than numbered
     * (so a freshly cleaned target still gets offset 0 and 1:1 ids).
     */
    private static function systemTargetIds($entity)
    {
        switch ($entity) {
            case 'category':
                return array_values(array_unique(array_filter([
                    (int) \Configuration::get('PS_ROOT_CATEGORY'), (int) \Configuration::get('PS_HOME_CATEGORY'), 1, 2,
                ])));
            case 'cms_category':
                return [1];
            case 'group':
                return array_values(array_unique(array_filter([
                    (int) \Configuration::get('PS_UNIDENTIFIED_GROUP'), (int) \Configuration::get('PS_GUEST_GROUP'),
                    (int) \Configuration::get('PS_CUSTOMER_GROUP'),
                ])));
            case 'profile':
                return [(int) _PS_ADMIN_PROFILE_];
        }

        return [];
    }

    /**
     * A matched system record counts only while it really exists — "Clean
     * target data" may have emptied its table, in which case the source record
     * is migrated (at the same id) instead of linked to nothing.
     */
    private static function existing($entity, $targetId)
    {
        $targetId = (int) $targetId;
        if ($targetId <= 0) {
            return 0;
        }
        list($table, $pk) = self::tableOf($entity);

        return Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . bqSQL($table) . '`
            WHERE `' . bqSQL($pk) . '` = ' . $targetId, false) ? $targetId : 0;
    }

    private static function hasMatcher($entity)
    {
        return in_array($entity, ['category', 'cms_category', 'group', 'customer', 'employee', 'profile', 'meta',
            'country', 'state', 'currency', 'tax', 'tax_rules_group', 'tag'], true);
    }

    /**
     * Finds an existing target record that IS the source record (natural key).
     * Returns its id or 0.
     */
    private static function matchExisting($entity, $sourceId, array $hints)
    {
        switch ($entity) {
            case 'category':
                $root = (int) self::sourceConfig('PS_ROOT_CATEGORY', 1);
                $home = (int) self::sourceConfig('PS_HOME_CATEGORY', 2);
                if ($sourceId === $root) {
                    return self::existing('category', (int) \Configuration::get('PS_ROOT_CATEGORY') ?: 1);
                }
                if ($sourceId === $home) {
                    return self::existing('category', (int) \Configuration::get('PS_HOME_CATEGORY') ?: 2);
                }

                return 0;

            case 'cms_category':
                return $sourceId === 1 ? self::existing('cms_category', 1) : 0;

            case 'group':
                foreach (['PS_UNIDENTIFIED_GROUP' => 1, 'PS_GUEST_GROUP' => 2, 'PS_CUSTOMER_GROUP' => 3] as $key => $default) {
                    if ($sourceId === (int) self::sourceConfig($key, $default)) {
                        return self::existing('group', (int) \Configuration::get($key) ?: $default);
                    }
                }
                $name = self::sourceValue("SELECT `name` FROM `" . self::$prefix . "group_lang` WHERE `id_group` = $sourceId ORDER BY (`id_lang` = " . (int) self::sourceConfig('PS_LANG_DEFAULT', 1) . ') DESC');

                return $name === '' ? 0 : (int) Db::getInstance()->getValue("SELECT `id_group` FROM `" . _DB_PREFIX_ . "group_lang` WHERE `name` = '" . pSQL($name) . "'", false);

            case 'customer':
                $email = isset($hints['email']) ? $hints['email'] : null;
                $guest = isset($hints['is_guest']) ? (int) $hints['is_guest'] : null;
                if ($email === null) {
                    $row = self::sourceRow("SELECT `email`, `is_guest` FROM `" . self::$prefix . "customer` WHERE `id_customer` = $sourceId");
                    if (!$row) {
                        return 0;
                    }
                    $email = $row['email'];
                    $guest = (int) $row['is_guest'];
                }
                if ($guest || trim((string) $email) === '') {
                    return 0; // guest checkouts are separate records even with the same e-mail
                }

                return (int) Db::getInstance()->getValue("SELECT `id_customer` FROM `" . _DB_PREFIX_ . "customer`
                    WHERE `email` = '" . pSQL($email) . "' AND `is_guest` = 0 AND `deleted` = 0
                    ORDER BY (`id_shop` = " . (int) SchemaHelper::getTargetShopId() . ') DESC, `id_customer` ASC', false);

            case 'employee':
                $email = isset($hints['email']) ? $hints['email'] : self::sourceValue("SELECT `email` FROM `" . self::$prefix . "employee` WHERE `id_employee` = $sourceId");
                if (trim((string) $email) === '') {
                    return 0;
                }

                return (int) Db::getInstance()->getValue("SELECT `id_employee` FROM `" . _DB_PREFIX_ . "employee` WHERE `email` = '" . pSQL($email) . "'", false);

            case 'profile':
                if ($sourceId === (int) _PS_ADMIN_PROFILE_) {
                    return self::existing('profile', (int) _PS_ADMIN_PROFILE_);
                }
                $name = self::sourceValue("SELECT `name` FROM `" . self::$prefix . "profile_lang` WHERE `id_profile` = $sourceId ORDER BY (`id_lang` = " . (int) self::sourceConfig('PS_LANG_DEFAULT', 1) . ') DESC');

                return $name === '' ? 0 : (int) Db::getInstance()->getValue("SELECT `id_profile` FROM `" . _DB_PREFIX_ . "profile_lang` WHERE `name` = '" . pSQL($name) . "'", false);

            case 'meta':
                $page = isset($hints['page']) ? $hints['page'] : self::sourceValue("SELECT `page` FROM `" . self::$prefix . "meta` WHERE `id_meta` = $sourceId");

                return $page === '' ? 0 : (int) Db::getInstance()->getValue("SELECT `id_meta` FROM `" . _DB_PREFIX_ . "meta` WHERE `page` = '" . pSQL($page) . "'", false);

            case 'country':
                $iso = self::sourceIso('country', $sourceId);

                return $iso === '' ? 0 : (int) \Country::getByIso($iso);

            case 'currency':
                $iso = self::sourceIso('currency', $sourceId);

                return $iso === '' ? 0 : (int) Db::getInstance()->getValue("SELECT `id_currency` FROM `" . _DB_PREFIX_ . "currency`
                    WHERE `iso_code` = '" . pSQL($iso) . "' ORDER BY `deleted` ASC", false);

            case 'state':
                $row = self::sourceRow("SELECT `iso_code`, `id_country` FROM `" . self::$prefix . "state` WHERE `id_state` = $sourceId");
                if (!$row) {
                    return 0;
                }
                $country = self::ref('country', (int) $row['id_country']);
                if ($country <= 0) {
                    return 0;
                }

                return (int) Db::getInstance()->getValue("SELECT `id_state` FROM `" . _DB_PREFIX_ . "state`
                    WHERE `iso_code` = '" . pSQL($row['iso_code']) . "' AND `id_country` = " . (int) $country, false);

            case 'tax':
                $row = self::sourceRow("SELECT t.`rate`, tl.`name` FROM `" . self::$prefix . "tax` t
                    LEFT JOIN `" . self::$prefix . "tax_lang` tl ON tl.`id_tax` = t.`id_tax`
                    WHERE t.`id_tax` = $sourceId ORDER BY (tl.`id_lang` = " . (int) self::sourceConfig('PS_LANG_DEFAULT', 1) . ') DESC');
                if (!$row || $row['name'] === null) {
                    return 0;
                }

                return (int) Db::getInstance()->getValue("SELECT t.`id_tax` FROM `" . _DB_PREFIX_ . "tax` t
                    JOIN `" . _DB_PREFIX_ . "tax_lang` tl ON tl.`id_tax` = t.`id_tax`
                    WHERE tl.`name` = '" . pSQL($row['name']) . "' AND ABS(t.`rate` - " . (float) $row['rate'] . ') < 0.001
                    ORDER BY t.`deleted` ASC', false);

            case 'tax_rules_group':
                $name = self::sourceValue("SELECT `name` FROM `" . self::$prefix . "tax_rules_group` WHERE `id_tax_rules_group` = $sourceId");
                if ($name === '') {
                    return 0;
                }
                $deleted = self::targetHasColumn('tax_rules_group', 'deleted') ? ' ORDER BY `deleted` ASC' : '';

                return (int) Db::getInstance()->getValue("SELECT `id_tax_rules_group` FROM `" . _DB_PREFIX_ . "tax_rules_group`
                    WHERE `name` = '" . pSQL($name) . "'" . $deleted, false);

            case 'tag':
                $row = isset($hints['name']) ? $hints : self::sourceRow("SELECT `name`, `id_lang` FROM `" . self::$prefix . "tag` WHERE `id_tag` = $sourceId");
                if (!$row) {
                    return 0;
                }
                $lang = LanguageMapper::toTarget($row['id_lang']);
                if (!$lang) {
                    return 0;
                }

                return (int) Db::getInstance()->getValue("SELECT `id_tag` FROM `" . _DB_PREFIX_ . "tag`
                    WHERE `name` = '" . pSQL($row['name']) . "' AND `id_lang` = " . (int) $lang, false);
        }

        return 0;
    }

    /**
     * Value used when a reference points at an entity that is neither mapped
     * nor part of this migration: always 0. Retargeting to a default would
     * change meaning (a price for EUR silently becoming a price for PLN); steps
     * that need a value substitute their own, context-aware default.
     */
    private static function fallback($entity)
    {
        switch ($entity) {
            case 'tax_rules_group':
                self::warnOnce('fallback_tax_rules_group', 'A tax rules group of the source has no match in the target '
                    . '(tax rules not migrated) — affected products are saved without a tax rule. Migrate "Tax rules" to keep them.');

                return 0;
        }

        return 0;
    }

    /**
     * Pseudo-entities that are matched each time and never stored.
     */
    private static function dictionary($entity, $sourceId)
    {
        switch ($entity) {
            case 'lang':
                return (int) LanguageMapper::toTargetOrDefault($sourceId);

            case 'shop':
                return (int) SchemaHelper::getTargetShopId();

            case 'shop_group':
                if (!isset(self::$dict['shop_group'])) {
                    self::$dict['shop_group'] = (int) Db::getInstance()->getValue('SELECT `id_shop_group` FROM `' . _DB_PREFIX_ . 'shop`
                        WHERE `id_shop` = ' . (int) SchemaHelper::getTargetShopId(), false) ?: 1;
                }

                return self::$dict['shop_group'];

            case 'gender':
            case 'risk':
                $table = $entity === 'gender' ? 'gender' : 'risk';
                if (!isset(self::$dict[$entity][$sourceId])) {
                    self::$dict[$entity][$sourceId] = (int) Db::getInstance()->getValue('SELECT `id_' . $table . '` FROM `' . _DB_PREFIX_ . $table . '`
                        WHERE `id_' . $table . '` = ' . (int) $sourceId, false);
                }

                return self::$dict[$entity][$sourceId];

            case 'zone':
                $map = isset(self::$options['zone_map']) && is_array(self::$options['zone_map']) ? self::$options['zone_map'] : [];
                if (isset($map[$sourceId]) && (int) $map[$sourceId] > 0) {
                    return (int) $map[$sourceId];
                }
                if (!isset(self::$dict['zone'][$sourceId])) {
                    $name = self::sourceValue("SELECT `name` FROM `" . self::$prefix . "zone` WHERE `id_zone` = $sourceId");
                    self::$dict['zone'][$sourceId] = $name === '' ? 0 : (int) Db::getInstance()->getValue("SELECT `id_zone` FROM `" . _DB_PREFIX_ . "zone`
                        WHERE `name` = '" . pSQL($name) . "'", false);
                }

                return self::$dict['zone'][$sourceId];

            case 'order_state':
                $map = isset(self::$options['status_map']) && is_array(self::$options['status_map']) ? self::$options['status_map'] : [];
                $candidate = isset($map[$sourceId]) && (int) $map[$sourceId] > 0 ? (int) $map[$sourceId] : $sourceId;
                if (!isset(self::$dict['order_state'][$candidate])) {
                    $exists = (int) Db::getInstance()->getValue('SELECT `id_order_state` FROM `' . _DB_PREFIX_ . 'order_state`
                        WHERE `id_order_state` = ' . (int) $candidate, false);
                    if (!$exists) {
                        $exists = (int) Db::getInstance()->getValue('SELECT `id_order_state` FROM `' . _DB_PREFIX_ . 'order_state`
                            ORDER BY `id_order_state` ASC', false);
                    }
                    self::$dict['order_state'][$candidate] = $exists;
                }

                return self::$dict['order_state'][$candidate];

            case 'none':
                return 0;
        }

        return 0;
    }

    private static function sourceIso($table, $sourceId)
    {
        if (!isset(self::$dict['iso_' . $table])) {
            self::$dict['iso_' . $table] = [];
            try {
                $rows = self::$conn->query("SELECT `id_{$table}` AS id, `iso_code` FROM `" . self::$prefix . "{$table}`")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    self::$dict['iso_' . $table][(int) $row['id']] = strtoupper((string) $row['iso_code']);
                }
            } catch (\Exception $e) {
                // source table unreadable — no match
            }
        }

        return isset(self::$dict['iso_' . $table][$sourceId]) ? self::$dict['iso_' . $table][$sourceId] : '';
    }

    public static function sourceConfig($name, $default = null)
    {
        if (!isset(self::$dict['source_config'])) {
            self::$dict['source_config'] = [];
            try {
                $rows = self::$conn->query("SELECT `name`, `value` FROM `" . self::$prefix . "configuration`
                    WHERE `name` IN ('PS_ROOT_CATEGORY','PS_HOME_CATEGORY','PS_UNIDENTIFIED_GROUP','PS_GUEST_GROUP','PS_CUSTOMER_GROUP',
                    'PS_LANG_DEFAULT','PS_CURRENCY_DEFAULT','PS_COUNTRY_DEFAULT')
                    ORDER BY (`id_shop` IS NULL) DESC, (`id_shop_group` IS NULL) DESC")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    if (!isset(self::$dict['source_config'][$row['name']])) {
                        self::$dict['source_config'][$row['name']] = $row['value'];
                    }
                }
            } catch (\Exception $e) {
                // defaults below
            }
        }

        return isset(self::$dict['source_config'][$name]) && self::$dict['source_config'][$name] !== ''
            ? self::$dict['source_config'][$name]
            : $default;
    }

    private static function sourceValue($sql)
    {
        $row = self::sourceRow($sql);

        return $row ? (string) reset($row) : '';
    }

    private static function sourceRow($sql)
    {
        try {
            $rows = self::$conn->query($sql . ' LIMIT 1')->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return null;
        }

        return empty($rows) ? null : $rows[0];
    }

    private static function targetHasColumn($table, $column)
    {
        return in_array($column, SchemaHelper::getTableColumns($table), true);
    }

    private static function warnOnce($key, $message)
    {
        if (isset(self::$warned[$key])) {
            return;
        }
        self::$warned[$key] = true;
        LogService::getInstance()->warning($message);
    }
}
