<?php
/**
 * PrestaShift Migration Module
 * 
 * @author    marcingajewski.pl <kontakt@marcingajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @attribution PrestaShift by Marcin Gajewski (marcingajewski.pl). Keep the Attribution Notice in prestashift.php — AFL-3.0 section 6.
 * @version   1.0.0
 */
namespace PrestaShift\Service;

use Db;

class SchemaHelper
{
    private static $columnsCache = [];

    /** @var array table => [column => ['type', 'nullable', 'default', 'auto']] */
    private static $definitionsCache = [];

    /** @var array table => ['count' => int, 'error' => string] — rows the target refused in this request */
    private static $failures = [];

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
        try {
            $result = Db::getInstance()->executeS($sql);
        } catch (\Throwable $e) {
            $result = []; // table absent in this PrestaShop version
        }

        $columns = [];
        $definitions = [];
        if ($result) {
            foreach ($result as $row) {
                // Key 'Field' contains the column name
                $columns[] = $row['Field'];
                $definitions[$row['Field']] = [
                    'type' => (string)$row['Type'],
                    'nullable' => $row['Null'] === 'YES',
                    'default' => $row['Default'],
                    'auto' => stripos((string)$row['Extra'], 'auto_increment') !== false,
                ];
            }
        }

        self::$columnsCache[$table] = $columns;
        self::$definitionsCache[$table] = $definitions;
        return $columns;
    }

    /**
     * Adapts a row to what the target columns accept.
     *
     * An older source has fewer columns and looser ones: PrestaShop 1.6 has no
     * product.show_condition and allows NULL in attribute.color, both NOT NULL
     * in later versions. MySQL refuses the whole row for a single NULL like
     * that, so such a column is left out and takes its default instead. An ENUM
     * value the target does not know (redirect_type '' in PrestaShop 9) would
     * be stored as an invalid empty value; it becomes the column default too.
     *
     * @param string $table Table name without prefix
     * @param array $data Row already reduced to existing columns
     * @return array
     */
    private static function fitToTarget($table, array $data)
    {
        $definitions = self::$definitionsCache[$table];

        foreach ($data as $column => $value) {
            $definition = $definitions[$column];

            if ($value === null) {
                if (!$definition['nullable']) {
                    unset($data[$column]);
                }
                continue;
            }

            $allowed = self::enumValues($definition['type']);
            if ($allowed === null || in_array(strtolower((string)$value), $allowed, true)) {
                continue;
            }
            if ($definition['default'] !== null || $definition['nullable']) {
                $data[$column] = $definition['default'];
            }
        }

        return $data;
    }

    /**
     * Puts an empty text where the given columns are NULL or missing.
     *
     * PrestaShop never stores NULL in the text fields of its own objects, and
     * the back office of PrestaShop 1.7.7+ refuses to open a product, a
     * combination or an order that has one ("must be of type string, null
     * given"). NULLs come from sources that lack the column (isbn and mpn in
     * PrestaShop 1.6) or allow NULL in it (ean13, upc, reference).
     *
     * @param array $row
     * @param array $columns
     * @return array
     */
    public static function blankNulls(array $row, array $columns)
    {
        foreach ($columns as $column) {
            if (!isset($row[$column])) {
                $row[$column] = '';
            }
        }

        return $row;
    }

    /**
     * Values of an ENUM column type, lower-cased (MySQL compares them
     * case-insensitively); null for any other type.
     */
    private static function enumValues($type)
    {
        if (stripos($type, 'enum(') !== 0) {
            return null;
        }
        preg_match_all("/'((?:[^']|'')*)'/", $type, $matches);

        return array_map(function ($value) {
            return strtolower(str_replace("''", "'", $value));
        }, $matches[1]);
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

        $filtered = self::fitToTarget($table, $filtered);

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

        $filtered = self::fitToTarget($table, $filtered);
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

        return self::write($table, $sql);
    }

    /**
     * INSERT IGNORE of one row (link tables whose whole row is the key).
     */
    public static function insertIgnore($table, $data)
    {
        $sql = self::buildInsertQuery($table, $data, true);
        if (empty($sql)) return true;

        return self::write($table, preg_replace('/^INSERT INTO/', 'INSERT IGNORE INTO', $sql));
    }

    /**
     * Runs a write and keeps track of what the target refused. A refused row
     * used to vanish without a trace: the steps skip it and carry on, so a
     * migration could end "successfully" with a whole entity missing.
     *
     * @param string $table Table name without prefix
     * @param string $sql
     * @return bool
     */
    private static function write($table, $sql)
    {
        try {
            $done = (bool)Db::getInstance()->execute($sql);
            $error = $done ? '' : (string)Db::getInstance()->getMsgError();
        } catch (\Exception $e) {
            // PrestaShop's debug mode turns a refused query into an exception
            // carrying the whole statement; the first line is the MySQL error
            $done = false;
            $error = trim(strip_tags(preg_replace('/<br\b.*$/s', '', $e->getMessage())));
        }

        if (!$done) {
            if (!isset(self::$failures[$table])) {
                self::$failures[$table] = ['count' => 0, 'error' => $error];
            }
            self::$failures[$table]['count']++;
        }

        return $done;
    }

    /**
     * Rows refused by the target since the last call, then forgotten.
     *
     * @return array table => ['count' => int, 'error' => first MySQL error]
     */
    public static function pullFailures()
    {
        $failures = self::$failures;
        self::$failures = [];

        return $failures;
    }

    public static function hasTable($table)
    {
        return !empty(self::getTableColumns($table));
    }
}
