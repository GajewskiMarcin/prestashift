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

class TagMigrationStep
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
        $items = $this->getData($offset, $limit);

        if (empty($items)) {
            return ['count' => 0, 'finished' => true];
        }

        foreach ($items as $item) {
            $this->importTag($item);
        }

        return ['count' => count($items), 'finished' => false];
    }

    private function getData($offset, $limit)
    {
        try {
            $sql = "SELECT * FROM `{$this->prefix}tag` ORDER BY `id_tag` ASC LIMIT $limit OFFSET $offset";
            $stmt = $this->db_connection->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Tags are shared words: a tag with the same name and language already in
     * the target is reused, not duplicated.
     */
    private function importTag($data)
    {
        $sid = (int)$data['id_tag'];
        $lang = LanguageMapper::toTarget($data['id_lang']);
        if (!$lang) {
            return; // language absent in the target
        }

        $tid = IdMapper::own('tag', $sid, ['name' => $data['name'], 'id_lang' => $data['id_lang']]);

        if (!IdMapper::isLinked('tag', $sid)) {
            SchemaHelper::upsert('tag', ['id_tag' => $tid, 'id_lang' => $lang, 'name' => $data['name']], ['id_tag']);
        }

        $this->importProductTags($sid, $tid, $lang);
    }

    private function importProductTags($sid, $tid, $lang)
    {
        try {
            $rows = $this->db_connection->query("SELECT id_product FROM `{$this->prefix}product_tag` WHERE id_tag = $sid")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return;
        }

        foreach ($rows as $row) {
            $idProduct = IdMapper::ref('product', (int)$row['id_product']);
            if ($idProduct > 0) {
                SchemaHelper::insertIgnore('product_tag', ['id_product' => $idProduct, 'id_tag' => $tid, 'id_lang' => $lang]);
            }
        }
    }
}
