<?php
/**
 * PrestaShift Connector: read-only SQL guard
 *
 * @author    marcingajewski.pl <kontakt@marcingajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @version   1.3.1
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The query as MySQL will read it, with everything that cannot execute taken
 * out: string literals become a placeholder, comments become a space and
 * backtick quoting is dropped (`SLEEP`(1) is still a call to SLEEP).
 *
 * Until 1.3.0 the checks ran on the raw text, so a comment between two
 * keywords (INTO/x/OUTFILE, with the slashes as a C comment) or a function
 * name in backticks slipped past them while MySQL still ran the statement.
 *
 * Returns null for what cannot be checked safely: an unterminated string or
 * comment, and MySQL's executable comments (slash-star-bang), whose content
 * the server runs as SQL.
 *
 * @param string $sql
 * @param bool $backslashEscapes false when the server runs with NO_BACKSLASH_ESCAPES
 *
 * @return string|null
 */
function psconnector_sql_skeleton($sql, $backslashEscapes)
{
    $out = '';
    $len = strlen($sql);
    $i = 0;

    while ($i < $len) {
        $c = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($c === "'" || $c === '"') {
            $i++;
            $closed = false;
            while ($i < $len) {
                if ($backslashEscapes && $sql[$i] === '\\') {
                    $i += 2;
                    continue;
                }
                if ($sql[$i] === $c) {
                    if ($i + 1 < $len && $sql[$i + 1] === $c) {
                        $i += 2;
                        continue;
                    }
                    $closed = true;
                    $i++;
                    break;
                }
                $i++;
            }
            if (!$closed) {
                return null;
            }
            $out .= ' S ';
            continue;
        }

        if ($c === '`') {
            $end = $i + 1;
            while (true) {
                $end = strpos($sql, '`', $end);
                if ($end === false) {
                    return null;
                }
                if ($end + 1 < $len && $sql[$end + 1] === '`') {
                    $end += 2;
                    continue;
                }
                break;
            }
            $out .= str_replace('``', '`', substr($sql, $i + 1, $end - $i - 1));
            $i = $end + 1;
            continue;
        }

        if ($c === '/' && $next === '*') {
            if ($i + 2 < $len && $sql[$i + 2] === '!') {
                return null;
            }
            $end = strpos($sql, '*/', $i + 2);
            if ($end === false) {
                return null;
            }
            $out .= ' ';
            $i = $end + 2;
            continue;
        }

        // "--" opens a comment only when followed by whitespace or the end.
        if ($c === '#' || ($c === '-' && $next === '-'
            && ($i + 2 >= $len || ctype_space($sql[$i + 2]) || ctype_cntrl($sql[$i + 2])))) {
            $end = strpos($sql, "\n", $i);
            $out .= ' ';
            $i = $end === false ? $len : $end + 1;
            continue;
        }

        $out .= $c;
        $i++;
    }

    return $out;
}

/**
 * Throws unless $sql is a single SELECT that does not touch files, locks or
 * the clock.
 *
 * @param string $sql
 * @param bool $backslashEscapes
 *
 * @throws Exception
 */
function psconnector_assert_read_only($sql, $backslashEscapes)
{
    $skeleton = psconnector_sql_skeleton($sql, $backslashEscapes);
    if ($skeleton === null) {
        throw new Exception('This query uses a construct the connector does not allow.');
    }

    if (!preg_match('/^\s*SELECT\b/i', $skeleton)) {
        throw new Exception('Only SELECT queries are allowed. The connector is read-only.');
    }
    if (strpos(rtrim($skeleton, "; \t\r\n"), ';') !== false) {
        throw new Exception('Only one statement per request is allowed.');
    }
    if (preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $skeleton)
        || preg_match('/\b(LOAD_FILE|BENCHMARK|SLEEP|GET_LOCK|RELEASE_LOCK|RELEASE_ALL_LOCKS|IS_FREE_LOCK|IS_USED_LOCK)\s*\(/i', $skeleton)) {
        throw new Exception('This query uses a construct the connector does not allow.');
    }
}
