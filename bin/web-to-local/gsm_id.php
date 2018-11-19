<?php

    $rack_servername = "192.168.150.75";
    $rack_username = "pysys_local";
    $rack_password = "NaCAhztBgYZ3HwTkvHwwGVtJn5sVMFgg";
    $rack_dbname = "comms_db";

    $networkSmart = ["00","07","08","09","10","11","12","14","18","19","20","21","22","23","24","25","28","29","30","31","32","33","34","38","39","40","42","43","44","46"];



    $networkGlobe = ["05","06","15","16","17","26","27","35","36","37","45","55","56","65","66","75","77","78","79","94","95","96","97"];


	// Create connection
    $rack_conn = new mysqli($rack_servername, $rack_username, $rack_password, $rack_dbname);
    // Check connection
    if ($rack_conn->connect_error) {
        die("\nConnection failed: " . $rack_conn->connect_error);
    }  else {
        echo "\nConnection established: rack server.\n\n";
    }


    $sql_query = "SELECT DISTINCT firstname,lastname,user_mobile.sim_num,user_mobile.mobile_id FROM comms_db.users INNER JOIN user_organization ON users.user_id = user_organization.user_id INNER JOIN user_mobile ON users.user_id = user_mobile.user_id;";
    $result = $rack_conn->query($sql_query);

    while ($row = $result->fetch_assoc()) {
    	$trimmed = substr($row["sim_num"], -10);
    	if (in_array($trimmed[1].$trimmed[2], $networkGlobe) == true) {
    		$update_network = "UPDATE user_mobile SET gsm_id = '8' WHERE mobile_id = '".$row['mobile_id']."'";
		    $gsm_result = $rack_conn->query($update_network);
    		if ($gsm_result == true) {
    			echo "Globe...\n";
    		} else {
    			echo "ERROR....\n";
    		}
    	} else {
    		$update_network = "UPDATE user_mobile SET gsm_id = '9' WHERE mobile_id = '".$row['mobile_id']."'";
    		$gsm_result = $rack_conn->query($update_network);
    		if ($gsm_result == true) {
    			echo "Smart...\n";
    		} else {
    			echo "ERROR....\n";
    		}
    	}
    }

?>