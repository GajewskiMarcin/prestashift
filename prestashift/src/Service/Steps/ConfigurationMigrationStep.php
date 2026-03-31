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
use PrestaShift\Service\SchemaHelper;

class ConfigurationMigrationStep
{
    private $db_connection;
    private $prefix;

    /**
     * Safe configuration keys to migrate — curated list of non-destructive settings
     */
    private static $safeKeys = [
        // Shop identity
        'PS_SHOP_NAME', 'PS_SHOP_EMAIL', 'PS_SHOP_PHONE', 'PS_SHOP_FAX',
        'PS_SHOP_DETAILS', 'PS_SHOP_ADDR1', 'PS_SHOP_ADDR2',
        'PS_SHOP_CODE', 'PS_SHOP_CITY', 'PS_SHOP_COUNTRY_ID', 'PS_SHOP_STATE_ID',

        // Catalog settings
        'PS_PRODUCTS_PER_PAGE', 'PS_PRODUCTS_ORDER_BY', 'PS_PRODUCTS_ORDER_WAY',
        'PS_DISPLAY_QTIES', 'PS_DISPLAY_JQZOOM', 'PS_DISPLAY_DISCOUNT_PRICE',
        'PS_COMPARATOR_MAX_ITEM', 'PS_NB_DAYS_NEW_PRODUCT',
        'PS_CATALOG_MODE', 'PS_STOCK_MANAGEMENT',

        // Shipping defaults
        'PS_SHIPPING_HANDLING', 'PS_SHIPPING_FREE_PRICE', 'PS_SHIPPING_FREE_WEIGHT',
        'PS_SHIPPING_METHOD',

        // Customer settings
        'PS_CUSTOMER_BIRTHDATE', 'PS_CUSTOMER_NWSL', 'PS_CUSTOMER_OPTIN',
        'PS_GUEST_CHECKOUT_ENABLED', 'PS_B2B_ENABLE',

        // Order settings
        'PS_ORDER_PROCESS_TYPE', 'PS_PURCHASE_MINIMUM',
        'PS_ALLOW_MULTISHIPPING', 'PS_GIFT_WRAPPING', 'PS_GIFT_WRAPPING_PRICE',

        // SEO
        'PS_REWRITING_SETTINGS', 'PS_ROUTE_product_rule', 'PS_ROUTE_category_rule',
        'PS_ROUTE_cms_rule', 'PS_ROUTE_cms_category_rule',

        // Images
        'PS_IMAGE_QUALITY', 'PS_JPEG_QUALITY', 'PS_PNG_QUALITY',

        // Weight/Dimension
        'PS_WEIGHT_UNIT', 'PS_DIMENSION_UNIT', 'PS_VOLUME_UNIT',

        // Localization
        'PS_LOCALE_LANGUAGE', 'PS_LOCALE_COUNTRY', 'PS_TIMEZONE',
        'PS_CURRENCY_DEFAULT', 'PS_COUNTRY_DEFAULT', 'PS_LANG_DEFAULT',

        // Taxes
        'PS_TAX', 'PS_TAX_DISPLAY', 'PS_PRICE_ROUND_MODE',
    ];

    public function __construct($db_connection, $prefix)
    {
        $this->db_connection = $db_connection;
        $this->prefix = $prefix;
    }

    public function process($offset, $limit, $dateFilter = null)
    {
        // One-shot migration
        try {
            $keysIn = implode("','", array_map('pSQL', self::$safeKeys));
            $sql = "SELECT * FROM `{$this->prefix}configuration` WHERE `name` IN ('{$keysIn}')";
            $rows = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return ['count' => 0, 'finished' => true];
        }

        $imported = 0;
        foreach ($rows as $row) {
            $this->importConfig($row);
            $imported++;
        }

        // Also migrate configuration_lang entries
        try {
            $sql = "SELECT cl.* FROM `{$this->prefix}configuration_lang` cl
                    JOIN `{$this->prefix}configuration` c ON c.id_configuration = cl.id_configuration
                    WHERE c.`name` IN ('{$keysIn}')";
            $langRows = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            foreach ($langRows as $langRow) {
                $this->importConfigLang($langRow);
            }
        } catch (\Exception $e) {
            // configuration_lang may not exist or no lang values
        }

        return ['count' => $imported, 'finished' => true];
    }

    private function importConfig($data)
    {
        $name = pSQL($data['name']);
        $value = pSQL($data['value'], true);

        // Use PrestaShop Configuration API for safety
        try {
            \Configuration::updateValue($name, $data['value']);
        } catch (\Exception $e) {
            // Fallback: direct SQL
            $shopId = SchemaHelper::getTargetShopId();
            $sql = "UPDATE `" . _DB_PREFIX_ . "configuration` SET `value` = '{$value}', `date_upd` = NOW()
                    WHERE `name` = '{$name}'";
            Db::getInstance()->execute($sql);
        }
    }

    private function importConfigLang($data)
    {
        try {
            $idConfig = (int)$data['id_configuration'];
            $idLang = (int)$data['id_lang'];
            $value = pSQL($data['value'], true);

            // Find matching config ID in target by looking up the parent config name
            $sql = "UPDATE `" . _DB_PREFIX_ . "configuration_lang`
                    SET `value` = '{$value}', `date_upd` = NOW()
                    WHERE `id_configuration` = {$idConfig} AND `id_lang` = {$idLang}";
            Db::getInstance()->execute($sql);
        } catch (\Exception $e) {
            // Skip silently
        }
    }

    /**
     * Get list of safe keys (for UI display)
     */
    public static function getSafeKeys()
    {
        return self::$safeKeys;
    }
}
