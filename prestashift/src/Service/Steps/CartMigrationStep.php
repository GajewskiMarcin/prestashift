<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
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

class CartMigrationStep
{
    private $db_connection;
    private $prefix;

    /** @var array table => [key => rows] — rows of the batch, one query per table */
    private $rows = [];

    public function __construct($db_connection, $prefix)
    {
        $this->db_connection = $db_connection;
        $this->prefix = $prefix;
    }

    public function process($offset, $limit, $dateFilter = null)
    {
        try {
            $carts = $this->getCartsFromSource($offset, $limit, $dateFilter);
        } catch (\Exception $e) {
            return ['count' => 0, 'finished' => true];
        }

        if (empty($carts)) {
            return ['count' => 0, 'finished' => true];
        }

        IdMapper::prepare('cart', array_column($carts, 'id_cart'));
        $this->loadBatch(array_map('intval', array_column($carts, 'id_cart')));

        foreach ($carts as $cart) {
            $this->importCart($cart);
        }

        return ['count' => count($carts), 'finished' => false];
    }

    private function getCartsFromSource($offset, $limit, $dateFilter = null)
    {
        $where = "";
        if ($dateFilter) {
            $where = " WHERE `date_add` > '{$dateFilter}' OR `date_upd` > '{$dateFilter}' ";
        }
        $sql = "SELECT * FROM `{$this->prefix}cart` {$where} ORDER BY `id_cart` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Child rows of the whole batch, one query per table.
     */
    private function loadBatch(array $ids)
    {
        $in = implode(',', $ids);
        $this->rows = [];

        foreach (['cart_product', 'customization', 'cart_cart_rule'] as $table) {
            $this->rows[$table] = [];
            try {
                foreach ($this->db_connection->query("SELECT * FROM `{$this->prefix}$table` WHERE id_cart IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $this->rows[$table][(int)$row['id_cart']][] = $row;
                }
            } catch (\Exception $e) {
                // table absent in the source version
            }
        }

        $customizationIds = [];
        foreach ($this->rows['customization'] as $list) {
            foreach ($list as $row) {
                $customizationIds[] = (int)$row['id_customization'];
            }
        }
        $this->rows['customized_data'] = [];
        if ($customizationIds) {
            try {
                foreach ($this->db_connection->query("SELECT * FROM `{$this->prefix}customized_data` WHERE id_customization IN (" . implode(',', $customizationIds) . ")")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $this->rows['customized_data'][(int)$row['id_customization']][] = $row;
                }
            } catch (\Exception $e) {
            }
        }
    }

    private function batchRows($table, $key)
    {
        return isset($this->rows[$table][(int)$key]) ? $this->rows[$table][(int)$key] : [];
    }

    private function importCart($data)
    {
        $sid = (int)$data['id_cart'];

        if ((int)$data['id_customer'] > 0 && IdMapper::ref('customer', (int)$data['id_customer']) <= 0) {
            return; // customer not migrated
        }

        $row = IdMapper::row('cart', $data);
        $tid = (int)$row['id_cart'];

        if ((int)$row['id_currency'] <= 0) {
            $row['id_currency'] = (int)\Configuration::get('PS_CURRENCY_DEFAULT');
        }
        $row['delivery_option'] = $this->translateDeliveryOption(isset($data['delivery_option']) ? $data['delivery_option'] : '');
        // Session state of an unfinished checkout, full of source ids — not transferable
        $row['checkout_session_data'] = null;

        if (!SchemaHelper::upsert('cart', $row, ['id_cart'])) {
            return;
        }

        $this->importCustomizations($sid);
        $this->importCartProducts($sid, $tid);
        $this->importCartRules($sid, $tid);
    }

    /**
     * delivery_option is JSON: {"<id_address>": "<id_carrier>,<id_carrier>,"}
     */
    private function translateDeliveryOption($value)
    {
        if ($value === null || $value === '') {
            return $value;
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            $decoded = @unserialize($value, ['allowed_classes' => false]); // PS 1.5/1.6
        }
        if (!is_array($decoded)) {
            return '';
        }

        $out = [];
        foreach ($decoded as $address => $carriers) {
            $idAddress = IdMapper::ref('address', (int)$address);
            if ($idAddress <= 0) {
                continue;
            }
            $list = [];
            foreach (explode(',', (string)$carriers) as $carrier) {
                if ((int)$carrier > 0) {
                    $idCarrier = IdMapper::ref('carrier', (int)$carrier);
                    if ($idCarrier > 0) {
                        $list[] = $idCarrier;
                    }
                }
            }
            if ($list) {
                $out[$idAddress] = implode(',', $list) . ',';
            }
        }

        return $out ? json_encode($out) : '';
    }

    private function importCartProducts($sid, $tid)
    {
        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "cart_product` WHERE id_cart = $tid");

        foreach ($this->batchRows('cart_product', $sid) as $r) {
            $row = IdMapper::row('cart_product', $r);
            if ((int)$row['id_product'] <= 0) {
                continue;
            }
            if ((int)$r['id_product_attribute'] > 0 && (int)$row['id_product_attribute'] <= 0) {
                continue;
            }
            SchemaHelper::insertIgnore('cart_product', $row);
        }
    }

    /**
     * Customizations (texts a customer entered for a product) — order lines
     * point at them.
     */
    private function importCustomizations($sid)
    {
        foreach ($this->batchRows('customization', $sid) as $r) {
            $row = IdMapper::row('customization', $r);
            if ((int)$row['id_product'] <= 0) {
                continue;
            }
            $tidCustomization = (int)$row['id_customization'];
            SchemaHelper::upsert('customization', $row, ['id_customization']);

            Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "customized_data` WHERE id_customization = $tidCustomization");
            foreach ($this->batchRows('customized_data', (int)$r['id_customization']) as $d) {
                // index = the customization field of the product
                $field = IdMapper::ref('customization_field', (int)$d['index']);
                if ($field <= 0) {
                    continue;
                }
                $d['id_customization'] = $tidCustomization;
                $d['index'] = $field;
                if (isset($d['id_module']) && (int)$d['id_module'] > 0) {
                    $d['id_module'] = 0; // module ids differ between shops
                }
                SchemaHelper::insertIgnore('customized_data', $d);
            }
        }
    }

    private function importCartRules($sid, $tid)
    {
        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "cart_cart_rule` WHERE id_cart = $tid");

        foreach ($this->batchRows('cart_cart_rule', $sid) as $r) {
            $rule = IdMapper::ref('cart_rule', (int)$r['id_cart_rule']);
            if ($rule > 0) {
                SchemaHelper::insertIgnore('cart_cart_rule', ['id_cart' => $tid, 'id_cart_rule' => $rule]);
            }
        }
    }
}
