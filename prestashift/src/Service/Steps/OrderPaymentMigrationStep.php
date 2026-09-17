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

/**
 * Payments, invoices and carrier lines of the migrated orders.
 *
 * Iterates the SOURCE orders and touches only their mapped target orders —
 * orders that already existed in the target shop are never read or changed.
 */
class OrderPaymentMigrationStep
{
    private $db_connection;
    private $prefix;

    /** @var array table => [key => rows] — rows of the batch, one query per table */
    private $rows = [];

    public function __construct($db_connection, $prefix)
    {
        $this->db_connection = $db_connection;
        $this->prefix = $prefix;
    }

    public function process($offset, $limit, $dateFilter = null)
    {
        $where = "";
        if ($dateFilter) {
            $where = " WHERE `date_add` > '{$dateFilter}' OR `date_upd` > '{$dateFilter}' ";
        }
        $orders = $this->db_connection->query("SELECT id_order, reference FROM `{$this->prefix}orders` {$where} ORDER BY `id_order` ASC LIMIT $limit OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($orders)) {
            return ['count' => 0, 'finished' => true];
        }

        IdMapper::prepare('order', array_column($orders, 'id_order'));
        $this->loadBatch($orders);

        foreach ($orders as $order) {
            $sid = (int)$order['id_order'];
            $tid = IdMapper::find('order', $sid);
            if ($tid <= 0) {
                continue; // order was not migrated (e.g. its customer is missing)
            }

            $this->importOrderInvoices($sid, $tid);
            $this->importOrderPayments($order['reference']);
            $this->importOrderInvoicePayments($sid, $tid);
            $this->importOrderCarriers($sid, $tid);
        }

        return ['count' => count($orders), 'finished' => false];
    }

    /**
     * Reads the rows of the whole batch with one query per table — one request
     * per order would multiply the calls to the source shop.
     */
    private function loadBatch(array $orders)
    {
        $ids = implode(',', array_map('intval', array_column($orders, 'id_order')));
        $refs = implode("','", array_map('pSQL', array_column($orders, 'reference')));
        $this->rows = [];

        $queries = [
            'order_invoice' => ["SELECT * FROM `{$this->prefix}order_invoice` WHERE id_order IN ($ids)", 'id_order'],
            'order_invoice_payment' => ["SELECT * FROM `{$this->prefix}order_invoice_payment` WHERE id_order IN ($ids)", 'id_order'],
            'order_carrier' => ["SELECT * FROM `{$this->prefix}order_carrier` WHERE id_order IN ($ids)", 'id_order'],
            'order_payment' => ["SELECT * FROM `{$this->prefix}order_payment` WHERE order_reference IN ('$refs')", 'order_reference'],
        ];
        foreach ($queries as $table => $q) {
            $this->rows[$table] = [];
            try {
                foreach ($this->db_connection->query($q[0])->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $this->rows[$table][(string)$row[$q[1]]][] = $row;
                }
            } catch (\Exception $e) {
                // table absent in the source version
            }
        }

        $invoiceIds = [];
        foreach ($this->rows['order_invoice'] as $list) {
            foreach ($list as $row) {
                $invoiceIds[] = (int)$row['id_order_invoice'];
            }
        }
        $this->rows['order_invoice_tax'] = [];
        if ($invoiceIds) {
            try {
                foreach ($this->db_connection->query("SELECT * FROM `{$this->prefix}order_invoice_tax` WHERE id_order_invoice IN (" . implode(',', $invoiceIds) . ")")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $this->rows['order_invoice_tax'][(string)$row['id_order_invoice']][] = $row;
                }
            } catch (\Exception $e) {
            }
        }
    }

    private function batchRows($table, $key)
    {
        return isset($this->rows[$table][(string)$key]) ? $this->rows[$table][(string)$key] : [];
    }

    private function importOrderInvoices($sid, $tid)
    {
        $kept = [];
        foreach ($this->batchRows('order_invoice', $sid) as $row) {
            $trow = IdMapper::row('order_invoice', $row);
            $kept[] = (int)$trow['id_order_invoice'];
            SchemaHelper::upsert('order_invoice', $trow, ['id_order_invoice']);
            $this->importOrderInvoiceTaxes((int)$row['id_order_invoice'], (int)$trow['id_order_invoice']);
        }

        // Invoices removed from the source order since the last run
        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "order_invoice` WHERE id_order = $tid"
            . ($kept ? " AND id_order_invoice NOT IN (" . implode(',', $kept) . ")" : ''));
    }

    /**
     * Tax summary of the invoice.
     */
    private function importOrderInvoiceTaxes($sidInvoice, $tidInvoice)
    {
        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "order_invoice_tax` WHERE id_order_invoice = $tidInvoice");

        foreach ($this->batchRows('order_invoice_tax', $sidInvoice) as $r) {
            $tax = IdMapper::ref('tax', (int)$r['id_tax']);
            if ($tax <= 0) {
                continue;
            }
            SchemaHelper::insertIgnore('order_invoice_tax', [
                'id_order_invoice' => $tidInvoice,
                'type' => $r['type'],
                'id_tax' => $tax,
                'amount' => $r['amount'],
            ]);
        }
    }

    /**
     * Payments are linked to orders by reference, not id. Only the source's
     * payment rows are written (by their mapped ids) — payments of target
     * orders that happen to share a reference stay untouched.
     */
    private function importOrderPayments($reference)
    {
        foreach ($this->batchRows('order_payment', $reference) as $row) {
            $trow = IdMapper::row('order_payment', $row);
            if ((int)$trow['id_currency'] <= 0) {
                $trow['id_currency'] = (int)\Configuration::get('PS_CURRENCY_DEFAULT');
            }
            SchemaHelper::upsert('order_payment', $trow, ['id_order_payment']);
        }
    }

    private function importOrderInvoicePayments($sid, $tid)
    {
        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "order_invoice_payment` WHERE id_order = $tid");

        foreach ($this->batchRows('order_invoice_payment', $sid) as $row) {
            $trow = IdMapper::row('order_invoice_payment', $row);
            if ((int)$trow['id_order_invoice'] <= 0 || (int)$trow['id_order_payment'] <= 0) {
                continue;
            }
            SchemaHelper::insertIgnore('order_invoice_payment', $trow);
        }
    }

    private function importOrderCarriers($sid, $tid)
    {
        $kept = [];
        foreach ($this->batchRows('order_carrier', $sid) as $row) {
            $trow = IdMapper::row('order_carrier', $row);
            $kept[] = (int)$trow['id_order_carrier'];
            SchemaHelper::upsert('order_carrier', $trow, ['id_order_carrier']);
        }

        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "order_carrier` WHERE id_order = $tid"
            . ($kept ? " AND id_order_carrier NOT IN (" . implode(',', $kept) . ")" : ''));
    }
}
