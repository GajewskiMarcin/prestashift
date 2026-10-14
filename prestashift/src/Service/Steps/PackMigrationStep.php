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
            // PrestaShop 1.5 packs have no combination column: sorting on it failed the query, and every pack was lost
            $withCombination = (bool)$this->db_connection->query("SHOW COLUMNS FROM `{$this->prefix}pack` LIKE 'id_product_attribute_item'")->fetchAll(PDO::FETCH_ASSOC);
            $order = 'id_product_pack ASC, id_product_item ASC' . ($withCombination ? ', id_product_attribute_item ASC' : '');
            $sql = "SELECT * FROM `{$this->prefix}pack` ORDER BY $order LIMIT $limit OFFSET $offset";
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
            if (!isset($row['id_product_attribute_item'])) {
                $row['id_product_attribute_item'] = 0;
            }
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
