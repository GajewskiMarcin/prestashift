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

use PDO;

/**
 * The addresses the source shop gave its pages, rebuilt from its database.
 * Over Direct DB none of its PHP can be run, and the old shop may be gone by
 * the time the new one goes live. Follows Dispatcher::createUrl() and the
 * Link class of PrestaShop 1.6 to 9 and thirtybees.
 */
class SourceUrlBuilder
{
    /** Route of each kind of page, by entity name of the id map */
    const ROUTES = [
        'product' => 'product_rule',
        'category' => 'category_rule',
        'cms' => 'cms_rule',
        'cms_category' => 'cms_category_rule',
        'manufacturer' => 'manufacturer_rule',
        'supplier' => 'supplier_rule',
    ];

    private $conn;
    private $prefix;

    /** @var int source shop the addresses belong to */
    private $shopId = 1;

    /** @var string physical + virtual URI of the source shop, '/' at least */
    private $base = '/';

    /** @var array id_lang => '' or 'iso/' (active languages only) */
    private $languages = [];

    /** @var array route => [id_lang => pattern] */
    private $rules = [];

    /** @var bool friendly URLs on in the source */
    private $rewriting = false;

    /** @var array|null id_category => id_parent */
    private $categoryParents;

    /** @var array id_category => [id_lang => link_rewrite] */
    private $categoryRewrites = [];

    /** @var array ids left out of category paths: root and home */
    private $hiddenCategories = [];

    /** @var array route => [keyword => true] keywords that cannot be rebuilt from the database */
    private $unsupported = [];

    public function __construct($conn, $prefix)
    {
        $this->conn = $conn;
        $this->prefix = $prefix;
        $this->load();
    }

    /**
     * Default patterns of the source version, used where the shop kept them
     * (no PS_ROUTE_* value). Checked against classes/Dispatcher.php of
     * 1.6.1, 1.7.0, 1.7.4, 1.7.5, 1.7.8, 8.0, 8.2, 9.0, 9.2 and thirtybees 1.7.
     */
    public static function defaultRules($psVersion, $isThirtybees)
    {
        if ($isThirtybees) {
            return [
                'product_rule' => '{categories:/}{rewrite}',
                'category_rule' => '{categories:/}{rewrite}',
                'supplier_rule' => '{rewrite}',
                'manufacturer_rule' => 'manufacturer/{rewrite}',
                'cms_rule' => 'info/{categories:/}{rewrite}',
                'cms_category_rule' => 'info/{categories:/}{rewrite}',
            ];
        }

        $rules = [
            'product_rule' => '{category:/}{id}{-:id_product_attribute}-{rewrite}{-:ean13}.html',
            'category_rule' => '{id}-{rewrite}',
            'supplier_rule' => 'supplier/{id}-{rewrite}',
            'manufacturer_rule' => 'brand/{id}-{rewrite}',
            'cms_rule' => 'content/{id}-{rewrite}',
            'cms_category_rule' => 'content/category/{id}-{rewrite}',
        ];
        if (version_compare($psVersion, '1.7.5.0', '<')) {
            $rules['supplier_rule'] = '{id}__{rewrite}';
            $rules['manufacturer_rule'] = '{id}_{rewrite}';
        }
        if (version_compare($psVersion, '1.7.0.0', '<')) {
            $rules['product_rule'] = '{category:/}{id}-{rewrite}{-:ean13}.html';
        } elseif (version_compare($psVersion, '9.0.0', '>=')) {
            $rules['product_rule'] = '{id}{-:id_product_attribute}-{rewrite}.html';
        }

        return $rules;
    }

    /**
     * Fills a pattern the way Dispatcher::createUrl() does: {prepend:keyword:append}
     * becomes prepend + value + append when the value is not empty, nothing
     * otherwise ("0" counts as empty there too).
     */
    public static function render($rule, array $params)
    {
        return preg_replace_callback('#\{([^{}]*:)?([a-z0-9_]+)(:[^{}]*)?\}#', function ($m) use ($params) {
            $value = isset($params[$m[2]]) ? (string) $params[$m[2]] : '';
            if (!$value) {
                return '';
            }

            return substr($m[1], 0, -1) . $value . (isset($m[3]) ? substr($m[3], 1) : '');
        }, $rule);
    }

    public function rewritingEnabled()
    {
        return $this->rewriting;
    }

    /** @return array source id_lang => '' or 'iso/' */
    public function languages()
    {
        return $this->languages;
    }

    /** Pattern the source used for this route and language */
    public function rule($route, $idLang)
    {
        return isset($this->rules[$route][$idLang]) ? $this->rules[$route][$idLang] : '';
    }

    /** @return array keywords of this entity's pattern that cannot be rebuilt */
    public function unsupportedKeywords($entity)
    {
        $route = self::ROUTES[$entity];

        return isset($this->unsupported[$route]) ? array_keys($this->unsupported[$route]) : [];
    }

    /**
     * Old addresses of the given records, from the root of the domain.
     *
     * @return array [[source id, source id_lang, path], ...]
     */
    public function paths($entity, array $ids)
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids) || !isset(self::ROUTES[$entity]) || $this->unsupportedKeywords($entity)) {
            return [];
        }

        $method = 'params' . str_replace(' ', '', ucwords(str_replace('_', ' ', $entity)));
        $paramsByRecord = $this->$method($ids);

        $paths = [];
        foreach ($paramsByRecord as $id => $byLang) {
            foreach ($byLang as $idLang => $params) {
                $rule = $this->rule(self::ROUTES[$entity], $idLang);
                if ($rule === '' || !isset($this->languages[$idLang])) {
                    continue;
                }
                $paths[] = [(int) $id, (int) $idLang, $this->base . $this->languages[$idLang] . self::render($rule, $params)];
            }
        }

        return $paths;
    }

    private function load()
    {
        $config = [];
        foreach ($this->fetch("SELECT `name`, `value` FROM `{$this->prefix}configuration`
            WHERE `name` IN ('PS_SHOP_DEFAULT', 'PS_REWRITING_SETTINGS', 'PS_ROOT_CATEGORY', 'PS_HOME_CATEGORY')
            ORDER BY (`id_shop` IS NULL) DESC, (`id_shop_group` IS NULL) DESC") as $row) {
            if (!isset($config[$row['name']])) {
                $config[$row['name']] = $row['value'];
            }
        }
        $this->shopId = !empty($config['PS_SHOP_DEFAULT']) ? (int) $config['PS_SHOP_DEFAULT'] : 1;
        $this->rewriting = !empty($config['PS_REWRITING_SETTINGS']);
        $this->hiddenCategories = [
            !empty($config['PS_ROOT_CATEGORY']) ? (int) $config['PS_ROOT_CATEGORY'] : 1,
            !empty($config['PS_HOME_CATEGORY']) ? (int) $config['PS_HOME_CATEGORY'] : 2,
        ];

        foreach ($this->fetch("SELECT `physical_uri`, `virtual_uri` FROM `{$this->prefix}shop_url`
            WHERE `main` = 1 ORDER BY (`id_shop` = {$this->shopId}) DESC") as $row) {
            $this->base = '/' . trim(trim((string) $row['physical_uri'], '/') . '/' . trim((string) $row['virtual_uri'], '/'), '/') . '/';
            $this->base = str_replace('//', '/', $this->base);
            break;
        }

        $active = $this->fetch("SELECT `id_lang`, `iso_code` FROM `{$this->prefix}lang` WHERE `active` = 1 ORDER BY `id_lang`");
        foreach ($active as $row) {
            $this->languages[(int) $row['id_lang']] = count($active) > 1 ? $row['iso_code'] . '/' : '';
        }

        $version = $this->sourceVersion();
        $defaults = self::defaultRules($version['ps'], $version['tb'] !== '');

        // Custom patterns: per language from 1.7 on and in thirtybees, one value for all languages in 1.6
        $custom = [];
        foreach ($this->fetch("SELECT c.`name`, c.`value`, cl.`id_lang`, cl.`value` AS `lang_value`
            FROM `{$this->prefix}configuration` c
            LEFT JOIN `{$this->prefix}configuration_lang` cl ON cl.`id_configuration` = c.`id_configuration`
            WHERE c.`name` LIKE 'PS\\_ROUTE\\_%'
            ORDER BY (c.`id_shop` IS NULL) DESC, (c.`id_shop_group` IS NULL) DESC") as $row) {
            $route = substr($row['name'], strlen('PS_ROUTE_'));
            if ((string) $row['lang_value'] !== '' && !isset($custom[$route]['lang'][(int) $row['id_lang']])) {
                $custom[$route]['lang'][(int) $row['id_lang']] = $row['lang_value'];
            }
            if ((string) $row['value'] !== '' && !isset($custom[$route]['all'])) {
                $custom[$route]['all'] = $row['value'];
            }
        }

        foreach (self::ROUTES as $route) {
            foreach (array_keys($this->languages) as $idLang) {
                if (isset($custom[$route]['lang'][$idLang])) {
                    $rule = $custom[$route]['lang'][$idLang];
                } elseif (isset($custom[$route]['all'])) {
                    $rule = $custom[$route]['all'];
                } else {
                    $rule = $defaults[$route];
                }
                $this->rules[$route][$idLang] = $rule;

                preg_match_all('#\{(?:[^{}]*:)?([a-z0-9_]+)(?::[^{}]*)?\}#', $rule, $m);
                foreach (array_diff($m[1], $this->supportedKeywords($route)) as $keyword) {
                    $this->unsupported[$route][$keyword] = true;
                }
            }
        }
    }

    private function supportedKeywords($route)
    {
        $common = ['id', 'rewrite', 'meta_title', 'meta_keywords'];
        switch ($route) {
            case 'product_rule':
                return array_merge($common, ['ean13', 'reference', 'category', 'categories', 'manufacturer', 'supplier', 'id_product_attribute']);
            case 'category_rule':
            case 'cms_rule':
            case 'cms_category_rule':
                return array_merge($common, ['categories']);
            default:
                return $common;
        }
    }

    private function sourceVersion()
    {
        $ps = '';
        $tb = '';
        try {
            $test = $this->conn->test();
            $ps = isset($test['ps_version']) ? (string) $test['ps_version'] : '';
            $tb = isset($test['tb_version']) ? (string) $test['tb_version'] : '';
        } catch (\Exception $e) {
            // read from the database below
        }
        if ($ps === '') {
            $rows = $this->fetch("SELECT `value` FROM `{$this->prefix}configuration` WHERE `name` = 'PS_VERSION_DB'");
            $ps = $rows ? (string) $rows[0]['value'] : '1.7.0.0';
        }

        return ['ps' => $ps, 'tb' => $tb];
    }

    private function paramsProduct(array $ids)
    {
        $in = implode(',', $ids);
        $route = self::ROUTES['product'];
        $needs = $this->keywordsUsed($route);

        $products = [];
        foreach ($this->fetch("SELECT p.`id_product`, p.`ean13`, p.`reference`, p.`id_manufacturer`, p.`id_supplier`,
            COALESCE(ps.`id_category_default`, p.`id_category_default`) AS `id_category_default`
            FROM `{$this->prefix}product` p
            LEFT JOIN `{$this->prefix}product_shop` ps ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = {$this->shopId}
            WHERE p.`id_product` IN ($in)") as $row) {
            $products[(int) $row['id_product']] = $row;
        }

        $manufacturers = isset($needs['manufacturer']) ? $this->names('manufacturer', array_column($products, 'id_manufacturer')) : [];
        $suppliers = isset($needs['supplier']) ? $this->names('supplier', array_column($products, 'id_supplier')) : [];

        $out = [];
        foreach ($this->langRows('product', $ids) as $id => $byLang) {
            if (!isset($products[$id])) {
                continue;
            }
            $product = $products[$id];
            $idCategory = (int) $product['id_category_default'];
            foreach ($byLang as $idLang => $lang) {
                $params = $this->commonParams($id, $lang);
                $params['ean13'] = $product['ean13'];
                $params['reference'] = \Tools::str2url((string) $product['reference']);
                if (isset($needs['category']) || isset($needs['categories'])) {
                    $params['category'] = \Tools::str2url($this->categoryRewrite($idCategory, $idLang));
                    // Ancestry of the default category, from the top, without root and home
                    $params['categories'] = implode('/', $this->categoryPath($idCategory, $idLang, true));
                }
                if (isset($needs['manufacturer'])) {
                    $params['manufacturer'] = \Tools::str2url(isset($manufacturers[(int) $product['id_manufacturer']]) ? $manufacturers[(int) $product['id_manufacturer']] : '');
                }
                if (isset($needs['supplier'])) {
                    $params['supplier'] = \Tools::str2url(isset($suppliers[(int) $product['id_supplier']]) ? $suppliers[(int) $product['id_supplier']] : '');
                }
                $out[$id][$idLang] = $params;
            }
        }

        return $out;
    }

    private function paramsCategory(array $ids)
    {
        $needsPath = isset($this->keywordsUsed(self::ROUTES['category'])['categories']);
        $out = [];
        foreach ($this->langRows('category', $ids) as $id => $byLang) {
            foreach ($byLang as $idLang => $lang) {
                $params = $this->commonParams($id, $lang);
                if ($needsPath) {
                    // Its parents, from the top, without root and home
                    $params['categories'] = implode('/', $this->categoryPath($this->categoryParent($id), $idLang, true));
                }
                $out[$id][$idLang] = $params;
            }
        }

        return $out;
    }

    private function paramsCms(array $ids)
    {
        $needsPath = isset($this->keywordsUsed(self::ROUTES['cms'])['categories']);
        $parents = [];
        if ($needsPath) {
            foreach ($this->fetch("SELECT `id_cms`, `id_cms_category` FROM `{$this->prefix}cms` WHERE `id_cms` IN (" . implode(',', $ids) . ")") as $row) {
                $parents[(int) $row['id_cms']] = (int) $row['id_cms_category'];
            }
        }

        $out = [];
        foreach ($this->langRows('cms', $ids) as $id => $byLang) {
            foreach ($byLang as $idLang => $lang) {
                $params = $this->commonParams($id, $lang);
                if ($needsPath) {
                    $params['categories'] = implode('/', $this->cmsCategoryPath(isset($parents[$id]) ? $parents[$id] : 1, $idLang));
                }
                $out[$id][$idLang] = $params;
            }
        }

        return $out;
    }

    private function paramsCmsCategory(array $ids)
    {
        $needsPath = isset($this->keywordsUsed(self::ROUTES['cms_category'])['categories']);
        $out = [];
        foreach ($this->langRows('cms_category', $ids) as $id => $byLang) {
            foreach ($byLang as $idLang => $lang) {
                $params = $this->commonParams($id, $lang);
                if ($needsPath) {
                    $params['categories'] = implode('/', $this->cmsCategoryPath($this->cmsCategoryParent($id), $idLang));
                }
                $out[$id][$idLang] = $params;
            }
        }

        return $out;
    }

    private function paramsManufacturer(array $ids)
    {
        return $this->paramsNamed('manufacturer', $ids);
    }

    private function paramsSupplier(array $ids)
    {
        return $this->paramsNamed('supplier', $ids);
    }

    /** Brands and suppliers have no link_rewrite: PrestaShop makes it from the name */
    private function paramsNamed($entity, array $ids)
    {
        $names = $this->names($entity, $ids);
        $langs = $this->langRows($entity, $ids);

        $out = [];
        foreach ($names as $id => $name) {
            foreach (array_keys($this->languages) as $idLang) {
                $lang = isset($langs[$id][$idLang]) ? $langs[$id][$idLang] : [];
                $params = $this->commonParams($id, $lang);
                $params['rewrite'] = \Tools::str2url($name);
                $out[$id][$idLang] = $params;
            }
        }

        return $out;
    }

    private function commonParams($id, array $lang)
    {
        return [
            'id' => (int) $id,
            'rewrite' => isset($lang['link_rewrite']) ? $lang['link_rewrite'] : '',
            'meta_title' => \Tools::str2url(isset($lang['meta_title']) ? (string) $lang['meta_title'] : ''),
            'meta_keywords' => \Tools::str2url(isset($lang['meta_keywords']) ? (string) $lang['meta_keywords'] : ''),
        ];
    }

    /**
     * Language rows of the records, one per language: the row of the source
     * shop when the table has one per shop.
     *
     * @return array id => [id_lang => row]
     */
    private function langRows($entity, array $ids)
    {
        $key = 'id_' . $entity;
        $out = [];
        foreach ($this->fetch("SELECT * FROM `{$this->prefix}{$entity}_lang` WHERE `$key` IN (" . implode(',', $ids) . ")") as $row) {
            $id = (int) $row[$key];
            $idLang = (int) $row['id_lang'];
            $ownShop = !isset($row['id_shop']) || (int) $row['id_shop'] === $this->shopId;
            if (!isset($this->languages[$idLang]) || (isset($out[$id][$idLang]) && !$ownShop)) {
                continue;
            }
            $out[$id][$idLang] = $row;
        }

        return $out;
    }

    private function names($entity, array $ids)
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }
        $names = [];
        foreach ($this->fetch("SELECT `id_$entity`, `name` FROM `{$this->prefix}$entity` WHERE `id_$entity` IN (" . implode(',', array_unique($ids)) . ")") as $row) {
            $names[(int) $row['id_' . $entity]] = (string) $row['name'];
        }

        return $names;
    }

    private function keywordsUsed($route)
    {
        $used = [];
        foreach ($this->rules[$route] as $rule) {
            preg_match_all('#\{(?:[^{}]*:)?([a-z0-9_]+)(?::[^{}]*)?\}#', $rule, $m);
            foreach ($m[1] as $keyword) {
                $used[$keyword] = true;
            }
        }

        return $used;
    }

    private function categoryParent($idCategory)
    {
        $this->loadCategoryTree();

        return isset($this->categoryParents[$idCategory]) ? $this->categoryParents[$idCategory] : 0;
    }

    /**
     * link_rewrite of the category and its ancestors, from the top, without
     * root and home (Product::getParentCategories / Category::getParentsCategories).
     */
    private function categoryPath($idCategory, $idLang, $includeSelf)
    {
        $this->loadCategoryTree();
        $chain = [];
        $seen = [];
        while ($idCategory > 0 && !isset($seen[$idCategory])) {
            $seen[$idCategory] = true;
            if (!in_array($idCategory, $this->hiddenCategories, true)) {
                $chain[] = $idCategory;
            }
            $idCategory = isset($this->categoryParents[$idCategory]) ? $this->categoryParents[$idCategory] : 0;
        }
        if (!$includeSelf) {
            array_shift($chain);
        }

        $this->loadCategoryRewrites($chain);
        $path = [];
        foreach (array_reverse($chain) as $id) {
            $path[] = $this->categoryRewrite($id, $idLang);
        }

        return $path;
    }

    private function categoryRewrite($idCategory, $idLang)
    {
        $this->loadCategoryRewrites([$idCategory]);

        return isset($this->categoryRewrites[$idCategory][$idLang]) ? $this->categoryRewrites[$idCategory][$idLang] : '';
    }

    private function loadCategoryTree()
    {
        if ($this->categoryParents !== null) {
            return;
        }
        $this->categoryParents = [];
        foreach ($this->fetch("SELECT `id_category`, `id_parent` FROM `{$this->prefix}category`") as $row) {
            $this->categoryParents[(int) $row['id_category']] = (int) $row['id_parent'];
        }
    }

    private function loadCategoryRewrites(array $ids)
    {
        $missing = array_diff(array_map('intval', $ids), array_keys($this->categoryRewrites));
        if (empty($missing)) {
            return;
        }
        foreach ($missing as $id) {
            $this->categoryRewrites[$id] = [];
        }
        foreach ($this->langRows('category', $missing) as $id => $byLang) {
            foreach ($byLang as $idLang => $row) {
                $this->categoryRewrites[$id][$idLang] = (string) $row['link_rewrite'];
            }
        }
    }

    /** thirtybees: the CMS categories above a page, from the top, without the home one (1) */
    private function cmsCategoryPath($idCmsCategory, $idLang)
    {
        $chain = [];
        $seen = [];
        while ($idCmsCategory > 1 && !isset($seen[$idCmsCategory])) {
            $seen[$idCmsCategory] = true;
            $chain[] = $idCmsCategory;
            $idCmsCategory = $this->cmsCategoryParent($idCmsCategory);
        }
        if (empty($chain)) {
            return [];
        }

        $rewrites = $this->langRows('cms_category', $chain);
        $path = [];
        foreach (array_reverse($chain) as $id) {
            $path[] = isset($rewrites[$id][$idLang]['link_rewrite']) ? $rewrites[$id][$idLang]['link_rewrite'] : '';
        }

        return $path;
    }

    private function cmsCategoryParent($idCmsCategory)
    {
        static $parents = [];
        $key = $this->prefix . (int) $idCmsCategory;
        if (!isset($parents[$key])) {
            $rows = $this->fetch("SELECT `id_parent` FROM `{$this->prefix}cms_category` WHERE `id_cms_category` = " . (int) $idCmsCategory);
            $parents[$key] = $rows ? (int) $rows[0]['id_parent'] : 0;
        }

        return $parents[$key];
    }

    private function fetch($sql)
    {
        try {
            return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }
}
