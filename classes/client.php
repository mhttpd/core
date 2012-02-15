<?php
/**
 * The MiniHTTPD client class.
 *
 * This class handles client requests and sends the relevant responses via the
 * MiniHTTPD_Request and MiniHTTPD_Response objects that it creates for each new
 * client. If a client has requested dynamic content, the class also acts as a
 * bridge for the MiniFCGI_Client object. This design ensures that all of the
 * created objects are tied to the individual client connections for easier and
 * more transparent management of resources.
 *
 * From version 0.4, request handlers have been implemented as objects for a more 
 * modular approach. These are managed in the processRequest() method, and are 
 * configured in order of execution in the loaded handlers queue. The $handler 
 * variable holds info about the currently selected handler. To add new handlers, 
 * see the notes for the MiniHTTPD_Request_Handler class.
 *
 * @see MiniHTTPD_Request_Handler
 *
 * @package    MiniHTTPD
 * @author     MiniHTTPD Team
 * @copyright  (c) 2010-2012 MiniHTTPD Team
 * @license    BSD revised
 */
class MiniHTTPD_Client
{
	/**
	 * Should debugging output be enabled?
	 * @var bool
	 */
	public $debug = false;
	
	/**
	 * The client ID number.
	 * @var integer
	 */
	protected $ID;

	/**
	 * The client socket connection.
	 * @var resource
	 */
	protected $socket;

	/**
	 * The client's IP address.
	 * @var string
	 */
	protected $address;

	/**
	 * The client's port number.
	 * @var integer
	 */
	protected $port;

	/**
	 * The raw data for the active request.
	 * @var string
	 */
	protected $input;

	/**
	 * The active request object for the client.
	 * @var MiniHTTPD_Request
	 */
	protected $request;

	/**
	 * Information about the active request handler.
	 * @var array
	 */
	protected $handler;

	/**
	 * Is the current request being reprocessed?
	 * @var bool
	 */
	protected $reprocessing = false;

	/**
	 * Does the current request need to be reauthorized?
	 * @var bool
	 */
	protected $reauthorize = false;
	
	/**
	 * Should the current request object be re-used when re-processing?
	 * @var bool
	 */	
	protected $persist = true;
	
	/**
	 * The active response object for the client.
	 * @var MiniHTTPD_Response
	 */
	protected $response;

	/**
	 * The active logger object for the client.
	 * @var MiniHTTPD_Logger
	 */
	protected $logger;

	/**
	 * The active FastCGI client object.
	 * @var MiniFCGI_Client
	 */
	protected $fcgi;

	/**
	 * The maximum bytes to buffer when reading request body content.
	 * @var integer  32 KB
	 */
	protected $requestBufferSize = 32768;
	
	/**
	 * The maximum bytes to buffer when sending streamed responses.
	 * @var integer  16 KB
	 */
	protected $streamBufferSize = 16384;

	/**
	 * Has the current client request been finished?
	 * @var bool
	 */
	protected $finished = false;

	/**
	 * Is the client currently posting data?
	 * @var bool
	 */
	protected $posting = false;
	
	/**
	 * Is the client currently streaming data?
	 * @var bool
	 */
	protected $streaming = false;

	/**
	 * Is the client currently in chunking mode?
	 * @var bool
	 */
	protected $chunking = false;
	
	/**
	 * Has the client sent the header block?
	 * @var bool
	 */	
	protected $sentHeaders = false;
	
	/**
	 * A count of the client's completed requests.
	 * @var integer
	 */
	protected $numRequests = 1;
	
	/**
	 * Initalizes the client object.
	 *
	 * @return  void
	 */
	public function __construct($ID, $socket, $peername)
	{
		$this->ID = $ID;
		$this->socket = $socket;
		list($this->address, $this->port) = explode(':', $peername);
	}

	/**
	 * Processes the client request and manages the request handlers.
	 *
	 * This method configures client requests and dispatches them to request handlers
	 * for further processing. It creates the request object from the raw input, then
	 * cycles through the queue of configured handlers for a match, executes them
	 * and reports any errors back to the main server loop.
	 *
	 * @uses MiniHTTPD_Logger
	 * @uses MiniHTTPD_Request_Handler
	 * @uses MiniHTTPD_Handlers_Queue
	 *
	 * @return  bool  true if the request has been successfully processed
	 */
	public function processRequest()
	{
		if (empty($this->input)) {
			trigger_error("No request input to process", E_USER_NOTICE);
			return false;
		}

		// Create a new request object if needed or forced
		if (empty($this->request) || $this->persist === false) {
		
			if ($this->debug) {
				cecho("Client ({$this->ID}) ... processing request\n");
				cecho("\n{$this->input}\n\n");
			}

			// Initialize the new request object
			if (!$this->startRequest()) {return false;}

			// Create an access logger for the request
			$this->logger = MHTTPD_Logger::factory('access')->addRequest($this->request);
			
		} else {

			// Re-use the current request object
			if ($this->debug) {cecho("Client ({$this->ID}) ... re-processing request\n");}
			$this->reprocessing = true;
		}

		// Get a new queue of loaded request handlers
		$handlers = MHTTPD::getHandlersQueue();

		// Call each request handler in the configured order
		while ($handlers->valid()) {
			
			// Force reauthorizaton of the request?
			if ($this->reauthorize) {
				if ($this->debug) {cecho("Client ({$this->ID}) ... reauthorization needed\n");}		
				$handlers->requeue('auth');
			}
			
			// Get the handler info
			$handler = $handlers->current();
			$type = $handlers->key();
			
			// Skip handlers marked for single use
			if ($this->reprocessing && $handler->useOnce() && !$this->reauthorize) {
				$handlers->next();
				continue;
			}
			
			// Initialize the handler
			$this->handler = array('type' => $type);
			$handler->init($this);
			$handler->debug = $this->debug;
			
			// Set the persistence of the current request object
			$this->persist = $handler->persist();
			
			// Does the current handler match the request?
			if ($this->debug) {cecho("Client ({$this->ID}) ... trying handler: $type\n");}
			if (!$handler->matches()) {$handlers->next(); continue;}
			$handler->addCount('match');
			
			// Try to execute the matching handler
			if ($handler->execute()) {
					
				$handler->addCount('success');
				
				// Should handler processing stop here?
				if ($handler->isFinal()) {
					if ($this->debug) {cecho("Client ({$this->ID}) ... handler finished, is final: $type\n");}
					return $handler->getResult();
				}

			} elseif ($handler->skipped()) {
			
				// The handler has an error, but can be skipped
				if ($this->debug) {cecho("Client ({$this->ID}) ... handler skipped ($type): {$handler->getError()}\n");}
				$handler->addCount('error');

			} else {
			
				// The handler has a non-recoverable error, stop processing here
				if ($this->debug) {cecho("Client ({$this->ID}) ... handler ($type) failed: {$handler->getError()}\n");}
				if (!$this->hasResponse()) {
					$this->sendError(500, "Request handler ($type) failed, can't be skipped (Error: {$handler->getError()})");
				}
				$handler->addCount('error');
				return $handler->getResult();
			}
			
			// Call the next handler
			$handlers->next();
		}

		// No handler matches, so send an internal server error
		$this->sendError(500, 'No handler is available to process this request.');
		return false;				
	}

	/**
	 * Creates the active request object for the client, parses the input for headers
	 * and starts the buffering of any posted content body.
	 *
	 * @return  bool  false if initialization failed
	 */	
	public function startRequest()
	{
		// Create a new request object and parse the input
		$request = MHTTPD::factory('request');
		$request->debug = $this->debug;
		$this->reprocessing = false;
		
		// Add the input size to the server stats
		MHTTPD::addStatCount('up', strlen($this->input));
		
		// Parse the request
		$request->parse($this->input);

		// Did parsing fail for some reason?
		if (!$request->isValid()) {
			$this->sendError(400, 'The request could not be processed.');
			if ($this->debug) {cprint_r($request);}
			return false;
		}
		
		// Set the extra request info
		$request->setClientInfo($this->address, $this->port);
		$request->setDocroot(MHTTPD::getDocroot());
		$request->getFileInfo();

		// Attach the request to the client
		$this->request = $request;
		
		// If this is a POST request, set the client to posting mode
		if ($request->isPost() && !$request->hasBody()) {
			$this->posting = true;
		}

		return true;
	}

	/**
	 * Buffers any request body content locally.
	 *
	 * This is especially handy for managing FCGI requests more efficiently, as the
	 * buffer can be flushed directly to the FCGI process while the body is read in
	 * by each main server loop, keeping memory usage low and concurrency high.
	 *
	 * Note: chunked requests still need to be buffered in whole before sending to 
	 * the FCGI process, as the content length needs to be known in advance.
	 *
	 * @return  void
	 */		
	public function readRequestBody()
	{		
		$request = $this->request;
		$buffer = '';
		
		// With Content-Length set
		if (($clen = $request->getContentLength())) {

			// Store the total bytes read locally
			static $rlen = 0;
			
			// Buffer part of the body content
			$len = min(($clen - $rlen), $this->requestBufferSize, 8192);
			$buffer = @fread($this->socket, $len);
			$len = strlen($buffer);
			
			// Update the byte counts
			MHTTPD::addStatCount('up', $len);
			$rlen += $len;
			if ($this->debug) {
				$bsize = strlen($this->request->getBody()) + $len;
				cecho("({$len}/{$rlen}/{$clen}) [{$bsize}] ");
			}
			if ($rlen >= $clen) {
				
				// No more body content is left to buffer
				if ($this->debug) {cecho("... done ");}
				$this->posting = false;
				$rlen = 0;
			}
			
		// With Transfer-Encoding: chunked
		} elseif ($request->isChunked()) {
		
			// The whole body content must first be buffered
			while (!@feof($this->socket)) {
				$buffer .= @fread($this->socket, $this->requestBufferSize);
			}
			
			// Unchunk the body
			MHTTPD::addStatCount('up', strlen($buffer));
			$buffer = MHTTPD_Request::unChunk($buffer);
			if ($this->debug) {cecho("... done ");}
			
			// End posting mode
			$this->posting = false;
		}
		
		if ($buffer == '') {
			
			// The client has disconnected
			if ($this->debug) {cecho("... no data");}
			$this->posting = false;
			
		} else {
			
			// Add the buffered data to the request
			$request->append($buffer);
		}
		
		if ($this->debug) {cecho("\n");}
	}
	
	/**
	 * Common initialization step for all response objects.
	 *
	 * @param   integer  response code
	 * @param   bool     should the connection be closed after the response is sent?
	 * @return  MiniHTTPD_Client  this instance
	 */
	public function startResponse($code=200, $close=false)
	{
		// Create the initial client response object
		$this->response = MHTTPD::factory('response')
			->setHeader('Server', MHTTPD::getSoftwareInfo())
			->setHeader('Date', MHTTPD_Response::httpDate(time()))
			->setUsername($this->request->getUsername())
			->setStatusCode($code)
		;

		// We only want a persistent connection if the client supports it
		if (!$this->request->allowsPersistence()) {$close = true;}
		// Process the connection status
		$maxRequests = MHTTPD::getMaxRequests();
		if ($close || $this->numRequests == $maxRequests) {

			// Close the connection now
			$this->response->setHeader('Connection', 'Close');

		} else {

			// Keep the connection alive
			$timeout = 'timeout='.MHTTPD::getAliveTimeout();
			$max = 'max='.($maxRequests - $this->numRequests);
			$this->response
				->setHeader('Connection', 'Keep-Alive, '.$this->ID)
				->setHeader('Keep-Alive', "{$timeout}, {$max}")
			;
			$this->numRequests++;
		}

		// Set client to the starting state
		$this->finished = false;
		$this->sentHeaders = false;
		
		// Allow chaining
		return $this->response;
	}
	
	/**
	 * Sends the completed response to the client.
	 *
	 * This is where any intermediate processing step should end. By this stage,
	 * all of the response headers should have been prepared, and the message
	 * body either buffered in whole or waiting to be streamed to the client. This
	 * method will usually be called from the main server loop, except in the case 
	 * of error messages that need to be sent immediately.
	 *
	 * @param   bool  should the response be finished here?
	 * @return  bool  true if response is sent successfully
	 */
	public function sendResponse($finish=true)
	{
		if (!$this->hasResponse()) {return false;}
		if ($this->debug) {cecho(":\n");}
		$sent = array();
		
		if (!$this->sentHeaders) {

			// Verify the response before sending
			$this->response->verify();

			// Get the response header block
			$header = $this->response->getHeaderBlock();
			if ($this->debug) {cecho("\n$header");}

			// Write the response header to the socket
			$bytes = @fwrite($this->socket, $header);
			$this->response->addBytesSent($bytes);
			$sent[] = $bytes;
			$this->sentHeaders = true;
		
		} elseif ($this->debug) {
			cecho("\n");
		}
		
		if (!$this->request->isHead()) {

			// Get the content body or stream
			$body = $this->response->getBody();

			if ($this->response->hasStream()) {
				
				if ($this->debug) {cecho("Streaming response ... ");}
				$this->streaming = true;
				
				if (!@feof($body)) {
					
					// Send streamed data in blocks of specified size
					$data = @fread($body, $this->streamBufferSize);
					$bytes = @fwrite($this->socket, $data);
					$this->response->addBytesSent($bytes);
					$sent[] = $bytes;
				}

			} elseif ($this->chunking && $body != '') {

				// Send the current body as a new chunk
				if ($this->debug) {cecho('Sending chunked ... ');}
				$chunk = dechex(strlen($body))."\r\n".$body."\r\n";
				$bytes = @fwrite($this->socket, $chunk);
				$this->response->addBytesSent($bytes);
				$sent[] = $bytes;
				
			} elseif ($body != '') {
				
				// Otherwise send the whole body
				if ($this->debug) {cecho('Sending body ... ');}
				$bytes = @fwrite($this->socket, $body);
				$this->response->addBytesSent($bytes);
				$sent[] = $bytes;
			}
			
			if ($this->response->isChunked()) {
			
				// Flush the current body
				$this->response->setBody('');
			}
		}
		if ($this->debug) {cecho('('.join(':', $sent).') ');}

		if ($finish && !$this->streaming && !$this->response->isChunked()) {

			// Finish the successful response now
			$this->finish();

		} else {

			// Finish in the main loop
			if ($this->debug) {cecho("... returning to main loop\n");}
		}

		return true;
	}

	/**
	 * Finalizes the client request/response.
	 *
	 * The main task here is to call any attached logger and clean up the created 
	 * objects. Typically this will be called immediately after sending the response,
	 * although error messages may be finished later by the main loop.
	 *
	 * @return  void
	 */
	public function finish()
	{
		if ($this->debug) {cecho("... finishing ");}

		// Finalize & clean up	
		if ($this->hasResponse()) {
		
			if ($this->streaming) {
				
				// Close open stream
				@fclose($this->response->getStream());
				$this->streaming = false;
			
			} elseif ($this->chunking) {
				
				// Add chunked encoding terminator
				@fwrite($this->socket, "0\r\n\r\n");
				$this->response->addBytesSent(5);
				$this->chunking = false;
			}
			
			// Update the logger
			if ($this->logger) {$this->logger->addResponse($this->response);}

			// Update the server stats
			MHTTPD::addStatCount('down', $this->response->getBytesSent());
		}
		
		// Reset the attached objects
		$this->response = null;
		$this->request  = null;
		$this->fcgi     = null;
		
		// Mark the request as finished
		$this->finished = true;
	}

	/**
	 * Writes the current log line to the attached log.
	 *
	 * @return  MiniHTTPD_Client  this instance
	 */
	public function writeLog()
	{
		if ($this->logger) {
			$this->logger->write();
			$this->logger = null;
		}
		return $this;
	}

	/**
	 * Returns a formatted summary of the current client information.
	 
	 * @return  string  the summary info
	 */	
	public function getSummary()
	{
		$summary = '('.$this->ID.') '.$this->address.':'.$this->port;
		if ($this->request) {
			$summary .= ' ('.$this->request->getUrl().')';
		} else {
			$summary .= ' (inactive)';
		}
		return $summary;
	}
	
	/**
	 * Returns the client ID number.
	 *
	 * @return  integer
	 */
	public function getID()
	{
		return $this->ID;
	}

	/**
	 * Determines whether the client request/response has been finished.
	 *
	 * @return  bool
	 */
	public function isFinished()
	{
		return $this->finished;
	}

	/**
	 * Determines whether the client is ready to process a new request.
	 *
	 * @return  bool
	 */
	public function isReady()
	{
		return (!$this->hasRequest() || $this->needsAuthorization()) && !$this->isPosting();
	}

	/**
	 * Determines whether the client is in the process of posting data.
	 *
	 * @return  bool
	 */
	public function isPosting()
	{
		return $this->posting;
	}
	
	/**
	 * Determines whether the client has an active request.
	 *
	 * @return  bool
	 */
	public function hasRequest()
	{
		return !empty($this->request);
	}

	/**
	 * Determines whether the client has any request body content currently buffered.
	 *
	 * @return  bool
	 */
	public function hasRequestBody()
	{
		return (!empty($this->request) && $this->request->hasBody());
	}
	
	/**
	 * Determines whether the client has an active response.
	 *
	 * @return  bool
	 */
	public function hasResponse()
	{
		return !empty($this->response);
	}

	/**
	 * Determines whether the client has any waiting content to send.
	 *
	 * @return  bool
	 */
	public function hasResponseContent()
	{
		return ($this->hasResponse() && $this->response->getContentLength() > 0);
	}

	/**
	 * Determines whether the client has sent all response headers.
	 *
	 * @return  bool
	 */
	public function hasSentHeaders()
	{
		return $this->sentHeaders;
	}

	/**
	 * Determines whether the client response is chunked transfer-encoding.
	 *
	 * @return  bool
	 */
	public function hasChunkedResponse()
	{
		return ($this->hasResponse() && $this->response->isChunked());
	}

	/**
	 * Determines whether the buffer for the request body has reached maximum size.
	 *
	 * @return  bool
	 */	
	public function hasFullRequestBuffer()
	{
		if ($this->hasRequestBody()) {
			$blen = strlen($this->request->getBody());
			$clen = $this->request->getContentLength();
			return ($blen >= $this->requestBufferSize || ($clen && ($blen >= $clen)));
		}
		return false;
	}
	
	/**
	 * Returns the client stream socket.
	 *
	 * @return  resource|bool  false if socket is invalid
	 */
	public function getSocket()
	{
		return isset($this->socket) && is_resource($this->socket) ? $this->socket : false;
	}

	/**
	 * Sets the client stream socket.
	 *
	 * @param   resource  the stream socket
	 * @return  MiniHTTPD_Client|bool  this instance or false if socket is invalid
	 */
	public function setSocket($sock)
	{
		if (!is_resource($sock)) {
			return false;
		}
		$this->socket = $sock;
		return $this;
	}

	/**
	 * Sets the timeput on the client stream socket.
	 *
	 * @param   integer  timeout in seconds
	 * @return  MiniHTTPD_Client  this instance
	 */
	public function setTimeout($secs)
	{
		stream_set_timeout($this->socket, $secs);
		return $this;
	}

	/**
	 * Sets the raw message input for the client.
	 *
	 * @param   string  message input
	 * @return  MiniHTTPD_Client  this instance
	 */
	public function setInput($input)
	{
		$this->input = $input;
		return $this;
	}

	/**
	 * Returns any file stream attached to the response.
	 *
	 * @return  resource  the file stream
	 */	
	public function getStream()
	{
		return $this->response->getStream();
	}

	/**
	 * Determines whether any attached response file stream is available and open
	 * for reading.
	 *
	 * @return  bool  true if the stream is open
	 */	
	public function hasOpenStream()
	{
		return $this->hasResponse() && $this->response->hasStream() && !@feof($this->response->getStream());
	}

	/**
	 * Determines whether the current request needs to be authorized.
	 *
	 * @return  bool
	 */
	public function needsAuthorization()
	{
		return isset($this->response) && $this->response->getStatusCode() == 401;
	}

	/**
	 * Sets whether the current request should be reauthorized.
	 *
	 * @param   bool  should the current request be reauthorized?
	 * @return  void
	 */	
	 public function reauthorize($reauth)
	 {
			$this->reauthorize = (bool) $reauth;
	 }
	 
	/**
	 * Returns the current request object.
	 *
	 * @return  MiniHTTPD_Request
	 */
	public function getRequest()
	{
		return $this->request;
	}

	/**
	 * Returns the current response object.
	 *
	 * @return  MiniHTTPD_Response
	 */
	public function getResponse()
	{
		return $this->response;
	}

	/**
	 * Nulls the current response object.
	 *
	 * @return  MiniHTTPD_Client  this instance
	 */
	public function clearResponse()
	{
		$this->response = null;
		return $this;
	}
	
	/**
	 * Determines whether the client is currently reprocessing the request.
	 *
	 * @return  bool
	 */
	public function isReprocessing()
	{
		return $this->reprocessing;
	}

	/**
	 * Determines whether the client is currently streaming the static file
	 * response.
	 *
	 * @return  bool  true if streaming
	 */	
	public function isStreaming()
	{
		return $this->streaming;
	}

	/**
	 * Determines whether the client is currently in chunking mode.
	 
	 * @return  bool  true if chunking
	 */
	public function isChunking()
	{
		return $this->chunking;
	}
	
	//------------- FastCGI METHODS ------------------------------------------------
	
	/**
	 * Adds a MiniFCGI client object to this client.
	 *
	 * @param   MiniFCGI_Client   the FCGI client
	 * @return  MiniHTTPD_Client  this instance
	 */
	public function addFCGIClient(MFCGI_Client $fcgi)
	{
		$this->fcgi = $fcgi;
		return $this;
	}
	
	/**
	 * Determines if the client has an active FCGI request.
	 *
	 * @return  bool
	 */
	public function hasFCGI()
	{
		return !empty($this->fcgi);
	}

	/**
	 * Returns the FCGI client ID number.
	 *
	 * @return  integer|bool  ID number or false if no FCGI client is attached
	 */
	public function getFCGIClientID()
	{
		if ($this->hasFCGI()) {
			return $this->fcgi->getID();
		}
		return false;
	}

	/**
	 * Returns the attached FCGI client stream socket.
	 *
	 * @return  resource|bool  false if no client is attached
	 */
	public function getFCGISocket()
	{
		return $this->hasOpenFCGI() ? $this->fcgi->getSocket() : false;
	}

	/**
	 * Determines whether any attached FCGI process is open for reading.
	 *
	 * @return  bool  true if the FCGI socket is open
	 */		
	public function hasOpenFCGI()
	{
		return ($this->hasFCGI() && $this->fcgi->hasOpenSocket());
	}

	/**
	 * Determines whether the FCGI process will block while waiting for the request to
	 * be completed.
	 *
	 * @return  bool  true if FCGI will block
	 */		
	public function hasBlockingFCGI()
	{
		return ($this->hasFCGI() && $this->fcgi->isBlocking());
	}

	/**
	 * Determines whether the FCGI request has been ended by the connected process.
	 *
	 * @return  bool  true if FCGI request is completed
	 */		
	public function hasEndedFCGI()
	{
		return ($this->hasFCGI() && $this->fcgi->isEnded());
	}

	/**
	 * Determines whether the FCGI request has been sent to the connected process.
	 *
	 * @return  bool  true if FCGI request was sent
	 */		
	public function hasSentFCGI()
	{
		return ($this->hasFCGI() && $this->fcgi->hasSent());
	}
	
	/**
	 * Returns the ID of the FCGI process with which the client is communicating.
	 *
	 * @return  integer|bool  false if no FCGI process is attached
	 */
	public function getFCGIProcess()
	{
		if ($this->hasFCGI()) {
			return $this->fcgi->getProcess();
		}
		return false;
	}

	/**
	 * Sends the next stage of the FCGI request to the connected process.
	 *
	 * This method can be called repeatedly to allow buffering of the FCGI request,
	 * which limits the chances of the main server loop being blocked by requests
	 * with large bodies, especially file uploads.
	 *
	 * @return  bool  false if the request could not be sent
	 */	
	public function sendFCGIRequest()
	{
		if ($this->request->isPost()) {
			
			// Add any buffered body content to the FCGI request
			$this->fcgi->addRequestContent($this->request);
		}
		
		// Send the FCGI request
		if (!$this->fcgi->sendRequest()) {
			$this->response = null;
			$this->sendError(408, 'The FCGI process could not be reached at this time.');
			return false;
		}

		return true;
	}
	
	/**
	 * Receives the FCGI response and processes it for returning to the client.
	 *
	 * The main task here is to handle the raw response returned from the FastCGI
	 * process and create a response object for communicating the result to the
	 * client. This involves merging any received headers with the server's default
	 * headers, calculating the content length, etc. It's more efficient in this
	 * case to use a single response object bound to both the server client and
	 * the FCGI client objects. Chunked transport-encoding will be automatically
	 * applied if the body exceeds the FCGI buffer size.
	 *
	 * @uses MiniFCGI_Client::readResponse()
	 *
	 * @return  bool  false if the client response needs to be aborted
	 */
	public function readFCGIResponse()
	{
		if ($this->response == null) {

			// Create the client response
			$this->startResponse();

			// Bind the FCGI and client responses
			$this->fcgi->bindResponse($this->response);
		}

		// Get the response and process it
		if ($this->fcgi->readResponse()) {

			// Continue in main loop if chunking, or a non-blocking request is not ended
			if ($this->chunking || (
					!$this->fcgi->isBlocking() && !$this->fcgi->isEnded() 
					&& !$this->fcgi->isChunking() && !$this->response->isChunked()
				)) {
				return true;
			}
			
			// Divert any error messages
			if ($this->response->hasErrorCode()) {
				$this->sendError($this->response->getStatusCode(), '(FastCGI) '.$this->response->getBody());
				return false;
				
			// Check for any X-SendFile request
			} elseif ($this->response->hasHeader('X-SendFile')) {
				return $this->sendFileX($this->response->getHeader('X-SendFile'));
			
			// Check chunked transfer-encoding
			} elseif ($this->fcgi->isChunking()) {
				$this->response->setHeader('Transfer-Encoding', 'chunked');
				$this->chunking = true;
			
			// Otherwise calculate the content length
			} elseif (!$this->response->isChunked()) {
				$this->response->setHeader('Content-Length', $this->response->getContentLength());
			}
			
			return true;

		// Abort client response if unsuccessful
		} else {
			$this->response = null;
			$this->sendError(502, 'The FCGI process did not respond correctly.');
			return false;
		}
	}
	
	//-------------	SERVER CLIENT RESPONSES ----------------------------------------

	/**
	 * Sends an error response to the client.
	 *
	 * This response will use the default error message template in the server's
	 * private docroot templates directory.
	 *
	 * @param   integer  the error response code
	 * @param   string   the error response message
	 * @param   bool     should the connection be closed after the response is sent?
	 * @param   array    a list of additional headers to send
	 * @return  void
	 */
	public function sendError($code, $message, $close=true, $headers=null)
	{
		if ($this->debug) {cecho("Client ({$this->ID}) ... responding with error ".MHTTPD_Message::httpCode($code));}

		// Load and process the error template
		$content = file_get_contents(MHTTPD::getServerDocroot().'templates\errors.tpl');
		$tags    = array(':code:', ':response:', ':message:', ':signature:');
		$values  = array($code, MHTTPD_Message::httpCode($code, true), $message, MHTTPD::getSignature());
		$content = str_replace($tags, $values, $content);

		// Build the error response
		$this->startResponse($code, $close)
			->setHeader('Content-Type', 'text/html'.MHTTPD::getDefaultCharset())
			->setHeader('Content-Length', strlen($content))
			->append($content)
		;

		// Add any extra headers
		if (!empty($headers)) foreach ($headers as $header=>$value) {
			$this->response->setHeader($header, $value);
		}

		// Send the response now, then finish in the main loop
		$this->sendResponse(false);
	}
	
	/**
	 * Sends a 304 Not Modified response to the client for caching purposes.
	 *
	 * @return  bool
	 */
	public function sendNotModified()
	{
		// Only the header will be sent
		if ($this->debug) {cecho("Client ({$this->ID}) ... responding with 304 Not Modified\n");}
		$this->startResponse(304);
		return true;
	}

	/**
	 * Sends a redirect response to the client.
	 *
	 * @param   string   redirected url
	 * @param   integer  redirection response code
	 * @return  bool
	 */
	public function sendRedirect($url, $code=302)
	{
		if ($this->debug) {cecho("Client ({$this->ID}) ... redirecting to: {$url}\n");}

		// Build the redirect response
		$message = 'Redirecting to <a href="'.$url.'">here</a>';
		$this->startResponse($code)
			->setHeader('Location', $url)
			->setHeader('Content-Type', 'text/html'.MHTTPD::getDefaultCharset())
			->setHeader('Content-Length', strlen($message))
			->append($message)
		;

		// Return to the main loop
		return true;
	}
	
	/**
	 * Implements X-SendFile requests from FCGI processes.
	 *
	 * If the FCGI returns an X-SendFile header containing a valid filename within
	 * the configured paths, the server will stream the static file directly. This 
	 * is especially useful for large file transfers. The FCGI script should also 
	 * usually set the Content-Disposition and Content-Type headers for sending files
	 * as attachments, otherwise the file contents will be displayed in the browser.
	 * The X-SendFile header may be a semi-colon separated list of values, the first
	 * of which must always be the absolute path to the file. Options include:
	 *
	 * - nocache  :  will ignore cache info, last-modified checks
	 * - encoded  :  will not remove any Content-Encoding header
	 *
	 * Examples of valid X-SendFile headers:
	 *
	 * - X-SendFile: "full_file_path"
	 * - X-SendFile: "full_file_path"; nocache; encoded
	 *
	 * @param   string  the X-SendFile header value
	 * @return  bool    true if the file was sent successfully
	 */
	protected function sendFileX($sendfile)
	{
		if ($this->debug) {cecho("Client ({$this->ID}) ... X-SendFile: {$sendfile}\n");}
		
		// Parse the X-SendFile header values
		$info = explode(';', $sendfile);
		$file = str_replace('"', '', array_shift($info));
		$opts = array();
		while ($val = array_shift($info)) {
			$opts[trim($val)] = 1;
		}
		
		// Get the valid search paths
		$paths = MHTTPD::getSendFilePaths();
		$valid = false;
		clearstatcache();
		
		// Check the absolute filepath
		$file = realpath($file);
		
		// Is the requested file a valid one?
		if (is_file($file)) foreach ($paths as $path) {
			if (stripos($file, $path) === 0) {$valid = true; break;}
		}		
		if (!$valid) {
			$this->sendError(404, 'The requested file was not found on this server.');
			return false;			
		}
		
		// Get the configured static request handler
		if(!($handler = MHTTPD::getHandlers(false, 'static'))) {
			$this->sendError(500, 'No static handler was available to complete this request.');
			return false;
		}
		$handler->init($this);

		// Remove unneeded headers from the FCGI response
		$this->response->removeHeader('X-SendFile')->removeHeader('Content-type');
		if (!isset($opts['encoded'])) {
			$this->response->removeHeader('Content-Encoding', true);
		}
		
		// Send the static file using the current response object
		$this->request->setFilepath($file)->refreshFileInfo();
		if ($handler->matches() && $handler->execute(false, isset($opts['nocache']))) {
			return $handler->getResult();
		}
		
		return false;
	}

} // End MiniHTTPD_Client
