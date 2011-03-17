<?php
/**
 * The MiniHTTPD abstract request handler class from which all custom handlers 
 * should be extended.
 * 
 * The client object delegates responsibility to handlers for processing various
 * aspects of the current request. These may range from changing request details to 
 * starting the client response. Handlers may act on both the client and request
 * objects, so can also serve as a bridge between the two.
 *
 * Usually the handlers will be called in the order in which they have been listed
 * in the server's ini file. If the request is being reprocessed, various options
 * exist for excluding or skipping repeat calls to individual handlers. Handlers
 * may also be re-called on the fly via the methods of the handler queue object
 * used by the client.
 *
 * To add a new handler, first extend this class and implement its abstract methods.
 * Then load the extended handler via the classes.php and default.ini files (or the 
 * user versions, see the notes in each).
 *
 * @see MiniHTTPD_Handlers_Queue
 *
 * @package    MiniHTTPD
 * @subpackage Handlers
 * @author     MiniHTTPD Team
 * @copyright  (c) 2010 MiniHTTPD Team
 * @license    BSD revised
 */
abstract class MiniHTTPD_Request_Handler
{
	/**
	 * Should debugging output be enabled?
	 * @var bool
	 */	
	public $debug = false;
	
	/**
	 * Should no more handlers be processed if this one matches?
	 * @var bool
	 */	
	protected $isFinal = false;
	
	/**
	 * If this handler matches but fails to execute, can it be skipped?
	 * @var bool
	 */		
	protected $canSkip = false;

	/**
	 * Should the handler not be used when re-processing the request?
	 * @var bool
	 */		
	protected $useOnce = false;
	
	/**
	 * Can the request object be reused when re-processing?
	 * @var bool
	 */	
	protected $persist = true;
	
	/**
	 * The value to return to the main server loop following execution.
	 * @var bool
	 */		
	protected $returnValue = true;
	
	/**
	 * Reference to the calling client object.
	 * @var MiniHTTPD_Client 
	 */	
	protected $client;

	/**
	 * Reference to the active request object for the client.
	 * @var MiniHTTPD_Request
	 */		
	protected $request;
	
	/**
	 * Any error message following failure to execute the handler.
	 * @var string
	 */		
	protected $error;

	/**
	 * A tally of calls to the handler and their outcomes.
	 * @var array
	 */		
	protected $counts = array(
		'init' => 0,
		'match' => 0,
		'success' => 0,
		'error' => 0,
	);

	/**
	 * Initializes the handler by resetting to default values and adding
	 * references to the calling client and request objects.
	 *
	 * @param   MiniHTTPD_Client  the calling client object
	 * @return  void
	 */
	public function init(MHTTPD_Client $client)
	{
		// Reset handler to starting state
		$this->reset();
		
		// Bind the calling client object
		$this->client = $client;

		// Bind the client's request object
		$this->request = $client->getRequest();
		
		// Update init count
		$this->counts['init']++;
	}

	/**
	 * Resets the handler to its starting values.
	 *
	 * This method is called automatically whenever the handler is initialized.
	 * An extended class that overrides this method will usually want to call 
	 * parent::reset() as its first action.
	 *
	 * @return  void
	 */
	public function reset()
	{
		$this->client = null;
		$this->request = null;
		$this->error = '';
	}
	
	/**
	 * Determines whether no more handlers should be called after this one, or 
	 * toggles the current setting if a value is passed.
	 *
	 * @param   bool  whether this should be the last called handler
	 * @return  bool  true if this handler is final
	 */
	public function isFinal($final=null)
	{
		if ($final != null) {
			$this->isFinal = (bool) $final;
		} else {
			return $this->isFinal;
		}
	}

	/**
	 * Determines whether the handler can be skipped if execution fails and returns
	 * errors or toggles the current setting if a value is passed.
	 *
	 * @param   bool  whether errors can be skipped
	 * @return  bool  true if errors can be skipped
	 */
	public function canSkip($skip=null)
	{
		if ($skip != null) {
			$this->canSkip = (bool) $skip;
		} else {
			return $this->canSkip;
		}
	}

	/**
	 * Determines whether the handler should not be used when re-processing the
	 * request, or toggles the current setting if a value is passed.
	 *
	 * @param   bool  whether the handler should be skipped when re-processing
	 * @return  bool  true if the handler can only be called once
	 */	
	public function useOnce($once=null)
	{
		if ($once != null) {
			$this->useOnce = (bool) $once;
		} else {
			return $this->useOnce;
		}
	}

	/**
	 * Increments a count in the current tally.
	 *
	 * @param   string  the count to increment
	 * @return  void
	 */	
	public function addCount($type)
	{
		if (isset($this->counts[$type])) {
			$this->counts[$type]++;
		}
	}

	/**
	 * Returns a count from the current stored tally.
	 *
	 * @param   string   the count type
	 * @return  integer  the stored count value
	 */	
	public function getCount($type)
	{
		return isset($this->counts[$type]) ? $this->counts[$type] : 0;	
	}
	
	/**
	 * Determines whether any error message has been set following execution.
	 *
	 * @return  bool  true if an error message is stored
	 */
	public function hasError()
	{
		return !empty($this->error);
	}

	/**
	 * Returns any error message stored following execution.
	 *
	 * @return  string  the error message
	 */	
	public function getError()
	{
		return $this->error;
	}
	
	/**
	 * Determines whether the handler has been skipped following any errors during
	 * its execution.
	 *
	 * @return  bool  true if the handler has been skipped
	 */
	public function skipped()
	{
		return ($this->canSkip && !empty($this->error));
	}
	
	/**
	 * Gets the value to be returned to the main server loop following execution.
	 * usually this will be set to false if there any errors.
	 *
	 * @return  bool  the value to return to the main server loop
	 */
	public function getReturn()
	{
		return $this->returnValue;
	}

	/**
	 * Returns whether this handler will allow the calling client to reuse the 
	 * current request object when re-processing, or instead force a new request
	 * object to be created.
	 *
	 * This is useful when requiring authorization for a request and the headers
	 * need to be re-parsed, for example.
	 *
	 * @return  bool  true if the client can reuse the request object
	 */
	public function persist()
	{
		return $this->persist;
	}

	/**
	 * Determines whether the handler matches the current request.
	 *
	 * Typically this method will also store any parsed request values for use in
	 * the execute method if the handler matches.
	 *
	 * @return  bool  true if the handler matches the request
	 */	
	abstract public function matches();

	/**
	 * This method performs the main actions of the handler on the client and
	 * request objects if it matches the request.
	 *
	 * Handlers may have various responsibilities, but typically if the handler is 
	 * final this method should start the appropriate response on the client, or 
	 * send any error or redirect responses and report errors back to the main server
	 * loop via $returnValue. If any details of the request are to be changed, the 
	 * request object should be updated accordingly.
	 *
	 * This method may also require that the handler queue should be re-processed
	 * from the top following execution (e.g. see the directory handler).
	 *
	 * @return  bool  true if execution was successful
	 */	
	abstract public function execute();
	
	
} // End MiniHTTPD_Request_Handler
