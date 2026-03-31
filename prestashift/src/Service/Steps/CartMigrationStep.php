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

class CartMigrationStep
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
            $carts = $this->getCartsFromSource($offset, $limit, $dateFilter);
        } catch (\Exception $e) {
            return ['count' => 0, 'finished' => true];
        }

        if (empty($carts)) {
            return ['count' => 0, 'finished' => true];
        }

        foreach ($carts as $cart) {
            $this->importCart($cart);
        }

        return ['count' => count($carts), 'finished' => false];
    }

    private function getCartsFromSource($offset, $limit, $dateFilter = null)
    {
        $where = "";
        if ($dateFilter) {
            $where = " WHERE `date_add` > '{$dateFilter}' OR `date_upd` > '{$dateFilter}' ";
        }
        $sql = "SELECT * FROM `{$this->prefix}cart` {$where} ORDER BY `id_cart` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importCart($data)
    {
        $id_cart = (int)$data['id_cart'];

        // Force shop ID
        $data['id_shop'] = \PrestaShift\Service\SchemaHelper::getTargetShopId();

        $sql = SchemaHelper::buildUpsertQuery('cart', $data, ['id_cart']);
        if ($sql) {
            try {
                Db::getInstance()->execute($sql);
            } catch (\Exception $e) {
                // Log
            }
        }

        $this->importCartProducts($id_cart);
    }

    private function importCartProducts($id_cart)
    {
        try {
            $sql = "SELECT * FROM `{$this->prefix}cart_product` WHERE id_cart = $id_cart";
            $rows = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "cart_product` WHERE id_cart = $id_cart");

            foreach ($rows as $row) {
                $sql = SchemaHelper::buildInsertQuery('cart_product', $row, true);
                if ($sql) {
                    Db::getInstance()->execute($sql);
                }
            }
        } catch (\Exception $e) {
            // Table may not exist in old PS versions
        }
    }
}
