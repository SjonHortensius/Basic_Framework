<?PHP

class Basic_Log
{
	private $_startTime;
	private $_enabled;
	private $_indenting = 0;
	private $_lastStarter;
	private $_logs = array();
	private $_timers = array();
	private $_counters = array();
	private $_startTimes = array();

	public function __construct()
	{
		$this->_startTime = microtime(TRUE);
		$this->_enabled = !Basic::$config->PRODUCTION_MODE;
	}

	public function start()
	{
		if (!$this->_enabled)
			return FALSE;

		$this->_indenting++;

		list($class, $method) = self::get_caller();

		array_push($this->_startTimes, array('class' => $class, 'method' => $method, 'time' => microtime(TRUE)));
	}

	public function end($line = NULL)
	{
		static $previousMemory;

		if (!$this->_enabled)
			return FALSE;

		if (empty($this->_startTimes))
			throw new Basic_Log_NotStartedException();

		$lastStarted = array_pop($this->_startTimes);

		$time = microtime(TRUE) - $lastStarted['time'];

		$this->_timers[ $lastStarted['class'] ][ $lastStarted['method'] ] += $time;

		$indent = ($this->indenting > 1) ? '|'. str_repeat('-', $this->indenting-1) : '';

		$extra = $indent . number_format($time * 1000, 2) .'ms - +'. round((memory_get_usage() - $previousMemory) / 1024) .' KiB : ';
		$previousMemory = memory_get_usage();

		$this->_write($lastStarted['class'], $lastStarted['method'], null, $extra);

		$this->indenting--;
	}

	public function write($line = NULL)
	{
		if (!$this->_enabled)
			return FALSE;

		list($class, $method) = self::get_caller();

		$this->_write($class, $method, $line);
	}

	private function _write($class, $method, $line = null, $extra = null)
	{
		$line = '<b>'. $class .'::'. $method.'</b>'. (isset($line) ? ' <i>'. $line .'</i>' : '');

		if (!isset($this->_counters[ $class ][ $method ]))
			$this->_counters[ $class ][ $method ] = 0;
		if (!isset($this->_timers[ $class ][ $method]))
			$this->_timers[ $class ][ $method ] = 0;

		$this->_counters[ $class ][ $method ]++;

		array_push($this->_logs, $line);
	}

	public function getStatistics()
	{
		$output = 'request_took '.sprintf('%01.4f', microtime(TRUE) - $this->_startTime) .'s';

		if (isset($this->_timers['database']['query']))
			$output .= ' ['. array_sum($this->engine->database->query_counter).' queries in '.sprintf('%01.4f', $this->engine->log->timers['database']['query']).'s]';

		$output .= ', '. round(memory_get_usage()/1024) .' Kb memory';

		return $output;
	}

	public function getTimers($show_trace = FALSE)
	{
		if (!$this->_enabled)
			return FALSE;

		$output = '';

		if ($show_trace)
			$output .= implode('<br />', $this->_logs) .'<hr />';

		$output .= 'Totals:';
		foreach ($this->_timers as $class => $functions)
			foreach ($functions as $function => $time)
				$timers[$class .'::'. $function] = $time;

		array_multisort($timers, SORT_DESC);

		foreach ($timers as $name => $time)
		{
			list($class, $function) = explode('::', $name);

			$count = '';
			if (isset($this->_counters[$class][$function]))
				$count = ' ['. $this->_counters[$class][$function] .']';

			$output .= '<br />'. number_format($time*1000, 2) . 'ms'.$count.': '. $name;
		}

		return $output;
	}

	public function getLogs()
	{
		return implode('<br/>', $this->_logs);
	}

	static function get_caller()
	{
		$trace = debug_backtrace();
		return array($trace[2]['class'], $trace[2]['function']);
	}
}