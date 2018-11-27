<?php
    namespace MyApp;
    class QATallyLibrary {
        public function __construct() {
            $host = "localhost";
            $usr = "root";
            $pwd = "senslope";
            $this->initDBforCommons($host, $usr, $pwd);
        }

        public function initDBforCommons($host, $usr, $pwd) {
            $dbname = "commons_db";
            $this->dbconn = new \mysqli($host, $usr, $pwd, $dbname);
            if ($this->dbconn->connect_error) {
                die("Connection failed: " . $this->dbconn->connect_error);
            } else {
                echo "Host server: ".$host . "\n";
                echo "Connection Established for commons_db ... \n";
                return true;
            }
        }

        public function updateTally($event_id) {
            var_dump($event_id);
        }

        public function sendTallyUpdate($category, $event_id, $data_timestamp,$sent_count) {
        $event_tally_url = "http://localhost/qa_tally/update_tally";
        $data = [
            'category' => $category,
            'event_id' => $event_id,
            'data_timestamp' => $data_timestamp,
            'sent_count' => $sent_count
        ];
        $ch = curl_init($event_tally_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        $response = curl_exec($ch);
        curl_close($ch);
    }
    }
?>