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

class AttributeGroupMigrationStep
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
        $rows = $this->getData($offset, $limit);

        if (empty($rows)) {
            return ['count' => 0, 'finished' => true];
        }

        IdMapper::prepare('attribute_group', array_column($rows, 'id_attribute_group'));

        foreach ($rows as $row) {
            $this->importItem($row);
        }

        return ['count' => count($rows), 'finished' => false];
    }

    private function getData($offset, $limit)
    {
        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}attribute_group` ORDER BY id_attribute_group ASC LIMIT $limit OFFSET $offset");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importItem($data)
    {
        $sid = (int)$data['id_attribute_group'];
        $row = IdMapper::row('attribute_group', $data);
        $tid = (int)$row['id_attribute_group'];

        SchemaHelper::upsert('attribute_group', $row, ['id_attribute_group']);

        $this->importLang($sid, $tid);

        Db::getInstance()->execute("REPLACE INTO `" . _DB_PREFIX_ . "attribute_group_shop` (id_attribute_group, id_shop) VALUES ($tid, " . SchemaHelper::getTargetShopId() . ")");
    }

    private function importLang($sid, $tid)
    {
        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}attribute_group_lang` WHERE id_attribute_group = $sid");
        $langs = LanguageMapper::expand($stmt->fetchAll(PDO::FETCH_ASSOC));

        foreach ($langs as $lang) {
            $lang['id_attribute_group'] = $tid;
            $lang['id_shop'] = SchemaHelper::getTargetShopId();
            Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "attribute_group_lang` WHERE id_attribute_group = $tid AND id_lang = " . (int)$lang['id_lang']);
            SchemaHelper::insertIgnore('attribute_group_lang', $lang);
        }
    }
}
