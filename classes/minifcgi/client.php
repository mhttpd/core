<?php
/**
 * The MiniFCGI client class.
 * 
 * This class is used to communicate with a running FastCGI process on behalf of
 * a server client. It handles the connections, parses and sends the FCGI request,
 * processes responses and applies the FCGI protocol through the record objects that
 * it creates. The responses are passed back to the server client typically via
 * a bound object.
 *
 * @package    MiniHTTPD
 * @subpackage MiniFCGI 
 * @author     MiniHTTPD Team
 * @copyright  (c) 2010-2012 MiniHTTPD Team
 * @license    BSD revised
 */
class MiniFCGI_Client
{
	/**
	 * Should debugging output be enabled?
	 * @var bool
	 */
	public $debug = false;
	
	/**
	 * The client ID number, usually the same as the server client ID.
	 * @var integer
	 */
	protected $ID;
	
	/**
	 * The ID number of the FCGI process to which the client is connected.
	 * @var integer 
	 */
	protected $process;
	
	/**
	 * The FCGI request info parsed from the server client request.
	 * @var array
	 */
	protected $request;
	
	/**
	 * The FCGI response object, typically bound to the server client.
	 * @var MiniHTTPD_Response
	 */
	protected $response;
	
	/**
	 * The socket connection to the FastCGI process.
	 * @var resource
	 */
	protected $socket;

	/**
	 * Is the client currently in chunking mode?
	 * @var bool
	 */
	protected $chunking = false;

	/**
	 * Is the current response in blocking mode?
	 * @var bool
	 */
	protected $blocking = true;
	
	/**
	 * Has the current request been completed?
	 * @var bool
	 */
	protected $ended = false;

	/**
	 * A count of the output buffer flushes when not in chunking mode.
	 * @var integer
	 */	
	protected $flushes = 0;
	
	/**
	 * Creates a new FCGI client, by default bound to a server client.
	 *
	 * @param   integer  the server client ID number
	 * @param   integer  the FCGI process ID number
	 * @return  void
	 */
	public function __construct($client=null, $process=null)
	{
		if ($client !== null) {$this->ID = $client;}
		if ($process !== null) {$this->process = $process;}
	}

	/**
	 * Parses the server client request and builds the FCGI request.
	 *
	 * The main task here is to build the list of parameters to be passed to the
	 * FCGI process in a form that can be encoded as FCGI records.
	 *
	 * @param   MiniHTTPD_Request  the server client request
	 * @return  MiniFCGI_Client|false  this instance or error
	 */
	public function addRequest(MHTTPD_Request $request)
	{
		if ($request == null || !($request instanceof MHTTPD_Request)) {
			trigger_error('Invalid input values for FCGI Request', E_USER_WARNING);
			return false;
		}
		
		// Get the needed variables
		list($host, $address, $port, $ssl) = MHTTPD::getServerInfo();
		list($remoteAddress, $remotePort)  = $request->getClientInfo();
		list($pathInfo, $pathTranslated)   = $request->getPathInfo();
		list($rUrl, $rQuery, $rStatus)     = $request->getRedirectInfo();
		$headers = $request->getHeaders();
		
		// Build the parameters list
		$p['GATEWAY_INTERFACE'] = 'FastCGI/1.0';
		if ($ssl) {$p['HTTPS']  = 'On';}
		if ($rStatus) {
			$p['REDIRECT_STATUS'] = $rStatus;
		}
		foreach ($headers as $header=>$value) {
			$header = str_replace('-', '_', strtoupper($header));
			$p['HTTP_'.$header]   = $value;
		}
		if ($rUrl) {
			$p['REDIRECT_URL']    = $rUrl;
			if ($rQuery) {$p['REDIRECT_QUERY_STRING'] = $rQuery;}			
		}
		$p['SERVER_SIGNATURE']  = MHTTPD::getSignature();
		$p['SERVER_SOFTWARE']   = MHTTPD::getSoftwareInfo();
		$p['SERVER_NAME']       = $host;
		$p['SERVER_PROTOCOL']   = MHTTPD::PROTOCOL;
		$p['SERVER_ADDR']       = $address;
		$p['SERVER_PORT']       = $port;
		$p['SERVER_ADMIN']      = 'admin@localhost';
		$p['REQUEST_METHOD']    = $request->getMethod();
		$p['DOCUMENT_ROOT']     = MHTTPD::getDocroot();
		$p['SCRIPT_NAME']       = $request->getScriptName();
		$p['QUERY_STRING']      = $request->getQueryString();
		$p['SCRIPT_FILENAME']   = $request->getFilepath();
		$p['REMOTE_HOST']       = gethostbyaddr($remoteAddress);
		$p['REMOTE_ADDR']       = $remoteAddress;
		$p['REMOTE_PORT']       = $remotePort;
		$p['REQUEST_URI']       = $request->getUrl(false);
		if ($pathTranslated) {
			$p['PATH_TRANSLATED'] = $pathTranslated;
		}
		if ($pathInfo) {
			$p['PATH_INFO']       = $pathInfo;
		}
		
		// Store the FCGI request info
		$this->request = array(
			'ID' => $this->ID,
			'params' => $p,
			'method' => $request->getMethod(),
		);
		
		// Initialize any request content
		if ($request->hasContent()) {
			$this->request['content'] = '';
			$this->request['clength'] = 0;
		}
		
		if ($this->debug) {
			cecho('--> Added FCGI request: '.$request->getUrl(false).PHP_EOL
				.'--> '.$request->getFilepath().PHP_EOL);
		}
		
		// Allow chaining
		return $this;
	}

	/**
	 * Buffers any server client request body for sending to the FCGI process in a
	 * later loop (as content via streaming STDIN records).
	 *
	 * @param   MiniHTTPD_Request  the server client request
	 * @return  MiniFCGI_Client    this instance
	 */	
	public function addRequestContent(MHTTPD_Request $request)
	{
		if ($this->debug) {cecho("--> Adding content to FCGI request ({$this->request['ID']})\n");}
		
		// Get the buffered body content
		$content = $request->getBody();

		// Get the content length
		if (!($clength = $request->getContentLength())) {
			$clength = strlen($content);
		}
	
		if (!empty($this->request['params'])) {
			
			// Add the content-related params
			$this->request['params']['CONTENT_TYPE'] = $request->getContentType();
			$this->request['params']['CONTENT_LENGTH'] = $clength;
			$this->request['clength'] = $clength;
		}

		// Flush the request buffer
		$request->setBody('');
		
		// Buffer the content locally
		$this->request['content'] = $content;
	
		// Allow chaining
		return $this;
	}
	
	/**
	 * Encodes the stored request info as a series of FCGI records and sends them 
	 * to the connected FCGI process.
	 *
	 * Request params are only sent the first time the method is called, and all
	 * further calls will send any buffered body content. The client request body can
	 * therefore be passed directly to the FCGI process as it's buffered in, which 
	 * limits the chances of any large posts blocking the main server loop - but the
	 * final content length still needs to be known in advance and sent first with
	 * the other params, or the post will fail.
	 *
	 * @uses MiniFCGI_Record::factory()
	 * @uses MiniFCGI_Manager::addRequest()
	 *
	 * @return  bool  false if the request failed
	 */
	public function sendRequest()
	{		
		// Get a new socket connection
		if (!$this->connect()) {return false;}
		$socket = $this->socket;

		// Get the active request
		$request = $this->request;		

		// Initialize the record object
		$record = MFCGI_Record::factory($socket, $request['ID']);
		$record->debug = $this->debug;

		// Begin the request if we have params to send
		if (!empty($request['params'])) {

			// Add debug info
			if ($this->debug) {
				cecho("--> Beginning FCGI request ({$request['ID']})\n");
				
				// Extra debug params
				$request['params']['X_PID'] = MFCGI::getPID($this->process);
				$request['params']['X_SCORE'] = MFCGI::getScoreboard(true);
				# $request['params']['X_REQUEST'] = print_r($request, true);
			}

			// Begin the request
			if (!($record->setType(MFCGI::BEGIN_REQUEST)->setRole(MFCGI::RESPONDER)
				->setFlags(MFCGI::NO_FLAGS)->write()
				)) {
				trigger_error("Cannot send FCGI Begin Request record ({$request['ID']})", E_USER_WARNING);
				return false;
			}
			
			// Update the FCGI process info
			MFCGI::addRequest($this->process);
			
			// Send the param records with valid lengths
			if ($this->debug) {cecho("--> Sending FCGI params ({$request['ID']})\n");}
			$record->setType(MFCGI::PARAMS);
			$c = count($request['params']);
			while ($name = key($request['params'])) {
				$added = $record->addParam($name, $request['params'][$name]);
				if (!$added || $c == 1) {
					if (!$record->write()) {
						trigger_error("Cannot send FCGI Params records ({$request['ID']})", E_USER_WARNING);
						return false;
					}
				}
				if ($added) {next($request['params']); $c--;}
			}
			$record->write(); // ends PARAMS
			
			// Remove the params
			unset($this->request['params']);
			
			// Reset flush count
			$this->flushes = 0;
		}

		// Send any buffered content via STDIN stream
		if (isset($request['content']) && $request['content'] != '') {
		
			// Store the total content bytes sent locally
			static $sent = 0;
			
			if ($this->debug) {cecho("--> Sending FCGI STDIN ({$request['ID']})\n");}
			$record->setType(MFCGI::STDIN);
			if (!$record->stream($request['content'])) {
				trigger_error("Cannot send FCGI STDIN records ({$request['ID']})", E_USER_WARNING);
				return false;
			}
			$record->write(); // ends STDIN
			
			// Check the total content bytes sent
			if (($sent += strlen($request['content'])) >= $this->request['clength']) {
				
				// Don't send any more content
				if ($this->debug) {cecho("--> Ending content stream ({$request['ID']})\n");}
				unset($this->request['content'], $this->request['clength']);
				$sent = 0;
			}
		}

		// Back to the main server loop
		return true;
	}

	/**
	 * Determines whether the current socket process is available.
	 *
	 * @return  bool  true if the FCGI socket is open
	 */			
	public function hasOpenSocket()
	{
		return is_resource($this->socket) && !@feof($this->socket);
	}
		
	/**
	 * Parses the received FCGI response records and builds the response object.
	 *
	 * The response can be set to a (pseudo) non-blocking mode: once all headers 
	 * have been received, the method will return after each new record and will need
	 * to be called repeatedly to get the full response. This is handy for managing
	 * any long-running scripts, since we can return to the main server loop while
	 * waiting for data to become available on the socket - but note that use of a
	 * true non-blocking socket is not very practical here, so there are limitations.
	 *
	 * Chunked responses are automatically non-blocking so that each chunk can be sent
	 * to the client immediately via the main server loop.
	 *
	 * @uses MiniFCGI_Record::factory()
	 * @uses MiniHTTPD_Server::factory()
	 *
	 * @param   bool  should the request block until the whole response is received?
	 * @return  bool  false if the response couldn't be processed
	 */
	public function readResponse($blocking=false)
	{
		// Get the active request
		$request = $this->request;

		// Check the socket connection
		if (!is_resource($this->socket)) {
			trigger_error("Lost socket connection for response ({$request['ID']})", E_USER_WARNING);
			return false;
		}
		$socket = $this->socket;
		
		// Set the response blocking mode
		$this->blocking = $this->chunking ? false : $blocking;
		
		// Initialize the record object
		$record = MFCGI_Record::factory($socket, $request['ID']);
		$record->debug = $this->debug;
		
		// Create a response object if one isn't already bound
		if ($this->response == null) {
			$response = MHTTPD::factory('response');
			if ($this->debug) {cecho("--> FCGI response is not bound\n");}
		} else {
			if ($this->debug) {cecho("--> FCGI response is bound\n");}
			$response = $this->response;
		}
		$tries = 5;
		
		// Fetch and process the response records
		while (!@feof($socket) && $tries > 0 && !$this->ended ) {

			if ($this->debug) {cecho("--> Reading FCGI response ({$request['ID']})\n");}
			
			// Get the current record
			if (!$record->read()) {
				trigger_error("Cannot read FCGI response ({$request['ID']}) (".(--$tries).' tries left)', E_USER_NOTICE);
				usleep(500);
				continue;
			}

			// Handle any content
			if ($record->isType(MFCGI::STDOUT)) {
				if (!$response->hasAllHeaders()) {
					$response->parse($record->getContent(), false);
				} else {
					$response->append($record->getContent());
				}
				$this->flushes++;

			// Handle any unlogged errors
			} elseif ($record->isType(MFCGI::STDERR)) {
				if ($this->debug) {trigger_error('FCGI server returned error', E_USER_NOTICE);}
				$error_msg = '[FCGI] '.trim($record->getContent());
				if ($this->debug) {cecho($error_msg."\n\n");}

				// Add the error to the server log
				$error_logged = error_log($error_msg);
				$this->blocking = true;

			// Request is ended
			} elseif ($record->isType(MFCGI::END_REQUEST)) {
				if ($this->debug) {cecho("--> FCGI request is ended ({$request['ID']})\n");}
				$this->ended = true;
			}

			if ($response->hasAllHeaders()) {
				
				// Get the whole of any error message before returning
				if ($response->hasErrorCode()) {
					$this->blocking = true;
					if ($this->ended && $response->isCompressed()) {

						// Messages must not be compressed
						$response->decompress();
					}
					continue;
				}
				
				// Check if response should be chunked
				if (!$this->chunking) {
				
					// Skip chunking mode if response is already chunked
					if ($response->isChunked()) {
						$this->blocking = false;
					
					// Start chunking mode if response exceeds buffer size, or the output
					// buffer has been flushed more than once (including at script end)
					} elseif (($response->getContentLength() >= (MFCGI::MAX_LENGTH - 8))
						|| ($this->flushes > 1)
						) {
						$this->chunking = true;
						$this->blocking = false;
					}
				}
				
				// Don't fetch any more records if not blocking
				if (!$this->blocking) break;				
			}
		}
		
		// Output any extra debug info
		if ($this->debug) {
			if ($this->chunking) {
				cecho("--> Using chunked encoding ({$request['ID']})\n");
			} elseif ($response->isChunked()) {
				cecho("--> FCGI response is chunked ({$request['ID']})\n");
			}
			if (!$this->blocking) {
				cecho("--> In non-blocking mode ({$request['ID']})\n");
			}
		}
		
		// Has the request ended?
		if (@feof($socket) || $this->ended) {
		
			// Make sure we've ended properly
			$this->ended = true;
			
			// Close the socket gracefully
			if ($this->debug) {
				$sname = stream_socket_get_name($socket, false);
				$pinfo = $this->ID == MFCGI::NULL_REQUEST_ID ? $this->process : $this->process.','.MFCGI::getPID($this->process);
				cecho("Closing FCGI connection (c:{$this->ID}, p:{$pinfo}) ({$sname})\n");
			}
			
			// Update the FCGI process info
			MFCGI::removeClient($this->process);
			
			// Close the client connection
			MHTTPD::closeSocket($socket);
			$this->socket = null;
		}

		// Empty responses should return valid error messages
		if (  $this->ended && $request['method'] != 'HEAD'
			&& !$this->chunking && !$response->isChunked()
			&& !$response->hasHeader('X-SendFile')
			&& !$response->hasBody()
			) {
			if ($this->debug) {
				trigger_error('FCGI returned no content, request is ended (codes: '
					.$record->getEndCodes().')', E_USER_NOTICE);
			}
			if (!$response->hasErrorCode()) {
				$response->setStatusCode(500); // Internal Server Error
			}
			$response->append('No content was returned from the FCGI process'
				.(isset($error_logged) ? ' (error logged).' : '.'));
		}
		
		// Everything received OK
		if ($this->response == null) {$this->response = $response;}
		return true;
	}

	/**
	 * Sets the FCGI request information.
	 *
	 * @param   array  the request info
	 * @return  MiniFCGI_Client  this instance
	 */
	public function setRequest($request)
	{
		$this->request = $request;
		return $this;
	}

	/**
	 * Returns the current FCGI request information.
	 *
	 * @return  array  the request info
	 */
	public function getRequest()
	{
		return $this->request;
	}
	
	/**
	 * Binds the FCGI response to the server client response.
	 *
	 * @param   MiniHTTPD_Response  the response object
	 * @return  MiniFCGI_Client     this instance
	 */
	public function bindResponse($response)
	{
		$this->response = $response;
		return $this;
	}
	
	/**
	 * Returns the current FCGI response object.
	 *
	 * @return  MiniHTTPD_Response  the response object
	 */
	public function getResponse()
	{
		return $this->response;
	}
	
	/**
	 * Returns the FCGI client ID number.
	 *
	 * @return  integer  client ID number
	 */
	public function getID()
	{
		return $this->ID;
	}

	/**
	 * Returns the connected FCGI process ID number.
	 *
	 * @return  integer  FCGI process ID number
	 */
	public function getProcess()
	{
		return $this->process;
	}
	
	/**
	 * Returns the active FCGI socket connection.
	 *
	 * @return  resource|false  the socket connection or error
	 */
	public function getSocket()
	{
		return is_resource($this->socket) ? $this->socket : false;
	}

	/**
	 * Determines whether the client is currently in chunking mode.
	 
	 * @return  bool  true if chunking
	 */
	public function isChunking()
	{
		return $this->chunking;
	}

	/**
	 * Determines whether the current response is in blocking mode.
	 
	 * @return  bool  true if blocking
	 */
	public function isBlocking()
	{
		return $this->blocking;
	}

	/**
	 * Determines whether the current request has been sent to the FCGI process.
	 
	 * @return  bool  true if sent
	 */
	public function hasSent()
	{
		return (!empty($this->request) && !isset($this->request['params']) 
			&& !isset($this->request['content'])
		);
	}
	
	/**
	 * Determines whether the current request has completed.
	 
	 * @return  bool  true if ended
	 */
	public function isEnded()
	{
		return $this->ended;
	}
	
	/**
	 * Returns the open FCGI process connection or creates a new one.
	 *
	 * @uses MiniFCGI_Manager::conect()
	 *
	 * @return  bool  false if no connection could be made
	 */
	protected function connect()
	{
		if (is_resource($this->socket)) {return true;}
		
		if ($process = MFCGI::connect($this->ID, $this->process)) {
			$this->process = $process[0];
			$this->socket  = $process[1];
			if ($this->debug) {
				$sname = stream_socket_get_name($this->socket, false);
				$pname = stream_socket_get_name($this->socket, true);
				$pinfo = $this->ID == MFCGI::NULL_REQUEST_ID ? $this->process 
					: $this->process.','.MFCGI::getPID($this->process);
				cecho("Opened FCGI connection (c:{$this->ID}, p:{$pinfo}) ({$sname}->{$pname})\n");
			}
			return true;
		}
		return false;
	}
	
} // End MiniFCGI_Client
