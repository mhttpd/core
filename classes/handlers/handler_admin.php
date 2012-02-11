<?php
/**
 * This handler returns the server administration pages if access is enabled
 * and authorized.
 * 
 * @see MiniHTTPD_Request_Handler for full documentation.
 *
 * @package    MiniHTTPD
 * @subpackage Handlers
 * @author     MiniHTTPD Team
 * @copyright  (c) 2010-2012 MiniHTTPD Team
 * @license    BSD revised
 */
class MiniHTTPD_Handler_Admin extends MHTTPD_Handler
{
	protected $isFinal = true;
	protected $useOnce = true;
	protected $page;
	
	public function matches() 
	{
		// Match server admin page requests only
		if (!preg_match('@^/server-(status|info)$@', $this->request->getUrl(), $matches)) {
			return false;
		}
		if ($this->debug) {cecho("Client ({$this->client->getID()}) ... admin request ({$matches[1]})\n");}
		$this->page = $matches[1];
		return true;
	}
	
	public function execute()
	{		
		$this->returnValue = call_user_func(array($this, 'sendServer'.$this->page));
		return true;
	}
	
	public function reset()
	{
		parent::reset();
		$this->page = null;
	}

	/**
	 * Outputs the Server Status administration page.
	 *
	 * @return  bool  false if access is not authorized
	 */
	protected function sendServerStatus()
	{
		if (!MHTTPD::allowServerStatus()) {
			if ($this->debug) {cecho("Client ({$this->client->getID()}) ... server Status page is not allowed\n");}
			$this->client->sendError(403, 'You are not authorized to view this page, or the page is not configured for public access.');
			return false;
		} else {
			if ($this->debug) {cecho("Client ({$this->client->getID()}) ... sending Server Status page\n");}
		}

		// Load and process the template
		$content = file_get_contents(MHTTPD::getServerDocroot().'templates\server_status.tpl');
		$tags = array(':version:', ':launched:', ':trafficup:', ':trafficdown:', ':clients:',
			':fcgiscoreboard:', ':aborted:', ':requesthandlers:', ':signature:'
		);
		$values = MHTTPD::getServerStatusInfo();
		$content = str_replace($tags, $values, $content);

		// Build the response
		$this->client->startResponse(200)
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
			if ($this->debug) {cecho("Client ({$this->client->getID()}) ... Server Info page is not allowed\n");}
			$this->client->sendError(403, 'You are not authorized to view this page, or the page is not configured for public access.');
			return false;
		} else {
			if ($this->debug) {cecho("Client ({$this->client->getID()}) ... sending Server Info page\n");}
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
		$this->client->startResponse(200)
			->setHeader('Content-Type', 'text/html')
			->setHeader('Content-Length', strlen($content))
			->append($content)
		;

		// Return to the main loop
		return true;
	}
	
} // End MiniHTTPD_Handler_Admin
