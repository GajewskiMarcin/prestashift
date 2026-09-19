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

use PDO;
use PrestaShift\Service\IdMapper;
use PrestaShift\Service\SchemaHelper;

/**
 * States / regions: matched by ISO code within their country, added only when
 * the target lacks them.
 */
class StateMigrationStep
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
            try {
                $rows = $this->db_connection->query("SELECT * FROM `{$this->prefix}state` ORDER BY `id_state` ASC")->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Exception $e) {
                return ['count' => 0, 'finished' => true];
            }

            foreach ($rows as $row) {
                $this->importState($row);
            }
        }

        return ['count' => 1, 'finished' => true];
    }

    private function importState($data)
    {
        $sid = (int)$data['id_state'];
        IdMapper::own('state', $sid);
        if (IdMapper::isLinked('state', $sid)) {
            return;
        }

        $row = IdMapper::row('state', $data);
        if ((int)$row['id_country'] <= 0) {
            return;
        }
        if ((int)$row['id_zone'] <= 0) {
            $row['id_zone'] = (int)\Db::getInstance()->getValue("SELECT id_zone FROM `" . _DB_PREFIX_ . "country` WHERE id_country = " . (int)$row['id_country'], false);
        }

        try {
            SchemaHelper::upsert('state', $row, ['id_state']);
        } catch (\Exception $e) {
        }
    }
}
