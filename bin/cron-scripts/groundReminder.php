<?php
    date_default_timezone_set('Asia/Manila');
    $credentials = include('/var/www/chatterbox/utils/config.php');
    class WebsocketClient {
        private $_Socket = null;
        public function __construct($host, $port) {
            $this->_connect($host, $port);
        }
        public function __destruct() {
            $this->_disconnect();
        }
        public function sendData($data) {
            fwrite($this->_Socket, "\x00" . $data . "\xff") or die('Error:' . $errno . ':' . $errstr);
            $wsData = fread($this->_Socket, 2000);
            $retData = trim($wsData, "\x00\xff");
            return $retData;
        }
        private function _connect($host, $port) {
            $key1 = $this->_generateRandomString(32);
            $key2 = $this->_generateRandomString(32);
            $key3 = $this->_generateRandomString(8, false, true);
            $header = "GET /echo HTTP/1.1\r\n";
            $header.= "Upgrade: WebSocket\r\n";
            $header.= "Connection: Upgrade\r\n";
            $header.= "Host: " . $host . ":" . $port . "\r\n";
            $header.= "Origin: http://localhost\r\n";
            $header.= "Sec-WebSocket-Key1: " . $key1 . "\r\n";
            $header.= "Sec-WebSocket-Key2: " . $key2 . "\r\n";
            $header.= "\r\n";
            $header.= $key3;
            $this->_Socket = fsockopen($host, $port, $errno, $errstr, 2);
            fwrite($this->_Socket, $header) or die('Error: ' . $errno . ':' . $errstr);
            $response = fread($this->_Socket, 2000);
            return true;
        }
        private function _disconnect() {
            fclose($this->_Socket);
        }
        private function _generateRandomString($length = 10, $addSpaces = true, $addNumbers = true) {
            $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!"§$%&/()=[]{}';
            $useChars = array();
            // select some random chars:    
            for ($i = 0; $i < $length; $i++) {
                $useChars[] = $characters[mt_rand(0, strlen($characters) - 1)];
            }
            // add spaces and numbers:
            if ($addSpaces === true) {
                array_push($useChars, ' ', ' ', ' ', ' ', ' ', ' ');
            }
            if ($addNumbers === true) {
                array_push($useChars, rand(0, 9), rand(0, 9), rand(0, 9));
            }
            shuffle($useChars);
            $randomString = trim(implode('', $useChars));
            $randomString = substr($randomString, 0, $length);
            return $randomString;
        }
    }
    // Create connection
    $conn = new mysqli($credentials['dbcredentials']['dbhost'], $credentials['dbcredentials']['dbuser'], 
                        $credentials['dbcredentials']['dbpass'], $credentials['dbcredentials']['dbname']);
    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }  else {
        echo "Connection established.\n\n";
    }

    $check_if_settings_exists_query = "SELECT * FROM ground_meas_reminder_automation WHERE status = 0";
    $exists = $conn->query($check_if_settings_exists_query);
    if ($exists->num_rows == 0) {
        $gnd_settings = (object) array("type"=>"setUneditedGndMeasReminderSetting");
        $WebSocketClient = new WebsocketClient('localhost', 5050);
        $WebSocketClient->sendData(json_encode($gnd_settings));
    }

    $sql = "SELECT * FROM ground_meas_reminder_automation WHERE status = 0";
    $result = $conn->query($sql);
    $ground_meas_collection = [];
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            array_push($ground_meas_collection,$row);
        }
    } else {
        echo "0 results";
        return;
    }
    
    foreach ($ground_meas_collection as $details) {
        $toBeSent = (object) array(
            "type"=>"sendAutoGndMeasReminder",
            "offices"=>[$details['office_recipients']],
            "sitenames"=>[$details['site']],
            "msg"=>$details['msg'],
            "event_type"=> $details['type']
            );
        $WebSocketClient = new WebsocketClient('localhost', 5050);
        $WebSocketClient->sendData(json_encode($toBeSent));
        unset($WebSocketClient);
        $sql = "UPDATE ground_meas_reminder_automation SET status = 1 WHERE status = 0 and automation_id = '".$details['automation_id']."'";
        $result = $conn->query($sql);
        echo "Site: ".$details['site']." Status: ".$result."\n";
    }
?>
