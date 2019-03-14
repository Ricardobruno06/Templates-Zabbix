#!/usr/bin/php

<?php

############################################################################
#
# json.php - Low Level Zabbix Discoveries of Database Instances
# Print Database Instances in Json format.
#
# July 2014: Version 1.0 by Alain Ganuchaud (alain@coreit.fr)
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
#	DB Monitoring V7
#
############################################################################

$progname = "json.php";
$version = "1.0";

// Check arguments number
$args_number = ($argc - 1);
if ($args_number < 2)
{
    echo "At least 2 parameters needed : mssql or oracle or mysql or pgsql or db2, host \n";
    exit(0);
}

switch ($argv[1]) {
    case "mssql":
        $file_conf = "/usr/local/zabbix/etc/zabsql.conf";
	$INSTANCE_NAME = "MSSQL_INSTANCE_NAME";
        break;
    case "oracle":
        $file_conf = "/usr/local/zabbix/etc/zabora.conf";
	$INSTANCE_NAME = "ORACLE_INSTANCE_NAME";
        break;
    case "mysql":
        $file_conf = "/usr/local/zabbix/etc/zabmy.conf";
	$INSTANCE_NAME = "MYSQL_INSTANCE_NAME";
        break;
    case "pgsql":
        $file_conf = "/usr/local/zabbix/etc/zabpgsql.conf";
	$INSTANCE_NAME = "PGSQL_INSTANCE_NAME";
        break;
    case "db2":
        $file_conf = "/usr/local/zabbix/etc/zabdb2.conf";
	$INSTANCE_NAME = "DB2_INSTANCE_NAME";
        break;
    default:
        exit(0);
}

// Check if Instances are configured
$INSTANCENAMES = "$file_conf";
if(!fopen("$INSTANCENAMES","r")) {
	echo "$file_conf is not readable.\n";
exit(0);
}

/////////////////////////////////////////////
// read instancenames config
$instancenames = parse_ini_file($INSTANCENAMES, true);

// Filter Instances running on host
$zbx_instances = array_keys($instancenames);
foreach($zbx_instances as $zbx_instance) {
	if ("$zbx_instance" == "global" ) {
		$array_host = array("$zbx_instance" => $instancenames["$zbx_instance"]);
		continue;
	}
	if ( $instancenames["$zbx_instance"]['host'] == $argv[2] ) {
		$add_array = array("$zbx_instance" => $instancenames["$zbx_instance"]);
		$array_host = array_merge($array_host,$add_array);
	}
}

// Generate json from Instances running on host
$zbx_instances = array_keys($array_host);
$output = "";
$i = 1;
$count = count($array_host);
foreach($zbx_instances as $zbx_instance) {
	if ("$zbx_instance" == "global" ) {
		$i--;
		$count--;
	} else {
        switch ($i) {
                case 1:
			if ( $count == 1) {
                        $output = "{\"data\":[ {\"{#".$INSTANCE_NAME."}\":\"".$zbx_instance."\"} ] }";
			break;
			} else {
                        $output = "{\"data\":[ {\"{#".$INSTANCE_NAME."}\":\"".$zbx_instance."\"}";
			}
                        break;
                case $count:
                        $output = $output.",{\"{#".$INSTANCE_NAME."}\":\"".$zbx_instance."\"} ] }";
                        break;
                default:
                        $output = $output.",{\"{#".$INSTANCE_NAME."}\":\"".$zbx_instance."\"}";
        }
	}
        $i++;
}

echo "$output\n";

exit();
?>
