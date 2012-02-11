<?php
/**
 * This file contains some basic helper functions for running the server.
 
 * @package    MiniHTTPD
 * @subpackage Launcher 
 * @author     MiniHTTPD Team
 * @copyright  (c) 2010-2012 MiniHTTPD Team
 * @license    BSD revised
 */

/**
 * Wrapper for the echo function.
 *
 * Text will be echoed to the console only if one is available.
 *
 * @param   string  text to be echoed
 * @return  void
 */
function cecho($text)
{
	if (HAS_CONSOLE) {
		echo $text;
	}
}

/**
 * Wrapper for the print_r() function.
 *
 * Arrays will be pretty-printed to the console only if one is available.
 *
 * @param   array  array to be pretty-printed
 * @return  void
 */
function cprint_r($array)
{
	if (HAS_CONSOLE) {
		print_r($array);
	}
}

/**
 * Converts comma-separated lists into arrays.
 *
 * This is used to parse the configuration file settings for local storage.
 *
 * @param   string  a comma-separated list
 * @return  array   the parsed list
 */
function listToArray($list)
{
	$arr = array();
	if ($list != '') {
		$items = explode(',', $list);
		foreach ($items as $key=>$value) {
			$value = trim($value);
			if ($value != '') {$arr[] = $value;}
		}
	}
	return $arr;
}
