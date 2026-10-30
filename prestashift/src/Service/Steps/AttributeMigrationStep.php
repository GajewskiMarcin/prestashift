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
use PrestaShift\Service\LanguageMapper;
use PrestaShift\Service\SchemaHelper;
use PrestaShift\Service\SourceBatch;

class AttributeMigrationStep
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
        $rows = $this->getData($offset, $limit);

        if (empty($rows)) {
            return ['count' => 0, 'finished' => true];
        }

        IdMapper::prepare('attribute', array_column($rows, 'id_attribute'));
        // One query for the whole batch instead of one per record
        $this->langRows = SourceBatch::grouped($this->db_connection,
            "SELECT * FROM `{$this->prefix}attribute_lang` WHERE id_attribute IN ({ids})",
            'id_attribute', array_column($rows, 'id_attribute'));

        foreach ($rows as $row) {
            $this->importItem($row);
        }

        return ['count' => count($rows), 'finished' => false];
    }

    private function getData($offset, $limit)
    {
        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}attribute` ORDER BY id_attribute ASC LIMIT $limit OFFSET $offset");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importItem($data)
    {
        $sid = (int)$data['id_attribute'];
        $row = IdMapper::row('attribute', $data);
        $tid = (int)$row['id_attribute'];

        if ((int)$row['id_attribute_group'] <= 0) {
            return; // group missing in the source — a value without a group breaks the combinations page
        }

        SchemaHelper::upsert('attribute', $row, ['id_attribute']);

        $this->importLang($sid, $tid);

        Db::getInstance()->execute("REPLACE INTO `" . _DB_PREFIX_ . "attribute_shop` (id_attribute, id_shop) VALUES ($tid, " . SchemaHelper::getTargetShopId() . ")");
    }

    private function importLang($sid, $tid)
    {
        $langs = LanguageMapper::expand(SourceBatch::of($this->langRows, $sid));

        foreach ($langs as $lang) {
            $lang['id_attribute'] = $tid;
            Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "attribute_lang` WHERE id_attribute = $tid AND id_lang = " . (int)$lang['id_lang']);
            SchemaHelper::insertIgnore('attribute_lang', $lang);
        }
    }
}
