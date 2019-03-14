#!/usr/bin/php
<?php

############################################################################
#
# zabpgsql.php - POSTGRESQL Plugin for zabbix
#
# September 2011: Version 1.0 by Alain Ganuchaud (alain@coreit.fr)
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
#       php-pgsql
#	Zabbix template
#
############################################################################

$progname = "zabpgsql.php";
$version = "3.0";

// Check if Instances are configured
$INSTANCENAMES = "/usr/local/zabbix/etc/zabpgsql.conf";
if(!fopen("$INSTANCENAMES","r"))
{
echo "Please configure $INSTANCENAMES or change the location in $progname ... \n";
exit(0);
}

// Check arguments number
$args_number = ($argc - 1);
if ($args_number < 2)
{
	echo "At least 2 parameters needed : action instance_name <option1> <option2> ... \n";
	exit();
}

/* Configure as appropriate or configure zabbix user in Postgresql:
CREATE USER zabbix WITH PASSWORD 'passw0rd'; GRANT SELECT ON pg_stat_activity to zabbix; GRANT SELECT ON pg_stat_activity to zabbix; GRANT SELECT ON pg_database to zabbix; GRANT SELECT ON pg_authid to zabbix; GRANT SELECT ON pg_stat_bgwriter to zabbix; GRANT SELECT ON pg_locks to zabbix; GRANT SELECT ON pg_stat_database to zabbix;*/

// Get Instance Name configured in zabpgsql.conf
$instancename = $argv[2];

/////////////////////////////////////////////
// read instancenames config
$instancenames = parse_ini_file($INSTANCENAMES, true);

if ($instancenames==false || !array_key_exists($instancename, $instancenames)) {
	echo "This postgresql instance is not defined in ".$INSTANCENAMES."\n";
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
// function: Connect to the postgresql instance
function connect() {
	global $host,$port,$username,$password,$link,$pg_connect;
	$link = pg_connect("host=$host port=$port dbname=template1 user=$username password=$password");
	if (!$link) {
 		$pg_connect = die('POSTGRESQL connect error');
		exit;
        }
	else {
                $pg_connect = "Connection OK\n";
        }
}

/////////////////////////////////////////////
// function: Connect and Query row
function connect_query($pgsql_query) {
	global $host,$port,$username,$password;
	connect();
	$result = pg_query($pgsql_query);
	if (!$result)
	{
   		die('Invalid query: ' . $pgsql_query);
	}
	$row_query = pg_fetch_row($result);
	return $row_query;
}

/////////////////////////////////////////////
// Action: version
// Returns: String, POSTGRESQL Server version
// Parameters:  version <instancename>
if ( $argv[1] == 'version' )
{
connect();
$rowver = pg_version($link);
}

/////////////////////////////////////////////
// Action: listdatabases
// Returns: Integer, POSTGRESQL total number of Server Processes that are active
// Parameters:  listdatabases <instancename>
if ( $argv[1] == 'listdatabases' )
{
	connect();
	$querylistdatabases = "select datname from pg_database;";
	$resultlistdatabases = pg_query($querylistdatabases);
	$dblist_text="";
	while ($rowlistdatabases = pg_fetch_array($resultlistdatabases))
	{
		$dblist_text="$dblist_text $rowlistdatabases[0] |";
	}
}

// Switch on action
switch($argv[1])
{
	case "version":
		echo $rowver['client'];
		break;

	case "listdatabases":
		echo $dblist_text;
		break;

	case "checkcon":
	// Returns: String, Connection OK or error
	// Parameters:  checkcon <instancename>
		connect();
		echo $pg_connect;
		break;

	case "serverprocesses":
	// Returns: Integer, POSTGRESQL total number of Server Processes that are active
	// Parameters:  serverprocesses <instancename>
		$pgsql_query = "select sum(numbackends) from pg_stat_database;";
		$row = connect_query($pgsql_query);
		echo $row[0];
		break;

	case "commits":
	// Returns: Integer, POSTGRESQL total number of commited transactions
	// Parameters:  commits <instancename>
		$pgsql_query = "select sum(xact_commit) from pg_stat_database;";
		$row = connect_query($pgsql_query);
		echo $row[0];
		break;

	case "rollbacks":
	// Returns: Integer, POSTGRESQL total number of roll back transactions
	// Parameters:  rollbacks <instancename>
		$pgsql_query = "select sum(xact_rollback) from pg_stat_database;";
		$row = connect_query($pgsql_query);
		echo $row[0];
		break;

	case "ckptimed":
	// Returns: Integer, POSTGRESQL total number of roll back transactions
	// Parameters:  ckptimed <instancename>
		$pgsql_query = "select checkpoints_timed from pg_stat_bgwriter;";
		$row = connect_query($pgsql_query);
		echo $row[0];
		break;

	case "ckprequests":
	// Returns: Integer, POSTGRESQL total number of roll back transactions
	// Parameters:  ckprequests <instancename>
		$pgsql_query = "select checkpoints_req from pg_stat_bgwriter;";
		$row = connect_query($pgsql_query);
		echo $row[0];
		break;

	case "dbsize":
	// Returns: Integer, POSTGRESQL Database size
	// Parameters:  dbsize <instancename> database_name
		$database_name = $argv[3];
		$pgsql_query = "select pg_database_size('$database_name');";
		$row = connect_query($pgsql_query);
		echo $row[0];
		break;

	case "activecon":
	// Returns: Integer, POSTGRESQL Database active connections
	// Parameters:  activecon <instancename> database_name
		$database_name = $argv[3];
		$pgsql_query = "select numbackends from pg_stat_database where datname = '$database_name';";
		$row = connect_query($pgsql_query);
		echo $row[0];
		break;

	case "tuples":
	// Returns: Integer, POSTGRESQL Database tuples
	// Parameters:  tuples <instancename> database_name
		$database_name = $argv[3];
		$pgsql_query = "select tup_fetched from pg_stat_database where datname = '$database_name';";
		$row = connect_query($pgsql_query);
		echo $row[0];
		break;

	case "inserts":
	// Returns: Integer, POSTGRESQL Database tuples inserts
	// Parameters:  inserts <instancename> database_name
		$database_name = $argv[3];
		$pgsql_query = "select tup_inserted from pg_stat_database where datname = '$database_name';";
		$row = connect_query($pgsql_query);
		echo $row[0];
		break;

	case "updates":
	// Returns: Integer, POSTGRESQL Database tuples updates
	// Parameters:  updates <instancename> database_name
		$database_name = $argv[3];
		$pgsql_query = "select tup_updated from pg_stat_database where datname = '$database_name';";
		$row = connect_query($pgsql_query);
		echo $row[0];
		break;

	case "deletes":
	// Returns: Integer, POSTGRESQL Database tuples deletes
	// Parameters:  deletes <instancename> database_name
		$database_name = $argv[3];
		$pgsql_query = "select tup_deleted from pg_stat_database where datname = '$database_name';";
		$row = connect_query($pgsql_query);
		echo $row[0];
		break;
	
	case "fetches":
	// Returns: Integer, POSTGRESQL Database tuples fetches
	// Parameters:  fetches <instancename> database_name
		$database_name = $argv[3];
		$pgsql_query = "select tup_fetched from pg_stat_database where datname = '$database_name';";
		$row = connect_query($pgsql_query);
		echo $row[0];
		break;

	case "ExclusiveLock":
	// Returns: Integer, POSTGRESQL total number of Exclusive Locks
	// Parameters:  ExclusiveLock <instancename>
		$pgsql_query = "SELECT count(*) FROM pg_locks where mode='ExclusiveLock';";
		$row = connect_query($pgsql_query);
		echo $row[0];
		break;

	case "AExclusiveLock":
	// Returns: Integer, POSTGRESQL total number of Access Exclusive Locks
	// Parameters:  AExclusiveLock <instancename>
		$pgsql_query = "SELECT count(*) FROM pg_locks where mode='AccessExclusiveLock';";
		$row = connect_query($pgsql_query);
		echo $row[0];
		break;

	case "AccessShareLock":
	// Returns: Integer, POSTGRESQL total number of Access Shared Locks
	// Parameters:  AccessShareLock <instancename>
		$pgsql_query = "SELECT count(*) FROM pg_locks where mode='AccessShareLock';";
		$row = connect_query($pgsql_query);
		echo $row[0];
		break;

	case "ShareLock":
	// Returns: Integer, POSTGRESQL total number of Shared Locks
	// Parameters:  ShareLock <instancename>
		$pgsql_query = "SELECT count(*) FROM pg_locks where mode='ShareLock';";
		$row = connect_query($pgsql_query);
		echo $row[0];
		break;

	case "RowShareLock":
	// Returns: Integer, POSTGRESQL total number of Row Shared Locks
	// Parameters:  RowShareLock <instancename>
		$pgsql_query = "SELECT count(*) FROM pg_locks where mode='RowShareLock';";
		$row = connect_query($pgsql_query);
		echo $row[0];
		break;

	case "RowExclusiveLock":
	// Returns: Integer, POSTGRESQL total number of Row Exclusive Locks
	// Parameters:  RowExclusiveLock <instancename>
		$pgsql_query = "SELECT count(*) FROM pg_locks where mode='RowExclusiveLock';";
		$row = connect_query($pgsql_query);
		echo $row[0];
		break;

	case "ShareUpdateExclusiveLock":
	// Returns: Integer, POSTGRESQL total number of Shared Update Exclusive Locks
	// Parameters:  ShareUpdateExclusiveLock <instancename>
		$pgsql_query = "SELECT count(*) FROM pg_locks where mode='ShareUpdateExclusiveLock';";
		$row = connect_query($pgsql_query);
		echo $row[0];
		break;

	case "ShareRowExclusiveLock":
	// Returns: Integer, POSTGRESQL total number of Shared Row Exclusive Locks
	// Parameters:  ShareRowExclusiveLock <instancename>
		$pgsql_query = "SELECT count(*) FROM pg_locks where mode='ShareRowExclusiveLock';";
		$row = connect_query($pgsql_query);
		echo $row[0];
		break;

	default : 
	echo "actions available :
		checkcon:	test connection to postgresql instance
		version:	POSTGRESQL Server version
		commits:	user commits
		rollbacks:	user rollbacks
		ckprequests:	total number of Checkpoints launched beacause of requests
		ckptimed:	total number of Checkpoints launched beacuse of timeout
		serverprocesses:	total number of Server Processes that are active
		listdatabases:	List all databases in instance
		ExclusiveLock:	total number of Exclusive Locks
		AExclusiveLock:	total number of Access Exclusive Locks
		AccessShareLock:	total number of Access Shared Locks
		RowShareLock:	total number of Row Shared Locks
		ShareLock:	total number of Shared Locks
		RowExclusiveLock:	total number of Row Exclusive Locks
		ShareUpdateExclusiveLock: total number of Shared Update Exclusive Locks
		ShareRowExclusiveLock: 	total number of Shared Row Exclusive Locks
		dbsize:		database size
		activecon:	database active connections
		tuples:		database tuples
		inserts:		database tuples inserts
		updates:		database tuples updates
		deletes:		database tuples deletes
		returns:		database tuples returns
		fetches:		database tuples fetches
		";
		exit;
}

if ( !empty($link) ) {
	pg_close($link);
}

exit();
?>
