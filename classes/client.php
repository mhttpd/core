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
 * @copyright  (c) 2010 MiniHTTPD Team
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
	 * The maximum block size for outputting streamed responses.
	 * @var integer  16 KB
	 */
	protected $blockSize = 16384;

	/**
	 * Has the current client request been finished?
	 * @var bool
	 */
	protected $finished = false;
	
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
					return $handler->getReturn();
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
				return $handler->getReturn();
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
	 * and retrieves any posted content body.
	 *
	 * @return  bool  false if initialization failed
	 */	
	public function startRequest()
	{
		// Create a new request object and parse the input
		$request = MHTTPD::factory('request');
		$request->debug = $this->debug;
		$this->reprocessing = false;
		
		// Parse the request
		$request->parse($this->input);

		// Did parsing fail for some reason?
		if (!$request->isValid()) {
			$this->sendError(400, 'The request could not be processed.');
			if ($this->debug) {cprint_r($request);}
			return false;
		}

		// Chunked encoding isn't yet supported
		if ($request->isChunked()) {
			$this->sendError(411, 'Chunked transfer-encoding is not supported in this version.');
			return false;
		}

		// Set the extra request info
		$request->setClientInfo($this->address, $this->port);
		$request->setDocroot(MHTTPD::getDocroot());
		$request->getFileInfo();
		
		// If this is a POST request, get the rest of the data
		if ($request->isPost() && $clen = $request->getContentLength() && !$request->hasBody()) {
			$request->setBody(@fread($this->socket, $clen));
		}

		// Attach the request to the client
		$this->request = $request;
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
	 * the FCGI client objects. Currently the whole of the  response body needs to
	 * be buffered to get its content length, which is not ideal.
	 *
	 * @uses MiniFCGI_Client::readResponse()
	 *
	 * @todo Add support for chunked encoding as per HTTP/1.1.
	 *
	 * @return  bool  false if the client response needs to be aborted
	 */
	public function readFCGIResponse()
	{
		// Create the client response
		$this->startResponse();

		// Bind the FCGI and client responses
		$this->fcgi->bindResponse($this->response);

		// Get the response and calculate its content length
		if ($this->fcgi->readResponse()) {

			if ($this->response->hasErrorCode()) {

				// Divert any error messages
				$this->sendError($this->response->getStatusCode(), '(FastCGI) '.$this->response->getBody());
				return false;

			} else {

				// Otherwise continue processing
				if ($this->response->hasBody()) {
					$this->response->setHeader('Content-Length', $this->response->getContentLength());
				}
			}
			return true;

		// Abort client response if unsuccessful
		} else {
			$this->response = null;
			$this->sendError(502, 'The FCGI process did not respond correctly.');
			return false;
		}
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
	 * @todo Support chunked encoding output as per HTTP/1.1
	 *
	 * @param   bool  should the response be finished here?
	 * @return  bool  true if response is sent successfully
	 */
	public function sendResponse($finish=true)
	{
		if (!$this->hasResponse()) {return false;}

		// Get the response header block
		$header = $this->response->getHeaderBlock();
		if ($this->debug) {cecho(":\n\n$header");}

		// Get the content body, if any
		if ($this->request->isHead()) {
			$body = false;
		} else {
			$body = $this->response->getBody();
		}

		// Write the response to the socket
		$bytes = @fwrite($this->socket, $header);
		$this->response->addBytesSent($bytes);
		$sent[] = $bytes;

		if ($body) {

			// Send streamed data in blocks of specified size
			if ($this->response->hasStream()) {
				if ($this->debug) {cecho("Streaming response ... ");}
				while (!feof($body)) {
					if ($data = @fread($body, $this->blockSize)) {
						$bytes = @fwrite($this->socket, $data);
						$this->response->addBytesSent($bytes);
						$sent[] = $bytes;
					}
				}
				@fclose($body);

			// Otherwise send the body string as one block
			} else {
				$bytes = @fwrite($this->socket, $body);
				$this->response->addBytesSent($bytes);
				$sent[] = $bytes;
			}
		}
		if ($this->debug) {cecho('('.join(':', $sent).') ');}

		if ($finish) {

			// Finish the successful request/response
			$this->finish();

		} else {

			// Errors will be finished in the main loop
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

		// Update the logger
		if ($this->logger) {$this->logger->addResponse($this->response);}

		// Finalize & clean up
		$this->finished = true;
		$this->fcgi = null;
		$this->request = null;
		$this->response = null;
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
	 * Determines whether the client has an active request.
	 *
	 * @return  bool
	 */
	public function hasRequest()
	{
		return !empty($this->request);
	}

	/**
	 * Determines whether the client has an active response.
	 *
	 * @return  bool
	 */
	public function hasResponse()
	{
		return $this->response instanceof MHTTPD_Response;
	}

	/**
	 * Returns the client stream socket.
	 *
	 * @return  resource|bool  false if socket is invalid
	 */
	public function getSocket()
	{
		return isset($this->socket) ? $this->socket : false;
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
		$this->socket= $sock;
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
		stream_set_timeout($this->socket, 10);
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
	 * Adds a MiniFCGI client object to this client.
	 *
	 * @param   MiniFCGI_Client   the FCGI client
	 * @return  MiniHTTPD_Client  this instance
	 */
	public function addFCGIClient(MiniFCGI_Client $fcgi)
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
		if ($this->fcgi) {
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
		if ($this->fcgi) {
			return $this->fcgi->getSocket();
		}
		return false;
	}

	/**
	 * Returns the ID of the FCGI process with which the client is communicating.
	 *
	 * @return  integer|bool  false if no FCGI process is attached
	 */
	public function getFCGIProcess()
	{
		if ($this->fcgi) {
			return $this->fcgi->getProcess();
		}
		return false;
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
	 * Determines whether the client is currently reprocessing the request.
	 *
	 * @return  bool
	 */
	public function isReprocessing()
	{
		return $this->reprocessing;
	}

	/**
	 * Common initalization step for all response objects.
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
			->setStatusCode($code)
		;

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

		// Needed for open connections waiting for FCGI
		$this->finished = false;

		// Allow chaining
		return $this->response;
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
			->setHeader('Content-Type', 'text/html')
			->setHeader('Content-Length', strlen($message))
			->append($message)
		;

		// Return to the main loop
		return true;
	}

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
		$tags = array(':code:', ':response:', ':message:', ':signature:');
		$values = array($code, MHTTPD_Message::httpCode($code, true), $message, MHTTPD::getSignature());
		$content = str_replace($tags, $values, $content);

		// Build the error response
		$this->startResponse($code, $close)
			->setHeader('Content-Type', 'text/html')
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
	
} // End MiniHTTPD_Client
