<?php
/**
 * This handler rewrites request details based on regex matches of URL strings.
 *
 * To add rewrite rules: extend this class and define the rules in $rules, then
 * load the extended handler via the classes.php and ini files. A simpler method
 * is to define the rules in a custom file and load it via the main server ini 
 * file (see the loadRules() method for more details).
 * 
 * The 'default' rule given as an example allows the use of pretty URLs as used by 
 * various web frameworks such as Kohana, CI, etc.
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
	protected $rules;
	
	protected $rules_default = array
	(
		'default' => array(
			'match'    => '^(.*)$',         // matches every URL in whole
			'exclude'  => NULL,             // won't exclude any matches
			'replace'  => '/index.php/$1',  // appends whole URL to index.php
			'isFile'   => false,            // ignores all existing files
			'isDir'    => false,            // ignores all existing directories
			'strict'   => true,             // checks for filename extensions
			'last'     => true,             // no further rules will be applied
			'redirect' => false,            // won't send any redirect info
		),
	);
		
	public function matches() 
	{
		$url = $this->request->getUrl();
		$ext = pathinfo($this->request->getFilename(), PATHINFO_EXTENSION);
		
		// Process each defined rule
		foreach ($this->rules as $name=>$rule) {

			if (!preg_match("|{$rule['match']}|", $url)
				|| (isset($rule['exclude']) && preg_match("|{$rule['exclude']}|", $url))
				) {
				continue;
			}
			$matched = false;
			
			// Test rules that shouldn't match files or directories
			if (((!$rule['strict'] || !$ext) && !$rule['isFile'] && !$rule['isDir']) 
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

	public function __construct()
	{
		$this->loadRules();
	}

	/**
	 * Loads the rewrite rules from an external file if $rules is not already set.
	 *
	 * The path to the rules file should be set in the server ini file. If it can't
	 * be found, the $rules_default array will be used instead.
	 *
	 * @return  void
	 */	
	protected function loadRules()
	{
		if (!isset($this->rules)) {
			
			// Search for the configured rules file
			if (($conf = MHTTPD::getConfig('Rewrite')) 
				&& (
					($file = realpath($conf['rules_file']))
					||
					($file = realpath(INIPATH.$conf['rules_file']))
				)
				&& is_file($file)
				) {

				// Fetch rules from the configured file
				$this->rules = parse_ini_file($file, true);
				if (MHTTPD::$debug) {cecho("Loaded rules from: $file\n");}	
				
			} else {
				
				// Use the default values
				if (MHTTPD::$debug) {cecho("Rules file not found, using default rules\n");}	
				$this->rules = $this->rules_default;
			}
		}
	}
	
} // End MiniHTTPD_Handler_Rewrite
