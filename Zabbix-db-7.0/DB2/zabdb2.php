#!/usr/bin/php
<?php

############################################################################
#
# zabdb2.php - DB2 Plugin for zabbix
#
# March 2012: Version 1.0 by Alain Ganuchaud (alain@coreit.fr)
# Sept 2012: Version 2.0 by Alain Ganuchaud (alain@coreit.fr)
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
#       Db2 Client lib
#       Php libs for db2
#	Zabbix template
#
############################################################################

$progname = "zabdb2.php";
$version  = "3.0";

// Check if Instances are configured
$INSTANCENAMES   = "/usr/local/zabbix/etc/zabdb2.conf";
if(!fopen("$INSTANCENAMES","r"))
{
echo "Please configure $INSTANCENAMES or change the location in $progname ... \n";
exit(0);
}

// Check arguments number
$args_number = ($argc - 1);
if ($args_number < 3)
{
        echo "At least 3 parameters needed : action instancename dbname \n";
        exit();
}

/* Configure zabbix user in db2:
-> db2icrt -s client zabbix
Log as user zabbix
-> db2 catalog tcpip node DB2SERVER remote <IP of DB2 server> server <port of DB2 server> # will catalog db2 server
-> db2 catalog database <Database used for connection on db2 server> at node DB2SERVER # will catalog database
Test it
-> db2 'connect to <Database used for connection on db2 server> user <DB2 user> using "<password of DB2 user"' */

// Get Instance Name & Database Name configured in zabpgsql.conf
$instancename = $argv[2];
$dbname = $argv[3];

/////////////////////////////////////////////
// read instancenames config
$instancenames = parse_ini_file($INSTANCENAMES, true);

if ($instancenames===false || !array_key_exists($instancename, $instancenames)) {
        echo "This db2 instance is not defined in ".$INSTANCENAMES."\n";
        exit();
}

// Look if default user and default pass exist
if ( array_key_exists('global', $instancenames) ) {
        // default user
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
// function: Connect to the db2 instance
function connect(){
	global $host,$port,$username,$password,$dbname,$link,$db2_connect;
	$conn_string = "DATABASE=".$dbname.";HOSTNAME=".$host.";PORT=".$port.";PROTOCOL=TCPIP;UID=".$username.";PWD=".$password.";";
	$link = db2_connect($conn_string, '','') ;
        if (!$link) {
                $db2_connect = die('DB2 connect error: '.db2_conn_error()."\n");
                exit;
        }
        else {
                $db2_connect = "Connection OK\n";
        }
}

/////////////////////////////////////////////
// function: Query row
function connect_query($db2_query) { 
	global $db2_sql,$link;
	connect();
	$result= db2_exec($link,$db2_query);
	if (!$result) {
	   die('Invalid query: ' . $db2_query);
	}
	$row_query = db2_fetch_array($result);
	return $row_query;
}

// Switch on action
switch($argv[1])
{
        case "checkcon":
        connect();
	echo $db2_connect;
        break;

        case "databasesize":
	// Database size
        connect();
	$db2_query = "SELECT db_size FROM systools.stmg_dbsize_info;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "databasecapacity":
	// Database capacity
        connect();
	$db2_query = "SELECT db_capacity FROM systools.stmg_dbsize_info;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "version":
	// DB2 version
        connect();
	$db2_query = "select service_level concat ' FP' concat fixpack_num from sysibmadm.env_inst_info;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "instancename":
	// Instance name
        connect();
	$db2_query = "select inst_name from sysibmadm.env_inst_info;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "productname":
	// Product name
        connect();
	$db2_query = "Select PRODUCT_NAME from sysibmadm.snapdbm;"; 
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "databasename":
	// Database name
        connect();
	$db2_query = "Select DB_NAME from sysibmadm.snapdb;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "servicelevel":
	// Service level
        connect();
	$db2_query = "Select SERVICE_LEVEL from sysibmadm.snapdbm;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "instanceconnections":
	// Number of connections to the instance
        connect();
	$db2_query = "Select REM_CONS_IN + LOCAL_CONS  from sysibmadm.snapdbm;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "instanceusedmemory":
	// Used memory for instance
        connect();
	$db2_query = "Select sum(POOL_CUR_SIZE) from sysibmadm.SNAPDBM_MEMORY_POOL;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "databaseconnections":
	// Total number of connections to the database since database start
        connect();
	$db2_query = "Select TOTAL_CONS from sysibmadm.snapdb;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "usedlog":
	// % of used log
        connect();
	$db2_query = "Select TOTAL_LOG_USED *1. / TOTAL_LOG_AVAILABLE * 100. from sysibmadm.snapdb;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "transactionsindoubt":
	// Number of transactions in doubt (which hold locks)
        connect();
	$db2_query = "Select NUM_INDOUBT_TRANS from sysibmadm.snapdb;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "xlocksescalations":
	// Number of lock escalation in exclusive mode
        connect();
	$db2_query = "Select X_LOCK_ESCALS from sysibmadm.snapdb;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "locksescalations":
	// Number of lock escalations
        connect();
	$db2_query = "Select LOCK_ESCALS from sysibmadm.snapdb;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "lockstimeouts":
	// Number of lock timeouts
        connect();
	$db2_query = "Select LOCK_TIMEOUTS from sysibmadm.snapdb;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "deadlocks":
	// Number of dealocks
        connect();
	$db2_query = "Select DEADLOCKS from sysibmadm.snapdb;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "lastbackuptime":
	// Time of the last database backup
        connect();
	$db2_query = "Select LAST_BACKUP from sysibmadm.snapdb;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "databasestatus":
	// Database status
        connect();
	$db2_query = "select DB_STATUS from sysibmadm.snapdb;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "instancestatus":
	// Instance status
        connect();
	$db2_query = "select DB2_STATUS from sysibmadm.snapdbm;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "bpindexhitratio":
	// Overall bufferpool index  hit ratio
        connect();
	$db2_query = "select case POOL_INDEX_L_READS when  0 then 1 else (POOL_INDEX_L_READS * 1.  - POOL_INDEX_P_READS * 1.) / POOL_INDEX_L_READS end * 100.  from sysibmadm.snapdb;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "bpdatahitratio":
	// Overall bufferpool data hit ratio
        connect();
	$db2_query = "select case POOL_DATA_L_READS when 0 then 1 else (POOL_DATA_L_READS * 1.  - POOL_DATA_P_READS * 1.) / POOL_DATA_L_READS end *100. from sysibmadm.snapdb;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "sortsinoverflow":
	// Percentage of sorts in overflow
        connect();
	$db2_query = "select case TOTAL_SORTS when 0 then 0 else SORT_OVERFLOWS *1. / TOTAL_SORTS *1. end * 100. from sysibmadm.snapdb;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "agentswaiting":
	// Number of agent waiting to be able to work
        connect();
	$db2_query = "select COALESCE(AGENTS_WAITING_TOP,0) from sysibmadm.snapdbm;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "updatedrows":
	// Number of updated rows
        connect();
	$db2_query = "Select ROWS_UPDATED from sysibmadm.snapdb;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "insertedrows":
	// Number of inserted rows
        connect();
	$db2_query = "Select ROWS_INSERTED from sysibmadm.snapdb;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "selectedrows":
	// Number of selected rows
        connect();
	$db2_query = "Select ROWS_SELECTED from sysibmadm.snapdb;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "deletedrows":
	// Number of deleted rows
        connect();
	$db2_query = "Select ROWS_DELETED from sysibmadm.snapdb;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "selects":
	// Number of select SQL statements
        connect();
	$db2_query = "Select SELECT_SQL_STMTS from sysibmadm.snapdb;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "staticsqls":
	// Number of static SQL statements
        connect();
	$db2_query = "Select STATIC_SQL_STMTS from sysibmadm.snapdb;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "dynamicsqls":
	// Number of dynamic SQL statements
        connect();
	$db2_query = "Select DYNAMIC_SQL_STMTS  from sysibmadm.snapdb;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "rollbacks":
	// Number of Rollbacks
        connect();
	$db2_query = "Select ROLLBACK_SQL_STMTS  from sysibmadm.snapdb;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "commits":
	// Number of commits
        connect();
	$db2_query = "Select COMMIT_SQL_STMTS from sysibmadm.snapdb;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "bptempindexhitratio":
	// Overall bufferpool temporary index hit ratio
        connect();
	$db2_query = "select case POOL_TEMP_INDEX_L_READS when 0 then 1 else (POOL_TEMP_INDEX_L_READS * 1. - POOL_TEMP_INDEX_P_READS * 1.) / POOL_TEMP_INDEX_L_READS end * 100 from sysibmadm.snapdb;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

        case "bptempdatahitratio":
	// Overall bufferpool temporary data hit ratio
        connect();
	$db2_query = "select case POOL_TEMP_DATA_L_READS when 0 then 1 else (POOL_TEMP_DATA_L_READS * 1. - POOL_TEMP_DATA_P_READS * 1.) /  POOL_TEMP_DATA_L_READS end * 100. from sysibmadm.snapdb;";
	$row = connect_query($db2_query);
	echo $row[0];
        break;

	default :
        echo "actions available :
                checkcon:       test connection to db2 instance
     		version:	DB2 version
		databasesize:	Database size
		databasecapacity:	Database capacity
        	instancename:	Instance name
	        productname:	Product name
        	databasename:	Database name
        	servicelevel:	Service level
        	instanceconnections:	Number of connections to the instance
        	instanceusedmemory:	Used memory for instance
        	databaseconnections:	Total number of connections to the database since database start
        	usedlog:	% of used log
        	transactionsindoubt:	Number of transactions in doubt (which hold locks)
        	xlocksecalations:	Number of locks escalations in exclusive mode
        	locksescalations:	Number of lock escalations
        	lockstimeouts:	Number of lock timeouts
        	deadlocks:	Number of dealocks
        	lastbackuptime:	Time of the last database backup
        	databasestatus:	Database status
        	instancestatus:	Instance status
        	bpindexhitratio:	Overall bufferpool index  hit ratio
        	bpdatahitratio:	Overall bufferpool data hit ratio
        	sortsinoverflow:	Percentage of sorts in overflow
        	agentswaiting:	Number of agent waiting to be able to work
        	updatedrows:	Number of updated rows
        	insertedrows:	Number of inserted rows
        	selectedrows:	Number of selected rows
        	deletedrows:	Number of deleted rows
        	selects:	Number of select SQL statements
        	staticsqls:	Number of static SQL statements
        	dynamicsqls:	Number of dynamic SQL statements
        	rollbacks:	Number of Rollbacks
        	commits:	Number of commits
        	bptempindexhitratio:	Overall bufferpool temporary index hit ratio
        	bptempdatahitratio:	Overall bufferpool temporary data hit ratio
	";
	exit;
}

if ( !empty($link) ) {
db2_close($link);
}

exit();
?>
