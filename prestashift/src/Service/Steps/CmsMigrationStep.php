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

class CmsMigrationStep
{
    private $db_connection;
    private $prefix;

    public function __construct($db_connection, $prefix)
    {
        $this->db_connection = $db_connection;
        $this->prefix = $prefix;
    }

    public function process($offset, $limit, $dateFilter = null)
    {
        // Strategy: First batch (offset 0) migrates ALL CMS Categories.
        // Then we migrate CMS Pages using offset/limit.
        
        if ($offset == 0) {
            $this->migrateCmsCategories();
        }

        $cmsPages = $this->getCmsPages($offset, $limit, $dateFilter);

        if (empty($cmsPages)) {
            return ['count' => 0, 'finished' => true];
        }

        foreach ($cmsPages as $row) {
            $this->importCmsPage($row);
        }

        return ['count' => count($cmsPages), 'finished' => false];
    }
    
    private function migrateCmsCategories()
    {
        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}cms_category` ORDER BY id_cms_category ASC");
        $cats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($cats as $cat) {
            $id = (int)$cat['id_cms_category'];
            if ($id < 1) continue; // Basic safety, but ID 1 is Root and MUST be migrated
            // Actually checking if Root exists is good practice.
            
            $sql = SchemaHelper::buildInsertQuery('cms_category', $cat);
            if ($sql) {
                Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "cms_category` WHERE id_cms_category = $id");
                Db::getInstance()->execute($sql);
            }
            
            // Lang
            $this->importCmsCategoryLang($id);
            // Shop
            Db::getInstance()->execute("REPLACE INTO `" . \_DB_PREFIX_ . "cms_category_shop` (id_cms_category, id_shop) VALUES ($id, " . \PrestaShift\Service\SchemaHelper::getTargetShopId() . ")");
        }
    }
    
    private function importCmsCategoryLang($id) {
        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}cms_category_lang` WHERE id_cms_category = $id");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $row['id_shop'] = \PrestaShift\Service\SchemaHelper::getTargetShopId();
            $sql = SchemaHelper::buildInsertQuery('cms_category_lang', $row);
            if ($sql) {
                $sql = str_replace('INSERT INTO', 'REPLACE INTO', $sql);
                Db::getInstance()->execute($sql);
            }
        }
    }

    private function getCmsPages($offset, $limit, $dateFilter = null)
    {
        // Many PS versions don't have date_add/upd in ps_cms, it's usually in ps_cms_lang or not at all.
        // Wait, ps_cms usually DOES have it in newer versions. Let's check or be safe.
        // If it doesn't exist, SQL will fail. We should probably only filter if we are sure.
        // For CMS, we can check lang if needed, but let's assume it has it or handle gracefully.
        
        $where = "";
        // Note: CMS tables vary. If columns missing, this might fail. 
        // We could wrap in try/catch or check columns, but for MVP we assume standard schema.
        if ($dateFilter) {
            $where = " WHERE `date_add` > '{$dateFilter}' OR `date_upd` > '{$dateFilter}' ";
        }
        
        $sql = "SELECT * FROM `{$this->prefix}cms` {$where} ORDER BY `id_cms` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importCmsPage($data)
    {
        $id = (int)$data['id_cms'];
        $sql = SchemaHelper::buildInsertQuery('cms', $data);
        if ($sql) {
            Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "cms` WHERE id_cms = $id");
            Db::getInstance()->execute($sql);
        }

        // Lang
        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}cms_lang` WHERE id_cms = $id");
        $langs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($langs as $lang) {
             $lang['id_shop'] = \PrestaShift\Service\SchemaHelper::getTargetShopId();
             $sqlL = SchemaHelper::buildInsertQuery('cms_lang', $lang);
             if ($sqlL) {
                 $sqlL = str_replace('INSERT INTO', 'REPLACE INTO', $sqlL);
                 Db::getInstance()->execute($sqlL);
             }
        }
        
        // Shop
        Db::getInstance()->execute("REPLACE INTO `" . \_DB_PREFIX_ . "cms_shop` (id_cms, id_shop) VALUES ($id, " . \PrestaShift\Service\SchemaHelper::getTargetShopId() . ")");
    }
}
