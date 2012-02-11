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
 * @copyright  (c) 2010-2012 MiniHTTPD Team
 * @license    BSD revised
 */
class MiniHTTPD_Handler_Static extends MHTTPD_Handler
{
	protected $isFinal = true;
	protected $mimes;
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
	
	public function execute($new=true, $nocache=false)
	{
		// Get the requested file details
		$filepath = $this->request->getFilepath();
		$filename = $this->request->getFileName();
		
		if ($new && (!$filepath || !is_file($filepath))) {
		
			// Cannot find the requested file
			$this->error = "File not found ({$filename})";
			$this->result = false;
			
			// Send the error response now
			$this->client->sendError(404, 'The requested URL '.$this->request->getUrlPath().' was not found on this server.');
			return false;
		}
		
		// Set the return value
		$this->result = true;
		
		// Check for any last modified query
		if (!$nocache && $this->request->hasHeader('if-modified-since')) {
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
		$this->startStatic($filepath, $this->info['extension'], $new);
		return true;
	}

	public function reset()
	{
		parent::reset();
		$this->info = null;
	}

	public function __construct()
	{
		$this->mimes = MHTTPD::getMimeTypes();
	}
	
	/**
	 * Initializes the response object for static requests.
	 *
	 * @todo add support for Range, Content-Range / 206 Partial Content
	 * http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.35
	 * http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.16
	 *
	 * @param   string  path to the requested file
	 * @param   string  extension of the requested file
	 * @param   bool    create a new response object?
	 * @return  void
	 */
	protected function startStatic($file, $ext, $new=true)
	{
		// Get the response object
		if ($new) {$this->client->startResponse();}
		$response = $this->client->getResponse();
		
		// Set the static headers
		$response
			->setHeader('Last-Modified', MHTTPD_Response::httpDate(filemtime($file)))
			->setHeader('Content-Length', filesize($file))
		;

		// Set the content-type for the requested file
		if ($new || !$response->hasHeader('Content-Type')) {
		
			// Get the mime type
			if (isset($this->mimes[$ext])) {
				$type = $this->mimes[$ext][0];
			} else {
				$finfo = new finfo(FILEINFO_MIME);
				$type = $finfo->file($file);
			}
			if ($type == '') {$type = 'application/octet-stream';}
			
			// Add any default charset info
			if (preg_match('@text/(plain|html)@', $type)) {
				$type .= MHTTPD::getDefaultCharset();
			}
			
			// Set the header
			$response->setHeader('Content-Type', $type);
		}

		// Open a file handle and attach it to the response
		$response->setStream(@fopen($file, 'rb'));
	}
	
} // End MiniHTTPD_Handler_Static
