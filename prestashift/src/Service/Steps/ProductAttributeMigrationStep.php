<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcingajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * Modifié par : Thierry Laval <contact@thierrylaval.dev>
 * @copyright 2026 Thierry Laval
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @attribution PrestaShift by Marcin Gajewski (marcingajewski.pl). Keep the Attribution Notice in prestashift.php — AFL-3.0 section 6.
 * @version   1.3.0
 */
namespace PrestaShift\Service\Steps;

use Db;
use PDO;
use PrestaShift\Service\IdMapper;
use PrestaShift\Service\SchemaHelper;
use PrestaShift\Service\StockWriter;
use PrestaShift\Service\SourceBatch;

class ProductAttributeMigrationStep
{
    private $db_connection;
    private $prefix;

    /** @var array source id_product_attribute => rows, each read once per batch */
    private $shopRows = [];
    private $attributeRows = [];
    private $stockRows = [];
    private $imageRows = [];

    public function __construct($db_connection, $prefix)
    {
        $this->db_connection = $db_connection;
        $this->prefix = $prefix;
    }

    public function process($offset, $limit, $dateFilter = null)
    {
        $rows = $this->getData($offset, $limit);

        if (empty($rows)) {
            return ['count' => 0, 'finished' => true];
        }

        IdMapper::prepare('product_attribute', array_column($rows, 'id_product_attribute'));
        IdMapper::prepare('product', array_column($rows, 'id_product'));
        $this->loadChildren(array_column($rows, 'id_product_attribute'));

        foreach ($rows as $row) {
            $this->importItem($row);
        }

        // The stock of the whole batch in one statement
        StockWriter::flush();

        return ['count' => count($rows), 'finished' => false];
    }

    private function getData($offset, $limit)
    {
        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}product_attribute` ORDER BY id_product_attribute ASC LIMIT $limit OFFSET $offset");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Shop rows, attribute values, stock and images of the whole batch: four
     * queries instead of four per combination.
     */
    private function loadChildren(array $ids)
    {
        $this->shopRows = SourceBatch::grouped($this->db_connection,
            "SELECT * FROM `{$this->prefix}product_attribute_shop` WHERE id_product_attribute IN ({ids}) ORDER BY id_shop ASC",
            'id_product_attribute', $ids);
        $this->attributeRows = SourceBatch::grouped($this->db_connection,
            "SELECT id_product_attribute, id_attribute FROM `{$this->prefix}product_attribute_combination` WHERE id_product_attribute IN ({ids})",
            'id_product_attribute', $ids);
        $this->stockRows = SourceBatch::grouped($this->db_connection,
            "SELECT id_product_attribute, quantity FROM `{$this->prefix}stock_available` WHERE id_product_attribute IN ({ids})",
            'id_product_attribute', $ids);
        $this->imageRows = SourceBatch::grouped($this->db_connection,
            "SELECT id_product_attribute, id_image FROM `{$this->prefix}product_attribute_image` WHERE id_product_attribute IN ({ids})",
            'id_product_attribute', $ids);
    }

    private function importItem($data)
    {
        $sid = (int)$data['id_product_attribute'];
        $row = IdMapper::row('product_attribute', $data);
        $tid = (int)$row['id_product_attribute'];
        $tidProduct = (int)$row['id_product'];

        if ($tidProduct <= 0) {
            return; // combination of a product that does not exist
        }

        SchemaHelper::upsert('product_attribute', $row, ['id_product_attribute']);

        $this->importShop($sid, $tid);
        $this->importCombination($sid, $tid);
        $this->importStock($sid, $tidProduct, $tid);
        $this->importImages($sid, $tid);
    }

    private function importShop($sid, $tid)
    {
        $shopId = SchemaHelper::getTargetShopId();
        $shops = SourceBatch::of($this->shopRows, $sid);
        if (empty($shops)) {
            return;
        }

        // One row for the target shop (the source may be multistore)
        $shop = IdMapper::row('product_attribute_shop', $shops[0]);
        $shop['id_product_attribute'] = $tid;
        $shop['id_shop'] = $shopId;

        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "product_attribute_shop` WHERE id_product_attribute = $tid AND id_shop = $shopId");
        SchemaHelper::insertIgnore('product_attribute_shop', $shop);
    }

    private function importCombination($sid, $tid)
    {
        $combs = SourceBatch::of($this->attributeRows, $sid);

        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "product_attribute_combination` WHERE id_product_attribute = $tid");

        $values = [];
        foreach ($combs as $comb) {
            $idAttribute = IdMapper::ref('attribute', (int)$comb['id_attribute']);
            if ($idAttribute > 0) {
                $values[$idAttribute] = "($idAttribute, $tid)";
            }
        }
        if ($values) {
            Db::getInstance()->execute("INSERT IGNORE INTO `" . _DB_PREFIX_ . "product_attribute_combination` (id_attribute, id_product_attribute) VALUES " . implode(',', $values));
        }
    }

    private function importStock($sid, $tidProduct, $tid)
    {
        $stock = SourceBatch::of($this->stockRows, $sid);
        $qty = isset($stock[0]['quantity']) ? (int)$stock[0]['quantity'] : 0;

        StockWriter::set($tidProduct, $tid, $qty);
    }

    private function importImages($sid, $tid)
    {
        $rows = SourceBatch::of($this->imageRows, $sid);

        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "product_attribute_image` WHERE id_product_attribute = $tid");

        $values = [];
        foreach ($rows as $row) {
            $idImage = IdMapper::ref('image', (int)$row['id_image']);
            if ($idImage > 0) {
                $values[$idImage] = "($tid, $idImage)";
            }
        }
        if ($values) {
            Db::getInstance()->execute("INSERT IGNORE INTO `" . _DB_PREFIX_ . "product_attribute_image` (id_product_attribute, id_image) VALUES " . implode(',', $values));
        }
    }
}
