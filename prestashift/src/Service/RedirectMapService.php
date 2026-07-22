<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @version   1.0.0
 */
namespace PrestaShift\Service;

use Db;

class RedirectMapService
{
    /**
     * Generate redirect map comparing source URLs with target URLs
     * Returns path to generated file
     */
    public static function generate($sourceConn, $sourcePrefix, $sourceDomain)
    {
        $lines = [];
        $lines[] = "# PrestaShift 301 Redirect Map";
        $lines[] = "# Generated: " . date('Y-m-d H:i:s');
        $lines[] = "# Source: " . $sourceDomain;
        $lines[] = "# Format: old_url new_url (for use in .htaccess or nginx config)";
        $lines[] = "";

        $targetLang = (int)\Configuration::get('PS_LANG_DEFAULT');
        $targetShopId = SchemaHelper::getTargetShopId();

        // 1. Products — compare source link_rewrite with target
        try {
            $sourceProducts = $sourceConn->query(
                "SELECT p.id_product, pl.link_rewrite
                 FROM `{$sourcePrefix}product` p
                 JOIN `{$sourcePrefix}product_lang` pl ON p.id_product = pl.id_product
                 WHERE pl.id_lang = (SELECT id_lang FROM `{$sourcePrefix}lang` WHERE active = 1 LIMIT 1)
                 ORDER BY p.id_product ASC"
            )->fetchAll(\PDO::FETCH_ASSOC);

            $targetProducts = Db::getInstance()->executeS(
                "SELECT p.id_product, pl.link_rewrite
                 FROM `" . _DB_PREFIX_ . "product` p
                 JOIN `" . _DB_PREFIX_ . "product_lang` pl ON p.id_product = pl.id_product
                 WHERE pl.id_lang = {$targetLang} AND pl.id_shop = {$targetShopId}
                 ORDER BY p.id_product ASC"
            );

            $targetMap = [];
            foreach ($targetProducts as $tp) {
                $targetMap[(int)$tp['id_product']] = $tp['link_rewrite'];
            }

            $lines[] = "# --- Products ---";
            foreach ($sourceProducts as $sp) {
                $id = (int)$sp['id_product'];
                $oldSlug = $sp['link_rewrite'];
                $newSlug = isset($targetMap[$id]) ? $targetMap[$id] : null;
                if ($newSlug && $oldSlug !== $newSlug) {
                    $lines[] = "/{$oldSlug}.html /{$newSlug}.html";
                }
            }
        } catch (\Exception $e) {
            $lines[] = "# Products: error — " . $e->getMessage();
        }

        // 2. Categories
        try {
            $sourceCategories = $sourceConn->query(
                "SELECT c.id_category, cl.link_rewrite
                 FROM `{$sourcePrefix}category` c
                 JOIN `{$sourcePrefix}category_lang` cl ON c.id_category = cl.id_category
                 WHERE cl.id_lang = (SELECT id_lang FROM `{$sourcePrefix}lang` WHERE active = 1 LIMIT 1)
                 AND c.id_category > 2
                 ORDER BY c.id_category ASC"
            )->fetchAll(\PDO::FETCH_ASSOC);

            $targetCategories = Db::getInstance()->executeS(
                "SELECT c.id_category, cl.link_rewrite
                 FROM `" . _DB_PREFIX_ . "category` c
                 JOIN `" . _DB_PREFIX_ . "category_lang` cl ON c.id_category = cl.id_category
                 WHERE cl.id_lang = {$targetLang} AND cl.id_shop = {$targetShopId}
                 AND c.id_category > 2
                 ORDER BY c.id_category ASC"
            );

            $targetMap = [];
            foreach ($targetCategories as $tc) {
                $targetMap[(int)$tc['id_category']] = $tc['link_rewrite'];
            }

            $lines[] = "";
            $lines[] = "# --- Categories ---";
            foreach ($sourceCategories as $sc) {
                $id = (int)$sc['id_category'];
                $oldSlug = $sc['link_rewrite'];
                $newSlug = isset($targetMap[$id]) ? $targetMap[$id] : null;
                if ($newSlug && $oldSlug !== $newSlug) {
                    $lines[] = "/{$oldSlug} /{$newSlug}";
                }
            }
        } catch (\Exception $e) {
            $lines[] = "# Categories: error — " . $e->getMessage();
        }

        // 3. CMS Pages
        try {
            $sourceCms = $sourceConn->query(
                "SELECT c.id_cms, cl.link_rewrite
                 FROM `{$sourcePrefix}cms` c
                 JOIN `{$sourcePrefix}cms_lang` cl ON c.id_cms = cl.id_cms
                 WHERE cl.id_lang = (SELECT id_lang FROM `{$sourcePrefix}lang` WHERE active = 1 LIMIT 1)
                 ORDER BY c.id_cms ASC"
            )->fetchAll(\PDO::FETCH_ASSOC);

            $targetCms = Db::getInstance()->executeS(
                "SELECT c.id_cms, cl.link_rewrite
                 FROM `" . _DB_PREFIX_ . "cms` c
                 JOIN `" . _DB_PREFIX_ . "cms_lang` cl ON c.id_cms = cl.id_cms
                 WHERE cl.id_lang = {$targetLang} AND cl.id_shop = {$targetShopId}
                 ORDER BY c.id_cms ASC"
            );

            $targetMap = [];
            foreach ($targetCms as $tc) {
                $targetMap[(int)$tc['id_cms']] = $tc['link_rewrite'];
            }

            $lines[] = "";
            $lines[] = "# --- CMS Pages ---";
            foreach ($sourceCms as $sc) {
                $id = (int)$sc['id_cms'];
                $oldSlug = $sc['link_rewrite'];
                $newSlug = isset($targetMap[$id]) ? $targetMap[$id] : null;
                if ($newSlug && $oldSlug !== $newSlug) {
                    $lines[] = "/content/{$oldSlug} /content/{$newSlug}";
                }
            }
        } catch (\Exception $e) {
            $lines[] = "# CMS: error — " . $e->getMessage();
        }

        // Write file
        $content = implode("\n", $lines);
        $filePath = _PS_ROOT_DIR_ . '/var/logs/prestashift_redirects.txt';
        $logDir = dirname($filePath);
        if (!is_dir($logDir)) {
            $filePath = _PS_ROOT_DIR_ . '/log/prestashift_redirects.txt';
        }
        @file_put_contents($filePath, $content);

        return $filePath;
    }
}
