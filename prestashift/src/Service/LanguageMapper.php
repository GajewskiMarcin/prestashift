<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @version   1.0.0
 */
namespace PrestaShift\Service;

use Db;
use PDO;

/**
 * Translates language IDs between the source and the target shop.
 *
 * Language IDs are per-shop: a shop where Polish was installed first has
 * pl = 1, while a target shop installed in English has en = 1. Copying rows
 * verbatim would therefore file Polish content under English and vice versa.
 * Matching is done on iso_code instead of the numeric ID.
 *
 * The target may also carry languages the source never had. Those would end up
 * with no *_lang rows at all, leaving product/category/CMS names blank in the
 * back office. For them the source's default-language row is duplicated, so the
 * field is populated and can be translated later.
 */
class LanguageMapper
{
    /** @var array|null  source id_lang => target id_lang */
    private static $map = null;

    /** @var array  target id_lang values with no counterpart in the source */
    private static $unmappedTargets = [];

    /** @var int|null  default id_lang of the source shop */
    private static $sourceDefault = null;

    /**
     * Builds the mapping. Must be called before any step processes *_lang rows;
     * static state does not survive between requests, so the manager re-inits
     * it on every batch.
     */
    public static function init($db_connection, $prefix)
    {
        self::$map = [];
        self::$unmappedTargets = [];
        self::$sourceDefault = null;

        // Source languages
        $source = [];
        try {
            $stmt = $db_connection->query("SELECT `id_lang`, `iso_code` FROM `{$prefix}lang`");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $source[strtolower($row['iso_code'])] = (int) $row['id_lang'];
            }
        } catch (\Exception $e) {
            return;
        }

        if (empty($source)) {
            return;
        }

        // Target languages
        $target = [];
        $rows = Db::getInstance()->executeS('SELECT `id_lang`, `iso_code` FROM `' . \_DB_PREFIX_ . 'lang`');
        if ($rows) {
            foreach ($rows as $row) {
                $target[strtolower($row['iso_code'])] = (int) $row['id_lang'];
            }
        }

        foreach ($source as $iso => $srcId) {
            if (isset($target[$iso])) {
                self::$map[$srcId] = $target[$iso];
            }
        }

        foreach ($target as $iso => $tgtId) {
            if (!isset($source[$iso])) {
                self::$unmappedTargets[] = $tgtId;
            }
        }

        // Source default language — the content copied into unmapped targets
        try {
            $stmt = $db_connection->query(
                "SELECT `value` FROM `{$prefix}configuration` WHERE `name` = 'PS_LANG_DEFAULT'"
            );
            $value = $stmt->fetchColumn();
            if ($value !== false && isset(self::$map[(int) $value])) {
                self::$sourceDefault = (int) $value;
            }
        } catch (\Exception $e) {
            // fall through to the first mapped language
        }

        if (self::$sourceDefault === null) {
            $ids = array_keys(self::$map);
            self::$sourceDefault = !empty($ids) ? $ids[0] : (!empty($source) ? reset($source) : null);
        }
    }

    /**
     * Translates one source language ID. Returns null when the target shop has
     * no such language — the caller should skip that row rather than insert it
     * under a foreign ID.
     */
    public static function toTarget($sourceLangId)
    {
        $sourceLangId = (int) $sourceLangId;

        if (self::$map === null) {
            return $sourceLangId; // not initialised — behave as before
        }

        return isset(self::$map[$sourceLangId]) ? self::$map[$sourceLangId] : null;
    }

    /**
     * Same as toTarget(), but never returns null — for entity columns such as
     * customer.id_lang or orders.id_lang, where a NULL or a foreign ID would
     * break the record. Falls back to the target shop's default language.
     */
    public static function toTargetOrDefault($sourceLangId)
    {
        $mapped = self::toTarget($sourceLangId);
        if ($mapped !== null) {
            return $mapped;
        }

        $default = (int) \Configuration::get('PS_LANG_DEFAULT');

        return $default > 0 ? $default : (int) $sourceLangId;
    }

    /**
     * Takes *_lang rows as fetched from the source and returns the rows to write
     * into the target: IDs translated, unmappable rows dropped, and one extra
     * copy per target-only language filled from the source default.
     *
     * @param array  $rows Rows containing an id_lang column
     * @param string $key  Column holding the language ID
     * @return array
     */
    public static function expand(array $rows, $key = 'id_lang')
    {
        if (self::$map === null || empty($rows)) {
            return $rows;
        }

        $out = [];
        $defaultRow = null;

        foreach ($rows as $row) {
            if (!isset($row[$key])) {
                $out[] = $row;
                continue;
            }

            $srcId = (int) $row[$key];
            $tgtId = self::toTarget($srcId);

            if ($tgtId === null) {
                continue; // language absent in target — dropping beats corrupting
            }

            $row[$key] = $tgtId;
            $out[] = $row;

            if ($srcId === self::$sourceDefault) {
                $defaultRow = $row;
            }
        }

        // Fill target-only languages so their fields are not left empty
        if (!empty(self::$unmappedTargets)) {
            if ($defaultRow === null && !empty($out)) {
                $defaultRow = $out[0];
            }
            if ($defaultRow !== null) {
                foreach (self::$unmappedTargets as $tgtId) {
                    $copy = $defaultRow;
                    $copy[$key] = $tgtId;
                    $out[] = $copy;
                }
            }
        }

        return $out;
    }

    /**
     * Diagnostics for the migration log.
     */
    public static function describe()
    {
        if (self::$map === null) {
            return 'language mapping not initialised';
        }

        $pairs = [];
        foreach (self::$map as $src => $tgt) {
            $pairs[] = $src . '->' . $tgt;
        }

        return sprintf(
            'languages mapped: [%s], target-only filled from source default %s: [%s]',
            implode(', ', $pairs),
            self::$sourceDefault === null ? '?' : self::$sourceDefault,
            implode(', ', self::$unmappedTargets)
        );
    }
}
