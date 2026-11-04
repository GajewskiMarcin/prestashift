<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcingajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @attribution PrestaShift by Marcin Gajewski (marcingajewski.pl). Keep the Attribution Notice in prestashift.php — AFL-3.0 section 6.
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

    public function __construct($host, $port, $dbname, $user, $pass, $prefix, $source_url = '', $sslCa = '')
    {
        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

        if (!$this->isLocalHost($host)) {
            $sslCa = trim((string) $sslCa);
            $isAbsolutePath = DIRECTORY_SEPARATOR === '\\'
                ? (bool) preg_match('#^(?:[A-Za-z]:[\\\\/]|\\\\\\\\)#', $sslCa)
                : isset($sslCa[0]) && $sslCa[0] === '/';
            $resolvedCa = $isAbsolutePath ? realpath($sslCa) : false;
            if ($resolvedCa === false || !is_file($resolvedCa) || !is_readable($resolvedCa)) {
                throw new Exception('A readable SSL CA certificate file is required for remote database connections.');
            }

            if (class_exists('Pdo\\Mysql', false)) {
                $options[constant('Pdo\\Mysql::ATTR_SSL_CA')] = $resolvedCa;
                $options[constant('Pdo\\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')] = true;
            } elseif (defined('PDO::MYSQL_ATTR_SSL_CA') && defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $options[constant('PDO::MYSQL_ATTR_SSL_CA')] = $resolvedCa;
                $options[constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')] = true;
            } else {
                throw new Exception('The PDO MySQL driver must support SSL certificate verification for remote database connections.');
            }
        }

        $this->pdo = new PDO($dsn, $user, $pass, $options);
        $this->prefix = $prefix;
        $this->source_url = rtrim($source_url, '/');
    }

    private function isLocalHost($host)
    {
        return in_array(strtolower(trim((string) $host)), ['localhost', '127.0.0.1', '::1', '[::1]'], true);
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
