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
    public function downloadAndSave($sourceUrl, $sourceImageId, $targetImageId, $sourceProductId = 0)
    {
        $sourceFolder = implode('/', str_split((string)(int)$sourceImageId));
        $targetFolder = implode('/', str_split((string)(int)$targetImageId));

        $folder = constant('_PS_PROD_IMG_DIR_') . $targetFolder . '/';
        if (!file_exists($folder) && !mkdir($folder, 0777, true)) {
            return false;
        }

        $content = $this->fetch($sourceUrl, "img/p/$sourceFolder/$sourceImageId.jpg");

        // Shops that started on PrestaShop 1.4 or older may still keep their
        // images flat, as <product>-<image>.jpg ("legacy images" setting)
        if (!$content && (int)$sourceProductId > 0) {
            $content = $this->fetch($sourceUrl, 'img/p/' . (int)$sourceProductId . '-' . (int)$sourceImageId . '.jpg');
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

    private function fetch($sourceUrl, $path)
    {
        if ($this->bridgeClient) {
            return $this->bridgeClient->getFile($path);
        }

        return Tools::file_get_contents(rtrim($sourceUrl, '/') . '/' . $path);
    }
}
