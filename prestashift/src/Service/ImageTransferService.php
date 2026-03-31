<?php
/**
 * PrestaShift Migration Module
 * 
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @version   1.0.0
 */
namespace PrestaShift\Service;

use Configuration;
use Tools;

class ImageTransferService
{
    private $bridgeClient;

    public function __construct($bridgeClient = null)
    {
        $this->bridgeClient = $bridgeClient;
    }

    /**
     * Download image from source URL and save to standard PS path
     */
    public function downloadAndSave($sourceUrl, $imageId, $productId, $type = 'products')
    {
        // Manual path construction to be 100% sure
        $splitIds = str_split((string)$imageId);
        $folderPath = implode('/', $splitIds);
        $imgDir = constant('_PS_PROD_IMG_DIR_');
        $folder = $imgDir . $folderPath . '/';
        
        if (!file_exists($folder)) {
            // Recursive creation
            if (!mkdir($folder, 0777, true)) {
                return false; // Failed to create directory
            }
        }

        $imagePath = $folder . $imageId . '.jpg';
        
        if ($this->bridgeClient) {
            $relativePath = "img/p/$folderPath/$imageId.jpg";
            $content = $this->bridgeClient->getFile($relativePath);
        } else {
            // Construct source URL
            // http://source.com/img/p/1/2/3/123.jpg
            $fullSourceUrl = rtrim($sourceUrl, '/') . "/img/p/$folderPath/$imageId.jpg";
            $content = Tools::file_get_contents($fullSourceUrl);
        }
        
        if ($content) {
            if (file_put_contents($imagePath, $content)) {
                // GENERATE THUMBNAILS (Crucial for BO visibility)
                $imagesTypes = \ImageType::getImagesTypes('products');
                foreach ($imagesTypes as $imageType) {
                    \ImageManager::resize(
                        $imagePath,
                        $folder . $imageId . '-' . stripslashes($imageType['name']) . '.jpg',
                        (int)$imageType['width'],
                        (int)$imageType['height']
                    );
                }
                return true;
            }
        }
        
        return false;
    }
    
    // In a real module we would also generate thumbnails here using ImageManager::resize()
}
