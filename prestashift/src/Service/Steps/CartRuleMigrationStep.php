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

class CartRuleMigrationStep
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
        $items = $this->getData($offset, $limit, $dateFilter);

        if (empty($items)) {
            return ['count' => 0, 'finished' => true];
        }

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
        $id = (int)$data['id_cart_rule'];
        
        // Clean fields that might not exist in older/newer versions or need reset
        $data['id_shop'] = \PrestaShift\Service\SchemaHelper::getTargetShopId();
        
        $sql = SchemaHelper::buildUpsertQuery('cart_rule', $data, ['id_cart_rule']);
        
        if ($sql) {
            try {
                Db::getInstance()->execute($sql);
                
                // Import Lang
                $this->importCartRuleLang($id);
                
                // Import Shop relation
                 Db::getInstance()->execute("REPLACE INTO `" . \_DB_PREFIX_ . "cart_rule_shop` (id_cart_rule, id_shop) VALUES ($id, " . \PrestaShift\Service\SchemaHelper::getTargetShopId() . ")");

                // Import conditions
                $this->importCartRuleCombinations($id);
                $this->importCartRuleProductRules($id);

            } catch (\Exception $e) {
                // Log
            }
        }
    }
    
    private function importCartRuleLang($id_cart_rule)
    {
        $sql = "SELECT * FROM `{$this->prefix}cart_rule_lang` WHERE id_cart_rule = $id_cart_rule";
        $rows = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
             Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "cart_rule_lang` WHERE id_cart_rule = $id_cart_rule AND id_lang = " . (int)$row['id_lang']);
             $row['id_shop'] = \PrestaShift\Service\SchemaHelper::getTargetShopId(); // Fallback
             $sql = SchemaHelper::buildInsertQuery('cart_rule_lang', $row);
             if ($sql) {
                 Db::getInstance()->execute($sql);
             }
        }
    }

    /**
     * Migrate cart rule combinations (which rules can stack together)
     */
    private function importCartRuleCombinations($id_cart_rule)
    {
        try {
            $sql = "SELECT * FROM `{$this->prefix}cart_rule_combination` WHERE id_cart_rule_1 = $id_cart_rule OR id_cart_rule_2 = $id_cart_rule";
            $rows = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $sql = SchemaHelper::buildInsertQuery('cart_rule_combination', $row, true);
                if ($sql) {
                    Db::getInstance()->execute($sql);
                }
            }
        } catch (\Exception $e) {
            // Table may not exist
        }
    }

    /**
     * Migrate cart rule product rules (conditions: which products/categories/etc.)
     */
    private function importCartRuleProductRules($id_cart_rule)
    {
        try {
            // 1. Product rule groups
            $sql = "SELECT * FROM `{$this->prefix}cart_rule_product_rule_group` WHERE id_cart_rule = $id_cart_rule";
            $groups = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "cart_rule_product_rule_group` WHERE id_cart_rule = $id_cart_rule");

            foreach ($groups as $group) {
                $groupId = (int)$group['id_product_rule_group'];

                $sql = SchemaHelper::buildInsertQuery('cart_rule_product_rule_group', $group, true);
                if ($sql) {
                    Db::getInstance()->execute($sql);
                }

                // 2. Product rules within each group
                $sql = "SELECT * FROM `{$this->prefix}cart_rule_product_rule` WHERE id_product_rule_group = $groupId";
                $rules = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);

                Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "cart_rule_product_rule` WHERE id_product_rule_group = $groupId");

                foreach ($rules as $rule) {
                    $ruleId = (int)$rule['id_product_rule'];

                    $sql = SchemaHelper::buildInsertQuery('cart_rule_product_rule', $rule, true);
                    if ($sql) {
                        Db::getInstance()->execute($sql);
                    }

                    // 3. Product rule values (actual product/category/attribute IDs)
                    $sql = "SELECT * FROM `{$this->prefix}cart_rule_product_rule_value` WHERE id_product_rule = $ruleId";
                    $values = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);

                    Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "cart_rule_product_rule_value` WHERE id_product_rule = $ruleId");

                    foreach ($values as $val) {
                        $sql = SchemaHelper::buildInsertQuery('cart_rule_product_rule_value', $val, true);
                        if ($sql) {
                            Db::getInstance()->execute($sql);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Tables may not exist in old PS versions
        }
    }
}
