<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcingajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @attribution PrestaShift by Marcin Gajewski (marcingajewski.pl). Keep the Attribution Notice in prestashift.php — AFL-3.0 section 6.
 * @version   1.3.0
 */
namespace PrestaShift\Service\Steps;

use Db;
use PDO;
use PrestaShift\Service\IdMapper;
use PrestaShift\Service\SchemaHelper;

class PackMigrationStep
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
        try {
            $sql = "SELECT * FROM `{$this->prefix}pack` ORDER BY id_product_pack ASC, id_product_item ASC, id_product_attribute_item ASC LIMIT $limit OFFSET $offset";
            $stmt = $this->db_connection->query($sql);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return ['count' => 0, 'finished' => true];
        }

        if (empty($items)) {
            return ['count' => 0, 'finished' => true];
        }

        foreach ($items as $item) {
            $row = IdMapper::row('pack', $item);
            if ((int)$row['id_product_pack'] <= 0 || (int)$row['id_product_item'] <= 0) {
                continue;
            }
            if (isset($item['id_product_attribute_item']) && (int)$item['id_product_attribute_item'] > 0 && (int)$row['id_product_attribute_item'] <= 0) {
                continue; // combination of the item not migrated
            }
            // Quantity may have changed since the last run
            SchemaHelper::upsert('pack', $row, ['id_product_pack', 'id_product_item', 'id_product_attribute_item']);
        }

        return ['count' => count($items), 'finished' => false];
    }
}
