<?php

namespace PaywithTerra;

use Exception;

class PaywithTerraClient
{
    const VERSION = '1.0.1';

    private $baseURL = 'https://paywithterra.com/api/';
    
    private $basicPauseDurationMs = 1000;

    private $maxAttemptsCount = 3;

    private $retryingErrorCodes = [423, 425, 429, 500, 502, 503, 504, 507, 510];
    
    public function __construct($apiToken, $config = [])
    {
        $this->apiToken = $apiToken;
        $this->setConfig($config);
    }
    
    /**
     * Create order for watching
     * @param array $orderData
     * @return array
     *
     * @see https://paywithterra.com/docs/api#create-order
     * 
     * @throws Exception
     */
    public function createOrder($orderData = [])
    {
        return $this->request('POST', "order/create", $orderData);
    }
    
    /**
     * Returns boolean order payment status
     * 
     * @param $uuid
     * @return bool
     * @throws Exception
     */
    public function isOrderPayedByUUID($uuid)
    {
        $orderStatus = $this->getOrderStatusByUUID($uuid);
        return (bool) $orderStatus["is_payed"];
    }
    
    /**
     * Get proven information about order with specified uuid (Second check).
     * @param $uuid
     * @return array
     * @throws Exception
     * 
     * @see https://paywithterra.com/docs/api#second-check
     */
    public function getOrderStatusByUUID($uuid)
    {
        return $this->request('GET', "order/$uuid/status");
    }

    /**
     * Check data integrity on callback input
     * 
     * @param $incomingData
     * @return array
     * @throws Exception
     */
    public function checkIncomingData($incomingData)
    {
        $check_hash = $incomingData['hash'];
        unset($incomingData['hash']);
        $data_check_arr = [];
        foreach ($incomingData as $key => $value) {
            $data_check_arr[] = $key . '=' . $value;
        }
        sort($data_check_arr);
        $data_check_string = implode("\n", $data_check_arr);
        $secret_key = hash('sha256', $this->apiToken, true);
        $hash = hash_hmac('sha256', $data_check_string, $secret_key);
        if (strcmp($hash, $check_hash) !== 0) {
            throw new Exception('Data is NOT from PaywithTerra');
        }
        return $incomingData;
    }

    /**
     * Add new stream
     *
     * @param array $streamData
     * @return array
     * @throws Exception
     * 
     * @see https://paywithterra.com/docs/stream
     * @see https://paywithterra.com/docs/api#managing-streams
     * @see https://paywithterra.com/docs/api#stream-signals
     */
    public function addStream($streamData = [])
    {
        return $this->request('POST', 'stream/add', $streamData);
    }

    /**
     * Get active streams list
     * 
     * @return array
     * @throws Exception
     * 
     * @see https://paywithterra.com/docs/api#managing-streams
     */
    public function listStreams()
    {
        return $this->request('GET', 'stream/list');
    }

    /**
     * Delete Stream by id
     * 
     * @param $id
     * @return array
     * @throws Exception
     * 
     * @see https://paywithterra.com/docs/api#managing-streams
     */
    public function deleteStream($id)
    {
        return $this->request('POST', 'stream/delete', [
            "id" => $id
        ]);
    }

    /**
     * Set extra options to set during curl initialization.
     *
     * @param array $options
     *
     * @return PaywithTerraClient
     */
    public function setCurlOptions(array $options)
    {
        $this->curlOptions = $options;

        return $this;
    }

    /**
     * @param array $config
     * @return $this
     */
    public function setConfig($config = [])
    {
        if(isset($config['baseURL'])){
            $this->baseURL = $config['baseURL'];
        }

        if(isset($config['basicPauseDurationMs'])){
            $this->basicPauseDurationMs = $config['basicPauseDurationMs'];
        }

        if(isset($config['maxAttemptsCount'])){
            $this->maxAttemptsCount = $config['maxAttemptsCount'];
        }

        if(isset($config['retryingErrorCodes'])){
            $this->retryingErrorCodes = $config['retryingErrorCodes'];
        }
        
        return $this;
    }
    
    /**
     * Execute prepared request
     *
     * Will retry up to 3 times failed requests
     * with a status code included in (423, 425, 429, 500, 502, 503, 504, 507, 510)
     * and it will wait exponentially from 1 second (first retry) to 3 seconds (third attempt).
     *
     * @param $method
     * @param $uri
     * @param array $params
     * @param int $retryAttempt
     * @return array
     * @throws Exception
     */
    public function request($method, $uri, $params = [], $retryAttempt = 1)
    {
        $fullUrl = $this->baseURL . $uri;

        $httpHeaders = [
            'Authorization: Bearer ' . $this->apiToken,
            'User-Agent: PaywithTerraClient_PHP/' . self::VERSION
        ];
        
        $session = curl_init($fullUrl);

        $options = $this->createCurlOptions($method, $params, $httpHeaders);
        
        curl_setopt_array($session, $options);
        $content = curl_exec($session);

        if ($content === false) {
            if($retryAttempt > $this->maxAttemptsCount){
                throw new Exception(curl_error($session), curl_errno($session));
            }
            $this->pauseBetweenAttempts($retryAttempt);
            return $this->request($method, $uri, $params, $retryAttempt + 1);
        }

        $responseBody = $this->parseResponse($session, $content);

        if ($retryAttempt < $this->maxAttemptsCount && in_array($this->lastResponseCode, $this->retryingErrorCodes) ) {

            $this->pauseBetweenAttempts($retryAttempt);
            return $this->request($method, $uri, $params, $retryAttempt + 1);
        }

        curl_close($session);
        
        $parsedBody = @json_decode($responseBody, true);
        
        
        if(null === $parsedBody){
            if($retryAttempt > $this->maxAttemptsCount){
                $msg = "Unable to parse API response";
                throw new Exception($msg);
            }

            $this->pauseBetweenAttempts($retryAttempt);
            return $this->request($method, $uri, $params, $retryAttempt + 1);
        }

        return $parsedBody;
    }
        
    /**
     * Get response code from last request
     * 
     * @return string
     */
    public function getLastResponseCode()
    {
        return $this->lastResponseCode;
    }

    /**
     * Get response headers from last request
     * @return array
     */
    public function getLastResponseHeaders()
    {
        return $this->lastResponseHeaders;
    }

    /**
     * @var string The API token to be used for requests.
     */
    protected $apiToken = '';
    
    private $lastResponseCode;
    private $lastResponseHeaders;
    
    protected $curlOptions = [];

    /**
     * Creates curl options for a request
     * this function does not mutate any private variables.
     *
     * @param string $method
     * @param array $body
     * @param null $addHeaders
     * @return array
     */
    private function createCurlOptions($method, $body = null, $addHeaders = null)
    {
        $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_FAILONERROR => false,
            ] + $this->curlOptions;

        $headers = [];

        if (isset($addHeaders)) {
            $headers = array_merge($headers, $addHeaders);
        }

        if (isset($body)) {
            $encodedBody = json_encode($body);
            $options[CURLOPT_POSTFIELDS] = $encodedBody;
            $headers = array_merge($headers, ['Content-Type: application/json']);
        }

        $options[CURLOPT_HTTPHEADER] = $headers;

        return $options;
    }

    /**
     * Making pause with length based on attempt number
     * @param int $attemptNo
     */
    private function pauseBetweenAttempts($attemptNo = 1)
    {
        $duration = $this->basicPauseDurationMs * $attemptNo;
        usleep(1E6 * $duration);
    }

    private function parseResponse($session, $content)
    {
        $headerSize = curl_getinfo($session, CURLINFO_HEADER_SIZE);
        $statusCode = curl_getinfo($session, CURLINFO_HTTP_CODE);

        $responseBody = mb_substr($content, $headerSize);

        $responseHeaders = mb_substr($content, 0, $headerSize);
        $responseHeaders = explode("\n", $responseHeaders);
        $responseHeaders = array_map('trim', $responseHeaders);
        
        $this->lastResponseCode = $statusCode;
        $this->lastResponseHeaders = $responseHeaders;

        return $responseBody;
    }
}