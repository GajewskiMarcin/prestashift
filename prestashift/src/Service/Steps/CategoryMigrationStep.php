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

class CategoryMigrationStep
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
        // Root category (ID 1, 2) handling is tricky. 
        // We usually assume ID 1 (Root) and ID 2 (Home) exist in target.
        // We might need to UPDATE them instead of INSERT if IDs match.
        // For simplicity, we 'INSERT IGNORE' or 'REPLACE' but let's be careful.
        
        $categories = $this->getCategoriesFromSource($offset, $limit, $dateFilter);

        if (empty($categories)) {
            return ['count' => 0, 'finished' => true];
        }

        foreach ($categories as $category) {
            $this->importCategory($category);
        }

        return ['count' => count($categories), 'finished' => false];
    }

    private function getCategoriesFromSource($offset, $limit, $dateFilter = null)
    {
        $where = "";
        if ($dateFilter) {
            $where = " WHERE `date_add` > '{$dateFilter}' OR `date_upd` > '{$dateFilter}' ";
        }
        $sql = "SELECT * FROM `{$this->prefix}category` {$where} ORDER BY `id_category` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importCategory($data)
    {
        $id = (int)$data['id_category'];
        
        // SAFETY: We MUST allow Root (1) and Home (2) to be imported/updated
        // because CleanupService truncates the table. If we skip them, the tree is broken.
        // if ($id <= 2) { return; }

        // Check if exists (for higher IDs that might clash)
        
        $exists = Db::getInstance()->getValue("SELECT id_category FROM " . \_DB_PREFIX_ . "category WHERE id_category = $id");

        // Data sanitization handled by SchemaHelper
        // $data = array_map(...) removed to avoid double quoting

        if ($exists) {
            $date_add = pSQL($data['date_add']);
            $date_upd = pSQL($data['date_upd']);
            
            $sql = "UPDATE `" . \_DB_PREFIX_ . "category` SET
                id_parent = " . (int)$data['id_parent'] . ",
                id_shop_default = " . (int)$data['id_shop_default'] . ",
                level_depth = " . (int)$data['level_depth'] . ",
                active = " . (int)$data['active'] . ",
                date_add = '$date_add',
                date_upd = '$date_upd',
                position = " . (int)$data['position'] . ",
                is_root_category = " . (int)$data['is_root_category'] . "
                WHERE id_category = $id";
        } else {
            $catData = [
                'id_category' => $id,
                'id_parent' => $data['id_parent'],
                'id_shop_default' => \PrestaShift\Service\SchemaHelper::getTargetShopId(), // Force to default shop to avoid foreign key issues
                'level_depth' => $data['level_depth'],
                'nleft' => $data['nleft'],
                'nright' => $data['nright'],
                'active' => $data['active'],
                'date_add' => $data['date_add'],
                'date_upd' => $data['date_upd'],
                'position' => $data['position'],
                'is_root_category' => $data['is_root_category']
            ];
            $sql = \PrestaShift\Service\SchemaHelper::buildInsertQuery('category', $catData, true); // pSQL true because we removed manual map
        }
        Db::getInstance()->execute($sql);

        // Lang
        $this->importCategoryLang($id);
        
        // Group & Shop association (simplified to default for now)
        $this->importCategoryShop($id); // Assuming 1:1 shop map
        $this->importCategoryGroup($id);
    }

    private function importCategoryLang($id_category)
    {
        $sql = "SELECT * FROM `{$this->prefix}category_lang` WHERE id_category = $id_category";
        $stmt = $this->db_connection->query($sql);
        $langs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($langs as $lang) {
            $id_lang = (int)$lang['id_lang']; // Assuming mapping is correct or handled elsewhere
            $name = pSQL($lang['name']);
            $link_rewrite = pSQL($lang['link_rewrite']);
            // ... other fields
            
            // cleanup first
            Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "category_lang` WHERE id_category = $id_category AND id_lang = $id_lang");
            
            $langData = [
                'id_category' => $id_category,
                'id_shop' => \PrestaShift\Service\SchemaHelper::getTargetShopId(),
                'id_lang' => $id_lang,
                'name' => $name,
                'description' => $lang['description'], // buildInsertQuery handles pSQL if 3rd arg is true
                'additional_description' => isset($lang['additional_description']) ? $lang['additional_description'] : null,
                'link_rewrite' => $link_rewrite,
                'meta_title' => $lang['meta_title'],
                'meta_keywords' => isset($lang['meta_keywords']) ? $lang['meta_keywords'] : null,
                'meta_description' => $lang['meta_description']
            ];

            // Filter out fields that don't exist in target table (e.g. meta_keywords in PS9)
            // pSQL handling is done inside buildInsertQuery by default
            
            $sql = \PrestaShift\Service\SchemaHelper::buildInsertQuery('category_lang', $langData, true);
            
            if ($sql) {
                 Db::getInstance()->execute($sql);
            }
        }
    }
    
    private function importCategoryShop($id_category) {
        Db::getInstance()->execute("REPLACE INTO `" . \_DB_PREFIX_ . "category_shop` (id_category, id_shop, position) VALUES ($id_category, " . \PrestaShift\Service\SchemaHelper::getTargetShopId() . ", 0)");
    }
    
    private function importCategoryGroup($id_category) {
        // Grant access to all groups (1, 2, 3) by default
        Db::getInstance()->execute("REPLACE INTO `" . \_DB_PREFIX_ . "category_group` (id_category, id_group) VALUES ($id_category, 1), ($id_category, 2), ($id_category, 3)");
    }

    public function restoreRootCategories()
    {
        $shopId = \PrestaShift\Service\SchemaHelper::getTargetShopId();

        // 1. Root Category
        Db::getInstance()->execute("
            INSERT IGNORE INTO `" . \_DB_PREFIX_ . "category`
            (id_category, id_parent, id_shop_default, level_depth, nleft, nright, active, date_add, date_upd, position, is_root_category)
            VALUES
            (1, 0, " . $shopId . ", 0, 1, 0, 1, NOW(), NOW(), 0, 1)
        ");

        Db::getInstance()->execute("
            INSERT IGNORE INTO `" . \_DB_PREFIX_ . "category_lang` (id_category, id_shop, id_lang, name, link_rewrite, description)
            VALUES (1, " . $shopId . ", 1, 'Root', 'root', '')
        ");

        Db::getInstance()->execute("REPLACE INTO `" . \_DB_PREFIX_ . "category_shop` (id_category, id_shop, position) VALUES (1, " . $shopId . ", 0)");
        Db::getInstance()->execute("REPLACE INTO `" . \_DB_PREFIX_ . "category_group` (id_category, id_group) VALUES (1, 1), (1, 2), (1, 3)");

        // 2. Home Category
        Db::getInstance()->execute("
            INSERT IGNORE INTO `" . \_DB_PREFIX_ . "category`
            (id_category, id_parent, id_shop_default, level_depth, nleft, nright, active, date_add, date_upd, position, is_root_category)
            VALUES
            (2, 1, " . $shopId . ", 1, 2, 0, 1, NOW(), NOW(), 0, 0)
        ");

        Db::getInstance()->execute("
            INSERT IGNORE INTO `" . \_DB_PREFIX_ . "category_lang` (id_category, id_shop, id_lang, name, link_rewrite, description)
            VALUES (2, " . $shopId . ", 1, 'Home', 'home', 'The main category of your shop.')
        ");

        Db::getInstance()->execute("REPLACE INTO `" . \_DB_PREFIX_ . "category_shop` (id_category, id_shop, position) VALUES (2, " . $shopId . ", 0)");
        Db::getInstance()->execute("REPLACE INTO `" . \_DB_PREFIX_ . "category_group` (id_category, id_group) VALUES (2, 1), (2, 2), (2, 3)");
    }
}
