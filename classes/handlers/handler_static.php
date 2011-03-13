<?php
/**
 * This class handles requests for static pages, including any last modified
 * queries for caching purposes.
 * 
 * @see MiniHTTPD_Request_Handler for full documentation.
 *
 * @package    MiniHTTPD
 * @subpackage Handlers
 * @author     MiniHTTPD Team
 * @copyright  (c) 2010 MiniHTTPD Team
 * @license    BSD revised
 */
class MiniHTTPD_Handler_Static extends MHTTPD_Handler
{
	protected $isFinal = true;
	protected $info;
	
	public function matches() 
	{
		$info = $this->request->getFileInfo();
		$ext = !empty($info['extension']) ? $info['extension'] : false;
		
		// Match all extensions that don't need FCGI
		if ($ext && !(in_array($ext, MHTTPD::getFCGIExtensions()))) {
			if ($this->debug) {cecho("Client ({$this->client->getID()}) ... extension matched: $ext\n");}
			$this->info = $info;
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
			$this->returnValue = false;
			
			// Send error response now
			$this->client->sendError(404, 'The requested URL '.$this->request->getUrlPath().' was not found on this server.');
			return false;
		}
		
		// Set the return value
		$this->returnValue = true;
		
		// Check for any last modified query
		if ($this->request->hasHeader('if-modified-since')) {
			$mtime = filemtime($filepath);
			$ifmod = strtotime($this->request->getHeader('if-modified-since'));
			if ($this->debug) {cecho("Client ({$this->client->getID()}) ... last modified query: if:{$ifmod} mt:{$mtime}\n");}
			if ($mtime == $ifmod) {

				// Nothing new to send, so end here
				$this->client->sendNotModified();
				return true;
			}
		}
		
		// Serve the static file
		$this->client->startStatic($filepath, $this->info['extension']);
		return true;
	}

	public function reset()
	{
		parent::reset();
		$this->info = null;
	}
	
} // End MiniHTTPD_Handler_Static
