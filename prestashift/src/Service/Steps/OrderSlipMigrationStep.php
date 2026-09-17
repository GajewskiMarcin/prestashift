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

        if (!SchemaHelper::upsert('order_slip', $row, ['id_order_slip'])) {
            return;
        }

        try {
            $rows = $this->db_connection->query("SELECT * FROM `{$this->prefix}order_slip_detail` WHERE id_order_slip = " . (int)$data['id_order_slip'])->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return;
        }

        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "order_slip_detail` WHERE id_order_slip = $tid");

        foreach ($rows as $r) {
            $trow = IdMapper::row('order_slip_detail', $r);
            if ((int)$trow['id_order_detail'] <= 0) {
                continue;
            }
            SchemaHelper::insertIgnore('order_slip_detail', $trow);
        }
    }
}
