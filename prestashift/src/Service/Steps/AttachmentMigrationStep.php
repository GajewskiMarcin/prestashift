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
use Tools;

class AttachmentMigrationStep
{
    private $db_connection;
    private $prefix;
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
        $id = (int)$data['id_attachment'];
        $sql = SchemaHelper::buildInsertQuery('attachment', $data);
        if ($sql) {
            Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "attachment` WHERE id_attachment = $id");
            Db::getInstance()->execute($sql);
        }

        // Lang
        $this->importLang($id);
        
        // Product Associations
        $this->importProductAttachments($id);

        // FILE DOWNLOAD
        if ($this->source_url && !$this->skip_files) {
            $this->downloadFile($data['file'], $data['file_name']); // file is the hash, file_name is original name
        }
    }

    private function importLang($id)
    {
        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}attachment_lang` WHERE id_attachment = $id");
        $langs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($langs as $lang) {
            $sql = SchemaHelper::buildInsertQuery('attachment_lang', $lang);
            if ($sql) {
                $sql = str_replace('INSERT INTO', 'REPLACE INTO', $sql);
                Db::getInstance()->execute($sql);
            }
        }
    }
    
    private function importProductAttachments($id_attachment)
    {
         $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}product_attachment` WHERE id_attachment = $id_attachment");
         $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
         foreach ($rows as $row) {
              $sql = SchemaHelper::buildInsertQuery('product_attachment', $row);
              if ($sql) {
                  $sql = str_replace('INSERT INTO', 'REPLACE INTO', $sql);
                  Db::getInstance()->execute($sql);
              }
         }
    }

    private function downloadFile($fileHash, $originalName)
    {
        // Source URL likely: http://source.com/download/fileHash
        // Target path: _PS_DOWNLOAD_DIR_ . fileHash
        
        $downloadDir = constant('_PS_DOWNLOAD_DIR_');
        $targetPath = $downloadDir . $fileHash;
        
        if (file_exists($targetPath)) {
            return; // Already exists
        }
        
        $downloadUrl = rtrim($this->source_url, '/') . '/download/' . $fileHash;
        
        // Try to fetch
        $content = @Tools::file_get_contents($downloadUrl);
        if ($content) {
            file_put_contents($targetPath, $content);
        }
    }
}
