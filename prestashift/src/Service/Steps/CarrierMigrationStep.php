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

class CarrierMigrationStep
{
    private $db_connection;
    private $prefix;
    private $skip_files; // Carriers have logos too

    public function __construct($db_connection, $prefix, $skip_files = false)
    {
        $this->db_connection = $db_connection;
        $this->prefix = $prefix;
        $this->skip_files = $skip_files;
    }

    public function process($offset, $limit, $dateFilter = null)
    {
        $items = $this->getData($offset, $limit);

        if (empty($items)) {
            return ['count' => 0, 'finished' => true];
        }

        foreach ($items as $item) {
            $this->importCarrier($item);
        }

        return ['count' => count($items), 'finished' => false];
    }

    private function getData($offset, $limit)
    {
        // Migrating deleted carriers is usually not needed for new setup, but valuable for old order history.
        // We will migrate ALL to be safe.
        $sql = "SELECT * FROM `{$this->prefix}carrier` ORDER BY `id_carrier` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importCarrier($data)
    {
        $id = (int)$data['id_carrier'];
        
        // Basic Carrier Data
        $sql = SchemaHelper::buildUpsertQuery('carrier', $data, ['id_carrier']);
        if ($sql) {
            try {
                Db::getInstance()->execute($sql);
            } catch (\Exception $e) { return; }
        }

        // Lang
        $this->importCarrierLang($id);
        
        // Shops (Link to shop 1)
        Db::getInstance()->execute("REPLACE INTO `" . \_DB_PREFIX_ . "carrier_shop` (id_carrier, id_shop) VALUES ($id, " . \PrestaShift\Service\SchemaHelper::getTargetShopId() . ")");

        // Groups (Access)
        $this->importCarrierGroups($id);

        // Zones (Where it delivers)
        $this->importCarrierZones($id);

        // Ranges & Delivery Prices
        $this->importRangesAndDelivery($id, $data);
        
        // Tax Rules Group Shop (Association)
        $this->importTaxRules($id);
    }
    
    private function importCarrierLang($id) {
        $sql = "SELECT * FROM `{$this->prefix}carrier_lang` WHERE id_carrier = $id";
        $rows = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
             $row['id_shop'] = \PrestaShift\Service\SchemaHelper::getTargetShopId();
             $sql = SchemaHelper::buildInsertQuery('carrier_lang', $row);
             if($sql) {
                 $sql = str_replace('INSERT INTO', 'REPLACE INTO', $sql);
                 Db::getInstance()->execute($sql);
             }
        }
    }
    
    private function importCarrierGroups($id) {
        $sql = "SELECT * FROM `{$this->prefix}carrier_group` WHERE id_carrier = $id";
        $rows = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "carrier_group` WHERE id_carrier = $id");
        
        foreach ($rows as $row) {
             Db::getInstance()->execute("INSERT IGNORE INTO `" . \_DB_PREFIX_ . "carrier_group` (id_carrier, id_group) VALUES ($id, " . (int)$row['id_group'] . ")");
        }
    }
    
    private function importCarrierZones($id) {
         $sql = "SELECT * FROM `{$this->prefix}carrier_zone` WHERE id_carrier = $id";
         $rows = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);
         Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "carrier_zone` WHERE id_carrier = $id");
         
         foreach ($rows as $row) {
              Db::getInstance()->execute("INSERT IGNORE INTO `" . \_DB_PREFIX_ . "carrier_zone` (id_carrier, id_zone) VALUES ($id, " . (int)$row['id_zone'] . ")");
         }
    }
    
    private function importRangesAndDelivery($id_carrier, $carrierData) {
        // Range Weight
        $sqlW = "SELECT * FROM `{$this->prefix}range_weight` WHERE id_carrier = $id_carrier";
        $weights = $this->db_connection->query($sqlW)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($weights as $w) {
            $id_range = (int)$w['id_range_weight'];
            $sql = SchemaHelper::buildUpsertQuery('range_weight', $w, ['id_range_weight']);
            if($sql) Db::getInstance()->execute($sql);
            
            // Delivery Prices for this Range
             $this->importDeliveryPrices('id_range_weight', $id_range, $id_carrier);
        }

        // Range Price
        $sqlP = "SELECT * FROM `{$this->prefix}range_price` WHERE id_carrier = $id_carrier";
        $prices = $this->db_connection->query($sqlP)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($prices as $p) {
            $id_range = (int)$p['id_range_price'];
            $sql = SchemaHelper::buildUpsertQuery('range_price', $p, ['id_range_price']);
            if($sql) Db::getInstance()->execute($sql);
            
            // Delivery Prices for this Range
             $this->importDeliveryPrices('id_range_price', $id_range, $id_carrier);
        }
    }
    
    private function importDeliveryPrices($rangeCol, $rangeId, $id_carrier) {
        // fetch from source ps_delivery
        $sql = "SELECT * FROM `{$this->prefix}delivery` WHERE $rangeCol = $rangeId AND id_carrier = $id_carrier";
        $deliveries = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($deliveries as $d) {
             $d['id_shop'] = \PrestaShift\Service\SchemaHelper::getTargetShopId();
             $d['id_shop_group'] = 0;
             $sql = SchemaHelper::buildUpsertQuery('delivery', $d, ['id_delivery']);
             if($sql) {
                  try { Db::getInstance()->execute($sql); } catch(\Exception $e){}
             }
        }
    }
    
    private function importTaxRules($id_carrier) {
         // Some versions store this in ps_carrier_tax_rules_group_shop
         $table = $this->prefix . 'carrier_tax_rules_group_shop';
         // Check if table exists in source
         $check = $this->db_connection->query("SHOW TABLES LIKE '$table'")->fetch();
         if ($check) {
              $sql = "SELECT * FROM `$table` WHERE id_carrier = $id_carrier";
              $rows = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);
              foreach ($rows as $row) {
                  $row['id_shop'] = \PrestaShift\Service\SchemaHelper::getTargetShopId();
                  $sqlIns = SchemaHelper::buildInsertQuery('carrier_tax_rules_group_shop', $row);
                  if($sqlIns) {
                      $sqlIns = str_replace('INSERT INTO', 'REPLACE INTO', $sqlIns);
                      Db::getInstance()->execute($sqlIns);
                  }
              }
         }
    }
}
