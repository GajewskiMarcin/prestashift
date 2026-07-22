<?php
/**
 * PrestaShift Migration Module
 * 
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @version   1.0.0
 */
namespace PrestaShift\Service;

use Exception;

class ConnectorClient
{
    private $url;
    private $token;

    public function __construct($url, $token)
    {
        $this->url = rtrim($url, '/');
        $this->token = $token;
    }

    /**
     * Mocks PDO::query()
     */
    public function query($sql)
    {
        $response = $this->request('query', ['sql' => $sql]);
        
        if (!$response['success']) {
            throw new Exception("Connector Query Error: " . ($response['error'] ?? 'Unknown error'));
        }

        return new ConnectorResult($response['data']);
    }

    public function test()
    {
        return $this->request('test');
    }

    /**
     * Fetch file from source via bridge
     */
    public function getFile($path)
    {
        return $this->requestRaw('file', ['path' => $path]);
    }

    private function request($action, $params = [])
    {
        $params['action'] = $action;
        $params['token'] = $this->token;

        $ch = curl_init($this->url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-PS-Connector-Token: ' . $this->token
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 403) {
            throw new Exception("Connector Error: 403 Forbidden. Check your token.");
        }

        if ($httpCode === 404) {
            throw new Exception("Connector Error: 404 Not Found. Check your Endpoint URL.");
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Connector Error: Invalid JSON response. Is the URL correct? Response: " . substr($response, 0, 100));
        }

        return $data;
    }

    private function requestRaw($action, $params = [])
    {
        $params['action'] = $action;
        $params['token'] = $this->token;

        $ch = curl_init($this->url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-PS-Connector-Token: ' . $this->token
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return false;
        }

        return $response;
    }
}

/**
 * Mock class for PDOStatement
 */
class ConnectorResult
{
    private $data;
    private $cursor = 0;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function fetchAll($mode = null)
    {
        return $this->data;
    }

    public function fetch($mode = null)
    {
        if (isset($this->data[$this->cursor])) {
            return $this->data[$this->cursor++];
        }
        return false;
    }

    public function fetchColumn($column_number = 0)
    {
        if (empty($this->data) || !isset($this->data[0])) {
            return false;
        }
        $row = array_values($this->data[0]);
        return $row[$column_number] ?? false;
    }
}
