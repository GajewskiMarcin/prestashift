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
     * Fetch a source file over verified HTTPS.
     */
    public function getFile($path, $expectedFilename = null)
    {
        $parts = parse_url($this->source_url);
        if (!$parts || !isset($parts['scheme'], $parts['host'])
            || strtolower($parts['scheme']) !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        $path = ltrim((string)$path, '/');
        if (strpos($path, "\0") !== false
            || !preg_match('#^(?:img|download|upload)/#', $path)
            || preg_match('#(?:^|/)\.\.?(/|$)#', $path)) {
            return false;
        }

        $urlPath = implode('/', array_map('rawurlencode', explode('/', $path)));
        $url = rtrim($this->source_url, '/') . '/' . $urlPath;
        $curl = curl_init($url);
        if ($curl === false) {
            return false;
        }

        $optionsSet = curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        if (!$optionsSet) {
            curl_close($curl);
            return false;
        }

        $content = curl_exec($curl);
        $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $contentType = strtolower((string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE));
        curl_close($curl);

        if ($content === false || $content === '' || $httpCode < 200 || $httpCode >= 300) {
            return false;
        }

        $extension = strtolower(pathinfo((string)($expectedFilename ?: basename($path)), PATHINFO_EXTENSION));
        $isHtmlFile = in_array($extension, ['html', 'htm', 'xhtml'], true);
        $bodyPrefix = ltrim(substr($content, 0, 512), "\xEF\xBB\xBF \t\n\r\0\x0B");
        $looksLikeHtml = preg_match('/^(?:<!doctype\s+html\b|<html\b|<head\b|<body\b)/i', $bodyPrefix);
        if (!$isHtmlFile && ($looksLikeHtml
            || strpos($contentType, 'text/html') === 0
            || strpos($contentType, 'application/xhtml+xml') === 0)) {
            return false;
        }

        return $content;
    }
}
