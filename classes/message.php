<?php
/**
 * The base class for the MiniHTTPD request and response classes.
 * 
 * This class contains common methods for handling both requests and responses,
 * key among which is the parser. Note that the class needs to be able to handle
 * both server client and FCGI client messages.
 *
 * @package    MiniHTTPD
 * @author     MiniHTTPD Team
 * @copyright  (c) 2010-2012 MiniHTTPD Team
 * @license    BSD revised
 */
class MiniHTTPD_Message	
{	
	// ------ Class variables and methods -------------------------------------------
	
	/**
	 * List of HTTP/1.1 response codes and messages
	 * @var array
	 */
	protected static $codes = array(
	
		// Informational 1xx
		100 => 'Continue',
		101 => 'Switching Protocols',

		// Success 2xx
		200 => 'OK',
		201 => 'Created',
		202 => 'Accepted',
		203 => 'Non-Authoritative Information',
		204 => 'No Content',
		205 => 'Reset Content',
		206 => 'Partial Content',
		207 => 'Multi-Status',

		// Redirection 3xx
		300 => 'Multiple Choices',
		301 => 'Moved Permanently',
		302 => 'Found', // 1.1
		303 => 'See Other',
		304 => 'Not Modified',
		305 => 'Use Proxy',
		307 => 'Temporary Redirect',

		// Client Error 4xx
		400 => 'Bad Request',
		401 => 'Unauthorized',
		402 => 'Payment Required',
		403 => 'Forbidden',
		404 => 'Not Found',
		405 => 'Method Not Allowed',
		406 => 'Not Acceptable',
		407 => 'Proxy Authentication Required',
		408 => 'Request Timeout',
		409 => 'Conflict',
		410 => 'Gone',
		411 => 'Length Required',
		412 => 'Precondition Failed',
		413 => 'Request Entity Too Large',
		414 => 'Request-URI Too Long',
		415 => 'Unsupported Media Type',
		416 => 'Requested Range Not Satisfiable',
		417 => 'Expectation Failed',
		422 => 'Unprocessable Entity',
		423 => 'Locked',
		424 => 'Failed Dependency',

		// Server Error 5xx
		500 => 'Internal Server Error',
		501 => 'Not Implemented',
		502 => 'Bad Gateway',
		503 => 'Service Unavailable',
		504 => 'Gateway Timeout',
		505 => 'HTTP Version Not Supported',
		507 => 'Insufficient Storage',
		509 => 'Bandwidth Limit Exceeded'
	);

	/**
	 * List of response types that should not include a body
	 * @var array
	 */	
	protected static $withoutBody = array(100, 101, 204, 205, 304);
	
	/**
	 * Returns a formatted HTTP status line.
	 *
	 * @param   integer  the response code
	 * @return  string   the formatted status line
	 */
	public static function httpStatus($code)
	{
		return isset(MHTTPD_Message::$codes[$code]) ? MHTTPD::PROTOCOL.' '.MHTTPD_Message::httpCode($code) : false;
	}
	
	/**
	 * Returns information about a response code.
	 *
	 * @param   integer  the response code
	 * @param   bool     only return the response message?
	 * @return  string   the requested information
	 */
	public static function httpCode($code, $messageOnly=false)
	{
		if (!isset(MHTTPD_Message::$codes[$code])) {
			return false;
		} else {
			$ret = '';
			if (!$messageOnly) {$ret .= $code.' ';}
			$ret .= MHTTPD_Message::$codes[$code];
			return $ret;
		}
	}

	// ------ Instance variables and methods ----------------------------------------

	/**
	 * Should debugging output be enabled? 
	 * @var bool
	 */
	public $debug = false;

	/**
	 * The raw message input, stored locally when debugging only.
	 * @var string
	 */
	protected $input = '';
	
	/**
	 * The list of parsed message headers. 
	 * @var array 
	 */
	protected $headers = array();
	
	/**
	 * The parsed message body (may be partially buffered).
	 * @var string
	 */
	protected $body = '';

	/**
	 * Has the whole header block been parsed?
	 * @var bool
	 */
	protected $hasHeaderBlock = false;
	
	/**
	 * Parses the given string into a list of message headers and a message body,
	 * then stores the results locally.
	 *
	 * This method works slightly differently depending on whether the message is
	 * a request or a response from an FCGI process, both in terms of how the info
	 * is parsed and how it is then stored for later processing. 
	 *
	 * Multiple header values are supported as follows if $replace is FALSE:
	 *
	 * - If the header entry is an array, new values will be added to the array
	 *   (usually for sending later as separate header lines)
	 * - If the header entry is a string, new values will be appended to a
	 *   comma-separated list.
	 *
	 * @param   string  the message to be parsed
	 * @param   bool    should the header names be lowercased?
	 * @param   bool    allow multiple header values?
	 * @return  bool    true on success
	 */
	public function parse($string, $lowercase=true, $replace=false)
	{
		if ($this->debug) {$this->input .= $string;}
		
		// If headers are done, append $string to body
		if ($this->hasHeaderBlock) {
			$this->body .= $string;
			return true;
		}

		// Split the header and body blocks
		if ($pos = strpos($string, "\r\n\r\n")) {
			$head = substr($string, 0, $pos + 4);
			$this->body = substr($string, $pos + 4);
			$this->hasHeaderBlock = true;
		} else {
			$head = $string;
		}

		// Start parsing the headers
		$str = strtok($head, "\n");
		$h = null;

		while ($str !== false) {
		
			// Don't parse any empty lines
			if ($h && trim($str) == '') {
				$h = false;
				continue;
			}
			
			// Parse the info/status line
			if ($h !== false && strpos($str, 'HTTP/') !== false) {
				$h = true;
				if ($this instanceof MHTTPD_Response) {
					
					// Response: process status
					$this->status = trim($str);
					$this->parseHttpStatus();

				} else {
					
					// Request: process info
					$info = explode(' ', trim($str));
					$this->info = array(
						'request' => trim($str),
						'method' => $info[0],
						'url' => $info[1],
						'url_parsed' => parse_url(str_replace(array('../', '..\\'), '', $info[1])),
						'protocol' => $info[2],
					);
					
					// Get the initial path info
					$this->info['path_parsed'] = pathinfo($this->info['url_parsed']['path']);
				}
								
			// Parse header values
			} elseif ($h !== false && strpos($str, ':') !== false) {
				$h = true;
				list($headername, $headervalue) = explode(':', trim($str), 2);
				if ($lowercase) {$headername = strtolower($headername);}
				$headervalue = ltrim($headervalue);
				
				if ($headername == 'Status' && ($this instanceof MHTTPD_Response)) {
					
					// Handle any Status headers from FCGI
					$this->status = MHTTPD::PROTOCOL.' '.$headervalue;
					$this->parseHttpStatus();
				
				} else {
				
					// Add or append new header values
					if (!$replace && isset($this->headers[$headername]) && $this->headers[$headername] != '' 
						&& is_array($this->headers[$headername])
						) {
						$this->headers[$headername][] = $headervalue;
					} elseif (!$replace && isset($this->headers[$headername]) && $this->headers[$headername] != '') {
						$this->headers[$headername] .= ','.$headervalue;
					} else {
						$this->headers[$headername] = $headervalue;
					}
				}
			}

			// Continue parsing
			$str = strtok("\n");
		}

		return true;
	}
	
	/**
	 * Determines whether a header by the given name exists.
	 *
	 * @param   string  the header name
	 * @param   bool    is the search case-insensitive?
	 * @return  bool    true if header found
	 */
	public function hasHeader($name, $nocase=false)
	{
		if (!$nocase) {
			return isset($this->headers[$name]) && $this->headers[$name] != '';
		}

		// Case-insensitive search
		return ($this->getHeader($name, true) !== '');
	}

	/**
	 * Returns the stored header value for the given header name.
	 *
	 * @param   string  the header name
	 * @param   string  should the search be case-insensitive?
	 * @return  string  the header value, or blank if none exists
	 */
	public function getHeader($name, $nocase=false)
	{
		if (!$nocase) {
			return isset($this->headers[$name]) && $this->headers[$name] != '' ? $this->headers[$name] : '';
		}
		
		// Case-insensitive search
		$name = strtolower($name);
		foreach ($this->headers as $hname=>$value) {
			if (strtolower($hname) == $name) {
				return $this->headers[$hname];
			}
		}
		return '';
	}

	/**
	 * Returns the list of stored headers as a single block.
	 *
	 * @return  string  the header block
	 */
	public function getHeaderBlock()
	{
		$headers = $this->status."\r\n";
		foreach ($this->headers as $header=>$value) {
			if (is_array($value)) foreach ($value as $val) {
				if ($val !== '') {$headers .= "{$header}: {$val}\r\n";}
			} elseif ($value !== '') {
				$headers .= "{$header}: {$value}\r\n";
			}
		}
		$headers .= "\r\n";
		
		return $headers;
	}
	
	/**
	 * Searches for a string in the header block, can be more accurate than
	 * hasHeader() if the string case isn't known.
	 *
	 * @param   string  the search string
	 * @param   bool    case-insensitive search?
	 * @return  bool    true if matched
	 */
	public function inHeaders($search, $nocase=true)
	{
		$headers = $this->getHeaderBlock();
		if ($nocase) {
			return (stripos($headers, $search) !== false);
		}
		return (strpos($headers, $search) !== false);
	}
	
	/**
	 * Removes a header from the current message.
	 *
	 * @param   string  the header name
	 * @param   bool    should the search be case-insensitive?
	 * @return  MiniHTTPD_Message  this instance
	 */
	public function removeHeader($name, $nocase=false)
	{
		// Case-sensitive search
		if (!$nocase) {
			if (isset($this->headers[$name])) {unset($this->headers[$name]);}
			return $this;
		}
		
		// Case-insensitive search
		$name = strtolower($name);
		foreach ($this->headers as $hname=>$value) {
			if (strtolower($hname) == $name) {
				unset($this->headers[$hname]);
			}
		}
		return $this;
	}
	
	/**
	 * Returns the list of stored headers.
	 *
	 * @return  array  the stored headers
	 */
	public function getHeaders()
	{
		return $this->headers;
	}

	/**
	 * Determines whether the current object has finished parsing headers.
	 *
	 * @return  bool
	 */
	public function hasAllHeaders()
	{
		return $this->hasHeaderBlock;
	}
	
	/**
	 * Determines whether the current object includes a message body.
	 *
	 * @return  bool
	 */
	public function hasBody()
	{
		return $this->body != '';
	}
	
	/**
	 * Returns the message body either as a string or as a stream resource,
	 * depending on how it has been stored locally.
	 *
	 * @return  string|resource
	 */
	public function getBody()
	{
		if (($this instanceof MiniHTTPD_Response) && $this->hasStream()) {
			return $this->stream;
		} else {
			return $this->body;
		}
	}

	/**
	 * Sets the message body.
	 *
	 * @return  string|resource
	 */	
	public function setBody($input)
	{
		$this->body = $input;
	}

	/**
	 * Appends the given input value to the existing message body.
	 *
	 * @param   string  input value
	 * @return  MiniHTTPD_Message  this instance
	 */
	public function append($input)
	{
		$this->body .= $input;
		return $this;
	}
	
	/**
	 * Returns the unparsed input.
	 *
	 * @return  string  the raw input
	 */
	public function getInput()
	{
		return $this->input;
	}
	
	/**
	 * Returns any username used for access authorization.
	 *
	 * @return  string  the username
	 */
	public function getUsername()
	{
		return isset($this->info['username']) ? $this->info['username'] : false;
	}	

} // End MiniHTTPD_Message
