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

class CatalogPriceRuleMigrationStep
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
            $this->importCatalogPriceRule($item);
        }

        return ['count' => count($items), 'finished' => false];
    }

    private function getData($offset, $limit, $dateFilter = null)
    {
        $where = "";
        if ($dateFilter) {
            $where = " WHERE `date_add` > '{$dateFilter}' OR `date_upd` > '{$dateFilter}' ";
        }

        try {
            $sql = "SELECT * FROM `{$this->prefix}catalog_price_rule` {$where} ORDER BY `id_catalog_price_rule` ASC LIMIT $limit OFFSET $offset";
            $stmt = $this->db_connection->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            // Table may not exist in old PS versions
            return [];
        }
    }

    private function importCatalogPriceRule($data)
    {
        $id = (int)$data['id_catalog_price_rule'];

        $sql = SchemaHelper::buildUpsertQuery('catalog_price_rule', $data, ['id_catalog_price_rule']);

        if ($sql) {
            try {
                Db::getInstance()->execute($sql);

                // Shop association
                Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "catalog_price_rule_shop` WHERE id_catalog_price_rule = $id");
                Db::getInstance()->execute("INSERT INTO `" . \_DB_PREFIX_ . "catalog_price_rule_shop` (id_catalog_price_rule, id_shop) VALUES ($id, " . \PrestaShift\Service\SchemaHelper::getTargetShopId() . ")");
            } catch (\Exception $e) {
                // Log error
            }
        }
    }
}
