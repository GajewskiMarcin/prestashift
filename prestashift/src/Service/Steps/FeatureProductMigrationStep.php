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

class FeatureProductMigrationStep
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
        // This table links id_feature, id_product, id_feature_value
        $sql = "SELECT * FROM `{$this->prefix}feature_product` LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            return ['count' => 0, 'finished' => true];
        }

        // Bulk Insert Construction
        $values = [];
        foreach ($items as $item) {
            $id_feature = (int)$item['id_feature'];
            $id_product = (int)$item['id_product'];
            $id_feature_value = (int)$item['id_feature_value'];
            $values[] = "($id_feature, $id_product, $id_feature_value)";
        }

        if (!empty($values)) {
            $valuesStr = implode(',', $values);
            $query = "INSERT IGNORE INTO `" . \_DB_PREFIX_ . "feature_product` (id_feature, id_product, id_feature_value) VALUES $valuesStr";
            Db::getInstance()->execute($query);
        }

        return ['count' => count($items), 'finished' => false];
    }
    
    // importAssignment removed as it's now handled inline via bulk insert

}
