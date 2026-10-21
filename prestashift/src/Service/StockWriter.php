<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcingajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @attribution PrestaShift by Marcin Gajewski (marcingajewski.pl). Keep the Attribution Notice in prestashift.php — AFL-3.0 section 6.
 * @version   1.6.0
 */
namespace PrestaShift\Service;

use Db;

/**
 * Writes the stock of a whole batch in one statement.
 *
 * The obvious way to set stock is StockAvailable::setQuantity(), and that is
 * what this module used to do. For an importer it is the wrong door:
 *
 *   - it costs about 147 ms per product (two object loads, validation and a
 *     stock movement), which is most of the time a batch of 50 products takes;
 *   - with its default arguments it records a stock movement for every product,
 *     so the new shop ends up with a "+N today" entry per product on top of the
 *     real movement history this module migrates from the source;
 *   - it fires the actionUpdateQuantity hook, so during a migration the code of
 *     every stock-related module in the target shop runs once per product. A
 *     module that throws there kills the migration, and one that queues feeds
 *     or webhooks floods them.
 *
 * The same rows are therefore written straight into ps_stock_available, in one
 * INSERT per batch, with the values PrestaShop itself would store.
 */
class StockWriter
{
    /** @var array pending "(product, combination) => quantity" of this batch */
    private static $rows = [];

    /** @var array|null target shop columns, resolved once */
    private static $shop = null;

    /**
     * Remembers the stock of one product or one combination.
     *
     * @param int $idProduct   target product id
     * @param int $idAttribute target combination id, 0 for the product itself
     * @param int $quantity
     */
    public static function set($idProduct, $idAttribute, $quantity)
    {
        $idProduct = (int) $idProduct;
        if ($idProduct <= 0) {
            return;
        }
        self::$rows[$idProduct . ':' . (int) $idAttribute] = [
            'id_product' => $idProduct,
            'id_product_attribute' => (int) $idAttribute,
            'quantity' => (int) $quantity,
        ];
    }

    /**
     * Writes everything remembered so far. Call it once per batch.
     */
    public static function flush()
    {
        if (empty(self::$rows)) {
            return;
        }

        list($idShop, $idShopGroup) = self::shop();
        $values = [];
        foreach (self::$rows as $row) {
            // out_of_stock = 2 means "use the shop's global setting", which is
            // what this module has always stored instead of copying the source's
            // per-product override.
            $values[] = '(' . $row['id_product'] . ', ' . $row['id_product_attribute'] . ', '
                . $idShop . ', ' . $idShopGroup . ', ' . $row['quantity'] . ', 0, 0, 0, 2)';
        }
        self::$rows = [];

        foreach (array_chunk($values, 500) as $chunk) {
            Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'stock_available`
                (`id_product`, `id_product_attribute`, `id_shop`, `id_shop_group`, `quantity`,
                 `physical_quantity`, `reserved_quantity`, `depends_on_stock`, `out_of_stock`)
                VALUES ' . implode(',', $chunk) . '
                ON DUPLICATE KEY UPDATE `quantity` = VALUES(`quantity`), `out_of_stock` = VALUES(`out_of_stock`)');
        }
    }

    /**
     * Which shop the rows belong to. A shop group that shares its stock keeps
     * one row for the whole group (id_shop 0), exactly as PrestaShop does.
     */
    private static function shop()
    {
        if (self::$shop !== null) {
            return self::$shop;
        }

        $idShop = SchemaHelper::getTargetShopId();
        $idShopGroup = 0;
        try {
            $group = new \ShopGroup((int) \Shop::getGroupFromShop($idShop));
            if ($group->id && $group->share_stock) {
                $idShopGroup = (int) $group->id;
                $idShop = 0;
            }
        } catch (\Exception $e) {
            // single shop: the defaults above are right
        }

        return self::$shop = [$idShop, $idShopGroup];
    }
}
