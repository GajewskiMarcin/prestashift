<?php
/**
 * PrestaShift Connector API Endpoint
 *
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @version   1.1.0
 */

// Prevent PrestaShop from redirecting based on domain mismatch
if (!defined('_PS_ADMIN_DIR_')) {
    define('_PS_ADMIN_DIR_', dirname(__FILE__) . '/../../admin');
}
// Suppress any output/redirects during bootstrap
ob_start();
require_once(dirname(__FILE__) . '/../../config/config.inc.php');
ob_end_clean();

// Override any redirect that PS core may have queued
header_remove('Location');

// 1. Security Check — Token validation
$storedToken = Configuration::get('PS_CONNECTOR_TOKEN');
$receivedToken = $_SERVER['HTTP_X_PS_CONNECTOR_TOKEN'] ?? $_POST['token'] ?? '';

if (empty($storedToken) || empty($receivedToken) || !hash_equals($storedToken, $receivedToken)) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit;
}

// 2. Handle Actions
$action = $_POST['action'] ?? 'test';

try {
    // === TEST — connection check + version info ===
    if ($action === 'test') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'version' => '1.1.0',
            'ps_version' => _PS_VERSION_,
            'php_version' => PHP_VERSION,
            'prefix' => _DB_PREFIX_,
            'shop_name' => Configuration::get('PS_SHOP_NAME'),
        ]);
        exit;
    }

    // === QUERY — execute read-only SQL ===
    if ($action === 'query') {
        $sql = $_POST['sql'] ?? '';
        if (empty($sql)) {
            throw new Exception("Empty SQL query.");
        }

        // Security: block write operations — connector is READ-ONLY
        $sqlUpper = strtoupper(trim($sql));
        $blocked = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE', 'CREATE', 'REPLACE', 'RENAME', 'GRANT', 'REVOKE'];
        foreach ($blocked as $keyword) {
            if (strpos($sqlUpper, $keyword) === 0) {
                throw new Exception("Write operations are not allowed. Connector is read-only.");
            }
        }

        $results = Db::getInstance()->executeS($sql);

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'data' => $results]);
        exit;
    }

    // === FILE — serve files from source shop ===
    if ($action === 'file') {
        $path = $_POST['path'] ?? '';
        if (empty($path)) {
            throw new Exception("Empty path.");
        }

        // Security: sanitize path — prevent directory traversal
        $path = str_replace("\0", '', $path);           // null bytes
        $path = preg_replace('#\.{2,}[/\\\\]#', '', $path); // ../ and ..\
        $path = ltrim($path, '/\\');                     // leading slashes

        // Only allow specific directories
        $allowedPrefixes = ['img/', 'download/', 'upload/'];
        $allowed = false;
        foreach ($allowedPrefixes as $prefix) {
            if (strpos($path, $prefix) === 0) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new Exception("Access denied. Only img/, download/, upload/ directories are accessible.");
        }

        $fullPath = _PS_ROOT_DIR_ . DIRECTORY_SEPARATOR . $path;
        $realPath = realpath($fullPath);

        // Verify resolved path is still within PS root
        if (!$realPath || strpos($realPath, realpath(_PS_ROOT_DIR_)) !== 0) {
            throw new Exception("File not found or access denied.");
        }

        if (!file_exists($realPath) || !is_file($realPath)) {
            throw new Exception("File not found: " . $path);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $realPath);
        finfo_close($finfo);

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($realPath));
        readfile($realPath);
        exit;
    }

    // === COUNT — quick record counts for preview ===
    if ($action === 'count') {
        $table = $_POST['table'] ?? '';
        if (empty($table) || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new Exception("Invalid table name.");
        }

        $fullTable = _DB_PREFIX_ . $table;
        $count = (int)Db::getInstance()->getValue("SELECT COUNT(*) FROM `" . pSQL($fullTable) . "`");

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'count' => $count]);
        exit;
    }

    // Unknown action
    throw new Exception("Unknown action: " . $action);

} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
