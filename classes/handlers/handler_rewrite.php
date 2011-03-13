<?php
/**
 * This handler rewrites request details based on regex matches of URL strings.
 *
 * To add rewrite rules: extend this class, define the rules in the $rules array,
 * and load the extended handler via the classes.php and .ini files. The 'default'
 * rule given as an example allows the use of pretty URLs as used by various web 
 * frameworks such as Kohana, CI, etc.
 * 
 * @see MiniHTTPD_Request_Handler for full documentation.
 *
 * @todo Add external redirect options to rules.
 *
 * @package    MiniHTTPD
 * @subpackage Handlers
 * @author     MiniHTTPD Team
 * @copyright  (c) 2010 MiniHTTPD Team
 * @license    BSD revised
 */
class MiniHTTPD_Handler_Rewrite extends MHTTPD_Handler
{
	protected $isFinal = false;
	protected $useOnce = true;
	protected $canSkip = true;
	protected $matches;
	
	protected $rules = array
	(
		'default' => array(
			'match'    => '^(.*)$',					// matches every URL in whole
			'replace'  => '/page.php/$1',		// appends whole URL to page.php
			'isFile'   => false,						// ignores all existing files
			'isDir'    => false,						// ignores all existing directories
			'last'     => true,							// no further rules will be applied
			'redirect' => false,						// won't send any redirect info
		),
	);
	
	public function matches() 
	{
		$url = $this->request->getUrl();
		
		// Process each defined rule
		foreach ($this->rules as $name=>$rule) {

			if (!preg_match("|{$rule['match']}|", $url)) {continue;}
			$matched = false;
			
			// Test rules that shouldn't match files or directories
			if ((!$rule['isFile'] && !$rule['isDir']) 
				&& !file_exists($this->request->getUrlFilepath())
				) {
				$matched = true;
			
			// Test rules that should match a file or directory
			} elseif (($rule['isFile'] && is_file($this->request->getUrlFilepath()))
				|| ($rule['isDir'] && is_dir($this->request->getUrlFilepath()))
				) {
				$matched = true;
			}
			
			// Add matching rules to the queue
			if ($matched) {			
				if ($this->debug) {cecho("Client ({$this->client->getID()}) ... rule matched ($name)\n");}
				$this->matches[] = $name;
				if ($rule['last']) {return true;}
			}
		}
		
		return !empty($this->matches);
	}
	
	public function execute()
	{
		$url = $this->request->getUrl();
		
		foreach ($this->matches as $name) {
			
			// Rewrite the URL according to the matched rules
			$rule = $this->rules[$name];
			$url = preg_replace("|{$rule['match']}|", $rule['replace'], $url,	1);
			$url = str_replace('//', '/', $url);
			$redirect = $rule['redirect'] ? $rule['redirect'] : false;

			if ($this->debug) {cecho("Client ({$this->client->getID()}) ... new URL: $url\n");}
		
			// Reprocess the URL info
			$info['url'] = $url;
			$info['url_parsed'] = parse_url($url);
			$info['path_parsed'] = pathinfo($info['url_parsed']['path']);
			
			// Store the previous state of the request
			$info['redirect_url'] = $this->request->getUrlPath();
			$info['redirect_query'] = $this->request->getQueryString();
			$info['redirect_status'] = $redirect;

			// Update the request info
			$this->request->setRewriteInfo($info)->refreshFileInfo();
		}

		// Require reauthorization
		$this->client->reauthorize(true);
		return true;
	}
	
	public function reset()
	{
		parent::reset();
		$this->matches = null;
	}

} // End MiniHTTPD_Handler_Rewrite
