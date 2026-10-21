<?php
/**
 * PrestaShift Migration Module
 * 
 * @author    marcingajewski.pl <kontakt@marcingajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @attribution PrestaShift by Marcin Gajewski (marcingajewski.pl). Keep the Attribution Notice in prestashift.php — AFL-3.0 section 6.
 * @version   1.6.0
 */
namespace PrestaShift\Service;

use PDO;

/**
 * Reads the child rows of a whole batch in one query instead of one query per
 * record.
 *
 * Through the connector every query is an HTTP round trip to the source shop, so
 * a batch of 50 categories used to cost more than a hundred requests. Slow
 * source servers stop answering under that load and the migration dies on a
 * timeout. One query per child table per batch carries the same data with a
 * fraction of the traffic.
 */
class SourceBatch
{
    /**
     * @param object $conn    source connection (connector or direct database)
     * @param string $sql     query with {ids} where the id list belongs
     * @param string $groupBy column the returned rows are grouped by
     * @param array  $ids     parent ids of the current batch
     *
     * @return array parent id => its rows
     */
    public static function grouped($conn, $sql, $groupBy, array $ids)
    {
        $out = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return $out;
        }

        // Chunked so the query never grows past what the source server accepts.
        foreach (array_chunk($ids, 500) as $chunk) {
            try {
                $rows = $conn->query(str_replace('{ids}', implode(',', $chunk), $sql))->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Exception $e) {
                continue; // table missing in this PrestaShop version
            }
            foreach ($rows as $row) {
                if (isset($row[$groupBy])) {
                    $out[(int) $row[$groupBy]][] = $row;
                }
            }
        }

        return $out;
    }

    /**
     * Rows belonging to one parent, or an empty array.
     */
    public static function of(array $grouped, $id)
    {
        return isset($grouped[(int) $id]) ? $grouped[(int) $id] : [];
    }

    /**
     * Same as grouped(), but for a whole table that has no parent filter.
     */
    public static function all($conn, $sql, $groupBy)
    {
        $out = [];
        try {
            $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return $out;
        }
        foreach ($rows as $row) {
            if (isset($row[$groupBy])) {
                $out[(int) $row[$groupBy]][] = $row;
            }
        }

        return $out;
    }
}
