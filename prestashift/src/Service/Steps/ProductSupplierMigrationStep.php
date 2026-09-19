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

class ProductSupplierMigrationStep
{
    private $db_connection;
    private $prefix;

    /** @var array|null target id_supplier => true */
    private $targetSuppliers = null;

    public function __construct($db_connection, $prefix)
    {
        $this->db_connection = $db_connection;
        $this->prefix = $prefix;
    }

    public function process($offset, $limit, $dateFilter = null)
    {
        try {
            $sql = "SELECT * FROM `{$this->prefix}product_supplier` ORDER BY id_product_supplier ASC LIMIT $limit OFFSET $offset";
            $stmt = $this->db_connection->query($sql);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return ['count' => 0, 'finished' => true];
        }

        if (empty($items)) {
            return ['count' => 0, 'finished' => true];
        }

        foreach ($items as $item) {
            $this->importItem($item);
        }

        return ['count' => count($items), 'finished' => false];
    }

    /**
     * PrestaShop 1.6/1.7 CSV imports leave rows with id_supplier = 0 (a
     * supplier reference typed without a supplier). PrestaShop 8/9 throws
     * "Invalid Supplier id: 0" when opening such a product, and a TypeError
     * when a row points at a supplier that does not exist. Neither kind of row
     * is copied.
     */
    private function importItem($data)
    {
        if ((int)$data['id_supplier'] <= 0 || (int)$data['id_product'] <= 0) {
            return;
        }

        $sid = (int)$data['id_product_supplier'];
        $row = IdMapper::row('product_supplier', $data);

        if ((int)$row['id_product'] <= 0 || !$this->supplierExists((int)$row['id_supplier'])) {
            return;
        }
        if ((int)$data['id_product_attribute'] > 0 && (int)$row['id_product_attribute'] <= 0) {
            return; // combination not migrated
        }
        if ((int)$row['id_currency'] <= 0) {
            $row['id_currency'] = (int)\Configuration::get('PS_CURRENCY_DEFAULT');
        }

        // One association per product/combination/supplier: a re-run must not
        // collide with the unique key when the row id changed.
        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "product_supplier`
            WHERE id_product = " . (int)$row['id_product'] . " AND id_product_attribute = " . (int)$row['id_product_attribute'] . "
            AND id_supplier = " . (int)$row['id_supplier'] . " AND id_product_supplier <> " . (int)$row['id_product_supplier']);

        SchemaHelper::upsert('product_supplier', $row, ['id_product_supplier']);
    }

    private function supplierExists($id)
    {
        if ($this->targetSuppliers === null) {
            $this->targetSuppliers = [];
            foreach ((array)Db::getInstance()->executeS("SELECT id_supplier FROM `" . _DB_PREFIX_ . "supplier`") as $r) {
                $this->targetSuppliers[(int)$r['id_supplier']] = true;
            }
        }

        return $id > 0 && isset($this->targetSuppliers[$id]);
    }
}
