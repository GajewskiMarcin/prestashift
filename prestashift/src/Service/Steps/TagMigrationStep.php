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

class TagMigrationStep
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
        $items = $this->getData($offset, $limit);

        if (empty($items)) {
            return ['count' => 0, 'finished' => true];
        }

        foreach ($items as $item) {
            $this->importTag($item);
        }

        return ['count' => count($items), 'finished' => false];
    }

    private function getData($offset, $limit)
    {
        try {
            $sql = "SELECT * FROM `{$this->prefix}tag` ORDER BY `id_tag` ASC LIMIT $limit OFFSET $offset";
            $stmt = $this->db_connection->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            // Table may not exist in old PS versions
            return [];
        }
    }

    private function importTag($data)
    {
        $id = (int)$data['id_tag'];

        $sql = SchemaHelper::buildUpsertQuery('tag', $data, ['id_tag']);

        if ($sql) {
            try {
                Db::getInstance()->execute($sql);
            } catch (\Exception $e) {
                // Log error
            }
        }

        // Product tag associations
        $this->importProductTags($id);
    }

    private function importProductTags($id_tag)
    {
        try {
            $sql = "SELECT * FROM `{$this->prefix}product_tag` WHERE id_tag = $id_tag";
            $rows = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return;
        }

        Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "product_tag` WHERE id_tag = $id_tag");

        foreach ($rows as $row) {
            $sqlIns = SchemaHelper::buildInsertQuery('product_tag', $row, true);
            if ($sqlIns) {
                $sqlIns = str_replace('INSERT INTO', 'INSERT IGNORE INTO', $sqlIns);
                Db::getInstance()->execute($sqlIns);
            }
        }
    }
}
