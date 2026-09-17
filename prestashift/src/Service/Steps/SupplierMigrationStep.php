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

class SupplierMigrationStep
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
        $sql = "SELECT * FROM `{$this->prefix}supplier` ORDER BY `id_supplier` ASC LIMIT $limit OFFSET $offset";
        $items = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            return ['count' => 0, 'finished' => true];
        }

        IdMapper::prepare('supplier', array_column($items, 'id_supplier'));

        foreach ($items as $item) {
            $this->importSupplier($item);
        }

        return ['count' => count($items), 'finished' => false];
    }

    private function importSupplier($data)
    {
        $sid = (int)$data['id_supplier'];
        $row = IdMapper::row('supplier', $data);
        $tid = (int)$row['id_supplier'];

        SchemaHelper::upsert('supplier', $row, ['id_supplier']);

        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}supplier_lang` WHERE id_supplier = $sid");
        foreach (LanguageMapper::expand($stmt->fetchAll(PDO::FETCH_ASSOC)) as $lang) {
            $lang['id_supplier'] = $tid;
            Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "supplier_lang` WHERE id_supplier = $tid AND id_lang = " . (int)$lang['id_lang']);
            SchemaHelper::insertIgnore('supplier_lang', $lang);
        }

        Db::getInstance()->execute("REPLACE INTO `" . _DB_PREFIX_ . "supplier_shop` (id_supplier, id_shop) VALUES ($tid, " . SchemaHelper::getTargetShopId() . ")");
    }
}
