<?php
/**
 * Launches the MiniHTTPD server.
 * 
 * This file bootstraps various settings, initializes the environment, loads
 * the required function and class files and starts the main server. Note that
 * the server can't be launched properly without calling this script.
 *
 * @package    MiniHTTPD
 * @subpackage Launcher
 * @author     MiniHTTPD Team
 * @copyright  (c) 2010-2012 MiniHTTPD Team
 * @license    BSD revised
 */ 

// Set time limit to indefinite execution
set_time_limit(0);

$DS = DIRECTORY_SEPARATOR;

// Get any valid commandline options
$options = getopt('d', array('debug'));

// Set the root path for the MiniHTTPD files
if (isset($_ENV['MHTTPD_ROOT'])) {
	$exepath = realpath($_ENV['MHTTPD_ROOT']).$DS;
} elseif (isset($_SERVER['MHTTPD_ROOT'])) {
	$exepath = realpath($_SERVER['MHTTPD_ROOT']).$DS;
} else {
	$exepath = getcwd().$DS;
}

// Set the initialization path
if (isset($_ENV['MHTTPD_INIPATH'])) {
	$inipath = realpath($_ENV['MHTTPD_INIPATH']).$DS;
} elseif (isset($_SERVER['MHTTPD_INIPATH'])) {
	$inipath = realpath($_SERVER['MHTTPD_INIPATH']).$DS;
} else {
	$inipath = $exepath;
}

// Are we running with a console?
define('HAS_CONSOLE', (php_sapi_name() == 'cli'));

// Find the server configuration file
if (isset($_ENV['MHTTPD_INIFILE'])) {
	$inifile = realpath($_ENV['MHTTPD_INIFILE']);
} elseif (isset($_SERVER['MHTTPD_INIFILE'])) {
	$inifile = realpath($_SERVER['MHTTPD_INIFILE']);
} elseif ($inifile = glob($inipath.'/*.ini')) {
	$inifile = realpath($inifile[0]);
}

// Parse the configuration file
if ( !($config = @parse_ini_file($inifile, true))
	&& !($config = @parse_ini_file('config\default.ini', true))
	) {
	trigger_error("Could not load the configuration file\n", E_USER_ERROR);
}

// Get the absolute path to the server's public docroot
if ( !($docroot = realpath($inipath.$config['Paths']['docroot']))
	&& !($docroot = realpath($config['Paths']['docroot']))
	) {
	trigger_error('Could not find the docroot directory: '.$config['Paths']['docroot'].PHP_EOL, E_USER_ERROR);
}
$config['Paths']['docroot'] = $docroot.$DS;

// Get the absolute path to the server's private docroot
if ( !($server_docroot = realpath($inipath.$config['Paths']['server_docroot']))
	&& !($server_docroot = realpath($config['Paths']['server_docroot']))
	) {
	trigger_error('Could not find the server docroot directory: '.$config['Paths']['server_docroot'].PHP_EOL, E_USER_ERROR);
}
$config['Paths']['server_docroot'] = $server_docroot.$DS;

// Get the absolute path to the temp folder
if ( !($temp = realpath($inipath.$config['Paths']['temp']))
	&& !($temp = realpath($config['Paths']['temp']))
	) {
	trigger_error('Could not find the temp directory: '.$config['Paths']['temp'].PHP_EOL, E_USER_ERROR);
}
$config['Paths']['temp'] = $temp.$DS;

// Get the absolute path to the logs folder
if ( !($logs = realpath($inipath.$config['Paths']['logs']))
	&& !($logs = realpath($config['Paths']['logs']))
	) {
	trigger_error('Could not find the logs directory: '.$config['Paths']['logs'].PHP_EOL, E_USER_ERROR);
}
$config['Paths']['logs'] = $logs.$DS;

// Set the error log for the server process
ini_set('error_log', $logs.'\mhttpd_errors.log');

// Start with a clean include paths list
set_include_path('.');

// Add the local include paths
$paths = array($exepath.'lib', $exepath.'lib\minihttpd\config', $exepath.'lib\pear');
foreach ($paths as $path) {
	if (strpos(get_include_path(), $path) === false) {
		set_include_path(get_include_path().PATH_SEPARATOR.$path);
	}
}

// Set the debug option
if (isset($options['d']) || isset($options['debug'])) {
	$config['Debug']['enabled'] = '1';
}

// Update the config
$config['Paths']['inipath'] = $inipath;
$config['Paths']['exepath'] = $exepath;

// Clean up the launch variables
unset($DS, $docroot, $server_docroot, $temp, $logs, $paths, $path, $options, $inipath, $exepath);

// Load the helper functions file
require 'helpers\common.php';

// Load the required classes
if (!@include 'user_classes.php') {
	require 'classes.php';
}

// Start the server
MHTTPD::start($config);
