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

class OrderMigrationStep
{
    private $db_connection;
    private $prefix;
    private $status_mapping;

    public function __construct($db_connection, $prefix, $status_mapping = [])
    {
        $this->db_connection = $db_connection;
        $this->prefix = $prefix;
        $this->status_mapping = $status_mapping;
    }

    public function process($offset, $limit, $dateFilter = null)
    {
        $orders = $this->getOrdersFromSource($offset, $limit, $dateFilter);

        if (empty($orders)) {
            return ['count' => 0, 'finished' => true];
        }

        foreach ($orders as $order) {
            $this->importOrder($order);
        }

        return ['count' => count($orders), 'finished' => false];
    }

    private function getOrdersFromSource($offset, $limit, $dateFilter = null)
    {
        $where = "";
        if ($dateFilter) {
            $where = " WHERE `date_add` > '{$dateFilter}' OR `date_upd` > '{$dateFilter}' ";
        }
        $sql = "SELECT * FROM `{$this->prefix}orders` {$where} ORDER BY `id_order` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importOrder($data)
    {
        $id_order = (int)$data['id_order'];
        
        $orderData = [
            'id_order' => $id_order,
            'reference' => $data['reference'],
            'id_shop_group' => 1,
            'id_shop' => \PrestaShift\Service\SchemaHelper::getTargetShopId(),
            'id_carrier' => $data['id_carrier'],
            'id_lang' => $data['id_lang'],
            'id_customer' => $data['id_customer'],
            'id_cart' => $data['id_cart'],
            'id_currency' => $data['id_currency'],
            'id_address_delivery' => $data['id_address_delivery'],
            'id_address_invoice' => $data['id_address_invoice'],
            'current_state' => $this->mapOrderState((int)$data['current_state']),
            'secure_key' => $data['secure_key'],
            'payment' => $data['payment'],
            'conversion_rate' => $data['conversion_rate'],
            'module' => $data['module'],
            'recyclable' => $data['recyclable'],
            'gift' => $data['gift'],
            'gift_message' => $data['gift_message'],
            'mobile_theme' => isset($data['mobile_theme']) ? $data['mobile_theme'] : 0, // Deprecated in some versions
            'shipping_number' => isset($data['shipping_number']) ? $data['shipping_number'] : null,
            'total_discounts' => $data['total_discounts'],
            'total_discounts_tax_incl' => $data['total_discounts_tax_incl'],
            'total_discounts_tax_excl' => $data['total_discounts_tax_excl'],
            'total_paid' => $data['total_paid'],
            'total_paid_tax_incl' => $data['total_paid_tax_incl'],
            'total_paid_tax_excl' => $data['total_paid_tax_excl'],
            'total_paid_real' => $data['total_paid_real'],
            'total_products' => $data['total_products'],
            'total_products_wt' => $data['total_products_wt'],
            'total_shipping' => $data['total_shipping'],
            'total_shipping_tax_incl' => $data['total_shipping_tax_incl'],
            'total_shipping_tax_excl' => $data['total_shipping_tax_excl'],
            'carrier_tax_rate' => $data['carrier_tax_rate'],
            'total_wrapping' => $data['total_wrapping'],
            'total_wrapping_tax_incl' => $data['total_wrapping_tax_incl'],
            'total_wrapping_tax_excl' => $data['total_wrapping_tax_excl'],
            'invoice_number' => $data['invoice_number'],
            'delivery_number' => $data['delivery_number'],
            'invoice_date' => $data['invoice_date'],
            'delivery_date' => $data['delivery_date'],
            'valid' => $data['valid'],
            'date_add' => $data['date_add'],
            'date_upd' => $data['date_upd']
        ];

        // Dynamic Upsert
        $sql = \PrestaShift\Service\SchemaHelper::buildUpsertQuery('orders', $orderData, ['id_order']);
        
        if ($sql) {
            try {
                Db::getInstance()->execute($sql);
            } catch (\Exception $e) {
                // Log error
            }
        }
           
        // 2. Order Details
        $this->importOrderDetails($id_order);
        
        // 3. Order History
        $this->importOrderHistory($id_order);
    }

    /**
     * Map order state ID through status_mapping with fallback safety
     */
    private function mapOrderState($oldStateId)
    {
        $newStateId = isset($this->status_mapping[$oldStateId]) ? (int)$this->status_mapping[$oldStateId] : $oldStateId;

        // Verify the state exists in destination
        $check = Db::getInstance()->getValue(
            "SELECT id_order_state FROM " . _DB_PREFIX_ . "order_state WHERE id_order_state = " . (int)$newStateId
        );
        if (!$check) {
            $newStateId = (int)Db::getInstance()->getValue(
                "SELECT id_order_state FROM " . _DB_PREFIX_ . "order_state ORDER BY id_order_state ASC"
            );
        }

        return $newStateId;
    }

    private function importOrderDetails($id_order)
    {
        $sql = "SELECT * FROM `{$this->prefix}order_detail` WHERE id_order = $id_order";
        $stmt = $this->db_connection->query($sql);
        $details = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "order_detail` WHERE id_order = $id_order");

        foreach ($details as $d) {
             // simplified insert
             $detailData = [
                'id_order_detail' => $d['id_order_detail'],
                'id_order' => $id_order,
                'id_order_invoice' => $d['id_order_invoice'],
                'id_warehouse' => $d['id_warehouse'],
                'id_shop' => \PrestaShift\Service\SchemaHelper::getTargetShopId(),
                'product_id' => $d['product_id'],
                'product_attribute_id' => $d['product_attribute_id'],
                'product_name' => $d['product_name'],
                'product_quantity' => $d['product_quantity'],
                'product_quantity_in_stock' => $d['product_quantity_in_stock'],
                'product_quantity_refunded' => $d['product_quantity_refunded'],
                'product_quantity_return' => $d['product_quantity_return'],
                'product_quantity_reinjected' => $d['product_quantity_reinjected'],
                'product_price' => $d['product_price'],
                'reduction_percent' => $d['reduction_percent'],
                'reduction_amount' => $d['reduction_amount'],
                'reduction_amount_tax_incl' => $d['reduction_amount_tax_incl'],
                'reduction_amount_tax_excl' => $d['reduction_amount_tax_excl'],
                'group_reduction' => $d['group_reduction'],
                'product_quantity_discount' => $d['product_quantity_discount'],
                'product_ean13' => $d['product_ean13'],
                'product_isbn' => isset($d['product_isbn']) ? $d['product_isbn'] : null,
                'product_upc' => $d['product_upc'],
                'product_mpn' => isset($d['product_mpn']) ? $d['product_mpn'] : null,
                'product_reference' => $d['product_reference'],
                'product_supplier_reference' => $d['product_supplier_reference'],
                'product_weight' => $d['product_weight'],
                'tax_computation_method' => $d['tax_computation_method'],
                'tax_name' => $d['tax_name'],
                'tax_rate' => $d['tax_rate'],
                'ecotax' => $d['ecotax'],
                'ecotax_tax_rate' => $d['ecotax_tax_rate'],
                'discount_quantity_applied' => $d['discount_quantity_applied'],
                'download_hash' => $d['download_hash'],
                'download_nb' => $d['download_nb'],
                'download_deadline' => $d['download_deadline'],
                'total_price_tax_incl' => $d['total_price_tax_incl'],
                'total_price_tax_excl' => $d['total_price_tax_excl'],
                'unit_price_tax_incl' => $d['unit_price_tax_incl'],
                'unit_price_tax_excl' => $d['unit_price_tax_excl'],
                'total_shipping_price_tax_incl' => $d['total_shipping_price_tax_incl'],
                'total_shipping_price_tax_excl' => $d['total_shipping_price_tax_excl'],
                'purchase_supplier_price' => $d['purchase_supplier_price'],
                'original_product_price' => $d['original_product_price'],
                'original_wholesale_price' => $d['original_wholesale_price']
             ];
             
             // Dynamic Insert for Details
             $sql = \PrestaShift\Service\SchemaHelper::buildInsertQuery('order_detail', $detailData, true);
             if ($sql) {
                 Db::getInstance()->execute($sql);
             }
        }
    }
    
    private function importOrderHistory($id_order) {
        $sql = "SELECT * FROM `{$this->prefix}order_history` WHERE id_order = $id_order";
        $stmt = $this->db_connection->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        Db::getInstance()->execute("DELETE FROM `" . \_DB_PREFIX_ . "order_history` WHERE id_order = $id_order");
        
        foreach ($rows as $r) {
             $historyData = [
                 'id_order' => $id_order,
                 'id_order_state' => $this->mapOrderState((int)$r['id_order_state']),
                 'id_employee' => 0,
                 'date_add' => $r['date_add']
             ];
             $sql = \PrestaShift\Service\SchemaHelper::buildInsertQuery('order_history', $historyData, true);
             if ($sql) {
                 Db::getInstance()->execute($sql);
             }
        }
    }
}
