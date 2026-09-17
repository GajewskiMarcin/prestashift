<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @version   1.3.0
 */
namespace PrestaShift\Service;

use Tools;

class ImageTransferService
{
    private $bridgeClient;

    public function __construct($bridgeClient = null)
    {
        $this->bridgeClient = $bridgeClient;
    }

    /**
     * Downloads a product image from the source (stored under its source id)
     * and saves it under its target id, with thumbnails.
     */
    public function downloadAndSave($sourceUrl, $sourceImageId, $targetImageId)
    {
        $sourceFolder = implode('/', str_split((string)(int)$sourceImageId));
        $targetFolder = implode('/', str_split((string)(int)$targetImageId));

        $folder = constant('_PS_PROD_IMG_DIR_') . $targetFolder . '/';
        if (!file_exists($folder) && !mkdir($folder, 0777, true)) {
            return false;
        }

        if ($this->bridgeClient) {
            $content = $this->bridgeClient->getFile("img/p/$sourceFolder/$sourceImageId.jpg");
        } else {
            $content = Tools::file_get_contents(rtrim($sourceUrl, '/') . "/img/p/$sourceFolder/$sourceImageId.jpg");
        }

        if (!$content) {
            return false;
        }

        $imagePath = $folder . $targetImageId . '.jpg';
        if (!file_put_contents($imagePath, $content)) {
            return false;
        }

        // Thumbnails (required for the back office to show the image)
        foreach (\ImageType::getImagesTypes('products') as $imageType) {
            \ImageManager::resize(
                $imagePath,
                $folder . $targetImageId . '-' . stripslashes($imageType['name']) . '.jpg',
                (int)$imageType['width'],
                (int)$imageType['height']
            );
        }

        return true;
    }
}
