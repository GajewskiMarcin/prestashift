<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcingajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @attribution PrestaShift by Marcin Gajewski (marcingajewski.pl). Keep the Attribution Notice in prestashift.php — AFL-3.0 section 6.
 * @version   1.3.0
 */
namespace PrestaShift\Service\Steps;

use Db;
use PDO;
use Exception;
use PrestaShift\Service\IdMapper;
use PrestaShift\Service\LanguageMapper;
use PrestaShift\Service\SchemaHelper;

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
        // 1. Groups (once, at offset 0)
        if ($offset === 0) {
            $this->migrateGroups();
        }

        // 2. Customers
        $customers = $this->getCustomersFromSource($offset, $limit, $dateFilter);
        if (empty($customers)) {
            return ['count' => 0, 'finished' => true];
        }

        IdMapper::prepare('customer', array_column($customers, 'id_customer'));

        $migrated = [];
        foreach ($customers as $customer) {
            if ($this->importCustomer($customer)) {
                $migrated[] = $customer;
            }
        }

        // 3. Group memberships of the customers created by the migration
        $this->migrateCustomerGroups($migrated);

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

    /**
     * @return bool true when the customer record was written (false when it
     *              was matched to an existing account, which is left untouched)
     */
    private function importCustomer($data)
    {
        $sid = (int)$data['id_customer'];

        // A registered account with the same e-mail already in the target is
        // the same person: link to it, never overwrite it
        IdMapper::own('customer', $sid, ['email' => $data['email'], 'is_guest' => $data['is_guest']]);
        if (IdMapper::isLinked('customer', $sid)) {
            return false;
        }

        $row = IdMapper::row('customer', [
            'id_customer' => $sid,
            'id_shop_group' => isset($data['id_shop_group']) ? $data['id_shop_group'] : 1,
            'id_shop' => isset($data['id_shop']) ? $data['id_shop'] : 1,
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
        ]);

        if ((int)$row['id_default_group'] <= 0 || !isset($this->getTargetGroupIds()[(int)$row['id_default_group']])) {
            $row['id_default_group'] = (int)\Configuration::get((int)$data['is_guest'] ? 'PS_GUEST_GROUP' : 'PS_CUSTOMER_GROUP');
        }

        // Full upsert — Delta must carry changed names, passwords, flags
        return (bool)SchemaHelper::upsert('customer', $row, ['id_customer']);
    }

    private function migrateGroups()
    {
        $groups = $this->db_connection->query("SELECT * FROM `{$this->prefix}group`")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($groups as $group) {
            $sid = (int)$group['id_group'];
            $tid = IdMapper::own('group', $sid);

            // Built-in groups and groups matched by name keep the target's settings
            if (IdMapper::isLinked('group', $sid)) {
                continue;
            }

            SchemaHelper::upsert('group', [
                'id_group' => $tid,
                'reduction' => isset($group['reduction']) ? $group['reduction'] : 0,
                'price_display_method' => (int)$group['price_display_method'],
                'show_prices' => isset($group['show_prices']) ? (int)$group['show_prices'] : 1,
                'date_add' => isset($group['date_add']) ? $group['date_add'] : date('Y-m-d H:i:s'),
                'date_upd' => isset($group['date_upd']) ? $group['date_upd'] : date('Y-m-d H:i:s'),
            ], ['id_group']);

            try {
                Db::getInstance()->execute("REPLACE INTO `" . _DB_PREFIX_ . "group_shop` (id_group, id_shop) VALUES ($tid, " . SchemaHelper::getTargetShopId() . ")");
            } catch (Exception $e) {
            }

            $langs = LanguageMapper::expand($this->db_connection->query("SELECT * FROM `{$this->prefix}group_lang` WHERE id_group = $sid")->fetchAll(PDO::FETCH_ASSOC));
            foreach ($langs as $lang) {
                Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "group_lang` WHERE id_group = $tid AND id_lang = " . (int)$lang['id_lang']);
                SchemaHelper::insertIgnore('group_lang', [
                    'id_group' => $tid,
                    'id_lang' => (int)$lang['id_lang'],
                    'name' => $lang['name'],
                ]);
            }
        }

        $this->targetGroupIds = null;
    }

    /**
     * Rebuilds ps_customer_group for the customers of the current batch.
     * Without this table a customer keeps no group membership at all:
     * group prices, discounts and access restrictions stop applying.
     */
    private function migrateCustomerGroups(array $customers)
    {
        if (empty($customers)) {
            return;
        }

        $targets = [];
        $defaults = [];
        foreach ($customers as $customer) {
            $sid = (int)$customer['id_customer'];
            $targets[$sid] = IdMapper::find('customer', $sid);
            $defaults[$sid] = (int)Db::getInstance()->getValue("SELECT id_default_group FROM `" . _DB_PREFIX_ . "customer` WHERE id_customer = " . $targets[$sid], false);
        }

        $links = [];
        try {
            $stmt = $this->db_connection->query("SELECT `id_customer`, `id_group` FROM `{$this->prefix}customer_group` WHERE `id_customer` IN (" . implode(',', array_keys($targets)) . ")");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $gid = IdMapper::ref('group', (int)$row['id_group']);
                if ($gid > 0) {
                    $links[(int)$row['id_customer']][$gid] = true;
                }
            }
        } catch (Exception $e) {
            // Source table unreadable — the default group below still applies
        }

        $existingGroups = $this->getTargetGroupIds();
        $values = [];
        foreach ($targets as $sid => $tid) {
            if ($tid <= 0) {
                continue;
            }
            if ($defaults[$sid] > 0) {
                $links[$sid][$defaults[$sid]] = true;
            }
            if (empty($links[$sid])) {
                continue;
            }
            foreach (array_keys($links[$sid]) as $gid) {
                if (isset($existingGroups[$gid])) {
                    $values[] = "($tid, $gid)";
                }
            }
        }

        $tids = array_filter($targets);
        if ($tids) {
            Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "customer_group` WHERE id_customer IN (" . implode(',', $tids) . ")");
        }
        foreach (array_chunk($values, 500) as $chunk) {
            Db::getInstance()->execute("INSERT IGNORE INTO `" . _DB_PREFIX_ . "customer_group` (`id_customer`, `id_group`) VALUES " . implode(', ', $chunk));
        }
    }

    private function getTargetGroupIds()
    {
        if ($this->targetGroupIds === null) {
            $this->targetGroupIds = [];
            $rows = Db::getInstance()->executeS("SELECT `id_group` FROM `" . _DB_PREFIX_ . "group`");
            if ($rows) {
                foreach ($rows as $row) {
                    $this->targetGroupIds[(int)$row['id_group']] = true;
                }
            }
        }

        return $this->targetGroupIds;
    }
}
