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

class FeatureValueMigrationStep
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
        $sql = "SELECT * FROM `{$this->prefix}feature_value` ORDER BY `id_feature_value` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            return ['count' => 0, 'finished' => true];
        }

        foreach ($items as $item) {
            $this->importValue($item);
        }

        return ['count' => count($items), 'finished' => false];
    }

    private function importValue($data)
    {
        $id = (int)$data['id_feature_value'];
        $id_feature = (int)$data['id_feature'];
        $custom = (int)$data['custom'];
        
        $mainData = [
            'id_feature_value' => $id,
            'id_feature' => $id_feature,
            'custom' => $custom
        ];
        
        $sql = SchemaHelper::buildInsertQuery('feature_value', $mainData, true);
        if ($sql) {
            $sql .= " ON DUPLICATE KEY UPDATE custom = VALUES(custom)";
            Db::getInstance()->execute($sql);
        }
        
        // Lang
        $this->importLang($id);
    }
    
    private function importLang($id_value)
    {
        $sql = "SELECT * FROM `{$this->prefix}feature_value_lang` WHERE id_feature_value = $id_value";
        $stmt = $this->db_connection->query($sql);
        $langs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($langs as $lang) {
            $langData = [
                'id_feature_value' => $id_value,
                'id_lang' => (int)$lang['id_lang'],
                'value' => $lang['value']
            ];
            
            Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "feature_value_lang` WHERE id_feature_value = $id_value AND id_lang = {$langData['id_lang']}");
            
            $sql = SchemaHelper::buildInsertQuery('feature_value_lang', $langData, true);
            if ($sql) {
                Db::getInstance()->execute($sql);
            }
        }
    }
}
