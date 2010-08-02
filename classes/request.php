<?php
/**
 * The MiniHTTPD request class.
 * 
 * Apart from overloading any members in the base message class, the main
 * responsibility of this class is to provide the information needed by the
 * client to process the request and determine the relevant response. Much of
 * this class therefore consists of accessor or query methods.
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
	 * The absolute path to the requested file. 
	 * @var string
	 */
	protected $filepath;
	
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
		if (!empty($this->body)) {
			$str .= "\r\n".$this->body;
		}
		
		return $str;
	}
	
	/**
	 * Returns an array of information about the requested file.
	 *
	 * @param   string  the docroot in which to search for the requested file
	 * @return  array   a normalized version of the pathinfo() output.
	 */
	public function getFileInfo($docroot=null)
	{
		if (!$docroot) {$docroot = MHTTPD::getDocroot();}
		$DS = DIRECTORY_SEPARATOR;
		$info = null;
		
		// Make sure we have uncached info
		clearstatcache();
		
		if (!empty($this->filepath)) {
			
			// If the last attempt was successful use that result
			$info = pathinfo($this->filepath);
			
		} else {

			// Start grokking the parsed path
			if (!empty($this->info['url_parsed'])) {
				
				$file = $docroot;
								
				// Try to get the filename and any extra path info
				if (preg_match('|(.*?\.\w+)(/.*)|', $this->info['url_parsed']['path'], $matches)) {
					$this->info['filename'] = $matches[1];
					$this->info['path_info'] = $matches[2];
				} else {
					$this->info['filename'] = $this->info['url_parsed']['path'];
				}
				$file .= $this->info['filename'];
				
				// So is the result an actual file?
				if (($file = realpath($file)) && is_file($file)) {
					$this->filepath = $file;
					$info = pathinfo($file);
				} else {
					$info = $this->info['path_parsed'];
				}
			}
		}

		if ($info) {
			
			// Get a normalized version of the info
			$dir = $info['dirname'] == $DS ? '' : str_replace('/', $DS, $info['dirname']).$DS;
			$dir = $dir != '' && $dir[0] == $DS ? substr($dir, 1) : $dir;
			$base = $info['basename'];
			$name = $info['filename'];
			$ext = !empty($info['extension']) ? $info['extension'] : '';
			return array($dir, $base, $name, $ext);
		
		} else {
		
			// Nothing else to do
			return array('','','','');
		}
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
		if ($this->debug) {cecho("Rewriting URL Path: ({$search} -> {$replace})");} 
		if (preg_match("|{$search}|", $this->info['url_parsed']['path'], $matches)) {
			$url = preg_replace("|{$search}|", $replace, $this->info['url_parsed']['path']);
			$this->info['path_parsed'] = pathinfo($url);
			$this->info['url_parsed']['path'] = $url;
			if ($isBasePath) {
				if ($this->debug) {cecho(' ... added as base path');}
				$this->info['url_parsed']['base_path'] = $matches[0];
			}
			if ($this->debug) {cecho(PHP_EOL);}
		}
		
		return $this;
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
		return !empty($this->headers['transfer-encoding']) && $this->headers['transfer-encoding'] == 'chunked';
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
		return empty($this->info['url_parsed']['query']) ? '' : $this->info['url_parsed']['query'];
	}

	/**
	 * Returns the path element of the request URL.
	 *
	 * @return  string  the URL path
	 */
	public function getUrlPath()
	{
		return $this->info['url_parsed']['path'];
	}
	
	/**
	 * Returns the unparsed request URL.
	 *
	 * @return  string  the request URL
	 */
	public function getUrl()
	{
		return $this->info['url'];
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
		if (isset($this->info['url_parsed']['base_path'])) {$ret = $this->info['url_parsed']['base_path'].$ret;}
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
