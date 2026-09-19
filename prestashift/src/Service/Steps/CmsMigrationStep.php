<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
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

class CmsMigrationStep
{
    private $db_connection;
    private $prefix;
    private $source_url;
    private $skip_files;

    public function __construct($db_connection, $prefix, $source_url = '', $skip_files = false)
    {
        $this->db_connection = $db_connection;
        $this->prefix = $prefix;
        $this->source_url = rtrim($source_url, '/');
        $this->skip_files = $skip_files;
    }

    public function process($offset, $limit, $dateFilter = null)
    {
        // First batch migrates all CMS categories, then pages page by page
        if ($offset == 0) {
            $this->migrateCmsCategories();
        }

        // ps_cms has no date columns — every run reads all pages; the id map
        // turns repeated pages into updates
        $cmsPages = $this->db_connection->query("SELECT * FROM `{$this->prefix}cms` ORDER BY `id_cms` ASC LIMIT $limit OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($cmsPages)) {
            return ['count' => 0, 'finished' => true];
        }

        IdMapper::prepare('cms', array_column($cmsPages, 'id_cms'));

        foreach ($cmsPages as $row) {
            $this->importCmsPage($row);
        }

        return ['count' => count($cmsPages), 'finished' => false];
    }

    private function migrateCmsCategories()
    {
        $cats = $this->db_connection->query("SELECT * FROM `{$this->prefix}cms_category` ORDER BY id_cms_category ASC")->fetchAll(PDO::FETCH_ASSOC);
        $shopId = SchemaHelper::getTargetShopId();

        foreach ($cats as $cat) {
            $sid = (int)$cat['id_cms_category'];
            if ($sid < 1) {
                continue;
            }
            $tid = IdMapper::own('cms_category', $sid);
            if (IdMapper::isLinked('cms_category', $sid)) {
                continue; // the target's own root
            }

            $row = IdMapper::row('cms_category', $cat);
            if ((int)$row['id_parent'] <= 0) {
                $row['id_parent'] = 1;
            }
            SchemaHelper::upsert('cms_category', $row, ['id_cms_category']);

            $langs = LanguageMapper::expand($this->db_connection->query("SELECT * FROM `{$this->prefix}cms_category_lang` WHERE id_cms_category = $sid ORDER BY id_shop ASC")->fetchAll(PDO::FETCH_ASSOC));
            $done = [];
            foreach ($langs as $lang) {
                if (isset($done[(int)$lang['id_lang']])) {
                    continue;
                }
                $done[(int)$lang['id_lang']] = true;
                $lang['id_cms_category'] = $tid;
                $lang['id_shop'] = $shopId;
                SchemaHelper::upsert('cms_category_lang', $lang, ['id_cms_category', 'id_shop', 'id_lang']);
            }

            Db::getInstance()->execute("REPLACE INTO `" . _DB_PREFIX_ . "cms_category_shop` (id_cms_category, id_shop) VALUES ($tid, $shopId)");
        }
    }

    private function importCmsPage($data)
    {
        $sid = (int)$data['id_cms'];
        $row = IdMapper::row('cms', $data);
        $tid = (int)$row['id_cms'];
        if ((int)$row['id_cms_category'] <= 0) {
            $row['id_cms_category'] = 1;
        }

        SchemaHelper::upsert('cms', $row, ['id_cms']);

        $shopId = SchemaHelper::getTargetShopId();
        $langs = LanguageMapper::expand($this->db_connection->query("SELECT * FROM `{$this->prefix}cms_lang` WHERE id_cms = $sid ORDER BY id_shop ASC")->fetchAll(PDO::FETCH_ASSOC));
        $done = [];
        foreach ($langs as $lang) {
            if (isset($done[(int)$lang['id_lang']])) {
                continue;
            }
            $done[(int)$lang['id_lang']] = true;
            $lang['id_cms'] = $tid;
            $lang['id_shop'] = $shopId;
            SchemaHelper::upsert('cms_lang', $lang, ['id_cms', 'id_shop', 'id_lang']);
        }

        Db::getInstance()->execute("REPLACE INTO `" . _DB_PREFIX_ . "cms_shop` (id_cms, id_shop) VALUES ($tid, $shopId)");

        // Images embedded in the content (stored by file name, not id)
        if (!$this->skip_files) {
            foreach ($langs as $lang) {
                $this->downloadImagesFromHtml(isset($lang['content']) ? $lang['content'] : '');
            }
        }
    }

    /**
     * Parse HTML content for images and download them from source
     */
    private function downloadImagesFromHtml($html)
    {
        if (empty($html)) return;

        $patterns = [
            '/src=["\'](?:https?:\/\/[^"\']*?)?(\/?)img\/cms\/([^"\']+)["\']/i',
            '/url\(["\']?(?:https?:\/\/[^"\']*?)?(\/?)img\/cms\/([^"\')\s]+)["\']?\)/i',
        ];

        $files = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[2] as $filename) {
                    $filename = trim($filename);
                    if (!empty($filename)) {
                        $files[$filename] = true;
                    }
                }
            }
        }

        if (empty($files)) return;

        $cmsImgDir = _PS_IMG_DIR_ . 'cms/';
        if (!is_dir($cmsImgDir)) {
            @mkdir($cmsImgDir, 0755, true);
        }

        foreach (array_keys($files) as $filename) {
            $targetPath = $cmsImgDir . $filename;

            $dir = dirname($targetPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            if (file_exists($targetPath)) {
                continue;
            }

            try {
                $fileData = $this->db_connection->getFile('img/cms/' . $filename);
                if ($fileData) {
                    @file_put_contents($targetPath, $fileData);
                    continue;
                }
            } catch (\Exception $e) {}

            if ($this->source_url) {
                try {
                    $fileData = @file_get_contents($this->source_url . '/img/cms/' . $filename);
                    if ($fileData) {
                        @file_put_contents($targetPath, $fileData);
                    }
                } catch (\Exception $e) {}
            }
        }
    }
}
