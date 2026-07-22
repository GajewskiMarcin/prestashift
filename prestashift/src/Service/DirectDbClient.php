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
use Exception;

class DirectDbClient
{
    private $pdo;
    private $prefix;
    private $source_url;

    public function __construct($host, $port, $dbname, $user, $pass, $prefix, $source_url = '')
    {
        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
        $this->pdo = new PDO($dsn, $user, $pass);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->prefix = $prefix;
        $this->source_url = rtrim($source_url, '/');
    }

    /**
     * Execute SQL query and return ConnectorResult
     */
    public function query($sql)
    {
        $stmt = $this->pdo->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return new ConnectorResult($data);
    }

    /**
     * Test connection and detect PrestaShop version
     */
    public function test()
    {
        try {
            $stmt = $this->pdo->query("SELECT VERSION() as v");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $psVersion = null;
            try {
                $stmt2 = $this->pdo->query(
                    "SELECT value FROM {$this->prefix}configuration WHERE name = 'PS_VERSION_DB' LIMIT 1"
                );
                $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);
                if ($row2) {
                    $psVersion = $row2['value'];
                }
            } catch (Exception $e) {
                // table may not exist — ignore
            }

            return [
                'success'    => true,
                'ps_version' => $psVersion,
                'prefix'     => $this->prefix,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch file from source shop via HTTP
     */
    public function getFile($path)
    {
        try {
            if ($this->source_url) {
                return @file_get_contents($this->source_url . '/' . ltrim($path, '/'));
            }
            return false;
        } catch (Exception $e) {
            return false;
        }
    }
}
