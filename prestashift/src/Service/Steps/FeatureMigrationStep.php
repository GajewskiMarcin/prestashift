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

class FeatureMigrationStep
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
        // Features are usually small tables, but let's stick to pagination
        $items = $this->getFeatures($offset, $limit);

        if (empty($items)) {
            // Once features are done, we also need to migrate FeatureValues and ProductFeatures
            // But we can do Values in a separate pass? 
            // Better strategy:
            // 1. Migrate Features (small) - do all at once if offset=0? No, stick to batch.
            // 2. Migrate FeatureValues (can be large)
            // 3. Migrate FeatureProduct (large)
            
            // For MVP simplicity: migrating features and values in one step might be okay if not huge.
            // But let's split logic:
            // process() will handle Features.
            // When Features empty, we could trigger Values?
            // To keep simple structure in Manager, let's create separate logical 'tasks' in Manager 
            // OR handle all sub-tables here if limit isn't reached.
            
            return ['count' => 0, 'finished' => true];
        }

        foreach ($items as $item) {
            $this->importFeature($item);
        }

        // Sub-import: Values and Assignments
        // This is tricky with offsets. 
        // Best approach for Manager structure: 
        // 1. Task 'features' -> migrates ps_feature
        // 2. Task 'feature_values' -> migrates ps_feature_value
        // 3. Task 'feature_products' -> migrates ps_feature_product
        
        // For now, let's just do Features definition. 
        // We will need to add more tasks to Manager.

        return ['count' => count($items), 'finished' => false];
    }

    private function getFeatures($offset, $limit)
    {
        $sql = "SELECT * FROM `{$this->prefix}feature` ORDER BY `id_feature` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importFeature($data)
    {
        $id = (int)$data['id_feature'];
        
        $mainData = [
            'id_feature' => $id,
            'position' => $data['position']
        ];
        
        $sql = SchemaHelper::buildInsertQuery('feature', $mainData, true);
        if ($sql) {
            $sql .= " ON DUPLICATE KEY UPDATE position = VALUES(position)";
            Db::getInstance()->execute($sql);
        }
        
        // Shop
        $shopData = ['id_feature' => $id, 'id_shop' => \PrestaShift\Service\SchemaHelper::getTargetShopId()];
        $sqlShop = SchemaHelper::buildInsertQuery('feature_shop', $shopData, true);
        if ($sqlShop) {
             $sqlShop = str_replace('INSERT INTO', 'INSERT IGNORE INTO', $sqlShop);
             Db::getInstance()->execute($sqlShop);
        }

        // Lang
        $this->importLang($id);
    }
    
    private function importLang($id_feature)
    {
        $sql = "SELECT * FROM `{$this->prefix}feature_lang` WHERE id_feature = $id_feature";
        $stmt = $this->db_connection->query($sql);
        $langs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($langs as $lang) {
            $langData = [
                'id_feature' => $id_feature,
                'id_lang' => (int)$lang['id_lang'],
                'name' => $lang['name']
            ];
            
            Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "feature_lang` WHERE id_feature = $id_feature AND id_lang = {$langData['id_lang']}");
            
            $sql = SchemaHelper::buildInsertQuery('feature_lang', $langData, true);
            if ($sql) {
                Db::getInstance()->execute($sql);
            }
        }
    }
}
