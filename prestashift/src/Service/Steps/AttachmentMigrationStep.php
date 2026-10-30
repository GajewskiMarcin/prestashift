<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcingajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * Modifié par : Thierry Laval <contact@thierrylaval.dev>
 * @copyright 2026 Thierry Laval
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @attribution PrestaShift by Marcin Gajewski (marcingajewski.pl). Keep the Attribution Notice in prestashift.php — AFL-3.0 section 6.
 * @version   1.3.0
 */
namespace PrestaShift\Service\Steps;

use Db;
use PDO;
use PrestaShift\Service\ConnectorClient;
use PrestaShift\Service\IdMapper;
use PrestaShift\Service\LogService;
use PrestaShift\Service\LanguageMapper;
use PrestaShift\Service\SchemaHelper;
use PrestaShift\Service\SourceBatch;
use Tools;

class AttachmentMigrationStep
{
    private $db_connection;
    private $prefix;

    /** @var array source id_attachment => rows, each read once per batch */
    private $langRows = [];
    private $productRows = [];
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
        // Two queries for the whole batch instead of two per attachment
        $ids = array_column($rows, 'id_attachment');
        $this->langRows = SourceBatch::grouped($this->db_connection,
            "SELECT * FROM `{$this->prefix}attachment_lang` WHERE id_attachment IN ({ids})",
            'id_attachment', $ids);
        $this->productRows = SourceBatch::grouped($this->db_connection,
            "SELECT id_attachment, id_product FROM `{$this->prefix}product_attachment` WHERE id_attachment IN ({ids})",
            'id_attachment', $ids);

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

        foreach (LanguageMapper::expand(SourceBatch::of($this->langRows, $sid)) as $lang) {
            $lang['id_attachment'] = $tid;
            Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "attachment_lang` WHERE id_attachment = $tid AND id_lang = " . (int)$lang['id_lang']);
            SchemaHelper::insertIgnore('attachment_lang', $lang);
        }

        foreach (SourceBatch::of($this->productRows, $sid) as $link) {
            $idProduct = IdMapper::ref('product', (int)$link['id_product']);
            if ($idProduct > 0) {
                SchemaHelper::insertIgnore('product_attachment', ['id_product' => $idProduct, 'id_attachment' => $tid]);
            }
        }

        // Files are stored under their hash, not their id
        if ($this->source_url && !$this->skip_files) {
            $this->downloadFile($data['file'], isset($data['file_size']) ? (int) $data['file_size'] : 0);
        }
    }

    /**
     * Fetches one attachment file.
     *
     * PrestaShop protects the download/ directory with its own .htaccess, so
     * asking for the file over plain HTTP comes back as the web server's
     * "403 Forbidden" page. This method used to write whatever came back
     * straight to disk, which is how a shop ended up with 277-byte HTML error
     * pages in place of its attachments — and nothing said a word about it.
     *
     * Through the connector the file is read from the source shop's disk, which
     * is the only way that works, so that is the path taken whenever the
     * migration runs on the bridge. The size recorded in the attachment row is
     * checked afterwards: anything of a different length is refused rather than
     * saved over the file.
     */
    private function downloadFile($fileHash, $expectedSize = 0)
    {
        $targetPath = constant('_PS_DOWNLOAD_DIR_') . $fileHash;
        // A file that is already there and the right size needs no fetching.
        // One of the wrong size does: that is how a shop repairs itself after a
        // run that saved a 403 page over its attachments, which "skip whatever
        // exists" would have left broken for good.
        if (file_exists($targetPath)
            && ($expectedSize <= 0 || (int) filesize($targetPath) === $expectedSize)) {
            return;
        }

        $log = LogService::getInstance();
        $content = false;

        if ($this->db_connection instanceof ConnectorClient) {
            $content = $this->db_connection->getFile('download/' . $fileHash);
        } elseif ($this->source_url) {
            $content = @Tools::file_get_contents(rtrim($this->source_url, '/') . '/download/' . $fileHash);
        }

        if ($content === false || $content === '' || $content === null) {
            $log->warning("Attachment file $fileHash could not be fetched from the source shop."
                . ' With a direct database connection the file cannot be read at all, because PrestaShop blocks'
                . ' the download/ directory: copy that folder to the new shop by hand, or migrate over the'
                . ' connector.');

            return;
        }

        if ($expectedSize > 0 && strlen($content) !== $expectedSize) {
            $log->warning("Attachment file $fileHash came back with " . strlen($content) . ' bytes instead of '
                . $expectedSize . ' — not saved, because that is an error page or a truncated file, not the'
                . ' attachment. The attachment itself is migrated; copy the file by hand or migrate over the'
                . ' connector.');

            return;
        }

        if (!file_put_contents($targetPath, $content)) {
            $log->warning("Attachment file $fileHash could not be written to " . constant('_PS_DOWNLOAD_DIR_')
                . ' — check the directory permissions.');
        }
    }
}
