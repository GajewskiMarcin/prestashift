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

use PDO;
use PrestaShift\Service\DirectDbClient;
use PrestaShift\Service\IdMapper;
use PrestaShift\Service\LogService;
use PrestaShift\Service\SchemaHelper;

class ProductDownloadMigrationStep
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
        try {
            $sql = "SELECT * FROM `{$this->prefix}product_download` ORDER BY id_product_download ASC LIMIT $limit OFFSET $offset";
            $stmt = $this->db_connection->query($sql);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return ['count' => 0, 'finished' => true];
        }

        if (empty($items)) {
            return ['count' => 0, 'finished' => true];
        }

        foreach ($items as $item) {
            $this->importItem($item);
        }

        return ['count' => count($items), 'finished' => false];
    }

    private function importItem($data)
    {
        $row = IdMapper::row('product_download', $data);
        if ((int)$row['id_product'] <= 0) {
            return;
        }

        SchemaHelper::upsert('product_download', $row, ['id_product_download']);

        // Files are stored under a hash name, not an id
        if (!$this->skip_files && !empty($data['filename'])) {
            $filename = (string)$data['filename'];
            if ($filename === '.' || $filename === '..' || strpbrk($filename, '/\\') !== false || strpos($filename, "\0") !== false) {
                LogService::getInstance()->warning('A virtual product file was skipped because its source filename is invalid.');
                return;
            }

            try {
                $sourceFile = $this->db_connection instanceof DirectDbClient
                    ? $this->db_connection->getFile('download/' . $filename, isset($data['display_filename']) ? $data['display_filename'] : '')
                    : $this->db_connection->getFile('download/' . $filename);
                $displayFilename = isset($data['display_filename']) ? $data['display_filename'] : '';
                if ($sourceFile === false || $sourceFile === '' || $this->isUnexpectedHtml($sourceFile, $displayFilename)) {
                    LogService::getInstance()->warning(
                        "Virtual product file " . basename($filename) . " could not be fetched as a valid file from the source shop."
                    );
                    return;
                }

                if (!defined('_PS_DOWNLOAD_DIR_')) {
                    define('_PS_DOWNLOAD_DIR_', _PS_ROOT_DIR_ . '/download/');
                }
                if (@file_put_contents(_PS_DOWNLOAD_DIR_ . $data['filename'], $sourceFile) === false) {
                    LogService::getInstance()->warning(
                        "Virtual product file " . basename($filename) . " could not be written to the target download directory."
                    );
                }
            } catch (\Exception $e) {
                LogService::getInstance()->warning(
                    "Virtual product file " . basename($filename) . " could not be fetched: " . $e->getMessage()
                );
            }
        }
    }

    private function isUnexpectedHtml($content, $displayFilename)
    {
        $extension = strtolower(pathinfo((string)$displayFilename, PATHINFO_EXTENSION));
        if (in_array($extension, ['html', 'htm', 'xhtml'], true)) {
            return false;
        }

        $prefix = ltrim(substr($content, 0, 512), "\xEF\xBB\xBF \t\n\r\0\x0B");
        return (bool)preg_match('/^(?:<!doctype\s+html\b|<html\b|<head\b|<body\b)/i', $prefix);
    }
}
