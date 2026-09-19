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
use PrestaShift\Service\IdMapper;
use PrestaShift\Service\SchemaHelper;

class AddressMigrationStep
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
        $addresses = $this->getAddressesFromSource($offset, $limit, $dateFilter);

        if (empty($addresses)) {
            return ['count' => 0, 'finished' => true];
        }

        IdMapper::prepare('address', array_column($addresses, 'id_address'));

        foreach ($addresses as $address) {
            $this->importAddress($address);
        }

        return ['count' => count($addresses), 'finished' => false];
    }

    private function getAddressesFromSource($offset, $limit, $dateFilter = null)
    {
        $where = "";
        if ($dateFilter) {
            $where = " WHERE `date_add` > '{$dateFilter}' OR `date_upd` > '{$dateFilter}' ";
        }
        $sql = "SELECT * FROM `{$this->prefix}address` {$where} ORDER BY `id_address` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importAddress($data)
    {
        // A customer matched to an existing account usually already has the
        // same address there — reuse it instead of adding a copy
        if ((int)$data['id_customer'] > 0 && IdMapper::isLinked('customer', (int)$data['id_customer'])) {
            $existing = $this->findSameAddress(IdMapper::find('customer', (int)$data['id_customer']), $data);
            if ($existing > 0) {
                IdMapper::link('address', (int)$data['id_address'], $existing);
                return;
            }
        }

        $row = IdMapper::row('address', $data);

        // The owner (customer, brand or supplier) must exist in the target
        foreach (['id_customer', 'id_manufacturer', 'id_supplier'] as $owner) {
            if (isset($data[$owner]) && (int)$data[$owner] > 0 && (int)$row[$owner] <= 0) {
                return;
            }
        }

        if ((int)$row['id_country'] <= 0) {
            $row['id_country'] = (int)\Configuration::get('PS_COUNTRY_DEFAULT');
            $row['id_state'] = 0;
        }
        $row['id_warehouse'] = 0;

        // Full upsert — changed addresses must be carried by Delta runs
        SchemaHelper::upsert('address', $row, ['id_address']);
    }

    /**
     * Id of a non-deleted address of the target customer with the same
     * person, street, postcode, city and country; 0 if none.
     */
    private function findSameAddress($idCustomer, array $data)
    {
        if ($idCustomer <= 0) {
            return 0;
        }
        $country = IdMapper::ref('country', (int)$data['id_country']);
        $norm = function ($v) {
            return mb_strtolower(trim(preg_replace('/\s+/', ' ', (string)$v)));
        };

        $rows = Db::getInstance()->executeS("SELECT id_address, firstname, lastname, company, address1, address2, postcode, city, id_country
            FROM `" . _DB_PREFIX_ . "address` WHERE id_customer = " . (int)$idCustomer . " AND deleted = 0");
        foreach ((array)$rows as $r) {
            if ((int)$r['id_country'] === $country
                && $norm($r['firstname']) === $norm($data['firstname'])
                && $norm($r['lastname']) === $norm($data['lastname'])
                && $norm($r['company']) === $norm($data['company'])
                && $norm($r['address1']) === $norm($data['address1'])
                && $norm($r['address2']) === $norm($data['address2'])
                && $norm($r['postcode']) === $norm($data['postcode'])
                && $norm($r['city']) === $norm($data['city'])) {
                return (int)$r['id_address'];
            }
        }

        return 0;
    }
}
