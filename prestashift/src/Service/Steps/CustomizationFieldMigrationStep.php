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

class CustomizationFieldMigrationStep
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
            $sql = "SELECT * FROM `{$this->prefix}customization_field` ORDER BY id_customization_field ASC LIMIT $limit OFFSET $offset";
            $stmt = $this->db_connection->query($sql);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return ['count' => 0, 'finished' => true];
        }

        if (empty($items)) {
            return ['count' => 0, 'finished' => true];
        }

        foreach ($items as $item) {
            $this->importItem($item);
        }

        return ['count' => count($items), 'finished' => false];
    }

    private function importItem($data)
    {
        $id = (int)$data['id_customization_field'];

        $sql = SchemaHelper::buildUpsertQuery('customization_field', $data, ['id_customization_field']);
        if ($sql) {
            try {
                Db::getInstance()->execute($sql);
            } catch (\Exception $e) {
                // Log error
            }
        }

        // Lang
        $this->importLang($id);
    }

    private function importLang($id)
    {
        try {
            $sql = "SELECT * FROM `{$this->prefix}customization_field_lang` WHERE id_customization_field = $id";
            $rows = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return;
        }

        Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "customization_field_lang` WHERE id_customization_field = $id");

        foreach ($rows as $row) {
            $sql = SchemaHelper::buildInsertQuery('customization_field_lang', $row, true);
            if ($sql) {
                Db::getInstance()->execute($sql);
            }
        }
    }
}
