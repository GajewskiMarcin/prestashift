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
namespace PrestaShift\Service\Steps;

use PDO;
use PrestaShift\Service\IdMapper;
use PrestaShift\Service\LanguageMapper;
use PrestaShift\Service\LogService;
use PrestaShift\Service\SchemaHelper;
use PrestaShift\Service\SourceUrlBuilder;
use PrestaShift\Service\UrlRedirects;

/**
 * Old address → new address of every migrated product, category, CMS page,
 * brand and supplier, for the redirects. Runs after the configuration, when
 * the URL patterns of this shop are final.
 */
class UrlMapStep
{
    const ENTITIES = ['product', 'category', 'cms', 'cms_category', 'manufacturer', 'supplier'];

    /** Seconds a batch may run, as for the images: Link builds every address with an object of the page */
    const TIME_BUDGET = 15;

    private $db_connection;
    private $prefix;
    private $options;

    public function __construct($db_connection, $prefix, array $options)
    {
        $this->db_connection = $db_connection;
        $this->prefix = $prefix;
        $this->options = $options;
    }

    /**
     * @param string|null $entity null on the first batch of the task
     * @return array count, finished, entity and offset to go on with
     */
    public function process($entity, $offset, $limit)
    {
        $idShop = SchemaHelper::getTargetShopId();
        $builder = new SourceUrlBuilder($this->db_connection, $this->prefix);

        if ($entity === null) {
            UrlRedirects::clear($idShop);
            if (!$builder->rewritingEnabled()) {
                // index.php?id_product=… — with the ids kept, these addresses keep working
                LogService::getInstance()->info('URL map: friendly URLs are off in the source, its addresses carry the ids.');

                return ['count' => 0, 'finished' => true];
            }
            if (!\Configuration::get('PS_REWRITING_SETTINGS')) {
                // Every new address would be /index.php: rules built on that would send all old addresses home
                LogService::getInstance()->warning('URL map: friendly URLs are off in this shop, the old addresses are not mapped. Switch them on and run the migration again.');

                return ['count' => 0, 'finished' => true];
            }
            // What an earlier run wrote goes first: a pattern of this source that cannot be used must not leave another source's in place
            UrlRedirects::restoreTargetRoutes();
            if (UrlRedirects::mode($this->options) === 'match') {
                $written = UrlRedirects::applySourceRoutes($builder);
                LogService::getInstance()->info("URL map: $written URL patterns of the source written.");
            }

            // The patterns are read by the Dispatcher when a request starts: the addresses follow in the next batch
            return ['count' => 0, 'finished' => false, 'entity' => self::ENTITIES[0], 'offset' => 0];
        }

        $unsupported = $builder->unsupportedKeywords($entity);
        if ($unsupported) {
            LogService::getInstance()->warning("URL map: the old addresses of $entity use {" . implode('}, {', $unsupported) . '}, which cannot be rebuilt — no redirects for them.');

            return $this->next($entity);
        }

        $ids = $this->sourceIds($entity, $offset, $limit);
        if (empty($ids)) {
            return $this->next($entity);
        }

        $existing = $this->existingTargets($entity, $ids);
        $byRecord = [];
        foreach ($builder->paths($entity, $ids) as $path) {
            $byRecord[$path[0]][] = $path;
        }

        $startedAt = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);
        $deadline = $startedAt + self::TIME_BUDGET;
        $link = new \Link();
        $rows = [];
        $done = 0;
        foreach ($ids as $sourceId) {
            ++$done;
            $targetId = isset($existing[$sourceId]) ? $existing[$sourceId] : 0;
            if ($targetId > 0 && isset($byRecord[$sourceId])) {
                foreach ($byRecord[$sourceId] as $path) {
                    $row = $this->redirect($link, $entity, $targetId, $path, $idShop);
                    if ($row) {
                        $rows[] = $row;
                    }
                }
            }
            if (microtime(true) >= $deadline) {
                break;
            }
        }
        UrlRedirects::store($idShop, $rows);

        return ['count' => $done, 'finished' => false, 'entity' => $entity, 'offset' => $offset + $done];
    }

    private function next($entity)
    {
        $index = array_search($entity, self::ENTITIES, true);
        if ($index === false || $index + 1 >= count(self::ENTITIES)) {
            $count = UrlRedirects::refreshCount();
            LogService::getInstance()->info("URL map: $count old addresses differ in this shop.");

            return ['count' => 0, 'finished' => true];
        }

        return ['count' => 0, 'finished' => false, 'entity' => self::ENTITIES[$index + 1], 'offset' => 0];
    }

    /**
     * @param array $path [source id, source id_lang, old path]
     * @return array|null row for UrlRedirects::store(), null when nothing changes
     */
    private function redirect(\Link $link, $entity, $targetId, array $path, $idShop)
    {
        $targetLang = (int) LanguageMapper::toTarget($path[1]);
        if ($targetLang <= 0) {
            return null;
        }
        try {
            $new = UrlRedirects::targetPath($link, $entity, $targetId, $targetLang, $idShop);
        } catch (\Throwable $e) {
            return null;
        }
        if ($new === '' || $new === $path[2]) {
            return null;
        }
        if (!UrlRedirects::safePath($path[2]) || !UrlRedirects::safePath($new)) {
            LogService::getInstance()->warning('URL map: address left out, it holds characters a server rule cannot carry: ' . str_replace(["\r", "\n"], ' ', $path[2]));

            return null;
        }

        return [$path[2], $entity, $targetId, $targetLang, $new];
    }

    private function sourceIds($entity, $offset, $limit)
    {
        $key = 'id_' . $entity;
        // The root category has no page
        $where = $entity === 'category' ? ' WHERE `id_parent` > 0' : '';
        $rows = $this->db_connection->query("SELECT `$key` FROM `{$this->prefix}$entity`$where ORDER BY `$key` ASC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset)
            ->fetchAll(PDO::FETCH_ASSOC);

        return array_map('intval', array_column($rows, $key));
    }

    /**
     * Target ids of the records that exist in this shop: a mapped record
     * whose row was refused has no page to send anyone to.
     *
     * @return array source id => target id
     */
    private function existingTargets($entity, array $ids)
    {
        IdMapper::prepare($entity, $ids);
        $targets = [];
        foreach ($ids as $sourceId) {
            $targetId = IdMapper::find($entity, $sourceId);
            if ($targetId > 0) {
                $targets[$sourceId] = $targetId;
            }
        }
        if (empty($targets)) {
            return [];
        }

        $key = 'id_' . $entity;
        $found = \Db::getInstance()->executeS('SELECT `' . bqSQL($key) . '` AS `id` FROM `' . _DB_PREFIX_ . bqSQL($entity) . '`
            WHERE `' . bqSQL($key) . '` IN (' . implode(',', array_map('intval', $targets)) . ')', true, false);
        $present = array_flip(array_map('intval', array_column($found, 'id')));

        return array_filter($targets, function ($targetId) use ($present) {
            return isset($present[(int) $targetId]);
        });
    }
}
