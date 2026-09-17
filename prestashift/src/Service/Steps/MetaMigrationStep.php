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

/**
 * SEO meta of shop pages. The page name is unique: a page the target already
 * has keeps its record. Its titles and friendly URLs are replaced by the
 * source's only when "Clean target data" was chosen (the source wins);
 * otherwise the target's own texts stay. Pages the target does not have are
 * added.
 */
class MetaMigrationStep
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
        if ($offset != 0) {
            return ['count' => 0, 'finished' => true];
        }

        try {
            $items = $this->db_connection->query("SELECT * FROM `{$this->prefix}meta` ORDER BY id_meta ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return ['count' => 0, 'finished' => true];
        }

        $overwrite = (bool)IdMapper::option('clean_target');

        foreach ($items as $data) {
            $sid = (int)$data['id_meta'];
            $tid = IdMapper::own('meta', $sid, ['page' => $data['page']]);
            $linked = IdMapper::isLinked('meta', $sid);

            if ($linked && !$overwrite) {
                continue;
            }
            if (!$linked) {
                $data['id_meta'] = $tid;
                SchemaHelper::upsert('meta', $data, ['id_meta']);
            }

            $this->importMetaLang($sid, $tid);
        }

        return ['count' => count($items), 'finished' => true];
    }

    private function importMetaLang($sid, $tid)
    {
        $shopId = SchemaHelper::getTargetShopId();
        $rows = LanguageMapper::expand($this->db_connection->query("SELECT * FROM `{$this->prefix}meta_lang` WHERE id_meta = $sid ORDER BY id_shop ASC")->fetchAll(PDO::FETCH_ASSOC));

        $done = [];
        foreach ($rows as $lang) {
            if (isset($done[(int)$lang['id_lang']])) {
                continue;
            }
            $done[(int)$lang['id_lang']] = true;
            $lang['id_meta'] = $tid;
            $lang['id_shop'] = $shopId;
            SchemaHelper::upsert('meta_lang', $lang, ['id_meta', 'id_shop', 'id_lang']);
        }
    }
}
