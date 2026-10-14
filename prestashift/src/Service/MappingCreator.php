<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcingajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @attribution PrestaShift by Marcin Gajewski (marcingajewski.pl). Keep the Attribution Notice in prestashift.php — AFL-3.0 section 6.
 * @version   1.4.2
 */
namespace PrestaShift\Service;

use Configuration;
use Db;
use PDO;
use Validate;

/**
 * Creates the order statuses and zones that the mapping step marked "Create it
 * as in the old shop" (value "new" in options status_map / zone_map) and puts
 * their target ids into the maps. Runs once, when the migration starts and
 * before anything is deleted: a record that cannot be created stops the
 * migration while the target is still untouched, and every batch afterwards
 * reads plain ids.
 *
 * A record with the same name already in the target is used instead of a
 * second copy, so running the migration again creates nothing twice.
 */
class MappingCreator
{
    const CREATE = 'new';

    /** Longest name PrestaShop accepts for an order status or a zone */
    const NAME_LENGTH = 64;

    /** An order status icon is a small GIF; anything bigger is not one */
    const ICON_MAX_BYTES = 102400;

    private $conn;
    private $prefix;

    public function __construct($conn, $prefix)
    {
        $this->conn = $conn;
        $this->prefix = $prefix;
    }

    /**
     * @param array $config Migration config; its status_map and zone_map get the target ids
     *
     * @return array ['order_states' => [...], 'zones' => [...]], one entry per record created or found
     *
     * @throws \Exception When a record cannot be created
     */
    public function apply(array &$config)
    {
        $done = ['order_states' => [], 'zones' => []];
        $states = $this->pending($config, 'status_map', 'orders');
        $zones = $this->pending($config, 'zone_map', 'carriers');
        if (empty($states) && empty($zones)) {
            return $done;
        }

        LanguageMapper::init($this->conn, $this->prefix);
        SchemaHelper::setTargetShopId(isset($config['options']['target_shop_id']) ? (int) $config['options']['target_shop_id'] : 1);

        foreach ($states as $sourceId) {
            $state = $this->orderState($sourceId);
            $config['options']['status_map'][$sourceId] = $state['id'];
            $done['order_states'][] = $state;
        }

        foreach ($zones as $sourceId) {
            $zone = $this->zone($sourceId);
            $config['options']['zone_map'][$sourceId] = $zone['id'];
            $done['zones'][] = $zone;
        }

        return $done;
    }

    /**
     * Source ids marked "new" in one of the maps, when its area is migrated.
     */
    private function pending(array $config, $mapKey, $scopeKey)
    {
        $scope = isset($config['scope']) && is_array($config['scope']) ? $config['scope'] : [];
        if (!empty($scope) && empty($scope[$scopeKey])) {
            return [];
        }

        $map = isset($config['options'][$mapKey]) && is_array($config['options'][$mapKey]) ? $config['options'][$mapKey] : [];
        $ids = [];
        foreach ($map as $sourceId => $target) {
            if ($target === self::CREATE && (int) $sourceId > 0) {
                $ids[] = (int) $sourceId;
            }
        }

        return $ids;
    }

    private function orderState($sourceId)
    {
        $rows = $this->conn->query("SELECT * FROM `{$this->prefix}order_state` WHERE `id_order_state` = " . (int) $sourceId)->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            throw new \Exception(sprintf('Order status #%d is no longer in the source shop. Go back to the mapping step and load it again.', $sourceId));
        }
        $row = $rows[0];

        $names = [];
        $templates = [];
        $langRows = LanguageMapper::expand($this->conn->query("SELECT * FROM `{$this->prefix}order_state_lang` WHERE `id_order_state` = " . (int) $sourceId)->fetchAll(PDO::FETCH_ASSOC));
        foreach ($langRows as $lang) {
            $idLang = (int) $lang['id_lang'];
            $names[$idLang] = $this->cleanName(isset($lang['name']) ? $lang['name'] : '');
            $templates[$idLang] = isset($lang['template']) && Validate::isTplName($lang['template']) ? (string) $lang['template'] : '';
        }

        $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $label = isset($names[$defaultLang]) && $names[$defaultLang] !== '' ? $names[$defaultLang] : (string) reset($names);
        if ($label === '') {
            $label = 'Order status ' . $sourceId;
        }
        $defaultTemplate = isset($templates[$defaultLang]) ? $templates[$defaultLang] : (string) reset($templates);
        // The name is required in every language of the shop
        foreach (\Language::getIDs(false) as $idLang) {
            if (!isset($names[$idLang]) || $names[$idLang] === '') {
                $names[$idLang] = $label;
            }
            if (!isset($templates[$idLang])) {
                $templates[$idLang] = $defaultTemplate;
            }
        }

        $deleted = !empty($row['deleted']);
        $existing = $this->findOrderState($names, $deleted);
        if ($existing > 0) {
            return ['source' => $sourceId, 'id' => $existing, 'name' => $label, 'reused' => true, 'mail_off' => false, 'template' => ''];
        }

        $state = new \OrderState();
        foreach (['invoice', 'send_email', 'logable', 'shipped', 'delivery', 'hidden', 'paid', 'pdf_invoice', 'pdf_delivery'] as $flag) {
            if (property_exists($state, $flag)) {
                $state->$flag = !empty($row[$flag]);
            }
        }
        $state->deleted = $deleted;
        // A status created here must stay removable by the merchant
        $state->unremovable = false;
        $state->color = isset($row['color']) && Validate::isColor($row['color']) ? $row['color'] : '#cccccc';
        $state->module_name = isset($row['module_name']) && Validate::isModuleName($row['module_name']) ? $row['module_name'] : '';
        $state->name = $names;
        $state->template = $templates;

        // Without its template the status would fail to send its e-mail on
        // every order that reaches it
        $mailOff = false;
        if ($state->send_email && !$this->hasMailTemplate($defaultTemplate)) {
            $state->send_email = false;
            $mailOff = true;
        }

        try {
            $saved = $state->add();
        } catch (\Exception $e) {
            throw new \Exception(sprintf('Order status "%s" could not be created: %s', $label, $e->getMessage()));
        }
        if (!$saved) {
            throw new \Exception(sprintf('Order status "%s" could not be created.', $label));
        }

        $this->copyIcon($sourceId, (int) $state->id);

        return ['source' => $sourceId, 'id' => (int) $state->id, 'name' => $label, 'reused' => false, 'mail_off' => $mailOff, 'template' => $defaultTemplate];
    }

    /**
     * Status of the target with one of these names (any language), deleted
     * only when the source one is.
     */
    private function findOrderState(array $names, $deleted)
    {
        $quoted = [];
        foreach (array_unique($names) as $name) {
            if ($name !== '') {
                $quoted[] = "'" . pSQL($name) . "'";
            }
        }
        if (empty($quoted)) {
            return 0;
        }

        return (int) Db::getInstance()->getValue('SELECT os.`id_order_state` FROM `' . _DB_PREFIX_ . 'order_state` os
            JOIN `' . _DB_PREFIX_ . 'order_state_lang` osl ON osl.`id_order_state` = os.`id_order_state`
            WHERE osl.`name` IN (' . implode(',', $quoted) . ') AND os.`deleted` = ' . ($deleted ? 1 : 0) . '
            ORDER BY os.`id_order_state` ASC', false);
    }

    /**
     * Same lookup as Mail::send(): the shop's mail folder or the theme's, in
     * the default language or in English, with the files the mail type needs.
     */
    private function hasMailTemplate($template)
    {
        if ($template === '' || !Validate::isTplName($template)) {
            return false;
        }

        $type = (int) Configuration::get('PS_MAIL_TYPE');
        $extensions = in_array($type, [1, 4], true) ? ['html'] : ($type === 2 ? ['txt'] : ['html', 'txt']);
        $folders = [_PS_MAIL_DIR_];
        if (defined('_PS_THEME_DIR_')) {
            $folders[] = _PS_THEME_DIR_ . 'mails/';
        }
        $isos = array_unique([(string) \Language::getIsoById((int) Configuration::get('PS_LANG_DEFAULT')), 'en']);

        foreach ($isos as $iso) {
            foreach ($folders as $folder) {
                $complete = true;
                foreach ($extensions as $extension) {
                    if (!file_exists($folder . $iso . '/' . $template . '.' . $extension)) {
                        $complete = false;
                        break;
                    }
                }
                if ($complete) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The icon of the status (img/os/<id>.gif), when the source has one. The
     * file comes from another server: only a real GIF is written.
     */
    private function copyIcon($sourceId, $targetId)
    {
        $content = $this->conn->getFile('img/os/' . (int) $sourceId . '.gif');
        if (!is_string($content) || strlen($content) > self::ICON_MAX_BYTES || strncmp($content, 'GIF8', 4) !== 0) {
            return false;
        }
        $info = @getimagesizefromstring($content);
        if (!$info || $info[2] !== IMAGETYPE_GIF) {
            return false;
        }

        $folder = defined('_PS_ORDER_STATE_IMG_DIR_') ? _PS_ORDER_STATE_IMG_DIR_ : _PS_IMG_DIR_ . 'os/';

        return (bool) @file_put_contents($folder . (int) $targetId . '.gif', $content);
    }

    private function zone($sourceId)
    {
        $rows = $this->conn->query("SELECT `name` FROM `{$this->prefix}zone` WHERE `id_zone` = " . (int) $sourceId)->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            throw new \Exception(sprintf('Zone #%d is no longer in the source shop. Go back to the mapping step and load it again.', $sourceId));
        }
        $name = $this->cleanName($rows[0]['name']);
        if ($name === '') {
            $name = 'Zone ' . $sourceId;
        }

        $id = (int) Db::getInstance()->getValue("SELECT `id_zone` FROM `" . _DB_PREFIX_ . "zone`
            WHERE `name` = '" . pSQL($name) . "' AND `active` = 1 ORDER BY `id_zone` ASC", false);
        $reused = $id > 0;
        if (!$reused) {
            $zone = new \Zone();
            $zone->name = $name;
            $zone->active = true;
            $zone->id_shop_list = [SchemaHelper::getTargetShopId()];
            try {
                $saved = $zone->add();
            } catch (\Exception $e) {
                throw new \Exception(sprintf('Zone "%s" could not be created: %s', $name, $e->getMessage()));
            }
            if (!$saved) {
                throw new \Exception(sprintf('Zone "%s" could not be created.', $name));
            }
            $id = (int) $zone->id;
        }

        return [
            'source' => $sourceId,
            'id' => $id,
            'name' => $name,
            'reused' => $reused,
            'countries' => $this->moveCountries($sourceId, $id),
            'states' => $this->moveStates($sourceId, $id),
        ];
    }

    /**
     * Countries of this shop (matched by ISO code) that were in the zone in
     * the old shop. Countries the target does not have yet get the zone when
     * the localization step adds them.
     */
    private function moveCountries($sourceZone, $targetZone)
    {
        $ids = [];
        $rows = $this->conn->query("SELECT `iso_code` FROM `{$this->prefix}country` WHERE `id_zone` = " . (int) $sourceZone)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $id = (int) Db::getInstance()->getValue("SELECT `id_country` FROM `" . _DB_PREFIX_ . "country`
                WHERE `iso_code` = '" . pSQL($row['iso_code']) . "'", false);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if (!empty($ids)) {
            Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'country` SET `id_zone` = ' . (int) $targetZone . '
                WHERE `id_country` IN (' . implode(',', $ids) . ')');
        }

        return count($ids);
    }

    /**
     * States of this shop (same ISO code in a country with the same ISO code)
     * that were in the zone in the old shop.
     */
    private function moveStates($sourceZone, $targetZone)
    {
        $ids = [];
        $rows = $this->conn->query("SELECT s.`iso_code` AS state_iso, c.`iso_code` AS country_iso
            FROM `{$this->prefix}state` s
            JOIN `{$this->prefix}country` c ON c.`id_country` = s.`id_country`
            WHERE s.`id_zone` = " . (int) $sourceZone)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $id = (int) Db::getInstance()->getValue("SELECT s.`id_state` FROM `" . _DB_PREFIX_ . "state` s
                JOIN `" . _DB_PREFIX_ . "country` c ON c.`id_country` = s.`id_country`
                WHERE s.`iso_code` = '" . pSQL($row['state_iso']) . "' AND c.`iso_code` = '" . pSQL($row['country_iso']) . "'", false);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if (!empty($ids)) {
            Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'state` SET `id_zone` = ' . (int) $targetZone . '
                WHERE `id_state` IN (' . implode(',', $ids) . ')');
        }

        return count($ids);
    }

    /**
     * Source names may hold characters PrestaShop refuses in a name (and that
     * would be markup in the back office); longer names are cut.
     */
    private function cleanName($name)
    {
        $name = preg_replace('/[<>={}]+/u', ' ', (string) $name);
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name));

        return \Tools::substr($name, 0, self::NAME_LENGTH);
    }
}
