<?php
/**
 * This class allows simple queuing, query and access of the configured request
 * handlers for processing by the client object.
 *
 * @package    MiniHTTPD
 * @subpackage Handlers
 * @author     MiniHTTPD Team
 * @copyright  (c) 2010-2012 MiniHTTPD Team
 * @license    BSD revised
 */
class MiniHTTPD_Handlers_Queue implements SeekableIterator
{
	protected $position = 0;
	protected $handlers;
  protected $queue;
	
  public function __construct($handlers) 
	{
    $this->handlers = $handlers;
		$this->init();
  }
  
	public function init()
	{
		$this->queue = array_keys($this->handlers);
		$this->rewind();
		return $this;
	}
	
	public function rewind() 
	{
		$this->position = 0;
  }
  
	public function current() 
	{
    return $this->handlers[$this->queue[$this->position]];
  }
  
	public function key() 
	{
    return $this->queue[$this->position];
  }
  
	public function next() 
	{
		$this->position++;
  }
  
	public function valid() 
	{
		return isset($this->queue[$this->position]);
  }

	public function seek($position)
	{
		$this->position = $position;
	}
	
	public function insert($key, $after=true)
	{
		if (!isset($this->handlers[$key])) {return false;}
		$pos = $after ? $this->position + 1 : $this->position;
		array_splice($this->queue, $pos, 0, $key);
		return true;
	}
	
	public function requeue($key)
	{
		$this->insert($key, false);
	}

} // End MiniHTTPD_Handlers_Queue
