<?php
/**
 * The main MiniHTTPD server class.
 * 
 * This static class deals with the most basic level of the server's operation. 
 * It initializes the main environment, sets up the listening socket, handles 
 * all client connections, and monitors any FastCGI connections for processing
 * by the created client objects. It also includes a number of useful helper
 * methods, e.g. an object factory, access to the configuration settings, etc.
 *
 * @package    MiniHTTPD
 * @author     MiniHTTPD Team
 * @copyright  (c) 2010 MiniHTTPD Team
 * @license    BSD revised
 */
class MiniHTTPD_Server
{
	// ------ Class constants ------------------------------------------------------	
	
	/**
	 * Current software version.
	 */
	const VERSION = '0.6';
	
	/**
	 * Supported HTTP protocol version.
	 */
	const PROTOCOL = 'HTTP/1.1';


	// ------ Class variables and methods ------------------------------------------
	
	/**
	 * Should debugging output be enabled?
	 * @var bool
	 */
	public static $debug = true;

	/**
	 * The server configuration settings.
	 * @var array
	 */	
	protected static $config;

	/**
	 * Main listening socket resource.
	 * @var resource
	 */
	protected static $listener;
	
	/**
	 * The listening socket stream type.
	 * @var string  either tcp or ssl
	 */
	protected static $type;
	
	/**
	 * Server information values.
	 * @var array
	 */
	protected static $info;

	/**
	 * The default charset string.
	 * @var string
	 */
	protected static $charset;
	
	/**
	 * Is the server running in its main loop?
	 * @var bool
	 */
	protected static $running = false;
	
	/**
	 * A list of the connected clients.
	 * @var array
	 */
	protected static $clients = array();

	/**
	 * A list of the loaded request handlers.
	 * @var array
	 */
	protected static $handlers = array();

	/**
	 * The request handler queue object
	 * @var MiniHTTPD_Handlers_Queue
	 */
	protected static $handlersQueue;
	
	/**
	 * A list of any aborted connections.
	 * @var array
	 */
	protected static $aborted = array();

	/**
	 * A list of paths from which X-SendFile request may be served.
	 * @var array
	 */	
	protected static $send_file_paths = array();
	
	/**
	 * A list of server statistics.
	 * @var array
	 */
	protected static $stats = array(
		'up'   => 0,
		'down' => 0,
	);	
	
	// ------ Public methods ------------------------------------------------------
			
	/**
	 * Initializes the server with the given configuration.
	 *
	 * This method will also spawn any configured FastCGI processes required at
	 * startup, and load the configured request handlers. Once initialized, it 
	 * starts the main server loop.
	 *
	 * @see  MiniHTTPD::main()
	 * @uses MiniFCGI_Manager::spawn()
	 * @uses MiniHTTPD_Request_Handler
	 * @uses MiniHTTPD_Handlers_Queue
	 *
	 * @param   array  configuration settings
	 * @return  void
	 */
	public static function start($config)
	{
		// Add the config
		if (!is_array($config)) {
			trigger_error("Cannot start server, invalid configuration settings", E_USER_ERROR);
		}
		MHTTPD::addConfig($config);

		// Load the mime types info
		$mimes = @parse_ini_file(EXEPATH.'lib\minihttpd\config\mimes.ini', true);
		MHTTPD::$config['Mimes'] = array_map('listToArray', $mimes['Mimes']);
		
		// Set the initial server info values
		MHTTPD::$info['software'] = 'MiniHTTPD/'.MHTTPD::VERSION.' ('.php_uname('s').')';
		$addr = $config['Server']['address'];
		$port = $config['Server']['port'];
		MHTTPD::$info['signature'] = 'MiniHTTPD/'.MHTTPD::VERSION.' ('.php_uname('s').") Server at {$addr} Port {$port}";
		MHTTPD::$info['launched'] = time();
		
		// Spawn any FCGI processes
		MFCGI::$debug = MHTTPD::$debug;
		MFCGI::spawn(null, MHTTPD::$config);
	
		// Load the configured request handlers
		if (MHTTPD::$debug) {cecho("------------------------------------\n");}
		foreach (MHTTPD::$config['Handlers'] as $type=>$handler) {
			if (class_exists($handler)) {
				if (MHTTPD::$debug) {cecho("Handler loaded ... $type\n");}
				MHTTPD::$handlers[$type] = new $handler;
			}
		}
		
		// Create the queue object for the handlers
		MHTTPD::$handlersQueue = new MHTTPD_Handlers_Queue(MHTTPD::$handlers);
		
		// Start running the main loop
		MHTTPD::$running = true;
		MHTTPD::main();
	}

	/**
	 * Factory method for creating MiniHTTPD objects.
	 *
	 * This is a helper method used mainly for creating chainable objects.
	 *
	 * @param   string  the MiniHTTPD class type suffix
	 * @return  mixed   the instantiated class object
	 */
	public static function factory($type)
	{
		$class = 'MHTTPD_'.ucfirst($type);
		return new $class;
	}
	
	/**
	 * Gracefully closes any open socket.
	 *
	 * @param   resource  the stream socket to be closed
	 * @return  void
	 */
	public static function closeSocket($socket)
	{
		$pname = stream_socket_get_name($socket, false);
		if (MHTTPD::$debug) {cecho("Closing socket: {$socket} ({$pname})\n");}
		@stream_socket_shutdown($socket, STREAM_SHUT_WR);
		usleep(500);
		@stream_socket_shutdown($socket, STREAM_SHUT_RD);
		@fclose($socket);
	}
	
	/**
	 * Determines whether the server is running in its main loop.
	 *
	 * @return  bool  true if server is running
	 */
	public static function isRunning()
	{
		return MHTTPD::$running;
	}

	/**
	 * Returns the configured public docroot path.
	 *
	 * @return  string  an absolute path
	 */
	public static function getDocroot() 
	{
		return MHTTPD::$config['Paths']['docroot'];
	}

	/**
	 * Returns the private server docroot path.
	 *
	 * @return  string  an absolute path
	 */
	public static function getServerDocroot() 
	{
		return MHTTPD::$config['Paths']['server_docroot'];
	}

	/**
	 * Returns the list of paths from which X-SendFile requests may be served.
	 *
	 * @return  array  a list of absolute paths
	 */
	public static function getSendFilePaths() 
	{
		if (!empty(MHTTPD::$send_file_paths)) {
			return MHTTPD::$send_file_paths;
		}
		
		// Store the absolute paths
		$paths = listToArray(MHTTPD::$config['Paths']['send_file']);
		$real_paths = array();
		foreach ($paths as $path) {
			if (($rpath = realpath(INIPATH.$path)) || ($rpath = realpath($path))) {
				$real_paths[] = $rpath;
			}
		}
		MHTTPD::$send_file_paths = $real_paths;
		return $real_paths;
	}
	
	/**
	 * Returns the maximum number of requests allowed for Keep-Alive connections.
	 *
	 * @return  integer  maximum requests allowed
	 */
	public static function getMaxRequests()
	{
		return MHTTPD::$config['Server']['keep_alive_max_requests'];
	}

	/**
	 * Returns the configured timeout for Keep-Alive connections.
	 *
	 * @return  integer  timeout in seconds
	 */
	public static function getAliveTimeout()
	{
		return MHTTPD::$config['Server']['keep_alive_timeout'];
	}
	
	/**
	 * Returns the list of default directory index files.
	 *
	 * @return  array  list of index filenames
	 */
	public static function getIndexFiles()
	{
		if (empty(MHTTPD::$config['Server']['index_files'])) {
			return array();
		}
		return MHTTPD::$config['Server']['index_files'];
	}
	
	/**
	 * Returns the list of file extensions to be interpreted by the PHP
	 * FastCGI processes.
	 *
	 * @return  array  list of PHP file extensions
	 */
	public static function getFCGIExtensions()
	{
		if (empty(MHTTPD::$config['FCGI']['extensions'])) {
			return array();
		}
		return MHTTPD::$config['FCGI']['extensions'];
	}
	
	/**
	 * Returns basic information about the running server.
	 *
	 * @return  array  server information
	 */
	public static function getServerInfo()
	{
		$pname = explode(':', stream_socket_get_name(MHTTPD::$listener, false));
		$info = array(
			MHTTPD::$config['Server']['address'],
			$pname[0], // address
			$pname[1], // port
			MHTTPD::$config['SSL']['enabled'],
		);
		return $info;
	}

	/**
	 * Returns the configured server signature for displaying on pages.
	 *
	 * @return  string  server signature
	 */	
	public static function getSignature()
	{
		return MHTTPD::$info['signature'];
	}

	/**
	 * Returns information about the current server software.
	 *
	 * @return  string  server software info
	 */		
	public static function getSoftwareInfo()
	{
		return MHTTPD::$info['software'];
	}	
	
	/**
	 * Returns information for listing on the Server Status page.
	 *
	 * @return  array  server status information
	 */		
	public static function getServerStatusInfo()
	{
		return array(
			MHTTPD::getSoftwareInfo(),
			date('r', MHTTPD::$info['launched']),
			MHTTPD::getStatCount('up', true),
			MHTTPD::getStatCount('down', true),
			MHTTPD::getClientsSummary(),
			MFCGI::getScoreboard(true),
			MHTTPD::getAbortedSummary(),
			MHTTPD::getHandlers(true),
			MHTTPD::getSignature(),
		);
	}

	/**
	 * Returns the path to the configured access and error logs directory.
	 *
	 * @return  string  an absolute path to the directory
	 */
	public static function getLogPath()
	{
		return MHTTPD::$config['Paths']['logs'];
	}	
	
	/**
	 * Determines whether the Server Status page should be displayed or not.
	 *
	 * @return  bool  display on true
	 */	
	public static function allowServerStatus()
	{
		return MHTTPD::$config['Admin']['allow_server_status'];
	}

	/**
	 * Determines whether the Server Info page should be displayed or not.
	 *
	 * @return  bool  display on true
	 */	
	public static function allowServerInfo()
	{
		return MHTTPD::$config['Admin']['allow_server_info'];
	}

	/**
	 * Determines whether the API Documentation page should be displayed or not.
	 *
	 * @return  bool  display on true
	 */		
	public static function allowAPIDocs()
	{
		return MHTTPD::$config['Admin']['allow_api_docs'];
	}

	/**
	 * Determines whether the Extras private server directory is accessible or not.
	 *
	 * @return  bool  display on true
	 */		
	public static function allowExtrasDir()
	{
		return MHTTPD::$config['Admin']['allow_extras_dir'];
	}
	
	/**
	 * Returns the configured user information for the server administrator.
	 *
	 * @return  array|false  admin authorization info
	 */		
	public static function getAdminAuth()
	{
		if (empty(MHTTPD::$config['Admin']['admin_user'])) {
			return false;
		}
		return array(
			'realm' => 'server admin',
			'user' => MHTTPD::$config['Admin']['admin_user'],
			'pass' => MHTTPD::$config['Admin']['admin_pass'],
		);
	}

	/**
	 * Returns the server URL for building links, based on the configured info.
	 *
	 * @return  string  base server url
	 */
	public static function getBaseUrl()
	{
		list($host, $address, $port, $ssl) = MHTTPD::getServerInfo();
		$url = $ssl ? 'https://' : 'http://';
		$url .= $host.':'.$port;
		return $url;
	}

	/**
	 * Returns a list of loaded request handlers as an array of objects or a
	 * formatted string, or a single handler object by configured type.
	 *
	 * @param   bool     return a string formatted list?
	 * @param   string   the type of single handler requested
	 * @return  mixed    false if single handler isn't found
	 */
	public static function getHandlers($asString=false, $type=null)
	{
		if (!$asString && !$type) {
			
			// Return the list of loaded objects
			return MHTTPD::$handlers;

		} elseif ($type && !$asString) {
			
			// Return a single loaded object
			if (!isset(MHTTPD::$handlers[$type])) {return false;}
			return MHTTPD::$handlers[$type];
		}
		
		// Or format the list as a string
		$ret = '';
		foreach (MHTTPD::$handlers as $type=>$handler) {
			$ret .= str_pad(ucfirst($type.' '), 20, '.')
				.sprintf(' I: %-4s M: %-4s S: %-4s E: %-4s', 
					$handler->getCount('init'),
					$handler->getCount('match'),
					$handler->getCount('success'),
					$handler->getCount('error')
				).PHP_EOL;
		}
		
		return $ret;
	}

	/**
	 * Returns the currently loaded handlers queue.
	 *
	 * @param   bool  should the queue be initialized?
	 * @return  MiniHTTPD_Handlers_Queue  the handlers queue object
	 */
	public static function getHandlersQueue($init=true)
	{
		if ($init) {MHTTPD::$handlersQueue->init();}
		return MHTTPD::$handlersQueue;
	}
	
	/**
	 * Returns the list of configured access authentication details.
	 *
	 * @return  array|bool  access authorization list, or false if none exists
	 */		
	public function getAuthList()
	{
		return !empty(MHTTPD::$config['Auth']) ? MHTTPD::$config['Auth'] : false;
	}
	
	/**
	 * Returns any configured access authentication info for the given resource.
	 *
	 * @param   string      the requested URI
	 * @return  array|bool  access authorization info, or false if none exists
	 */	
	public function getAuthInfo($uri)
	{
		if (isset(MHTTPD::$config['Auth'][$uri])) {
			$arr = listToArray(MHTTPD::$config['Auth'][$uri]);
			return array(
				'realm' => $arr[0],
				'user' => $arr[1],
				'pass' => $arr[2],
			);
		}
		return false;
	}

	/**
	 * Returns raw info from the stored configuration file.
	 *
	 * @param   string  the config section name
	 * @return  mixed   the config info, or false if empty
	 */		
	public static function getConfig($section)
	{
		if (!empty(MHTTPD::$config[$section])) {
			return MHTTPD::$config[$section];
		}
		return false;
	}
	
	/**
	 * Increments a named server statistic by the given amount.
	 *
	 * @param   string   the stat name to be incremented
	 * @param   integer  the value to be added
	 * @return  void
	 */		
	public static function addStatCount($name, $count)
	{
		if (isset(MHTTPD::$stats[$name])) {
			MHTTPD::$stats[$name] += $count;
		}
	}

	/**
	 * Returns the server stat count of the given name.
	 *
	 * @param   string   the stat name
	 * @param   bool     should the byte size be formatted?
	 * @return  void
	 */			
	public static function getStatCount($name, $formatted=false)
	{
		if (isset(MHTTPD::$stats[$name])) {
			return $formatted ? MHTTPD::formatSize(MHTTPD::$stats[$name]) : MHTTPD::$stats[$name];
		}
		return 0;
	}

	/**
	 * Converts byte sizes to formatted strings.
	 *
	 * @param   integer  the value in bytes to be formatted
	 * @param   integer  the rounding value
	 * @return  string   the formatted value
	 */			
	public static function formatSize($bytes, $round=1)
	{
		$suffix = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
		for ($i=0; $bytes > 1024 && isset($suffix[$i+1]); $i++) {$bytes /= 1024;}
		return round($bytes,$round)." ".$suffix[$i];
	}

	/**
	 * Creates a brief summary list of the connected clients.
	 *
	 * @return  string  the formatted summary list
	 */			
	public static function getClientsSummary()
	{
		$summary = '';
		foreach (MHTTPD::$clients as $client) {
			$summary .= $client->getSummary()."\n";
		}
		return $summary;
	}

	/**
	 * Creates a brief summary list of any aborted requests.
	 *
	 * @return  string  the formatted summary list
	 */			
	public static function getAbortedSummary() 
	{
		$summary = '';
		foreach (MHTTPD::$aborted as $num=>$ab) {
			$summary .= "({$num}) C: {$ab['client']}, F: {$ab['fcgi_client']}, "
				. "P: {$ab['process']} ({$ab['pid']}), T: {$ab['time']}\n";
		}
		$summary = $summary != '' ? $summary : 'None';
		return $summary;
	}
	
	/**
	 * Returns the list of configured mime types.
	 *
	 * @return  array  the mime types list
	 */			
	public static function getMimeTypes()
	{
		return MHTTPD::$config['Mimes'];
	}

	/**
	 * Returns the default formatted charset string.
	 *
	 * @return  string  the charset string
	 */				
	public static function getDefaultCharset()
	{
		if (MHTTPD::$charset !== null) {
			return MHTTPD::$charset;
		}
		$charset = ini_get('default_charset');
		if ($charset != '') {$charset = "; charset={$charset}";}
		MHTTPD::$charset = $charset;
		
		return $charset;
	}
	
	// ------ Protected/Private methods --------------------------------------------

	/**
	 * Parses the given configuration settings and stores them locally.
	 *
	 * @param   array  the configuration settings
	 * @return  void
	 */
	protected static function addConfig($config)
	{
		// Set debugging
		MHTTPD::$debug = $config['Debug']['enabled'];
		
		// Convert any config lists to arrays
		if (!empty($config['Server']['index_files'])) {
			$config['Server']['index_files'] = listToArray($config['Server']['index_files']);
		}
		if (!empty($config['FCGI']['extensions'])) {
			$config['FCGI']['extensions'] = listToArray($config['FCGI']['extensions']);
		}
		MHTTPD::$config = $config;
		
		// Set Logger config
		MHTTPD_Logger::addConfig($config);
	}
	
	/**
	 * Creates a stream context for the main server listening socket based on the
	 * configured settings.
	 *
	 * @return  resource  the stream context
	 */
	protected static function getContext()
	{
		$opts = array(
			'socket' => array(
				'backlog' => MHTTPD::$config['Server']['queue_backlog'],
			),
		);
		if (MHTTPD::$config['SSL']['enabled']) {
			
			// Find SSL certificate file
			$cert = MHTTPD::$config['SSL']['cert_file'];
			if ( !($cert_file = realpath($cert))
				&& !($cert_file = realpath(INIPATH.$cert))
				) {
				trigger_error("Cannot find SSL certificate file: {$cert}", E_USER_ERROR);
			}
			
			// Add SSL options
			$opts['ssl'] = array(
				'local_cert' => $cert_file,
				'passphrase' => MHTTPD::$config['SSL']['passphrase'],
				'allow_self_signed' => true,
				'verify_peer' => false,
			);
		}
		
		return stream_context_create($opts);
	}
	
	/**
	 * Creates the main server listening socket on the configured address/port.
	 *
	 * stream_socket_server() is used here rather than the sockets extension
	 * mainly due to its inbuilt support for SSL connections.
	 *
	 * @param   resource  the stream context
	 * @return  void
	 */
	protected static function createListener($context)
	{
		$type = MHTTPD::$config['SSL']['enabled'] ? 'ssl' : 'tcp';
		$addr = MHTTPD::$config['Server']['address'];
		$port = MHTTPD::$config['Server']['port'];
		$t = '';
		if (!(MHTTPD::$listener = stream_socket_server("{$type}://{$addr}:{$port}", $errno, $errstr, 
			STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context))
			) {
			trigger_error("Could not create ".strtoupper($type)." server socket", E_USER_ERROR);
		}
		if ($type == 'ssl') {$t = ' (SSL)';}
		if (MHTTPD::$debug) {
			cecho("\n------------------------------------\n");
			cecho("Created ".strtoupper($type)." listener: ".stream_socket_get_name(MHTTPD::$listener, false)."\n\n");
		} else {
			cecho("Started MiniHTTPD server on {$addr}, port {$port}{$t} ...\n\n");
		}
	}

	/**
	 * Handles the client response for any unfinished requests.
	 *
	 * @param   MiniHTTPD_Client  the client object
	 * @return  void
	 */
	protected static function handleResponse(MiniHTTPD_Client $client)
	{
		if (!$client->isFinished()) {
			
			if ($client->isStreaming() && !$client->hasOpenStream()) {
				
				// The client has finished streaming a static file
				if (MHTTPD::$debug) {cecho("Client ({$client->getID()}) ... stream ended ");}
				$client->finish();
			
			} elseif ($client->isChunking() && !$client->hasOpenFCGI()) {
				
				// Flush any remaining chunked output
				if ($client->hasResponseContent())  {
					if (MHTTPD::$debug) {cecho("Client ({$client->getID()}) ... flushing ");}
					$client->sendResponse();
				}
				
				// Finish the chunked response
				if (MHTTPD::$debug) {cecho("Client ({$client->getID()}) ... chunking ended ");}
				$client->finish();
			
			} else {
			
				// The client is sending the response
				if (MHTTPD::$debug) {cecho("Client ({$client->getID()}) ... sending ");}
				$client->sendResponse();
			}
			
			// Complete logging
			if (!$client->isStreaming() && !$client->isChunking()) {
				if (MHTTPD::$debug) {cecho("... done\n\n");}
				$client->writeLog();
			}
		}
	}
	
	/**
	 * Removes the client from the active queue.
	 *
	 * The client's finish() method will be called and its socket will be closed
	 * gracefully. If an active FCGI request has been aborted, the FCGI socket
	 * will be queued in the $aborted list for handling later.
	 *
	 * @see MiniFCGI_client::finish()
	 *
	 * @param   MiniHTTPD_Client  the client object
	 * @return  void
	 */
	protected static function removeClient(MiniHTTPD_Client $client) 
	{
		$clientID = $client->getID();
				
		if (MHTTPD::$debug) {cecho("Client ({$clientID}) ... removing client ");}
		
		// Has an FCGI request been aborted?
		if ($client->hasOpenFCGI()) {
			
			// Queue the aborted request for handling later
			MHTTPD::$aborted[] = array(
				'client' => $clientID,
				'fcgi_client' => $client->getFCGIClientID(),
				'process' => $client->getFCGIProcess(),
				'pid' => MFCGI::getPID($client->getFCGIProcess()),
				'socket' => $client->getFCGISocket(),
				'time' => time(),
			);
			
			if (MHTTPD::$debug) {
				cecho("\nClient ({$clientID}) ... aborted FCGI request");
				cecho("\nClient ({$clientID}) ");
			}
		}
	
		// Finish up
		if (!$client->isFinished()) {
			$client->finish();
			if (MHTTPD::$debug) {cecho("\n");}
			$client->writeLog();
		}
		if (MHTTPD::$debug) {cecho("\n");}
		MHTTPD::closeSocket($client->getSocket());
		unset(MHTTPD::$clients[$clientID]);
	}

	/**
	 * This method should be called to gracefully shut down the server.
	 *
	 * @todo Allow FCGI_Manager to kill the FCGI processes.
	 *
	 * @return  void
	 */
	protected static function shutdown()
	{
		if (MHTTPD::$debug) {cecho("Shutting down the server ...\n");}
		MHTTPD::$running = false;
		
		// Close listener
		MHTTPD::closeSocket(MHTTPD::$listener);
		
		// Kill all running FCGI processes
		exec('taskkill /F /IM php-cgi.exe');
				
		// Remove any active clients
		foreach ($this->clients as $client) {
			MHTTPD::removeClient($client);
		}
		
		// Flush any buffered logs
		MHTTPD_Logger::flushLogs();		
	}
	
	/**
	 * Launches the default browser in the background and navigates to the 
	 * default index page (after a brief delay).
	 *
	 * rundll32.exe/url.dll is called here using WScript over the other options 
	 * due to its generally more reliable performance ('start $url' produces an
	 * annoying error popup if the browser isn't already open).
	 *
	 * @todo Test this with other Windows versions > XP SP3.
	 *
	 * @return  void
	 */
	protected static function launchBrowser()
	{
		if (MHTTPD::$config['Other']['browser_autolaunch']) {
			$url  = MHTTPD::$config['SSL']['enabled'] ? 'https://' : 'http://';
			$url .= MHTTPD::$config['Server']['address'].':'.MHTTPD::$config['Server']['port'].'/';
			$cmd = 'cmd.exe /C start /B /WAIT PING 127.0.0.1 -n 3 -w 1000 >NUL '
				.'& start /B rundll32.exe url.dll,FileProtocolHandler '.$url;
			$wshShell = new COM('WScript.Shell');
			$wshShell->Run($cmd, 0, false);
		}
	}
	
	/**
	 * Runs the main server loop.
	 *
	 * This is where the server does most if its work. Once the listening socket
	 * is established, iterations of the the main loop are controlled entirely by
	 * stream_select() for both client connections and FCGI requests as well as any
	 * open file streams. Each loop should ideally finish as quickly as possible to
	 * enable best concurrency.
	 *
	 * @todo Add a proper system for timing out idle/slow client connections.
	 *
	 * @return  void
	 */
	protected static function main()
	{
		// Create a TCP/SSL server socket context
		$context = MHTTPD::getContext();
		
		// Start the listener
		MHTTPD::createListener($context);

		// Initialize some handy vars
		$timeout = ini_get("default_socket_timeout");
		$maxClients = MHTTPD::$config['Server']['max_clients'];
		
		// Start the browser
		MHTTPD::launchBrowser();
		
		// The main loop
		while (MHTTPD::$running) 	{	
		
			// Build a list of active streams to monitor
			$read = array('listener' => MHTTPD::$listener);
			foreach (MHTTPD::$clients as $i=>$client) {
							
				// Add any client sockets
				if ($csock = $client->getSocket()) {
					$read["client_$i"] = $csock;
					
					// Add any client FCGI sockets
					if ($cfsock = $client->getFCGISocket()) {
						$read["clfcgi_$i"] = $cfsock;
					}
					
					// Add any client file streams
					if ($client->isStreaming()) {
						$read["clstrm_$i"] = $client->getStream();
					}
				}
			}
			
			// Add any aborted FCGI requests
			foreach (MHTTPD::$aborted as $aID=>$ab) {
				$read['aborted_'.$aID.'_'.$ab['client']] = $ab['socket'];
			}

			if (MHTTPD::$debug) {
				cecho("FCGI scoreboard:\n"); cprint_r(MFCGI::getScoreboard(true)); cecho("\n");
				cecho("Pre-select:\n"); cprint_r($read);
			}
			
			// Wait for any new activity
			if (MHTTPD::$debug) {cecho("=== Waiting for connections ===\n\n");}
			if (!($ready = @stream_select($read, $write=null, $error=null, null))) {
				trigger_error("Could not select streams", E_USER_WARNING);
			}
			
			if (MHTTPD::$debug) {cecho("Post-select:\n"); cprint_r($read);}
						
			// Check if the listener has a new client connection
			if (in_array(MHTTPD::$listener, $read)) {
				
				// Search for a free slot to add the new client
				for ($i = 1; $i <= $maxClients; $i++) {
					
					if (!isset(MHTTPD::$clients[$i])) {
						
						// This slot is free, so add the new client connection
						if (MHTTPD::$debug) {cecho("New client ($i): ");}
						if (!($sock = @stream_socket_accept(MHTTPD::$listener, $timeout, $peername))) {
							if (MHTTPD::$debug) {cecho("\nCould not accept client stream\n");}
							break;
						}
						if (MHTTPD::$debug) {cecho("$peername\n");}
						$client = new MHTTPD_Client($i, $sock, $peername);
						$client->debug = MHTTPD::$debug;
						MHTTPD::$clients[$i] = $client;
						break;
					
					} elseif ($i == $maxClients) {
						
						// No free slots, so the request goes to the backlog
						if (MHTTPD::$debug) {cecho("No free client slots!\n");}
						trigger_error("Too many clients", E_USER_NOTICE);
					}
				}
				
				// Return to waiting if only the listener is active
				if (--$ready <= 0) {
					if (MHTTPD::$debug) {cecho("No other connections to handle\n\n");}
					continue;
				}
			}
			
			// Process the current client list
			foreach (MHTTPD::$clients as $i=>$client) {
		 
				if (MHTTPD::$debug) {cecho("Client ($i) ... ");}
				$csock = $client->getSocket();
				
				// Handle any queued client requests
				if ($client->isReady() && $csock && in_array($csock, $read)) {
				
					// Start reading the request
					if (MHTTPD::$debug) {cecho("reading ... ");}
					$client->setTimeout(10);
					$input = '';
					
					// Get the request header block only
					while (	$buffer = @fgets($csock, 1024)) {
						$input .= $buffer;
						if ($buffer == '' || substr($input, -4) == "\r\n\r\n") {
							break;
						}
					}
					if ($input) {
						
						// Store the headers and process the request
						if (MHTTPD::$debug) {cecho("done\n");}
						$client->setInput(trim($input));
						if (!$client->processRequest() && !$client->needsAuthorization()) {
							MHTTPD::removeClient($client);
						}
					
					} elseif ($input == '') {
						
						// No data, meaning client has disconnected
						if (MHTTPD::$debug) {cecho("disconnected\n");}
						MHTTPD::removeClient($client);
						continue;
					
					} elseif ($input === false) {

						// Something went wrong ...
						if (MHTTPD::$debug) {cecho("oops, input is false\n");}
						MHTTPD::removeClient($client);
						continue;
						
					}				
					
				// Handle any inactive client connections
				} else {
					
					// TODO: add a timeout tracker, e.g. set/update timer, Send 408 and close, etc.
					if (MHTTPD::$debug) {
						cecho('inactive (');
						cecho('req:'.$client->hasRequest());
						cecho(' resp:'.$client->hasResponse());
						cecho(' fcgi:'.$client->hasFCGI());
						cecho(")\n");
					}
				}
				
				// Handle any queued FCGI requests
				if ($clfsock =& $client->getFCGISocket() && in_array($clfsock, $read)) {
					if (MHTTPD::$debug) {cecho("Client ($i) ... reading FCGI socket: {$clfsock}\n");}
					if (!$client->readFCGIResponse()) {
						MHTTPD::removeClient($client); // abort any hanging connections
					}
				}
			}
						
			// Handle any outgoing client responses in a new loop
			foreach (MHTTPD::$clients as $i=>$client) {
				if ($client->hasRequest()) {
					if ($client->hasFCGI() && !$client->hasResponse()) {
						if (MHTTPD::$debug){cecho("Client ($i) ... waiting for FCGI response\n");}
						continue;
					} elseif ($client->needsAuthorization()) {
						if (MHTTPD::$debug){cecho("Client ($i) ... waiting for authorization\n");}
						continue;
					} elseif ($client->hasResponse()) {
						if (MHTTPD::$debug) {cecho("Client ($i) ... handling response\n");}
						MHTTPD::handleResponse($client);
					}
				}
			}
		
			// Handle any aborted FCGI requests
			foreach ($read as $r) {
				foreach (MHTTPD::$aborted as $aID=>$ab) {
					if ($r == $ab['socket']) {
						MFCGI::removeClient($ab['process']);
						MHTTPD::closeSocket($r);
						unset(MHTTPD::$aborted[$aID]);
					}
				}
			}
			
			// End of while loop
			if (MHTTPD::$debug) {cecho("\n");}
		}
		
		// Quit the server cleanly
		MHTTPD::shutdown();
	}
	
	/**
	 * Prevents instantiation of this static class.
	 *
	 * @return  void
	 */
	final private function __construct() {}
	
} // End MiniHTTPD_Server
