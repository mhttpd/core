<?php
/**
 * The MiniFCGI record class.
 * 
 * The FastCGI/1.0 protocol is implemented through record objects created by
 * this class. Although the protocol requires new records for each stage of 
 * the communication process, each with their own header and body, in practice 
 * it's easier to reuse existing record objects and change the necessary values 
 * at each step. The history of the whole request/response process can also be
 * followed quite easily by tracking changes in the object's state.
 *
 * @todo Add support for FCGI management records.
 *
 * @package    MiniHTTPD
 * @subpackage MiniFCGI  
 * @author     MiniHTTPD Team
 * @copyright  (c) 2010-2012 MiniHTTPD Team
 * @license    BSD revised
 */
class MiniFCGI_Record
{
	// ------ Class variables and methods ------------------------------------------
	
	/**
	 * Factory method for creating new FCGI records.
	 *
	 * This method must be used by default, as the class can't be instantiated 
	 * directly. One advantage is that the initialization can be handled cleanly, 
	 * and the result is also chainable.
	 *
	 * @param   resource  the FCGI socket connection
	 * @param   integer   the request ID number
	 * @param   integer   the request type
	 * @param   string    the request content
	 * @return  MiniFCGI_Record  a new FCGI record
	 */
	public static function factory($socket, $requestID=null, $type=null, $content=null)
	{
		$record = new MFCGI_Record;
		$record->socket = $socket;
		$record->version = MFCGI::VERSION_1;
		
		if ($requestID !== null) {$record->requestID = $requestID;}
		if ($type !== null) {$record->type = $type;}
		if ($content !== null) {$record->content = $content;}
		
		return $record;
	}

	/**
	 * Encodes parameter name-value pairs for adding to FCGI record bodies.
	 *
	 * PHP doesn't allow parameter pairs to be split between records, so the 
	 * de facto maximum size for individual name-value pairs must be checked 
	 * here before any record is sent.
	 *
	 * @param   string  parameter name
	 * @param   string  parameter value
	 * @return  string|false  encoded name-value pair or error
	 */
	protected static function encodePair($name, $value) 
	{
		$name_length = strlen($name);
		$value_length = strlen($value);
		
		$lengths  = MFCGI_Record::encodeLength($name_length);
		$lengths .= MFCGI_Record::encodeLength($value_length);
		
		$nvpair = $lengths.$name.$value;
		$length = strlen($nvpair);
		
		// Check that the maximum record size won't be exceeded
		if ($length > (MFCGI::MAX_LENGTH - 8)) {
			trigger_error("Name-value pair is too long ({$request['ID']}:{$length})", E_USER_WARNING);
			return false;
		}
		
		return $nvpair;
	}
	
	/**
	 * Encodes the length elements of the parameter name-value pairs.
	 *
	 * The value is encoded as 1 or 4 bytes, depending on the size.
	 *
	 * @param   integer  length in bytes
	 * @return  string   the encoded length value
	 */
	protected static function encodeLength($length)
	{
		if ($length < 127) {
			$length = chr($length);
		} else {
			$length = 
				 chr((($length >> 24) & 0xFF) | 0x80)
				.chr(($length >> 16) & 0xFF)
				.chr(($length >> 8) & 0xFF)
				.chr($length & 0xFF);
		}		
		return $length;	
	}

	// ------ Instance variables and methods ---------------------------------------

	/**
	 * Should debugging output be enabled? 
	 * @var bool
	 */
	public $debug = true;
	
	/**
	 * The FCGI protocol version.
	 * @var integer
	 */
	protected $version;
	
	/**
	 * The FCGI protocol type.
	 * @var integer
	 */	
	protected $type;

	/**
	 * The active request ID number.
	 * @var integer
	 */	
	protected $requestID;
	
	/**
	 * The record content/body.
	 * @var string
	 */	
	protected $content;

	/**
	 * Length in bytes of the record body.
	 * @var integer
	 */		
	protected $length;

	/**
	 * Length in bytes of any record body padding.
	 * @var integer
	 */			
	protected $padding;
	
	/**
	 * The active FCGI process socket connection.
	 * @var resource
	 */			
	protected $socket;
		
	/**
	 * Encodes the current record header (and body if needed) and sends the record
	 * to the active FCGI process.
	 *
	 * This could be optimized by sending header, body and padding separately, but 
	 * the overhead for most requests seems minimal enough.
	 *
	 * @return  bool  false on error
	 */
	public function write()
	{
		if ($this->type == MFCGI::BEGIN_REQUEST) {
			
			// Encode the Begin Request record body
			$this->content  = chr(0).chr($this->role);
			$this->content .= chr($this->flags);
			$this->content .= str_repeat(chr(0), 5); // reserved
			$this->length = 8;
			
		} elseif ($this->type == MFCGI::PARAMS || $this->type == MFCGI::STDIN) {
			$this->length = strlen($this->content);

		} else {
			$this->length = 0;
			$this->content = '';
		}
		
		// Calculate any padding
		$this->padding = (8 - ($this->length % 8)) % 8;
		
		// Build the header
		$record = chr($this->version)
			.chr($this->type)
			.chr(($this->requestID >> 8) & 0xFF)
			.chr($this->requestID & 0xFF)
			.chr(($this->length >> 8) & 0xFF)
			.chr($this->length & 0xFF)
			.chr($this->padding)
			.chr(0) // reserved
		;
		
		// Add the body content with any padding
		if ($this->content != '') {
			$record .= $this->content;
			if ($this->padding) {
				$record .= str_repeat(chr(0), $this->padding);
			}
		}
		
		// Send the record and clear any current content
		if (($sent = @fwrite($this->socket, $record)) === false) {return false;}
		if ($this->debug) {cecho("--> Sent record ({$this->requestID}:{$this->type}:{$sent}/{$this->length})\n");}
		$this->content = '';
		
		return true;
	}
	
	/**
	 * Decodes a received record and parses its header and body.
	 *
	 * FCGI headers are always 8 bytes, so if there are fewer than 8 bytes in the
	 * response, then the method should be aborted and the stream pointer rewound
	 * until more data becomes available.
	 *
	 * @return  bool  false on error
	 */
	public function read()
	{
		// Get the record header (first 8 bytes required)
		$data = fread($this->socket, 8);
		if (($len = strlen($data)) < 8) {
			if ($len > 0) {fseek($this->socket, 0 - $len, SEEK_CUR);}
			return false;
		}
		
		// Parse the record header
		$this->version = ord($data[0]);
		$this->type    = ord($data[1]);
		$this->length  = (ord($data[4]) << 8) + ord($data[5]);
		$this->padding = ord($data[6]);
		$this->content = '';
		
		if ($this->debug) {cecho("--> Received record ({$this->requestID}:{$this->type}:{$this->length})\n");}
		
		// Get the record content
		while (($clen = strlen($this->content)) < $this->length) {
			if(($data = fread($this->socket, $this->length - $clen)) === false) {
				return false;
			}
			$this->content .= $data;
		}
				
		// Skip over any padding
		if ($this->padding > 0) {
			fseek($this->socket, $this->padding, SEEK_CUR);
		}
		
		// Parse the content
		if ($this->type == MFCGI::END_REQUEST) {

			// Decode application status
			$this->appStatus  = ord($this->content[0]) << 24;
			$this->appStatus += ord($this->content[1]) << 16;
			$this->appStatus += ord($this->content[2]) << 8;
			$this->appStatus += ord($this->content[3]);
			
			// Decode protocol status
			$this->protocolStatus = ord($this->content[4]);
			// 5-7 = reserved		
		}
		
		return true;
	}	

	/**
	 * Sends FCGI stream records to the active FCGI process.
	 *
	 * Data that is sent as a series of stream records (such as STDIN) needs to be
	 * chunked into sizes that don't exceed the maximum record length.
	 *
	 * @param   resource  the stream to be chunked
	 * @return  bool
	 */
	public function stream(&$stream)
	{		
		$length = strlen($stream);
		$offset = 0;
				
		while ($length >= 0) {

			// Get a new chunk
			$len = ($length + 8) > MFCGI::MAX_LENGTH ? (MFCGI::MAX_LENGTH - 8) : $length;
			$this->content = substr($stream, $offset, $len);
			
			// Send the record
			if (!$this->write()) {return false;}
			
			// Next chunk values
			$length -= $len;
			if ($length <= 0) {break;}
			$offset += $len;
		}
		
		return true;
	}
	
	/**
	 * Adds an encoded parameter name-value pair to the current record body.
	 *
	 * If the maximum record size is exceeded, the method should be aborted and 
	 * a new record started once the current one has been sent.
	 *
	 * @param   string  parameter name
	 * @param   string  parameter value
	 * @return  bool    false if max record length is exceeded
	 */
	public function addParam($name, $value)
	{
		// Encode the parameter pair
		if ($nvpair = MFCGI_Record::encodePair($name, $value)) {

			// Is there space for the pair in the current list?
			if ((strlen($this->content) + strlen($nvpair)) > (MFCGI::MAX_LENGTH - 8)) {
				return false;
			}
			
			// Add the encoded pair to the list
			$this->content .= $nvpair;
		}
		
		return true;
	}
	
	/**
	 * Sets the current record type.
	 *
	 * @param   integer  FCGI record type
	 * @return  MiniFCGI_Record  this instance
	 */
	public function setType($type)
	{
		$this->type = $type;
		return $this;	
	}

	/**
	 * Sets the FCGI Begin Request record flags.
	 *
	 * @param   integer  FCGI record flags
	 * @return  MiniFCGI_Record  this instance
	 */
	public function setFlags($flags)
	{
		$this->flags = $flags;
		return $this;	
	}

	/**
	 * Sets the FCGI Begin Request record role.
	 *
	 * @param   integer  FCGI record role
	 * @return  MiniFCGI_Record  this instance
	 */
	public function setRole($role)
	{
		$this->role = $role;
		return $this;	
	}
	
	/**
	 * Binds a record value to a variable.
	 *
	 * @param   string  variable name
	 * @param   mixed   bound variable reference
	 * @return  MiniFCGI_Record  this instance
	 */
	public function bind($var, &$value)
	{
		$this->$var =& $value;
		return $this;
	}

	/**
	 * Determines whether the record is of the given type.
	 *
	 * @param   integer  FCGI record type
	 * @return  bool
	 */
	public function isType($type)
	{
		return $this->type == $type;
	}

	/**
	 * Returns the current record body.
	 *
	 * @return  string  the record body
	 */
	public function getContent()
	{
		return $this->content;
	}
	
	/**
	 * Returns the parsed application and protocol codes from the last FCGI 
	 * End Request record.
	 *
	 * @return  string  parsed status codes
	 */
	public function getEndCodes()
	{
		$codes = '';
		if (isset($this->appStatus)) {$codes .= $this->appStatus;}
		if (isset($this->protocolStatus)) {$codes .= ', '.$this->protocolStatus;}
		return $codes;
	}

	/**
	 * Ensures that the factory method must be used to instantiate the class.
	 *
	 * @return  void
	 */
	protected function __construct()	{}
	
		
} // End MiniFCGI_Record
