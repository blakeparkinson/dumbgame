<?php
/**
 * Created by PhpStorm.
 * User: blake
 * Date: 5/21/14
 * Time: 2:08 PM
 */

class SnapshotApiClient
{

    static protected $_instance = NULL;

    private $_options = array(
        'client_id' => null, // client id required by snapshot
        'endpoint' => null, // Snapshot API endpoint
        'response_format' => null, // json or xml
        'response_type' => null, // 'assoc' for an associative array or 'object' for std object
        'client_timeout' => null, // number of seconds to wait for the client to complete it's request
    );

    public static $snapshot_time_limit_options = array(
        array('id' => 0, 'limit' => 'Time Limit: Unlimited'),
        array('id' => 300, 'limit' => 'Time Limit: 5 minutes'),
        array('id' => 600, 'limit' => 'Time Limit: 10 minutes'),
        array('id' => 900, 'limit' => 'Time Limit: 15 minutes'),
        array('id' => 1200, 'limit' => 'Time Limit: 20 minutes'),
        array('id' => 1500, 'limit' => 'Time Limit: 25 minutes'),
        array('id' => 1800, 'limit' => 'Time Limit: 30 minutes'),
        array('id' => 2100, 'limit' => 'Time Limit: 35 minutes'),
        array('id' => 2400, 'limit' => 'Time Limit: 40 minutes'),
        array('id' => 2700, 'limit' => 'Time Limit: 45 minutes'),
        array('id' => 3000, 'limit' => 'Time Limit: 50 minutes'),
        array('id' => 3300, 'limit' => 'Time Limit: 55 minutes'),
        array('id' => 3600, 'limit' => 'Time Limit: 1 hour'));

    public static $snapshot_grade_levels = array(
        array('id' => 3, 'grade_level' => '3rd'),
        array('id' => 4, 'grade_level' => '4th'),
        array('id' => 5, 'grade_level' => '5th'),
        array('id' => 6, 'grade_level' => '6th'),
        array('id' => 7, 'grade_level' => '7th'),
        array('id' => 8, 'grade_level' => '8th'),
        array('id' => 9, 'grade_level' => '9th'),
        array('id' => 10, 'grade_level' => '10th'),
        array('id' => 11, 'grade_level' => '11th'),
        array('id' => 12, 'grade_level' => '12th'));

    const HTTP_POST = 'POST';
    const HTTP_GET = 'GET';
    const JSON = 'json';
    const RESPONSE_TYPE_OBJECT = 'object'; // response type object
    const RESPONSE_TYPE_ASSOC = 'assoc'; // response type associative array
    const DEFAULT_CLIENT_TIMEOUT = 10; // default client timeout in seconds
    const SNAPSHOT_CLIENT_ID = SNAPSHOT_QA_API_CLIENT_ID;


    /**
     * Constructor
     * @param array $options, array of options to set
     */
    public function __construct($options) {
        // set defaults
        $this->_options['endpoint'] = $this->_getSnapshotEndpoint();
        $this->_options['client_id'] = self::SNAPSHOT_CLIENT_ID;
        $this->_options['response_format'] = self::JSON;
        $this->_options['response_type'] = self::RESPONSE_TYPE_OBJECT;
        $this->_options['client_timeout'] = self::DEFAULT_CLIENT_TIMEOUT;

        // set options (if any)
        $this->setOptions($this->_options);
    }

    /**
     * Implements the "singleton" pattern for this class
     * @return instance of this class
     */
    static function getInstance($options = array())
    {
        if( !isset(self::$_instance)){
            self::$_instance = new self($options);
        }
        return self::$_instance;
    }

    /**
     * @return string - the snapshot api endpoint to use
     */
    private function _getSnapshotEndpoint(){

        if (ENVIROMENT_DOMAIN == 'edmodo.com'){
            $snapshot_endpoint = 'https://snapshot.edmodo.com/';
        }
        else{
            $snapshot_endpoint = 'https://snapshot.edmodoqabranch.com/';
        }
        return $snapshot_endpoint;
    }

    /**
     * setOptions- setting some more config stuff
     * @param array $options
     * @return bool
     */

    public function setOptions($options = array()) {

        if (is_array($options) && $options) {

            foreach (array_keys($this->_options) as $option_name) {

                if (isset($options[$option_name])) {
                    $option_value = $options[$option_name];

                    $this->_options[$option_name] = $option_value;
                }

                if ($option_name == 'client_id'){
                    if (ENVIROMENT_DOMAIN == 'edmodo.com'){

                        $this->_options[$option_name] = SNAPSHOT_PROD_API_CLIENT_ID;

                    }
                }

            }

            return true;
        } else {
            return false;
        }
    }

    /**
     * getSnapshotAccessToken - the initial snapshot call which retrieves the access token
     * @param string $launch_key -- launch key required for validation
     * @param string $grant_type -- grant type that snapshot expects
     * @param string $language   -- language of the user
     * @return stdClass -- snapshot response object
     */

    public function getSnapshotAccessToken($launch_key, $grant_type = "launch_key", $language = "en"){

        $resource= 'oauth/token';
        $method = self::HTTP_POST;
        $payload = array(
            'client_id'     => $this->_options['client_id'],
            'launch_key'    => $launch_key,
            'grant_type'    => $grant_type,
            'ln'            => $language
        );

        return $this->_makeSnapshotApiRequest($resource, $method, $payload);

    }

    /**
     * getReport - fetches report for a given student
     * @param string $access_token -- access token of the requesting user
     * @param string $student  -- the id of the student you want the report for ex ('227')
     * @param string $standard  -- The id of the standard you want the report for ex ('601')
     * @param string $report_type -- snapshot has two types answers or filters, this specifies the type you want
     * @param string optional $group_ids -- The group you own. Defaults to all.
     * @return stdClass -- snapshot response object
     */

    public function getReport($access_token, $student, $standard, $report_type, $group_ids = ''){

        $resource= 'reports/' .$report_type;
        $method = self::HTTP_GET;
        $payload = array(
            'access_token'  => $access_token,
            'group_ids'     => $group_ids,
            'student'       => $student,
            'standard'      => $standard
        );

        return $this->_makeSnapshotApiRequest($resource, $method, $payload);

    }

    /**
     * getStandards - retrieve requested common core standards
     * @param string $access_token -- access token of the requesting user
     * @param string optional $level  -- Filter standards by a level. Defaults to snapshot all if not specified.
     * @param string optional $subject -- Filter standards by subject. "ELA" or "Math". Defaults to snapshot all if not specified.
     * @param int optional $tiny  -- Returns a more compactly formatted response if 1. Defaults to 0.
     * @return stdClass -- snapshot response object
     */

    public function getStandards($access_token, $level ='', $subject = '', $tiny = 0){
        $resource = 'standards';
        $method = self::HTTP_GET;
        $payload = array(
            'access_token'  => $access_token,
            'level'         => $level,
            'subject'       => $subject,
            'tiny'          => $tiny,
        );

        return $this->_makeSnapshotApiRequest($resource, $method, $payload);

    }

    /**
     * createQuiz -- create a snapshot quiz
     * @param string $access_token
     * @param array $group_ids  -- Required array of ints
     * @param string $grade_level  -- grade level of group
     * @param string $name -- name of snapshot
     * @param string $due_date -- date string in format "2014-04-01"
     * @param int $time_limit --  Required int in seconds
     * @param array $standard_ids -- Required array of ints
     * @param string $subject -- Required string "ELA" or "Math"
     * @param string optional $note -- message to leave with snapshot
     * @return stdClass -- snapshot response object
     */
    public function createQuiz($access_token, $group_ids, $grade_level, $name, $due_date, $time_limit, $standard_ids, $subject, $note = ''){

        $resource = 'quizzes';
        $group_ids = makeArray($group_ids);
        $standard_ids = makeArray($standard_ids);
        $method = self::HTTP_POST;
        $payload = array(
            'edmodo_group_ids'     => $group_ids,
            'grade_level'   => $grade_level,
            'name'          => $name,
            'due_date'      => $due_date,
            'time_limit'    => $time_limit,
            'standard_ids'  => $standard_ids,
            'subject'       => $subject,
            'note'          => $note
        );

        $payload = json_encode($payload);

        return $this->_makeSnapshotApiRequest($resource, $method, $payload, $access_token, true);

    }

    /**
     * private _makeSnapshotApiRequest -- makes request to Snapshot API
     * @param string $resource  -- the api call that you would like to make
     * @param string $method  -- the type of request to make ex. ('GET' or 'POST')
     * @param array/json string $payload   -- the parameters being passed to the api call
     * @param string optional $access_token  -- the access token used for authentication
     * @param bool $set_body -- whether to set a json body content or not
     * @return stdClass -- snapshot response object
     * @throws Exception
     */
    private function _makeSnapshotApiRequest($resource, $method, $payload, $access_token = '',$set_body = false){

        // make sure the endpoint option is set
        if (!isset($this->_options['endpoint']) || trim($this->_options['endpoint']) == '') {
            throw new Exception('Snapshot API endpoint not set');
        }

        $endpoint = $this->_options['endpoint'] . $resource . '.' . $this->_options['response_format'].'?access_token='.$access_token;

        // create a new cURL resource
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);


        if ($method == self::HTTP_POST) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, self::HTTP_POST);

            if ($payload) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                if ($set_body){
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                            'Content-Type: application/json',
                            'Content-Length: ' . strlen($payload))
                    );
                }
            }
        }

        else if ($method == self::HTTP_GET) {
            $query_string = http_build_query($payload);

            if (strpos($endpoint, '?') !== false) {
                $endpoint .= '&' . $query_string;
            } else {
                $endpoint .= '?' . $query_string;
            }
        } else {
            throw new Exception('Unsupported HTTP method [' . $method . ']');
        }

        // set URL and other appropriate options
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->_options['client_timeout']);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->_options['client_timeout']);

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, true);

        $raw_response = curl_exec($ch);
        $curl_response_info = curl_getinfo($ch);

        // close cURL resource, and free up system resources
        curl_close($ch);

        if (isset($curl_response_info['http_code']) && $curl_response_info['http_code'] == 200) {
            $ok = true;
        } else {
            $ok = false;
        }

        $request_response = new stdClass();
        $request_response->ok = $ok;
        $request_response->info = $curl_response_info;

        if ($this->_options['response_format'] == self::JSON) {
            $assoc = false;

            if ($this->_options['response_type'] == self::RESPONSE_TYPE_ASSOC) {
                $assoc = true;
            }

            $decoded_response = json_decode($raw_response, $assoc);
        }

        $request_response->raw_response = $raw_response;
        $request_response->response = $decoded_response;
        return $request_response;
    }


}
