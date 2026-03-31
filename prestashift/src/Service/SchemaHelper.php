<?php
/**
 * PrestaShift Migration Module
 * 
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @version   1.0.0
 */
namespace PrestaShift\Service;

use Db;

class SchemaHelper
{
    private static $columnsCache = [];

    /**
     * Target shop ID — used by all migration steps instead of hardcoded 1
     */
    private static $targetShopId = 1;

    public static function setTargetShopId($id)
    {
        self::$targetShopId = (int)$id;
    }

    public static function getTargetShopId()
    {
        return self::$targetShopId;
    }

    /**
     * Returns an array of valid column names for a given table (without prefix).
     * e.g. 'product', 'category_lang'
     */
    public static function getTableColumns($table)
    {
        if (isset(self::$columnsCache[$table])) {
            return self::$columnsCache[$table];
        }

        $tableName = \_DB_PREFIX_ . $table;
        // Check if table exists first to avoid fatal errors if something is really wrong, 
        // though usually we know the table should exist.
        // We use executeS to get the structure.
        $sql = "SHOW COLUMNS FROM `$tableName`";
        $result = Db::getInstance()->executeS($sql);

        $columns = [];
        if ($result) {
            foreach ($result as $row) {
                // Key 'Field' contains the column name
                $columns[] = $row['Field'];
            }
        }

        self::$columnsCache[$table] = $columns;
        return $columns;
    }

    /**
     * Filters an associative array of data, keeping only keys that correspond
     * to existing columns in the target table.
     *
     * @param string $table Table name without prefix (e.g. 'product_lang')
     * @param array $data Associative array of data ['col' => 'val', ...]
     * @return array Filtered data
     */
    public static function filterData($table, $data)
    {
        $validColumns = self::getTableColumns($table);
        $validColumnsMap = array_flip($validColumns); // for fast lookup

        return array_filter($data, function($key) use ($validColumnsMap) {
            return isset($validColumnsMap[$key]);
        }, ARRAY_FILTER_USE_KEY);
    }
    
    /**
     * Constructs a safe INSERT statement dynamically based on available columns.
     * 
     * @param string $table Table name without prefix
     * @param array $data Data to insert
     * @param bool $pSQL Whether to apply pSQL to values (default: true)
     * @return string SQL string
     */
    public static function buildInsertQuery($table, $data, $pSQL = true)
    {
        $filtered = self::filterData($table, $data);
        
        if (empty($filtered)) {
            return '';
        }

        $keys = array_keys($filtered);
        $values = array_values($filtered);

        $keysStr = '`' . implode('`, `', $keys) . '`';
        
        $escapedValues = array_map(function($v) use ($pSQL) {
            if ($v === null) return 'NULL';
            // If already escaped or special handling needed, caller should handle, 
            // but for safety we usually assume the caller passes raw data or we pSQL it here.
            // However, existing steps often use pSQL manually. 
            // Let's assume standard pSQL usage if $pSQL is true.
            return "'" . ($pSQL ? pSQL($v, true) : $v) . "'";
        }, $values);
        
        $valuesStr = implode(', ', $escapedValues);

        return "INSERT INTO `" . \_DB_PREFIX_ . "$table` ($keysStr) VALUES ($valuesStr)";
    }
    /**
     * Builds a safe INSERT ... ON DUPLICATE KEY UPDATE statement.
     * 
     * @param string $table Table name without prefix
     * @param array $data Data to insert/update
     * @param array $excludeFromUpdate Columns to exclude from the UPDATE part (typically ID)
     * @return string SQL string
     */
    public static function buildUpsertQuery($table, $data, $excludeFromUpdate = [])
    {
        $filtered = self::filterData($table, $data);
        if (empty($filtered)) return '';

        $keys = array_keys($filtered);
        $keysStr = '`' . implode('`, `', $keys) . '`';
        
        $escapedValues = array_map(function($v) {
            return ($v === null) ? 'NULL' : "'" . pSQL($v, true) . "'";
        }, array_values($filtered));
        $valuesStr = implode(', ', $escapedValues);

        $sql = "INSERT INTO `" . \_DB_PREFIX_ . "$table` ($keysStr) VALUES ($valuesStr)";
        
        $updateParts = [];
        foreach ($keys as $key) {
            if (!in_array($key, $excludeFromUpdate)) {
                $updateParts[] = "`$key` = VALUES(`$key`)";
            }
        }

        if (!empty($updateParts)) {
            $sql .= " ON DUPLICATE KEY UPDATE " . implode(', ', $updateParts);
        }

        return $sql;
    }

    /**
     * Executes an UPSERT query directly.
     * 
     * @param string $table Table name without prefix
     * @param array $data Data to insert/update
     * @param array $excludeFromUpdate Columns to exclude from the UPDATE part
     * @return bool
     */
    public static function upsert($table, $data, $excludeFromUpdate = [])
    {
        $sql = self::buildUpsertQuery($table, $data, $excludeFromUpdate);
        if (empty($sql)) return true;
        
        return Db::getInstance()->execute($sql);
    }
}
