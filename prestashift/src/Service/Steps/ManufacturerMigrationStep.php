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
use PrestaShift\Service\SchemaHelper;

class ManufacturerMigrationStep
{
    private $db_connection;
    private $prefix;
    private $source_url;
    private $skip_files;

    public function __construct($db_connection, $prefix, $source_url = '', $skip_files = false)
    {
        $this->db_connection = $db_connection;
        $this->prefix = $prefix;
        $this->source_url = $source_url;
        $this->skip_files = $skip_files;
    }

    public function process($offset, $limit, $dateFilter = null)
    {
        $items = $this->getManufacturers($offset, $limit);

        if (empty($items)) {
            return ['count' => 0, 'finished' => true];
        }

        foreach ($items as $item) {
            $this->importManufacturer($item);
            
            // Image
            if ($this->source_url && !$this->skip_files) {
                $this->downloadImage($item['id_manufacturer']);
            }
        }

        return ['count' => count($items), 'finished' => false];
    }

    private function getManufacturers($offset, $limit)
    {
        $sql = "SELECT * FROM `{$this->prefix}manufacturer` ORDER BY `id_manufacturer` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importManufacturer($data)
    {
        $id = (int)$data['id_manufacturer'];
        
        $mainData = [
            'id_manufacturer' => $id,
            'name' => $data['name'],
            'date_add' => $data['date_add'],
            'date_upd' => $data['date_upd'],
            'active' => $data['active']
        ];
        
        $sql = SchemaHelper::buildUpsertQuery('manufacturer', $mainData, ['id_manufacturer']);
        if ($sql) {
            Db::getInstance()->execute($sql);
        }
        
        // Shop
        $shopData = [
            'id_manufacturer' => $id,
            'id_shop' => \PrestaShift\Service\SchemaHelper::getTargetShopId(),
        ];
        $sqlShop = SchemaHelper::buildInsertQuery('manufacturer_shop', $shopData, true);
        if ($sqlShop) {
            $sqlShop = str_replace('INSERT INTO', 'INSERT IGNORE INTO', $sqlShop);
            Db::getInstance()->execute($sqlShop);
        }

        // Lang
        $this->importLang($id);
    }
    
    private function importLang($id_manufacturer)
    {
        $sql = "SELECT * FROM `{$this->prefix}manufacturer_lang` WHERE id_manufacturer = $id_manufacturer";
        $stmt = $this->db_connection->query($sql);
        $langs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($langs as $lang) {
            $langData = [
                'id_manufacturer' => $id_manufacturer,
                'id_lang' => (int)$lang['id_lang'],
                'description' => $lang['description'],
                'short_description' => $lang['short_description'],
                'meta_title' => $lang['meta_title'],
                'meta_keywords' => isset($lang['meta_keywords']) ? $lang['meta_keywords'] : null,
                'meta_description' => $lang['meta_description']
            ];
            
            Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "manufacturer_lang` WHERE id_manufacturer = $id_manufacturer AND id_lang = {$langData['id_lang']}");
            
            $sql = SchemaHelper::buildInsertQuery('manufacturer_lang', $langData, true);
            if ($sql) {
                Db::getInstance()->execute($sql);
            }
        }
    }
    private function downloadImage($id_manufacturer)
    {
        $manuDir = constant('_PS_MANU_IMG_DIR_');
        $targetPath = $manuDir . $id_manufacturer . '.jpg';
        
        // CLEANUP: Delete existing main file and thumbnails to ensure no broken files stay on disk
        if (file_exists($targetPath)) {
            @unlink($targetPath);
        }
        $imagesTypes = \ImageType::getImagesTypes('manufacturers');
        foreach ($imagesTypes as $imageType) {
            $thumbPath = $manuDir . $id_manufacturer . '-' . stripslashes($imageType['name']) . '.jpg';
            if (file_exists($thumbPath)) {
                @unlink($thumbPath);
            }
        }
        
        // http://source.com/img/m/5.jpg
        $sourceUrl = rtrim($this->source_url, '/') . "/img/m/{$id_manufacturer}.jpg";
        
        $content = @\Tools::file_get_contents($sourceUrl);
        if ($content) {
            // Validate image content before saving
            $imageInfo = @getimagesizefromstring($content);
            if (!$imageInfo) {
                // Not a valid image (e.g. 404 HTML page) - leave it deleted
                return;
            }

            file_put_contents($targetPath, $content);
            
            // Generate thumbnails
            foreach ($imagesTypes as $imageType) {
                @\ImageManager::resize(
                    $targetPath,
                    $manuDir . $id_manufacturer . '-' . stripslashes($imageType['name']) . '.jpg',
                    (int)$imageType['width'],
                    (int)$imageType['height']
                );
            }
        }
    }
}
