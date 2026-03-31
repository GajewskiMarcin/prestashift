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

class AttributeMigrationStep
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
        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}attribute` ORDER BY id_attribute ASC LIMIT $limit OFFSET $offset");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importItem($data)
    {
        $id = (int)$data['id_attribute'];
        $sql = SchemaHelper::buildInsertQuery('attribute', $data);
        if ($sql) {
            Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "attribute` WHERE id_attribute = $id");
            Db::getInstance()->execute($sql);
        }

        // Lang
        $this->importLang($id);
        
        // Shop
        Db::getInstance()->execute("REPLACE INTO `" . \_DB_PREFIX_ . "attribute_shop` (id_attribute, id_shop) VALUES ($id, " . \PrestaShift\Service\SchemaHelper::getTargetShopId() . ")");
    }

    private function importLang($id)
    {
        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}attribute_lang` WHERE id_attribute = $id");
        $langs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($langs as $lang) {
             // Force shop 1 (PS 1.7+ attribute_lang doesn't always have id_shop, but PS 8+ might?)
             // SchemaHelper will filter out id_shop if target table doesn't have it, or we add it if missing in source but needed.
             // Usually attribute_lang has no id_shop, attribute_shop does. Allow SchemaHelper to decide based on target schema.
            
            $sql = SchemaHelper::buildInsertQuery('attribute_lang', $lang);
            if ($sql) {
                 Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "attribute_lang` WHERE id_attribute = $id AND id_lang = " . (int)$lang['id_lang']);
                 Db::getInstance()->execute($sql);
            }
        }
    }
}
