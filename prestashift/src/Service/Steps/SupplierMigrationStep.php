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

class SupplierMigrationStep
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
        $sql = "SELECT * FROM `{$this->prefix}supplier` ORDER BY `id_supplier` ASC LIMIT $limit OFFSET $offset";
        $items = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            return ['count' => 0, 'finished' => true];
        }

        foreach ($items as $item) {
            $this->importSupplier($item);
        }

        return ['count' => count($items), 'finished' => false];
    }

    private function importSupplier($data)
    {
        $id = (int)$data['id_supplier'];
        
        $sql = SchemaHelper::buildUpsertQuery('supplier', $data, ['id_supplier']);
        if ($sql) {
            Db::getInstance()->execute($sql);
            
            // Lang
            $this->importLang($id);
            // Shop
            Db::getInstance()->execute("REPLACE INTO `" . \_DB_PREFIX_ . "supplier_shop` (id_supplier, id_shop) VALUES ($id, " . \PrestaShift\Service\SchemaHelper::getTargetShopId() . ")");
        }
    }
    
    private function importLang($id)
    {
        $sql = "SELECT * FROM `{$this->prefix}supplier_lang` WHERE id_supplier = $id";
        $rows = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "supplier_lang` WHERE id_supplier = $id AND id_lang = " . (int)$row['id_lang']);
            $sql = SchemaHelper::buildInsertQuery('supplier_lang', $row);
            if ($sql) Db::getInstance()->execute($sql);
        }
    }
}
