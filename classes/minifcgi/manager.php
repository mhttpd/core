<?php
/**
 * The MiniFCGI manager class.
 * 
 * This static class is responsible for managing the FastCGI process pool. It 
 * connects clients to available processes, spawns new ones if needed, monitors 
 * activity on each of the processes, culls any idle processes after a given time
 * and maintains a scoreboard for the pool.
 *
 * @package    MiniHTTPD
 * @subpackage MiniFCGI
 * @author     MiniHTTPD Team
 * @copyright  (c) 2010-2012 MiniHTTPD Team
 * @license    BSD revised
 */
class MiniFCGI_Manager
{	
	// ------ Class constants ------------------------------------------------------	

	/**#@+
	 * FastCGI/1.0 Protocol Constants
	 */
	
	// Roles
	const RESPONDER =  1;
	const AUTHORIZER = 2;
	const FILTER = 3;

	// Flags
	const KEEP_CONN = 1;

	// Version
	const VERSION_1 = 1;

	// Record types
	const BEGIN_REQUEST = 1;
	const ABORT_REQUEST = 2;
	const END_REQUEST = 3;
	const PARAMS = 4;
	const STDIN = 5;
	const STDOUT = 6;
	const STDERR = 7;
	const DATA = 8;
	const GET_VALUES = 9;
	const GET_VALUES_RESULT = 10;
	const UNKNOWN_TYPE = 11;
	
	// For management records
	const NULL_REQUEST_ID = 0;
	
	// Protocol status
	const REQUEST_COMPLETE = 0;		# Request completed OK
	const CANT_MPX_CONN = 1;		 	# This app can't multiplex
	const OVERLOADED = 2;					# Request rejected, too busy
	const UNKNOWN_ROLE = 3;				# Role value not known

	/**#@-*/
	
	/**
	 * Maximum length in bytes of FCGI records.
	 */
	const MAX_LENGTH = 65536;
	
	/**
	 * Nulls BEGIN_REQUEST flags
	 */
	const NO_FLAGS = 0;
	

	// ------ Class variables and methods ------------------------------------------
	
	/**
	 * Should debugging output be enabled?
	 * @var bool
	 */
	public static $debug = false;

	/**
	 * The time in seconds after which a connection attempt will abort.
	 * @var integer
	 */
	public static $timeout = 5;
	
	/**
	 * The list of running processes along with information about them.
	 * @see createProcess()
	 * @see spawn()
	 * @var array
	 */
	protected static $pool;
	
	/**
	 * The stored configuration settings for the pool.
	 * @var array
	 */
	protected static $config;
	
	/**
	 * Adds new FastCGI processes to the pool.
	 *
	 * At startup, this method will spawn the minimum number of processes given
	 * in the configurations settings. After startup, ID numbers should be passed by 
	 * clients when requesting a new process. Using this method ensures that the
	 * maximum number of processes given in the configuration is never exceeded. 
	 *
	 * @uses createProcess()
	 *
	 * @param   integer  a stored process ID number
	 * @param   array    the configuration settings
	 * @return  bool     true if a new process was spawned  
	 */
	public static function spawn($ID=null, $config=null)
	{
		// Store initialization options on first run
		if (MFCGI::$config == null && $config != null) {
			MFCGI::$config = $config['FCGI'];
			MFCGI::$config['cwd'] = $config['Paths']['docroot'];
		}
		
		// Maximum number of processes shouldn't be exceeded
		if (count(MFCGI::$pool) >= MFCGI::$config['max_processes']) {
			return false;
		}
		$valid = false;
		
		if ($ID == null && MFCGI::$config == null) {
			
			// We need at least the ini settings if there's no ID
			trigger_error("No initialization options given for FCGI", E_USER_WARNING);

		} elseif ($ID == null) {	
				
			// Add the minimum number of processes to the pool
			for ($i = 1; $i <= MFCGI::$config['min_processes']; $i++) {
				if (!isset(MFCGI::$pool[$i])) {
					$valid = MFCGI::createProcess($i, MFCGI::$config['binds'][$i], MFCGI::$config['cwd']);
				}
			}
			
		} elseif ($ID) {
			
			// Add a single process by stored ID value to the pool
			if (!isset(MFCGI::$pool[$ID]) && !empty(MFCGI::$config['binds'][$ID])) {
				$valid = MFCGI::createProcess($ID, MFCGI::$config['binds'][$ID], MFCGI::$config['cwd']);
			}
		}
		
		return $valid;
	}

	/**
	 * Connects a client to a running process, or spawns a new one if needed.
	 *
	 * The method will try to handle new client connections in the most optimal
	 * way, otherwise if a client is trying to resume a connection to an already
	 * existing process (for example, when reading an FCGI response) and cannot
	 * complete the connection, the method must abort, as responses need to come
	 * from the same process as the original requests.
	 *
	 * @param   integer  a stored client ID number
	 * @param   integer  a stored process ID number
	 * @return  resource|false  the socket connection or error
	 */
	public static function connect($clientID, $processID=null)
	{		
		// Cull any idle processes
		MFCGI::cullProcesses();

		if (MFCGI::$debug) {cecho("Connecting FCGI ... ");}
		
		// Keep count of connection attempts
		static $attempts;
		
		// Are we checking for an existing process?
		if ($processID != null && !isset(MFCGI::$pool[$processID])) {
			return false;
		} elseif ($processID != null) {
			if (MFCGI::$debug) {cecho("using processID: {$processID}\n");}
			
			// Check that the process is still running
			if (!MFCGI::processExists($processID)) {
				if (MFCGI::$debug) {cecho("Process {$processID}, ".MFCGI::$pool[$processID]['pid']." no longer exists\n");}
				unset(MFCGI::$pool[$ID]);
			} else {
				$address = MFCGI::$pool[$processID]['address'];
				$port = MFCGI::$pool[$processID]['port'];
				$ID = $processID;
			}
		}
		
		// Verify any non-running processes
		MFCGI::verifyProcesses();
		
		if (empty($ID)) {
		
			// Try to match client to an idle process
			foreach (MFCGI::$pool as $ID=>$info) {
				if ($info['clients'] == 0) {
					if (MFCGI::$debug) {cecho("using idle process: {$ID}\n");}
					$address = $info['address'];
					$port = $info['port'];
					break;
				}
			}
			
			// No idle processes, so try to spawn a new one
			if (empty($address)) {
				for ($i = 1; $i <= MFCGI::$config['max_clients']; $i++) {
					if (MFCGI::spawn($i)) {
						if (MFCGI::$debug) {cecho("... using new spawn: {$i}\n");}
						$address = MFCGI::$pool[$i]['address'];
						$port = MFCGI::$pool[$i]['port'];
						$ID = $i;
						break;
					}
				}
			}
			
			// Still no luck, so now try to find the least busy process
			if (empty($address)) {
				$clients = MFCGI::$config['max_clients'];
				foreach (MFCGI::$pool as $iID=>$info) {
					if ($info['clients'] < $clients) {
						$clients = $info['clients'];
						$address = $info['address'];
						$port = $info['port'];
						$ID = $iID;
					}
				}
				if (MFCGI::$debug && !empty($address)) {
					cecho("using least busy process: {$ID} (C:{$clients})\n");
				}
			}
			
			// Still no joy? Then give up here ...
			if (empty($address)) {
				if (MFCGI::$debug) {cecho("giving up!\n");}
				trigger_error("No available FCGI process for client {$clientID}", E_USER_WARNING);
				return false;
			}
		}
		
		// Try making a new connection
		if (($sock = @fsockopen($address, $port, $errno, $errstr, MFCGI::$timeout)) === false ) {
			trigger_error("Cannot connect to FCGI process (p:{$ID}, c:{$clientID}): $errno - $errstr", E_USER_WARNING);
			
			// Does the process actually exist?
			if (!MFCGI::processExists($ID, true)) {
				if (MFCGI::$debug) {cecho("Process {$ID}, ".MFCGI::$pool[$ID]['pid']." no longer exists\n");}
				unset(MFCGI::$pool[$ID]);
			}
			
			// Try to connect again?
			if ($attempts == 3) {
				$attempts = 0;
				return false;
			} else {
				usleep(500); $attempts++;
				$processID = $processID ? $processID : null;
				return MFCGI::connect($clientID, $processID);
			}
		}
		
		// Update client count
		if (!$attempts) {MFCGI::$pool[$ID]['clients']++;}
		$attempts = 0;
		
		// Return the process ID and opened socket
		return array($ID, $sock);
	}
	
	/**
	 * Detaches a client from a running process.
	 *
	 * Currently this only decrements the client count for the specified process.
	 *
	 * @param   integer  the stored process ID number
	 * @return  bool
	 */
	public static function removeClient($ID)
	{
		if (!empty(MFCGI::$pool[$ID]) && MFCGI::$pool[$ID]['clients'] > 0) {
			MFCGI::$pool[$ID]['clients']--;
			if (MFCGI::$debug) {
				cecho("(FCGI process {$ID}");
				if (!empty(MFCGI::$pool[$ID]['pid'])) {cecho(' ('.MFCGI::$pool[$ID]['pid'].')');}
				cecho(' now has '.MFCGI::$pool[$ID]['clients']." clients)\n");
			}
			return true;
		}
		return false;
	}
	
	/**
	 * Returns the scoreboard for the process pool as an array or string.
	 *
	 * @param   bool  return scoreboard as a string?
	 * @return  array|string  the scorebord for the process pool
	 */
	public static function getScoreboard($asString=false) 
	{
		if ($asString) {
			$ret = '';
			foreach (MFCGI::$pool as $ID=>$info) {
				$ret .= "P: {$ID} ({$info['pid']}), C: {$info['clients']}, R: {$info['requests']}, T: {$info['time']}\n";
			}		
		} else {
			$ret = array();
			foreach (MFCGI::$pool as $ID=>$info) {
				$ret[$ID] = array(
					'pid' => $info['pid'],
					'clients' => $info['clients'],
					'requests' => $info['requests'],
					'time' => $info['time'],
				);
			}
		}
		
		return $ret;
	}
	
	/**
	 * Increments the request count for a running process.
	 *
	 * @param   integer  a stored process ID number
	 * @return  void
	 */
	public static function addRequest($ID) {
		if (!empty(MFCGI::$pool[$ID])) {
			MFCGI::$pool[$ID]['requests']++;
		}
	}
	
	/**
	 * Returns the PID number for a running process.
	 *
	 * Getting the PID numbers for processes on Windows systems is more fiddly than
	 * it should be. In this case, the most reliable method seems to be to run a 
	 * query script on the process itself that returns the output of the getmypid() 
	 * function in a custom header.
	 *
	 * @link http://www.php.net/manual/en/function.getmypid.php
	 * @uses MiniFCGI_Client::sendRequest()
	 *
	 * @param   integer  a stored process ID number
	 * @return  integer|bool  the PID number or error
	 */
	public static function getPID($ID) 
	{
		// Check if the queried process already has a PID
		if (isset(MFCGI::$pool[$ID]['pid']) && is_numeric(MFCGI::$pool[$ID]['pid'])) {
			return MFCGI::$pool[$ID]['pid'];
		}
	
		// Otherwise start a new client instance to make the query
		$fcgi = new MFCGI_Client(0, $ID);
		$fcgi->debug = false;
		$pid = false;
		
		// Create the PID request
		$fcgi->setRequest(array(
			'ID' => MFCGI::NULL_REQUEST_ID,
			'params'=> array(
				'REQUEST_METHOD' => 'HEAD',
				'SCRIPT_FILENAME' => MHTTPD::getServerDocroot().'scripts\getpid.php',
			),
			'method' => 'HEAD',
		));

		// Get the PID value from the response header
		if (MFCGI::$debug) {cecho("Getting PID ... ");}
		if ($fcgi->sendRequest() && $fcgi->readResponse(true)
			&& $fcgi->getResponse()->hasHeader('X-PID')
			) {
			$pid = $fcgi->getResponse()->getHeader('X-PID');
		}
		
		// Any errors?
		if (MFCGI::$debug && !$pid) {
			cprint_r($fcgi);
		}
		
		return $pid;
	}
	
	/**
	 * Starts a new FastCGI process in the background.
	 *
	 * There are different ways of doing this on Windows, none as easy as it is on 
	 * UNIX systems ... anyway, using a WScript COM object seems to be the least 
	 * problematic, especially when running the server without a console.
	 *
	 * @param   integer  a stored process ID number
	 * @param   string   the address:port of the new process
	 * @param   string   the working directory for the new process
	 * @return  bool     false if the process can't be created
	 */
	protected static function createProcess($ID, $bind, $cwd)
	{
		if (MFCGI::$debug) {cecho("Creating new FCGI process (P: $ID)\n");}
		
		// Set environment variables
		putenv('PHP_FCGI_MAX_REQUESTS='.MFCGI::$config['max_requests']);
		putenv('FCGI_WEB_SERVER_ADDRS='.MFCGI::$config['allow_from']);
		putenv('PHP_INI_SCAN_DIR='.MHTTPD::getExepath().'bin\php\php.d');
		
		// Launch the new process in the background
		$fcgi_path = MHTTPD::getExepath().'bin\php\\'.MFCGI::$config['name'];
		$cmd = '"'.$fcgi_path.'.exe" -b '.$bind.' -c "'.$fcgi_path.'.ini"';
		$wshShell = new COM('WScript.Shell');
		$oExec = $wshShell->Run($cmd, 0, false);
		sleep(1); // to be safe
		
		if ($oExec !== false) {

			// Add the process info to the active pool list
			list($address, $port) = explode(':', $bind);
			MFCGI::$pool[$ID]['name'] = MFCGI::$config['name'];
			MFCGI::$pool[$ID]['address'] = $address;
			MFCGI::$pool[$ID]['port'] = $port;
			MFCGI::$pool[$ID]['cwd'] = $cwd;
			MFCGI::$pool[$ID]['time'] = time();
			MFCGI::$pool[$ID]['pid'] = null;
			MFCGI::$pool[$ID]['clients'] = 0;
			MFCGI::$pool[$ID]['requests'] = 0;
			
			// Get the PID of the new process
			if ($pid = MFCGI::getPID($ID)) {
				MFCGI::$pool[$ID]['pid'] = $pid;
			} else {
				trigger_error("Cannot get FCGI PID ({$bind})", E_USER_WARNING);
			}
			
			// Debug
			if (MFCGI::$debug) {
				cecho(chrule()."\n");
				cecho("Created FCGI process ($ID)\n\n");
				cprint_r(MFCGI::$pool[$ID]);
			}
			return true;
		}
		
		return false;
	}
	
	/**
	 * Determines whether a process in the pool is still alive.
	 *
	 * Again this is annoyingly fiddly on Windows systems, and can be terribly
	 * slow to use on a regular basis. Two methods are therefore used, one quick
	 * and dirty for checking each request, and the other slower but more accurate
	 * for when more critical checks are needed.
	 *
	 * @param   integer  a stored process ID number
	 * @param   bool     use the slow but accurate  method?
	 * @return  bool
	 */
	protected static function processExists($ID, $byPID=false)
	{
		// This method is very slow, but accurate
		if ($byPID) {
			if (!empty(MFCGI::$pool[$ID]['pid'])) {
				$pid = MFCGI::$pool[$ID]['pid'];
				$tasks = shell_exec('tasklist.exe /FI "PID eq '.$pid.'" 2>&1');
				if (strpos($tasks, $pid) === false) {
					return false;
				}
			}
			return true;

		// This quick and dirty check will do in most cases
		} else {
			if (!empty(MFCGI::$pool[$ID])
				&& (MFCGI::$pool[$ID]['requests'] < MFCGI::$config['max_requests'])
				) {
				return true;
			}
			return false;
		}
	}
	
	/**
	 * Checks all of the processes in the pool and removes any that that seem to 
	 * have died or are not responding as expected.
	 *
	 * @todo This needs a much more robust approach.
	 * @see processExists()
	 *
	 * @return  void
	 */
	protected static function verifyProcesses()
	{
		foreach (MFCGI::$pool as $ID=>$info) {
			if (!MFCGI::processExists($ID)) {
				if (MFCGI::$debug) {cecho("Process {$ID} ({$info['pid']}) no longer exists\n");}
				unset(MFCGI::$pool[$ID]);
			}
		}
	}

	/**
	 * Kills any idle processes and removes them from the pool.
	 *
	 * This method checks the age of each process in the pool (in reverse order)
	 * and kills any idle ones that are older than the configured cull_time_limit 
	 * if there are more in the pool than the configured min_processes number.
	 *
	 * @return  void
	 */	
	protected static function cullProcesses()
	{
		// Only cull if allowed and if idle processes exist
		$maxCulls = count(MFCGI::$pool) - MFCGI::$config['min_processes'];
		if ($maxCulls == 0 || MFCGI::$config['cull_time_limit'] == 0) {
			return;
		}
		
		// Start culling processes
		$pids = array(); $now = time(); $c = 0;
		$cullSecs =  MFCGI::$config['cull_time_limit'] * 60;
		$processes = MFCGI::$pool;
		krsort($processes);
		
		// Get the PIDs of idle processes
		foreach ($processes as $ID=>$info) {
			if ($info['clients'] == 0 && ($info['time'] + $cullSecs) < $now) {
				if (++$c > $maxCulls) {break;}
				$pids[] = $info['pid'];
				unset(MFCGI::$pool[$ID]);
			}
		}	

		// Kill the processes as a batch
		if (count($pids) > 0) {
			$pidList = '/PID '.join(' /PID ', $pids);
			if (MFCGI::$debug) {
				cecho("Culling FCGI processes ... $pidList\n");
			}
			$kill = shell_exec('taskkill /F /T '.$pidList.' 2>&1');
		}
	}
	
	/**
	 * Prevents instantiation of this static class.
	 *
	 * @return  void
	 */
	final private function __construct() {}
			
} // End MiniHHTPD_FCGI_Manager
