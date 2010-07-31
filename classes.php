<?php
/**
 * Loads the required class files and sets the class aliases.
 *
 * To extend any of the classes transparently, first copy this file and rename
 * it to 'user_classes.php'. Edit the new file to load your extended class
 * definitions immediately after the original ones, then change the relevant
 * class aliases to point to your extended classes. For example:
 *
 * <code>
 *   require 'classes\logger.php';
 *   require 'myclasses\mylogger.php'; 
 *   // class My_Logger extends MiniHTTPD_Logger {}
 *   class_alias('My_Logger', 'MHTTPD_Logger');
 * </code>
 *
 * All of the other classes will now be able to make use of this extended class
 * without any of the scripts needing to be edited, since they only reference
 * the class aliases directly, not the original class names.
 *
 * @link http://www.php.net/manual/en/function.class-alias.php
 *
 * @package    MiniHTTPD
 * @subpackage Launcher
 * @author     MiniHTTPD Team
 * @copyright  (c) 2010 MiniHTTPD Team
 * @license    BSD revised
 */

require 'classes\server.php';
class_alias('MiniHTTPD_Server', 'MHTTPD');

require 'classes\client.php';
class_alias('MiniHTTPD_Client', 'MHTTPD_Client');

require 'classes\message.php';
class_alias('MiniHTTPD_Message', 'MHTTPD_Message');

require 'classes\request.php';
class_alias('MiniHTTPD_Request', 'MHTTPD_Request');

require 'classes\response.php';
class_alias('MiniHTTPD_Response', 'MHTTPD_Response');

require 'classes\logger.php';
class_alias('MiniHTTPD_Logger', 'MHTTPD_Logger');

require 'classes\minifcgi\manager.php';
class_alias('MiniFCGI_Manager',  'MFCGI');

require 'classes\minifcgi\client.php';
class_alias('MiniFCGI_Client', 'MFCGI_Client');

require 'classes\minifcgi\record.php';
class_alias('MiniFCGI_Record', 'MFCGI_Record');

