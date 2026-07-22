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
        $sql = SchemaHelper::buildUpsertQuery('product_download', $data, ['id_product_download']);
        if ($sql) {
            try {
                Db::getInstance()->execute($sql);
            } catch (\Exception $e) {
                // Log error
            }
        }

        // File download
        if (!$this->skip_files && !empty($data['filename'])) {
            try {
                $sourceFile = $this->db_connection->getFile('download/' . $data['filename']);
                if ($sourceFile) {
                    if (!defined('_PS_DOWNLOAD_DIR_')) {
                        define('_PS_DOWNLOAD_DIR_', _PS_ROOT_DIR_ . '/download/');
                    }
                    @file_put_contents(_PS_DOWNLOAD_DIR_ . $data['filename'], $sourceFile);
                }
            } catch (\Exception $e) {
            }
        }
    }
}
