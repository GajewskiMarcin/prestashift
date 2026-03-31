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

class WishlistMigrationStep
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
            $items = $this->getData($offset, $limit);
        } catch (\Exception $e) {
            return ['count' => 0, 'finished' => true];
        }

        if (empty($items)) {
            return ['count' => 0, 'finished' => true];
        }

        foreach ($items as $item) {
            $this->importWishlist($item);
        }

        return ['count' => count($items), 'finished' => false];
    }

    private function getData($offset, $limit)
    {
        $sql = "SELECT * FROM `{$this->prefix}wishlist` ORDER BY `id_wishlist` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importWishlist($data)
    {
        $id = (int)$data['id_wishlist'];

        // Force shop ID if field exists
        if (array_key_exists('id_shop', $data)) {
            $data['id_shop'] = \PrestaShift\Service\SchemaHelper::getTargetShopId();
        }

        $sql = SchemaHelper::buildUpsertQuery('wishlist', $data, ['id_wishlist']);

        if ($sql) {
            try {
                Db::getInstance()->execute($sql);
            } catch (\Exception $e) {
                // Log error
            }
        }

        // Wishlist products
        $this->importWishlistProducts($id);
    }

    private function importWishlistProducts($id_wishlist)
    {
        try {
            $sql = "SELECT * FROM `{$this->prefix}wishlist_product` WHERE id_wishlist = $id_wishlist";
            $rows = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return;
        }

        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "wishlist_product` WHERE id_wishlist = $id_wishlist");

        foreach ($rows as $row) {
            $sqlIns = SchemaHelper::buildInsertQuery('wishlist_product', $row, true);
            if ($sqlIns) {
                Db::getInstance()->execute($sqlIns);
            }
        }
    }
}
