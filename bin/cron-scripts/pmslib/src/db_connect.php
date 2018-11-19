<?php
	$connection = [];

	class DBConnect {
        public function __connection($db_credentials) {
        	$pms_conn = $this->initPMSDatabaseConnection($db_credentials['dbcredentials_pms']);
        	$cbx_conn = $this->initCBXDatabaseConnection($db_credentials['dbcredentials_cbx_rack']);
        	return ["pms_conn" => $pms_conn, "cbx_conn" => $cbx_conn];
        }

        public function initPMSDatabaseConnection($db_credentials) {
		    $conn = new mysqli($db_credentials['dbhost'], $db_credentials['dbuser'], 
		                        $db_credentials['dbpass'], $db_credentials['dbname']);

		    if ($conn->connect_error) {
		        die("Connection failed: " . $conn->connect_error);
		    }  else {
		        echo "Connection established.\n\n";
		    }
		    return $conn;
        }

        public function initCBXDatabaseConnection($db_credentials) {
		    $conn = new mysqli($db_credentials['dbhost'], $db_credentials['dbuser'], 
		                        $db_credentials['dbpass'], $db_credentials['dbname']);

		    if ($conn->connect_error) {
		        die("Connection failed: " . $conn->connect_error);
		    }  else {
		        echo "Connection established.\n\n";
		    }
		    return $conn;
        }
	}



?>