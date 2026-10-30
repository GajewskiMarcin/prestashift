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

class EmployeeMigrationStep
{
    private $db_connection;
    private $prefix;

    /** @var array source id_tab => target id_tab */
    private $tabs = [];

    /** @var array|null source id_tab => class name, the whole table read once */
    private $tabClasses = null;

    public function __construct($db_connection, $prefix)
    {
        $this->db_connection = $db_connection;
        $this->prefix = $prefix;
    }

    public function process($offset, $limit, $dateFilter = null)
    {
        if ((int) $offset === 0) {
            $this->migrateProfiles();
        }

        $employees = $this->getEmployees($offset, $limit, $dateFilter);

        if (empty($employees)) {
            return ['count' => 0, 'finished' => true];
        }

        foreach ($employees as $item) {
            $this->importEmployee($item);
        }

        return ['count' => count($employees), 'finished' => false];
    }

    private function getEmployees($offset, $limit, $dateFilter = null)
    {
        // ps_employee has no date columns before PrestaShop 8 — no Delta
        // filter; the handful of rows is re-read and the id map updates them
        $sql = "SELECT * FROM `{$this->prefix}employee` ORDER BY `id_employee` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Profiles: SuperAdmin and profiles with the same name are the target's
     * own and stay untouched.
     */
    private function migrateProfiles()
    {
        $profiles = $this->db_connection->query("SELECT * FROM `{$this->prefix}profile` ORDER BY `id_profile` ASC")->fetchAll(PDO::FETCH_ASSOC);

        // All profile names in one query instead of one query per profile
        $langRows = SourceBatch::grouped($this->db_connection,
            "SELECT * FROM `{$this->prefix}profile_lang` WHERE id_profile IN ({ids})",
            'id_profile', array_column($profiles, 'id_profile'));

        foreach ($profiles as $p) {
            $sid = (int)$p['id_profile'];
            $tid = IdMapper::own('profile', $sid);
            if (IdMapper::isLinked('profile', $sid)) {
                continue;
            }

            SchemaHelper::insertIgnore('profile', ['id_profile' => $tid]);

            $langs = LanguageMapper::expand(SourceBatch::of($langRows, $sid));
            foreach ($langs as $l) {
                Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "profile_lang` WHERE id_profile = $tid AND id_lang = " . (int)$l['id_lang']);
                SchemaHelper::insertIgnore('profile_lang', [
                    'id_profile' => $tid,
                    'id_lang' => (int)$l['id_lang'],
                    'name' => $l['name'],
                ]);
            }
        }
    }

    /**
     * An employee whose e-mail already exists in the target is that person's
     * account there (possibly the operator running the migration) — it is
     * linked, never overwritten. Everybody else gets a fresh id, so no source
     * employee can land on an existing account.
     */
    private function importEmployee($data)
    {
        $sid = (int)$data['id_employee'];
        IdMapper::own('employee', $sid, ['email' => isset($data['email']) ? $data['email'] : '']);
        if (IdMapper::isLinked('employee', $sid)) {
            return;
        }

        $row = IdMapper::row('employee', $data);
        if ((int)$row['id_profile'] <= 0) {
            return; // profile unknown — an account without permissions is useless
        }
        $row['default_tab'] = $this->targetTab(isset($data['default_tab']) ? (int)$data['default_tab'] : 0);
        foreach (['id_last_order', 'id_last_customer_message', 'id_last_customer'] as $col) {
            if (isset($row[$col])) {
                $row[$col] = 0; // notification counters of the source shop
            }
        }

        $tid = (int)$row['id_employee'];
        if (SchemaHelper::upsert('employee', $row, ['id_employee'])) {
            Db::getInstance()->execute("INSERT IGNORE INTO `" . _DB_PREFIX_ . "employee_shop` (id_employee, id_shop) VALUES ($tid, " . SchemaHelper::getTargetShopId() . ")");
        }
    }

    /**
     * Back-office tab ids differ between PrestaShop versions; match by class.
     */
    private function targetTab($sourceTab)
    {
        if ($sourceTab <= 0) {
            return 0;
        }
        if ($this->tabClasses === null) {
            // The whole tab table once, instead of a query per employee
            $this->tabClasses = [];
            try {
                foreach ($this->db_connection->query("SELECT id_tab, class_name FROM `{$this->prefix}tab`")->fetchAll(PDO::FETCH_ASSOC) as $t) {
                    $this->tabClasses[(int)$t['id_tab']] = (string)$t['class_name'];
                }
            } catch (\Exception $e) {
            }
        }

        if (!isset($this->tabs[$sourceTab])) {
            $class = isset($this->tabClasses[$sourceTab]) ? $this->tabClasses[$sourceTab] : '';
            $target = $class !== '' ? (int)\Tab::getIdFromClassName($class) : 0;
            $this->tabs[$sourceTab] = $target ?: (int)\Tab::getIdFromClassName('AdminDashboard');
        }

        return $this->tabs[$sourceTab];
    }
}
