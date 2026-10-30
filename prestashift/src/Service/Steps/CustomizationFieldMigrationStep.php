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

class CustomizationFieldMigrationStep
{
    private $db_connection;
    private $prefix;

    /** @var array source id_customization_field => label rows, read once per batch */
    private $langRows = [];

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

        IdMapper::prepare('customization_field', array_column($items, 'id_customization_field'));
        // One query for the whole batch instead of one per field
        $this->langRows = SourceBatch::grouped($this->db_connection,
            "SELECT * FROM `{$this->prefix}customization_field_lang` WHERE id_customization_field IN ({ids}) ORDER BY id_lang ASC",
            'id_customization_field', array_column($items, 'id_customization_field'));

        foreach ($items as $item) {
            $this->importItem($item);
        }

        return ['count' => count($items), 'finished' => false];
    }

    private function importItem($data)
    {
        $sid = (int)$data['id_customization_field'];
        $row = IdMapper::row('customization_field', $data);
        $tid = (int)$row['id_customization_field'];

        if ((int)$row['id_product'] <= 0) {
            return;
        }

        SchemaHelper::upsert('customization_field', $row, ['id_customization_field']);

        $rows = LanguageMapper::expand(SourceBatch::of($this->langRows, $sid));

        $shopId = SchemaHelper::getTargetShopId();
        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "customization_field_lang` WHERE id_customization_field = $tid AND id_shop = $shopId");

        foreach ($rows as $lang) {
            $lang['id_customization_field'] = $tid;
            $lang['id_shop'] = $shopId;
            SchemaHelper::insertIgnore('customization_field_lang', $lang);
        }
    }
}
