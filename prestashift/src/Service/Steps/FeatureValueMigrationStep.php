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

class FeatureValueMigrationStep
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
        $sql = "SELECT * FROM `{$this->prefix}feature_value` ORDER BY `id_feature_value` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            return ['count' => 0, 'finished' => true];
        }

        IdMapper::prepare('feature_value', array_column($items, 'id_feature_value'));

        foreach ($items as $item) {
            $this->importValue($item);
        }

        return ['count' => count($items), 'finished' => false];
    }

    private function importValue($data)
    {
        $sid = (int)$data['id_feature_value'];
        $row = IdMapper::row('feature_value', [
            'id_feature_value' => $sid,
            'id_feature' => $data['id_feature'],
            'custom' => (int)$data['custom'],
        ]);
        if ((int)$row['id_feature'] <= 0) {
            return;
        }
        $tid = (int)$row['id_feature_value'];

        SchemaHelper::upsert('feature_value', $row, ['id_feature_value']);

        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}feature_value_lang` WHERE id_feature_value = $sid");
        $langs = LanguageMapper::expand($stmt->fetchAll(PDO::FETCH_ASSOC));

        foreach ($langs as $lang) {
            $idLang = (int)$lang['id_lang'];
            Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "feature_value_lang` WHERE id_feature_value = $tid AND id_lang = $idLang");
            SchemaHelper::insertIgnore('feature_value_lang', [
                'id_feature_value' => $tid,
                'id_lang' => $idLang,
                'value' => $lang['value'],
            ]);
        }
    }
}
