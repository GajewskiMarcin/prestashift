<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @version   1.3.0
 */
namespace PrestaShift\Service\Steps;

use Db;
use PDO;
use PrestaShift\Service\IdMapper;
use PrestaShift\Service\LanguageMapper;
use PrestaShift\Service\LogService;
use PrestaShift\Service\SchemaHelper;

/**
 * Vouchers with everything that limits them: customer, countries, groups,
 * carriers, combinable vouchers and product conditions. Translation always errs
 * on the restrictive side — a voucher may end up unusable, never more generous
 * than in the source.
 */
class CartRuleMigrationStep
{
    private $db_connection;
    private $prefix;

    /** product rule type => entity of id_item */
    private static $itemEntities = [
        'products' => 'product',
        'categories' => 'category',
        'attributes' => 'attribute',
        'manufacturers' => 'manufacturer',
        'suppliers' => 'supplier',
    ];

    public function __construct($db_connection, $prefix)
    {
        $this->db_connection = $db_connection;
        $this->prefix = $prefix;
    }

    public function process($offset, $limit, $dateFilter = null)
    {
        $items = $this->getData($offset, $limit, $dateFilter);

        if (empty($items)) {
            return ['count' => 0, 'finished' => true];
        }

        IdMapper::prepare('cart_rule', array_column($items, 'id_cart_rule'));

        foreach ($items as $item) {
            $this->importCartRule($item);
        }

        return ['count' => count($items), 'finished' => false];
    }

    private function getData($offset, $limit, $dateFilter = null)
    {
        $where = "";
        if ($dateFilter) {
            $where = " WHERE `date_add` > '{$dateFilter}' OR `date_upd` > '{$dateFilter}' ";
        }
        $sql = "SELECT * FROM `{$this->prefix}cart_rule` {$where} ORDER BY `id_cart_rule` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importCartRule($data)
    {
        $sid = (int)$data['id_cart_rule'];
        $row = IdMapper::row('cart_rule', $data);

        // A personal voucher must not become public
        if ((int)$data['id_customer'] > 0 && (int)$row['id_customer'] <= 0) {
            LogService::getInstance()->warning("Voucher #$sid skipped: it belongs to customer #{$data['id_customer']}, who is not in the target.");
            return;
        }
        // A discount on one product must not become a discount on the order
        if ((int)$data['reduction_product'] > 0 && (int)$row['reduction_product'] <= 0) {
            LogService::getInstance()->warning("Voucher #$sid skipped: its discounted product was not migrated.");
            return;
        }
        if ((int)$data['gift_product'] > 0 && (int)$row['gift_product'] <= 0) {
            $row['gift_product'] = 0;
            $row['gift_product_attribute'] = 0;
        }
        foreach (['reduction_currency', 'minimum_amount_currency'] as $col) {
            if (isset($row[$col]) && (int)$row[$col] <= 0) {
                $row[$col] = (int)\Configuration::get('PS_CURRENCY_DEFAULT');
            }
        }

        $tid = (int)$row['id_cart_rule'];
        if (!SchemaHelper::upsert('cart_rule', $row, ['id_cart_rule'])) {
            return;
        }

        $this->importLang($sid, $tid);
        Db::getInstance()->execute("REPLACE INTO `" . _DB_PREFIX_ . "cart_rule_shop` (id_cart_rule, id_shop) VALUES ($tid, " . SchemaHelper::getTargetShopId() . ")");

        $this->importRestriction('cart_rule_country', 'id_country', 'country', $sid, $tid);
        $this->importRestriction('cart_rule_group', 'id_group', 'group', $sid, $tid);
        // cart_rule_carrier holds carrier reference ids (id_reference)
        $this->importRestriction('cart_rule_carrier', 'id_carrier', 'carrier', $sid, $tid);
        $this->importCombinations($sid, $tid);
        $this->importProductRules($sid, $tid);
    }

    private function importLang($sid, $tid)
    {
        $rows = LanguageMapper::expand($this->db_connection->query("SELECT * FROM `{$this->prefix}cart_rule_lang` WHERE id_cart_rule = $sid")->fetchAll(PDO::FETCH_ASSOC));

        foreach ($rows as $row) {
            Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "cart_rule_lang` WHERE id_cart_rule = $tid AND id_lang = " . (int)$row['id_lang']);
            SchemaHelper::insertIgnore('cart_rule_lang', [
                'id_cart_rule' => $tid,
                'id_lang' => (int)$row['id_lang'],
                'name' => $row['name'],
            ]);
        }
    }

    /**
     * Country / group / carrier lists. Entries that cannot be translated are
     * dropped, which narrows the voucher.
     */
    private function importRestriction($table, $column, $entity, $sid, $tid)
    {
        try {
            $rows = $this->db_connection->query("SELECT `$column` FROM `{$this->prefix}$table` WHERE id_cart_rule = $sid")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return;
        }

        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "$table` WHERE id_cart_rule = $tid");

        foreach ($rows as $r) {
            $id = IdMapper::ref($entity, (int)$r[$column]);
            if ($id > 0) {
                SchemaHelper::insertIgnore($table, ['id_cart_rule' => $tid, $column => $id]);
            }
        }
    }

    /**
     * Which vouchers can be combined with this one.
     */
    private function importCombinations($sid, $tid)
    {
        try {
            $rows = $this->db_connection->query("SELECT * FROM `{$this->prefix}cart_rule_combination` WHERE id_cart_rule_1 = $sid OR id_cart_rule_2 = $sid")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return;
        }

        foreach ($rows as $r) {
            $row = IdMapper::row('cart_rule_combination', $r);
            if ((int)$row['id_cart_rule_1'] > 0 && (int)$row['id_cart_rule_2'] > 0) {
                SchemaHelper::insertIgnore('cart_rule_combination', $row);
            }
        }
    }

    /**
     * Product conditions: groups of rules, each rule a list of products,
     * categories, attributes, brands or suppliers (id_item depends on the type).
     */
    private function importProductRules($sid, $tid)
    {
        try {
            $groups = $this->db_connection->query("SELECT * FROM `{$this->prefix}cart_rule_product_rule_group` WHERE id_cart_rule = $sid")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return;
        }

        // Replace the conditions as a whole
        $old = Db::getInstance()->executeS("SELECT id_product_rule_group FROM `" . _DB_PREFIX_ . "cart_rule_product_rule_group` WHERE id_cart_rule = $tid");
        foreach ((array)$old as $g) {
            $gid = (int)$g['id_product_rule_group'];
            $rules = Db::getInstance()->executeS("SELECT id_product_rule FROM `" . _DB_PREFIX_ . "cart_rule_product_rule` WHERE id_product_rule_group = $gid");
            foreach ((array)$rules as $r) {
                Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "cart_rule_product_rule_value` WHERE id_product_rule = " . (int)$r['id_product_rule']);
            }
            Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "cart_rule_product_rule` WHERE id_product_rule_group = $gid");
        }
        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "cart_rule_product_rule_group` WHERE id_cart_rule = $tid");

        foreach ($groups as $group) {
            $group['id_cart_rule'] = $sid;
            $grow = IdMapper::row('cart_rule_product_rule_group', $group);
            SchemaHelper::upsert('cart_rule_product_rule_group', $grow, ['id_product_rule_group']);

            $rules = $this->db_connection->query("SELECT * FROM `{$this->prefix}cart_rule_product_rule` WHERE id_product_rule_group = " . (int)$group['id_product_rule_group'])->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rules as $rule) {
                $rrow = IdMapper::row('cart_rule_product_rule', $rule);
                SchemaHelper::upsert('cart_rule_product_rule', $rrow, ['id_product_rule']);

                $entity = isset(self::$itemEntities[$rule['type']]) ? self::$itemEntities[$rule['type']] : null;
                $values = $this->db_connection->query("SELECT id_item FROM `{$this->prefix}cart_rule_product_rule_value` WHERE id_product_rule = " . (int)$rule['id_product_rule'])->fetchAll(PDO::FETCH_ASSOC);
                foreach ($values as $v) {
                    $item = $entity ? IdMapper::ref($entity, (int)$v['id_item']) : 0;
                    if ($item > 0) {
                        SchemaHelper::insertIgnore('cart_rule_product_rule_value', [
                            'id_product_rule' => (int)$rrow['id_product_rule'],
                            'id_item' => $item,
                        ]);
                    }
                }
            }
        }
    }
}
