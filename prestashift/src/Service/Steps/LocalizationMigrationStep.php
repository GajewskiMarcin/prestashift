<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @attribution PrestaShift by Marcin Gajewski (marcingajewski.pl). Keep the Attribution Notice in prestashift.php — AFL-3.0 section 6.
 * @version   1.3.0
 */
namespace PrestaShift\Service\Steps;

use Db;
use PDO;
use PrestaShift\Service\IdMapper;
use PrestaShift\Service\LanguageMapper;
use PrestaShift\Service\LogService;
use PrestaShift\Service\SchemaHelper;

/**
 * Countries and currencies. Both exist in every shop under ISO codes: records
 * the target already has are matched by ISO code and left as they are; only
 * ones the target lacks are added.
 *
 * Languages are not copied as rows — a language needs its translation pack,
 * which only PrestaShop's installer provides. Content is mapped onto the
 * target's languages by ISO code (LanguageMapper).
 */
class LocalizationMigrationStep
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
        if ($offset == 0) {
            $this->migrateCurrencies();
            $this->migrateCountries();
            $this->reportLanguages();
        }

        return ['count' => 1, 'finished' => true];
    }

    private function migrateCountries()
    {
        $shopId = SchemaHelper::getTargetShopId();
        $rows = $this->db_connection->query("SELECT * FROM `{$this->prefix}country`")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $sid = (int)$row['id_country'];
            IdMapper::own('country', $sid);
            if (IdMapper::isLinked('country', $sid)) {
                continue;
            }

            $trow = IdMapper::row('country', $row);
            $tid = (int)$trow['id_country'];
            if ((int)$trow['id_zone'] <= 0) {
                $trow['id_zone'] = (int)Db::getInstance()->getValue("SELECT MIN(id_zone) FROM `" . _DB_PREFIX_ . "zone`", false);
            }
            SchemaHelper::upsert('country', $trow, ['id_country']);

            $langs = LanguageMapper::expand($this->db_connection->query("SELECT * FROM `{$this->prefix}country_lang` WHERE id_country = $sid")->fetchAll(PDO::FETCH_ASSOC));
            foreach ($langs as $l) {
                $l['id_country'] = $tid;
                SchemaHelper::upsert('country_lang', $l, ['id_country', 'id_lang']);
            }

            Db::getInstance()->execute("REPLACE INTO `" . _DB_PREFIX_ . "country_shop` (id_country, id_shop) VALUES ($tid, $shopId)");
        }
    }

    private function migrateCurrencies()
    {
        $shopId = SchemaHelper::getTargetShopId();
        $rows = $this->db_connection->query("SELECT * FROM `{$this->prefix}currency`")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $sid = (int)$row['id_currency'];
            IdMapper::own('currency', $sid);
            if (IdMapper::isLinked('currency', $sid)) {
                continue;
            }

            $trow = IdMapper::row('currency', $row);
            $tid = (int)$trow['id_currency'];
            SchemaHelper::upsert('currency', $trow, ['id_currency']);

            if (SchemaHelper::hasTable('currency_lang')) {
                try {
                    $langs = LanguageMapper::expand($this->db_connection->query("SELECT * FROM `{$this->prefix}currency_lang` WHERE id_currency = $sid")->fetchAll(PDO::FETCH_ASSOC));
                } catch (\Exception $e) {
                    $langs = []; // source older than 1.7.6
                }
                foreach ($langs as $l) {
                    $l['id_currency'] = $tid;
                    SchemaHelper::upsert('currency_lang', $l, ['id_currency', 'id_lang']);
                }
            }

            Db::getInstance()->execute("REPLACE INTO `" . _DB_PREFIX_ . "currency_shop` (id_currency, id_shop, conversion_rate) VALUES ($tid, $shopId, " . (float)$row['conversion_rate'] . ")");
        }
    }

    private function reportLanguages()
    {
        try {
            $rows = $this->db_connection->query("SELECT id_lang, iso_code FROM `{$this->prefix}lang`")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return;
        }
        foreach ($rows as $r) {
            if (LanguageMapper::toTarget((int)$r['id_lang']) === null) {
                LogService::getInstance()->warning("Language '{$r['iso_code']}' of the source is not installed in the target — its translations are not migrated. Install it in International > Translations and run the migration again.");
            }
        }
    }
}
