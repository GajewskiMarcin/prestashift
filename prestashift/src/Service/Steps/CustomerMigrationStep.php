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
use Exception;

class CustomerMigrationStep
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
        // 1. Migrate Groups (only once, at offset 0)
        if ($offset === 0) {
            $this->migrateGroups();
        }

        // 2. Migrate Customers
        $customers = $this->getCustomersFromSource($offset, $limit, $dateFilter);
        if (empty($customers)) {
            return ['count' => 0, 'finished' => true];
        }

        foreach ($customers as $customer) {
            $this->importCustomer($customer);
        }

        // 3. Migrate Addresses (linked to these customers)
        // For simplicity in this batch, we migrate addresses for the customers we just imported
        //$this->migrateAddressesForCustomers($customers);
        // BETTER STRATEGY: Migrate all addresses in a separate pass or separate batch logic. 
        // For MVP, let's assume we do Customers batch, then we will switch to Addresses batch.
        // But the Manager logic needs to handle switching Steps.
        
        return ['count' => count($customers), 'finished' => false];
    }

    private function getCustomersFromSource($offset, $limit, $dateFilter = null)
    {
        $where = "";
        if ($dateFilter) {
            $where = " WHERE `date_add` > '{$dateFilter}' OR `date_upd` > '{$dateFilter}' ";
        }
        $sql = "SELECT * FROM `{$this->prefix}customer` {$where} ORDER BY `id_customer` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importCustomer($data)
    {
        $customerData = [
            'id_customer' => $data['id_customer'],
            'id_shop_group' => 1,
            'id_shop' => \PrestaShift\Service\SchemaHelper::getTargetShopId(),
            'id_gender' => $data['id_gender'],
            'id_default_group' => $data['id_default_group'],
            'id_lang' => $data['id_lang'],
            'id_risk' => $data['id_risk'],
            'company' => $data['company'],
            'siret' => $data['siret'],
            'ape' => $data['ape'],
            'firstname' => $data['firstname'],
            'lastname' => $data['lastname'],
            'email' => $data['email'],
            'passwd' => $data['passwd'],
            'last_passwd_gen' => $data['last_passwd_gen'],
            'birthday' => $data['birthday'],
            'newsletter' => $data['newsletter'],
            'ip_registration_newsletter' => $data['ip_registration_newsletter'],
            'newsletter_date_add' => $data['newsletter_date_add'],
            'optin' => $data['optin'],
            'website' => $data['website'],
            'outstanding_allow_amount' => $data['outstanding_allow_amount'],
            'show_public_prices' => $data['show_public_prices'],
            'max_payment_days' => $data['max_payment_days'],
            'secure_key' => $data['secure_key'],
            'note' => isset($data['note']) ? $data['note'] : null,
            'active' => $data['active'],
            'is_guest' => $data['is_guest'],
            'deleted' => $data['deleted'],
            'date_add' => $data['date_add'],
            'date_upd' => $data['date_upd'],
            'reset_password_token' => isset($data['reset_password_token']) ? $data['reset_password_token'] : null,
            'reset_password_validity' => isset($data['reset_password_validity']) ? $data['reset_password_validity'] : null,
        ];

        // Dynamic Insert
        $sql = \PrestaShift\Service\SchemaHelper::buildInsertQuery('customer', $customerData, true);
        
        if ($sql) {
            $sql .= " ON DUPLICATE KEY UPDATE email = VALUES(email)"; 
            Db::getInstance()->execute($sql);
        }
    }

    private function migrateGroups()
    {
        // Simple 1:1 migration for groups
        $sql = "SELECT * FROM `{$this->prefix}group`";
        $stmt = $this->db_connection->query($sql);
        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($groups as $group) {
            $groupData = [
                'id_group' => (int)$group['id_group'],
                'price_display_method' => (int)$group['price_display_method'],
                'date_add' => isset($group['date_add']) ? $group['date_add'] : date('Y-m-d H:i:s'),
                'date_upd' => isset($group['date_upd']) ? $group['date_upd'] : date('Y-m-d H:i:s'),
            ];
             
             // SchemaHelper will strip date_add/date_upd if they don't exist in target ps_group (they often don't)
             $sql = \PrestaShift\Service\SchemaHelper::buildInsertQuery('group', $groupData, true);
             if ($sql) {
                  // IGNORE to avoid errors on duplicates
                  $sql = str_replace('INSERT INTO', 'INSERT IGNORE INTO', $sql);
                  Db::getInstance()->execute($sql);
             }
             
             // Also lang
             $sqlLang = "SELECT * FROM `{$this->prefix}group_lang` WHERE id_group = {$group['id_group']}";
             $stmtLang = $this->db_connection->query($sqlLang);
             $langs = $stmtLang->fetchAll(PDO::FETCH_ASSOC);
             foreach ($langs as $lang) {
                  $langData = [
                      'id_group' => (int)$group['id_group'],
                      'id_lang' => (int)$lang['id_lang'],
                      'name' => $lang['name']
                  ];
                  $sqlL = \PrestaShift\Service\SchemaHelper::buildInsertQuery('group_lang', $langData, true);
                  if ($sqlL) {
                      $sqlL = str_replace('INSERT INTO', 'INSERT IGNORE INTO', $sqlL);
                      Db::getInstance()->execute($sqlL);
                  }
             }
        }
    }
}
