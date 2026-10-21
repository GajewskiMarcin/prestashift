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

use Mail;
use Language;
use Context;
use Configuration;
use Module;

class TelemetryService
{
    private $recipient = 'kontakt@marcingajewski.pl';
    
    public function sendErrorReport($errorData, $config)
    {
        if (empty($config['options']['telemetry'])) {
            return false;
        }

        $id_lang = (int)Context::getContext()->language->id;
        $admin_email = Context::getContext()->employee ? Context::getContext()->employee->email : 'unknown';
        
        $template_vars = [
            '{prestashop_version}' => _PS_VERSION_,
            '{php_version}' => PHP_VERSION,
            '{memory_limit}' => ini_get('memory_limit'),
            '{max_execution_time}' => ini_get('max_execution_time'),
            '{error_message}' => $errorData['message'],
            '{error_details}' => isset($errorData['details']) ? $errorData['details'] : 'N/A',
            '{admin_contact}' => $admin_email,
            '{source_url}' => isset($config['source_url']) ? $config['source_url'] : 'N/A',
            '{module_version}' => $this->moduleVersion(),
            '{source_shop}' => $this->sourceShop(),
            '{migration_context}' => $this->context($errorData, $config),
            '{connection_info}' => $this->connection(),
            '{log_tail}' => $this->logTail(),
            '{debug_info}' => json_encode($errorData, JSON_PRETTY_PRINT)
        ];

        try {
            return Mail::Send(
                $id_lang,
                'telemetry',
                'PrestaShift Error Report',
                $template_vars,
                $this->recipient,
                'PrestaShift Telemetry',
                null,
                null,
                null,
                null,
                _PS_MODULE_DIR_ . 'prestashift/mails/'
            );
        } catch (\Exception $e) {
            return false;
        }
    }

    private function moduleVersion()
    {
        $module = Module::getInstanceByName('prestashift');

        return $module ? $module->version : 'unknown';
    }

    /**
     * What the connection test found on the source shop, stored when the
     * operator pressed "Test Connection".
     */
    private function sourceShop()
    {
        $raw = Configuration::get('PRESTASHIFT_SOURCE_INFO');
        $info = $raw ? json_decode($raw, true) : null;
        if (!is_array($info)) {
            return 'unknown';
        }

        return 'PrestaShop ' . (isset($info['ps_version']) ? $info['ps_version'] : '?')
            . ', PHP ' . (isset($info['php_version']) ? $info['php_version'] : '?')
            . ', connector ' . (isset($info['version']) ? $info['version'] : '?')
            . ', prefix ' . (isset($info['prefix']) ? $info['prefix'] : '?');
    }

    /**
     * Where the migration stood and how it was set up. Settings only — no
     * customer, order or catalogue content.
     */
    private function context($errorData, $config)
    {
        $options = isset($config['options']) && is_array($config['options']) ? $config['options'] : [];
        $scope = isset($config['scope']) && is_array($config['scope']) ? array_keys(array_filter($config['scope'])) : [];
        $flag = function ($name) use ($options) {
            return empty($options[$name]) ? 'no' : 'yes';
        };

        $lines = [
            'Task: ' . (isset($errorData['task']) ? $errorData['task'] : 'unknown')
                . ' (offset ' . (isset($errorData['offset']) ? $errorData['offset'] : '?') . ')',
            'Connection: ' . (isset($config['connection_method']) ? $config['connection_method'] : 'bridge')
                . ', table prefix ' . (isset($config['db_prefix']) ? $config['db_prefix'] : '?'),
            'Batch size: ' . (isset($options['batch_size']) ? $options['batch_size'] : 'default')
                . ', images ' . (isset($options['img_batch_size']) ? $options['img_batch_size'] : 'default')
                . ', request delay ' . (isset($options['delay']) ? $options['delay'] : 'default') . ' ms'
                . ', image delay ' . (isset($options['img_delay']) ? $options['img_delay'] : 'default') . ' ms',
            'Clean target: ' . $flag('clean_target') . ', delta: ' . $flag('incremental')
                . ', skip files: ' . $flag('skip_files') . ', target shop id: '
                . (isset($options['target_shop_id']) ? $options['target_shop_id'] : '1'),
            'Scope: ' . ($scope ? implode(', ', $scope) : 'everything'),
        ];

        return implode("\n", $lines);
    }

    /**
     * What the last call to the source shop did, which separates a server that
     * never answered from one that was never reached.
     */
    private function connection()
    {
        $r = ConnectorClient::lastRequest();
        if (empty($r)) {
            return 'no HTTP request was made (direct database connection)';
        }

        return 'action ' . $r['action'] . ', HTTP ' . $r['http_code']
            . ', attempt ' . $r['attempt']
            . ', DNS ' . $r['dns_lookup_s'] . 's, connect ' . $r['connect_s'] . 's, total ' . $r['total_s'] . 's'
            . ', received ' . $r['bytes_received'] . ' bytes'
            . ($r['error'] !== '' ? ', error: ' . $r['error'] : '')
            // Requests and waiting time of this one batch: they separate a slow
            // source shop from a slow target.
            . "\n" . 'this batch: ' . ConnectorClient::requestCount() . ' request(s) to the source, '
            . round(ConnectorClient::requestTime(), 2) . 's spent waiting for them';
    }

    /**
     * The module's own migration log, last lines only, and only when the
     * operator turned logging on. It holds step names and counts, never
     * customer or order content.
     */
    private function logTail()
    {
        $log = LogService::getInstance()->getLogFile();
        if (!file_exists($log)) {
            return 'logging was off';
        }
        $lines = @file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return 'log is empty';
        }

        return implode("\n", array_slice($lines, -20));
    }

}
