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

use PDO;
use PrestaShift\Service\IdMapper;
use PrestaShift\Service\SchemaHelper;

class MessageMigrationStep
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
        $threads = $this->getCustomerThreads($offset, $limit, $dateFilter);

        if (empty($threads)) {
            return ['count' => 0, 'finished' => true];
        }

        IdMapper::prepare('customer_thread', array_column($threads, 'id_customer_thread'));

        foreach ($threads as $thread) {
            $this->importThread($thread);
        }

        return ['count' => count($threads), 'finished' => false];
    }

    private function getCustomerThreads($offset, $limit, $dateFilter = null)
    {
        $where = "";
        if ($dateFilter) {
            $where = " WHERE `date_add` > '{$dateFilter}' OR `date_upd` > '{$dateFilter}' ";
        }
        $sql = "SELECT * FROM `{$this->prefix}customer_thread` {$where} ORDER BY `id_customer_thread` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importThread($data)
    {
        $row = IdMapper::row('customer_thread', $data);

        // A thread of a customer that is not in the target would be shown under
        // no one — skip it. Guest threads (no customer) are kept.
        if ((int)$data['id_customer'] > 0 && (int)$row['id_customer'] <= 0) {
            return;
        }

        if (!SchemaHelper::upsert('customer_thread', $row, ['id_customer_thread'])) {
            return;
        }

        $messages = $this->db_connection->query("SELECT * FROM `{$this->prefix}customer_message` WHERE id_customer_thread = " . (int)$data['id_customer_thread'])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($messages as $msg) {
            SchemaHelper::upsert('customer_message', IdMapper::row('customer_message', $msg), ['id_customer_message']);
        }
    }
}
