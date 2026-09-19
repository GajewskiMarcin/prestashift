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
use Tools;

class AttachmentMigrationStep
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
        $rows = $this->getData($offset, $limit);

        if (empty($rows)) {
            return ['count' => 0, 'finished' => true];
        }

        IdMapper::prepare('attachment', array_column($rows, 'id_attachment'));

        foreach ($rows as $row) {
            $this->importItem($row);
        }

        return ['count' => count($rows), 'finished' => false];
    }

    private function getData($offset, $limit)
    {
        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}attachment` ORDER BY id_attachment ASC LIMIT $limit OFFSET $offset");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importItem($data)
    {
        $sid = (int)$data['id_attachment'];
        $row = IdMapper::row('attachment', $data);
        $tid = (int)$row['id_attachment'];

        SchemaHelper::upsert('attachment', $row, ['id_attachment']);

        if (SchemaHelper::hasTable('attachment_shop')) {
            SchemaHelper::insertIgnore('attachment_shop', ['id_attachment' => $tid, 'id_shop' => SchemaHelper::getTargetShopId()]);
        }

        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}attachment_lang` WHERE id_attachment = $sid");
        foreach (LanguageMapper::expand($stmt->fetchAll(PDO::FETCH_ASSOC)) as $lang) {
            $lang['id_attachment'] = $tid;
            Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "attachment_lang` WHERE id_attachment = $tid AND id_lang = " . (int)$lang['id_lang']);
            SchemaHelper::insertIgnore('attachment_lang', $lang);
        }

        $stmt = $this->db_connection->query("SELECT id_product FROM `{$this->prefix}product_attachment` WHERE id_attachment = $sid");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $link) {
            $idProduct = IdMapper::ref('product', (int)$link['id_product']);
            if ($idProduct > 0) {
                SchemaHelper::insertIgnore('product_attachment', ['id_product' => $idProduct, 'id_attachment' => $tid]);
            }
        }

        // Files are stored under their hash, not their id
        if ($this->source_url && !$this->skip_files) {
            $this->downloadFile($data['file']);
        }
    }

    private function downloadFile($fileHash)
    {
        $targetPath = constant('_PS_DOWNLOAD_DIR_') . $fileHash;
        if (file_exists($targetPath)) {
            return;
        }

        $content = @Tools::file_get_contents(rtrim($this->source_url, '/') . '/download/' . $fileHash);
        if ($content) {
            file_put_contents($targetPath, $content);
        }
    }
}
