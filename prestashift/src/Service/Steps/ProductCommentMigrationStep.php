<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @version   1.3.0
 */
namespace PrestaShift\Service\Steps;

use Db;
use PDO;
use Exception;
use PrestaShift\Service\IdMapper;
use PrestaShift\Service\LanguageMapper;
use PrestaShift\Service\SchemaHelper;

/**
 * Migrates product reviews from the native "productcomments" module.
 *
 * A review row (product_comment) carries both the written opinion (title +
 * content) and the star rating (grade), plus optional per-criterion ratings
 * (product_comment_grade). The rating criteria, their translations and their
 * product/category links are reference data and are migrated once, at offset 0.
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
        // No productcomments module in the target — nowhere to write
        if (!SchemaHelper::hasTable('product_comment')) {
            return ['count' => 0, 'finished' => true];
        }

        if ((int) $offset === 0) {
            $this->migrateCriteria();
        }

        $comments = $this->getComments($offset, $limit, $dateFilter);
        if (empty($comments)) {
            return ['count' => 0, 'finished' => true];
        }

        IdMapper::prepare('product_comment', array_column($comments, 'id_product_comment'));

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
            return [];
        }
    }

    private function importComment($data)
    {
        $row = IdMapper::row('product_comment', $data);
        if ((int)$row['id_product'] <= 0) {
            return; // review of a product that is not in the target
        }
        $tid = (int)$row['id_product_comment'];
        $sid = (int)$data['id_product_comment'];

        if (!SchemaHelper::upsert('product_comment', $row, ['id_product_comment'])) {
            return;
        }

        // Per-criterion grades, "useful" votes, abuse reports
        foreach (['product_comment_grade', 'product_comment_usefulness', 'product_comment_report'] as $table) {
            if (!SchemaHelper::hasTable($table)) {
                continue;
            }
            try {
                $rows = $this->db_connection->query("SELECT * FROM `{$this->prefix}{$table}` WHERE id_product_comment = $sid")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                continue;
            }
            Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "{$table}` WHERE id_product_comment = $tid");
            foreach ($rows as $r) {
                $trow = IdMapper::row($table, $r);
                if (isset($r['id_customer']) && (int)$r['id_customer'] > 0 && (int)$trow['id_customer'] <= 0) {
                    continue;
                }
                if (isset($r['id_product_comment_criterion']) && (int)$trow['id_product_comment_criterion'] <= 0) {
                    continue;
                }
                SchemaHelper::insertIgnore($table, $trow);
            }
        }
    }

    /**
     * Rating criteria with their names and product/category links.
     */
    private function migrateCriteria()
    {
        try {
            $criteria = $this->db_connection->query("SELECT * FROM `{$this->prefix}product_comment_criterion`")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return;
        }

        foreach ($criteria as $criterion) {
            $sid = (int)$criterion['id_product_comment_criterion'];
            $row = IdMapper::row('product_comment_criterion', $criterion);
            $tid = (int)$row['id_product_comment_criterion'];
            SchemaHelper::upsert('product_comment_criterion', $row, ['id_product_comment_criterion']);

            try {
                $langs = LanguageMapper::expand($this->db_connection->query("SELECT * FROM `{$this->prefix}product_comment_criterion_lang` WHERE id_product_comment_criterion = $sid")->fetchAll(PDO::FETCH_ASSOC));
            } catch (Exception $e) {
                $langs = [];
            }
            foreach ($langs as $lang) {
                $lang['id_product_comment_criterion'] = $tid;
                Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "product_comment_criterion_lang` WHERE id_product_comment_criterion = $tid AND id_lang = " . (int)$lang['id_lang']);
                SchemaHelper::insertIgnore('product_comment_criterion_lang', $lang);
            }

            foreach (['product_comment_criterion_product' => 'id_product', 'product_comment_criterion_category' => 'id_category'] as $table => $col) {
                try {
                    $links = $this->db_connection->query("SELECT * FROM `{$this->prefix}{$table}` WHERE id_product_comment_criterion = $sid")->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    continue;
                }
                foreach ($links as $link) {
                    $trow = IdMapper::row($table, $link);
                    if ((int)$trow[$col] > 0) {
                        SchemaHelper::insertIgnore($table, $trow);
                    }
                }
            }
        }
    }
}
