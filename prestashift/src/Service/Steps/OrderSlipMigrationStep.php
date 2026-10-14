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

class OrderSlipMigrationStep
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
            $slips = $this->getSlipsFromSource($offset, $limit, $dateFilter);
        } catch (\Exception $e) {
            return ['count' => 0, 'finished' => true];
        }

        if (empty($slips)) {
            return ['count' => 0, 'finished' => true];
        }

        IdMapper::prepare('order_slip', array_column($slips, 'id_order_slip'));

        foreach ($slips as $slip) {
            $this->importSlip($slip);
        }

        return ['count' => count($slips), 'finished' => false];
    }

    private function getSlipsFromSource($offset, $limit, $dateFilter = null)
    {
        $where = "";
        if ($dateFilter) {
            $where = " WHERE `date_add` > '{$dateFilter}' OR `date_upd` > '{$dateFilter}' ";
        }
        $sql = "SELECT * FROM `{$this->prefix}order_slip` {$where} ORDER BY `id_order_slip` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importSlip($data)
    {
        if (IdMapper::find('order', (int)$data['id_order']) <= 0) {
            return; // order not migrated
        }

        $row = IdMapper::row('order_slip', $data);
        $tid = (int)$row['id_order_slip'];

        try {
            $rows = $this->db_connection->query("SELECT * FROM `{$this->prefix}order_slip_detail` WHERE id_order_slip = " . (int)$data['id_order_slip'])->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            $rows = null;
        }

        if ($rows !== null && !array_key_exists('total_products_tax_excl', $data)) {
            $row = array_merge($row, $this->totalsFromLines($data, $rows));
        }

        if (!SchemaHelper::upsert('order_slip', $row, ['id_order_slip']) || $rows === null) {
            return;
        }

        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "order_slip_detail` WHERE id_order_slip = $tid");

        foreach ($rows as $r) {
            if (!array_key_exists('total_price_tax_excl', $r)) {
                $r = array_merge($r, $this->lineTotals($r));
            }
            $trow = IdMapper::row('order_slip_detail', $r);
            if ((int)$trow['id_order_detail'] <= 0) {
                continue;
            }
            SchemaHelper::insertIgnore('order_slip_detail', $trow);
        }
    }

    /**
     * PrestaShop 1.5 kept no totals on a credit slip (1.6.0.10 added the
     * columns and left the old slips empty). They follow from what 1.5 did
     * keep: the refunded amount of each line, tax excluded and included, and
     * the refunded shipping, tax included.
     */
    private function totalsFromLines(array $slip, array $lines)
    {
        $productsExcl = 0.0;
        $productsIncl = 0.0;
        foreach ($lines as $line) {
            $productsExcl += (float)$line['amount_tax_excl'];
            $productsIncl += (float)$line['amount_tax_incl'];
        }

        $shippingIncl = isset($slip['shipping_cost_amount']) ? (float)$slip['shipping_cost_amount'] : 0.0;
        $carrierTaxRate = 0.0;
        try {
            $carrierTaxRate = (float)$this->db_connection->query("SELECT `carrier_tax_rate` FROM `{$this->prefix}orders` WHERE id_order = " . (int)$slip['id_order'])->fetchColumn();
        } catch (\Exception $e) {
            // without the rate the shipping stays as refunded, tax included
        }

        return [
            'total_products_tax_excl' => round($productsExcl, 6),
            'total_products_tax_incl' => round($productsIncl, 6),
            'total_shipping_tax_excl' => round($carrierTaxRate > 0 ? $shippingIncl / (1 + $carrierTaxRate / 100) : $shippingIncl, 6),
            'total_shipping_tax_incl' => round($shippingIncl, 6),
        ];
    }

    /** Same for a line of a PrestaShop 1.5 credit slip */
    private function lineTotals(array $line)
    {
        $quantity = max(1, (int)$line['product_quantity']);

        return [
            'unit_price_tax_excl' => round((float)$line['amount_tax_excl'] / $quantity, 6),
            'unit_price_tax_incl' => round((float)$line['amount_tax_incl'] / $quantity, 6),
            'total_price_tax_excl' => round((float)$line['amount_tax_excl'], 6),
            'total_price_tax_incl' => round((float)$line['amount_tax_incl'], 6),
        ];
    }
}
