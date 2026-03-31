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

class AttributeGroupMigrationStep
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
        $rows = $this->getData($offset, $limit);

        if (empty($rows)) {
            return ['count' => 0, 'finished' => true];
        }

        foreach ($rows as $row) {
            $this->importItem($row);
        }

        return ['count' => count($rows), 'finished' => false];
    }

    private function getData($offset, $limit)
    {
        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}attribute_group` ORDER BY id_attribute_group ASC LIMIT $limit OFFSET $offset");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importItem($data)
    {
        $id = (int)$data['id_attribute_group'];
        $sql = SchemaHelper::buildInsertQuery('attribute_group', $data);
        if ($sql) {
            Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "attribute_group` WHERE id_attribute_group = $id");
            Db::getInstance()->execute($sql);
        }

        // Lang
        $this->importLang($id);
        
        // Shop
        Db::getInstance()->execute("REPLACE INTO `" . \_DB_PREFIX_ . "attribute_group_shop` (id_attribute_group, id_shop) VALUES ($id, " . \PrestaShift\Service\SchemaHelper::getTargetShopId() . ")");
    }

    private function importLang($id)
    {
        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}attribute_group_lang` WHERE id_attribute_group = $id");
        $langs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($langs as $lang) {
            // Force target shop
            $lang['id_shop'] = \PrestaShift\Service\SchemaHelper::getTargetShopId();
            
            $sql = SchemaHelper::buildInsertQuery('attribute_group_lang', $lang);
            if ($sql) {
                 Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "attribute_group_lang` WHERE id_attribute_group = $id AND id_lang = " . (int)$lang['id_lang']);
                 Db::getInstance()->execute($sql);
            }
        }
    }
}
