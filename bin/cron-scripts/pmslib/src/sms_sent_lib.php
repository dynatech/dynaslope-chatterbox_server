<?php
	class SmsSentPMS {
        public function getLatestSentMessages($conn, $minutes = 10) {
        	$outbox_container = [];
        	$outbox_query = "SELECT smsoutbox_users.outbox_id, ts_written, ts_sent, send_status FROM smsoutbox_users INNER JOIN smsoutbox_user_status ON smsoutbox_users.outbox_id = smsoutbox_user_status.outbox_id where ts_written > (now() - interval 4 hour) order by smsoutbox_users.outbox_id desc";
        	$result = $conn->query($outbox_query);
        	if ($result->num_rows != 0) {
        		while ($row = $result->fetch_assoc()) {
        			array_push($outbox_container, $row);
        		}
        	}
        	return $outbox_container;
        }

        public function getLatestSentMessagesUsingMemcachedID($conn, $cached_id) {
        	$outbox_container = [];
        	$outbox_query = "SELECT smsoutbox_users.outbox_id, ts_written, ts_sent, send_status FROM smsoutbox_users INNER JOIN smsoutbox_user_status ON smsoutbox_users.outbox_id = smsoutbox_user_status.outbox_id where ts_written > (now() - interval 4 hour) AND smsoutbox_users.outbox_id > ".$cached_id." order by smsoutbox_users.outbox_id desc";
        	$result = $conn->query($outbox_query);
        	if ($result->num_rows != 0) {
        		while ($row = $result->fetch_assoc()) {
        			array_push($outbox_container, $row);
        		}
        	}
        	return $outbox_container;
        }

        public function getLatestSendMessageViaLastID() {

        }

        public function analyzeSendingLatency($sms_data) {
        	$status_container = [];
        	if ($sms_data["ts_sent"] != "") {
        		$sent = new DateTime($sms_data['ts_sent']);
        		$written = new DateTime($sms_data['ts_written']);
        		$diff = $written->diff($sent);
        		$diff = $diff->s * 1000;
        		$result = $this->analyzeAcceptanceRating($diff, $sms_data);
        	} else {
        		$diff = null;
        		$result = $this->analyzeAcceptanceRating($diff, $sms_data);
        	}
        	return $result;
        }

        public function analyzeAcceptanceRating($difference, $data) {
        	$acceptance_milliseconds = 5000;
        	if ($data['ts_sent'] != "" || $data['ts_sent'] != 0) {
	    		if ($difference > $acceptance_milliseconds) {
	    			$report = [
	    				"type" => "timeliness",
	    				"report_status" => true,
	    				"time_difference" => $difference,
	    				"sms_status" => "sent",
	    				"outbox_id" => $data['outbox_id']
	    			];
	    		} else {
	    			$report = [
	    				"type" => "timeliness",
	    				"report_status" => false,
	    				"time_difference" => $difference,
	    				"sms_status" => "sent",
	    				"outbox_id" => $data['outbox_id']
	    			];
	    		}	
        	} else {
        		$report = [
    				"type" => "error_log",
    				"report_status" => true,
    				"report_message" => "Failed to send message with a status: ".$data['send_status'],
    				"outbox_id" => $data['outbox_id']
    			];
        	}

    		return $report;
        }
	}
?>