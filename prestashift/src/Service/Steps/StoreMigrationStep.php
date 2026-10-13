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
        if (empty($langs)) {
            $langs = $this->langRowsFromStore($data);
        }
        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "store_lang` WHERE id_store = $tid");
        foreach ($langs as $lang) {
            $lang['id_store'] = $tid;
            SchemaHelper::insertIgnore('store_lang', $lang);
        }

        SchemaHelper::insertIgnore('store_shop', ['id_store' => $tid, 'id_shop' => SchemaHelper::getTargetShopId()]);
    }

    /**
     * Before PrestaShop 1.7.3 the name, address, opening hours and note of a
     * store were plain columns of `store`, the same for every language. One
     * row per language of the target is built from them.
     */
    private function langRowsFromStore(array $data)
    {
        if (!isset($data['name'])) {
            return [];
        }

        $rows = [];
        foreach (\Language::getIDs(false) as $idLang) {
            $rows[] = [
                'id_lang' => (int)$idLang,
                'name' => $data['name'],
                'address1' => isset($data['address1']) ? $data['address1'] : '',
                'address2' => isset($data['address2']) ? $data['address2'] : null,
                'hours' => $this->hoursAsJson(isset($data['hours']) ? $data['hours'] : null),
                'note' => isset($data['note']) ? $data['note'] : null,
            ];
        }

        return $rows;
    }

    /**
     * Opening hours used to be a serialized PHP array of seven texts.
     * PrestaShop 1.7.3+ reads JSON: one list of time ranges per day.
     */
    private function hoursAsJson($hours)
    {
        if ($hours === null || $hours === '' || is_array(json_decode($hours, true))) {
            return $hours;
        }

        // Source data: never let it instantiate objects
        $days = @unserialize($hours, ['allowed_classes' => false]);
        if (!is_array($days)) {
            return null;
        }

        $out = [];
        foreach ($days as $day) {
            $out[] = [is_scalar($day) ? (string)$day : ''];
        }

        return json_encode($out);
    }
}
