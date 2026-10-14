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
use PrestaShift\Service\ImageTransferService;
use PrestaShift\Service\LanguageMapper;
use PrestaShift\Service\SchemaHelper;

class ImageMigrationStep
{
    const TIME_BUDGET = 15;

    private $db_connection;
    private $prefix;
    private $source_url;
    private $transferService;

    private $skip_files;

    public function __construct($db_connection, $prefix, $source_url, $skip_files = false)
    {
        $this->db_connection = $db_connection;
        $this->prefix = $prefix;
        $this->source_url = $source_url;
        $this->skip_files = $skip_files;

        $bridge = ($db_connection instanceof \PrestaShift\Service\ConnectorClient) ? $db_connection : null;
        $this->transferService = new ImageTransferService($bridge);
    }

    public function process($offset, $limit, $dateFilter = null)
    {
        // Batch size for images should be small (e.g. 5-10) to avoid timeouts
        $images = $this->getImagesFromSource($offset, $limit);

        if (empty($images)) {
            return ['count' => 0, 'finished' => true];
        }

        IdMapper::prepare('image', array_column($images, 'id_image'));
        IdMapper::prepare('product', array_column($images, 'id_product'));

        $legends = $this->getLegendsFromSource(array_column($images, 'id_image'));
        // Counted from the start of the request: what came before the images is part of what the server allows
        $startedAt = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float)$_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);
        $deadline = $startedAt + $this->timeBudget();
        $imported = 0;
        $slowest = 0;

        foreach ($images as $image) {
            $imageStartedAt = microtime(true);
            $sid = (int)$image['id_image'];
            $this->importImage($image, isset($legends[$sid]) ? $legends[$sid] : []);
            $imported++;

            // Stop while there is still room for an image as slow as the slowest so far
            $slowest = max($slowest, microtime(true) - $imageStartedAt);
            if (microtime(true) + $slowest >= $deadline) {
                break;
            }
        }

        return ['count' => $imported, 'finished' => false];
    }

    /**
     * Seconds one batch may spend on images. A picture costs what no other
     * row costs — a download, then a dozen thumbnails — so a batch counted in
     * images can run for minutes on a shop with large photos, and the web
     * server cuts the request off (HTTP 500 or 504). Tried again, the same
     * batch runs just as long. When the time is up the batch ends with the
     * images it has finished, and the next one goes on from there.
     */
    private function timeBudget()
    {
        $maxExecutionTime = (int)ini_get('max_execution_time');

        return $maxExecutionTime > 0 ? min(self::TIME_BUDGET, $maxExecutionTime / 2) : self::TIME_BUDGET;
    }

    /**
     * Legends of the whole batch in one query: through the connector every
     * query is a request to the source shop.
     */
    private function getLegendsFromSource(array $imageIds)
    {
        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}image_lang` WHERE id_image IN (" . implode(',', array_map('intval', $imageIds)) . ")");

        $legends = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $legends[(int)$row['id_image']][] = $row;
        }

        return $legends;
    }

    private function getImagesFromSource($offset, $limit)
    {
        $sql = "SELECT * FROM `{$this->prefix}image` ORDER BY `id_image` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importImage($data, array $legends)
    {
        $sid = (int)$data['id_image'];
        $row = IdMapper::row('image', [
            'id_image' => $sid,
            'id_product' => $data['id_product'],
            'position' => isset($data['position']) ? $data['position'] : 0,
            'cover' => isset($data['cover']) && $data['cover'] ? 1 : null,
        ]);
        $tid = (int)$row['id_image'];
        $tidProduct = (int)$row['id_product'];

        if ($tidProduct <= 0) {
            return; // image of a product that was not migrated
        }

        SchemaHelper::upsert('image', $row, ['id_image']);

        $this->importImageLang($tid, $legends);

        $coverVal = $row['cover'] ? 1 : 'NULL';
        Db::getInstance()->execute("REPLACE INTO `" . _DB_PREFIX_ . "image_shop` (id_image, id_product, id_shop, cover) VALUES ($tid, $tidProduct, " . SchemaHelper::getTargetShopId() . ", $coverVal)");

        // File: read under the source id, written under the target id
        if ($this->source_url && !$this->skip_files) {
            $this->transferService->downloadAndSave($this->source_url, $sid, $tid, (int)$data['id_product']);
        }
    }

    private function importImageLang($tid, array $legends)
    {
        $langs = LanguageMapper::expand($legends);

        foreach ($langs as $lang) {
            $idLang = (int)$lang['id_lang'];
            Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "image_lang` WHERE id_image = $tid AND id_lang = $idLang");
            SchemaHelper::insertIgnore('image_lang', [
                'id_image' => $tid,
                'id_lang' => $idLang,
                'legend' => $lang['legend'],
            ]);
        }
    }
}
