<?php
namespace MyApp;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

require_once(__DIR__.'/../../html/ais/src/websocket/chatterbox-server/php-libraries/ais.php'); // AIS PLUGIN

class ChatMessageModel {
    protected $dbconn, $ais_instance;
    public function __construct() {
        global $ais_instance;
        $db_credentials = include(__DIR__."/../utils/config.php");
        $this->ais_instance = new \AisWebsocket();
        $this->initDBforCB($db_credentials);
        $this->switchDBforCB($db_credentials);
        date_default_timezone_set('Asia/Manila');
    }

    public function initDBforCB($credentials) {
        $this->dbconn = new \mysqli($credentials['cbx_cred']['dbhost'], $credentials['cbx_cred']['dbuser'], 
            $credentials['cbx_cred']['dbpass'], $credentials['cbx_cred']['dbnamecomms']);

        if ($this->dbconn->connect_error) {
            die("Connection failed: " . $this->dbconn->connect_error);
        } else {
            echo $credentials['cbx_cred']['dbhost'] . "\n";
            echo "Connection Established for comms_db... \n";
            return true;
        }
    }

    function switchDBforCB($credentials) {
        $this->senslope_dbconn = new \mysqli($credentials['cbx_cred']['dbhost'], $credentials['cbx_cred']['dbuser'], 
            $credentials['cbx_cred']['dbpass'], $credentials['cbx_cred']['dbnamesenslope']);

        if ($this->senslope_dbconn->connect_error) {
            die("Connection failed: " . $this->senslope_dbconn->connect_error);
        } else {
            echo $credentials['cbx_cred']['dbhost'] . "\n";
            echo "Connection Established for senslopedb... \n";
            return true;
        }
    }

    public function utf8_encode_recursive ($array) {
        $result = array();
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->utf8_encode_recursive($value);
            } else if (is_string($value)) {
                $result[$key] = utf8_encode($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    public function filterSpecialCharacters($message) {
        try {
            $filteredMsg = str_replace("\\", "\\\\", $message);
            $filteredMsg = str_replace("'", "\'", $filteredMsg);
            return $filteredMsg;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }

    }

    public function checkConnectionDB($sql = "Nothing") {
        if (!mysqli_ping($this->dbconn)) {
            echo 'Lost connection, exiting after query #1';
            $logFile = fopen("../logs/mysqlRunAwayLogs.txt", "a+");
            $t = time();
            fwrite($logFile, date("Y-m-d H:i:s") . "\n" . $sql . "\n\n");
            fclose($logFile);
            $this->initDBforCB();
        }
    }

    public function identifyMobileNetwork($contactNumber) {
        try {
            $countNum = strlen($contactNumber);
            if ($countNum == 11) {
                // echo $contactNumber . " | ";
                $curSimPrefix = substr($contactNumber, 2, 2);
                // echo $curSimPrefix . " | ";
            } elseif ($countNum == 12) {
                // echo $contactNumber . " | ";
                $curSimPrefix = substr($contactNumber, 3, 2);
                // echo $curSimPrefix . " | ";
            }

            $networkSmart = ['00','07','08','09','10','11','12','14','18','19','20','21','22','23','24','25','28','29','30','31',
            '32','33','34','38','39','40','42','43','44','46','47','48','49','50','89','98','99'];

            $networkGlobe = ['05','06','15','16','17','25','26','27','35','36','37','45','55','56','65','67','75','77','78','79','94','95','96','97'];

            if (isset($curSimPrefix) == false || is_numeric($curSimPrefix) == false) {
                return "You";
            } else {
                if (in_array($curSimPrefix,$networkSmart)) {
                    return "SMART";
                } else if (in_array($curSimPrefix,$networkGlobe)) {
                    return "GLOBE";
                } else {
                    return "UNKNOWN";
                }           
            }
        } catch (Exception $e) {
            echo "identifyMobileNetwork Exception: Unknown Network\n";
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
            return "UNKNOWN";
        }
    }

    public function convertNameToUTF8($name) {
        try {
            $converted = utf8_decode($name);
            return str_replace("?", "ñ", $converted);
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    public function getQuickInboxMain($isForceLoad=false) {
        try {
            $start = microtime(true);
            $qiResults = $this->getQuickInboxMessages();
            $execution_time = microtime(true) - $start;
            echo "\n\nExecution Time: $execution_time\n\n";

            return $qiResults;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    public function getUnregisteredInboxMain($isForceLoad=false) {
        try {
            $start = microtime(true);
            $qiResults = $this->getUnregisteredInboxMessages();
            $execution_time = microtime(true) - $start;
            echo "\n\nExecution Time: $execution_time\n\n";
            return $qiResults;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    public function getLatestAlerts(){
        try {
            $query = "SELECT * FROM sites inner join public_alert_event alerts on sites.site_id=alerts.site_id inner join public_alert_release releases on alerts.latest_release_id = releases.release_id WHERE alerts.status <> 'finished' AND alerts.status <> 'invalid' AND alerts.status <> 'routine'"; 
            $this->checkConnectionDB($query);
            $alerts = $this->senslope_dbconn->query($query);
            $fullData['type'] = 'latestAlerts';
            $raw_data = array();
            $ctr = 0;
            if ($alerts->num_rows > 0) {
                while ($row = $alerts->fetch_assoc()) {
                    $raw_data["site_id"] = $row["site_id"];
                    $raw_data["site_code"] = $row["site_code"];
                    $raw_data["sitio"] = $row["sitio"];
                    $raw_data["barangay"] = $row["barangay"];
                    $raw_data["province"] = $row["province"];
                    $raw_data["region"] = $row["region"];
                    $raw_data["municipality"] = $row["municipality"];
                    $raw_data["status"] = $row["status"];
                    $raw_data["internal_alert_level"] = $row["internal_alert_level"];
                    $fullData['data'][$ctr] = $raw_data;
                    $ctr++;
                }
            }
            else {
                echo "0 results\n";
                $fullData['data'] = null;
            }
            return $fullData;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    public function getQuickInboxMessages($periodDays = 7) {
        try {
             $get_all_sms_from_period = "SELECT * FROM (
                SELECT max(inbox_id) as inbox_id FROM (
                    SELECT smsinbox_users.inbox_id, smsinbox_users.ts_sms, smsinbox_users.mobile_id, smsinbox_users.sms_msg, smsinbox_users.read_status, smsinbox_users.web_status,smsinbox_users.gsm_id,user_mobile.sim_num, CONCAT(sites.site_code,' ',user_organization.org_name, ' - ', users.lastname, ', ', users.firstname) as full_name
                    FROM smsinbox_users INNER JOIN user_mobile ON smsinbox_users.mobile_id = user_mobile.mobile_id
                    INNER JOIN users ON user_mobile.user_id = users.user_id 
                    INNER JOIN user_organization ON users.user_id = user_organization.user_id 
                    INNER JOIN sites ON user_organization.fk_site_id = sites.site_id 
                    WHERE smsinbox_users.ts_sms > (now() - interval 7 day)
                    ) as smsinbox 
                GROUP BY full_name) as quickinbox 
            INNER JOIN (
                SELECT smsinbox_users.inbox_id, smsinbox_users.ts_sms, smsinbox_users.mobile_id, smsinbox_users.sms_msg, smsinbox_users.read_status, smsinbox_users.web_status,smsinbox_users.gsm_id,user_mobile.sim_num, CONCAT(sites.site_code,' ',user_organization.org_name, ' - ', users.lastname, ', ', users.firstname) as full_name
                FROM smsinbox_users INNER JOIN user_mobile ON smsinbox_users.mobile_id = user_mobile.mobile_id
                INNER JOIN users ON user_mobile.user_id = users.user_id 
                INNER JOIN user_organization ON users.user_id = user_organization.user_id 
                INNER JOIN sites ON user_organization.fk_site_id = sites.site_id 
                WHERE smsinbox_users.ts_sms > (now() - interval 7 day) ORDER BY smsinbox_users.ts_sms desc) as smsinbox2 
            USING(inbox_id) ORDER BY ts_sms";

            $get_all_sms_from_period_employee = "SELECT * FROM (SELECT MAX(inbox_id) AS inbox_id FROM (SELECT smsinbox_users.inbox_id,smsinbox_users.ts_sms,smsinbox_users.mobile_id,smsinbox_users.sms_msg,smsinbox_users.read_status,smsinbox_users.web_status,smsinbox_users.gsm_id,user_mobile.sim_num,
                CONCAT(dewsl_teams.team_code,' - ', users.lastname, ', ', users.firstname) AS full_name
                FROM
                    smsinbox_users
                INNER JOIN user_mobile ON smsinbox_users.mobile_id = user_mobile.mobile_id
                INNER JOIN users ON user_mobile.user_id = users.user_id
                INNER JOIN dewsl_team_members ON users.user_id = dewsl_team_members.users_users_id 
                                        INNER JOIN dewsl_teams ON dewsl_team_members.dewsl_teams_team_id = dewsl_teams.team_id
                WHERE
                    smsinbox_users.ts_sms > (NOW() - INTERVAL 7 DAY)) AS smsinbox
                GROUP BY full_name) AS quickinbox INNER JOIN (SELECT smsinbox_users.inbox_id,smsinbox_users.ts_sms,smsinbox_users.mobile_id,smsinbox_users.sms_msg,smsinbox_users.read_status,smsinbox_users.web_status,smsinbox_users.gsm_id,user_mobile.sim_num,CONCAT(dewsl_teams.team_code,' - ', users.lastname, ', ', users.firstname) AS full_name
                FROM
                    smsinbox_users
                INNER JOIN user_mobile ON smsinbox_users.mobile_id = user_mobile.mobile_id
                INNER JOIN users ON user_mobile.user_id = users.user_id
                INNER JOIN dewsl_team_members ON users.user_id = dewsl_team_members.users_users_id 
                                        INNER JOIN dewsl_teams ON dewsl_team_members.dewsl_teams_team_id = dewsl_teams.team_id
                WHERE
                    smsinbox_users.ts_sms > (NOW() - INTERVAL 7 DAY)
                ORDER BY smsinbox_users.ts_sms DESC) AS smsinbox2 USING (inbox_id)
                ORDER BY ts_sms";

            $full_query = "SELECT * FROM (".$get_all_sms_from_period.") as community UNION SELECT * FROM (".$get_all_sms_from_period_employee.") as employee ORDER BY ts_sms";
            // echo "$full_query";
            $this->checkConnectionDB($full_query);
            $sms_result_from_period = $this->dbconn->query($full_query);

            $full_data['type'] = 'smsloadquickinbox';
            $all_messages = [];
            $ctr = 0;

            $temp_senders = [];
            if ($sms_result_from_period->num_rows > 0) {
                while ($row = $sms_result_from_period->fetch_assoc()) {
                    if (in_array($row['mobile_id'], $temp_senders, TRUE) != true) {
                        $normalized_number = substr($row["sim_num"], -10);
                        $all_messages[$ctr]['sms_id'] = $row['inbox_id'];
                        $all_messages[$ctr]['full_name'] = strtoupper($row['full_name']);
                        $all_messages[$ctr]['user_number'] = $normalized_number;
                        $all_messages[$ctr]['mobile_id'] = $row['mobile_id'];
                        $all_messages[$ctr]['msg'] = $row['sms_msg'];
                        $all_messages[$ctr]['ts_received'] = $row['ts_sms'];
                        $all_messages[$ctr]['network'] = $this->identifyMobileNetwork($row['sim_num']);  
                        array_push($temp_senders, $row['mobile_id']);
                        $ctr++;
                    } 
                }

                $full_data['data'] = $all_messages;
            } else {
                echo "0 results\n";
                $full_data['data'] = null;
            }
            return $this->utf8_encode_recursive($full_data);
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    public function getUnregisteredInboxMessages($periodDays = 7) {
        try {
            $get_all_unregistered_query = "SELECT 
                                                smsinbox_users.inbox_id,
                                                CONCAT(users.lastname, ', ', users.firstname) AS full_name,
                                                user_mobile.sim_num,
                                                user_mobile.mobile_id,
                                                user_mobile.user_id,
                                                smsinbox_users.sms_msg,
                                                smsinbox_users.ts_sms
                                            FROM
                                                smsinbox_users
                                                    INNER JOIN
                                                user_mobile ON smsinbox_users.mobile_id = user_mobile.mobile_id
                                                    INNER JOIN
                                                users ON user_mobile.user_id = users.user_id
                                            WHERE
                                                smsinbox_users.ts_sms > (NOW() - INTERVAL 7 DAY)
                                                    AND users.firstname LIKE '%UNKNOWN_%' ORDER by ts_sms desc;";
            $sms_result_from_period = $this->dbconn->query($get_all_unregistered_query);
            
            $full_data['type'] = 'smsloadunregisteredinbox';
            $all_messages = [];
            $ctr = 0;
            $temp_senders = [];
            if ($sms_result_from_period->num_rows > 0) {
                while ($row = $sms_result_from_period->fetch_assoc()) {
                    if (in_array($row['mobile_id'], $temp_senders, TRUE) != true) {
                        $normalized_number = substr($row["sim_num"], -10);
                        $all_messages[$ctr]['sms_id'] = $row['inbox_id'];
                        $all_messages[$ctr]['full_name'] = strtoupper($row['full_name']);
                        $all_messages[$ctr]['user_number'] = $normalized_number;
                        $all_messages[$ctr]['mobile_id'] = $row['mobile_id'];
                        $all_messages[$ctr]['user_id'] = $row['user_id'];
                        $all_messages[$ctr]['msg'] = $row['sms_msg'];
                        $all_messages[$ctr]['ts_received'] = $row['ts_sms'];
                        $all_messages[$ctr]['network'] = $this->identifyMobileNetwork($row['sim_num']);
                        $ctr++;
                        array_push($temp_senders, $row['mobile_id']);
                    } 
                }
                $full_data['data'] = $all_messages;
            } else {
                echo "0 results\n";
                $full_data['data'] = null;
            }

            return $this->utf8_encode_recursive($full_data);
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    public function getAllUnregisteredNumber(){
        try {
             $get_all_unregistered_number = "SELECT 
                                            CONCAT(users.lastname, ', ', users.firstname) AS full_name,
                                            user_mobile.sim_num,
                                            user_mobile.mobile_id,
                                            user_mobile.user_id
                                        FROM
                                            smsinbox_users
                                                INNER JOIN
                                            user_mobile ON smsinbox_users.mobile_id = user_mobile.mobile_id
                                                INNER JOIN
                                            users ON user_mobile.user_id = users.user_id
                                        WHERE
                                            users.firstname LIKE '%UNKNOWN_%'
                                                AND user_mobile.sim_num NOT LIKE '%SMART%'
                                                AND user_mobile.sim_num NOT LIKE '%GLOBE%'
                                        GROUP BY (user_mobile.mobile_id);";
            $unregistered_result = $this->dbconn->query($get_all_unregistered_number);
            $full_data['type'] = 'allUnregisteredNumbers';
            $all_unregistered = [];
            $counter = 0;
            if($unregistered_result->num_rows > 0){
                while ($row = $unregistered_result->fetch_assoc()) {
                    $normalized_number = "63".substr($row["sim_num"], -10);
                    $all_unregistered[$counter]['unknown_label'] = strtoupper($row['full_name']);
                    $all_unregistered[$counter]['user_number'] = $normalized_number;
                    $all_unregistered[$counter]['mobile_id'] = $row['mobile_id'];
                    $all_unregistered[$counter]['user_id'] = $row['user_id'];
                    $counter++;
                }
                $full_data['data'] = $all_unregistered;
            }   else {
                echo "0 results\n";
                $full_data['data'] = null;
            }

            return $this->utf8_encode_recursive($full_data);
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    public function getFullnamesAndNumbers() {
        try {
            $get_full_names_query = "SELECT * FROM (SELECT UPPER(CONCAT(sites.site_code,' ',user_organization.org_name,' ',users.salutation,' ',users.firstname,' ',users.lastname)) as fullname,user_mobile.sim_num as number FROM users INNER JOIN user_organization ON user_organization.user_id = users.user_id LEFT JOIN user_mobile ON user_mobile.user_id = users.user_id LEFT JOIN sites ON user_organization.fk_site_id = sites.site_id) as fullcontact UNION SELECT * FROM (SELECT UPPER(CONCAT(dewsl_teams.team_code,' ',users.salutation,' ',users.firstname,' ',users.lastname)) as fullname,user_mobile.sim_num as number FROM users INNER JOIN user_mobile ON user_mobile.user_id = users.user_id LEFT JOIN dewsl_team_members ON dewsl_team_members.users_users_id = users.user_id LEFT JOIN dewsl_teams ON dewsl_teams.team_id = dewsl_team_members.dewsl_teams_team_id) as fullcontact;";
            $this->checkConnectionDB($get_full_names_query);
            $result = $this->dbconn->query($get_full_names_query);
            $ctr = 0;
            $dbreturn = "";
            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $dbreturn[$ctr]['fullname'] = $row['fullname'];
                    $dbreturn[$ctr]['number'] = $row['number'];
                    $ctr++;
                }
                return $dbreturn;
            } else {
                echo "0 results\n";
            }
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    public function getContactSuggestions($queryName = "") {
        try {
            $sql = "SELECT 
                        *
                    FROM
                        (SELECT 
                            UPPER(CONCAT(sites.site_code, ' ', user_organization.org_name, ' - ', users.lastname, ', ', users.firstname)) AS fullname,
                                user_mobile.sim_num AS number,
                                users.user_id AS id,
                                contact_hierarchy.priority,
                                user_mobile.mobile_status AS status, users.status as user_status
                        FROM
                            users
                        INNER JOIN user_organization ON users.user_id = user_organization.user_id
                        LEFT JOIN contact_hierarchy ON contact_hierarchy.fk_user_id = users.user_id
                        RIGHT JOIN sites ON sites.site_id = user_organization.fk_site_id
                        RIGHT JOIN user_mobile ON user_mobile.user_id = users.user_id UNION SELECT 
                            UPPER(CONCAT(dewsl_teams.team_name, ' - ', users.salutation, ' ', users.lastname, ', ', users.firstname)) AS fullname,
                                user_mobile.sim_num AS number,
                                users.user_id AS id,
                                contact_hierarchy.priority,
                                user_mobile.mobile_status AS status, users.status as user_status
                        FROM
                            users
                        INNER JOIN dewsl_team_members ON users.user_id = dewsl_team_members.users_users_id
                        LEFT JOIN contact_hierarchy ON contact_hierarchy.fk_user_id = users.user_id
                        RIGHT JOIN dewsl_teams ON dewsl_team_members.dewsl_teams_team_id = dewsl_teams.team_id
                        RIGHT JOIN user_mobile ON user_mobile.user_id = users.user_id) AS fullcontact
                    WHERE
                        status = 1 and user_status = 1 and (fullname LIKE '%$queryName%' or id LIKE '%$queryName%')";

            $this->checkConnectionDB($sql);
            $result = $this->dbconn->query($sql);

            $ctr = 0;
            $dbreturn = "";
            $fullData['type'] = 'loadnamesuggestions';

            if ($result->num_rows > 0) {
                $fullData['total'] = $result->num_rows;
                while ($row = $result->fetch_assoc()) {
                    $dbreturn[$ctr]['fullname'] = $this->convertNameToUTF8($row['fullname']);
                    $dbreturn[$ctr]['id'] = $row['id'];
                    $dbreturn[$ctr]['number'] = $row['number'];
                    $dbreturn[$ctr]['priority'] = $row['priority'];
                    $dbreturn[$ctr]['status'] = $row['status'];
                    $ctr = $ctr + 1;
                }

                $dbreturn = $this->utf8_encode_recursive($dbreturn);
                $fullData['data'] = $dbreturn;
            }
            else {
                echo "0 results\n";
                $fullData['data'] = null;
            }
            return $fullData;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    public function getAllCmmtyContacts() {
        try {
            $this->checkConnectionDB();
            $returnCmmtyContacts = [];
            $returnData = [];
            $ctr = 0;
            // $query = "SELECT DISTINCT users.user_id,users.firstname,users.lastname,users.middlename,users.salutation,users.status FROM users INNER JOIN user_organization ON users.user_id = user_organization.user_id;"; OLD QUERY
            $query = "SELECT DISTINCT
                        users.user_id,
                        users.firstname,
                        users.lastname,
                        users.middlename,
                        users.salutation,
                        users.status,
                        UPPER(user_organization.org_name) as org_name,
                        UPPER(senslopedb.sites.site_code) as site_code,
                        user_mobile.sim_num as mobile_number
                    FROM
                        users
                            INNER JOIN
                        user_organization ON users.user_id = user_organization.user_id
                            INNER JOIN
                        user_mobile ON users.user_id = user_mobile.user_id
                            INNER JOIN
                        senslopedb.sites ON senslopedb.sites.site_id = user_organization.fk_site_id;";

            $result = $this->dbconn->query($query);
            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $returnCmmtyContacts[$ctr]['user_id'] = $row['user_id'];
                    $returnCmmtyContacts[$ctr]['firstname'] = $row['firstname'];
                    $returnCmmtyContacts[$ctr]['lastname'] = $row['lastname'];
                    $returnCmmtyContacts[$ctr]['site_code'] = $row['site_code'];
                    $returnCmmtyContacts[$ctr]['org_name'] = $row['org_name'];
                    $returnCmmtyContacts[$ctr]['mobile_number'] = $row['mobile_number'];
                    if($row['status'] == 1){
                        $status = "Active";
                    }else{
                        $status = "Inactive";
                    }
                    $returnCmmtyContacts[$ctr]['active_status'] = $status;
                    $ctr++;
                }
            } else {
                echo "No results..";
            }
            $returnData['type'] = 'fetchedCmmtyContacts';
            $returnData['data'] = $returnCmmtyContacts;

            return $this->utf8_encode_recursive($returnData);
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    public function getAllDwslContacts() {
        try {
             $this->checkConnectionDB();
            $returnDwslContacts = [];
            $finContact = [];
            $returnTeams = [];
            $returnData = [];
            $ctr = 0;
            $query = "SELECT * FROM users INNER JOIN dewsl_team_members ON users.user_id = dewsl_team_members.users_users_id INNER JOIN dewsl_teams ON dewsl_team_members.dewsl_teams_team_id = dewsl_teams.team_id";
            $result = $this->dbconn->query($query);
            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $returnDwslContacts[$ctr]['user_id'] = $row['user_id'];
                    $returnDwslContacts[$ctr]['salutation'] = $row['salutation'];
                    $returnDwslContacts[$ctr]['firstname'] = $row['firstname'];
                    $returnDwslContacts[$ctr]['lastname'] = $row['lastname'];
                    $returnDwslContacts[$ctr]['middlename'] = $row['middlename'];
                    $returnDwslContacts[$ctr]['active_status'] = $row['status'];
                    $returnTeams[$ctr]['team'] = $row['team_name'];
                    $ctr++;
                }
            } else {
                echo "No results..";
            }

            for ($x = 0; $x < $ctr; $x++) {
                if (!in_array($returnDwslContacts[$x],$finContact)) {
                    array_push($finContact,$returnDwslContacts[$x]);

                }
            }

            for ($x = 0; $x < sizeof($finContact); $x++) {
                $finContact[$x]['team'] = "";
            }

            for ($x = 0; $x < $ctr; $x++) {
                for ($y = 0; $y < sizeof($finContact); $y++) {
                    if ($finContact[$y]['user_id'] == $returnDwslContacts[$x]['user_id']) {
                        $finContact[$y]['team'] = ltrim($finContact[$y]['team'].",".$returnTeams[$x]['team'],',');
                    }
                }
            }

            $returnData['type'] = 'fetchedDwslContacts';
            $returnData['data'] = $finContact;
            return $this->utf8_encode_recursive($returnData);
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    public function getDwslContact($id) {
        try {
             $returnData = [];
            $returnContact = [];
            $returnMobileNumbers = [];
            $returnLandlineNumbers = [];
            $returnEmail = [];
            $returnTeam = [];
            $ctr = 0;
            $this->checkConnectionDB();
            $query = "SELECT users.user_id as id,users.salutation,users.firstname,users.middlename,users.lastname,users.nickname,users.birthday,users.sex,users.status,user_mobile.mobile_id,user_mobile.user_id,user_mobile.sim_num,user_mobile.priority,user_mobile.mobile_status,user_landlines.landline_id,user_landlines.landline_num,user_landlines.user_id,user_landlines.landline_num,user_landlines.remarks as landline_remarks,dewsl_team_members.members_id,dewsl_team_members.users_users_id,dewsl_team_members.dewsl_teams_team_id,dewsl_teams.team_id,dewsl_teams.team_name,dewsl_teams.remarks, user_emails.email_id,user_emails.email FROM users LEFT JOIN user_mobile ON users.user_id = user_mobile.user_id LEFT JOIN user_landlines ON users.user_id = user_landlines.user_id LEFT JOIN dewsl_team_members ON users.user_id = dewsl_team_members.users_users_id LEFT JOIN dewsl_teams ON dewsl_team_members.dewsl_teams_team_id = dewsl_teams.team_id LEFT JOIN user_emails ON users.user_id = user_emails.user_id WHERE users.user_id = '$id' order by lastname desc;";
            $result = $this->dbconn->query($query);
            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    if (empty($returnContact)) {
                        $returnContact['id'] = $row['id'];
                        $returnContact['salutation'] = $row['salutation'];
                        $returnContact['firstname'] = $row['firstname'];
                        $returnContact['lastname'] = $row['lastname'];
                        $returnContact['middlename'] = $row['middlename'];
                        $returnContact['nickname'] = $row['nickname'];
                        $returnContact['gender'] = $row['sex'];
                        $returnContact['birthday'] = $row['birthday'];
                        $returnContact['contact_active_status'] = $row['status'];
                        $returnMobileNumbers[$ctr]['number_id'] = $row['mobile_id'];
                        $returnMobileNumbers[$ctr]['number'] = $row['sim_num'];
                        $returnMobileNumbers[$ctr]['priority'] = $row['priority'];
                        $returnMobileNumbers[$ctr]['number_status'] = $row['mobile_status'];
                        $returnLandlineNumbers[$ctr]['landline_id'] = $row['landline_id'];
                        $returnLandlineNumbers[$ctr]['landline_number'] = $row['landline_num'];
                        $returnLandlineNumbers[$ctr]['landline_remarks'] = $row['landline_remarks'];
                        $returnTeam[$ctr]['member_id'] = $row['members_id'];
                        $returnTeam[$ctr]['team_id'] = $row['team_id'];
                        $returnTeam[$ctr]['team_ref_id'] = $row['dewsl_teams_team_id'];
                        $returnTeam[$ctr]['team_name'] = $row['team_name'];
                        $returnEmail[$ctr]['email_id'] = $row['email_id'];
                        $returnEmail[$ctr]['email'] = $row['email'];
                        $ctr++;
                    } else {
                        $returnMobileNumbers[$ctr]['number_id'] = $row['mobile_id'];
                        $returnMobileNumbers[$ctr]['number'] = $row['sim_num'];
                        $returnMobileNumbers[$ctr]['priority'] = $row['priority'];
                        $returnMobileNumbers[$ctr]['number_status'] = $row['mobile_status'];
                        $returnLandlineNumbers[$ctr]['landline_id'] = $row['landline_id'];
                        $returnLandlineNumbers[$ctr]['landline_number'] = $row['landline_num'];
                        $returnLandlineNumbers[$ctr]['landline_remarks'] = $row['landline_remarks'];
                        $returnTeam[$ctr]['member_id'] = $row['members_id'];
                        $returnTeam[$ctr]['team_id'] = $row['team_id'];
                        $returnTeam[$ctr]['team_ref_id'] = $row['dewsl_teams_team_id'];
                        $returnTeam[$ctr]['team_name'] = $row['team_name'];
                        $returnEmail[$ctr]['email_id'] = $row['email_id'];
                        $returnEmail[$ctr]['email'] = $row['email'];
                        $ctr++;
                    }
                }
            } else {
                echo "No results..";
            }

            $finLandline = [];
            $finMobile = [];
            $finTeam = [];
            $finEmail = [];
            for ($x=0; $x < $ctr; $x++) {
                if (!in_array($returnMobileNumbers[$x],$finMobile)) {
                    array_push($finMobile,$returnMobileNumbers[$x]);
                }

                if (!in_array($returnLandlineNumbers[$x], $finLandline)) {
                    array_push($finLandline, $returnLandlineNumbers[$x]);
                }

                if (!in_array($returnTeam[$x], $finTeam)) {
                    array_push($finTeam,$returnTeam[$x]);
                }

                if (!in_array($returnEmail[$x], $finEmail)) {
                    array_push($finEmail,$returnEmail[$x]);
                }
            }

            $returnData['contact_info'] = $returnContact;
            $returnData['email_data'] = $finEmail;
            $returnData['mobile_data'] = $finMobile;
            $returnData['landline_data'] = $finLandline;
            $returnData['team_data'] = $finTeam;
            $returnObj['data'] = $returnData;
            $returnObj['type'] = "fetchedSelectedDwslContact";

            return $returnObj;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    public function getCmmtyContact($id) {
        try {
            $returnData = [];
            $returnContact = [];
            $returnMobile = [];
            $returnLandline = [];
            $returnEwiStatus = [];
            $returnOrg = [];
            $ctr = 0;
            $site_id = null;
            $this->checkConnectionDB();

            $if_ewi_updated = "SELECT * FROM user_ewi_status WHERE users_id = '$id'";
            $ewi_update = $this->dbconn->query($if_ewi_updated);
            $query = "SELECT users.user_id,users.salutation,users.firstname,users.middlename,users.lastname,users.nickname,users.birthday,users.sex,users.status as active_status,user_organization.org_id,user_organization.org_name,user_organization.scope,organization.org_id as organization_id,user_mobile.mobile_id,user_mobile.sim_num,user_mobile.priority,user_mobile.mobile_status,user_landlines.landline_id,user_landlines.landline_num,user_landlines.remarks,sites.site_id,sites.site_code,sites.psgc_source,user_ewi_status.mobile_id as ewi_mobile_id,user_ewi_status.status as ewi_status,user_ewi_status.remarks as ewi_remarks FROM users INNER JOIN user_organization ON users.user_id = user_organization.user_id LEFT JOIN user_ewi_status ON user_ewi_status.users_id = users.user_id LEFT JOIN organization ON user_organization.org_name = organization.org_name LEFT JOIN user_mobile ON user_mobile.user_id = users.user_id LEFT JOIN user_landlines ON user_landlines.user_id = users.user_id LEFT JOIN user_emails ON user_emails.user_id = users.user_id LEFT JOIN sites ON sites.site_id = user_organization.fk_site_id WHERE users.user_id = '$id' order by lastname desc;";

            $result = $this->dbconn->query($query);

            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    if (empty($returnContact)) {
                        $returnContact['id'] = $row['user_id'];
                        $returnContact['salutation'] = $row['salutation'];
                        $returnContact['firstname'] = $row['firstname'];
                        $returnContact['lastname'] = $row['lastname'];
                        $returnContact['middlename'] = $row['middlename'];
                        $returnContact['nickname'] = $row['nickname'];
                        $returnContact['gender'] = $row['sex'];
                        $returnContact['birthday'] = $row['birthday'];
                        $returnContact['contact_active_status'] = $row['active_status'];
                        $returnMobile[$ctr]['number_id'] = $row['mobile_id'];
                        $returnMobile[$ctr]['number'] = $row['sim_num'];
                        $returnMobile[$ctr]['priority'] = $row['priority'];
                        $returnMobile[$ctr]['number_status'] = $row['mobile_status'];
                        $returnLandline[$ctr]['landline_id'] = $row['landline_id'];
                        $returnLandline[$ctr]['landline_number'] = $row['landline_num'];
                        $returnLandline[$ctr]['landline_remarks'] = $row['remarks'];
                        $returnEwiStatus[$ctr]['ewi_mobile_id'] = $row['ewi_mobile_id'];
                        $returnEwiStatus[$ctr]['ewi_status'] = $row['ewi_status'];
                        $returnEwiStatus[$ctr]['ewi_remarks'] = $row['ewi_remarks'];
                        $returnOrg[$ctr]['org_id'] = $row['org_id'];
                        $returnOrg[$ctr]['organization_id'] = $row['organization_id'];
                        $returnOrg[$ctr]['org_name'] = strtoupper($row['org_name']);
                        $returnOrg[$ctr]['org_scope'] = $row['scope'];
                        $returnOrg[$ctr]['site_code'] = strtoupper($row['site_code']);
                        $returnOrg[$ctr]['org_psgc_source'] = $row['psgc_source'];
                        $ctr++;
                    } else {
                        $returnMobile[$ctr]['number_id'] = $row['mobile_id'];
                        $returnMobile[$ctr]['number'] = $row['sim_num'];
                        $returnMobile[$ctr]['priority'] = $row['priority'];
                        $returnMobile[$ctr]['number_status'] = $row['mobile_status'];
                        $returnLandline[$ctr]['landline_id'] = $row['landline_id'];
                        $returnLandline[$ctr]['landline_number'] = $row['landline_num'];
                        $returnLandline[$ctr]['landline_remarks'] = $row['remarks'];
                        $returnEwiStatus[$ctr]['ewi_mobile_id'] = $row['ewi_mobile_id'];
                        $returnEwiStatus[$ctr]['ewi_status'] = $row['ewi_status'];
                        $returnEwiStatus[$ctr]['ewi_remarks'] = $row['ewi_remarks'];
                        $returnOrg[$ctr]['org_users_id'] = $row['org_id'];
                        $returnOrg[$ctr]['org_id'] = $row['organization_id'];
                        $returnOrg[$ctr]['org_name'] = strtoupper($row['org_name']);
                        $returnOrg[$ctr]['org_scope'] = $row['scope'];
                        $returnOrg[$ctr]['site_code'] = strtoupper($row['site_code']);
                        $returnOrg[$ctr]['org_psgc_source'] = $row['psgc_source'];
                        $ctr++;
                    }

                    if($site_id == null){
                        $site_id = $row['site_id'];
                    }
                }
            } else {
                echo "No results..";
            }

            $finMobile = [];
            $finLandline = [];
            $finOrg = [];
            $finSite = [];
            $finEwi = [];

            for ($x = 0; $x < $ctr; $x++) {
                if (!in_array($returnMobile[$x],$finMobile)) {
                    array_push($finMobile,$returnMobile[$x]);
                }

                if (!in_array($returnLandline[$x],$finLandline)) {
                    array_push($finLandline,$returnLandline[$x]);
                }

                if (!in_array($returnOrg[$x],$finOrg)) {
                    array_push($finOrg,$returnOrg[$x]);
                }

                if (!in_array($returnEwiStatus[$x],$finEwi)) {
                    array_push($finEwi,$returnEwiStatus[$x]);
                }
            }
            $returnData['contact_info'] = $returnContact;
            $returnData['mobile_data'] = $finMobile;
            $returnData['landline_data'] = $finLandline;
            $returnData['ewi_data'] = $finEwi;
            $returnData['org_data'] = $finOrg;
            $returnData['site_id'] = $site_id;
            $returnData['has_hierarchy'] = $this->getContactPriority($id,$site_id);
            $returnData['list_of_sites'] = $this->getAllSites();
            $returnData['list_of_orgs'] = $this->getAllOrganization();
            $returnObj['data'] = $returnData;
            $returnObj['type'] = "fetchedSelectedCmmtyContact";
            return $returnObj;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function getContactPriority($id){
        try {
            $query = "SELECT * FROM contact_hierarchy WHERE fk_user_id = '$id';";
            $result = $this->dbconn->query($query);
            $hierarchy_data = [];
            $ctr = 0;
            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $hierarchy_data[$ctr] = $row;
                    $ctr++;
                }
                return $hierarchy_data;
            }else{
                return false;
            }
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function insertInitialHierarchy($site_id, $user_id, $org_data){
        try {
            $org_names = join("','",$org_data);
            $select_query = "SELECT * FROM user_organization WHERE user_id = $user_id AND fk_site_id = $site_id LIMIT 1;";
            $select_result = $this->dbconn->query($select_query);
            $org_id = null;
            $last_inserted_id = 0;

            if($org_id == null ){
                $row = $select_result->fetch_assoc();
                $org_id = $row['org_id'];

                $query = "SELECT contact_hierarchy.contact_hierarchy_id,contact_hierarchy.fk_user_id,contact_hierarchy.fk_user_organization_id,contact_hierarchy.fk_site_id,contact_hierarchy.priority,UPPER(user_organization.org_name) AS org_name FROM contact_hierarchy JOIN user_organization ON user_organization.org_id = contact_hierarchy.fk_user_organization_id WHERE contact_hierarchy.fk_site_id = $site_id AND org_name IN ('$org_names') ORDER BY priority DESC LIMIT 1;";
                $query_result = $this->dbconn->query($query);

                if($query_result->num_rows > 0){
                    while ($row = $query_result->fetch_assoc()) {
                        $latest_priority = $row['priority'] + 1;
                        $insert_with_priority_query = "INSERT INTO contact_hierarchy VALUES(0,'$user_id','$org_id', $site_id, $latest_priority);";
                        $insert_with_priority_result = $this->dbconn->query($insert_with_priority_query);
                    }
                }else{
                    $insert_query = "INSERT INTO contact_hierarchy VALUES(0,'$user_id','$org_id', $site_id,1);";
                    $insert_result = $this->dbconn->query($insert_query);
                }
            }
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function getSiteContactHierarchy($site_id, $user_id, $org_data){
        try {
            $org_names = join("','",$org_data);
            $contact_hierarchy = [];
            $full_data['type'] = 'fetchContactHierarchy';
            $counter = 0;
            $query = "SELECT contact_hierarchy.contact_hierarchy_id, contact_hierarchy.fk_user_organization_id, user_organization.fk_site_id, user_organization.user_id, contact_hierarchy.priority, user_organization.org_name, users.firstname AS first_name, users.lastname AS last_name, UPPER(sites.site_code) as site_code FROM contact_hierarchy JOIN user_organization ON user_organization.org_id = contact_hierarchy.fk_user_organization_id JOIN users ON users.user_id = contact_hierarchy.fk_user_id JOIN sites ON sites.site_id = user_organization.fk_site_id WHERE user_organization.fk_site_id = $site_id AND user_organization.org_name IN ('$org_names');";
            $result = $this->dbconn->query($query);

            if($result->num_rows > 0){
                while ($row = $result->fetch_assoc()) {
                    $contact_hierarchy[$counter]['contact_hierarchy_id'] = $row['contact_hierarchy_id'];
                    $contact_hierarchy[$counter]['user_organization_id'] = $row['fk_user_organization_id'];
                    $contact_hierarchy[$counter]['site_id'] = $row['fk_site_id'];
                    $contact_hierarchy[$counter]['priority'] = $row['priority'];
                    $contact_hierarchy[$counter]['org_name'] = $row['org_name'];
                    $contact_hierarchy[$counter]['first_name'] = $row['first_name'];
                    $contact_hierarchy[$counter]['last_name'] = $row['last_name'];
                    $contact_hierarchy[$counter]['site_code'] = $row['site_code'];
                    $counter++;
                }
                $full_data['data'] = $contact_hierarchy;
                echo "has existing!";
            }   else {
                echo "0 results\n";
                $full_data['data'] = null;
            }

            return $this->utf8_encode_recursive($full_data);
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function getUnregisteredNumber($id){
        try {
            $get_unregistered_number = "SELECT * FROM user_mobile WHERE user_id = $id";
            $unregistered_result = $this->dbconn->query($get_unregistered_number);
            $full_data['type'] = 'unregisteredNumber';
            $unregistered = [];
            $counter = 0;

            if($unregistered_result->num_rows > 0){
                while ($row = $unregistered_result->fetch_assoc()) {
                    $normalized_number = substr($row["sim_num"], -10);
                    $unregistered[$counter]['user_number'] = $normalized_number;
                    $unregistered[$counter]['mobile_id'] = $row['mobile_id'];
                    $unregistered[$counter]['user_id'] = $row['user_id'];
                    $unregistered[$counter]['mobile_status'] = $row['mobile_status'];
                    $unregistered[$counter]['priority'] = $row['priority'];
                    $counter++;
                }
                $full_data['data'] = $unregistered;
            }   else {
                echo "0 results\n";
                $full_data['data'] = null;
            }

            return $this->utf8_encode_recursive($full_data);
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    public function updateContactHierarchy($data){
        try {
            foreach ($data as $row) {
                $query = "UPDATE contact_hierarchy SET priority='$row->hierarchy_priority' WHERE contact_hierarchy_id = $row->hierarchy_id;";
                $execute_query = $this->dbconn->query($query);
            }
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    public function updateDwslContact($data) {
        try {
            $query_contact_info = "UPDATE users SET firstname='$data->firstname',lastname='$data->lastname',middlename='$data->middlename',salutation='$data->salutation',birthday='$data->birthdate',sex='$data->gender',status=$data->contact_active_status WHERE user_id = $data->id;";
            $result = $this->dbconn->query($query_contact_info);
            if ($result == true) {
                $flag = true;
                $emails = explode(',',$data->email_address);
                $teams = explode(',',$data->teams);
                $remove_email = "DELETE FROM user_emails WHERE user_id='$data->id'";
                $result = $this->dbconn->query($remove_email);
                if ($emails[0] != "") {
                    for ($counter = 0; $counter < sizeof($emails); $counter++) {
                        try {
                            $insert_new_emails = "INSERT INTO user_emails VALUES(0,'$data->id','$emails[$counter]')";
                            $result = $this->dbconn->query($insert_new_emails);
                        } catch (Exception $e) {
                            $flag = false;
                        }
                    }
                }

                try {
                    $remove_teams = "DELETE FROM dewsl_team_members WHERE users_users_id='$data->id'";
                    $result = $this->dbconn->query($remove_teams);
                } catch (Exception $e) {
                    $flag = false;
                }

                if ($teams[0] != "") {
                    for ($counter = 0; $counter < sizeof($teams); $counter++) {
                        $check_if_existing = "SELECT * FROM dewsl_teams WHERE team_name ='$teams[$counter]'";
                        $result = $this->dbconn->query($check_if_existing);
                        if ($result->num_rows == 0) {
                            $insert_new_teams = "INSERT INTO dewsl_teams VALUES (0,'$teams[$counter]','')";
                            $result = $this->dbconn->query($insert_new_teams);
                            $newly_added_team = "SELECT * FROM dewsl_teams WHERE team_name ='$teams[$counter]'";
                            $result = $this->dbconn->query($newly_added_team);
                            $team_details = $result->fetch_assoc();
                            $insert_team_member = "INSERT INTO dewsl_team_members VALUES (0,'$data->id','".$team_details['team_id']."')";
                            $result = $this->dbconn->query($insert_team_member);
                        } else {
                            $team_details = $result->fetch_assoc();
                            $insert_team_member = "INSERT INTO dewsl_team_members VALUES (0,'$data->id','".$team_details['team_id']."')";
                            $result = $this->dbconn->query($insert_team_member);
                        }
                    }
                }

                if (sizeof($data->numbers) == 0) {
                    try {
                        $num_exist = "DELETE FROM user_mobile WHERE user_id='".$data->id."'";
                        $result = $this->dbconn->query($num_exist);
                    } catch (Exception $e) {
                        $flag = false;
                    }
                } else {
                    for ($num_counter = 0; $num_counter < sizeof($data->numbers); $num_counter++) {
                        
                        // Get GSM ID
                        $mobile_gsm_id = $this->identifyGSMIDFromMobileNumber($data->numbers[$num_counter]->mobile_number);

                        if ($data->numbers[$num_counter]->mobile_id != "" && $data->numbers[$num_counter]->mobile_number != "") {
                            try {
                                $num_exist = "UPDATE user_mobile SET sim_num = '".$data->numbers[$num_counter]->mobile_number."',priority = '".$data->numbers[$num_counter]->mobile_priority."',mobile_status = '".$data->numbers[$num_counter]->mobile_status."',gsm_id = '".$mobile_gsm_id."' WHERE mobile_id='".$data->numbers[$num_counter]->mobile_id."'";
                                $result = $this->dbconn->query($num_exist);
                            } catch (Exception $e) {
                                $flag = false;
                            }
                        } else if ($data->numbers[$num_counter]->mobile_number == "") {
                            try {
                                $num_exist = "DELETE FROM user_mobile WHERE mobile_id='".$data->numbers[$num_counter]->mobile_id."'";
                                $result = $this->dbconn->query($num_exist);
                            } catch (Exception $e) {
                                $flag = false;
                            }
                        } else {
                            try {
                                $new_num = "INSERT INTO user_mobile VALUES (0,'$data->id','".$data->numbers[$num_counter]->mobile_number."','".$data->numbers[$num_counter]->mobile_priority."','".$data->numbers[$num_counter]->mobile_status."','".$mobile_gsm_id."')";
                                $result = $this->dbconn->query($new_num);
                            } catch (Exception $e) {
                                $flag = false;
                            }
                        }
                    }
                }

                if ($flag == false) {
                    $return_data['return_msg'] = "Error occured, please refresh the page and try again.";
                } else {
                    $return_data['return_msg'] = "Successfully updated contact.";
                }
                $return_data['status'] = $flag;
            } else {
                $return_data['status'] = $result;
                $return_data['return_msg'] = "Contact update failed, Please recheck inputs.";

            }
            $return_data['type'] = "updatedDwslContact";
            return $return_data;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    public function updateCmmtyContact($data) {
        $flag = true;
        $query_contact_info = "UPDATE users SET firstname='$data->firstname',lastname='$data->lastname',middlename='$data->middlename',nickname='$data->nickname',salutation='$data->salutation',birthday='$data->birthdate',sex='$data->gender',status=$data->contact_active_status WHERE user_id = $data->user_id;";
        $result = $this->dbconn->query($query_contact_info);
        if ($result == true) {
            if (sizeof($data->numbers) == 0) {  
                try {
                    $num_exist = "DELETE FROM user_mobile WHERE user_id='".$data->user_id."'";
                    $result = $this->dbconn->query($num_exist);
                } catch (Exception $e) {
                    $flag = false;
                    echo $e->getMessage();
                    $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
                    $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
                }
            } else {
                for ($num_counter = 0; $num_counter < sizeof($data->numbers); $num_counter++) {

                    if ($data->numbers[$num_counter]->mobile_id != "" && $data->numbers[$num_counter]->mobile_number != "") {
                        // NUMBER ALREADY EXISTS
                        echo "\nThis number already exists:";
                        echo $data->numbers[$num_counter]->mobile_number;
                        echo "\n";                        
                        try {
                            // Get GSM ID
                            $mobile_gsm_id = $this->identifyGSMIDFromMobileNumber($data->numbers[$num_counter]->mobile_number);
                            $num_exist = "UPDATE user_mobile SET sim_num = '".$data->numbers[$num_counter]->mobile_number."',priority = '".$data->numbers[$num_counter]->mobile_priority."',mobile_status = '".$data->numbers[$num_counter]->mobile_status."',gsm_id = '".$mobile_gsm_id."' WHERE mobile_id='".$data->numbers[$num_counter]->mobile_id."'";
                            $result = $this->dbconn->query($num_exist);

                            /* Update user_ewi_status */
                            if ($data->ewi_recipient == "" || $data->ewi_recipient == 0) {
                                try {
                                    $update_existing = "UPDATE user_ewi_status SET status='0', remarks='Inactive' WHERE users_id = '".$data->user_id."' AND mobile_id='".$data->numbers[$num_counter]->mobile_id."'";
                                    $result = $this->dbconn->query($update_existing);
                                } catch (Exception $e) {
                                    $flag = false;
                                    echo $e->getMessage();
                                    $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
                                    $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
                                }
                            } else {
                                try {
                                    $update_existing = "UPDATE user_ewi_status SET status='1', remarks='Active' WHERE users_id = '".$data->user_id."' AND mobile_id='".$data->numbers[$num_counter]->mobile_id."'";
                                    $result = $this->dbconn->query($update_existing);
                                } catch (Exception $e) {
                                    $flag = false;
                                    echo $e->getMessage();
                                    $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
                                    $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
                                }
                            }

                        } catch (Exception $e) {
                            $flag = false;
                            echo $e->getMessage();
                            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
                            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
                        }
                    } else if ($data->numbers[$num_counter]->mobile_number == "") {                    
                        try {
                            $num_exist = "DELETE FROM user_mobile WHERE mobile_id='".$data->numbers[$num_counter]->mobile_id."'";
                            $result = $this->dbconn->query($num_exist);

                            $last_insert_mobile_id = $this->getLastInsertID();

                            if ($data->ewi_recipient == "") {
                                try {
                                    $num_exist = "DELETE FROM user_ewi_status WHERE mobile_id='".$data->numbers[$num_counter]->mobile_id."'";
                                    $result = $this->dbconn->query($num_exist);
                                } catch (Exception $e) {
                                    $flag = false;
                                    echo $e->getMessage();
                                    $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
                                    $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
                                }
                            } else {
                                try {
                                    $num_exist = "DELETE FROM user_ewi_status WHERE mobile_id='".$data->numbers[$num_counter]->mobile_id."'";
                                    $result = $this->dbconn->query($num_exist);
                                } catch (Exception $e) {
                                    $flag = false;
                                    echo $e->getMessage();
                                    $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
                                    $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
                                }
                            }
                        } catch (Exception $e) {
                            $flag = false;
                            echo $e->getMessage();
                            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
                            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
                        }
                    } else {                      
                        try {
                            // Get GSM ID
                            $mobile_gsm_id = $this->identifyGSMIDFromMobileNumber($data->numbers[$num_counter]->mobile_number);
                            $new_num = "INSERT INTO user_mobile VALUES (0,'$data->user_id','".$data->numbers[$num_counter]->mobile_number."','".$data->numbers[$num_counter]->mobile_priority."','".$data->numbers[$num_counter]->mobile_status."','".$mobile_gsm_id."')";
                            $result = $this->dbconn->query($new_num);

                            $last_insert_mobile_id = $this->getLastInsertID();

                            if ($data->ewi_recipient == "") {
                                try {
                                    $insert_ewi_status = "INSERT INTO user_ewi_status VALUES ('".$last_insert_mobile_id."','0','Inactive','".$data->user_id."')";
                                    $result = $this->dbconn->query($insert_ewi_status);
                                } catch (Exception $e) {
                                    $flag = false;
                                    echo $e->getMessage();
                                    $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
                                    $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
                                }
                            } else {
                                try {
                                    $insert_ewi_status = "INSERT INTO user_ewi_status VALUES ('".$last_insert_mobile_id."','".$data->numbers[$num_counter]->mobile_status."','Active','".$data->user_id."')";
                                    $result = $this->dbconn->query($insert_ewi_status);
                                } catch (Exception $e) {
                                    $flag = false;
                                    echo $e->getMessage();
                                    $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
                                    $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
                                }
                            }

                        } catch (Exception $e) {
                            $flag = false;
                            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
                            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
                            echo $e->getMessage();
                        }
                    }
                }
            }

            /* Update user_landlines */
            if (sizeof($data->landline) == 0) {
                try {
                    $landline_exist = "DELETE FROM user_landlines WHERE user_id='".$data->user_id."'";
                    $result = $this->dbconn->query($landline_exist);
                } catch (Exception $e) {
                    $flag = false;
                    $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
                    $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
                    echo $e->getMessage();
                }
            } else {
                for ($landline_counter = 0; $landline_counter < sizeof($data->landline); $landline_counter++) {
                    if ($data->landline[$landline_counter]->landline_id != "" && $data->landline[$landline_counter]->landline_number != "") {
                        try {
                            $landline_exist = "UPDATE user_landlines SET landline_num = '".$data->landline[$landline_counter]->landline_number."', remarks = '".$data->landline[$landline_counter]->landline_remarks."' WHERE landline_id='".$data->landline[$landline_counter]->landline_id."'";
                            $result = $this->dbconn->query($landline_exist);
                        } catch (Exception $e) {
                            $flag = false;
                            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
                            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
                            echo $e->getMessage();
                        }
                    } else if ($data->landline[$landline_counter]->landline_number == "") {
                        try {
                            $landline_exist = "DELETE FROM user_landlines WHERE landline_id='".$data->landline[$landline_counter]->landline_id."'";
                            $result = $this->dbconn->query($landline_exist);
                        } catch (Exception $e) {
                            $flag = false;
                            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
                            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
                            echo $e->getMessage();
                        }
                    } else {
                        try {
                            $new_landline = "INSERT INTO user_landlines VALUES (0,'$data->user_id','".$data->landline[$landline_counter]->landline_number."','".$data->landline[$landline_counter]->landline_remarks."')";
                            $result = $this->dbconn->query($new_landline); 
                        } catch (Exception $e) {
                            $flag = false;
                            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
                            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
                            echo $e->getMessage();
                        }
                    }
                }
            }


            $scope_query = "";
            for ($counter = 0; $counter < sizeof($data->organizations); $counter++) {
                if ($counter == 0) {
                    $scope_query = "org_name = '".$data->organizations[$counter]."'";
                } else {
                    $scope_query = $scope_query." OR org_name = '".$data->organizations[$counter]."'";
                }
            }

            $psgc_query = "";
            for ($counter = 0; $counter < sizeof($data->sites); $counter++) {
                if ($counter == 0) {
                    $psgc_query = "site_code = '".$data->sites[$counter]."'";
                } else {
                    $psgc_query = $psgc_query." OR site_code = '".$data->sites[$counter]."'";
                }   
            }

            try {
                $get_scope = "SELECT org_scope FROM organization WHERE ".$scope_query.";";
                $scope_result = $this->dbconn->query($get_scope);
                $ctr = 0;
                $scope = [];
                if ($scope_result->num_rows != 0) {
                    while ($row = $scope_result->fetch_assoc()) {
                        array_push($scope,$row['org_scope']);
                    }
                }
            } catch (Exception $e) {
                $flag = false;
                $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
                $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
                echo $e->getMessage();
            }

            try {
                $get_psgc = "SELECT site_id FROM sites WHERE ".$psgc_query.";";
                $psgc_result = $this->dbconn->query($get_psgc);
                $ctr = 0;
                $psgc = [];
                if ($psgc_result->num_rows != 0) {
                    while ($row = $psgc_result->fetch_assoc()) {
                        array_push($psgc,$row['site_id']);
                    }
                }
            } catch (Exception $e) {
                $flag = false;
                $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
                $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
                echo $e->getMessage();
            }

            try {
                $delete_orgs = "DELETE FROM user_organization WHERE user_id = '".$data->user_id."'";
                $result = $this->dbconn->query($delete_orgs);
            } catch (Exception $e) {
                $flag = false;
                $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
                $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
                echo $e->getMessage();
            }

            if ($flag == false) {
                $return_data['return_msg'] = "Error occured, please refresh the page and try again.";
            } else {
                $return_data['return_msg'] = "Successfully updated contact.";
            }
            $return_data['status'] = $flag;
        } else {
            $return_data['status'] = $result;
            $return_data['return_msg'] = "Contact update failed, Please recheck inputs.";
        }
        
        $return_data['type'] = "updatedCmmtyContact";
        return $return_data;
    }

    public function getAllSites() {
        try {
            $sites = [];
            $ctr = 0;
            $all_sites_query = "SELECT * FROM sites;";
            $result = $this->dbconn->query($all_sites_query);
            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $sites[$ctr]["site_id"] = $row["site_id"];
                    $sites[$ctr]["site_code"] = $row["site_code"];
                    $sites[$ctr]["purok"] = $row["purok"];
                    $sites[$ctr]["sitio"] = $row["sitio"];
                    $sites[$ctr]["barangay"] = $row["barangay"];
                    $sites[$ctr]["municipality"] = $row["municipality"];
                    $sites[$ctr]["province"] = $row["province"];
                    $sites[$ctr]["region"] = $row["region"];
                    $sites[$ctr]["psgc_source"] = $row["psgc_source"];
                    $ctr++;
                }
            }
            return $sites;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    public function getAllOrganization() {
        try {
            $orgs = [];
            $ctr = 0;
            $all_organization_query = "SELECT * FROM organization;";
            $result = $this->dbconn->query($all_organization_query);
            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $orgs[$ctr]["org_id"] = $row["org_id"];
                    $orgs[$ctr]["org_name"] = $row["org_name"];
                    $ctr++;
                }
            }
            return $orgs;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    public function createDwlsContact($data) {
        $flag = true;
        $emails = explode(',',$data->email_address);
        $teams = explode(',',$data->teams);
        try {
            $query_contact_info = "INSERT INTO users VALUES (0,'$data->salutation','$data->firstname','$data->middlename','$data->lastname','$data->nickname','$data->birthdate','$data->gender','$data->contact_active_status');";
            $result = $this->dbconn->query($query_contact_info);
        } catch (Exception $e) {
            $flag = false;
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }

        try {
            $get_last_id = "SELECT LAST_INSERT_ID();";
            $result = $this->dbconn->query($get_last_id);
            $data->id = $result->fetch_assoc()["LAST_INSERT_ID()"];
        } catch (Exception $e) {
            $flag = false;
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }

        if ($emails[0] != "") {
            for ($counter = 0; $counter < sizeof($emails); $counter++) {
                try {
                    $insert_new_emails = "INSERT INTO user_emails VALUES(0,'$data->id','$emails[$counter]')";
                    $result = $this->dbconn->query($insert_new_emails);
                } catch (Exception $e) {
                    $flag = false;
                    $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
                    $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
                }
            }
        }

        if ($teams[0] != "") {
            for ($counter = 0; $counter < sizeof($teams); $counter++) {
                $check_if_existing = "SELECT * FROM dewsl_teams WHERE team_name ='$teams[$counter]'";
                $result = $this->dbconn->query($check_if_existing);
                if ($result->num_rows == 0) {
                    $insert_new_teams = "INSERT INTO dewsl_teams VALUES (0,'$teams[$counter]','')";
                    $result = $this->dbconn->query($insert_new_teams);
                    $newly_added_team = "SELECT * FROM dewsl_teams WHERE team_name ='$teams[$counter]'";
                    $result = $this->dbconn->query($newly_added_team);
                    $team_details = $result->fetch_assoc();
                    $insert_team_member = "INSERT INTO dewsl_team_members VALUES (0,'$data->id','".$team_details['team_id']."')";
                    $result = $this->dbconn->query($insert_team_member);
                } else {
                    $team_details = $result->fetch_assoc();
                    $insert_team_member = "INSERT INTO dewsl_team_members VALUES (0,'$data->id','".$team_details['team_id']."')";
                    $result = $this->dbconn->query($insert_team_member);
                }
            }
        }

        for ($num_counter = 0; $num_counter < sizeof($data->numbers); $num_counter++) {
            try {
                $mobile_gsm_id = $this->identifyGSMIDFromMobileNumber($data->numbers[$num_counter]->mobile_number);

                $new_num_query = "INSERT INTO user_mobile VALUES (0,'$data->id','".$data->numbers[$num_counter]->mobile_number."','".$data->numbers[$num_counter]->mobile_priority."','".$data->numbers[$num_counter]->mobile_status."','".$mobile_gsm_id."')";
                $result = $this->dbconn->query($new_num_query);
            } catch (Exception $e) {
                $flag = false;
                $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
                $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
            }
        }

        for ($landline_counter = 0; $landline_counter < sizeof($data->landline); $landline_counter++) {
            try {
                $new_landline = "INSERT INTO user_landlines VALUES (0,'$data->id','".$data->landline[$landline_counter]->landline_number."','".$data->landline[$landline_counter]->landline_remarks."')";
                $result = $this->dbconn->query($new_landline); 
            } catch (Exception $e) {
                $flag = false;
                $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
                $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
            }
        }

        if ($flag == false) {
            $return_data['return_msg'] = "Error occured, please refresh the page and try again.";
        } else {
            $return_data['return_msg'] = "Successfully added new contact.";
        }
        $return_data['status'] = $flag;

        $return_data['type'] = "newAddedDwslContact";
        return $return_data;
    }

    public function createCommContact($data) {
        $flag = true;
        try {
            $query_contact_info = "INSERT INTO users VALUES (0,'$data->salutation','$data->firstname','$data->middlename','$data->lastname','$data->nickname','$data->birthdate','$data->gender','$data->contact_active_status');";
            $result = $this->dbconn->query($query_contact_info);
        } catch (Exception $e) {
            $flag = false;
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }

        try {
            $get_last_id = "SELECT LAST_INSERT_ID();";
            $result = $this->dbconn->query($get_last_id);
            $data->id = $result->fetch_assoc()["LAST_INSERT_ID()"];
        } catch (Exception $e) {
            $flag = false;
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }

        /* Insert all mobile numbers to user_mobile table */
        for ($num_counter = 0; $num_counter < sizeof($data->numbers); $num_counter++) {
            try {
                $mobile_gsm_id = $this->identifyGSMIDFromMobileNumber($data->numbers[$num_counter]->mobile_number);

                $new_num_query = "INSERT INTO user_mobile VALUES (0,'$data->id','".$data->numbers[$num_counter]->mobile_number."','".$data->numbers[$num_counter]->mobile_priority."','".$data->numbers[$num_counter]->mobile_status."','".$mobile_gsm_id."')";

                $result = $this->dbconn->query($new_num_query);
            } catch (Exception $e) {
                $flag = false;
                $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
                $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
            }
        }

        /* Insert all landline numbers to user_landlines table */
        for ($landline_counter = 0; $landline_counter < sizeof($data->landline); $landline_counter++) {
            try {
                $new_landline = "INSERT INTO user_landlines VALUES (0,'$data->id','".$data->landline[$landline_counter]->landline_number."','".$data->landline[$landline_counter]->landline_remarks."')";
                $result = $this->dbconn->query($new_landline); 
            } catch (Exception $e) {
                $flag = false;
                $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
                $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
            }
        }

        /* Prepare the WHERE filter for the PSGC query */
        $site_query = "";
        for ($counter = 0; $counter < sizeof($data->sites); $counter++) {
            if ($counter == 0) {
                $site_query = "site_code = '".$data->sites[$counter]."'";
            } else {
                $site_query = $site_query." OR site_code = '".$data->sites[$counter]."'";
            }
        }

        /* Get Site Details */
        $site_details = [];
        $ctr = 0;
        try {
            $site_details_query = "SELECT site_id, site_code, psgc_source  FROM sites WHERE ".$site_query;
            $site_collection = $this->dbconn->query($site_details_query);
            while ($row = $site_collection->fetch_assoc()) {
                $site_details[$ctr] = $row;
                $ctr++;
            }
        } catch (Exception $e) {
            $flag = false;
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }        

        /* Prepare org details query */
        $org_query = "";
        for ($counter = 0; $counter < sizeof($data->organizations); $counter++) {
            if ($counter == 0) {
                $org_query = "org_name = '".$data->organizations[$counter]."'";
            } else {
                $org_query = $org_query." OR org_name = '".$data->organizations[$counter]."'";
            }
        }

        /* Get org details from database */
        $org_details = [];
        $ctr = 0;
        try {
            $get_org_scope = "SELECT * FROM organization WHERE ".$org_query;
            $org_collection = $this->dbconn->query($get_org_scope);
            while ($row = $org_collection->fetch_assoc()) {
                $org_details[$ctr] = $row;
                $ctr++;
            }
        } catch (Exception $e) {
            $flag = false;
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }        

        /* Delete first, all users that already exists in user_organization table */
        try { 
            $delete_orgs = "DELETE FROM user_organization WHERE users_id = '".$data->id."'";
            $result = $this->dbconn->query($delete_orgs);
        } catch (Exception $e) {
            $flag = false;
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }

        /* Insert into user_organization table the new community contact */
        for ($counter = 0; $counter < sizeof($site_details); $counter++) {
            for ($sub_counter = 0; $sub_counter < sizeof($org_details); $sub_counter++) {
                try {
                    $insert_org = "INSERT INTO user_organization VALUES (0,'".$data->id."','".$site_details[$counter]['site_id']."','".$org_details[$sub_counter]['org_name']."','".$org_details[$sub_counter]['org_scope']."')";
                    $result_org = $this->dbconn->query($insert_org);
                } catch (Exception $e) {
                    $flag = false;
                    $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
                    $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
                }
            }
        }

        /* Get the mobile IDs per user */
        $mobile_details_per_user = [];
        $mobile_details_per_user = $this->getMobileDetailsPerUser($data->id);

        /* INSERT into user_ewi_status if user is mobile recipient and corresponding mobile_id */
        for ($counter = 0; $counter < sizeof($mobile_details_per_user); $counter++) {
            try {
                $insert_ewi_status = "INSERT INTO user_ewi_status VALUES ('".$mobile_details_per_user[$counter]['mobile_id']."','".$mobile_details_per_user[$counter]['mobile_status']."','Active','".$data->id."')";
                $result = $this->dbconn->query($insert_ewi_status);
            } catch (Exception $e) {
                $flag = false;
                $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
                $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
            }        
        }

        /* Return response message for notification toast */
        if ($flag == false) {
            $return_data['return_msg'] = "Error occured, please refresh the page and try again.";
        } else {
            $return_data['return_msg'] = "Successfully added new contact.";
        }
        $return_data['status'] = $flag;

        echo "\n" . $return_data['return_msg'] . "\n"; // Log to chatterbox log
        
        // $return_data['type'] = "newAddedCommContact";
        $return_data['type'] = "newCommunityContact";
        return $return_data;
    }

    public function getSmsForGroups($organizations,$sitenames) {
        $mobile_data = [];
        $mobile_numbers = [];
        $recipient_ids = [];
        $mobile_ids = [];
        $ctr = 0;
        $site_query = "";
        $org_query = "";
        try {
            for ($sub_counter = 0; $sub_counter < sizeof($sitenames); $sub_counter++) {
                if ($sub_counter == 0) {
                    $site_query = "sites.site_code ='".strtoupper($sitenames[$sub_counter])."'";
                } else {
                    $site_query = $site_query." OR sites.site_code ='".strtoupper($sitenames[$sub_counter])."'";
                }
            }

            for ($sub_counter = 0; $sub_counter < sizeof($organizations); $sub_counter++) {
                if ($sub_counter == 0) {
                    $org_query = "user_organization.org_name = '".strtoupper($organizations[$sub_counter])."'";
                } else {
                    $org_query = $org_query." OR user_organization.org_name = '".strtoupper($organizations[$sub_counter])."'";
                }
            }

            $get_mobile_ids_query = "SELECT * FROM users INNER JOIN user_mobile ON users.user_id = user_mobile.user_id LEFT JOIN user_organization ON users.user_id = user_organization.user_id LEFT JOIN sites ON sites.site_id = user_organization.fk_site_id WHERE (".$site_query.") AND (".$org_query.")";
            $mobile_ids_raw = $this->dbconn->query($get_mobile_ids_query);
            if ($mobile_ids_raw->num_rows != 0) {
                while ($row = $mobile_ids_raw->fetch_assoc()) {
                    if (!in_array($row['user_id'],$recipient_ids)) {
                        array_push($recipient_ids,$row['user_id']);    
                        $mobile_numbers[$ctr]['mobile_id'] = $row['mobile_id'];
                        $mobile_numbers[$ctr]['user_id'] = $row['user_id'];
                        $mobile_numbers[$ctr]['sim_num'] = $row['sim_num'];
                        $mobile_numbers[$ctr]['number_priority'] = $row['priority'];
                        $mobile_numbers[$ctr]['mobile_active_status'] = $row['mobile_status'];
                        $mobile_data[$ctr]['user_id'] = $row['user_id'];
                        $mobile_data[$ctr]['salutation'] = $row['salutation'];
                        $mobile_data[$ctr]['firstname'] = $row['firstname'];
                        $mobile_data[$ctr]['middlename'] = $row['middlename'];
                        $mobile_data[$ctr]['lastname'] = $row['lastname'];
                        $mobile_data[$ctr]['site_code'] = $row['site_code'];
                        $mobile_data[$ctr]['site_id'] = $row['site_id'];
                        $mobile_data[$ctr]['site_code'] = $row['site_code'];
                        $mobile_data[$ctr]['purok'] = $row['purok'];
                        $mobile_data[$ctr]['sitio'] = $row['sitio'];
                        $mobile_data[$ctr]['barangay'] = $row['barangay'];
                        $mobile_data[$ctr]['municipality'] = $row['municipality'];
                        $mobile_data[$ctr]['province'] = $row['province'];
                        $mobile_data[$ctr]['region'] = $row['region'];
                        $mobile_data[$ctr]['psgc'] = $row['psgc'];
                        $ctr++;
                    } else {
                        $mobile_numbers[$ctr]['user_id'] = $row['user_id'];
                        $mobile_numbers[$ctr]['mobile_id'] = $row['mobile_id'];
                        $mobile_numbers[$ctr]['sim_num'] = $row['sim_num'];
                        $mobile_numbers[$ctr]['number_priority'] = $row['priority'];
                        $mobile_numbers[$ctr]['mobile_active_status'] = $row['mobile_status'];
                        $ctr++;
                    }
                }
            } else {
                $return_data['status'] = 'success';
                $return_data['data'] = [];
                $return_data['result_msg'] = 'No message fetched.';
                $return_data['type'] = 'fetchGroupSms';
                return $return_data;
            }

            for ($counter = 0; $counter < sizeof($mobile_data); $counter++) {
                $mobile_data[$counter]['mobile_numbers'] = [];
                for ($sub_counter = 0; $sub_counter < sizeof($mobile_numbers); $sub_counter++) {
                    if ($mobile_data[$counter]['user_id'] == $mobile_numbers[$sub_counter]['user_id']) {
                        array_push($mobile_data[$counter]['mobile_numbers'],$mobile_numbers[$sub_counter]);
                    }
                }
            }

            for ($counter = 0; $counter < sizeof($mobile_data); $counter++) {
                for ($sub_counter = 0; $sub_counter < sizeof($mobile_data[$counter]['mobile_numbers']); $sub_counter++) {
                    array_push($mobile_ids,$mobile_data[$counter]['mobile_numbers'][$sub_counter]['mobile_id']);

                }
            }
            
            $mobile_id_sub_query = "";
            for ($counter = 0; $counter < sizeof($mobile_ids); $counter++) {
                if ($counter == 0 ) {
                    $mobile_id_sub_query = "mobile_id = '".$mobile_ids[$counter]."'";
                } else {
                    $mobile_id_sub_query = $mobile_id_sub_query." OR mobile_id = '".$mobile_ids[$counter]."'";
                }
            }

            $inbox_outbox_collection = [];
            try {
                $inbox_query = "SELECT * FROM newdb.smsinbox_users WHERE ".$mobile_id_sub_query." LIMIT 70";
                $test_fetch_inboxes = $this->dbconn->query($inbox_query);
                if ($test_fetch_inboxes->num_rows != 0) {
                    while ($row = $test_fetch_inboxes->fetch_assoc()) {
                        array_push($inbox_outbox_collection,$row);
                    }
                }

                $outbox_query = "SELECT * FROM newdb.smsoutbox_users INNER JOIN smsoutbox_user_status ON smsoutbox_users.outbox_id = smsoutbox_user_status.outbox_id WHERE ".$mobile_id_sub_query." LIMIT 70";
                $test_fetch_outboxes = $this->dbconn->query($outbox_query);
                if ($test_fetch_outboxes->num_rows != 0) {
                    while ($row = $test_fetch_outboxes->fetch_assoc()) {
                        array_push($inbox_outbox_collection,$row);
                    }
                }

                $data = [];
                $ctr = 0;
                $inbox_outbox_collection = $this->sort_msgs($inbox_outbox_collection);
                for ($sms_counter = 0; $sms_counter < sizeof($inbox_outbox_collection); $sms_counter++) {
                    if (isset($inbox_outbox_collection[$sms_counter]['outbox_id'])) {
                        for ($contact_counter = 0; $contact_counter < sizeof($mobile_data); $contact_counter++) {
                            for ($number_counter = 0; $number_counter < sizeof($mobile_data[$contact_counter]['mobile_numbers']); $number_counter++) {
                                if ($mobile_data[$contact_counter]['mobile_numbers'][$number_counter]['mobile_id'] == $inbox_outbox_collection[$sms_counter]['mobile_id']) {
                                    $data[$ctr]['sms_id'] = $inbox_outbox_collection[$sms_counter]['outbox_id'];
                                    $data[$ctr]['msg'] = $inbox_outbox_collection[$sms_counter]['sms_msg'];
                                    $data[$ctr]['timestamp'] = $inbox_outbox_collection[$sms_counter]['ts_written'];
                                    $data[$ctr]['timestamp_sent'] = $inbox_outbox_collection[$sms_counter]['ts_sent'];
                                    $data[$ctr]['name'] = 'You';
                                    $data[$ctr]['recipient_user_id'] = $mobile_data[$contact_counter]['user_id'];
                                    $data[$ctr]['recipient_name'] = $mobile_data[$contact_counter]['salutation']." ".$mobile_data[$contact_counter]['firstname']." ".$mobile_data[$contact_counter]['lastname'];
                                    $data[$ctr]['recipient_mobile_id'] = $mobile_data[$contact_counter]['mobile_numbers'][$number_counter]['mobile_id'];
                                    $data[$ctr]['recipient_sim_num'] = $mobile_data[$contact_counter]['mobile_numbers'][$number_counter]['sim_num'];
                                    $data[$ctr]['recipient_site_code'] = $mobile_data[$contact_counter]['site_code'];
                                    $ctr++;
                                }
                            }
                        }
                    } else {  
                        for ($contact_counter = 0; $contact_counter < sizeof($mobile_data); $contact_counter++) {
                            for ($number_counter = 0; $number_counter < sizeof($mobile_data[$contact_counter]['mobile_numbers']); $number_counter++) {
                                if ($mobile_data[$contact_counter]['mobile_numbers'][$number_counter]['mobile_id'] == $inbox_outbox_collection[$sms_counter]['mobile_id']) {
                                    $data[$ctr]['sms_id'] = $inbox_outbox_collection[$sms_counter]['inbox_id'];
                                    $data[$ctr]['msg'] = $inbox_outbox_collection[$sms_counter]['sms_msg'];
                                    $data[$ctr]['timestamp'] = $inbox_outbox_collection[$sms_counter]['ts_received'];
                                    $data[$ctr]['user_id'] = $mobile_data[$contact_counter]['user_id'];
                                    $data[$ctr]['name'] = $mobile_data[$contact_counter]['salutation']." ".$mobile_data[$contact_counter]['firstname']." ".$mobile_data[$contact_counter]['lastname'];
                                    $data[$ctr]['mobile_id'] = $mobile_data[$contact_counter]['mobile_numbers'][$number_counter]['mobile_id'];
                                    $data[$ctr]['sim_num'] = $mobile_data[$contact_counter]['mobile_numbers'][$number_counter]['sim_num'];
                                    $data[$ctr]['site_code'] = $mobile_data[$contact_counter]['site_code'];
                                    $ctr++;
                                }
                            }
                        }
                    }
                }
                $return_data['status'] = 'success';
                $return_data['data'] = $data;
                $return_data['result_msg'] = 'Messages fetched.';
                
            } catch (Exception $e) {
                $return_data['result_msg'] = 'Message fetch failed, please contact SWAT for more details';
                $return_data['status'] = 'failed';
                $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
                $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
            }
        } catch (Exception $e) {
            $return_data['status'] = 'failed';
            $return_data['result_msg'] = 'Message fetch failed, please contact SWAT for more details';
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
        $return_data['type'] = 'fetchGroupSms';
        return $return_data;
    }

    public function getSmsPerContact($fullname,$timestamp,$limit=20) {
        try {
            $contact_details_raw = explode(" ",$fullname);
            $contact_details = [];
            $inbox_outbox_collection = [];
            $number_query = "";
            $data = [];
            $mobile_ids = [];
            $return_data = [];
            $ctr = 0;

            $where_query = "";
            for ($counter = 0; $counter < sizeof($contact_details_raw); $counter++) {
                if ($contact_details_raw[$counter] != "" && $contact_details_raw[$counter] != "-") {
                    array_push($contact_details,$contact_details_raw[$counter]);
                }
            }
            $org_team_checker_query = "SELECT * FROM dewsl_teams WHERE team_name LIKE '%".$contact_details[0]."%'";
            $is_org = $this->dbconn->query($org_team_checker_query);

            if ($is_org->num_rows != 0) {
                for ($counter = 2; $counter < sizeof($contact_details); $counter++) {
                    $where_query = $where_query."AND (users.firstname LIKE '%".trim($contact_details[$counter],";")."%' OR users.lastname LIKE '%".trim($contact_details[$counter],";")."%') ";
                }
                $get_numbers_query = "SELECT * FROM user_mobile INNER JOIN users ON user_mobile.user_id = users.user_id RIGHT JOIN dewsl_team_members ON dewsl_team_members.users_users_id = users.user_id RIGHT JOIN dewsl_teams ON dewsl_teams.team_id = dewsl_team_members.dewsl_teams_team_id WHERE dewsl_teams.team_name LIKE '%".$contact_details[0]."%' ".$where_query.";";
            } else {
                for ($counter = 3; $counter < sizeof($contact_details); $counter++) {
                   $where_query = $where_query."AND (users.firstname LIKE '%".trim($contact_details[$counter],";")."%' OR users.lastname LIKE '%".trim($contact_details[$counter],";")."%') ";
                }
                $get_numbers_query = "SELECT * FROM user_mobile INNER JOIN users ON user_mobile.user_id = users.user_id RIGHT JOIN user_organization ON user_organization.user_id = users.user_id RIGHT JOIN organization ON user_organization.org_name = organization.org_name RIGHT JOIN sites ON user_organization.fk_site_id = sites.site_id WHERE organization.org_name LIKE '%".$contact_details[1]."%' AND sites.site_code LIKE '%".$contact_details[0]."%' AND users.salutation = '".$contact_details[2]."' ".$where_query.";";
            }

            $numbers = $this->dbconn->query($get_numbers_query);
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function getMessageConversations($details, $limit = 20) {
        try {
            // var_dump($details);
            $inbox_outbox_collection = [];
            $temp_timestamp = [];
            $sorted_sms = [];
            $recipient_container = [];
            $mobile_id_container = [];
            $filter_builder = "";
            if ($details['number'] == "N/A") {
                $counter = 0;
                $mobile_number = $this->getMobileDetails($details);
                foreach ($mobile_number as $number) {
                    if ($counter == 0) {
                        $filter_builder = "sim_num LIKE '%".substr($number['sim_num'], -10)."%'";
                    } else {
                        $filter_builder = $filter_builder." OR sim_num LIKE '%".substr($number['sim_num'], -10)."%'";
                    }
                    $counter++;
                }
            }

            $mobile_id_query = "SELECT mobile_id FROM user_mobile where ".$filter_builder.";";
            $mobile_id = $this->dbconn->query($mobile_id_query);

            $counter = 0;
            while ($row = $mobile_id->fetch_assoc()) {
                if ($counter == 0) {
                    $inbox_builder = "mobile_id = '".$row['mobile_id']."'";
                    $outbox_builder = "smsoutbox_user_status.mobile_id = '".$row['mobile_id']."'";
                } else {
                    $inbox_builder = $inbox_builder." OR mobile_id = '".$row['mobile_id']."'";
                    $outbox_builder = $outbox_builder." OR smsoutbox_user_status.mobile_id = '".$row['mobile_id']."'";
                }
                $counter++;
            }

            $inbox_query = "SELECT smsinbox_users.inbox_id as convo_id, mobile_id, 
                            smsinbox_users.ts_sms as ts_received, null as ts_written, null as ts_sent, smsinbox_users.sms_msg,
                            smsinbox_users.read_status, smsinbox_users.web_status, smsinbox_users.gsm_id ,
                            null as send_status , ts_sms as timestamp , '".$details['full_name']."' as user from smsinbox_users WHERE ".$inbox_builder." ";
            // echo $inbox_query;
            // echo " | ";                           
            $outbox_query = "SELECT smsoutbox_users.outbox_id as convo_id, mobile_id,
                            null as ts_received, ts_written, ts_sent, sms_msg , null as read_status,
                            web_status, gsm_id , send_status , ts_written as timestamp, 'You' as user FROM smsoutbox_users INNER JOIN smsoutbox_user_status ON smsoutbox_users.outbox_id = smsoutbox_user_status.outbox_id WHERE ".$outbox_builder."";
            // echo $outbox_query;
            $full_query = "SELECT * FROM (".$inbox_query." UNION ".$outbox_query.") as full_contact group by timestamp order by timestamp desc limit 20;";
            // echo $full_query;
            $fetch_convo = $this->dbconn->query($full_query);
            if ($fetch_convo->num_rows != 0) {
                while($row = $fetch_convo->fetch_assoc()) {
                    $table_used = '';
                    if($row['user'] == "You"){
                        $table_used = "smsoutbox_users"
                    }else{
                        $table_used = "smsinbox_users"
                    }
                    $tag = $this->fetchSmsTags($row['convo_id'], $table_used);
                    if (sizeOf($tag['data']) == 0) {
                        $row['hasTag'] = 0;
                    } else {
                        $row['hasTag'] = 1;
                    }
                    array_push($inbox_outbox_collection,$row);
                }
            } else {
                echo "No message fetched!";
            }

            $full_data = [];
            $full_data['full_name'] = $details['full_name'];
            $full_data['recipients'] = $mobile_number;
            $full_data['type'] = "loadSmsConversation";
            $full_data['data'] = $inbox_outbox_collection;
            // var_dump($full_data['recipients']);
            return $full_data;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function getMessageConversationsForMultipleContact($details) {
        try {
            $temp = [];
            $mobile_id_container = [];
            $counter = 0;
            $inbox_outbox_collection = [];
            foreach($details as $raw) {
                $temp['first_name'] =  $raw->firstname;
                $temp['last_name'] = $raw->lastname;
                $mobile_id = $this->getMobileDetails($temp);
                array_push($mobile_id_container,$mobile_id);
            }

            foreach ($mobile_id_container as $mobile_data) {
                if ($counter == 0) {
                    $outbox_filter_query = "smsoutbox_user_status.mobile_id = ".$mobile_data[0]['mobile_id'];
                    $inbox_filter_query = "smsinbox_users.mobile_id = ".$mobile_data[0]['mobile_id'];
                    $counter++;
                } else {
                    $outbox_filter_query = $outbox_filter_query." OR smsoutbox_user_status.mobile_id = ".$mobile_data[0]['mobile_id']." ";
                    $inbox_filter_query = $inbox_filter_query." OR smsinbox_users.mobile_id = ".$mobile_data[0]['mobile_id']." ";
                }
            }

            $inbox_query = "SELECT smsinbox_users.inbox_id as convo_id, smsinbox_users.mobile_id, 
                            smsinbox_users.ts_sms as ts_received, null as ts_written, null as ts_sent, smsinbox_users.sms_msg,
                            smsinbox_users.read_status, smsinbox_users.web_status, smsinbox_users.gsm_id ,
                            null as send_status , ts_sms as timestamp, user_mobile.sim_num, UPPER(CONCAT(sites.site_code,' ',user_organization.org_name, ' - ', users.lastname, ', ', users.firstname)) as user from smsinbox_users INNER JOIN user_mobile ON smsinbox_users.mobile_id = user_mobile.mobile_id 
                            INNER JOIN users ON users.user_id = user_mobile.user_id INNER JOIN user_organization ON users.user_id = user_organization.user_id INNER JOIN sites ON user_organization.fk_site_id = sites.site_id WHERE ".$inbox_filter_query."";

            $outbox_query = "SELECT smsoutbox_users.outbox_id as convo_id, mobile_id,
                            null as ts_received, ts_written, ts_sent, sms_msg , null as read_status,
                            web_status, gsm_id , send_status , ts_written as timestamp,null as sim_num 'You' as user FROM smsoutbox_users INNER JOIN smsoutbox_user_status ON smsoutbox_users.outbox_id = smsoutbox_user_status.outbox_id WHERE ".$outbox_filter_query."";
            $full_query = "SELECT * FROM (".$inbox_query." UNION ".$outbox_query.") as full_contact group by sms_msg order by timestamp desc limit 70;";

            $fetch_convo = $this->dbconn->query($full_query);
            if ($fetch_convo->num_rows != 0) {
                while($row = $fetch_convo->fetch_assoc()) {
                    $table_used = '';
                    if($row['user'] == "You"){
                        $table_used = "smsoutbox_users"
                    }else{
                        $table_used = "smsinbox_users"
                    }
                    $tag = $this->fetchSmsTags($row['convo_id'], $table_used);
                    if (sizeOf($tag['data']) == 0) {
                        $row['hasTag'] = 0;
                    } else {
                        $row['hasTag'] = 1;
                    }
                    $row['network'] = $this->identifyMobileNetwork($row['sim_num']);
                    array_push($inbox_outbox_collection,$row);
                }
            } else {
                echo "No message fetched!";
            }

            $full_data = [];
            $full_data['type'] = "loadSmsConversation";
            $full_data['data'] = $inbox_outbox_collection;
            $full_data['recipients'] = $mobile_id_container;
            return $full_data; 
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }  
    }

    function getMessageConversationsPerSites($offices, $sites) {
        try {
            $counter = 0;
            $inbox_filter_query = "";
            $outbox_filter_query = "";
            $inbox_outbox_collection = [];
            $convo_id_container = [];
            if(empty($offices)){
                $offices = ["mlgu","blgu","lewc","plgu"];
            }
            $contact_lists = $this->getMobileDetailsViaOfficeAndSitename($offices,$sites);

            foreach ($contact_lists as $mobile_data) {
                if ($counter == 0) {
                    $outbox_filter_query = "smsoutbox_user_status.mobile_id = ".$mobile_data['mobile_id'];
                    $inbox_filter_query = "smsinbox_users.mobile_id = ".$mobile_data['mobile_id'];
                    $counter++;
                } else {
                    $outbox_filter_query = $outbox_filter_query." OR smsoutbox_user_status.mobile_id = ".$mobile_data['mobile_id']." ";
                    $inbox_filter_query = $inbox_filter_query." OR smsinbox_users.mobile_id = ".$mobile_data['mobile_id']." ";
                }
            }

            $inbox_query = "SELECT smsinbox_users.inbox_id as convo_id, smsinbox_users.mobile_id, 
                            smsinbox_users.ts_sms as ts_received, null as ts_written, null as ts_sent, smsinbox_users.sms_msg,
                            smsinbox_users.read_status, smsinbox_users.web_status, smsinbox_users.gsm_id, user_mobile.sim_num,
                            null as send_status , ts_sms as timestamp, UPPER(CONCAT(sites.site_code,' ',user_organization.org_name, ' - ', users.lastname, ', ', users.firstname)) as user from smsinbox_users INNER JOIN user_mobile ON smsinbox_users.mobile_id = user_mobile.mobile_id 
                            INNER JOIN users ON users.user_id = user_mobile.user_id INNER JOIN user_organization ON users.user_id = user_organization.user_id INNER JOIN sites ON user_organization.fk_site_id = sites.site_id WHERE ".$inbox_filter_query."";

            $outbox_query = "SELECT smsoutbox_users.outbox_id as convo_id, mobile_id,
                            null as ts_received, ts_written, ts_sent, sms_msg , null as read_status,
                            web_status, gsm_id, null as sim_num, send_status , ts_written as timestamp, 'You' as user FROM smsoutbox_users INNER JOIN smsoutbox_user_status ON smsoutbox_users.outbox_id = smsoutbox_user_status.outbox_id WHERE ".$outbox_filter_query."";
            $full_query = "SELECT * FROM (".$inbox_query." UNION ".$outbox_query.") as full_contact group by sms_msg,timestamp order by timestamp desc limit 70;";

            $fetch_convo = $this->dbconn->query($full_query);
            if ($fetch_convo->num_rows != 0) {
                while($row = $fetch_convo->fetch_assoc()) {
                    $table_used = '';
                    if($row['user'] == "You"){
                        $table_used = "smsoutbox_users"
                    }else{
                        $table_used = "smsinbox_users"
                    }
                    $tag = $this->fetchSmsTags($row['convo_id'], $table_used);
                    if (sizeOf($tag['data']) == 0) {
                        $row['hasTag'] = 0;
                    } else {
                        $row['hasTag'] = 1;
                    }
                    $row['network'] = $this->identifyMobileNetwork($row['sim_num']);
                    array_push($inbox_outbox_collection,$row);
                }
            } else {
                echo "No message fetched!";
            }

            $title_collection = [];
            foreach ($inbox_outbox_collection as $raw) {
                if ($raw['user'] == 'You') {
                    $titles = $this->getSentStatusForGroupConvos($raw['sms_msg'],$raw['timestamp'], $raw['mobile_id']);
                    $constructed_title = "";
                    foreach ($titles as $concat_title) {
                        if ($concat_title['status'] >= 5 ) {
                            $constructed_title = $constructed_title.$concat_title['full_name']." (SENT) <split>";
                        } else if ($concat_title['status'] < 5 && $concat_title >= 1) {
                            $constructed_title = $constructed_title.$concat_title['full_name']." (RESENDING) <split>";
                        } else {
                            $constructed_title = $constructed_title.$concat_title['full_name']." (FAIL) <split>";
                        }
                    }
                    array_push($title_collection, $constructed_title);
                } else {
                    array_push($title_collection, $raw['user']);
                }
            }

            $full_data = [];
            $full_data['type'] = "loadSmsConversation";
            $full_data['data'] = $inbox_outbox_collection;
            $full_data['titles'] = array_reverse($title_collection);
            $full_data['recipients'] = $contact_lists;
            return $this->utf8_encode_recursive($full_data);
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function getSentStatusForGroupConvos($sms_msg, $timestamp, $mobile_id) {
        try {
            $status_container = [];
            $get_sent_status_query = 'SELECT smsoutbox_users.outbox_id as sms_id, smsoutbox_user_status.send_status as status, CONCAT(users.lastname,", ",users.firstname) as full_name FROM smsoutbox_users INNER JOIN smsoutbox_user_status ON smsoutbox_users.outbox_id = smsoutbox_user_status.outbox_id INNER JOIN user_mobile ON smsoutbox_user_status.mobile_id = user_mobile.mobile_id INNER JOIN users ON user_mobile.user_id = users.user_id WHERE ts_written = "'.$timestamp.'" AND sms_msg = "'.$sms_msg.'";';
            $sent_status = $this->dbconn->query($get_sent_status_query);
            if ($sent_status->num_rows !=0) {
                while ($row = $sent_status->fetch_assoc()) {
                    array_push($status_container, $row);
                }
            } else {
                echo "No sent status fetched.\n\n PMS FIRED!";
            }
            return $status_container;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
       
    }

    function getMobileDetails($details) {
        try {
           $mobile_number_container = [];
            if (isset($details->mobile_id) == false ) {
                $mobile_number_query = "SELECT * FROM users NATURAL JOIN user_mobile WHERE users.firstname LIKE '%".$details['first_name']."%' AND users.lastname LIKE '%".$details['last_name']."%';";
            } else {
                $mobile_number_query = "SELECT * FROM users NATURAL JOIN user_mobile WHERE mobile_id = '".$details->mobile_id."';";
            }
            $mobile_number = $this->dbconn->query($mobile_number_query);
            if ($mobile_number->num_rows != 0) {
                while ($row = $mobile_number->fetch_assoc()) {
                    array_push($mobile_number_container, $row);
                }
            } else {
                echo "No numbers fetched!";
            }
            return $mobile_number_container;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
        $mobile_number_container = [];
    }

    function getMobileDetailsViaOfficeAndSitename($offices,$sites) {
        try {
            $where = "";
            $counter = 0;
            $site_office_query = "";
            $mobile_data_container = [];
            foreach ($offices as $office) {
                foreach ($sites as $site) {
                    if ($counter == 0) {
                        $site_office_query = "(org_name = '".$office."' AND fk_site_id = '".$site."')";
                    } else {
                        $site_office_query = $site_office_query." OR (org_name = '".$office."' AND fk_site_id = '".$site."')";
                    }

                    $counter++;
                }
            }

            $mobile_data_query = "SELECT * FROM user_organization INNER JOIN users ON user_organization.user_id = users.user_id INNER JOIN user_ewi_status ON user_organization.user_id = user_ewi_status.users_id INNER JOIN user_mobile ON user_mobile.user_id = users.user_id INNER JOIN sites ON sites.site_id = '".$site."' WHERE user_ewi_status.status = '1' AND users.status = '1' AND (".$site_office_query.");";

            $mobile_number = $this->dbconn->query($mobile_data_query);
            while ($row = $mobile_number->fetch_assoc()) {
                array_push($mobile_data_container, $row);
            }
            return $mobile_data_container;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function sendTallyUpdate($category, $event_id, $data_timestamp,$sent_count) {
        try {
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
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function getRoutineMobileIDsViaSiteName($offices,$site_codes) {
        try {
            $where = "";
            $counter = 0;
            $site_office_query = "";
            $mobile_id_container = [];
            foreach ($offices as $office) {
                foreach ($site_codes as $site_code) {
                    if ($counter == 0) { 
                        $site_office_query = "(org_name = '".$office."' AND fk_site_id = '".$site_code."')";
                    } else {
                        $site_office_query = $site_office_query." OR (org_name = '".$office."' AND fk_site_id = '".$site_code."')";
                    }            
                    $counter++;
                }
            }

            $mobile_data_query = "SELECT DISTINCT user_mobile.mobile_id,fk_site_id FROM user_organization INNER JOIN users ON user_organization.user_id = users.user_id INNER JOIN user_mobile ON user_mobile.user_id = users.user_id INNER JOIN sites ON sites.site_id INNER JOIN user_ewi_status ON user_ewi_status.mobile_id = user_mobile.mobile_id WHERE user_ewi_status.status = 1 AND users.status = 1 AND (".$site_office_query.") order by fk_site_id;";

            $execute_query = $this->dbconn->query($mobile_data_query);

            if ($execute_query->num_rows > 0) {
                while ($row = $execute_query->fetch_assoc()) {
                    array_push($mobile_id_container, $row);
                }
                $full_data['data'] = $mobile_id_container;
            } else {
                echo "0 results\n";
                $full_data['data'] = null;
            }        

            $full_data['date'] = date("F j, Y");
            $full_data['sites'] = $this->getAllSites();
            $full_data['type'] = "getLEWCMobileDetailsViaSiteName";
            return $this->utf8_encode_recursive($full_data); 
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
        
    }

    function sendSms($recipients, $message) {
        $sms_status_container = [];
        $convo_id_container = [];
        $current_ts = date("Y-m-d H:i:s", time());
        foreach ($recipients as $recipient) {
            $message = str_replace('"','\"',$message);
            $insert_smsoutbox_query = 'INSERT INTO smsoutbox_users VALUES (0,"'.$current_ts.'","central","'.$message.'")';
	        $smsoutbox = $this->dbconn->query($insert_smsoutbox_query);
            array_push($convo_id_container, $this->dbconn->insert_id);
            if ($smsoutbox == true) {
                $insert_smsoutbox_status = "INSERT INTO smsoutbox_user_status VALUES (0,'".$this->dbconn->insert_id."','".$recipient."',null,0,0,'".$this->getGsmId($recipient)."')";      
                $smsoutbox_status = $this->dbconn->query($insert_smsoutbox_status);
                if ($smsoutbox_status == true) {
                    $stats = [
                        "status" => $smsoutbox_status,
                        "mobile_id" => $recipient
                    ];
                    array_push($sms_status_container, $stats);
                } else {
                    return -1;
                }
            } else {
                return -1;
            }
        }

        $result = [
            "type" => "sendSms",
            "isYou" => 1,
            "mobile_id" => $sms_status_container[0]['mobile_id'],
            "read_status" => null,
            "send_status" => 0,
            "timestamp" => $current_ts,
            "ts_sent" => null,
            "ts_written" => $current_ts,
            "ts_received" => null,
            "user" => "You",
            "web_status" => null,
            "gsm_id" => 1,
            "convo_id" => $convo_id_container,
            "sms_msg" => $message,
            "data" => $sms_status_container
        ];
        return $result;
    }

    function callLogs($data, $recipients){
        try {
            $sms_status_container = [];
            $convo_id_container = [];
            foreach ($recipients as $recipient) {
            $insert_smsoutbox_query = 'INSERT INTO smsoutbox_users VALUES (0,"'.$data->timestamp.'","central","'.$data->message.'")';
            $smsoutbox = $this->dbconn->query($insert_smsoutbox_query);
                array_push($convo_id_container, $this->dbconn->insert_id);
                if ($smsoutbox == true) {
                    $insert_smsoutbox_status = "INSERT INTO smsoutbox_user_status VALUES (0,'".$this->dbconn->insert_id."','".$recipient."','".$data->timestamp."',-1,0,'".$this->getGsmId($recipient)."')";      
                    $smsoutbox_status = $this->dbconn->query($insert_smsoutbox_status);
                    if ($smsoutbox_status == true) {
                        $stats = [
                            "status" => $smsoutbox_status,
                            "mobile_id" => $recipient
                        ];
                        array_push($sms_status_container, $stats);
                    } else {
                        return -1;
                    }
                } else {
                    return -1;
                }
            }

            $result = [
                "type" => "callLogSaved",
                "isYou" => 1,
                "mobile_id" => $sms_status_container[0]['mobile_id'],
                "read_status" => null,
                "send_status" => 0,
                "timestamp" => $data->timestamp,
                "ts_sent" => $data->timestamp,
                "ts_written" => $data->timestamp,
                "ts_received" => null,
                "user" => "You",
                "web_status" => null,
                "gsm_id" => 1,
                "convo_id" => $convo_id_container,
                "sms_msg" => $data->message,
                "data" => $sms_status_container,
                "account_id" => $data->tagger_user_id
            ];

            $tag_data = [
                "recipients" => $recipients,
                "tag" => "#EwiCallAck",
                "full_name" => "You",
                "ts" => $data->timestamp,
                "time_sent" => "",
                "msg" => $data->message,
                "account_id" => $data->tagger_user_id,
                "tag_important" => true,
                "site_code" => $data->site_code,
            ];
            $this->tagMessage($tag_data);

            return $result;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function getGsmId($mobile_id) {
        try {
            $gsm_id_query = "SELECT gsm_id FROM user_mobile WHERE mobile_id = '".$mobile_id."'";
            $gsm_container = $this->dbconn->query($gsm_id_query);
            $gsm_id = "";
            while ($row = $gsm_container->fetch_assoc()) {
                $gsm_id = $row['gsm_id'];
            }
            return $gsm_id;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function fetchSmsInboxData($inbox_id) {
        try {
            $inbox_data = "SELECT smsinbox_users.inbox_id, smsinbox_users.ts_sms, smsinbox_users.mobile_id, smsinbox_users.sms_msg, 
                            smsinbox_users.read_status, smsinbox_users.web_status,smsinbox_users.gsm_id,user_mobile.sim_num, CONCAT(sites.site_code,' ',user_organization.org_name, ' - ', users.lastname, ', ', users.firstname) as full_name, users.user_id 
                            FROM smsinbox_users INNER JOIN user_mobile ON smsinbox_users.mobile_id = user_mobile.mobile_id 
                            INNER JOIN users ON user_mobile.user_id = users.user_id INNER JOIN user_organization ON users.user_id = user_organization.user_id INNER JOIN sites ON user_organization.fk_site_id = sites.site_id WHERE smsinbox_users.inbox_id = '".$inbox_id."';";
            $execute_query = $this->dbconn->query($inbox_data);
            $distinct_numbers = "";
            $all_numbers = [];
            $all_messages = [];
            $quick_inbox_messages = [];
            $ctr = 0;

            if ($execute_query->num_rows > 0) {
                while ($row = $execute_query->fetch_assoc()) {
                    $normalized_number = substr($row["sim_num"], -10);
                    $all_messages[$ctr]['user_id'] = $row['user_id'];
                    $all_messages[$ctr]['sms_id'] = $row['inbox_id'];
                    $all_messages[$ctr]['full_name'] = strtoupper($row['full_name']);
                    $all_messages[$ctr]['user_number'] = $normalized_number;
                    $all_messages[$ctr]['mobile_id'] = $row['mobile_id'];
                    $all_messages[$ctr]['msg'] = $row['sms_msg'];
                    $all_messages[$ctr]['gsm_id'] = $row['gsm_id'];
                    $all_messages[$ctr]['ts_received'] = $row['ts_sms'];
                    $ctr++;
                }

                $full_data['data'] = $all_messages;
            } else {
                $inbox_data = "SELECT smsinbox_users.inbox_id, smsinbox_users.ts_sms, smsinbox_users.mobile_id, smsinbox_users.sms_msg, 
                                smsinbox_users.read_status, smsinbox_users.web_status,smsinbox_users.gsm_id,user_mobile.sim_num, CONCAT(dewsl_teams.team_code,' - ', users.lastname, ', ', users.firstname) as full_name, users.user_id 
                                FROM smsinbox_users INNER JOIN user_mobile ON smsinbox_users.mobile_id = user_mobile.mobile_id 
                                INNER JOIN users ON user_mobile.user_id = users.user_id INNER JOIN dewsl_team_members ON users.user_id = dewsl_team_members.users_users_id 
                                INNER JOIN dewsl_teams ON dewsl_team_members.dewsl_teams_team_id = dewsl_teams.team_id WHERE smsinbox_users.inbox_id = '".$inbox_id."';";
                $execute_query = $this->dbconn->query($inbox_data);
                $distinct_numbers = "";
                $all_numbers = [];
                $all_messages = [];
                $quick_inbox_messages = [];
                $ctr = 0;

                if ($execute_query->num_rows > 0) {
                    while ($row = $execute_query->fetch_assoc()) {
                        $normalized_number = substr($row["sim_num"], -10);
                        $all_messages[$ctr]['user_id'] = $row['user_id'];
                        $all_messages[$ctr]['sms_id'] = $row['inbox_id'];
                        $all_messages[$ctr]['full_name'] = strtoupper($row['full_name']);
                        $all_messages[$ctr]['user_number'] = $normalized_number;
                        $all_messages[$ctr]['mobile_id'] = $row['mobile_id'];
                        $all_messages[$ctr]['msg'] = $row['sms_msg'];
                        $all_messages[$ctr]['gsm_id'] = $row['gsm_id'];
                        $all_messages[$ctr]['ts_received'] = $row['ts_sms'];
                        $ctr++;
                    }
                    $full_data['data'] = $all_messages;
                } else {
                    echo "0 results\n";
                    $full_data['data'] = null;
                }
            }
            $full_data['type'] = "newSmsInbox";
            return $full_data;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function updateSmsOutboxStatus($outbox_id) {
        try {
            $full_data['type'] = 'smsoutboxStatusUpdate';
            $status_update = [];
            $outbox_data = "SELECT * FROM smsoutbox_user_status WHERE outbox_id = '".$outbox_id."'";
            $execute_query = $this->dbconn->query($outbox_data);
            if ($execute_query->num_rows > 0) {
                while ($row = $execute_query->fetch_assoc()) {
                    $status_update = [
                        "stat_id" => $row['stat_id'],
                        "outbox_id" => $row['outbox_id'],
                        "mobile_id" => $row['mobile_id'],
                        "ts_sent" => $row['ts_sent'],
                        "send_status" => $row['send_status'],
                        "gsm_id" => $row['gsm_id']
                    ];
                }
                $full_data['data'] = $status_update;
            } else {
                echo "0 results\n";
                $full_data['data'] = null;
            }
            return $full_data;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function fetchImportantGintags() {
        try {
            $gintags_query = "SELECT gintags_reference.tag_name FROM gintags_reference INNER JOIN gintags_manager ON gintags_reference.tag_id = gintags_manager.tag_id_fk;";
            $tags = [];
            $execute_query = $this->dbconn->query($gintags_query);
            if ($execute_query->num_rows > 0) {
                while ($row = $execute_query->fetch_assoc()) {
                    array_push($tags, $row['tag_name']);
                }
                $full_data['data'] = $tags;
            } else {
                echo "0 results\n";
                $full_data['data'] = null;
            }
            $full_data['type'] = "fetchedImportantTags";
            return $full_data;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function fetchSmsTags($sms_id, $table_used) {
        try {
            $tags = [];
            $tags_information = [];
            $get_tags_query = "SELECT * FROM gintags INNER JOIN gintags_reference ON tag_id_fk = gintags_reference.tag_id WHERE table_element_id = '".$sms_id."' AND table_used = '".$table_used."';";
            $execute_query = $this->dbconn->query($get_tags_query);
            if ($execute_query->num_rows > 0) {
                while ($row = $execute_query->fetch_assoc()) {
                    if (in_array($row['tag_name'], $tags) == false) {
                        array_push($tags,$row['tag_name']);
                        array_push($tags_information,$row);
                    }
                }
                $full_data['data'] = $tags;
                $full_data['tag_information'] = $tags_information;
            } else {
                $full_data['data'] = [];
            }
            $full_data['type'] = "fetchedSmsTags";
            return $full_data;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function tagMessage($data) {
        try {
            $status = false;
            $full_data['type'] = "taggingStatus";
            if ($data['tag_important'] != true) {
                $insert_tag_status = $this->insertTag($data); 
                $full_data['tag_status'] = $insert_tag_status;
                $full_data['status'] = true;
            } else {
                $full_data['tag_status'] = $this->tagToNarratives($data);
                $full_data['status'] = true;
            }
            
            return $full_data;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function deleteTags($data){
        try {
            foreach ($data as $gintags_id) {
                $delete_tag = "DELETE FROM gintags WHERE gintags_id = '".$gintags_id."'";
                $execute_query = $this->dbconn->query($delete_tag);
            }

            $full_data['status'] = true;
            $full_data['type'] = "deleteTagStatus";
            return $full_data;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function getUserAndSiteAssociationViaMobile_id($mobile_id) {
        try {
            $site_container = [];
            $get_user_sites_associate = "SELECT 
                user_mobile.user_id, fk_site_id, org_name, site_code
            FROM
                user_mobile
                    INNER JOIN
                user_organization ON user_mobile.user_id = user_organization.user_id
                    INNER JOIN
                sites ON user_organization.fk_site_id = sites.site_id
            WHERE
                mobile_id = '".$mobile_id."'";

            $execute_query = $this->dbconn->query($get_user_sites_associate);
            while ($row = $execute_query->fetch_assoc()) {
                array_push($site_container, $row);
            }

            return $site_container;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function tagToNarratives($data) {
        try {
            $event_container = [];
            $offices = [];
            $result = null;
            $message = str_replace("'","\'",$data['msg']);

            if (isset($data['sms_id']) == true) {
                $insert_tag_status = $this->insertTag($data);
                if ($insert_tag_status['status'] == true) {
                    $time_sent = null;
                    $narrative_input = $this->getNarrativeInput($data['tag']);
                    if($data['tag'] == "#EwiCallAck"){
                        $template = $message;
                    }else{
                        $template = $narrative_input->fetch_assoc()['narrative_input'];
                    }
                    $raw_office = explode(" ",$data['full_name']);
                    $office = [$raw_office[1]];
                    $get_ongoing_query = "SELECT site_code,sites.site_id,event_id FROM senslopedb.public_alert_event INNER JOIN sites ON public_alert_event.site_id = sites.site_id where status = 'on-going';";
                    $ongoing = $this->senslope_dbconn->query($get_ongoing_query);

                    while ($row = $ongoing->fetch_assoc()) {
                        if (in_array(strtoupper($row['site_code']),$data['site_code'])) {
                            array_push($event_container, $row);
                        }
                    }
                    // $time_sent = $this->setTimeSent($data['ts'], $data['time_sent']);
                    foreach ($event_container as $sites) {
                        $narrative = $this->parseTemplateCodes($offices, $sites['site_id'], $data['ts'], $time_sent, $template, $message, $data['full_name']);
                        $sql = "INSERT INTO narratives VALUES(0,'".$sites['site_id']."','".$sites['event_id']."','".$data['ts']."','".$narrative."')";
                        $result = $this->senslope_dbconn->query($sql);
                    }

                } else {
                    // Add error message
                }

            } else {
                $insert_tag_status = $this->insertTag($data);
                if ($insert_tag_status['status'] == true) {
                    $filter_builder = "";

                    for ($counter=0; $counter < sizeOf($data['recipients']); $counter++) { 
                        if ($counter == 0 ) {
                            $filter_builder = " mobile_id = ".$data['recipients'][$counter];
                        } else {
                            $filter_builder = $filter_builder." or mobile_id = ".$data['recipients'][$counter];
                        }
                    }

                    $get_site = "SELECT DISTINCT site_code FROM comms_db.user_mobile INNER JOIN user_organization ON user_mobile.user_id = user_organization.user_id INNER JOIN sites ON user_organization.fk_site_id = sites.site_id WHERE ".$filter_builder.";";
                    $site_code = $this->dbconn->query($get_site);
                    $site_code = $site_code->fetch_assoc()['site_code'];
                    $get_ongoing_query = "SELECT site_code,sites.site_id,event_id FROM senslopedb.public_alert_event INNER JOIN sites ON public_alert_event.site_id = sites.site_id where status = 'on-going';";
                    $ongoing = $this->senslope_dbconn->query($get_ongoing_query);

                    while ($row = $ongoing->fetch_assoc()) {
                        if (in_array(strtoupper($row['site_code']),$data['site_code'])) {
                            array_push($event_container, $row);
                        }
                    }

                    if (sizeOf($event_container) != 0) {
                        $narrative_input = $this->getNarrativeInput($data['tag']);
                        if($data['tag'] == "#EwiCallAck"){
                            $template = $message;
                        }else{
                            $template = $narrative_input->fetch_assoc()['narrative_input'];
                        }
                        
                        $get_offices_query = "SELECT DISTINCT org_name FROM comms_db.user_mobile INNER JOIN user_organization ON user_mobile.user_id = user_organization.user_id INNER JOIN sites ON user_organization.fk_site_id = sites.site_id WHERE ".$filter_builder.";";
                        $get_office = $this->dbconn->query($get_offices_query);
                        while ($row = $get_office->fetch_assoc()) {
                            array_push($offices, $row['org_name']);
                        }

                        foreach ($event_container as $sites) {
                            $narrative = $this->parseTemplateCodes($offices, $sites['site_id'], $data['ts'], $data['time_sent'], $template, $message);
                            $sql = "INSERT INTO narratives VALUES(0,'".$sites['site_id']."','".$sites['event_id']."','".$data['ts']."','".$narrative."');";
                            $result = $this->senslope_dbconn->query($sql);
                        }
                    }

                } else {
                    // Add error message
                }
            }
            return $result;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function setTimeSent ($timestamp, $time_sent) {
        try {
            $time_state = null;
            if(strtotime($timestamp) >= strtotime(date("Y-m-d 00:00:01")) && strtotime($timestamp) < strtotime(date("Y-m-d 11:59:59"))){
                $time_state = "AM";
            }else if(strtotime($timestamp) == strtotime(date("Y-m-d 12:00:00"))){
                $time_state = "NN";
            }else if(strtotime($timestamp) >= strtotime(date("Y-m-d 12:01:00")) && strtotime($timestamp) < strtotime(date("Y-m-d 23:59:59"))) {
                $time_state = "PM";
            }else if(strtotime($timestamp) == strtotime(date("Y-m-d 00:00:00"))){
                $time_state = "MN";
            }
            $time = "$time_sent $time_state";
            return $time;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function searchConvoIdViaMessageAttribute($ts, $msg, $recipients) {
        try {
            $convo_id_container = [];
            $filter_builder = "";

            for ($counter=0; $counter < sizeOf($recipients); $counter++) { 
                if ($counter == 0 ) {
                    $filter_builder = " mobile_id = ".$recipients[$counter];
                } else {
                    $filter_builder = $filter_builder." or mobile_id = ".$recipients[$counter];
                }
            }

            $message = str_replace("'","\'",$msg);
            $convo_id_query = "SELECT * FROM smsoutbox_users natural join smsoutbox_user_status where sms_msg LIKE '%".$message."%' and ts_written = '".$ts."' and (".$filter_builder.") order by ts_written desc;";
            $execute_query = $this->dbconn->query($convo_id_query);
            while ($row = $execute_query->fetch_assoc()) {
                array_push($convo_id_container, $row['outbox_id']);
            }
            return $convo_id_container;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function insertTag($data) {
        try {
            $convo_id_collection = [];
            $tag_exist_query = "SELECT * FROM gintags_reference WHERE tag_name = '".$data['tag']."'";
            $execute_query = $this->dbconn->query($tag_exist_query);
            if ($execute_query->num_rows == 0) {
                $tag_message_query = "INSERT INTO gintags_reference VALUES (0,'".$data['tag']."','NULL')";
                $execute_query = $this->dbconn->query($tag_message_query);
                if ($execute_query == true) {
                    $status = true;
                    $last_inserted_id = $this->dbconn->insert_id;
                }
            } else {
                $status = true;
                $last_inserted_id = $execute_query->fetch_assoc()['tag_id'];
            }

            if ($data['full_name'] == "You") {
                $database_reference = "smsoutbox_users";
                $convo_id_collection = $this->searchConvoIdViaMessageAttribute($data['ts'], str_replace("<br />","\n",$data['msg']), $data['recipients']);
            } else {
                $database_reference = "smsinbox_users";
                array_push($convo_id_collection, $data['sms_id']);
            }

            foreach ($convo_id_collection as $id) {
                if ($status == true) {
                    $tag_insertion_query = "INSERT INTO gintags VALUES (0,'".$last_inserted_id."','".$data['account_id']."','".$id."','".$database_reference."','".$data['ts']."','Null')";
                    $execute_query = $this->dbconn->query($tag_insertion_query);
                }
            }

            $full_data['type'] = "messageTaggingStatus";
            if ($execute_query == true) {
                $full_data['status_message'] = "Successfully tagged message!";
                $full_data['status'] = true;
            } else {
                $full_data['status_message'] = "Failed to tag message!";
                $full_data['status'] = false;
            }
            return $full_data;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function autoNarrative($offices, $event_id, $site_id,$data_timestamp, $timestamp, $tag, $msg, $previous_release_time = "", $event_start = "") {
        try {
            $narrative_input = $this->getNarrativeInput($tag);
            $template = $narrative_input->fetch_assoc()['narrative_input'];
            if (($tag == "#EwiMessage" || $tag == "#AlteredEwi") && strtotime ('-30 minute' , strtotime ($data_timestamp)) != strtotime($event_start)) {
                
                $check_ack = "SELECT * FROM narratives WHERE timestamp BETWEEN ('".$data_timestamp."' - interval 240 minute) AND '".$data_timestamp."' AND (event_id = '".$event_id."' AND narrative LIKE '%EWI SMS acknowledged by%')";
                $ack_result = $this->senslope_dbconn->query($check_ack);
                if ($ack_result->num_rows == 0){
                    $timestamp_release_date = strtotime ( '-1 second' , strtotime ( $data_timestamp ) ) ;
                    $timestamp_release_date = date ( "Y-m-d H:i:s" , $timestamp_release_date );

                    $no_ack_narrative_input = $this->getNarrativeInput("#NoAckEwi");
                    $no_ack_template = $no_ack_narrative_input->fetch_assoc()['narrative_input'];
                    if (strtotime($event_start.'+4hours') != strtotime($data_timestamp) && strtotime($data_timestamp.'-4hours') < strtotime($event_start)) {
                        $previous_release_time = "onset";
                    }
                    $no_ack_narrative = $this->parseTemplateCodes($offices, $site_id, $data_timestamp, $previous_release_time, $no_ack_template, $msg);
                    $sql = "INSERT INTO narratives VALUES(0,'".$site_id."','".$event_id."','".$timestamp_release_date."','".$no_ack_narrative."')";
                    $this->senslope_dbconn->query($sql);
                }
            }

            $narrative = $this->parseTemplateCodes($offices, $site_id, $data_timestamp, $timestamp, $template, $msg);
            if ($template != "") {
                $sql = "INSERT INTO narratives VALUES(0,'".$site_id."','".$event_id."','".date("Y-m-d H:i:s")."','".$narrative."')";
                $result = $this->senslope_dbconn->query($sql);
            } else {
                $result = false;
                echo "No templates fetch..\n\n";
            }
            return $result;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function autoTagMessage($acc_id, $sms_id, $ts,$tag = "#EwiMessage") {
        try {
            $status = [];
            $get_tag_id = "SELECT tag_id FROM gintags_reference WHERE tag_name = '".$tag."'";
            $tag_id_container = $this->dbconn->query($get_tag_id);
            while ($row = $tag_id_container->fetch_assoc()) {
                $tag_insertion_query = "INSERT INTO gintags VALUES (0,'".$row['tag_id']."','".$acc_id."','".$sms_id."','smsoutbox_users','".$ts."','Null')";
                $tag_message = $this->dbconn->query($tag_insertion_query);
                array_push($status, $tag_message);
            }
            return $status;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function routineNarrative ($site_id, $timestamp){
        try {
            $start_time = date("Y-m-d 12:00:00", time());
            $end_time = date("Y-m-d 13:00:00", time());
            if($site_id != 0){
                $check_narrative_query = "SELECT * FROM narratives WHERE site_id = '".$site_id."' AND narrative LIKE '%Sent Routine Message to LEWC, BLGU, MLGU%' AND timestamp >= '".$start_time."' AND timestamp <= '".$end_time."';";
                $narrative_checker_result = $this->senslope_dbconn->query($check_narrative_query);
                if ($narrative_checker_result->num_rows == 0) {
                    if($timestamp >= $start_time && $timestamp <= $end_time){
                        $sql = "INSERT INTO narratives VALUES(0,'".$site_id."',NULL,'".$timestamp."','Sent Routine Message to LEWC, BLGU, MLGU')";
                        $this->senslope_dbconn->query($sql);
                    }
                }
            }
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function getNarrativeInput($tag) {
        try {
            $get_tag_narrative = "SELECT narrative_input FROM comms_db.gintags_manager INNER JOIN gintags_reference ON gintags_manager.tag_id_fk = gintags_reference.tag_id WHERE gintags_reference.tag_name = '".$tag."';";
            $narrative_input = $this->dbconn->query($get_tag_narrative);
            return $narrative_input;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }

    }

    function parseTemplateCodes($offices, $site_id, $data_timestamp, $timestamp, $template, $msg, $full_name = "") {
        try {
            $codes = ["(sender)","(sms_msg)","(current_release_time)","(stakeholders)","(previous_release_time)"];
            if ($timestamp == "12:00 AM"){
                $timestamp = "12:00 MN";
            }else if ($timestamp == "12:00 PM"){
                $timestamp = "12:00 NN";
            }
            foreach ($codes as $code) {
                switch ($code) {
                    case '(sender)':
                        $template = str_replace($code,$full_name,$template);
                        break;

                    case '(sms_msg)':
                        $template = str_replace($code, $msg,$template);
                        break;

                    case '(current_release_time)':
                        $raw_time = explode(":",$timestamp);
                        if (strlen($raw_time[0]) == 1) {$timestamp = "0".$timestamp;}
                        $template = str_replace($code,$timestamp,$template);
                        break;
                    case '(previous_release_time)':
                        $raw_time = explode(":",$timestamp);
                        if (strlen($raw_time[0]) == 1) {$timestamp = "0".$timestamp;}
                        $template = str_replace($code,$timestamp,$template);
                        break;
                    case '(stakeholders)':
                        $stakeholders = "";
                        $counter = 0;
                        foreach ($offices as $office) {
                            if ($counter == 0) {
                                $stakeholders = $office;
                            } else {
                                $stakeholders = $stakeholders.", ".$office;
                            }
                            $counter++;
                        }
                        $template = str_replace($code,$stakeholders,$template);
                        break;

                    default:
                        $template = str_replace($code,'NA',$template);
                        break;
                }
            }
            return $template;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function fetchSitesForRoutine() {
        try {
            $sites_query = "SELECT site_id,site_code,season from sites;";
            $sites = [];
            $execute_query = $this->dbconn->query($sites_query);
            if ($execute_query->num_rows > 0) {
                while ($row = $execute_query->fetch_assoc()) {
                    $raw = [
                        "id" => $row['site_id'],
                        "site" => $row['site_code'],
                        "season" => $row['season']
                    ];
                    array_push($sites, $raw);
                }
                $full_data['data'] = $sites;
            } else {
                echo "0 results\n";
                $full_data['data'] = null;
            }
            $full_data['type'] = "fetchSitesForRoutine";
            return $full_data; 
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function fetchRoutineReminder() {
        try {
            $routine_query = "SELECT * from ewi_backbone_template WHERE alert_status = 'GndMeasReminder';";
            $template = [];
            $execute_query = $this->dbconn->query($routine_query);
            if ($execute_query->num_rows > 0) {
                while ($row = $execute_query->fetch_assoc()) {
                    array_push($template, $row);
                }
                $full_data['data'] = $template;
            } else {
                echo "0 results\n";
                $full_data['data'] = null;
            }
            $full_data['type'] = "fetchRoutineReminder";
            return $full_data;  
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function fetchRoutineTemplate() {
        try {
            $routine_query = "SELECT * from ewi_backbone_template WHERE alert_status = 'Routine';";
            $template = [];
            $execute_query = $this->dbconn->query($routine_query);
            if ($execute_query->num_rows > 0) {
                while ($row = $execute_query->fetch_assoc()) {
                    array_push($template, $row);
                }
                $full_data['data'] = $template;
            } else {
                echo "0 results\n";
                $full_data['data'] = null;
            }
            $full_data['type'] = "fetchRoutineTemplate";
            return $full_data;   
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function fetchAlertStatus() {
        try {
            $alert_query = "SELECT distinct alert_status FROM ewi_template;";
            $alert_collection = [];
            $site_collection = [];
            $execute_query = $this->dbconn->query($alert_query);
            if ($execute_query->num_rows > 0) {
                while ($row = $execute_query->fetch_assoc()) {
                    array_push($alert_collection, $row);
                }
            } else {
                echo "0 results\n";
                $alert_collection = null;
            }

            $site_query = "SELECT distinct site_code FROM sites;";
            $execute_query = $this->dbconn->query($site_query);
            if ($execute_query->num_rows > 0) {
                while ($row = $execute_query->fetch_assoc()) {
                    array_push($site_collection, $row);
                }
            } else {
                echo "0 results\n";
                $site_collection = null;
            }

            $full_data ['data'] = [
                "site_code" => $site_collection,
                "alert_status" => $alert_collection
            ];

            $full_data['type'] = "fetchAlertStatus";
            return $full_data;  
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function fetchEWISettings($alert_status) {
        try {
            $settings_collection = [];
            $settings_query = "SELECT distinct alert_symbol_level FROM ewi_template where alert_status like '%".$alert_status."%';";
            $execute_query = $this->dbconn->query($settings_query);
            if ($execute_query->num_rows > 0) {
                while ($row = $execute_query->fetch_assoc()) {
                    array_push($settings_collection, $row);
                }
                $full_data['data'] = $settings_collection;
            } else {
                echo "0 results\n";
                $alert_collection = null;
            }
            $full_data['type'] = "fetchEWISettings";
            return $full_data;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function fetchEventTemplate($template_data) {
        try {
            $site_query = "SELECT * FROM sites WHERE site_code = '".$template_data->site_name."';";
            $site_container = [];
            $ewi_backbone_container = [];
            $ewi_key_input_container = [];
            $ewi_recommended_container = [];
            $extended_day = 0;
            $execute_query = $this->dbconn->query($site_query);
            if ($execute_query->num_rows > 0) {
                while ($row = $execute_query->fetch_assoc()) {
                    array_push($site_container, $row);
                }
            } else {
                echo "0 results\n";
            }

            if($template_data->alert_level == "A0") {
                $alert_status = null;
                if($template_data->event_category == "event"){// for lowering
                    $alert_status = "Lowering";
                }else {// for extended
                    $alert_status = "Extended";
                    $extended_day = $template_data->ewi_details->day;
                }

                $ewi_backbone_query = "SELECT * FROM ewi_backbone_template WHERE alert_status = '".$alert_status."';";
                $execute_query = $this->dbconn->query($ewi_backbone_query);
                if ($execute_query->num_rows > 0) {
                    while ($row = $execute_query->fetch_assoc()) {
                        array_push($ewi_backbone_container, $row);
                    }
                } else {
                    echo "0 results\n";
                }
            }else {
                 $ewi_backbone_query = "SELECT * FROM ewi_backbone_template WHERE alert_status = '".$template_data->alert_status."';";
                $execute_query = $this->dbconn->query($ewi_backbone_query);
                if ($execute_query->num_rows > 0) {
                    while ($row = $execute_query->fetch_assoc()) {
                        array_push($ewi_backbone_container, $row);
                    }
                } else {
                    echo "0 results\n";
                }
            }

            $key_input_query = "SELECT * FROM ewi_template WHERE alert_symbol_level = '".$template_data->internal_alert."' AND alert_status = '".$template_data->alert_status."';";
            $execute_query = $this->dbconn->query($key_input_query);
            if ($execute_query->num_rows > 0) {
                while ($row = $execute_query->fetch_assoc()) {
                    array_push($ewi_key_input_container, $row);
                }
            } else {
                echo "0 results\n";
            }
            
            if ($template_data->alert_level == "ND"){
                $template_data->alert_level = "A1";
                $extended_day = $template_data->ewi_details->day;
            }

            $alert_level = str_replace('A','Alert ',$template_data->alert_level);
            $recom_query = "SELECT * FROM ewi_template WHERE alert_symbol_level = '".$alert_level."' AND alert_status = '".$template_data->alert_status."';";
            $execute_query = $this->dbconn->query($recom_query);
            if ($execute_query->num_rows > 0) {
                while ($row = $execute_query->fetch_assoc()) {
                    array_push($ewi_recommended_container, $row);
                }
            } else {
                echo "0 results\n";
            }

            $on_set = null;

            if($template_data->ewi_details->event_start === $template_data->ewi_details->data_timestamp) {
                $on_set = true;
            }else {
                $on_set = false;
            }
            $raw_template = [
                "site" => $site_container,
                "backbone" => $ewi_backbone_container,
                "tech_info" => $ewi_key_input_container,
                "recommended_response" => $ewi_recommended_container,
                "formatted_data_timestamp" => $template_data->formatted_data_timestamp,
                "data_timestamp" => $template_data->data_timestamp,
                "on_set" => $on_set,
                "alert_level" => $alert_level,
                "event_category" => $template_data->event_category,
                "extended_day" => $extended_day
            ];

            $full_data['data'] = $this->reconstructEWITemplate($raw_template);
            return $full_data;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function reconstructEWITemplate($raw_data) {
        try {
            $counter = 0;
            $time_submission = null;
            $date_submission = null;
            $ewi_time = null;
            $greeting = null;
            date_default_timezone_set('Asia/Manila');
            $current_date = date('Y-m-d H:i:s');//H:i:s
            $final_template = $raw_data['backbone'][0]['template'];
            $site_details = $this->generateSiteDetails($raw_data);
            $greeting = $this->generateGreetingsMessage(strtotime('+30 minutes', strtotime($raw_data['data_timestamp'])));

            if(isset($on_set) && $on_set == true){
                $time_messages = $this->generateTimeMessages(strtotime($raw_data['data_timestamp']));
            }else {
                $time_messages = $this->generateTimeMessages(strtotime(date('Y-m-d H:i:s', strtotime('+30 minutes', strtotime($raw_data['data_timestamp'])))));
            }

            if($raw_data['alert_level'] == "Alert 0" || $raw_data['event_category'] == "extended" && $raw_data['alert_level'] == "Alert 1"){
                $final_template = str_replace("(site_location)",$site_details,$final_template);
                $final_template = str_replace("(alert_level)",$raw_data['alert_level'],$final_template);
                $final_template = str_replace("(current_date_time)",$raw_data['formatted_data_timestamp'],$final_template);
                $final_template = str_replace("(greetings)",$greeting,$final_template);
                if($raw_data['event_category'] == "extended"){
                    $extended_day_text = $this->generateExtendedDayMessage($raw_data['extended_day']);
                    $final_template = str_replace("(current_date)",$raw_data['formatted_data_timestamp'],$final_template);
                    $final_template = str_replace("(nth-day-extended)",$extended_day_text ,$final_template);
                }else if ($raw_data['event_category'] == "event") {
                    $final_template = str_replace("(current_date_time)",$raw_data['formatted_data_timestamp'],$final_template);
                    $final_template = str_replace("(nth-day-extended)",$raw_data['extended_day'] . "-day" ,$final_template);
                }
            } else {
                $final_template = str_replace("(site_location)",$site_details,$final_template);
                $final_template = str_replace("(alert_level)",$raw_data['alert_level'],$final_template);
                $final_template = str_replace("(current_date_time)",$raw_data['formatted_data_timestamp'],$final_template);
                $final_template = str_replace("(technical_info)",$raw_data['tech_info'][0]['key_input'],$final_template);
                $final_template = str_replace("(recommended_response)",$raw_data['recommended_response'][0]['key_input'],$final_template);
                $final_template = str_replace("(gndmeas_date_submission)",$time_messages["date_submission"],$final_template);
                $final_template = str_replace("(gndmeas_time_submission)",$time_messages["time_submission"],$final_template);
                $final_template = str_replace("(next_ewi_time)",$time_messages["next_ewi_time"],$final_template);
                $final_template = str_replace("(greetings)",$greeting,$final_template);
            }
            return $final_template;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function generateSiteDetails($raw_data) {
        try {
            if (($raw_data['site'][0]['purok'] == "" || $raw_data['site'][0]['purok'] == NULL) && $raw_data['site'][0]['sitio'] != NULL) {
                $reconstructed_site_details = $raw_data['site'][0]['sitio'].", ".$raw_data['site'][0]['barangay'].", ".$raw_data['site'][0]['municipality'].", ".$raw_data['site'][0]['province'];
            } else if ($raw_data['site'][0]['sitio'] == "" || $raw_data['site'][0]['sitio'] == NULL) {
                 $reconstructed_site_details = $raw_data['site'][0]['barangay'].", ".$raw_data['site'][0]['municipality'].", ".$raw_data['site'][0]['province'];
            } else if (($raw_data['site'][0]['sitio'] == "" || $raw_data['site'][0]['sitio'] == NULL) && ($raw_data['site'][0]['purok'] == "" || $raw_data['site'][0]['purok'] == NULL)) {
                $reconstructed_site_details = $raw_data['site'][0]['barangay'].", ".$raw_data['site'][0]['municipality'].", ".$raw_data['site'][0]['province'];
            } else {
                 $reconstructed_site_details = $raw_data['site'][0]['purok'].", ".$raw_data['site'][0]['sitio'].", ".$raw_data['site'][0]['barangay'].", ".$raw_data['site'][0]['municipality'].", ".$raw_data['site'][0]['province'];
            }

            return $reconstructed_site_details;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function generateExtendedDayMessage($day) {
        try {
            if($day == 3){
                $extended_day_message = "susunod na routine";
            }else if($day == 2) {
                $extended_day_message = "huling araw ng 3-day extended";
            }else if($day == 1) {
                $extended_day_message = "ikalawang araw ng 3-day extended";
            }

            return $extended_day_message;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function generateTimeMessages($release_time) {
        try {
            if($release_time >= strtotime(date("Y-m-d 00:00:00")) && $release_time < strtotime(date("Y-m-d 03:59:59"))){
              $date_submission = "mamaya";
              $time_submission = "bago mag-7:30 AM";
              $next_ewi_time = "4:00 AM";
            } 
            else if($release_time >= strtotime(date("Y-m-d 04:00:00")) && $release_time < strtotime(date("Y-m-d 07:59:59"))){
              $date_submission = "mamaya";
              $time_submission = "bago mag-7:30 AM";
              $next_ewi_time = "8:00 AM";
            } 
            else if($release_time >= strtotime(date("Y-m-d 08:00:00")) && $release_time < strtotime(date("Y-m-d 11:59:59"))){
              $date_submission = "mamaya";
              $time_submission = "bago mag-11:30 AM";
              $next_ewi_time = "12:00 NN";
            } 
            else if($release_time >= strtotime(date("Y-m-d 12:00:00")) && $release_time < strtotime(date("Y-m-d 15:59:59"))){
              $date_submission = "mamaya";
              $time_submission = "bago mag-3:30 PM";
              $next_ewi_time = "4:00 PM";
            } 
            else if($release_time >= strtotime(date("Y-m-d 16:00:00")) && $release_time < strtotime(date("Y-m-d 19:59:59"))){
              $date_submission = "bukas";
              $time_submission = "bago mag-7:30 AM";
              $next_ewi_time = "8:00 PM";
            } 
            else if($release_time >= strtotime(date("Y-m-d 20:00:00"))){
              $date_submission = "bukas";
              $time_submission = "bago mag-7:30 AM";
              $next_ewi_time = "12:00 MN";
            } 
            else {
              $date_submission = "mamaya";
              $time_submission = "bago mag-7:30 AM";
              $next_ewi_time = "4:00 AM";
            }

            $timeTemplate = [
              "date_submission" => $date_submission,
              "time_submission" => $time_submission,
              "next_ewi_time" => $next_ewi_time
            ];

            return $timeTemplate;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }

    }

    function generateGreetingsMessage($release_time) {
        try {
            if( $release_time >= strtotime(date("Y-m-d 18:00:00")) && $release_time <= strtotime(date("Y-m-d 23:59:59")) ){
              $greeting = "gabi";
            } 
            else if( $release_time == strtotime(date("Y-m-d 00:00:00")) ){
              $greeting = "gabi";
            } 
            else if( $release_time >= strtotime(date("Y-m-d 00:01:00")) && $release_time <= strtotime(date("Y-m-d 11:59:59")) ){
              $greeting = "umaga";
            }
            else if( $release_time == strtotime(date("Y-m-d 12:00:00")) ){
              $greeting = "tanghali";
            } 
            else if( $release_time >= strtotime(date("Y-m-d 12:01:00")) && $release_time <= strtotime(date("Y-m-d 17:59:59")) ){
              $greeting = "hapon";
            } 
            else {
              $greeting = "araw";
            }

            return $greeting;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }

    }

    function fetchSearchKeyViaGlobalMessages($search_key, $search_limit) {
        try {
            $search_key_container = [];
            $search_key_query = "SELECT smsinbox_users.sms_msg, CONCAT(users.firstname,' ',users.lastname) AS user, smsinbox_users.ts_sms AS ts, smsinbox_users.inbox_id AS sms_id, 'smsinbox' AS table_source , smsinbox_users.mobile_id as mobile_id
            FROM senslopedb.smsinbox_users INNER JOIN user_mobile ON smsinbox_users.mobile_id = user_mobile.mobile_id INNER JOIN users ON user_mobile.user_id = users.user_id WHERE sms_msg LIKE '".$search_key."' 
            UNION 
            SELECT smsoutbox_users.sms_msg, 'You' AS user, smsoutbox_user_status.ts_sent AS ts, smsoutbox_user_status.outbox_id AS sms_id, 'smsoutbox' AS table_source , smsoutbox_user_status.mobile_id
            from smsoutbox_users INNER JOIN smsoutbox_user_status ON smsoutbox_users.outbox_id = smsoutbox_user_status.outbox_id WHERE sms_msg LIKE '".$search_key."' order by ts desc limit ".$search_limit.";";

            $execute_query = $this->dbconn->query($search_key_query);
            if ($execute_query->num_rows > 0) {
                while ($row = $execute_query->fetch_assoc()) {
                    array_push($search_key_container, $row);
                }
            } else {
                echo "0 results\n";
            }
            $full_data['type'] = "fetchedSearchKeyViaGlobalMessage";
            $full_data['data'] = $search_key_container;
            return $full_data;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }

    }

    function fetchSearchKeyViaGintags($search_key, $search_limit) {
        try {
            $search_key_query = "SELECT smsinbox_users.sms_msg, CONCAT(users.firstname,' ',users.lastname) AS user, smsinbox_users.ts_sms AS ts, smsinbox_users.inbox_id AS sms_id, 'smsinbox' AS table_source , smsinbox_users.mobile_id as mobile_id
            FROM senslopedb.smsinbox_users 
            INNER JOIN user_mobile ON smsinbox_users.mobile_id = user_mobile.mobile_id 
            INNER JOIN users ON user_mobile.user_id = users.user_id 
            INNER JOIN gintags ON gintags.table_element_id = smsinbox_users.inbox_id
            INNER JOIN gintags_reference ON gintags_reference.tag_id = gintags.tag_id_fk WHERE gintags_reference.tag_name = '".$search_key."' limit ".$search_limit.";";

            $execute_query = $this->dbconn->query($search_key_query);
            if ($execute_query->num_rows > 0) {
                while ($row = $execute_query->fetch_assoc()) {
                    array_push($search_key_container, $row);
                }
            } else {
                echo "0 results\n";
            }
            $full_data['type'] = "fetchedSearchKeyViaGlobalMessage";
            $full_data['data'] = $search_key_container;
            return $full_data;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function fetchSearchedMessageViaGlobal($data) {
        try {
            $convo_container = [];
            $number_container = $this->getMobileDetails($data);
            $full_name = $this->getUserFullname($number_container[0]);
            $prev_messages_container = $this->getTwentySearchedPreviousMessages($number_container[0],$full_name,$data->ts);
            $latest_messages_container = $this->getTwentySearchedLatestMessages($number_container[0],$full_name,$data->ts);
            foreach ($latest_messages_container as $sms) {
                array_push($convo_container,$sms);
            }
            foreach ($prev_messages_container as $sms) {
                array_push($convo_container,$sms);
            }
            $full_data = [];
            $full_data['full_name'] = $full_name;
            $full_data['recipients'] = $number_container;
            $full_data['type'] = "loadSmsConversation";
            $full_data['data'] = $convo_container;
            return $full_data;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function getUserFullname($data) {
        try {
            $full_name_container = "";
            $full_name_query = "SELECT CONCAT(sites.site_code,' ',user_organization.org_name, ' - ', users.lastname, ', ', users.firstname) as full_name 
            from users INNER JOIN user_organization ON users.user_id = user_organization.user_id INNER JOIN sites ON user_organization.fk_site_id = sites.site_id where users.user_id = '".$data['user_id']."';";
            $execute_query = $this->dbconn->query($full_name_query);
            if ($execute_query->num_rows > 0) {
                while ($row = $execute_query->fetch_assoc()) {
                    $full_name_container = $full_name_container." ".$row['full_name'];
                }
            } else {
                echo "0 results\n";
            }

            return strtoupper($full_name_container);
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function getTwentySearchedPreviousMessages($details, $fullname, $ts) {
        try {
            $convo_container = [];
            $inbox_query = "SELECT smsinbox_users.inbox_id as convo_id, mobile_id, 
                            smsinbox_users.ts_sms as ts_received, null as ts_written, null as ts_sent, smsinbox_users.sms_msg,
                            smsinbox_users.read_status, smsinbox_users.web_status, smsinbox_users.gsm_id ,
                            null as send_status , ts_sms as timestamp , '".$fullname."' as user from smsinbox_users WHERE mobile_id = (SELECT mobile_id FROM user_mobile where sim_num LIKE '%".substr($details["sim_num"], -10)."%') and smsinbox_users.ts_sms <'".$ts."'";
            $outbox_query = "SELECT smsoutbox_users.outbox_id as convo_id, mobile_id,
                            null as ts_received, ts_written, ts_sent, sms_msg , null as read_status,
                            web_status, gsm_id , send_status , ts_written as timestamp, 'You' as user FROM smsoutbox_users INNER JOIN smsoutbox_user_status ON smsoutbox_users.outbox_id = smsoutbox_user_status.outbox_id WHERE smsoutbox_user_status.mobile_id = 
                            (SELECT mobile_id FROM user_mobile where sim_num LIKE '%".substr($details["sim_num"], -10)."%') and smsoutbox_users.ts_written <'".$ts."'";


            $full_query = "SELECT * FROM (".$inbox_query." UNION ".$outbox_query.") as full_contact group by sms_msg order by timestamp desc limit 20;";

            $fetch_convo = $this->dbconn->query($full_query);
            if ($fetch_convo->num_rows != 0) {
                while($row = $fetch_convo->fetch_assoc()) {
                    array_push($convo_container,$row);
                }
            } else {
                echo "No message fetched!";
            }
            return $convo_container;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }

    }

    function getTwentySearchedLatestMessages($details, $fullname, $ts) {
        try {
            $convo_container = [];
            $inbox_query = "SELECT smsinbox_users.inbox_id as convo_id, mobile_id, 
                            smsinbox_users.ts_sms as ts_received, null as ts_written, null as ts_sent, smsinbox_users.sms_msg,
                            smsinbox_users.read_status, smsinbox_users.web_status, smsinbox_users.gsm_id ,
                            null as send_status , ts_sms as timestamp , '".$fullname."' as user from smsinbox_users WHERE mobile_id = (SELECT mobile_id FROM user_mobile where sim_num LIKE '%".substr($details["sim_num"], -10)."%') and smsinbox_users.ts_sms >'".$ts."'";
            $outbox_query = "SELECT smsoutbox_users.outbox_id as convo_id, mobile_id,
                            null as ts_received, ts_written, ts_sent, sms_msg , null as read_status,
                            web_status, gsm_id , send_status , ts_written as timestamp, 'You' as user FROM smsoutbox_users INNER JOIN smsoutbox_user_status ON smsoutbox_users.outbox_id = smsoutbox_user_status.outbox_id WHERE smsoutbox_user_status.mobile_id = 
                            (SELECT mobile_id FROM user_mobile where sim_num LIKE '%".substr($details["sim_num"], -10)."%') and smsoutbox_users.ts_written >='".$ts."'";


            $full_query = "SELECT * FROM (".$inbox_query." UNION ".$outbox_query.") as full_contact group by sms_msg order by timestamp desc limit 21;";
            $fetch_convo = $this->dbconn->query($full_query);
            if ($fetch_convo->num_rows != 0) {
                while($row = $fetch_convo->fetch_assoc()) {
                    array_push($convo_container,$row);
                }
            } else {
                echo "No message fetched!";
            }
            return $convo_container;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function fetchTeams() {
        try {
            $teams = [];
            $get_teams_query = "SELECT DISTINCT TRIM(team_name) as team_name FROM dewsl_teams;";
            $get_teams = $this->dbconn->query($get_teams_query);
            if ($get_teams->num_rows != 0) {
                while ($row = $get_teams->fetch_assoc()) {
                   array_push($teams, $row['team_name']);
                }
            } else {
                echo "No teams fetched!\n\n";
            }
            $full_data['type'] = "fetchedTeams";
            $full_data['data'] = $teams;
            return $full_data;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }

    }
  
    function getMobileDetailsPerUser($user_id) {
        $mobile_details_per_user = [];
        $ctr = 0;
        try {
            $get_org_scope = "SELECT * FROM user_mobile WHERE user_id='".$user_id."'";
            $org_collection = $this->dbconn->query($get_org_scope);
            while ($row = $org_collection->fetch_assoc()) {
                $mobile_details_per_user[$ctr] = $row;
                $ctr++;
            }
            return $mobile_details_per_user;
        } catch (Exception $e) {
            $flag = false;
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function identifyGSMIDFromMobileNumber($mobile_number) { // New function to identify the GSM_ID
        try {
            $current_mobile_gsm_id = 0;
            $current_mobile_network = $this->identifyMobileNetwork($mobile_number);
            switch ($current_mobile_network) {
                case "GLOBE":
                    $current_mobile_gsm_id = 2;
                    break;
                case "SMART":
                    $current_mobile_gsm_id = 3;
                    break;
                default:
                    $current_mobile_gsm_id = 0;
            }
            return $current_mobile_gsm_id;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function getLastInsertID() {
        try {
            $last_inserted_id;
            try {
                $get_last_id = "SELECT LAST_INSERT_ID();";
                $result = $this->dbconn->query($get_last_id);
                $last_inserted_id = $result->fetch_assoc()["LAST_INSERT_ID()"];
            } catch (Exception $e) {
                $flag = false;
            }        
            return $last_inserted_id;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function flagGndMeasSettingsSentStatus() {
        try {
            $overwrite_query = "UPDATE ground_meas_reminder_automation SET status = 1 WHERE status = 0";
            $this->checkConnectionDB($overwrite_query);
            $result = $this->dbconn->query($overwrite_query);
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function getGroundMeasurementsForToday() {
        try {
            if (strtotime(date('H:i')) > strtotime('08:00') && strtotime(date('H:i')) < strtotime('12:00')) {
                $ground_time = '12:00';
                $current_date = date_format(date_sub(date_create(date('Y-m-d').$ground_time),date_interval_create_from_date_string("4 hours")),"Y-m-d H:i:s");
            } else if (strtotime(date('H:i')) > strtotime('12:01') && strtotime(date('H:i')) < strtotime('16:00')) {
                $ground_time = '16:00';
                $current_date = date_format(date_sub(date_create(date('Y-m-d').$ground_time),date_interval_create_from_date_string("4 hours")),"Y-m-d H:i:s");
            } else {
                $ground_time = '8:00';
                $current_date = date_format(date_sub(date_create(date('Y-m-d').$ground_time),date_interval_create_from_date_string("4 hours")),"Y-m-d H:i:s");
            }

            $gndmeas_sent_sites = [];
            $sql = "SELECT * FROM gintags 
                    INNER JOIN smsinbox_users ON smsinbox_users.inbox_id = table_element_id 
                    INNER JOIN gintags_reference ON gintags.tag_id_fk = gintags_reference.tag_id
                    INNER JOIN user_mobile ON smsinbox_users.mobile_id = user_mobile.mobile_id where (gintags_reference.tag_name = '#CantSendGroundMeas' OR gintags_reference.tag_name = '#GroundMeas' OR gintags_reference.tag_name = '#GroundObs') AND smsinbox_users.ts_sms < '".date('Y-m-d ').$ground_time."' AND smsinbox_users.ts_sms > '".$current_date."' limit 100;";
            $result = $this->dbconn->query($sql);
            if ($result->num_rows > 0) {
                foreach ($result as $tagged) {
                    $sql = "SELECT DISTINCT site_code FROM user_mobile INNER JOIN user_organization ON user_mobile.user_id = user_organization.user_id INNER JOIN sites ON user_organization.fk_site_id = sites.site_id WHERE user_mobile.sim_num like '%".substr($tagged['sim_num'],-10)."%';";
                    $get_sites = $this->dbconn->query($sql);
                    if ($get_sites->num_rows > 0) {
                        foreach ($get_sites as $site) {
                            if (sizeOf($gndmeas_sent_sites) == 1) {
                                array_push($gndmeas_sent_sites, $site['site_code']);
                            } else {
                                if (!in_array($site['site_code'],$gndmeas_sent_sites)) {
                                    array_push($gndmeas_sent_sites,$site['site_code']);
                                }
                            }
                        }
                    } else {
                        echo "No contacts fetched. \n\n";
                    }
                }
            } else {
                echo "No Ground measurement received.\n\n";
            }
            return array_unique($gndmeas_sent_sites);
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function checkForGndMeasSettings($time) {
        try {
            $settings_container = [];
            $template_query = "SELECT * FROM ground_meas_reminder_automation WHERE status = 0 and timestamp = '".$time."' order by site";
            $this->checkConnectionDB($template_query);
            $result = $this->dbconn->query($template_query);
            while ($row = $result->fetch_assoc()) {
                array_push($settings_container, $row);
            }
            return $settings_container;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }

    }

    function routineSites() {
        try {
            $sites_cant_send_gndmeas = $this->getGroundMeasurementsForToday();
            $sql = "SELECT DISTINCT site_code,status from sites INNER JOIN public_alert_event ON sites.site_id=public_alert_event.site_id WHERE public_alert_event.status <> 'routine' AND public_alert_event.status <> 'finished' AND public_alert_event.status <> 'invalid' order by site_code";
            $result = $this->senslope_dbconn->query($sql);
            $site_routine_collection['sitename'] = [];
            $site_routine_collection['status'] = [];
            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    array_push($site_routine_collection['sitename'],$row['site_code']);
                    array_push($site_routine_collection['status'],$row['status']);
                }
            } else {
                echo "0 results";
            }
            $sql = "SELECT site_code,season from sites";
            $result = $this->dbconn->query($sql);
            $sites_on_routine = [];
            $site_collection['sitename'] = [];
            $site_collection['season'] = [];
            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    array_push($site_collection['sitename'],$row['site_code']);
                    array_push($site_collection['season'],$row['season']);
                }
            } else {
                echo "0 results";
            }
            $on_routine_raw = array_diff($site_collection['sitename'],$site_routine_collection['sitename']);
            
            $on_routine['sitename'] = [];
            $on_routine['season'] = [];
            foreach ($on_routine_raw as $sites) {
                array_push($on_routine['sitename'], $sites);
            }
            for ($allsite_counter = 0; $allsite_counter < sizeof($site_collection['sitename']);$allsite_counter++) {
                for ($raw_counter = 0; $raw_counter < sizeof($on_routine['sitename']);$raw_counter++) { 
                    if ($on_routine['sitename'][$raw_counter] == $site_collection['sitename'][$allsite_counter]) {
                        array_push($on_routine['season'],$site_collection['season'][$allsite_counter]);
                    }
                }
            }
            // [[s1],[s2]];
            $wet = [[1,2,6,7,8,9,10,11,12], [5,6,7,8,9,10]];
            $dry = [[3,4,5], [1,2,3,4,11,12]];
            $month = (int) date("m"); // ex 3.
            $today = date("l");
            switch ($today) {
                case 'Wednesday':
                    for ($routine_counter = 0; $routine_counter < sizeof($on_routine['sitename']); $routine_counter++) {
                        if (in_array($month,$dry[(int) ($on_routine['season'][$routine_counter]-1)])) {
                            array_push($sites_on_routine, $on_routine['sitename'][$routine_counter]);
                        }
                    }
                    break;
                case 'Tuesday':
                case 'Friday':
                    for ($routine_counter = 0; $routine_counter < sizeof($on_routine['sitename']); $routine_counter++) {
                        if (in_array($month,$wet[(int) ($on_routine['season'][$routine_counter]-1)])){
                            array_push($sites_on_routine, $on_routine['sitename'][$routine_counter]);
                        }
                    }
                    break;
            }
            $final_sites = [];
            foreach ($sites_on_routine as $rtn_site) {
                if (sizeOf($sites_cant_send_gndmeas) > 0) {
                    foreach ($sites_cant_send_gndmeas as $cant_send) {
                       if (strtoupper($rtn_site) != $cant_send) {
                            array_push($final_sites, $rtn_site);
                       }
                    }
                } else {
                    $final_sites = $sites_on_routine;
                }
            }
            $temp = [];
            foreach (array_unique($final_sites) as $site) {
                array_push($temp, $site);
            }
            return $temp;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }


    function eventSites() {
        try {
            $sites_cant_send_gndmeas = $this->getGroundMeasurementsForToday();
            $event_sites_query = "SELECT DISTINCT site_code,status,public_alert_release.event_id from sites INNER JOIN public_alert_event ON sites.site_id=public_alert_event.site_id INNER JOIN public_alert_release ON public_alert_event.event_id = public_alert_release.event_id WHERE public_alert_event.status <> 'routine' AND public_alert_event.status <> 'finished' AND public_alert_event.status <> 'invalid' AND public_alert_event.status <> 'extended' and public_alert_release.internal_alert_level NOT LIKE 'A3%' order by site_code";

            $sites_with_stepup_alert = "SELECT DISTINCT site_code,status,public_alert_release.event_id from sites INNER JOIN public_alert_event ON sites.site_id=public_alert_event.site_id INNER JOIN public_alert_release ON public_alert_event.event_id = public_alert_release.event_id WHERE public_alert_event.status = 'on-going' and public_alert_release.internal_alert_level like '%A3%' order by site_code;";

            $alert_three = [];
            $result = $this->senslope_dbconn->query($sites_with_stepup_alert);
            while ($row = $result->fetch_assoc()) {
                array_push($alert_three, $row['site_code']);
            }

            $event_sites = [];
            $this->checkConnectionDB($event_sites_query);
            $result = $this->senslope_dbconn->query($event_sites_query);
            while ($row = $result->fetch_assoc()) {
                array_push($event_sites, $row);
            }

            $reconstrunct_event_sites_query = "SELECT * FROM (SELECT DISTINCT site_code,status,public_alert_release.event_id from sites INNER JOIN public_alert_event ON sites.site_id=public_alert_event.site_id INNER JOIN public_alert_release ON public_alert_event.event_id = public_alert_release.event_id WHERE public_alert_event.status <> 'routine' AND public_alert_event.status <> 'finished' AND public_alert_event.status <> 'invalid' AND public_alert_event.status <> 'extended' and public_alert_release.internal_alert_level NOT LIKE 'A3%' order by site_code) AS distinct_events INNER JOIN public_alert_release ON distinct_events.event_id = public_alert_release.event_id ORDER BY release_id desc LIMIT ".sizeOf($event_sites);

            $event_sites = [];
            $this->checkConnectionDB($reconstrunct_event_sites_query);
            $result = $this->senslope_dbconn->query($reconstrunct_event_sites_query);
            while ($row = $result->fetch_assoc()) {
                array_push($event_sites, $row);
            }

            $final_sites = [];
            foreach ($event_sites as $evt_site) {
                if (sizeOf($sites_cant_send_gndmeas) > 0) {
                    foreach ($sites_cant_send_gndmeas as $cant_send) {
                       if (strtoupper($evt_site['site_code']) != strtoupper($cant_send)) {
                            array_push($final_sites, $evt_site);
                       }
                    }
                } else {
                    $final_sites = $event_sites;
                }
            }

            $temp_sites = [];
            foreach ($final_sites as $evt_site) {
                sizeOf($alert_three);
                if (sizeOf($alert_three) > 0) {
                    if (!in_array($evt_site['site_code'], $alert_three)) {
                        array_push($temp_sites, $evt_site);
                    }
                } else {
                    $temp_sites = $event_sites;
                }
            }

            $temp = [];
            foreach (array_unique($temp_sites,SORT_REGULAR) as $site) {
                array_push($temp, $site);
            }

            return $temp;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function extendedSites() {
        try {
            $extended_sites = [];
            $sites_cant_send_gndmeas = $this->getGroundMeasurementsForToday();
            $extended_sites_query = "SELECT sites.site_code,public_alert_event.validity from sites INNER JOIN public_alert_event ON sites.site_id=public_alert_event.site_id WHERE public_alert_event.status = 'extended' order by sites.site_code";
            $this->checkConnectionDB($extended_sites_query);
            $result = $this->senslope_dbconn->query($extended_sites_query);
            $cur_date = date("Y-m-d");

            while($row = $result->fetch_assoc()) {
                $validity_date = substr($row['validity'],0,10);
                if ($validity_date != $cur_date) {
                    array_push($extended_sites, $row['site_code']);
                }
            }

            $final_sites = [];
            foreach ($extended_sites as $extnd_site) {
                if (sizeOf($sites_cant_send_gndmeas) > 0) {
                    foreach ($sites_cant_send_gndmeas as $cant_send) {
                       if (strtoupper($extnd_site) != $cant_send) {
                            array_push($final_sites, $extnd_site);
                       }
                    }
                } else {
                    $final_sites = $extended_sites;
                }
            }
            $temp = [];
            foreach (array_unique($final_sites) as $site) {
                array_push($temp, $site);
            }
            return $temp;
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function getGroundMeasurementReminderTemplate() {
        try {
            $template_query = "SELECT template FROM ewi_backbone_template WHERE alert_status = 'GndMeasReminder'";
            $this->checkConnectionDB($template_query);
            $result = $this->dbconn->query($template_query);
            return $result->fetch_assoc();
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function insertGndMeasReminderSettings($site, $type, $template, $altered, $modified_by, $send_time) {
        try {
            $template_query = "INSERT INTO ground_meas_reminder_automation VALUES (0,'".$type."','".$template."', 'LEWC', '".$site."','".$altered."','".$send_time."',0, '".$modified_by."')";
            $this->checkConnectionDB($template_query);
            $result = $this->dbconn->query($template_query);
            return $result; 
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function getSiteDetails($data) {
        try {
            $site_query = "SELECT * FROM sites WHERE site_code = '".$data."'";
            $result = $this->dbconn->query($site_query);
            return $result->fetch_assoc();   
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }

    function getOldMessageConversations($last_inbox, $last_outbox, $recipients) {
        try {
            $counter = 0;
            $inbox_filter_query = "";
            $outbox_filter_query = "";
            $inbox_outbox_collection = [];
            $convo_id_container = [];

            foreach ($recipients as $id) {
                if ($counter == 0) {
                    $outbox_filter_query = "smsoutbox_user_status.mobile_id = ".$id;
                    $inbox_filter_query = "smsinbox_users.mobile_id = ".$id;
                    $counter++;
                } else {
                    $outbox_filter_query = $outbox_filter_query." OR smsoutbox_user_status.mobile_id = ".$id." ";
                    $inbox_filter_query = $inbox_filter_query." OR smsinbox_users.mobile_id = ".$id." ";
                }
            }

            $inbox_query = "SELECT smsinbox_users.inbox_id as convo_id, smsinbox_users.mobile_id, 
                            smsinbox_users.ts_sms as ts_received, null as ts_written, null as ts_sent, smsinbox_users.sms_msg,
                            smsinbox_users.read_status, smsinbox_users.web_status, smsinbox_users.gsm_id, user_mobile.sim_num,
                            null as send_status , ts_sms as timestamp, UPPER(CONCAT(sites.site_code,' ',user_organization.org_name, ' - ', users.lastname, ', ', users.firstname)) as user from smsinbox_users INNER JOIN user_mobile ON smsinbox_users.mobile_id = user_mobile.mobile_id 
                            INNER JOIN users ON users.user_id = user_mobile.user_id INNER JOIN user_organization ON users.user_id = user_organization.user_id INNER JOIN sites ON user_organization.fk_site_id = sites.site_id WHERE (".$inbox_filter_query.") AND smsinbox_users.ts_sms < '".$last_inbox."'";

            $outbox_query = "SELECT smsoutbox_users.outbox_id as convo_id, mobile_id,
                            null as ts_received, ts_written, ts_sent, sms_msg , null as read_status,
                            web_status, gsm_id, null as sim_num, send_status , ts_written as timestamp, 'You' as user FROM smsoutbox_users INNER JOIN smsoutbox_user_status ON smsoutbox_users.outbox_id = smsoutbox_user_status.outbox_id WHERE (".$outbox_filter_query.") AND ts_written < '".$last_outbox."'";
            $full_query = "SELECT * FROM (".$inbox_query." UNION ".$outbox_query.") as full_contact group by sms_msg,timestamp order by timestamp desc limit 70;";

            $fetch_convo = $this->dbconn->query($full_query);
            if ($fetch_convo->num_rows != 0) {
                while($row = $fetch_convo->fetch_assoc()) {
                    $tag = $this->fetchSmsTags($row['convo_id']);
                    if (sizeOf($tag['data']) == 0) {
                        $row['hasTag'] = 0;
                    } else {
                        $row['hasTag'] = 1;
                    }
                    $row['network'] = $this->identifyMobileNetwork($row['sim_num']);
                    array_push($inbox_outbox_collection,$row);
                }
            } else {
                echo "No message fetched!";
            }

            $title_collection = [];
            foreach ($inbox_outbox_collection as $raw) {
                if ($raw['user'] == 'You') {
                    $titles = $this->getSentStatusForGroupConvos($raw['sms_msg'],$raw['timestamp'], $raw['mobile_id']);
                    $constructed_title = "";
                    foreach ($titles as $concat_title) {
                        if ($concat_title['status'] >= 5 ) {
                            $constructed_title = $constructed_title.$concat_title['full_name']." (SENT) <split>";
                        } else if ($concat_title['status'] < 5 && $concat_title >= 1) {
                            $constructed_title = $constructed_title.$concat_title['full_name']." (RESENDING) <split>";
                        } else {
                            $constructed_title = $constructed_title.$concat_title['full_name']." (FAIL) <split>";
                        }
                    }
                    array_push($title_collection, $constructed_title);
                } else {
                    array_push($title_collection, $raw['user']);
                }
            }

            $full_data = [];
            $full_data['type'] = "loadOldSmsConversation";
            $full_data['data'] = $inbox_outbox_collection;
            $full_data['titles'] = array_reverse($title_collection);
            $full_data['recipients'] = $recipients;
            return $this->utf8_encode_recursive($full_data);
        } catch(Exception $e) {
            $report = $this->ais_instance->aisSendReport($e->getMessage(),0);
            $this->sendSms($report["mobile_ids"],$report["message"]); // send Report
        }
    }
}
