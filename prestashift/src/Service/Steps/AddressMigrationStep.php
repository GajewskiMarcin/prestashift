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
        // Sanitize
        $data = array_map(function($value) {
            return $value === null ? 'NULL' : "'" . pSQL($value) . "'";
        }, $data);

        // PrestaShop 1.7/8 structure for ps_address
        $sql = "INSERT INTO `" . \_DB_PREFIX_ . "address` 
                (id_address, id_country, id_state, id_customer, id_manufacturer, id_supplier, 
                id_warehouse, alias, company, lastname, firstname, address1, address2, 
                postcode, city, other, phone, phone_mobile, vat_number, dni, date_add, date_upd, active, deleted)
                VALUES (
                    {$data['id_address']}, {$data['id_country']}, {$data['id_state']}, {$data['id_customer']}, 
                    {$data['id_manufacturer']}, {$data['id_supplier']}, {$data['id_warehouse']}, 
                    {$data['alias']}, {$data['company']}, {$data['lastname']}, {$data['firstname']}, 
                    {$data['address1']}, {$data['address2']}, {$data['postcode']}, {$data['city']}, 
                    {$data['other']}, {$data['phone']}, {$data['phone_mobile']}, {$data['vat_number']}, 
                    {$data['dni']}, {$data['date_add']}, {$data['date_upd']}, {$data['active']}, {$data['deleted']}
                )
                ON DUPLICATE KEY UPDATE date_upd = VALUES(date_upd);";

        Db::getInstance()->execute($sql);
    }
}
