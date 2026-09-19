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

use Db;
use PDO;
use PrestaShift\Service\IdMapper;
use PrestaShift\Service\LanguageMapper;
use PrestaShift\Service\SchemaHelper;

class ProductMigrationStep
{
    private $db_connection;
    private $prefix;

    /** @var array source id_product => true, for products having combinations */
    private $withCombinations = [];

    /** @var array source id_product_1 => [source id_product_2, ...] for the batch */
    private $accessories = [];

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

        $ids = array_map('intval', array_column($products, 'id_product'));
        IdMapper::prepare('product', $ids);
        $this->loadCombinationFlags($ids);
        $this->loadAccessories($ids);

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

    private function loadCombinationFlags(array $ids)
    {
        $this->withCombinations = [];
        try {
            $rows = $this->db_connection->query("SELECT DISTINCT id_product FROM `{$this->prefix}product_attribute` WHERE id_product IN (" . implode(',', $ids) . ")")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $this->withCombinations[(int)$r['id_product']] = true;
            }
        } catch (\Exception $e) {
        }
    }

    private function loadAccessories(array $ids)
    {
        $this->accessories = [];
        try {
            $rows = $this->db_connection->query("SELECT id_product_1, id_product_2 FROM `{$this->prefix}accessory` WHERE id_product_1 IN (" . implode(',', $ids) . ")")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $this->accessories[(int)$r['id_product_1']][] = (int)$r['id_product_2'];
            }
        } catch (\Exception $e) {
        }
    }

    private function importProduct($data)
    {
        $sid = (int)$data['id_product'];
        $redirectType = $this->transformRedirectType(isset($data['redirect_type']) ? $data['redirect_type'] : '');

        $productData = [
            'id_product' => $sid,
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
            'redirect_type' => $redirectType,
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
            'product_type' => $this->productType($data),
        ];

        $row = IdMapper::row('product', $productData);
        $tid = (int)$row['id_product'];

        // Redirect target: a product or a category depending on the type
        // (PS 1.6 only knew products, in id_product_redirected)
        $redirectSource = isset($data['id_type_redirected']) ? (int)$data['id_type_redirected']
            : (isset($data['id_product_redirected']) ? (int)$data['id_product_redirected'] : 0);
        $redirectEntity = strpos((string)$redirectType, 'category') !== false ? 'category' : 'product';
        $row['id_type_redirected'] = $redirectSource > 0 ? IdMapper::ref($redirectEntity, $redirectSource) : 0;
        $row['id_product_redirected'] = $redirectEntity === 'product' ? $row['id_type_redirected'] : 0;

        if ((int)$row['id_category_default'] <= 0) {
            $row['id_category_default'] = (int)\Configuration::get('PS_HOME_CATEGORY');
        }

        // Full upsert: a repeated run (Delta) must refresh every field, not
        // just the price — a partial update leaves half-old products behind.
        if (!SchemaHelper::upsert('product', $row, ['id_product'])) {
            return;
        }

        $this->importProductLang($sid, $tid);
        $this->importProductShop($row);
        $this->importStock($sid, $tid);
        $this->importCategoryLink($sid, $tid, (int)$row['id_category_default']);
        $this->importAccessories($sid, $tid);
    }

    /**
     * PrestaShop 8+ decides which product page tabs exist from product_type.
     * 1.7 sources have no such column — derive it, otherwise products with
     * combinations open as standard products and their combinations vanish
     * from the back office.
     */
    private function productType($data)
    {
        if (!empty($data['product_type'])) {
            return $data['product_type'];
        }
        if (!empty($data['is_virtual'])) {
            return 'virtual';
        }
        if (!empty($data['cache_is_pack'])) {
            return 'pack';
        }
        if (isset($this->withCombinations[(int)$data['id_product']])) {
            return 'combinations';
        }

        return 'standard';
    }

    private function importProductLang($sid, $tid)
    {
        $shopId = SchemaHelper::getTargetShopId();
        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}product_lang` WHERE id_product = $sid ORDER BY id_shop ASC");
        $langs = LanguageMapper::expand($stmt->fetchAll(PDO::FETCH_ASSOC));

        $done = [];
        foreach ($langs as $lang) {
            $idLang = (int)$lang['id_lang'];
            if (isset($done[$idLang])) {
                continue; // multistore source: one row per language
            }
            $done[$idLang] = true;

            Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "product_lang` WHERE id_product = $tid AND id_lang = $idLang AND id_shop = $shopId");

            SchemaHelper::upsert('product_lang', [
                'id_product' => $tid,
                'id_shop' => $shopId,
                'id_lang' => $idLang,
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
            ], ['id_product', 'id_shop', 'id_lang']);
        }
    }

    /**
     * @param array $p translated product row (target ids)
     */
    private function importProductShop(array $p)
    {
        $shopData = [
            'id_product' => (int)$p['id_product'],
            'id_shop' => SchemaHelper::getTargetShopId(),
            'id_category_default' => (int)$p['id_category_default'],
            'id_tax_rules_group' => (int)$p['id_tax_rules_group'],
            'on_sale' => (int)$p['on_sale'],
            'online_only' => (int)$p['online_only'],
            'ecotax' => (float)$p['ecotax'],
            'minimal_quantity' => (int)$p['minimal_quantity'],
            'low_stock_threshold' => $p['low_stock_threshold'],
            'low_stock_alert' => $p['low_stock_alert'],
            'price' => (float)$p['price'],
            'wholesale_price' => (float)$p['wholesale_price'],
            'unity' => $p['unity'],
            'unit_price_ratio' => (float)$p['unit_price_ratio'],
            'additional_shipping_cost' => (float)$p['additional_shipping_cost'],
            'customizable' => (int)$p['customizable'],
            'uploadable_files' => (int)$p['uploadable_files'],
            'text_fields' => (int)$p['text_fields'],
            'active' => (int)$p['active'],
            'redirect_type' => $p['redirect_type'],
            'id_type_redirected' => (int)$p['id_type_redirected'],
            'id_product_redirected' => (int)$p['id_product_redirected'],
            'available_for_order' => (int)$p['available_for_order'],
            'available_date' => $p['available_date'],
            'show_condition' => (int)$p['show_condition'],
            'condition' => $p['condition'],
            'show_price' => (int)$p['show_price'],
            'indexed' => 1,
            'visibility' => $p['visibility'],
            'cache_default_attribute' => (int)$p['cache_default_attribute'],
            'advanced_stock_management' => (int)$p['advanced_stock_management'],
            'date_add' => $p['date_add'],
            'date_upd' => $p['date_upd'],
            'pack_stock_type' => $p['pack_stock_type'],
        ];

        SchemaHelper::upsert('product_shop', $shopData, ['id_product', 'id_shop']);
    }

    private function importStock($sid, $tid)
    {
        $sql = "SELECT quantity, out_of_stock FROM `{$this->prefix}stock_available` WHERE id_product = $sid AND id_product_attribute = 0";
        $row = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $qty = isset($row[0]['quantity']) ? (int)$row[0]['quantity'] : 0;

        \StockAvailable::setQuantity($tid, 0, $qty);

        // Force out_of_stock=2 (use global setting) — safer than copying
        // per-product overrides from source which may be stale or incorrect
        \StockAvailable::setProductOutOfStock($tid, 2);
    }

    private function importCategoryLink($sid, $tid, $defaultCategory)
    {
        $stmt = $this->db_connection->query("SELECT id_category, position FROM `{$this->prefix}category_product` WHERE id_product = $sid");
        $cats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "category_product` WHERE id_product = $tid");

        $values = [];
        foreach ($cats as $cat) {
            $idCategory = IdMapper::ref('category', (int)$cat['id_category']);
            if ($idCategory <= 0) {
                continue;
            }
            $values[$idCategory] = "($idCategory, $tid, " . (int)$cat['position'] . ")";
        }
        if ($defaultCategory > 0 && !isset($values[$defaultCategory])) {
            $values[$defaultCategory] = "($defaultCategory, $tid, 0)";
        }

        if ($values) {
            Db::getInstance()->execute("INSERT IGNORE INTO `" . _DB_PREFIX_ . "category_product` (id_category, id_product, position) VALUES " . implode(',', $values));
        }
    }

    /**
     * Related products ("accessories").
     */
    private function importAccessories($sid, $tid)
    {
        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "accessory` WHERE id_product_1 = $tid");

        $values = [];
        foreach (isset($this->accessories[$sid]) ? $this->accessories[$sid] : [] as $sourceOther) {
            $other = IdMapper::ref('product', $sourceOther);
            if ($other > 0) {
                $values[$other] = "($tid, $other)";
            }
        }
        if ($values) {
            Db::getInstance()->execute("INSERT IGNORE INTO `" . _DB_PREFIX_ . "accessory` (id_product_1, id_product_2) VALUES " . implode(',', $values));
        }
    }

    private function transformRedirectType($type)
    {
        $map = ['301' => '301-product', '302' => '302-product'];
        return isset($map[$type]) ? $map[$type] : $type;
    }
}
