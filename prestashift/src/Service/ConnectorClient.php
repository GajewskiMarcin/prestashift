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

    /** @var array what the last request to the source shop did — for error reports */
    private static $lastRequest = [];

    /** @var int how many requests have been sent to the source shop */
    private static $requestCount = 0;

    /** @var float seconds spent waiting for the source shop to answer */
    private static $requestTime = 0.0;

    /**
     * Requests sent to the source shop so far. Every one of them is a round trip
     * over the internet, so this is the number that decides whether a slow source
     * server can keep up with the migration.
     */
    public static function requestCount()
    {
        return self::$requestCount;
    }

    /**
     * Seconds spent waiting for the source shop. Together with requestCount() it
     * separates a slow source from a slow target: if a batch takes 20 seconds and
     * 2 of them were spent waiting, the time went on writing, not on the network.
     */
    public static function requestTime()
    {
        return self::$requestTime;
    }

    /**
     * Timings and outcome of the last call to the source shop. Says whether the
     * name resolved, whether the connection was established, how long the shop
     * was given and how much it sent back.
     */
    public static function lastRequest()
    {
        return self::$lastRequest;
    }

    public function __construct($url, $token)
    {
        $this->url = rtrim($url, '/');
        if (strtolower((string) parse_url($this->url, PHP_URL_SCHEME)) !== 'https') {
            throw new Exception('Connector Error: HTTPS is required to protect the connector token and migration data.');
        }
        $this->token = $token;
    }

    /**
     * The address of the source shop, whatever the operator pasted in.
     *
     * The wizard asks for the shop address and appends the connector path
     * itself. People understandably paste the connector address they were told
     * to open, which used to produce .../api.php/modules/psconnector/api.php
     * and a request that never answers. Both forms are accepted now, and the
     * cleaned address is also what the file downloads (logos, attachments,
     * CMS images) are built from.
     */
    public static function shopUrl($url)
    {
        $url = trim((string) $url);
        $url = preg_replace('#/modules/psconnector/api\.php/?$#i', '', $url);

        return rtrim($url, '/');
    }

    /**
     * Address of the connector endpoint on the source shop.
     */
    public static function endpointUrl($url)
    {
        return self::shopUrl($url) . '/modules/psconnector/api.php';
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
        ++self::$requestCount;

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
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            $started = microtime(true);
            $started = microtime(true);
        $response = curl_exec($ch);
        self::$requestTime += microtime(true) - $started;
            self::$requestTime += microtime(true) - $started;
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrno = curl_errno($ch);
            $curlError = $curlErrno ? curl_error($ch) : '';
            self::$lastRequest = [
                'action' => $action,
                'http_code' => $httpCode,
                'error' => $curlError,
                'attempt' => $attempt + 1,
                'dns_lookup_s' => round((float) curl_getinfo($ch, CURLINFO_NAMELOOKUP_TIME), 2),
                'connect_s' => round((float) curl_getinfo($ch, CURLINFO_CONNECT_TIME), 2),
                'total_s' => round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME), 2),
                'bytes_received' => is_string($response) ? strlen($response) : 0,
            ];
            curl_close($ch);

            // The request never completed: DNS, TLS, a timeout, or the source
            // server dropping the connection. A refused or dropped connection
            // usually works on the next try, so those are retried in full.
            //
            // A request that connected and then stayed silent for the whole
            // timeout is different: the source shop is saturated, not blinking.
            // Asking it three more times only adds load to a server already on
            // its knees and leaves the user watching a frozen progress bar for
            // two minutes, so a timeout gets one retry instead of three.
            if ($response === false || $curlError !== '' || $httpCode === 0) {
                $timedOut = $curlErrno === (defined('CURLE_OPERATION_TIMEDOUT') ? CURLE_OPERATION_TIMEDOUT : 28);
                $allowed = $timedOut ? 1 : count($backoff);
                if ($attempt < $allowed) {
                    sleep(isset($backoff[$attempt]) ? $backoff[$attempt] : 2);
                    ++$attempt;
                    continue;
                }
                throw new Exception('Connector Error: no answer from the source shop at ' . $this->url
                    . ($curlError !== '' ? ' (' . $curlError . ')' : '')
                    . '. Tried ' . ($attempt + 1) . ' time' . ($attempt ? 's' : '')
                    . '. The connection was refused, timed out or was dropped. Check that the address is reachable;'
                    . ' if it only fails during a migration, the source server is cutting the connection under load:'
                    . " lower 'Batch Size' and 'Image Batch Size' and raise 'Request Delay' in the Options step,"
                    . ' then click Resume. If both shops are on the same server, the Direct Database connection'
                    . ' makes no HTTP requests at all and avoids this entirely.');
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
        ++self::$requestCount;

        $ch = curl_init($this->url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-PS-Connector-Token: ' . $this->token
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

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
