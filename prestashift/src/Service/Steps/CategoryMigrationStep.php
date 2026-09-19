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
namespace PrestaShift\Service\Steps;

use Db;
use PDO;
use PrestaShift\Service\IdMapper;
use PrestaShift\Service\LanguageMapper;
use PrestaShift\Service\SchemaHelper;

class CategoryMigrationStep
{
    private $db_connection;
    private $prefix;

    public function __construct($db_connection, $prefix)
    {
        $this->db_connection = $db_connection;
        $this->prefix = $prefix;
    }

    public function process($offset, $limit, $dateFilter = null)
    {
        $categories = $this->getCategoriesFromSource($offset, $limit, $dateFilter);

        if (empty($categories)) {
            return ['count' => 0, 'finished' => true];
        }

        IdMapper::prepare('category', array_column($categories, 'id_category'));

        foreach ($categories as $category) {
            $this->importCategory($category);
        }

        return ['count' => count($categories), 'finished' => false];
    }

    private function getCategoriesFromSource($offset, $limit, $dateFilter = null)
    {
        $where = "";
        if ($dateFilter) {
            $where = " WHERE `date_add` > '{$dateFilter}' OR `date_upd` > '{$dateFilter}' ";
        }
        $sql = "SELECT * FROM `{$this->prefix}category` {$where} ORDER BY `id_category` ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->db_connection->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importCategory($data)
    {
        $sid = (int)$data['id_category'];
        $tid = IdMapper::own('category', $sid);

        // Root / Home of the source are the target's own Root / Home — never
        // overwritten.
        if (IdMapper::isLinked('category', $sid)) {
            return;
        }

        $row = IdMapper::row('category', [
            'id_category' => $sid,
            'id_parent' => $data['id_parent'],
            'id_shop_default' => $data['id_shop_default'],
            'level_depth' => $data['level_depth'],
            'nleft' => $data['nleft'],
            'nright' => $data['nright'],
            'active' => $data['active'],
            'date_add' => $data['date_add'],
            'date_upd' => $data['date_upd'],
            'position' => isset($data['position']) ? $data['position'] : 0,
            'is_root_category' => $data['is_root_category'],
        ]);

        // A parent that resolves to nothing would orphan the branch
        if ((int)$row['id_parent'] <= 0 && (int)$data['id_parent'] > 0) {
            $row['id_parent'] = (int)\Configuration::get('PS_HOME_CATEGORY');
        }

        SchemaHelper::upsert('category', $row, ['id_category']);

        $this->importCategoryLang($sid, $tid);
        $this->importCategoryShop($tid, $row['position']);
        $this->importCategoryGroup($sid, $tid);
    }

    private function importCategoryLang($sid, $tid)
    {
        $shopId = SchemaHelper::getTargetShopId();
        $stmt = $this->db_connection->query("SELECT * FROM `{$this->prefix}category_lang` WHERE id_category = $sid");
        $langs = LanguageMapper::expand($stmt->fetchAll(PDO::FETCH_ASSOC));

        $done = [];
        foreach ($langs as $lang) {
            $idLang = (int)$lang['id_lang'];
            if (isset($done[$idLang])) {
                continue; // multistore source: one row per language is enough
            }
            $done[$idLang] = true;

            Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "category_lang` WHERE id_category = $tid AND id_lang = $idLang AND id_shop = $shopId");

            SchemaHelper::upsert('category_lang', [
                'id_category' => $tid,
                'id_shop' => $shopId,
                'id_lang' => $idLang,
                'name' => $lang['name'],
                'description' => $lang['description'],
                'additional_description' => isset($lang['additional_description']) ? $lang['additional_description'] : null,
                'link_rewrite' => $lang['link_rewrite'],
                'meta_title' => $lang['meta_title'],
                'meta_keywords' => isset($lang['meta_keywords']) ? $lang['meta_keywords'] : null,
                'meta_description' => $lang['meta_description'],
            ], ['id_category', 'id_shop', 'id_lang']);
        }
    }

    private function importCategoryShop($tid, $position)
    {
        Db::getInstance()->execute("REPLACE INTO `" . _DB_PREFIX_ . "category_shop` (id_category, id_shop, position) VALUES ($tid, " . SchemaHelper::getTargetShopId() . ", " . (int)$position . ")");
    }

    /**
     * Group access of the category. Groups that do not exist in the target
     * (customers not migrated) are dropped; with nothing left the category is
     * opened to the shop's three built-in groups so it does not disappear.
     */
    private function importCategoryGroup($sid, $tid)
    {
        $groups = [];
        try {
            $rows = $this->db_connection->query("SELECT id_group FROM `{$this->prefix}category_group` WHERE id_category = $sid")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $gid = IdMapper::ref('group', (int)$r['id_group']);
                if ($gid > 0) {
                    $groups[$gid] = $gid;
                }
            }
        } catch (\Exception $e) {
        }

        $existing = [];
        foreach ((array)Db::getInstance()->executeS("SELECT id_group FROM `" . _DB_PREFIX_ . "group`") as $g) {
            $existing[(int)$g['id_group']] = true;
        }
        $groups = array_filter($groups, function ($gid) use ($existing) {
            return isset($existing[$gid]);
        });

        if (empty($groups)) {
            foreach (['PS_UNIDENTIFIED_GROUP', 'PS_GUEST_GROUP', 'PS_CUSTOMER_GROUP'] as $key) {
                $gid = (int)\Configuration::get($key);
                if ($gid > 0) {
                    $groups[$gid] = $gid;
                }
            }
        }

        Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "category_group` WHERE id_category = $tid");
        $values = [];
        foreach ($groups as $gid) {
            $values[] = "($tid, $gid)";
        }
        if ($values) {
            Db::getInstance()->execute("INSERT IGNORE INTO `" . _DB_PREFIX_ . "category_group` (id_category, id_group) VALUES " . implode(',', $values));
        }
    }

    public function restoreRootCategories()
    {
        $shopId = SchemaHelper::getTargetShopId();

        // 1. Root Category
        Db::getInstance()->execute("
            INSERT IGNORE INTO `" . \_DB_PREFIX_ . "category`
            (id_category, id_parent, id_shop_default, level_depth, nleft, nright, active, date_add, date_upd, position, is_root_category)
            VALUES
            (1, 0, " . $shopId . ", 0, 1, 0, 1, NOW(), NOW(), 0, 1)
        ");

        Db::getInstance()->execute("
            INSERT IGNORE INTO `" . \_DB_PREFIX_ . "category_lang` (id_category, id_shop, id_lang, name, link_rewrite, description)
            VALUES (1, " . $shopId . ", 1, 'Root', 'root', '')
        ");

        Db::getInstance()->execute("INSERT IGNORE INTO `" . \_DB_PREFIX_ . "category_shop` (id_category, id_shop, position) VALUES (1, " . $shopId . ", 0)");
        Db::getInstance()->execute("INSERT IGNORE INTO `" . \_DB_PREFIX_ . "category_group` (id_category, id_group) VALUES (1, 1), (1, 2), (1, 3)");

        // 2. Home Category
        Db::getInstance()->execute("
            INSERT IGNORE INTO `" . \_DB_PREFIX_ . "category`
            (id_category, id_parent, id_shop_default, level_depth, nleft, nright, active, date_add, date_upd, position, is_root_category)
            VALUES
            (2, 1, " . $shopId . ", 1, 2, 0, 1, NOW(), NOW(), 0, 0)
        ");

        Db::getInstance()->execute("
            INSERT IGNORE INTO `" . \_DB_PREFIX_ . "category_lang` (id_category, id_shop, id_lang, name, link_rewrite, description)
            VALUES (2, " . $shopId . ", 1, 'Home', 'home', 'The main category of your shop.')
        ");

        Db::getInstance()->execute("INSERT IGNORE INTO `" . \_DB_PREFIX_ . "category_shop` (id_category, id_shop, position) VALUES (2, " . $shopId . ", 0)");
        Db::getInstance()->execute("INSERT IGNORE INTO `" . \_DB_PREFIX_ . "category_group` (id_category, id_group) VALUES (2, 1), (2, 2), (2, 3)");
    }
}
