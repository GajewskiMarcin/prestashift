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

class StateMigrationStep
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
        // One-shot migration — all states migrated at offset=0
        if ($offset == 0) {
            try {
                $rows = $this->db_connection->query(
                    "SELECT * FROM `{$this->prefix}state` ORDER BY `id_state` ASC"
                )->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Exception $e) {
                // Table may not exist in old PS versions
                return ['count' => 0, 'finished' => true];
            }

            foreach ($rows as $row) {
                $this->importState($row);
            }
        }

        return ['count' => 1, 'finished' => true];
    }

    private function importState($data)
    {
        $sql = SchemaHelper::buildUpsertQuery('state', $data, ['id_state']);

        if ($sql) {
            try {
                Db::getInstance()->execute($sql);
            } catch (\Exception $e) {
                // Log error
            }
        }
    }
}
