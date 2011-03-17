<?php
/**
 * Handles requests for private server directories, checks access authorization
 * and changes the configured docroot to the private path.
 *
 * @package    MiniHTTPD
 * @subpackage Handlers
 * @author     MiniHTTPD Team
 * @copyright  (c) 2010 MiniHTTPD Team
 * @license    BSD revised
 */
class MiniHTTPD_Handler_Private extends MHTTPD_Handler
{
	protected $isFinal = false;
	protected $useOnce = true;
	protected $dir;
	
	public function matches()
	{
		// Match private directory URLs only
		if (!preg_match('@^/(api-docs|extras)/?@', $this->request->getUrl(), $matches)) {
			return false;
		}
		if ($this->debug) {cecho("Client ({$this->client->getID()}) ... private request ({$matches[1]})\n");}
		$this->dir = $matches[1];
		return true;
	}
	
	public function execute()
	{
		$dir = $this->dir;
		
		// Check the configured access info
		if ( ($dir == 'extras' && !MHTTPD::allowExtrasDir()) 
			|| ($dir == 'api-docs' && !MHTTPD::allowAPIDocs()) 
			) {
			$this->error = "Access to $dir not allowed";
			$this->client->sendError(403, 'You are not authorized to view this page, or the page is not configured for public access.');
			$this->returnValue = true;
			return false;
		}
		
		// Update the request info and continue processing
		$rdir = ($dir == 'api-docs') ? 'docs' : $dir;
		$this->request->rewriteUrlPath("^/$dir/?", '/', true)
			->setDocroot(MHTTPD::getServerDocroot().$rdir.DIRECTORY_SEPARATOR)
			->refreshFileInfo();
		return true;
	}

	public function reset()
	{
		parent::reset();
		$this->dir = null;
	}
	
} // End MiniHTTPD_Handler_Private
