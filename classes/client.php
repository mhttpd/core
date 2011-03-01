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
	 * The raw data for teh active request.
	 * @var string
	 */	
	protected $input;
	
	/**
	 * The active request object for the client.
	 * @var MiniHTTPD_Request
	 */	
	protected $request;

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
	 * Processes the client request and determines the response type.
	 *
	 * This method contains most of the logic for handling client requests. First
	 * it creates a request object from the raw input, then figures out whether
	 * the request is for static or dynamic content. It is responsible for calling
	 * the appropriate actions for the client response and reporting any errors
	 * back to the main server loop.
	 *
	 * @todo Refactor to make the whole class more modular and extensible, probably
	 * by adding event hooks or similar.
	 *
	 * @return  bool  true if the request has been successfully handled
	 */
	public function processRequest()
	{
		if (empty($this->input)) {
			trigger_error("No request input to process", E_USER_NOTICE);
			return false;
		}
		
		if ($this->debug) {cecho("Client ({$this->ID}) ... processing request\n");}
			
		// Create a new request object and parse the input
		if ($this->debug) {cecho("\n{$this->input}\n\n");}
		$request = MHTTPD::factory('request');
		$request->debug = $this->debug;

		// Bind the request to the client
		$this->request =& $request;
		
		// Start the access logger
		$this->logger = MHTTPD_Logger::factory('access');
	
		// Parse the request
		$request->parse($this->input);
		
		// Did parsing fail for some reason?
		if (!$request->isValid()) {
			$this->sendError(400, 'The request could not be processed.');
			cprint_r($request);
			return false;
		}
		
		// Chunked encoding isn't yet supported
		if ($request->isChunked()) {
			$this->sendError(411, 'Chunked transfer-encoding is not supported in this version.');
			return false;
		}
		
		// Set the client info
		$request->setClientInfo($this->address, $this->port);
		$this->logger->addRequest($this->request);
		$url = $request->getUrl();
		
		// Handle any internal admin requests needing authorization
		if (preg_match('@^/server-(status|info)$@', $url, $matches)) {
			if ($this->checkAuthorization('server admin', MHTTPD::getAdminInfo())) {
				return call_user_func(array($this, 'sendServer'.$matches[1]));
			} else {
				return false;
			}
		}

		// If the request is for a directory, add a trailing slash if needed
		if ($this->request->needsTrailingSlash($url)) {
			$this->sendRedirect(MHTTPD::getBaseUrl().$url.'/', 301);
			return true;
		}
		
		// Set the docroot, handling any virtual-to-real mappings
		if (preg_match('|^/api-docs/?|', $url, $match)) {
			$docroot = $this->getAPIDocsRoot($match[0]);
			if (is_bool($docroot)) {return $docroot;}
		} elseif (preg_match('|^/extras/?|', $url, $match)) {
			$docroot = $this->getExtrasRoot($url);
			if (is_bool($docroot)) {return $docroot;}		
		} else {
			$docroot = MHTTPD::getDocroot();
		}
		
		// Process the requested file
		list($dir, $base, $name, $ext) = $request->getFileInfo($docroot);
		$haveFile = false;
		$file = '';

		// Try to find the file to serve
		if (strpos($dir, $docroot) !== false && $ext != '') {
			
			// It's not pointing to a directory, so use this
			$file = $dir.$base;
			
		} elseif (empty($ext)) {

			// No file is specified, so look for a default index file
			$base = $base ? $base.DIRECTORY_SEPARATOR : '';
			$indexFiles = MHTTPD::getIndexFiles();
			foreach ($indexFiles as $index) {
				$file = $docroot.$dir.$base.$index;
				if (is_file($file)) {
					if ($this->debug) {cecho("Picking default index file ({$file})\n");}
					$request->setFilename(str_replace('//', '/', $request->getUrlPath().'/'.$index))
						->setFilepath($file);
					list($dir, $base, $name, $ext) = $request->getFileInfo($docroot);
					$haveFile = true;
					break;
				}
			}
		}

		// Does the requested file exist?
		if (!$haveFile && !is_file($file)) {
			if ($this->debug) {cecho("Requested file does not exist ({$file})\n");}
			$this->sendError(404, 'The requested URL '.$request->getUrlPath().' was not found on this server.');
			return false;
		}

		// If this is a POST request, get the rest of the data
		if ($request->isPost() && $clen = $request->getContentLength()) {
			$request->body = @fread($this->socket, $clen);
		}
		
		// Dynamic responses: send an FCGI request and wait for the reply in the next server loop
		if (in_array($ext, MHTTPD::getFCGIExtensions())) {
			if ($this->debug) {cecho("Client ({$this->ID}) ... needs FCGI ({$ext})\n");}
			return $this->sendFCGIRequest();
		}

		// Static responses: first check for any last modified query
		if ($request->hasHeader('if-modified-since')) {
			$mtime = filemtime($file);
			$ifmod = strtotime($request->getHeader('if-modified-since'));
			if ($this->debug) {cecho("Last modified query: if:{$ifmod} mt:{$mtime}\n");}
			if ($mtime == $ifmod) {
			
				// Nothing new to send, so end here
				$this->sendNotModified();
				return true;
			}
		}		
			
		// Handle new static responses in the current server loop
		if ($this->debug) {cecho("Client ({$this->ID}) ... is static ({$ext})\n");}
		$this->startStatic($file, $ext);
		
		// Finish
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
	 * all of the respose headers should have been prepared, and the message 
	 * body either buffered in whole or waiting to be streamed to the client.
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
	 * The main task here is to call any attached logger and clean up the 
	 * created objects.  Typically this will be called immediately after 
	 * sending the response, although error messages may be finished later.
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
	 * Creates an FCGI request for any dynamic content and sends it to any
	 * available FastCGI process via a new MiniFCGI client object.
	 *
	 * @uses MiniFCGI_Client::addRequest()
	 * @uses MiniFCGI_Client::sendRequest()
	 *
	 * @return  bool  false if the request could not be sent
	 */
	protected function sendFCGIRequest()
	{
		// Create the FCGI client object
		$fcgi = new MFCGI_Client($this->ID);
		$fcgi->debug = $this->debug;
		
		// Start the FCGI request
		if (!$fcgi->addRequest($this->request) || !$fcgi->sendRequest()) {
			$this->sendError(502, 'The FCGI process did not respond correctly.');
			return false;
		}
		$this->fcgi = $fcgi;
		
		return true;	
	}
	
	/**
	 * Determines whether the client is authorized to access the requested resource.
	 *
	 * This method uses the HTTP digest access authentication system. It will keep
	 * repeating the authentication challenge if the attempt to authorize fails.
	 *
	 * @param   string  the authentication realm
	 * @param   array   list of valid users
	 * @return  bool    false if client is not authorized
	 */
	protected function checkAuthorization($realm, $users) 
	{
		if ($this->debug) {cecho("Checking authorization ({$realm})\n");}
		
		if (!$this->request->hasHeader('authorization')) {
		
			// The text that will be displayed if the client cancels:
			$text = 'This server could not verify that you are authorized to access the page requested. '
				.'Either you supplied the wrong credentials (e.g., bad password), or your browser does not understand '
				.'how to supply the credentials required.';
			
			// Create the authentication digest challenge
			$digest = 'Digest realm="'.$realm.'",qop="auth",nonce="'.uniqid().'",opaque="'.md5($realm).'"';
			$header = array('WWW-Authenticate' => $digest);
			
			// Send using the error template, but keep the connection open
			$this->sendError(401, $text, false, $header);
			return false;
			
		} elseif (!$this->request->isAuthorized($realm, $users)) {
			
			// Authorization failed, so keep trying in a loop
			if ($this->debug) {cecho("Authorization failed, retrying ...\n");}
			$this->checkAuthorization($realm, $users);
			return false;
		}
		
		return true;
	}
	
	/**
	 * Initializes the response object for static requests.
	 *
	 * @param   string  path to the requested file
	 * @param   string  extension of the requested file
	 * @return  void
	 */
	protected function startStatic($file, $ext)
	{
		$this->startResponse();
		
		// Set the static headers
		$this->response
			->setHeader('Last-Modified', MHTTPD_Response::httpDate(filemtime($file)))
			->setHeader('Content-Length', filesize($file))
		;
		
		// Get the mime type for the requested file
		switch ($ext) {
			case 'html':
				$mime = 'text/html; charset=utf-8'; break;
			case 'css':
				$mime = 'text/css; charset=utf-8'; break;
			default:
				$finfo = new finfo(FILEINFO_MIME);
				$mime = $finfo->file($file);
				$mime = !empty($mime) ? $mime : 'application/octet-stream';
		}
		$this->response->setHeader('Content-Type', $mime);
		
		// Open a file handle and attach it to the response
		$this->response->setStream(fopen($file, 'rb'));
	}
	
	/**
	 * Common initalization step for all response objects.
	 *
	 * @param   integer  response code
	 * @param   bool     should the connection be closed after the response is sent?
	 * @return  MiniHTTPD_Client  this instance
	 */
	protected function startResponse($code=200, $close=false)
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
	protected function sendNotModified()
	{
		// Only the header will be sent
		if ($this->debug) {cecho("Responding with 304 Not Modified\n");}
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
	protected function sendRedirect($url, $code=302)
	{
		if ($this->debug) {cecho("Redirecting request to: {$url}\n");}
		
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
	protected function sendError($code, $message, $close=true, $headers=null)
	{
		if ($this->debug) {cecho('Responding with error '.MHTTPD_Message::httpCode($code));}
		
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

	/**
	 * Outputs the Server Status administration page.
	 *
	 * @return  bool  false if access is not authorized
	 */
	protected function sendServerStatus()
	{
		if (!MHTTPD::allowServerStatus()) {
			if ($this->debug) {cecho("Server Status page is not allowed\n");}
			$this->sendError(403, 'You are not authorized to view this page, or the page is not configured for public access.');
			return false;
		} else {
			if ($this->debug) {cecho("Sending Server Status page\n");}
		}
		
		// Load and process the template
		$content = file_get_contents(MHTTPD::getServerDocroot().'templates\server_status.tpl');
		$tags = array(':version:', ':clients:', ':fcgiscoreboard:', ':signature:');
		$values = MHTTPD::getServerStatusInfo();
		$content = str_replace($tags, $values, $content);
		
		// Build the response
		$this->startResponse(200)
			->setHeader('Content-Type', 'text/html')
			->setHeader('Content-Length', strlen($content))
			->append($content)
		;
		
		// Return to the main loop
		return true;
	}

	/**
	 * Outputs the Server Info administration page.
	 *
	 * @return  bool  false if access is not authorized
	 */	
	protected function sendServerInfo()
	{
		if (!MHTTPD::allowServerInfo()) {
			if ($this->debug) {cecho("Server Info page is not allowed\n");}
			$this->sendError(403, 'You are not authorized to view this page, or the page is not configured for public access.');
			return false;
		} else {
			if ($this->debug) {cecho("Sending Server Info page\n");}
		}
		
		// Capture the server info output
		ob_start();	phpinfo(INFO_GENERAL | INFO_CONFIGURATION | INFO_MODULES);
		$info = ob_get_clean();
		if (HAS_CONSOLE) {$info = '<pre>'.$info.'</pre>';}
		
		// Load and process the template
		$content = file_get_contents(MHTTPD::getServerDocroot().'templates\server_info.tpl');
		$tags = array(':info:', ':signature:');
		$values = array($info, MHTTPD::getSignature());
		$content = str_replace($tags, $values, $content);
				
		// Build the response
		$this->startResponse(200)
			->setHeader('Content-Type', 'text/html')
			->setHeader('Content-Length', strlen($content))
			->append($content)
		;
		
		// Return to the main loop
		return true;
	}
	
	/**
	 * Returns the path to the API documentation directory in the server's
	 * private docroot.
	 *
	 * @param   string  the requested url
	 * @return  string|bool  the directory path, false if not authorized,
	 *                       true if the request needs to be redirected
	 */
	protected function getAPIDocsRoot($url)
	{
		if ($this->checkAuthorization('server admin', MHTTPD::getAdminInfo())) {
			if (!MHTTPD::allowAPIDocs()) {
				if ($this->debug) {cecho("API Docs page is not allowed\n");}
				$this->sendError(403, 'You are not authorized to view this page, or the page is not configured for public access.');
				return false;
			}
			$docroot = MHTTPD::getServerDocroot().'docs'.DIRECTORY_SEPARATOR;
			$this->request->rewriteUrlPath($url, '/', true);
			return $docroot;
		}
		
		return false;
	}

	/**
	 * Returns the path to the Extras directory in the server's private docroot.
	 *
	 * @param   string  the requested url
	 * @return  string|bool  the directory path, false if not authorized,
	 *                       true if the request needs to be redirected
	 */
	protected function getExtrasRoot($url)
	{
		if ($this->checkAuthorization('server admin', MHTTPD::getAdminInfo())) {
			if (!MHTTPD::allowExtrasDir()) {
				if ($this->debug) {cecho("Access to the Extras directory is not allowed\n");}
				$this->sendError(403, 'You are not authorized to view this page, or the page is not configured for public access.');
				return false;
			}
			$docroot = MHTTPD::getServerDocroot().'extras'.DIRECTORY_SEPARATOR;
			$this->request->rewriteUrlPath('^/extras/?', '/', true);
			return $docroot;
		}
		
		return false;
	}
	
} // End MiniHTTPD_Client
