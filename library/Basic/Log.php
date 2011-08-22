<?php

class Basic_Log
{
	protected $_startTime;
	protected $_logs = array();
	protected $_started = array();
	public static $queryCount = 0;

	public function __construct()
	{
		$this->_startTime = microtime(true);
	}

	public function start($method = null)
	{
		if (Basic::$config->PRODUCTION_MODE)
			return;

		if (!isset($method))
			$method = self::getCaller();

		// Start the log by showing what the initial memory usage is
		if (empty($this->_logs))
			array_push($this->_logs, array(0, 'Basic::bootstrap', microtime(true)-$this->_startTime, round(memory_get_usage() / 1024)));

		array_push($this->_started, array($method, microtime(true), memory_get_usage()));
	}

	public function write($text)
	{
		$this->start(self::getCaller());
		$this->end($text);
	}

	public function end($text = null)
	{
		if (Basic::$config->PRODUCTION_MODE)
			return;

		if (empty($this->_started))
			throw new Basic_Log_NotStartedException();

		list($method, $time, $memory) = array_pop($this->_started);

		array_push($this->_logs, array(count($this->_started), $method, microtime(true) - $time, round((memory_get_usage() - $memory) / 1024), $text));
	}

	public function getStatistics()
	{
		return array(
			'time' => microtime(true) - $this->_startTime,
			'queryCount' => self::$queryCount,
			'memory' => (int)round(memory_get_usage()/1024),
		);
	}

	public function getTimers()
	{
		if (Basic::$config->PRODUCTION_MODE)
			return;

		$timers = $counters = array();
		foreach ($this->_logs as $logEntry)
		{
			list(, $method, $time, ) = $logEntry;

			$counters[ $method ]++;
			$timers[ $method ] += $time;
		}

		arsort($timers, SORT_NUMERIC);

		$output = '';
		foreach ($timers as $name => $time)
		{
			$output .= '<dt>'. $name. '</dt><dd><b>'. number_format($time*1000, 2) . '</b> ms for <b>'. $counters[$name] .'</b> call';

			if ($counters[$name] > 1)
			 	$output .= 's ('. number_format(($time / $counters[$name]) * 1000, 2) .' ms per call)</dd>';
		}

		return '<dl>'. $output .'</dl>';
	}

	public function getLogs()
	{
		$output = array();

		foreach ($this->_logs as $logEntry)
		{
			list($indenting, $method, $time, $memory, $text) = $logEntry;

			array_push($output, sprintf('%s %5.2f ms %+5d KiB <b>%s</b> %s', htmlspecialchars(str_pad(str_repeat(">", $indenting), 5, '_', STR_PAD_RIGHT)), 1000*$time, $memory, $method, htmlspecialchars($text)));
		}

		return implode('<br/>', $output);
	}

	public function getGraph()
	{
/*
<?xml version='1.0'?>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:svg="http://www.w3.org/2000/svg">
*/
//This makes no sense as it doesn't check $indenting, making it count stuff double
		$data = array();
		foreach ($this->_logs as $logEntry)
		{
			list(, $method, $time, $memory, ) = $logEntry;

			$prev = end($data);
			array_push($data, array($prev[0]+round(100000*$time), $prev[1]+$memory, $method));
		}

		$width = 400;
		$height = 300;
		$points = array(array(0,0,0,300));
		$lastPoint = end($data);
		$maxTime = $lastPoint[0];
		$maxMemory = $lastPoint[1];

		foreach ($data as $point)
		{
			list($time, $memory, $method) = $point;

			$prevPoint = end($points);
			array_push($points, array($prevPoint[2], $prevPoint[3], round($width/$maxTime*$time), round($height/$maxMemory*$memory), $method));
		}
//Basic::debug($data, $points);
		$output = '';
		foreach ($points as $point)
			$output .= '<svg:line x1="'.$point[0].'" y1="'.$point[1].'" x2="'.$point[2].'" y2="'.$point[3].'" style="stroke:#006600;" title="'.$point[4].'" />';

		return '<svg:svg id="display" width="'.$width.'" height="'.$height.'">'. $output .'</svg:svg>';
	}

	public static function getCaller()
	{
		$trace = debug_backtrace();

		return $trace[2]['class'] .'::'. $trace[2]['function'];
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