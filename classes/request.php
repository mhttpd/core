<?php
/**
 * The MiniHTTPD request class.
 * 
 * Apart from overloading any members in the base message class, the main
 * responsibility of this class is to provide the information needed by the
 * client and request handlers to process the request and determine the relevant
 * response. Much of this class therefore consists of accessor or query methods.
 *
 * @package    MiniHTTPD
 * @author     MiniHTTPD Team
 * @copyright  (c) 2010 MiniHTTPD Team
 * @license    BSD revised
 */
class MiniHTTPD_Request extends MHTTPD_Message
{
	// ------ Class variables and methods ------------------------------------------

	/**
	 * Decodes strings sent via chunked transport-encoding.
	 *
	 * @param   string  the chunked string
	 * @return  string  the decoded string
	 */
	public static function unChunk($chunked) 
	{
		$len = strlen($chunked);
		$result = '';		
		$pos = 0;
		while ($pos < $len) {
			$hex = substr($chunked, $pos, strpos(substr($chunked, $pos), "\r\n") + 2);
			$size = hexdec(trim($hex));
			$pos += strlen($hex);
			$chunk = substr($chunked, $pos, $size);
			$result .= $chunk;
			$pos += strlen($chunk);
		}
		return $result;
	}
	
	/**
	 * Parses a HTTP digest authentication string.
	 *
	 * @link http://www.php.net/manual/en/features.http-auth.php
	 *
	 * @param   string       the digest value
	 * @return  array|false  the parsed digest or error
	 */
	protected static function parseHTTPDigest($string)
	{
		// Protect against missing data
		$needed_parts = array('nonce'=>1, 'nc'=>1, 'cnonce'=>1, 'qop'=>1, 'username'=>1, 'uri'=>1, 'response'=>1);
		$data = array();
		
		// Parse the digest text
		$entries = explode(',', str_ireplace('digest ', '', $string));
		foreach ($entries as $entry) {
			$e = explode('=', trim($entry), 2);
			$e[1] = trim(str_replace('"', '', $e[1]));
			if (!empty($e[1])) {
				$data[$e[0]] = $e[1];
				unset($needed_parts[$e[0]]);
			}
		}

		return $needed_parts ? false : $data;
	}
	
	// ------ Instance variables and methods ---------------------------------------
	
	/**
	 * Information about the request parsed from the request line.
	 * @var array
	 */
	protected $info = array();

	/**
	 * The server docroot accessible to this request. 
	 * @var string
	 */
	protected $docroot;
	
	/**
	 * The absolute path to the requested file. 
	 * @var string
	 */
	protected $filepath;

	/**
	 * Pre-parsed information about the requested file. 
	 * @var array
	 */
	protected $fileInfo = array();
		
	/**
	 * Returns the stored request information as an array.
	 *
	 * @return  array
	 */
	public function asArray()
	{
		$ret = array(
			'info' => $this->info,
			'file' => $this->filepath,
			'headers' => $this->headers,
			'body' => $this->body,
		);
		if ($this->debug) {
			$ret = array('input' => $this->input) + $ret;
		}
		return $ret;
	}	
	
	/**
	 * Returns the stored request information as a raw string.
	 *
	 * @return  string  the request headers and body
	 */
	public function asString()
	{
		$str = '';
		
		// Status/info line
		foreach ($this->info as $key=>$value) {
			$str .= $value.' ';
		}
		$str .= "\r\n";
		
		// Header values
		foreach ($this->headers as $key=>$value) {
			$str .= "$key: $value\r\n";
		}
		
		// Message body
		if ($this->body != '') {
			$str .= "\r\n".$this->body;
		}
		
		return $str;
	}
	
	/**
	 * Returns an array of information about the requested file.
	 *
	 * By default this method uses the result of the last call, so if the request
	 * details are changed it should be re-called with $refresh set too true.
	 *
	 * @param   string  the docroot in which to search for the requested file
	 * @param   bool    should the stored values be refreshed?
	 * @return  array   the pathinfo() output of the requested file
	 */
	public function getFileInfo($dcroot=null, $refresh=false)
	{
		// Use the default, request or passed docroot?
		if (!$dcroot && !$this->docroot) {		
			$docroot = MHTTPD::getDocroot();
		} elseif (!$dcroot) {
			$docroot = $this->docroot;
		} else {
			$docroot = $dcroot;
		}		
		
		// Initialize
		$DS = DIRECTORY_SEPARATOR;
		$info = array();
		
		// Make sure we have uncached info
		if ($refresh || empty($this->fileInfo)) {
			if ($this->debug) {cecho("Docroot: $docroot\n");}
			clearstatcache();
		}
		
		if (!$refresh && !empty($this->fileInfo)) {
			
			// Use the previously stored values
			return $this->fileInfo;
		
		} elseif (!empty($this->filepath)) {
			
			// Parse the file path found in the last successful attempt
			if ($this->debug) {cecho("Using file: {$this->filepath}\n");}
			$info = pathinfo($this->filepath);
			
		} elseif (!empty($this->info['url_parsed'])) {

			// Start grokking the parsed URL path	
			$file = $docroot;
			
			// Choose the original or rewritten URL info
			$urlPath = !empty($this->info['rewritten']['url_parsed'])
				? $this->info['rewritten']['url_parsed']['path']
				: $this->info['url_parsed']['path']
			;
			
			// Try to get the filename and any extra path info
			if (preg_match('|(.*?\.\w+)(/.*)|', $urlPath, $matches)) {
				$this->info['filename'] = $matches[1];
				$this->info['path_info'] = $matches[2];
			} else {
				$this->info['filename'] = $urlPath;
			}
			if ($this->debug) {cecho("Filename: {$this->info['filename']}\n");}
			
			// Build the real file path to search
			$file .= str_replace('/', $DS, ltrim($this->info['filename'], '/'));
			
			if (($rfile = realpath($file)) && is_file($rfile)) {
				
				// The file exists, so store its details
				if ($this->debug) {cecho("File found: $rfile\n");}
				$this->filepath = $rfile;
				$info = pathinfo($rfile);
				
			} else {
				
				// File not found, so use the URL values as fallback
				$info = !empty($this->info['rewritten']['path_parsed']) 
				 ? $this->info['rewritten']['path_parsed']
				 : $this->info['path_parsed']
				;
			}
		}
		
		// Store the values and return
		$this->fileInfo = $info;
		return $info;
	}

	/**
	 * Helper method that refreshes the stored file info, is chainable.
	 *
	 * @return  MiniHTTPD_Request  this object
	 */
	public function refreshFileInfo()
	{
		$this->getFileInfo(null, true);
		return $this;
	}
	
	/**
	 * Rewrites the path element of the request URL to allow transparent virtual 
	 * to real path mapping.
	 *
	 * This is especially useful for fixing issues with links given as relative 
	 * paths where the browser will automatically add the base path. Any virtual
	 * path elements (i.e. ones that can't be found in the docroot) should be 
	 * rewritten in this case usually to just '/'.
	 *
	 * @param   string  the regex search pattern
	 * @param   string  the replacement value
	 * @param   bool    should $search be stored as the new base path?
	 * @return  MiniHTTPD_Request  this instance
	 */
	public function rewriteUrlPath($search, $replace='', $isBasePath)
	{
		if ($this->debug) {cecho("Rewriting URL Path: ({$this->info['url_parsed']['path']} | {$search} -> {$replace} | ");} 

		if (!preg_match("|{$search}|", $this->info['url_parsed']['path'], $matches)) {
			if ($this->debug) {cecho('nothing replaced)'.PHP_EOL);}
			return $this;
		}

		// Rewrite the URL path
		$url = preg_replace("|{$search}|", $replace, $this->info['url_parsed']['path']);
		$this->info['path_parsed'] = pathinfo($url);
		$this->info['url_parsed']['path'] = $url;
		if ($this->debug) {cecho($url.')'.PHP_EOL);}
		
		// Add $search as base path?
		if ($isBasePath) {
			$this->info['url_parsed']['base_path'] = $matches[0];
			if ($this->debug) {cecho(' ... added as base path ('.$matches[0].')'.PHP_EOL);}
		}
		
		return $this;
	}

	/**
	 * Sets any new request info created by a rewrite action.
	 *
	 * @param   array  the rewritten info
	 * @return  MiniHTTPD_Request  this object
	 */
	public function setRewriteInfo($info)
	{
		$this->info['rewritten'] = $info;
		return $this;
	}
	
	/**
	 * Tests whether a request URL needs a trailing slash (mainly for redirecting
	 * to a directory with '301 Moved Permanently'). If a URL is not passed as a 
	 * parameter, the current request URL will be tested instead.
	 *
	 * @param   string  URL to be tested
	 * @param   bool    use simple test? 
	 * @return  bool	  true if trailing slash is needed
	 */	
	public function needsTrailingSlash($url=null)
	{
		if (!$url) {$url = $this->getUrl();}
		
		// Only test if a trailing slash isn't already present
		if (substr($url, -1) != '/') {
		
			// Parse the URL and check for any files in the path
			$i = parse_url($url);
			$p = pathinfo($i['path']);
			$d = pathinfo($p['dirname']);
			
			// Uncomment for debugging
			// if ($this->debug) {cprint_r($i); cprint_r($p); cprint_r($d);}
			
			if (!isset($i['query']) && !isset($p['extension']) && !isset($d['extension'])) {
				
				// The URL is a directory, so we need a trailing slash
				return true;
			}
		}
		
		// No trailing slash is needed
		return false;
	}
	
	/**
	 * Determines whether the client supports a particular HTTP option.
	 *
	 * This method searches the 'Accept' (if $name is null) or 'Accepts-*' headers 
	 * for the given value, e,g, to test whether the client supports gzip encoding:
	 * accepts('encoding', 'gzip').
	 *
	 * @param   string  the suffix for any 'Accepts-*' headers
	 * @param   string  the option value
	 * @return  bool
	 */
	public function accepts($name, $value)
	{
		$header = $name == null ? 'accept' : 'accept-'.$name;
		return !empty($this->headers[$header]) && stripos($this->headers[$header], $value) !== false;
	}
	
	/**
	 * Determines whether the request is valid and can be used for processing.
	 *
	 * @return  bool
	 */
	public function isValid()
	{
		return !empty($this->info);
	}

	/**
	 * Determines whether chunked transfer-encoding has been used by the request.
	 *
	 * @return  bool
	 */
	public function isChunked()
	{
		return isset($this->headers['transfer-encoding']) && $this->headers['transfer-encoding'] == 'chunked';
	}
	
	/**
	 * Determines whether this is a POST request.
	 *
	 * @return  bool
	 */
	public function isPost()
	{
		return !empty($this->info['method']) && $this->info['method'] == 'POST';
	}

	/**
	 * Determines whether this is a HEAD request and no body should be returned.
	 *
	 * @return  bool
	 */
	public function isHead()
	{
		return !empty($this->info['method']) && $this->info['method'] == 'HEAD';
	}

	/**
	 * Determines whether this is a GET request.
	 *
	 * @return  bool
	 */
	public function isGet()
	{
		return !empty($this->info['method']) && $this->info['method'] == 'GET';
	}
	
	/**
	 * Returns the request method.
	 *
	 * @return  string  the request method
	 */
	public function getMethod()
	{
		return empty($this->info['method']) ? 'GET' : $this->info['method'];
	}
	
	/**
	 * Stores the client connection info locally.
	 *
	 * @param   string   client IP address
	 * @param   integer  client port number
	 * @return  MiniHTTPD_Request  this instance
	 */
	public function setClientInfo($address, $port)
	{
		$this->info['remote_address'] = $address;
		$this->info['remote_port'] = $port;
		return $this;
	}
	
	/**
	 * Returns the client connection info.
	 *
	 * @return  array  client address and port number
	 */
	public function getClientInfo()
	{
		return array($this->info['remote_address'], $this->info['remote_port']);
	}
	
	/**
	 * Returns the query string element of the request URL.
	 *
	 * @return  string  the query string
	 */
	public function getQueryString()
	{
		$query = '';
		if (!empty($this->info['rewritten']['url_parsed']['query'])) {
			$query = $this->info['rewritten']['url_parsed']['query'];
		} elseif (!empty($this->info['url_parsed']['query'])) {
			$query = $this->info['url_parsed']['query'];
		}
		return $query;
	}

	/**
	 * Returns the path element of the request URL.
	 *
	 * @return  string  the URL path
	 */
	public function getUrlPath()
	{
		if (isset($this->info['rewritten']['url_parsed'])) {
			$path = $this->info['rewritten']['url_parsed']['path'];
		} else {
			$path = $this->info['url_parsed']['path'];
		}
		return $path;
	}

	/**
	 * Returns a full file path string based on the parsed URL filename. Useful for
	 * testing quickly whether the request is for a known file or directory.
	 *
	 * @return  string  the full file path
	 */	
	public function getUrlFilepath($full=true)
	{
		$DS = DIRECTORY_SEPARATOR;
		$dir = str_replace('/', $DS, $this->getFilename());
		if ($full) {
			$dir = $this->getDocroot().ltrim($dir, $DS);
		}
		return $dir;
	}
	
	/**
	 * Returns the unparsed request URL. By default any rewritten URL will be
	 * returned, or the original request URL can be returned by setting the 
	 * parameter to false.
	 *
	 * @param   bool    should the rewritten URL be returned?
	 * @return  string  the current request URL
	 */
	public function getUrl($rewritten=true)
	{
		if ($rewritten && isset($this->info['rewritten']['url'])) {
			$url = $this->info['rewritten']['url'];
		} else {
			$url = $this->info['url'];
		}
		return $url;
	}
	
	/**
	 * Returns the info for the redirected/rewritten request, mainly for use with
	 * FastCGI requests.
	 *
	 * Note that if redirect_status is set to false in the info, no redirect values
	 * will be passed to the FCGI process. This means the loss of variables such as 
	 * REDIRECT_URL in scripts, but PATH_INFO and PHP_SELF work the same as in a
	 * non-CGI mode. With the redirect info added, ORIG_PATH_INFO must be used in 
	 * scripts instead, and PHP_SELF won't append any path info.
	 *
	 * @return  array  the redirected info
	 */
	public function getRedirectInfo()
	{
		$arr = array(false, false, false);
		if (empty($this->info['rewritten']['redirect_status'])) {
			return $arr;
		}
		if (isset($this->info['rewritten'])) {
			
			// Redirect URL
			$arr[0] = $this->info['rewritten']['redirect_url'];
			
			// Redirect query
			if	(isset($this->info['rewritten']['redirect_query'])) {
				$arr[1] = $this->info['rewritten']['redirect_query'];
			}
			
			// Redirect Status
			$arr[2] = $this->info['rewritten']['redirect_status'];
		}
		
		return $arr;
	}
	
	/**
	 * Returns the HTTP protocol version supported by the client.
	 *
	 * @return  string  HTTP protocol version
	 */
	public function getProtocol()
	{
		return $this->info['protocol'];
	}
	
	/**
	 * Returns the unparsed request line.
	 *
	 * @return  string  the request line
	 */
	public function getRequestLine()
	{
		return $this->info['request'];
	}

	/**
	 * Returns any referer information for the request.
	 *
	 * @return  string  referer or '-' if none
	 */
	public function getReferer()
	{
		return empty($this->headers['referer']) ? '-' : $this->headers['referer'];
	}

	/**
	 * Returns any user-agent information for the request.
	 *
	 * @return  string  user-agent or '-' if none
	 */	
	public function getUserAgent()
	{
		return empty($this->headers['user-agent']) ? '-' : $this->headers['user-agent'];
	}

	/**
	 * Sets the absolute file path for the request.
	 *
	 * @param   string  the file path
	 * @return  MiniHTTPD_Request  this instance
	 */
	public function setFilepath($path)
	{
		$this->filepath = $path;
		return $this;
	}

	/**
	 * Returns the absolute file path for the request.
	 *
	 * @return  string  absolute file path
	 */
	public function getFilepath()
	{
		return $this->filepath;
	}

	/**
	 * Sets the docroot path for the request.
	 *
	 * @param   string  absolute docroot path
	 * @return  MiniHTTPD_Request  this instance
	 */
	public function setDocroot($docroot)
	{
		if (substr($docroot, -1) !== DIRECTORY_SEPARATOR) {$docroot .= DIRECTORY_SEPARATOR;}
		$this->docroot = $docroot;
		return $this;
	}

	/**
	 * Returns the docroot in which the request may search for files.
	 *
	 * @return  string  absolute docroot path
	 */
	public function getDocroot()
	{
		return $this->docroot;
	}
	
	/**
	 * Returns the calculated script name for the request.
	 *
	 * This may be modified depending on whether a virtual path is set for the
	 * request to allow internal links to be written properly. The resulting value
	 * is used to set both $_SERVER['SCRIPT_NAME'] and $_SERVER['PHP_SELF'] for
	 * FastCGI requests.
	 *
	 * @return  string  the script name
	 */
	public function getScriptName()
	{
		$ret = $this->getFilename();
		if (isset($this->info['url_parsed']['base_path'])) {
			$ret = $this->info['url_parsed']['base_path'].$ret;
		}
		return str_replace('//', '/', $ret);
	}
		
	/**
	 * Returns the filename parsed from the request line.
	 *
	 * @return  string  the request filename
	 */
	public function getFilename()
	{
		return $this->info['filename'];
	}

	/**
	 * Overrides the parsed request filename.
	 *
	 * @param   string  the filename
	 * @return  MiniHTTPD_Request  this instance
	 */
	public function setFilename($name)
	{
		$this->info['filename'] = $name;
		return $this;
	}	
	
	/**
	 * Sets the path translated info for the request.
	 *
	 * @todo This needs to be properly verified.
	 *
	 * @param   string  path translated
	 * @return  MiniHTTPD_Request  this instance
	 */	
	public function setPathTranslated($path)
	{
		$this->info['path_translated'] = $path;
		return $this;
	}

	/**
	 * Returns the calculated path information for the request.
	 *
	 * @return  array  path info, path translated
	 */
	public function getPathInfo()
	{
		$pathInfo = !empty($this->info['path_info']) ? $this->info['path_info'] : false;
		$pathTranslated = !empty($this->info['path_translated']) ? $this->info['path_translated'] : false;
		return array($pathInfo, $pathTranslated);
	}

	/**
	 * Overrides the calculated path information for the request.
	 *
	 * @param   array  the new path info
	 * @return  MiniHTTPD_Request  this instance
	 */
	public function setPathInfo($pathinfo)
	{
		$this->info['path_info'] = $pathinfo;
		return $this;
	}
	
	/**
	 * Returns the request content type.
	 *
	 * @return  string  the content type
	 */
	public function getContentType()
	{
		return empty($this->headers['content-type']) ? false : $this->headers['content-type'];
	}

	/**
	 * Returns the request content length.
	 *
	 * @return  integer|bool  length in bytes of the request body, false if unknown
	 */	
	public function getContentLength()
	{
		if (isset($this->headers['content-length'])
			&& is_numeric($this->headers['content-length'])
			) {
			return $this->headers['content-length'];
		}
		return false;
	}
	
	/**
	 * Determines whether the listed users are authorized to access the requested
	 * resource against the given realm.
	 *
	 * The HTTP digest access authentication method is used here. The request must
	 * include the 'Authorization' header with the correct digest info.
	 *
	 * @param   string  the authorization realm
	 * @param   array   list of usernames & passwords
	 * @return  bool
	 */
	public function isAuthorized($realm, $users)
	{
		// Validate the authorization header digest
		if ($this->hasHeader('authorization')) {
			if (($data = MHTTPD_Request::parseHTTPDigest($this->getHeader('authorization')))
				&& isset($users[$data['username']])
				) {
				$A1 = md5($data['username'].':'.$realm.':'.$users[$data['username']]);
				$A2 = md5($this->getMethod().':'.$data['uri']);
				$valid = md5($A1.':'.$data['nonce'].':'.$data['nc'].':'.$data['cnonce'].':'.$data['qop'].':'.$A2);
				$this->info['username'] = $data['username'];
				if ($data['response'] == $valid) {
					return true;
				}
			}
		}
		
		// Remove the header if authorization fails
		unset($this->headers['authorization']);
		return false;
	}
	
} // End MiniHTTPD_Request
