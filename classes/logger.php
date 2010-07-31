<?php
/**
 * The MiniHTTPD logger class.
 * 
 * This class provides a simple framework for logging the server's activities.
 * By default it provides functionality for an access log in Apache style 
 * format, although this is easy to customize. Log entries may be buffered for 
 * batch writing to disk, depending on the configuration.
 *
 * @package    MiniHTTPD
 * @author     MiniHTTPD Team
 * @copyright  (c) 2010 MiniHTTPD Team
 * @license    BSD revised
 */
class MiniHTTPD_Logger
{
	// ------ Class variables and methods ------------------------------------------

	/**
	 * A list of output string formats for log entries.
	 * @var  array
	 */
	protected static $formats = array(
		'access' => ':address: - :user: [:date:] ":request:" :code: :bytes: ":referer:" ":useragent:"',
	);
	
	/**
	 * Internal buffers for storing log entries before batch writing to disk.
	 * @var array 
	 */
	protected static $buffers = array(
		'access' => array(
			'file' => '',
			'lines' => array(),
		),
	);
	
	/**
	 * The configuration settings.
	 * @var array
	 */
	protected static $config;
	
  /**
   * Factory method for creating configured logger objects.
   *
   * Note that this static method must be used, as objects can't be created
	 * directly. The main task here is to complete the internal configuration.
   *
   * @param   string  the configured log type
   * @return  MiniHTTPD_Logger  a logger instance
   */	
	public static function factory($type)
	{
		$logger = new MHTTPD_Logger($type);
		
		if ($type == 'access') {
			if (MHTTPD_Logger::$config['enabled'] && empty(MHTTPD_Logger::$buffers['access'])) {
				MHTTPD_Logger::$buffers['access']['file'] = MHTTPD_Logger::$config['logpath'].'mhttpd_access.log';
			}
		}
		
		return $logger;
	}
	
	/**
	 * Stores the logger configuration locally.
	 *
	 * @param   array  the configuration settings
	 * @return  void
	 */
	public static function addConfig($config) 
	{
		MHTTPD_Logger::$config = $config['Logging'];
		MHTTPD_Logger::$config['logpath'] = $config['Paths']['logs'];
	}
	
	/**
	 * Writes all of the buffered log entries to disk immediately.
	 *
	 * @return  void
	 */
	public static function flushLogs()
	{
		if (!MHTTPD_Logger::$config['enabled']) {return;}
		
		foreach (MHTTPD_Logger::$buffers as $type=>$info) {
			$fh = fopen($info['file'], 'at');
			foreach ($info['lines'] as $line) {
				fwrite($fh, $line);
			}
			fclose($fh);
			MHTTPD_Logger::$buffers[$type]['lines'] = array();
		}
	}
	
	// ------ Instance variables and methods ---------------------------------------
	
	/**
	 * The configured logger type for this instance.
	 * @var string
	 */
	protected $type;
	
	/**
	 * The current stored data used to output a single log entry.
	 * @var array
	 */
	protected $entry;

	/**
	 * Adds the formatted log entry to a buffer, or writes directly to disk.
	 *
	 * @return  void
	 */
	public function write()
	{
		// Get the formatted log line
		$line = $this->getFormatted()."\n";
		cecho($line);
				
		if (MHTTPD_Logger::$config['enabled']) {
		
			// Buffer the current log line
			MHTTPD_Logger::$buffers[$this->type]['lines'][] = $line;

			// Write the log buffer to disk now?
			if (count(MHTTPD_Logger::$buffers[$this->type]['lines']) >= MHTTPD_Logger::$config['buffer_lines']) {
				$fh = fopen(MHTTPD_Logger::$buffers[$this->type]['file'], 'at');
				foreach (MHTTPD_Logger::$buffers[$this->type]['lines'] as $line) {
					fwrite($fh, $line);
				}
				fclose($fh);
				MHTTPD_Logger::$buffers[$this->type]['lines'] = array();
			}
		}
	}
	
	/**
	 * Parses a request object to build the initial log info, or adds the request
	 * info directly.
	 *
	 * @todo Add access authorization info.
	 *
	 * @param   MiniHTTPD_Request|array  the request object or the request info
	 * @return  MiniHTTPD_Logger   this instance
	 */
	public function addRequest($request)
	{
		if ($this->type == 'access' && ($request instanceof MHTTPD_Request)) {	
			list($address,) = $request->getClientInfo();
			$this->entry = array(
				'address' => $address,
				'user' => '-',
				'date'=> date('d/M/Y:H:i:s O', time()),
				'request' => $request->getRequestLine(),
				'code' => '',
				'bytes' => '',
				'referer' => $request->getReferer(),
				'useragent' => $request->getUserAgent(),
			);
		}
		
		return $this;
	}

	/**
	 * Parses a response object to finalize the log info, or adds the response 
	 * info directly.
	 *
	 * @param   MiniHTTPD_Response|array  the response object or the response info
	 * @return  MiniHTTPD_Logger   this instance
	 */	
	public function addResponse($response)
	{
		if ($this->type == 'access') {
			if ($response instanceof MHTTPD_Response) {
				$this->entry['code'] = $response->getStatusCode();
				$this->entry['bytes'] = $response->getBytesSent();
			}
		}
		
		return $this;
	}

	/**
	 * Returns a formatted string from the currently stored log entry info.
	 *
	 * @return  string  the formatted log entry
	 */
	protected function getFormatted()
	{
		$string = MHTTPD_Logger::$formats[$this->type];
		foreach ($this->entry as $key=>$value) {
			$string = str_replace(":{$key}:", $value, $string);
		}
		return $string;
	}
	
	/**
	 * Sets the configured logger type.
	 *
	 * This also ensures that the factory method must be used to instantiate
	 * the class properly.
	 *
	 * @return  void
	 */
	protected function __construct($type)
	{
		$this->type = $type;
	}
	
} // End MiniHTTPD_Logger
