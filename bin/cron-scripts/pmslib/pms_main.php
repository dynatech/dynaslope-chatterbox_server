<?php
	require_once(__DIR__.'/src/db_connect.php');
	require_once(__DIR__.'/src/ground_measurement_lib.php');
	require_once(__DIR__.'/src/sms_sent_lib.php');

	$db_credentials = include(__DIR__.'/config/config.php');
	$pms_url = "http://192.168.150.76/api/insert_report";

	class Main {
        public function __construct() {

        	global $db_credentials, $connect, 
        			$gndmeas_lib, $smssent_lib,
        			 $memcached;

			$gndmeas_lib = new GroundMeasPMS();
			$smssent_lib = new SmsSentPMS();
			$db_connect = new DBConnect();
			$memcached = new Memcached;
			$memcached->addServer('127.0.0.1', 11211);
			$connect = $db_connect->__connection($db_credentials);
        }

        public function HttpRequestToPMS($raw, $url) {
        	switch ($raw['type']) {
        		case 'timeliness':
        			$report = $this->constructTimelinessHttpRequest($raw);
        			break;
    			case 'error_log':
        			$report = $this->constructErrorLogsHttpRequest($raw);
        			break;
        	}

			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($report));
			$response = curl_exec($ch);
			curl_close($ch);
		}

        public function constructTimelinessHttpRequest($report) {
			$data = [
				'type' => 'timeliness', 
				'team_name' => 'Systems, Web, and Automation Team',
				'metric_name' => 'sms_sent_delay_timeliness',
				'module_name' => 'Communications',
				'reference_id' => $report['outbox_id'],
				'reference_table' => 'smsoutbox_users',
				'execution_time' => $report['time_difference'],
				'submetrics' => []
			];
			return $data;
        }

        public function constructErrorLogsHttpRequest($report) {
			$data = [
				'type' => 'error_log', 
				'team_name' => 'Systems, Web, and Automation Team',
				'metric_name' => 'sms_fails_error',
				'module_name' => 'Communications',
				'reference_id' => $report['outbox_id'],
				'reference_table' => 'smsoutbox_users',
				'report_message' => $report['report_message'],
				'submetrics' => []
			];
			return $data;
        }
	}
	
	$main = new Main();
	if ($memcached->get("smsoutbox_last_id") == false) {
		$sms_collection = $smssent_lib->getLatestSentMessages($connect['cbx_conn']);
		foreach ($sms_collection as $sms) {
			$report = $smssent_lib->analyzeSendingLatency($sms);
			if ($report['report_status'] == true) {
				$main->HttpRequestToPMS($report, $pms_url);
			}
		}
		$memcached->add("smsoutbox_last_id",$sms_collection[0]['outbox_id']);
	} else {
		$sms_collection = $smssent_lib->getLatestSentMessagesUsingMemcachedID($connect['cbx_conn'], $memcached->get("smsoutbox_last_id"));
		if (sizeOf($sms_collection) > 0) {
			foreach ($sms_collection as $sms) {
				$report = $smssent_lib->analyzeSendingLatency($sms);
				if ($report['report_status'] == true) {
					$main->HttpRequestToPMS($report, $pms_url);
				}
			}
		} else {
			echo "No new messages sent..\n\n";
		}
		$memcached->replace("smsoutbox_last_id",$sms_collection[0]['outbox_id']);
	}
?>