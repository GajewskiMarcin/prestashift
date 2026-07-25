<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @version   1.0.0
 */
namespace PrestaShift\Service\Steps;

use Db;
use PDO;
use Exception;
use PrestaShift\Service\SchemaHelper;
use PrestaShift\Service\LanguageMapper;

/**
 * Migrates product reviews from the native "productcomments" module.
 *
 * A review row (product_comment) carries both the written opinion (title +
 * content) and the star rating (grade), plus optional per-criterion ratings
 * (product_comment_grade). The rating criteria, their translations and their
 * product/category links are reference data and are migrated once, at offset 0.
 *
 * IDs are preserved throughout, consistent with the rest of the module, so the
 * reviews stay attached to the same products and customers.
 */
class ProductCommentMigrationStep
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
        // If the target shop has no productcomments module installed, there is
        // nowhere to write — skip the whole step gracefully.
        if (!$this->targetTableExists('product_comment')) {
            return ['count' => 0, 'finished' => true];
        }

        // Reference data (criteria) — once per run, before the first batch.
        if ((int) $offset === 0) {
            $this->migrateCriteria();
        }

        $comments = $this->getComments($offset, $limit, $dateFilter);
        if (empty($comments)) {
            return ['count' => 0, 'finished' => true];
        }

        foreach ($comments as $comment) {
            $this->importComment($comment);
        }

        return ['count' => count($comments), 'finished' => false];
    }

    private function getComments($offset, $limit, $dateFilter = null)
    {
        $where = '';
        if ($dateFilter) {
            $where = " WHERE `date_add` > '" . pSQL($dateFilter) . "' ";
        }

        try {
            $sql = "SELECT * FROM `{$this->prefix}product_comment` {$where} ORDER BY `id_product_comment` ASC LIMIT $limit OFFSET $offset";
            return $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // Source shop has no productcomments data
            return [];
        }
    }

    private function importComment($data)
    {
        $id = (int) $data['id_product_comment'];

        $sql = SchemaHelper::buildUpsertQuery('product_comment', $data, ['id_product_comment']);
        if (!$sql) {
            return;
        }
        Db::getInstance()->execute($sql);

        // Per-criterion grades
        $this->copyChildRows(
            'product_comment_grade',
            "WHERE id_product_comment = $id",
            "id_product_comment = $id"
        );

        // "Was this review useful?" votes
        $this->copyChildRows(
            'product_comment_usefulness',
            "WHERE id_product_comment = $id",
            "id_product_comment = $id"
        );

        // Abuse reports
        $this->copyChildRows(
            'product_comment_report',
            "WHERE id_product_comment = $id",
            "id_product_comment = $id"
        );
    }

    /**
     * Migrates the rating criteria and everything attached to them. These are
     * small reference tables, so a full pass is cheap and keeps the target in
     * sync with the source.
     */
    private function migrateCriteria()
    {
        // 1. Criteria themselves
        try {
            $criteria = $this->db_connection
                ->query("SELECT * FROM `{$this->prefix}product_comment_criterion`")
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return; // no criteria in source
        }

        foreach ($criteria as $criterion) {
            $cid = (int) $criterion['id_product_comment_criterion'];

            $sql = SchemaHelper::buildUpsertQuery(
                'product_comment_criterion',
                $criterion,
                ['id_product_comment_criterion']
            );
            if ($sql) {
                Db::getInstance()->execute($sql);
            }

            // 2. Criterion names (language-mapped)
            $this->migrateCriterionLang($cid);
        }

        // 3. Criterion → product and criterion → category links
        $this->copyChildRows('product_comment_criterion_product', '', null, true);
        $this->copyChildRows('product_comment_criterion_category', '', null, true);
    }

    private function migrateCriterionLang($cid)
    {
        try {
            $rows = LanguageMapper::expand(
                $this->db_connection
                    ->query("SELECT * FROM `{$this->prefix}product_comment_criterion_lang` WHERE id_product_comment_criterion = $cid")
                    ->fetchAll(PDO::FETCH_ASSOC)
            );
        } catch (Exception $e) {
            return;
        }

        foreach ($rows as $row) {
            $idLang = (int) $row['id_lang'];
            Db::getInstance()->execute(
                "DELETE FROM `" . \_DB_PREFIX_ . "product_comment_criterion_lang` WHERE id_product_comment_criterion = $cid AND id_lang = $idLang"
            );
            $sql = SchemaHelper::buildInsertQuery('product_comment_criterion_lang', $row, true);
            if ($sql) {
                Db::getInstance()->execute($sql);
            }
        }
    }

    /**
     * Copies rows of a child table from source to target: clears the matching
     * target rows first (idempotent re-runs), then inserts the source rows.
     *
     * @param string      $table       table name without prefix
     * @param string      $sourceWhere WHERE clause (incl. keyword) for the source read, or '' for all
     * @param string|null $targetWhere WHERE condition (no keyword) to clear on the target, or null to skip the delete
     * @param bool        $ignore      use INSERT IGNORE (for tables with no natural single-column key)
     */
    private function copyChildRows($table, $sourceWhere, $targetWhere, $ignore = false)
    {
        try {
            $rows = $this->db_connection
                ->query("SELECT * FROM `{$this->prefix}{$table}` {$sourceWhere}")
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return; // table absent in source
        }

        if ($targetWhere !== null) {
            try {
                Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "{$table}` WHERE {$targetWhere}");
            } catch (Exception $e) {
                // target table absent — nothing to clear
            }
        }

        foreach ($rows as $row) {
            $sql = SchemaHelper::buildInsertQuery($table, $row, true);
            if (!$sql) {
                continue;
            }
            if ($ignore) {
                $sql = str_replace('INSERT INTO', 'INSERT IGNORE INTO', $sql);
            }
            try {
                Db::getInstance()->execute($sql);
            } catch (Exception $e) {
                // Skip a row the target can't accept rather than abort the batch
            }
        }
    }

    private function targetTableExists($table)
    {
        try {
            $name = \_DB_PREFIX_ . $table;
            $res = Db::getInstance()->executeS("SHOW TABLES LIKE '" . pSQL($name) . "'");
            return !empty($res);
        } catch (Exception $e) {
            return false;
        }
    }
}
