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
	 * Maximum number of headers that can be stored
	 * @var integer
	 */	
	protected static $maxHeaders = 100;

	/**
	 * Maximum allowed size of any header name
	 * @var integer
	 */	
	protected static $maxHeaderNameSize = 256;
	
	/**
	 * Maximum allowed size of any header value
	 * @var integer
	 */	
	protected static $maxHeaderValueSize = 8190;

	/**
	 * Maximum allowed size of the header block
	 * @var integer
	 */	
	protected static $maxHeaderBlockSize = NULL;
	
	/**
	 * List of response types that should not include a body
	 * @var array
	 */	
	protected static $withoutBody = array(100, 101, 204, 205, 304);

	/**
	 * List of header types that are not allowed multiple entries
	 * @var array
	 */	
	protected static $singleHeaders = array('Connection', 'Keep-Alive');
	
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
		}
		$msg = MHTTPD_Message::$codes[$code];
		
		return ($messageOnly ? $msg : $code.' '.$msg);
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
	 * Multiple headers of the same type are handled as follows if $replace is FALSE:
	 *
	 * - For responses: multiple values will be stored in an array for the header,
	 *                  usually for sending to the client as multiple header lines
	 * - For requests:  new values will be appended to a single, comma-separated
	 *                  list for the header
	 *
	 * The $replace parameter and stored $singleHeaders list can be used to override
	 * this default behaviour.
	 *
	 * @param   string  the message to be parsed
	 * @param   bool    should the header names be lowercased?
	 * @param   bool    prevent multiple header entries?
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
				
				// Verify max length
				$str = trim($str);
				$str = substr($str, 0, min(strlen($str), MHTTPD_Message::$maxHeaderValueSize));
				
				if ($this instanceof MHTTPD_Response) {
					
					// Response: process status
					$this->status = $str;
					$this->parseHttpStatus();

				} else {
					
					// Request: process info
					$info = explode(' ', $str);
					$this->info = array(
						'request' => $str,
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

				// Verify header lengths
				$headervalue = ltrim($headervalue);
				$headervalue = substr($headervalue, 0, min(strlen($headervalue), MHTTPD_Message::$maxHeaderValueSize));
				$headername  = substr($headername, 0, min(strlen($headername), MHTTPD_Message::$maxHeaderNameSize));
				
				if ($headername == 'Status' && ($this instanceof MHTTPD_Response)) {
					
					// Handle any Status headers from FCGI
					$this->status = MHTTPD::PROTOCOL.' '.$headervalue;
					$this->parseHttpStatus();
				
				} elseif ($replace || in_array($headername, MHTTPD_Message::$singleHeaders)) {
					
					// Set the header value to the last received
					$this->headers[$headername] = $headervalue;

				} else {

					// Combine or append multiple header values
					$this->addHeader($headername, $headervalue, ($this instanceof MHTTPD_Request));
				}
			
			// Parse any multi-line header continuation lines
			} elseif ($h !== false && ($str[0] == ' ' || $str[0] == "\t") 
				&& isset($headername) && isset($this->headers[$headername])
				) {
				$hlen = $this->getHeaderSize($headername);
				if ($hlen < MHTTPD_Message::$maxHeaderValueSize) {
					$val = ' '.trim($str);
					$val = substr($val, 0, min(strlen($val), MHTTPD_Message::$maxHeaderValueSize - $hlen));
					if (is_array($this->headers[$headername])) {
						$last = count($this->headers[$headername]) - 1;
						$this->headers[$headername][$last] .= $val;
					} else {
						$this->headers[$headername] .= $val;
					}
				}
			}
			
			// Continue parsing
			$str = strtok("\n");
		}

		return true;
	}

	/**
	 * Adds a new header value either by combining with existing values or appending
	 * to an array of mutiple header values. If the header doesn't exist, it will
	 * be created with a single string value.
	 *
	 * Generally speaking, it's better to build multiple header values as a comma-
	 * separated list, but in some circumstances creating multiple headers as
	 * individual lines is needed, in which case an array of values can be built
	 * by setting $combine to FALSE.
	 *
	 * Identical values will not be repeated, and values within quotation marks are
	 * regarded as different from those without.
	 *
	 * @param   string  header name
	 * @param   string  header value
	 * @param   bool    combine values in a comma-separated list?
	 * @return  MiniHTTPD_Message  this instance
	 */
	public function addHeader($name, $value, $combine=true)
	{
		// Verify the header name
		$name = substr($name, 0, min(strlen($name), MHTTPD_Message::$maxHeaderNameSize));
		
		if ($combine && isset($this->headers[$name]) && !is_array($this->headers[$name])) {
			
			// Combine header values as a comma-separated list
			if (strpos($this->headers[$name], $value) === false
				&& strpos('"'.$this->headers[$name].'"', $value) === false
				) {
				$hlen = $this->getHeaderSize($name);
				if ($hlen < MHTTPD_Message::$maxHeaderValueSize) {
					$value = ', '.$value;
					if (($vlen = min(strlen($value), MHTTPD_Message::$maxHeaderValueSize - $hlen)) > 2) {
						$this->headers[$name] .= substr($value, 0, $vlen);
					}
				}
			}

		} elseif (isset($this->headers[$name]) && $this->headers[$name] != '') {
			
			// Create an array of multiple header values
			if (!is_array($this->headers[$name])) {
				$vals = explode(',', $this->headers[$name]);
				$this->headers[$name] = array();
				foreach ($vals as $val) {
					$this->headers[$name][] = trim($val);
				}
			}
			
			// Only add the value if it doesn't already exist
			if (!$this->hasMaxHeaders() && !in_array($value, $this->headers[$name])) {
				$hlen = $this->getHeaderSize($name);
				if ($hlen < MHTTPD_Message::$maxHeaderValueSize) {
					$this->headers[$name][] = substr($value, 0, min(strlen($value), MHTTPD_Message::$maxHeaderValueSize - $hlen));
				}
			}
		
		} elseif (!$this->hasMaxHeaders()) {
		
			// Set the single header value string
			$this->headers[$name] = substr($value, 0, min(strlen($value), MHTTPD_Message::$maxHeaderValueSize));
		}

		return $this;
	}

	/**
	 * Determines whether the maximum number of allowed headers has been reached.
	 *
	 * @return  bool  true if maximum is reached
	 */	
	public function hasMaxHeaders()
	{
		return (count($this->headers) >= MHTTPD_Message::$maxHeaders);
	}

	/**
	 * Returns the maximum header block size in bytes.
	 *
	 * @return  integer  maximum allowed bytes
	 */	
	public function getMaxHeaderBlockSize()
	{
		if (empty(MHTTPD_Message::$maxHeaderBlockSize)) {
			MHTTPD_Message::$maxHeaderBlockSize = (
					(MHTTPD_Message::$maxHeaders * MHTTPD_Message::$maxHeaderNameSize)
				+ (MHTTPD_Message::$maxHeaders * MHTTPD_Message::$maxHeaderValueSize) 
				+ 1024 // to be safe
			);
		}
		
		return MHTTPD_Message::$maxHeaderBlockSize;
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
	 * Returns the size in bytes of a header value, whether stored as a string
	 * or as an array of values.
	 *
	 * @param   string   the header name
	 * @return  integer  size in bytes
	 */	
	public function getHeaderSize($name)
	{
		if (!isset($this->headers[$name])) {return false;}
		$size = 0;
		
		if (is_array($this->headers[$name])) foreach($this->headers[$name] as $val) {
			$size += strlen($val);
		} else {
			$size += strlen($this->headers[$name]);
		}
		
		return $size;
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
