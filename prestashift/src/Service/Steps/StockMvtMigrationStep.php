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
use PrestaShift\Service\SchemaHelper;

class StockMvtMigrationStep
{
    private $db_connection;
    private $prefix;

    /** @var array source id_stock_available => target id_stock_available (0 = none) */
    private $stockMap = [];

    public function __construct($db_connection, $prefix)
    {
        $this->db_connection = $db_connection;
        $this->prefix = $prefix;
    }

    public function process($offset, $limit, $dateFilter = null)
    {
        try {
            $items = $this->getData($offset, $limit);
        } catch (\Exception $e) {
            return ['count' => 0, 'finished' => true];
        }

        if (empty($items)) {
            return ['count' => 0, 'finished' => true];
        }

        $this->loadStockMap(array_column($items, 'id_stock'));
        IdMapper::prepare('stock_mvt', array_column($items, 'id_stock_mvt'));

        foreach ($items as $item) {
            $this->importStockMvt($item);
        }

        return ['count' => count($items), 'finished' => false];
    }

    private function getData($offset, $limit)
    {
        $sql = "SELECT * FROM `{$this->prefix}stock_mvt` ORDER BY `id_stock_mvt` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * stock_mvt.id_stock points at stock_available, whose ids are created by
     * the target itself — resolve through product + combination.
     */
    private function loadStockMap(array $ids)
    {
        $this->stockMap = [];
        $ids = array_filter(array_map('intval', $ids));
        if (empty($ids)) {
            return;
        }

        $rows = $this->db_connection->query("SELECT id_stock_available, id_product, id_product_attribute FROM `{$this->prefix}stock_available`
            WHERE id_stock_available IN (" . implode(',', array_unique($ids)) . ")")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $product = IdMapper::find('product', (int)$r['id_product']);
            $combination = (int)$r['id_product_attribute'] > 0 ? IdMapper::find('product_attribute', (int)$r['id_product_attribute']) : 0;
            $target = 0;
            if ($product > 0 && ((int)$r['id_product_attribute'] === 0 || $combination > 0)) {
                $target = (int)Db::getInstance()->getValue("SELECT id_stock_available FROM `" . _DB_PREFIX_ . "stock_available`
                    WHERE id_product = $product AND id_product_attribute = $combination AND id_shop = " . (int)SchemaHelper::getTargetShopId(), false);
            }
            $this->stockMap[(int)$r['id_stock_available']] = $target;
        }
    }

    private function importStockMvt($data)
    {
        $stock = isset($this->stockMap[(int)$data['id_stock']]) ? $this->stockMap[(int)$data['id_stock']] : 0;
        if ($stock <= 0) {
            return; // product not migrated
        }

        $row = IdMapper::row('stock_mvt', $data);
        $row['id_stock'] = $stock;
        $row['id_supply_order'] = 0;

        SchemaHelper::upsert('stock_mvt', $row, ['id_stock_mvt']);
    }
}
