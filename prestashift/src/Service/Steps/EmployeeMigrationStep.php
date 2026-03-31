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
use Context;
use PrestaShift\Service\SchemaHelper;

class EmployeeMigrationStep
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
        // 1. Profiles (Small table, migrate at once)
        if ($offset === 0) {
            $this->migrateProfiles();
        }

        // 2. Employees
        $employees = $this->getEmployees($offset, $limit, $dateFilter);

        if (empty($employees)) {
            return ['count' => 0, 'finished' => true];
        }

        $currentEmployeeEmail = (isset(Context::getContext()->employee) && Context::getContext()->employee->id) 
            ? Context::getContext()->employee->email 
            : null;

        foreach ($employees as $item) {
            // SAFETY: Do not overwrite the currently logged-in admin to prevent session loss/lockout
            if ($currentEmployeeEmail && $item['email'] === $currentEmployeeEmail) {
                continue;
            }
            $this->importEmployee($item);
        }

        return ['count' => count($employees), 'finished' => false];
    }

    private function getEmployees($offset, $limit, $dateFilter = null)
    {
        $where = "";
        if ($dateFilter) {
            $where = " WHERE `date_add` > '{$dateFilter}' OR `date_upd` > '{$dateFilter}' ";
        }
        $sql = "SELECT * FROM `{$this->prefix}employee` {$where} ORDER BY `id_employee` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function migrateProfiles()
    {
        $sql = "SELECT * FROM `{$this->prefix}profile` ORDER BY `id_profile` ASC";
        $stmt = $this->db_connection->query($sql);
        $profiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($profiles as $p) {
            $data = [
                'id_profile' => (int)$p['id_profile']
            ];
            $insertSql = SchemaHelper::buildInsertQuery('profile', $data, true);
            if ($insertSql) {
                $insertSql = str_replace('INSERT INTO', 'INSERT IGNORE INTO', $insertSql);
                Db::getInstance()->execute($insertSql);
            }
            
            // Lang
            $this->migrateProfileLang($p['id_profile']);
        }
    }

    private function migrateProfileLang($id_profile)
    {
        $sql = "SELECT * FROM `{$this->prefix}profile_lang` WHERE id_profile = $id_profile";
        $stmt = $this->db_connection->query($sql);
        $langs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($langs as $l) {
            $data = [
                'id_profile' => (int)$id_profile,
                'id_lang' => (int)$l['id_lang'],
                'name' => $l['name']
            ];
            Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "profile_lang` WHERE id_profile = $id_profile AND id_lang = " . (int)$l['id_lang']);
            $insertSql = SchemaHelper::buildInsertQuery('profile_lang', $data, true);
            if ($insertSql) {
                Db::getInstance()->execute($insertSql);
            }
        }
    }

    private function importEmployee($data)
    {
        $id = (int)$data['id_employee'];
        
        // Remove sensitive fields that might break logic if purely copied without context
        // though passwords will be migrated as hashes.
        $employeeData = $data;
        $employeeData['id_employee'] = $id;

        $sql = SchemaHelper::buildUpsertQuery('employee', $employeeData, ['id_employee']);
        if ($sql) {
            Db::getInstance()->execute($sql);
        }
        
        // Employee Shop
        Db::getInstance()->execute("INSERT IGNORE INTO `" . _DB_PREFIX_ . "employee_shop` (id_employee, id_shop) VALUES ($id, " . \PrestaShift\Service\SchemaHelper::getTargetShopId() . ")");
    }
}
