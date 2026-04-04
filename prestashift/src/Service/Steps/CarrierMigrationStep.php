<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @version   1.1.0
 */
namespace PrestaShift\Service\Steps;

use Db;
use PDO;
use PrestaShift\Service\SchemaHelper;

class CarrierMigrationStep
{
    private $db_connection;
    private $prefix;
    private $skip_files;
    private $zoneMap = [];
    private $userZoneMap = [];

    public function __construct($db_connection, $prefix, $skip_files = false, $zoneMap = [])
    {
        $this->db_connection = $db_connection;
        $this->prefix = $prefix;
        $this->skip_files = $skip_files;
        // User-defined zone map from UI (source_id => target_id)
        $this->userZoneMap = $zoneMap;
    }

    public function process($offset, $limit, $dateFilter = null)
    {
        // Build zone map on every batch (object is recreated per AJAX request)
        $this->buildZoneMap();

        $items = $this->getData($offset, $limit);

        if (empty($items)) {
            return ['count' => 0, 'finished' => true];
        }

        foreach ($items as $item) {
            $this->importCarrier($item);
        }

        return ['count' => count($items), 'finished' => false];
    }

    /**
     * Build a map of source zone IDs → target zone IDs
     * Priority: user-defined map from UI > auto-match by name
     */
    private function buildZoneMap()
    {
        // 1. Apply user-defined mappings first (from UI zone_map selects)
        foreach ($this->userZoneMap as $srcId => $tgtId) {
            $tgtId = (int)$tgtId;
            if ($tgtId > 0) {
                $this->zoneMap[(int)$srcId] = $tgtId;
            }
            // If tgtId = 0 or empty → user chose "skip", leave unmapped
        }

        // 2. Auto-match remaining unmapped zones by name
        try {
            $sourceZones = $this->db_connection->query(
                "SELECT id_zone, name FROM `{$this->prefix}zone`"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return;
        }

        $targetZones = Db::getInstance()->executeS(
            "SELECT id_zone, name FROM `" . _DB_PREFIX_ . "zone`"
        );

        $targetByName = [];
        foreach ($targetZones as $tz) {
            $targetByName[strtolower(trim($tz['name']))] = (int)$tz['id_zone'];
        }

        foreach ($sourceZones as $sz) {
            $srcId = (int)$sz['id_zone'];
            // Skip if already mapped by user
            if (isset($this->zoneMap[$srcId])) {
                continue;
            }
            $srcName = strtolower(trim($sz['name']));
            if (isset($targetByName[$srcName])) {
                $this->zoneMap[$srcId] = $targetByName[$srcName];
            }
        }
    }

    /**
     * Map a source zone ID to target zone ID, returns null if no mapping exists
     */
    private function mapZoneId($sourceZoneId)
    {
        return isset($this->zoneMap[(int)$sourceZoneId]) ? $this->zoneMap[(int)$sourceZoneId] : null;
    }

    private function getData($offset, $limit)
    {
        // Only active, non-deleted carriers
        $sql = "SELECT * FROM `{$this->prefix}carrier`
                WHERE active = 1 AND deleted = 0
                ORDER BY `id_carrier` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importCarrier($data)
    {
        $oldId = (int)$data['id_carrier'];

        // Remove id_carrier — let PS9 assign new auto-increment ID
        unset($data['id_carrier']);

        // Clean up fields
        $data['deleted'] = 0;
        $data['active'] = 1;

        // Insert carrier with new ID
        $sql = SchemaHelper::buildInsertQuery('carrier', $data);
        if (!$sql) return;

        try {
            Db::getInstance()->execute($sql);
        } catch (\Exception $e) {
            return;
        }

        $newId = (int)Db::getInstance()->getValue("SELECT LAST_INSERT_ID()");
        if (!$newId) return;

        // Lang
        $this->importCarrierLang($oldId, $newId);

        // Shop
        $shopId = SchemaHelper::getTargetShopId();
        Db::getInstance()->execute(
            "REPLACE INTO `" . _DB_PREFIX_ . "carrier_shop` (id_carrier, id_shop) VALUES ($newId, $shopId)"
        );

        // Groups
        $this->importCarrierGroups($oldId, $newId);

        // Zones (with mapping)
        $this->importCarrierZones($oldId, $newId);

        // Ranges & Delivery
        $this->importRangesAndDelivery($oldId, $newId);

        // Tax Rules
        $this->importTaxRules($oldId, $newId);
    }

    private function importCarrierLang($oldId, $newId)
    {
        $rows = $this->db_connection->query(
            "SELECT * FROM `{$this->prefix}carrier_lang` WHERE id_carrier = $oldId"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $row['id_carrier'] = $newId;
            $row['id_shop'] = SchemaHelper::getTargetShopId();
            $sql = SchemaHelper::buildInsertQuery('carrier_lang', $row);
            if ($sql) {
                $sql = str_replace('INSERT INTO', 'REPLACE INTO', $sql);
                Db::getInstance()->execute($sql);
            }
        }
    }

    private function importCarrierGroups($oldId, $newId)
    {
        $rows = $this->db_connection->query(
            "SELECT * FROM `{$this->prefix}carrier_group` WHERE id_carrier = $oldId"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            Db::getInstance()->execute(
                "INSERT IGNORE INTO `" . _DB_PREFIX_ . "carrier_group` (id_carrier, id_group)
                 VALUES ($newId, " . (int)$row['id_group'] . ")"
            );
        }
    }

    private function importCarrierZones($oldId, $newId)
    {
        $rows = $this->db_connection->query(
            "SELECT * FROM `{$this->prefix}carrier_zone` WHERE id_carrier = $oldId"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $mappedZone = $this->mapZoneId($row['id_zone']);
            if ($mappedZone === null) {
                continue; // Zone doesn't exist in target — skip
            }

            Db::getInstance()->execute(
                "INSERT IGNORE INTO `" . _DB_PREFIX_ . "carrier_zone` (id_carrier, id_zone)
                 VALUES ($newId, $mappedZone)"
            );
        }
    }

    private function importRangesAndDelivery($oldId, $newId)
    {
        $shopId = SchemaHelper::getTargetShopId();

        // Get shop group from target
        $shopGroupId = (int)Db::getInstance()->getValue(
            "SELECT id_shop_group FROM `" . _DB_PREFIX_ . "shop` WHERE id_shop = $shopId"
        );

        // Range Weight
        $weights = $this->db_connection->query(
            "SELECT * FROM `{$this->prefix}range_weight` WHERE id_carrier = $oldId"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($weights as $w) {
            $oldRangeId = (int)$w['id_range_weight'];
            unset($w['id_range_weight']); // new auto-increment
            $w['id_carrier'] = $newId;

            $sql = SchemaHelper::buildInsertQuery('range_weight', $w);
            if ($sql) {
                Db::getInstance()->execute($sql);
                $newRangeId = (int)Db::getInstance()->getValue("SELECT LAST_INSERT_ID()");

                // Delivery prices for this weight range
                $this->importDelivery($oldId, $newId, 'id_range_weight', $oldRangeId, $newRangeId, $shopId, $shopGroupId);
            }
        }

        // Range Price
        $prices = $this->db_connection->query(
            "SELECT * FROM `{$this->prefix}range_price` WHERE id_carrier = $oldId"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($prices as $p) {
            $oldRangeId = (int)$p['id_range_price'];
            unset($p['id_range_price']); // new auto-increment
            $p['id_carrier'] = $newId;

            $sql = SchemaHelper::buildInsertQuery('range_price', $p);
            if ($sql) {
                Db::getInstance()->execute($sql);
                $newRangeId = (int)Db::getInstance()->getValue("SELECT LAST_INSERT_ID()");

                // Delivery prices for this price range
                $this->importDelivery($oldId, $newId, 'id_range_price', $oldRangeId, $newRangeId, $shopId, $shopGroupId);
            }
        }
    }

    private function importDelivery($oldCarrierId, $newCarrierId, $rangeCol, $oldRangeId, $newRangeId, $shopId, $shopGroupId)
    {
        $deliveries = $this->db_connection->query(
            "SELECT * FROM `{$this->prefix}delivery`
             WHERE id_carrier = $oldCarrierId AND $rangeCol = $oldRangeId"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($deliveries as $d) {
            $mappedZone = $this->mapZoneId($d['id_zone']);
            if ($mappedZone === null) {
                continue; // Zone not mapped — skip
            }

            unset($d['id_delivery']); // new auto-increment
            $d['id_carrier'] = $newCarrierId;
            $d['id_zone'] = $mappedZone;
            $d['id_shop'] = $shopId;
            $d['id_shop_group'] = $shopGroupId;

            // Set the correct range ID
            if ($rangeCol === 'id_range_weight') {
                $d['id_range_weight'] = $newRangeId;
                $d['id_range_price'] = 0;
            } else {
                $d['id_range_price'] = $newRangeId;
                $d['id_range_weight'] = 0;
            }

            $sql = SchemaHelper::buildInsertQuery('delivery', $d);
            if ($sql) {
                try {
                    Db::getInstance()->execute($sql);
                } catch (\Exception $e) {}
            }
        }
    }

    private function importTaxRules($oldId, $newId)
    {
        $table = $this->prefix . 'carrier_tax_rules_group_shop';
        try {
            $check = $this->db_connection->query("SHOW TABLES LIKE '$table'")->fetch();
            if (!$check) return;

            $rows = $this->db_connection->query(
                "SELECT * FROM `$table` WHERE id_carrier = $oldId"
            )->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $row['id_carrier'] = $newId;
                $row['id_shop'] = SchemaHelper::getTargetShopId();
                $sql = SchemaHelper::buildInsertQuery('carrier_tax_rules_group_shop', $row);
                if ($sql) {
                    $sql = str_replace('INSERT INTO', 'REPLACE INTO', $sql);
                    Db::getInstance()->execute($sql);
                }
            }
        } catch (\Exception $e) {}
    }
}
