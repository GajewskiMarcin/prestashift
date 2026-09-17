<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @version   1.3.0
 */
namespace PrestaShift\Service\Steps;

use Db;
use PDO;
use PrestaShift\Service\IdMapper;
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
        if (!SchemaHelper::hasTable('wishlist')) {
            return ['count' => 0, 'finished' => true]; // wishlist module not installed in the target
        }

        try {
            $items = $this->getData($offset, $limit);
        } catch (\Exception $e) {
            return ['count' => 0, 'finished' => true];
        }

        if (empty($items)) {
            return ['count' => 0, 'finished' => true];
        }

        IdMapper::prepare('wishlist', array_column($items, 'id_wishlist'));

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
        $row = IdMapper::row('wishlist', $data);
        if ((int)$row['id_customer'] <= 0) {
            return;
        }
        $tid = (int)$row['id_wishlist'];

        try {
            SchemaHelper::upsert('wishlist', $row, ['id_wishlist']);
        } catch (\Exception $e) {
            return;
        }

        try {
            $rows = $this->db_connection->query("SELECT * FROM `{$this->prefix}wishlist_product` WHERE id_wishlist = " . (int)$data['id_wishlist'])->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return;
        }

        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "wishlist_product` WHERE id_wishlist = $tid");

        foreach ($rows as $r) {
            $trow = IdMapper::row('wishlist_product', $r);
            if ((int)$trow['id_product'] <= 0) {
                continue;
            }
            unset($trow['id_wishlist_product']); // own auto-increment key
            SchemaHelper::insertIgnore('wishlist_product', $trow);
        }
    }
}
