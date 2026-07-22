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

    /** @var array|null Cache of group IDs that exist in the target shop */
    private $targetGroupIds = null;

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

        // 3. Link customers to their groups (ps_customer_group)
        $this->migrateCustomerGroups($customers);

        // 4. Migrate Addresses (linked to these customers)
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
            'id_lang' => \PrestaShift\Service\LanguageMapper::toTargetOrDefault($data['id_lang']),
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
            $idGroup = (int)$group['id_group'];
            $groupData = [
                'id_group' => $idGroup,
                'reduction' => isset($group['reduction']) ? $group['reduction'] : 0,
                'price_display_method' => (int)$group['price_display_method'],
                'show_prices' => isset($group['show_prices']) ? (int)$group['show_prices'] : 1,
                'date_add' => isset($group['date_add']) ? $group['date_add'] : date('Y-m-d H:i:s'),
                'date_upd' => isset($group['date_upd']) ? $group['date_upd'] : date('Y-m-d H:i:s'),
            ];

             // SchemaHelper will strip date_add/date_upd if they don't exist in target ps_group (they often don't)
             // Groups already present in the target (1/2/3) must be overwritten, not skipped,
             // otherwise their reduction/price_display_method stay at PrestaShop defaults.
             $sql = \PrestaShift\Service\SchemaHelper::buildUpsertQuery('group', $groupData, ['id_group']);
             if ($sql) {
                  Db::getInstance()->execute($sql);
             }

             // Shop association — without it the group is invisible in the shop context
             try {
                 Db::getInstance()->execute(
                     "REPLACE INTO `" . \_DB_PREFIX_ . "group_shop` (id_group, id_shop) VALUES ($idGroup, "
                     . \PrestaShift\Service\SchemaHelper::getTargetShopId() . ")"
                 );
             } catch (Exception $e) {
                 // Table may not exist on very old targets — skip
             }

             // Also lang
             $sqlLang = "SELECT * FROM `{$this->prefix}group_lang` WHERE id_group = {$group['id_group']}";
             $stmtLang = $this->db_connection->query($sqlLang);
             $langs = \PrestaShift\Service\LanguageMapper::expand($stmtLang->fetchAll(PDO::FETCH_ASSOC));
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

        // Reset the cache — groups just changed
        $this->targetGroupIds = null;
    }

    /**
     * Rebuilds ps_customer_group for the customers of the current batch.
     * Without this table a customer keeps no group membership at all:
     * group prices, discounts and access restrictions stop applying.
     */
    private function migrateCustomerGroups(array $customers)
    {
        $defaults = [];
        foreach ($customers as $customer) {
            $defaults[(int)$customer['id_customer']] = (int)$customer['id_default_group'];
        }
        if (empty($defaults)) {
            return;
        }

        $idList = implode(',', array_keys($defaults));

        // Wipe the batch first so re-runs don't accumulate stale memberships
        Db::getInstance()->execute(
            "DELETE FROM `" . \_DB_PREFIX_ . "customer_group` WHERE id_customer IN ($idList)"
        );

        $links = [];
        try {
            $stmt = $this->db_connection->query(
                "SELECT `id_customer`, `id_group` FROM `{$this->prefix}customer_group` WHERE `id_customer` IN ($idList)"
            );
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $links[(int)$row['id_customer']][(int)$row['id_group']] = true;
            }
        } catch (Exception $e) {
            // Source table unreadable — the default group fallback below still applies
        }

        $existingGroups = $this->getTargetGroupIds();

        $values = [];
        foreach ($defaults as $idCustomer => $idDefaultGroup) {
            // The default group must always be there, even when the source
            // link table has no row for this customer.
            if ($idDefaultGroup > 0) {
                $links[$idCustomer][$idDefaultGroup] = true;
            }
            if (empty($links[$idCustomer])) {
                continue;
            }
            foreach (array_keys($links[$idCustomer]) as $idGroup) {
                // Skip groups that don't exist in the target — would fail the foreign key
                if (!isset($existingGroups[$idGroup])) {
                    continue;
                }
                $values[] = "($idCustomer, $idGroup)";
            }
        }

        if (empty($values)) {
            return;
        }

        foreach (array_chunk($values, 500) as $chunk) {
            Db::getInstance()->execute(
                "INSERT IGNORE INTO `" . \_DB_PREFIX_ . "customer_group` (`id_customer`, `id_group`) VALUES "
                . implode(', ', $chunk)
            );
        }
    }

    private function getTargetGroupIds()
    {
        if ($this->targetGroupIds === null) {
            $this->targetGroupIds = [];
            $rows = Db::getInstance()->executeS("SELECT `id_group` FROM `" . \_DB_PREFIX_ . "group`");
            if ($rows) {
                foreach ($rows as $row) {
                    $this->targetGroupIds[(int)$row['id_group']] = true;
                }
            }
        }

        return $this->targetGroupIds;
    }
}
