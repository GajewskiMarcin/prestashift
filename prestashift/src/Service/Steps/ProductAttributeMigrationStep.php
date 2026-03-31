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

class ProductAttributeMigrationStep
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
        // We migrate product_attributes (combinations)
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
        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}product_attribute` ORDER BY id_product_attribute ASC LIMIT $limit OFFSET $offset");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importItem($data)
    {
        $id = (int)$data['id_product_attribute'];
        $sql = SchemaHelper::buildInsertQuery('product_attribute', $data);
        if ($sql) {
            Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "product_attribute` WHERE id_product_attribute = $id");
            Db::getInstance()->execute($sql);
        }

        // Shop
        $this->importShop($id); // Usually simple, but check
        
        // Combination (The link to attributes)
        $this->importCombination($id);

        // Stock (Crucial for 1.7+)
        $this->importStock($id, (int)$data['id_product']);

        // Images linking
        $this->importImages($id);
    }

    private function importShop($id)
    {
         // Source might have product_attribute_shop
         $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}product_attribute_shop` WHERE id_product_attribute = $id");
         if ($stmt) {
             $shops = $stmt->fetchAll(PDO::FETCH_ASSOC);
             foreach ($shops as $shop) {
                 $shop['id_shop'] = \PrestaShift\Service\SchemaHelper::getTargetShopId();
                 $sql = SchemaHelper::buildInsertQuery('product_attribute_shop', $shop);
                 if ($sql) {
                     // Delete potential conflict first
                     Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "product_attribute_shop` WHERE id_product_attribute = $id AND id_shop = " . \PrestaShift\Service\SchemaHelper::getTargetShopId());
                     Db::getInstance()->execute($sql);
                 }
             }
         } else {
             // Fallback if no shop table (older PS?) - unlikely for 1.7
         }
    }

    private function importCombination($id_pa)
    {
        // ps_product_attribute_combination
        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}product_attribute_combination` WHERE id_product_attribute = $id_pa");
        $combs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($combs as $comb) {
             $sql = SchemaHelper::buildInsertQuery('product_attribute_combination', $comb);
             if ($sql) {
                 // REPLACE to avoid duplicates
                 $sql = str_replace('INSERT INTO', 'REPLACE INTO', $sql);
                 Db::getInstance()->execute($sql);
             }
        }
    }

    private function importStock($id_pa, $id_product)
    {
        // 1. Get quantity from Source stock_available
        $sql = "SELECT quantity FROM `{$this->prefix}stock_available` WHERE id_product_attribute = $id_pa";
        $qty = $this->db_connection->query($sql)->fetchColumn();
        
        if ($qty === false) {
             $qty = 0;
        }

        // 2. Set in Target
        \StockAvailable::setQuantity($id_product, $id_pa, (int)$qty);
    }

    private function importImages($id_pa)
    {
        // ps_product_attribute_image
        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}product_attribute_image` WHERE id_product_attribute = $id_pa");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows) {
            Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "product_attribute_image` WHERE id_product_attribute = $id_pa");
            
            foreach ($rows as $row) {
                // Ensure id_image is treated as int, though schema helper handles it
                $sql = SchemaHelper::buildInsertQuery('product_attribute_image', $row);
                if ($sql) {
                    Db::getInstance()->execute($sql);
                }
            }
        }
    }
}
