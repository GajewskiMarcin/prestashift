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

class TaxRulesMigrationStep
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
        // We really should migrate Taxes first, then Tax Rules Groups, then Tax Rules.
        // But since this is a looped process, we might do it all in one go or split.
        // Let's do a simple approach: First batch handles ALL taxes (usually few), 
        // subsequent batches handle Rules Groups.
        
        if ($offset == 0) {
            $this->migrateTaxes();
        }

        $rows = $this->getData($offset, $limit);

        if (empty($rows)) {
            return ['count' => 0, 'finished' => true];
        }

        foreach ($rows as $row) {
            $this->importItem($row);
        }

        return ['count' => count($rows), 'finished' => false];
    }
    
    private function migrateTaxes()
    {
        // ps_tax
        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}tax`");
        $taxes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($taxes as $tax) {
            $id = (int)$tax['id_tax'];
            $sql = SchemaHelper::buildInsertQuery('tax', $tax);
            if ($sql) {
                Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "tax` WHERE id_tax = $id");
                Db::getInstance()->execute($sql);
            }
            
            // Lang
            $stmtL = $this->db_connection->query("SELECT * FROM `{$this->prefix}tax_lang` WHERE id_tax = $id");
            $langs = $stmtL->fetchAll(PDO::FETCH_ASSOC);
            foreach ($langs as $lang) {
                // Ensure id_lang matches or map it
                $sqlL = SchemaHelper::buildInsertQuery('tax_lang', $lang);
                if ($sqlL) {
                     $sqlL = str_replace('INSERT INTO', 'REPLACE INTO', $sqlL);
                     Db::getInstance()->execute($sqlL);
                }
            }
        }
    }

    private function getData($offset, $limit)
    {
        // We iterate over Tax Rules Groups
        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}tax_rules_group` ORDER BY id_tax_rules_group ASC LIMIT $limit OFFSET $offset");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importItem($data)
    {
        $id = (int)$data['id_tax_rules_group'];
        $sql = SchemaHelper::buildInsertQuery('tax_rules_group', $data);
        if ($sql) {
            Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "tax_rules_group` WHERE id_tax_rules_group = $id");
            Db::getInstance()->execute($sql);
        }
        
        // Shop
        Db::getInstance()->execute("REPLACE INTO `" . \_DB_PREFIX_ . "tax_rules_group_shop` (id_tax_rules_group, id_shop) VALUES ($id, " . \PrestaShift\Service\SchemaHelper::getTargetShopId() . ")");

        // Rules
        $this->importRules($id);
    }
    
    private function importRules($id_group)
    {
        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}tax_rule` WHERE id_tax_rules_group = $id_group");
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "tax_rule` WHERE id_tax_rules_group = $id_group");
        
        foreach ($rules as $rule) {
            // Need to map country/state IDs? Ideally yes, but scope is same shop likely.
            // Assumption: Zone/Country IDs are same or standard.
            
            $sql = SchemaHelper::buildInsertQuery('tax_rule', $rule);
            if ($sql) Db::getInstance()->execute($sql);
        }
    }
}
