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

class FeatureMigrationStep
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
        $items = $this->getFeatures($offset, $limit);

        if (empty($items)) {
            return ['count' => 0, 'finished' => true];
        }

        IdMapper::prepare('feature', array_column($items, 'id_feature'));
        // One query for the whole batch instead of one per record
        $this->langRows = SourceBatch::grouped($this->db_connection,
            "SELECT * FROM `{$this->prefix}feature_lang` WHERE id_feature IN ({ids})",
            'id_feature', array_column($items, 'id_feature'));

        foreach ($items as $item) {
            $this->importFeature($item);
        }

        return ['count' => count($items), 'finished' => false];
    }

    private function getFeatures($offset, $limit)
    {
        $sql = "SELECT * FROM `{$this->prefix}feature` ORDER BY `id_feature` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importFeature($data)
    {
        $sid = (int)$data['id_feature'];
        $tid = IdMapper::own('feature', $sid);

        SchemaHelper::upsert('feature', [
            'id_feature' => $tid,
            'position' => $data['position'],
        ], ['id_feature']);

        SchemaHelper::insertIgnore('feature_shop', ['id_feature' => $tid, 'id_shop' => SchemaHelper::getTargetShopId()]);

        $this->importLang($sid, $tid);
    }

    private function importLang($sid, $tid)
    {
        $langs = LanguageMapper::expand(SourceBatch::of($this->langRows, $sid));

        foreach ($langs as $lang) {
            $idLang = (int)$lang['id_lang'];
            Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "feature_lang` WHERE id_feature = $tid AND id_lang = $idLang");
            SchemaHelper::insertIgnore('feature_lang', [
                'id_feature' => $tid,
                'id_lang' => $idLang,
                'name' => $lang['name'],
            ]);
        }
    }
}
