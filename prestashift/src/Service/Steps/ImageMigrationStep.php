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

        foreach ($images as $image) {
            $this->importImage($image);
        }

        return ['count' => count($images), 'finished' => false];
    }

    private function getImagesFromSource($offset, $limit)
    {
        $sql = "SELECT * FROM `{$this->prefix}image` ORDER BY `id_image` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importImage($data)
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

        $this->importImageLang($sid, $tid);

        $coverVal = $row['cover'] ? 1 : 'NULL';
        Db::getInstance()->execute("REPLACE INTO `" . _DB_PREFIX_ . "image_shop` (id_image, id_product, id_shop, cover) VALUES ($tid, $tidProduct, " . SchemaHelper::getTargetShopId() . ", $coverVal)");

        // File: read under the source id, written under the target id
        if ($this->source_url && !$this->skip_files) {
            $this->transferService->downloadAndSave($this->source_url, $sid, $tid);
        }
    }

    private function importImageLang($sid, $tid)
    {
        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}image_lang` WHERE id_image = $sid");
        $langs = LanguageMapper::expand($stmt->fetchAll(PDO::FETCH_ASSOC));

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
