#!/usr/bin/php
<?php

############################################################################
#
# zabsql.php - MSSQL Plugin for zabbix
#
# April 2010: Version 1.0 by Alain Ganuchaud (alain@coreit.fr)
# September 2012: Version 2.0 by Alain Ganuchaud (alain@coreit.fr)
# July 2014: Version 3.0 by Alain Ganuchaud (alain@coreit.fr)
# Welcome to report me any support and enhancement request
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
#       php-mssql or php-sybase (depends on distributions)
#	freetds 
#	Zabbix template
#
############################################################################

$progname = "zabsql.php";
$version = "3.0";

// Check if Instances are configured
$INSTANCENAMES = "/usr/local/zabbix/etc/zabsql.conf";
if(!fopen("$INSTANCENAMES","r")) {
echo "Please configure $INSTANCENAMES or change the location in $progname ... \n";
exit(0);
}

// Check arguments number
$args_number = ($argc - 1);
if ($args_number < 2) {
        echo "At least 2 parameters needed : action instance_name <option1> <option2> ... \n";
        exit();
}

// Some checks need at least 3 arguments
if ($argv[1]=="user_databases_size") {
        if ($args_number < 3) {
                echo "At least 3 parameters needed for user_databases_size check:\n";
                echo "database_sizes instance_name threshold\n";
                exit();
        }
}

// Some checks need at least 4 arguments
if ($argv[1]=="database_size") {
        if ($args_number < 4) {
                echo "At least 4 parameters needed for database_size check:\n";
                echo "database_size instance_name database_name percent|megabytes\n";
                exit();
        }
}

/* Configure as appropriate or configure zabbix user in MSSQL:
DECLARE @SQL NVARCHAR(1000);
Declare @login  as Varchar(35);
Declare @user  as Varchar(35);
SET @login = 'zabbix';
SET @user = 'zabbix';
SET NOCOUNT ON

SET @SQL = '
IF ''?'' NOT IN (''master'', ''model'', ''msdb'',
''tempdb'',''pubs'',''northwind'') 
BEGIN
EXEC ?.dbo.sp_grantdbaccess ''' + @login + ''',''' + @user + '''
EXEC ?.dbo.sp_addrolemember ''db_datareader'',''' + @user + '''
END '

Exec sp_addlogin @login, 'xxxxxxxx'
EXEC sp_MSForEachDb @sql
*/

// Get Instance Name configured in zabpgsql.conf
$instancename = $argv[2];

/////////////////////////////////////////////
// read instancenames config
$instancenames = parse_ini_file($INSTANCENAMES, true);

if ($instancenames==false || !array_key_exists($instancename, $instancenames)) {
        echo "This MSSQL instance is not defined in ".$INSTANCENAMES."\n";
        exit();
}

// Look if default user and default pass exist
if ( array_key_exists('global', $instancenames) ) {
        $default_user = $instancenames['global']['default_user'];
        $default_password = $instancenames['global']['default_password'];
	$text_size = $instancenames['global']['text_size'];
	$tds_version = $instancenames['global']['tds_version'];
}

$host = $instancenames[$instancename]['host'];
$port = $instancenames[$instancename]['port'];
$username = $instancenames[$instancename]['username'];
$password = $instancenames[$instancename]['password'];
if (empty($username)) $username = $default_user;
if (empty($password)) $password = $default_password;

/////////////////////////////////////////////
// function: Connect to the MSSQL instance
function connect() {
        global $host,$port,$username,$password,$link,$mssql_connect,$tds_version,$TDSVER;
	putenv("TDSVER=$tds_version");
        $link = mssql_connect("$host:$port", $username, $password);
        if (!$link) {
                $mssql_connect = die('MSSQL connect error');
                exit;
        }
        else {
                $mssql_connect = "Connection OK\n";
        }
}

/////////////////////////////////////////////
// function: Connect to MSSQL instance and Query row
function connect_query($mssql_query) {
        global $host,$port,$username,$password;
        connect();
        $result = mssql_query($mssql_query);
        if (!$result) {
                die('Invalid query: ' . $mssql_query);
        }
        $row_query = mssql_fetch_array($result);
        return $row_query;
}

/////////////////////////////////////////////
// function: Connect to a database and Query row
function connect_database_query($databasename,$mssql_query) {
        global $host,$port,$username,$password,$databasename,$link;
        connect();
	
       // Select database
        if(!mssql_select_db($databasename, $link)) {
                $db_connect = die('MSSQL connect error: ' . mssql_get_last_message());
        }

        $result = mssql_query($mssql_query);
        if (!$result) {
                die('Invalid query: ' . $mssql_query);
        }
        $row_query = mssql_fetch_array($result);
        return $row_query;
}

/////////////////////////////////////////////
// Action: database_size
// Returns: Float, database used space in megabytes or in percent
// Parameters:  database_used_space_percent <instancename> <databasename> percent|megabytes
if ( $argv[1] == 'database_size' ) {
	$databasename = $argv[3];

	$mssql_query="exec sp_spaceused";
	$row_db_size = connect_database_query($databasename,$mssql_query);
	
	if (!$row_db_size ) {
	   die('Invalid query: ' . mssql_get_last_message());
	}
	switch($argv[4])
	{
        	case "megabytes":
			$database_size=str_replace(" MB","",$row_db_size[1]);
		break;

        	case "percent":
			// 0=name 1=database size  2=Unallocated space
			$row_db_size[1]=str_replace(" MB","",$row_db_size[1]);
			$row_db_size[2]=str_replace(" MB","",$row_db_size[2]);
			$database_size=round(100-(($row_db_size[2]/$row_db_size[1])*100));
		break;
	}
}
	
/////////////////////////////////////////////
// Action: databases_list
// Returns: String, all users databases list
// Parameters:  databases_list <instancename>
// if only users databases are to requested, prefer the following statement:
// SELECT name FROM master.sys.databases where database_id > 3
if ( $argv[1] == 'databases_list' ) {
	connect();
	//SQL 2005 syntax
	//$query_dblist = "SELECT name FROM master.sys.databases where database_id > 3";

	//SQL 2000 + SQL 2005 syntax
	$query_dblist = "SELECT name FROM master.dbo.sysdatabases";
	$result_dblist = mssql_query($query_dblist);

	if (!$result_dblist) {
	   die('Invalid query: ' . mssql_get_last_message());
	}
	$row_dblist = mssql_fetch_array($result_dblist);

	$msg_db_list="";
	while ($row_dblist = mssql_fetch_array($result_dblist)) {
			$msg_db_list="$msg_db_list $row_dblist[0]";
	}
}

/////////////////////////////////////////////
// Action: user_databases_size
// Returns: String, used space in MB & percent of all databases
// Parameters:  user_databases_size <instancename> <threshold>
// Databases Fullfilness percent, database name not required
// Didn't success to use sp_msforeachdb ??
// exec sp_msforeachdb "sp_spaceused @updateusage = N'TRUE'" ??
if ( $argv[1] == 'user_databases_size' ) {
	connect();

	// free space % must be higher than threshold
	$threshold = $argv[3];

	//SQL 2005 syntax
	//$query_dblist = "SELECT name FROM master.sys.databases";

	//SQL 2000 + SQL 2005 syntax
	$query_dblist = "SELECT name FROM master.dbo.sysdatabases where dbid > 3";
	$result_dblist = mssql_query($query_dblist);

	if (!$result_dblist) {
	   die('Invalid query: ' . mssql_get_last_message());
	}

	// Zabbix message initialization
	$zabbix_msg="DATABASES USED SPACE:";

	while ($row_dblist = mssql_fetch_array($result_dblist,MSSQL_ASSOC)) {
		if ( $row_dblist['name'] != 'master' ) {
			// mssql_connect is used with true parameter (opens a new link)
			$newlink = mssql_connect("$host:$port", $username, $password, true);
			if (! mssql_select_db($row_dblist['name'], $newlink) ) continue ;
		}

		$query_dbs_size = "exec sp_spaceused";
		$result_dbs_size = mssql_query($query_dbs_size);
		$row_dbs_size = mssql_fetch_array($result_dbs_size);

		// 0=name  1=database size  2=Unallocated Space
		$row_dbs_size[1]=str_replace(" MB","",$row_dbs_size[1]);
		// $row_dbs_size[1]=round($row_dbs_size[1]);
		$row_dbs_size[2]=str_replace(" MB","",$row_dbs_size[2]);
		// $row_dbs_size[2]=round($row_dbs_size[2]);
		$used_percent=round(100-(($row_dbs_size[2]/$row_dbs_size[1])*100));

		// Threshold
		if ( $used_percent > $threshold ) {
			$zabbix_msg="$zabbix_msg $row_dbs_size[0] $row_dbs_size[1]MB $used_percent% above threshold | ";
		} else {
			$zabbix_msg="$zabbix_msg $row_dbs_size[0] $row_dbs_size[1]MB $used_percent% | ";
		}

		if ( $row_dblist['name'] != 'master' ) {
			mssql_close($newlink);
		}
	}
}

/////////////////////////////////////////////
// Action: logs_size
// Returns: String, used space in MB & percent of all databases logs
// Parameters:  logs_size <instancename> <threshold>
// Databases Fullfilness percent, database name not required
if ( $argv[1] == 'logs_size' ) {
	connect();
	// Trigger for Zabbix (free space % must be higher than threshold)
	$threshold = $argv[3];

	$query_dblog = "DBCC SQLPERF(LOGSPACE)";
	$result_dblog = mssql_query($query_dblog);
	if (!$result_dblog) {
	   die('Invalid query: ' . mssql_get_last_message());
	}

	// Zabbix message initialization
	$zabbix_msg="LOGS USED SPACE:";

	while ($row_dblog = mssql_fetch_array($result_dblog,MSSQL_NUM)) {
		// 0=name  1=UsedSpaceMB  2=UsedSpace%
		$row_dblog[1]=round($row_dblog[1]);
		$row_dblog[2]=round($row_dblog[2]);

		// Trigger Applied for Zabbix
		if ( $row_dblog[2] > $threshold ) {
			$zabbix_msg="$zabbix_msg $row_dblog[0] $row_dblog[1]MB $row_dblog[2]% above threshold | ";
		} else {
			$zabbix_msg="$zabbix_msg $row_dblog[0] $row_dblog[1]MB $row_dblog[2]% | ";
		}
		mssql_next_result($result_dblog);
	}
}

/////////////////////////////////////////////
// Switch cases
switch($argv[1]) 
{
        case "database_size":  
	// for a selected database
                echo $database_size;
                break;

        case "databases_list":
	// list databases in instance
		echo $msg_db_list;
               break;

        case "user_databases_size": 
	// % used for all databases
		echo $zabbix_msg;
                break;

        case "logs_size": 
	// % used for all databases
		echo $zabbix_msg;
                break;

        case "version":
	// Returns: String, MSSQL Server version
	// Parameters:  version <instancename>
        	$mssql_query = "EXEC sp_MSgetversion";
		$row = connect_query($mssql_query);
                echo $row[0];
                break;

        case "checkcon":
        // Returns: String, Connection OK or error
        // Parameters:  checkcon <instancename>
                connect();
                echo $mssql_connect;
                break;

        case "instance_name":
	// Returns: String, Instance Name
	// Parameters:  instance_name <instancename>
		$mssql_query = "SELECT @@SERVICENAME";
		$row = connect_query($mssql_query);
                echo $row[0];
               	break;

        case "cpu_percent":
	// Returns: Float, cpu percentage used by MSSQL Server
	// Parameters:  cpu_percent <instancename>
		$mssql_query = "select CAST(@@CPU_BUSY as FLOAT) * @@TIMETICKS / 1000000";
		$row = connect_query($mssql_query);
                echo $row[0];
               break;

        case "io_percent":
	// Returns: Float, io percentage used by MSSQL Server
	// Parameters:  io_percent <instancename>
		$mssql_query = "select CAST(@@IO_BUSY as FLOAT) * @@TIMETICKS / 1000000";
		$row = connect_query($mssql_query);
                echo $row[0];
               break;

        case "disk_read":
	// Returns: Float, disks reads launched by MSSQL Server
	// Parameters:  disk_read <instancename>
		$mssql_query = "SELECT @@TOTAL_READ";
		$row = connect_query($mssql_query);
                echo $row[0];
               break;

        case "disk_write":
	// Returns: Float, disks writes launched by MSSQL Server
	// Parameters:  disk_write <instancename>
		$mssql_query = "SELECT @@TOTAL_WRITE";
		$row = connect_query($mssql_query);
                echo $row[0];
               break;

        default :
                        echo "\n Actions available :
- database_size : Database size (MBytes or percent)
- user_databases_size : used space % for all user databases
- logs_size : used space % for all databases logs
- databases_list : list all users databases in the instance
- version : version of MSSQL Server
- instance_name : MSSQL Server Instance Name
- checkcon : test connection to instance
- cpu_percent : cpu seconds used by MSSQL Server
- io percent : io seconds used by MSSQL Server
- disk_read : disks reads launched by MSSQL Server
- disk_write : disks writes launched by MSSQL Server
                \n";
                break;
}

if ( !empty($link) ) {
mssql_close($link);
}
exit(0);

?>
