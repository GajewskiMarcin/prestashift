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
        $id_order_slip = (int)$data['id_order_slip'];

        $sql = SchemaHelper::buildUpsertQuery('order_slip', $data, ['id_order_slip']);
        if ($sql) {
            try {
                Db::getInstance()->execute($sql);
            } catch (\Exception $e) {
                // Log
            }
        }

        $this->importSlipDetails($id_order_slip);
    }

    private function importSlipDetails($id_order_slip)
    {
        try {
            $sql = "SELECT * FROM `{$this->prefix}order_slip_detail` WHERE id_order_slip = $id_order_slip";
            $rows = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "order_slip_detail` WHERE id_order_slip = $id_order_slip");

            foreach ($rows as $row) {
                $sql = SchemaHelper::buildInsertQuery('order_slip_detail', $row, true);
                if ($sql) {
                    Db::getInstance()->execute($sql);
                }
            }
        } catch (\Exception $e) {
            // Table may not exist in old PS versions
        }
    }
}
