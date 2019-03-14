#!/usr/bin/php
<?php

############################################################################
#
# zabora.php - ORACLE Plugin for zabbix
# Mainly based from Den Crane zabora.sh with new checks:
# - extent       Segments with less than alarm level free extents
# - tablespace   Non Autoextensible tablespaces above alarm level used% space
# - datafile     Autoextensible datafile with less alarm level free increments
# - bufcachehit  Rewrite rcachehit according to METALINK Note 33883.1
# - libcachehit  Library Cache Hit Ratio
# - alertlogpath Search for ORA- strings in alert.log file
#
# May-August 2010: Version 1.0 1.1 1.2 by Alain Ganuchaud (alain@coreit.fr)
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
#       php with oci8
#	Zabbix template
#	ZabbixAPI.class.php for alertlogpath check
#	ZabbixAPI.class.php requires curl and php5-curl
#
############################################################################

$progname = "zabora.php";
$version = "3.0";

// Check if Instances are configured
$INSTANCENAMES = "/etc/zabbix/zabora.conf";
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

// Some checks need at least 3 arguments
if ($argv[1]=="extent" || $argv[1]=="tablespace" || $argv[1]=="datafile")
{
	if ($args_number < 3)
	{
		echo "At least 3 parameters needed for extent or tablespace or datafile checks:\n";
		echo "extent <instance_name> <free_extents>\n";
		echo "tablespace <instance_name> <used_space> <exception1> <exception2> ...\n";
		echo "datafile <instance_name> <free_increments> <exception1> <exception2> ...\n";
		exit();
	}
}
	
/* Configure as appropriate or configure zabbix user in Oracle:
CREATE USER "ZABBIX" IDENTIFIED BY "password";
GRANT "CONNECT" TO "ZABBIX";
grant select on v_$instance to zabbix;
grant select on v_$sysstat to zabbix;
grant select on v_$session to zabbix;
grant select on dba_free_space to zabbix;
grant select on dba_data_files to zabbix;
grant select on dba_tablespaces to zabbix;
grant select on v_$log to zabbix;
grant select on v_$archived_log to zabbix;
grant select on v_$loghist to zabbix;
grant select on v_$system_event to zabbix;
grant select on v_$event_name to zabbix;
grant select on v_$parameter to zabbix;
grant select on v_$librarycache to zabbix;*/

// Get Instance Name configured in zabora.conf
$instancename = $argv[2];

/////////////////////////////////////////////
// read instancenames config
$instancenames = parse_ini_file($INSTANCENAMES, true);

if ($instancenames==false || !array_key_exists($instancename, $instancenames)) {
        echo "This oracle instance is not defined in ".$INSTANCENAMES."\n";
        exit();
}

// Look if default user and default pass exist
if ( array_key_exists('global', $instancenames) ) {
        $default_user = $instancenames['global']['default_user'];
        $default_password = $instancenames['global']['default_password'] ;
	$SERVER_URL = $instancenames['global']['apiurl'];
	$API_USER   = $instancenames['global']['apiuser'];
	$API_PASS   = $instancenames['global']['apipass'];
}

$host = $instancenames[$instancename]['host'];
$port = $instancenames[$instancename]['port'];
$service = $instancenames[$instancename]['service'];

$username = $instancenames[$instancename]['username'];
$password = $instancenames[$instancename]['password'];
if (empty($username)) $username = $default_user;
if (empty($password)) $password = $default_password;

/////////////////////////////////////////////
// function: Connect to the oracle instance
// For Oracle10g connectstring=[//]host_name[:port][/service_name]
// For Oracle11g connectstring=[//]host_name[:port][/service_name][:server_type][/instance_name]
// You can get services details by executing "lsnrctl status" on the Oracle server
function connect() {
	global $connectstring,$host,$port,$service,$oracle_connect,$link,$username,$password;
	$connectstring = "//$host:$port/$service";
	putenv( "NLS_LANG=American_America.WE8ISO8859P1" ) ; // For . as decimal char
	$link = oci_connect($username, $password, $connectstring );
	if (!$link) {
                $oracle_connect = die('Oracle connect error');
                exit;
        }
        else {
                $oracle_connect = "Connection OK\n";
        }
}

/////////////////////////////////////////////
// function: Connect and Query row
function connect_query($oracle_query) {
	global $link;
	connect();
	$result = oci_parse($link,$oracle_query);
	if (!$result)
	{
   		die('Invalid query: ' . $oracle_query);
	}
	oci_execute($result);
	$row_query=oci_fetch_array($result, OCI_BOTH);
	return $row_query;
}

/////////////////////////////////////////////
// function: Query row
function query($oracle_query) {
	global $link;
	$result = oci_parse($link,$oracle_query);
	if (!$result)
	{
   		die('Invalid query: ' . $oracle_query);
	}
	oci_execute($result);
	$row_query=oci_fetch_array($result, OCI_BOTH);
	return $row_query;
}

/////////////////////////////////////////////
// Action: alertlogpath
// Returns: configure host macro with alert.log path
// Parameters: alertlogpath  <instancename>
if ( $argv[1] == 'alertlogpath' ) {
	connect();

	// Get alert.log directory
	$queryalertpath = 'SELECT \'xxxx\' ,value FROM v$parameter WHERE  name = \'background_dump_dest\'';
	$rowalertpath=query($queryalertpath);

	// Get instance name
	$queryinstancename = 'SELECT \'xxxx\' ,value FROM v$parameter WHERE  name = \'instance_name\'';
	$rowinstancename=query($queryinstancename);

	// Alert.log file full path
	$alertlogpath="$rowalertpath[1]/alert_$rowinstancename[1].log";

	// We need hostname to get hostid for API
	$instancename_fields=explode('_',$instancename);

	// ZABBIX API START
	// We need hostid for configuring host level macro with alert.log 
	require_once("ZabbixAPI.class.php");
	ZabbixAPI::debugEnabled(FALSE); // keep this declaration with TRUE or FALSE otherwise it does not work
	
	// This logs into Zabbix, and returns false if it fails
	ZabbixAPI::login("$SERVER_URL","$API_USER","$API_PASS") or die('Unable to login: '.print_r(ZabbixAPI::getLastError(),true));

	// get hostid with ip dns or hostname
	$host_id = ZabbixAPI::fetch_row('host','get',array('filter'=>array('ip'=>"$host")))
		or $host_id = ZabbixAPI::fetch_row('host','get',array('filter'=>array('dns'=>"$host")))
			or $host_id = ZabbixAPI::fetch_row('host','get',array('filter'=>array('host'=>"$host")))
				or die("Unable to get hostid for $host  : ".print_r(ZabbixAPI::getLastError(),true));
	$hid=$host_id['hostid'];

    // check if macro already exists
    $ALERT_MACRO = "{\$".$instancename."_ORA_ALERTLOGPATH}";
	echo $ALERT_MACRO;
    $result = ZabbixAPI::fetch_row('usermacro','get',array('hostids'=>"$hid",'filter'=>array('macro'=>"$ALERT_MACRO"))) ;
    $macro_id = $result['hostmacroid'] ;

    // create it if not exists
    if (  empty($macro_id) ) {
        $result = ZabbixAPI::fetch_row('usermacro','create',array('hostid'=>"$hid",'macro'=>"$ALERT_MACRO",'value'=>"$alertlogpath"));
        $macro_id = $result[0] ;
        $macro_alertlogpath="{\$ALERT_MACRO} macro (hostmacroid: $macro_id) created for $host with value $alertlogpath";
    } else {
        $macro_alertlogpath="{\$$ALERT_MACRO} macro (hostmacroid: $macro_id) for $host is already configured";
    }
}

/////////////////////////////////////////////
// Action: extent
// Returns: string, Segments with less than alarm level free extents
// Parameters:  extent <instancename> <free_extents>
if ( $argv[1] == 'extent' ) {
	connect();

	// alarm level
	$free_extents = $argv[3];
	// Zabbix message initialization
	$alarm_text="Segments with less $free_extents free extents:";
	$zabbix_msg="$alarm_text";
	$queryextent = 'SELECT segment_name, extents, max_extents FROM dba_segments WHERE extents+'.$free_extents.' > max_extents AND segment_type<>\'CACHE\'';
	$resultextent = oci_parse($link, $queryextent);
	oci_execute($resultextent);

	while ($rowextent = oci_fetch_array($resultextent, OCI_BOTH)){
		$zabbix_msg="$zabbix_msg $rowextent[0] used:$rowextent[1] max:$rowextent[2] |";
	}
	// No segment in alarm found
	if($zabbix_msg == "$alarm_text") {
		$zabbix_msg="$zabbix_msg None";
	}
}

/////////////////////////////////////////////
// Action: tablespace
// Returns: string, Tablespaces above alarm level used% space
// TBS non autoextensible : % used space above level
// Parameters:  tablespace <instancename> <used_space> <exception1> <exception2> ...
if ( $argv[1] == 'tablespace' ) {
	connect();

	// alarm level
	$used_space = $argv[3];

	// Zabbix message initialization
	$alarm_text="Nonautoextensible tablespaces above $used_space% used space:";
	$zabbix_msg="$alarm_text";

	// tablespace in exception after $argv[3]
	$tbs_exception=array();
	if ( !empty($arg[4]) ) {
		$i = 1;
		while($argv[3+$i]) {
			$tbs_exception[$i]=$argv[3+$i];
			$i++;
		}
	}

	// Get tablespace used % space
	$querytablespace = "select  d.tablespace_name,
        sum(d.bytes)/1048576,
        100 -(round((FREESPCE/(sum(d.bytes)/1048576))*100))
        FROM dba_data_files  d,
        ( SELECT round(sum(f.bytes)/1048576,2) FREESPCE,
        f.tablespace_name Tablespc
        FROM dba_free_space f
        GROUP BY f.tablespace_name)
	WHERE d.tablespace_name = Tablespc AND d.autoextensible = 'NO'
        group by d.tablespace_name,FREESPCE
        order by 1 desc";
	$resulttablespace = oci_parse($link, $querytablespace);
	oci_execute($resulttablespace);

	// Compare with alarm level and exclude exceptions
	while ($rowtablespace = oci_fetch_array($resulttablespace, OCI_BOTH)){
		if (!in_array($rowtablespace[0], $tbs_exception)) {
			if ($rowtablespace[2] > $used_space) {
			$zabbix_msg="$zabbix_msg $rowtablespace[0] $rowtablespace[2]% |";
			}
		}
	}

	// No tablespace in alarm found
	if($zabbix_msg == "$alarm_text") {
		$zabbix_msg="$zabbix_msg None";
	}
}

/////////////////////////////////////////////
// Action: datafile
// Returns: string, Datafile with less alarm level free increments
// autoextensible TBS: datafile can not extend with less level free increments
// Info: autoextensible TBS can grow by INCREMENT_BY (= set of blocks) till MAX_BLOCKS
// Parameters:  datafile <instancename> <free_increments> <exception1> <exception2> ...
if ( $argv[1] == 'datafile' ) {
	connect();

	// alarm level
	$free_increment = $argv[3];

	// Zabbix message initialization
	$alarm_text="Autoextensible datafiles with less $free_increment free increments:";
	$zabbix_msg="$alarm_text";

	// datafile in exception after $argv[3]
	$datafile_exception=array();
	if ( !empty($arg[4]) ) {
		$i = 1;
		echo $argv[3 + $i];
		while($argv[3 + $i]) {
			$datafile_exception[$i]=$argv[3+$i];
			$i++;
		}
	}

	// Datafile with free increments below level
	$querydatafile = "select TABLESPACE_NAME,
	FILE_NAME, BLOCKS, MAXBLOCKS, INCREMENT_BY 
	from dba_data_files where autoextensible='YES' 
	and blocks + increment_by * $free_increment > maxblocks";
	$resultdatafile = oci_parse($link, $querydatafile);
	oci_execute($resultdatafile);

	// exclude exceptions
	while ($rowdatafile = oci_fetch_array($resultdatafile, OCI_BOTH)) {
		if (!in_array($rowdatafile[1], $datafile_exception)) {
			$zabbix_msg="$zabbix_msg $rowdatafile[1] from $rowdatafile[0] |";
		}
	}

	// No datafile in alarm found
	if($zabbix_msg == "$alarm_text") {
		$zabbix_msg="$zabbix_msg None";
	}
}

// Switch on action
switch($argv[1])
{
	case "extent":
		print_r($rowextent);
		echo $zabbix_msg;
		break;

	case "tablespace":
		print_r($rowtablespace);
		echo $zabbix_msg;
		break;

	case "datafile":
		print_r($rowdatafile);
		echo $zabbix_msg;
		break;
	
	case "alertlogpath":
		echo $macro_alertlogpath;
		break;

	case "checkcon":
		// Returns: String, Connection OK or error
		// Parameters:  checkcon <instancename>
		connect();
		echo $oracle_connect;
		break;

	case "activeusercount":
		// Returns: integer, number of active users
		// Parameters:  activeusercount <instancename>
		$oracle_query = 'select to_char(count(*)-1, \'FM99999999999999990\') retvalue from v$session where status=\'ACTIVE\'';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "version":
		// Returns: String, ORACLE Server version
		// Parameters:  version <instancename>
		$oracle_query = 'select banner from v$version where rownum=1';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "dbsize":
		// Returns: integer, Database size (Octets) (< All datafiles size)
		// Parameters:  dbsize <instancename>
		$oracle_query = 'SELECT to_char(sum(  NVL(a.bytes - NVL(f.bytes, 0), 0)), \'FM99999999999999990\') retvalue
             		FROM sys.dba_tablespaces d,
             		(select tablespace_name, sum(bytes) bytes from dba_data_files group by tablespace_name) a,
             		(select tablespace_name, sum(bytes) bytes from dba_free_space group by tablespace_name) f
             		WHERE d.tablespace_name = a.tablespace_name(+) AND d.tablespace_name = f.tablespace_name(+)
             		AND NOT (d.extent_management like \'LOCAL\' AND d.contents like \'TEMPORARY\')';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "alldbfilesize":
		// Returns: integer, All datafiles size (Octets)
		// Parameters:  alldbfilesize <instancename>
		$oracle_query = 'select to_char(sum(bytes), \'FM99999999999999990\') retvalue from dba_data_files';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "uptime":
		// Returns: integer, uptime (seconds)
		// Parameters:  uptime <instancename>
		$oracle_query = 'select to_char((sysdate-startup_time)*86400, \'FM99999999999999990\') retvalue from v$instance';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "commits":
		// Returns: integer, user commits
		// Parameters:  commits <instancename>
		$oracle_query = 'select to_char(value, \'FM99999999999999990\') retvalue from v$sysstat where name = \'user commits\'';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "rollbacks":
		// Returns: integer, user rollbacks
		// Parameters:  rollbacks <instancename>
		$oracle_query = 'select to_char(value, \'FM99999999999999990\') retvalue from v$sysstat where name = \'user rollbacks\'';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "deadlocks":
		// Returns: integer, deadlocks
		// Parameters:  deadlocks <instancename>
		$oracle_query = 'select to_char(value, \'FM99999999999999990\') retvalue from v$sysstat where name = \'enqueue deadlocks\'';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "redowrites":
		// Returns: integer, redo writes
		// Parameters:  redowrites <instancename>
		$oracle_query = 'select to_char(value, \'FM99999999999999990\') retvalue from v$sysstat where name = \'redo writes\'';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "tblscans":
		// Returns: integer, long table scans
		// Parameters:  tblscans <instancename>
		$oracle_query = 'select to_char(value, \'FM99999999999999990\') retvalue from v$sysstat where name = \'table scans (long tables)\'';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "tblrowsscans":
		// Returns: integer, table scan rows gotten
		// Parameters:  tblrowsscans <instancename>
		$oracle_query = 'select to_char(value, \'FM99999999999999990\') retvalue from v$sysstat where name = \'table scan rows gotten\'';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "indexffs":
		// Returns: integer, index fast full scans (full)
		// Parameters:  indexffs <instancename>
		$oracle_query = 'select to_char(value, \'FM99999999999999990\') retvalue from v$sysstat where name = \'index fast full scans (full)\'';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "hparsratio":
		// Returns: float (%), hard parse ratio
		// Parameters:  hparsratio <instancename>
		$oracle_query = 'SELECT to_char(h.value/t.value*100,\'FM99999990.9999\') retvalue FROM  v$sysstat h, v$sysstat t WHERE h.name = \'parse count (hard)\' AND t.name = \'parse count (total)\'';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "netsent":
		// Returns: integer, bytes sent via SQL*Net to client
		// Parameters:  netsent <instancename>
		$oracle_query = 'select to_char(value, \'FM99999999999999990\') retvalue from v$sysstat where name = \'bytes sent via SQL*Net to client\'';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "netrecv":
		// Returns: integer, bytes received via SQL*Net to client
		// Parameters:  netrecv <instancename>
		$oracle_query = 'select to_char(value, \'FM99999999999999990\') retvalue from v$sysstat where name = \'bytes received via SQL*Net from client\'';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "logonscurrent":
		// Returns: integer, logons current
		// Parameters:  logonscurrent <instancename>
		$oracle_query = 'select to_char(value, \'FM99999999999999990\') retvalue from v$sysstat where name = \'logons current\'';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "lastarclog":
		// Returns: integer, last archivelog sequence
		// Parameters:  lastarclog <instancename>
		$oracle_query = 'select to_char(max(SEQUENCE#), \'FM99999999999999990\') retvalue from v$log where archived = \'YES\'';
		$row = connect_query($oracle_query);
		if (empty($row)) {
			echo "Not in archive mode";
		} else {
			echo $row[0];
		}
		break;

	case "lastapplarclog":
		// Returns: integer, last applied archivelog
		// Parameters:  lastapplarclog <instancename>
		$oracle_query = 'select to_char(max(lh.SEQUENCE#), \'FM99999999999999990\') retvalue from v$loghist lh, v$archived_log al where lh.SEQUENCE# = al.SEQUENCE# and applied=\'YES\'';
		$row = connect_query($oracle_query);
		if (empty($row)) {
			echo "Not in archive mode";
		} else {
			echo $row[0];
		}
		break;

	case "freebufwaits":
		// Returns: integer, free buffer waits
		// Parameters:  freebufwaits <instancename>
		$oracle_query = 'select to_char(time_waited, \'FM99999999999999990\') retvalue from v$system_event WHERE event = \'free buffer waits\'';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "bufbusywaits":
		// Returns: integer, buffer busy waits
		// Parameters:  bufbusywaits <instancename>
		$oracle_query = 'select to_char(time_waited, \'FM99999999999999990\') retvalue from v$system_event WHERE event = \'buffer busy waits\'';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "logswcompletion":
		// Returns: integer, log file switch completion
		// Parameters:  logswcompletion <instancename>
		$oracle_query = 'select to_char(time_waited, \'FM99999999999999990\') retvalue from v$system_event WHERE event = \'log file switch completion\'';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "logfilesync":
		// Returns: integer, log file sync
		// Parameters:  logfilesync <instancename>
		$oracle_query = 'select to_char(time_waited, \'FM99999999999999990\') retvalue from v$system_event WHERE event = \'log file sync\'';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "logprllwrite":
		// Returns: integer, log file parallel write
		// Parameters:  logprllwrite <instancename>
		$oracle_query = 'select to_char(time_waited, \'FM99999999999999990\') retvalue from v$system_event WHERE event = \'log file parallel write\'';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "enqueue":
		// Returns: integer, enqueue
		// Parameters:  enqueue <instancename>
		$oracle_query = 'select to_char(time_waited, \'FM99999999999999990\') retvalue from v$system_event WHERE event = \'enqueue\'';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "dbseqread":
		// Returns: integer, db file sequential read
		// Parameters:  dbseqread <instancename>
		$oracle_query = 'select to_char(time_waited, \'FM99999999999999990\') retvalue from v$system_event WHERE event = \'db file sequential read\'';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "dbscattread":
		// Returns: integer, db file scattered read
		// Parameters:  dbscattread <instancename>
		$oracle_query = 'select to_char(time_waited, \'FM99999999999999990\') retvalue from v$system_event WHERE event = \'db file scattered read\'';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "dbsnglwrite":
		// Returns: integer, db file single write
		// Parameters:  dbsnglwrite <instancename>
		$oracle_query = 'select to_char(time_waited, \'FM99999999999999990\') retvalue from v$system_event WHERE event = \'db file single write\'';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "dbprllwrite":
		// Returns: integer, db file parallel write
		// Parameters:  dbprllwrite <instancename>
		$oracle_query = 'select to_char(time_waited, \'FM99999999999999990\') retvalue from v$system_event WHERE event = \'db file parallel write\'';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "directread":
		// Returns: integer, direct path read
		// Parameters:  directread <instancename>
		$oracle_query = 'select to_char(time_waited, \'FM99999999999999990\') retvalue from v$system_event WHERE event = \'direct path read\'';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "directwrite":
		// Returns: integer, direct path write
		// Parameters:  directwrite <instancename>
		$oracle_query = 'select to_char(time_waited, \'FM99999999999999990\') retvalue from v$system_event WHERE event = \'direct path write\'';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "usercount":
		// Returns: integer, number of connected users
		// Parameters:  usercount <instancename>
		$oracle_query = 'select to_char(count(*)-1, \'FM99999999999999990\') retvalue from v$session';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "dsksortratio":
		// Returns: float (%), disk sort ratio
		// Parameters:  dsksortratio <instancename>
		$oracle_query = 'SELECT to_char(d.value/(d.value + m.value)*100, \'FM99999990.9999\') retvalue
             		FROM  v$sysstat m, v$sysstat d
             		WHERE m.name = \'sorts (memory)\'
             		AND d.name = \'sorts (disk)\'';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "bufcachehit":
		// Returns: integer (%), Buffer cache Hit Ratio (See METALINK Note 33883.1)
		// Parameters:  bufcachehit <instancename>
		$oracle_query = 'select to_char(round( (1 - ((PHYREAD.VALUE - PHYREADDIR.VALUE - PHYREADDIRLOB.VALUE) / (CONSGET.VALUE + BLOCKGET.VALUE - PHYREADDIR.VALUE - PHYREADDIRLOB.VALUE)))*100,2), \'FM99999990.9999\') from V$SYSSTAT PHYREAD, V$SYSSTAT PHYREADDIR, V$SYSSTAT PHYREADDIRLOB, V$SYSSTAT CONSGET, V$SYSSTAT BLOCKGET where PHYREAD.NAME = \'physical reads\' and PHYREADDIR.NAME = \'physical reads direct\' and PHYREADDIRLOB.NAME = \'physical reads direct (lob)\' and CONSGET.NAME = \'consistent gets\' and BLOCKGET.NAME = \'db block gets\'';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	case "libcachehit":
		// Returns: integer (%), Library cache Hit Ratio
		// Parameters:  libcachehit <instancename>
		$oracle_query = 'SELECT  to_char(round( SUM(PINS-RELOADS)/SUM(PINS)*100,2), \'FM99999990.9999\') FROM V$LIBRARYCACHE';
		$row = connect_query($oracle_query);
		echo $row[0];
		break;

	default : 
	echo "actions available :
		checkactive:	test connection to oracle
		version:	ORACLE Server version
		alertlogpath:	Configure host macro with alert.log path
		uptime:		uptime (seconds)
		dbsize:		Database size (Octets) (< All datafiles size)
		activeusercount:Number of active users
		usercount:	Number of connected users
		alldbfilesize: 	All datafiles size (Octets)
		commits:	user commits
		rollbacks:	user rollbacks
		deadlocks:	deadlocks
		enqueue:	enqueue
		redowrites:	redo writes
		tblscans:	long table scans
		tblrowsscans:	table scan rows gotten
		indexffs:	index fast full scans (full)
		hparsratio:	hard parse ratio (%)
		dsksortratio:	disk sort ratio (%)
		netsent:	bytes sent via SQL*Net to client
		netrecv:	bytes received via SQL*Net to client
		logonscurrent:	logons current
		lastarclog:	last archivelog sequence
		lastapplarclog:	last applied archivelog
		freebufwaits:	free buffer waits
		bufbusywaits:	buffer busy waits
		logswcompletion:log file switch completion
		logfilesync:	log file sync
		logprllwrite:	log file parallel write
		dbseqread:	db file sequential read
		dbscattread:	db file scattered read
		dbsnglwrite:	db file single write
		dbprllwrite:	db file parallel write
		directread:	direct path read
		directwrite:	direct path write
		extent:		Segments with less than alarm level free extents
		tablespace:	Non autoextensible tablespaces above alarm level used% space
		datafile:	Autoextensible datafile with less alarm level free increments
		bufcachehit	Buffer Cache Hit Ratio (See METALINK Note 33883.1) (%)
		libcachehit	Library Cache Hit Ratio (%)
		";
		break;
}

if ( !empty($link) ) {
oci_close($link);
}

exit();
?>
