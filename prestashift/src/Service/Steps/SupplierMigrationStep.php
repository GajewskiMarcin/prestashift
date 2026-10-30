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

class SupplierMigrationStep
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
        $sql = "SELECT * FROM `{$this->prefix}supplier` ORDER BY `id_supplier` ASC LIMIT $limit OFFSET $offset";
        $items = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            return ['count' => 0, 'finished' => true];
        }

        IdMapper::prepare('supplier', array_column($items, 'id_supplier'));
        // One query for the whole batch instead of one per record
        $this->langRows = SourceBatch::grouped($this->db_connection,
            "SELECT * FROM `{$this->prefix}supplier_lang` WHERE id_supplier IN ({ids})",
            'id_supplier', array_column($items, 'id_supplier'));

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

        foreach (LanguageMapper::expand(SourceBatch::of($this->langRows, $sid)) as $lang) {
            $lang['id_supplier'] = $tid;
            Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "supplier_lang` WHERE id_supplier = $tid AND id_lang = " . (int)$lang['id_lang']);
            SchemaHelper::insertIgnore('supplier_lang', $lang);
        }

        Db::getInstance()->execute("REPLACE INTO `" . _DB_PREFIX_ . "supplier_shop` (id_supplier, id_shop) VALUES ($tid, " . SchemaHelper::getTargetShopId() . ")");
    }
}
