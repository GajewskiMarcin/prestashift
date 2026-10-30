<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcingajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * Modifié par : Thierry Laval <contact@thierrylaval.dev>
 * @copyright 2026 Thierry Laval
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @attribution PrestaShift by Marcin Gajewski (marcingajewski.pl). Keep the Attribution Notice in prestashift.php — AFL-3.0 section 6.
 * @version   1.3.0
 */
namespace PrestaShift\Service\Steps;

use Db;
use PDO;
use PrestaShift\Service\IdMapper;
use PrestaShift\Service\LanguageMapper;
use PrestaShift\Service\SchemaHelper;
use PrestaShift\Service\SourceBatch;

/**
 * Taxes and tax rules groups. A tax (same name and rate) or a group (same
 * name) already present in the target is reused as it is — its rules are the
 * target's business. Everything else is added.
 */
class TaxRulesMigrationStep
{
    private $db_connection;
    private $prefix;

    /** @var array source id_tax_rules_group => rule rows, read once per batch */
    private $ruleRows = [];

    public function __construct($db_connection, $prefix)
    {
        $this->db_connection = $db_connection;
        $this->prefix = $prefix;
    }

    public function process($offset, $limit, $dateFilter = null)
    {
        if ($offset == 0) {
            $this->migrateTaxes();
        }

        $rows = $this->db_connection->query("SELECT * FROM `{$this->prefix}tax_rules_group` ORDER BY id_tax_rules_group ASC LIMIT $limit OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return ['count' => 0, 'finished' => true];
        }

        // All rules of the batch in one query instead of one query per group
        $this->ruleRows = SourceBatch::grouped($this->db_connection,
            "SELECT * FROM `{$this->prefix}tax_rule` WHERE id_tax_rules_group IN ({ids})",
            'id_tax_rules_group', array_column($rows, 'id_tax_rules_group'));

        foreach ($rows as $row) {
            $this->importGroup($row);
        }

        return ['count' => count($rows), 'finished' => false];
    }

    private function migrateTaxes()
    {
        $taxes = $this->db_connection->query("SELECT * FROM `{$this->prefix}tax`")->fetchAll(PDO::FETCH_ASSOC);

        // All tax names in one query instead of one query per tax
        $langRows = SourceBatch::grouped($this->db_connection,
            "SELECT * FROM `{$this->prefix}tax_lang` WHERE id_tax IN ({ids})",
            'id_tax', array_column($taxes, 'id_tax'));

        foreach ($taxes as $tax) {
            $sid = (int)$tax['id_tax'];
            $tid = IdMapper::own('tax', $sid);
            if (IdMapper::isLinked('tax', $sid)) {
                continue;
            }

            $tax['id_tax'] = $tid;
            SchemaHelper::upsert('tax', $tax, ['id_tax']);

            $langs = LanguageMapper::expand(SourceBatch::of($langRows, $sid));
            foreach ($langs as $lang) {
                $lang['id_tax'] = $tid;
                SchemaHelper::upsert('tax_lang', $lang, ['id_tax', 'id_lang']);
            }
        }
    }

    private function importGroup($data)
    {
        $sid = (int)$data['id_tax_rules_group'];
        $tid = IdMapper::own('tax_rules_group', $sid);
        if (IdMapper::isLinked('tax_rules_group', $sid)) {
            return;
        }

        $data['id_tax_rules_group'] = $tid;
        SchemaHelper::upsert('tax_rules_group', $data, ['id_tax_rules_group']);

        Db::getInstance()->execute("REPLACE INTO `" . _DB_PREFIX_ . "tax_rules_group_shop` (id_tax_rules_group, id_shop) VALUES ($tid, " . SchemaHelper::getTargetShopId() . ")");

        $rules = SourceBatch::of($this->ruleRows, $sid);

        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "tax_rule` WHERE id_tax_rules_group = $tid");

        foreach ($rules as $rule) {
            $row = IdMapper::row('tax_rule', $rule);
            if ((int)$row['id_country'] <= 0 || ((int)$rule['id_tax'] > 0 && (int)$row['id_tax'] <= 0)) {
                continue; // country or tax unknown in the target
            }
            if ((int)$rule['id_state'] > 0 && (int)$row['id_state'] <= 0) {
                continue;
            }
            SchemaHelper::upsert('tax_rule', $row, ['id_tax_rule']);
        }
    }
}
