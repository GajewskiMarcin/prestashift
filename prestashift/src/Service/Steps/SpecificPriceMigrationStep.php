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

class SpecificPriceMigrationStep
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
            $this->importSpecificPrice($item);
        }

        return ['count' => count($items), 'finished' => false];
    }

    private function getData($offset, $limit, $dateFilter = null)
    {
        $where = "";
        if ($dateFilter) {
            $where = " WHERE `date_add` > '{$dateFilter}' ";
        }
        $sql = "SELECT * FROM `{$this->prefix}specific_price` {$where} ORDER BY `id_specific_price` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importSpecificPrice($data)
    {
        $id = (int)$data['id_specific_price'];
        
        // Fix Shop ID -> Force to default shop 1
        $data['id_shop'] = \PrestaShift\Service\SchemaHelper::getTargetShopId();
        $data['id_shop_group'] = 0; // usually 0 or 1
        
        // Ensure Currency fallback
        if ($data['id_currency'] == 0) {
             // 0 means all currencies, keep it.
        }
        
        // Ensure Country fallback
        if ($data['id_country'] == 0) {
             // 0 means all countries
        }

        // Clean ID
        $data['id_specific_price'] = $id;

        $sql = SchemaHelper::buildUpsertQuery('specific_price', $data, ['id_specific_price']);
        
        if ($sql) {
            try {
                Db::getInstance()->execute($sql);
            } catch (\Exception $e) {
                // Log error
            }
        }
    }
}
