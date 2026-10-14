<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcingajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @attribution PrestaShift by Marcin Gajewski (marcingajewski.pl). Keep the Attribution Notice in prestashift.php — AFL-3.0 section 6.
 * @version   1.5.0
 */
namespace PrestaShift\Service;

use Db;

/**
 * Old addresses of the source shop that differ in the new one, and the ways
 * to send visitors from the first to the second: the URL patterns of the
 * source copied here, a block of rules in .htaccess, a file of nginx rules,
 * and the module itself (hookActionDispatcherBefore) as the fallback.
 */
class UrlRedirects
{
    const TABLE = 'prestashift_url';

    /** Option of the migration => redirect status code */
    const MODES = [
        'match' => 301,
        'htaccess_301' => 301,
        'htaccess_302' => 302,
        'nginx_301' => 301,
        'nginx_302' => 302,
    ];

    /** Rows in the table: 0 lets the hook return before any query */
    const COUNT_KEY = 'PRESTASHIFT_URL_REDIRECTS';
    const CODE_KEY = 'PRESTASHIFT_URL_REDIRECT_CODE';

    /** URL patterns of the source written here by "keep the old addresses" */
    const ROUTES_KEY = 'PRESTASHIFT_URL_ROUTES';

    const HTACCESS_FILE = 'prestashift_redirects.htaccess';
    const NGINX_FILE = 'prestashift_redirects.nginx.conf';

    const BLOCK_START = '# ~~prestashift-redirects~~ start';
    const BLOCK_END = '# ~~prestashift-redirects~~ end';

    /** Header of the module's own redirects: tells the .htaccess check who answered */
    const FALLBACK_HEADER = 'X-PrestaShift-Redirect';

    public static function mode(array $options)
    {
        $mode = isset($options['url_mode']) ? (string) $options['url_mode'] : 'match';

        return isset(self::MODES[$mode]) ? $mode : 'match';
    }

    public static function ensureTable()
    {
        Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
            `id_shop` INT UNSIGNED NOT NULL,
            `path_hash` CHAR(32) NOT NULL,
            `old_path` TEXT NOT NULL,
            `entity` VARCHAR(32) NOT NULL,
            `id_target` INT UNSIGNED NOT NULL,
            `id_lang` INT UNSIGNED NOT NULL,
            `new_path` TEXT NOT NULL,
            PRIMARY KEY (`id_shop`, `path_hash`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
    }

    public static function clear($idShop)
    {
        self::ensureTable();
        Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_shop` = ' . (int) $idShop);
        self::refreshCount();
    }

    /**
     * @param array $rows [[old path, entity, target id, target id_lang, new path], ...]
     */
    public static function store($idShop, array $rows)
    {
        if (empty($rows)) {
            return;
        }
        $values = [];
        foreach ($rows as $row) {
            list($old, $entity, $idTarget, $idLang, $new) = $row;
            $values[] = '(' . (int) $idShop . ", '" . md5($old) . "', '" . pSQL($old) . "', '" . pSQL($entity) . "', "
                . (int) $idTarget . ', ' . (int) $idLang . ", '" . pSQL($new) . "')";
        }
        Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . self::TABLE . '`
            (`id_shop`, `path_hash`, `old_path`, `entity`, `id_target`, `id_lang`, `new_path`)
            VALUES ' . implode(', ', $values) . '
            ON DUPLICATE KEY UPDATE `old_path` = VALUES(`old_path`), `entity` = VALUES(`entity`), `id_target` = VALUES(`id_target`),
                `id_lang` = VALUES(`id_lang`), `new_path` = VALUES(`new_path`)');
    }

    public static function refreshCount()
    {
        $count = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '`', false);
        \Configuration::updateGlobalValue(self::COUNT_KEY, $count);

        return $count;
    }

    public static function count($idShop)
    {
        return (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_shop` = ' . (int) $idShop, false);
    }

    /**
     * A path goes into server configuration files: one that could carry a
     * directive (line break, quote, brace, semicolon…) is refused, whatever
     * the source database holds.
     */
    public static function safePath($path)
    {
        return is_string($path)
            && strlen($path) > 1
            && strlen($path) <= 2000
            && $path[0] === '/'
            && !preg_match('/[\x00-\x20\x7F"\'\\\\?#<>`{};]/', $path);
    }

    /** Everything but unreserved characters and "/" percent-encoded */
    public static function encodePath($path)
    {
        return preg_replace_callback('#[^A-Za-z0-9\-._~/]#', function ($m) {
            return '%' . strtoupper(bin2hex($m[0]));
        }, $path);
    }

    /**
     * A URL pattern is copied only if this shop can read it.
     *
     * thirtybees builds its addresses without ids ({categories:/}{rewrite});
     * PrestaShop cannot resolve such a pattern, and with it in place the home
     * page and every product answer 404. A product pattern written for
     * PrestaShop 1.6 only lacks the combination, which later versions require:
     * it is added as an option, so the old addresses stay as they are.
     *
     * @return string|false the pattern to write, false when it is unusable
     */
    public static function usableRoute($route, $rule)
    {
        $dispatcher = \Dispatcher::getInstance();
        $errors = [];
        if ($dispatcher->validateRoute($route, $rule, $errors)) {
            return $rule;
        }

        if ($route === 'product_rule' && strpos($rule, 'id_product_attribute') === false) {
            $withCombination = str_replace('{id}', '{id}{-:id_product_attribute}', $rule);
            if ($dispatcher->validateRoute($route, $withCombination, $errors)) {
                return $withCombination;
            }
        }

        LogService::getInstance()->warning("URL pattern PS_ROUTE_$route of the source ($rule) cannot be used in this PrestaShop version — the default pattern is kept, the old addresses of these pages change.");

        return false;
    }

    /**
     * "Keep the old addresses": the patterns of the source become the
     * patterns of this shop. Written explicitly, also where the source kept
     * its defaults: PrestaShop 9 has other defaults than 1.6, 1.7 and 8.
     *
     * @return int patterns written
     */
    public static function applySourceRoutes(SourceUrlBuilder $builder)
    {
        // PrestaShop 9 reads a pattern per language, 1.7 and 8 one pattern for all
        // languages (stored under language 0 here): that of the source's default language
        $perLanguage = version_compare(_PS_VERSION_, '9.0.0', '>=');
        $languages = array_keys($builder->languages());
        $default = (int) IdMapper::sourceConfig('PS_LANG_DEFAULT', 0);
        if (in_array($default, $languages, true)) {
            $languages = array_values(array_unique(array_merge([$default], $languages)));
        }

        $written = 0;
        $record = [];
        foreach (SourceUrlBuilder::ROUTES as $route) {
            $byLang = [];
            foreach ($languages as $sourceLang) {
                $targetLang = $perLanguage ? (int) LanguageMapper::toTarget($sourceLang) : 0;
                $rule = $builder->rule($route, $sourceLang);
                if (($perLanguage && $targetLang <= 0) || $rule === '' || isset($byLang[$targetLang])) {
                    continue;
                }
                $usable = self::usableRoute($route, $rule);
                if ($usable !== false) {
                    $byLang[$targetLang] = $usable;
                }
            }
            if (!empty($byLang) && \Configuration::updateValue('PS_ROUTE_' . $route, $perLanguage ? $byLang : $byLang[0])) {
                $written += count($byLang);
                $record[$route] = $byLang;
            }
        }
        \Configuration::updateGlobalValue(self::ROUTES_KEY, json_encode($record));

        return $written;
    }

    /**
     * The redirect modes give the pages the addresses of this shop. Patterns
     * an earlier "keep the old addresses" run wrote are taken back — only
     * those still holding exactly what was written: a pattern changed by hand
     * since then stays.
     */
    public static function restoreTargetRoutes()
    {
        $record = json_decode((string) \Configuration::get(self::ROUTES_KEY), true);
        if (!is_array($record)) {
            return;
        }
        foreach ($record as $route => $byLang) {
            $name = 'PS_ROUTE_' . $route;
            $current = [];
            foreach ((array) $byLang as $idLang => $rule) {
                $idLang = (int) $idLang;
                $value = $idLang > 0 ? \Configuration::get($name, $idLang) : \Configuration::get($name);
                if ((string) $value === (string) $rule) {
                    $current[$idLang] = '';
                }
            }
            if (isset($current[0])) {
                \Configuration::updateValue($name, '');
                unset($current[0]);
            }
            if (!empty($current)) {
                \Configuration::updateValue($name, $current);
            }
        }
        \Configuration::deleteByName(self::ROUTES_KEY);
    }

    /**
     * Address of a migrated page in this shop, from the root of the domain.
     */
    public static function targetPath(\Link $link, $entity, $idTarget, $idLang, $idShop)
    {
        $path = parse_url(self::targetUrl($link, $entity, $idTarget, $idLang, $idShop), PHP_URL_PATH);

        return $path ? rawurldecode($path) : '';
    }

    public static function targetUrl(\Link $link, $entity, $idTarget, $idLang, $idShop)
    {
        switch ($entity) {
            case 'product':
                $url = $link->getProductLink((int) $idTarget, null, null, null, (int) $idLang, (int) $idShop);
                break;
            case 'category':
                $url = $link->getCategoryLink((int) $idTarget, null, (int) $idLang, null, (int) $idShop);
                break;
            case 'cms':
                $url = $link->getCMSLink((int) $idTarget, null, null, (int) $idLang, (int) $idShop);
                break;
            case 'cms_category':
                $url = $link->getCMSCategoryLink((int) $idTarget, null, (int) $idLang, (int) $idShop);
                break;
            case 'manufacturer':
                $url = $link->getManufacturerLink((int) $idTarget, null, (int) $idLang, (int) $idShop);
                break;
            case 'supplier':
                $url = $link->getSupplierLink((int) $idTarget, null, (int) $idLang, (int) $idShop);
                break;
            default:
                return '';
        }

        return (string) $url;
    }

    /**
     * Hook side: the redirect for the requested path, or null.
     *
     * @return array|null row of the table
     */
    public static function lookup($path, $idShop)
    {
        return Db::getInstance()->getRow('SELECT `entity`, `id_target`, `id_lang`, `new_path` FROM `' . _DB_PREFIX_ . self::TABLE . '`
            WHERE `id_shop` = ' . (int) $idShop . " AND `path_hash` = '" . md5($path) . "'", false) ?: null;
    }

    /**
     * After the migration: writes the .htaccess block and the nginx rules
     * (both downloadable in every mode, so the module can be switched off
     * later), and in the .htaccess modes puts the block into the shop's
     * .htaccess.
     *
     * @return array report for the last screen of the wizard
     */
    public static function finish(array $options, $idShop)
    {
        self::ensureTable();
        $mode = self::mode($options);
        $code = self::MODES[$mode];
        \Configuration::updateGlobalValue(self::CODE_KEY, $code);
        $count = self::count($idShop);

        $report = [
            'mode' => $mode,
            'code' => $code,
            'count' => $count,
            'htaccess' => null,
            'nginx_preview' => '',
            'outside' => 0,
            'files' => [],
        ];
        $htaccessMode = strpos($mode, 'htaccess') === 0;

        // A block from an earlier run points at addresses that may have changed
        // since; with the old patterns kept here it would even loop
        if ($count === 0 || !$htaccessMode) {
            $report['htaccess'] = self::removeBlock();
        }
        if ($count === 0) {
            self::removeFile(self::HTACCESS_FILE);
            self::removeFile(self::NGINX_FILE);

            return $report;
        }

        $shop = new \Shop((int) $idShop);
        $base = '/' . trim((string) $shop->physical_uri, '/');
        $base = rtrim($base, '/') . '/';

        $htaccess = [self::BLOCK_START . ' — old addresses of the migrated shop, ' . date('Y-m-d H:i') . '. Remove the whole block to drop them.',
            '<IfModule mod_rewrite.c>', 'RewriteEngine on'];
        $nginx = ['# PrestaShift — old addresses of the migrated shop, ' . date('Y-m-d H:i') . '.',
            '# Paste inside the server { } block of the shop, above "location /", then reload nginx.'];

        $offset = 0;
        do {
            $rows = Db::getInstance()->executeS('SELECT `old_path`, `new_path` FROM `' . _DB_PREFIX_ . self::TABLE . '`
                WHERE `id_shop` = ' . (int) $idShop . ' ORDER BY `old_path` LIMIT 1000 OFFSET ' . (int) $offset, true, false);
            foreach ($rows as $row) {
                if (!self::safePath($row['old_path']) || !self::safePath($row['new_path'])) {
                    continue;
                }
                $target = self::encodePath($row['new_path']);
                // The host the visitor asked for, port included: the rules survive a change of domain
                $nginx[] = 'location = "' . $row['old_path'] . '" { return ' . $code . ' $scheme://$http_host' . $target . '$is_args$args; }';
                // .htaccess rules only see what lies below the folder of the shop
                if (strpos($row['old_path'], $base) !== 0) {
                    ++$report['outside'];
                    continue;
                }
                $relative = substr($row['old_path'], strlen($base));
                $htaccess[] = 'RewriteRule ^' . preg_quote($relative) . '$ ' . $target . ' [R=' . $code . ',L,NE]';
            }
            $offset += 1000;
        } while (count($rows) === 1000);

        $htaccess[] = '</IfModule>';
        $htaccess[] = self::BLOCK_END;

        $report['files']['htaccess'] = self::writeFile(self::HTACCESS_FILE, implode("\n", $htaccess) . "\n");
        $report['files']['nginx'] = self::writeFile(self::NGINX_FILE, implode("\n", $nginx) . "\n");
        $report['nginx_preview'] = implode("\n", array_slice($nginx, 0, 52));

        if ($htaccessMode) {
            $report['htaccess'] = self::installBlock(implode("\n", $htaccess), $idShop);
        }

        return $report;
    }

    /** Folder of the generated files: the module's log folder */
    public static function fileDir()
    {
        $dir = _PS_ROOT_DIR_ . '/var/logs/';

        return is_dir($dir) ? $dir : _PS_ROOT_DIR_ . '/log/';
    }

    /**
     * Path of a generated file for the download action — only these two
     * names, nothing from the request.
     */
    public static function filePath($type)
    {
        $names = ['htaccess' => self::HTACCESS_FILE, 'nginx' => self::NGINX_FILE];
        if (!isset($names[$type])) {
            return null;
        }
        $path = self::fileDir() . $names[$type];

        return is_file($path) ? $path : null;
    }

    private static function writeFile($name, $content)
    {
        return @file_put_contents(self::fileDir() . $name, $content) !== false;
    }

    private static function removeFile($name)
    {
        if (is_file(self::fileDir() . $name)) {
            @unlink(self::fileDir() . $name);
        }
    }

    /**
     * Puts the block into the shop's .htaccess, above the part PrestaShop
     * writes (# ~~start~~): Tools::generateHtaccess() keeps what lies outside
     * its own markers, so the rules survive a regeneration. A copy of the
     * file is kept first; if the shop answers with a server error afterwards,
     * the copy is put back.
     */
    private static function installBlock($block, $idShop)
    {
        $file = _PS_ROOT_DIR_ . '/.htaccess';
        $current = is_file($file) ? (string) file_get_contents($file) : '';
        if ((is_file($file) && !is_writable($file)) || (!is_file($file) && !is_writable(_PS_ROOT_DIR_))) {
            return ['status' => 'not_writable'];
        }

        $home = self::homeUrl($idShop);
        $before = self::probe($home);

        $backup = self::fileDir() . 'prestashift_htaccess_backup_' . date('Ymd_His') . '.txt';
        if ($current !== '' && @file_put_contents($backup, $current) === false) {
            return ['status' => 'no_backup'];
        }

        $content = self::withoutBlock($current);
        $marker = strpos($content, '# ~~start~~');
        $content = $marker === false
            ? $block . "\n\n" . ltrim($content)
            : substr($content, 0, $marker) . $block . "\n\n" . substr($content, $marker);
        if (@file_put_contents($file, $content) === false) {
            return ['status' => 'not_writable'];
        }

        $result = ['status' => 'unverified', 'backup' => basename($backup)];
        $after = self::probe($home);
        if ($before['code'] > 0 && $before['code'] < 500 && $after['code'] >= 500) {
            @file_put_contents($file, $current);
            $result['status'] = 'restored';

            return $result;
        }

        // One old address: does the server itself redirect it, or only the module?
        $row = Db::getInstance()->getRow('SELECT `old_path`, `new_path` FROM `' . _DB_PREFIX_ . self::TABLE . '`
            WHERE `id_shop` = ' . (int) $idShop . ' ORDER BY `old_path`', false);
        if ($row && $after['code'] > 0) {
            $parts = parse_url($home);
            $probe = self::probe($parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . self::encodePath($row['old_path']));
            $location = $probe['location'] !== '' ? rawurldecode((string) parse_url($probe['location'], PHP_URL_PATH)) : '';
            if ($probe['code'] > 0) {
                $redirected = in_array($probe['code'], [301, 302], true) && $location === $row['new_path'];
                $result['status'] = $redirected && !$probe['fallback'] ? 'verified' : 'ignored';
            }
        }

        return $result;
    }

    /** In the .htaccess modes with nothing to redirect: an earlier block goes */
    private static function removeBlock()
    {
        $file = _PS_ROOT_DIR_ . '/.htaccess';
        if (!is_file($file)) {
            return null;
        }
        $current = (string) file_get_contents($file);
        $content = self::withoutBlock($current);
        if ($content !== $current && is_writable($file)) {
            @file_put_contents($file, $content);

            return ['status' => 'removed'];
        }

        return null;
    }

    private static function withoutBlock($content)
    {
        $start = strpos($content, self::BLOCK_START);
        $end = strpos($content, self::BLOCK_END);
        if ($start === false || $end === false || $end < $start) {
            return $content;
        }

        return substr($content, 0, $start) . ltrim(substr($content, $end + strlen(self::BLOCK_END)));
    }

    private static function homeUrl($idShop)
    {
        $link = new \Link();

        return $link->getPageLink('index', null, null, null, false, (int) $idShop);
    }

    /**
     * One request to the shop itself, redirects not followed.
     *
     * @return array code (0: no answer), location, fallback (answered by the module)
     */
    private static function probe($url)
    {
        $result = ['code' => 0, 'location' => '', 'fallback' => false];
        if (!function_exists('curl_init') || !$url) {
            return $result;
        }
        $headers = [];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$headers) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return strlen($line);
        });
        curl_exec($ch);
        $result['code'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $result['location'] = isset($headers['location']) ? $headers['location'] : '';
        $result['fallback'] = isset($headers[strtolower(self::FALLBACK_HEADER)]);

        return $result;
    }
}
