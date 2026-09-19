<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @attribution PrestaShift by Marcin Gajewski (marcingajewski.pl). Keep the Attribution Notice in prestashift.php — AFL-3.0 section 6.
 * @version   1.3.0
 */
namespace PrestaShift\Service\Steps;

use Db;
use PDO;
use PrestaShift\Service\IdMapper;

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
        // Stable order — pagination without ORDER BY may skip or repeat rows
        $sql = "SELECT * FROM `{$this->prefix}feature_product` ORDER BY id_product, id_feature, id_feature_value LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            return ['count' => 0, 'finished' => true];
        }

        IdMapper::prepare('product', array_column($items, 'id_product'));
        IdMapper::prepare('feature', array_column($items, 'id_feature'));
        IdMapper::prepare('feature_value', array_column($items, 'id_feature_value'));

        $values = [];
        foreach ($items as $item) {
            $row = IdMapper::row('feature_product', $item);
            $idFeature = (int)$row['id_feature'];
            $idProduct = (int)$row['id_product'];
            $idValue = (int)$row['id_feature_value'];
            if ($idFeature <= 0 || $idProduct <= 0 || $idValue <= 0) {
                continue;
            }
            $values[] = "($idFeature, $idProduct, $idValue)";
        }

        if (!empty($values)) {
            Db::getInstance()->execute("INSERT IGNORE INTO `" . _DB_PREFIX_ . "feature_product` (id_feature, id_product, id_feature_value) VALUES " . implode(',', $values));
        }

        return ['count' => count($items), 'finished' => false];
    }
}
