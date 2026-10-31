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

use Db;
use PDO;
use PrestaShift\Service\IdMapper;
use PrestaShift\Service\LanguageMapper;
use PrestaShift\Service\SchemaHelper;
use PrestaShift\Service\SourceBatch;

class ContactMigrationStep
{
    private $db_connection;
    private $prefix;

    /** @var array source id => translation rows, read once per batch */
    private $langRows = [];

    public function __construct($db_connection, $prefix)
    {
        $this->db_connection = $db_connection;
        $this->prefix = $prefix;
    }

    public function process($offset, $limit, $dateFilter = null)
    {
        // One-shot migration — small data set
        if ($offset == 0) {
            $rows = $this->db_connection->query("SELECT * FROM `{$this->prefix}contact` ORDER BY `id_contact` ASC")->fetchAll(PDO::FETCH_ASSOC);

            // One query for the whole batch instead of one per record
            $this->langRows = SourceBatch::grouped($this->db_connection,
                "SELECT * FROM `{$this->prefix}contact_lang` WHERE id_contact IN ({ids})",
                'id_contact', array_column($rows, 'id_contact'));

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

        $langs = LanguageMapper::expand(SourceBatch::of($this->langRows, $sid));
        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "contact_lang` WHERE id_contact = $tid");
        foreach ($langs as $lang) {
            $lang['id_contact'] = $tid;
            SchemaHelper::insertIgnore('contact_lang', $lang);
        }

        SchemaHelper::insertIgnore('contact_shop', ['id_contact' => $tid, 'id_shop' => SchemaHelper::getTargetShopId()]);
    }
}
