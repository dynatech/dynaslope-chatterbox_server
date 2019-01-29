<?php
	if (strpos(exec('hostname -I'), '192.168.150.80') == true) {
		return [
		    "dbcredentials" => [
			    'dbhost' => '192.168.150.75',
			    'dbname' => 'comms_db',
			    'dbuser' => 'pysys_local',
			    'dbpass' => 'NaCAhztBgYZ3HwTkvHwwGVtJn5sVMFgg'
			    ],
			"wsscredentials" => [
				'host' => 'localhost',
				'port' => '5150'
			],
			"cbx_cred" => [
			    'dbhost' => '192.168.150.75',
			    'dbnamecomms' => 'comms_db',
			    'dbnamesenslope' => 'senslopedb',
			    'dbuser' => 'pysys_local',
			    'dbpass' => 'NaCAhztBgYZ3HwTkvHwwGVtJn5sVMFgg'
			]
		];
	} else {
		return [
		    "dbcredentials" => [
			    'dbhost' => 'localhost',
			    'dbname' => 'comms_db',
			    'dbuser' => 'root',
			    'dbpass' => 'senslope'
			    ],
			"wsscredentials" => [
				'host' => 'localhost',
				'port' => '5150'
			],
			"cbx_cred" => [
			    'dbhost' => 'localhost',
			    'dbnamecomms' => 'comms_db',
			    'dbnamesenslope' => 'senslopedb',
			    'dbuser' => 'root',
			    'dbpass' => 'senslope'
			]
		];
	}
// ?>
