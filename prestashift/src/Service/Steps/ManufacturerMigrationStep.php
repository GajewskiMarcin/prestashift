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
use PrestaShift\Service\LanguageMapper;
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

        IdMapper::prepare('manufacturer', array_column($items, 'id_manufacturer'));

        foreach ($items as $item) {
            $sid = (int)$item['id_manufacturer'];
            $tid = $this->importManufacturer($item);

            if ($this->source_url && !$this->skip_files) {
                $this->downloadImage($sid, $tid);
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
        $sid = (int)$data['id_manufacturer'];
        $tid = IdMapper::own('manufacturer', $sid);

        SchemaHelper::upsert('manufacturer', [
            'id_manufacturer' => $tid,
            'name' => $data['name'],
            'date_add' => $data['date_add'],
            'date_upd' => $data['date_upd'],
            'active' => $data['active'],
        ], ['id_manufacturer']);

        SchemaHelper::insertIgnore('manufacturer_shop', [
            'id_manufacturer' => $tid,
            'id_shop' => SchemaHelper::getTargetShopId(),
        ]);

        $this->importLang($sid, $tid);

        return $tid;
    }

    private function importLang($sid, $tid)
    {
        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}manufacturer_lang` WHERE id_manufacturer = $sid");
        $langs = LanguageMapper::expand($stmt->fetchAll(PDO::FETCH_ASSOC));

        foreach ($langs as $lang) {
            $idLang = (int)$lang['id_lang'];
            Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "manufacturer_lang` WHERE id_manufacturer = $tid AND id_lang = $idLang");
            SchemaHelper::insertIgnore('manufacturer_lang', [
                'id_manufacturer' => $tid,
                'id_lang' => $idLang,
                'description' => $lang['description'],
                'short_description' => $lang['short_description'],
                'meta_title' => $lang['meta_title'],
                'meta_keywords' => isset($lang['meta_keywords']) ? $lang['meta_keywords'] : null,
                'meta_description' => $lang['meta_description'],
            ]);
        }
    }

    /**
     * Logo is read under the source id and saved under the target id.
     */
    private function downloadImage($sid, $tid)
    {
        $manuDir = constant('_PS_MANU_IMG_DIR_');
        $targetPath = $manuDir . $tid . '.jpg';
        $imagesTypes = \ImageType::getImagesTypes('manufacturers');

        $content = @\Tools::file_get_contents(rtrim($this->source_url, '/') . "/img/m/{$sid}.jpg");
        if (!$content || !@getimagesizefromstring($content)) {
            return; // no logo (or a 404 page) — keep whatever the target has
        }

        // Replace old files only once the new image is known to be valid
        @unlink($targetPath);
        foreach ($imagesTypes as $imageType) {
            @unlink($manuDir . $tid . '-' . stripslashes($imageType['name']) . '.jpg');
        }

        file_put_contents($targetPath, $content);

        foreach ($imagesTypes as $imageType) {
            @\ImageManager::resize(
                $targetPath,
                $manuDir . $tid . '-' . stripslashes($imageType['name']) . '.jpg',
                (int)$imageType['width'],
                (int)$imageType['height']
            );
        }
    }
}
