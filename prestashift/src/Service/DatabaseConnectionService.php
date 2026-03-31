<?php
/**
 * PrestaShift Migration Module
 * 
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @version   1.0.0
 */
namespace PrestaShift\Service;

use PDO;
use PDOException;
use Exception;

class DatabaseConnectionService
{
    /**
     * Connect to external database using PDO
     */
    public function connect($host, $user, $pass, $name, $port = 3306)
    {
        $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
        ];

        try {
            // Explicitly use global namespace \PDO
            return new \PDO($dsn, $user, $pass, $options);
        } catch (\PDOException $e) {
            throw new Exception("MySQL Error: " . $e->getMessage());
        } catch (\Throwable $e) {
             throw new Exception("Fatal PDO Error: " . $e->getMessage());
        }
    }

    /**
     * Verify if the prefix is valid by checking if a core table exists
     */
    public function verifyPrefix(PDO $connection, $prefix)
    {
        try {
            // Check for ps_employee or ps_configuration
            $stmt = $connection->query("SELECT 1 FROM `{$prefix}configuration` LIMIT 1");
            return $stmt !== false;
        } catch (PDOException $e) {
            return false;
        }
    }
}
