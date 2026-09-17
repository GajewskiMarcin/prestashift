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
use PrestaShift\Service\LanguageMapper;
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
                $rows = $this->db_connection->query("SELECT * FROM `{$this->prefix}contact` ORDER BY `id_contact` ASC")->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Exception $e) {
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
        $sid = (int)$data['id_contact'];
        $row = IdMapper::row('contact', $data);
        $tid = (int)$row['id_contact'];

        SchemaHelper::upsert('contact', $row, ['id_contact']);

        try {
            $langs = LanguageMapper::expand($this->db_connection->query("SELECT * FROM `{$this->prefix}contact_lang` WHERE id_contact = $sid")->fetchAll(PDO::FETCH_ASSOC));
        } catch (\Exception $e) {
            $langs = [];
        }
        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "contact_lang` WHERE id_contact = $tid");
        foreach ($langs as $lang) {
            $lang['id_contact'] = $tid;
            SchemaHelper::insertIgnore('contact_lang', $lang);
        }

        SchemaHelper::insertIgnore('contact_shop', ['id_contact' => $tid, 'id_shop' => SchemaHelper::getTargetShopId()]);
    }
}
