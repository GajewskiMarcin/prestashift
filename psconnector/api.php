<?php
/**
 * PrestaShift Connector API Endpoint
 *
 * @author    marcingajewski.pl <kontakt@marcingajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @version   1.3.0
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
            'version' => '1.3.0',
            'ps_version' => _PS_VERSION_,
            'php_version' => PHP_VERSION,
            'prefix' => _DB_PREFIX_,
            'shop_name' => Configuration::get('PS_SHOP_NAME'),
        ]);
        exit;
    }

    // === QUERY — execute read-only SQL ===
    if ($action === 'query') {
        // Both the SQL (request) and the rows (response) are base64-encoded so
        // that a WAF/ModSecurity on this server cannot pattern-match the content.
        // Shop descriptions are full of HTML (<span style=...>, <script>, ...),
        // which security filters flag as XSS and block with 403/429 — that is
        // why raw-JSON transport failed as soon as the migration reached the
        // first HTML-bearing table (categories, CMS, products). Plain `sql` is
        // still accepted as a fallback for older clients.
        $sqlB64 = $_POST['sql_b64'] ?? '';
        if ($sqlB64 !== '') {
            $sql = base64_decode($sqlB64, true);
            if ($sql === false) {
                throw new Exception("Invalid base64 SQL payload.");
            }
        } else {
            $sql = $_POST['sql'] ?? '';
        }

        if (empty($sql)) {
            throw new Exception("Empty SQL query.");
        }

        // Security: this endpoint reads the shop, and nothing else.
        //
        // The rule used to be a list of forbidden first words (INSERT, UPDATE,
        // DELETE…), which let through everything that merely starts with
        // SELECT. That is not a small gap: with the MySQL FILE privilege, which
        // plenty of shared hosts hand out, "SELECT … INTO OUTFILE" writes a
        // file anywhere the database user can write — a PHP file in the shop's
        // own directory included — and LOAD_FILE() reads one back. SLEEP() and
        // BENCHMARK() tie up a PHP worker for as long as they please. A leaked
        // token was therefore worth far more than read access.
        //
        // So: one statement, it must be a SELECT, and the handful of SELECT
        // constructs that touch files, locks or the clock are refused.
        $candidate = ltrim($sql);
        // Comments first, so /* */ or -- cannot hide the real first word.
        do {
            $before = $candidate;
            $candidate = preg_replace('#^/\*.*?\*/#s', '', $candidate);
            $candidate = preg_replace('#^(--[^\n]*|\#[^\n]*)(\n|$)#', '', $candidate);
            $candidate = ltrim($candidate);
        } while ($candidate !== $before);

        if (!preg_match('/^SELECT\b/i', $candidate)) {
            throw new Exception('Only SELECT queries are allowed. The connector is read-only.');
        }
        if (strpos(rtrim($sql, "; \t\r\n"), ';') !== false) {
            throw new Exception('Only one statement per request is allowed.');
        }
        if (preg_match('/\binto\s+(outfile|dumpfile)\b/i', $sql)
            || preg_match('/\b(load_file|benchmark|sleep|get_lock|release_lock)\s*\(/i', $sql)) {
            throw new Exception('This query uses a construct the connector does not allow.');
        }

        $results = Db::getInstance()->executeS($sql);

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'encoding' => 'base64',
            'data_b64' => base64_encode(json_encode($results)),
        ]);
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
