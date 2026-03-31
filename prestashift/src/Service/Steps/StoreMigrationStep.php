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

class StoreMigrationStep
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
            $this->importStore($item);
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
            $sql = "SELECT * FROM `{$this->prefix}store` {$where} ORDER BY `id_store` ASC LIMIT $limit OFFSET $offset";
            $stmt = $this->db_connection->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            // Table may not exist in old PS versions
            return [];
        }
    }

    private function importStore($data)
    {
        $id = (int)$data['id_store'];

        $sql = SchemaHelper::buildUpsertQuery('store', $data, ['id_store']);

        if ($sql) {
            try {
                Db::getInstance()->execute($sql);
            } catch (\Exception $e) {
                // Log error
            }
        }

        // Lang
        $this->importStoreLang($id);

        // Shop association
        $shopData = [
            'id_store' => $id,
            'id_shop' => \PrestaShift\Service\SchemaHelper::getTargetShopId(),
        ];
        $sqlShop = SchemaHelper::buildInsertQuery('store_shop', $shopData, true);
        if ($sqlShop) {
            $sqlShop = str_replace('INSERT INTO', 'INSERT IGNORE INTO', $sqlShop);
            Db::getInstance()->execute($sqlShop);
        }
    }

    private function importStoreLang($id_store)
    {
        try {
            $sql = "SELECT * FROM `{$this->prefix}store_lang` WHERE id_store = $id_store";
            $rows = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return;
        }

        Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "store_lang` WHERE id_store = $id_store");

        foreach ($rows as $row) {
            $sqlIns = SchemaHelper::buildInsertQuery('store_lang', $row, true);
            if ($sqlIns) {
                Db::getInstance()->execute($sqlIns);
            }
        }
    }
}
