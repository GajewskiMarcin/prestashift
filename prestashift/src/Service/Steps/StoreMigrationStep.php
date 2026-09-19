<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
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

class StoreMigrationStep
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
        $items = $this->getData($offset, $limit, $dateFilter);

        if (empty($items)) {
            return ['count' => 0, 'finished' => true];
        }

        foreach ($items as $item) {
            $this->importStore($item);
        }

        return ['count' => count($items), 'finished' => false];
    }

    private function getData($offset, $limit, $dateFilter = null)
    {
        $where = "";
        if ($dateFilter) {
            $where = " WHERE `date_add` > '{$dateFilter}' OR `date_upd` > '{$dateFilter}' ";
        }

        try {
            $sql = "SELECT * FROM `{$this->prefix}store` {$where} ORDER BY `id_store` ASC LIMIT $limit OFFSET $offset";
            return $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    private function importStore($data)
    {
        $sid = (int)$data['id_store'];
        $row = IdMapper::row('store', $data);
        $tid = (int)$row['id_store'];
        if ((int)$row['id_country'] <= 0) {
            $row['id_country'] = (int)\Configuration::get('PS_COUNTRY_DEFAULT');
            $row['id_state'] = 0;
        }

        SchemaHelper::upsert('store', $row, ['id_store']);

        try {
            $langs = LanguageMapper::expand($this->db_connection->query("SELECT * FROM `{$this->prefix}store_lang` WHERE id_store = $sid")->fetchAll(PDO::FETCH_ASSOC));
        } catch (\Exception $e) {
            $langs = [];
        }
        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "store_lang` WHERE id_store = $tid");
        foreach ($langs as $lang) {
            $lang['id_store'] = $tid;
            SchemaHelper::insertIgnore('store_lang', $lang);
        }

        SchemaHelper::insertIgnore('store_shop', ['id_store' => $tid, 'id_shop' => SchemaHelper::getTargetShopId()]);
    }
}
