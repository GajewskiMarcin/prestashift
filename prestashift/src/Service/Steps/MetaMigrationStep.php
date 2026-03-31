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

class MetaMigrationStep
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
        // One-shot migration: all meta data at offset 0 (small table)
        if ($offset != 0) {
            return ['count' => 0, 'finished' => true];
        }

        try {
            $stmt = $this->db_connection->query(
                "SELECT * FROM `{$this->prefix}meta` ORDER BY id_meta ASC"
            );
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($items)) {
                return ['count' => 0, 'finished' => true];
            }

            foreach ($items as $data) {
                $id = (int)$data['id_meta'];

                // Upsert meta row
                $sql = SchemaHelper::buildUpsertQuery('meta', $data, ['id_meta']);
                if ($sql) {
                    Db::getInstance()->execute($sql);
                }

                // Import meta_lang
                $this->importMetaLang($id);
            }

            return ['count' => count($items), 'finished' => true];
        } catch (\Exception $e) {
            // Table might not exist in source
            return ['count' => 0, 'finished' => true];
        }
    }

    private function importMetaLang($id)
    {
        $stmt = $this->db_connection->query(
            "SELECT * FROM `{$this->prefix}meta_lang` WHERE id_meta = $id"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Delete existing lang rows for this meta
        Db::getInstance()->execute(
            "DELETE FROM `" . \_DB_PREFIX_ . "meta_lang` WHERE id_meta = $id"
        );

        foreach ($rows as $langData) {
            $sql = SchemaHelper::buildInsertQuery('meta_lang', $langData, true);
            if ($sql) {
                Db::getInstance()->execute($sql);
            }
        }
    }
}
