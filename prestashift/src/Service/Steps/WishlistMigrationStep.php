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
use PrestaShift\Service\SourceBatch;

class WishlistMigrationStep
{
    private $db_connection;
    private $prefix;

    /** @var array source id_wishlist => product rows, read once per batch */
    private $productRows = [];

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
        // All wishlist products of the batch in one query instead of one per list
        $this->productRows = SourceBatch::grouped($this->db_connection,
            "SELECT * FROM `{$this->prefix}wishlist_product` WHERE id_wishlist IN ({ids})",
            'id_wishlist', array_column($items, 'id_wishlist'));

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

        $rows = SourceBatch::of($this->productRows, $data['id_wishlist']);

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
