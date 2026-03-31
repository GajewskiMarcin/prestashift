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
use PrestaShift\Service\SchemaHelper;

class MessageMigrationStep
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
         // We migrate by Customer Thread to keep data consistent
         // 1. Get Threads
         $threads = $this->getCustomerThreads($offset, $limit, $dateFilter);
         
         if (empty($threads)) {
             return ['count' => 0, 'finished' => true];
         }
         
         foreach ($threads as $thread) {
             $this->importThread($thread);
         }
         
         return ['count' => count($threads), 'finished' => false];
    }
    
    private function getCustomerThreads($offset, $limit, $dateFilter = null)
    {
        $where = "";
        if ($dateFilter) {
            $where = " WHERE `date_add` > '{$dateFilter}' OR `date_upd` > '{$dateFilter}' ";
        }
        $sql = "SELECT * FROM `{$this->prefix}customer_thread` {$where} ORDER BY `id_customer_thread` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function importThread($data) {
        $id_thread = (int)$data['id_customer_thread'];
        
        // Clean
        $data['id_shop'] = \PrestaShift\Service\SchemaHelper::getTargetShopId();
        
        // Upsert Thread
        $sql = SchemaHelper::buildUpsertQuery('customer_thread', $data, ['id_customer_thread']);
        if ($sql) {
            Db::getInstance()->execute($sql);
            
            // Import Messages for this Thread
            $this->importMessages($id_thread);
        }
    }
    
    private function importMessages($id_thread) {
        $sql = "SELECT * FROM `{$this->prefix}customer_message` WHERE id_customer_thread = $id_thread";
        $messages = $this->db_connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($messages as $msg) {
             $sql = SchemaHelper::buildUpsertQuery('customer_message', $msg, ['id_customer_message']);
             if ($sql) {
                 Db::getInstance()->execute($sql);
             }
        }
    }
}
