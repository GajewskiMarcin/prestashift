<?php
/**
 * PrestaShift Migration Module
 * 
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @version   1.0.0
 */
namespace PrestaShift\Service\Steps;

use Db;
use PDO;
use PrestaShift\Service\ImageTransferService;

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
        $id_image = (int)$data['id_image'];
        $id_product = (int)$data['id_product'];
        
        // 1. Insert DB Record
        // 1. Insert DB Record
        $imageData = [
            'id_image' => $id_image,
            'id_product' => $id_product,
            'position' => isset($data['position']) ? $data['position'] : 0,
            'cover' => isset($data['cover']) ? $data['cover'] : null
        ];
        
        $sql = \PrestaShift\Service\SchemaHelper::buildInsertQuery('image', $imageData, true);
        
        if ($sql) {
            $sql .= " ON DUPLICATE KEY UPDATE position = VALUES(position), cover = VALUES(cover)";
            try {
                Db::getInstance()->execute($sql);
            } catch (\Exception $e) {
                // Log
            }
        }

        // 2. Import Lang
        $this->importImageLang($id_image);
        
        // 3. Import Shop
        $coverVal = isset($data['cover']) && $data['cover'] !== null ? (int)$data['cover'] : 'NULL';
        Db::getInstance()->execute("REPLACE INTO `" . \_DB_PREFIX_ . "image_shop` (id_image, id_product, id_shop, cover) VALUES ($id_image, $id_product, " . \PrestaShift\Service\SchemaHelper::getTargetShopId() . ", $coverVal)");

        // 4. Download File
        if ($this->source_url && !$this->skip_files) {
            $this->transferService->downloadAndSave($this->source_url, $id_image, $id_product);
        }
    }

    private function importImageLang($id_image)
    {
        $sql = "SELECT * FROM `{$this->prefix}image_lang` WHERE id_image = $id_image";
        $stmt = $this->db_connection->query($sql);
        $langs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($langs as $lang) {
            $langData = [
                'id_image' => $id_image,
                'id_lang' => (int)$lang['id_lang'],
                'legend' => $lang['legend']
            ];
            
            Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "image_lang` WHERE id_image = $id_image AND id_lang = {$langData['id_lang']}");
            
            $sql = \PrestaShift\Service\SchemaHelper::buildInsertQuery('image_lang', $langData, true);
            if ($sql) {
                Db::getInstance()->execute($sql);
            }
        }
    }
}
