<?php
/**
 * PrestaShift Migration Module
 * 
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @version   1.0.0
 */
namespace PrestaShift\Service;

use Mail;
use Language;
use Context;
use Configuration;

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
}
