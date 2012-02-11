<?php
/**
 * Handles requests for directories. If a default index file is found in the
 * directory, the request will be re-processed to serve this file.
 * 
 * @see MiniHTTPD_Request_Handler for full documentation.
 *
 * @package    MiniHTTPD
 * @subpackage Handlers
 * @author     MiniHTTPD Team
 * @copyright  (c) 2010-2012 MiniHTTPD Team
 * @license    BSD revised
 */
class MiniHTTPD_Handler_Directory extends MHTTPD_Handler
{
	protected $isFinal = true;
	protected $dir;
	
	public function matches() 
	{
		// Only match requests for directories
		$info = $this->request->getFileInfo();
		if (!empty($info['extension'])) {return false;}
		
		$DS = DIRECTORY_SEPARATOR;
		$dir = trim(str_replace('/', $DS, $this->request->getUrlPath()), $DS);
		if ($this->debug) {cecho("Client ({$this->client->getID()}) ... directory requested ($dir)\n");}
		$this->dir = $dir;
		
		return true;
	}
	
	public function execute()
	{
		$DS = DIRECTORY_SEPARATOR;
		
		// Search for a default index file
		$dir = rtrim($this->request->getDocroot().$this->dir, $DS);
		$indexFiles = MHTTPD::getIndexFiles();
		$url = $this->request->getUrl();
		
		foreach ($indexFiles as $index) {
			
			// Build the file path string
			$file = $dir.$DS.$index;
			
			if (($file = realpath($file)) && is_file($file)) {
				
				// The index file path is valid
				if ($this->debug) {cecho("Client ({$this->client->getID()}) ... picking default index file ({$file})\n");}
								
				// Redirect to add a trailing slash to the URL if needed
				if ($this->request->needsTrailingSlash($url)) {
					$this->client->sendRedirect(MHTTPD::getBaseUrl().$url.'/', 301);
					$this->result = true;
					return true;
				}

				// Otherwise update the file info and re-process the request
				$this->request->setFilename(rtrim($this->request->getUrlPath(), '/').'/'.$index)
					->setFilepath($file)
					->refreshFileInfo();
				$this->result = $this->client->processRequest();
				return true;
			}
		}
		
		// Nothing to serve
		$this->client->sendError(404, 'The requested URL '.$this->request->getUrlPath().' was not found on this server.');
		$this->result = false;
		return false;
	}

	public function reset()
	{
		parent::reset();
		$this->dir = null;
	}
	
} // End MiniHTTPD_Handler_Directory
