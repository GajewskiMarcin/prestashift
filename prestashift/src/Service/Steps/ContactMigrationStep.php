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

class ContactMigrationStep
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
        // One-shot migration — small data set
        if ($offset == 0) {
            try {
                $rows = $this->db_connection->query(
                    "SELECT * FROM `{$this->prefix}contact` ORDER BY `id_contact` ASC"
                )->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Exception $e) {
                // Table may not exist in old PS versions
                return ['count' => 0, 'finished' => true];
            }

            foreach ($rows as $row) {
                $this->importContact($row);
            }
        }

        return ['count' => 1, 'finished' => true];
    }

    private function importContact($data)
    {
        $id = (int)$data['id_contact'];

        $sql = SchemaHelper::buildUpsertQuery('contact', $data, ['id_contact']);

        if ($sql) {
            try {
                Db::getInstance()->execute($sql);
            } catch (\Exception $e) {
                // Log error
            }
        }

        // Lang
        $this->importContactLang($id);

        // Shop association
        $shopData = [
            'id_contact' => $id,
            'id_shop' => \PrestaShift\Service\SchemaHelper::getTargetShopId(),
        ];
        $sqlShop = SchemaHelper::buildInsertQuery('contact_shop', $shopData, true);
        if ($sqlShop) {
            $sqlShop = str_replace('INSERT INTO', 'INSERT IGNORE INTO', $sqlShop);
            Db::getInstance()->execute($sqlShop);
        }
    }

    private function importContactLang($id_contact)
    {
        try {
            $sql = "SELECT * FROM `{$this->prefix}contact_lang` WHERE id_contact = $id_contact";
            $rows = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return;
        }

        Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "contact_lang` WHERE id_contact = $id_contact");

        foreach ($rows as $row) {
            $sqlIns = SchemaHelper::buildInsertQuery('contact_lang', $row, true);
            if ($sqlIns) {
                Db::getInstance()->execute($sqlIns);
            }
        }
    }
}
