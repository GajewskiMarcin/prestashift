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
 * Carriers.
 *
 * PrestaShop creates a new carrier version (new id, same id_reference) every
 * time a carrier used by orders is edited, and hides the old one. Old orders
 * point at old versions, so every version is migrated:
 *   - old (deleted) versions: hidden, only name/shop — enough for order history
 *   - current carriers configured by hand: as in the source, with ranges,
 *     prices, zones, groups and taxes
 *   - current carriers of shipping modules (InPost, DPD…): inactive — they only
 *     work together with their module, which creates its own carrier when
 *     installed in the new shop
 * Product → carrier restrictions are migrated afterwards, only towards active
 * carriers, so a product never ends up restricted to a carrier nobody can pick.
 */
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
        $this->userZoneMap = is_array($zoneMap) ? $zoneMap : [];
    }

    public function process($offset, $limit, $dateFilter = null)
    {
        $this->buildZoneMap();

        $items = $this->getData($offset, $limit);

        if (empty($items)) {
            $this->importProductCarriers();
            return ['count' => 0, 'finished' => true];
        }

        IdMapper::prepare('carrier', array_column($items, 'id_carrier'));

        foreach ($items as $item) {
            $this->importCarrier($item);
        }

        return ['count' => count($items), 'finished' => false];
    }

    /**
     * Source zone → target zone. User map from the UI first, then by name.
     */
    private function buildZoneMap()
    {
        foreach ($this->userZoneMap as $srcId => $tgtId) {
            if ((int)$tgtId > 0) {
                $this->zoneMap[(int)$srcId] = (int)$tgtId;
            }
        }

        try {
            $sourceZones = $this->db_connection->query("SELECT id_zone, name FROM `{$this->prefix}zone`")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return;
        }

        $targetByName = [];
        foreach ((array)Db::getInstance()->executeS("SELECT id_zone, name FROM `" . _DB_PREFIX_ . "zone`") as $tz) {
            $targetByName[strtolower(trim($tz['name']))] = (int)$tz['id_zone'];
        }

        foreach ($sourceZones as $sz) {
            $srcId = (int)$sz['id_zone'];
            if (!isset($this->zoneMap[$srcId]) && isset($targetByName[strtolower(trim($sz['name']))])) {
                $this->zoneMap[$srcId] = $targetByName[strtolower(trim($sz['name']))];
            }
        }
    }

    private function mapZoneId($sourceZoneId)
    {
        return isset($this->zoneMap[(int)$sourceZoneId]) ? $this->zoneMap[(int)$sourceZoneId] : null;
    }

    private function getData($offset, $limit)
    {
        $sql = "SELECT * FROM `{$this->prefix}carrier` ORDER BY `id_carrier` ASC LIMIT $limit OFFSET $offset";
        return $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function isModuleCarrier(array $data)
    {
        return !empty($data['is_module']) || (isset($data['external_module_name']) && trim((string)$data['external_module_name']) !== '');
    }

    private function importCarrier($data)
    {
        $sid = (int)$data['id_carrier'];
        $deleted = !empty($data['deleted']);

        $row = IdMapper::row('carrier', $data);
        $tid = (int)$row['id_carrier'];
        if ((int)$row['id_reference'] <= 0) {
            $row['id_reference'] = $tid;
        }

        if ($deleted) {
            $row['deleted'] = 1;
            $row['active'] = 0;
        } elseif ($this->isModuleCarrier($data)) {
            $row['active'] = 0;
        }

        if (!SchemaHelper::upsert('carrier', $row, ['id_carrier'])) {
            return;
        }

        $this->importCarrierLang($sid, $tid);
        Db::getInstance()->execute("REPLACE INTO `" . _DB_PREFIX_ . "carrier_shop` (id_carrier, id_shop) VALUES ($tid, " . SchemaHelper::getTargetShopId() . ")");

        if ($deleted) {
            return; // history only
        }

        $this->importCarrierGroups($sid, $tid);
        $this->importCarrierZones($sid, $tid);
        $this->importRangesAndDelivery($sid, $tid);
        $this->importTaxRules($sid, $tid);
    }

    private function importCarrierLang($sid, $tid)
    {
        $shopId = SchemaHelper::getTargetShopId();
        $rows = LanguageMapper::expand($this->db_connection->query("SELECT * FROM `{$this->prefix}carrier_lang` WHERE id_carrier = $sid ORDER BY id_shop ASC")->fetchAll(PDO::FETCH_ASSOC));

        $done = [];
        foreach ($rows as $row) {
            $idLang = (int)$row['id_lang'];
            if (isset($done[$idLang])) {
                continue;
            }
            $done[$idLang] = true;
            $row['id_carrier'] = $tid;
            $row['id_shop'] = $shopId;
            SchemaHelper::upsert('carrier_lang', $row, ['id_carrier', 'id_shop', 'id_lang']);
        }
    }

    private function importCarrierGroups($sid, $tid)
    {
        $groups = [];
        foreach ($this->db_connection->query("SELECT id_group FROM `{$this->prefix}carrier_group` WHERE id_carrier = $sid")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $gid = IdMapper::ref('group', (int)$row['id_group']);
            if ($gid > 0) {
                $groups[$gid] = $gid;
            }
        }
        // Customer groups not migrated: open the carrier to the built-in groups
        // rather than to nobody
        if (empty($groups)) {
            foreach (['PS_UNIDENTIFIED_GROUP', 'PS_GUEST_GROUP', 'PS_CUSTOMER_GROUP'] as $key) {
                $gid = (int)\Configuration::get($key);
                if ($gid > 0) {
                    $groups[$gid] = $gid;
                }
            }
        }

        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "carrier_group` WHERE id_carrier = $tid");
        foreach ($groups as $gid) {
            Db::getInstance()->execute("INSERT IGNORE INTO `" . _DB_PREFIX_ . "carrier_group` (id_carrier, id_group) VALUES ($tid, $gid)");
        }
    }

    private function importCarrierZones($sid, $tid)
    {
        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "carrier_zone` WHERE id_carrier = $tid");

        foreach ($this->db_connection->query("SELECT id_zone FROM `{$this->prefix}carrier_zone` WHERE id_carrier = $sid")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $zone = $this->mapZoneId($row['id_zone']);
            if ($zone !== null) {
                Db::getInstance()->execute("INSERT IGNORE INTO `" . _DB_PREFIX_ . "carrier_zone` (id_carrier, id_zone) VALUES ($tid, $zone)");
            }
        }
    }

    private function importRangesAndDelivery($sid, $tid)
    {
        // Price table as a whole: rebuilt on every run
        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "delivery` WHERE id_carrier = $tid");
        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "range_weight` WHERE id_carrier = $tid");
        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "range_price` WHERE id_carrier = $tid");

        foreach (['range_weight', 'range_price'] as $table) {
            $ranges = $this->db_connection->query("SELECT * FROM `{$this->prefix}$table` WHERE id_carrier = $sid")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($ranges as $range) {
                $trow = IdMapper::row($table, $range);
                if (!SchemaHelper::upsert($table, $trow, ['id_' . $table])) {
                    continue;
                }
                $this->importDelivery($sid, $tid, 'id_' . $table, (int)$range['id_' . $table], (int)$trow['id_' . $table]);
            }
        }
    }

    private function importDelivery($sid, $tid, $rangeCol, $sidRange, $tidRange)
    {
        $log = LogService::getInstance();
        try {
            $deliveries = $this->db_connection->query("SELECT * FROM `{$this->prefix}delivery` WHERE id_carrier = $sid AND $rangeCol = $sidRange")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            $log->error("Carrier delivery fetch failed: carrier=$sid, $rangeCol=$sidRange: " . $e->getMessage());
            return;
        }

        $seen = [];
        foreach ($deliveries as $d) {
            $zone = $this->mapZoneId((int)$d['id_zone']);
            if ($zone === null) {
                $log->warning("Carrier delivery skipped: zone {$d['id_zone']} has no mapping");
                continue;
            }
            // Multistore sources repeat each price per shop — keep one per zone
            if (isset($seen[$zone])) {
                continue;
            }
            $seen[$zone] = true;

            $row = [
                'id_delivery' => IdMapper::own('delivery', (int)$d['id_delivery']),
                'id_carrier' => $tid,
                'id_zone' => $zone,
                // PS9 CQRS requires id_shop and id_shop_group to be NULL
                // (CarrierRangeRepository::applyShopConstraint())
                'id_shop' => null,
                'id_shop_group' => null,
                'id_range_weight' => $rangeCol === 'id_range_weight' ? $tidRange : 0,
                'id_range_price' => $rangeCol === 'id_range_price' ? $tidRange : 0,
                'price' => $d['price'],
            ];
            SchemaHelper::upsert('delivery', $row, ['id_delivery']);
        }
    }

    private function importTaxRules($sid, $tid)
    {
        try {
            $rows = $this->db_connection->query("SELECT * FROM `{$this->prefix}carrier_tax_rules_group_shop` WHERE id_carrier = $sid ORDER BY id_shop ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return; // table absent in the source version
        }

        $shopId = SchemaHelper::getTargetShopId();
        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "carrier_tax_rules_group_shop` WHERE id_carrier = $tid AND id_shop = $shopId");

        foreach ($rows as $row) {
            $group = IdMapper::ref('tax_rules_group', (int)$row['id_tax_rules_group']);
            if ($group > 0) {
                SchemaHelper::insertIgnore('carrier_tax_rules_group_shop', [
                    'id_carrier' => $tid,
                    'id_tax_rules_group' => $group,
                    'id_shop' => $shopId,
                ]);
                break;
            }
        }
    }

    /**
     * Product → carrier restrictions (by carrier reference). Written only for
     * products migrated and carriers active in the target.
     */
    private function importProductCarriers()
    {
        if (!SchemaHelper::hasTable('product_carrier')) {
            return;
        }

        $activeReferences = [];
        foreach ((array)Db::getInstance()->executeS("SELECT DISTINCT id_reference FROM `" . _DB_PREFIX_ . "carrier` WHERE deleted = 0 AND active = 1") as $r) {
            $activeReferences[(int)$r['id_reference']] = true;
        }

        $shopId = SchemaHelper::getTargetShopId();
        $offset = 0;
        $chunk = 1000;
        $cleared = [];

        do {
            try {
                $rows = $this->db_connection->query("SELECT id_product, id_carrier_reference FROM `{$this->prefix}product_carrier`
                    ORDER BY id_product, id_carrier_reference LIMIT $chunk OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Exception $e) {
                return;
            }

            foreach ($rows as $r) {
                $product = IdMapper::find('product', (int)$r['id_product']);
                if ($product <= 0) {
                    continue;
                }
                if (!isset($cleared[$product])) {
                    Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "product_carrier` WHERE id_product = $product AND id_shop = $shopId");
                    $cleared[$product] = true;
                }
                $reference = IdMapper::find('carrier', (int)$r['id_carrier_reference']);
                if ($reference > 0 && isset($activeReferences[$reference])) {
                    SchemaHelper::insertIgnore('product_carrier', [
                        'id_product' => $product,
                        'id_carrier_reference' => $reference,
                        'id_shop' => $shopId,
                    ]);
                }
            }

            $offset += $chunk;
        } while (count($rows) === $chunk);
    }
}
