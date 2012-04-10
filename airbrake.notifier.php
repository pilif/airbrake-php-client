<?php
/**
 * Airbrake Notifier Client for PHP
 * @author Jonathan Azoff
 * @date 01/18/2011
 * @homepage https://github.com/rentjuice/airbrake-php-client
 * @reference http://help.airbrake.io/kb/api-2/notifier-api-version-22
 */
class AirbrakeNotifier {

    const NOTIFIER_NAME     = 'Airbrake Notifier Client for PHP';
    const NOTIFIER_VERSION  = '1.0.0';
    const NOTIFIER_URL      = 'https://github.com/rentjuice/airbrake-php-client';

    const API_BASE_URL      = 'http://airbrake.io';
    const API_VERSION       = '2.2';

    /**
     * @var bool Toggles error logging for the lifetime of notifier
     */
    public static $debugMode  = false;

    /**
     * @var #D__DIR__|? The root of the project that is sending notices
     */
    public static $projectRoot = __DIR__;

    /**
     * @var string The environment that the notifier is running in. This can be any string value.
     */
    public static $environmentName;

    /**
     * @var string The version of the project sending notices.
     */
    public static $projectVersion = '1.0.0';

    /**
     * @var array These options are required when making a request to the notifier API
     */
    public static $requiredCurlOpts = array(
        CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_HTTPHEADER => array('Expect:', 'Content-Type: text/xml; charset=utf-8')
    );

    /**
     * @var array These options will be used if none are defined
     */
    public static $defaultCurlOpts = array(
        CURLOPT_DNS_CACHE_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 6
    );

    private $apiKey, $curlOpts;

    /**
     * Creates an Airbrake notifier instance
     * @param string $apiKey The API Token provided by Airbrake to interface with their API
     * @param array $desiredCurlOpts Optional cURL parameters to use in requests to the notifier
     * @see http://php.net/manual/en/function.curl-setopt.php for a full list of cURL options
     */
    public function __construct($apiKey, array $desiredCurlOpts = array()) {
        $this->apiKey   = $apiKey;
        $this->curlOpts = self::$requiredCurlOpts + $desiredCurlOpts + self::$defaultCurlOpts;
    }

    /**
     * Tracks an exception against the Airbrake Notifier API
     * @param Exception $exeption The exception to track
     * @param array $session An optional associative array of extra key/value pairs to pass to the API
     * @return string|bool The created notice ID returned by the API, false if an error occurred
     */
    public function notifyException(Exception $exeption, array $session = array()) {
        return $this->notify($exeption->getMessage(), $this->getFixedTrace($exeption), $session);
    }

    /**
     * Returns a more airbrake-ish backtrace
     *
     * php has the nasty habit (really? a nasty habit? Unthinkable!) of
     *    a) not including the actual spot of the error in the trace
     *    b) naming the name of the function that's about to be called
     *       instead of the one currently being executed.
     * this fixes up the trace in order to get it into a state more useful
     * in the contect of airbrake
     *
     * @param Exception $e the exception to get the trace for
     * @return array The cleaned up backtrace
     */
    private function getFixedTrace(Exception $e){
        $t = $e->getTrace();
        array_unshift($t, array('file' => $e->getFile(), 'line' => $e->getLine()));
        for($i = 0; $i < count($t); $i++){
            $t[$i]['function'] = $t[$i+1]['function'];
            $t[$i]['class'] = $t[$i+1]['class'];
        }
        return $t;
    }

    /**
     * Tracks an error against the Airbrake Notifier API
     * @param string $message The message to track (this is required)
     * @param string $cls Exception class
     * @param array $backtrace The backtrace for the error, formatted like the output of debug_backtrace()
     * @param array $session An optional associative array of extra key/value pairs to pass to the API
     * @see http://php.net/manual/en/function.debug-backtrace.php for info on how to format the trace
     * @return string|bool The created notice ID returned by the API, false if an error occurred
     */
    public function notify($message, $cls, array $backtrace = array(), array $session = array()) {
        $notice   = self::createNoticeXml($message, $cls, $backtrace, $custdata);
        $version  = intval(self::API_VERSION);
        $response = $this->execute("/notifier_api/v{$version}/notices", $notice);
        $noticeId = $response && property_exists($response, 'id') ? $response->id[0] : false;
        if (self::$debugMode) {
            $noticeUrl = $response && property_exists($response, 'url') ? $response->url[0] : '';
            if ($noticeUrl) {
                error_log("AIRBRAKE NOTICE CREATED: {$noticeUrl}");
            }
        }
        return $noticeId;
    }

    /**
     * Creates the notice XML for a given error message
     * @static
     * @param string $message The error message to track
     * @param string $cls Exception class
     * @param array $backtrace The stack trace of the generated error
     * @param array $session A map of extra data to send with the error
     * @see http://airbrake.io/airbrake_2_2.xsd for details on the generated XML
     * @return SimpleXMLElement The notice as a well-formed XML object
     */
    public function createNoticeXml($message, $cls, array $backtrace = array(), array $custdata = array()) {;

        // use fallbacks if empty arrays are provided
        if (!$session) $session = $_COOKIE;
        if (!$backtrace) $backtrace = debug_backtrace();

        // create the top-level notice
        $notice = new SimpleXMLElement('<notice></notice>');
        $notice->addAttribute('version', self::API_VERSION);
        $notice->addChild('api-key', $this->apiKey);

        // declare who is sending the error (aka the "notifier")
        $notifier = $notice->addChild('notifier');
        $notifier->addChild('name', self::NOTIFIER_NAME);
        $notifier->addChild('version', self::NOTIFIER_VERSION);
        $notifier->addChild('url', self::NOTIFIER_URL);

        // track the request that caused this error
        $component = self::escape(self::fetch($backtrace[0], 'class', __CLASS__));
        $action    = self::fetch($backtrace[0], 'function', __FUNCTION__);
        $request = $notice->addChild('request');

        // track any and all params that came in with this request
        if ($_REQUEST) {
            $params = $request->addChild('params');
            foreach ($_REQUEST as $key => $value) {
                $var = $params->addChild('var', self::escape($value));
                $var->addAttribute('key', self::escape($key));
            }
            // Airbrake overrides "controller" attributes with the component...
            $request->addChild('component', fetch($_REQUEST, 'controller', $component));
            // Allow users to specify actions via the $_REQUEST
            $request->addChild('action', fetch($_REQUEST, 'action', $action));
        } else {
            $request->addChild('component', $component);
            $request->addChild('action', $action);
        }

        // get the URL that caused this error
        $protocol = strtolower(array_shift(explode('/', self::fetch($_SERVER, 'SERVER_PROTOCOL'))));
        if ($protocol) {
            $host = self::fetch($_SERVER, 'SERVER_NAME');
            $path = self::fetch($_SERVER, 'REQUEST_URI');
            $url = "{$protocol}://{$host}{$path}";
        } else {
            $url = self::fetch($backtrace[0], 'file', __FILE__);
        }
        $request->addChild('url', self::escape($url));

        // define the error message and class
        $error = $notice->addChild('error');
        $error->addChild('message', self::escape($message));
        $error->addChild('class', $cls);

        // catalog the error backtrace
        $trace = $error->addChild('backtrace');

        foreach ($backtrace as $lineTrace) {
            $line = $trace->addChild('line');
            $line->addAttribute('file', self::escape(self::fetch($lineTrace, 'file', __FILE__)));
            $line->addAttribute('number', self::escape(self::fetch($lineTrace, 'line', __LINE__)));
            if (array_key_exists('function', $lineTrace)) {
                $line->addAttribute('method', self::escape($lineTrace['function']));
            }
        }

        // track the session that triggered the error (if any)
        if ($session) {
            $extraData = $request->addChild('session');
            foreach ($session as $key => $value) {
                $var = $extraData->addChild('var', self::escape($value));
                $var->addAttribute('key', self::escape($key));
            }
        }

        // track any and all server parameters
        $cgiData = $request->addChild('cgi-data');
        foreach ($_SERVER as $key => $value) {
            $var = $cgiData->addChild('var', self::escape($value));
            $var->addAttribute('key', self::escape($key));
        }

        // finally, track the server environment
        $envName = isset(self::$environmentName) ? self::$environmentName : (self::$debugMode ? 'development' : 'production');
        $serverEnv = $notice->addChild('server-environment');
        $serverEnv->addChild('project-root', self::escape(self::$projectRoot));
        $serverEnv->addChild('environment-name', self::escape($envName));
        $serverEnv->addChild('app-version', self::escape(self::$projectVersion));
        $serverEnv->addChild('hostname', self::escape(gethostname()));

        return $notice;
    }

    /**
     * POSTs XML to the Airbrake API servers using cURL as the transport
     * @param string $path The path to POST the XML to
     * @param SimpleXMLElement $payload The XML payload to post
     * @return null|SimpleXMLElement The parsed server response, as XML
     */
    private function execute($path, SimpleXMLElement $payload) {
        $url = implode('/', array(self::API_BASE_URL, $path));
        $transport = curl_init();

        curl_setopt_array($transport, array(
            CURLOPT_URL => $url,
            CURLOPT_POSTFIELDS => $payload->asXML()
        ) + $this->curlOpts);

        $response = curl_exec($transport);
        $info     = self::parseCurlInfo($transport);
        curl_close($transport);
        $responseXml = $response ? @simplexml_load_string($response) : null;

        if (!$info->success && self::$debugMode) {
            $msg = $response ? $response : $info->errorMsg;
            error_log("AIRBRAKE NOTIFIER ERROR: Received {$info->httpStatus} HTTP status code, '{$msg}'.");
        }

        return $responseXml;
    }

    /**
     * Parses the cURL transport for information about the response
     * @static
     * @param resource $transport The cURL to parse
     * @return stdClass A normalized map of data about the response
     */
    public static function parseCurlInfo($transport) {
        $output             = new stdClass();
        $curlInfo           = curl_getinfo($transport);
        $output->httpStatus = intval(self::fetch($curlInfo, 'http_code', '0'));
        $output->errorMsg   = self::fetch($curlInfo, 'error');
        $output->success    = !$output->errorMsg && $output->httpStatus > 99 && $output->httpStatus < 400;
        return $output;
    }

    /**
     * A utility function to get a value, under a key in an array
     * @static
     * @param array $array The array to search
     * @param string $key The key to look for
     * @param string $default the default value to use
     * @return mixed The value found under the key, or the default if none is found
     */
    private static function fetch(array $array, $key = '', $default = '') {
        return array_key_exists($key, $array) ? $array[$key] : $default;
    }

    /**
     * A utility function to escape objects for XML
     * @static
     * @param mixed $object The object to encode
     * @return string The XML-safe string-encoded representation
     */
    private static function escape($object) {
        if (!is_string($object)) {
            $object = print_r($object, true);
        } return htmlentities($object);
    }

}