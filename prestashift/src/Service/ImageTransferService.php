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
use PrestaShift\Service\DirectDbClient;

class ImageTransferService
{
    private $fileClient;

    public function __construct($fileClient = null)
    {
        $this->fileClient = $fileClient;
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

        if ($this->fileClient) {
            $content = $this->fileClient instanceof DirectDbClient
                ? $this->fileClient->getFile("img/p/$sourceFolder/$sourceImageId.jpg", "$sourceImageId.jpg")
                : $this->fileClient->getFile("img/p/$sourceFolder/$sourceImageId.jpg");
        } else {
            $content = Tools::file_get_contents(rtrim($sourceUrl, '/') . "/img/p/$sourceFolder/$sourceImageId.jpg");
        }

        if (!$content) {
            return false;
        }

        // What comes back has to be an image. A web server that answers 403
        // or 404 answers with an HTML page, and writing that page as <id>.jpg
        // leaves the shop with a broken image and no complaint anywhere.
        if (@getimagesizefromstring($content) === false) {
            LogService::getInstance()->warning("Image $sourceImageId was not an image when fetched from the source"
                . ' shop (' . strlen($content) . ' bytes, probably an error page) — not saved.');

            return false;
        }

        $imagePath = $folder . $targetImageId . '.jpg';
        if (!file_put_contents($imagePath, $content)) {
            return false;
        }

        // Only the original is written. PrestaShop builds the thumbnails itself
        // the first time each one is needed: the front office does it in
        // ImageRetriever::checkOrGenerateImageType() (same code in 1.7.8, 8.2
        // and 9.1), and the back office in ImageManager::thumbnail(), which
        // resizes from the original into img/tmp. Generating every image type
        // here instead meant five resize operations per image — half a million
        // of them on a shop with 100 000 images — while the shopkeeper waits,
        // for files the shop would have produced on demand anyway.
        //
        // A shopkeeper who wants them all ready up front has the tools for it:
        // "Regenerate thumbnails" in the back office, or, from 9.1,
        // `php bin/console prestashop:thumbnails:regenerate products`.

        return true;
    }
}
