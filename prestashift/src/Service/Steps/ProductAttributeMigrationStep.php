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

class ProductAttributeMigrationStep
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
        $rows = $this->getData($offset, $limit);

        if (empty($rows)) {
            return ['count' => 0, 'finished' => true];
        }

        IdMapper::prepare('product_attribute', array_column($rows, 'id_product_attribute'));
        IdMapper::prepare('product', array_column($rows, 'id_product'));

        foreach ($rows as $row) {
            $this->importItem($row);
        }

        return ['count' => count($rows), 'finished' => false];
    }

    private function getData($offset, $limit)
    {
        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}product_attribute` ORDER BY id_product_attribute ASC LIMIT $limit OFFSET $offset");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
        $shops = $this->db_connection->query("SELECT * FROM `{$this->prefix}product_attribute_shop` WHERE id_product_attribute = $sid ORDER BY id_shop ASC")->fetchAll(PDO::FETCH_ASSOC);
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
        $combs = $this->db_connection->query("SELECT id_attribute FROM `{$this->prefix}product_attribute_combination` WHERE id_product_attribute = $sid")->fetchAll(PDO::FETCH_ASSOC);

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
        $qty = $this->db_connection->query("SELECT quantity FROM `{$this->prefix}stock_available` WHERE id_product_attribute = $sid")->fetchColumn();

        \StockAvailable::setQuantity($tidProduct, $tid, (int)$qty);
    }

    private function importImages($sid, $tid)
    {
        $rows = $this->db_connection->query("SELECT id_image FROM `{$this->prefix}product_attribute_image` WHERE id_product_attribute = $sid")->fetchAll(PDO::FETCH_ASSOC);

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
