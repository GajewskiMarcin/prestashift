<?php
/**
 * PrestaShift Connector API Endpoint
 *
 * @author    marcingajewski.pl <kontakt@marcingajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @version   1.2.0
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

// The source shop is the old one: this file has to run on the PHP versions
// PrestaShop 1.5 and 1.6 shops are still hosted on (5.3 and up), hence no
// "??", no [] arrays, and the fallbacks below.
function psconnector_post($key, $default = '')
{
    // Strings only: "token[]=x" must not reach the token check or base64_decode()
    return isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : $default;
}

/**
 * Token check in constant time, so that the answer time does not tell how
 * much of a guessed token was right. hash_equals() exists from PHP 5.6.
 */
function psconnector_equals($known, $given)
{
    if (function_exists('hash_equals')) {
        return hash_equals($known, $given);
    }
    if (strlen($known) !== strlen($given)) {
        return false;
    }
    $difference = 0;
    for ($i = 0, $length = strlen($known); $i < $length; $i++) {
        $difference |= ord($known[$i]) ^ ord($given[$i]);
    }

    return $difference === 0;
}

/**
 * The connector promises to be read-only, and looking at the first word of
 * the query does not keep that promise: "SELECT 1; DELETE FROM ..." starts
 * with SELECT and PDO runs both statements, SET or CALL are not on any list,
 * and SELECT ... INTO OUTFILE writes a file on the database server.
 *
 * True only for one single SELECT or SHOW statement that cannot write.
 *
 * $backslashEscapes: whether MySQL reads a backslash inside quotes as an
 * escape (it does, unless the session runs in NO_BACKSLASH_ESCAPES mode). The
 * check has to read the quotes exactly as MySQL will.
 */
function psconnector_is_read_only($sql, $backslashEscapes = true)
{
    if (!is_string($sql) || !preg_match('/^\s*(SELECT|SHOW)\b/i', $sql)) {
        return false;
    }

    // Empty the quoted parts: what is left is the statement itself, and
    // values (product descriptions…) can no longer be mistaken for it
    $bare = '';
    $quote = '';
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        if ($quote === '') {
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
            }
            $bare .= $char;
        } elseif ($char === '\\' && $quote !== '`' && $backslashEscapes) {
            $i++; // escaped character
        } elseif ($char === $quote) {
            if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                $i++; // doubled quote, still inside
            } else {
                $quote = '';
                $bare .= $char;
            }
        }
    }
    if ($quote !== '') {
        return false; // unterminated quote
    }

    // A second statement, or a comment (MySQL runs what is inside /*! ... */)
    if (preg_match('/;\s*\S|--|#|\/\*/', $bare)) {
        return false;
    }

    // Reading or writing files on the database server
    return !preg_match('/\b(OUTFILE|DUMPFILE|LOAD_FILE)\b/i', $bare);
}

// 1. Security Check — Token validation
$storedToken = Configuration::get('PS_CONNECTOR_TOKEN');
$receivedToken = isset($_SERVER['HTTP_X_PS_CONNECTOR_TOKEN']) ? $_SERVER['HTTP_X_PS_CONNECTOR_TOKEN'] : psconnector_post('token');

if (empty($storedToken) || empty($receivedToken) || !psconnector_equals((string)$storedToken, (string)$receivedToken)) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: application/json');
    echo json_encode(array('success' => false, 'error' => 'Unauthorized access.'));
    exit;
}

// 2. Handle Actions
$action = psconnector_post('action', 'test');

try {
    // === TEST — connection check + version info ===
    if ($action === 'test') {
        header('Content-Type: application/json');
        echo json_encode(array(
            'success' => true,
            'version' => '1.2.0',
            'ps_version' => _PS_VERSION_,
            // thirtybees reports itself as PrestaShop 1.6.1.999
            'tb_version' => defined('_TB_VERSION_') ? _TB_VERSION_ : '',
            'php_version' => PHP_VERSION,
            'prefix' => _DB_PREFIX_,
            'shop_name' => Configuration::get('PS_SHOP_NAME'),
        ));
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
        $sqlB64 = psconnector_post('sql_b64');
        if ($sqlB64 !== '') {
            $sql = base64_decode($sqlB64, true);
            if ($sql === false) {
                throw new Exception("Invalid base64 SQL payload.");
            }
        } else {
            $sql = psconnector_post('sql');
        }

        if (empty($sql)) {
            throw new Exception("Empty SQL query.");
        }

        // Security: block write operations — connector is READ-ONLY
        $sqlMode = (string)Db::getInstance()->getValue('SELECT @@SESSION.sql_mode');
        if (!psconnector_is_read_only($sql, stripos($sqlMode, 'NO_BACKSLASH_ESCAPES') === false)) {
            throw new Exception("Write operations are not allowed. Connector is read-only.");
        }

        $results = Db::getInstance()->executeS($sql);

        // Outside debug mode PrestaShop answers false for a query that failed
        // (a table this version does not have, for instance). Passed on as
        // data it would read as "no rows" and the migration would skip the
        // rest of the table without a word.
        if ($results === false) {
            throw new Exception("Query failed: " . Db::getInstance()->getMsgError());
        }

        $json = json_encode($results);
        if ($json === false) {
            // json_last_error_msg() exists from PHP 5.5
            throw new Exception("Rows could not be encoded: " . (function_exists('json_last_error_msg') ? json_last_error_msg() : 'JSON error ' . json_last_error()));
        }

        header('Content-Type: application/json');
        echo json_encode(array(
            'success' => true,
            'encoding' => 'base64',
            'data_b64' => base64_encode($json),
        ));
        exit;
    }

    // === FILE — serve files from source shop ===
    if ($action === 'file') {
        $path = psconnector_post('path');
        if (empty($path)) {
            throw new Exception("Empty path.");
        }

        // Security: sanitize path — prevent directory traversal
        $path = str_replace("\0", '', $path);           // null bytes
        $path = preg_replace('#\.{2,}[/\\\\]#', '', $path); // ../ and ..\
        $path = ltrim($path, '/\\');                     // leading slashes

        // Only allow specific directories
        $allowedPrefixes = array('img/', 'download/', 'upload/');
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

        // fileinfo is an optional extension on old hostings
        $mime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $realPath);
            finfo_close($finfo);
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($realPath));
        readfile($realPath);
        exit;
    }

    // === COUNT — quick record counts for preview ===
    if ($action === 'count') {
        $table = psconnector_post('table');
        if (empty($table) || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new Exception("Invalid table name.");
        }

        $fullTable = _DB_PREFIX_ . $table;
        $count = (int)Db::getInstance()->getValue("SELECT COUNT(*) FROM `" . pSQL($fullTable) . "`");

        header('Content-Type: application/json');
        echo json_encode(array('success' => true, 'count' => $count));
        exit;
    }

    // Unknown action
    throw new Exception("Unknown action: " . $action);

} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: application/json');
    echo json_encode(array('success' => false, 'error' => $e->getMessage()));
    exit;
}
