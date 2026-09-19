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
namespace PrestaShift\Service\Steps;

use PDO;
use PrestaShift\Service\IdMapper;
use PrestaShift\Service\LanguageMapper;
use PrestaShift\Service\LogService;

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

    /** Keys whose value is a record id — translated, never copied */
    private static $idKeys = [
        'PS_SHOP_COUNTRY_ID' => 'country',
        'PS_COUNTRY_DEFAULT' => 'country',
        'PS_SHOP_STATE_ID' => 'state',
        'PS_CURRENCY_DEFAULT' => 'currency',
        'PS_LANG_DEFAULT' => 'lang',
    ];

    public function __construct($db_connection, $prefix)
    {
        $this->db_connection = $db_connection;
        $this->prefix = $prefix;
    }

    public function process($offset, $limit, $dateFilter = null)
    {
        $keysIn = implode("','", array_map('pSQL', self::$safeKeys));

        try {
            // The shop-independent value first; one value per key
            $rows = $this->db_connection->query("SELECT * FROM `{$this->prefix}configuration` WHERE `name` IN ('{$keysIn}')
                ORDER BY (`id_shop` IS NULL) DESC, (`id_shop_group` IS NULL) DESC, `id_configuration` ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return ['count' => 0, 'finished' => true];
        }

        $seen = [];
        $imported = 0;
        foreach ($rows as $row) {
            if (isset($seen[$row['name']])) {
                continue;
            }
            $seen[$row['name']] = (int)$row['id_configuration'];
            if ($this->importConfig($row['name'], $row['value'])) {
                $imported++;
            }
        }

        $this->importConfigLang(array_flip($seen));

        return ['count' => $imported, 'finished' => true];
    }

    private function importConfig($name, $value)
    {
        if (isset(self::$idKeys[$name])) {
            $entity = self::$idKeys[$name];
            $translated = $entity === 'lang' ? LanguageMapper::toTarget((int)$value) : IdMapper::ref($entity, (int)$value);
            if ((int)$value > 0 && (int)$translated <= 0) {
                LogService::getInstance()->warning("Configuration $name not migrated: value #$value has no counterpart in the target.");
                return false;
            }
            $value = (int)$translated;
        }

        return (bool)\Configuration::updateValue($name, $value);
    }

    /**
     * Multilingual values. Configuration ids differ between shops, so values
     * are written by key name and target language.
     *
     * @param array $keysById source id_configuration => name
     */
    private function importConfigLang(array $keysById)
    {
        if (empty($keysById)) {
            return;
        }

        try {
            $rows = LanguageMapper::expand($this->db_connection->query("SELECT * FROM `{$this->prefix}configuration_lang`
                WHERE id_configuration IN (" . implode(',', array_map('intval', array_keys($keysById))) . ")")->fetchAll(PDO::FETCH_ASSOC));
        } catch (\Exception $e) {
            return;
        }

        $values = [];
        foreach ($rows as $row) {
            $name = $keysById[(int)$row['id_configuration']];
            $values[$name][(int)$row['id_lang']] = $row['value'];
        }

        foreach ($values as $name => $byLang) {
            \Configuration::updateValue($name, $byLang, true);
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
