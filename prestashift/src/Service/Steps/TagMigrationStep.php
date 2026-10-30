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

class TagMigrationStep
{
    private $db_connection;
    private $prefix;

    /** @var array product-tag links of the batch, written in one statement */
    private $links = [];

    /** @var array source id_tag => product link rows, read once per batch */
    private $productRows = [];

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

        // All product links of the batch in one query instead of one per tag
        $this->productRows = SourceBatch::grouped($this->db_connection,
            "SELECT id_tag, id_product FROM `{$this->prefix}product_tag` WHERE id_tag IN ({ids})",
            'id_tag', array_column($items, 'id_tag'));

        // The products those links point at, resolved in one bulk query instead
        // of one lookup per link.
        $products = [];
        foreach ($this->productRows as $rows) {
            foreach ($rows as $row) {
                $products[] = (int)$row['id_product'];
            }
        }
        IdMapper::prepare('product', $products);

        $this->links = [];
        foreach ($items as $item) {
            $this->importTag($item);
        }
        $this->writeLinks();

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
        $rows = SourceBatch::of($this->productRows, $sid);

        foreach ($rows as $row) {
            $idProduct = IdMapper::ref('product', (int)$row['id_product']);
            if ($idProduct > 0) {
                $this->links["$idProduct:$tid:$lang"] = "($idProduct, $tid, $lang)";
            }
        }
    }

    /**
     * One statement for every product-tag link of the batch.
     *
     * A row at a time meant five hundred INSERTs per batch of fifty tags, which
     * measured 5 to 6 seconds against 0.3 seconds for the feature links, which
     * were already written this way — and one batch took 28 seconds, close
     * enough to a 30 second request limit to kill a migration on a slower
     * server.
     */
    private function writeLinks()
    {
        if (empty($this->links)) {
            return;
        }
        foreach (array_chunk(array_values($this->links), 500) as $chunk) {
            Db::getInstance()->execute('INSERT IGNORE INTO `' . _DB_PREFIX_ . 'product_tag`
                (id_product, id_tag, id_lang) VALUES ' . implode(',', $chunk));
        }
        $this->links = [];
    }
}
