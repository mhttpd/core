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
 * @copyright  (c) 2010 MiniHTTPD Team
 * @license    BSD revised
 */ 

// Set time limit to indefinite execution
set_time_limit(0);

$DS = DIRECTORY_SEPARATOR;

// Set the root path for the MiniHTTPD files
if (!defined('EXEPATH') && isset($_ENV['MHTTPD_ROOT'])) {
	define('EXEPATH', realpath($_ENV['MHTTPD_ROOT']).$DS);
} elseif (!defined('EXEPATH') && isset($_SERVER['MHTTPD_ROOT'])) {
	define('EXEPATH', realpath($_SERVER['MHTTPD_ROOT']).$DS);
} elseif (!defined('EXEPATH')) {
	define('EXEPATH', getcwd().$DS);
}

// Set the initialization path
if (!defined('INIPATH') && isset($_ENV['MHTTPD_INIPATH'])) {
	define('INIPATH', realpath($_ENV['MHTTPD_INIPATH']).$DS);
} elseif (!defined('INIPATH') && isset($_SERVER['MHTTPD_INIPATH'])) {	
	define('INIPATH', realpath($_SERVER['MHTTPD_INIPATH']).$DS);
} elseif (!defined('INIPATH')) {
	define('INIPATH', EXEPATH);
}

// Are we running with a console?
if(php_sapi_name() == 'cli') {
	define('HAS_CONSOLE', 1);
} else {
	define('HAS_CONSOLE', 0);
}

// Parse the configuration file
if ($inifile = glob(INIPATH.'/*.ini')) {$inifile = $inifile[0];}
if ( !($config = @parse_ini_file($inifile, true))
	&& !($config = @parse_ini_file('default.ini', true))
	) {
	trigger_error("Could not load configuration file\n", E_USER_ERROR);
}

// Get the absolute path to the server's public docroot
if ( !($docroot = realpath(INIPATH.$config['Paths']['docroot']))
	&& !($docroot = realpath($config['Paths']['docroot']))
	) {
	trigger_error('Could not find the docroot directory: '.$config['Paths']['docroot'].PHP_EOL, E_USER_ERROR);
}
$config['Paths']['docroot'] = $docroot.$DS;

// Get the absolute path to the server's private docroot
if ( !($server_docroot = realpath(INIPATH.$config['Paths']['server_docroot']))
	&& !($server_docroot = realpath($config['Paths']['server_docroot']))
	) {
	trigger_error('Could not find the server docroot directory: '.$config['Paths']['server_docroot'].PHP_EOL, E_USER_ERROR);
}
$config['Paths']['server_docroot'] = $server_docroot.$DS;

// Get the absolute path to the temp folder
if ( !($temp = realpath(INIPATH.$config['Paths']['temp']))
	&& !($temp = realpath($config['Paths']['temp']))
	) {
	trigger_error('Could not find the temp directory: '.$config['Paths']['temp'].PHP_EOL, E_USER_ERROR);
}
$config['Paths']['temp'] = $temp.$DS;

// Get the absolute path to the logs folder
if ( !($logs = realpath(INIPATH.$config['Paths']['logs']))
	&& !($logs = realpath($config['Paths']['logs']))
	) {
	trigger_error('Could not find the logs directory: '.$config['Paths']['logs'].PHP_EOL, E_USER_ERROR);
}
$config['Paths']['logs'] = $logs.$DS;

// Set the error log for the server process
ini_set('error_log', $logs.'\mhttpd_errors.log');

// Add any local include paths
$paths = array(EXEPATH.'lib', EXEPATH.'lib\pear\classes');
foreach ($paths as $path) {
	if (strpos(get_include_path(), $path) === false) {
		set_include_path(get_include_path().PATH_SEPARATOR.$path);
	}
}

// Clean up the launch variables
unset($DS, $docroot, $server_docroot, $temp, $logs, $paths, $path);

// Load the helper functions file
require 'functions\common.php';

// Load the required classes
if (!@include 'user_classes.php') {
	require 'classes.php';
}

// Start the server
MHTTPD::start($config);
