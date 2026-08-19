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
        // 1. Profiles (small table, migrate at once)
        if ((int) $offset === 0) {
            $this->migrateProfiles();
        }

        // 2. Employees
        $employees = $this->getEmployees($offset, $limit, $dateFilter);

        if (empty($employees)) {
            return ['count' => 0, 'finished' => true];
        }

        $ctx = Context::getContext();
        $currentEmployeeId = (isset($ctx->employee) && $ctx->employee->id) ? (int) $ctx->employee->id : 0;

        foreach ($employees as $item) {
            // Never overwrite an existing target account. Employees are upserted
            // by id_employee, so importing the source employee that sits at the
            // logged-in admin's id would replace their e-mail and password and
            // lock the operator out mid-migration. We also skip any source
            // employee whose e-mail already exists in the target, to avoid
            // clobbering / duplicating an existing account.
            //
            // This mirrors the reference migration modules, which likewise do
            // not overwrite existing employees (they skip on email-exists and
            // remap ids through a mapping table). Preserving the source admin's
            // exact id without logging the operator out would require that same
            // remap+mapping layer, which this module intentionally does not have.
            if ($currentEmployeeId && (int) $item['id_employee'] === $currentEmployeeId) {
                continue;
            }
            if (isset($item['email']) && \Employee::employeeExists($item['email'])) {
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
        $langs = \PrestaShift\Service\LanguageMapper::expand($stmt->fetchAll(PDO::FETCH_ASSOC));

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
