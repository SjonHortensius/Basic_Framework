<?php

class Basic_Log
{
	protected $_startTime;
	protected $_enabled;
	protected $_lastStarter;
	protected $_logs = array();
	protected $_timers = array();
	protected $_counters = array();
	protected $_started = array();
	public static $queryCount = 0;

	public function __construct()
	{
		$this->_startTime = microtime(true);
		$this->_enabled = !Basic::$config->PRODUCTION_MODE;
	}

	public function start()
	{
		if (!$this->_enabled)
			return;

		list($class, $method) = self::getCaller();

		array_push($this->_started, array($class .'::'. $method, microtime(true), memory_get_usage()));
	}

	public function end($text = NULL)
	{
		if (!$this->_enabled)
			return;

		if (empty($this->_started))
			throw new Basic_Log_NotStartedException();

		list($method, $time, $memory) = array_pop($this->_started);

		array_push($this->_logs, array(count($this->_started), $method, microtime(true) - $time, round((memory_get_usage() - $memory) / 1024), $text));
	}

	public function getStatistics($extended = true)
	{
		if (!$extended)
			return sprintf('%01.2f|%d|%d', microtime(true) - $this->_startTime, self::$queryCount, round(memory_get_usage()/1024));
		else
			return sprintf('%01.2fs, %d queries, %d KiB memory', microtime(true) - $this->_startTime, self::$queryCount, round(memory_get_usage()/1024));
	}

	public function getTimers()
	{
		if (!$this->_enabled)
			return;

		$timers = $counters = array();
		foreach ($this->_logs as $logEntry)
		{
			list($indenting, $method, $time, $memory) = $logEntry;

			$counters[ $method ]++;
			$timers[ $method ] += $time;
		}

		arsort($timers, SORT_NUMERIC);

		$output = '';
		foreach ($timers as $name => $time)
			$output .= '<dt>'. $name. '</dt><dd><b>'. number_format($time*1000, 2) . '</b> ms for <b>'. $counters[$name] .'</b> calls ('. number_format(($time / $counters[$name]) * 1000, 2) .' ms per call)</dd>';

		return '<dl>'. $output .'</dl>';
	}

	public function getLogs()
	{
		$output = $max = array();

		foreach ($this->_logs as $logEntry)
		{
			list($indenting, $method, $time, $memory, $text) = $logEntry;

			array_push($output, sprintf('%s %5.2f ms %+4d KiB <b>%s</b> %s', str_pad(str_repeat(">", $indenting), 3, '_', STR_PAD_RIGHT), 1000*$time, $memory, $method, htmlspecialchars($text)));
		}

		return implode('<br/>', $output);
	}

	public static function getCaller()
	{
		$trace = debug_backtrace();
		return array($trace[2]['class'], $trace[2]['function']);
	}

	public static function getSimpleTrace()
	{
		$trace = array();
		$_trace = array_reverse(array_slice(debug_backtrace(), 2));

		foreach ($_trace as $point)
		{
//			$lineNo = (isset($point['line']) ? '@'. $point['line'] : '');

			if (isset($point['class']))
				$line = $point['class'] . $point['type'] . $point['function'] .$lineNo;
			else if (isset($point['file']))
				$line = basename($point['file']) . $lineNo .':'. $point['function'];

			array_push($trace, $line);
		}

		return implode(' > ', $trace);
	}
}