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
use PrestaShift\Service\LogService;
use PrestaShift\Service\SchemaHelper;

class OrderMigrationStep
{
    private $db_connection;
    private $prefix;
    private $status_mapping;

    /** @var array table => [source id_order => rows] — child rows of the batch, read in one query each */
    private $children = [];

    /** @var array source id_order_detail => tax rows */
    private $detailTaxes = [];

    /** @var array source id_order_return => detail rows */
    private $returnDetails = [];

    public function __construct($db_connection, $prefix, $status_mapping = [])
    {
        $this->db_connection = $db_connection;
        $this->prefix = $prefix;
        // Applied through IdMapper's order_state dictionary (config options.status_map)
        $this->status_mapping = $status_mapping;
    }

    public function process($offset, $limit, $dateFilter = null)
    {
        $orders = $this->getOrdersFromSource($offset, $limit, $dateFilter);

        if (empty($orders)) {
            return ['count' => 0, 'finished' => true];
        }

        $ids = array_map('intval', array_column($orders, 'id_order'));
        IdMapper::prepare('order', $ids);
        $this->loadChildren($ids);

        foreach ($orders as $order) {
            $this->importOrder($order);
        }

        return ['count' => count($orders), 'finished' => false];
    }

    private function getOrdersFromSource($offset, $limit, $dateFilter = null)
    {
        $where = "";
        if ($dateFilter) {
            $where = " WHERE `date_add` > '{$dateFilter}' OR `date_upd` > '{$dateFilter}' ";
        }
        $sql = "SELECT * FROM `{$this->prefix}orders` {$where} ORDER BY `id_order` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Reads all child rows of the batch with one query per table — one
     * request per order line would multiply the calls to the source shop.
     */
    private function loadChildren(array $ids)
    {
        $in = implode(',', $ids);
        $this->children = [];
        $this->detailTaxes = [];
        $this->returnDetails = [];

        foreach (['order_detail', 'order_history', 'order_cart_rule', 'order_return'] as $table) {
            $this->children[$table] = [];
            try {
                $rows = $this->db_connection->query("SELECT * FROM `{$this->prefix}$table` WHERE id_order IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Exception $e) {
                continue; // table absent in the source version
            }
            foreach ($rows as $row) {
                $this->children[$table][(int)$row['id_order']][] = $row;
            }
        }

        $detailIds = [];
        foreach ($this->children['order_detail'] as $rows) {
            foreach ($rows as $row) {
                $detailIds[] = (int)$row['id_order_detail'];
            }
        }
        foreach (array_chunk($detailIds, 1000) as $chunk) {
            try {
                $rows = $this->db_connection->query("SELECT * FROM `{$this->prefix}order_detail_tax` WHERE id_order_detail IN (" . implode(',', $chunk) . ")")->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Exception $e) {
                break;
            }
            foreach ($rows as $row) {
                $this->detailTaxes[(int)$row['id_order_detail']][] = $row;
            }
        }

        $returnIds = [];
        foreach ($this->children['order_return'] as $rows) {
            foreach ($rows as $row) {
                $returnIds[] = (int)$row['id_order_return'];
            }
        }
        if ($returnIds) {
            try {
                $rows = $this->db_connection->query("SELECT * FROM `{$this->prefix}order_return_detail` WHERE id_order_return IN (" . implode(',', $returnIds) . ")")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $this->returnDetails[(int)$row['id_order_return']][] = $row;
                }
            } catch (\Exception $e) {
            }
        }
    }

    private function childRows($table, $sid)
    {
        return isset($this->children[$table][$sid]) ? $this->children[$table][$sid] : [];
    }

    private function importOrder($data)
    {
        $sid = (int)$data['id_order'];

        $row = IdMapper::row('orders', [
            'id_order' => $sid,
            'reference' => $data['reference'],
            'id_shop_group' => isset($data['id_shop_group']) ? $data['id_shop_group'] : 1,
            'id_shop' => isset($data['id_shop']) ? $data['id_shop'] : 1,
            'id_carrier' => $data['id_carrier'],
            'id_lang' => $data['id_lang'],
            'id_customer' => $data['id_customer'],
            'id_cart' => $data['id_cart'],
            'id_currency' => $data['id_currency'],
            'id_address_delivery' => $data['id_address_delivery'],
            'id_address_invoice' => $data['id_address_invoice'],
            'current_state' => $data['current_state'],
            'secure_key' => $data['secure_key'],
            'payment' => $data['payment'],
            'conversion_rate' => $data['conversion_rate'],
            'module' => $data['module'],
            'recyclable' => $data['recyclable'],
            'gift' => $data['gift'],
            'gift_message' => $data['gift_message'],
            'mobile_theme' => isset($data['mobile_theme']) ? $data['mobile_theme'] : 0,
            'shipping_number' => isset($data['shipping_number']) ? $data['shipping_number'] : null,
            'note' => isset($data['note']) ? $data['note'] : null,
            'total_discounts' => $data['total_discounts'],
            'total_discounts_tax_incl' => $data['total_discounts_tax_incl'],
            'total_discounts_tax_excl' => $data['total_discounts_tax_excl'],
            'total_paid' => $data['total_paid'],
            'total_paid_tax_incl' => $data['total_paid_tax_incl'],
            'total_paid_tax_excl' => $data['total_paid_tax_excl'],
            'total_paid_real' => $data['total_paid_real'],
            'total_products' => $data['total_products'],
            'total_products_wt' => $data['total_products_wt'],
            'total_shipping' => $data['total_shipping'],
            'total_shipping_tax_incl' => $data['total_shipping_tax_incl'],
            'total_shipping_tax_excl' => $data['total_shipping_tax_excl'],
            'carrier_tax_rate' => $data['carrier_tax_rate'],
            'total_wrapping' => $data['total_wrapping'],
            'total_wrapping_tax_incl' => $data['total_wrapping_tax_incl'],
            'total_wrapping_tax_excl' => $data['total_wrapping_tax_excl'],
            'round_mode' => isset($data['round_mode']) ? $data['round_mode'] : null,
            'round_type' => isset($data['round_type']) ? $data['round_type'] : null,
            'invoice_number' => $data['invoice_number'],
            'delivery_number' => $data['delivery_number'],
            'invoice_date' => $data['invoice_date'],
            'delivery_date' => $data['delivery_date'],
            'valid' => $data['valid'],
            'date_add' => $data['date_add'],
            'date_upd' => $data['date_upd'],
        ]);
        $tid = (int)$row['id_order'];

        // An order without its customer would be attached to nobody
        if ((int)$data['id_customer'] > 0 && (int)$row['id_customer'] <= 0) {
            LogService::getInstance()->warning("Order #$sid skipped: its customer #{$data['id_customer']} is not in the target (migrate customers first).");
            return;
        }
        if ((int)$row['id_currency'] <= 0) {
            $row['id_currency'] = (int)\Configuration::get('PS_CURRENCY_DEFAULT');
        }
        if ($row['round_mode'] === null) {
            unset($row['round_mode']);
        }
        if ($row['round_type'] === null) {
            unset($row['round_type']);
        }

        if (!SchemaHelper::upsert('orders', $row, ['id_order'])) {
            return;
        }

        $this->importOrderDetails($sid, $tid);
        $this->importOrderHistory($sid, $tid);
        $this->importOrderCartRules($sid, $tid);
        $this->importOrderReturns($sid, $tid);
    }

    private function importOrderDetails($sid, $tid)
    {
        $details = $this->childRows('order_detail', $sid);

        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "order_detail` WHERE id_order = $tid");

        foreach ($details as $d) {
            $d['id_order'] = $sid;
            $row = IdMapper::row('order_detail', $d);
            $row['id_warehouse'] = 0;
            // Products that no longer exist keep their name/price snapshot
            // (as in the source, where deleted products keep id 0 too)

            if (!SchemaHelper::upsert('order_detail', $row, ['id_order_detail'])) {
                continue;
            }

            $this->importOrderDetailTaxes((int)$d['id_order_detail'], (int)$row['id_order_detail']);
        }
    }

    /**
     * Per-line tax breakdown — without it invoices generated in the new shop
     * show no VAT summary.
     */
    private function importOrderDetailTaxes($sidDetail, $tidDetail)
    {
        $rows = isset($this->detailTaxes[$sidDetail]) ? $this->detailTaxes[$sidDetail] : [];

        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "order_detail_tax` WHERE id_order_detail = $tidDetail");

        foreach ($rows as $r) {
            $tax = IdMapper::ref('tax', (int)$r['id_tax']);
            if ($tax <= 0) {
                continue;
            }
            SchemaHelper::insertIgnore('order_detail_tax', [
                'id_order_detail' => $tidDetail,
                'id_tax' => $tax,
                'unit_amount' => $r['unit_amount'],
                'total_amount' => $r['total_amount'],
            ]);
        }
    }

    private function importOrderHistory($sid, $tid)
    {
        $rows = $this->childRows('order_history', $sid);

        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "order_history` WHERE id_order = $tid");

        foreach ($rows as $r) {
            $row = IdMapper::row('order_history', $r);
            SchemaHelper::upsert('order_history', $row, ['id_order_history']);
        }
    }

    /**
     * Vouchers used on the order (the discount lines of the order page).
     */
    private function importOrderCartRules($sid, $tid)
    {
        $rows = $this->childRows('order_cart_rule', $sid);

        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "order_cart_rule` WHERE id_order = $tid");

        foreach ($rows as $r) {
            // The name and amounts are stored on the order itself; the link to
            // the voucher is kept when vouchers were migrated
            $row = IdMapper::row('order_cart_rule', $r);
            SchemaHelper::upsert('order_cart_rule', $row, ['id_order_cart_rule']);
        }
    }

    /**
     * Merchandise returns (RMA).
     */
    private function importOrderReturns($sid, $tid)
    {
        $returns = $this->childRows('order_return', $sid);

        foreach ($returns as $ret) {
            $row = IdMapper::row('order_return', $ret);
            if ((int)$row['id_customer'] <= 0) {
                continue;
            }
            $tidReturn = (int)$row['id_order_return'];
            SchemaHelper::upsert('order_return', $row, ['id_order_return']);

            $details = isset($this->returnDetails[(int)$ret['id_order_return']]) ? $this->returnDetails[(int)$ret['id_order_return']] : [];
            Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "order_return_detail` WHERE id_order_return = $tidReturn");
            foreach ($details as $d) {
                $drow = IdMapper::row('order_return_detail', $d);
                if ((int)$drow['id_order_detail'] <= 0) {
                    continue;
                }
                SchemaHelper::insertIgnore('order_return_detail', $drow);
            }
        }
    }
}
