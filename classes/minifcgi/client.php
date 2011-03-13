<?php
/**
 * The MiniFCI client class.
 * 
 * This class is used to communicate with a running FastCGI process on behalf of
 * a server client. It handles the connections, parses and sends the FCGI request,
 * and applies the FCGI protocol through the record objects that it creates. The
 * responses are passed back to the server client typically via a bound object.
 *
 * @package    MiniHTTPD
 * @subpackage MiniFCGI 
 * @author     MiniHTTPD Team
 * @copyright  (c) 2010 MiniHTTPD Team
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
	public function addRequest($request)
	{
		if ($request == null || !($request instanceof MHTTPD_Request)) {
			trigger_error('Invalid input values for FCGI Request', E_USER_WARNING);
			return false;
		}
		
		// Get a connection (for the PID header only)
		if (!$this->connect()) {return false;}
		
		// Get the needed variables
		list($host, $address, $port, $ssl) = MHTTPD::getServerInfo();
		list($remoteAddress, $remotePort) = $request->getClientInfo();
		list($pathInfo, $pathTranslated) = $request->getPathInfo();
		list($rUrl, $rQuery, $rStatus) = $request->getRedirectInfo();
		$headers = $request->getHeaders();
		
		// Build the parameters
		$p['GATEWAY_INTERFACE'] = 'FastCGI/1.0';
		if ($ssl) {$p['HTTPS'] = 'On';}
		if ($rStatus) {
			$p['REDIRECT_STATUS'] = $rStatus;
		}
		foreach ($headers as $header=>$value){
			$header = str_replace('-', '_', strtoupper($header));
			$p['HTTP_'.$header] = $value;
		}
		if ($rUrl) {
			$p['REDIRECT_URL'] = $rUrl;
			if ($rQuery) {$p['REDIRECT_QUERY_STRING'] = $rQuery;}			
		}
		$p['SERVER_SIGNATURE'] = MHTTPD::getSignature();
		$p['SERVER_SOFTWARE'] = MHTTPD::getSoftwareInfo();
		$p['SERVER_NAME'] = $host;
		$p['SERVER_PROTOCOL'] = MHTTPD::PROTOCOL;
		$p['SERVER_ADDR'] = $address;
		$p['SERVER_PORT'] = $port;
		$p['SERVER_ADMIN'] = 'admin@localhost';
		$p['REQUEST_METHOD'] = $request->getMethod();
		$p['DOCUMENT_ROOT'] = MHTTPD::getDocroot();
		$p['SCRIPT_NAME'] = $request->getScriptName();
		$p['QUERY_STRING'] = $request->getQueryString();
		$p['SCRIPT_FILENAME'] = $request->getFilepath();
		$p['REMOTE_HOST'] = gethostbyaddr($remoteAddress);
		$p['REMOTE_ADDR'] = $remoteAddress;
		$p['REMOTE_PORT'] = $remotePort;
		$p['REQUEST_URI'] = $request->getUrl(false);
		if ($pathTranslated) {
			$p['PATH_TRANSLATED'] = $pathTranslated;
		}
		if ($pathInfo) {
			$p['PATH_INFO'] = $pathInfo;
		}
		if ($this->debug) {
			$p['X_PID'] = MFCGI::getPID($this->process);
			$p['X_SCORE'] = MFCGI::getScoreboard(true);
			$p['X_REQUEST'] = print_r($request, true);
		}

		// For POST requests add content details
		if ($request->isPost()) {
			$p['CONTENT_TYPE'] = $request->getContentType();
			$content =& $request->getBody();
			$p['CONTENT_LENGTH'] = $request->getContentLength();
		} else {
			$content = '';
		}
		
		// Add the FCGI request info
		$this->request = array(
			'ID' => $this->ID,
			'params'=> $p,
			'content' => $content,
		);
		
		if ($this->debug) {
			cecho('Added FCGI request: '.$request->getUrl(false).PHP_EOL.'--> '.$request->getFilepath().PHP_EOL);
		}
		
		// Allow chaining
		return $this;
	}
	
	/**
	 * Encodes the stored request info as a series of FCGI records and sends them 
	 * to the connected FCGI process.
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

		if ($this->debug) {
			cecho("--> Beginning FCGI request ({$request['ID']})\n");
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
		
		// Send the STDIN stream
		if (!empty($request['content'])) {
			if ($this->debug) {cecho("--> Sending FCGI STDIN ({$request['ID']})\n");}
			$record->setType(MFCGI::STDIN);
			if (!$record->stream($request['content'])) {
				trigger_error("Cannot send FCGI STDIN records ({$request['ID']})", E_USER_WARNING);
				return false;
			}			
			$record->write(); // ends STDIN
		}
				
		// Everything sent OK
		return true;
	}
	
	/**
	 * Parses the received FCGI response records and builds the response object.
	 *
	 * @uses MiniFCGI_Record::factory()
	 * @uses MiniHTTPD_Server::factory()
	 *
	 * @return  bool  false if the response couldn't be processed
	 */
	public function readResponse()
	{
		// Get the active request
		$request = $this->request;
		
		// Check the socket connection
		if (!is_resource($this->socket)) {
			trigger_error("Lost socket connection for response ({$request['ID']})", E_USER_WARNING);
			return false;
		}
		$socket = $this->socket;
		
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
		
		// Fetch and process the response
		while (!@feof($socket) && $tries > 0) {

			if ($this->debug) {cecho("--> Reading FCGI response ({$request['ID']})\n");}
			
			// Get the current record
			if (!$record->read()) {
				trigger_error("Cannot read FCGI response ({$request['ID']}) (".(--$tries).' tries left)', E_USER_NOTICE);
				usleep(500); 
				continue;
			}
						
			// Handle the content
			if ($record->isType(MFCGI::STDOUT) || $record->isType(MFCGI::STDERR)) {
				if (!$response->hasAllHeaders()) {
					$response->parse($record->getContent(), false);
				} else {
					$response->append($record->getContent());
				}
			}
			
			// Any errors to log?
			if ($record->isType(MFCGI::STDERR)) {
				trigger_error('FCGI server returned error', E_USER_WARNING);
			}
		}
		
		// Close the socket gracefully
		if ($this->debug) {
			$sname = stream_socket_get_name($socket, false);
			$sname = stream_socket_get_name($this->socket, false);
			$pinfo = $this->ID == 0 ? $this->process : $this->process.','.MFCGI::getPID($this->process);
			cecho("Closing FCGI connection (c:{$this->ID}, p:{$pinfo}) ({$sname})\n");
		}
		
		// Update the FCGI process info
		MFCGI::removeClient($this->process);
		
		// Close the connection
		MHTTPD::closeSocket($socket);
		
		// Any problems? Check the End Request codes
		if ($request['ID'] != 0 && !$response->hasBody() && $record->isType(MFCGI::END_REQUEST)) {
			trigger_error('FCGI returned no content, request is ended (codes: '.$record->getEndCodes().')', E_USER_NOTICE);
			if ($response->hasErrorCode()) {
				$response->append(print_r($response, true));
			} else {
				$response->append('Nothing to see here ...');
			}
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
			$this->socket = $process[1];
			if ($this->debug) {
				$sname = stream_socket_get_name($this->socket, false);
				$pname = stream_socket_get_name($this->socket, true);
				$pinfo = $this->ID == 0 ? $this->process : $this->process.','.MFCGI::getPID($this->process);
				cecho("Opened FCGI connection (c:{$this->ID}, p:{$pinfo}) ({$sname}->{$pname})\n");
			}
			return true;
		}
		return false;
	}
	
} // End MiniFCGI_Client
