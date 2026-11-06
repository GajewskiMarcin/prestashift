<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcingajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @attribution PrestaShift by Marcin Gajewski (marcingajewski.pl). Keep the Attribution Notice in prestashift.php — AFL-3.0 section 6.
 * @version   1.3.0
 */
namespace PrestaShift\Service;

class DownloadPathResolver
{
    public static function isSafeFilename($filename)
    {
        return is_string($filename)
            && $filename !== ''
            && $filename !== '.'
            && $filename !== '..'
            && strpos($filename, '/') === false
            && strpos($filename, '\\') === false
            && strpos($filename, ':') === false
            && !preg_match('/[\x00-\x1F\x7F]/', $filename);
    }

    public static function resolve($downloadDir, $filename)
    {
        if (!self::isSafeFilename($filename)) {
            return false;
        }

        return self::resolveRelativePath($downloadDir, $filename);
    }

    public static function resolveRelativePath($baseDir, $relativePath)
    {
        if (!is_string($relativePath)
            || $relativePath === ''
            || strpos($relativePath, '\\') !== false
            || strpos($relativePath, ':') !== false
            || preg_match('/[\x00-\x1F\x7F]/', $relativePath)
            || $relativePath[0] === '/') {
            return false;
        }

        $segments = explode('/', $relativePath);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        $realDir = realpath($baseDir);
        if ($realDir === false || !is_dir($realDir)) {
            return false;
        }

        $currentPath = $realDir;
        $lastIndex = count($segments) - 1;
        foreach ($segments as $index => $segment) {
            $currentPath .= DIRECTORY_SEPARATOR . $segment;
            if (!file_exists($currentPath) && !is_link($currentPath)) {
                continue;
            }

            if (is_link($currentPath)) {
                return false;
            }

            $realPath = realpath($currentPath);
            if ($realPath === false
                || ($index < $lastIndex && !is_dir($realPath))
                || strpos($realPath, $realDir . DIRECTORY_SEPARATOR) !== 0) {
                return false;
            }
        }

        return $currentPath;
    }
}
