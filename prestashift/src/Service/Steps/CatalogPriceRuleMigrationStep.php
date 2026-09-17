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
use PrestaShift\Service\SchemaHelper;

/**
 * Catalog price rules (table specific_price_rule) with their conditions.
 * A rule without its conditions applies to the whole catalog, so a rule whose
 * conditions cannot all be translated is not migrated at all. The specific
 * prices the rules generate are rebuilt at the end of the migration.
 */
class CatalogPriceRuleMigrationStep
{
    private $db_connection;
    private $prefix;

    /** condition type => entity of its value */
    private static $conditionEntities = [
        'category' => 'category',
        'manufacturer' => 'manufacturer',
        'supplier' => 'supplier',
        'attribute' => 'attribute',
        'feature' => 'feature_value',
    ];

    public function __construct($db_connection, $prefix)
    {
        $this->db_connection = $db_connection;
        $this->prefix = $prefix;
    }

    public function process($offset, $limit, $dateFilter = null)
    {
        try {
            $items = $this->db_connection->query("SELECT * FROM `{$this->prefix}specific_price_rule` ORDER BY `id_specific_price_rule` ASC LIMIT $limit OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return ['count' => 0, 'finished' => true];
        }

        if (empty($items)) {
            return ['count' => 0, 'finished' => true];
        }

        foreach ($items as $item) {
            $this->importRule($item);
        }

        return ['count' => count($items), 'finished' => false];
    }

    private function importRule($data)
    {
        $sid = (int)$data['id_specific_price_rule'];

        $groups = $this->loadConditions($sid);
        if ($groups === null) {
            \PrestaShift\Service\LogService::getInstance()->warning(
                "Catalog price rule #$sid skipped: its conditions point at data that was not migrated (it would otherwise apply to every product)."
            );
            return;
        }

        $row = IdMapper::row('specific_price_rule', $data);
        foreach (['id_currency', 'id_country', 'id_group'] as $col) {
            if ((int)$data[$col] > 0 && (int)$row[$col] <= 0) {
                return; // restriction not translatable
            }
        }
        $row['id_shop'] = SchemaHelper::getTargetShopId();
        $tid = (int)$row['id_specific_price_rule'];

        SchemaHelper::upsert('specific_price_rule', $row, ['id_specific_price_rule']);

        // Replace the conditions as a whole
        $old = Db::getInstance()->executeS("SELECT id_specific_price_rule_condition_group FROM `" . _DB_PREFIX_ . "specific_price_rule_condition_group` WHERE id_specific_price_rule = $tid");
        foreach ((array)$old as $g) {
            Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "specific_price_rule_condition` WHERE id_specific_price_rule_condition_group = " . (int)$g['id_specific_price_rule_condition_group']);
        }
        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "specific_price_rule_condition_group` WHERE id_specific_price_rule = $tid");

        foreach ($groups as $sourceGroupId => $conditions) {
            $groupId = IdMapper::own('specific_price_rule_condition_group', $sourceGroupId);
            SchemaHelper::upsert('specific_price_rule_condition_group', [
                'id_specific_price_rule_condition_group' => $groupId,
                'id_specific_price_rule' => $tid,
            ], ['id_specific_price_rule_condition_group']);

            foreach ($conditions as $c) {
                SchemaHelper::upsert('specific_price_rule_condition', [
                    'id_specific_price_rule_condition' => IdMapper::own('specific_price_rule_condition', $c['id']),
                    'id_specific_price_rule_condition_group' => $groupId,
                    'type' => $c['type'],
                    'value' => $c['value'],
                ], ['id_specific_price_rule_condition']);
            }
        }
    }

    /**
     * @return array|null source group id => translated conditions; null when a
     *                    condition cannot be translated
     */
    private function loadConditions($sid)
    {
        try {
            $rows = $this->db_connection->query("SELECT g.id_specific_price_rule_condition_group AS gid, c.id_specific_price_rule_condition AS cid, c.type, c.value
                FROM `{$this->prefix}specific_price_rule_condition_group` g
                LEFT JOIN `{$this->prefix}specific_price_rule_condition` c ON c.id_specific_price_rule_condition_group = g.id_specific_price_rule_condition_group
                WHERE g.id_specific_price_rule = $sid")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return null;
        }

        $groups = [];
        foreach ($rows as $r) {
            $gid = (int)$r['gid'];
            if (!isset($groups[$gid])) {
                $groups[$gid] = [];
            }
            if ($r['cid'] === null) {
                continue;
            }
            $entity = isset(self::$conditionEntities[$r['type']]) ? self::$conditionEntities[$r['type']] : null;
            $value = $entity ? IdMapper::ref($entity, (int)$r['value']) : 0;
            if ($value <= 0) {
                return null;
            }
            $groups[$gid][] = ['id' => (int)$r['cid'], 'type' => $r['type'], 'value' => $value];
        }

        return $groups;
    }
}
