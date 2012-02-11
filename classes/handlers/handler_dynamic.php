<?php
/**
 * This class handles requests for files that need a FastCGI response.
 * 
 * @see MiniHTTPD_Request_Handler for full documentation.
 *
 * @package    MiniHTTPD
 * @subpackage Handlers
 * @author     MiniHTTPD Team
 * @copyright  (c) 2010-2012 MiniHTTPD Team
 * @license    BSD revised
 */
class MiniHTTPD_Handler_Dynamic extends MHTTPD_Handler
{
	protected $isFinal = true;
	
	public function matches() 
	{
		$info = $this->request->getFileInfo();
		$ext = !empty($info['extension']) ? $info['extension'] : false;
		
		// Match all extensions that need FCGI
		if ($ext && in_array($ext, MHTTPD::getFCGIExtensions())) {
			if ($this->debug) {cecho("Client ({$this->client->getID()}) ... extension matched: $ext\n");}
			return true;
		}
		
		return false;
	}
	
	public function execute()
	{
		// Get the requested file details
		$filepath = $this->request->getFilepath();
		$filename = $this->request->getFileName();
		
		if (!$filepath || !is_file($filepath)) {
		
			// Cannot find the requested file
			$this->error = "File not found ({$filename})";
			$this->result = false;
			
			// Send error response now
			$this->client->sendError(404, 'The requested URL '.$this->request->getUrlPath().' was not found on this server.');
			return false;
		}

		// Start the FCGI request in the calling client
		$this->result = $this->startFCGIRequest();
		return true;
	}

	/**
	 * Creates an FCGI request for any dynamic content via a new MiniFCGI client
	 * object and attaches it to the current server client.
	 *
	 * @uses MiniFCGI_Client::addRequest()
	 *
	 * @return  bool  false if the request could not be started
	 */
	protected function startFCGIRequest()
	{
		// Create the FCGI client object
		$fcgi = new MFCGI_Client($this->client->getID());
		$fcgi->debug = $this->debug;

		// Start the FCGI request
		if (!$fcgi->addRequest($this->request)) {
			$this->client->sendError(408, 'The FCGI process could not be reached at this time.');
			return false;
		}
		$this->client->addFCGIClient($fcgi);

		return true;
	}
	
} // End MiniHTTPD_Handler_Dynamic
