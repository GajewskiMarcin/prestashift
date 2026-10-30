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

class OrderSlipMigrationStep
{
    private $db_connection;
    private $prefix;

    /** @var array source id_order_slip => detail rows, read once per batch */
    private $detailRows = [];

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
        // All slip lines of the batch in one query instead of one per slip
        $this->detailRows = SourceBatch::grouped($this->db_connection,
            "SELECT * FROM `{$this->prefix}order_slip_detail` WHERE id_order_slip IN ({ids})",
            'id_order_slip', array_column($slips, 'id_order_slip'));

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

        $rows = SourceBatch::of($this->detailRows, $data['id_order_slip']);

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
