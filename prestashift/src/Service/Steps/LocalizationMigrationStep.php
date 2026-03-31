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
        // One-shot migration for all localization data (usually small tables)
        if ($offset == 0) {
            $this->migrateZones();
            $this->migrateCountries();
            $this->migrateCurrencies();
            $this->migrateLanguages();
        }

        return ['count' => 1, 'finished' => true];
    }
    
    private function migrateZones() {
        $rows = $this->db_connection->query("SELECT * FROM `{$this->prefix}zone`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
             SchemaHelper::upsert('zone', $row, ['id_zone']);
        }
    }
    
    private function migrateCountries() {
        $rows = $this->db_connection->query("SELECT * FROM `{$this->prefix}country`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
             // Basic Upsert
             SchemaHelper::upsert('country', $row, ['id_country']);
             
             // Lang
             $langs = $this->db_connection->query("SELECT * FROM `{$this->prefix}country_lang` WHERE id_country = " . $row['id_country'])->fetchAll(PDO::FETCH_ASSOC);
             foreach ($langs as $l) {
                 SchemaHelper::upsert('country_lang', $l, ['id_country', 'id_lang']);
             }
             
             // Shop
             Db::getInstance()->execute("REPLACE INTO `" . \_DB_PREFIX_ . "country_shop` (id_country, id_shop) VALUES (".$row['id_country'].", " . \PrestaShift\Service\SchemaHelper::getTargetShopId() . ")");
        }
    }
    
    private function migrateCurrencies() {
        $rows = $this->db_connection->query("SELECT * FROM `{$this->prefix}currency`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
             SchemaHelper::upsert('currency', $row, ['id_currency']);
             
             // Lang
             $langs = $this->db_connection->query("SELECT * FROM `{$this->prefix}currency_lang` WHERE id_currency = " . $row['id_currency'])->fetchAll(PDO::FETCH_ASSOC);
             foreach ($langs as $l) {
                 SchemaHelper::upsert('currency_lang', $l, ['id_currency', 'id_lang']);
             }
             
             // Shop
             Db::getInstance()->execute("REPLACE INTO `" . \_DB_PREFIX_ . "currency_shop` (id_currency, id_shop, conversion_rate) VALUES (".$row['id_currency'].", " . \PrestaShift\Service\SchemaHelper::getTargetShopId() . ", ".$row['conversion_rate'].")");
        }
    }
    
    private function migrateLanguages() {
        $rows = $this->db_connection->query("SELECT * FROM `{$this->prefix}lang`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
             SchemaHelper::upsert('lang', $row, ['id_lang']);
             
             // Shop
                         // Some versions of PS use lang_shop, others don't. Check manually or just insert if exists.
             try {
                Db::getInstance()->execute("REPLACE INTO `" . \_DB_PREFIX_ . "lang_shop` (id_lang, id_shop) VALUES (".$row['id_lang'].", " . \PrestaShift\Service\SchemaHelper::getTargetShopId() . ")");
             } catch (\Exception $e) {}
        }
    }
}
