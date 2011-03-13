<?php
/**
 * This class handles the access authorization for the requested resource.
 * URIs, usernames and passwords should be set in the server .ini file.
 * 
 * @see MiniHTTPD_Request_Handler for full documentation.
 *
 * @package    MiniHTTPD
 * @subpackage Handlers
 * @author     MiniHTTPD Team
 * @copyright  (c) 2010 MiniHTTPD Team
 * @license    BSD revised
 */
class MiniHTTPD_Handler_Auth extends MHTTPD_Handler
{
	protected $isFinal = false;
	protected $useOnce = true;
	protected $persist = false;
	protected $auth;

	public function init(MiniHTTPD_Client $client)
	{
		parent::init($client);
		$this->client->reauthorize(false);
	}
	
	public function matches() 
	{
		$url = $this->request->getUrl();
		
		// Match any server admin or private dir request
		if (($auth = MHTTPD::getAdminAuth()) !== false
			&& ( preg_match('@^/server-(status|info)$@', $url, $matches)
				|| preg_match('@^/(api-docs|extras)/?@', $url, $matches)
			)) {
			if ($this->debug) {cecho("Client ({$this->client->getID()}) ... auth request (admin)\n");}
			$this->auth = $auth;
			return true;
		}
		
		// Match any configured access auth info for the URI
		if (($authList = MHTTPD::getAuthList()) === false) {return false;}
		$url = $this->request->getUrlPath();
		foreach ($authList as $uri=>$info) {
			if (stripos($url, $uri) === 0) {
				if ($this->debug) {cecho("Client ({$this->client->getID()}) ... auth request ($uri)\n");}
				$this->auth = MHTTPD::getAuthInfo($uri);
				return true;
			}
		}
		
		return false;
	}
	
	public function execute()
	{		
		$realm = $this->auth['realm'];
		$user = array($this->auth['user'] => $this->auth['pass']);
		
		// Check access authorization
		if ($this->checkDigestAuthorization($realm, $user)) {
			return true;
		} 
		
		// Authorization failed
		$this->error = "Access denied ({$this->auth['realm']})";
		return false;
	}
	
	public function reset()
	{
		parent::reset();
		$this->auth = null;
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
	protected function checkDigestAuthorization($realm, $users)
	{
		if ($this->debug) {cecho("Client ({$this->client->getID()}) ... checking authorization ({$realm})\n");}

		if (!$this->request->hasHeader('authorization')) {

			// The text that will be displayed if the client cancels:
			$text = 'This server could not verify that you are authorized to access the page requested. '
				.'Either you supplied the wrong credentials (e.g., bad password), or your browser does not understand '
				.'how to supply the credentials required.';

			// Create the authentication digest challenge
			$digest = 'Digest realm="'.$realm.'",qop="auth",nonce="'.uniqid().'",opaque="'.md5($realm).'"';
			$header = array('WWW-Authenticate' => $digest);

			// Send challenge using the error template, but keep the connection open
			$this->client->sendError(401, $text, false, $header);
			return false;

		} elseif (!$this->request->isAuthorized($realm, $users)) {

			// Authorization failed, so keep trying in a loop
			if ($this->debug) {cecho("Client ({$this->client->getID()}) ... authorization failed, retrying ...\n");}
			$this->checkDigestAuthorization($realm, $users);
			return false;
		}
		
		// Successfully authorized
		return true;
	}
	
} // End MiniHTTPD_Handler_Admin
