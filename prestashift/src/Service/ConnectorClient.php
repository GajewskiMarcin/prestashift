<?php
/**
 * PrestaShift Migration Module
 * 
 * @author    marcingajewski.pl <kontakt@marcingajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * @attribution PrestaShift by Marcin Gajewski (marcingajewski.pl). Keep the Attribution Notice in prestashift.php — AFL-3.0 section 6.
 * @version   1.4.3
 */
namespace PrestaShift\Service;

use Exception;

class ConnectorClient
{
    private $url;
    private $token;

    public function __construct($url, $token)
    {
        $this->url = rtrim($url, '/');
        $this->token = $token;
    }

    /**
     * Mocks PDO::query()
     */
    public function query($sql)
    {
        // Send the SQL base64-encoded and expect base64-encoded rows back, so a
        // WAF/ModSecurity on the source server cannot pattern-match HTML in the
        // descriptions and block the transfer. See psconnector/api.php.
        $response = $this->request('query', ['sql_b64' => base64_encode($sql)]);

        if (!$response['success']) {
            throw new Exception("Connector Query Error: " . ($response['error'] ?? 'Unknown error'));
        }

        if (isset($response['data_b64'])) {
            $decoded = base64_decode($response['data_b64'], true);
            $data = ($decoded === false) ? [] : json_decode($decoded, true);
            if (!is_array($data)) {
                $data = [];
            }
        } else {
            // Fallback for an older connector that still returns raw rows
            $data = isset($response['data']) ? $response['data'] : [];
        }

        return new ConnectorResult($data);
    }

    public function test()
    {
        return $this->request('test');
    }

    /**
     * Fetch file from source via bridge
     */
    public function getFile($path)
    {
        return $this->requestRaw('file', ['path' => $path]);
    }

    private function request($action, $params = [])
    {
        $params['action'] = $action;
        $params['token'] = $this->token;
        $postFields = http_build_query($params);

        // Auto-retry on rate limiting (429/503). The migration makes many
        // requests to the connector; a source server with a rate limiter
        // (mod_evasive, nginx limit_req, Cloudflare, shared hosts) starts
        // answering 429 once the threshold is crossed. Backing off a few seconds
        // lets the limit window reset so the batch continues instead of failing.
        $backoff = [2, 4, 8]; // seconds before each retry — ~14s total
        $attempt = 0;

        while (true) {
            $retryIn = isset($backoff[$attempt]) ? $backoff[$attempt] : null;
            $ch = curl_init($this->url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'X-PS-Connector-Token: ' . $this->token
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_errno($ch) ? curl_error($ch) : '';
            curl_close($ch);

            // The request never completed: DNS, TLS, a timeout, or the source
            // server dropping the connection. That usually succeeds on a second
            // try, so retry before giving up — and say what actually happened
            // instead of blaming the URL.
            if ($response === false || $curlError !== '' || $httpCode === 0) {
                if ($retryIn !== null) {
                    sleep($retryIn);
                    ++$attempt;
                    continue;
                }
                throw new Exception('Connector Error: no answer from the source shop at ' . $this->url
                    . ($curlError !== '' ? ' (' . $curlError . ')' : '')
                    . '. The connection was refused, timed out or was dropped. Check that the address is reachable;'
                    . ' if it only fails during a migration, the source server is cutting the connection under load:'
                    . " lower 'Batch Size' and 'Image Batch Size' and raise 'Request Delay' in the Options step.");
            }

            if ($httpCode === 403) {
                // Two very different causes answer 403: our connector rejecting
                // the token (JSON), and the source server's firewall, WAF or
                // country blocking (an HTML page the connector never saw).
                $body = json_decode($response, true);
                if (is_array($body) && isset($body['error'])) {
                    throw new Exception('Connector Error: 403 Forbidden — the connector rejected the token ('
                        . $body['error'] . '). Copy the token again from the Connector module on the source shop.');
                }
                throw new Exception('Connector Error: 403 Forbidden — the request was blocked by the source server'
                    . ' before it reached the connector, so the token was never checked. A firewall, ModSecurity,'
                    . ' a security plugin or country blocking is rejecting ' . $this->url
                    . '. Allow that address and the IP of this shop\'s server, or use the Direct Database connection'
                    . ' instead. Server answer: ' . $this->snippet($response));
            }

            if ($httpCode === 404) {
                throw new Exception('Connector Error: 404 Not Found at ' . $this->url
                    . '. Check the source shop URL and that the Connector module is installed there.'
                    . ' Server answer: ' . $this->snippet($response));
            }

            // Gateway errors: the web server in front of the source shop gave up
            // waiting for it. Worth retrying, like a rate limit.
            if ($httpCode === 502 || $httpCode === 504) {
                if ($retryIn !== null) {
                    sleep($retryIn);
                    ++$attempt;
                    continue;
                }
                throw new Exception('Connector Error: the source server kept answering HTTP ' . $httpCode
                    . ' (gateway timeout) after ' . count($backoff) . ' retries. It takes too long to reply:'
                    . " lower 'Batch Size' and 'Image Batch Size' in the Options step, then click Resume.");
            }

            // Rate limit on the source server (mod_evasive, nginx limit_req,
            // Cloudflare, shared-host limits): it answers 429/503 with an HTML
            // error page, not JSON. Retry with backoff, then report it clearly.
            if ($httpCode === 429 || $httpCode === 503) {
                if ($attempt < count($backoff)) {
                    sleep($backoff[$attempt]);
                    $attempt++;
                    continue;
                }
                throw new Exception("Connector Error: the source server is rate-limiting the connection (HTTP " . $httpCode . " Too Many Requests) and kept blocking after " . count($backoff) . " retries. Increase 'Request Delay' in the Options step, wait a minute and click Resume, or ask your hosting to relax the rate limit (mod_evasive / mod_security) or whitelist the target server's IP.");
            }

            // An empty body means the source shop died mid-request (PHP memory
            // or time limit) or the server closed the connection. Retry first.
            if (trim($response) === '') {
                if ($retryIn !== null) {
                    sleep($retryIn);
                    ++$attempt;
                    continue;
                }
                throw new Exception('Connector Error: the source shop returned an empty answer (HTTP ' . $httpCode
                    . ') after ' . count($backoff) . ' retries. That usually means its PHP ran out of memory or time'
                    . " while answering: lower 'Batch Size' and 'Image Batch Size', raise 'Request Delay' in the"
                    . ' Options step, then click Resume.');
            }

            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Connector Error: the source shop answered something the module cannot read'
                    . ' (HTTP ' . $httpCode . '), usually a PHP warning, a security plugin or a login page put in'
                    . " front of the connector's answer. Server answer: " . $this->snippet($response));
            }

            return $data;
        }
    }

    private function requestRaw($action, $params = [])
    {
        $params['action'] = $action;
        $params['token'] = $this->token;

        $ch = curl_init($this->url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-PS-Connector-Token: ' . $this->token
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_errno($ch) ? curl_error($ch) : '';
        curl_close($ch);

        // Files (images, attachments) are optional: a missing or blocked file
        // must not stop the migration, so the failure goes to the log and the
        // step carries on without that file.
        if ($httpCode !== 200 || $response === false || $response === '') {
            LogService::getInstance()->warning('Connector: could not fetch '
                . (isset($params['path']) ? $params['path'] : $action)
                . ' from the source shop (HTTP ' . $httpCode . ($curlError !== '' ? ', ' . $curlError : '') . ').');

            return false;
        }

        return $response;
    }

    /**
     * First readable line of whatever the server sent, for error messages.
     */
    private function snippet($response)
    {
        // Tags become spaces, otherwise words from separate HTML elements
        // run together and the message is unreadable.
        $text = preg_replace('#<(script|style)[^>]*>.*?</>#is', ' ', (string) $response);
        $text = trim(preg_replace('/\s+/', ' ', preg_replace('/<[^>]*>/', ' ', $text)));

        return $text === '' ? '(empty)' : mb_substr($text, 0, 200);
    }
}

