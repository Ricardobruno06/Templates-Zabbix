#!/usr/bin/php
<?php

############################################################################
#
# zabmy.php - MYSQL Plugin for zabbix
#
# April 2010: Version 1.0 by Alain Ganuchaud (alain@coreit.fr)
# September 2012: Version 2.0 by Alain Ganuchaud (alain@coreit.fr)
# July 2014: Version 3.0 by Alain Ganuchaud (alain@coreit.fr)
# Welcome to report to me any support and enhancement request
#
# Licence: GPL
# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License
# as published by the Free Software Foundation; either version 2
# of the License, or (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# Requires:
#       php-cli
#       php-mysql
#	Zabbix template
#
############################################################################

$progname = "zabmy.php";
$version  = "3.0";

// Check if Instances are configured
$INSTANCENAMES = "/usr/local/zabbix/etc/zabmy.conf";
if(!fopen("$INSTANCENAMES","r")) {
exit(0);
}

// Check arguments number
$args_number = ($argc - 1);
if ($args_number < 2) {
        echo "At least 2 parameters needed : action instance_name <database_name> \n";
        exit();
}

/* Configure as appropriate or configure zabbix user in MYSQL:
mysql> grant select on *.* to <user>@<host> identified by <passwd>
mysql> grant process on *.* to <user>@<host> identified by <passwd> */

// Get Instance Name configured in zabmy.conf
$instancename = $argv[2];

/////////////////////////////////////////////
// read instancenames config
$instancenames = parse_ini_file($INSTANCENAMES, true);

if ($instancenames==false || !array_key_exists($instancename, $instancenames)) {
        echo "This MYSQL instance is not defined in ".$INSTANCENAMES."\n";
        exit();
}

// Look if default user and default pass exist
if ( array_key_exists('global', $instancenames) ) {
        $default_user = $instancenames['global']['default_user'];
        $default_password = $instancenames['global']['default_password'] ;
}

$host = $instancenames[$instancename]['host'];
$port = $instancenames[$instancename]['port'];
$username = $instancenames[$instancename]['username'];
$password = $instancenames[$instancename]['password'];
if (empty($username)) $username = $default_user;
if (empty($password)) $password = $default_password;

/////////////////////////////////////////////
// function: Connect to the MYSQL instance
function connect() {
        global $host,$port,$username,$password,$link,$mysql_connect;
        $link = mysql_connect("$host:$port","$username","$password");
        if (!$link) {
                $mysql_connect = die('MYSQL connect error');
                exit;
        }
        else {
                $mysql_connect = "Connection OK\n";
        }
}

/////////////////////////////////////////////
// function: Connect and Query row
function connect_query($mysql_query) {
        global $host,$port,$username,$password,$row_query;
        connect();
        $result = mysql_query($mysql_query);
        if (!$result)
        {
                die('Invalid query: ' . $mysql_query);
        }
        $row_query = mysql_fetch_array($result);
        return $row_query[0];
}

/////////////////////////////////////////////
// function: Any metric returned by "SHOW GLOBAL STATUS"
function show_global_status($status_var_name) {
	global $row_query;
	$mysql_query = "SHOW GLOBAL STATUS like '$status_var_name'";
	$result = mysql_query($mysql_query);
	if (!$result) {
		die("Invalid query: $mysql_query " . mysql_error());
	}
	$row_query = mysql_fetch_array($result, MYSQL_ASSOC);
}

/////////////////////////////////////////////
// function: Database parameters
function database_parameters ($databasename){
	global $total_rows,$total_data_length,$total_index_length,$total_data_free;

        // MYSQL 4.x
        //$mysql_query = "SHOW TABLE STATUS from `".$databasename."`";

        // MYSQL 5.x
        $mysql_query = 'SELECT data_length,table_rows,index_length,data_free from information_schema.TABLES where table_schema like \''.$databasename.'\';';
	$result = mysql_query($mysql_query);

        if (!$result) {
                die('Invalid query: ' . $mysql_query);
        }

        while ($row = mysql_fetch_array($result, MYSQL_ASSOC)) {
                $total_rows += $row['table_rows'];
                $total_data_length += $row['data_length'];
                $total_index_length += $row['index_length'];
                $total_data_free += $row['data_free'];
        }
}

/////////////////////////////////////////////
// function: Instance parameters
function instance_parameters() {
	global $instance_total_rows,$instance_total_data_length,$instance_total_index_length,$instance_total_data_free,$total_rows,$total_data_length,$total_index_length,$total_data_free;
	
	// query all databases available 
	$querydb = "SHOW DATABASES";
	$resultdb = mysql_query($querydb);
	if (!$resultdb) {
	    die('Invalid query: ' . mysql_error());
	}

	$instance_total_rows = 0;
	$instance_total_data_length = 0;
	$instance_total_index_length = 0;
	while ($rowdb = mysql_fetch_array($resultdb, MYSQL_ASSOC)) {	
		database_parameters($rowdb['Database']) ;
                $instance_total_rows += $total_rows;
                $instance_total_data_length += $total_data_length;
                $instance_total_index_length += $total_index_length;
                $instance_total_data_free += $total_data_free;
		$total_rows = 0;
		$total_data_length = 0;
		$total_index_length = 0;
		$total_data_free = 0;
	}
}

/////////////////////////////////////////////
// function: Count Number of threads of state $argv[3]
function count_threads($thread_state){
	global $state_count;
	$state_count=0;
      	$result = mysql_query('SHOW PROCESSLIST');
	if (!$result) {
	    die('Invalid query: ' . mysql_error());
	}

      	while ( $row_query = mysql_fetch_array($result, MYSQL_ASSOC) ) {
         	$state = $row_query['State'];
		if ( is_null($state) ) {
            		$state = 'NULL';
         	}
         	if ( $state == '' ) {
            		$state = 'none';
         	}
         	if ( $state == "$thread_state" ) {
            		$state_count++;
         	}
      	}
	echo $state_count;
}

switch($argv[1]) {

	case "threads":
        // Returns: integer, number of threads with state $argv[3]
        // Parameters:  threads <instancename>
	connect();
	count_threads($argv[3]);
		break;

	case "version":
	// Returns: String, MYSQL Server version
	// Parameters: dummy version <mysql_instance_name>
		$mysql_query = "SELECT VERSION();";
		$row = connect_query($mysql_query);
		echo $row;
		break;

	case "checkcon":
        // Returns: String, Connection OK or error
        // Parameters:  checkcon <instancename>
                connect();
                echo $mysql_connect;
                break;

	case "instance_datas_size":
        // Returns: Integer, datas size for database
        // Parameters:  database_datas_size <instancename> <databasename>
                connect();
		instance_parameters($argv[2]);
		echo $instance_total_data_length;
                break;

	case "instance_index_size":
        // Returns: Integer, index size for database
        // Parameters:  instance_index_size <instancename> <databasename>
                connect();
		instance_parameters($argv[2]);
		echo $instance_total_index_length;
                break;

	case "instance_datas_free":
        // Returns: Integer, datas free space for database
        // Parameters:  instance_datas_free <instancename> <databasename>
                connect();
		instance_parameters($argv[2]);
		echo $instance_total_data_free;
                break;

	case "instance_rows":
        // Returns: Integer, datas free space for database
        // Parameters:  instance_datas_free <instancename> <databasename>
                connect();
		instance_parameters($argv[2]);
		echo $instance_total_rows;
                break;

	case "instance_size":
        // Returns: Integer, size for database (datas + index)
        // Parameters:  instance_size <instancename> <databasename>
                connect();
		instance_parameters($argv[2]);
		$instance_size = $instance_total_data_length + $instance_total_index_length;
		echo $instance_size;
                break;

	case "database_datas_size":
        // Returns: Integer, datas size for database
        // Parameters:  database_datas_size <instancename> <databasename>
                connect();
		database_parameters($argv[3]);
		echo $total_data_length;
                break;

	case "database_index_size":
        // Returns: Integer, index size for database
        // Parameters:  database_index_size <instancename> <databasename>
                connect();
		database_parameters($argv[3]);
		echo $total_index_length;
                break;

	case "database_datas_free":
        // Returns: Integer, datas free space for database
        // Parameters:  database_datas_free <instancename> <databasename>
                connect();
		database_parameters($argv[3]);
		echo $total_data_free;
                break;

	case "database_rows":
        // Returns: Integer, datas free space for database
        // Parameters:  database_datas_free <instancename> <databasename>
                connect();
		database_parameters($argv[3]);
		echo $total_rows;
                break;

	case "database_size":
        // Returns: Integer, size for database (datas + index)
        // Parameters:  database_size <instancename> <databasename>
                connect();
		database_parameters($argv[3]);
		$database_size = $total_data_length + $total_index_length;
		echo $database_size;
                break;

	default : 
	// Returns: Any metric from SHOW GLOBAL STATUS
        // Parameters:  <Variable_name> <instancename>
		connect();
		show_global_status($argv[1]);
		if ( !empty ($row_query['Variable_name'] )) {
			echo $row_query['Value'];
		} else {
			echo "actions available :
	- Rows				instance or database row count
	- Index_length			instance or database index size in Bytes
	- Avg_row_length		instance or database row size average in Bytes
	- Data_length			instance or database size in Bytes
	- version :  			version of mysql server
	- <Variable_name>: 		any metric from SHOW GLOBAL STATUS
	- threads:			Number of threads with a specific state
		\n\n";
		}
		break;
}

if ( !empty($link) ) {
	mysql_close($link);
}

exit();
?>
