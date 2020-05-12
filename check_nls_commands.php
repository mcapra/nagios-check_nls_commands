#!/usr/bin/php
<?php
// Check NLS Commands PLUGIN
//
// Copyright (c) 2016 Matthew Capra, Nagios Enterprises <mcapra@nagios.com>
//  
// $Id: $mcapra@nagios.com
define("PROGRAM", 'check_nls_commands.php');
define("VERSION", '1.0.2');
define("STATUS_OK", 0);
define("STATUS_WARNING", 1);
define("STATUS_CRITICAL", 2);
define("STATUS_UNKNOWN", 3);
define("DEBUG", false);

function parse_args() {
    $specs = array(array('short' => 'h',
                         'long' => 'help',
                         'required' => false),
                   array('short' => 'a',
                         'long' => 'address', 
                         'required' => true),
				   array('short' => 'k',
                         'long' => 'key', 
                         'required' => true),
				   array('short' => 't',
                         'long' => 'timeout', 
                         'required' => false),
				   array('short' => 's',
                         'long' => 'ssl', 
                         'required' => false),
                   array('short' => 'w', 
                         'long' => 'warning', 
                         'required' => false),
                   array('short' => 'c', 
                         'long' => 'critical', 
                         'required' => false),
				   array('short' => 'v', 
                         'long' => 'verbose', 
                         'required' => false)
    );
    
    $options = parse_specs($specs);
    return $options;
}
function parse_specs($specs) {
    $shortopts = '';
    $longopts = array();
    $opts = array();
    foreach($specs as $spec) {    
        if(!empty($spec['short'])) {
            $shortopts .= "{$spec['short']}:";
        }
        if(!empty($spec['long'])) {
            $longopts[] = "{$spec['long']}:";
        }
    }
    $parsed = getopt($shortopts, $longopts);
    foreach($specs as $spec) {
        $l = $spec['long'];
        $s = $spec['short'];
        if(array_key_exists($l, $parsed) && array_key_exists($s, $parsed)) {
            plugin_error("Command line parsing error: Inconsistent use of flag: ".$spec['long']);
        }
        if(array_key_exists($l, $parsed)) {
            $opts[$l] = $parsed[$l];
        }
        elseif(array_key_exists($s, $parsed)) {
            $opts[$l] = $parsed[$s];
        }
        elseif($spec['required'] == true) {
            plugin_error("Command line parsing error: Required variable ".$spec['long']." not present.");
        }
    }
    return $opts;
}
function debug_logging($message) {
    if(DEBUG) {
        echo $message;
    }
}
function plugin_error($error_message) {
    print("***ERROR***:\n\n{$error_message}\n\n");
    fullusage();
    nagios_exit('', STATUS_UNKNOWN);
}
function nagios_exit($stdout='', $exitcode=0) {
    print($stdout . PHP_EOL);
    exit($exitcode);
}
function main() {
    $options = parse_args();
    
	
    if(array_key_exists('version', $options)) {
        print('Plugin version: '.VERSION);
        fullusage();
        nagios_exit('', STATUS_OK);
    }
    check_commands($options);
}

function check_commands($options) {
	//cut down the PHP chatter
	error_reporting(0);
	$commandsIn['144'] = array('do_maintenance', 'do_backups', 'update_check', 'cleanup', 'run_alerts');
	
	if(isset($options['warning'])) {
		$warningThreshold = (int)$options['warning'];
	}
	if(isset($options['critical'])) {
		$criticalThreshold = (int)$options['critical'];
	}
	
	$warningCommands = array();
	$criticalCommands = array();
	
	$address = $options['address'];
	$key = $options['key'];
	$release = 0;
	$url = get_url($address, $options);
	// this is an array we provide to make sure all the neccesary commands exist on the system
	$version = get_nls_version($address, $key, $options);
	
	$checkForCommands = $commandsIn[$version];
	//$warning = (int)$options['warning'];
	//$critical = (int)$options['critical'];
	$output = '';
	if(isset($options['timeout'])) {
		$timeout = $options['timeout'];
	}
	else $timeout = 5;

	if(isset($options['verbose']))
		echo('URL: ' . $url . PHP_EOL);
	
	$curl = curl_init($url . 'nagioslogserver/commands/_search?size=1000&token=' . $key);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curl,CURLOPT_TIMEOUT,$timeout);
	$resp = curl_exec($curl);
	$curl_errno = curl_errno($curl);
    $curl_error = curl_error($curl);
	curl_close($curl);
	
	if ($curl_errno > 0) {
		nagios_exit('UNKNOWN - ' . $curl_error, STATUS_UNKNOWN);
    }
	
	$commands = json_decode($resp);
	
	if($commands->error)
                nagios_exit('UNKNOWN - ' . $commands->message, STATUS_UNKNOWN);
	
	if(isset($options['verbose']))
		print_r($commands);
	
	foreach($commands->hits->hits as $command) {
		$checkForCommands = array_diff($checkForCommands, array($command->_source->command));
		
		if(isset($criticalThreshold)) {
			if(time() > ($command->_source->run_time  + $criticalThreshold)) {
				//append to critical
				array_push($criticalCommands, $command->_id);
			}
		}
		if(isset($warningThreshold)) {
			if(time() > ($command->_source->run_time  + $warningThreshold)) {
				array_push($warningCommands, $command->_id);
			}
		}
	}
	
	if(sizeof($checkForCommands) > 0) {
		nagios_exit('UNKNOWN - NLS is missing the following commands: ' . implode(',' , $checkForCommands), STATUS_UNKNOWN);
	}
	else {
		if(sizeof($criticalCommands) > 0) {
			nagios_exit('CRITICAL - Stale commands: ' . implode(',' , $criticalCommands), STATUS_CRITICAL);
		}
		else if(sizeof($warningCommands) > 0) {
			nagios_exit('WARNING - Stale commands: ' . implode(',' , $warningCommands), STATUS_WARNING);
		}
		else {
			nagios_exit('OK - All commands found and have run recently.');
		}
	}
}

function get_nls_version($a, $k, $options) {
	$version = null;
	$url = get_url($a, $options);
	$curl = curl_init($url . 'nagioslogserver/node/_search?size=1000&token=' . $k);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curl,CURLOPT_TIMEOUT,5);
	$resp = curl_exec($curl);
	$curl_errno = curl_errno($curl);
    $curl_error = curl_error($curl);
	$resp = curl_exec($curl);
	curl_close($curl);
	
	$nodes = json_decode($resp);
	
	foreach($nodes->hits->hits as $node) {
		if(isset($node->_source->address)) {
			if($node->_source->address == $a){
				if($node->_source->ls_release <= 144) {
					$version = '144';
				}
				else $version = '' . $node->_source->ls_release;
				break;
			}
		}
	}
	return $version;
}

function get_url($a, $options) {
	$url = null;
	if(isset($options['ssl'])) {
		$url = 'https://' . $a. ':9200/';
	}
	else {
		$url = 'http://' . $a . ':9200/';
	}
	return $url;
}

function fullusage() {
print(
	"check_nls_commands.php - v".VERSION."
        Copyright (c) 2017 Matthew Capra, Nagios Enterprises <mcapra@nagios.com>
	Under GPL v2 License
	This plugin checks the commands in command subsystem on a Nagios Log Server machine to verify 
	Usage: ".PROGRAM." -h | -a <address> -k <api_key> [-s <value>] [-v <value>] [-t <timeout>] [-c <critical>] [-w <warning>] 
	NOTE: -a must be specified
	Options:
	-h
	     Print this help and usage message
	-a
	     The address (or block) we wish to check
	-k	
		 The API key to connect to the Nagios Log Server API
	-s	
		 Enable SSL. Use this if Nagios Log Server is configured to use HTTPS.
	-t
	     The timeout for our checks (seconds), default is 5.
	-w
	     Threshold for warning (seconds). If current time > next_run_time + warning.
	-c
	     Threshold for critical (seconds). If current time > next_run_time + critical.
	-v	
		 Enable verbose output.
	
	Examples:
		check_nls_commands.php -a 192.168.67.4 -k de57bf110ba73a36345b83dc40c261c22d2035e1 -s 1 -w 100 -c 200
		--Check if 192.168.67.4 has stale comands using SSL with -s 1
		check_nls_commands.php -a 192.168.67.4 -k de57bf110ba73a36345b83dc40c261c22d2035e1 -w 100 -c 200
		--Check if 192.168.67.4 has stale comands over 100 seconds warning, over 200 seconds critical"
    );
}
main();
?>
