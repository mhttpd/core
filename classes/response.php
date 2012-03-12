<?php
/**
 * The MiniHTTPD response class.
 * 
 * Apart from overloading any members in the base message class, the main
 * responsibility of this class is to build the response for returning to the
 * client, and in particular to combine the server client response with any
 * FCGI client response. The response body may be buffered locally or handled 
 * as a stream resource. Most of the methods here are also chainable.
 *
 * @package    MiniHTTPD
 * @author     MiniHTTPD Team
 * @copyright  (c) 2010-2012 MiniHTTPD Team
 * @license    BSD revised
 */
class MiniHTTPD_Response extends MHTTPD_Message
{
	// ------ Class variables and methods ------------------------------------------
	
	/**
	 * Returns a HTTP formatted date string.
	 *
	 * @param   integer  UNIX timestamp
	 * @return  string   formatted date
	 */
	public static function httpDate($time)
	{
		return gmdate('D, d M Y H:i:s T', $time);
	}

	// ------ Instance variables and methods ---------------------------------------
	
	/**
	 * The response status line. 
	 * @var string
	 */
	protected $status;
	
	/**
	 * The response status code.
	 * @var integer
	 */
	protected $code;
	
	/**
	 * The raw response message input.
	 * @var string
	 */
	protected $input;
	
	/**
	 * Stream resource for the response body.
	 * @var resource
	 */
	protected $stream;
	
	/**
	 * Number of bytes successfully sent to the client.
	 * @var integer
	 */
	protected $bytes;
	
	/**
	 * The default list of response headers.
	 *
	 * Any empty headers will not be sent to the client, and this list is useful
	 * mainly for asserting the order in which valid headers should be sent.
	 *
	 * @var array
	 */
	protected $headers = array(
		'Date' => '',
		'Server'=> '',
		'X-Powered-By' => '',
		'Last-Modified' => '',
		'Etag' => '',
		'Expires' =>  '',
		'Cache-Control'=> '',
		'Pragma'=> '',
		'Location' => '',
		'Content-Encoding' => '',
		'Transfer-Encoding' => '',
		'Vary' => '',
		'WWW-Authenticate' => '',
		'Content-Length'=> '',
		'Content-Type' => '',
		'Keep-Alive'=> '',
		'Connection' => '',
	);
	
	/**
	 * Sets the default status line.
	 *
	 * @return  void
	 */
	public function __construct()
	{
		$this->status = MHTTPD::PROTOCOL.' 200 OK';
	}

	/**
	 * Returns the stored response information as an array.
	 *
	 * @return  array
	 */	
	public function asArray()
	{
		$headers = array();
		foreach ($this->headers as $key=>$val) {
			if ($val != '') {$headers[$key] = $val;}
		}
		$ret = array(
			'status' => $this->status,
			'headers' => $headers,
			'body' => $this->body,
		);
		if ($this->debug) {
			$ret = array('input' => $this->input) + $ret;
		}
		return $ret;
	}
	
	/**
	 * Binds a stream resource to the response body.
	 *
	 * @param   resource  source from which to stream the body
	 * @return  MiniHTTPD_Response  this instance
	 */
	public function setStream(&$handle)
	{
		$this->stream =& $handle;
		return $this;
	}

	public function getStream()
	{
		return $this->stream;
	}
	
	/**
	 * Determines whether the message body is bound to a stream resource.
	 *
	 * @return  bool
	 */
	public function hasStream()
	{
		return !empty($this->stream) && is_resource($this->stream);
	}

	/**
	 * Sets the response status code and http status line.
	 *
	 * @param   integer  status code
	 * @return  MiniHTTPD_Response  this instance
	 */	
	public function setStatusCode($code)
	{
		$this->code = $code;
		$this->status = MHTTPD_Message::httpStatus($code);
		return $this;
	}

	/**
	 * Parses a http status line for code and message (e.g. from FCGI response).
	 *
	 * @param   string  status line
	 * @return  MiniHTTPD_Response  this instance
	 */	
	public function parseHttpStatus($status=null)
	{
		if ($status != null && strpos($status, 'HTTP/') !== false) {
			$this->status = $status;
		}
		if ($this->status != '' && strpos($this->status, 'HTTP/') !== false) {
			list($proto, $this->code, $this->info['status_message']) = explode(' ', $this->status, 3);
		}
		return $this;
	}
	
	/**
	 * Determines whether this is an error response.
	 * 
	 * @return  bool
	 */
	public function hasErrorCode()
	{
		return $this->code >= 400 ? true : false;
	}
	
	/**
	 * Returns the response status code.
	 *
	 * @return  integer  response code
	 */
	public function getStatusCode()
	{
		return $this->code;
	}
	
	/**
	 * Toggles the response as static or dynamic.
	 *
	 * @param   bool  true for static, false for dynamic
	 * @return  MiniHTTPD_Response  this instance
	 */
	public function setStatic($value)
	{
		$this->static = $value;
		return $this;
	}

	/**
	 * Overrides a parsed header value, or adds a new one.
	 *
	 * @param   string  header name
	 * @param   string  header value
	 * @return  MiniHTTPD_Response  this instance
	 */
	public function setHeader($name, $value)
	{
		// Verify the header values
		$name  = substr($name, 0, min(strlen($name), MHTTPD_Message::$maxHeaderNameSize));
		$value = substr($value, 0, min(strlen($value), MHTTPD_Message::$maxHeaderValueSize));
		
		// Don't exceed the max header count
		if ($this->hasMaxHeaders() && !isset($this->headers[$name])) {
			return $this;
		}
		
		// Add the header info
		$this->headers[$name] = $value;
		return $this;
	}
	
	/**
	 * Overrides multiple parsed header values, or add new ones.
	 *
	 * @param   array  list of header names & values
	 * @return  MiniHTTPD_Response  this instance
	 */
	public function setHeaders($headers)
	{
		foreach($headers as $name=>$value) {
			$this->headers[$name] = $value;
		}
		return $this;
	}
	
	/**
	 * Returns the response content length.
	 *
	 * @return  integer  length in bytes of the response body
	 */
	public function getContentLength()
	{
		return strlen($this->body);
	}
	
	/**
	 * Increments the count of successfully sent bytes.
	 *
	 * @param   integer  bytes sent
	 * @return  MiniHTTPD_Response  this instance
	 */
	public function addBytesSent($bytes)
	{
		$this->bytes += $bytes;
		return $this;
	}
	
	/**
	 * Returns the count of successfully sent bytes.
	 *
	 * @return  integer  bytes sent
	 */
	public function getBytesSent()
	{
		return empty($this->bytes) ? 0 : $this->bytes;
	}

	/**
	 * Determines whether chunked transfer-encoding has been used by the response.
	 *
	 * @return  bool
	 */
	public function isChunked()
	{
		return $this->getHeader('Transfer-Encoding', true) == 'chunked';
	}

	/**
	 * Determines whether the response is compressed.
	 *
	 * @return  bool
	 */
	public function isCompressed()
	{
		return preg_match('/(gzip|deflate)/i', $this->getHeader('Content-Encoding', true));
	}

	/**
	 * Decompresses the response body by the given content-encoding methods.
	 *
	 * @return  void
	 */
	public function decompress()
	{
		if ($this->body == '') {return;}

		$methods = explode(',', strtolower($this->getHeader('Content-Encoding', true)));

		foreach ($methods as $method) {
			if ($method == 'gzip') {
				$this->body = gzinflate(substr($this->body, 10, -8));
			} elseif ($method == 'deflate') {
				$this->body = gzinflate($this->body);
			}
		}
	}

	/**
	 * Sets the username used for access authorization.
	 *
	 * @param   string  the username
	 * @return  MiniHTTPD_Request  this instance
	 */	
	public function setUsername($user)
	{
		$this->info['username'] = $user;
		return $this;
	}	
	
	/**
	 * Completes a final check on the response before sending it to ensure
	 * compliance with HTTP/1.1.
	 *
	 * @return  MiniHTTPD_Response  this instance
	 */	
	public function verify()
	{
		// Remove body if not allowed by the message type
		if (isset($this->body) && $this->body != '' 
			&& in_array($this->code, MHTTPD_Message::$withoutBody)
			) {
			
			// Remove any unneeded headers
			$this->removeHeader('Content-Length', true);
			$this->removeHeader('Transfer-Encoding', true);
			
			// Remove the body
			$this->body = '';
		}
		
		// Check error responses that should close connections
		if ($this->code > 401) {
			$this->setHeader('Connection', 'close');
		}
		
		return $this;
	}
	
} // End MiniHTTPD_Response
