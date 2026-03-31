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

class OrderPaymentMigrationStep
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
        // Get already-migrated orders from TARGET
        $sql = "SELECT id_order, reference FROM `" . _DB_PREFIX_ . "orders` ORDER BY `id_order` ASC LIMIT $limit OFFSET $offset";
        $orders = Db::getInstance()->executeS($sql);

        if (empty($orders)) {
            return ['count' => 0, 'finished' => true];
        }

        foreach ($orders as $order) {
            $id_order = (int)$order['id_order'];
            $reference = pSQL($order['reference']);

            $this->importOrderPayment($reference);
            $this->importOrderInvoice($id_order);
            $this->importOrderInvoicePayment($id_order);
            $this->importOrderCarrier($id_order);
        }

        return ['count' => count($orders), 'finished' => false];
    }

    private function importOrderPayment($reference)
    {
        try {
            $sql = "SELECT * FROM `{$this->prefix}order_payment` WHERE order_reference = '{$reference}'";
            $rows = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "order_payment` WHERE order_reference = '{$reference}'");

            foreach ($rows as $row) {
                $sql = SchemaHelper::buildInsertQuery('order_payment', $row, true);
                if ($sql) {
                    Db::getInstance()->execute($sql);
                }
            }
        } catch (\Exception $e) {
            // Table may not exist in old PS versions
        }
    }

    private function importOrderInvoice($id_order)
    {
        try {
            $sql = "SELECT * FROM `{$this->prefix}order_invoice` WHERE id_order = $id_order";
            $rows = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "order_invoice` WHERE id_order = $id_order");

            foreach ($rows as $row) {
                $sql = SchemaHelper::buildUpsertQuery('order_invoice', $row, ['id_order_invoice']);
                if ($sql) {
                    Db::getInstance()->execute($sql);
                }
            }
        } catch (\Exception $e) {
            // Table may not exist in old PS versions
        }
    }

    private function importOrderInvoicePayment($id_order)
    {
        try {
            $sql = "SELECT * FROM `{$this->prefix}order_invoice_payment` WHERE id_order = $id_order";
            $rows = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "order_invoice_payment` WHERE id_order = $id_order");

            foreach ($rows as $row) {
                $sql = SchemaHelper::buildInsertQuery('order_invoice_payment', $row, true);
                if ($sql) {
                    Db::getInstance()->execute($sql);
                }
            }
        } catch (\Exception $e) {
            // Table may not exist in old PS versions
        }
    }

    private function importOrderCarrier($id_order)
    {
        try {
            $sql = "SELECT * FROM `{$this->prefix}order_carrier` WHERE id_order = $id_order";
            $rows = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "order_carrier` WHERE id_order = $id_order");

            foreach ($rows as $row) {
                $sql = SchemaHelper::buildUpsertQuery('order_carrier', $row, ['id_order_carrier']);
                if ($sql) {
                    Db::getInstance()->execute($sql);
                }
            }
        } catch (\Exception $e) {
            // Table may not exist in old PS versions
        }
    }
}
